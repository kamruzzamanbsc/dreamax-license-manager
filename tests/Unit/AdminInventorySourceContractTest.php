<?php
/**
 * Verifies bounded inventory sorting, references, and warning contracts.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminInventorySourceContractTest extends TestCase {
	public function test_sorting_is_allowlisted_and_preserves_read_only_filters(): void {
		$admin = $this->read( 'src/Admin/class-admin.php' );
		foreach ( array( 'created', 'license', 'product', 'status', 'activations', 'expiry' ) as $key ) {
			self::assertStringContainsString( "'{$key}'", $admin );
		}
		self::assertStringContainsString( '$sort_columns[ $orderby ]', $admin );
		self::assertStringContainsString( 'ORDER BY {$order_clause} LIMIT 50 OFFSET %d', $admin );
		self::assertMatchesRegularExpression( "/'product'\\s*=>\\s*\\\$valid_product/", $admin );
		self::assertStringContainsString( 'aria-sort=', $admin );
	}

	public function test_references_are_authorized_and_private_data_stays_out_of_inventory(): void {
		$admin      = $this->read( 'src/Admin/class-admin.php' );
		$activation = $this->read( 'src/Admin/class-activationadmin.php' );
		foreach ( array( "current_user_can( 'edit_post'", "current_user_can( 'edit_user'", "current_user_can( 'edit_shop_order'" ) as $check ) {
			self::assertStringContainsString( $check, $admin );
		}
		self::assertStringContainsString( 'get_edit_order_url()', $admin );
		self::assertStringContainsString( 'dreamax-license-manager-activations', $admin );
		self::assertStringContainsString( "current_user_can( 'edit_post'", $activation );
		self::assertStringContainsString( "current_user_can( 'edit_user'", $activation );
		self::assertStringNotContainsString( 'key_ciphertext', $activation );
	}

	public function test_warning_queries_are_bounded_and_exclude_revoked_expiry(): void {
		$insights = $this->read( 'src/Admin/class-inventoryinsights.php' );
		$admin    = $this->read( 'src/Admin/class-admin.php' );
		self::assertStringContainsString( "lifecycle_status<>'revoked'", $insights );
		self::assertStringContainsString( "'posts_per_page' => \$product_limit", $insights );
		self::assertStringContainsString( "'no_found_rows'  => true", $insights );
		self::assertStringContainsString( "'cache_results'  => false", $insights );
		self::assertStringContainsString( 'array_slice( $low, 0, 20 )', $insights );
		self::assertStringContainsString( 'dreamax-lm-inventory-insights', $admin );
		self::assertStringContainsString( 'All activation slots in use', $admin );
	}

	private function read( string $relative_path ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative_path );
		self::assertIsString( $contents );
		return $contents;
	}
}
