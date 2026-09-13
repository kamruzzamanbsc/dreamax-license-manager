<?php
/**
 * Defines the AuditEventCatalog class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Events;

use InvalidArgumentException;

/**
 * Authoritative compatibility contract for every persisted audit event.
 */
final class AuditEventCatalog {
	public const SCHEMA_V1 = 1;

	public const CREDENTIAL_CREATED                       = 'credential_created';
	public const CREDENTIAL_AUTHENTICATION_USED           = 'credential_authentication_used';
	public const CREDENTIAL_ROTATION_SUCCEEDED            = 'credential_rotation_succeeded';
	public const CREDENTIAL_ROTATION_FAILED               = 'credential_rotation_failed';
	public const CREDENTIAL_REVOKED                       = 'credential_revoked';
	public const CREDENTIAL_EXPIRED_AUTHENTICATION_FAILED = 'credential_expired_authentication_failed';
	public const CREDENTIAL_INSUFFICIENT_SCOPE            = 'credential_insufficient_scope';
	public const GUEST_CLAIM_ISSUED                       = 'guest_claim_issued';
	public const GUEST_CLAIM_SUCCEEDED                    = 'guest_claim_succeeded';
	public const GUEST_CLAIM_REPLAY_FAILED                = 'guest_claim_replay_failed';
	public const GUEST_CLAIM_EXPIRED_FAILED               = 'guest_claim_expired_failed';
	public const GUEST_CLAIM_CONFLICT_FAILED              = 'guest_claim_conflict_failed';
	public const GUEST_CLAIM_RELEASED                     = 'guest_claim_released';
	public const GUEST_CLAIM_ADMINISTRATOR_OVERRIDE       = 'guest_claim_administrator_override';
	public const LICENSE_CREATED                          = 'license_created';
	public const LICENSE_ASSIGNED                         = 'license_assigned';
	public const LICENSE_DELIVERED                        = 'license_delivered';
	public const LICENSE_ACTIVATED                        = 'license_activated';
	public const LICENSE_REACTIVATED                      = 'license_reactivated';
	public const LICENSE_DEACTIVATED                      = 'license_deactivated';
	public const LICENSE_REVEALED                         = 'license_revealed';
	public const LICENSE_EXPORTED                         = 'license_exported';
	public const LICENSE_SUSPENDED                        = 'license_suspended';
	public const LICENSE_REVOKED                          = 'license_revoked';
	public const LICENSE_RESTORED                         = 'license_restored';
	public const LICENSE_UPDATED                          = 'license_updated';
	public const LICENSE_EXTENDED                         = 'license_extended';
	public const LICENSE_ACTIVATIONS_RESET                = 'license_activations_reset';
	public const LICENSE_REASSIGNED                       = 'license_reassigned';
	public const LICENSE_REASSIGNMENT_NOTIFIED            = 'license_reassignment_notified';
	public const LICENSE_REASSIGNMENT_NOTIFICATION_FAILED = 'license_reassignment_notification_failed';
	public const LICENSE_DELETED                          = 'license_deleted';
	public const LICENSE_RESENT                           = 'license_resent';
	public const LICENSE_OPERATION_REJECTED               = 'license_operation_rejected';
	public const LEGACY_ACTIVATION_FAILED                 = 'activation_failed';
	public const LEGACY_LICENSE_EXPIRED                   = 'license_expired';
	public const LEGACY_LICENSE_IMPORTED                  = 'license_imported';
	public const LEGACY_LICENSE_RENEWED                   = 'license_renewed';
	public const ORDER_ALLOCATION_FAILED                  = 'order_allocation_failed';
	public const ORDER_AUTOMATIC_ALLOCATION_COMPLETED     = 'order_automatic_allocation_completed';
	public const ORDER_EXPLICIT_ALLOCATION_COMPLETED      = 'order_explicit_allocation_completed';
	public const ORDER_REFUNDED                           = 'order_refunded';
	public const ORDER_QUANTITY_CHANGED_AFTER_DELIVERY    = 'order_quantity_changed_after_delivery';
	public const ORDER_ITEM_DELETED_AFTER_DELIVERY        = 'order_item_deleted_after_delivery';
	public const ORDER_REFUND_UNMAPPED                    = 'order_refund_unmapped';
	public const ORDER_REFUND_POLICY_APPLIED              = 'order_refund_policy_applied';
	public const ORDER_CANCELLATION_POLICY_APPLIED        = 'order_cancellation_policy_applied';
	public const PRIVACY_DATA_ANONYMIZED                  = 'privacy_data_anonymized';

