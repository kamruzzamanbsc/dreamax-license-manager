<?php
/**
 * Verifies the complete live schema migration matrix on disposable prefixes.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

use Dreamax\LicenseManager\Database\Installer;
use Dreamax\LicenseManager\Database\Schema;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/release-tools.php';

const DREAMAX_LM_F02_PREFIXES = array(
	'dlm_f02_disposable_1_',
	'dlm_f02_disposable_2_',
	'dlm_f02_disposable_3_',
	'dlm_f02_disposable_4_',
);

/**
 * Stops with a sanitized failure.
 *
 * @param string $message Failure message.
 */
function dreamax_lm_f02_fail( string $message ): never {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Sanitized CLI failures belong on stderr.
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
}

/**
 * Returns every database object owned by the fixed verifier prefixes.
 *
 * @return array<string,string> Object name to object type.
 */
function dreamax_lm_f02_owned_objects(): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact residue discovery requires current database metadata.
	$rows    = $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N );
	$objects = array();
	foreach ( is_array( $rows ) ? $rows : array() as $row ) {
		$name = (string) ( $row[0] ?? '' );
		foreach ( DREAMAX_LM_F02_PREFIXES as $prefix ) {
			if ( str_starts_with( $name, $prefix ) ) {
				$objects[ $name ] = strtoupper( (string) ( $row[1] ?? 'BASE TABLE' ) );
				break;
			}
		}
	}
	ksort( $objects );
	return $objects;
}

/**
 * Drops only fixed-prefix verifier objects.
 *
 * @return int Number of removed objects.
 * @throws RuntimeException When a target is ambiguous or cleanup fails.
 */
function dreamax_lm_f02_drop_owned_objects(): int {
	global $wpdb;
	$objects = dreamax_lm_f02_owned_objects();
	foreach ( $objects as $name => $type ) {
		if ( 1 !== preg_match( '/^dlm_f02_disposable_[1-4]_[A-Za-z0-9_]+$/D', $name ) ) {
			throw new RuntimeException( 'Cleanup refused an ambiguous database object.' );
		}
		$kind = 'VIEW' === $type ? 'VIEW' : 'TABLE';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The kind and identifier are constrained to fixed safe values.
		if ( false === $wpdb->query( "DROP {$kind} IF EXISTS `{$name}`" ) ) {
			throw new RuntimeException( 'An exact verifier-owned database object could not be removed.' );
		}
	}
	return count( $objects );
}

/**
 * Switches WordPress database access to one fixed verifier prefix.
 *
 * @param string $prefix Fixed verifier prefix.
 * @throws RuntimeException When the prefix is not verifier-owned.
 */
function dreamax_lm_f02_switch_prefix( string $prefix ): void {
	global $wpdb, $table_prefix;
	if ( ! in_array( $prefix, DREAMAX_LM_F02_PREFIXES, true ) ) {
		throw new RuntimeException( 'A migration scenario requested an unknown prefix.' );
	}
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- The guarded verifier temporarily switches only among fixed isolated prefixes.
	$table_prefix = $prefix;
	$wpdb->set_prefix( $prefix, true );
	$wpdb->set_blog_id( 1 );
	wp_cache_flush();
}

/**
 * Creates a clean single-site WordPress option store for one scenario.
 *
 * @param string $prefix Fixed verifier prefix.
 * @throws RuntimeException When prefix switching fails.
 */
function dreamax_lm_f02_bootstrap_prefix( string $prefix ): void {
	dreamax_lm_f02_switch_prefix( $prefix );
	make_db_current_silent();
	$_SERVER['HTTP_HOST']   = 'localhost';
	$_SERVER['REQUEST_URI'] = '/dlm-f02-disposable/';
	$_SERVER['HTTPS']       = 'off';
	populate_options(
		array(
			'siteurl'     => 'http://localhost/dlm-f02-disposable',
			'home'        => 'http://localhost/dlm-f02-disposable',
			'blogname'    => 'DLM F02 disposable',
			'admin_email' => 'dlm-f02@example.invalid',
		)
	);
	update_option( 'dreamax_lm_schema_version', '0', false );
}

/**
 * Returns schema-derived plugin table names for one version and prefix.
 *
 * @param string $version Supported schema version.
 * @param string $prefix Fixed verifier prefix.
 * @return list<string>
 * @throws RuntimeException When a statement does not expose a safe table name.
 */
