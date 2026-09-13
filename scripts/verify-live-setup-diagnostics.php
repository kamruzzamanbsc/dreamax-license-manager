<?php
/**
 * Verifies guarded setup diagnostics without sending mail or exposing secrets.
 *
 * Run with wp eval-file scripts/verify-live-setup-diagnostics.php --path=<disposable-site>.
 *
 * @package DreamaxLicenseManager
 */

use Dreamax\LicenseManager\Admin\Admin;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\Capabilities;
use Dreamax\LicenseManager\Support\Diagnostics;

require_once __DIR__ . '/lib/release-tools.php';

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'DB_NAME' ) ) {
	throw new RuntimeException( 'Run this verifier through WP-CLI on a disposable site.' );
}
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => 'DREAMAX_LM_DISPOSABLE_TEST',
		'database' => (string) DB_NAME,
		'site_url' => home_url( '/' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	throw new RuntimeException( 'The guarded license-manager runtime is unavailable.' );
}

$administrators = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( array() === $administrators ) {
	throw new RuntimeException( 'The guarded administrator is unavailable.' );
}

$previous_user = get_current_user_id();
$buffer_level  = ob_get_level();
$checks        = array();
$html          = '';
try {
	wp_set_current_user( (int) $administrators[0] );
	$diagnostics                        = new Diagnostics();
	$rows                               = $diagnostics->checks();
	$report                             = $diagnostics->report();
	$required                           = array( 'environment', 'schema', 'storage_engine', 'encryption', 'delivery', 'generator', 'test_license', 'customer_portal', 'api', 'background', 'proxy_rate_limit', 'backup' );
	$checks['administrator_authorized'] = current_user_can( Capabilities::DIAGNOSTICS );
	$checks['complete_checklist']       = array_keys( $rows ) === $required;
	$checks['safe_report_shape']        = isset( $report['checks'] ) && array_keys( $report['checks'] ) === $required && 'dreamax-license-manager' === ( $report['plugin'] ?? null );
	$checks['runtime_crypto_ready']     = true === $rows['encryption']['ready'];
	$checks['runtime_api_private']      = true === $rows['api']['ready'];
	$checks['runtime_schema_ready']     = true === $rows['schema']['ready'] && true === $rows['storage_engine']['ready'];
	$report_json                        = wp_json_encode( $report );
	$secret                             = defined( 'DREAMAX_LICENSE_MANAGER_MASTER_KEY' ) ? (string) constant( 'DREAMAX_LICENSE_MANAGER_MASTER_KEY' ) : '';
	$user                               = wp_get_current_user();
	$checks['report_secret_free']       = is_string( $report_json ) && ( '' === $secret || ! str_contains( $report_json, $secret ) ) && ! str_contains( $report_json, (string) DB_NAME ) && ( '' === $user->user_email || ! str_contains( $report_json, $user->user_email ) );
	ob_start();
	( new Admin() )->status_page();
	$html                             = (string) ob_get_clean();
	$checks['status_screen_rendered'] = str_contains( $html, 'dreamax-lm-status-page' ) && str_contains( $html, 'Download system report' ) && str_contains( $html, 'Send test email' );
	$checks['forms_nonce_guarded']    = str_contains( $html, 'dreamax_lm_download_system_report' ) && str_contains( $html, 'dreamax_lm_send_test_email' ) && str_contains( $html, '_wpnonce' );
	$checks['status_secret_free']     = '' === $secret || ! str_contains( $html, $secret );
} finally {
	while ( ob_get_level() > $buffer_level ) {
		ob_end_clean();
	}
	wp_set_current_user( $previous_user );
	unset( $html, $report_json, $secret );
}

if ( in_array( false, $checks, true ) ) {
	throw new RuntimeException( 'A guarded setup diagnostics contract failed.' );
}
echo wp_json_encode(
	array(
		'classification'     => 'live_disposable_setup_diagnostics',
		'checks_passed'      => count( $checks ),
		'report_secret_free' => true,
		'external_mail_sent' => false,
		'sensitive_output'   => false,
	)
) . PHP_EOL;
