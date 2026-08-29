<?php
/**
 * Verifies the complete F31 privileged Bearer contract on a disposable site.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Api\SourceAddress;
use Dreamax\LicenseManager\Credentials\CredentialService;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Licenses\LicenseException;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with one sanitized failure.
 *
 * @param string $stage Fixed failure stage.
 */
function dreamax_lm_f31_fail( string $stage ): never {
	$category = (string) ( $GLOBALS['dreamax_lm_f31_failure_category'] ?? 'none' );
	$category = strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $category ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Sanitized CLI failure only.
	fwrite( STDERR, 'FAIL: F31 verification failed at sanitized stage: ' . $stage . '; category: ' . $category . '.' . PHP_EOL );
	exit( 1 );
}

/**
 * Generates one bounded opaque fixture suffix.
 */
function dreamax_lm_f31_suffix(): string {
	return substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 );
}

/**
 * Takes authoritative aggregate counts.
 *
 * @return array<string,int>
 */
function dreamax_lm_f31_snapshot(): array {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup proof needs authoritative counts.
	return array(
		'credentials' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_api_credentials" ),
		'licenses'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'activations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations" ),
		'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
		'rate_limits' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_rate_limits" ),
	);
	// phpcs:enable
}

/**
 * Executes one real loopback WordPress REST request.
 *
 * @param string               $method HTTP method.
 * @param string               $route REST route relative to the plugin namespace.
 * @param string|null          $credential Optional Bearer value.
 * @param array<string,mixed>  $body Optional JSON body.
 * @param array<string,string> $headers Extra headers.
 * @return array{status:int,data:array<string,mixed>,raw:string}
 * @throws RuntimeException When the bounded response is unavailable or invalid.
 */
function dreamax_lm_f31_http( string $method, string $route, ?string $credential = null, array $body = array(), array $headers = array() ): array {
	$route_parts = explode( '?', $route, 2 );
	$url         = add_query_arg( 'rest_route', '/dreamax-license-manager/v1/' . ltrim( $route_parts[0], '/' ), home_url( '/' ) );
	if ( isset( $route_parts[1] ) ) {
		$query = array();
		wp_parse_str( $route_parts[1], $query );
		$url = add_query_arg( $query, $url );
	}
	if ( ! str_starts_with( $url, home_url( '/' ) ) && ! str_starts_with( $url, site_url( '/' ) ) ) {
		throw new RuntimeException( 'The loopback target was outside the disposable site.' );
	}
	$request_headers = array_merge( array( 'X-Forwarded-Proto' => 'https' ), $headers );
	if ( null !== $credential ) {
		$request_headers['Authorization'] = 'Bearer ' . $credential;
	}
	$args = array(
		'method'      => $method,
		'timeout'     => 20,
		'redirection' => 0,
		'headers'     => $request_headers,
	);
	if ( array() !== $body ) {
		$encoded = wp_json_encode( $body );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'The private request body could not be encoded.' );
		}
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = $encoded;
	}
	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		$GLOBALS['dreamax_lm_f31_failure_category'] = 'loopback_transport';
		throw new RuntimeException( 'The loopback REST request did not complete.' );
	}
	$raw  = wp_remote_retrieve_body( $response );
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || strlen( $raw ) > 262144 ) {
		$GLOBALS['dreamax_lm_f31_failure_category'] = 'loopback_response';
		throw new RuntimeException( 'The loopback REST response was invalid.' );
	}
	return array(
		'status' => wp_remote_retrieve_response_code( $response ),
		'data'   => $data,
		'raw'    => $raw,
	);
}

/**
 * Checks one stable response without retaining response content.
 *
 * @param array{status:int,data:array<string,mixed>,raw:string} $response Response.
 * @param int                                                   $status Expected status.
 * @param string                                                $code Expected code.
 * @param array                                                 $forbidden Forbidden values.
 * @phpstan-param list<string> $forbidden Forbidden values.
 * @return array<string,mixed> Response data.
 * @throws RuntimeException When the response contract differs.
 */
function dreamax_lm_f31_expect( array $response, int $status, string $code, array $forbidden = array() ): array {
	// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- Both expected values are variables supplied by the fixed verifier matrix.
	if ( $status !== $response['status'] || $code !== (string) ( $response['data']['code'] ?? '' ) ) {
		$observed_code = strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', (string) ( $response['data']['code'] ?? 'unknown' ) ) );
		$message_kind  = 'other';
		if ( 'HTTPS is required for licensing requests.' === (string) ( $response['data']['message'] ?? '' ) ) {
			$message_kind = 'https';
		} elseif ( 'The licensing service is temporarily unavailable.' === (string) ( $response['data']['message'] ?? '' ) ) {
			$message_kind = 'generic';
		}
		$GLOBALS['dreamax_lm_f31_failure_category'] = 'http_' . $response['status'] . '_' . $observed_code . '_' . $message_kind;
		throw new RuntimeException( 'A loopback REST response differed from its stable contract.' );
	}
	foreach ( $forbidden as $value ) {
		if ( '' !== $value && str_contains( $response['raw'], $value ) ) {
			throw new RuntimeException( 'A loopback REST response disclosed protected input.' );
		}
	}
	$data = $response['data']['data'] ?? array();
	return is_array( $data ) ? $data : array();
}

