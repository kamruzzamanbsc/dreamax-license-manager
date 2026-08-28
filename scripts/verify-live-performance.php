<?php
/**
 * Verifies the complete F24 full performance profile on a disposable site.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\ReleaseTools\PerformanceFixture;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a fixed sanitized error.
 *
 * @param string $message Sanitized message.
 */
function dreamax_lm_f24_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier emits only fixed sanitized messages.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Reserves a loopback TCP port.
 *
 * @throws RuntimeException When a loopback port cannot be reserved.
 */
function dreamax_lm_f24_port(): int {
	$error_code = 0;
	$error_text = '';
	$socket     = stream_socket_server( 'tcp://127.0.0.1:0', $error_code, $error_text );
	if ( false === $socket ) {
		unset( $error_code, $error_text );
		throw new RuntimeException( 'A loopback port was unavailable.' );
	}
	$name = stream_socket_get_name( $socket, false );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes an ephemeral socket.
	fclose( $socket );
	if ( ! is_string( $name ) || 1 !== preg_match( '/:(\d+)$/D', $name, $matches ) ) {
		throw new RuntimeException( 'The loopback port could not be resolved.' );
	}
	return (int) $matches[1];
}

/**
 * Creates a temporary authenticated administrator session.
 *
 * @param int $user_id User ID.
 * @return array{cookie:string,nonce:string}
 * @throws RuntimeException When a session cannot be created.
 */
function dreamax_lm_f24_auth( int $user_id ): array {
	$expiration = time() + 30 * MINUTE_IN_SECONDS;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	$auth       = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
	$name       = defined( 'LOGGED_IN_COOKIE' ) ? (string) constant( 'LOGGED_IN_COOKIE' ) : '';
	if ( '' === $name || '' === $auth ) {
		throw new RuntimeException( 'A temporary performance session could not be created.' );
	}
	wp_set_current_user( $user_id );
	$_COOKIE[ $name ] = $auth;
	return array(
		'cookie' => $name . '=' . $auth,
		'nonce'  => wp_create_nonce( 'dreamax_lm_export_csv' ),
	);
}

/**
 * Requests a masked production CSV export through loopback HTTP.
 *
 * @param string $url Loopback URL.
 * @param string $cookie Runtime Cookie header.
 * @param string $nonce Export nonce.
 * @return array{status:int,body:string,headers:array<string,string>,peak:int}
 * @throws RuntimeException When the loopback request fails.
 */
function dreamax_lm_f24_export( string $url, string $cookie, string $nonce ): array {
	$headers = array();
	// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- Owned loopback-only HTTP request.
	$handle = curl_init( $url );
	if ( false === $handle ) {
		throw new RuntimeException( 'The loopback export request could not be initialized.' );
	}
	curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $handle, CURLOPT_TIMEOUT, 300 );
	curl_setopt( $handle, CURLOPT_POST, true );
	curl_setopt(
		$handle,
		CURLOPT_POSTFIELDS,
		array(
			'action'   => 'dreamax_lm_export_csv',
			'_wpnonce' => $nonce,
		)
	);
	curl_setopt( $handle, CURLOPT_HTTPHEADER, array( 'Cookie: ' . $cookie ) );
	curl_setopt(
		$handle,
		CURLOPT_HEADERFUNCTION,
		static function ( $curl, string $line ) use ( &$headers ): int {
			unset( $curl );
			$length = strlen( $line );
			$parts  = explode( ':', $line, 2 );
			if ( 2 === count( $parts ) ) {
				$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
			}
			return $length;
		}
	);
	$body   = curl_exec( $handle );
	$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	curl_close( $handle );
	// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close
	if ( ! is_string( $body ) ) {
		throw new RuntimeException( 'The loopback export request failed.' );
	}
	return array(
		'status'  => $status,
		'body'    => $body,
		'headers' => $headers,
		'peak'    => (int) ( $headers['x-dreamax-f24-peak-memory'] ?? 0 ),
	);
}

