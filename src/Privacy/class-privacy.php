<?php
/**
 * Defines the Privacy class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Privacy;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;

/**
 * Handles Privacy operations.
 */
final class Privacy {
	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/**
	 * Handles the exporters operation.
	 *
	 * @param array $exporters Exporters value.
	 * @phpstan-param array<string,array{exporter_friendly_name:string,callback:callable(string,int):array{data:list<array<string,mixed>>,done:bool}}> $exporters Exporters value.
	 * @return non-empty-array<string,array{exporter_friendly_name:string,callback:callable(string,int):array{data:list<array<string,mixed>>,done:bool}}>
	 */
	public function exporters( array $exporters ): array {
		$exporters['dreamax-license-manager'] = array(
			'exporter_friendly_name' => __( 'Dreamax License Manager', 'dreamax-license-manager' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Handles the erasers operation.
	 *
	 * @param array $erasers Erasers value.
	 * @phpstan-param array<string,array{eraser_friendly_name:string,callback:callable(string,int):array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}}> $erasers Erasers value.
	 * @return non-empty-array<string,array{eraser_friendly_name:string,callback:callable(string,int):array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}}>
	 */
	public function erasers( array $erasers ): array {
		$erasers['dreamax-license-manager'] = array(
			'eraser_friendly_name' => __( 'Dreamax License Manager', 'dreamax-license-manager' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Handles the export operation.
	 *
	 * @param string $email Email value.
	 * @param int    $page Page value.
	 * @return array{data:list<array<string,mixed>>,done:bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,product_public_id,lifecycle_status,expires_at,created_at FROM {$wpdb->prefix}dreamax_lm_licenses WHERE customer_id=%d ORDER BY id LIMIT 100 OFFSET %d", (int) $user->ID, max( 0, ( $page - 1 ) * 100 ) ), ARRAY_A );
		$data = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$data[] = array(
				'group_id'    => 'dreamax-licenses',
				'group_label' => __( 'Software licenses', 'dreamax-license-manager' ),
				'item_id'     => (string) $row['public_id'],
				'data'        => array(
					array(
						'name'  => __( 'License public ID', 'dreamax-license-manager' ),
						'value' => $row['public_id'],
					),
					array(
						'name'  => __( 'Product public ID', 'dreamax-license-manager' ),
						'value' => $row['product_public_id'],
					),
					array(
						'name'  => __( 'Status', 'dreamax-license-manager' ),
						'value' => $row['lifecycle_status'],
					),
					array(
						'name'  => __( 'Expiry', 'dreamax-license-manager' ),
						'value' => $row['expires_at'],
					),
					array(
						'name'  => __( 'Created', 'dreamax-license-manager' ),
						'value' => $row['created_at'],
					),
				),
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy export reads the plugin-owned claim table directly and never returns hashes or proof material.
		$claims = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,order_id,status,created_at,consumed_at,invalidated_at FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE target_user_id=%d ORDER BY id LIMIT 100 OFFSET %d", (int) $user->ID, max( 0, ( $page - 1 ) * 100 ) ), ARRAY_A );
		foreach ( is_array( $claims ) ? $claims : array() as $claim ) {
			$data[] = array(
				'group_id'    => 'dreamax-guest-claims',
				'group_label' => __( 'Guest order license claims', 'dreamax-license-manager' ),
				'item_id'     => (string) $claim['public_id'],
				'data'        => array(
					array(
						'name'  => __( 'Order ID', 'dreamax-license-manager' ),
						'value' => $claim['order_id'],
					),
					array(
						'name'  => __( 'Claim status', 'dreamax-license-manager' ),
						'value' => $claim['status'],
					),
					array(
						'name'  => __( 'Created', 'dreamax-license-manager' ),
						'value' => $claim['created_at'],
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => count( is_array( $rows ) ? $rows : array() ) < 100 && count( is_array( $claims ) ? $claims : array() ) < 100,
		);
	}

	/**
	 * Handles the erase operation.
	 *
	 * @param string $email Email value.
	 * @param int    $page Page value.
	 * @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE customer_id=%d LIMIT 100", (int) $user->ID ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts determine whether any plugin-owned claim identity also needs erasure.
		$claim_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_guest_claims WHERE target_user_id=%d", (int) $user->ID ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Counts determine whether any plugin-owned claimed owner also needs erasure.
		$owner_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_order_owners WHERE customer_id=%d", (int) $user->ID ) );
		if ( ! $ids && 0 === $claim_count && 0 === $owner_count ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}
		( new Transaction() )->run(
			function () use ( $wpdb, $ids, $user, $claim_count, $owner_count ): void {
				if ( $ids ) {
					$id_list = implode( ',', array_map( 'intval', $ids ) );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Every list value is normalized with intval(); privacy writes must be direct and fresh.
					$wpdb->query( "UPDATE {$wpdb->prefix}dreamax_lm_activations SET instance_label=NULL,ip_fingerprint=NULL,metadata=NULL WHERE license_id IN ({$id_list})" );
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Every list value is normalized with intval(); privacy writes must be direct and fresh.
					$wpdb->query( "UPDATE {$wpdb->prefix}dreamax_lm_licenses SET customer_id=NULL,customer_email_enc=NULL,metadata=NULL WHERE id IN ({$id_list})" );
				}
				$now = gmdate( 'Y-m-d H:i:s' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Privacy erasure invalidates proof material and removes its direct user link.
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}dreamax_lm_guest_claims SET target_user_id=NULL,invalidated_at=IF(status IN ('pending','issued'),%s,invalidated_at),status=IF(status IN ('pending','issued'),'invalidated',status),active_order_id=NULL,token_hash=NULL,updated_at=%s WHERE target_user_id=%d", $now, $now, (int) $user->ID ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Removing the direct claimed-owner row permits a later authoritative reclaim after privacy erasure.
				$wpdb->delete( $wpdb->prefix . 'dreamax_lm_order_owners', array( 'customer_id' => (int) $user->ID ), array( '%d' ) );
				( new EventRepository() )->append(
					AuditEventCatalog::PRIVACY_DATA_ANONYMIZED,
					null,
					'privacy_tool',
					null,
					null,
					array(
						'license_count' => count( $ids ),
						'claim_count'   => $claim_count,
						'owner_count'   => $owner_count,
					),
					AuditEventCatalog::SCHEMA_V1
				);
			}
		);
		return array(
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => array( __( 'Personal labels and direct customer links were anonymized. Minimal license, order, lifecycle, and security records were retained for integrity.', 'dreamax-license-manager' ) ),
			'done'           => count( $ids ) < 100,
		);
	}
}
