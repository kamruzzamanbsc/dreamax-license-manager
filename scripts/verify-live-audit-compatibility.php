<?php
/**
 * Verifies the F32 versioned audit compatibility contract on a disposable site.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Admin\Admin;
use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\Capabilities;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with one fixed, sanitized failure.
 *
 * @param string $stage Fixed stage name.
 */
function dreamax_lm_f32_fail( string $stage ): never {
	$stage = strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $stage ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Sanitized CLI failure only.
	fwrite( STDERR, 'FAIL: F32 verification failed at sanitized stage: ' . $stage . '.' . PHP_EOL );
	exit( 1 );
}

/**
 * Returns an opaque fixture suffix which is never printed.
 */
function dreamax_lm_f32_suffix(): string {
	return substr( hash( 'sha256', random_bytes( 32 ) ), 0, 18 );
}

/**
 * Takes authoritative aggregate counts.
 *
 * @return array{events:int,rate_limits:int}
 */
function dreamax_lm_f32_snapshot(): array {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup proof needs authoritative aggregate reads.
	return array(
		'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
		'rate_limits' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_rate_limits" ),
	);
	// phpcs:enable
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f32_fail( 'preflight' );
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
	dreamax_lm_f32_fail( 'preflight' );
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
	dreamax_lm_f32_fail( 'environment_guard' );
}
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f32_fail( 'plugin_state' );
}

global $wpdb;
$events_table = $wpdb->prefix . 'dreamax_lm_events';
$rate_table   = $wpdb->prefix . 'dreamax_lm_rate_limits';
foreach ( array( $events_table, $rate_table ) as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live rollback and cleanup require InnoDB.
	$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f32_fail( 'transactional_storage' );
	}
}

$administrator_ids = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ids',
	)
);
if ( ! is_array( $administrator_ids ) || 1 !== count( $administrator_ids ) || (int) $administrator_ids[0] < 1 ) {
	dreamax_lm_f32_fail( 'administrator_context' );
}

$before              = dreamax_lm_f32_snapshot();
$current_user_before = get_current_user_id();
$event_public_ids    = array();
$rate_hash           = random_bytes( 32 );
$failure_stage       = 'setup';
$failure             = null;
$cleanup_failure     = false;
$mail_attempts       = 0;
$checks              = array();
$repository          = new EventRepository();
$transaction         = new Transaction();
$private_value       = 'Bearer f32_' . dreamax_lm_f32_suffix();
$mail_guard          = static function ( $short_circuit ) use ( &$mail_attempts ) {
	unset( $short_circuit );
	++$mail_attempts;
	return true;
};
add_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
wp_set_current_user( (int) $administrator_ids[0] );

