<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EnumerationProxyAbuseSourceContractTest extends TestCase {
	private string $source;
	private string $transport;
	private string $routes;
	private string $verifier;

	protected function setUp(): void {
		$root            = dirname( __DIR__, 2 );
		$this->source    = (string) file_get_contents( $root . '/src/Api/class-sourceaddress.php' );
		$this->transport = (string) file_get_contents( $root . '/src/Api/class-transportguard.php' );
		$this->routes    = (string) file_get_contents( $root . '/src/Api/class-publicroutes.php' );
		$this->verifier  = (string) file_get_contents( $root . '/scripts/verify-live-enumeration-proxy-abuse.php' );
	}

	public function test_forwarded_headers_require_an_explicitly_trusted_direct_proxy(): void {
		self::assertStringContainsString( "in_array( \$remote, \$trusted, true )", $this->source );
		self::assertStringContainsString( '$this->source->remote_is_trusted_proxy()', $this->transport );
		self::assertStringContainsString( "'https' === strtolower", $this->transport );
	}

	public function test_public_routes_apply_layered_failure_limits_and_empty_error_data(): void {
		self::assertStringContainsString( "'breaker|' . \$operation", $this->routes );
		self::assertStringContainsString( "'aggregate|' . \$network", $this->routes );
		self::assertStringContainsString( "'failure|' . \$this->source->network()", $this->routes );
		self::assertStringContainsString( "'rate_limited'", $this->routes );
	}

	public function test_live_verifier_covers_http_spoof_timing_rate_limit_and_restoration(): void {
		foreach ( array( 'http_spoof_rejected', 'http_trusted_proxy_allowed', 'timing_shape_bounded', 'failure_rate_limit_enforced', 'rate_before' ) as $contract ) {
			self::assertStringContainsString( $contract, $this->verifier );
		}
		self::assertStringContainsString( 'DisposableEnvironmentGuard::assertSafe(', $this->verifier );
	}
}
