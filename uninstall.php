<?php
/**
 * Dreamax License Manager uninstall handler.
 *
 * Data is retained unless a capability-protected setting was explicitly enabled beforehand.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$permanent_delete = get_option( 'dreamax_lm_permanent_delete_on_uninstall', false );
if ( ! in_array( $permanent_delete, array( true, 1, '1' ), true ) ) {
	return;
}

global $wpdb;
$tables = array( 'order_owners', 'guest_claims', 'rate_limits', 'idempotency', 'api_credentials', 'generators', 'events', 'activations', 'licenses' );
foreach ( $tables as $name ) {
	$table = $wpdb->prefix . 'dreamax_lm_' . $name;
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

foreach ( array( 'dreamax_lm_schema_version', 'dreamax_lm_kdf_salt', 'dreamax_lm_master_key_id', 'dreamax_lm_trusted_proxies', 'dreamax_lm_allowed_origins', 'dreamax_lm_allow_http_local', 'dreamax_lm_permanent_delete_on_uninstall' ) as $option ) {
	delete_option( $option );
}
