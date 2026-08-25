<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CredentialLifecycleSourceContractTest extends TestCase {
	private string $service;
	private string $schema;
	private string $routes;
	private string $transport;
	private string $admin;

	protected function setUp(): void {
		$root            = dirname( __DIR__, 2 );
		$this->service   = (string) file_get_contents( $root . '/src/Credentials/class-credentialservice.php' );
		$this->schema    = (string) file_get_contents( $root . '/src/Database/class-schema.php' );
		$this->routes    = (string) file_get_contents( $root . '/src/Api/class-privilegedroutes.php' );
		$this->transport = (string) file_get_contents( $root . '/src/Api/class-transportguard.php' );
		$this->admin     = (string) file_get_contents( $root . '/src/Admin/class-admin.php' );
	}

	public function test_additive_schema_tracks_rotation_and_revocation_without_plaintext(): void {
		self::assertStringContainsString( "VERSION = '3'", $this->schema );
		self::assertStringContainsString( 'secret_hash varchar(255) NOT NULL', $this->schema );
		self::assertStringContainsString( 'secret_version int(10) unsigned NOT NULL DEFAULT 1', $this->schema );
		self::assertStringContainsString( 'rotated_at datetime NULL', $this->schema );
		self::assertStringContainsString( 'revoked_at datetime NULL', $this->schema );
		self::assertStringNotContainsString( 'plaintext_secret', $this->schema );
	}

	public function test_rotation_is_transactional_version_guarded_and_zero_overlap(): void {
		self::assertStringContainsString( 'SELECT GET_LOCK', $this->service );
		self::assertStringContainsString( 'FOR UPDATE', $this->service );
		self::assertStringContainsString( '\'secret_version\' => $expected_version', $this->service );
		self::assertStringContainsString( '\'secret_hash\'    => $hash', $this->service );
		self::assertStringContainsString( 'credential_rotation_succeeded', $this->service );
		self::assertStringContainsString( 'credential_rotation_failed', $this->service );
		self::assertStringNotContainsString( 'grace', strtolower( $this->service ) );
	}

	public function test_usage_rotation_and_revocation_share_one_site_local_lock(): void {
		self::assertGreaterThanOrEqual( 3, substr_count( $this->service, '$this->with_lock(' ) );
		self::assertStringContainsString( 'get_current_blog_id()', $this->service );
		self::assertStringContainsString( '$wpdb->prefix', $this->service );
		self::assertStringContainsString( 'SELECT RELEASE_LOCK', $this->service );
	}

	public function test_revocation_is_immediate_irreversible_and_idempotent(): void {
		self::assertStringContainsString( "if ( ! \$this->policy->revocation_changes", $this->service );
		self::assertStringContainsString( "'status'         => 'revoked'", $this->service );
		self::assertStringContainsString( "'secret_hash'    => \$this->tokens->hash( \$replacement_secret )", $this->service );
		self::assertStringContainsString( "'revoked_at'     => \$now", $this->service );
		self::assertStringContainsString( 'credential_revoked', $this->service );
	}

	public function test_authentication_precedes_exact_scope_and_business_processing(): void {
		$authenticate = strpos( $this->routes, '->authenticate(' );
		$authorized   = strpos( $this->routes, '->authorized_use(' );
		self::assertNotFalse( $authenticate );
		self::assertNotFalse( $authorized );
		self::assertLessThan( $authorized, $authenticate );
		self::assertStringContainsString( 'credential_insufficient_scope', $this->service );
		self::assertStringContainsString( 'credential_authentication_used', $this->service );
	}

	public function test_per_credential_limit_and_coalesced_last_used_write_are_present(): void {
		self::assertStringContainsString( 'privileged-credential|', $this->service );
		self::assertStringContainsString( 'LAST_USED_INTERVAL', $this->service );
		self::assertStringContainsString( 'last_used_at IS NULL OR last_used_at<=%s', $this->service );
	}

	public function test_query_and_body_credentials_are_rejected_before_route_callback(): void {
		self::assertStringContainsString( 'assert_no_body_credentials', $this->transport );
		self::assertStringContainsString( 'body_contains_credential', $this->transport );
		self::assertStringContainsString( '$request->get_body()', $this->routes );
		self::assertStringContainsString( 'assert_no_query_secrets', $this->transport );
	}

	public function test_https_proxy_and_loopback_policy_remains_fail_closed(): void {
		self::assertStringContainsString( 'remote_is_trusted_proxy()', $this->transport );
		self::assertStringContainsString( "HTTP_X_FORWARDED_PROTO", $this->transport );
		self::assertStringContainsString( "array( '127.0.0.1', '::1' )", $this->transport );
		self::assertStringContainsString( 'dreamax_lm_allow_http_local', $this->transport );
	}

	public function test_administration_is_nonce_and_dedicated_capability_protected(): void {
		$capabilities = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Support/class-capabilities.php' );
		self::assertStringContainsString( 'Capabilities::CREDENTIALS', $this->admin );
		self::assertStringContainsString( 'check_admin_referer', $this->admin );
		self::assertStringContainsString( 'dreamax_lm_rotate_credential', $this->admin );
		self::assertStringContainsString( 'dreamax_lm_revoke_credential', $this->admin );
		self::assertStringNotContainsString( '$shop_manager->add_cap( self::CREDENTIALS', $capabilities );
	}

	public function test_safe_inventory_and_audit_never_select_or_emit_verifiers(): void {
		self::assertStringContainsString( 'SELECT public_id,name,visible_prefix,scopes,status,expires_at,last_used_at,secret_version,created_at,updated_at,rotated_at,revoked_at', $this->service );
		self::assertStringContainsString( "unset( \$row['secret_hash'] )", $this->service );
		self::assertStringNotContainsString( "'secret_hash' => \$hash", $this->service );
		self::assertStringNotContainsString( 'error_log', $this->service );
	}

	public function test_permanent_uninstall_and_frozen_v1_namespace_are_preserved(): void {
		$root      = dirname( __DIR__, 2 );
		$uninstall = (string) file_get_contents( $root . '/uninstall.php' );
		$openapi   = (string) file_get_contents( $root . '/docs/openapi-v1.yaml' );
		self::assertStringContainsString( "'api_credentials'", $uninstall );
		self::assertStringContainsString( 'dreamax-license-manager/v1', $openapi );
		self::assertStringContainsString( 'dlm_v1_', $openapi );
	}
}
