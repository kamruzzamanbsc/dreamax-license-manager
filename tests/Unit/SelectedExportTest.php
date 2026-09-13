<?php
/**
 * Tests selected-license export's fixed SQL and masking boundary.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\ImportExport\CsvController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SelectedExportTest extends TestCase {
	public function test_selection_adds_only_placeholders_after_existing_filters(): void {
		$where = ( new ReflectionMethod( CsvController::class, 'export_where' ) )->invoke(
			new CsvController(),
			array(
				'status'   => 'assigned',
				'product'  => '',
				'customer' => 0,
				'order'    => 0,
				'expiry'   => '',
			),
			'licenses',
			array( 'lic_AAAAAAAAAAAAAAAAAAAAAA', 'lic_BBBBBBBBBBBBBBBBBBBBBB' )
		);
		self::assertSame( ' AND l.lifecycle_status=%s AND l.public_id IN (%s,%s)', $where['sql'] );
		self::assertSame( array( 'assigned', 'lic_AAAAAAAAAAAAAAAAAAAAAA', 'lic_BBBBBBBBBBBBBBBBBBBBBB' ), $where['args'] );
	}

	public function test_selected_route_forces_masking_and_audits_before_download(): void {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/ImportExport/class-csvcontroller.php' );
		self::assertIsString( $source );
		self::assertStringContainsString( "check_admin_referer( 'dreamax_lm_bulk_lifecycle' )", $source );
		self::assertStringContainsString( "'licenses',\n\t\t\tfalse,", $source );
		self::assertStringContainsString( 'wp_delete_file( $path );', $source );
		self::assertStringContainsString( 'The export could not be audited; no download was created.', $source );
	}
}