	/**
	 * Returns all supported actor classifications.
	 *
	 * @return list<string>
	 */
	public static function actors(): array {
		return array( 'administrator', 'api_credential', 'customer', 'privacy_tool', 'public_api', 'system', 'woocommerce' );
	}

	/**
	 * Returns all published contracts keyed by event type and schema version.
	 *
	 * Metadata is closed-world for each published version. New optional fields may
	 * be added to `optional` without changing the existing meaning; consumers must
	 * ignore optional fields they do not understand.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function contracts(): array {
		$admin         = array( 'administrator' );
		$credential    = array( 'api_credential' );
		$customer      = array( 'customer' );
		$woo           = array( 'woocommerce' );
		$license_ops   = array( 'administrator' );
		$issuers       = array( 'administrator', 'api_credential', 'system', 'woocommerce' );
		$order_refs    = array(
			'order_id'      => 'positive-int',
			'order_item_id' => 'nullable-positive-int',
		);
		$transition    = array(
			'from'   => 'non-empty-string',
			'to'     => 'non-empty-string',
			'reason' => 'reason',
		);
		$credential_id = array( 'credential_public_id' => 'credential-public-id' );

		return array(
			self::CREDENTIAL_CREATED                       => self::v1(
				$admin,
				'none',
				'required',
				'optional',
				array_merge(
					$credential_id,
					array(
						'scopes'  => 'string-list',
						'expires' => 'bool',
					)
				),
				array(),
				array( 'src/Credentials/class-credentialservice.php' )
			),
			self::CREDENTIAL_AUTHENTICATION_USED           => self::v1( $credential, 'none', 'required', 'required', array_merge( $credential_id, array( 'required_scope' => 'non-empty-string' ) ), array(), array( 'src/Credentials/class-credentialservice.php' ) ),
			self::CREDENTIAL_ROTATION_SUCCEEDED            => self::v1(
				$admin,
				'none',
				'required',
				'optional',
				array_merge(
					$credential_id,
					array(
						'previous_version' => 'positive-int',
						'new_version'      => 'positive-int',
					)
				),
				array(),
				array( 'src/Credentials/class-credentialservice.php' )
			),
			self::CREDENTIAL_ROTATION_FAILED               => self::v1(
				$admin,
				'none',
				'required',
				'optional',
				array_merge(
					$credential_id,
					array(
						'expected_version' => 'positive-int',
						'failure'          => self::enum( array( 'not_completed' ) ),
					)
				),
				array(),
				array( 'src/Credentials/class-credentialservice.php' )
			),
			self::CREDENTIAL_REVOKED                       => self::v1( $admin, 'none', 'required', 'optional', $credential_id, array(), array( 'src/Credentials/class-credentialservice.php' ) ),
			self::CREDENTIAL_EXPIRED_AUTHENTICATION_FAILED => self::v1( $credential, 'none', 'required', 'required', $credential_id, array(), array( 'src/Credentials/class-credentialservice.php' ) ),
			self::CREDENTIAL_INSUFFICIENT_SCOPE            => self::v1( $credential, 'none', 'required', 'required', array_merge( $credential_id, array( 'required_scope' => 'non-empty-string' ) ), array(), array( 'src/Credentials/class-credentialservice.php' ) ),

			self::GUEST_CLAIM_ISSUED                       => self::v1(
				$customer,
				'none',
				'required',
				'required',
				array(
					'order_id'         => 'positive-int',
					'lifetime_seconds' => 'positive-int',
				),
				array(),
				array( 'src/CustomerPortal/class-guestclaimservice.php' )
			),
			self::GUEST_CLAIM_SUCCEEDED                    => self::v1(
				$customer,
				'none',
				'required',
				'required',
				array(
					'order_id'      => 'positive-int',
					'license_count' => 'positive-int',
				),
				array(),
				array( 'src/CustomerPortal/class-guestclaimservice.php' )
			),
			self::GUEST_CLAIM_REPLAY_FAILED                => self::v1(
				$customer,
				'none',
				'required',
				'required',
				array(
					'order_id' => 'positive-int',
					'failure'  => self::enum( array( 'replay' ) ),
				),
				array(),
				array( 'src/CustomerPortal/class-guestclaimservice.php' )
			),
			self::GUEST_CLAIM_EXPIRED_FAILED               => self::v1(
				$customer,
				'none',
				'required',
				'required',
				array(
					'order_id' => 'positive-int',
					'failure'  => self::enum( array( 'expired' ) ),
				),
				array(),
				array( 'src/CustomerPortal/class-guestclaimservice.php' )
			),
			self::GUEST_CLAIM_CONFLICT_FAILED              => self::v1(
				array( 'customer', 'woocommerce' ),
				'none',
				'optional',
				'required',
				array(
					'order_id' => 'positive-int',
					'failure'  => self::enum( array( 'conflict', 'ownership_changed' ) ),
				),
				array(),
				array( 'src/CustomerPortal/class-guestclaimservice.php' )
			),
			self::GUEST_CLAIM_RELEASED                     => self::v1(
				$admin,
				'none',
				'required',
				'optional',
				array(
					'order_id'      => 'positive-int',
					'license_count' => 'positive-int',
				),
				array(),
				array( 'src/CustomerPortal/class-guestclaimservice.php' )
			),
			self::GUEST_CLAIM_ADMINISTRATOR_OVERRIDE       => self::v1(
				$admin,
				'optional',
				'required',
				'optional',
				array(
					'order_id'    => 'positive-int',
					'customer_id' => 'positive-int',
				),
				array(
					'license_count' => 'positive-int',
					'scope'         => self::enum( array( 'single_license_reassignment' ) ),
				),
				array( 'src/CustomerPortal/class-guestclaimservice.php', 'src/Licenses/class-lifecycleservice.php' ),
				array( array( 'one_of' => array( 'license_count', 'scope' ) ) )
			),

			self::LICENSE_CREATED                          => self::v1(
				$issuers,
				'required',
				'optional',
				'optional',
				array(
					'source'    => 'non-empty-string',
					'public_id' => 'license-public-id',
				),
				array( 'normalization_profile' => 'non-empty-string' ),
				array( 'src/Licenses/class-licenseservice.php' )
			),
			self::LICENSE_ASSIGNED                         => self::v1( $issuers, 'required', 'optional', 'optional', $order_refs, array(), array( 'src/Licenses/class-licenseservice.php' ) ),
			self::LICENSE_DELIVERED                        => self::v1( $issuers, 'required', 'optional', 'optional', $order_refs, array(), array( 'src/Licenses/class-licenseservice.php' ) ),
			self::LICENSE_ACTIVATED                        => self::v1( array( 'public_api', 'customer' ), 'required', 'optional', 'required', array( 'activation_public_id' => 'activation-public-id' ), array(), array( 'src/Activations/class-activationservice.php' ) ),
			self::LICENSE_REACTIVATED                      => self::v1( array( 'public_api', 'customer' ), 'required', 'optional', 'required', array( 'activation_public_id' => 'activation-public-id' ), array(), array( 'src/Activations/class-activationservice.php' ) ),
			self::LICENSE_DEACTIVATED                      => self::v1( array( 'public_api', 'customer' ), 'required', 'optional', 'required', array( 'activation_public_id' => 'activation-public-id' ), array(), array( 'src/Activations/class-activationservice.php' ) ),
			self::LICENSE_REVEALED                         => self::v1( $customer, 'required', 'required', 'optional', array( 'channel' => self::enum( array( 'my_account' ) ) ), array(), array( 'src/CustomerPortal/class-accountendpoint.php' ) ),
			self::LICENSE_EXPORTED                         => self::v1(
				$admin,
				'none',
				'required',
				'optional',
				array(
					'full_keys' => 'bool',
					'row_count' => 'non-negative-int',
				),
				array(),
				array( 'src/ImportExport/class-csvcontroller.php' )
			),
			self::LICENSE_SUSPENDED                        => self::v1( $license_ops, 'required', 'required', 'required', $transition, array(), array( 'src/Licenses/class-lifecycleservice.php' ) ),
			self::LICENSE_REVOKED                          => self::v1(
				array( 'administrator', 'api_credential' ),
				'required',
				'optional',
				'optional',
				array(),
				array_merge( $transition, array( 'changed_fields' => 'string-list' ) ),
				array( 'src/Licenses/class-lifecycleservice.php', 'src/Api/class-privilegedroutes.php' ),
				array(
					array(
						'one_of'   => array( 'from', 'changed_fields' ),
						'requires' => array( 'from' => array( 'to', 'reason' ) ),
					),
				)
			),
			self::LICENSE_RESTORED                         => self::v1( $license_ops, 'required', 'required', 'required', $transition, array(), array( 'src/Licenses/class-lifecycleservice.php' ) ),
			self::LICENSE_UPDATED                          => self::v1( array( 'administrator', 'api_credential' ), 'required', 'optional', 'optional', array( 'changed_fields' => 'string-list' ), array( 'reason' => 'reason' ), array( 'src/Api/class-privilegedroutes.php', 'src/Licenses/class-licensepolicyeditor.php', 'src/Licenses/class-merchantmetadata.php' ) ),
			self::LICENSE_EXTENDED                         => self::v1(
				$license_ops,
				'required',
				'required',
				'required',
				array(
					'old_expiry'     => 'nullable-utc-datetime',
					'new_expiry'     => 'utc-datetime',
					'extension_days' => 'positive-int',
					'reason'         => 'reason',
				),
				array(),
				array( 'src/Licenses/class-lifecycleservice.php' )
			),
			self::LICENSE_ACTIVATIONS_RESET                => self::v1(
				$license_ops,
				'required',
				'required',
				'required',
				array(
					'reset_count' => 'non-negative-int',
					'reason'      => 'reason',
				),
				array(),
				array( 'src/Licenses/class-lifecycleservice.php' )
			),
			self::LICENSE_REASSIGNED                       => self::v1(
				$license_ops,
				'required',
				'required',
				'required',
				array(
					'before'           => 'license-snapshot',
					'after'            => 'license-snapshot',
					'activation_reset' => 'bool',
					'reset_count'      => 'non-negative-int',
					'reason'           => 'reason',
				),
				array(),
				array( 'src/Licenses/class-lifecycleservice.php' )
			),
			self::LICENSE_REASSIGNMENT_NOTIFIED            => self::v1( $license_ops, 'required', 'required', 'required', array( 'customer_id' => 'positive-int' ), array(), array( 'src/Licenses/class-lifecycleservice.php' ) ),
			self::LICENSE_REASSIGNMENT_NOTIFICATION_FAILED => self::v1( $license_ops, 'required', 'required', 'required', array( 'customer_id' => 'positive-int' ), array(), array( 'src/Licenses/class-lifecycleservice.php' ) ),
			self::LICENSE_DELETED                          => self::v1(
				$license_ops,
				'required',
				'required',
				'required',
				array(
					'license_public_id' => 'license-public-id',
					'reason'            => 'reason',
				),
				array(),
				array( 'src/Licenses/class-lifecycleservice.php' )
			),
			self::LICENSE_RESENT                           => self::v1(
				$admin,
				'required',
				'required',
				'required',
				array(
					'order_id' => 'positive-int',
					'channel'  => self::enum( array( 'billing_email' ) ),
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderlicensing.php' )
			),
			self::LICENSE_OPERATION_REJECTED               => self::v1( $admin, 'required', 'required', 'required', array( 'operation' => 'non-empty-string' ), array(), array( 'src/Admin/class-admin.php' ) ),

			self::ORDER_ALLOCATION_FAILED                  => self::v1(
				array( 'administrator', 'woocommerce' ),
				'none',
				'optional',
				'required',
				array(
					'order_id'      => 'positive-int',
					'order_item_id' => 'positive-int',
					'quantity_slot' => 'positive-int',
					'source'        => 'non-empty-string',
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderlicensing.php' )
			),
			self::ORDER_AUTOMATIC_ALLOCATION_COMPLETED     => self::v1(
				$woo,
				'none',
				'none',
				'required',
				array(
					'order_id'  => 'positive-int',
					'allocated' => 'positive-int',
					'existing'  => 'non-negative-int',
					'failed'    => 'non-negative-int',
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderlicensing.php' )
			),
			self::ORDER_EXPLICIT_ALLOCATION_COMPLETED      => self::v1(
				$admin,
				'none',
				'required',
				'required',
				array(
					'order_id'  => 'positive-int',
					'allocated' => 'positive-int',
					'existing'  => 'non-negative-int',
					'failed'    => 'non-negative-int',
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderlicensing.php' )
			),
			self::ORDER_REFUNDED                           => self::v1(
				$woo,
				'none',
				'none',
				'required',
				array(
					'order_id'          => 'positive-int',
					'refund_id'         => 'positive-int',
					'licenses_affected' => 'non-negative-int',
					'licenses_replayed' => 'non-negative-int',
					'unmapped_items'    => 'non-negative-int',
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderlicensing.php' )
			),
			self::ORDER_QUANTITY_CHANGED_AFTER_DELIVERY    => self::v1(
				$woo,
				'required',
				'none',
				'optional',
				array(
					'order_id'           => 'positive-int',
					'order_item_id'      => 'positive-int',
					'delivery_quantity'  => 'non-negative-int',
					'requested_quantity' => 'non-negative-int',
					'policy_result'      => self::enum( array( 'increase_requires_allocation', 'decrease_retains_licenses' ) ),
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderlicensing.php' )
			),
			self::ORDER_ITEM_DELETED_AFTER_DELIVERY        => self::v1(
				$woo,
				'required',
				'none',
				'optional',
				array(
					'order_id'                 => 'positive-int',
					'order_item_id'            => 'positive-int',
					'license_history_retained' => 'bool',
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderlicensing.php' )
			),
			self::ORDER_REFUND_UNMAPPED                    => self::v1(
				$woo,
				'none',
				'none',
				'required',
				array(
					'order_id'  => 'positive-int',
					'refund_id' => 'positive-int',
					'reason'    => 'reason',
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderpolicyservice.php' )
			),
			self::ORDER_REFUND_POLICY_APPLIED              => self::v1(
				$woo,
				'required',
				'none',
				'required',
				array(
					'order_id'          => 'positive-int',
					'refund_id'         => 'non-negative-int',
					'order_item_id'     => 'nullable-positive-int',
					'quantity_slot'     => 'nullable-positive-int',
					'configured_policy' => 'non-empty-string',
					'effective_policy'  => 'non-empty-string',
					'before_status'     => 'non-empty-string',
					'after_status'      => 'non-empty-string',
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderpolicyservice.php' )
			),
			self::ORDER_CANCELLATION_POLICY_APPLIED        => self::v1(
				$woo,
				'required',
				'none',
				'required',
				array(
					'order_id'          => 'positive-int',
					'refund_id'         => 'non-negative-int',
					'order_item_id'     => 'nullable-positive-int',
					'quantity_slot'     => 'nullable-positive-int',
					'configured_policy' => 'non-empty-string',
					'effective_policy'  => 'non-empty-string',
					'before_status'     => 'non-empty-string',
					'after_status'      => 'non-empty-string',
				),
				array(),
				array( 'src/Integrations/WooCommerce/class-orderpolicyservice.php' )
			),

			self::PRIVACY_DATA_ANONYMIZED                  => self::v1(
				array( 'privacy_tool' ),
				'none',
				'none',
				'optional',
				array(
					'license_count' => 'non-negative-int',
					'claim_count'   => 'non-negative-int',
					'owner_count'   => 'non-negative-int',
				),
				array(),
				array( 'src/Privacy/class-privacy.php' )
			),
			self::LEGACY_ACTIVATION_FAILED                 => self::legacy_v1( 'Previously documented without a production emitter or a defined payload shape.' ),
			self::LEGACY_LICENSE_EXPIRED                   => self::legacy_v1( 'Previously documented as computed expiry, but no persisted emitter was implemented.' ),
			self::LEGACY_LICENSE_IMPORTED                  => self::legacy_v1( 'Previously documented, while imports actually persist license_created with a source field.' ),
			self::LEGACY_LICENSE_RENEWED                   => self::legacy_v1( 'Previously documented, but renewal remains outside the implemented Free V1 event surface.' ),
		);
	}

	/**
	 * Validates and returns sanitized metadata for persistence.
	 *
	 * @param string      $event_type Stable event type.
	 * @param int         $schema_version Positive payload version.
	 * @param int|null    $license_id Internal license reference.
	 * @param string      $actor_type Actor classification.
	 * @param int|null    $actor_id Internal actor reference.
	 * @param string|null $request_id Opaque request/operation reference.
	 * @param array       $metadata Metadata payload.
	 * @phpstan-param array<string,mixed> $metadata
	 * @return array<string,mixed>
	 */
	public static function validate(
		string $event_type,
		int $schema_version,
		?int $license_id,
		string $actor_type,
		?int $actor_id,
		?string $request_id,
		array $metadata
	): array {
		$contract = self::contract( $event_type, $schema_version );
		if ( false === $contract['persistable'] ) {
			self::invalid();
		}
		if ( ! in_array( $actor_type, self::actors(), true ) || ! in_array( $actor_type, $contract['actors'], true ) ) {
			self::invalid();
		}
		self::validate_reference( $contract['license_reference'], $license_id );
		self::validate_reference( $contract['actor_reference'], $actor_id );
		self::validate_request_reference( $contract['request_reference'], $request_id );

		$clean = ( new AuditMetadata() )->sanitize( $metadata );
		foreach ( $contract['required'] as $field => $type ) {
			if ( ! array_key_exists( $field, $clean ) || ! self::value_matches( $clean[ $field ], $type ) ) {
				self::invalid();
			}
		}
		$allowed = array_merge( $contract['required'], $contract['optional'] );
		foreach ( $clean as $field => $value ) {
			if ( ! array_key_exists( $field, $allowed ) || ! self::value_matches( $value, $allowed[ $field ] ) ) {
				self::invalid();
			}
		}
		foreach ( $contract['rules'] as $rule ) {
			$present = array_filter( $rule['one_of'], static fn( string $field ): bool => array_key_exists( $field, $clean ) );
			if ( 1 !== count( $present ) ) {
				self::invalid();
			}
			foreach ( $rule['requires'] ?? array() as $field => $required_fields ) {
				if ( ! array_key_exists( $field, $clean ) ) {
					continue;
				}
				foreach ( $required_fields as $required_field ) {
					if ( ! array_key_exists( $required_field, $clean ) ) {
						self::invalid();
					}
				}
			}
		}
		return $clean;
	}