function dreamax_lm_f02_schema_tables( string $version, string $prefix ): array {
	$tables = array();
	foreach ( ( new Schema() )->statements( $version, $prefix . 'dreamax_lm_' ) as $statement ) {
		if ( 1 !== preg_match( '/^CREATE TABLE ([A-Za-z0-9_]+)/D', trim( $statement ), $match ) ) {
			throw new RuntimeException( 'A migration statement did not expose a safe table name.' );
		}
		$tables[] = $match[1];
	}
	return $tables;
}

/**
 * Installs one historical checkpoint into the active verifier prefix.
 *
 * @param string $version Supported schema version.
 * @throws RuntimeException When installation or checkpoint storage fails.
 */
function dreamax_lm_f02_install_checkpoint( string $version ): void {
	( new Schema() )->install_version( $version );
	update_option( 'dreamax_lm_schema_version', $version, false );
	if ( (string) get_option( 'dreamax_lm_schema_version', '0' ) !== $version ) {
		throw new RuntimeException( 'A historical checkpoint could not be stored.' );
	}
}

/**
 * Inserts one opaque synthetic row into every table available at a checkpoint.
 *
 * @param string $version Starting schema version.
 * @param string $label Scenario label.
 * @throws RuntimeException When an opaque fixture cannot be inserted.
 */
function dreamax_lm_f02_seed_checkpoint( string $version, string $label ): void {
	global $wpdb;
	$prefix  = $wpdb->prefix . 'dreamax_lm_';
	$now     = '2026-08-28 00:00:00';
	$queries = array(
		"INSERT INTO {$prefix}licenses (id,public_id,key_ciphertext,key_fingerprint,normalization_profile,encryption_key_version,lifecycle_status,created_at,updated_at,metadata) VALUES (1,'{$label}-license','opaque-ciphertext',UNHEX(REPEAT('11',32)),'exact',1,'available','{$now}','{$now}','{\"fixture\":true}')",
		"INSERT INTO {$prefix}activations (id,public_id,license_id,instance_fingerprint,status,first_activated_at,activated_at,updated_at,metadata) VALUES (1,'{$label}-activation',1,UNHEX(REPEAT('22',32)),'active','{$now}','{$now}','{$now}','{\"fixture\":true}')",
		"INSERT INTO {$prefix}events (id,public_id,license_id,event_type,schema_version,actor_type,occurred_at,metadata) VALUES (1,'{$label}-event',1,'legacy_fixture',7,'system','{$now}','{\"opaque\":\"bytes\"}')",
		"INSERT INTO {$prefix}generators (id,public_id,name,configuration,created_at,updated_at) VALUES (1,'{$label}-generator','Synthetic','{\"length\":32}','{$now}','{$now}')",
		"INSERT INTO {$prefix}api_credentials (id,public_id,name,visible_prefix,secret_hash,scopes,status,created_at,updated_at) VALUES (1,'{$label}-credential','Synthetic','synthetic','non-secret-fixture-hash','[\"licenses:read\"]','active','{$now}','{$now}')",
		"INSERT INTO {$prefix}idempotency (id,scope_hash,payload_digest,api_version,operation,state,created_at,expires_at) VALUES (1,UNHEX(REPEAT('33',32)),UNHEX(REPEAT('44',32)),'v1','activate','completed','{$now}','2026-08-29 00:00:00')",
		"INSERT INTO {$prefix}rate_limits (bucket_hash,tokens,updated_microtime,expires_at) VALUES (UNHEX(REPEAT('55',32)),1.000000,1.000000,'2026-08-29 00:00:00')",
	);
	if ( version_compare( $version, '2', '>=' ) ) {
		$queries[] = "INSERT INTO {$prefix}guest_claims (id,public_id,order_id,active_order_id,target_user_id,token_hash,ownership_hash,status,expires_at,created_at,updated_at) VALUES (1,'{$label}-claim',7001,7001,8001,UNHEX(REPEAT('66',32)),UNHEX(REPEAT('77',32)),'pending','2026-08-29 00:00:00','{$now}','{$now}')";
		$queries[] = "INSERT INTO {$prefix}order_owners (order_id,customer_id,claim_id,ownership_hash,source,claimed_at,updated_at) VALUES (7001,8001,1,UNHEX(REPEAT('77',32)),'claim','{$now}','{$now}')";
	}
	foreach ( $queries as $query ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed opaque verifier fixtures target only schema-derived tables.
		if ( false === $wpdb->query( $query ) ) {
			throw new RuntimeException( 'An opaque migration fixture could not be inserted.' );
		}
	}
}

