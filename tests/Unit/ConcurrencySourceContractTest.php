<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConcurrencySourceContractTest extends TestCase {
	private string $license_service;
	private string $activation_service;
	private string $schema;
	private string $coordinator;
	private string $worker;

	protected function setUp(): void {
		$root                     = dirname( __DIR__, 2 );
		$this->license_service    = (string) file_get_contents( $root . '/src/Licenses/class-licenseservice.php' );
		$this->activation_service = (string) file_get_contents( $root . '/src/Activations/class-activationservice.php' );
		$this->schema             = (string) file_get_contents( $root . '/src/Database/class-schema.php' );
		$this->coordinator        = (string) file_get_contents( $root . '/scripts/verify-live-concurrency.php' );
		$this->worker             = (string) file_get_contents( $root . '/scripts/lib/concurrency-worker.php' );
	}

	public function test_pool_assignment_locks_the_last_available_key(): void {
		self::assertStringContainsString( "lifecycle_status = 'available' AND order_id IS NULL ORDER BY id LIMIT 1 FOR UPDATE", $this->license_service );
		self::assertStringContainsString( "'lifecycle_status' => 'available'", $this->license_service );
		self::assertStringContainsString( 'The imported key pool is empty.', $this->license_service );
	}

	public function test_activation_serializes_on_the_license_and_has_unique_instance_storage(): void {
		self::assertStringContainsString( '$this->licenses->lock_by_id', $this->activation_service );
		self::assertStringContainsString( "WHERE license_id = %d AND status = 'active'", $this->activation_service );
		self::assertStringContainsString( 'UNIQUE KEY license_instance (license_id,instance_fingerprint)', $this->schema );
	}

	public function test_live_harness_proves_in_flight_workers_and_exact_cleanup(): void {
		foreach ( array( 'both_in_flight', "'0:1', '1:0'", 'activation_limit_reached', 'array( false, true )', 'plugin_aggregates_unchanged', 'owned_fixture_rows_remaining' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->coordinator );
		}
		self::assertStringContainsString( "file_get_contents( 'php://stdin' )", $this->worker );
		self::assertStringNotContainsString( "'--key='", $this->coordinator );
		self::assertStringContainsString( 'is_resource( $worker[\'process\'] )', $this->coordinator );
		self::assertStringContainsString( 'sanitized substage:', $this->coordinator );
	}
}
