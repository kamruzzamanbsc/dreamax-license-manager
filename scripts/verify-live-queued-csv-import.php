<?php
/**
 * Verifies resumable 250-row CSV import batches on a disposable WordPress site.
 *
 * Run with wp eval-file scripts/verify-live-queued-csv-import.php --path=<disposable-site>.
 *
 * @package DreamaxLicenseManager
 */

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\ImportExport\CsvImportJob;
use Dreamax\LicenseManager\ImportExport\CsvImportProcessor;
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
$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( array() === $admins ) {
	throw new RuntimeException( 'A disposable administrator is required.' );
}

$license_table = $wpdb->prefix . 'dreamax_lm_licenses';
$event_table   = $wpdb->prefix . 'dreamax_lm_events';
$product_id    = PublicId::generate( 'prd' );
$token         = bin2hex( random_bytes( 16 ) );
$option_name   = 'dreamax_lm_csv_job_' . $token;
$row_count     = 501;
$temp_path     = wp_tempnam( 'dreamax-lm-queued-verifier.csv' );
$previous_user = get_current_user_id();
$before        = array(
	'licenses' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $license_table ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded aggregate baseline.
	'events'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $event_table ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded aggregate baseline.
);
$checks        = array();
$failure       = null;

if ( ! is_string( $temp_path ) || '' === $temp_path ) {
	throw new RuntimeException( 'A private temporary path could not be created.' );
}