/**
 * Counts owned rows in a masked CSV body without retaining row values.
 *
 * @param string $body CSV body.
 * @param string $product_public_id Owned product identity.
 */
function dreamax_lm_f24_csv_count( string $body, string $product_public_id ): int {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory stream only.
	$stream = fopen( 'php://temp', 'w+b' );
	if ( false === $stream ) {
		return -1;
	}
	fwrite( $stream, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory stream only.
	rewind( $stream );
	$headers       = fgetcsv( $stream );
	$product_index = is_array( $headers ) ? array_search( 'product_public_id', $headers, true ) : false;
	$count         = 0;
	if ( false !== $product_index ) {
		while ( true ) {
			$row = fgetcsv( $stream );
			if ( ! is_array( $row ) ) {
				break;
			}
			if ( ( $row[ $product_index ] ?? null ) === $product_public_id ) {
				++$count;
			}
		}
	}
	fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory stream only.
	return $count;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f24_fail( 'A valid WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
if ( ! defined( 'DB_NAME' ) ) {
	dreamax_lm_f24_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! extension_loaded( 'curl' ) ) {
	dreamax_lm_f24_fail( 'The required performance runtime is unavailable.' );
}

global $wpdb;
$license_table = $wpdb->prefix . 'dreamax_lm_licenses';
$event_table   = $wpdb->prefix . 'dreamax_lm_events';
$tables        = array( $wpdb->users, $wpdb->usermeta, $license_table, $event_table );
foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Safety preflight requires fresh engine metadata.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f24_fail( 'A required performance table is not transactional.' );
	}
}

$profile            = PerformanceFixture::profile( 'full' );
$fixture            = new PerformanceFixture( 'free-v1-live-full-20260828' );
$run_id             = $fixture->runId();
$owned_metadata     = (string) wp_json_encode( array( 'fixture_run_id' => $run_id ) );
$product_public_id  = 'prd_' . substr( hash( 'sha256', $run_id . '|product' ), 0, 22 );
$license_batch_size = 100;
$event_batch_size   = 500;
$memory_ceiling     = 256 * MB_IN_BYTES;
$temporary_user_id  = 0;
$temporary_password = '';
$session            = array();
$server             = null;
$server_pipes       = array();
$failure            = null;
$cleanup_failure    = null;
$cleanup_committed  = false;
$user_before        = get_current_user_id();
$cookie_before      = $_COOKIE;
$database_started   = hrtime( true );
$license_seconds    = 0.0;
$event_seconds      = 0.0;
$admin_seconds      = 0.0;
$api_seconds        = 0.0;
$export_seconds     = 0.0;
$peak_delta         = 0;
$checks             = array();
$verification_stage = 'preflight';

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted plugin table names and fresh direct queries are required for the isolated performance verifier.
$snapshot = static function () use ( $wpdb, $license_table, $event_table ): array {
	return array(
		'users'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'usermeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ),
		'licenses' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$license_table}" ),
		'events'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$event_table}" ),
	);
};
$sentinel = static function () use ( $wpdb, $license_table, $event_table ): string {
	$license = $wpdb->get_row( "SELECT * FROM {$license_table} ORDER BY id LIMIT 1", ARRAY_A );
	$event   = $wpdb->get_row( "SELECT * FROM {$event_table} ORDER BY id LIMIT 1", ARRAY_A );
	return hash( 'sha256', (string) wp_json_encode( array( $license, $event ) ) );
};

$before          = $snapshot();
$sentinel_before = $sentinel();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact stale-marker refusal.
$stale       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$license_table} WHERE metadata=%s)+(SELECT COUNT(*) FROM {$event_table} WHERE metadata=%s)", $owned_metadata, $owned_metadata ) );
$stale_users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'dlm_f24_' ) . '%' ) );
if ( 0 !== $stale || 0 !== $stale_users ) {
	dreamax_lm_f24_fail( 'A stale owned F24 fixture must be removed before running.' );
}