	/**
	 * Returns compatibility status without rejecting or rewriting stored history.
	 *
	 * @param array $row Persisted event row.
	 * @phpstan-param array<string,mixed> $row
	 */
	public static function compatibility_status( array $row ): string {
		$type    = isset( $row['event_type'] ) ? (string) $row['event_type'] : '';
		$version = isset( $row['schema_version'] ) ? (int) $row['schema_version'] : 0;
		if ( '' === $type || ! isset( self::contracts()[ $type ] ) ) {
			return 'unknown_type';
		}
		if ( $version < 1 ) {
			return 'legacy_unversioned';
		}
		if ( ! isset( self::contracts()[ $type ][ $version ] ) ) {
			return 'unsupported_version';
		}
		if ( ! isset( $row['public_id'] ) || 1 !== preg_match( '/^evt_[A-Za-z0-9_-]{22}$/D', (string) $row['public_id'] ) || ! self::utc_datetime( $row['occurred_at'] ?? null ) ) {
			return 'legacy_payload';
		}
		$contract = self::contracts()[ $type ][ $version ];
		if ( is_string( $contract['legacy_reason'] ) && '' !== $contract['legacy_reason'] ) {
			return 'legacy_catalog_entry';
		}
		try {
			$metadata = json_decode( (string) ( $row['metadata'] ?? '{}' ), true );
			if ( ! is_array( $metadata ) ) {
				return 'legacy_payload';
			}
			self::validate(
				$type,
				$version,
				isset( $row['license_id'] ) ? (int) $row['license_id'] : null,
				(string) ( $row['actor_type'] ?? '' ),
				isset( $row['actor_id'] ) ? (int) $row['actor_id'] : null,
				isset( $row['request_id'] ) ? (string) $row['request_id'] : null,
				$metadata
			);
		} catch ( InvalidArgumentException ) {
			return 'legacy_payload';
		}
		return 'supported';
	}