try {
	wp_set_current_user( (int) $admins[0] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Creates one owned private CSV fixture.
	$handle = fopen( $temp_path, 'wb' );
	if ( false === $handle ) {
		throw new RuntimeException( 'The queued CSV fixture could not be opened.' );
	}
	fputcsv( $handle, array( 'license_key', 'product_public_id', 'activation_limit', 'expires_at', 'normalization_profile', 'separator' ), ',', '"', '' );
	for ( $index = 0; $index < $row_count; ++$index ) {
		$key = 'F19Q-' . str_pad( (string) $index, 4, '0', STR_PAD_LEFT ) . '-' . str_repeat( hash( 'sha256', $token . ':' . $index ), 9 );
		fputcsv( $handle, array( $key, $product_id, '2', '2030-01-02 00:00:00', 'import-exact-v1', '' ), ',', '"', '' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact owned fixture.
	fclose( $handle );
	chmod( $temp_path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restricts the exact owned fixture.
	$checks['threshold_fixture'] = filesize( $temp_path ) > 262144;

	$configuration = array(
		'delimiter' => ',',
		'encoding'  => 'UTF-8',
		'mapping'   => array(
			'license_key'           => 'license_key',
			'product_public_id'     => 'product_public_id',
			'activation_limit'      => 'activation_limit',
			'expires_at'            => 'expires_at',
			'normalization_profile' => 'normalization_profile',
			'separator'             => 'separator',
		),
		'duplicate' => 'error',
		'dry_run'   => false,
		'actor_id'  => (int) $admins[0],
		'headers'   => array( 'license_key', 'product_public_id', 'activation_limit', 'expires_at', 'normalization_profile', 'separator' ),
	);
	$state         = array(
		'owner'         => (int) $admins[0],
		'path'          => $temp_path,
		'report_path'   => '',
		'configuration' => $configuration,
		'offset'        => 0,
		'row'           => 1,
		'valid'         => 0,
		'skipped'       => 0,
		'errors'        => array(),
		'status'        => 'queued',
		'created_at'    => time(),
		'updated_at'    => time(),
	);
	if ( ! add_option( $option_name, $state, '', false ) ) {
		throw new RuntimeException( 'The disposable queued state could not be stored.' );
	}

	$job       = new CsvImportJob();
	$lock_name = $option_name . '_lock';
	$acquire   = new ReflectionMethod( CsvImportJob::class, 'acquire_lock' );
	$release   = new ReflectionMethod( CsvImportJob::class, 'release_lock' );
	$acquire->setAccessible( true );
	$release->setAccessible( true );
	add_option( $lock_name, '1:expired-verifier', '', false );
	$successor = $acquire->invoke( $job, $token );
	if ( ! is_string( $successor ) ) {
		throw new RuntimeException( 'An expired verifier lock could not be claimed.' );
	}
	$release->invoke( $job, $token, '1:expired-verifier' );
	$checks['expired_lock_handoff'] = get_option( $lock_name ) === $successor;
	$release->invoke( $job, $token, $successor );
	$checks['exact_lock_release'] = false === get_option( $lock_name, false );
	add_option( $option_name . '_lock', time() + MINUTE_IN_SECONDS, '', false );
	$job->process( $token );
	$contended             = $job->for_owner( $token, (int) $admins[0] );
	$checks['worker_lock'] = is_array( $contended ) && 'queued' === $contended['status'] && 0 === (int) $contended['valid'] && 0 === (int) $contended['offset'];
	delete_option( $option_name . '_lock' );
	$job->process( $token );
	$first                 = $job->for_owner( $token, (int) $admins[0] );
	$checks['first_batch'] = is_array( $first ) && 'queued' === $first['status'] && 250 === (int) $first['valid'] && 251 === (int) $first['row'] && (int) $first['offset'] > 0;
	$checks['owner_scope'] = null === $job->for_owner( $token, (int) $admins[0] + 100000 );

	$job->process( $token );
	$second                 = $job->for_owner( $token, (int) $admins[0] );
	$checks['second_batch'] = is_array( $second ) && 'queued' === $second['status'] && 500 === (int) $second['valid'] && 501 === (int) $second['row'] && is_array( $first ) && (int) $second['offset'] > (int) $first['offset'];

	$job->process( $token );
	$complete                       = $job->for_owner( $token, (int) $admins[0] );
	$checks['final_batch']          = is_array( $complete ) && 'complete' === $complete['status'] && $row_count === (int) $complete['valid'] && 502 === (int) $complete['row'] && 0 === (int) $complete['skipped'] && array() === $complete['errors'];
	$checks['private_file_removed'] = is_array( $complete ) && '' === $complete['path'] && ! file_exists( $temp_path );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact guarded fixture assertions.
	$license_count            = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE product_public_id=%s', $license_table, $product_id ) );
	$audit_count              = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i e INNER JOIN %i l ON l.id=e.license_id WHERE l.product_public_id=%s AND e.event_type=%s', $event_table, $license_table, $product_id, AuditEventCatalog::LICENSE_CREATED ) );
	$checks['rows_and_audit'] = $row_count === $license_count && $row_count === $audit_count;
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'dreamax_lm_process_csv_import', array( $token ), 'dreamax-license-manager' );
	}
	wp_clear_scheduled_hook( 'dreamax_lm_process_csv_import', array( $token ) );
	wp_clear_scheduled_hook( 'dreamax_lm_cleanup_csv_job', array( $token ) );
	$stored = get_option( $option_name, null );
	if ( is_array( $stored ) ) {
		foreach ( array( 'path', 'report_path' ) as $field ) {
			if ( ! empty( $stored[ $field ] ) && is_string( $stored[ $field ] ) ) {
				wp_delete_file( $stored[ $field ] );
			}
		}
	}
	delete_option( $option_name );
	delete_option( $option_name . '_lock' );
	if ( file_exists( $temp_path ) ) {
		wp_delete_file( $temp_path );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only rows owned by the random product fixture.
	$owned_ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE product_public_id=%s', $license_table, $product_id ) );
	if ( is_array( $owned_ids ) && array() !== $owned_ids ) {
		foreach ( $owned_ids as $owned_id ) {
			$wpdb->delete( $event_table, array( 'license_id' => (int) $owned_id ), array( '%d' ) );
			$wpdb->delete( $license_table, array( 'id' => (int) $owned_id ), array( '%d' ) );
		}
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	wp_set_current_user( $previous_user );
}

$after             = array(
	'licenses' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $license_table ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded aggregate cleanup assertion.
	'events'   => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $event_table ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded aggregate cleanup assertion.
);
$checks['cleanup'] = $before === $after && false === get_option( $option_name, false );
if ( $failure instanceof Throwable || in_array( false, $checks, true ) ) {
	$failed = implode( ',', array_keys( array_filter( $checks, static fn( bool $passed ): bool => ! $passed ) ) );
	throw new RuntimeException( esc_html( 'Queued CSV verification failed; checks: ' . ( '' === $failed ? 'runtime' : $failed ) . '.' ) );
}

echo wp_json_encode(
	$checks + array(
		'batch_size' => CsvImportProcessor::BATCH_SIZE,
		'runtime'    => true,
	)
);
