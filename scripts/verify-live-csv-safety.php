<?php
/**
 * Verifies the complete F19 CSV contract on a disposable WordPress site.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\ReleaseTools\Filesystem;
use Dreamax\LicenseManager\Support\Capabilities;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f19_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only sanitized failures.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Reserves one loopback TCP port.
 *
 * @throws RuntimeException When no port can be reserved.
 */
function dreamax_lm_f19_port(): int {
	$error_code = 0;
	$error_text = '';
	$socket     = stream_socket_server( 'tcp://127.0.0.1:0', $error_code, $error_text );
	if ( false === $socket ) {
		unset( $error_code, $error_text );
		throw new RuntimeException( 'A loopback port was unavailable.' );
	}
	$name = stream_socket_get_name( $socket, false );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes an ephemeral socket, not a file.
	fclose( $socket );
	if ( ! is_string( $name ) || ! preg_match( '/:(\d+)$/D', $name, $matches ) ) {
		throw new RuntimeException( 'The loopback port could not be resolved.' );
	}
	return (int) $matches[1];
}

/**
 * Writes an owned CSV fixture.
 *
 * @param string $path File path.
 * @param array  $headers Header row.
 * @phpstan-param list<string> $headers Header row.
 * @param array  $rows Data rows.
 * @phpstan-param list<list<string>> $rows Data rows.
 * @throws RuntimeException When the file cannot be written.
 */
function dreamax_lm_f19_csv( string $path, array $headers, array $rows ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Writes an exact temporary multipart fixture.
	$handle = fopen( $path, 'wb' );
	if ( false === $handle ) {
		throw new RuntimeException( 'A temporary CSV fixture could not be opened.' );
	}
	fputcsv( $handle, $headers );
	foreach ( $rows as $row ) {
		fputcsv( $handle, $row );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes an exact owned fixture.
	fclose( $handle );
	chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricts the exact owned sensitive fixture.
}

/**
 * Sends one loopback request without logging protected request or response data.
 *
 * @param string              $url Loopback endpoint.
 * @param string              $cookie Authenticated Cookie header value.
 * @param array<string,mixed> $fields Form fields.
 * @return array{status:int,body:string,headers:array<string,string>,peak:int,stage:string,fatal:string}
 * @throws RuntimeException When transport fails.
 */
function dreamax_lm_f19_request( string $url, string $cookie, array $fields ): array {
	$headers = array();
	// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- Multipart upload requires local cURL; the target is a loopback-only owned server.
	$handle = curl_init( $url );
	if ( false === $handle ) {
		throw new RuntimeException( 'A loopback request could not be initialized.' );
	}
	curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
	curl_setopt( $handle, CURLOPT_TIMEOUT, 45 );
	curl_setopt( $handle, CURLOPT_POST, true );
	curl_setopt( $handle, CURLOPT_POSTFIELDS, $fields );
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
		throw new RuntimeException( 'A loopback request failed.' );
	}
	return array(
		'status'  => $status,
		'body'    => $body,
		'headers' => $headers,
		'peak'    => (int) ( $headers['x-dreamax-f19-peak-memory'] ?? 0 ),
		'stage'   => (string) ( $headers['x-dreamax-f19-router-stage'] ?? 'none' ),
		'fatal'   => (string) ( $headers['x-dreamax-f19-fatal'] ?? 'none' ),
	);
}

/**
 * Classifies a response body without exposing its contents.
 *
 * @param string $body Response body.
 */
function dreamax_lm_f19_body_label( string $body ): string {
	if ( '' === trim( $body ) ) {
		return 'empty';
	}
	if ( str_contains( $body, 'not allowed' ) ) {
		return 'denied';
	}
	if ( str_contains( $body, 'Import' ) || str_contains( $body, 'valid rows' ) ) {
		return 'import';
	}
	if ( str_contains( $body, 'nonce' ) || str_contains( $body, 'Are you sure' ) ) {
		return 'nonce';
	}
	if ( str_contains( $body, '5 MiB' ) ) {
		return 'oversize';
	}
	if ( str_contains( $body, 'license_key' ) && str_contains( $body, 'public_id' ) ) {
		return 'export';
	}
	return 'other';
}

