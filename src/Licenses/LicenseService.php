<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Generators\KeyGenerator;
use Dreamax\LicenseManager\Support\PublicId;
use InvalidArgumentException;
use RuntimeException;

final class LicenseService {
	private LicenseRepository $licenses;
	private KeyNormalizer $normalizer;
	private Crypto $crypto;
	private EventRepository $events;
	private KeyGenerator $generator;
	private Transaction $transaction;

	public function __construct() {
		$this->normalizer = new KeyNormalizer();
		$this->crypto     = new Crypto();
		$this->licenses   = new LicenseRepository( $this->normalizer, $this->crypto );
		$this->events     = new EventRepository();
		$this->generator  = new KeyGenerator();
		$this->transaction = new Transaction();
	}

	/** @param array<string,mixed> $attributes @return array{id:int,public_id:string,key:string} */
	public function create_generated( array $attributes ): array {
		if ( ! $this->crypto->ready() ) {
			throw new RuntimeException( 'Encryption is not ready. No license was created.' );
		}

		$config    = is_array( $attributes['generator'] ?? null ) ? $attributes['generator'] : array();
		$separator = (string) ( $config['separator'] ?? '-' );
		$key       = $this->generator->generate( $config );
		return $this->create( $key, KeyNormalizer::GENERATED, $separator, $attributes );
	}

	/** @param array<string,mixed> $attributes @return array{id:int,public_id:string,key:string} */
	public function import( string $key, string $profile, ?string $separator, array $attributes ): array {
		if ( ! $this->crypto->ready() ) {
			throw new RuntimeException( 'Encryption is not ready. No license was imported.' );
		}
		return $this->create( $key, $profile, $separator, $attributes );
	}

