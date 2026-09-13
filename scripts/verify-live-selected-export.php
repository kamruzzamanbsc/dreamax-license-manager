<?php
/**
 * Verifies masked selected-row CSV export inside a disposable transaction.
 *
 * Run with wp eval-file scripts/verify-live-selected-export.php --path=<disposable-site>.
 *
 * @package DreamaxLicenseManager
 */

use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\ImportExport\CsvController;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'DB_NAME' ) ) {
	throw new RuntimeException( 'Run this verifier through WP-CLI on a disposable site.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => 'DREAMAX_LM_DISPOSABLE_TEST',
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);

global $wpdb;
$table  = $wpdb->prefix . 'dreamax_lm_licenses';
$events = $wpdb->prefix . 'dreamax_lm_events';
foreach ( array( $table, $events ) as $checked_table ) {
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $checked_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded InnoDB check.
	if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) {
		throw new RuntimeException( 'The disposable tables must be transactional.' );
	}
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$crypto = new Crypto();
if ( array() === $admins || ! $crypto->ready() ) {
	throw new RuntimeException( 'The disposable site needs an administrator and a working test key.' );
}
$baseline_licenses = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
$baseline_events   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable aggregate baseline.
$previous_user     = get_current_user_id();
$previous_post     = $_POST;
$previous_request  = $_REQUEST;
$ids               = array( PublicId::generate( 'lic' ), PublicId::generate( 'lic' ) );
$keys              = array( 'AAAA-TEST-1234', 'BBBB-TEST-5678' );
$now               = gmdate( 'Y-m-d H:i:s' );
$transaction       = false;
$buffer            = false;
$passed            = array();
$captured          = '';
$previous_level    = ob_get_level();

register_shutdown_function(
	static function () use ( &$transaction, &$buffer, &$passed, &$captured, $previous_level, $previous_user, $previous_post, $previous_request, $baseline_licenses, $baseline_events, $table, $events, $ids, $keys, $wpdb ): void {
		$csv = $captured;
		if ( $buffer && ob_get_level() > $previous_level ) {
			$csv .= (string) ob_get_clean();
		}
		if ( $transaction ) {
			$last_event = $wpdb->get_row( "SELECT event_type,metadata FROM {$events} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect the final event inside the disposable transaction only.
			$event_data = is_array( $last_event ) ? json_decode( (string) $last_event['metadata'], true ) : null;
			$passed['audit_written'] = $baseline_events + 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ) && is_array( $last_event ) && 'license_exported' === $last_event['event_type'] && is_array( $event_data ) && ( $event_data['row_count'] ?? -1 ) === 1 && ( $event_data['full_keys'] ?? true ) === false; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact disposable event count and privacy facts.
			$rolled_back = false !== $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The verifier owns the transaction and all synthetic rows.
		} else {
			$rolled_back = false;
		}
		$passed['one_selected_row'] = str_contains( $csv, $ids[0] ) && ! str_contains( $csv, $ids[1] );
		$passed['masked_only'] = str_contains( $csv, 'AAAA********1234' ) && ! str_contains( $csv, $keys[0] ) && ! str_contains( $csv, $keys[1] );
		$passed['csv_header'] = str_starts_with( $csv, 'public_id,license_key,product_public_id' );
		$passed['rollback'] = $rolled_back && $baseline_licenses === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) && $baseline_events === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$events}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact aggregate rollback check.
		$_POST = $previous_post;
		$_REQUEST = $previous_request;
		wp_set_current_user( $previous_user );
		echo wp_json_encode( $passed ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI prints fixed booleans only.
		if ( in_array( false, $passed, true ) ) {
			exit( 1 );
		}
	}
);

try {
	wp_set_current_user( (int) $admins[0] );
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded disposable test transaction.
		throw new RuntimeException( 'The disposable transaction did not start.' );
	}
	$transaction = true;
	foreach ( $ids as $index => $public_id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Synthetic rows are rolled back in the shutdown handler.
		$created = $wpdb->insert(
			$table,
			array(
				'public_id'             => $public_id,
				'key_ciphertext'        => $crypto->encrypt( $keys[ $index ] ),
				'key_fingerprint'       => random_bytes( 32 ),
				'normalization_profile' => 'generated-ascii-v1',
				'lifecycle_status'      => 'available',
				'product_public_id'     => PublicId::generate( 'prd' ),
				'created_at'            => $now,
				'updated_at'            => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $created ) {
			throw new RuntimeException( 'A synthetic license could not be created.' );
		}
	}
	$_POST = array( '_wpnonce' => wp_create_nonce( 'dreamax_lm_bulk_lifecycle' ), 'full_keys' => '1' );
	$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];
	ob_start(
		static function ( string $chunk ) use ( &$captured ): string {
			$captured .= $chunk;
			return '';
		}
	);
	$buffer = true;
	$passed['runtime'] = true;
	( new CsvController() )->export_selected( array( $ids[0] ) );
} catch ( Throwable $error ) {
	$passed['runtime'] = false;
}