try {
	$failure_stage = 'marker_creation';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact verifier-owned transactional marker.
	$marker_inserted = $wpdb->insert(
		$rate_table,
		array(
			'bucket_hash'       => $rate_hash,
			'tokens'            => 1,
			'updated_microtime' => microtime( true ),
			'expires_at'        => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
		),
		array( '%s', '%f', '%f', '%s' )
	);
	if ( 1 !== $marker_inserted ) {
		throw new RuntimeException( 'Owned marker creation failed.' );
	}

	$failure_stage      = 'successful_write';
	$written_id         = $repository->append(
		AuditEventCatalog::PRIVACY_DATA_ANONYMIZED,
		null,
		'privacy_tool',
		null,
		null,
		array(
			'license_count' => 0,
			'claim_count'   => 0,
			'owner_count'   => 0,
			'password'      => $private_value,
		),
		AuditEventCatalog::SCHEMA_V1
	);
	$event_public_ids[] = $written_id;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Confirms the exact accepted envelope through the trusted current-blog table; the value remains prepared.
	$written_row = $wpdb->get_row( $wpdb->prepare( "SELECT event_type,schema_version,actor_type,metadata FROM {$events_table} WHERE public_id=%s", $written_id ), ARRAY_A );
	if ( ! is_array( $written_row ) || AuditEventCatalog::PRIVACY_DATA_ANONYMIZED !== $written_row['event_type'] || 1 !== (int) $written_row['schema_version'] || str_contains( (string) $written_row['metadata'], $private_value ) ) {
		throw new RuntimeException( 'The accepted write envelope was invalid.' );
	}
	$checks['successful_write_sanitized'] = true;

	$failure_stage = 'catalog_rejection_rollback';
	$rejected      = false;
	try {
		$transaction->run(
			static function () use ( $wpdb, $rate_table, $rate_hash, $repository ): void {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact marker mutation proves validation failure rollback.
				$wpdb->update( $rate_table, array( 'tokens' => 0 ), array( 'bucket_hash' => $rate_hash ), array( '%f' ), array( '%s' ) );
				$repository->append( 'f32_unknown_new_write', null, 'system', null, null, array(), AuditEventCatalog::SCHEMA_V1 );
			}
		);
	} catch ( InvalidArgumentException ) {
		$rejected = true;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads the exact rollback marker through the trusted current-blog table; the value remains prepared.
	$marker_tokens = (float) $wpdb->get_var( $wpdb->prepare( "SELECT tokens FROM {$rate_table} WHERE bucket_hash=%s", $rate_hash ) );
	if ( ! $rejected || 1.0 !== $marker_tokens ) {
		throw new RuntimeException( 'Catalog rejection did not roll back.' );
	}
	$checks['catalog_rejection_rolled_back'] = true;

	$failure_stage   = 'closed_write_contract';
	$invalid_writes  = array(
		array( AuditEventCatalog::LEGACY_LICENSE_IMPORTED, 'system', array() ),
		array(
			AuditEventCatalog::PRIVACY_DATA_ANONYMIZED,
			'administrator',
			array(
				'license_count' => 0,
				'claim_count'   => 0,
				'owner_count'   => 0,
			),
		),
		array(
			AuditEventCatalog::PRIVACY_DATA_ANONYMIZED,
			'privacy_tool',
			array(
				'license_count' => -1,
				'claim_count'   => 0,
				'owner_count'   => 0,
			),
		),
	);
	$rejection_count = 1;
	foreach ( $invalid_writes as $invalid ) {
		try {
			$repository->append( $invalid[0], null, $invalid[1], null, null, $invalid[2], AuditEventCatalog::SCHEMA_V1 );
		} catch ( InvalidArgumentException ) {
			++$rejection_count;
		}
	}
	if ( 4 !== $rejection_count ) {
		throw new RuntimeException( 'The closed write contract accepted an invalid event.' );
	}
	$checks['rejected_writes'] = $rejection_count;

	$failure_stage        = 'persistence_failure_rollback';
	$insert_filter        = static function ( string $query ) use ( $events_table ): string {
		if ( str_starts_with( ltrim( $query ), 'INSERT' ) && str_contains( $query, $events_table ) ) {
			return 'INSERT INTO dreamax_lm_f32_intentionally_missing (id) VALUES (1)';
		}
		return $query;
	};
	$previous_suppression = $wpdb->suppress_errors( true );
	add_filter( 'query', $insert_filter, PHP_INT_MAX );
	$persistence_failed = false;
	try {
		$transaction->run(
			static function () use ( $wpdb, $rate_table, $rate_hash, $repository ): void {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact marker mutation proves persistence failure rollback.
				$wpdb->update( $rate_table, array( 'tokens' => 0 ), array( 'bucket_hash' => $rate_hash ), array( '%f' ), array( '%s' ) );
				$repository->append(
					AuditEventCatalog::PRIVACY_DATA_ANONYMIZED,
					null,
					'privacy_tool',
					null,
					null,
					array(
						'license_count' => 0,
						'claim_count'   => 0,
						'owner_count'   => 0,
					),
					AuditEventCatalog::SCHEMA_V1
				);
			}
		);
	} catch ( RuntimeException ) {
		$persistence_failed = true;
	} finally {
		remove_filter( 'query', $insert_filter, PHP_INT_MAX );
		$wpdb->suppress_errors( $previous_suppression );
		$wpdb->last_error = '';
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads the exact rollback marker through the trusted current-blog table; the value remains prepared.
	$marker_tokens = (float) $wpdb->get_var( $wpdb->prepare( "SELECT tokens FROM {$rate_table} WHERE bucket_hash=%s", $rate_hash ) );
	if ( ! $persistence_failed || 1.0 !== $marker_tokens ) {
		throw new RuntimeException( 'Audit persistence failure did not roll back.' );
	}
	$checks['persistence_failure_rolled_back'] = true;

	$failure_stage     = 'historical_fixture_creation';
	$occurred_at       = gmdate( 'Y-m-d H:i:s' );
	$historical        = array(
		array( AuditEventCatalog::LEGACY_LICENSE_IMPORTED, 1, 'system', '{}', 'legacy_catalog_entry' ),
		array( AuditEventCatalog::PRIVACY_DATA_ANONYMIZED, 0, 'privacy_tool', '{}', 'legacy_unversioned' ),
		array(
			AuditEventCatalog::PRIVACY_DATA_ANONYMIZED,
			99,
			'privacy_tool',
			wp_json_encode(
				array(
					'password'   => $private_value,
					'safe_label' => 'unsupported historical row',
				)
			),
			'unsupported_version',
		),
		array(
			'f32_unknown_historical',
			1,
			'system',
			wp_json_encode(
				array(
					'nested' => array(
						'authorization' => $private_value,
						'safe_label'    => 'unknown historical row',
					),
				)
			),
			'unknown_type',
		),
		array( AuditEventCatalog::PRIVACY_DATA_ANONYMIZED, 1, 'privacy_tool', wp_json_encode( array( 'license_count' => -1 ) ), 'legacy_payload' ),
		array( AuditEventCatalog::PRIVACY_DATA_ANONYMIZED, 1, 'privacy_tool', '{"malformed":', 'legacy_payload' ),
	);
	$expected_statuses = array( 'supported' => 1 );
	foreach ( $historical as $fixture ) {
		$public_id = PublicId::generate( 'evt' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact synthetic historical compatibility fixture.
		$inserted = $wpdb->insert(
			$events_table,
			array(
				'public_id'      => $public_id,
				'license_id'     => null,
				'event_type'     => $fixture[0],
				'schema_version' => $fixture[1],
				'actor_type'     => $fixture[2],
				'actor_id'       => null,
				'request_id'     => null,
				'occurred_at'    => $occurred_at,
				'metadata'       => $fixture[3],
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'A historical fixture could not be created.' );
		}
		$event_public_ids[]               = $public_id;
		$expected_statuses[ $fixture[4] ] = ( $expected_statuses[ $fixture[4] ] ?? 0 ) + 1;
	}

	$placeholders = implode( ',', array_fill( 0, count( $event_public_ids ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Trusted current-blog table and bounded internal placeholder list; every value is prepared.
	$raw_before = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,license_id,event_type,schema_version,actor_type,actor_id,request_id,occurred_at,metadata FROM {$events_table} WHERE public_id IN ({$placeholders}) ORDER BY id", ...$event_public_ids ), ARRAY_A );
	if ( ! is_array( $raw_before ) || count( $event_public_ids ) !== count( $raw_before ) ) {
		throw new RuntimeException( 'The historical snapshot was incomplete.' );
	}

	$failure_stage = 'compatibility_read';
	$recent        = $repository->recent( 200 );
	$statuses      = array();
	$owned_reads   = array();
	foreach ( $recent as $row ) {
		if ( in_array( (string) $row['public_id'], $event_public_ids, true ) ) {
			$contract_status              = (string) ( $row['contract_status'] ?? '' );
			$statuses[ $contract_status ] = ( $statuses[ $contract_status ] ?? 0 ) + 1;
			$owned_reads[]                = $row;
		}
	}
	ksort( $statuses );
	ksort( $expected_statuses );
	$serialized_reads = wp_json_encode( $owned_reads );
	if ( $expected_statuses !== $statuses || ! is_string( $serialized_reads ) || str_contains( $serialized_reads, $private_value ) ) {
		throw new RuntimeException( 'Compatibility labels or read-side redaction differed.' );
	}
	$checks['compatibility_statuses'] = count( $statuses );
	$checks['read_side_redaction']    = true;

	$failure_stage = 'administrator_rendering';
	$render_error  = null;
	$html          = '';
	// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Callback context is passed to ErrorException but never output.
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Warnings are part of the bounded rendering acceptance contract.
	set_error_handler(
		static function ( int $severity, string $message, string $file, int $line ): never {
			throw new ErrorException( 'F32 rendering warning.', 0, $severity, $file, $line );
		},
		E_WARNING | E_NOTICE
	);
	// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
	ob_start();
	try {
		( new Admin() )->activity_page();
		$html = (string) ob_get_clean();
	} catch ( Throwable $error ) {
		$render_error = $error;
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	} finally {
		restore_error_handler();
	}
	if ( $render_error instanceof Throwable || '' === $html || str_contains( $html, $private_value ) ) {
		throw new RuntimeException( 'Administrator rendering failed or disclosed protected metadata.' );
	}
	foreach ( array( AuditEventCatalog::PRIVACY_DATA_ANONYMIZED, AuditEventCatalog::LEGACY_LICENSE_IMPORTED, 'f32_unknown_historical' ) as $rendered_type ) {
		if ( ! str_contains( $html, $rendered_type ) ) {
			throw new RuntimeException( 'A compatibility event did not render.' );
		}
	}
	$checks['administrator_rendering'] = true;

	$failure_stage = 'historical_nonmutation';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Exact comparison through the trusted current-blog table and bounded internal placeholder list; every value is prepared.
	$raw_after = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,license_id,event_type,schema_version,actor_type,actor_id,request_id,occurred_at,metadata FROM {$events_table} WHERE public_id IN ({$placeholders}) ORDER BY id", ...$event_public_ids ), ARRAY_A );
	if ( $raw_before !== $raw_after ) {
		throw new RuntimeException( 'Historical rows changed during compatibility reads.' );
	}
	$checks['historical_rows_byte_stable'] = true;
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	remove_filter( 'pre_wp_mail', $mail_guard, PHP_INT_MAX );
	wp_set_current_user( $current_user_before );
	$cleanup_ok = false !== $wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $cleanup_ok ) {
		foreach ( $event_public_ids as $public_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes one exact verifier-owned event.
			$cleanup_ok = $cleanup_ok && false !== $wpdb->delete( $events_table, array( 'public_id' => $public_id ), array( '%s' ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes one exact verifier-owned marker.
		$cleanup_ok = $cleanup_ok && false !== $wpdb->delete( $rate_table, array( 'bucket_hash' => $rate_hash ), array( '%s' ) );
	}
	if ( $cleanup_ok ) {
		$cleanup_ok = false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	} else {
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	$cleanup_failure = ! $cleanup_ok;
}

$after = dreamax_lm_f32_snapshot();
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Final exact owned-residue proof.
$owned_event_rows = 0;
foreach ( $event_public_ids as $public_id ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-blog table name; the value remains prepared.
	$owned_event_rows += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$events_table} WHERE public_id=%s", $public_id ) );
}
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-blog table name; the value remains prepared.
$owned_rate_rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$rate_table} WHERE bucket_hash=%s", $rate_hash ) );
// phpcs:enable

if ( $failure instanceof Throwable ) {
	dreamax_lm_f32_fail( $failure_stage );
}
if ( $cleanup_failure || $before !== $after || 0 !== $owned_event_rows + $owned_rate_rows ) {
	dreamax_lm_f32_fail( 'cleanup' );
}
if ( 0 !== $mail_attempts ) {
	dreamax_lm_f32_fail( 'mail_isolation' );
}

$result = array(
	'gate'                          => 'F32',
	'status'                        => 'PASS',
	'successful_writes'             => 1,
	'rejected_writes'               => $checks['rejected_writes'],
	'compatibility_statuses'        => $checks['compatibility_statuses'],
	'historical_rows_verified'      => count( $event_public_ids ) - 1,
	'catalog_rejection_rollback'    => true,
	'persistence_failure_rollback'  => true,
	'read_side_redaction'           => true,
	'administrator_rendering'       => true,
	'historical_rows_byte_stable'   => true,
	'database_aggregates_unchanged' => true,
	'owned_fixture_rows_remaining'  => 0,
	'outbound_email_sent'           => false,
	'sensitive_output'              => false,
);
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed sanitized CLI evidence only.
echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
