<?php
/**
 * Verifies equivalent licensing outcomes across WooCommerce storage and checkout modes.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Utilities\OrderUtil;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Integrations\WooCommerce\OrderLicensing;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\PublicId;

require_once __DIR__ . '/lib/release-tools.php';

const DREAMAX_LM_F20_DATABASE_PREFIX = 'dreamax_lm_f20_';
const DREAMAX_LM_F20_CLONE_PREFIX    = 'dreamax-lm-f20-clone-';
const DREAMAX_LM_F20_CONFIRMATION    = 'I_CONFIRM_F20_PRIVATE_CLONE_MATRIX';

/**
 * Stops with a fixed sanitized error.
 *
 * @param string $message Sanitized message.
 */
function dreamax_lm_f20_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier reports fixed sanitized errors.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Confirms an exact verifier-owned database name.
 *
 * @param string $database Database name.
 * @throws RuntimeException When the name is not exactly verifier-scoped.
 */
function dreamax_lm_f20_assert_database( string $database ): void {
	if ( 1 !== preg_match( '/^dreamax_lm_f20_(?:hpos|classic)_test_[a-f0-9]{16}$/D', $database ) ) {
		throw new RuntimeException( 'A temporary database identifier was not safely scoped.' );
	}
}

/**
 * Lists all base tables in a constrained database.
 *
 * @param string $database Database name.
 * @return list<string>
 * @throws RuntimeException When tables cannot be inspected safely.
 */
function dreamax_lm_f20_tables( string $database ): array {
	global $wpdb;
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $database ) ) {
		throw new RuntimeException( 'A database identifier was unsafe.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is strictly constrained above.
	$rows = $wpdb->get_results( "SHOW FULL TABLES FROM `{$database}`", ARRAY_N );
	if ( ! is_array( $rows ) || array() === $rows ) {
		throw new RuntimeException( 'The database had no inspectable tables.' );
	}
	$tables = array();
	foreach ( $rows as $row ) {
		$table = (string) ( $row[0] ?? '' );
		$type  = strtoupper( (string) ( $row[1] ?? '' ) );
		if ( 'BASE TABLE' !== $type || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'The verifier refuses views or unsafe table identifiers.' );
		}
		$tables[] = $table;
	}
	sort( $tables, SORT_STRING );
	return array_values( array_unique( $tables ) );
}

/**
 * Returns a value-free schema and data digest.
 *
 * @param string $database Database name.
 * @return array{digest:string,tables:int,rows:int}
 * @throws RuntimeException When a table cannot be safely digested.
 */
function dreamax_lm_f20_database_digest( string $database ): array {
	global $wpdb;
	$state      = array();
	$total_rows = 0;
	$tables     = dreamax_lm_f20_tables( $database );
	foreach ( $tables as $table ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict identifiers and checksums avoid retaining values.
		$create   = $wpdb->get_row( "SHOW CREATE TABLE `{$database}`.`{$table}`", ARRAY_N );
		$count    = $wpdb->get_var( "SELECT COUNT(*) FROM `{$database}`.`{$table}`" );
		$checksum = $wpdb->get_row( "CHECKSUM TABLE `{$database}`.`{$table}`", ARRAY_N );
		// phpcs:enable
		if ( ! is_array( $create ) || ! is_numeric( $count ) || ! is_array( $checksum ) || ! is_numeric( $checksum[1] ?? null ) ) {
			throw new RuntimeException( 'A database table could not be digested.' );
		}
		$schema = preg_replace( '/\sAUTO_INCREMENT=\d+/D', '', (string) ( $create[1] ?? '' ) );
		if ( ! is_string( $schema ) ) {
			throw new RuntimeException( 'A database schema could not be normalized.' );
		}
		$row_count   = (int) $count;
		$total_rows += $row_count;
		$state[]     = array(
			'table'    => $table,
			'schema'   => $schema,
			'rows'     => $row_count,
			'checksum' => (string) $checksum[1],
		);
	}
	$encoded = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
	if ( ! is_string( $encoded ) ) {
		throw new RuntimeException( 'The database digest could not be encoded.' );
	}
	return array(
		'digest' => hash( 'sha256', $encoded ),
		'tables' => count( $tables ),
		'rows'   => $total_rows,
	);
}