/**
 * Creates a disposable authenticated session and nonces.
 *
 * @param int   $user_id User ID.
 * @param array $actions Nonce actions.
 * @phpstan-param list<string> $actions Nonce actions.
 * @return array{cookie:string,nonces:array<string,string>}
 * @throws RuntimeException When authentication state cannot be created.
 */
function dreamax_lm_f19_auth( int $user_id, array $actions ): array {
	$expiration = time() + 15 * MINUTE_IN_SECONDS;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	$auth       = wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token );
	$name       = defined( 'LOGGED_IN_COOKIE' ) ? (string) constant( 'LOGGED_IN_COOKIE' ) : '';
	if ( '' === $name || '' === $auth ) {
		throw new RuntimeException( 'A temporary authenticated session could not be created.' );
	}
	wp_set_current_user( $user_id );
	$_COOKIE[ $name ] = $auth;
	$nonces           = array();
	foreach ( $actions as $action ) {
		$nonces[ $action ] = wp_create_nonce( $action );
	}
	return array(
		'cookie' => $name . '=' . $auth,
		'nonces' => $nonces,
	);
}

/**
 * Parses CSV output into rows keyed by the header.
 *
 * @param string $body CSV body.
 * @return list<array<string,string>>
 */
function dreamax_lm_f19_rows( string $body ): array {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Uses only an in-memory stream for protected export verification.
	$stream = fopen( 'php://temp', 'w+b' );
	if ( false === $stream ) {
		return array();
	}
	fwrite( $stream, $body ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes only to the in-memory stream.
	rewind( $stream );
	$headers = fgetcsv( $stream );
	$rows    = array();
	if ( is_array( $headers ) ) {
		while ( true ) {
			$values = fgetcsv( $stream );
			if ( ! is_array( $values ) ) {
				break;
			}
			$values = array_pad( $values, count( $headers ), '' );
			$row    = array_combine( $headers, array_slice( $values, 0, count( $headers ) ) );
			if ( is_array( $row ) ) {
				$rows[] = array_map( 'strval', $row );
			}
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the in-memory stream.
	fclose( $stream );
	return $rows;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f19_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f19_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! extension_loaded( 'curl' ) ) {
	dreamax_lm_f19_fail( 'The required runtime is unavailable.' );
}

global $wpdb;
$tables = array( $wpdb->users, $wpdb->usermeta, $wpdb->prefix . 'dreamax_lm_licenses', $wpdb->prefix . 'dreamax_lm_events' );
foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup requires transactional tables.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f19_fail( 'A required CSV verification table is not safely transactional.' );
	}
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A stale exact marker must block mutation.
$stale_users = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'dlm_f19_' ) . '%' ) );
if ( 0 !== $stale_users ) {
	dreamax_lm_f19_fail( 'A stale owned F19 fixture must be cleaned before running.' );
}

$snapshot = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact before/after evidence requires fresh aggregates.
	return array(
		'users'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'usermeta' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ),
		'licenses' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'events'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$before               = $snapshot();
$cookie_before        = $_COOKIE;
$user_before          = get_current_user_id();
$suffix               = substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 );
$temp_dir             = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f19-' . $suffix;
$product_public_id    = PublicId::generate( 'prd' );
$user_ids             = array();
$license_ids          = array();
$export_event_ids     = array();
$queued_job_tokens    = array();
$cookies              = array();
$keys                 = array();
$server               = null;
$server_pipes         = array();
$failure              = null;
$cleanup_failure      = null;
$cleanup_committed    = false;
$checks               = array();
$response_diagnostics = array();
$verification_stage   = 'user_setup';

