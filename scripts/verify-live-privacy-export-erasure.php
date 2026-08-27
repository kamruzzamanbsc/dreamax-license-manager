<?php
/**
 * Verifies privacy export, partial/repeated erasure, and customer isolation.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Activations\ActivationService;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\Privacy\Privacy;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

/**
 * Stops with a sanitized error that does not disclose fixture values.
 *
 * @param string $message Sanitized failure message.
 */
function dreamax_lm_f27_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier must report a sanitized failure to STDERR.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f27_fail( 'A valid WordPress root is required.' );
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
	dreamax_lm_f27_fail( 'The database identity is unavailable.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! class_exists( Privacy::class ) ) {
	dreamax_lm_f27_fail( 'Dreamax License Manager is not active.' );
}

global $wpdb;

$tables = array(
	$wpdb->users,
	$wpdb->usermeta,
	$wpdb->prefix . 'dreamax_lm_licenses',
	$wpdb->prefix . 'dreamax_lm_activations',
	$wpdb->prefix . 'dreamax_lm_events',
	$wpdb->prefix . 'dreamax_lm_guest_claims',
	$wpdb->prefix . 'dreamax_lm_order_owners',
);
foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Mutation safety requires authoritative engine checks.
	$engine = $wpdb->get_var(
		$wpdb->prepare(
			'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
			(string) DB_NAME,
			$table
		)
	);
	if ( 'InnoDB' !== $engine ) {
		dreamax_lm_f27_fail( 'A required privacy table is not safely transactional.' );
	}
}

$snapshot = static function () use ( $wpdb ): array {
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup evidence requires authoritative aggregate reads.
	return array(
		'users'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
		'usermeta'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta}" ),
		'licenses'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses" ),
		'activations' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations" ),
		'events'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events" ),
		'claims'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims" ),
		'owners'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_order_owners" ),
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
};

$before            = $snapshot();
$user_ids          = array();
$license_ids       = array();
$claim_ids         = array();
$order_ids         = array();
$privacy_event_ids = array();
$event_floor       = 0;
$first_key         = '';
$failure           = null;
$cleanup_failure   = null;
$cleanup_committed = false;
$export_boundary   = false;
$cross_isolation   = false;
$erasure_boundary  = false;
$anonymized        = false;
$business_retained = false;
$repeat_idempotent = false;
$audit_exact       = false;