	/**
	 * Selects the stable failure event for a guest-claim outcome.
	 *
	 * @param string $failure Internal failure classification.
	 */
	public static function guest_claim_failure( string $failure ): string {
		if ( 'expired' === $failure ) {
			return self::GUEST_CLAIM_EXPIRED_FAILED;
		}
		if ( 'replay' === $failure ) {
			return self::GUEST_CLAIM_REPLAY_FAILED;
		}
		return self::GUEST_CLAIM_CONFLICT_FAILED;
	}

	/**
	 * Gets an exact supported contract.
	 *
	 * @param string $event_type Stable event type.
	 * @param int    $schema_version Published payload schema version.
	 * @return array<string,mixed>
	 */
	private static function contract( string $event_type, int $schema_version ): array {
		$contracts = self::contracts();
		if ( $schema_version < 1 || ! isset( $contracts[ $event_type ][ $schema_version ] ) ) {
			self::invalid();
		}
		return $contracts[ $event_type ][ $schema_version ];
	}

	/**
	 * Wraps one version-1 definition.
	 *
	 * @param array                     $actors Allowed actors.
	 * @phpstan-param list<string> $actors
	 * @param string                    $license_reference License reference policy.
	 * @param string                    $actor_reference Actor reference policy.
	 * @param string                    $request_reference Request reference policy.
	 * @param array<string,mixed>       $required Required metadata.
	 * @param array<string,mixed>       $optional Optional metadata.
	 * @param array                     $emitters Production emitter paths.
	 * @phpstan-param list<string> $emitters
	 * @param list<array<string,mixed>> $rules Cross-field rules.
	 * @return array<int,array<string,mixed>>
	 */
	private static function v1( array $actors, string $license_reference, string $actor_reference, string $request_reference, array $required, array $optional, array $emitters, array $rules = array() ): array {
		return array(
			self::SCHEMA_V1 => array(
				'actors'            => $actors,
				'license_reference' => $license_reference,
				'actor_reference'   => $actor_reference,
				'request_reference' => $request_reference,
				'required'          => $required,
				'optional'          => $optional,
				'emitters'          => $emitters,
				'legacy_reason'     => null,
				'persistable'       => true,
				'rules'             => $rules,
			),
		);
	}

