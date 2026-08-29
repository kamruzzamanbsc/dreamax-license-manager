<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MultisiteLifecycleSourceContractTest extends TestCase {
	private string $installer;
	private string $plugin;
	private string $uninstall;

	protected function setUp(): void {
		$root            = dirname( __DIR__, 2 );
		$this->installer = (string) file_get_contents( $root . '/src/Database/class-installer.php' );
		$this->plugin    = (string) file_get_contents( $root . '/src/class-plugin.php' );
		$this->uninstall = (string) file_get_contents( $root . '/uninstall.php' );
	}

	public function test_network_activation_and_deactivation_visit_each_site(): void {
		self::assertGreaterThanOrEqual( 2, substr_count( $this->installer, "'fields' => 'ids'" ) );
		self::assertStringContainsString( 'self::install_site();', $this->installer );
		self::assertStringContainsString( 'self::deactivate_site();', $this->installer );
		self::assertStringContainsString( 'wp_clear_scheduled_hook', $this->installer );
	}

	public function test_network_active_plugin_initializes_future_sites(): void {
		self::assertStringContainsString( "get_site_option( 'active_sitewide_plugins'", $this->plugin );
		self::assertStringContainsString( "add_action( 'wp_initialize_site'", $this->plugin );
		self::assertStringContainsString( "array( Installer::class, 'initialize_site' )", $this->plugin );
		self::assertStringContainsString( 'switch_to_blog( $site_id );', $this->installer );
		self::assertStringContainsString( 'finally', $this->installer );
		self::assertStringContainsString( 'restore_current_blog();', $this->installer );
	}

	public function test_permanent_uninstall_remains_current_site_scoped(): void {
		self::assertStringContainsString( "array( true, 1, '1' )", $this->uninstall );
		self::assertStringContainsString( '$wpdb->prefix . \'dreamax_lm_\'', $this->uninstall );
		self::assertStringNotContainsString( 'get_sites(', $this->uninstall );
		self::assertStringNotContainsString( 'switch_to_blog(', $this->uninstall );
	}
}
