<?php
/**
 * Defines the Diagnostics class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Support;

use Dreamax\LicenseManager\Admin\CustomerPortalPage;
use Dreamax\LicenseManager\Database\Schema;
use Dreamax\LicenseManager\Encryption\Crypto;

/**
 * Builds secret-free operational readiness checks and support data.
 */
final class Diagnostics {
	/**
	 * Returns bounded setup and runtime facts.
	 *
	 * @return array<string,array{ready:bool,label:string,detail:string}>
	 */
	public function checks(): array {
		global $wpdb;

		$crypto_ready    = ( new Crypto() )->ready();
		$schema_ready    = Health::storage_ready() && Schema::VERSION === (string) get_option( 'dreamax_lm_schema_version', '0' );
		$generator_count = $this->table_count( $wpdb->prefix . 'dreamax_lm_generators' );
		$license_count   = $this->table_count( $wpdb->prefix . 'dreamax_lm_licenses' );
		$portal_page     = absint( get_option( CustomerPortalPage::OPTION_PAGE_ID, 0 ) );
		$portal_ready    = $portal_page > 0 && 'publish' === get_post_status( $portal_page );
		$statuses        = Settings::allocation_statuses();
		$api             = $this->api_smoke();
		$queue           = $this->queue_health();
		$innodb_ready    = $this->tables_use_innodb();
		$backup_ready    = Settings::backup_confirmed();
		$delivery_detail = sprintf(
			/* translators: %s: comma-separated WooCommerce order statuses. */
			__( 'Configured order statuses: %s.', 'dreamax-license-manager' ),
			implode( ', ', $statuses )
		);
		$generator_detail = sprintf(
			/* translators: %d: number of configured secure generators. */
			__( '%d secure generator configuration(s) are available.', 'dreamax-license-manager' ),
			$generator_count
		);
		$proxy_detail = sprintf(
			/* translators: %d: number of trusted proxy IP addresses. */
			__( '%d trusted proxy address(es) configured; forwarded headers remain ignored for all other sources.', 'dreamax-license-manager' ),
			count( Settings::trusted_proxies() )
		);

		return array(
			'environment'      => array(
				'ready'  => version_compare( PHP_VERSION, '8.0', '>=' ) && extension_loaded( 'sodium' ) && class_exists( 'WooCommerce' ),
				'label'  => __( 'Environment', 'dreamax-license-manager' ),
				'detail' => sprintf( 'PHP %s; WordPress %s; WooCommerce %s; Sodium %s.', PHP_VERSION, get_bloginfo( 'version' ), defined( 'WC_VERSION' ) ? WC_VERSION : 'unavailable', extension_loaded( 'sodium' ) ? 'loaded' : 'missing' ),
			),
			'schema'           => array(
				'ready'  => $schema_ready,
				'label'  => __( 'Database schema', 'dreamax-license-manager' ),
				'detail' => $schema_ready ? __( 'All authoritative tables and the current schema checkpoint are available.', 'dreamax-license-manager' ) : __( 'One or more tables or the schema checkpoint needs repair.', 'dreamax-license-manager' ),
			),
			'storage_engine'   => array(
				'ready'  => $innodb_ready,
				'label'  => __( 'Transactional storage', 'dreamax-license-manager' ),
				'detail' => $innodb_ready ? __( 'All authoritative tables use InnoDB transactional storage.', 'dreamax-license-manager' ) : __( 'One or more authoritative tables are missing or do not use InnoDB.', 'dreamax-license-manager' ),
			),
			'encryption'       => array(
				'ready'  => $crypto_ready,
				'label'  => __( 'Encryption', 'dreamax-license-manager' ),
				'detail' => $crypto_ready ? __( 'Authenticated encryption and key identity checks pass.', 'dreamax-license-manager' ) : __( 'Configure or restore the exact wp-config.php master key.', 'dreamax-license-manager' ),
			),
			'delivery'         => array(
				'ready'  => array() !== $statuses,
				'label'  => __( 'License delivery', 'dreamax-license-manager' ),
				'detail' => $delivery_detail,
			),
			'generator'        => array(
				'ready'  => $generator_count > 0,
				'label'  => __( 'First generator', 'dreamax-license-manager' ),
				'detail' => $generator_count > 0 ? $generator_detail : __( 'Create a secure generator before configuring generated-key products.', 'dreamax-license-manager' ),
			),
			'test_license'     => array(
				'ready'  => $license_count > 0,
				'label'  => __( 'Test license', 'dreamax-license-manager' ),
				'detail' => $license_count > 0 ? __( 'At least one license exists for an end-to-end validation check.', 'dreamax-license-manager' ) : __( 'Create a test license or complete a test order.', 'dreamax-license-manager' ),
			),
			'customer_portal'  => array(
				'ready'  => $portal_ready,
				'label'  => __( 'Customer portal', 'dreamax-license-manager' ),
				'detail' => $portal_ready ? __( 'The standalone customer dashboard is published.', 'dreamax-license-manager' ) : __( 'Publish the standalone customer dashboard.', 'dreamax-license-manager' ),
			),
			'api'              => array(
				'ready'  => $api['ready'],
				'label'  => __( 'REST API and private cache headers', 'dreamax-license-manager' ),
				'detail' => $api['detail'],
			),
			'background'       => array(
				'ready'  => $queue['ready'],
				'label'  => __( 'Background processing', 'dreamax-license-manager' ),
				'detail' => $queue['detail'],
			),
			'proxy_rate_limit' => array(
				'ready'  => Health::storage_ready(),
				'label'  => __( 'Proxy and rate-limit storage', 'dreamax-license-manager' ),
				'detail' => $proxy_detail,
			),
			'backup'           => array(
				'ready'  => $backup_ready,
				'label'  => __( 'Backup readiness', 'dreamax-license-manager' ),
				'detail' => $backup_ready ? __( 'The current master-key identity is acknowledged in the backup plan.', 'dreamax-license-manager' ) : __( 'Confirm that the database and external master key are both included in a tested backup plan.', 'dreamax-license-manager' ),
			),
		);
	}