/**
 * Copies one complete disposable database to an exact verifier-owned target.
 *
 * @param string $source Source database.
 * @param string $target Target database.
 * @throws RuntimeException When the private database cannot be copied.
 */
function dreamax_lm_f20_copy_database( string $source, string $target ): void {
	global $wpdb;
	dreamax_lm_f20_assert_database( $target );
	if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $source ) ) {
		throw new RuntimeException( 'The source database identifier was unsafe.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check prevents overwrite.
	if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = %s', $target ) ) ) {
		throw new RuntimeException( 'A verifier-owned database target already exists.' );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact random target is validated above.
	if ( false === $wpdb->query( "CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" ) ) {
		throw new RuntimeException( 'A verifier-owned database could not be created.' );
	}
	foreach ( dreamax_lm_f20_tables( $source ) as $table ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict source and target identifiers copy into a fresh private database.
		if ( false === $wpdb->query( "CREATE TABLE `{$target}`.`{$table}` LIKE `{$source}`.`{$table}`" )
			|| false === $wpdb->query( "INSERT INTO `{$target}`.`{$table}` SELECT * FROM `{$source}`.`{$table}`" ) ) {
			throw new RuntimeException( 'A private matrix database could not be copied.' );
		}
		// phpcs:enable
	}
}

/**
 * Drops only an exact verifier-owned database.
 *
 * @param string $database Database name.
 * @throws RuntimeException When guarded cleanup fails.
 */
function dreamax_lm_f20_drop_database( string $database ): void {
	global $wpdb;
	dreamax_lm_f20_assert_database( $database );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact target is validated above.
	if ( false === $wpdb->query( "DROP DATABASE IF EXISTS `{$database}`" ) ) {
		throw new RuntimeException( 'A verifier-owned database could not be removed.' );
	}
}

/**
 * Copies a WordPress tree without mutable uploads or caches.
 *
 * @param string $source Source root.
 * @param string $target Private clone root.
 * @throws RuntimeException When the clone cannot be copied safely.
 */
function dreamax_lm_f20_copy_tree( string $source, string $target ): void {
	$excluded = array( 'wp-content/uploads', 'wp-content/cache', 'wp-content/upgrade', 'wp-content/backups', 'wp-content/ai1wm-backups' );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
	foreach ( $iterator as $item ) {
		$relative = str_replace( '\\', '/', substr( $item->getPathname(), strlen( $source ) + 1 ) );
		$skip     = false;
		foreach ( $excluded as $excluded_path ) {
			if ( $relative === $excluded_path || str_starts_with( $relative, $excluded_path . '/' ) ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}
		if ( $item->isLink() ) {
			throw new RuntimeException( 'The private matrix clone refused a symbolic link.' );
		}
		$destination = $target . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the exact private clone.
			if ( ! is_dir( $destination ) && ! mkdir( $destination, 0700, true ) && ! is_dir( $destination ) ) {
				throw new RuntimeException( 'A private matrix clone directory could not be created.' );
			}
			continue;
		}
		$parent = dirname( $destination );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only the exact private clone parent.
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) && ! is_dir( $parent ) ) {
			throw new RuntimeException( 'A private matrix clone parent could not be created.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Copies only into the private clone.
		if ( ! copy( $item->getPathname(), $destination ) ) {
			throw new RuntimeException( 'A private matrix clone file could not be copied.' );
		}
	}
}

/**
 * Removes only a verified clone directly below the system temporary directory.
 *
 * @param string $target Clone root.
 * @throws RuntimeException When guarded cleanup is ambiguous or fails.
 */
function dreamax_lm_f20_remove_tree( string $target ): void {
	$temp_real   = realpath( sys_get_temp_dir() );
	$target_real = realpath( $target );
	if ( false === $temp_real || false === $target_real || dirname( $target_real ) !== rtrim( $temp_real, DIRECTORY_SEPARATOR ) || ! str_starts_with( basename( $target_real ), DREAMAX_LM_F20_CLONE_PREFIX ) ) {
		throw new RuntimeException( 'Cleanup refused an ambiguous private matrix clone.' );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $target_real, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $item ) {
		if ( $item->isLink() ) {
			throw new RuntimeException( 'Cleanup refused a symbolic link.' );
		}
		if ( $item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only the guarded private clone.
			if ( ! rmdir( $item->getPathname() ) ) {
				throw new RuntimeException( 'A private clone directory could not be removed.' );
			}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only a guarded private clone file.
		} elseif ( ! unlink( $item->getPathname() ) ) {
			throw new RuntimeException( 'A private clone file could not be removed.' );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes only the guarded clone root.
	if ( ! rmdir( $target_real ) ) {
		throw new RuntimeException( 'The private clone root could not be removed.' );
	}
}

/**
 * Selects an exact verifier database in cloned configuration text.
 *
 * @param string $config Configuration text.
 * @param string $database Database name.
 * @throws RuntimeException When the database definition cannot be replaced exactly.
 */
function dreamax_lm_f20_config_for_database( string $config, string $database ): string {
	dreamax_lm_f20_assert_database( $database );
	$pattern = '/^[ \t]*define\s*\(\s*([\'\"])DB_NAME\1\s*,.*?\)\s*;[ \t]*\r?$/m';
	if ( 1 !== preg_match_all( $pattern, $config ) ) {
		throw new RuntimeException( 'The cloned database definition was not uniquely replaceable.' );
	}
	$changed = preg_replace( $pattern, "define( 'DB_NAME', '" . $database . "' );", $config, 1, $count );
	if ( ! is_string( $changed ) || 1 !== $count ) {
		throw new RuntimeException( 'The cloned matrix database could not be selected.' );
	}
	return $changed;
}

/**
 * Configures only a verifier-owned database for one compatibility mode.
 *
 * @param string $database Target database.
 * @param string $mode Mode name.
 * @throws RuntimeException When a private mode cannot be configured exactly.
 */
function dreamax_lm_f20_prepare_mode( string $database, string $mode ): void {
	global $wpdb;
	dreamax_lm_f20_assert_database( $database );
	if ( ! in_array( $mode, array( 'hpos_blocks', 'classic_shortcode' ), true ) || 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $wpdb->prefix ) ) {
		throw new RuntimeException( 'The requested compatibility mode was invalid.' );
	}
	$options = $wpdb->prefix . 'options';
	$posts   = $wpdb->prefix . 'posts';
	$hpos    = 'hpos_blocks' === $mode ? 'yes' : 'no';
	foreach ( array(
		'woocommerce_custom_orders_table_enabled' => $hpos,
		'woocommerce_custom_orders_table_data_sync_enabled' => 'no',
	) as $name => $value ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads only option existence in the private clone database.
		$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$database}`.`{$options}` WHERE option_name=%s", $name ) );
		if ( 0 === $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Inserts only a missing option in the private clone database.
			if ( false === $wpdb->query( $wpdb->prepare( "INSERT INTO `{$database}`.`{$options}` (option_name, option_value, autoload) VALUES (%s,%s,%s)", $name, $value, 'on' ) ) ) {
				throw new RuntimeException( 'A private compatibility option could not be inserted.' );
			}
			continue;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Strict target identifiers and prepared values mutate only the disposable clone database.
		if ( false === $wpdb->query( $wpdb->prepare( "UPDATE `{$database}`.`{$options}` SET option_value=%s WHERE option_name=%s", $value, $name ) ) ) {
			throw new RuntimeException( 'A private compatibility option could not be set.' );
		}
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Reads only the cloned checkout-page option.
	$checkout_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$database}`.`{$options}` WHERE option_name=%s", 'woocommerce_checkout_page_id' ) );
	if ( $checkout_id < 1 ) {
		throw new RuntimeException( 'The private checkout page was unavailable.' );
	}
	$content = 'hpos_blocks' === $mode ? '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->' : '[woocommerce_checkout]';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Updates only the known checkout page in the private clone database.
	if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE `{$database}`.`{$posts}` SET post_content=%s WHERE ID=%d AND post_type='page'", $content, $checkout_id ) ) ) {
		throw new RuntimeException( 'The private checkout mode could not be prepared.' );
	}
}

/**
 * Writes one private sanitized worker result.
 *
 * @param string              $path Result path.
 * @param array<string,mixed> $result Result data.
 * @throws RuntimeException When the private result cannot be written.
 */
function dreamax_lm_f20_write_result( string $path, array $result ): void {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- JSON exceptions are required before WordPress may be loaded.
	$encoded = json_encode( $result, JSON_THROW_ON_ERROR );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only the exact private result.
	if ( strlen( $encoded ) !== file_put_contents( $path, $encoded, LOCK_EX ) ) {
		throw new RuntimeException( 'The private matrix result could not be written.' );
	}
}

/**
 * Executes one isolated compatibility mode.
 *
 * @param array<string,string|false> $options CLI options.
 * @throws RuntimeException When writing a worker result fails.
 */
function dreamax_lm_f20_worker( array $options ): never {
	$mode        = (string) ( $options['worker'] ?? '' );
	$marker      = (string) ( $options['environment-marker'] ?? '' );
	$wp_root     = realpath( (string) ( $options['wp-root'] ?? '' ) );
	$result_path = (string) ( $options['result-file'] ?? '' );
	$stage       = 'worker_preflight';
	try {
		if ( false === $wp_root || ! in_array( $mode, array( 'hpos_blocks', 'classic_shortcode' ), true ) || realpath( dirname( $result_path ) ) !== $wp_root || ! str_starts_with( basename( $result_path ), '.dreamax-f20-result-' ) ) {
			throw new RuntimeException( 'The private worker arguments were invalid.' );
		}
		if ( ! defined( 'DISABLE_WP_CRON' ) ) {
			define( 'DISABLE_WP_CRON', true );
		}
		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', false );
		}
		require_once $wp_root . '/wp-load.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		DisposableEnvironmentGuard::assertSafe(
			array(
				'marker'   => $marker,
				'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
				'site_url' => (string) get_option( 'siteurl' ),
			)
		);
		if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) || ! class_exists( OrderLicensing::class ) ) {
			throw new RuntimeException( 'The required private runtime was unavailable.' );
		}
		global $wpdb;
		$wpdb->suppress_errors( true );
		$database = (string) $wpdb->get_var( 'SELECT DATABASE()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		dreamax_lm_f20_assert_database( $database );
		$stage       = 'mode_contract';
		$hpos_active = OrderUtil::custom_orders_table_usage_is_enabled();
		$data_store  = WC_Data_Store::load( 'order' );
		$store_class = $data_store->get_current_class_name();
		$checkout_id = wc_get_page_id( 'checkout' );
		$checkout    = $checkout_id > 0 ? get_post( $checkout_id ) : null;
		if ( ! $checkout instanceof WP_Post ) {
			throw new RuntimeException( 'The private checkout page could not be loaded.' );
		}
		$block_mode     = has_block( 'woocommerce/checkout', $checkout->post_content );
		$shortcode_mode = has_shortcode( $checkout->post_content, 'woocommerce_checkout' );
		if ( 'hpos_blocks' === $mode ) {
			if ( ! $hpos_active ) {
				$stage = 'hpos_flag';
				throw new RuntimeException( 'The private HPOS flag did not activate.' );
			}
			if ( ! is_a( $store_class, OrdersTableDataStore::class, true ) ) {
				$stage = 'hpos_datastore';
				throw new RuntimeException( 'The private HPOS data store did not activate.' );
			}
			if ( ! $block_mode || $shortcode_mode ) {
				$stage = 'block_checkout';
				throw new RuntimeException( 'The private block checkout did not activate.' );
			}
		} else {
			if ( $hpos_active ) {
				$stage = 'classic_flag';
				throw new RuntimeException( 'The private classic-storage flag did not activate.' );
			}
			if ( ! is_a( $store_class, WC_Order_Data_Store_CPT::class, true ) ) {
				$stage = 'classic_datastore';
				throw new RuntimeException( 'The private classic data store did not activate.' );
			}
			if ( $block_mode || ! $shortcode_mode ) {
				$stage = 'classic_checkout';
				throw new RuntimeException( 'The private classic checkout did not activate.' );
			}
		}
		$mode_ok = true;

		add_filter( 'pre_wp_mail', static fn(): bool => false, PHP_INT_MAX );
		add_filter( 'woocommerce_email_enabled_new_order', '__return_false', PHP_INT_MAX );
		add_filter( 'woocommerce_email_enabled_customer_processing_order', '__return_false', PHP_INT_MAX );
		$stage    = 'licensing_runs';
		$outcomes = array();
		for ( $run = 1; $run <= 2; ++$run ) {
			$stage   = 'product_create';
			$product = new WC_Product_Simple();
			$product->set_name( 'DLM F20 temporary compatibility product' );
			$product->set_status( 'publish' );
			$product->set_virtual( true );
			$product->set_regular_price( '10' );
			$product->save();
			$product->update_meta_data( '_dreamax_lm_enabled', 'yes' );
			$product->update_meta_data( '_dreamax_lm_product_public_id', PublicId::generate( 'prd' ) );
			$product->update_meta_data( '_dreamax_lm_source', 'generated' );
			$product->update_meta_data( '_dreamax_lm_issuance', 'per_quantity' );
			$product->update_meta_data( '_dreamax_lm_activation_limit', '2' );
			$product->update_meta_data( '_dreamax_lm_valid_days', '30' );
			$product->save_meta_data();

			$stage = 'order_create';
			$order = wc_create_order( array( 'status' => 'pending' ) );
			if ( ! $order instanceof WC_Order ) {
				throw new RuntimeException( 'A private compatibility order could not be created.' );
			}
			$order->set_billing_email( 'dlm-f20@example.invalid' );
			$order->set_payment_method( 'bacs' );
			$item_id = (int) $order->add_product( $product, 2 );
			$order->calculate_totals();
			$order->set_date_paid( time() );
			$order->set_status( 'processing' );
			$order->save();

			$stage   = 'outcome_read';
			$rows    = ( new LicenseRepository() )->for_order_item( $item_id );
			$preview = ( new OrderLicensing() )->preview( (int) $order->get_id() );
			$events  = array();
			foreach ( array( AuditEventCatalog::LICENSE_CREATED, AuditEventCatalog::LICENSE_ASSIGNED, AuditEventCatalog::LICENSE_DELIVERED ) as $event_type ) {
				$events[ $event_type ] = 0;
				foreach ( $rows as $row ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact private-fixture event count.
					$events[ $event_type ] += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_events WHERE license_id=%d AND event_type=%s", (int) $row['id'], $event_type ) );
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies physical placement of only the private order.
			$hpos_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE id=%d", (int) $order->get_id() ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verifies physical placement of only the private order.
			$post_row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID=%d AND post_type='shop_order'", (int) $order->get_id() ) );
			$slots    = array_map( static fn( array $row ): int => (int) $row['quantity_slot'], $rows );
			sort( $slots );
			$outcomes[] = array(
				'licenses'      => count( $rows ),
				'slots'         => $slots,
				'missing'       => (int) $preview['missing'],
				'eligible'      => true === $preview['eligible'],
				'created'       => $events[ AuditEventCatalog::LICENSE_CREATED ],
				'assigned'      => $events[ AuditEventCatalog::LICENSE_ASSIGNED ],
				'delivered'     => $events[ AuditEventCatalog::LICENSE_DELIVERED ],
				'storage_match' => 'hpos_blocks' === $mode ? 1 === $hpos_row && 0 === $post_row : 0 === $hpos_row && 1 === $post_row,
			);
		}
		$expected  = array(
			'licenses'      => 2,
			'slots'         => array( 1, 2 ),
			'missing'       => 0,
			'eligible'      => true,
			'created'       => 2,
			'assigned'      => 2,
			'delivered'     => 2,
			'storage_match' => true,
		);
		$stage     = 'outcome_contract';
		$worker_ok = array( $expected, $expected ) === $outcomes;
		dreamax_lm_f20_write_result(
			$result_path,
			array(
				'worker_ok'        => $worker_ok,
				'mode_contract'    => $mode_ok,
				'repeated_parity'  => isset( $outcomes[1] ) && $outcomes[0] === $outcomes[1],
				'outcome'          => $outcomes[0] ?? array(),
				'failure_stage'    => $worker_ok ? 'none' : 'outcome_contract',
				'sensitive_output' => false,
			)
		);
		exit( $worker_ok ? 0 : 2 );
	} catch ( Throwable $error ) {
		unset( $error );
		if ( '' !== $result_path && false !== $wp_root && realpath( dirname( $result_path ) ) === $wp_root ) {
			dreamax_lm_f20_write_result(
				$result_path,
				array(
					'worker_ok'        => false,
					'failure_stage'    => $stage,
					'sensitive_output' => false,
				)
			);
		}
		exit( 2 );
	}
}

/**
 * Runs and consumes one isolated worker result.
 *
 * @param string $mode Mode name.
 * @param string $clone_root Clone root.
 * @param string $marker Disposable marker.
 * @return array<string,mixed>
 * @throws RuntimeException When the worker cannot execute or its result is invalid.
 */
function dreamax_lm_f20_run_worker( string $mode, string $clone_root, string $marker ): array {
	$result_file = $clone_root . DIRECTORY_SEPARATOR . '.dreamax-f20-result-' . $mode . '-' . bin2hex( random_bytes( 4 ) ) . '.json';
	$command     = array( PHP_BINARY, __FILE__, '--worker=' . $mode, '--wp-root=' . $clone_root, '--environment-marker=' . $marker, '--result-file=' . $result_file );
	$descriptor  = array(
		0 => array( 'pipe', 'r' ),
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Separate processes ensure WooCommerce selects each mode during boot.
	$process = proc_open( $command, $descriptor, $pipes, __DIR__ );
	if ( ! is_resource( $process ) ) {
		throw new RuntimeException( 'A private compatibility worker could not be started.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes verifier-owned pipes.
	fclose( $pipes[0] );
	$stdout = stream_get_contents( $pipes[1] );
	$stderr = stream_get_contents( $pipes[2] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes verifier-owned pipes.
	fclose( $pipes[1] );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes verifier-owned pipes.
	fclose( $pipes[2] );
	$exit = proc_close( $process );
	unset( $stdout, $stderr );
	if ( ! in_array( $exit, array( 0, 2 ), true ) || ! is_file( $result_file ) ) {
		throw new RuntimeException( 'A private compatibility worker returned no valid status.' );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads only the exact private result.
	$encoded = file_get_contents( $result_file );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes only the exact private result.
	if ( false === $encoded || ! unlink( $result_file ) ) {
		throw new RuntimeException( 'A private compatibility result could not be consumed.' );
	}
	$result = json_decode( $encoded, true, 512, JSON_THROW_ON_ERROR );
	if ( ! is_array( $result ) ) {
		throw new RuntimeException( 'A private compatibility result was invalid.' );
	}
	return $result;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:', 'confirm-matrix:', 'worker:', 'result-file:', 'diagnose-only' ) );
if ( isset( $options['worker'] ) ) {
	dreamax_lm_f20_worker( $options );
}

$marker       = (string) ( $options['environment-marker'] ?? '' );
$confirmation = (string) ( $options['confirm-matrix'] ?? '' );
$wp_root      = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) || ! is_file( $wp_root . '/wp-config.php' ) ) {
	dreamax_lm_f20_fail( 'A valid disposable WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
		'site_url' => (string) get_option( 'siteurl' ),
	)
);
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! function_exists( 'WC' ) ) {
	dreamax_lm_f20_fail( 'The disposable WooCommerce runtime is unavailable.' );
}

global $wpdb;
$wpdb->suppress_errors( true );
$source_database = (string) $wpdb->get_var( 'SELECT DATABASE()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/D', $source_database ) ) {
	dreamax_lm_f20_fail( 'The disposable database identifier was unsafe.' );
}
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only residue preflight prevents ambiguous cleanup.
$stale_databases = $wpdb->get_col( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME LIKE %s', DREAMAX_LM_F20_DATABASE_PREFIX . '%' ) );
$temp_root       = realpath( sys_get_temp_dir() );
$stale_clones    = false === $temp_root ? false : glob( $temp_root . DIRECTORY_SEPARATOR . DREAMAX_LM_F20_CLONE_PREFIX . '*', GLOB_ONLYDIR );
$source_digest   = dreamax_lm_f20_database_digest( $source_database );
$checkout_id     = wc_get_page_id( 'checkout' );
$checkout        = $checkout_id > 0 ? get_post( $checkout_id ) : null;
$required_tables = array( $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'wc_orders', $wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta', $wpdb->prefix . 'dreamax_lm_licenses', $wpdb->prefix . 'dreamax_lm_events' );
$innodb_ready    = true;
foreach ( $required_tables as $table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only engine preflight.
	$engine       = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', $source_database, $table ) );
	$innodb_ready = $innodb_ready && 'InnoDB' === $engine;
}
$diagnosis = array(
	'disposable_guard_passed'   => true,
	'source_database_readable'  => $source_digest['tables'] > 0,
	'source_hpos_enabled'       => OrderUtil::custom_orders_table_usage_is_enabled(),
	'source_hpos_datastore'     => is_a( WC_Data_Store::load( 'order' )->get_current_class_name(), OrdersTableDataStore::class, true ),
	'source_checkout_is_block'  => $checkout instanceof WP_Post && has_block( 'woocommerce/checkout', $checkout->post_content ),
	'required_tables_innodb'    => $innodb_ready,
	'no_stale_database_residue' => array() === $stale_databases,
	'no_stale_clone_residue'    => is_array( $stale_clones ) && array() === $stale_clones,
);
if ( isset( $options['diagnose-only'] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only sanitized booleans and counts.
	echo json_encode(
		array(
			'classification'   => 'sanitized_f20_read_only_diagnosis',
			'checks'           => $diagnosis,
			'source_tables'    => $source_digest['tables'],
			'sensitive_output' => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( in_array( false, $diagnosis, true ) ? 1 : 0 );
}
if ( DREAMAX_LM_F20_CONFIRMATION !== $confirmation || in_array( false, $diagnosis, true ) ) {
	dreamax_lm_f20_fail( 'The exact private-clone confirmation and clean diagnosis are required.' );
}

$suffix        = bin2hex( random_bytes( 8 ) );
$clone_root    = rtrim( (string) $temp_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . DREAMAX_LM_F20_CLONE_PREFIX . $suffix;
$databases     = array(
	'hpos_blocks'       => DREAMAX_LM_F20_DATABASE_PREFIX . 'hpos_test_' . $suffix,
	'classic_shortcode' => DREAMAX_LM_F20_DATABASE_PREFIX . 'classic_test_' . $suffix,
);
$cleanup       = array(
	'hpos_database_removed'    => false,
	'classic_database_removed' => false,
	'clone_removed'            => false,
);
$results       = array();
$worker_stages = array(
	'hpos_blocks'       => 'not_run',
	'classic_shortcode' => 'not_run',
);
$failure       = null;
$failure_stage = 'clone_create';
try {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates only a random private clone.
	if ( file_exists( $clone_root ) || ! mkdir( $clone_root, 0700 ) ) {
		throw new RuntimeException( 'The private compatibility clone could not be created.' );
	}
	dreamax_lm_f20_copy_tree( $wp_root, $clone_root );
	$original_config_hash = hash_file( 'sha256', $wp_root . '/wp-config.php' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Clone configuration stays private and is never emitted.
	$clone_config = file_get_contents( $clone_root . '/wp-config.php' );
	if ( false === $original_config_hash || false === $clone_config ) {
		throw new RuntimeException( 'The private configuration baseline could not be verified.' );
	}
	foreach ( $databases as $matrix_mode => $database ) {
		$failure_stage = $matrix_mode . '_database_copy';
		dreamax_lm_f20_copy_database( $source_database, $database );
		$failure_stage = $matrix_mode . '_mode_prepare';
		dreamax_lm_f20_prepare_mode( $database, $matrix_mode );
		$mode_config = dreamax_lm_f20_config_for_database( $clone_config, $database );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes only the private cloned configuration.
		if ( strlen( $mode_config ) !== file_put_contents( $clone_root . '/wp-config.php', $mode_config, LOCK_EX ) ) {
			throw new RuntimeException( 'A private compatibility configuration could not be written.' );
		}
		$failure_stage                 = $matrix_mode . '_worker';
		$results[ $matrix_mode ]       = dreamax_lm_f20_run_worker( $matrix_mode, $clone_root, $marker );
		$worker_stages[ $matrix_mode ] = true === ( $results[ $matrix_mode ]['worker_ok'] ?? false ) ? 'none' : (string) ( $results[ $matrix_mode ]['failure_stage'] ?? 'unknown' );
		if ( true !== ( $results[ $matrix_mode ]['worker_ok'] ?? false ) ) {
			throw new RuntimeException( 'A private compatibility worker contract failed.' );
		}
	}
	$failure_stage = 'parity_compare';
	if ( ( $results['hpos_blocks']['outcome'] ?? null ) !== ( $results['classic_shortcode']['outcome'] ?? null ) ) {
		throw new RuntimeException( 'The supported-mode outcomes were not equivalent.' );
	}
	if ( dreamax_lm_f20_database_digest( $source_database ) !== $source_digest || ! hash_equals( $original_config_hash, (string) hash_file( 'sha256', $wp_root . '/wp-config.php' ) ) ) {
		throw new RuntimeException( 'The source environment changed during private verification.' );
	}
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	foreach ( $databases as $matrix_mode => $database ) {
		$key = 'hpos_blocks' === $matrix_mode ? 'hpos_database_removed' : 'classic_database_removed';
		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cleanup checks only the random verifier target.
			if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=%s', $database ) ) ) {
				dreamax_lm_f20_drop_database( $database );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact post-cleanup check.
			$cleanup[ $key ] = null === $wpdb->get_var( $wpdb->prepare( 'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME=%s', $database ) );
		} catch ( Throwable $cleanup_error ) {
			unset( $cleanup_error );
			$cleanup[ $key ] = false;
		}
	}
	try {
		if ( is_dir( $clone_root ) ) {
			dreamax_lm_f20_remove_tree( $clone_root );
		}
		$cleanup['clone_removed'] = ! file_exists( $clone_root );
	} catch ( Throwable $cleanup_error ) {
		unset( $cleanup_error );
		$cleanup['clone_removed'] = false;
	}
}

$cleanup_committed = ! in_array( false, $cleanup, true );
if ( $failure instanceof Throwable || ! $cleanup_committed ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only fixed sanitized fields.
	echo json_encode(
		array(
			'classification'   => 'sanitized_f20_verification_failure',
			'failure_stage'    => $failure_stage,
			'worker_stages'    => $worker_stages,
			'worker_outcomes'  => array(
				'hpos_blocks'       => $results['hpos_blocks']['outcome'] ?? array(),
				'classic_shortcode' => $results['classic_shortcode']['outcome'] ?? array(),
			),
			'cleanup'          => $cleanup,
			'sensitive_output' => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f20_fail( 'The guarded F20 compatibility verification failed.' );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Emits only sanitized booleans and counts.
echo json_encode(
	array(
		'classification'           => 'live_disposable_compatibility_matrix',
		'modes_verified'           => 2,
		'runs_verified'            => 4,
		'hpos_block_passed'        => true,
		'classic_shortcode_passed' => true,
		'equivalent_outcomes'      => true,
		'licenses_per_run'         => 2,
		'missing_slots_per_run'    => 0,
		'source_unchanged'         => true,
		'cleanup_committed'        => true,
		'outbound_email_sent'      => false,
		'sensitive_output'         => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