	/**
	 * Defines a readable but non-persistable legacy catalog entry.
	 *
	 * @param string $reason Compatibility reason.
	 * @return array<int,array<string,mixed>>
	 */
	private static function legacy_v1( string $reason ): array {
		return array(
			self::SCHEMA_V1 => array(
				'actors'            => self::actors(),
				'license_reference' => 'optional',
				'actor_reference'   => 'optional',
				'request_reference' => 'optional',
				'required'          => array(),
				'optional'          => array(),
				'emitters'          => array(),
				'legacy_reason'     => $reason,
				'persistable'       => false,
				'rules'             => array(),
			),
		);
	}

	/**
	 * Creates an enum field definition.
	 *
	 * @param array $values Allowed values.
	 * @phpstan-param list<string> $values
	 * @return array{enum:list<string>}
	 */
	private static function enum( array $values ): array {
		return array( 'enum' => $values );
	}

	/**
	 * Validates an integer reference against its policy.
	 *
	 * @param string   $policy Reference policy.
	 * @param int|null $value Internal reference value.
	 */
	private static function validate_reference( string $policy, ?int $value ): void {
		if ( ( 'required' === $policy && ( null === $value || $value < 1 ) ) || ( 'none' === $policy && null !== $value ) || ( null !== $value && $value < 1 ) ) {
			self::invalid();
		}
	}

