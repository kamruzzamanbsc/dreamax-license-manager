<?php
/**
 * Defines the RouteSchema class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

/**
 * Publishes JSON Schema metadata for v1 route discovery and validation.
 */
final class RouteSchema {
	/**
	 * Returns the stable v1 response envelope schema.
	 *
	 * @return array<string,mixed>
	 */
	public static function envelope(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'dreamax-license-manager-v1-envelope',
			'type'       => 'object',
			'required'   => array( 'success', 'code', 'message', 'request_id', 'timestamp', 'data' ),
			'properties' => array(
				'success'    => array( 'type' => 'boolean' ),
				'code'       => array( 'type' => 'string' ),
				'message'    => array( 'type' => 'string' ),
				'request_id' => array(
					'type'    => 'string',
					'pattern' => '^req_[A-Za-z0-9_-]{22}$',
				),
				'timestamp'  => array(
					'type'   => 'string',
					'format' => 'date-time',
				),
				'data'       => array( 'type' => 'object' ),
			),
		);
	}

	/**
	 * Returns public license-operation request fields.
	 *
	 * @param bool $instance_required Whether an installation identity is mandatory.
	 * @return array<string,array<string,mixed>>
	 */
	public static function public_operation_args( bool $instance_required ): array {
		return array(
			'license_key'       => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
				'maxLength' => 512,
			),
			'product_public_id' => self::public_id_arg( 'prd', true ),
			'instance_id'       => array(
				'type'      => 'string',
				'required'  => $instance_required,
				'minLength' => 16,
				'maxLength' => 128,
				'pattern'   => '^[A-Za-z0-9._:-]+$',
			),
			'instance_label'    => array(
				'type'      => array( 'string', 'null' ),
				'required'  => false,
				'maxLength' => 255,
			),
		);
	}

	/**
	 * Returns list pagination fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function list_args(): array {
		return array(
			'page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'default' => 1,
			),
		);
	}

	/**
	 * Returns create-license fields for privileged route discovery.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function create_license_args(): array {
		return array(
			'product_public_id' => self::public_id_arg( 'prd', true ),
			'license_key'       => array(
				'type'      => 'string',
				'required'  => false,
				'maxLength' => 512,
			),
			'activation_limit'  => array(
				'type'     => array( 'integer', 'null' ),
				'required' => false,
				'minimum'  => 0,
			),
			'expires_at'        => array(
				'type'     => array( 'string', 'null' ),
				'required' => false,
				'format'   => 'date-time',
			),
		);
	}

	/**
	 * Returns editable license fields.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function update_license_args(): array {
		return array(
			'lifecycle_status' => array(
				'type'     => 'string',
				'required' => false,
				'enum'     => array( 'available', 'assigned', 'suspended', 'revoked' ),
			),
			'activation_limit' => array(
				'type'     => array( 'integer', 'null' ),
				'required' => false,
				'minimum'  => 0,
			),
			'expires_at'       => array(
				'type'     => array( 'string', 'null' ),
				'required' => false,
				'format'   => 'date-time',
			),
		);
	}

	/**
	 * Returns a stable opaque public-ID route argument.
	 *
	 * @param string $prefix Identifier prefix.
	 * @param bool   $required Whether the field is required.
	 * @return array<string,mixed>
	 */
	public static function public_id_arg( string $prefix, bool $required ): array {
		return array(
			'type'     => 'string',
			'required' => $required,
			'pattern'  => '^' . preg_quote( $prefix, '/' ) . '_[A-Za-z0-9_-]{22}$',
		);
	}
}