if ( ! mkdir( $temp_dir, 0700 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates one exact private fixture directory.
	dreamax_lm_f19_fail( 'The private CSV fixture directory could not be created.' );
}

try {
	$roles = array( 'administrator', 'shop_manager', 'customer' );
	foreach ( $roles as $fixture_role ) {
		$password = bin2hex( random_bytes( 24 ) );
		$user_id  = wp_insert_user(
			array(
				'user_login' => 'dlm_f19_' . $fixture_role . '_' . $suffix,
				'user_pass'  => $password,
				'user_email' => 'dlm-f19-' . $fixture_role . '-' . $suffix . '@example.invalid',
				'role'       => $fixture_role,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'A temporary capability user could not be created.' );
		}
		$user_ids[ $fixture_role ] = (int) $user_id;
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $password );
		}
	}

	wp_set_current_user( $user_ids['administrator'] );
	$checks['admin_capabilities'] = current_user_can( Capabilities::MANAGE ) && current_user_can( Capabilities::EXPORT );
	wp_set_current_user( $user_ids['shop_manager'] );
	$checks['shop_capabilities'] = current_user_can( Capabilities::MANAGE ) && ! current_user_can( Capabilities::EXPORT );
	wp_set_current_user( $user_ids['customer'] );
	$checks['customer_capabilities'] = ! current_user_can( Capabilities::MANAGE ) && ! current_user_can( Capabilities::EXPORT );

	foreach ( $user_ids as $fixture_role => $user_id ) {
		$auth                     = dreamax_lm_f19_auth( $user_id, array( 'dreamax_lm_import_csv', 'dreamax_lm_export_csv' ) );
		$cookies[ $fixture_role ] = $auth;
	}
	wp_set_current_user( 0 );

	$verification_stage                    = 'server_start';
	$port                                  = dreamax_lm_f19_port();
	$environment                           = getenv();
	$environment                           = is_array( $environment ) ? $environment : array();
	$environment['DREAMAX_LM_F19_WP_ROOT'] = $wp_root;
	$environment['DREAMAX_LM_F19_MARKER']  = $marker;
	$null_device                           = defined( 'PHP_WINDOWS_VERSION_BUILD' ) ? 'NUL' : '/dev/null';
	$descriptors                           = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'file', $null_device, 'a' ),
		2 => array( 'file', $null_device, 'a' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Starts only the committed loopback router and terminates it in finally.
	$server = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/lib/csv-http-router.php' ), $descriptors, $server_pipes, __DIR__, $environment );
	if ( ! is_resource( $server ) ) {
		throw new RuntimeException( 'The loopback CSV server could not start.' );
	}
	if ( isset( $server_pipes[0] ) && is_resource( $server_pipes[0] ) ) {
		fclose( $server_pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- The loopback server has no interactive input.
		unset( $server_pipes[0] );
	}
	$base  = 'http://127.0.0.1:' . $port;
	$ready = false;
	for ( $attempt = 0; $attempt < 50; ++$attempt ) {
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
		throw new RuntimeException( 'The loopback CSV server did not become ready.' );
	}
	$target = $base . '/admin-post.php';

	$valid_path = $temp_dir . DIRECTORY_SEPARATOR . 'valid.csv';
	$keys[]     = "=F19-\u{00C5}-" . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 20 );
	$keys[]     = 'f19-' . substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 );
	$canonical  = ( new KeyNormalizer() )->normalize( $keys[1], KeyNormalizer::GENERATED, '-' );
	dreamax_lm_f19_csv(
		$valid_path,
		array( 'license_key', 'product_public_id', 'activation_limit', 'expires_at', 'normalization_profile', 'separator' ),
		array(
			array( $keys[0], $product_public_id, '2', '2030-01-02', KeyNormalizer::IMPORTED, '' ),
			array( $keys[0], $product_public_id, '2', '2030-01-02', KeyNormalizer::IMPORTED, '' ),
			array( $keys[1], $product_public_id, '3', '', KeyNormalizer::GENERATED, '-' ),
			array( 'invalid!generated', $product_public_id, '1', '', KeyNormalizer::GENERATED, '-' ),
		)
	);

	$verification_stage                   = 'unauthorized_import';
	$customer_import                      = dreamax_lm_f19_request(
		$target,
		$cookies['customer']['cookie'],
		array(
			'action'   => 'dreamax_lm_import_csv',
			'_wpnonce' => $cookies['customer']['nonces']['dreamax_lm_import_csv'],
			'csv'      => new CURLFile( $valid_path, 'text/csv', 'fixture.csv' ),
		)
	);
	$checks['unauthorized_import_denied'] = str_contains( $customer_import['body'], 'not allowed' );

	$verification_stage = 'row_boundary';
	$boundary_path      = $temp_dir . DIRECTORY_SEPARATOR . 'boundary.csv';
	$boundary_rows      = array();
	for ( $index = 0; $index < 10001; ++$index ) {
		$boundary_rows[] = array( 'F19BOUNDARY' . str_pad( (string) $index, 5, '0', STR_PAD_LEFT ), $product_public_id );
	}
	dreamax_lm_f19_csv( $boundary_path, array( 'license_key', 'product_public_id' ), $boundary_rows );
	unset( $boundary_rows );
	$boundary                 = dreamax_lm_f19_request(
		$target,
		$cookies['administrator']['cookie'],
		array(
			'action'   => 'dreamax_lm_import_csv',
			'_wpnonce' => $cookies['administrator']['nonces']['dreamax_lm_import_csv'],
			'dry_run'  => '1',
			'csv'      => new CURLFile( $boundary_path, 'text/csv', 'boundary.csv' ),
		)
	);
	$checks['row_boundary']   = str_contains( $boundary['body'], '10000 valid rows' ) && str_contains( $boundary['body'], 'limited to 10,000 rows' );
	$checks['bounded_memory'] = $boundary['peak'] > 0 && $boundary['peak'] <= 128 * MB_IN_BYTES;

	$verification_stage = 'size_boundary';
	$oversize_path      = $temp_dir . DIRECTORY_SEPARATOR . 'oversize.csv';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Creates an exact temporary size-boundary fixture.
	$oversize = fopen( $oversize_path, 'wb' );
	if ( false === $oversize ) {
		throw new RuntimeException( 'The oversize fixture could not be opened.' );
	}
	fputcsv( $oversize, array( 'license_key', 'product_public_id' ) );
	fwrite( $oversize, str_repeat( 'x', 5 * MB_IN_BYTES + 1024 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Exact owned boundary bytes.
	fclose( $oversize ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact owned fixture.
	chmod( $oversize_path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricts the exact owned fixture.
	$oversize_response       = dreamax_lm_f19_request(
		$target,
		$cookies['administrator']['cookie'],
		array(
			'action'   => 'dreamax_lm_import_csv',
			'_wpnonce' => $cookies['administrator']['nonces']['dreamax_lm_import_csv'],
			'csv'      => new CURLFile( $oversize_path, 'text/csv', 'oversize.csv' ),
		)
	);
	$checks['size_boundary'] = str_contains( $oversize_response['body'], 'larger than 5 MiB' );

	$verification_stage = 'queued_private_upload';
	$queued_path        = $temp_dir . DIRECTORY_SEPARATOR . 'queued.csv';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Creates an exact private multipart fixture.
	$queued_handle = fopen( $queued_path, 'wb' );
	if ( false === $queued_handle ) {
		throw new RuntimeException( 'The queued upload fixture could not be opened.' );
	}
	fputcsv( $queued_handle, array( 'license_key', 'product_public_id' ) );
	for ( $index = 0; $index < 450; ++$index ) {
		fputcsv( $queued_handle, array( 'invalid-' . str_repeat( 'x', 700 ) . $index, $product_public_id ) );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact queued fixture.
	fclose( $queued_handle );
	$queued_response = dreamax_lm_f19_request(
		$target,
		$cookies['administrator']['cookie'],
		array(
			'action'   => 'dreamax_lm_import_csv',
			'_wpnonce' => $cookies['administrator']['nonces']['dreamax_lm_import_csv'],
			'csv'      => new CURLFile( $queued_path, 'text/csv', 'queued.csv' ),
		)
	);
	$location        = (string) ( $queued_response['headers']['location'] ?? '' );
	$query           = (string) wp_parse_url( $location, PHP_URL_QUERY );
	parse_str( $query, $queued_query );
	$queued_token                     = is_string( $queued_query['import_job'] ?? null ) ? $queued_query['import_job'] : '';
	$checks['queued_upload_redirect'] = 302 === $queued_response['status'] && 1 === preg_match( '/^[a-f0-9]{32}$/D', $queued_token );
	if ( ! $checks['queued_upload_redirect'] ) {
		throw new RuntimeException( 'The guarded queued upload did not return its job identifier.' );
	}
	$queued_job_tokens[]           = $queued_token;
	$queued_state                  = get_option( 'dreamax_lm_csv_job_' . $queued_token, null );
	$queued_private_path           = is_array( $queued_state ) ? (string) ( $queued_state['path'] ?? '' ) : '';
	$checks['queued_private_copy'] = is_array( $queued_state ) && (int) ( $queued_state['owner'] ?? 0 ) === $user_ids['administrator'] && 'queued' === ( $queued_state['status'] ?? '' ) && '' !== $queued_private_path && is_file( $queued_private_path ) && filesize( $queued_path ) > 262144 && hash_file( 'sha256', $queued_path ) === hash_file( 'sha256', $queued_private_path );
	$uploads                       = wp_upload_dir();
	$checks['queued_not_public']   = is_array( $uploads ) && '' !== $queued_private_path && ! str_starts_with( str_replace( '\\', '/', $queued_private_path ), str_replace( '\\', '/', (string) $uploads['basedir'] ) . '/' );

	$verification_stage               = 'actual_import';
	$actual                           = dreamax_lm_f19_request(
		$target,
		$cookies['administrator']['cookie'],
		array(
			'action'   => 'dreamax_lm_import_csv',
			'_wpnonce' => $cookies['administrator']['nonces']['dreamax_lm_import_csv'],
			'csv'      => new CURLFile( $valid_path, 'text/csv', 'valid.csv' ),
		)
	);
	$checks['actual_import_response'] = str_contains( $actual['body'], 'Import complete' ) && str_contains( $actual['body'], '2 valid rows' ) && str_contains( $actual['body'], 'Rows needing attention' );

	$verification_stage = 'import_database';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned import assertions.
	$imported   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE product_public_id=%s ORDER BY id", $product_public_id ), ARRAY_A );
	$imported   = is_array( $imported ) ? $imported : array();
	$repository = new LicenseRepository();
	$decrypted  = array();
	foreach ( $imported as $row ) {
		$license_ids[] = (int) $row['id'];
		$decrypted[]   = $repository->decrypt_key( $row );
	}
	$generated_rows                        = array_values(
		array_filter(
			$imported,
			static fn( array $row ): bool => KeyNormalizer::GENERATED === ( $row['normalization_profile'] ?? '' ) && '-' === ( $row['normalization_separator'] ?? '' )
		)
	);
	$checks['duplicate_and_normalization'] = 2 === count( $imported )
		&& in_array( $keys[0], $decrypted, true )
		&& in_array( $keys[1], $decrypted, true )
		&& 1 === count( $generated_rows )
		&& $canonical !== $keys[1];

	$created_events = 0;
	foreach ( $license_ids as $license_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact source-audit assertion.
		$events = $wpdb->get_results( $wpdb->prepare( "SELECT metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s", $license_id, AuditEventCatalog::LICENSE_CREATED ), ARRAY_A );
		foreach ( is_array( $events ) ? $events : array() as $event ) {
			$metadata = json_decode( (string) $event['metadata'], true );
			if ( is_array( $metadata ) && 'csv_import' === ( $metadata['source'] ?? null ) ) {
				++$created_events;
			}
		}
	}
	$checks['import_audit_exact'] = 2 === $created_events;

	$verification_stage                   = 'unauthorized_export';
	$customer_export                      = dreamax_lm_f19_request(
		$target,
		$cookies['customer']['cookie'],
		array(
			'action'    => 'dreamax_lm_export_csv',
			'_wpnonce'  => $cookies['customer']['nonces']['dreamax_lm_export_csv'],
			'full_keys' => '1',
		)
	);
	$checks['unauthorized_export_denied'] = str_contains( $customer_export['body'], 'not allowed' );

	$verification_stage         = 'authorized_exports';
	$masked                     = dreamax_lm_f19_request(
		$target,
		$cookies['shop_manager']['cookie'],
		array(
			'action'    => 'dreamax_lm_export_csv',
			'_wpnonce'  => $cookies['shop_manager']['nonces']['dreamax_lm_export_csv'],
			'full_keys' => '1',
		)
	);
	$full                       = dreamax_lm_f19_request(
		$target,
		$cookies['administrator']['cookie'],
		array(
			'action'    => 'dreamax_lm_export_csv',
			'_wpnonce'  => $cookies['administrator']['nonces']['dreamax_lm_export_csv'],
			'full_keys' => '1',
		)
	);
	$download_headers           = static function ( array $response ): bool {
		return 200 === $response['status']
			&& str_starts_with( strtolower( (string) ( $response['headers']['content-type'] ?? '' ) ), 'text/csv' )
			&& 1 === preg_match( '/^attachment; filename="dreamax-licenses-\d{8}-\d{6}\.csv"$/D', (string) ( $response['headers']['content-disposition'] ?? '' ) );
	};
	$checks['download_headers'] = $download_headers( $masked ) && $download_headers( $full );

	$verification_stage       = 'export_content';
	$owned_public_ids         = array_column( $imported, 'public_id' );
	$masked_rows              = array_values( array_filter( dreamax_lm_f19_rows( $masked['body'] ), static fn( array $row ): bool => in_array( $row['public_id'] ?? '', $owned_public_ids, true ) ) );
	$full_rows                = array_values( array_filter( dreamax_lm_f19_rows( $full['body'] ), static fn( array $row ): bool => in_array( $row['public_id'] ?? '', $owned_public_ids, true ) ) );
	$masked_keys              = array_column( $masked_rows, 'license_key' );
	$full_keys                = array_column( $full_rows, 'license_key' );
	$checks['masked_export']  = 2 === count( $masked_rows ) && ! in_array( $keys[0], $masked_keys, true ) && ! in_array( $keys[1], $masked_keys, true );
	$checks['full_export']    = 2 === count( $full_rows ) && in_array( "'" . $keys[0], $full_keys, true ) && in_array( $keys[1], $full_keys, true );
	$checks['formula_escape'] = in_array( "'" . $keys[0], $full_keys, true ) && 1 === count( array_filter( $masked_keys, static fn( string $key ): bool => str_starts_with( $key, "'=" ) ) );

	$verification_stage = 'export_audit';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned export audit assertions and cleanup identities.
	$export_events     = $wpdb->get_results( $wpdb->prepare( "SELECT id,actor_id,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE event_type=%s AND actor_id IN (%d,%d,%d)", AuditEventCatalog::LICENSE_EXPORTED, $user_ids['administrator'], $user_ids['shop_manager'], $user_ids['customer'] ), ARRAY_A );
	$export_events     = is_array( $export_events ) ? $export_events : array();
	$metadata_by_actor = array();
	foreach ( $export_events as $event ) {
		$export_event_ids[]                            = (int) $event['id'];
		$metadata_by_actor[ (int) $event['actor_id'] ] = json_decode( (string) $event['metadata'], true );
	}
	$expected_rows                = $before['licenses'] + 2;
	$checks['export_audit_exact'] = 2 === count( $export_events )
		&& false === ( $metadata_by_actor[ $user_ids['shop_manager'] ]['full_keys'] ?? null )
		&& true === ( $metadata_by_actor[ $user_ids['administrator'] ]['full_keys'] ?? null )
		&& ( $metadata_by_actor[ $user_ids['shop_manager'] ]['row_count'] ?? -1 ) === $expected_rows
		&& ( $metadata_by_actor[ $user_ids['administrator'] ]['row_count'] ?? -1 ) === $expected_rows;

	$verification_stage         = 'combined_contract';
	$checks['preview_no_write'] = 2 === count( $imported );
	$checks['memory_headers']   = $actual['peak'] > 0 && $masked['peak'] > 0 && $full['peak'] > 0;
	$response_diagnostics       = array(
		'customer_import' => $customer_import['status'] . ':' . dreamax_lm_f19_body_label( $customer_import['body'] ) . ':' . $customer_import['stage'] . ':' . $customer_import['fatal'],
		'boundary'        => $boundary['status'] . ':' . dreamax_lm_f19_body_label( $boundary['body'] ) . ':' . $boundary['stage'] . ':' . $boundary['fatal'],
		'oversize'        => $oversize_response['status'] . ':' . dreamax_lm_f19_body_label( $oversize_response['body'] ) . ':' . $oversize_response['stage'] . ':' . $oversize_response['fatal'],
		'actual'          => $actual['status'] . ':' . dreamax_lm_f19_body_label( $actual['body'] ) . ':' . $actual['stage'] . ':' . $actual['fatal'],
		'customer_export' => $customer_export['status'] . ':' . dreamax_lm_f19_body_label( $customer_export['body'] ) . ':' . $customer_export['stage'] . ':' . $customer_export['fatal'],
		'masked'          => $masked['status'] . ':' . dreamax_lm_f19_body_label( $masked['body'] ) . ':' . $masked['stage'] . ':' . $masked['fatal'],
		'full'            => $full['status'] . ':' . dreamax_lm_f19_body_label( $full['body'] ) . ':' . $full['stage'] . ':' . $full['fatal'],
	);
	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'The live CSV contract did not match.' );
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
				fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes exact owned server pipes.
			}
		}
		proc_close( $server );
	}
	foreach ( $queued_job_tokens as $queued_token ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'dreamax_lm_process_csv_import', array( $queued_token ), 'dreamax-license-manager' );
		}
		wp_clear_scheduled_hook( 'dreamax_lm_process_csv_import', array( $queued_token ) );
		wp_clear_scheduled_hook( 'dreamax_lm_cleanup_csv_job', array( $queued_token ) );
		$queued_state = get_option( 'dreamax_lm_csv_job_' . $queued_token, null );
		if ( is_array( $queued_state ) ) {
			foreach ( array( 'path', 'report_path' ) as $field ) {
				if ( ! empty( $queued_state[ $field ] ) && is_string( $queued_state[ $field ] ) ) {
					wp_delete_file( $queued_state[ $field ] );
				}
			}
		}
		delete_option( 'dreamax_lm_csv_job_' . $queued_token );
		delete_option( 'dreamax_lm_csv_job_' . $queued_token . '_lock' );
	}

	// Recover exact owned license identities even when a prior assertion failed.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned cleanup discovery.
	$license_ids = array_values( array_unique( array_merge( $license_ids, array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE product_public_id=%s", $product_public_id ) ) ) ) ) );
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cleanup_failure = new RuntimeException( 'Cleanup transaction start failed.' );
	} else {
		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only exact owned plugin fixtures inside the cleanup transaction.
			foreach ( $license_ids as $license_id ) {
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) );
			}
			foreach ( $user_ids as $user_id ) {
				$wpdb->delete(
					$wpdb->prefix . 'dreamax_lm_events',
					array(
						'actor_id'   => $user_id,
						'event_type' => AuditEventCatalog::LICENSE_EXPORTED,
					),
					array( '%d', '%s' )
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$cleanup_committed = false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $cleanup_committed ) {
				throw new RuntimeException( 'CSV cleanup commit failed.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$cleanup_failure = $error;
		}
	}
	foreach ( $user_ids as $user_id ) {
		if ( ! wp_delete_user( $user_id ) ) {
			$cleanup_failure = $cleanup_failure ?? new RuntimeException( 'A temporary user cleanup failed.' );
		}
	}
	foreach ( $keys as &$key ) {
		if ( '' !== $key && function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $key );
		}
	}
	unset( $key );
	foreach ( $cookies as &$auth ) {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $auth['cookie'] );
		}
	}
	unset( $auth );
	if ( is_dir( $temp_dir ) ) {
		try {
			Filesystem::removeTree( $temp_dir, sys_get_temp_dir() );
		} catch ( Throwable $error ) {
			$cleanup_failure = $cleanup_failure ?? $error;
		}
	}
	wp_set_current_user( $user_before );
	$_COOKIE = $cookie_before;
	if ( function_exists( 'wp_cache_flush_runtime' ) ) {
		wp_cache_flush_runtime();
	}
}