/**
 * Sends two raw Authorization fields to the local web server.
 *
 * @param string $credential Exact private Bearer value.
 * @throws RuntimeException When the server does not reject the duplicate fields uniformly.
 */
function dreamax_lm_f31_duplicate_header( string $credential ): void {
	$url    = add_query_arg( 'rest_route', '/dreamax-license-manager/v1/licenses', home_url( '/' ) );
	$parts  = wp_parse_url( $url );
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	$host   = (string) ( $parts['host'] ?? '' );
	$port   = (int) ( $parts['port'] ?? ( 'https' === $scheme ? 443 : 80 ) );
	$path   = (string) ( $parts['path'] ?? '/' );
	$query  = isset( $parts['query'] ) ? '?' . (string) $parts['query'] : '';
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || 1 !== preg_match( '/^[A-Za-z0-9.-]+$/D', $host ) || $port < 1 || $port > 65535 ) {
		throw new RuntimeException( 'The raw loopback endpoint was unsafe.' );
	}
	$transport    = 'https' === $scheme ? 'ssl://' : 'tcp://';
	$errno        = 0;
	$socket_error = '';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_socket_client -- Exact raw-header verification requires a local socket.
	$socket = stream_socket_client( $transport . $host . ':' . $port, $errno, $socket_error, 10 );
	if ( ! is_resource( $socket ) ) {
		throw new RuntimeException( 'The raw loopback connection did not open.' );
	}
	stream_set_timeout( $socket, 20 );
	$request = 'GET ' . $path . $query . " HTTP/1.1\r\n"
		. 'Host: ' . $host . "\r\n"
		. "Connection: close\r\n"
		. "X-Forwarded-Proto: https\r\n"
		. 'Authorization: Bearer ' . $credential . "\r\n"
		. 'Authorization: Bearer ' . $credential . "\r\n\r\n";
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Sends only to the validated local server.
	fwrite( $socket, $request );
	$raw        = '';
	$raw_length = 0;
	while ( ! feof( $socket ) && $raw_length <= 262144 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads only the bounded local response.
		$chunk = fread( $socket, 8192 );
		if ( false === $chunk ) {
			break;
		}
		$raw       .= $chunk;
		$raw_length = strlen( $raw );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact local socket.
	fclose( $socket );
	if ( strlen( $raw ) > 262144 || ! preg_match( '/^HTTP\/1\.[01] 401\b/', $raw ) || ! str_contains( $raw, 'authentication_required' ) || str_contains( $raw, $credential ) ) {
		throw new RuntimeException( 'Duplicate raw Authorization fields were not rejected uniformly.' );
	}
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $request );
	}
}

/**
 * Starts one private worker with input delivered only through STDIN.
 *
 * @param array<string,mixed> $payload Worker payload.
 * @param string              $wp_root WordPress root.
 * @param string              $marker Guard marker.
 * @return array{process:resource,pipes:array<int,resource>}
 * @throws RuntimeException When the worker cannot start.
 */
function dreamax_lm_f31_spawn( array $payload, string $wp_root, string $marker ): array {
	$descriptor = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Bounded local concurrency worker.
	$process = proc_open(
		array(
			PHP_BINARY,
			__DIR__ . '/lib/credential-concurrency-worker.php',
			'--environment-marker=' . $marker,
			'--wp-root=' . $wp_root,
		),
		$descriptor,
		$pipes,
		__DIR__
	);
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'A credential worker could not start.' );
	}
	$encoded = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $encoded ) ) {
		throw new RuntimeException( 'A credential worker input could not be encoded.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Private child-process pipe only.
	fwrite( $pipes[0], $encoded );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes exact private input pipe.
	fclose( $pipes[0] );
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $encoded );
	}
	return array(
		'process' => $process,
		'pipes'   => $pipes,
	);
}

/**
 * Collects one sanitized worker result.
 *
 * @param array{process:resource,pipes:array<int,resource>} $worker Worker.
 * @return string Status.
 * @throws RuntimeException When output is not fixed and sanitized.
 */
