<?php
/**
 * Defines the Health class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

use Dreamax\LicenseManager\Encryption\Crypto;

/**
 * Handles Health operations.
 */
final class Health {
	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_filter( 'site_status_tests', array( $this, 'site_health_test' ) );
	}

	/**
	 * Handles the notice operation.
	 */
	public function notice(): void {
		if ( ! current_user_can( Capabilities::SECURITY ) || ( new Crypto() )->ready() ) {
			return;
		}

		if ( false === get_option( 'dreamax_lm_master_key_id', false ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Dreamax License Manager setup required:', 'dreamax-license-manager' ) . '</strong> ';
			echo esc_html__( 'Configure the encryption master key before issuing licenses.', 'dreamax-license-manager' );
			if ( current_user_can( Capabilities::DIAGNOSTICS ) ) {
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=dreamax-license-manager-status' ) ) . '">' . esc_html__( 'Open System Status', 'dreamax-license-manager' ) . '</a>';
			}
			echo '</p></div>';
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Dreamax License Manager recovery mode:', 'dreamax-license-manager' ) . '</strong> ';
		echo esc_html__( 'The master key is missing, invalid, or does not match this site. Key generation, assignment, reveal, export, and public validation are paused. Restore the correct wp-config.php key; stored encrypted data has not been changed.', 'dreamax-license-manager' );
		echo '</p></div>';
	}

	/**
	 * Handles the site health test operation.
	 *
	 * @param array $tests Tests value.
	 * @phpstan-param array{
	 *     direct?:array<string,array{label:string,test:callable|string,skip_cron?:bool}>,
	 *     async?:array<string,array{label:string,test:string,has_rest?:bool,skip_cron?:bool,async_direct_test?:callable,headers?:array<string,string>}>
	 * } $tests Site Health tests.
	 * @return array{
	 *     direct:array<string,array{label:string,test:callable|string,skip_cron?:bool}>,
	 *     async?:array<string,array{label:string,test:string,has_rest?:bool,skip_cron?:bool,async_direct_test?:callable,headers?:array<string,string>}>
	 * }
	 */
	public function site_health_test( array $tests ): array {
		$tests['direct']['dreamax_lm_encryption'] = array(
			'label' => __( 'Dreamax license encryption', 'dreamax-license-manager' ),
			'test'  => array( $this, 'test_encryption' ),
		);
		$tests['direct']['dreamax_lm_storage']    = array(
			'label' => __( 'Dreamax license storage', 'dreamax-license-manager' ),
			'test'  => array( $this, 'test_storage' ),
		);
		$tests['direct']['dreamax_lm_cleanup']    = array(
			'label' => __( 'Dreamax license cleanup schedule', 'dreamax-license-manager' ),
			'test'  => array( $this, 'test_cleanup' ),
		);
		return $tests;
	}

	/**
	 * Handles the test encryption operation.
	 *
	 * @return array{
	 *     label:string,
	 *     status:'good'|'critical',
	 *     badge:array{label:string,color:'blue'},
	 *     description:string,
	 *     test:'dreamax_lm_encryption'
	 * }
	 */
	public function test_encryption(): array {
		$ready = ( new Crypto() )->ready();
		return array(
			'label'       => $ready ? __( 'Dreamax license encryption is ready', 'dreamax-license-manager' ) : __( 'Dreamax license encryption needs attention', 'dreamax-license-manager' ),
			'status'      => $ready ? 'good' : 'critical',
			'badge'       => array(
				'label' => __( 'Security', 'dreamax-license-manager' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $ready ? __( 'The configured key passed an authenticated encryption self-test.', 'dreamax-license-manager' ) : __( 'Restore or configure the dedicated wp-config.php master key before issuing licenses.', 'dreamax-license-manager' ) ) . '</p>',
			'test'        => 'dreamax_lm_encryption',
		);
	}

	/**
	 * Confirms that every authoritative plugin table is available.
	 *
	 * @return array{
	 *     label:string,
	 *     status:'good'|'critical',
	 *     badge:array{label:string,color:'blue'},
	 *     description:string,
	 *     test:'dreamax_lm_storage'
	 * }
	 */
	public function test_storage(): array {
		$ready = self::storage_ready();

		return array(
			'label'       => $ready ? __( 'Dreamax license storage is ready', 'dreamax-license-manager' ) : __( 'Dreamax license storage needs attention', 'dreamax-license-manager' ),
			'status'      => $ready ? 'good' : 'critical',
			'badge'       => array(
				'label' => __( 'Security', 'dreamax-license-manager' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $ready ? __( 'All required licensing tables are available.', 'dreamax-license-manager' ) : __( 'One or more required licensing tables are unavailable. Mutating operations must remain paused until storage is restored.', 'dreamax-license-manager' ) ) . '</p>',
			'test'        => 'dreamax_lm_storage',
		);
	}

	/**
	 * Reports whether every authoritative plugin table is available.
	 */
	public static function storage_ready(): bool {
		global $wpdb;

		$required = array(
			'licenses',
			'activations',
			'events',
			'generators',
			'idempotency',
			'rate_limits',
			'api_credentials',
			'guest_claims',
			'order_owners',
		);
		foreach ( $required as $suffix ) {
			$table = $wpdb->prefix . 'dreamax_lm_' . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Site Health must inspect the current authoritative schema without cached results.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( $table !== $found ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Confirms that bounded cleanup remains scheduled.
	 *
	 * @return array{
	 *     label:string,
	 *     status:'good'|'recommended',
	 *     badge:array{label:string,color:'blue'},
	 *     description:string,
	 *     test:'dreamax_lm_cleanup'
	 * }
	 */
	public function test_cleanup(): array {
		$ready = false !== wp_next_scheduled( 'dreamax_lm_cleanup' );
		return array(
			'label'       => $ready ? __( 'Dreamax license cleanup is scheduled', 'dreamax-license-manager' ) : __( 'Dreamax license cleanup needs attention', 'dreamax-license-manager' ),
			'status'      => $ready ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'dreamax-license-manager' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $ready ? __( 'The bounded cleanup job is scheduled.', 'dreamax-license-manager' ) : __( 'The bounded cleanup job is not scheduled. Core licensing remains synchronous; restore WordPress cron and retry cleanup manually.', 'dreamax-license-manager' ) ) . '</p>',
			'test'        => 'dreamax_lm_cleanup',
		);
	}
}
