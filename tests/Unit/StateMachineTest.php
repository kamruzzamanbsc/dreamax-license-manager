<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Licenses\StateMachine;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase {
	public function test_revocation_is_terminal(): void {
		$machine = new StateMachine();
		foreach ( $machine->states() as $target ) {
			self::assertFalse( $machine->can_transition( 'revoked', $target ) );
		}
	}

	public function test_suspend_restore_paths(): void {
		$machine = new StateMachine();
		self::assertTrue( $machine->can_transition( 'assigned', 'suspended' ) );
		self::assertTrue( $machine->can_transition( 'suspended', 'assigned' ) );
		self::assertFalse( $machine->can_transition( 'assigned', 'available' ) );
	}

	/** @dataProvider transitionMatrix */
	public function test_complete_transition_matrix( string $from, string $to, bool $allowed ): void {
		self::assertSame( $allowed, ( new StateMachine() )->can_transition( $from, $to ) );
	}

	/** @return iterable<string,array{string,string,bool}> */
	public static function transitionMatrix(): iterable {
		$allowed = array(
			'available' => array( 'assigned', 'suspended', 'revoked' ),
			'assigned'  => array( 'suspended', 'revoked' ),
			'suspended' => array( 'available', 'assigned', 'revoked' ),
			'revoked'   => array(),
		);
		foreach ( array_keys( $allowed ) as $from ) {
			foreach ( array_keys( $allowed ) as $to ) {
				yield $from . '-to-' . $to => array( $from, $to, in_array( $to, $allowed[ $from ], true ) );
			}
		}
	}
}