function dreamax_lm_f31_collect( array $worker ): string {
	$deadline = microtime( true ) + 30.0;
	do {
		$status = proc_get_status( $worker['process'] );
		if ( ! $status['running'] ) {
			break;
		}
		usleep( 20000 );
	} while ( microtime( true ) < $deadline );
	if ( $status['running'] ) {
		proc_terminate( $worker['process'] );
		throw new RuntimeException( 'A credential worker exceeded its bounded runtime.' );
	}
	$stdout = stream_get_contents( $worker['pipes'][1] );
	$stderr = stream_get_contents( $worker['pipes'][2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes exact private output pipes.
	fclose( $worker['pipes'][1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes exact private output pipes.
	fclose( $worker['pipes'][2] );
	proc_close( $worker['process'] );
	$result = is_string( $stdout ) ? json_decode( trim( $stdout ), true ) : null;
	if ( '' !== trim( (string) $stderr ) || ! is_array( $result ) || false !== ( $result['sensitive_output'] ?? null ) ) {
		throw new RuntimeException( 'A credential worker returned an invalid result.' );
	}
	return (string) ( $result['status'] ?? '' );
}

/**
 * Waits for one worker readiness marker.
 *
 * @param string                                            $ready Ready path.
 * @param array{process:resource,pipes:array<int,resource>} $worker Worker.
 * @throws RuntimeException When the barrier is not reached.
 */
function dreamax_lm_f31_ready( string $ready, array $worker ): void {
	$deadline = microtime( true ) + 20.0;
	while ( ! is_file( $ready ) && microtime( true ) < $deadline ) {
		$status = proc_get_status( $worker['process'] );
		if ( ! $status['running'] ) {
			throw new RuntimeException( 'A credential worker stopped before its barrier.' );
		}
		usleep( 20000 );
	}
	if ( ! is_file( $ready ) ) {
		throw new RuntimeException( 'A credential worker did not reach its barrier.' );
	}
}

/**
 * Creates one empty barrier marker.
 *
 * @param string $path Marker path.
 * @throws RuntimeException When the marker cannot be created.
 */
function dreamax_lm_f31_release( string $path ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Owned empty barrier marker only.
	if ( false === file_put_contents( $path, 'go', LOCK_EX ) ) {
		throw new RuntimeException( 'A credential worker barrier could not be released.' );
	}
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f31_fail( 'preflight' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( ! defined( 'DB_NAME' ) ) {
	dreamax_lm_f31_fail( 'preflight' );
}
try {
	DisposableEnvironmentGuard::assertSafe(
		array(
			'marker'   => $marker,
			'database' => (string) DB_NAME,
			'site_url' => home_url( '/' ),
		)
	);
} catch ( Throwable ) {
	dreamax_lm_f31_fail( 'environment_guard' );
}
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f31_fail( 'plugin_state' );
}

global $wpdb;
foreach ( array( 'api_credentials', 'licenses', 'activations', 'events', 'rate_limits' ) as $suffix ) {
	$table = $wpdb->prefix . 'dreamax_lm_' . $suffix;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live locking and cleanup require InnoDB.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f31_fail( 'transactional_storage' );
	}
}

$before              = dreamax_lm_f31_snapshot();
$service             = new CredentialService();
$crypto              = new Crypto();
$current_user_before = get_current_user_id();
$administrator_ids   = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ids',
	)
);
if ( ! is_array( $administrator_ids ) || 1 !== count( $administrator_ids ) || (int) $administrator_ids[0] < 1 ) {
	dreamax_lm_f31_fail( 'administrator_context' );
}
$actor_id = (int) $administrator_ids[0];
wp_set_current_user( $actor_id );
$fixture_suffix    = dreamax_lm_f31_suffix();
$name_prefix       = 'DLM F31 temporary ' . $fixture_suffix . ' ';
$product_public_id = 'prd_' . substr( rtrim( strtr( base64_encode( random_bytes( 17 ) ), '+/', '-_' ), '=' ), 0, 22 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Synthetic product identity.
$credentials       = array();
$credential_ids    = array();
$license_ids       = array();
$tokens            = array();
$rate_hashes       = array();
$rate_before       = array();
$barriers          = array();
$event_floor       = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}dreamax_lm_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$trusted_had       = false !== get_option( 'dreamax_lm_trusted_proxies', false );
$trusted_before    = get_option( 'dreamax_lm_trusted_proxies', null );
$http_had          = false !== get_option( 'dreamax_lm_allow_http_local', false );
$http_before       = get_option( 'dreamax_lm_allow_http_local', null );
$error_log_before  = ini_get( 'error_log' );
$htaccess_path     = $wp_root . DIRECTORY_SEPARATOR . '.htaccess';
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the exact local disposable configuration for byte-for-byte restoration.
$htaccess_before     = is_file( $htaccess_path ) ? file_get_contents( $htaccess_path ) : false;
$htaccess_changed    = false;
$htaccess_restored   = false;
$failure             = null;
$failure_stage       = 'setup';
$cleanup_complete    = false;
$checks              = array();
$workers_verified    = 0;
$http_requests       = 0;
$audit_rows_verified = 0;

$track_rate = static function ( string $scope ) use ( $crypto, &$rate_hashes, &$rate_before, $wpdb ): void {
	$hex = bin2hex( $crypto->keyed_hash( $scope, 'rate-limit' ) );
	if ( isset( $rate_hashes[ $hex ] ) ) {
		return;
	}
	$rate_hashes[ $hex ] = true;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact pre-run bucket snapshot.
	$rate_before[ $hex ] = $wpdb->get_row( $wpdb->prepare( "SELECT tokens,updated_microtime,expires_at FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ), ARRAY_A );
};

$create = static function ( string $label, array $scopes, ?string $expires = null ) use ( $service, $name_prefix, &$credentials, &$credential_ids, &$tokens, $track_rate, $wpdb ): array {
	$result = $service->create( $name_prefix . $label, $scopes, $expires );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Captures exact owned credential row.
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id=%s", $result['public_id'] ) );
	if ( $id < 1 ) {
		throw new RuntimeException( 'An owned credential row was unavailable.' );
	}
	$credentials[]    = $result['public_id'];
	$credential_ids[] = $id;
	$tokens[]         = $result['credential'];
	$track_rate( 'privileged-credential|' . get_current_blog_id() . '|' . $result['public_id'] );
	return $result;
};

try {
	// phpcs:ignore WordPress.PHP.IniSet.Risky -- Prevents private runtime details from entering CLI logs; restored exactly.
	ini_set( 'error_log', 'NUL' );
	$failure_stage = 'authorization_forwarding';
	if ( ! is_string( $htaccess_before ) || '' === $htaccess_before ) {
		throw new RuntimeException( 'The disposable web configuration was unavailable.' );
	}
	if ( ! str_contains( $htaccess_before, 'HTTP_AUTHORIZATION' ) ) {
		$forwarding_block = "<IfModule mod_rewrite.c>\r\nRewriteEngine On\r\nRewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\r\n</IfModule>\r\n";
		$htaccess_test    = $forwarding_block . $htaccess_before;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Exact temporary disposable configuration, restored byte-for-byte in finally.
		if ( strlen( $htaccess_test ) !== file_put_contents( $htaccess_path, $htaccess_test, LOCK_EX ) ) {
			throw new RuntimeException( 'The temporary Authorization forwarding rule could not be applied.' );
		}
		$htaccess_changed = true;
	}
	update_option( 'dreamax_lm_allow_http_local', 1, false );
	update_option( 'dreamax_lm_trusted_proxies', array( '127.0.0.1', '::1' ), false );
	$track_rate( 'privileged-auth-failed|127.0.0.0/24' );
	$track_rate( 'privileged-auth-failed|0000000000000000/56' );

	$failure_stage = 'credential_creation';
	$read          = $create( 'read', array( 'licenses:read' ) );
	$write         = $create( 'write', array( 'licenses:write' ) );
	$activation    = $create( 'activation', array( 'activations:read' ) );
	$generator     = $create( 'generator', array( 'generators:read' ) );
	$expired       = $create( 'expired', array( 'licenses:read' ), gmdate( 'Y-m-d H:i:s', time() + 120 ) );
	$revoked       = $create( 'revoked', array( 'licenses:read' ) );
	$last_used     = $create( 'last-used', array( 'licenses:read' ) );
	$rate          = $create( 'rate', array( 'licenses:read' ) );
	$inject        = $create( 'rollback', array( 'licenses:read' ) );
	$double        = $create( 'double', array( 'licenses:read' ) );
	$use_rotate    = $create( 'use-rotate', array( 'licenses:read' ) );
	$use_revoke    = $create( 'use-revoke', array( 'licenses:read' ) );

	$failure_stage = 'direct_scope_read';
	try {
		$direct_safe = $service->authenticate( 'Bearer ' . $read['credential'] );
		if ( ! is_array( $direct_safe ) ) {
			throw new RuntimeException( 'The direct credential did not authenticate.' );
		}
		$direct_used = $service->authorized_use(
			$direct_safe,
			'licenses:read',
			'req_' . rtrim( strtr( base64_encode( random_bytes( 17 ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Synthetic request identity.
			static fn(): bool => true
		);
		if ( true !== $direct_used ) {
			throw new RuntimeException( 'The direct credential use did not complete.' );
		}
	} catch ( LicenseException $error ) {
		$GLOBALS['dreamax_lm_f31_failure_category'] = 'direct_license_' . $error->machine_code();
		throw $error;
	} catch ( RuntimeException $error ) {
		$known                                      = array(
			'The credential is temporarily busy.'          => 'direct_lock',
			'The rate-limit bucket could not be stored.'   => 'direct_rate_store',
			'The rate-limit bucket could not be updated.'  => 'direct_rate_update',
			'Credential usage could not be recorded.'      => 'direct_last_used',
			'Required audit evidence could not be stored.' => 'direct_audit',
			'The direct credential did not authenticate.'  => 'direct_auth',
			'The direct credential use did not complete.'  => 'direct_callback',
		);
		$GLOBALS['dreamax_lm_f31_failure_category'] = $known[ $error->getMessage() ] ?? 'direct_runtime';
		throw $error;
	}

	$failure_stage = 'scope_read';
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $read['credential'] ), 200, 'licenses_listed', $tokens );
	++$http_requests;
	$failure_stage = 'scope_activation';
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'activations', $activation['credential'] ), 200, 'activations_listed', $tokens );
	++$http_requests;
	$failure_stage = 'scope_generator';
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'generators', $generator['credential'] ), 200, 'generators_listed', $tokens );
	++$http_requests;
	$failure_stage = 'scope_write';
	$created       = dreamax_lm_f31_expect(
		dreamax_lm_f31_http(
			'POST',
			'licenses',
			$write['credential'],
			array(
				'product_public_id' => $product_public_id,
				'activation_limit'  => 1,
			)
		),
		201,
		'license_created',
		$tokens
	);
	++$http_requests;
	$created_public = (string) ( $created['license_public_id'] ?? '' );
	$created_key    = (string) ( $created['license_key'] ?? '' );
	if ( 1 !== preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $created_public ) || '' === $created_key ) {
		throw new RuntimeException( 'The scoped write did not create one owned license.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Captures exact owned license row.
	$created_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id=%s AND product_public_id=%s", $created_public, $product_public_id ) );
	if ( $created_id < 1 ) {
		throw new RuntimeException( 'The exact owned license row was unavailable.' );
	}
	$license_ids[] = $created_id;
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $created_key );
	}
	$checks['all_scopes_allowed'] = true;

	$failure_stage = 'wrong_scope';
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'generators', $read['credential'] ), 403, 'insufficient_scope', $tokens );
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $write['credential'] ), 403, 'insufficient_scope', $tokens );
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'generators', $activation['credential'] ), 403, 'insufficient_scope', $tokens );
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'activations', $generator['credential'] ), 403, 'insufficient_scope', $tokens );
	$http_requests                += 4;
	$checks['wrong_scopes_denied'] = true;

	$failure_stage = 'header_and_transport';
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses' ), 401, 'authentication_required', $tokens );
	++$http_requests;
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', 'not-a-valid-bearer' ), 401, 'authentication_required', $tokens );
	++$http_requests;
	$unknown = ( new Dreamax\LicenseManager\Credentials\CredentialToken() )->generate();
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $unknown['credential'] ), 401, 'authentication_required', array_merge( $tokens, array( $unknown['credential'] ) ) );
	++$http_requests;
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $unknown['credential'] );
		sodium_memzero( $unknown['secret'] );
	}
	$service->revoke( $revoked['public_id'] );
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $revoked['credential'] ), 401, 'authentication_required', $tokens );
	++$http_requests;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Sets one exact owned credential at the closed expiry boundary.
	$wpdb->update( $wpdb->prefix . 'dreamax_lm_api_credentials', array( 'expires_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'public_id' => $expired['public_id'] ), array( '%s' ), array( '%s' ) );
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $expired['credential'] ), 401, 'authentication_required', $tokens );
	++$http_requests;
	dreamax_lm_f31_duplicate_header( $read['credential'] );
	++$http_requests;
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses?authorization=blocked', $read['credential'] ), 400, 'invalid_request', $tokens );
	++$http_requests;
	dreamax_lm_f31_expect(
		dreamax_lm_f31_http(
			'POST',
			'licenses',
			$write['credential'],
			array(
				'product_public_id' => $product_public_id,
				'authorization'     => 'blocked',
			)
		),
		400,
		'invalid_request',
		$tokens
	);
	++$http_requests;
	update_option( 'dreamax_lm_allow_http_local', 0, false );
	update_option( 'dreamax_lm_trusted_proxies', array(), false );
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $read['credential'], array(), array( 'X-Forwarded-Proto' => 'https' ) ), 503, 'server_unavailable', $tokens );
	++$http_requests;
	update_option( 'dreamax_lm_trusted_proxies', array( '127.0.0.1', '::1' ), false );
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $read['credential'], array(), array( 'X-Forwarded-Proto' => 'https' ) ), 200, 'licenses_listed', $tokens );
	++$http_requests;
	update_option( 'dreamax_lm_allow_http_local', 1, false );
	update_option( 'dreamax_lm_trusted_proxies', array( '127.0.0.1', '::1' ), false );
	$checks['uniform_header_failures'] = true;
	$checks['proxy_policy']            = true;

	$failure_stage = 'last_used';
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $last_used['credential'] ), 200, 'licenses_listed', $tokens );
	++$http_requests;
	$first_used = (string) $wpdb->get_var( $wpdb->prepare( "SELECT last_used_at FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id=%s", $last_used['public_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$inside     = gmdate( 'Y-m-d H:i:s', time() - 240 );
	$wpdb->update( $wpdb->prefix . 'dreamax_lm_api_credentials', array( 'last_used_at' => $inside ), array( 'public_id' => $last_used['public_id'] ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $last_used['credential'] ), 200, 'licenses_listed', $tokens );
	++$http_requests;
	$inside_after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT last_used_at FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id=%s", $last_used['public_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$boundary     = gmdate( 'Y-m-d H:i:s', time() - 360 );
	$wpdb->update( $wpdb->prefix . 'dreamax_lm_api_credentials', array( 'last_used_at' => $boundary ), array( 'public_id' => $last_used['public_id'] ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $last_used['credential'] ), 200, 'licenses_listed', $tokens );
	++$http_requests;
	$boundary_after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT last_used_at FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id=%s", $last_used['public_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( '' === $first_used || $inside_after !== $inside || $boundary_after === $boundary ) {
		throw new RuntimeException( 'The five-minute last-used boundary did not coalesce exactly.' );
	}
	$checks['last_used_coalesced'] = true;

	$failure_stage = 'rate_limit';
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $rate['credential'] ), 200, 'licenses_listed', $tokens );
	++$http_requests;
	$rate_hex = bin2hex( $crypto->keyed_hash( 'privileged-credential|' . get_current_blog_id() . '|' . $rate['public_id'], 'rate-limit' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Places one exact owned bucket in a controlled depleted state for the next request.
	$depleted = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_rate_limits SET tokens=0,updated_microtime=%f WHERE bucket_hash=UNHEX(%s)", microtime( true ) + 60.0, $rate_hex ) );
	if ( 1 !== $depleted ) {
		throw new RuntimeException( 'The exact owned rate bucket could not be depleted.' );
	}
	dreamax_lm_f31_expect( dreamax_lm_f31_http( 'GET', 'licenses', $rate['credential'] ), 429, 'rate_limited', $tokens );
	++$http_requests;
	$checks['per_credential_rate_limited'] = true;

	$failure_stage = 'audit_failure_rollback';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned row snapshot for rollback proof.
	$inject_before        = $wpdb->get_row( $wpdb->prepare( "SELECT secret_hash,secret_version FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id=%s", $inject['public_id'] ), ARRAY_A );
	$events_table         = $wpdb->prefix . 'dreamax_lm_events';
	$inject_filter        = static function ( string $query ) use ( $events_table ): string {
		if ( str_contains( $query, $events_table ) && str_contains( $query, AuditEventCatalog::CREDENTIAL_ROTATION_SUCCEEDED ) && str_starts_with( ltrim( $query ), 'INSERT' ) ) {
			return 'INSERT INTO dreamax_lm_f31_intentionally_missing (id) VALUES (1)';
		}
		return $query;
	};
	$previous_suppression = $wpdb->suppress_errors( true );
	add_filter( 'query', $inject_filter, PHP_INT_MAX );
	$injected_failed = false;
	try {
		$service->rotate( $inject['public_id'], 1 );
	} catch ( RuntimeException ) {
		$injected_failed = true;
	} finally {
		remove_filter( 'query', $inject_filter, PHP_INT_MAX );
		$wpdb->suppress_errors( $previous_suppression );
		$wpdb->last_error = '';
	}
	$inject_after = $wpdb->get_row( $wpdb->prepare( "SELECT secret_hash,secret_version FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id=%s", $inject['public_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$old_usable   = $service->authenticate( 'Bearer ' . $inject['credential'] );
	if ( ! $injected_failed || ! is_array( $inject_before ) || $inject_before !== $inject_after || ! is_array( $old_usable ) ) {
		throw new RuntimeException( 'A failed audit write did not preserve the old verifier exactly.' );
	}
	$checks['audit_failure_rolled_back'] = true;

	$failure_stage  = 'double_rotation';
	$go             = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f31-go-' . dreamax_lm_f31_suffix();
	$barriers[]     = $go;
	$double_workers = array();
	for ( $i = 0; $i < 2; ++$i ) {
		$ready_path       = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f31-ready-' . dreamax_lm_f31_suffix();
		$barriers[]       = $ready_path;
		$double_workers[] = array(
			'ready'  => $ready_path,
			'worker' => dreamax_lm_f31_spawn(
				array(
					'mode'      => 'rotate_barrier',
					'public_id' => $double['public_id'],
					'version'   => 1,
					'actor_id'  => $actor_id,
					'ready'     => $ready_path,
					'go'        => $go,
				),
				$wp_root,
				$marker
			),
		);
	}
	foreach ( $double_workers as $entry ) {
		dreamax_lm_f31_ready( $entry['ready'], $entry['worker'] );
	}
	dreamax_lm_f31_release( $go );
	$statuses = array();
	foreach ( $double_workers as $entry ) {
		$statuses[] = dreamax_lm_f31_collect( $entry['worker'] );
		++$workers_verified;
	}
	sort( $statuses );
	if ( array( 'rejected', 'rotated' ) !== $statuses ) {
		throw new RuntimeException( 'Concurrent double rotation did not produce one winner.' );
	}
	$checks['double_rotation_one_winner'] = true;

	$failure_stage = 'use_rotation_serialization';
	$use_safe      = $service->authenticate( 'Bearer ' . $use_rotate['credential'] );
	if ( ! is_array( $use_safe ) ) {
		throw new RuntimeException( 'The use/rotation fixture did not authenticate.' );
	}
	$ready_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f31-ready-' . dreamax_lm_f31_suffix();
	$go_path    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f31-go-' . dreamax_lm_f31_suffix();
	$barriers[] = $ready_path;
	$barriers[] = $go_path;
	$use_worker = dreamax_lm_f31_spawn(
		array(
			'mode'       => 'use_hold',
			'public_id'  => $use_rotate['public_id'],
			'version'    => 1,
			'actor_id'   => $actor_id,
			'credential' => $use_safe,
			'ready'      => $ready_path,
			'go'         => $go_path,
		),
		$wp_root,
		$marker
	);
	dreamax_lm_f31_ready( $ready_path, $use_worker );
	$mutation_worker = dreamax_lm_f31_spawn(
		array(
			'mode'      => 'rotate',
			'public_id' => $use_rotate['public_id'],
			'version'   => 1,
			'actor_id'  => $actor_id,
		),
		$wp_root,
		$marker
	);
	usleep( 300000 );
	if ( ! proc_get_status( $mutation_worker['process'] )['running'] ) {
		throw new RuntimeException( 'Rotation did not wait behind in-flight use.' );
	}
	dreamax_lm_f31_release( $go_path );
	if ( 'used' !== dreamax_lm_f31_collect( $use_worker ) || 'rotated' !== dreamax_lm_f31_collect( $mutation_worker ) ) {
		throw new RuntimeException( 'Use and rotation did not serialize successfully.' );
	}
	$workers_verified                 += 2;
	$checks['use_rotation_serialized'] = true;

	$failure_stage = 'use_revocation_serialization';
	$revoke_safe   = $service->authenticate( 'Bearer ' . $use_revoke['credential'] );
	if ( ! is_array( $revoke_safe ) ) {
		throw new RuntimeException( 'The use/revocation fixture did not authenticate.' );
	}
	$ready_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f31-ready-' . dreamax_lm_f31_suffix();
	$go_path    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dreamax-lm-f31-go-' . dreamax_lm_f31_suffix();
	$barriers[] = $ready_path;
	$barriers[] = $go_path;
	$use_worker = dreamax_lm_f31_spawn(
		array(
			'mode'       => 'use_hold',
			'public_id'  => $use_revoke['public_id'],
			'version'    => 1,
			'actor_id'   => $actor_id,
			'credential' => $revoke_safe,
			'ready'      => $ready_path,
			'go'         => $go_path,
		),
		$wp_root,
		$marker
	);
	dreamax_lm_f31_ready( $ready_path, $use_worker );
	$mutation_worker = dreamax_lm_f31_spawn(
		array(
			'mode'      => 'revoke',
			'public_id' => $use_revoke['public_id'],
			'version'   => 1,
			'actor_id'  => $actor_id,
		),
		$wp_root,
		$marker
	);
	usleep( 300000 );
	if ( ! proc_get_status( $mutation_worker['process'] )['running'] ) {
		throw new RuntimeException( 'Revocation did not wait behind in-flight use.' );
	}
	dreamax_lm_f31_release( $go_path );
	if ( 'used' !== dreamax_lm_f31_collect( $use_worker ) || 'revoked' !== dreamax_lm_f31_collect( $mutation_worker ) || null !== $service->authenticate( 'Bearer ' . $use_revoke['credential'] ) ) {
		throw new RuntimeException( 'Use and revocation did not serialize and fail closed.' );
	}
	$workers_verified                   += 2;
	$checks['use_revocation_serialized'] = true;

	$failure_stage = 'audit_safety';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reviews only post-floor events for exact owned fixtures.
	$events = $wpdb->get_results( $wpdb->prepare( "SELECT id,license_id,event_type,actor_type,actor_id,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE id>%d ORDER BY id", $event_floor ), ARRAY_A );
	$counts = array();
	foreach ( is_array( $events ) ? $events : array() as $event ) {
		$metadata = json_decode( (string) $event['metadata'], true );
		$owned    = in_array( (int) ( $event['license_id'] ?? 0 ), $license_ids, true )
			|| ( 'api_credential' === (string) $event['actor_type'] && in_array( (int) $event['actor_id'], $credential_ids, true ) )
			|| ( is_array( $metadata ) && in_array( (string) ( $metadata['credential_public_id'] ?? '' ), $credentials, true ) );
		if ( ! $owned ) {
			continue;
		}
		$encoded = (string) $event['metadata'];
		foreach ( $tokens as $token ) {
			if ( str_contains( $encoded, $token ) ) {
				throw new RuntimeException( 'Audit metadata retained a private Bearer value.' );
			}
		}
		if ( preg_match( '/authorization|secret_hash|request_body|plaintext/i', $encoded ) ) {
			throw new RuntimeException( 'Audit metadata retained a forbidden credential field.' );
		}
		$event_type            = (string) $event['event_type'];
		$counts[ $event_type ] = (int) ( $counts[ $event_type ] ?? 0 ) + 1;
		++$audit_rows_verified;
	}
	$required_counts = array(
		AuditEventCatalog::CREDENTIAL_CREATED             => count( $credentials ),
		AuditEventCatalog::CREDENTIAL_AUTHENTICATION_USED => 1,
		AuditEventCatalog::CREDENTIAL_ROTATION_SUCCEEDED  => 2,
		AuditEventCatalog::CREDENTIAL_ROTATION_FAILED     => 2,
		AuditEventCatalog::CREDENTIAL_REVOKED             => 2,
		AuditEventCatalog::CREDENTIAL_EXPIRED_AUTHENTICATION_FAILED => 1,
		AuditEventCatalog::CREDENTIAL_INSUFFICIENT_SCOPE  => 4,
	);
	foreach ( $required_counts as $event_type => $minimum ) {
		if ( (int) ( $counts[ $event_type ] ?? 0 ) < $minimum ) {
			throw new RuntimeException( 'A required credential audit event was missing.' );
		}
	}
	$checks['audit_payloads_private'] = true;
	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'One or more F31 checks failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( $trusted_had ) {
		update_option( 'dreamax_lm_trusted_proxies', $trusted_before, false );
	} else {
		delete_option( 'dreamax_lm_trusted_proxies' );
	}
	if ( $http_had ) {
		update_option( 'dreamax_lm_allow_http_local', $http_before, false );
	} else {
		delete_option( 'dreamax_lm_allow_http_local' );
	}
	if ( false !== $error_log_before ) {
		// phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores exact parent logging destination.
		ini_set( 'error_log', (string) $error_log_before );
	}
	wp_set_current_user( $current_user_before );
	if ( $htaccess_changed ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Restores the exact pre-run disposable configuration.
		$htaccess_restored = is_string( $htaccess_before ) && strlen( $htaccess_before ) === file_put_contents( $htaccess_path, $htaccess_before, LOCK_EX );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Confirms the exact local configuration remained unchanged.
		$htaccess_restored = is_string( $htaccess_before ) && file_get_contents( $htaccess_path ) === $htaccess_before;
	}

	$cleanup_ok = false !== $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $cleanup_ok ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned cleanup and state restoration.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Selects only post-floor rows to identify exact owned events.
		$post_events = $wpdb->get_results( $wpdb->prepare( "SELECT id,license_id,actor_type,actor_id,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE id>%d", $event_floor ), ARRAY_A );
		foreach ( is_array( $post_events ) ? $post_events : array() as $event ) {
			$metadata = json_decode( (string) $event['metadata'], true );
			$owned    = in_array( (int) ( $event['license_id'] ?? 0 ), $license_ids, true )
				|| ( 'api_credential' === (string) $event['actor_type'] && in_array( (int) $event['actor_id'], $credential_ids, true ) )
				|| ( is_array( $metadata ) && in_array( (string) ( $metadata['credential_public_id'] ?? '' ), $credentials, true ) );
			if ( $owned ) {
				$cleanup_ok = $cleanup_ok && false !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'id' => (int) $event['id'] ), array( '%d' ) );
			}
		}
		foreach ( $license_ids as $license_id ) {
			$cleanup_ok = $cleanup_ok && false !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $license_id ), array( '%d' ) );
			$cleanup_ok = $cleanup_ok && 1 === $wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) );
		}
		foreach ( $credentials as $public_id ) {
			$cleanup_ok = $cleanup_ok && 1 === $wpdb->delete( $wpdb->prefix . 'dreamax_lm_api_credentials', array( 'public_id' => $public_id ), array( '%s' ) );
		}
		foreach ( array_keys( $rate_hashes ) as $hex ) {
			$cleanup_ok = $cleanup_ok && false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}dreamax_lm_rate_limits WHERE bucket_hash=UNHEX(%s)", $hex ) );
			$row        = $rate_before[ $hex ] ?? null;
			if ( is_array( $row ) ) {
				$cleanup_ok = $cleanup_ok && false !== $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->prefix}dreamax_lm_rate_limits (bucket_hash,tokens,updated_microtime,expires_at) VALUES (UNHEX(%s),%f,%f,%s)", $hex, (float) $row['tokens'], (float) $row['updated_microtime'], (string) $row['expires_at'] ) );
			}
		}
		$cleanup_ok = $cleanup_ok && false !== $wpdb->query( 'COMMIT' );
		if ( ! $cleanup_ok ) {
			$wpdb->query( 'ROLLBACK' );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	foreach ( $tokens as &$token ) {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $token );
		}
	}
	unset( $token );
	foreach ( $barriers as $barrier ) {
		if ( is_file( $barrier ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only exact random F31 barrier files.
			unlink( $barrier );
		}
	}
	$cleanup_complete = $cleanup_ok && $htaccess_restored;
}

