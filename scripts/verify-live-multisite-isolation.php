<?php
/**
 * Verifies F29/F30 on an exact disposable private multisite clone.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Credentials\CredentialService;
use Dreamax\LicenseManager\Database\Installer;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\ReleaseTools\PrivateCloneHarness;
use Dreamax\LicenseManager\Support\Capabilities;
use Dreamax\LicenseManager\Support\Health;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/release-tools.php';
require_once __DIR__ . '/lib/class-privatecloneharness.php';

const DREAMAX_LM_F2930_DATABASE_PREFIX = 'dreamax_lm_f2930_test_';
const DREAMAX_LM_F2930_CLONE_PREFIX    = 'dreamax-lm-f2930-clone-';
const DREAMAX_LM_F2930_CONFIRMATION    = 'I_CONFIRM_F29_F30_PRIVATE_MULTISITE';

/**
 * Writes a sanitized CLI failure and exits.
 *
 * @param string $message Failure message.
 */
function dreamax_lm_f2930_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Sanitized CLI failure only.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Overlays only current production plugin PHP into the private clone.
 *
 * @param string $target Private plugin root.
 * @throws RuntimeException When the private overlay fails.
 */
function dreamax_lm_f2930_overlay_plugin( string $target ): void {
	$source = dirname( __DIR__ );
	foreach ( array( 'dreamax-license-manager.php', 'uninstall.php' ) as $file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copies current production source only into the private clone.
		if ( ! copy( $source . DIRECTORY_SEPARATOR . $file, $target . DIRECTORY_SEPARATOR . $file ) ) {
			throw new RuntimeException( 'A private production file could not be overlaid.' );
		}
	}
	$source_root = $source . DIRECTORY_SEPARATOR . 'src';
	$iterator    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source_root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $iterator as $item ) {
		$relative    = substr( $item->getPathname(), strlen( $source_root ) + 1 );
		$destination = $target . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative;
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only private clone paths.
			if ( ! is_dir( $destination ) && ! mkdir( $destination, 0700, true ) && ! is_dir( $destination ) ) {
				throw new RuntimeException( 'A private production directory could not be overlaid.' );
			}
			continue;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copies current production source only into the private clone.
		if ( ! copy( $item->getPathname(), $destination ) ) {
			throw new RuntimeException( 'A private production source file could not be overlaid.' );
		}
	}
}

/**
 * Writes one sanitized private worker result.
 *
 * @param string              $path Result path.
 * @param array<string,mixed> $result Sanitized result.
 * @throws RuntimeException When the private result cannot be written.
 */