try {
	$verification_stage = 'temporary_actor';
	$temporary_password = bin2hex( random_bytes( 24 ) );
	$temporary_user_id  = wp_insert_user(
		array(
			'user_login' => 'dlm_f24_' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 ),
			'user_pass'  => $temporary_password,
			'user_email' => 'dlm-f24-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 ) . '@example.invalid',
			'role'       => 'administrator',
		)
	);
	if ( is_wp_error( $temporary_user_id ) ) {
		throw new RuntimeException( 'The temporary performance actor could not be created.' );
	}
	$temporary_user_id = (int) $temporary_user_id;
	$session           = dreamax_lm_f24_auth( $temporary_user_id );

	$crypto = new Crypto();
	if ( ! $crypto->ready() ) {
		throw new RuntimeException( 'Encryption was not ready for the performance fixture.' );
	}
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$memory_start = memory_get_usage( true );

	$verification_stage = 'license_insert';
	$license_started    = hrtime( true );
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Owned fixture transaction.
	for ( $batch_start = 0; $batch_start < $profile['licenses']; $batch_start += $license_batch_size ) {
		$values = array();
		$limit  = min( $profile['licenses'], $batch_start + $license_batch_size );
		for ( $index = $batch_start; $index < $limit; ++$index ) {
			$key        = 'F24' . strtoupper( substr( hash( 'sha256', $run_id . '|key|' . $index ), 0, 28 ) );
			$public_id  = 'lic_' . substr( hash( 'sha256', $run_id . '|license|' . $index ), 0, 22 );
			$created_at = gmdate( 'Y-m-d H:i:s', time() - $profile['licenses'] + $index );
			$values[]   = $wpdb->prepare(
				'(%s,%s,UNHEX(%s),%s,%s,%d,%s,%s,%d,%d,%d,%s,%s,%s)',
				$public_id,
				$crypto->encrypt( $key ),
				bin2hex( $crypto->fingerprint( $key ) ),
				'generated-ascii-v1',
				'-',
				1,
				0 === $index % 3 ? 'available' : 'assigned',
				$product_public_id,
				700000000 + intdiv( $index, 2 ),
				1 + $index % 2,
				2,
				$created_at,
				$created_at,
				$owned_metadata
			);
			$key        = str_repeat( "\0", strlen( $key ) );
		}
		$sql = "INSERT INTO {$license_table} (public_id,key_ciphertext,key_fingerprint,normalization_profile,normalization_separator,encryption_key_version,lifecycle_status,product_public_id,order_item_id,quantity_slot,activation_limit,created_at,updated_at,metadata) VALUES " . implode( ',', $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Every row fragment was prepared above; table is trusted.
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( 'An owned license batch could not be inserted.' );
		}
	}
	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commits only the owned fixture.
	$license_seconds = ( hrtime( true ) - $license_started ) / 1_000_000_000;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned identity map for linked event fixtures.
	$license_ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$license_table} WHERE metadata=%s ORDER BY id", $owned_metadata ) ) );
	if ( count( $license_ids ) !== $profile['licenses'] ) {
		throw new RuntimeException( 'The owned license fixture count did not match.' );
	}

	$verification_stage = 'event_insert';
	$event_started      = hrtime( true );
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Owned fixture transaction.
	for ( $batch_start = 0; $batch_start < $profile['events']; $batch_start += $event_batch_size ) {
		$values = array();
		$limit  = min( $profile['events'], $batch_start + $event_batch_size );
		for ( $index = $batch_start; $index < $limit; ++$index ) {
			$values[] = $wpdb->prepare(
				'(%s,%d,%s,%d,%s,%s,%s)',
				'evt_' . substr( hash( 'sha256', $run_id . '|event|' . $index ), 0, 22 ),
				$license_ids[ $index % $profile['licenses'] ],
				0 === $index % 2 ? AuditEventCatalog::LICENSE_CREATED : AuditEventCatalog::LICENSE_ASSIGNED,
				AuditEventCatalog::SCHEMA_V1,
				'system',
				gmdate( 'Y-m-d H:i:s' ),
				$owned_metadata
			);
		}
		$sql = "INSERT INTO {$event_table} (public_id,license_id,event_type,schema_version,actor_type,occurred_at,metadata) VALUES " . implode( ',', $values );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Every row fragment was prepared above; table is trusted.
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( 'An owned event batch could not be inserted.' );
		}
	}
	$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commits only the owned fixture.
	$event_seconds = ( hrtime( true ) - $event_started ) / 1_000_000_000;

	// Confirm the database-level duplicate order-slot contract without retaining the failed row.
	$verification_stage = 'duplicate_probe';
	$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Rollback-only duplicate probe.
	$previous_suppression = $wpdb->suppress_errors( true );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Deliberate duplicate-key probe using one owned source row.
	$duplicate_result = $wpdb->query( $wpdb->prepare( "INSERT INTO {$license_table} (public_id,key_ciphertext,key_fingerprint,normalization_profile,encryption_key_version,lifecycle_status,product_public_id,order_item_id,quantity_slot,created_at,updated_at,metadata) SELECT %s,key_ciphertext,UNHEX(%s),normalization_profile,encryption_key_version,lifecycle_status,product_public_id,order_item_id,quantity_slot,created_at,updated_at,metadata FROM {$license_table} WHERE metadata=%s ORDER BY id LIMIT 1", 'lic_' . substr( hash( 'sha256', $run_id . '|duplicate' ), 0, 22 ), hash( 'sha256', $run_id . '|duplicate-fingerprint' ), $owned_metadata ) );
	$wpdb->suppress_errors( $previous_suppression );
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Removes any rollback-only probe state.
	$checks['duplicate_slot_rejected'] = false === $duplicate_result;

	$verification_stage = 'admin_pagination';
	$admin_started      = hrtime( true );
	$admin_seen         = array();
	$admin_pages        = (int) ceil( $profile['licenses'] / 50 );
	for ( $page_index = 0; $page_index < $admin_pages; ++$page_index ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Executes the bounded production admin-list query shape through the final page.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT l.public_id FROM {$license_table} l LEFT JOIN (SELECT license_id,COUNT(*) AS active_count FROM {$wpdb->prefix}dreamax_lm_activations WHERE status='active' GROUP BY license_id) a ON a.license_id=l.id WHERE l.product_public_id=%s ORDER BY l.created_at DESC LIMIT 50 OFFSET %d", $product_public_id, $page_index * 50 ) );
		foreach ( $rows as $public_id ) {
			$admin_seen[] = (string) $public_id;
		}
	}
	$admin_seconds              = ( hrtime( true ) - $admin_started ) / 1_000_000_000;
	$checks['admin_pagination'] = count( $admin_seen ) === $profile['licenses'] && count( $admin_seen ) === count( array_unique( $admin_seen ) );
	unset( $admin_seen );

	$verification_stage = 'api_pagination';
	$api_started        = hrtime( true );
	$api_seen           = 0;
	$api_pages          = (int) ceil( $profile['licenses'] / 100 );
	for ( $page_index = 0; $page_index < $api_pages; ++$page_index ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Executes the exact bounded privileged-list query shape through the fixture final page.
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT product_public_id FROM {$license_table} ORDER BY id DESC LIMIT 100 OFFSET %d", $page_index * 100 ) );
		foreach ( $rows as $row_product_id ) {
			if ( $product_public_id === $row_product_id ) {
				++$api_seen;
			}
		}
	}
	$api_seconds              = ( hrtime( true ) - $api_started ) / 1_000_000_000;
	$checks['api_pagination'] = $profile['licenses'] === $api_seen;

	$verification_stage = 'event_pagination';
	$event_seen         = 0;
	$event_pages        = (int) ceil( $profile['events'] / $profile['page_size'] );
	for ( $page_index = 0; $page_index < $event_pages; ++$page_index ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded owned event traversal through the final page.
		$event_seen += count( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$event_table} WHERE metadata=%s ORDER BY id LIMIT 250 OFFSET %d", $owned_metadata, $page_index * $profile['page_size'] ) ) );
	}
	$checks['event_pagination'] = $profile['events'] === $event_seen;

	$verification_stage                    = 'server_start';
	$port                                  = dreamax_lm_f24_port();
	$environment                           = getenv();
	$environment                           = is_array( $environment ) ? $environment : array();
	$environment['DREAMAX_LM_F24_WP_ROOT'] = $wp_root;
	$environment['DREAMAX_LM_F24_MARKER']  = $marker;
	$null_device                           = defined( 'PHP_WINDOWS_VERSION_BUILD' ) ? 'NUL' : '/dev/null';
	$descriptors                           = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'file', $null_device, 'a' ),
		2 => array( 'file', $null_device, 'a' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Starts only the committed loopback router and terminates it in finally.
	$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/lib/performance-export-router.php' ), $descriptors, $server_pipes, __DIR__, $environment );
	if ( ! is_resource( $server ) ) {
		throw new RuntimeException( 'The loopback performance server could not start.' );
	}
	if ( isset( $server_pipes[0] ) && is_resource( $server_pipes[0] ) ) {
		fclose( $server_pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- No interactive input.
		unset( $server_pipes[0] );
	}
	$base  = 'http://127.0.0.1:' . $port;
	$ready = false;
	for ( $attempt = 0; $attempt < 100; ++$attempt ) {
		$health = wp_remote_get(
			$base . '/health',
			array(
				'timeout'     => 1,
				'redirection' => 0,
			)
		);
		if ( ! is_wp_error( $health ) && 204 === wp_remote_retrieve_response_code( $health ) ) {
			$ready = true;
			break;
		}
		usleep( 100000 );
	}
	if ( ! $ready ) {
		throw new RuntimeException( 'The loopback performance server did not become ready.' );
	}

	$verification_stage        = 'streamed_export';
	$export_started            = hrtime( true );
	$export                    = dreamax_lm_f24_export( $base . '/admin-post.php', (string) $session['cookie'], (string) $session['nonce'] );
	$export_seconds            = ( hrtime( true ) - $export_started ) / 1_000_000_000;
	$headers_ok                = 200 === $export['status']
		&& str_starts_with( strtolower( (string) ( $export['headers']['content-type'] ?? '' ) ), 'text/csv' )
		&& 1 === preg_match( '/^attachment; filename="dreamax-licenses-\d{8}-\d{6}\.csv"$/D', (string) ( $export['headers']['content-disposition'] ?? '' ) );
	$checks['streamed_export'] = $headers_ok
		&& dreamax_lm_f24_csv_count( $export['body'], $product_public_id ) === $profile['licenses']
		&& $export['peak'] > 0
		&& $export['peak'] <= $memory_ceiling;
	$export['body']            = '';

	$verification_stage       = 'final_assertions';
	$peak_delta               = max( 0, memory_get_peak_usage( true ) - $memory_start );
	$checks['bounded_memory'] = $peak_delta <= $memory_ceiling;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-run fixture counts.
	$checks['fixture_counts'] = $profile['licenses'] === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$license_table} WHERE metadata=%s", $owned_metadata ) )
		&& $profile['events'] === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$event_table} WHERE metadata=%s", $owned_metadata ) );
	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'The full performance contract did not match.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( is_resource( $server ) ) {
		$process_status = proc_get_status( $server );
		if ( $process_status['running'] ) {
			proc_terminate( $server );
		}
		foreach ( $server_pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Exact owned server pipe.
			}
		}
		proc_close( $server );
	}
	if ( $temporary_user_id > 0 ) {
		try {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact owned cleanup transaction.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Deletes only owned linked event fixtures.
			$wpdb->query( $wpdb->prepare( "DELETE e FROM {$event_table} e INNER JOIN {$license_table} l ON l.id=e.license_id WHERE l.metadata=%s AND e.metadata=%s", $owned_metadata, $owned_metadata ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Deletes only the temporary actor's export audit.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$event_table} WHERE actor_id=%d AND event_type=%s", $temporary_user_id, AuditEventCatalog::LICENSE_EXPORTED ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Deletes only exact owned license fixtures.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$license_table} WHERE metadata=%s AND product_public_id=%s", $owned_metadata, $product_public_id ) );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Commits exact owned cleanup only.
			$cleanup_committed = true;
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Rolls back failed cleanup.
			$cleanup_failure = $error;
		}
		WP_Session_Tokens::get_instance( $temporary_user_id )->destroy_all();
		if ( ! wp_delete_user( $temporary_user_id ) ) {
			$cleanup_failure = $cleanup_failure ?? new RuntimeException( 'The temporary performance actor could not be removed.' );
		}
	}
	$temporary_password = str_repeat( "\0", strlen( $temporary_password ) );
	$session            = array();
	wp_set_current_user( $user_before );
	$_COOKIE = $cookie_before;
	if ( function_exists( 'wp_cache_flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}
}

$after          = $snapshot();
$sentinel_after = $sentinel();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact final owned-row count.
$owned_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$license_table} WHERE metadata=%s)+(SELECT COUNT(*) FROM {$event_table} WHERE metadata=%s)", $owned_metadata, $owned_metadata ) );
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( $cleanup_failure instanceof Throwable || ! $cleanup_committed || $before !== $after || ! hash_equals( $sentinel_before, $sentinel_after ) || 0 !== $owned_left ) {
	dreamax_lm_f24_fail( 'The owned F24 fixtures were not completely removed.' );
}
if ( $failure instanceof Throwable ) {
	$failed = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Emits only fixed internal check labels.
	dreamax_lm_f24_fail( 'The guarded F24 verification failed at sanitized stage: ' . $verification_stage . '; checks: ' . ( $failed ? implode( ',', $failed ) : 'none' ) . '.' );
}

echo wp_json_encode(
	array(
		'classification'                  => 'live_disposable_wordpress_woocommerce_innodb_full_profile',
		'licenses'                        => $profile['licenses'],
		'events'                          => $profile['events'],
		'admin_pages'                     => $admin_pages,
		'api_pages'                       => $api_pages,
		'event_pages'                     => $event_pages,
		'license_insert_seconds'          => round( $license_seconds, 3 ),
		'event_insert_seconds'            => round( $event_seconds, 3 ),
		'admin_pagination_seconds'        => round( $admin_seconds, 3 ),
		'api_pagination_seconds'          => round( $api_seconds, 3 ),
		'export_seconds'                  => round( $export_seconds, 3 ),
		'total_database_seconds'          => round( ( hrtime( true ) - $database_started ) / 1_000_000_000, 3 ),
		'memory_delta_within_256_mib'     => $checks['bounded_memory'],
		'export_memory_within_256_mib'    => $checks['streamed_export'],
		'duplicate_slot_rejected'         => $checks['duplicate_slot_rejected'],
		'admin_pagination_complete'       => $checks['admin_pagination'],
		'api_pagination_complete'         => $checks['api_pagination'],
		'event_pagination_complete'       => $checks['event_pagination'],
		'streamed_masked_export_complete' => $checks['streamed_export'],
		'cleanup_committed'               => $cleanup_committed,
		'aggregates_unchanged'            => $before === $after,
		'sentinel_unchanged'              => hash_equals( $sentinel_before, $sentinel_after ),
		'owned_fixture_rows_remaining'    => $owned_left,
		'outbound_email_sent'             => false,
		'sensitive_output'                => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