$after = dreamax_lm_f31_snapshot();
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact named-residue diagnosis.
$residue  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE name LIKE %s", $name_prefix . '%' ) );
$residue += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE product_public_id=%s", $product_public_id ) );
// phpcs:enable
if ( ! $cleanup_complete || $before !== $after || 0 !== $residue ) {
	dreamax_lm_f31_fail( 'cleanup' );
}
if ( $failure instanceof Throwable ) {
	dreamax_lm_f31_fail( $failure_stage );
}

echo wp_json_encode(
	array(
		'classification'                  => 'live_disposable_wordpress_rest_http_innodb_parallel_workers',
		'credential_contracts_verified'   => count( $checks ),
		'loopback_http_requests_verified' => $http_requests,
		'parallel_workers_verified'       => $workers_verified,
		'all_four_scopes_allowed'         => true,
		'all_wrong_scopes_denied'         => true,
		'uniform_authentication_failures' => true,
		'duplicate_raw_header_rejected'   => true,
		'query_and_body_proof_rejected'   => true,
		'proxy_boundary_verified'         => true,
		'authorization_forwarding_tested' => true,
		'web_configuration_restored'      => true,
		'expiration_boundary_closed'      => true,
		'per_credential_rate_limited'     => true,
		'last_used_write_coalesced'       => true,
		'audit_failure_rolled_back'       => true,
		'concurrency_serialized'          => true,
		'audit_rows_verified'             => $audit_rows_verified,
		'audit_payloads_private'          => true,
		'cleanup_complete'                => true,
		'database_aggregates_unchanged'   => true,
		'owned_fixture_rows_remaining'    => 0,
		'outbound_email_sent'             => false,
		'sensitive_output'                => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