	/**
	 * Returns a machine-readable report without secrets or server paths.
	 *
	 * @return array<string,mixed>
	 */
	public function report(): array {
		$checks = array();
		foreach ( $this->checks() as $id => $check ) {
			$checks[ $id ] = array(
				'ready'  => $check['ready'],
				'detail' => $check['detail'],
			);
		}
		return array(
			'plugin'                  => 'dreamax-license-manager',
			'plugin_version'          => DREAMAX_LM_VERSION,
			'schema_version'          => (string) get_option( 'dreamax_lm_schema_version', '0' ),
			'wordpress_version'       => get_bloginfo( 'version' ),
			'woocommerce_version'     => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'php_version'             => PHP_VERSION,
			'database_server_version' => $this->database_version(),
			'multisite'               => is_multisite(),
			'https'                   => is_ssl(),
			'wp_cron_disabled'        => defined( 'DISABLE_WP_CRON' ) && true === constant( 'DISABLE_WP_CRON' ),
			'generated_at_utc'        => gmdate( DATE_RFC3339 ),
			'checks'                  => $checks,
		);
	}

	/**
	 * Exercises public route registration and private response headers in memory.
	 *
	 * @return array{ready:bool,detail:string}
	 */
	private function api_smoke(): array {
		if ( ! function_exists( 'rest_do_request' ) ) {
			return array(
				'ready'  => false,
				'detail' => __( 'The WordPress REST server is unavailable.', 'dreamax-license-manager' ),
			);
		}
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/dreamax-license-manager/v1/system/ping' ) );
		$headers  = $response->get_headers();
		$private  = isset( $headers['Cache-Control'] ) && str_contains( strtolower( (string) $headers['Cache-Control'] ), 'no-store' ) && str_contains( strtolower( (string) $headers['Cache-Control'] ), 'private' );
		$ready    = in_array( $response->get_status(), array( 200, 503 ), true ) && $private;
		return array(
			'ready'  => $ready,
			'detail' => $ready ? __( 'The v1 endpoint responds with private no-store headers.', 'dreamax-license-manager' ) : __( 'The v1 endpoint or private cache-header contract needs attention.', 'dreamax-license-manager' ),
		);
	}

	/**
	 * Checks the scheduler surface and bounded Dreamax backlog.
	 *
	 * @return array{ready:bool,detail:string}
	 */
	private function queue_health(): array {
		$cron = false !== wp_next_scheduled( 'dreamax_lm_cleanup' );
		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$pending = as_get_scheduled_actions(
				array(
					'group'    => 'dreamax-license-manager',
					'status'   => 'pending',
					'per_page' => 1,
				),
				'count'
			);
			$count   = is_numeric( $pending ) ? (int) $pending : 0;
			$detail  = sprintf(
				/* translators: 1: number of pending Dreamax jobs, 2: cleanup cron state. */
				__( 'Action Scheduler is available with %1$d pending Dreamax job(s); cleanup cron is %2$s.', 'dreamax-license-manager' ),
				$count,
				$cron ? 'scheduled' : 'missing'
			);
			return array(
				'ready'  => $cron && $count < 100,
				'detail' => $detail,
			);
		}
		return array(
			'ready'  => $cron,
			'detail' => $cron ? __( 'WP-Cron fallback is scheduled; Action Scheduler is unavailable.', 'dreamax-license-manager' ) : __( 'No supported background runner is currently scheduled.', 'dreamax-license-manager' ),
		);
	}

	/** Reports whether every authoritative table uses InnoDB. */
	private function tables_use_innodb(): bool {
		global $wpdb;
		foreach ( array( 'licenses', 'activations', 'events', 'generators', 'idempotency', 'rate_limits', 'api_credentials', 'guest_claims', 'order_owners' ) as $suffix ) {
			$table = $wpdb->prefix . 'dreamax_lm_' . $suffix;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics must inspect the current authoritative storage engine.
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
			if ( ! is_string( $engine ) || 'INNODB' !== strtoupper( $engine ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Returns a count only when the fixed plugin table exists.
	 *
	 * @param string $table Validated plugin table name.
	 */
	private function table_count( string $table ): int {
		global $wpdb;
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Diagnostics reads a validated plugin-owned table.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/** Returns only the database server version string. */
	private function database_version(): string {
		global $wpdb;
		return (string) $wpdb->db_version();
	}
}
