<?php
/**
 * Verifies the existing disposable simple paid-order path with local-only mail capture.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error that does not disclose order, customer, or license values.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f03_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier must report a sanitized failure to STDERR.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Calls the loopback-only Mailpit API.
 *
 * @param string $path API path.
 * @param string $method HTTP method.
 * @throws RuntimeException When the local API is unavailable.
 * @return array<string,mixed>
 */
function dreamax_lm_f03_mailpit( string $path, string $method = 'GET' ): array {
	$response = wp_remote_request(
		'http://127.0.0.1:8025/api/v1/' . ltrim( $path, '/' ),
		array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
		)
	);
	if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 400 ) {
		throw new RuntimeException( 'The local mail-catcher API is unavailable.' );
	}
	if ( 'DELETE' === $method ) {
		return array();
	}
	$body = wp_remote_retrieve_body( $response );
	if ( '' === $body ) {
		return array();
	}
	$decoded = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
	return is_array( $decoded ) ? $decoded : array();
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );

if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f03_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f03_fail( 'The database identity is unavailable.' );
}

DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);

if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) ) {
	dreamax_lm_f03_fail( 'The required WordPress plugins are not active.' );
}

global $wpdb;

$licenses   = new LicenseRepository();
$candidates = array();
$orders     = wc_get_orders(
	array(
		'limit'   => 25,
		'status'  => array( 'processing' ),
		'orderby' => 'date',
		'order'   => 'DESC',
	)
);

foreach ( $orders as $candidate_order ) {
	if ( ! $candidate_order instanceof WC_Order
		|| 'bacs' !== $candidate_order->get_payment_method()
		|| ! $candidate_order->is_paid()
		|| ! str_ends_with( strtolower( (string) $candidate_order->get_billing_email() ), '@example.invalid' ) ) {
		continue;
	}
	$order_licenses = $licenses->for_order( (int) $candidate_order->get_id() );
	if ( 1 !== count( $order_licenses ) || 'assigned' !== (string) $order_licenses[0]['lifecycle_status'] ) {
		continue;
	}
	$items = array_values( $candidate_order->get_items( 'line_item' ) );
	if ( 1 !== count( $items ) || ! $items[0] instanceof WC_Order_Item_Product || 1 !== (int) $items[0]->get_quantity() ) {
		continue;
	}
	$product = $items[0]->get_product();
	if ( ! $product instanceof WC_Product
		|| ! $product->is_type( 'simple' )
		|| ! $product->is_virtual()
		|| 'yes' !== $product->get_meta( '_dreamax_lm_enabled', true )
		|| 'generated' !== $product->get_meta( '_dreamax_lm_source', true )
		|| 'per_quantity' !== $product->get_meta( '_dreamax_lm_issuance', true ) ) {
		continue;
	}
	if ( 1 !== (int) $order_licenses[0]['quantity_slot']
		|| (int) $items[0]->get_id() !== (int) $order_licenses[0]['order_item_id']
		|| 1 !== (int) $items[0]->get_meta( '_dreamax_lm_delivery_target_slots', true ) ) {
		continue;
	}
	$candidates[] = array(
		'order'   => $candidate_order,
		'item'    => $items[0],
		'license' => $order_licenses[0],
	);
}

if ( 1 !== count( $candidates ) ) {
	dreamax_lm_f03_fail( 'Exactly one sanitized F03 candidate order is required.' );
}

$candidate     = $candidates[0];
$fixture_order = $candidate['order'];
$license       = $candidate['license'];
$order_id      = (int) $fixture_order->get_id();
$license_id    = (int) $license['id'];

$event_types  = array(
	AuditEventCatalog::LICENSE_CREATED,
	AuditEventCatalog::LICENSE_ASSIGNED,
	AuditEventCatalog::LICENSE_DELIVERED,
);
$event_counts = array();
foreach ( $event_types as $event_type ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact live audit evidence must bypass caches.
	$event_counts[ $event_type ] = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id = %d AND event_type = %s",
			$license_id,
			$event_type
		)
	);
}
if ( array( 1, 1, 1 ) !== array_values( $event_counts ) ) {
	dreamax_lm_f03_fail( 'The exact created, assigned, and delivered audit aggregate is not present.' );
}

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The verifier proves the email test leaves plugin data unchanged.
$plugin_rows_before = (int) $wpdb->get_var( "SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses) + (SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events)" );
$notes_before       = count( wc_get_order_notes( array( 'order_id' => $order_id ) ) );

