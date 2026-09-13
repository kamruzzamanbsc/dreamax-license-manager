<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminLicenseExperienceSourceContractTest extends TestCase {
	public function test_inventory_uses_scoped_assets_and_accessible_controls(): void {
		$source = $this->read( 'src/Admin/class-admin.php' );

		self::assertStringContainsString( "add_action( 'admin_enqueue_scripts'", $source );
		self::assertStringContainsString( "'assets/css/admin.css'", $source );
		self::assertStringContainsString( "'assets/js/admin.js'", $source );
		self::assertStringContainsString( 'License summary', $source );
		self::assertStringContainsString( 'Lifecycle state', $source );
		self::assertStringContainsString( 'dreamax-lm-filter-button', $source );
		self::assertStringContainsString( 'data-dlm-selection-status', $source );
		self::assertStringContainsString( '<progress', $source );
		self::assertStringContainsString( 'Clear all filters', $source );
		self::assertStringContainsString( 'License created successfully.', $source );
	}

	public function test_inventory_presentation_distinguishes_assignment_from_delivery(): void {
		$source = $this->read( 'src/Admin/class-admin.php' );

		self::assertStringContainsString( "? 'delivered' : 'assigned'", $source );
		self::assertStringContainsString( "'assigned'  => __( 'Assigned'", $source );
		self::assertStringContainsString( "'delivered' => __( 'Delivered'", $source );
		self::assertStringContainsString( "__( 'Not linked'", $source );
		self::assertStringContainsString( "__( 'Created manually'", $source );
	}

	public function test_styles_are_scoped_responsive_and_keyboard_visible(): void {
		$styles = $this->read( 'assets/css/admin.css' );

		self::assertStringContainsString( '.dreamax-lm-admin', $styles );
		self::assertStringContainsString( ':focus-visible', $styles );
		self::assertStringContainsString( '@media (max-width:', $styles );
		self::assertStringContainsString( '@media (prefers-reduced-motion: reduce)', $styles );
	}

	public function test_bulk_interaction_requires_complete_explicit_input(): void {
		$script = $this->read( 'assets/js/admin.js' );

		self::assertStringContainsString( 'selectAll.indeterminate', $script );
		self::assertStringContainsString( "operation.value === 'extend'", $script );
		self::assertStringContainsString( 'reason.value.trim().length >= 3', $script );
		self::assertStringContainsString( 'operation.disabled = !hasSelection', $script );
		self::assertStringContainsString( 'reason.disabled = !hasSelection', $script );
		self::assertStringContainsString( 'confirmation.disabled = !shown', $script );
		self::assertStringContainsString( 'renderImpact(preview, records, operation.value', $script );
		self::assertStringContainsString( 'confirmation.checked = false', $script );
		self::assertStringContainsString( '!confirmation.checked', $script );
		self::assertStringContainsString( 'submit.disabled', $script );
	}

	public function test_add_license_experience_defaults_to_secure_generation(): void {
		$source = $this->read( 'src/Admin/class-admin.php' );
		$script = $this->read( 'assets/js/admin.js' );

		self::assertStringContainsString( 'data-dlm-add-license', $source );
		self::assertStringContainsString( 'Generate securely', $source );
		self::assertStringContainsString( 'value="generated" checked', $source );
		self::assertStringContainsString( 'type="password"', $source );
		self::assertStringContainsString( 'autocomplete="new-password"', $source );
		self::assertStringContainsString( 'The key is encrypted before it is stored.', $source );
		self::assertStringContainsString( "keyInput.disabled = !importing", $script );
		self::assertStringContainsString( "keyInput.required = importing", $script );
		self::assertStringContainsString( "keyInput.value = ''", $script );
		self::assertStringContainsString( "toggle.setAttribute('aria-pressed'", $script );
		self::assertStringContainsString( "toggleIcon.classList.toggle('dashicons-hidden'", $script );
	}

	public function test_license_details_use_guarded_premium_operation_panels(): void {
		$source = $this->read( 'src/Admin/class-admin.php' );
		$script = $this->read( 'assets/js/admin.js' );

		self::assertStringContainsString( 'dreamax-lm-license-overview', $source );
		self::assertStringContainsString( 'data-dlm-lifecycle-form', $source );
		self::assertStringContainsString( 'data-dlm-reassign-form', $source );
		self::assertStringContainsString( 'data-dlm-impact-preview', $source );
		self::assertStringContainsString( 'Immutable operational history', $source );
		self::assertStringContainsString( "_n( '%d installation', '%d installations'", $source );
		self::assertStringContainsString( 'render_event_details', $source );
		self::assertStringContainsString( "operation.value === 'extend'", $script );
		self::assertStringContainsString( '!form.checkValidity()', $script );
		self::assertStringContainsString( "data.expiry.replace(' ', 'T') + 'Z'", $script );
	}

	public function test_activity_page_summarizes_sanitized_events(): void {
		$source = $this->read( 'src/Admin/class-admin.php' );

		self::assertStringContainsString( 'dreamax-lm-activity-page', $source );
		self::assertStringContainsString( 'Activity summary', $source );
		self::assertStringContainsString( 'Event timeline', $source );
		self::assertStringContainsString( 'Up to 200 sanitized events', $source );
		self::assertStringContainsString( "unset( \$metadata['public_id'], \$metadata['license_public_id'] )", $source );
	}

	public function test_transfer_page_is_preview_first_and_warns_before_sensitive_export(): void {
		$source = $this->read( 'src/Admin/class-admin.php' );
		$script = $this->read( 'assets/js/admin.js' );

		self::assertStringContainsString( 'dreamax-lm-transfer-page', $source );
		self::assertStringContainsString( 'data-dlm-import-form', $source );
		self::assertStringContainsString( 'name="dry_run" value="1" checked', $source );
		self::assertStringContainsString( 'data-dlm-import-confirm', $source );
		self::assertStringContainsString( 'data-dlm-import-submit', $source );
		self::assertStringContainsString( 'Sensitive and audited.', $source );
		self::assertStringContainsString( 'data-dlm-export-confirm', $source );
		self::assertStringContainsString( 'data-dlm-file-name', $source );
		self::assertStringContainsString( 'input.files[0].name', $script );
		self::assertStringContainsString( 'submit.disabled = !input.files.length || (committing && !confirmation.checked)', $script );
		self::assertStringContainsString( "strings.commitImport || 'Import licenses'", $script );
		self::assertStringContainsString( 'submit.disabled = sensitive && !confirmation.checked', $script );
	}

	public function test_system_status_summarizes_operational_health(): void {
		$source      = $this->read( 'src/Admin/class-admin.php' );
		$diagnostics = $this->read( 'src/Support/class-diagnostics.php' );

		self::assertStringContainsString( 'dreamax-lm-status-page', $source );
		self::assertStringContainsString( 'Setup and operational readiness', $source );
		self::assertStringContainsString( 'Download system report', $source );
		self::assertStringContainsString( 'Send test email', $source );
		self::assertStringContainsString( 'dreamax-lm-status-grid', $source );
		self::assertStringContainsString( "'storage_engine'", $diagnostics );
		self::assertStringContainsString( "'background'", $diagnostics );
		self::assertStringContainsString( "'proxy_rate_limit'", $diagnostics );
		self::assertStringContainsString( "'backup'", $diagnostics );
		self::assertStringContainsString( 'dreamax-lm-recovery-panel', $source );
	}

	public function test_credentials_page_uses_scoped_creation_and_guarded_lifecycle_actions(): void {
		$source = $this->read( 'src/Admin/class-admin.php' );
		$script = $this->read( 'assets/js/admin.js' );

		self::assertStringContainsString( 'dreamax-lm-credentials-page', $source );
		self::assertStringContainsString( 'data-dlm-credential-create', $source );
		self::assertStringContainsString( 'data-dlm-credential-scope', $source );
		self::assertStringContainsString( 'Credential inventory', $source );
		self::assertStringContainsString( "summary_card( __( 'Total credentials'", $source );
		self::assertStringContainsString( "\$total, 'admin-network'", $source );
		self::assertStringContainsString( 'data-dlm-credential-action', $source );
		self::assertStringContainsString( 'submit.disabled = !name.value.trim()', $script );
		self::assertStringContainsString( 'submit.disabled = !confirmation.checked', $script );
		self::assertStringContainsString( 'render_credential_secret_response', $source );
		self::assertStringContainsString( 'data-dlm-copy-secret', $source );
		self::assertStringContainsString( 'navigator.clipboard.writeText', $script );
	}

	private function read( string $relative_path ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . '/' . $relative_path );
		self::assertIsString( $contents );

		return $contents;
	}
}