/**
 * Returns a non-output digest of columns that existed at the starting checkpoint.
 *
 * @param string $version Starting schema version.
 * @return string SHA-256 digest.
 */
function dreamax_lm_f02_preservation_digest( string $version ): string {
	global $wpdb;
	$prefix  = $wpdb->prefix . 'dreamax_lm_';
	$columns = array(
		'licenses'        => 'id,public_id,key_ciphertext,HEX(key_fingerprint),normalization_profile,encryption_key_version,lifecycle_status,created_at,updated_at,metadata',
		'activations'     => 'id,public_id,license_id,HEX(instance_fingerprint),status,first_activated_at,activated_at,updated_at,metadata',
		'events'          => 'id,public_id,license_id,event_type,schema_version,actor_type,occurred_at,metadata',
		'generators'      => 'id,public_id,name,configuration,created_at,updated_at',
		'api_credentials' => 'id,public_id,name,visible_prefix,secret_hash,scopes,status,expires_at,last_used_at,created_at,updated_at',
		'idempotency'     => 'id,HEX(scope_hash),HEX(payload_digest),api_version,operation,state,created_at,completed_at,expires_at',
		'rate_limits'     => 'HEX(bucket_hash),tokens,updated_microtime,expires_at',
	);
	if ( version_compare( $version, '2', '>=' ) ) {
		$columns['guest_claims'] = 'id,public_id,order_id,active_order_id,target_user_id,HEX(token_hash),HEX(ownership_hash),status,expires_at,created_at,updated_at';
		$columns['order_owners'] = 'order_id,customer_id,claim_id,HEX(ownership_hash),source,claimed_at,updated_at';
	}
	$snapshot = array();
	foreach ( $columns as $table => $projection ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed projections and schema-derived verifier tables are used only for internal preservation digests.
		$snapshot[ $table ] = $wpdb->get_results( "SELECT {$projection} FROM {$prefix}{$table}", ARRAY_N );
	}
	return hash( 'sha256', (string) wp_json_encode( $snapshot ) );
}

/**
 * Verifies current table count, engine, v3 columns, and required indexes.
 *
 * @throws RuntimeException When a schema statement cannot be parsed.
 */