try {
	dreamax_lm_f03_mailpit( 'messages', 'DELETE' );
	add_filter( 'woocommerce_email_log_enabled', '__return_false', PHP_INT_MAX );

	add_action(
		'phpmailer_init',
		static function ( PHPMailer\PHPMailer\PHPMailer $mailer ): void {
			$mailer->isSMTP();
			// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- PHPMailer public API uses these exact property names.
			$mailer->Host        = '127.0.0.1';
			$mailer->Port        = 1025;
			$mailer->SMTPAuth    = false;
			$mailer->SMTPAutoTLS = false;
			$mailer->SMTPSecure  = '';
			// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		},
		PHP_INT_MAX
	);

	$emails           = WC()->mailer()->get_emails();
	$processing_email = $emails['WC_Email_Customer_Processing_Order'] ?? null;
	if ( ! $processing_email instanceof WC_Email_Customer_Processing_Order
		|| 'yes' !== $processing_email->get_option( 'enabled', 'yes' ) ) {
		throw new RuntimeException( 'The processing-order email is not safely configured for the synthetic recipient.' );
	}

	$processing_email->trigger( $order_id, $fixture_order );
	if ( strtolower( (string) $processing_email->get_recipient() ) !== strtolower( (string) $fixture_order->get_billing_email() ) ) {
		throw new RuntimeException( 'The processing-order recipient did not match the verified disposable order.' );
	}
	usleep( 500000 );

	$message_list = dreamax_lm_f03_mailpit( 'messages' );
	$messages     = isset( $message_list['messages'] ) && is_array( $message_list['messages'] ) ? $message_list['messages'] : array();
	if ( 1 !== count( $messages ) ) {
		throw new RuntimeException( 'The local mail catcher did not receive exactly one processing email.' );
	}
	$message_id = isset( $messages[0]['ID'] ) ? (string) $messages[0]['ID'] : '';
	if ( '' === $message_id || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/D', $message_id ) ) {
		throw new RuntimeException( 'The captured message identifier is invalid.' );
	}

	$message      = dreamax_lm_f03_mailpit( 'message/' . rawurlencode( $message_id ) );
	$message_json = wp_json_encode( $message, JSON_UNESCAPED_SLASHES );
	$license_key  = $licenses->decrypt_key( $license );
	$mail_matched = is_string( $message_json ) && str_contains( $message_json, $license_key );
	if ( function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $license_key );
	}
	if ( ! $mail_matched ) {
		throw new RuntimeException( 'The captured processing email did not contain the assigned license.' );
	}
} catch ( Throwable $error ) {
	try {
		dreamax_lm_f03_mailpit( 'messages', 'DELETE' );
	} catch ( Throwable $cleanup_error ) {
		unset( $cleanup_error );
	}
	dreamax_lm_f03_fail( $error->getMessage() );
}

dreamax_lm_f03_mailpit( 'messages', 'DELETE' );
$mailpit_after = dreamax_lm_f03_mailpit( 'messages' );
$messages_left = isset( $mailpit_after['messages'] ) && is_array( $mailpit_after['messages'] ) ? count( $mailpit_after['messages'] ) : 0;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The verifier proves the email test leaves plugin data unchanged.
$plugin_rows_after = (int) $wpdb->get_var( "SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses) + (SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events)" );
$notes_after       = count( wc_get_order_notes( array( 'order_id' => $order_id ) ) );

if ( 0 !== $messages_left || $plugin_rows_before !== $plugin_rows_after || $notes_before !== $notes_after ) {
	dreamax_lm_f03_fail( 'The local mail or database state was not restored.' );
}

echo wp_json_encode(
	array(
		'classification'          => 'live_disposable_wordpress_mailpit',
		'order_status'            => 'processing',
		'payment_method'          => 'bacs',
		'product_type'            => 'simple_virtual',
		'purchased_quantity'      => 1,
		'assigned_licenses'       => 1,
		'missing_slots'           => 0,
		'created_events'          => 1,
		'assigned_events'         => 1,
		'delivered_events'        => 1,
		'processing_emails'       => 1,
		'captured_license_match'  => true,
		'mail_catcher_loopback'   => true,
		'messages_after_cleanup'  => $messages_left,
		'database_rows_unchanged' => $plugin_rows_before === $plugin_rows_after,
		'order_notes_unchanged'   => $notes_before === $notes_after,
		'sensitive_output'        => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
