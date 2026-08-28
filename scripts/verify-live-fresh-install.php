<?php
/**
 * Verifies a fresh single-site activation inside an isolated disposable table prefix.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Database\Installer;
use Dreamax\LicenseManager\Database\Schema;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\Support\Capabilities;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/release-tools.php';

const DREAMAX_LM_F01_PREFIX          = 'dlm_f01_disposable_';
const DREAMAX_LM_F01_RECOVERY_PREFIX = 'dlm_f01_recovery_';

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Failure message.
 */
function dreamax_lm_f01_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI verifier failures must go to stderr without rendering sensitive context.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Returns table names from one schema snapshot.
 *
 * @param Schema $schema Schema service.
 * @param string $prefix Plugin table prefix.
 * @return list<string>
 * @throws RuntimeException When a schema statement does not expose a safe table name.
 */
function dreamax_lm_f01_schema_tables( Schema $schema, string $prefix ): array {
	$tables = array();
	foreach ( $schema->statements( Schema::VERSION, $prefix ) as $statement ) {
		if ( 1 !== preg_match( '/^CREATE TABLE ([A-Za-z0-9_]+)/D', trim( $statement ), $match ) ) {
			throw new RuntimeException( 'A schema statement did not expose a safe table name.' );
		}
		$tables[] = $match[1];
	}
	return $tables;
}

/**
 * Returns every current database table owned by the exact verifier prefix.
 *
 * @return list<string>
 */
function dreamax_lm_f01_owned_tables(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded residue discovery requires fresh database table names.
	$all_tables = $wpdb->get_col( 'SHOW TABLES' );
	$owned      = array();
	foreach ( is_array( $all_tables ) ? $all_tables : array() as $table ) {
		$table = (string) $table;
		if ( str_starts_with( $table, DREAMAX_LM_F01_PREFIX ) ) {
			$owned[] = $table;
		}
	}
	sort( $owned );
	return $owned;
}

/**
 * Returns every exact recovery table created for verifier-owned cleanup.
 *
 * @return list<string>
 */
function dreamax_lm_f01_recovery_tables(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Guarded recovery discovery requires fresh database table names.
	$all_tables = $wpdb->get_col( 'SHOW TABLES' );
	$recovery   = array();
	foreach ( is_array( $all_tables ) ? $all_tables : array() as $table ) {
		$table = (string) $table;
		if ( str_starts_with( $table, DREAMAX_LM_F01_RECOVERY_PREFIX ) ) {
			$recovery[] = $table;
		}
	}
	sort( $recovery );
	return $recovery;
}

/**
 * Creates exact restorable database copies before verifier-owned tables are dropped.
 *
 * @return int Number of verified recovery tables.
 * @throws RuntimeException When a target is ambiguous, recovery already exists, or copying fails.
 */
