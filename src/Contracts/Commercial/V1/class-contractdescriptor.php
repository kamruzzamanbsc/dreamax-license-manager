<?php
/**
 * Defines the ContractDescriptor class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Publishes non-sensitive compatibility state.
 */
final class ContractDescriptor extends ContractData {
	/**
	 * Returns the allowlisted fields.
	 *
	 * @return array<string,bool>
	 */
	protected static function fields(): array {
		return array(
			'contract_version' => true,
			'free_version'     => true,
			'schema_version'   => true,
			'capabilities'     => true,
			'storage_ready'    => true,
			'encryption_ready' => true,
		);
	}
}
