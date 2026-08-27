<?php
/**
 * Verifies direct and loopback-intermediary private-cache response contracts.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f14_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports only sanitized failures.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Confirms the complete private-cache response contract.
 *
 * @param array|WP_Error $response HTTP response.
 * @phpstan-param array<string,mixed>|WP_Error $response HTTP response.
 * @throws RuntimeException When a response does not preserve the contract.
 */
function dreamax_lm_f14_headers( $response ): void {
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		throw new RuntimeException( 'A private response was unavailable.' );
	}
	$cache_control = strtolower( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );
	foreach ( array( 'no-store', 'no-cache', 'must-revalidate', 'private', 'max-age=0' ) as $directive ) {
		if ( ! str_contains( $cache_control, $directive ) ) {
			throw new RuntimeException( 'A private cache-control directive was missing.' );
		}
	}
	if ( 'no-cache' !== strtolower( (string) wp_remote_retrieve_header( $response, 'pragma' ) )
		|| 'Wed, 11 Jan 1984 05:00:00 GMT' !== (string) wp_remote_retrieve_header( $response, 'expires' ) ) {
		throw new RuntimeException( 'A private compatibility cache header was missing.' );
	}
}

/**
 * Returns a free loopback TCP port.
 *
 * @throws RuntimeException When a port cannot be reserved.
 */
function dreamax_lm_f14_port(): int {
	$error_code = 0;
	$error_text = '';
	$socket     = stream_socket_server( 'tcp://127.0.0.1:0', $error_code, $error_text );
	if ( false === $socket ) {
		unset( $error_code, $error_text );
		throw new RuntimeException( 'A loopback intermediary port was unavailable.' );
	}
	$name = stream_socket_get_name( $socket, false );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes an ephemeral TCP socket, not a filesystem resource.
	fclose( $socket );
	if ( ! is_string( $name ) || ! preg_match( '/:(\d+)$/D', $name, $matches ) ) {
		throw new RuntimeException( 'A loopback intermediary port could not be resolved.' );
	}
	return (int) $matches[1];
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f14_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f14_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) ) {
	dreamax_lm_f14_fail( 'The required WordPress plugins are not active.' );
}

global $wpdb;
$repository = new LicenseRepository();
$candidates = array();
foreach ( wc_get_orders(
	array(
		'limit'   => 25,
		'status'  => array( 'processing' ),
		'orderby' => 'date',
		'order'   => 'DESC',
	)
) as $candidate_order ) {
	if ( ! $candidate_order instanceof WC_Order
		|| ! $candidate_order->is_paid()
		|| (int) $candidate_order->get_customer_id() < 1
		|| ! str_ends_with( strtolower( (string) $candidate_order->get_billing_email() ), '@example.invalid' ) ) {
		continue;
	}
	$rows = $repository->for_order( (int) $candidate_order->get_id() );
	if ( 1 === count( $rows ) && 'assigned' === (string) $rows[0]['lifecycle_status'] && (int) $rows[0]['customer_id'] === (int) $candidate_order->get_customer_id() ) {
		$candidates[] = array(
			'order'   => $candidate_order,
			'license' => $rows[0],
		);
	}
}
if ( 1 !== count( $candidates ) ) {
	dreamax_lm_f14_fail( 'Exactly one sanitized registered-customer fixture is required.' );
}

$fixture_order  = $candidates[0]['order'];
$license        = $candidates[0]['license'];
$owner_id       = (int) $fixture_order->get_customer_id();
$license_id     = (int) $license['id'];
$license_public = (string) $license['public_id'];
$license_key    = $repository->decrypt_key( $license );
$session_had    = metadata_exists( 'user', $owner_id, 'session_tokens' );
$session_before = get_user_meta( $owner_id, 'session_tokens', true );
$cookie_before  = $_COOKIE;
$user_before    = get_current_user_id();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact verifier-owned reveal cleanup requires a fresh starting identity set.
$event_ids_before   = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s AND actor_type=%s AND actor_id=%d",
		$license_id,
		AuditEventCatalog::LICENSE_REVEALED,
		'customer',
		$owner_id
	)
);
$event_count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$proxy              = null;
$proxy_pipes        = array();
$failure            = null;
$checks             = array();

