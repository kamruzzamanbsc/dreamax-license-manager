<?php
/**
 * Dreamax License Manager uninstall handler.
 *
 * Data is retained unless a capability-protected setting was explicitly enabled beforehand.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dreamax_lm_permanent_delete = get_option( 'dreamax_lm_permanent_delete_on_uninstall', false );
if ( ! in_array( $dreamax_lm_permanent_delete, array( true, 1, '1' ), true ) ) {
	return;
}

global $wpdb;
$dreamax_lm_tables = array( 'order_owners', 'guest_claims', 'rate_limits', 'idempotency', 'api_credentials', 'generators', 'events', 'activations', 'licenses' );
foreach ( $dreamax_lm_tables as $dreamax_lm_table_name ) {
	$dreamax_lm_table = $wpdb->prefix . 'dreamax_lm_' . $dreamax_lm_table_name;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit permanent deletion requires removing plugin-owned tables during uninstall.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $dreamax_lm_table ) );
}

foreach ( array( 'dreamax_lm_schema_version', 'dreamax_lm_kdf_salt', 'dreamax_lm_master_key_id', 'dreamax_lm_trusted_proxies', 'dreamax_lm_allowed_origins', 'dreamax_lm_allow_http_local', 'dreamax_lm_permanent_delete_on_uninstall' ) as $dreamax_lm_option ) {
	delete_option( $dreamax_lm_option );
}