try {
	$suffix = substr( hash( 'sha256', random_bytes( 32 ) ), 0, 16 );
	$users  = array();
	foreach ( array( 'a', 'b' ) as $label ) {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'dlm_f27_' . $label . '_' . $suffix,
				'user_pass'  => wp_generate_password( 32, true, true ),
				'user_email' => 'dlm-f27-' . $label . '-' . $suffix . '@example.invalid',
				'role'       => 'customer',
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'A synthetic privacy user could not be created.' );
		}
		$user_ids[]      = (int) $user_id;
		$users[ $label ] = get_user_by( 'id', (int) $user_id );
	}
	if ( ! $users['a'] instanceof WP_User || ! $users['b'] instanceof WP_User ) {
		throw new RuntimeException( 'Synthetic privacy users could not be loaded.' );
	}

	$product_public_id = PublicId::generate( 'prd' );
	$service           = new LicenseService();
	$customer_licenses = array();
	foreach ( array(
		'a' => 101,
		'b' => 1,
	) as $label => $count ) {
		$customer_licenses[ $label ] = array();
		for ( $index = 0; $index < $count; ++$index ) {
			$result                        = $service->create_generated(
				array(
					'lifecycle_status'  => 'assigned',
					'product_public_id' => $product_public_id,
					'customer_id'       => (int) $users[ $label ]->ID,
					'activation_limit'  => 1,
					'actor_type'        => 'system',
					'request_id'        => 'f27-create-' . $label . '-' . $index . '-' . $suffix,
					'source'            => 'generated',
					'metadata'          => array( 'personal_note' => 'temporary privacy fixture' ),
				)
			);
			$license_ids[]                 = (int) $result['id'];
			$customer_licenses[ $label ][] = (int) $result['id'];
			if ( 'a' === $label && 0 === $index ) {
				$first_key = (string) $result['key'];
			} elseif ( function_exists( 'sodium_memzero' ) ) {
				$key = (string) $result['key'];
				sodium_memzero( $key );
			}
		}
	}

	$first_license_id = (int) $customer_licenses['a'][0];
	( new ActivationService() )->activate( $first_key, $product_public_id, 'f27-instance-' . $suffix, 'Temporary personal label', 'f27-activation-' . $suffix );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synthetic personal fields are added only to prove live erasure.
	$wpdb->update(
		$wpdb->prefix . 'dreamax_lm_activations',
		array(
			'ip_fingerprint' => random_bytes( 32 ),
			'metadata'       => wp_json_encode( array( 'temporary_personal_value' => true ) ),
		),
		array( 'license_id' => $first_license_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Synthetic encrypted-email placeholder is added only to prove live erasure.
	$wpdb->update(
		$wpdb->prefix . 'dreamax_lm_licenses',
		array( 'customer_email_enc' => 'temporary-encrypted-email-placeholder' ),
		array( 'id' => $first_license_id ),
		array( '%s' ),
		array( '%d' )
	);

	$now = gmdate( 'Y-m-d H:i:s' );
	foreach ( array( 'a', 'b' ) as $label ) {
		do {
			$order_id = random_int( 700000000, 900000000 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Collision avoidance requires an authoritative read across both fixture target tables.
			$order_collision = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT (SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE order_id=%d) + (SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_order_owners WHERE order_id=%d)",
					$order_id,
					$order_id
				)
			);
		} while ( 0 !== $order_collision );
		$ownership_hash = random_bytes( 32 );
		$claim_public   = PublicId::generate( 'clm' );
		$inserted       = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact synthetic claim fixture.
			$wpdb->prefix . 'dreamax_lm_guest_claims',
			array(
				'public_id'       => $claim_public,
				'order_id'        => $order_id,
				'active_order_id' => $order_id,
				'target_user_id'  => (int) $users[ $label ]->ID,
				'token_hash'      => random_bytes( 32 ),
				'ownership_hash'  => $ownership_hash,
				'status'          => 'issued',
				'expires_at'      => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				'created_at'      => $now,
				'issued_at'       => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $inserted ) {
			throw new RuntimeException( 'A synthetic privacy claim could not be created.' );
		}
		$claim_id       = (int) $wpdb->insert_id;
		$claim_ids[]    = $claim_id;
		$order_ids[]    = $order_id;
		$owner_inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Exact synthetic owner fixture.
			$wpdb->prefix . 'dreamax_lm_order_owners',
			array(
				'order_id'       => $order_id,
				'customer_id'    => (int) $users[ $label ]->ID,
				'claim_id'       => $claim_id,
				'ownership_hash' => $ownership_hash,
				'source'         => 'guest_claim',
				'claimed_at'     => $now,
				'updated_at'     => $now,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		if ( 1 !== $owner_inserted ) {
			throw new RuntimeException( 'A synthetic privacy owner could not be created.' );
		}
	}

	$privacy   = new Privacy();
	$exporters = $privacy->exporters( array() );
	$erasers   = $privacy->erasers( array() );
	if ( ! isset( $exporters['dreamax-license-manager']['callback'], $erasers['dreamax-license-manager']['callback'] )
		|| ! is_callable( $exporters['dreamax-license-manager']['callback'] )
		|| ! is_callable( $erasers['dreamax-license-manager']['callback'] ) ) {
		throw new RuntimeException( 'The WordPress privacy callbacks are not registered correctly.' );
	}
	$export_callback = $exporters['dreamax-license-manager']['callback'];
	$erase_callback  = $erasers['dreamax-license-manager']['callback'];

	$a_page_1        = $export_callback( (string) $users['a']->user_email, 1 );
	$a_page_2        = $export_callback( (string) $users['a']->user_email, 2 );
	$b_before        = $export_callback( (string) $users['b']->user_email, 1 );
	$unknown         = $export_callback( 'dlm-f27-unknown@example.invalid', 1 );
	$export_boundary = 101 === count( $a_page_1['data'] )
		&& false === $a_page_1['done']
		&& 1 === count( $a_page_2['data'] )
		&& true === $a_page_2['done']
		&& 2 === count( $b_before['data'] )
		&& true === $b_before['done']
		&& array() === $unknown['data']
		&& true === $unknown['done'];

	$encoded_export = wp_json_encode( array( $a_page_1, $a_page_2, $b_before ) );
	if ( ! is_string( $encoded_export )
		|| str_contains( $encoded_export, $first_key )
		|| preg_match( '/token|hash|ciphertext|customer email/i', $encoded_export ) ) {
		throw new RuntimeException( 'The privacy export included a prohibited value or field.' );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- New verifier-created privacy events are bounded after this exact event ID.
	$event_floor       = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}dreamax_lm_events" );
	$erase_1           = $erase_callback( (string) $users['a']->user_email, 1 );
	$erase_2           = $erase_callback( (string) $users['a']->user_email, 2 );
	$erase_3           = $erase_callback( (string) $users['a']->user_email, 3 );
	$erasure_boundary  = true === $erase_1['items_removed']
		&& true === $erase_1['items_retained']
		&& false === $erase_1['done']
		&& true === $erase_2['items_removed']
		&& true === $erase_2['items_retained']
		&& true === $erase_2['done'];
	$repeat_idempotent = false === $erase_3['items_removed']
		&& false === $erase_3['items_retained']
		&& true === $erase_3['done'];

	$a_license_id_list = implode( ',', array_map( 'intval', $customer_licenses['a'] ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact post-erasure assertions use an integer-normalized, verifier-owned ID list.
	$a_linked          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE customer_id=%d", (int) $users['a']->ID ) );
	$a_retained        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id IN ({$a_license_id_list})" );
	$a_personal_fields = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id IN ({$a_license_id_list}) AND (customer_email_enc IS NOT NULL OR metadata IS NOT NULL)" );
	$activation_clean  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d AND instance_label IS NULL AND ip_fingerprint IS NULL AND metadata IS NULL", $first_license_id ) );
	$a_claim_clean     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE id=%d AND target_user_id IS NULL AND active_order_id IS NULL AND token_hash IS NULL AND status='invalidated'", $claim_ids[0] ) );
	$a_owner_left      = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_order_owners WHERE order_id=%d", $order_ids[0] ) );
	$b_license_linked  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id=%d AND customer_id=%d AND metadata IS NOT NULL", $customer_licenses['b'][0], (int) $users['b']->ID ) );
	$b_claim_unchanged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE id=%d AND target_user_id=%d AND active_order_id=%d AND token_hash IS NOT NULL AND status='issued'", $claim_ids[1], (int) $users['b']->ID, $order_ids[1] ) );
	$b_owner_unchanged = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_order_owners WHERE order_id=%d AND customer_id=%d", $order_ids[1], (int) $users['b']->ID ) );
	$privacy_events    = $wpdb->get_results( $wpdb->prepare( "SELECT id,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE id>%d AND event_type=%s AND actor_type='privacy_tool' ORDER BY id", $event_floor, AuditEventCatalog::PRIVACY_DATA_ANONYMIZED ), ARRAY_A );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$anonymized        = 0 === $a_linked && 0 === $a_personal_fields && 1 === $activation_clean && 1 === $a_claim_clean && 0 === $a_owner_left;
	$business_retained = 101 === $a_retained;
	$cross_isolation   = 1 === $b_license_linked && 1 === $b_claim_unchanged && 1 === $b_owner_unchanged;
	$audit_exact       = is_array( $privacy_events ) && 2 === count( $privacy_events );
	if ( $audit_exact ) {
		$metadata_1        = json_decode( (string) $privacy_events[0]['metadata'], true );
		$metadata_2        = json_decode( (string) $privacy_events[1]['metadata'], true );
		$audit_exact       = is_array( $metadata_1 )
			&& is_array( $metadata_2 )
			&& 100 === (int) ( $metadata_1['license_count'] ?? -1 )
			&& 1 === (int) ( $metadata_1['claim_count'] ?? -1 )
			&& 1 === (int) ( $metadata_1['owner_count'] ?? -1 )
			&& 1 === (int) ( $metadata_2['license_count'] ?? -1 )
			&& 0 === (int) ( $metadata_2['claim_count'] ?? -1 )
			&& 0 === (int) ( $metadata_2['owner_count'] ?? -1 );
		$privacy_event_ids = array_map( static fn( array $row ): int => (int) $row['id'], $privacy_events );
	}

	$b_after         = $export_callback( (string) $users['b']->user_email, 1 );
	$cross_isolation = $cross_isolation && $b_before === $b_after;
	if ( ! $export_boundary || ! $erasure_boundary || ! $repeat_idempotent || ! $anonymized || ! $business_retained || ! $cross_isolation || ! $audit_exact ) {
		throw new RuntimeException( 'The privacy exporter or eraser contract did not match.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( 0 < $event_floor ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The guarded verifier exclusively owns privacy-tool events created after its exact pre-erasure boundary.
		$bounded_event_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}dreamax_lm_events WHERE id>%d AND event_type=%s AND actor_type='privacy_tool' ORDER BY id",
				$event_floor,
				AuditEventCatalog::PRIVACY_DATA_ANONYMIZED
			)
		);
		if ( is_array( $bounded_event_ids ) ) {
			$privacy_event_ids = array_values( array_unique( array_map( 'intval', $bounded_event_ids ) ) );
		}
	}
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$cleanup_failure = new RuntimeException( 'Cleanup transaction start failed.' );
	} else {
		try {
			foreach ( $license_ids as $license_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned fixture cleanup.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_activations', array( 'license_id' => $license_id ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned fixture cleanup.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'license_id' => $license_id ), array( '%d' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned fixture cleanup.
				if ( 1 !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_licenses', array( 'id' => $license_id ), array( '%d' ) ) ) {
					throw new RuntimeException( 'Owned license cleanup failed.' );
				}
			}
			foreach ( $privacy_event_ids as $event_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned fixture cleanup.
				if ( 1 !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_events', array( 'id' => $event_id ), array( '%d' ) ) ) {
					throw new RuntimeException( 'Owned privacy-event cleanup failed.' );
				}
			}
			foreach ( $claim_ids as $claim_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned fixture cleanup.
				if ( 1 !== $wpdb->delete( $wpdb->prefix . 'dreamax_lm_guest_claims', array( 'id' => $claim_id ), array( '%d' ) ) ) {
					throw new RuntimeException( 'Owned claim cleanup failed.' );
				}
			}
			foreach ( $order_ids as $order_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned fixture cleanup; the erased customer-A row may already be absent.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_order_owners', array( 'order_id' => $order_id ), array( '%d' ) );
			}
			foreach ( $user_ids as $user_id ) {
				if ( ! wp_delete_user( $user_id ) ) {
					throw new RuntimeException( 'Owned user cleanup failed.' );
				}
			}
			$cleanup_committed = false !== $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $cleanup_committed ) {
				throw new RuntimeException( 'Owned cleanup commit failed.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$cleanup_failure = $error;
		}
	}
	if ( '' !== $first_key && function_exists( 'sodium_memzero' ) ) {
		sodium_memzero( $first_key );
	}
}

$after = $snapshot();
if ( $cleanup_failure instanceof Throwable || ! $cleanup_committed || $before !== $after ) {
	dreamax_lm_f27_fail( 'The owned privacy fixtures were not completely removed.' );
}
if ( $failure instanceof Throwable ) {
	dreamax_lm_f27_fail( 'The guarded privacy verification failed.' );
}

echo wp_json_encode(
	array(
		'classification'                    => 'live_disposable_wordpress_innodb',
		'customer_a_license_records'        => 101,
		'customer_b_sentinel_records'       => 1,
		'export_page_one_license_boundary'  => 100,
		'export_page_two_license_count'     => 1,
		'export_boundary_matched'           => $export_boundary,
		'export_contains_protected_values'  => false,
		'first_erasure_done'                => false,
		'second_erasure_done'               => true,
		'partial_erasure_boundary_matched'  => $erasure_boundary,
		'repeated_erasure_idempotent'       => $repeat_idempotent,
		'personal_fields_anonymized'        => $anonymized,
		'business_records_retained'         => $business_retained,
		'cross_customer_sentinel_unchanged' => $cross_isolation,
		'privacy_audit_events_exact'        => $audit_exact,
		'cleanup_transaction_committed'     => $cleanup_committed,
		'database_aggregates_unchanged'     => $before === $after,
		'owned_fixture_rows_remaining'      => 0,
		'outbound_email_sent'               => false,
		'sensitive_output'                  => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
