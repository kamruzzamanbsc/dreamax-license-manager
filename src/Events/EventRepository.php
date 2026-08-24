<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Events;

use Dreamax\LicenseManager\Support\PublicId;
use RuntimeException;

final class EventRepository {
	/** @param array<string,mixed> $metadata */
	public function append(
		string $event_type,
		?int $license_id,
		string $actor_type,
		?int $actor_id,
		?string $request_id,
		array $metadata = array()
	): string {
		global $wpdb;
		$public_id = PublicId::generate( 'evt' );
		$inserted  = $wpdb->insert(
			$wpdb->prefix . 'dreamax_lm_events',
			array(
				'public_id'      => $public_id,
				'license_id'    => $license_id,
				'event_type'    => $event_type,
				'schema_version'=> 1,
				'actor_type'    => $actor_type,
				'actor_id'      => $actor_id,
				'request_id'    => $request_id,
				'occurred_at'   => gmdate( 'Y-m-d H:i:s' ),
				'metadata'      => wp_json_encode( $this->sanitize_metadata( $metadata ) ),
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'Required audit evidence could not be stored.' );
		}
		return $public_id;
	}

	/** @return list<array<string,mixed>> */
	public function for_license( int $license_id, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT public_id,event_type,schema_version,actor_type,actor_id,request_id,occurred_at,metadata FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d ORDER BY id DESC LIMIT %d",
				$license_id,
				min( 100, max( 1, $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function recent( int $limit = 100 ): array {
		global $wpdb;
		$events  = $wpdb->prefix . 'dreamax_lm_events';
		$licenses = $wpdb->prefix . 'dreamax_lm_licenses';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.public_id,e.event_type,e.actor_type,e.actor_id,e.occurred_at,e.metadata,l.public_id AS license_public_id FROM {$events} e LEFT JOIN {$licenses} l ON l.id=e.license_id ORDER BY e.id DESC LIMIT %d",
				min( 200, max( 1, $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string,mixed> $metadata @return array<string,mixed> */
	private function sanitize_metadata( array $metadata ): array {
		$blocked = array( 'license_key', 'key', 'secret', 'authorization', 'token', 'raw_ip', 'password' );
		foreach ( $blocked as $field ) {
			unset( $metadata[ $field ] );
		}
		return $metadata;
	}
}