$after = $snapshot();
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned cleanup evidence.
$owned_users_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( 'dlm_f19_' ) . '%' ) );
$owned_rows_left  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE product_public_id=%s", $product_public_id ) );
foreach ( $user_ids as $user_id ) {
	$owned_rows_left += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE actor_id=%d AND event_type=%s", $user_id, AuditEventCatalog::LICENSE_EXPORTED ) );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

if ( $cleanup_failure instanceof Throwable || ! $cleanup_committed || $before !== $after || 0 !== $owned_users_left || 0 !== $owned_rows_left ) {
	dreamax_lm_f19_fail( 'The owned F19 fixtures were not completely removed.' );
}
if ( $failure instanceof Throwable ) {
	$failed_checks  = array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) );
	$failed_label   = $failed_checks ? implode( ',', $failed_checks ) : 'none';
	$response_label = $response_diagnostics ? implode( ',', array_map( static fn( string $name, string $value ): string => $name . '=' . $value, array_keys( $response_diagnostics ), $response_diagnostics ) ) : 'none';
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Only fixed internal stage/check labels are emitted; no exception or fixture value is included.
	dreamax_lm_f19_fail( 'The guarded F19 verification failed at sanitized stage: ' . $verification_stage . '; checks: ' . $failed_label . '; responses: ' . $response_label . '.' );
}