	/** @param array<string,mixed> $attributes @return array{id:int,public_id:string,key:string} */
	public function assign_pool( string $product_public_id, array $attributes ): array {
		if ( ! $this->crypto->ready() ) {
			throw new RuntimeException( 'Encryption is not ready. No pool license was assigned.' );
		}

		return $this->transaction->run(
			function () use ( $product_public_id, $attributes ): array {
				global $wpdb;
				$table = $wpdb->prefix . 'dreamax_lm_licenses';
				$license = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT * FROM {$table} WHERE product_public_id = %s AND lifecycle_status = 'available' AND order_id IS NULL ORDER BY id LIMIT 1 FOR UPDATE",
						$product_public_id
					),
					ARRAY_A
				);
				if ( ! is_array( $license ) ) {
					throw new RuntimeException( 'The imported key pool is empty.' );
				}

				$metadata = json_decode( (string) ( $license['metadata'] ?? '{}' ), true );
				$metadata = is_array( $metadata ) ? $metadata : array();
				if ( is_array( $attributes['metadata'] ?? null ) ) {
					$metadata = array_replace_recursive( $metadata, $attributes['metadata'] );
				}
				$encoded_metadata = wp_json_encode( $metadata );
				if ( ! is_string( $encoded_metadata ) ) {
					throw new RuntimeException( 'The pool assignment metadata could not be encoded.' );
				}

				$updated = $wpdb->update(
					$table,
					array(
						'lifecycle_status' => 'assigned',
						'order_id'         => $attributes['order_id'],
						'order_item_id'    => $attributes['order_item_id'],
						'quantity_slot'    => $attributes['quantity_slot'],
						'customer_id'      => $attributes['customer_id'],
						'product_id'       => $attributes['product_id'],
						'variation_id'     => $attributes['variation_id'],
						'activation_limit' => $attributes['activation_limit'],
						'expires_at'       => $attributes['expires_at'],
						'metadata'         => $encoded_metadata,
						'updated_at'       => gmdate( 'Y-m-d H:i:s' ),
					),
					array( 'id' => (int) $license['id'], 'lifecycle_status' => 'available' ),
					array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' ),
					array( '%d', '%s' )
				);
				if ( 1 !== $updated ) {
					throw new RuntimeException( 'The imported key could not be reserved.' );
				}
				$actor_type = (string) ( $attributes['actor_type'] ?? 'woocommerce' );
				$actor_id   = isset( $attributes['actor_id'] ) ? (int) $attributes['actor_id'] : null;
				$request_id = isset( $attributes['request_id'] ) ? (string) $attributes['request_id'] : null;
				$this->events->append( 'license_assigned', (int) $license['id'], $actor_type, $actor_id, $request_id, array( 'order_id' => $attributes['order_id'], 'order_item_id' => $attributes['order_item_id'] ) );
				$this->events->append( 'license_delivered', (int) $license['id'], $actor_type, $actor_id, $request_id, array( 'order_id' => $attributes['order_id'], 'order_item_id' => $attributes['order_item_id'] ) );
				return array( 'id' => (int) $license['id'], 'public_id' => (string) $license['public_id'], 'key' => $this->licenses->decrypt_key( $license ) );
			}
		);
	}

	/** @param array<string,mixed> $attributes @return array{id:int,public_id:string,key:string} */
	private function create( string $key, string $profile, ?string $separator, array $attributes ): array {
		$canonical = $this->normalizer->normalize( $key, $profile, $separator );
		$public_id = PublicId::generate( 'lic' );
		$now       = gmdate( 'Y-m-d H:i:s' );
		$status    = (string) ( $attributes['lifecycle_status'] ?? 'available' );
		if ( ! in_array( $status, array( 'available', 'assigned', 'suspended', 'revoked' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid lifecycle status.' );
		}

		$limit = array_key_exists( 'activation_limit', $attributes ) ? $attributes['activation_limit'] : 1;
		if ( null !== $limit && (int) $limit < 0 ) {
			throw new InvalidArgumentException( 'Activation limit cannot be negative.' );
		}

		$record = array(
			'public_id'              => $public_id,
			'key_ciphertext'         => $this->crypto->encrypt( $key ),
			'key_fingerprint'        => $this->crypto->fingerprint( $canonical ),
			'normalization_profile'  => $profile,
			'normalization_separator'=> $separator,
			'lifecycle_status'       => $status,
			'product_public_id'      => $attributes['product_public_id'] ?? null,
			'product_id'             => $attributes['product_id'] ?? null,
			'variation_id'           => $attributes['variation_id'] ?? null,
			'order_id'               => $attributes['order_id'] ?? null,
			'order_item_id'          => $attributes['order_item_id'] ?? null,
			'quantity_slot'          => $attributes['quantity_slot'] ?? null,
			'customer_id'            => $attributes['customer_id'] ?? null,
			'generator_id'           => $attributes['generator_id'] ?? null,
			'activation_limit'       => null === $limit ? null : (int) $limit,
			'valid_for_seconds'      => $attributes['valid_for_seconds'] ?? null,
			'expires_at'             => $attributes['expires_at'] ?? null,
			'created_at'             => $now,
			'updated_at'             => $now,
			'metadata'               => is_array( $attributes['metadata'] ?? null ) ? $attributes['metadata'] : array(),
		);

		$id = $this->transaction->run(
			function () use ( $record, $attributes, $public_id ): int {
				$id = $this->licenses->insert( $record );
				$this->events->append(
					'license_created',
					$id,
					(string) ( $attributes['actor_type'] ?? 'system' ),
					isset( $attributes['actor_id'] ) ? (int) $attributes['actor_id'] : null,
					isset( $attributes['request_id'] ) ? (string) $attributes['request_id'] : null,
					array( 'source' => $attributes['source'] ?? 'generated', 'public_id' => $public_id )
				);
				if ( ! empty( $attributes['order_id'] ) ) {
					$actor_type = (string) ( $attributes['actor_type'] ?? 'woocommerce' );
					$actor_id   = isset( $attributes['actor_id'] ) ? (int) $attributes['actor_id'] : null;
					$request_id = isset( $attributes['request_id'] ) ? (string) $attributes['request_id'] : null;
					$this->events->append( 'license_assigned', $id, $actor_type, $actor_id, $request_id, array( 'order_id' => $attributes['order_id'], 'order_item_id' => $attributes['order_item_id'] ?? null ) );
					$this->events->append( 'license_delivered', $id, $actor_type, $actor_id, $request_id, array( 'order_id' => $attributes['order_id'], 'order_item_id' => $attributes['order_item_id'] ?? null ) );
				}
				return $id;
			}
		);

		return array( 'id' => $id, 'public_id' => $public_id, 'key' => $key );
	}
}
