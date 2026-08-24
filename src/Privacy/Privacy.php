<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Privacy;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\EventRepository;

final class Privacy {
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/** @param array<string,mixed> $exporters @return array<string,mixed> */
	public function exporters( array $exporters ): array {
		$exporters['dreamax-license-manager'] = array( 'exporter_friendly_name' => __( 'Dreamax License Manager', 'dreamax-license-manager' ), 'callback' => array( $this, 'export' ) );
		return $exporters;
	}

	/** @param array<string,mixed> $erasers @return array<string,mixed> */
	public function erasers( array $erasers ): array {
		$erasers['dreamax-license-manager'] = array( 'eraser_friendly_name' => __( 'Dreamax License Manager', 'dreamax-license-manager' ), 'callback' => array( $this, 'erase' ) );
		return $erasers;
	}

	/** @return array{data:list<array<string,mixed>>,done:bool} */
	public function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'data' => array(), 'done' => true );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,product_public_id,lifecycle_status,expires_at,created_at FROM {$wpdb->prefix}dreamax_lm_licenses WHERE customer_id=%d ORDER BY id LIMIT 100 OFFSET %d", (int) $user->ID, max( 0, ( $page - 1 ) * 100 ) ), ARRAY_A );
		$data = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$data[] = array( 'group_id' => 'dreamax-licenses', 'group_label' => __( 'Software licenses', 'dreamax-license-manager' ), 'item_id' => (string) $row['public_id'], 'data' => array( array( 'name' => __( 'License public ID', 'dreamax-license-manager' ), 'value' => $row['public_id'] ), array( 'name' => __( 'Product public ID', 'dreamax-license-manager' ), 'value' => $row['product_public_id'] ), array( 'name' => __( 'Status', 'dreamax-license-manager' ), 'value' => $row['lifecycle_status'] ), array( 'name' => __( 'Expiry', 'dreamax-license-manager' ), 'value' => $row['expires_at'] ), array( 'name' => __( 'Created', 'dreamax-license-manager' ), 'value' => $row['created_at'] ) ) );
		}
		return array( 'data' => $data, 'done' => count( $data ) < 100 );
	}

	/** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}dreamax_lm_licenses WHERE customer_id=%d LIMIT 100", (int) $user->ID ) );
		if ( ! $ids ) {
			return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
		}
		( new Transaction() )->run(
			function () use ( $wpdb, $ids ): void {
				$id_list = implode( ',', array_map( 'intval', $ids ) );
				$wpdb->query( "UPDATE {$wpdb->prefix}dreamax_lm_activations SET instance_label=NULL,ip_fingerprint=NULL,metadata=NULL WHERE license_id IN ({$id_list})" );
				$wpdb->query( "UPDATE {$wpdb->prefix}dreamax_lm_licenses SET customer_id=NULL,customer_email_enc=NULL,metadata=NULL WHERE id IN ({$id_list})" );
				( new EventRepository() )->append( 'privacy_data_anonymized', null, 'privacy_tool', null, null, array( 'license_count' => count( $ids ) ) );
			}
		);
		return array( 'items_removed' => true, 'items_retained' => true, 'messages' => array( __( 'Personal labels and direct customer links were anonymized. Minimal license, order, lifecycle, and security records were retained for integrity.', 'dreamax-license-manager' ) ), 'done' => count( $ids ) < 100 );
	}
}
