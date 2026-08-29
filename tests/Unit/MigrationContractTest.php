<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Database\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MigrationContractTest extends TestCase {
	public function test_supported_versions_are_complete_and_sequential(): void {
		$versions = Schema::supported_versions();
		self::assertSame( array( '1', '2', '3' ), $versions );
		self::assertSame( Schema::VERSION, end( $versions ) );
	}

	public function test_historical_schema_snapshots_are_additive(): void {
		$schema = new Schema();
		$v1 = implode( "\n", $schema->statements( '1', 'wp_dreamax_lm_', 'DEFAULT CHARSET=utf8mb4' ) );
		$v2 = implode( "\n", $schema->statements( '2', 'wp_dreamax_lm_', 'DEFAULT CHARSET=utf8mb4' ) );
		$v3 = implode( "\n", $schema->statements( '3', 'wp_dreamax_lm_', 'DEFAULT CHARSET=utf8mb4' ) );

		self::assertSame( 7, substr_count( $v1, 'CREATE TABLE' ) );
		self::assertSame( 9, substr_count( $v2, 'CREATE TABLE' ) );
		self::assertSame( 9, substr_count( $v3, 'CREATE TABLE' ) );
		self::assertStringNotContainsString( 'guest_claims', $v1 );
		self::assertStringContainsString( 'guest_claims', $v2 );
		self::assertStringNotContainsString( 'secret_version', $v2 );
		self::assertStringContainsString( 'secret_version', $v3 );
		self::assertStringContainsString( 'rotated_at', $v3 );
		self::assertStringContainsString( 'revoked_at', $v3 );
	}

	public function test_every_snapshot_preserves_core_and_sensitive_data_columns(): void {
		$schema = new Schema();
		foreach ( Schema::supported_versions() as $version ) {
			$sql = implode( "\n", $schema->statements( $version, 'wp_7_dreamax_lm_' ) );
			foreach ( array( 'licenses', 'activations', 'events', 'api_credentials', 'public_id', 'key_ciphertext', 'key_fingerprint', 'encryption_key_version', 'schema_version', 'metadata' ) as $required ) {
				self::assertStringContainsString( $required, $sql, 'Missing ' . $required . ' from schema v' . $version );
			}
			self::assertStringContainsString( 'ENGINE=InnoDB', $sql );
			self::assertStringContainsString( 'wp_7_dreamax_lm_', $sql );
		}
	}

	public function test_current_snapshot_contains_required_unique_constraints_and_indexes(): void {
		$sql = implode( "\n", ( new Schema() )->statements( Schema::VERSION, 'wp_dreamax_lm_' ) );
		foreach ( array( 'UNIQUE KEY public_id', 'UNIQUE KEY key_fingerprint', 'UNIQUE KEY order_slot', 'UNIQUE KEY license_instance', 'UNIQUE KEY scope_hash', 'UNIQUE KEY active_order', 'UNIQUE KEY token_hash', 'UNIQUE KEY claim_id', 'KEY customer_status', 'KEY product_status', 'KEY event_time', 'KEY status_expiry' ) as $index ) {
			self::assertStringContainsString( $index, $sql );
		}
	}

	public function test_installer_checkpoints_each_completed_version_and_refuses_downgrade_rewrite(): void {
		$installer = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Database/class-installer.php' );
		self::assertStringContainsString( 'foreach ( Schema::supported_versions() as $version )', $installer );
		self::assertStringContainsString( '$schema->install_version( $version );', $installer );
		self::assertStringContainsString( "update_option( 'dreamax_lm_schema_version', \$version, false );", $installer );
		self::assertStringContainsString( "version_compare( \$installed, Schema::VERSION, '>=' )", $installer );
		self::assertLessThan( strpos( $installer, "update_option( 'dreamax_lm_schema_version'" ), strpos( $installer, '$schema->install_version' ) );
	}

	public function test_invalid_or_non_site_local_schema_requests_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		( new Schema() )->statements( '4', 'wp_dreamax_lm_' );
	}

	public function test_schema_history_fixture_matches_the_executable_plan(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/schema-history.json' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( Schema::supported_versions(), array_column( $fixture['versions'], 'version' ) );
		foreach ( $fixture['versions'] as $entry ) {
			$statements = ( new Schema() )->statements( $entry['version'], 'wp_dreamax_lm_' );
			self::assertSame( $entry['table_count'], count( $statements ) );
			foreach ( $entry['introduced'] as $name ) {
				self::assertStringContainsString( $name, implode( "\n", $statements ) );
			}
		}
	}
}
