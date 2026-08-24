<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

use Dreamax\LicenseManager\Encryption\Crypto;

final class Health {
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'site_status_tests', array( $this, 'site_health_test' ) );
	}

	public function notice(): void {
		if ( ! current_user_can( Capabilities::SECURITY ) || ( new Crypto() )->ready() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Dreamax License Manager recovery mode:', 'dreamax-license-manager' ) . '</strong> ';
		echo esc_html__( 'The master key is missing, invalid, or does not match this site. Key generation, assignment, reveal, export, and public validation are paused. Restore the correct wp-config.php key; stored encrypted data has not been changed.', 'dreamax-license-manager' );
		echo '</p></div>';
	}

	/** @param array<string,mixed> $tests @return array<string,mixed> */
	public function site_health_test( array $tests ): array {
		$tests['direct']['dreamax_lm_encryption'] = array( 'label' => __( 'Dreamax license encryption', 'dreamax-license-manager' ), 'test' => array( $this, 'test_encryption' ) );
		return $tests;
	}

	/** @return array<string,mixed> */
	public function test_encryption(): array {
		$ready = ( new Crypto() )->ready();
		return array(
			'label'       => $ready ? __( 'Dreamax license encryption is ready', 'dreamax-license-manager' ) : __( 'Dreamax license encryption needs attention', 'dreamax-license-manager' ),
			'status'      => $ready ? 'good' : 'critical',
			'badge'       => array( 'label' => __( 'Security', 'dreamax-license-manager' ), 'color' => 'blue' ),
			'description' => '<p>' . esc_html( $ready ? __( 'The configured key passed an authenticated encryption self-test.', 'dreamax-license-manager' ) : __( 'Restore or configure the dedicated wp-config.php master key before issuing licenses.', 'dreamax-license-manager' ) ) . '</p>',
			'test'        => 'dreamax_lm_encryption',
		);
	}
}