	/**
	 * Validates an opaque request reference against its policy.
	 *
	 * @param string      $policy Reference policy.
	 * @param string|null $value Opaque request reference.
	 */
	private static function validate_request_reference( string $policy, ?string $value ): void {
		if ( 'none' === $policy && null !== $value ) {
			self::invalid();
		}
		if ( 'required' === $policy && ( null === $value || '' === $value ) ) {
			self::invalid();
		}
		if ( null !== $value && ( '' === $value || strlen( $value ) > 64 || preg_match( '/[\x00-\x1F\x7F]/', $value ) ) ) {
			self::invalid();
		}
	}

	/**
	 * Checks one value against a published safe type.
	 *
	 * @param mixed $value Value.
	 * @param mixed $type Type definition.
	 */
	private static function value_matches( $value, $type ): bool {
		if ( is_array( $type ) && isset( $type['enum'] ) ) {
			return is_string( $value ) && in_array( $value, $type['enum'], true );
		}
		if ( 'bool' === $type ) {
			return is_bool( $value );
		}
		if ( 'positive-int' === $type ) {
			return is_int( $value ) && $value > 0;
		}
		if ( 'nullable-positive-int' === $type ) {
			return null === $value || ( is_int( $value ) && $value > 0 );
		}
		if ( 'non-negative-int' === $type ) {
			return is_int( $value ) && $value >= 0;
		}
		if ( 'string-list' === $type ) {
			return is_array( $value ) && array_is_list( $value ) && array() !== $value && count( $value ) <= 32 && array() === array_filter( $value, static fn( $item ): bool => ! self::safe_string( $item ) );
		}
		if ( 'license-public-id' === $type ) {
			return is_string( $value ) && 1 === preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $value );
		}
		if ( 'activation-public-id' === $type ) {
			return is_string( $value ) && 1 === preg_match( '/^act_[A-Za-z0-9_-]{22}$/D', $value );
		}
		if ( 'credential-public-id' === $type ) {
			return is_string( $value ) && 1 === preg_match( '/^[A-Za-z0-9_-]{22}$/D', $value );
		}
		if ( 'utc-datetime' === $type ) {
			return self::utc_datetime( $value );
		}
		if ( 'nullable-utc-datetime' === $type ) {
			return null === $value || self::utc_datetime( $value );
		}
		if ( 'reason' === $type ) {
			return self::safe_string( $value, 500 );
		}
		if ( 'non-empty-string' === $type ) {
			return self::safe_string( $value );
		}
		if ( 'license-snapshot' === $type ) {
			return self::license_snapshot( $value );
		}
		return false;
	}

	/**
	 * Validates bounded text after recursive secret redaction.
	 *
	 * @param mixed $value Value.
	 * @param int   $limit Maximum byte length.
	 */
	private static function safe_string( $value, int $limit = 2048 ): bool {
		return is_string( $value ) && '' !== $value && strlen( $value ) <= $limit && 0 === preg_match( '/[\x00-\x1F\x7F]/', $value );
	}

	/**
	 * Validates exact UTC storage format and calendar values.
	 *
	 * @param mixed $value Value.
	 */
	private static function utc_datetime( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value ) ) {
			return false;
		}
		$timestamp = strtotime( $value . ' UTC' );
		return false !== $timestamp && gmdate( 'Y-m-d H:i:s', $timestamp ) === $value;
	}

	/**
	 * Validates the stable v1 reassignment snapshot shape.
	 *
	 * @param mixed $value Value.
	 */
	private static function license_snapshot( $value ): bool {
		if ( ! is_array( $value ) || array_diff( array_keys( $value ), array( 'customer_id', 'order_id', 'product_public_id', 'lifecycle_status' ) ) || array_diff( array( 'customer_id', 'order_id', 'product_public_id', 'lifecycle_status' ), array_keys( $value ) ) ) {
			return false;
		}
		return self::value_matches( $value['customer_id'], 'nullable-positive-int' )
			&& self::value_matches( $value['order_id'], 'nullable-positive-int' )
			&& ( null === $value['product_public_id'] || self::safe_string( $value['product_public_id'] ) )
			&& self::safe_string( $value['lifecycle_status'] );
	}

	/**
	 * Throws one privacy-safe contract error.
	 *
	 * @throws InvalidArgumentException Always.
	 */
	private static function invalid(): void {
		throw new InvalidArgumentException( 'The audit event does not satisfy its published contract.' );
	}
}