echo wp_json_encode(
	array(
		'classification'                => 'live_disposable_wordpress_innodb_loopback_multipart',
		'upload_size_boundary'          => $checks['size_boundary'],
		'queued_private_upload'         => $checks['queued_upload_redirect'] && $checks['queued_private_copy'] && $checks['queued_not_public'],
		'row_limit_boundary'            => $checks['row_boundary'],
		'bounded_memory'                => $checks['bounded_memory'] && $checks['memory_headers'],
		'preview_no_write'              => $checks['preview_no_write'],
		'duplicate_handling'            => $checks['duplicate_and_normalization'],
		'normalization_boundaries'      => $checks['duplicate_and_normalization'],
		'import_audit_exact'            => $checks['import_audit_exact'],
		'capability_separation'         => $checks['admin_capabilities'] && $checks['shop_capabilities'] && $checks['customer_capabilities'] && $checks['unauthorized_import_denied'] && $checks['unauthorized_export_denied'],
		'masked_export'                 => $checks['masked_export'],
		'authorized_full_export'        => $checks['full_export'],
		'formula_escape'                => $checks['formula_escape'],
		'download_headers'              => $checks['download_headers'],
		'export_audit_exact'            => $checks['export_audit_exact'],
		'cleanup_transaction_committed' => $cleanup_committed,
		'aggregates_unchanged'          => $before === $after,
		'owned_fixture_rows_remaining'  => $owned_users_left + $owned_rows_left,
		'outbound_email_sent'           => false,
		'sensitive_output'              => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