function dreamax_lm_f01_backup_owned_tables(): int {
	global $wpdb;
	$owned = dreamax_lm_f01_owned_tables();
	if ( array() !== dreamax_lm_f01_recovery_tables() ) {
		throw new RuntimeException( 'Recovery refused because an earlier exact backup exists.' );
	}
	foreach ( $owned as $table ) {
		$suffix   = substr( $table, strlen( DREAMAX_LM_F01_PREFIX ) );
		$recovery = DREAMAX_LM_F01_RECOVERY_PREFIX . $suffix;
		if (
			1 !== preg_match( '/^dlm_f01_disposable_[A-Za-z0-9_]+$/D', $table )
			|| 1 !== preg_match( '/^dlm_f01_recovery_[A-Za-z0-9_]+$/D', $recovery )
		) {
			throw new RuntimeException( 'Recovery refused an ambiguous table target.' );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Both identifiers are constrained to fixed verifier-only prefixes and safe characters.
		if ( false === $wpdb->query( "CREATE TABLE `{$recovery}` LIKE `{$table}`" ) ) {
			throw new RuntimeException( 'An exact recovery table could not be created.' );
		}
		if ( false === $wpdb->query( "INSERT INTO `{$recovery}` SELECT * FROM `{$table}`" ) ) {
			throw new RuntimeException( 'An exact recovery table could not be populated.' );
		}
		$source_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$recovery_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$recovery}`" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $source_count !== $recovery_count ) {
			throw new RuntimeException( 'An exact recovery row-count check failed.' );
		}
	}
	return count( $owned );
}

/**
 * Drops only exact verifier recovery tables while their source originals still exist.
 *
 * @return int Number of dropped recovery tables.
 * @throws RuntimeException When cleanup encounters an ambiguous target or database failure.
 */
function dreamax_lm_f01_drop_recovery_tables(): int {
	global $wpdb;
	$tables = dreamax_lm_f01_recovery_tables();
	foreach ( $tables as $table ) {
		if ( 1 !== preg_match( '/^dlm_f01_recovery_[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'Recovery cleanup refused an ambiguous table target.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The identifier is constrained to the exact verifier recovery prefix and safe character set.
		if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ) ) {
			throw new RuntimeException( 'An exact verifier recovery table could not be removed.' );
		}
	}
	return count( $tables );
}

/**
 * Drops only exact verifier-owned tables.
 *
 * @return int Number of dropped tables.
 * @throws RuntimeException When cleanup encounters an ambiguous target or database failure.
 */
function dreamax_lm_f01_drop_owned_tables(): int {
	global $wpdb;
	$tables = dreamax_lm_f01_owned_tables();
	foreach ( $tables as $table ) {
		if ( 1 !== preg_match( '/^dlm_f01_disposable_[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'Cleanup refused an ambiguous table target.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The identifier is constrained to the exact verifier prefix and safe character set.
		if ( false === $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ) ) {
			throw new RuntimeException( 'An exact verifier-owned table could not be removed.' );
		}
	}
	return count( $tables );
}

/**
 * Returns exact row counts for the current installed plugin tables.
 *
 * @param array $tables Trusted schema-derived table names.
 * @phpstan-param list<string> $tables Trusted schema-derived table names.
 * @return array<string,int>
 * @throws RuntimeException When a table name was not schema-derived.
 */
function dreamax_lm_f01_table_counts( array $tables ): array {
	global $wpdb;
	$counts = array();
	foreach ( $tables as $table ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+dreamax_lm_[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'An aggregate table name was not schema-derived.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted schema-derived identifiers require exact fresh aggregate counts.
		$counts[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}
	return $counts;
}

/**
 * Counts scheduled instances of one hook in the active prefix.
 */
function dreamax_lm_f01_cleanup_schedule_count(): int {
	$count = 0;
	foreach ( _get_cron_array() as $hooks ) {
		if ( isset( $hooks['dreamax_lm_cleanup'] ) && is_array( $hooks['dreamax_lm_cleanup'] ) ) {
			$count += count( $hooks['dreamax_lm_cleanup'] );
		}
	}
	return $count;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:', 'cleanup-only', 'diagnose-only', 'discard-recovery' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f01_fail( 'A valid WordPress root is required.' );
}
if ( ! defined( 'DISABLE_WP_CRON' ) ) {
	define( 'DISABLE_WP_CRON', true );
}
if ( ! defined( 'WP_USE_THEMES' ) ) {
	define( 'WP_USE_THEMES', false );
}
require_once $wp_root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

DisposableEnvironmentGuard::assertSafe(
	array(
		'marker'   => $marker,
		'database' => defined( 'DB_NAME' ) ? (string) DB_NAME : '',
		'site_url' => (string) get_option( 'siteurl' ),
	)
);

if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) || ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Install' ) ) {
	dreamax_lm_f01_fail( 'The disposable WordPress/WooCommerce/plugin runtime is unavailable.' );
}
if ( isset( $options['diagnose-only'] ) ) {
	echo wp_json_encode(
		array(
			'classification'          => 'sanitized_f01_read_only_diagnosis',
			'owned_tables_present'    => count( dreamax_lm_f01_owned_tables() ),
			'recovery_tables_present' => count( dreamax_lm_f01_recovery_tables() ),
			'sensitive_output'        => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 );
}
if ( isset( $options['discard-recovery'] ) ) {
	if ( array() !== dreamax_lm_f01_owned_tables() ) {
		dreamax_lm_f01_fail( 'Recovery discard refused while verifier-owned originals exist.' );
	}
	try {
		$recovery_removed = dreamax_lm_f01_drop_recovery_tables();
	} catch ( Throwable $error ) {
		dreamax_lm_f01_fail( 'The exact verifier recovery cleanup failed.' );
	}
	echo wp_json_encode(
		array(
			'classification'            => 'sanitized_f01_recovery_discard',
			'recovery_tables_removed'   => $recovery_removed,
			'recovery_tables_remaining' => count( dreamax_lm_f01_recovery_tables() ),
			'owned_tables_remaining'    => count( dreamax_lm_f01_owned_tables() ),
			'sensitive_output'          => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 );
}
if ( isset( $options['cleanup-only'] ) ) {
	try {
		$recovery_rotated = 0;
		if ( array() !== dreamax_lm_f01_owned_tables() && array() !== dreamax_lm_f01_recovery_tables() ) {
			$recovery_rotated = dreamax_lm_f01_drop_recovery_tables();
		}
		$backed_up = dreamax_lm_f01_backup_owned_tables();
		$dropped   = dreamax_lm_f01_drop_owned_tables();
	} catch ( Throwable $error ) {
		dreamax_lm_f01_fail( 'The exact verifier-owned backup or cleanup failed.' );
	}
	echo wp_json_encode(
		array(
			'classification'          => 'sanitized_f01_exact_cleanup',
			'recovery_tables_rotated' => $recovery_rotated,
			'owned_tables_backed_up'  => $backed_up,
			'owned_tables_removed'    => $dropped,
			'owned_tables_remaining'  => count( dreamax_lm_f01_owned_tables() ),
			'recovery_tables_present' => count( dreamax_lm_f01_recovery_tables() ),
			'sensitive_output'        => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 );
}
if ( array() !== dreamax_lm_f01_owned_tables() ) {
	dreamax_lm_f01_fail( 'Existing verifier-owned tables require the explicit cleanup mode.' );
}

global $wpdb, $wp_roles, $table_prefix;
$schema                 = new Schema();
$original_prefix        = (string) $wpdb->prefix;
$original_base_prefix   = (string) $wpdb->base_prefix;
$original_blog_id       = (int) $wpdb->blogid;
$original_table_prefix  = (string) $table_prefix;
$original_roles         = $wp_roles;
$original_plugin_tables = dreamax_lm_f01_schema_tables( $schema, $original_prefix . 'dreamax_lm_' );
$before                 = dreamax_lm_f01_table_counts( $original_plugin_tables );
$checks                 = array();
$failure                = null;
$failure_stage          = null;
$cleanup_failure        = null;
$cleanup_committed      = false;
$created_table_count    = 0;
$mail_calls             = 0;
$current_stage          = 'switch_prefix';
$prefix_switched        = false;
$plugin_activated       = false;

add_filter( 'flush_rewrite_rules_hard', '__return_false', PHP_INT_MAX );
add_filter(
	'pre_wp_mail',
	static function () use ( &$mail_calls ): bool {
		++$mail_calls;
		return true;
	},
	PHP_INT_MAX
);

try {
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The isolated verifier temporarily switches only to its exact table prefix and restores it in finally.
	$table_prefix = DREAMAX_LM_F01_PREFIX;
	$wpdb->set_prefix( DREAMAX_LM_F01_PREFIX, true );
	$prefix_switched = true;
	$wpdb->set_blog_id( 1 );
	wp_cache_flush();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Let the clean-prefix bootstrap construct roles only after it creates the isolated options table.
	$wp_roles = null;

	$current_stage = 'fresh_wordpress_schema';
	make_db_current_silent();
	$checks['wordpress_core_tables'] = 12 === count( dreamax_lm_f01_owned_tables() );
	$_SERVER['HTTP_HOST']            = 'localhost';
	$_SERVER['REQUEST_URI']          = '/dlm-f01-disposable/';
	$_SERVER['HTTPS']                = 'off';
	populate_options(
		array(
			'siteurl'     => 'http://localhost/dlm-f01-disposable',
			'home'        => 'http://localhost/dlm-f01-disposable',
			'blogname'    => 'DLM F01 disposable',
			'admin_email' => 'dlm-f01@example.invalid',
		)
	);
	populate_roles();
	wp_cache_flush();
	$checks['wordpress_installed'] = is_blog_installed();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Re-read the roles created inside the isolated verifier prefix.
	$wp_roles = new WP_Roles();

	$current_stage = 'woocommerce_roles';
	WC_Install::create_roles();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Re-read the roles created inside the isolated verifier prefix.
	$wp_roles = new WP_Roles();

	$current_stage = 'plugin_activation';
	Installer::activate();
	$plugin_activated             = true;
	$plugin_tables                = dreamax_lm_f01_schema_tables( $schema, DREAMAX_LM_F01_PREFIX . 'dreamax_lm_' );
	$checks['schema_table_count'] = 9 === count( $plugin_tables );
	$table_definitions            = array();
	foreach ( $plugin_tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh schema acceptance requires live engine and row checks.
		$table_status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted schema-derived table names require an exact empty-row check.
		$row_count           = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
		$table_definitions[] = is_array( $table_status ) && 'InnoDB' === $table_status['Engine'] && 0 === $row_count;
	}
	$checks['schema_innodb_empty'] = 9 === count( array_filter( $table_definitions ) );
	$checks['schema_version']      = Schema::VERSION === (string) get_option( 'dreamax_lm_schema_version', '0' );

	$administrator                        = get_role( 'administrator' );
	$shop_manager                         = get_role( 'shop_manager' );
	$customer                             = get_role( 'customer' );
	$checks['administrator_capabilities'] = $administrator instanceof WP_Role;
	foreach ( Capabilities::all() as $capability ) {
		$checks['administrator_capabilities'] = $checks['administrator_capabilities'] && $administrator instanceof WP_Role && $administrator->has_cap( $capability );
	}
	$checks['shop_manager_capabilities'] = $shop_manager instanceof WP_Role
		&& $shop_manager->has_cap( Capabilities::MANAGE )
		&& $shop_manager->has_cap( Capabilities::DIAGNOSTICS );
	foreach ( array_diff( Capabilities::all(), array( Capabilities::MANAGE, Capabilities::DIAGNOSTICS ) ) as $capability ) {
		$checks['shop_manager_capabilities'] = $checks['shop_manager_capabilities'] && ! $shop_manager->has_cap( $capability );
	}
	$checks['customer_isolation'] = $customer instanceof WP_Role;
	foreach ( Capabilities::all() as $capability ) {
		$checks['customer_isolation'] = $checks['customer_isolation'] && $customer instanceof WP_Role && ! $customer->has_cap( $capability );
	}
	$checks['cleanup_scheduled_once'] = 1 === dreamax_lm_f01_cleanup_schedule_count();

	$current_stage = 'activation_replay';
	Installer::activate();
	$checks['activation_replay'] = 1 === dreamax_lm_f01_cleanup_schedule_count()
		&& Schema::VERSION === (string) get_option( 'dreamax_lm_schema_version', '0' )
		&& 9 === count( dreamax_lm_f01_schema_tables( $schema, DREAMAX_LM_F01_PREFIX . 'dreamax_lm_' ) );

	$created_table_count = count( dreamax_lm_f01_owned_tables() );
	$current_stage       = 'contract_assertions';
	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'One or more F01 fresh-install contracts failed.' );
	}
} catch ( Throwable $error ) {
	$failure       = $error;
	$failure_stage = $current_stage;
} finally {
	try {
		$current_stage = 'exact_cleanup';
		if ( $prefix_switched && $plugin_activated ) {
			Installer::deactivate();
		}
		dreamax_lm_f01_drop_owned_tables();
		$cleanup_committed = array() === dreamax_lm_f01_owned_tables();
	} catch ( Throwable $error ) {
		$cleanup_failure = $error;
	}

	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact original WordPress prefix after isolated cleanup.
	$table_prefix = $original_table_prefix;
	$wpdb->set_prefix( $original_base_prefix, true );
	$wpdb->set_blog_id( $original_blog_id );
	wp_cache_flush();
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact original roles object after isolated cleanup.
	$wp_roles = $original_roles;
	remove_filter( 'flush_rewrite_rules_hard', '__return_false', PHP_INT_MAX );
}

$after                = dreamax_lm_f01_table_counts( $original_plugin_tables );
$aggregates_unchanged = $before === $after;
if ( $cleanup_failure instanceof Throwable || ! $cleanup_committed || ! $aggregates_unchanged ) {
	echo wp_json_encode(
		array(
			'classification'         => 'sanitized_f01_cleanup_failure',
			'failure_stage'          => $failure_stage,
			'acceptance_failure'     => $failure instanceof Throwable,
			'cleanup_committed'      => $cleanup_committed,
			'aggregates_unchanged'   => $aggregates_unchanged,
			'owned_tables_remaining' => count( dreamax_lm_f01_owned_tables() ),
			'sensitive_output'       => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f01_fail( 'The isolated fresh-install cleanup did not complete.' );
}
if ( $failure instanceof Throwable ) {
	$failed_contracts = array();
	foreach ( $checks as $contract => $passed ) {
		if ( ! $passed ) {
			$failed_contracts[] = $contract;
		}
	}
	echo wp_json_encode(
		array(
			'classification'       => 'sanitized_f01_acceptance_failure',
			'failure_stage'        => $failure_stage,
			'failed_contracts'     => $failed_contracts,
			'contracts_recorded'   => count( $checks ),
			'cleanup_committed'    => $cleanup_committed,
			'aggregates_unchanged' => $aggregates_unchanged,
			'sensitive_output'     => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f01_fail( 'The guarded F01 verification failed.' );
}

echo wp_json_encode(
	array(
		'classification'                 => 'live_disposable_fresh_single_site_prefix',
		'contracts_passed'               => count( $checks ),
		'wordpress_core_tables'          => $checks['wordpress_core_tables'],
		'fresh_wordpress_installed'      => $checks['wordpress_installed'],
		'plugin_schema_tables'           => 9,
		'plugin_tables_innodb_and_empty' => $checks['schema_innodb_empty'],
		'schema_version_current'         => $checks['schema_version'],
		'administrator_defaults'         => $checks['administrator_capabilities'],
		'shop_manager_defaults'          => $checks['shop_manager_capabilities'],
		'customer_isolated'              => $checks['customer_isolation'],
		'cleanup_job_scheduled_once'     => $checks['cleanup_scheduled_once'],
		'activation_replay_safe'         => $checks['activation_replay'],
		'temporary_tables_created'       => $created_table_count,
		'cleanup_committed'              => $cleanup_committed,
		'owned_tables_remaining'         => count( dreamax_lm_f01_owned_tables() ),
		'aggregates_unchanged'           => $aggregates_unchanged,
		'outbound_email_sent'            => false,
		'intercepted_mail_calls'         => $mail_calls,
		'sensitive_output'               => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
