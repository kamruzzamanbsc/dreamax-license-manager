<?php
/**
 * Defines the ActivationService class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Activations;

use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\LicenseException;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Support\PublicId;

/**
 * Handles Activation service operations.
 */
final class ActivationService {
	/**
	 * Licenses value.
	 *
	 * @var LicenseRepository
	 */
	private LicenseRepository $licenses;
	/**
	 * Crypto value.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;
	/**
	 * Events value.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;
	/**
	 * Transaction value.
	 *
	 * @var Transaction
	 */
	private Transaction $transaction;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->crypto      = new Crypto();
		$this->licenses    = new LicenseRepository( null, $this->crypto );
		$this->events      = new EventRepository();
		$this->transaction = new Transaction();
	}

	/**
	 * Handles the activate operation.
	 *
	 * @param string  $key Key value.
	 * @param string  $product_public_id Product public id value.
	 * @param string  $instance_id Instance id value.
	 * @param ?string $label Label value.
	 * @phpstan-param string|null $label Label value.
	 * @param string  $request_id Request id value.
	 * @throws LicenseException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	public function activate( string $key, string $product_public_id, string $instance_id, ?string $label, string $request_id ): array {
		$this->guard_inputs( $product_public_id, $instance_id, $label );
		$found = $this->find_for_product( $key, $product_public_id );

		$instance_fingerprint = $this->crypto->fingerprint( $instance_id, 'instance-identity' );

		return $this->transaction->run(
			function () use ( $found, $instance_fingerprint, $label, $request_id ): array {
				global $wpdb;
				$license = $this->licenses->lock_by_id( (int) $found['id'] );
				if ( null === $license ) {
					throw new LicenseException( 'invalid_license', 'The license could not be validated.', 404 );
				}
				$this->assert_eligible( $license );

				$table = $wpdb->prefix . 'dreamax_lm_activations';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$existing = $wpdb->get_row(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the trusted WordPress database prefix.
						"SELECT * FROM {$table} WHERE license_id = %d AND instance_fingerprint = UNHEX(%s) LIMIT 1",
						(int) $license['id'],
						bin2hex( $instance_fingerprint )
					),
					ARRAY_A
				);

				if ( is_array( $existing ) && 'active' === $existing['status'] ) {
					return $this->result( $license, $existing, true );
				}

				$limit = null === $license['activation_limit'] ? null : (int) $license['activation_limit'];
				if ( 0 === $limit ) {
					throw new LicenseException( 'activation_limit_reached', 'Activation is disabled for this license.', 409 );
				}

				if ( null !== $limit ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
					$count = (int) $wpdb->get_var(
						$wpdb->prepare(
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the trusted WordPress database prefix.
							"SELECT COUNT(*) FROM {$table} WHERE license_id = %d AND status = 'active'",
							(int) $license['id']
						)
					);
					if ( $count >= $limit ) {
						throw new LicenseException( 'activation_limit_reached', 'All activation slots are in use.', 409 );
					}
				}

				$now = gmdate( 'Y-m-d H:i:s' );
				if ( is_array( $existing ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
					$updated = $wpdb->update(
						$table,
						array(
							'instance_label' => null === $label ? null : sanitize_text_field( $label ),
							'status'         => 'active',
							'activated_at'   => $now,
							'deactivated_at' => null,
							'updated_at'     => $now,
						),
						array( 'id' => (int) $existing['id'] ),
						array( '%s', '%s', '%s', '%s', '%s' ),
						array( '%d' )
					);
					if ( false === $updated ) {
						throw new LicenseException( 'server_unavailable', 'Activation could not be stored.', 503 );
					}
					$activation_id = (int) $existing['id'];
					$public_id     = (string) $existing['public_id'];
				} else {
					$public_id = PublicId::generate( 'act' );
					$sql       = $wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the trusted WordPress database prefix.
						"INSERT INTO {$table} (public_id,license_id,instance_fingerprint,instance_label,status,first_activated_at,activated_at,deactivated_at,last_seen_at,updated_at,metadata)
						VALUES (%s,%d,UNHEX(%s),%s,'active',%s,%s,NULL,%s,%s,%s)",
						$public_id,
						(int) $license['id'],
						bin2hex( $instance_fingerprint ),
						null === $label ? null : sanitize_text_field( $label ),
						$now,
						$now,
						$now,
						$now,
						'{}'
					);
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The statement is prepared above; plugin-owned transactional tables require fresh direct writes.
					if ( false === $wpdb->query( $sql ) ) {
						throw new LicenseException( 'server_unavailable', 'Activation could not be stored.', 503 );
					}
					$activation_id = (int) $wpdb->insert_id;
				}

				$this->events->append(
					is_array( $existing ) ? AuditEventCatalog::LICENSE_REACTIVATED : AuditEventCatalog::LICENSE_ACTIVATED,
					(int) $license['id'],
					'public_api',
					null,
					$request_id,
					array( 'activation_public_id' => $public_id ),
					AuditEventCatalog::SCHEMA_V1
				);

				$activation = array(
					'id'           => $activation_id,
					'public_id'    => $public_id,
					'status'       => 'active',
					'activated_at' => $now,
				);
				return $this->result( $license, $activation, false );
			}
		);
	}

	/**
	 * Handles the deactivate operation.
	 *
	 * @param string $key Key value.
	 * @param string $product_public_id Product public id value.
	 * @param string $instance_id Instance id value.
	 * @param string $request_id Request id value.
	 * @throws LicenseException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	public function deactivate( string $key, string $product_public_id, string $instance_id, string $request_id ): array {
		$this->guard_inputs( $product_public_id, $instance_id, null );
		$found       = $this->find_for_product( $key, $product_public_id );
		$fingerprint = $this->crypto->fingerprint( $instance_id, 'instance-identity' );

		return $this->transaction->run(
			function () use ( $found, $fingerprint, $request_id ): array {
				global $wpdb;
				$license = $this->licenses->lock_by_id( (int) $found['id'] );
				if ( null === $license ) {
					throw new LicenseException( 'invalid_license', 'The license could not be validated.', 404 );
				}
				$table = $wpdb->prefix . 'dreamax_lm_activations';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$activation = $wpdb->get_row(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is built from the trusted WordPress database prefix.
						"SELECT * FROM {$table} WHERE license_id = %d AND instance_fingerprint = UNHEX(%s) LIMIT 1",
						(int) $found['id'],
						bin2hex( $fingerprint )
					),
					ARRAY_A
				);
				if ( ! is_array( $activation ) ) {
					throw new LicenseException( 'activation_not_found', 'The activation could not be found.', 404 );
				}
				if ( 'inactive' === $activation['status'] ) {
					return array(
						'license_public_id'    => $license['public_id'],
						'activation_public_id' => $activation['public_id'],
						'status'               => 'inactive',
						'replayed'             => true,
					);
				}

				$now = gmdate( 'Y-m-d H:i:s' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$updated = $wpdb->update(
					$table,
					array(
						'status'         => 'inactive',
						'deactivated_at' => $now,
						'updated_at'     => $now,
					),
					array(
						'id'     => (int) $activation['id'],
						'status' => 'active',
					),
					array( '%s', '%s', '%s' ),
					array( '%d', '%s' )
				);
				if ( 1 !== $updated ) {
					throw new LicenseException( 'server_unavailable', 'Deactivation could not be stored.', 503 );
				}
				$this->events->append( AuditEventCatalog::LICENSE_DEACTIVATED, (int) $license['id'], 'public_api', null, $request_id, array( 'activation_public_id' => $activation['public_id'] ), AuditEventCatalog::SCHEMA_V1 );
				return array(
					'license_public_id'    => $license['public_id'],
					'activation_public_id' => $activation['public_id'],
					'status'               => 'inactive',
					'replayed'             => false,
				);
			}
		);
	}

	/**
	 * Handles the validate operation.
	 *
	 * @param string  $key Key value.
	 * @param string  $product_public_id Product public id value.
	 * @param ?string $instance_id Instance id value.
	 * @phpstan-param string|null $instance_id Instance id value.
	 * @throws LicenseException When the operation cannot be completed.
	 * @return array<string,mixed>
	 */
	public function validate( string $key, string $product_public_id, ?string $instance_id = null ): array {
		if ( null !== $instance_id ) {
			$this->guard_inputs( $product_public_id, $instance_id, null );
		}
		$license = $this->find_for_product( $key, $product_public_id );
		$this->assert_eligible( $license );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id = %d AND status = 'active'", (int) $license['id'] ) );
		return array(
			'license_public_id' => $license['public_id'],
			'product_public_id' => $license['product_public_id'],
			'status'            => 'valid',
			'expires_at'        => $license['expires_at'],
			'activations'       => array(
				'active' => $active,
				'limit'  => null === $license['activation_limit'] ? null : (int) $license['activation_limit'],
			),
		);
	}

	/**
	 * Finds a license for the presented key and product contract.
	 *
	 * @param string $key Presented key.
	 * @param string $product_public_id Product public ID.
	 * @throws LicenseException When the key is unknown or belongs to another product.
	 * @return array<string,mixed>
	 */
	private function find_for_product( string $key, string $product_public_id ): array {
		$license = $this->licenses->find_presented( $key, $product_public_id );
		if ( is_array( $license ) ) {
			return $license;
		}
		if ( is_array( $this->licenses->find_presented_any_product( $key ) ) ) {
			throw new LicenseException( 'product_mismatch', 'The license does not belong to the requested product.', 409 );
		}
		throw new LicenseException( 'invalid_license', 'The license could not be validated.', 404 );
	}

	/**
	 * Handles the assert eligible operation.
	 *
	 * @param array $license License value.
	 * @phpstan-param array<string,mixed> $license License value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function assert_eligible( array $license ): void {
		if ( 'revoked' === $license['lifecycle_status'] ) {
			throw new LicenseException( 'license_revoked', 'The license is permanently revoked.', 403 );
		}
		if ( 'suspended' === $license['lifecycle_status'] ) {
			throw new LicenseException( 'license_suspended', 'The license is temporarily disabled.', 403 );
		}
		if ( null !== $license['expires_at'] && strtotime( (string) $license['expires_at'] . ' UTC' ) <= time() ) {
			throw new LicenseException( 'license_expired', 'The license has expired.', 403 );
		}
		if ( 'assigned' !== $license['lifecycle_status'] ) {
			throw new LicenseException( 'invalid_license', 'The license could not be validated.', 404 );
		}
	}

	/**
	 * Handles the guard inputs operation.
	 *
	 * @param string  $product_public_id Product public id value.
	 * @param string  $instance_id Instance id value.
	 * @param ?string $label Label value.
	 * @phpstan-param string|null $label Label value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function guard_inputs( string $product_public_id, string $instance_id, ?string $label ): void {
		if ( ! preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product_public_id ) ) {
			throw new LicenseException( 'invalid_request', 'The product identifier is invalid.', 400 );
		}
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{16,128}$/D', $instance_id ) ) {
			throw new LicenseException( 'invalid_request', 'The installation identifier is invalid.', 400 );
		}
		if ( null !== $label && strlen( $label ) > 255 ) {
			throw new LicenseException( 'invalid_request', 'The installation label is too long.', 400 );
		}
	}

	/**
	 * Handles the result operation.
	 *
	 * @param array $license License value.
	 * @phpstan-param array{public_id:string,product_public_id:string,expires_at:string|null} $license License value.
	 * @param array $activation Activation value.
	 * @phpstan-param array{public_id:string} $activation Activation value.
	 * @param bool  $replayed Replayed value.
	 * @return array{license_public_id:string,product_public_id:string,activation_public_id:string,status:'active',expires_at:string|null,replayed:bool}
	 */
	private function result( array $license, array $activation, bool $replayed ): array {
		return array(
			'license_public_id'    => $license['public_id'],
			'product_public_id'    => $license['product_public_id'],
			'activation_public_id' => $activation['public_id'],
			'status'               => 'active',
			'expires_at'           => $license['expires_at'],
			'replayed'             => $replayed,
		);
	}
}