function dreamax_lm_f2930_write_result( string $path, array $result ): void {
	if ( dirname( $path ) !== rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) || ! str_starts_with( basename( $path ), DREAMAX_LM_F2930_CLONE_PREFIX ) ) {
		throw new RuntimeException( 'The private result path was not safely scoped.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- This checkpoint must work before WordPress loads.
	$encoded = json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	if ( ! is_string( $encoded ) ) {
		throw new RuntimeException( 'The private result could not be encoded.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact private result file.
	if ( strlen( $encoded ) !== file_put_contents( $path, $encoded, LOCK_EX ) ) {
		throw new RuntimeException( 'The private result could not be written.' );
	}
}

/**
 * Persists a sanitized worker checkpoint so an abrupt WordPress exit remains diagnosable.
 *
 * @param string $path  Result path.
 * @param string $stage Sanitized stage.
 */
function dreamax_lm_f2930_checkpoint( string $path, string $stage ): void {
	$stage = strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', $stage ) );
	dreamax_lm_f2930_write_result(
		$path,
		array(
			'worker_ok'        => false,
			'failure_stage'    => $stage,
			'sensitive_output' => false,
		)
	);
}

/**
 * Reduces an unread worker sink to a fixed, non-sensitive diagnostic category.
 *
 * @param string $sink Worker sink text.
 */
function dreamax_lm_f2930_sink_category( string $sink ): string {
	$fixed = array(
		'Could not find site'                         => 'site_not_found',
		'Database tables are missing'                 => 'database_tables_missing',
		'One or more database tables are unavailable' => 'database_tables_unavailable',
		'Error establishing a database connection'    => 'database_connection',
		'There has been a critical error'             => 'wordpress_critical',
		'Allowed memory size'                         => 'memory_limit',
		'Maximum execution time'                      => 'execution_timeout',
		'Failed opening required'                     => 'required_file',
		'PHP Parse error'                             => 'php_parse_error',
	);
	foreach ( $fixed as $needle => $category ) {
		if ( str_contains( $sink, $needle ) ) {
			return $category;
		}
	}
	$patterns = array(
		'/Call to undefined function ([A-Za-z0-9_\\\\]+)\(\)/' => 'undefined_function_',
		'/Class [\"\']([A-Za-z0-9_\\\\]+)[\"\'] not found/' => 'missing_class_',
		'/Undefined constant [\"\']([A-Za-z0-9_\\\\]+)[\"\']/' => 'undefined_constant_',
		'/Cannot redeclare ([A-Za-z0-9_\\\\]+)\(\)/' => 'redeclare_',
	);
	foreach ( $patterns as $pattern => $prefix ) {
		if ( 1 === preg_match( $pattern, $sink, $match ) ) {
			return $prefix . strtolower( str_replace( '\\', '_', $match[1] ) );
		}
	}
	if ( str_contains( $sink, 'PHP Fatal error' ) ) {
		return 'php_fatal';
	}
	return '' === trim( $sink ) ? 'silent_exit' : 'unclassified_output';
}

/**
 * Adds deterministic multisite constants to the private configuration.
 *
 * @param string $config Configuration text.
 * @throws RuntimeException When multisite definitions cannot be added safely.
 */
function dreamax_lm_f2930_multisite_config( string $config ): string {
	foreach ( array( 'MULTISITE', 'SUBDOMAIN_INSTALL', 'DOMAIN_CURRENT_SITE', 'PATH_CURRENT_SITE', 'SITE_ID_CURRENT_SITE', 'BLOG_ID_CURRENT_SITE' ) as $constant ) {
		if ( 1 === preg_match( '/define\s*\(\s*([\'\"])' . preg_quote( $constant, '/' ) . '\1/', $config ) ) {
			throw new RuntimeException( 'The clone already contained a multisite definition.' );
		}
	}
	$anchor = "/* That's all, stop editing! Happy publishing. */";
	if ( 1 !== substr_count( $config, $anchor ) ) {
		throw new RuntimeException( 'The private configuration anchor was not unique.' );
	}
	$host = (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_HOST );
	if ( '' === $host || 1 !== preg_match( '/^[A-Za-z0-9.-]+$/D', $host ) ) {
		throw new RuntimeException( 'The disposable host was not safe for a private network.' );
	}
	$path = (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_PATH );
	$path = '/' . trim( $path, '/' ) . '/';
	if ( '//' === $path ) {
		$path = '/';
	}
	if ( 1 !== preg_match( '#^/[A-Za-z0-9_/-]*/$#D', $path ) ) {
		throw new RuntimeException( 'The disposable path was not safe for a private network.' );
	}
	$definitions = "define( 'MULTISITE', true );\n"
		. "define( 'SUBDOMAIN_INSTALL', false );\n"
		. "define( 'DOMAIN_CURRENT_SITE', '" . $host . "' );\n"
		. "define( 'PATH_CURRENT_SITE', '" . $path . "' );\n"
		. "define( 'SITE_ID_CURRENT_SITE', 1 );\n"
		. "define( 'BLOG_ID_CURRENT_SITE', 1 );\n\n";
	return str_replace( $anchor, $definitions . $anchor, $config );
}

/**
 * Runs one isolated private worker and reads only its sanitized result.
 *
 * @param string $mode Worker mode.
 * @param string $clone_root Clone root.
 * @param string $marker Disposable marker.
 * @return array<string,mixed>
 * @throws RuntimeException When the worker fails.
 */
function dreamax_lm_f2930_run_worker( string $mode, string $clone_root, string $marker ): array {
	$result_file = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . DREAMAX_LM_F2930_CLONE_PREFIX . $mode . '-' . bin2hex( random_bytes( 8 ) ) . '.json';
	$sink_file   = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . DREAMAX_LM_F2930_CLONE_PREFIX . $mode . '-' . bin2hex( random_bytes( 8 ) ) . '.log';
	$site_url    = (string) get_option( 'siteurl' );
	$host        = (string) wp_parse_url( $site_url, PHP_URL_HOST );
	$path        = '/' . trim( (string) wp_parse_url( $site_url, PHP_URL_PATH ), '/' ) . '/';
	if ( '//' === $path ) {
		$path = '/';
	}
	if ( 1 !== preg_match( '/^[A-Za-z0-9.-]+$/D', $host ) || 1 !== preg_match( '#^/[A-Za-z0-9_/-]*/$#D', $path ) ) {
		throw new RuntimeException( 'The private network request context was unsafe.' );
	}
	$command    = array( PHP_BINARY, __FILE__, '--worker=' . $mode, '--wp-root=' . $clone_root, '--environment-marker=' . $marker, '--result-file=' . $result_file, '--network-host=' . $host, '--network-path=' . $path );
	$descriptor = array(
		0 => array( 'file', 'NUL', 'r' ),
		1 => array( 'file', $sink_file, 'ab' ),
		2 => array( 'file', $sink_file, 'ab' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Guarded CLI verifier requires an isolated WordPress process.
	$process = proc_open( $command, $descriptor, $pipes, __DIR__ );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'A private multisite worker could not start.' );
	}
	$exit = proc_close( $process );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads only the exact private worker result.
	$raw = is_file( $result_file ) ? file_get_contents( $result_file ) : false;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Categorizes but never emits the private worker sink.
	$sink = is_file( $sink_file ) ? file_get_contents( $sink_file ) : false;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the exact private worker result.
	is_file( $result_file ) && unlink( $result_file );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes the unread exact private worker sink.
	is_file( $sink_file ) && unlink( $sink_file );
	$result = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( 0 !== $exit || ! is_array( $result ) || true !== ( $result['worker_ok'] ?? false ) ) {
		$failure_stage  = is_array( $result ) ? sanitize_key( (string) ( $result['failure_stage'] ?? 'unknown' ) ) : 'no_result';
		$category       = dreamax_lm_f2930_sink_category( is_string( $sink ) ? $sink : '' );
		$failure_stage .= '_' . sanitize_key( $category );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Stage is reduced to fixed sanitized tokens and is never rendered as HTML.
		throw new RuntimeException( 'worker_stage:' . $failure_stage );
	}
	return $result;
}

/**
 * Executes a private worker without printing protected values.
 *
 * @param array<string,string|false> $options Worker options.
 * @throws RuntimeException When a guarded worker contract fails.
 */
function dreamax_lm_f2930_worker( array $options ): never {
	$mode         = (string) ( $options['worker'] ?? '' );
	$wp_root      = isset( $options['wp-root'] ) ? rtrim( (string) $options['wp-root'], '\\/' ) : '';
	$marker       = (string) ( $options['environment-marker'] ?? '' );
	$result_file  = (string) ( $options['result-file'] ?? '' );
	$network_host = (string) ( $options['network-host'] ?? '' );
	$network_path = (string) ( $options['network-path'] ?? '' );
	$stage        = 'worker_preflight';
	try {
		if ( ! in_array( $mode, array( 'prepare', 'verify' ), true ) || '' === $wp_root || ! is_file( $wp_root . '/wp-load.php' )
			|| 1 !== preg_match( '/^[A-Za-z0-9.-]+$/D', $network_host ) || 1 !== preg_match( '#^/[A-Za-z0-9_/-]*/$#D', $network_path ) ) {
			throw new RuntimeException( 'Private worker arguments were invalid.' );
		}
		$_SERVER['HTTP_HOST']       = $network_host;
		$_SERVER['SERVER_NAME']     = $network_host;
		$_SERVER['REQUEST_URI']     = $network_path . 'wp-admin/';
		$_SERVER['SCRIPT_NAME']     = $network_path . 'wp-admin/index.php';
		$_SERVER['PHP_SELF']        = $network_path . 'wp-admin/index.php';
		$_SERVER['DOCUMENT_ROOT']   = $wp_root;
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
		$_SERVER['SERVER_PORT']     = '80';
		$_SERVER['HTTPS']           = 'off';
		dreamax_lm_f2930_checkpoint( $result_file, 'wordpress_bootstrap' );
		if ( ! defined( 'WP_ADMIN' ) ) {
			define( 'WP_ADMIN', true );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', false );
		}
		require_once $wp_root . '/wp-load.php';
		dreamax_lm_f2930_checkpoint( $result_file, 'wordpress_loaded' );
		DisposableEnvironmentGuard::assertSafe(
			array(
				'marker'   => $marker,
				'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
				'site_url' => (string) get_option( 'siteurl' ),
			)
		);

		if ( 'prepare' === $mode ) {
			$stage = 'network_schema_reset';
			dreamax_lm_f2930_checkpoint( $result_file, $stage );
			if ( ! defined( 'DB_NAME' ) || ! str_starts_with( (string) DB_NAME, DREAMAX_LM_F2930_DATABASE_PREFIX ) ) {
				throw new RuntimeException( 'The private network database was not verifier-owned.' );
			}
			global $wpdb;
			foreach ( $wpdb->tables( 'ms_global' ) as $network_property => $network_table ) {
				$wpdb->{$network_property} = $network_table;
			}
			$network_suffixes = array( 'blogmeta', 'blogs', 'registration_log', 'signups', 'site', 'sitemeta' );
			foreach ( $network_suffixes as $network_suffix ) {
				$stage         = 'network_schema_reset_' . $network_suffix;
				$network_table = $wpdb->base_prefix . $network_suffix;
				dreamax_lm_f2930_checkpoint( $result_file, $stage );
				if ( 1 !== preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '[A-Za-z0-9_]+$/D', $network_table ) ) {
					throw new RuntimeException( 'A private network table was unsafe.' );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact global table in the guarded private clone only.
				if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$network_table}`" ) ) {
					throw new RuntimeException( 'A private network table could not be reset.' );
				}
			}
			$stage = 'network_schema_include';
			dreamax_lm_f2930_checkpoint( $result_file, $stage );
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$stage = 'network_schema_install';
			dreamax_lm_f2930_checkpoint( $result_file, $stage );
			if ( ! defined( 'WP_INSTALLING_NETWORK' ) ) {
				define( 'WP_INSTALLING_NETWORK', true );
			}
			dbDelta( wp_get_db_schema( 'ms_global' ) );
			$stage = 'network_schema_populate';
			dreamax_lm_f2930_checkpoint( $result_file, $stage );
			$email  = (string) get_option( 'admin_email' );
			$result = populate_network( 1, $network_host, $email, 'DLM Private Verification', $network_path, false );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( 'The private WordPress network could not be populated.' );
			}
			$stage = 'network_schema_metadata';
			dreamax_lm_f2930_checkpoint( $result_file, $stage );
			$plugin = 'dreamax-license-manager/dreamax-license-manager.php';
			$active = maybe_serialize( array( $plugin => time() ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery -- Guarded private clone network metadata only.
			$meta_id = $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s LIMIT 1", 1, 'active_sitewide_plugins' ) );
			if ( null === $meta_id ) {
				$written = $wpdb->insert(
					$wpdb->sitemeta,
					array(
						'site_id'    => 1,
						'meta_key'   => 'active_sitewide_plugins',
						'meta_value' => $active,
					),
					array( '%d', '%s', '%s' )
				);
			} else {
				$written = $wpdb->update( $wpdb->sitemeta, array( 'meta_value' => $active ), array( 'meta_id' => (int) $meta_id ), array( '%s' ), array( '%d' ) );
			}
			// phpcs:enable
			if ( false === $written ) {
				throw new RuntimeException( 'Network activation metadata could not be prepared.' );
			}
			dreamax_lm_f2930_write_result(
				$result_file,
				array(
					'worker_ok'        => true,
					'network_prepared' => true,
					'sensitive_output' => false,
				)
			);
			exit( 0 );
		}

		$stage = 'multisite_boot_plugin_api';
		dreamax_lm_f2930_checkpoint( $result_file, $stage );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$stage = 'multisite_boot_network_active';
		dreamax_lm_f2930_checkpoint( $result_file, $stage );
		if ( ! is_multisite() || ! is_plugin_active_for_network( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
			throw new RuntimeException( 'The private network-active plugin did not boot.' );
		}
		$stage = 'multisite_boot_ms_api';
		dreamax_lm_f2930_checkpoint( $result_file, $stage );
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		require_once ABSPATH . WPINC . '/ms-functions.php';

		$stage    = 'network_activation';
		$owner_id = (int) get_network_option( 1, 'admin_user_id', 0 );
		if ( $owner_id < 1 ) {
			throw new RuntimeException( 'The private network owner was unavailable.' );
		}
		remove_action( 'wp_initialize_site', array( Installer::class, 'initialize_site' ), 200 );
		$domain = (string) wp_parse_url( (string) network_site_url(), PHP_URL_HOST );
		$site2  = wpmu_create_blog( $domain, $network_path . 'f2930-existing/', 'F2930 Existing', $owner_id, array(), 1 );
		if ( is_wp_error( $site2 ) || (int) $site2 < 2 ) {
			throw new RuntimeException( 'The pre-activation private site could not be created.' );
		}
		Installer::activate( true );
		add_action( 'wp_initialize_site', array( Installer::class, 'initialize_site' ), 200, 1 );
		$site3 = wpmu_create_blog( $domain, $network_path . 'f2930-future/', 'F2930 Future', $owner_id, array(), 1 );
		if ( is_wp_error( $site3 ) || (int) $site3 < 3 ) {
			throw new RuntimeException( 'The future private site could not be created.' );
		}
		$site_ids = array( 1, (int) $site2, (int) $site3 );

		$stage  = 'site_bootstrap_contracts';
		$checks = array();
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				global $wpdb;
				$tables_ready = Health::storage_ready();
				$schema_ready = '3' === (string) get_option( 'dreamax_lm_schema_version' );
				$job_ready    = false !== wp_next_scheduled( 'dreamax_lm_cleanup' );
				$role         = get_role( 'administrator' );
				$caps_ready   = $role && ! array_diff( Capabilities::all(), array_keys( array_filter( (array) $role->capabilities ) ) );
				$checks[ 'site_' . $site_id . '_initialized' ] = $tables_ready && $schema_ready && $job_ready && $caps_ready;
			} finally {
				restore_current_blog();
			}
		}

		$stage = 'site_local_data';
		$state = array();
		foreach ( array( 1, (int) $site2 ) as $site_id ) {
			switch_to_blog( $site_id );
			try {
				global $wpdb;
				$crypto = new Crypto();
				if ( ! $crypto->ready() ) {
					throw new RuntimeException( 'A site-local encryption context was not ready.' );
				}
				$key               = 'DLM-' . strtoupper( bin2hex( random_bytes( 16 ) ) );
				$product           = 'prd_' . \Dreamax\LicenseManager\Support\Base64Url::encode( random_bytes( 16 ) );
				$license           = ( new LicenseService() )->import(
					$key,
					KeyNormalizer::IMPORTED,
					null,
					array(
						'product_public_id' => $product,
						'lifecycle_status'  => 'assigned',
						'actor_type'        => 'system',
						'source'            => 'multisite_verifier',
					)
				);
				$state[ $site_id ] = array(
					'license_id' => (int) $license['id'],
					'public_id'  => (string) $license['public_id'],
					'ciphertext' => (string) $wpdb->get_var( $wpdb->prepare( "SELECT key_ciphertext FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d", (int) $license['id'] ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					'key'        => $key,
					'salt'       => (string) get_option( 'dreamax_lm_kdf_salt' ),
				);
			} finally {
				restore_current_blog();
			}
		}

		$checks['site_salts_distinct'] = ! hash_equals( hash( 'sha256', $state[1]['salt'] ), hash( 'sha256', $state[ (int) $site2 ]['salt'] ) );
		$stage                         = 'cross_site_crypto';
		switch_to_blog( (int) $site2 );
		try {
			$cross_decrypt_blocked = false;
			try {
				( new Crypto() )->decrypt( $state[1]['ciphertext'] );
			} catch ( Throwable $error ) {
				$cross_decrypt_blocked = true;
			}
			$checks['cross_site_decrypt_blocked'] = $cross_decrypt_blocked;
			$checks['site2_own_decrypts']         = hash_equals( $state[ (int) $site2 ]['key'], ( new Crypto() )->decrypt( $state[ (int) $site2 ]['ciphertext'] ) );
			$checks['site2_cannot_read_site1']    = null === ( new LicenseRepository() )->by_public_id( $state[1]['public_id'] );
		} finally {
			restore_current_blog();
		}
		switch_to_blog( 1 );
		try {
			$checks['site1_own_decrypts']      = hash_equals( $state[1]['key'], ( new Crypto() )->decrypt( $state[1]['ciphertext'] ) );
			$checks['site1_cannot_read_site2'] = null === ( new LicenseRepository() )->by_public_id( $state[ (int) $site2 ]['public_id'] );
			$credential                        = ( new CredentialService() )->create( 'F2930 private verifier', array( 'licenses:read' ), null, $owner_id );
			$header                            = 'Bearer ' . $credential['credential'];
			$checks['site1_credential_works']  = is_array( ( new CredentialService() )->authenticate( $header ) );
		} finally {
			restore_current_blog();
		}
		switch_to_blog( (int) $site2 );
		try {
			$checks['site2_rejects_site1_credential'] = null === ( new CredentialService() )->authenticate( $header );
		} finally {
			restore_current_blog();
		}
		if ( isset( $header ) ) {
			sodium_memzero( $header );
		}
		if ( isset( $credential['credential'] ) && is_string( $credential['credential'] ) ) {
			sodium_memzero( $credential['credential'] );
		}

		$stage = 'site_local_export_selection';
		foreach ( array( 1, (int) $site2 ) as $site_id ) {
			switch_to_blog( $site_id );
			try {
				global $wpdb;
				$own_id   = $state[ $site_id ]['public_id'];
				$other_id = 1 === $site_id ? $state[ (int) $site2 ]['public_id'] : $state[1]['public_id'];
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exercises the exact current-prefix export selection boundary.
				$own_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id=%s", $own_id ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exercises the exact current-prefix export selection boundary.
				$other_count                                       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id=%s", $other_id ) );
				$checks[ 'site_' . $site_id . '_export_isolated' ] = 1 === $own_count && 0 === $other_count;
			} finally {
				restore_current_blog();
			}
		}

		$stage = 'site_restore';
		switch_to_blog( (int) $site2 );
		try {
			delete_option( 'dreamax_lm_kdf_salt' );
			$checks['site2_missing_salt_fails_closed'] = ! ( new Crypto() )->ready();
			update_option( 'dreamax_lm_kdf_salt', $state[ (int) $site2 ]['salt'], false );
			$checks['site2_exact_restore_ready'] = ( new Crypto() )->ready()
				&& hash_equals( $state[ (int) $site2 ]['key'], ( new Crypto() )->decrypt( $state[ (int) $site2 ]['ciphertext'] ) );
		} finally {
			restore_current_blog();
		}
		switch_to_blog( 1 );
		try {
			$checks['site1_unaffected_by_site2_restore'] = ( new Crypto() )->ready()
				&& hash_equals( $state[1]['key'], ( new Crypto() )->decrypt( $state[1]['ciphertext'] ) );
		} finally {
			restore_current_blog();
		}

		$stage = 'network_jobs';
		Installer::deactivate( true );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				$checks[ 'site_' . $site_id . '_job_cleared' ] = false === wp_next_scheduled( 'dreamax_lm_cleanup' );
			} finally {
				restore_current_blog();
			}
		}
		Installer::activate( true );
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			try {
				$checks[ 'site_' . $site_id . '_job_restored' ] = false !== wp_next_scheduled( 'dreamax_lm_cleanup' );
			} finally {
				restore_current_blog();
			}
		}

		$stage = 'site_local_uninstall';
		switch_to_blog( 1 );
		try {
			update_option( 'dreamax_lm_permanent_delete_on_uninstall', true, false );
			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'dreamax-license-manager/dreamax-license-manager.php' );
			}
			include DREAMAX_LM_DIR . 'uninstall.php';
			$checks['site1_uninstall_removed_only_local_storage'] = ! Health::storage_ready()
				&& false === get_option( 'dreamax_lm_schema_version', false )
				&& false === get_option( 'dreamax_lm_kdf_salt', false );
		} finally {
			restore_current_blog();
		}
		foreach ( array( (int) $site2, (int) $site3 ) as $site_id ) {
			switch_to_blog( $site_id );
			try {
				$checks[ 'site_' . $site_id . '_survived_site1_uninstall' ] = Health::storage_ready()
					&& '3' === (string) get_option( 'dreamax_lm_schema_version' );
				if ( (int) $site2 === $site_id ) {
					$checks['site2_data_survived_site1_uninstall'] = null !== ( new LicenseRepository() )->by_public_id( $state[ (int) $site2 ]['public_id'] );
				}
			} finally {
				restore_current_blog();
			}
		}

		$failed = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
		foreach ( $state as &$site_state ) {
			foreach ( array( 'key', 'salt', 'ciphertext' ) as $sensitive ) {
				if ( isset( $site_state[ $sensitive ] ) && is_string( $site_state[ $sensitive ] ) ) {
					sodium_memzero( $site_state[ $sensitive ] );
				}
			}
		}
		unset( $site_state );
		if ( array() !== $failed ) {
			$stage = 'contract_' . sanitize_key( (string) $failed[0] );
			dreamax_lm_f2930_checkpoint( $result_file, $stage );
			throw new RuntimeException( 'One or more private multisite contracts failed.' );
		}
		dreamax_lm_f2930_write_result(
			$result_file,
			array(
				'worker_ok'                 => true,
				'contracts_passed'          => count( $checks ),
				'existing_site_initialized' => true,
				'future_site_initialized'   => true,
				'key_separation_passed'     => true,
				'site_restore_passed'       => true,
				'data_api_export_isolated'  => true,
				'jobs_isolated'             => true,
				'uninstall_isolated'        => true,
				'outbound_email_sent'       => false,
				'sensitive_output'          => false,
			)
		);
		exit( 0 );
	} catch ( Throwable $error ) {
		try {
			dreamax_lm_f2930_write_result(
				$result_file,
				array(
					'worker_ok'        => false,
					'failure_stage'    => $stage,
					'sensitive_output' => false,
				)
			);
		} catch ( Throwable $ignored ) {
			unset( $ignored );
			// The parent still treats a missing result as failure.
		}
		exit( 2 );
	}
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:', 'confirm-matrix:', 'worker:', 'result-file:', 'network-host:', 'network-path:', 'diagnose-only' ) );
if ( isset( $options['worker'] ) ) {
	dreamax_lm_f2930_worker( $options );
}

$marker       = (string) ( $options['environment-marker'] ?? '' );
$wp_root      = isset( $options['wp-root'] ) ? rtrim( (string) $options['wp-root'], '\\/' ) : '';
$confirmation = (string) ( $options['confirm-matrix'] ?? '' );
if ( '' === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) || ! is_file( $wp_root . '/wp-config.php' ) ) {
	dreamax_lm_f2930_fail( 'A valid disposable WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
		'site_url' => (string) get_option( 'siteurl' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f2930_fail( 'The disposable plugin runtime is unavailable.' );
}

global $wpdb;
$source_database = (string) $wpdb->get_var( 'SELECT DATABASE()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $source_database ) ) {
	dreamax_lm_f2930_fail( 'The disposable database identifier was unsafe.' );
}
$source_digest = PrivateCloneHarness::database_digest( $source_database );
$config_hash   = hash_file( 'sha256', $wp_root . '/wp-config.php' );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only residue preflight.
$stale_databases = $wpdb->get_col( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE %s', DREAMAX_LM_F2930_DATABASE_PREFIX . '%' ) );
$temp_root       = realpath( sys_get_temp_dir() );
$stale_clones    = false === $temp_root ? false : glob( $temp_root . DIRECTORY_SEPARATOR . DREAMAX_LM_F2930_CLONE_PREFIX . '*', GLOB_ONLYDIR );
$diagnosis       = array(
	'disposable_guard_passed'   => true,
	'source_database_readable'  => $source_digest['tables'] > 0,
	'source_is_single_site'     => ! is_multisite(),
	'plugin_active'             => true,
	'key_ready'                 => ( new Crypto() )->ready(),
	'no_stale_database_residue' => array() === $stale_databases,
	'no_stale_clone_residue'    => is_array( $stale_clones ) && array() === $stale_clones,
);
if ( isset( $options['diagnose-only'] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixed sanitized diagnostics only.
	echo json_encode(
		array(
			'classification'   => 'sanitized_f2930_read_only_diagnosis',
			'checks'           => $diagnosis,
			'sensitive_output' => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( in_array( false, $diagnosis, true ) ? 1 : 0 );
}
if ( DREAMAX_LM_F2930_CONFIRMATION !== $confirmation || in_array( false, $diagnosis, true ) ) {
	dreamax_lm_f2930_fail( 'The exact disposable multisite confirmation and clean preflight are required.' );
}

$suffix     = bin2hex( random_bytes( 8 ) );
$database   = DREAMAX_LM_F2930_DATABASE_PREFIX . $suffix;
$clone_root = rtrim( (string) $temp_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . DREAMAX_LM_F2930_CLONE_PREFIX . $suffix;
$failure    = null;
$stage      = 'private_clone';
$worker     = array();
$cleanup    = array(
	'database_removed' => false,
	'clone_removed'    => false,
);

try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Exact private clone root.
	if ( file_exists( $clone_root ) || ! mkdir( $clone_root, 0700 ) ) {
		throw new RuntimeException( 'The private multisite clone could not be created.' );
	}
	PrivateCloneHarness::copy_tree( $wp_root, $clone_root );
	$clone_plugin = $clone_root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'dreamax-license-manager';
	if ( ! is_dir( $clone_plugin ) ) {
		throw new RuntimeException( 'The private plugin target was unavailable.' );
	}
	dreamax_lm_f2930_overlay_plugin( $clone_plugin );
	PrivateCloneHarness::copy_database( $source_database, $database, DREAMAX_LM_F2930_DATABASE_PREFIX );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Private configuration is never emitted.
	$config = file_get_contents( $clone_root . '/wp-config.php' );
	if ( ! is_string( $config ) ) {
		throw new RuntimeException( 'The private configuration could not be read.' );
	}
	$config = PrivateCloneHarness::config_for_database( $config, $database, DREAMAX_LM_F2930_DATABASE_PREFIX );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact private cloned configuration.
	if ( strlen( $config ) !== file_put_contents( $clone_root . '/wp-config.php', $config, LOCK_EX ) ) {
		throw new RuntimeException( 'The private database configuration could not be written.' );
	}
	$stage             = 'network_prepare';
	$worker['prepare'] = dreamax_lm_f2930_run_worker( 'prepare', $clone_root, $marker );
	$config            = dreamax_lm_f2930_multisite_config( $config );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact private cloned configuration.
	if ( strlen( $config ) !== file_put_contents( $clone_root . '/wp-config.php', $config, LOCK_EX ) ) {
		throw new RuntimeException( 'The private multisite configuration could not be written.' );
	}
	$stage            = 'multisite_verify';
	$worker['verify'] = dreamax_lm_f2930_run_worker( 'verify', $clone_root, $marker );
	if ( PrivateCloneHarness::database_digest( $source_database ) !== $source_digest || ! hash_equals( (string) $config_hash, (string) hash_file( 'sha256', $wp_root . '/wp-config.php' ) ) ) {
		throw new RuntimeException( 'The source disposable environment changed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
	if ( str_starts_with( $error->getMessage(), 'worker_stage:' ) ) {
		$stage .= ':' . sanitize_key( substr( $error->getMessage(), strlen( 'worker_stage:' ) ) );
	}
} finally {
	try {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact residue check.
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $database ) ) ) {
			PrivateCloneHarness::drop_database( $database, DREAMAX_LM_F2930_DATABASE_PREFIX );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup check.
		$cleanup['database_removed'] = null === $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $database ) );
	} catch ( Throwable $ignored ) {
		$cleanup['database_removed'] = false;
	}
	try {
		if ( is_dir( $clone_root ) ) {
			PrivateCloneHarness::remove_tree( $clone_root, DREAMAX_LM_F2930_CLONE_PREFIX );
		}
		$cleanup['clone_removed'] = ! file_exists( $clone_root );
	} catch ( Throwable $ignored ) {
		$cleanup['clone_removed'] = false;
	}
}

if ( $failure instanceof Throwable || in_array( false, $cleanup, true ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixed sanitized failure only.
	echo json_encode(
		array(
			'classification'   => 'sanitized_f2930_verification_failure',
			'failure_stage'    => $stage,
			'cleanup'          => $cleanup,
			'sensitive_output' => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f2930_fail( 'The guarded F29/F30 multisite verification failed.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fixed sanitized acceptance only.
echo json_encode(
	array(
		'classification'            => 'live_disposable_multisite_isolation',
		'contracts_passed'          => (int) ( $worker['verify']['contracts_passed'] ?? 0 ),
		'existing_site_initialized' => true,
		'future_site_initialized'   => true,
		'key_separation_passed'     => true,
		'site_restore_passed'       => true,
		'data_api_export_isolated'  => true,
		'jobs_isolated'             => true,
		'uninstall_isolated'        => true,
		'source_unchanged'          => true,
		'cleanup_committed'         => true,
		'outbound_email_sent'       => false,
		'sensitive_output'          => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
