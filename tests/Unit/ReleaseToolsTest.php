<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\ReleaseTools\BuildPolicy;
use Dreamax\LicenseManager\ReleaseTools\DeterministicZip;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\ReleaseTools\Filesystem;
use Dreamax\LicenseManager\ReleaseTools\PerformanceFixture;
use Dreamax\LicenseManager\ReleaseTools\ReadmeHeaderValidator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname( __DIR__, 2 ) . '/scripts/lib/release-tools.php';

final class ReleaseToolsTest extends TestCase {
	public function test_disposable_environment_guard_accepts_only_explicit_test_targets(): void {
		DisposableEnvironmentGuard::assertSafe( array( 'marker' => 'DREAMAX_LM_DISPOSABLE_TEST', 'database' => 'dreamax_lm_test', 'site_url' => 'https://store.test' ) );
		self::assertTrue( true );

		foreach ( array(
			array( 'marker' => '', 'database' => 'dreamax_lm_test', 'site_url' => 'https://store.test' ),
			array( 'marker' => 'DREAMAX_LM_DISPOSABLE_TEST', 'database' => 'dreamax_lm_production', 'site_url' => 'https://store.test' ),
			array( 'marker' => 'DREAMAX_LM_DISPOSABLE_TEST', 'database' => 'dreamax_lm_test', 'site_url' => 'https://store.example.com' ),
		) as $unsafe ) {
			try {
				DisposableEnvironmentGuard::assertSafe( $unsafe );
				self::fail( 'Unsafe environment was accepted.' );
			} catch ( RuntimeException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_fixture_seed_is_required_and_reproducible(): void {
		$first = ( new PerformanceFixture( 'repeatable-seed' ) )->generate( 'smoke' );
		$second = ( new PerformanceFixture( 'repeatable-seed' ) )->generate( 'smoke' );
		$different = ( new PerformanceFixture( 'different-seed' ) )->generate( 'smoke' );
		self::assertSame( $first, $second );
		self::assertNotSame( $first['run_id'], $different['run_id'] );
		self::assertCount( 250, $first['licenses'] );
		self::assertCount( 1250, $first['events'] );
		self::assertArrayNotHasKey( 'license_key', $first['licenses'][0] );
		self::assertArrayNotHasKey( 'token', $first['events'][0] );
	}

	public function test_full_profile_targets_ten_thousand_licenses_and_fifty_thousand_events(): void {
		self::assertSame( 10000, PerformanceFixture::profile( 'full' )['licenses'] );
		self::assertSame( 50000, PerformanceFixture::profile( 'full' )['events'] );
	}

	public function test_fixture_cleanup_is_ownership_scoped_and_duplicate_runs_are_detected(): void {
		$fixture = new PerformanceFixture( 'cleanup-seed' );
		$owned = array( 'fixture_run_id' => $fixture->runId(), 'public_id' => 'owned' );
		$foreign = array( 'fixture_run_id' => 'perf_foreign', 'public_id' => 'foreign' );
		$result = $fixture->cleanupOwned( array( $owned, $foreign ) );
		self::assertSame( 1, $result['deleted'] );
		self::assertSame( array( $foreign ), $result['kept'] );

		$this->expectException( RuntimeException::class );
		$fixture->generate( 'smoke', array( $fixture->runId() ) );
	}

	public function test_readme_header_and_distribution_policy_are_consistent(): void {
		$root = dirname( __DIR__, 2 );
		$headers = ReadmeHeaderValidator::validate( $root );
		self::assertSame( '0.3.6', $headers['Version'] );
		self::assertSame( 'dreamax-license-manager', $headers['Text Domain'] );
		self::assertSame( '7.1', $headers['Tested up to'] );
		self::assertSame( '11.0.1', $headers['WC tested up to'] );
		$files = BuildPolicy::distributionFiles( $root );
		self::assertContains( 'dreamax-license-manager.php', $files );
		self::assertContains( 'docs/BUILDING.md', $files );
		self::assertNotContains( 'composer.lock', $files );
		self::assertSame( array(), array_filter( $files, static fn(string $file): bool => str_starts_with( $file, 'tests/' ) || str_starts_with( $file, 'vendor/' ) ) );
	}

	public function test_prohibited_build_and_private_paths_are_rejected(): void {
		foreach ( array( '.env', 'php.ini', 'credentials.json', 'private.key', 'tests/fixture.php', 'build/release.zip', 'C:/private/file.php', '../escape.php' ) as $path ) {
			try {
				BuildPolicy::assertAllowed( $path );
				self::fail( 'Prohibited path was accepted: ' . $path );
			} catch ( RuntimeException ) {
				self::assertTrue( true );
			}
		}
	}

	public function test_zip_ordering_metadata_and_hash_are_deterministic(): void {
		$base = sys_get_temp_dir() . '/dreamax-lm-' . bin2hex( random_bytes( 6 ) );
		$source = $base . '/source';
		mkdir( $source . '/src', 0777, true );
		file_put_contents( $source . '/dreamax-license-manager.php', "<?php\n" );
		file_put_contents( $source . '/src/class-example.php', "<?php\n" );
		$files = array( 'dreamax-license-manager.php', 'src/class-example.php' );
		$first = $base . '/first.zip';
		$second = $base . '/second.zip';
		try {
			$inventory1 = DeterministicZip::create( $source, $files, $first, 1787529600 );
			$inventory2 = DeterministicZip::create( $source, $files, $second, 1787529600 );
			self::assertSame( hash_file( 'sha256', $first ), hash_file( 'sha256', $second ) );
			self::assertSame( $inventory1, $inventory2 );
			self::assertSame( array( 'dreamax-license-manager/dreamax-license-manager.php', 'dreamax-license-manager/src/class-example.php' ), DeterministicZip::paths( $first ) );
		} finally {
			Filesystem::removeTree( $base, sys_get_temp_dir() );
		}
	}

	public function test_manifest_schema_and_two_build_contract_are_complete(): void {
		$root = dirname( __DIR__, 2 );
		$schema = json_decode( (string) file_get_contents( $root . '/docs/release-manifest.schema.json' ), true, 512, JSON_THROW_ON_ERROR );
		$required = array( 'plugin_name', 'plugin_version', 'artifact_filename', 'source_git_commit', 'source_state', 'build_command', 'build_profile', 'distribution_zip_sha256', 'dependency_lock_sha256', 'dependency_license_inventory_reference', 'tested_versions', 'build_test_utc', 'release_gate_evidence_reference', 'build_tool_versions', 'reproducibility_result' );
		foreach ( $required as $field ) {
			self::assertContains( $field, $schema['required'] );
		}
		$builder = (string) file_get_contents( $root . '/scripts/build-release.php' );
		self::assertStringContainsString( '$first = build_once', $builder );
		self::assertStringContainsString( '$second = build_once', $builder );
		self::assertStringContainsString( "'sha256_match' => true", $builder );
		self::assertStringContainsString( "'inventory_match' => true", $builder );
		self::assertStringContainsString( "'source_state' => 'clean_recorded_commit'", $builder );
		self::assertStringContainsString( "'wordpress_tested' => \$headers['Tested up to']", $builder );
		self::assertStringContainsString( "'woocommerce_tested' => \$headers['WC tested up to']", $builder );
		self::assertStringContainsString( "'wordpress' => array(\$first['wordpress_tested'])", $builder );
		self::assertStringContainsString( "'woocommerce' => array(\$first['woocommerce_tested'])", $builder );
		self::assertStringNotContainsString( 'Manual release gates remain', $builder );
	}

	public function test_dependency_license_inventory_covers_every_locked_package(): void {
		$root = dirname( __DIR__, 2 );
		$lock = json_decode( (string) file_get_contents( $root . '/composer.lock' ), true, 512, JSON_THROW_ON_ERROR );
		$inventory = (string) file_get_contents( $root . '/docs/DEPENDENCIES.md' );
		$packages = array_merge( $lock['packages'] ?? array(), $lock['packages-dev'] ?? array() );
		foreach ( $packages as $package ) {
			self::assertStringContainsString( '`' . $package['name'] . '`', $inventory );
			foreach ( $package['license'] ?? array() as $license ) {
				self::assertStringContainsString( $license, $inventory );
			}
		}
	}

	public function test_administration_and_export_queries_are_bounded(): void {
		$root = dirname( __DIR__, 2 );
		$admin = (string) file_get_contents( $root . '/src/Admin/class-admin.php' );
		$export = (string) file_get_contents( $root . '/src/ImportExport/class-csvcontroller.php' );
		self::assertStringContainsString( 'LIMIT 50 OFFSET %d', $admin );
		self::assertStringContainsString( 'WHERE id>%d ORDER BY id LIMIT %d', $export );
		self::assertStringContainsString( '$batch_size = 250;', $export );
		self::assertStringNotContainsString( 'SELECT * FROM {$wpdb->prefix}dreamax_lm_licenses ORDER BY id"', $export );
	}
}