try {
	$expiration  = time() + 10 * MINUTE_IN_SECONDS;
	$token       = WP_Session_Tokens::get_instance( $owner_id )->create( $expiration );
	$cookie      = wp_generate_auth_cookie( $owner_id, $expiration, 'logged_in', $token );
	$cookie_name = defined( 'LOGGED_IN_COOKIE' ) ? (string) constant( 'LOGGED_IN_COOKIE' ) : '';
	if ( '' === $cookie_name ) {
		throw new RuntimeException( 'The authenticated cookie name is unavailable.' );
	}
	$header                  = $cookie_name . '=' . $cookie;
	$_COOKIE[ $cookie_name ] = $cookie;
	wp_set_current_user( $owner_id );
	$nonce = wp_create_nonce( 'dreamax_lm_reveal' );

	$document_target = wc_get_account_endpoint_url( 'licenses' );
	$reveal_target   = admin_url( 'admin-ajax.php' );
	$document_args   = array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => array( 'Cookie' => $header ),
	);
	$reveal_args     = array(
		'timeout'     => 15,
		'redirection' => 0,
		'headers'     => array( 'Cookie' => $header ),
		'body'        => array(
			'action'  => 'dreamax_lm_reveal',
			'nonce'   => $nonce,
			'license' => $license_public,
		),
	);

	$direct_document = wp_remote_get( $document_target, $document_args );
	dreamax_lm_f14_headers( $direct_document );
	$direct_document_body              = wp_remote_retrieve_body( $direct_document );
	$checks['direct_document_private'] = str_contains( $direct_document_body, $license_public ) && ! str_contains( $direct_document_body, $license_key );

	$direct_reveal = wp_remote_post( $reveal_target, $reveal_args );
	dreamax_lm_f14_headers( $direct_reveal );
	$direct_reveal_data              = json_decode( wp_remote_retrieve_body( $direct_reveal ), true );
	$checks['direct_reveal_private'] = is_array( $direct_reveal_data )
		&& true === ( $direct_reveal_data['success'] ?? false )
		&& hash_equals( hash( 'sha256', $license_key ), hash( 'sha256', (string) ( $direct_reveal_data['data']['key'] ?? '' ) ) );

	$port        = dreamax_lm_f14_port();
	$environment = getenv();
	$environment = is_array( $environment ) ? $environment : array();
	$environment['DREAMAX_LM_CACHE_DOCUMENT_TARGET'] = $document_target;
	$environment['DREAMAX_LM_CACHE_REVEAL_TARGET']   = $reveal_target;
	$descriptors                                     = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Starts only the committed loopback-only PHP intermediary and terminates it in finally.
	$proxy = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/lib/private-cache-proxy-router.php' ), $descriptors, $proxy_pipes, __DIR__, $environment );
	if ( ! is_resource( $proxy ) ) {
		throw new RuntimeException( 'The loopback intermediary could not start.' );
	}
	foreach ( $proxy_pipes as $pipe ) {
		stream_set_blocking( $pipe, false );
	}
	$proxy_base = 'http://127.0.0.1:' . $port;
	$ready      = false;
	for ( $attempt = 0; $attempt < 30; ++$attempt ) {
		$health = wp_remote_get(
			$proxy_base . '/health',
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
		throw new RuntimeException( 'The loopback intermediary did not become ready.' );
	}

	$proxy_document = wp_remote_get( $proxy_base . '/document', $document_args );
	dreamax_lm_f14_headers( $proxy_document );
	$proxy_document_body                     = wp_remote_retrieve_body( $proxy_document );
	$checks['intermediary_document_private'] = str_contains( $proxy_document_body, $license_public ) && ! str_contains( $proxy_document_body, $license_key );

	$proxy_reveal = wp_remote_post( $proxy_base . '/reveal', $reveal_args );
	dreamax_lm_f14_headers( $proxy_reveal );
	$proxy_reveal_data                     = json_decode( wp_remote_retrieve_body( $proxy_reveal ), true );
	$checks['intermediary_reveal_private'] = is_array( $proxy_reveal_data )
		&& true === ( $proxy_reveal_data['success'] ?? false )
		&& hash_equals( hash( 'sha256', $license_key ), hash( 'sha256', (string) ( $proxy_reveal_data['data']['key'] ?? '' ) ) );

	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'A private response content assertion failed.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( is_resource( $proxy ) ) {
		proc_terminate( $proxy );
	}
	foreach ( $proxy_pipes as $pipe ) {
		if ( is_resource( $pipe ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes an ephemeral child-process pipe, not a filesystem resource.
			fclose( $pipe );
		}
	}
	if ( is_resource( $proxy ) ) {
		proc_close( $proxy );
	}
	wp_set_current_user( 0 );
	if ( $session_had ) {
		update_user_meta( $owner_id, 'session_tokens', $session_before );
	} else {
		delete_user_meta( $owner_id, 'session_tokens' );
	}
	clean_user_cache( $owner_id );
	$_COOKIE = $cookie_before;
	wp_set_current_user( $user_before );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Identify only reveal rows created inside the controlled verifier window.
	$event_ids_after = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s AND actor_type=%s AND actor_id=%d",
			$license_id,
			AuditEventCatalog::LICENSE_REVEALED,
			'customer',
			$owner_id
		)
	);
	$owned_events    = array_diff( array_map( 'intval', $event_ids_after ), array_map( 'intval', $event_ids_before ) );
	foreach ( $owned_events as $event_id ) {
		$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'id' => $event_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete only an exact verifier-owned event identity.
	}
}

$session_restored = $session_had
	? get_user_meta( $owner_id, 'session_tokens', true ) === $session_before
	: ! metadata_exists( 'user', $owner_id, 'session_tokens' );
$events_restored  = $event_count_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( function_exists( 'sodium_memzero' ) ) {
	sodium_memzero( $license_key );
}
unset( $cookie, $header, $nonce, $token, $direct_reveal_data, $proxy_reveal_data );
if ( ! $session_restored || ! $events_restored ) {
	dreamax_lm_f14_fail( 'The temporary authentication or audit state was not restored.' );
}
if ( $failure instanceof Throwable ) {
	dreamax_lm_f14_fail( $failure->getMessage() );
}

echo wp_json_encode(
	array(
		'classification'                => 'live_authenticated_http_loopback_intermediary',
		'private_responses_verified'    => count( $checks ),
		'direct_document_headers'       => true,
		'direct_reveal_headers'         => true,
		'intermediary_document_headers' => true,
		'intermediary_reveal_headers'   => true,
		'full_keys_masked_in_document'  => true,
		'session_state_restored'        => $session_restored,
		'audit_state_restored'          => $events_restored,
		'sensitive_output'              => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
