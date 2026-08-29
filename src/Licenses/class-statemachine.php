<?php
/**
 * Defines the StateMachine class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

/**
 * Handles State machine operations.
 */
final class StateMachine {
	/**
	 * Allowed lifecycle transitions.
	 *
	 * @var array<string,list<string>>
	 */
	private const TRANSITIONS = array(
		'available' => array( 'assigned', 'suspended', 'revoked' ),
		'assigned'  => array( 'suspended', 'revoked' ),
		'suspended' => array( 'available', 'assigned', 'revoked' ),
		'revoked'   => array(),
	);

	/**
	 * Handles the can transition operation.
	 *
	 * @param string $from From value.
	 * @param string $to To value.
	 */
	public function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/**
	 * Handles the states operation.
	 *
	 * @return list<string>
	 */
	public function states(): array {
		return array_keys( self::TRANSITIONS );
	}
}
