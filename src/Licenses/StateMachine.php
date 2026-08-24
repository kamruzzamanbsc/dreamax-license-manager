<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

final class StateMachine {
	/** @var array<string,list<string>> */
	private const TRANSITIONS = array(
		'available' => array( 'assigned', 'suspended', 'revoked' ),
		'assigned'  => array( 'suspended', 'revoked' ),
		'suspended' => array( 'available', 'assigned', 'revoked' ),
		'revoked'   => array(),
	);

	public function can_transition( string $from, string $to ): bool {
		return isset( self::TRANSITIONS[ $from ] ) && in_array( $to, self::TRANSITIONS[ $from ], true );
	}

	/** @return list<string> */
	public function states(): array {
		return array_keys( self::TRANSITIONS );
	}
}
