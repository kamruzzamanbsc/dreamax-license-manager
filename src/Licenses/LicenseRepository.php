<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use Dreamax\LicenseManager\Encryption\Crypto;
use RuntimeException;
use Throwable;

final class LicenseRepository {
	private KeyNormalizer $normalizer;
	private Crypto $crypto;

	public function __construct( ?KeyNormalizer $normalizer = null, ?Crypto $crypto = null ) {
		$this->normalizer = $normalizer ?? new KeyNormalizer();
		$this->crypto     = $crypto ?? new Crypto();
	}

	/** @param array<string,mixed> $record */
	public function insert( array $record ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'dreamax_lm_licenses';
		$inserted = $wpdb->insert(
			$table,
			array(
				'public_id'               => $record['public_id'],
				'key_ciphertext'          => $record['key_ciphertext'],
				'key_fingerprint'         => $record['key_fingerprint'],
				'normalization_profile'   => $record['normalization_profile'],
				'normalization_separator' => $record['normalization_separator'],
				'encryption_key_version'  => 1,
				'lifecycle_status'        => $record['lifecycle_status'],
				'product_public_id'       => $record['product_public_id'],
				'product_id'              => $record['product_id'],
				'variation_id'            => $record['variation_id'],
				'order_id'                => $record['order_id'],
				'order_item_id'           => $record['order_item_id'],
				'quantity_slot'           => $record['quantity_slot'],
				'customer_id'             => $record['customer_id'],
				'generator_id'            => $record['generator_id'],
				'activation_limit'        => $record['activation_limit'],
				'valid_for_seconds'       => $record['valid_for_seconds'],
				'expires_at'              => $record['expires_at'],
				'created_at'              => $record['created_at'],
				'updated_at'              => $record['updated_at'],
				'metadata'                => wp_json_encode( $record['metadata'] ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			throw new RuntimeException( 'The license could not be stored.' );
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed>|null */
	public function find_presented( string $presented, string $product_public_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'dreamax_lm_licenses';
		$configurations = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT normalization_profile, normalization_separator FROM {$table} WHERE product_public_id = %s LIMIT 32",
				$product_public_id
			),
			ARRAY_A
		);

		if ( ! is_array( $configurations ) || array() === $configurations ) {
			$this->crypto->fingerprint( $presented );
			return null;
		}

		foreach ( $configurations as $configuration ) {
			try {
				$canonical   = $this->normalizer->normalize(
					$presented,
					(string) $configuration['normalization_profile'],
					null === $configuration['normalization_separator'] ? null : (string) $configuration['normalization_separator']
				);
				$fingerprint = bin2hex( $this->crypto->fingerprint( $canonical ) );
				$row = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE key_fingerprint = UNHEX(%s) AND product_public_id = %s LIMIT 1",
						$fingerprint,
						$product_public_id
					),
					ARRAY_A
				);
				if ( is_array( $row ) ) {
					return $row;
				}
			} catch ( Throwable $error ) {
				continue;
			}
		}
		return null;
	}

	/** @return array<string,mixed>|null */
	public function lock_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE id = %d FOR UPDATE",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function by_public_id( string $public_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id = %s LIMIT 1",
				$public_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public function by_order_slot( int $order_item_id, int $quantity_slot ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_item_id = %d AND quantity_slot = %d LIMIT 1",
				$order_item_id,
				$quantity_slot
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** @return list<array<string,mixed>> */
	public function for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_id = %d ORDER BY order_item_id, quantity_slot",
				$order_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function for_order_item( int $order_item_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE order_item_id = %d ORDER BY quantity_slot, id",
				$order_item_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** @return list<array<string,mixed>> */
	public function for_customer( int $customer_id, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses WHERE customer_id = %d ORDER BY created_at DESC LIMIT %d",
				$customer_id,
				min( 100, max( 1, $limit ) )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public function decrypt_key( array $license ): string {
		return $this->crypto->decrypt( (string) $license['key_ciphertext'] );
	}
}