function dreamax_lm_f02_current_schema_passes(): bool {
	global $wpdb;
	$tables = dreamax_lm_f02_schema_tables( Schema::VERSION, $wpdb->prefix );
	if ( 9 !== count( $tables ) ) {
		return false;
	}
	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Live migration acceptance requires current engine metadata.
		$engine = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s', (string) DB_NAME, $table ) );
		if ( 'InnoDB' !== $engine ) {
			return false;
		}
	}
	$credential_table = $wpdb->prefix . 'dreamax_lm_api_credentials';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-derived verifier table requires exact column metadata.
	$credential_columns = array_map( 'strval', $wpdb->get_col( "SHOW COLUMNS FROM {$credential_table}", 0 ) );
	if ( array() !== array_diff( array( 'secret_version', 'rotated_at', 'revoked_at' ), $credential_columns ) ) {
		return false;
	}
	$required_indexes = array(
		'licenses'        => array( 'public_id', 'key_fingerprint', 'order_slot', 'customer_status', 'product_status' ),
		'activations'     => array( 'public_id', 'license_instance', 'license_status' ),
		'events'          => array( 'public_id', 'license_time', 'event_time' ),
		'api_credentials' => array( 'public_id', 'status_expiry' ),
		'idempotency'     => array( 'scope_hash', 'expires_at' ),
		'guest_claims'    => array( 'public_id', 'active_order', 'token_hash', 'order_target', 'status_expiry' ),
		'order_owners'    => array( 'PRIMARY', 'claim_id', 'customer_id' ),
	);
	foreach ( $required_indexes as $suffix => $expected ) {
		$table = $wpdb->prefix . 'dreamax_lm_' . $suffix;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema-derived verifier table requires exact index metadata.
		$present = array_unique( array_map( 'strval', $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ) ) );
		if ( array() !== array_diff( $expected, $present ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Returns a non-output digest of current plugin table definitions.
 *
 * @return string SHA-256 digest.
 * @throws RuntimeException When a schema table definition is unavailable.
 */
function dreamax_lm_f02_schema_digest(): string {
	global $wpdb;
	$definitions = array();
	foreach ( dreamax_lm_f02_schema_tables( Schema::VERSION, $wpdb->prefix ) as $table ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SHOW CREATE on schema-derived verifier tables is read-only and required for exact definition snapshots.
		$row = $wpdb->get_row( "SHOW CREATE TABLE `{$table}`", ARRAY_N );
		if ( ! is_array( $row ) || ! isset( $row[1] ) ) {
			throw new RuntimeException( 'A current schema definition is unavailable.' );
		}
		$definitions[] = (string) $row[1];
	}
	return hash( 'sha256', implode( "\n", $definitions ) );
}

/**
 * Returns exact aggregate counts for the installed site's current plugin tables.
 *
 * @param array $tables Schema-derived table names.
 * @phpstan-param list<string> $tables Schema-derived table names.
 * @return array<string,int>
 * @throws RuntimeException When a table name is not schema-derived.
 */
function dreamax_lm_f02_counts( array $tables ): array {
	global $wpdb;
	$counts = array();
	foreach ( $tables as $table ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+dreamax_lm_[A-Za-z0-9_]+$/D', $table ) ) {
			throw new RuntimeException( 'An aggregate target was not schema-derived.' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted schema-derived identifiers require exact fresh aggregate counts.
		$counts[ $table ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );
	}
	return $counts;
}

$options = getopt( '', array( 'environment-marker:', 'wp-root:', 'diagnose-only', 'cleanup-only' ) );
$marker  = (string) ( $options['environment-marker'] ?? '' );
$wp_root = realpath( (string) ( $options['wp-root'] ?? '' ) );
if ( false === $wp_root || ! is_file( $wp_root . '/wp-load.php' ) ) {
	dreamax_lm_f02_fail( 'A valid WordPress root is required.' );
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
if ( ! is_plugin_active( 'dreamax-license-manager/dreamax-license-manager.php' ) ) {
	dreamax_lm_f02_fail( 'The disposable Dreamax runtime is unavailable.' );
}
if ( isset( $options['diagnose-only'] ) ) {
	echo wp_json_encode(
		array(
			'classification'        => 'sanitized_f02_read_only_diagnosis',
			'owned_objects_present' => count( dreamax_lm_f02_owned_objects() ),
			'sensitive_output'      => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 );
}
if ( isset( $options['cleanup-only'] ) ) {
	try {
		$removed = dreamax_lm_f02_drop_owned_objects();
	} catch ( Throwable $error ) {
		dreamax_lm_f02_fail( 'The exact F02 verifier cleanup failed.' );
	}
	echo wp_json_encode(
		array(
			'classification'          => 'sanitized_f02_exact_cleanup',
			'owned_objects_removed'   => $removed,
			'owned_objects_remaining' => count( dreamax_lm_f02_owned_objects() ),
			'sensitive_output'        => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	exit( 0 );
}
if ( array() !== dreamax_lm_f02_owned_objects() ) {
	dreamax_lm_f02_fail( 'Existing F02 verifier residue requires explicit cleanup.' );
}

global $wpdb, $table_prefix;
$original_prefix        = (string) $wpdb->prefix;
$original_base_prefix   = (string) $wpdb->base_prefix;
$original_blog_id       = (int) $wpdb->blogid;
$original_table_prefix  = (string) $table_prefix;
$original_plugin_tables = dreamax_lm_f02_schema_tables( Schema::VERSION, $original_prefix );
$before                 = dreamax_lm_f02_counts( $original_plugin_tables );
$checks                 = array();
$failure                = null;
$failure_stage          = null;
$cleanup_failure        = null;
$cleanup_committed      = false;
$objects_created        = 0;
$current_stage          = 'initialize';
$previous_suppression   = $wpdb->suppress_errors( true );
add_filter( 'flush_rewrite_rules_hard', '__return_false', PHP_INT_MAX );

try {
	$current_stage = 'fresh_v3';
	dreamax_lm_f02_bootstrap_prefix( DREAMAX_LM_F02_PREFIXES[0] );
	Installer::maybe_upgrade();
	$checks['fresh_v3'] = Schema::VERSION === (string) get_option( 'dreamax_lm_schema_version', '0' ) && dreamax_lm_f02_current_schema_passes();
	Installer::maybe_upgrade();
	$checks['fresh_replay'] = $checks['fresh_v3'] && dreamax_lm_f02_current_schema_passes();

	$current_stage = 'v1_to_v3';
	dreamax_lm_f02_bootstrap_prefix( DREAMAX_LM_F02_PREFIXES[1] );
	dreamax_lm_f02_install_checkpoint( '1' );
	dreamax_lm_f02_seed_checkpoint( '1', 'f02-v1' );
	$v1_digest = dreamax_lm_f02_preservation_digest( '1' );
	$v2_view   = DREAMAX_LM_F02_PREFIXES[1] . 'dreamax_lm_guest_claims';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed verifier view intentionally faults the next additive checkpoint.
	$wpdb->query( "CREATE VIEW `{$v2_view}` AS SELECT CAST(1 AS UNSIGNED) AS id" );
	$v2_failed = false;
	try {
		Installer::maybe_upgrade();
	} catch ( Throwable $error ) {
		$v2_failed = true;
	}
	$checks['v2_interruption_checkpoint'] = $v2_failed && '1' === (string) get_option( 'dreamax_lm_schema_version', '0' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed verifier fault view is removed before recovery.
	$wpdb->query( "DROP VIEW IF EXISTS `{$v2_view}`" );
	Installer::maybe_upgrade();
	Installer::maybe_upgrade();
	$checks['v1_preserved'] = dreamax_lm_f02_preservation_digest( '1' ) === $v1_digest && dreamax_lm_f02_current_schema_passes();

	$current_stage = 'v2_to_v3';
	dreamax_lm_f02_bootstrap_prefix( DREAMAX_LM_F02_PREFIXES[2] );
	dreamax_lm_f02_install_checkpoint( '2' );
	dreamax_lm_f02_seed_checkpoint( '2', 'f02-v2' );
	$v2_digest        = dreamax_lm_f02_preservation_digest( '2' );
	$credential_table = DREAMAX_LM_F02_PREFIXES[2] . 'dreamax_lm_api_credentials';
	$credential_hold  = $credential_table . '_f02_hold';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed verifier objects intentionally fault and then restore the v3 credential-table migration.
	$wpdb->query( "RENAME TABLE `{$credential_table}` TO `{$credential_hold}`" );
	$wpdb->query( "CREATE VIEW `{$credential_table}` AS SELECT id,public_id,name,visible_prefix,secret_hash,scopes,status,expires_at,last_used_at,created_at,updated_at FROM `{$credential_hold}`" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$v3_failed = false;
	try {
		Installer::maybe_upgrade();
	} catch ( Throwable $error ) {
		$v3_failed = true;
	}
	$checks['v3_interruption_checkpoint'] = $v3_failed && '2' === (string) get_option( 'dreamax_lm_schema_version', '0' );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed verifier view and held table are restored exactly before recovery.
	$wpdb->query( "DROP VIEW IF EXISTS `{$credential_table}`" );
	$wpdb->query( "RENAME TABLE `{$credential_hold}` TO `{$credential_table}`" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	Installer::maybe_upgrade();
	Installer::maybe_upgrade();
	$checks['v2_preserved'] = dreamax_lm_f02_preservation_digest( '2' ) === $v2_digest && dreamax_lm_f02_current_schema_passes();

	$current_stage = 'v1_interruption';
	dreamax_lm_f02_bootstrap_prefix( DREAMAX_LM_F02_PREFIXES[3] );
	$v1_view = DREAMAX_LM_F02_PREFIXES[3] . 'dreamax_lm_licenses';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed verifier view intentionally faults the first checkpoint.
	$wpdb->query( "CREATE VIEW `{$v1_view}` AS SELECT CAST(1 AS UNSIGNED) AS id" );
	$v1_failed = false;
	try {
		Installer::maybe_upgrade();
	} catch ( Throwable $error ) {
		$v1_failed = true;
	}
	$checks['v1_interruption_checkpoint'] = $v1_failed && '0' === (string) get_option( 'dreamax_lm_schema_version', '0' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed verifier fault view is removed before recovery.
	$wpdb->query( "DROP VIEW IF EXISTS `{$v1_view}`" );
	Installer::maybe_upgrade();
	Installer::maybe_upgrade();
	$checks['v1_recovery'] = Schema::VERSION === (string) get_option( 'dreamax_lm_schema_version', '0' ) && dreamax_lm_f02_current_schema_passes();

	$current_stage = 'prefix_isolation';
	dreamax_lm_f02_switch_prefix( DREAMAX_LM_F02_PREFIXES[1] );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact cross-prefix fixture counts require fresh reads from fixed verifier tables.
	$v1_label_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id='f02-v1-license'" );
	$v2_label_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id='f02-v2-license'" );
	dreamax_lm_f02_switch_prefix( DREAMAX_LM_F02_PREFIXES[2] );
	$v2_own_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id='f02-v2-license'" );
	$v1_cross_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}dreamax_lm_licenses WHERE public_id='f02-v1-license'" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$checks['second_prefix_isolation'] = 1 === $v1_label_count && 0 === $v2_label_count && 1 === $v2_own_count && 0 === $v1_cross_count;

	$current_stage = 'future_version';
	dreamax_lm_f02_switch_prefix( DREAMAX_LM_F02_PREFIXES[0] );
	$future_before        = dreamax_lm_f02_counts( dreamax_lm_f02_schema_tables( Schema::VERSION, DREAMAX_LM_F02_PREFIXES[0] ) );
	$future_schema_before = dreamax_lm_f02_schema_digest();
	update_option( 'dreamax_lm_schema_version', '999', false );
	Installer::maybe_upgrade();
	$checks['future_version_preserved'] = '999' === (string) get_option( 'dreamax_lm_schema_version', '0' )
		&& dreamax_lm_f02_counts( dreamax_lm_f02_schema_tables( Schema::VERSION, DREAMAX_LM_F02_PREFIXES[0] ) ) === $future_before
		&& dreamax_lm_f02_schema_digest() === $future_schema_before;

	$objects_created = count( dreamax_lm_f02_owned_objects() );
	$current_stage   = 'contract_assertions';
	if ( in_array( false, $checks, true ) ) {
		throw new RuntimeException( 'One or more live F02 migration contracts failed.' );
	}
} catch ( Throwable $error ) {
	$failure       = $error;
	$failure_stage = $current_stage;
} finally {
	try {
		dreamax_lm_f02_drop_owned_objects();
		$cleanup_committed = array() === dreamax_lm_f02_owned_objects();
	} catch ( Throwable $error ) {
		$cleanup_failure = $error;
	}
	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the exact original WordPress prefix after isolated cleanup.
	$table_prefix = $original_table_prefix;
	$wpdb->set_prefix( $original_base_prefix, true );
	$wpdb->set_blog_id( $original_blog_id );
	$wpdb->suppress_errors( $previous_suppression );
	wp_cache_flush();
	remove_filter( 'flush_rewrite_rules_hard', '__return_false', PHP_INT_MAX );
}

$after                = dreamax_lm_f02_counts( $original_plugin_tables );
$aggregates_unchanged = $before === $after;
if ( $cleanup_failure instanceof Throwable || ! $cleanup_committed || ! $aggregates_unchanged ) {
	dreamax_lm_f02_fail( 'The isolated F02 cleanup or aggregate restoration failed.' );
}
if ( $failure instanceof Throwable ) {
	$failed_contracts = array_keys( array_filter( $checks, static fn ( bool $passed ): bool => ! $passed ) );
	echo wp_json_encode(
		array(
			'classification'       => 'sanitized_f02_acceptance_failure',
			'failure_stage'        => $failure_stage,
			'failed_contracts'     => $failed_contracts,
			'contracts_recorded'   => count( $checks ),
			'cleanup_committed'    => $cleanup_committed,
			'aggregates_unchanged' => $aggregates_unchanged,
			'sensitive_output'     => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
	dreamax_lm_f02_fail( 'The guarded live F02 migration matrix failed.' );
}

echo wp_json_encode(
	array(
		'classification'            => 'live_disposable_mysql_migration_matrix',
		'contracts_passed'          => count( $checks ),
		'fresh_v3'                  => $checks['fresh_v3'],
		'fresh_replay'              => $checks['fresh_replay'],
		'v1_to_v3_data_preserved'   => $checks['v1_preserved'],
		'v2_to_v3_data_preserved'   => $checks['v2_preserved'],
		'v1_interruption_recovered' => $checks['v1_interruption_checkpoint'] && $checks['v1_recovery'],
		'v2_interruption_recovered' => $checks['v2_interruption_checkpoint'],
		'v3_interruption_recovered' => $checks['v3_interruption_checkpoint'],
		'second_prefix_isolated'    => $checks['second_prefix_isolation'],
		'future_version_preserved'  => $checks['future_version_preserved'],
		'temporary_objects_created' => $objects_created,
		'cleanup_committed'         => $cleanup_committed,
		'owned_objects_remaining'   => count( dreamax_lm_f02_owned_objects() ),
		'aggregates_unchanged'      => $aggregates_unchanged,
		'outbound_email_sent'       => false,
		'sensitive_output'          => false,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
