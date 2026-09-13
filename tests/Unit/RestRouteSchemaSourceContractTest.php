<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Api\RouteSchema;
use PHPUnit\Framework\TestCase;

final class RestRouteSchemaSourceContractTest extends TestCase {
	public function test_response_envelope_publishes_all_frozen_fields(): void {
		$schema = RouteSchema::envelope();
		self::assertSame( array( 'success', 'code', 'message', 'request_id', 'timestamp', 'data' ), $schema['required'] );
		self::assertSame( '^req_[A-Za-z0-9_-]{22}$', $schema['properties']['request_id']['pattern'] );
	}

	public function test_public_mutation_schema_requires_bounded_installation_identity(): void {
		$args = RouteSchema::public_operation_args( true );
		self::assertTrue( $args['license_key']['required'] );
		self::assertSame( 512, $args['license_key']['maxLength'] );
		self::assertTrue( $args['product_public_id']['required'] );
		self::assertTrue( $args['instance_id']['required'] );
		self::assertSame( 128, $args['instance_id']['maxLength'] );
	}

	public function test_all_route_groups_publish_schema_callbacks_and_request_args(): void {
		$root       = dirname( __DIR__, 2 );
		$public     = (string) file_get_contents( $root . '/src/Api/class-publicroutes.php' );
		$privileged = (string) file_get_contents( $root . '/src/Api/class-privilegedroutes.php' );

		self::assertStringContainsString( "'schema' => array( RouteSchema::class, 'envelope' )", $public );
		self::assertStringContainsString( 'RouteSchema::public_operation_args(', $public );
		self::assertSame( 2, substr_count( $public, "'schema' => array( RouteSchema::class, 'envelope' )" ) );
		self::assertSame( 5, substr_count( $privileged, "'schema' => array( RouteSchema::class, 'envelope' )" ) );
		self::assertStringContainsString( 'RouteSchema::create_license_args()', $privileged );
		self::assertStringContainsString( 'RouteSchema::update_license_args()', $privileged );
	}
}
