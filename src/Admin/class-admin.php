<?php
/**
 * Defines the Admin class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Admin;

use Dreamax\LicenseManager\Credentials\CredentialAdminOperation;
use Dreamax\LicenseManager\Credentials\CredentialService;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\ImportExport\CsvController;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LifecycleService;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\Licenses\MerchantMetadata;
use Dreamax\LicenseManager\Support\Base64Url;
use Dreamax\LicenseManager\Support\Capabilities;
use Dreamax\LicenseManager\Support\Diagnostics;
use Throwable;

/**
 * Handles Admin operations.
 */
final class Admin {
	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		// Register the parent before separately owned submenus so WordPress creates matching page hooks and admin.php routes.
		add_action( 'admin_menu', array( $this, 'menu' ), 9 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_dreamax_lm_create_license', array( $this, 'create_license' ) );
		add_action( 'admin_post_dreamax_lm_bulk_lifecycle', array( $this, 'bulk_lifecycle' ) );
		add_action( 'admin_post_dreamax_lm_reassign_license', array( $this, 'reassign_license' ) );
		add_action( 'admin_post_dreamax_lm_save_license_notes', array( $this, 'save_license_notes' ) );
		add_action( 'admin_post_dreamax_lm_generate_master_key', array( $this, 'master_key' ) );
		add_action( 'admin_post_dreamax_lm_create_credential', array( $this, 'create_credential' ) );
		add_action( 'admin_post_dreamax_lm_rotate_credential', array( $this, 'rotate_credential' ) );
		add_action( 'admin_post_dreamax_lm_revoke_credential', array( $this, 'revoke_credential' ) );
	}

	/**
	 * Loads the focused administration experience only on plugin-owned screens.
	 *
	 * @param string $hook_suffix Current WordPress administration page hook.
	 */
	public function assets( string $hook_suffix ): void {
		unset( $hook_suffix );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The page slug is used only to scope static administration assets.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( ! str_starts_with( $page, 'dreamax-license-manager' ) ) {
			return;
		}

		$asset_version = DREAMAX_LM_VERSION . '.portal-setup-2';
		wp_enqueue_style( 'dreamax-lm-admin', DREAMAX_LM_URL . 'assets/css/admin.css', array(), $asset_version );
		wp_enqueue_script( 'dreamax-lm-admin', DREAMAX_LM_URL . 'assets/js/admin.js', array(), $asset_version, true );
		wp_localize_script(
			'dreamax-lm-admin',
			'dreamaxLmAdmin',
			array(
				'noneSelected'    => __( 'Select one or more licenses to continue.', 'dreamax-license-manager' ),
				'oneSelected'     => __( '1 license selected', 'dreamax-license-manager' ),
				/* translators: %d: Number of selected licenses. */
				'manySelected'    => __( '%d licenses selected', 'dreamax-license-manager' ),
				'showKey'         => __( 'Show', 'dreamax-license-manager' ),
				'hideKey'         => __( 'Hide', 'dreamax-license-manager' ),
				'noFileSelected'  => __( 'No file selected', 'dreamax-license-manager' ),
				'checkImport'     => __( 'Check import', 'dreamax-license-manager' ),
				'commitImport'    => __( 'Import licenses', 'dreamax-license-manager' ),
				'safeExport'      => __( 'Download safe export', 'dreamax-license-manager' ),
				'sensitiveExport' => __( 'Download sensitive export', 'dreamax-license-manager' ),
				'selectedExport'  => __( 'Download masked CSV', 'dreamax-license-manager' ),
				'applyAction'     => __( 'Apply action', 'dreamax-license-manager' ),
			)
		);
	}

	/**
	 * Handles the menu operation.
	 */
	public function menu(): void {
		add_menu_page( __( 'License Manager', 'dreamax-license-manager' ), __( 'License Manager', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager', array( $this, 'licenses_page' ), 'dashicons-admin-network', 56 );
		add_submenu_page( 'dreamax-license-manager', __( 'Add license', 'dreamax-license-manager' ), __( 'Add license', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-add', array( $this, 'add_page' ) );
		add_submenu_page( 'dreamax-license-manager', __( 'Import and export', 'dreamax-license-manager' ), __( 'Import / Export', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-transfer', array( $this, 'transfer_page' ) );
		add_submenu_page( 'dreamax-license-manager', __( 'Recent activity', 'dreamax-license-manager' ), __( 'Activity', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-activity', array( $this, 'activity_page' ) );
		add_submenu_page( 'dreamax-license-manager', __( 'API credentials', 'dreamax-license-manager' ), __( 'API credentials', 'dreamax-license-manager' ), Capabilities::CREDENTIALS, 'dreamax-license-manager-credentials', array( $this, 'credentials_page' ) );
		add_submenu_page( 'dreamax-license-manager', __( 'System status', 'dreamax-license-manager' ), __( 'System status', 'dreamax-license-manager' ), Capabilities::DIAGNOSTICS, 'dreamax-license-manager-status', array( $this, 'status_page' ) );
		/**
		 * Register the hidden license-details page.
		 *
		 * @phpstan-ignore argument.type (A null parent intentionally creates a hidden WordPress admin page.)
		 */
		add_submenu_page( null, __( 'License details', 'dreamax-license-manager' ), __( 'License details', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-license', array( $this, 'license_page' ) );
	}

	/**
	 * Handles the licenses page operation.
	 */
	public function licenses_page(): void {
		global $wpdb;
		$this->authorize( Capabilities::MANAGE );
		/* phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are read-only admin filters and notices behind a capability check. */
		$page        = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$product     = isset( $_GET['product'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['product'] ) ) : '';
		$status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$order_id    = isset( $_GET['order_id'] ) ? max( 0, (int) $_GET['order_id'] ) : 0;
		$customer_id = isset( $_GET['customer_id'] ) ? max( 0, (int) $_GET['customer_id'] ) : 0;
		$expiry      = isset( $_GET['expiry'] ) ? sanitize_key( wp_unslash( (string) $_GET['expiry'] ) ) : '';
		$orderby     = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'created';
		$order       = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( (string) $_GET['order'] ) ) : 'desc';
		$created_id  = isset( $_GET['created'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['created'] ) ) : '';

		$valid_status     = in_array( $status, array( 'available', 'assigned', 'suspended', 'revoked' ), true ) ? $status : '';
		$valid_expiry     = in_array( $expiry, array( 'expired', 'soon', 'lifetime' ), true ) ? $expiry : '';
		$valid_product    = 1 === preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product ) ? $product : '';
		$sort_columns     = array(
			'created'     => 'l.created_at',
			'license'     => 'l.public_id',
			'product'     => 'l.product_public_id',
			'status'      => 'l.lifecycle_status',
			'activations' => 'active_count',
			'expiry'      => 'l.expires_at',
		);
		$orderby          = isset( $sort_columns[ $orderby ] ) ? $orderby : 'created';
		$order            = in_array( $order, array( 'asc', 'desc' ), true ) ? $order : 'desc';
		$order_direction  = 'asc' === $order ? 'ASC' : 'DESC';
		$order_clause     = $sort_columns[ $orderby ] . ' ' . $order_direction . ', l.id ' . $order_direction;
		$search_pattern   = '%' . $wpdb->esc_like( $search ) . '%';
		$now              = gmdate( 'Y-m-d H:i:s' );
		$soon             = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		$table            = $wpdb->prefix . 'dreamax_lm_licenses';
		$activation_table = $wpdb->prefix . 'dreamax_lm_activations';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The order clause is assembled only from the fixed allowlists above.
		$rows_query = "SELECT l.*, COALESCE(a.active_count,0) AS active_count
			FROM %i l
			LEFT JOIN (SELECT license_id,COUNT(*) AS active_count FROM %i WHERE status='active' GROUP BY license_id) a ON a.license_id=l.id
			WHERE (%d=0 OR l.public_id LIKE %s OR l.product_public_id LIKE %s)
			AND (%d=0 OR l.product_public_id=%s)
			AND (%d=0 OR l.lifecycle_status=%s)
			AND (%d=0 OR l.order_id=%d)
			AND (%d=0 OR l.customer_id=%d)
			AND (%s='' OR (%s='expired' AND l.expires_at IS NOT NULL AND l.expires_at<=%s) OR (%s='soon' AND l.expires_at>%s AND l.expires_at<=%s) OR (%s='lifetime' AND l.expires_at IS NULL))
			ORDER BY {$order_clause} LIMIT 50 OFFSET %d";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This administration query must be fresh.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The query has fixed placeholders; only its allowlisted ORDER BY column and direction are interpolated.
				$rows_query,
				$table,
				$activation_table,
				'' === $search ? 0 : 1,
				$search_pattern,
				$search_pattern,
				'' === $valid_product ? 0 : 1,
				$valid_product,
				'' === $valid_status ? 0 : 1,
				$valid_status,
				$order_id > 0 ? 1 : 0,
				$order_id,
				$customer_id > 0 ? 1 : 0,
				$customer_id,
				$valid_expiry,
				$valid_expiry,
				$now,
				$valid_expiry,
				$now,
				$soon,
				$valid_expiry,
				( $page - 1 ) * 50
			),
			ARRAY_A
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- This dashboard aggregate must be current.
		$summary     = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(CASE WHEN lifecycle_status='available' THEN 1 ELSE 0 END) AS available, SUM(CASE WHEN lifecycle_status='assigned' THEN 1 ELSE 0 END) AS assigned, SUM(CASE WHEN lifecycle_status IN ('suspended','revoked') OR (expires_at IS NOT NULL AND expires_at<=%s) THEN 1 ELSE 0 END) AS attention FROM %i", $now, $table ), ARRAY_A );
		$summary     = is_array( $summary ) ? $summary : array();
		$insights    = ( new InventoryInsights() )->summary();
		$has_filters = '' !== $search || '' !== $valid_product || '' !== $status || $order_id > 0 || $customer_id > 0 || '' !== $expiry;
		$add_url     = admin_url( 'admin.php?page=dreamax-license-manager-add' );
		$clear_url   = admin_url( 'admin.php?page=dreamax-license-manager' );
		$sort_args   = array( 'page' => 'dreamax-license-manager' );
		foreach ( array(
			's'           => $search,
			'product'     => $valid_product,
			'status'      => $valid_status,
			'order_id'    => $order_id,
			'customer_id' => $customer_id,
			'expiry'      => $valid_expiry,
		) as $key => $value ) {
			if ( '' !== $value && 0 !== $value ) {
				$sort_args[ $key ] = $value;
			}
		}

		echo '<div class="wrap dreamax-lm-admin"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'License operations', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'Licenses', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Review ownership, lifecycle, activation use, and expiry from one workspace.', 'dreamax-license-manager' ) . '</p></div><a class="button button-primary dreamax-lm-primary-action" href="' . esc_url( $add_url ) . '"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__( 'Add license', 'dreamax-license-manager' ) . '</a></header>';
		if ( '' !== $created_id ) {
			$created_url = add_query_arg(
				array(
					'page'    => 'dreamax-license-manager-license',
					'license' => $created_id,
				),
				admin_url( 'admin.php' )
			);
			echo '<div class="notice notice-success is-dismissible dreamax-lm-notice"><p><strong>' . esc_html__( 'License created successfully.', 'dreamax-license-manager' ) . '</strong> <a href="' . esc_url( $created_url ) . '">' . esc_html__( 'View license details', 'dreamax-license-manager' ) . '</a></p></div>';
		}
		if ( isset( $_GET['changed'] ) ) {
			/* translators: %d: Number of completed license operations. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%d license operation(s) completed.', 'dreamax-license-manager' ), max( 0, (int) $_GET['changed'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['failed'] ) && (int) $_GET['failed'] > 0 ) {
			/* translators: %d: Number of rejected license operations. */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( '%d operation(s) were rejected. Review the activity log and license state.', 'dreamax-license-manager' ), (int) $_GET['failed'] ) ) . '</p></div>';
		}
		/* phpcs:enable WordPress.Security.NonceVerification.Recommended */
		echo '<section class="dreamax-lm-summary" aria-label="' . esc_attr__( 'License summary', 'dreamax-license-manager' ) . '">';
		$this->summary_card( __( 'Total licenses', 'dreamax-license-manager' ), (int) ( $summary['total'] ?? 0 ), 'portfolio' );
		$this->summary_card( __( 'Available', 'dreamax-license-manager' ), (int) ( $summary['available'] ?? 0 ), 'yes-alt' );
		$this->summary_card( __( 'In circulation', 'dreamax-license-manager' ), (int) ( $summary['assigned'] ?? 0 ), 'admin-users' );
		$this->summary_card( __( 'Needs attention', 'dreamax-license-manager' ), (int) ( $summary['attention'] ?? 0 ), 'warning' );
		echo '</section>';
		if ( $insights['expiring'] > 0 || array() !== $insights['low_pools'] ) {
			echo '<section class="dreamax-lm-inventory-insights" aria-label="' . esc_attr__( 'Inventory warnings', 'dreamax-license-manager' ) . '">';
			if ( $insights['expiring'] > 0 ) {
				$expiry_url = add_query_arg(
					array(
						'page'   => 'dreamax-license-manager',
						'expiry' => 'soon',
					),
					admin_url( 'admin.php' )
				);
				/* translators: 1: license count, 2: number of days in the upcoming-expiry window. */
				$expiry_text = sprintf( __( '%1$d license(s) expire within %2$d days.', 'dreamax-license-manager' ), $insights['expiring'], $insights['expiry_days'] );
				echo '<a class="dreamax-lm-inventory-insight dreamax-lm-inventory-insight--expiry" href="' . esc_url( $expiry_url ) . '"><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><span><strong>' . esc_html( $expiry_text ) . '</strong><small>' . esc_html__( 'Review upcoming expirations', 'dreamax-license-manager' ) . '</small></span><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a>';
			}
			if ( array() !== $insights['low_pools'] ) {
				/* translators: 1: number of low pools, 2: available-key warning threshold. */
				$pool_text = sprintf( __( 'At least %1$d imported-key pool(s) have %2$d or fewer keys.', 'dreamax-license-manager' ), count( $insights['low_pools'] ), $insights['pool_threshold'] );
				echo '<div class="dreamax-lm-inventory-insight dreamax-lm-inventory-insight--pool"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span><strong>' . esc_html( $pool_text ) . '</strong><small>';
				foreach ( array_slice( $insights['low_pools'], 0, 3 ) as $index => $pool ) {
					if ( $index > 0 ) {
						echo '<span aria-hidden="true"> | </span>';
					}
					$pool_url = add_query_arg(
						array(
							'page'    => 'dreamax-license-manager',
							'product' => $pool['public_id'],
						),
						admin_url( 'admin.php' )
					);
					/* translators: %d: number of available imported keys. */
					$available = sprintf( __( '%d available', 'dreamax-license-manager' ), $pool['available'] );
					echo '<a href="' . esc_url( $pool_url ) . '">' . esc_html( $pool['name'] ) . '</a> <span>(' . esc_html( $available ) . ')</span>';
				}
				echo '</small></span></div>';
			}
			echo '</section>';
		}
		echo '<section class="dreamax-lm-panel dreamax-lm-filter-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Find licenses', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Combine filters to narrow the inventory.', 'dreamax-license-manager' ) . '</p></div>';
		if ( $has_filters ) {
			echo '<a class="button button-secondary" href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear all filters', 'dreamax-license-manager' ) . '</a>';
		}
		echo '</div>';
		echo '<form method="get" class="dreamax-lm-filter-form"><input type="hidden" name="page" value="dreamax-license-manager">';
		echo '<div class="dreamax-lm-field dreamax-lm-field--search"><label for="dreamax-lm-search">' . esc_html__( 'License or product ID', 'dreamax-license-manager' ) . '</label><input id="dreamax-lm-search" type="search" name="s" placeholder="' . esc_attr__( 'Enter public or product ID', 'dreamax-license-manager' ) . '" value="' . esc_attr( $search ) . '"></div>';
		echo '<div class="dreamax-lm-field"><label for="dreamax-lm-product">' . esc_html__( 'Product public ID', 'dreamax-license-manager' ) . '</label><input id="dreamax-lm-product" type="text" name="product" pattern="prd_[A-Za-z0-9_-]{22}" placeholder="prd_..." value="' . esc_attr( $valid_product ) . '"></div>';
		echo '<div class="dreamax-lm-field"><label for="dreamax-lm-status">' . esc_html__( 'Lifecycle state', 'dreamax-license-manager' ) . '</label><select id="dreamax-lm-status" name="status"><option value="">' . esc_html__( 'All states', 'dreamax-license-manager' ) . '</option>';
		foreach ( array(
			'available' => __( 'Available', 'dreamax-license-manager' ),
			'assigned'  => __( 'Assigned', 'dreamax-license-manager' ),
			'suspended' => __( 'Suspended', 'dreamax-license-manager' ),
			'revoked'   => __( 'Revoked', 'dreamax-license-manager' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></div><div class="dreamax-lm-field"><label for="dreamax-lm-order">' . esc_html__( 'Order ID', 'dreamax-license-manager' ) . '</label><input id="dreamax-lm-order" type="number" min="1" name="order_id" placeholder="' . esc_attr__( 'Any order', 'dreamax-license-manager' ) . '" value="' . esc_attr( $order_id ? $order_id : '' ) . '"></div><div class="dreamax-lm-field"><label for="dreamax-lm-customer">' . esc_html__( 'Customer ID', 'dreamax-license-manager' ) . '</label><input id="dreamax-lm-customer" type="number" min="1" name="customer_id" placeholder="' . esc_attr__( 'Any customer', 'dreamax-license-manager' ) . '" value="' . esc_attr( $customer_id ? $customer_id : '' ) . '"></div>';
		echo '<div class="dreamax-lm-field"><label for="dreamax-lm-expiry">' . esc_html__( 'Expiry', 'dreamax-license-manager' ) . '</label><select id="dreamax-lm-expiry" name="expiry"><option value="">' . esc_html__( 'Any expiry', 'dreamax-license-manager' ) . '</option><option value="expired"' . selected( $expiry, 'expired', false ) . '>' . esc_html__( 'Expired', 'dreamax-license-manager' ) . '</option><option value="soon"' . selected( $expiry, 'soon', false ) . '>' . esc_html__( 'Expires within 30 days', 'dreamax-license-manager' ) . '</option><option value="lifetime"' . selected( $expiry, 'lifetime', false ) . '>' . esc_html__( 'Never expires', 'dreamax-license-manager' ) . '</option></select></div><div class="dreamax-lm-filter-submit"><button class="button button-secondary dreamax-lm-filter-button"><span class="dashicons dashicons-filter" aria-hidden="true"></span>' . esc_html__( 'Apply filters', 'dreamax-license-manager' ) . '</button></div></form></section>';

		$inventory_intro = 'created' === $orderby && 'desc' === $order ? __( 'Newest licenses appear first. Select rows to perform a controlled bulk action.', 'dreamax-license-manager' ) : __( 'Select rows to perform a controlled bulk action.', 'dreamax-license-manager' );
		echo '<section class="dreamax-lm-panel dreamax-lm-inventory"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'License inventory', 'dreamax-license-manager' ) . '</h2><p>' . esc_html( $inventory_intro ) . '</p></div></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-dlm-bulk><input type="hidden" name="action" value="dreamax_lm_bulk_lifecycle"><input type="hidden" name="operation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'dreamax_lm_bulk_lifecycle' );
		echo '<div class="dreamax-lm-table-scroll"><table class="widefat dreamax-lm-table"><thead><tr><td class="check-column"><input data-dlm-select-all type="checkbox" aria-label="' . esc_attr__( 'Select all visible licenses', 'dreamax-license-manager' ) . '"></td>';
		$this->sortable_header( __( 'License', 'dreamax-license-manager' ), 'license', $orderby, $order, $sort_args );
		$this->sortable_header( __( 'Product', 'dreamax-license-manager' ), 'product', $orderby, $order, $sort_args );
		echo '<th scope="col">' . esc_html__( 'Ownership', 'dreamax-license-manager' ) . '</th>';
		$this->sortable_header( __( 'Status', 'dreamax-license-manager' ), 'status', $orderby, $order, $sort_args );
		$this->sortable_header( __( 'Activations', 'dreamax-license-manager' ), 'activations', $orderby, $order, $sort_args );
		$this->sortable_header( __( 'Expiry', 'dreamax-license-manager' ), 'expiry', $orderby, $order, $sort_args );
		echo '</tr></thead><tbody>';
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$count = (int) $row['active_count'];
			$limit = null === $row['activation_limit'] ? null : (int) $row['activation_limit'];
			if ( null === $limit ) {
				/* translators: %d: Current activation count for an unlimited license. */
				$usage = sprintf( __( '%d active; unlimited', 'dreamax-license-manager' ), $count );
			} else {
				/* translators: 1: Current activation count. 2: Activation limit. */
				$usage = sprintf( __( '%1$d of %2$d in use', 'dreamax-license-manager' ), $count, $limit );
			}
			$detail = add_query_arg(
				array(
					'page'    => 'dreamax-license-manager-license',
					'license' => (string) $row['public_id'],
				),
				admin_url( 'admin.php' )
			);
			/* translators: %s: License public ID. */
			echo '<tr><th class="check-column"><input class="dreamax-license-select" data-dlm-license-checkbox type="checkbox" name="license_ids[]" value="' . esc_attr( (string) $row['public_id'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Select license %s', 'dreamax-license-manager' ), (string) $row['public_id'] ) ) . '"></th>';
			echo '<td><a class="dreamax-lm-license-link" href="' . esc_url( $detail ) . '"><code>' . esc_html( (string) $row['public_id'] ) . '</code><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></a><span class="dreamax-lm-cell-meta">' . esc_html__( 'View details', 'dreamax-license-manager' ) . '</span></td>';
			echo '<td>';
			$this->render_product_reference( $row );
			echo '</td><td><strong>';
			$this->render_ownership_references( $row );
			echo '</strong>';
			if ( ! $row['customer_id'] && ! $row['order_id'] ) {
				echo '<span class="dreamax-lm-cell-meta">' . esc_html__( 'Created manually', 'dreamax-license-manager' ) . '</span>';
			}
			echo '</td><td><span class="dreamax-lm-status dreamax-lm-status--' . esc_attr( $this->status_key( $row ) ) . '"><span aria-hidden="true"></span>' . esc_html( $this->status_label( $row ) ) . '</span></td><td><strong>' . esc_html( $usage ) . '</strong>';
			if ( null !== $limit ) {
				echo '<progress max="' . esc_attr( (string) max( 1, $limit ) ) . '" value="' . esc_attr( (string) min( $count, max( 1, $limit ) ) ) . '" aria-label="' . esc_attr( $usage ) . '"></progress>';
			}
			if ( $count > 0 ) {
				$activations_url = add_query_arg(
					array(
						'page'    => 'dreamax-license-manager-activations',
						'license' => (string) $row['public_id'],
					),
					admin_url( 'admin.php' )
				);
				echo '<span class="dreamax-lm-cell-meta"><a href="' . esc_url( $activations_url ) . '">' . esc_html__( 'View installations', 'dreamax-license-manager' ) . '</a></span>';
			}
			if ( null !== $limit && $limit > 0 && $count >= $limit && ! in_array( $this->status_key( $row ), array( 'revoked', 'suspended', 'expired' ), true ) ) {
				echo '<span class="dreamax-lm-cell-meta dreamax-lm-capacity-warning">' . esc_html__( 'All activation slots in use', 'dreamax-license-manager' ) . '</span>';
			}
			echo '</td><td><strong>' . esc_html( $row['expires_at'] ? mysql2date( get_option( 'date_format' ), (string) $row['expires_at'], true ) : __( 'Never', 'dreamax-license-manager' ) ) . '</strong>';
			if ( $row['expires_at'] ) {
				echo '<span class="dreamax-lm-cell-meta">' . esc_html__( 'UTC', 'dreamax-license-manager' ) . '</span>';
			}
			echo '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td class="dreamax-lm-empty" colspan="7"><span class="dashicons dashicons-search" aria-hidden="true"></span><strong>' . esc_html__( 'No licenses match these filters', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'Try clearing one or more filters, or add a new license.', 'dreamax-license-manager' ) . '</p></td></tr>';
		}
		echo '</tbody></table></div><div class="dreamax-lm-bulk' . esc_attr( $rows ? '' : ' dreamax-lm-bulk--empty' ) . '"><div class="dreamax-lm-bulk-heading"><h3>' . esc_html__( 'Bulk action', 'dreamax-license-manager' ) . '</h3><p data-dlm-selection-status aria-live="polite">' . esc_html__( 'Select one or more licenses to continue.', 'dreamax-license-manager' ) . '</p></div><div class="dreamax-lm-bulk-grid"><label class="dreamax-lm-field"><span>' . esc_html__( 'Action', 'dreamax-license-manager' ) . '</span><select name="bulk_operation" data-dlm-operation required><option value="">' . esc_html__( 'Choose an action', 'dreamax-license-manager' ) . '</option><option value="suspend">' . esc_html__( 'Suspend', 'dreamax-license-manager' ) . '</option><option value="restore">' . esc_html__( 'Restore', 'dreamax-license-manager' ) . '</option><option value="revoke">' . esc_html__( 'Permanently revoke', 'dreamax-license-manager' ) . '</option><option value="extend">' . esc_html__( 'Extend expiry', 'dreamax-license-manager' ) . '</option><option value="reset">' . esc_html__( 'Reset activations', 'dreamax-license-manager' ) . '</option>';
		if ( current_user_can( Capabilities::DELETE ) ) {
			echo '<option value="delete">' . esc_html__( 'Permanently delete eligible pool records', 'dreamax-license-manager' ) . '</option>';
		}
		echo '<option value="export">' . esc_html__( 'Export selected (masked CSV)', 'dreamax-license-manager' ) . '</option></select></label><label class="dreamax-lm-field" data-dlm-extension hidden><span>' . esc_html__( 'Extension days', 'dreamax-license-manager' ) . '</span><input type="number" name="extension_days" min="1" max="3650" value="30"></label><label class="dreamax-lm-field dreamax-lm-field--reason"><span>' . esc_html__( 'Reason', 'dreamax-license-manager' ) . '</span><input type="text" name="reason" minlength="3" maxlength="500" required placeholder="' . esc_attr__( 'Required for the audit log', 'dreamax-license-manager' ) . '"></label><label class="dreamax-lm-confirm"><input data-dlm-confirm type="checkbox" name="confirm_operation" value="1" required> <span>' . esc_html__( 'I reviewed the selected licenses and confirm this operation.', 'dreamax-license-manager' ) . '</span></label><button class="button button-primary" data-dlm-submit>' . esc_html__( 'Apply action', 'dreamax-license-manager' ) . '</button></div></div></form></section>';
		$base = remove_query_arg( 'paged' );
		echo '<nav class="dreamax-lm-pagination" aria-label="' . esc_attr__( 'License pages', 'dreamax-license-manager' ) . '">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $page - 1, $base ) ) . '">' . esc_html__( 'Previous', 'dreamax-license-manager' ) . '</a> ';
		}
		if ( is_array( $rows ) && 50 === count( $rows ) ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $page + 1, $base ) ) . '">' . esc_html__( 'Next', 'dreamax-license-manager' ) . '</a>';
		}
		echo '</nav></div>';
	}

	/**
	 * Handles the license page operation.
	 */
	public function license_page(): void {
		global $wpdb;
		$this->authorize( Capabilities::MANAGE );
		/* phpcs:disable WordPress.Security.NonceVerification.Recommended -- These are read-only admin navigation and notice values behind a capability check. */
		$public_id = isset( $_GET['license'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['license'] ) ) : '';
		$license   = ( new LicenseRepository() )->by_public_id( $public_id );
		if ( ! is_array( $license ) ) {
			wp_die( esc_html__( 'The license could not be found.', 'dreamax-license-manager' ), 404 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$activations = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,instance_label,status,first_activated_at,activated_at,deactivated_at,last_seen_at FROM {$wpdb->prefix}dreamax_lm_activations WHERE license_id=%d ORDER BY id DESC LIMIT 100", (int) $license['id'] ), ARRAY_A );
		$events      = ( new EventRepository() )->for_license( (int) $license['id'], 100 );
		$list_url    = admin_url( 'admin.php?page=dreamax-license-manager' );
		if ( null === $license['activation_limit'] ) {
			$limit_label = __( 'Unlimited', 'dreamax-license-manager' );
		} else {
			$activation_limit = (int) $license['activation_limit'];
			/* translators: %d: Activation limit. */
			$limit_label = sprintf( _n( '%d installation', '%d installations', $activation_limit, 'dreamax-license-manager' ), $activation_limit );
		}
		$expiry_label = $license['expires_at'] ? mysql2date( get_option( 'date_format' ) . ' · ' . get_option( 'time_format' ), (string) $license['expires_at'], true ) . ' UTC' : __( 'Never expires', 'dreamax-license-manager' );

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-detail-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'License record', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'License details', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Review identity, ownership, activation policy, and the complete audit history.', 'dreamax-license-manager' ) . '</p></div><a class="button button-secondary dreamax-lm-back-action" href="' . esc_url( $list_url ) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>' . esc_html__( 'Back to licenses', 'dreamax-license-manager' ) . '</a></header>';
		if ( isset( $_GET['changed'] ) && (int) $_GET['changed'] > 0 ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'The license operation was completed.', 'dreamax-license-manager' ) . '</p></div>';
		}
		if ( isset( $_GET['failed'] ) && (int) $_GET['failed'] > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The operation was rejected because it did not match the current license state or policy.', 'dreamax-license-manager' ) . '</p></div>';
		}
		if ( isset( $_GET['notes_saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Internal notes saved.', 'dreamax-license-manager' ) . '</p></div>';
		}
		/* phpcs:enable WordPress.Security.NonceVerification.Recommended */
		echo '<section class="dreamax-lm-panel dreamax-lm-license-overview"><div class="dreamax-lm-license-identity"><div><span class="dreamax-lm-detail-label">' . esc_html__( 'License public ID', 'dreamax-license-manager' ) . '</span><code>' . esc_html( $public_id ) . '</code></div><span class="dreamax-lm-status dreamax-lm-status--' . esc_attr( $this->status_key( $license ) ) . '"><span aria-hidden="true"></span>' . esc_html( $this->status_label( $license ) ) . '</span></div><div class="dreamax-lm-detail-facts">';
		echo '<article><span class="dashicons dashicons-products" aria-hidden="true"></span><div><span class="dreamax-lm-detail-label">' . esc_html__( 'Product', 'dreamax-license-manager' ) . '</span>';
		$this->render_product_reference( $license );
		echo '</div></article>';
		echo '<article><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><div><span class="dreamax-lm-detail-label">' . esc_html__( 'Ownership', 'dreamax-license-manager' ) . '</span><strong>';
		$this->render_ownership_references( $license );
		echo '</strong>';
		if ( ! $license['customer_id'] && ! $license['order_id'] ) {
			echo '<small>' . esc_html__( 'Created manually', 'dreamax-license-manager' ) . '</small>';
		}
		echo '</div></article><article><span class="dashicons dashicons-admin-network" aria-hidden="true"></span><div><span class="dreamax-lm-detail-label">' . esc_html__( 'Activation policy', 'dreamax-license-manager' ) . '</span><strong>' . esc_html( $limit_label ) . '</strong></div></article><article><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><div><span class="dreamax-lm-detail-label">' . esc_html__( 'Expiry', 'dreamax-license-manager' ) . '</span><strong>' . esc_html( $expiry_label ) . '</strong></div></article></div></section>';

		$this->render_merchant_notes( $license );
		echo '<div class="dreamax-lm-detail-operation-grid"><section class="dreamax-lm-panel dreamax-lm-detail-operation"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Lifecycle operation', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Change availability, expiry, or active installations.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-update-alt" aria-hidden="true"></span></div>';
		echo '<form class="dreamax-lm-operation-form" data-dlm-lifecycle-form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_bulk_lifecycle"><input type="hidden" name="license_ids[]" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="return_license" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="operation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'dreamax_lm_bulk_lifecycle' );
		echo '<div class="dreamax-lm-operation-body"><label class="dreamax-lm-field"><span>' . esc_html__( 'Action', 'dreamax-license-manager' ) . '</span><select name="bulk_operation" data-dlm-operation required><option value="">' . esc_html__( 'Choose an action', 'dreamax-license-manager' ) . '</option><option value="suspend">' . esc_html__( 'Suspend temporarily', 'dreamax-license-manager' ) . '</option><option value="restore">' . esc_html__( 'Restore from suspension', 'dreamax-license-manager' ) . '</option><option value="revoke">' . esc_html__( 'Permanently revoke', 'dreamax-license-manager' ) . '</option><option value="extend">' . esc_html__( 'Extend expiry', 'dreamax-license-manager' ) . '</option><option value="reset">' . esc_html__( 'Reset all active installations', 'dreamax-license-manager' ) . '</option>';
		if ( current_user_can( Capabilities::DELETE ) ) {
			echo '<option value="delete">' . esc_html__( 'Permanently delete eligible pool record', 'dreamax-license-manager' ) . '</option>';
		}
		echo '</select></label><label class="dreamax-lm-field" data-dlm-extension hidden><span>' . esc_html__( 'Extension days', 'dreamax-license-manager' ) . '</span><input type="number" name="extension_days" min="1" max="3650" value="30"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Reason', 'dreamax-license-manager' ) . '</span><input name="reason" minlength="3" maxlength="500" required placeholder="' . esc_attr__( 'Required for the audit log', 'dreamax-license-manager' ) . '"></label><div class="dreamax-lm-operation-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span>' . esc_html__( 'Extension starts from the later of the current expiry or current server time. Revocation is permanent.', 'dreamax-license-manager' ) . '</span></div><label class="dreamax-lm-confirm"><input data-dlm-confirm type="checkbox" name="confirm_operation" value="1" required><span>' . esc_html__( 'I understand the effect and confirm this operation.', 'dreamax-license-manager' ) . '</span></label><button class="button button-primary" data-dlm-submit disabled>' . esc_html__( 'Apply operation', 'dreamax-license-manager' ) . '</button></div></form></section>';

		echo '<section class="dreamax-lm-panel dreamax-lm-detail-operation"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Reassign ownership', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Transfer this license to a verified customer and optional order.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-migrate" aria-hidden="true"></span></div><form class="dreamax-lm-operation-form" data-dlm-confirmed-form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_reassign_license"><input type="hidden" name="license" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="operation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'dreamax_lm_reassign_license' );
		echo '<div class="dreamax-lm-operation-body"><div class="dreamax-lm-form-grid"><label class="dreamax-lm-field"><span>' . esc_html__( 'Target customer ID', 'dreamax-license-manager' ) . '</span><input type="number" min="1" required id="target_customer_id" name="target_customer_id"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Target order ID', 'dreamax-license-manager' ) . ' <span class="dreamax-lm-optional">' . esc_html__( 'Optional', 'dreamax-license-manager' ) . '</span></span><input type="number" min="1" id="target_order_id" name="target_order_id"></label></div><label class="dreamax-lm-field"><span>' . esc_html__( 'Target product public ID', 'dreamax-license-manager' ) . '</span><input pattern="prd_[A-Za-z0-9_-]{22}" id="target_product_public_id" name="target_product_public_id" value="' . esc_attr( (string) $license['product_public_id'] ) . '"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Reason', 'dreamax-license-manager' ) . '</span><input minlength="3" maxlength="500" required id="reassign_reason" name="reason" placeholder="' . esc_attr__( 'Required for the audit log', 'dreamax-license-manager' ) . '"></label><div class="dreamax-lm-check-stack"><label><input type="checkbox" name="reset_activations" value="1"><span>' . esc_html__( 'Deactivate all existing installations', 'dreamax-license-manager' ) . '</span></label><label><input type="checkbox" name="notify_customer" value="1"><span>' . esc_html__( 'Email the target customer without including the key', 'dreamax-license-manager' ) . '</span></label></div><div class="dreamax-lm-operation-note dreamax-lm-operation-note--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span>' . esc_html__( 'The former owner immediately loses access through the old order-item link.', 'dreamax-license-manager' ) . '</span></div><label class="dreamax-lm-confirm"><input data-dlm-confirm type="checkbox" name="confirm_operation" value="1" required><span>' . esc_html__( 'I verified the old and new ownership and confirm reassignment.', 'dreamax-license-manager' ) . '</span></label><button class="button button-primary" data-dlm-submit disabled>' . esc_html__( 'Reassign license', 'dreamax-license-manager' ) . '</button></div></form></section></div>';

		$all_activations_url = add_query_arg(
			array(
				'page'    => 'dreamax-license-manager-activations',
				'license' => $public_id,
			),
			admin_url( 'admin.php' )
		);
		echo '<section class="dreamax-lm-panel dreamax-lm-detail-table-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Installations', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Registered devices and their current activation state.', 'dreamax-license-manager' ) . '</p></div><a class="button" href="' . esc_url( $all_activations_url ) . '">' . esc_html__( 'View all', 'dreamax-license-manager' ) . '</a></div><div class="dreamax-lm-table-scroll"><table class="widefat dreamax-lm-table"><thead><tr><th>' . esc_html__( 'Activation ID', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Label', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Activated', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Deactivated', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( is_array( $activations ) ? $activations : array() as $activation ) {
			echo '<tr><td><code>' . esc_html( (string) $activation['public_id'] ) . '</code></td><td>' . esc_html( (string) ( $activation['instance_label'] ? $activation['instance_label'] : '—' ) ) . '</td><td>' . esc_html( (string) $activation['status'] ) . '</td><td>' . esc_html( (string) $activation['activated_at'] ) . '</td><td>' . esc_html( (string) ( $activation['deactivated_at'] ? $activation['deactivated_at'] : '—' ) ) . '</td></tr>';
		}
		if ( ! $activations ) {
			echo '<tr><td class="dreamax-lm-empty dreamax-lm-empty--compact" colspan="5"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span><strong>' . esc_html__( 'No installations recorded', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'Activated devices will appear here.', 'dreamax-license-manager' ) . '</p></td></tr>';
		}
		echo '</tbody></table></div></section><section class="dreamax-lm-panel dreamax-lm-detail-table-panel dreamax-lm-audit-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Audit trail', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Immutable operational history for this license.', 'dreamax-license-manager' ) . '</p></div><span class="dreamax-lm-count">' . esc_html( number_format_i18n( count( $events ) ) ) . '</span></div><div class="dreamax-lm-table-scroll">';
		$this->render_events( $events );
		echo '</div></section></div>';
	}

	/**
	 * Renders the merchant-only note and reference editor for one license.
	 *
	 * @param array<string,mixed> $license License row.
	 */
	private function render_merchant_notes( array $license ): void {
		$merchant = ( new MerchantMetadata() )->view( $license );
		echo '<section class="dreamax-lm-panel dreamax-lm-merchant-notes"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Internal notes', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Visible only to license administrators. Do not enter keys, passwords, or personal data.', 'dreamax-license-manager' ) . '</p></div></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_save_license_notes"><input type="hidden" name="license" value="' . esc_attr( (string) $license['public_id'] ) . '"><input type="hidden" name="revision" value="' . esc_attr( $merchant['revision'] ) . '">';
		wp_nonce_field( 'dreamax_lm_save_license_notes' );
		echo '<div class="dreamax-lm-merchant-body"><label class="dreamax-lm-field" for="dreamax-lm-internal-note"><span>' . esc_html__( 'Note', 'dreamax-license-manager' ) . '</span></label><textarea id="dreamax-lm-internal-note" name="internal_note" maxlength="2000" rows="3">' . esc_textarea( $merchant['note'] ) . '</textarea><div class="dreamax-lm-merchant-field-heading"><strong>' . esc_html__( 'Reference fields', 'dreamax-license-manager' ) . '</strong><button type="button" class="button" data-dlm-add-merchant-field>' . esc_html__( 'Add field', 'dreamax-license-manager' ) . '</button></div><div data-dlm-merchant-fields data-limit="10">';
		foreach ( $merchant['fields'] as $key => $value ) {
			$this->render_merchant_field_row( $key, $value );
		}
		if ( count( $merchant['fields'] ) < 10 ) {
			$this->render_merchant_field_row( '', '' );
		}
		echo '</div><template data-dlm-merchant-template>';
		$this->render_merchant_field_row( '', '' );
		echo '</template><button class="button button-primary" type="submit">' . esc_html__( 'Save internal notes', 'dreamax-license-manager' ) . '</button></div></form></section>';
	}

	/**
	 * Renders one paired, editable reference field.
	 *
	 * @param string $key Reference name.
	 * @param string $value Reference value.
	 */
	private function render_merchant_field_row( string $key, string $value ): void {
		echo '<div class="dreamax-lm-merchant-field" data-dlm-merchant-row><label><span class="screen-reader-text">' . esc_html__( 'Field name', 'dreamax-license-manager' ) . '</span><input name="field_keys[]" aria-label="' . esc_attr__( 'Field name', 'dreamax-license-manager' ) . '" pattern="[a-z][a-z0-9_]{0,31}" maxlength="32" placeholder="' . esc_attr__( 'Name', 'dreamax-license-manager' ) . '" value="' . esc_attr( $key ) . '"></label><label><span class="screen-reader-text">' . esc_html__( 'Field value', 'dreamax-license-manager' ) . '</span><input name="field_values[]" aria-label="' . esc_attr__( 'Field value', 'dreamax-license-manager' ) . '" maxlength="160" placeholder="' . esc_attr__( 'Value', 'dreamax-license-manager' ) . '" value="' . esc_attr( $value ) . '"></label><button type="button" class="button" data-dlm-remove-merchant-field aria-label="' . esc_attr__( 'Remove field', 'dreamax-license-manager' ) . '" title="' . esc_attr__( 'Remove field', 'dreamax-license-manager' ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button></div>';
	}

	/**
	 * Handles the activity page operation.
	 */
	public function activity_page(): void {
		$this->authorize( Capabilities::MANAGE );
		$events      = ( new EventRepository() )->recent( 200 );
		$event_types = array();
		$license_ids = array();
		$actors      = array();
		foreach ( $events as $event ) {
			$event_types[] = (string) ( $event['event_type'] ?? '' );
			$actor_type    = (string) ( $event['actor_type'] ?? '' );
			if ( '' !== $actor_type ) {
				$actors[] = $actor_type . ':' . (string) ( $event['actor_id'] ?? '' );
			}
			if ( ! empty( $event['license_public_id'] ) ) {
				$license_ids[] = (string) $event['license_public_id'];
			}
		}

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-activity-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Audit and monitoring', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'Recent activity', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Review the latest license operations, actors, and sanitized context.', 'dreamax-license-manager' ) . '</p></div></header>';
		echo '<section class="dreamax-lm-summary" aria-label="' . esc_attr__( 'Activity summary', 'dreamax-license-manager' ) . '">';
		$this->summary_card( __( 'Recent events', 'dreamax-license-manager' ), count( $events ), 'list-view' );
		$this->summary_card( __( 'Event types', 'dreamax-license-manager' ), count( array_unique( array_filter( $event_types ) ) ), 'category' );
		$this->summary_card( __( 'Licenses referenced', 'dreamax-license-manager' ), count( array_unique( $license_ids ) ), 'admin-network' );
		$this->summary_card( __( 'Actors', 'dreamax-license-manager' ), count( array_unique( $actors ) ), 'groups' );
		echo '</section><section class="dreamax-lm-panel dreamax-lm-detail-table-panel dreamax-lm-audit-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Event timeline', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Newest first · Up to 200 sanitized events', 'dreamax-license-manager' ) . '</p></div><span class="dreamax-lm-count">' . esc_html( number_format_i18n( count( $events ) ) ) . '</span></div><div class="dreamax-lm-table-scroll">';
		$this->render_events( $events, true );
		echo '</div></section></div>';
	}

	/**
	 * Handles the add page operation.
	 */
	public function add_page(): void {
		$this->authorize( Capabilities::MANAGE );
		$list_url = admin_url( 'admin.php?page=dreamax-license-manager' );

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-add-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'License inventory', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'Add license', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Create a secure license for a product, with clear activation and expiry rules.', 'dreamax-license-manager' ) . '</p></div><a class="button button-secondary dreamax-lm-back-action" href="' . esc_url( $list_url ) . '"><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>' . esc_html__( 'Back to licenses', 'dreamax-license-manager' ) . '</a></header>';
		echo '<form class="dreamax-lm-add-form" data-dlm-add-license method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_create_license">';
		wp_nonce_field( 'dreamax_lm_create_license' );
		echo '<div class="dreamax-lm-add-layout"><section class="dreamax-lm-panel dreamax-lm-create-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'License details', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Fields marked required must be completed.', 'dreamax-license-manager' ) . '</p></div><span class="dreamax-lm-required-note">' . esc_html__( 'Required', 'dreamax-license-manager' ) . '</span></div><div class="dreamax-lm-form-body">';
		echo '<div class="dreamax-lm-field dreamax-lm-field--large"><label for="product_public_id">' . esc_html__( 'Product public ID', 'dreamax-license-manager' ) . ' <span aria-hidden="true">*</span></label><input class="large-text" required pattern="prd_[A-Za-z0-9_-]{22}" autocomplete="off" spellcheck="false" id="product_public_id" name="product_public_id" placeholder="prd_…" aria-describedby="dreamax-lm-product-help"><p id="dreamax-lm-product-help" class="dreamax-lm-help">' . esc_html__( 'Use the public ID of the product that will validate this license.', 'dreamax-license-manager' ) . '</p></div>';
		echo '<fieldset class="dreamax-lm-key-source"><legend>' . esc_html__( 'Key source', 'dreamax-license-manager' ) . '</legend><div class="dreamax-lm-choice-grid"><label class="dreamax-lm-choice"><input type="radio" name="key_source" value="generated" checked><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><span><strong>' . esc_html__( 'Generate securely', 'dreamax-license-manager' ) . '</strong><small>' . esc_html__( 'Recommended. Dreamax creates a strong unique key.', 'dreamax-license-manager' ) . '</small></span></label><label class="dreamax-lm-choice"><input type="radio" name="key_source" value="imported"><span class="dashicons dashicons-upload" aria-hidden="true"></span><span><strong>' . esc_html__( 'Import an existing key', 'dreamax-license-manager' ) . '</strong><small>' . esc_html__( 'Preserve one exact key from another system.', 'dreamax-license-manager' ) . '</small></span></label></div></fieldset>';
		echo '<div class="dreamax-lm-field dreamax-lm-import-field" data-dlm-import-field hidden><label for="license_key">' . esc_html__( 'Existing license key', 'dreamax-license-manager' ) . ' <span aria-hidden="true">*</span></label><div class="dreamax-lm-secret-input"><input type="password" class="large-text" id="license_key" name="license_key" autocomplete="new-password" spellcheck="false" disabled><button class="button" type="button" data-dlm-toggle-key aria-controls="license_key" aria-pressed="false"><span class="dashicons dashicons-visibility" data-dlm-toggle-icon aria-hidden="true"></span><span data-dlm-toggle-label>' . esc_html__( 'Show', 'dreamax-license-manager' ) . '</span></button></div><p class="dreamax-lm-help">' . esc_html__( 'Imported keys are exact and case-sensitive. They are encrypted before storage.', 'dreamax-license-manager' ) . '</p></div>';
		echo '<div class="dreamax-lm-form-grid"><div class="dreamax-lm-field"><label for="activation_limit">' . esc_html__( 'Activation limit', 'dreamax-license-manager' ) . '</label><input type="number" min="0" id="activation_limit" name="activation_limit" value="1" inputmode="numeric" aria-describedby="dreamax-lm-limit-help"><p id="dreamax-lm-limit-help" class="dreamax-lm-help">' . esc_html__( 'Blank allows unlimited activations; zero disables activation.', 'dreamax-license-manager' ) . '</p></div><div class="dreamax-lm-field"><label for="expires_at">' . esc_html__( 'Expiry', 'dreamax-license-manager' ) . ' <span class="dreamax-lm-optional">' . esc_html__( 'Optional', 'dreamax-license-manager' ) . '</span></label><input type="datetime-local" id="expires_at" name="expires_at" aria-describedby="dreamax-lm-expiry-help"><p id="dreamax-lm-expiry-help" class="dreamax-lm-help">' . esc_html__( 'Stored in UTC. Leave empty for no expiry.', 'dreamax-license-manager' ) . '</p></div></div></div>';
		echo '<footer class="dreamax-lm-form-actions"><p><span class="dashicons dashicons-lock" aria-hidden="true"></span>' . esc_html__( 'The key is encrypted before it is stored.', 'dreamax-license-manager' ) . '</p><button class="button button-primary dreamax-lm-create-button" type="submit"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__( 'Create license', 'dreamax-license-manager' ) . '</button></footer></section>';
		echo '<aside class="dreamax-lm-panel dreamax-lm-guidance"><div class="dreamax-lm-guidance-icon"><span class="dashicons dashicons-shield" aria-hidden="true"></span></div><h2>' . esc_html__( 'Secure by default', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Generated keys use the plugin’s secure format and avoid manual handling.', 'dreamax-license-manager' ) . '</p><ul><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Product identity is validated before creation.', 'dreamax-license-manager' ) . '</li><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Keys are never stored as plain text.', 'dreamax-license-manager' ) . '</li><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Creation is recorded in the audit trail.', 'dreamax-license-manager' ) . '</li></ul><div class="dreamax-lm-guidance-note"><strong>' . esc_html__( 'Before you continue', 'dreamax-license-manager' ) . '</strong><span>' . esc_html__( 'Confirm the product ID and activation policy. A manually created license is not linked to a customer or order.', 'dreamax-license-manager' ) . '</span></div></aside></div></form></div>';
	}

	/**
	 * Handles the transfer page operation.
	 */
	public function transfer_page(): void {
		$this->authorize( Capabilities::MANAGE );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The opaque token only displays an owner-scoped job created by a nonce-protected action.
		$job_token = isset( $_GET['import_job'] ) ? sanitize_key( wp_unslash( (string) $_GET['import_job'] ) ) : '';
		$job       = '' !== $job_token ? ( new \Dreamax\LicenseManager\ImportExport\CsvImportJob() )->for_owner( $job_token, get_current_user_id() ) : null;
		echo '<div class="wrap dreamax-lm-admin dreamax-lm-transfer-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Data portability', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'Import and export', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Move license inventory with preview-first validation and audited sensitive exports.', 'dreamax-license-manager' ) . '</p></div></header><div class="dreamax-lm-transfer-grid">';
		if ( is_array( $job ) ) {
			$status = (string) ( $job['status'] ?? 'queued' );
			/* translators: 1: job status, 2: valid rows, 3: skipped duplicates, 4: errors. */
			echo '<section class="dreamax-lm-panel dreamax-lm-import-progress"><div><h2>' . esc_html__( 'Import progress', 'dreamax-license-manager' ) . '</h2><p>' . esc_html( sprintf( __( 'Status: %1$s. Valid rows: %2$d. Skipped duplicates: %3$d. Errors: %4$d.', 'dreamax-license-manager' ), $status, (int) ( $job['valid'] ?? 0 ), (int) ( $job['skipped'] ?? 0 ), count( (array) ( $job['errors'] ?? array() ) ) ) ) . '</p></div>';
			if ( ! empty( $job['report_path'] ) ) {
				$report_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'dreamax_lm_csv_error_report',
							'job'    => $job_token,
						),
						admin_url( 'admin-post.php' )
					),
					'dreamax_lm_csv_error_report_' . $job_token
				);
				echo '<a class="button" href="' . esc_url( $report_url ) . '">' . esc_html__( 'Download row error report', 'dreamax-license-manager' ) . '</a>';
			}
			echo '</section>';
		}
		echo '<section class="dreamax-lm-panel dreamax-lm-transfer-card"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Import CSV', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Validate the file before committing any license records.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-upload" aria-hidden="true"></span></div><form class="dreamax-lm-transfer-form" data-dlm-import-form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_import_csv">';
		wp_nonce_field( 'dreamax_lm_import_csv' );
		echo '<label class="dreamax-lm-file-picker"><span class="dashicons dashicons-media-spreadsheet" aria-hidden="true"></span><strong>' . esc_html__( 'Choose a CSV file', 'dreamax-license-manager' ) . '</strong><span data-dlm-file-name>' . esc_html__( 'No file selected', 'dreamax-license-manager' ) . '</span><input class="screen-reader-text" data-dlm-file-input type="file" name="csv" accept=".csv,text/csv" required></label>';
		echo '<div class="dreamax-lm-form-grid"><label class="dreamax-lm-field"><span>' . esc_html__( 'Encoding', 'dreamax-license-manager' ) . '</span><select name="encoding"><option value="UTF-8">UTF-8</option><option value="Windows-1252">Windows-1252</option><option value="ISO-8859-1">ISO-8859-1</option></select></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Delimiter', 'dreamax-license-manager' ) . '</span><select name="delimiter"><option value="comma">' . esc_html__( 'Comma', 'dreamax-license-manager' ) . '</option><option value="semicolon">' . esc_html__( 'Semicolon', 'dreamax-license-manager' ) . '</option><option value="tab">' . esc_html__( 'Tab', 'dreamax-license-manager' ) . '</option></select></label></div>';
		echo '<details class="dreamax-lm-csv-mapping"><summary>' . esc_html__( 'Map source columns', 'dreamax-license-manager' ) . '</summary><p>' . esc_html__( 'Enter the heading used in your file for each Dreamax field.', 'dreamax-license-manager' ) . '</p><div class="dreamax-lm-form-grid">';
		foreach ( array(
			'license_key'           => __( 'License key', 'dreamax-license-manager' ),
			'product_public_id'     => __( 'Product public ID', 'dreamax-license-manager' ),
			'activation_limit'      => __( 'Activation limit', 'dreamax-license-manager' ),
			'expires_at'            => __( 'Expiry', 'dreamax-license-manager' ),
			'normalization_profile' => __( 'Normalization profile', 'dreamax-license-manager' ),
			'separator'             => __( 'Separator', 'dreamax-license-manager' ),
		) as $field => $label ) {
			echo '<label class="dreamax-lm-field"><span>' . esc_html( $label ) . '</span><input name="map_' . esc_attr( $field ) . '" value="' . esc_attr( $field ) . '"></label>';
		}
		echo '</div></details><label class="dreamax-lm-field"><span>' . esc_html__( 'Duplicate keys', 'dreamax-license-manager' ) . '</span><select name="duplicate_strategy"><option value="error">' . esc_html__( 'Report as errors', 'dreamax-license-manager' ) . '</option><option value="skip">' . esc_html__( 'Skip existing keys', 'dreamax-license-manager' ) . '</option></select></label>';
		echo '<label class="dreamax-lm-preview-toggle"><input data-dlm-preview-only type="checkbox" name="dry_run" value="1" checked><span><strong>' . esc_html__( 'Preview only', 'dreamax-license-manager' ) . '</strong><small>' . esc_html__( 'Recommended. Validate rows and errors without writing to the database.', 'dreamax-license-manager' ) . '</small></span></label><label class="dreamax-lm-import-confirm" data-dlm-import-confirm hidden><input type="checkbox" disabled><span><strong>' . esc_html__( 'Confirm database import', 'dreamax-license-manager' ) . '</strong><small>' . esc_html__( 'I understand this will create license records from every valid CSV row.', 'dreamax-license-manager' ) . '</small></span></label><div class="dreamax-lm-csv-contract"><strong>' . esc_html__( 'CSV columns', 'dreamax-license-manager' ) . '</strong><dl><div><dt>' . esc_html__( 'Required', 'dreamax-license-manager' ) . '</dt><dd><code>license_key</code>, <code>product_public_id</code></dd></div><div><dt>' . esc_html__( 'Optional', 'dreamax-license-manager' ) . '</dt><dd><code>activation_limit</code>, <code>expires_at</code>, <code>normalization_profile</code>, <code>separator</code></dd></div></dl></div><div class="dreamax-lm-transfer-actions"><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dreamax_lm_csv_template' ), 'dreamax_lm_csv_template' ) ) . '">' . esc_html__( 'Download template', 'dreamax-license-manager' ) . '</a><button class="button button-primary" data-dlm-import-submit type="submit"><span class="dashicons dashicons-search" aria-hidden="true"></span><span data-dlm-import-label>' . esc_html__( 'Check import', 'dreamax-license-manager' ) . '</span></button></div></form></section>';
		echo '<section class="dreamax-lm-panel dreamax-lm-transfer-card"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Export CSV', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Download a portable inventory for authorized operational use.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-download" aria-hidden="true"></span></div><form class="dreamax-lm-transfer-form" data-dlm-export-form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_export_csv">';
		wp_nonce_field( 'dreamax_lm_export_csv' );
		echo '<div class="dreamax-lm-export-guidance"><span class="dashicons dashicons-shield" aria-hidden="true"></span><div><strong>' . esc_html__( 'Default export is safer', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'Full keys remain excluded unless an authorized administrator explicitly requests them.', 'dreamax-license-manager' ) . '</p></div></div><div class="dreamax-lm-form-grid"><label class="dreamax-lm-field"><span>' . esc_html__( 'Export', 'dreamax-license-manager' ) . '</span><select name="export_type"><option value="licenses">' . esc_html__( 'Licenses', 'dreamax-license-manager' ) . '</option><option value="activations">' . esc_html__( 'Activations', 'dreamax-license-manager' ) . '</option></select></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</span><input name="filter_status" placeholder="' . esc_attr__( 'Any status', 'dreamax-license-manager' ) . '"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Product public ID', 'dreamax-license-manager' ) . '</span><input name="filter_product"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Customer ID', 'dreamax-license-manager' ) . '</span><input type="number" min="1" name="filter_customer"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Order ID', 'dreamax-license-manager' ) . '</span><input type="number" min="1" name="filter_order"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Expiry', 'dreamax-license-manager' ) . '</span><select name="filter_expiry"><option value="">' . esc_html__( 'Any expiry', 'dreamax-license-manager' ) . '</option><option value="expired">' . esc_html__( 'Expired', 'dreamax-license-manager' ) . '</option><option value="soon">' . esc_html__( 'Within 30 days', 'dreamax-license-manager' ) . '</option><option value="lifetime">' . esc_html__( 'Never expires', 'dreamax-license-manager' ) . '</option></select></label></div>';
		if ( current_user_can( Capabilities::EXPORT ) ) {
			echo '<label class="dreamax-lm-sensitive-option"><input data-dlm-full-keys type="checkbox" name="full_keys" value="1"><span><strong>' . esc_html__( 'Include full license keys', 'dreamax-license-manager' ) . '</strong><small>' . esc_html__( 'Sensitive and audited. Store the downloaded file securely and delete it when no longer needed.', 'dreamax-license-manager' ) . '</small></span></label><label class="dreamax-lm-export-confirm" data-dlm-export-confirm hidden><input type="checkbox" disabled><span>' . esc_html__( 'I understand this export contains sensitive full license keys.', 'dreamax-license-manager' ) . '</span></label>';
		}
		echo '<button class="button button-primary" data-dlm-export-submit type="submit"><span class="dashicons dashicons-download" aria-hidden="true"></span><span data-dlm-export-label>' . esc_html__( 'Download safe export', 'dreamax-license-manager' ) . '</span></button></form></section></div></div>';
	}

	/**
	 * Handles the credentials page operation.
	 */
	public function credentials_page(): void {
		$this->authorize( Capabilities::CREDENTIALS );
		$service          = new CredentialService();
		$operations       = new CredentialAdminOperation();
		$actor_id         = get_current_user_id();
		$create_operation = $operations->issue( CredentialAdminOperation::CREATE, '', $actor_id );
		$rows             = $service->list_safe();
		$total            = count( $rows );
		$active           = count( array_filter( $rows, static fn( array $row ): bool => 'active' === (string) $row['effective_status'] ) );
		$attention        = $total - $active;
		$scope_catalog    = array(
			'licenses:read'    => array( __( 'Read licenses', 'dreamax-license-manager' ), __( 'View authorized license inventory and status.', 'dreamax-license-manager' ) ),
			'licenses:write'   => array( __( 'Manage licenses', 'dreamax-license-manager' ), __( 'Create or change license records through privileged routes.', 'dreamax-license-manager' ) ),
			'activations:read' => array( __( 'Read activations', 'dreamax-license-manager' ), __( 'Inspect registered installations and activation state.', 'dreamax-license-manager' ) ),
			'generators:read'  => array( __( 'Read generators', 'dreamax-license-manager' ), __( 'Inspect configured license generation policies.', 'dreamax-license-manager' ) ),
		);
		echo '<div class="wrap dreamax-lm-admin dreamax-lm-credentials-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Privileged access', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'API credentials', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Issue narrowly scoped Bearer credentials and control their complete lifecycle.', 'dreamax-license-manager' ) . '</p></div></header>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-action status notice behind the credential capability.
		if ( isset( $_GET['credential_revoked'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The credential is revoked. Repeating revocation has no additional effect.', 'dreamax-license-manager' ) . '</p></div>';
		}
		echo '<div class="dreamax-lm-summary">';
		$this->summary_card( __( 'Total credentials', 'dreamax-license-manager' ), $total, 'admin-network' );
		$this->summary_card( __( 'Active', 'dreamax-license-manager' ), $active, 'yes-alt' );
		$this->summary_card( __( 'Needs attention', 'dreamax-license-manager' ), $attention, 'warning' );
		echo '</div><div class="dreamax-lm-credential-layout"><section class="dreamax-lm-panel dreamax-lm-credential-create"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Create credential', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Use the minimum permissions and an expiration whenever possible.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span></div><form data-dlm-credential-create method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_create_credential">';
		$this->credential_operation_fields( $create_operation );
		wp_nonce_field( 'dreamax_lm_create_credential' );
		echo '<div class="dreamax-lm-credential-fields"><div class="dreamax-lm-field"><label for="credential_name">' . esc_html__( 'Credential name', 'dreamax-license-manager' ) . ' <span aria-hidden="true">*</span></label><input data-dlm-credential-name id="credential_name" name="name" maxlength="191" placeholder="' . esc_attr__( 'Example: Internal reporting service', 'dreamax-license-manager' ) . '" required><p class="dreamax-lm-help">' . esc_html__( 'Use a name that identifies one service and environment.', 'dreamax-license-manager' ) . '</p></div><div class="dreamax-lm-field"><label for="credential_expiry">' . esc_html__( 'Expiration', 'dreamax-license-manager' ) . ' <span class="dreamax-lm-optional">' . esc_html__( 'Optional · UTC', 'dreamax-license-manager' ) . '</span></label><input id="credential_expiry" type="datetime-local" name="expires_at"><p class="dreamax-lm-help">' . esc_html__( 'Short-lived credentials reduce exposure.', 'dreamax-license-manager' ) . '</p></div></div><fieldset class="dreamax-lm-scope-fieldset"><legend>' . esc_html__( 'Permissions', 'dreamax-license-manager' ) . ' <span aria-hidden="true">*</span></legend><p>' . esc_html__( 'Select at least one scope. Permissions are enforced independently.', 'dreamax-license-manager' ) . '</p><div class="dreamax-lm-scope-grid">';
		foreach ( $scope_catalog as $scope => $details ) {
			echo '<label class="dreamax-lm-scope-option"><input data-dlm-credential-scope type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '"><span><strong>' . esc_html( $details[0] ) . '</strong><code>' . esc_html( $scope ) . '</code><small>' . esc_html( $details[1] ) . '</small></span></label>';
		}
		echo '</div></fieldset><div class="dreamax-lm-credential-create-footer"><span class="dashicons dashicons-lock" aria-hidden="true"></span><p>' . esc_html__( 'The complete credential is shown once after creation.', 'dreamax-license-manager' ) . '</p><button class="button button-primary" data-dlm-credential-create-submit type="submit"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__( 'Create credential', 'dreamax-license-manager' ) . '</button></div></form></section><aside class="dreamax-lm-panel dreamax-lm-credential-guidance"><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><h2>' . esc_html__( 'Protect every credential', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Secrets are never recoverable after the one-time display.', 'dreamax-license-manager' ) . '</p><ul><li>' . esc_html__( 'Store only in a server-side secret manager.', 'dreamax-license-manager' ) . '</li><li>' . esc_html__( 'Never ship a credential in browser or client software.', 'dreamax-license-manager' ) . '</li><li>' . esc_html__( 'Rotate immediately after suspected exposure.', 'dreamax-license-manager' ) . '</li></ul></aside></div>';
		echo '<section class="dreamax-lm-panel dreamax-lm-credential-inventory"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Credential inventory', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Review only non-secret identity, permissions, usage, and lifecycle metadata.', 'dreamax-license-manager' ) . '</p></div><span class="dreamax-lm-count-badge">' . esc_html( number_format_i18n( $total ) ) . '</span></div><div class="dreamax-lm-table-scroll"><table><thead><tr><th>' . esc_html__( 'Credential', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Permissions', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Usage', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Controlled actions', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$public_id = (string) $row['public_id'];
			$scopes    = is_array( $row['scopes'] ) ? array_map( 'strval', $row['scopes'] ) : array();
			$status    = (string) $row['effective_status'];
			echo '<tr><td><strong>' . esc_html( (string) $row['name'] ) . '</strong><code class="dreamax-lm-credential-id">' . esc_html( $public_id ) . '</code><small>' . esc_html__( 'Secret version', 'dreamax-license-manager' ) . ' ' . esc_html( (string) $row['secret_version'] ) . '</small></td><td><div class="dreamax-lm-scope-list">';
			foreach ( $scopes as $scope ) {
				echo '<code>' . esc_html( $scope ) . '</code>';
			}
			echo '</div></td><td><span class="dreamax-lm-credential-status dreamax-lm-credential-status--' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td><td><strong>' . esc_html( $row['last_used_at'] ? __( 'Last used', 'dreamax-license-manager' ) : __( 'Never used', 'dreamax-license-manager' ) ) . '</strong><small>' . esc_html( $row['last_used_at'] ? (string) $row['last_used_at'] . ' UTC' : ( $row['expires_at'] ? __( 'Expires ', 'dreamax-license-manager' ) . (string) $row['expires_at'] . ' UTC' : __( 'No expiration', 'dreamax-license-manager' ) ) ) . '</small></td><td><div class="dreamax-lm-credential-actions">';
			if ( 'active' === $status ) {
				$rotate_operation = $operations->issue( CredentialAdminOperation::ROTATE, $this->credential_rotation_context( $public_id, (int) $row['secret_version'] ), $actor_id );
				echo '<form data-dlm-credential-action method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_rotate_credential"><input type="hidden" name="public_id" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="expected_version" value="' . esc_attr( (string) $row['secret_version'] ) . '">';
				$this->credential_operation_fields( $rotate_operation );
				wp_nonce_field( 'dreamax_lm_rotate_credential_' . $public_id );
				echo '<label><input data-dlm-credential-confirm type="checkbox" name="confirm_rotation" value="1" required> ' . esc_html__( 'Invalidate old secret', 'dreamax-license-manager' ) . '</label><button class="button button-secondary" data-dlm-credential-action-submit type="submit">' . esc_html__( 'Rotate', 'dreamax-license-manager' ) . '</button></form>';
			}
			if ( 'revoked' !== $status ) {
				echo '<form class="dreamax-lm-credential-revoke" data-dlm-credential-action method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_revoke_credential"><input type="hidden" name="public_id" value="' . esc_attr( $public_id ) . '">';
				wp_nonce_field( 'dreamax_lm_revoke_credential_' . $public_id );
				echo '<label><input data-dlm-credential-confirm type="checkbox" name="confirm_revocation" value="1" required> ' . esc_html__( 'Permanently revoke', 'dreamax-license-manager' ) . '</label><button class="button dreamax-lm-danger-button" data-dlm-credential-action-submit type="submit">' . esc_html__( 'Revoke', 'dreamax-license-manager' ) . '</button></form>';
			}
			echo '</div></td></tr>';
		}
		if ( array() === $rows ) {
			echo '<tr><td class="dreamax-lm-empty-state" colspan="5"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span><strong>' . esc_html__( 'No API credentials yet', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'Create a narrowly scoped credential when a trusted server integration needs access.', 'dreamax-license-manager' ) . '</p></td></tr>';
		}
		echo '</tbody></table></div></section></div>';
	}

	/**
	 * Handles the status page operation.
	 */
	public function status_page(): void {
		$this->authorize( Capabilities::DIAGNOSTICS );
		$checks       = ( new Diagnostics() )->checks();
		$ready_count  = count( array_filter( $checks, static fn( array $check ): bool => $check['ready'] ) );
		$total_count  = count( $checks );
		$healthy      = $ready_count === $total_count;
		$crypto_ready = $checks['encryption']['ready'];
		$links        = array(
			'delivery'        => admin_url( 'admin.php?page=dreamax-license-manager-settings' ),
			'generator'       => admin_url( 'admin.php?page=dreamax-license-manager-generators' ),
			'test_license'    => admin_url( 'admin.php?page=dreamax-license-manager-add' ),
			'customer_portal' => admin_url( 'admin.php?page=dreamax-license-manager-customer-portal' ),
			'backup'          => admin_url( 'admin.php?page=dreamax-license-manager-settings' ),
		);
		$icons        = array(
			'environment'      => 'admin-tools',
			'schema'           => 'database',
			'storage_engine'   => 'database-view',
			'encryption'       => 'shield',
			'delivery'         => 'email-alt',
			'generator'        => 'randomize',
			'test_license'     => 'admin-network',
			'customer_portal'  => 'groups',
			'api'              => 'rest-api',
			'background'       => 'clock',
			'proxy_rate_limit' => 'lock',
			'backup'           => 'backup',
		);

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-status-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Setup and operational readiness', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'System status', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Complete the setup checklist, verify protected delivery, and download a secret-free support report.', 'dreamax-license-manager' ) . '</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_download_system_report">';
		wp_nonce_field( 'dreamax_lm_download_system_report' );
		echo '<button class="button" type="submit"><span class="dashicons dashicons-download" aria-hidden="true"></span>' . esc_html__( 'Download system report', 'dreamax-license-manager' ) . '</button></form></header>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only result notice follows a nonce-protected email action.
		if ( isset( $_GET['email_test'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The value only selects fixed notice copy.
			$sent = 'sent' === sanitize_key( wp_unslash( (string) $_GET['email_test'] ) );
			echo '<div class="notice ' . esc_attr( $sent ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $sent ? __( 'WordPress accepted the test email for delivery.', 'dreamax-license-manager' ) : __( 'WordPress could not accept the test email. Review the site mail configuration.', 'dreamax-license-manager' ) ) . '</p></div>';
		}
		/* translators: 1: ready check count, 2: total check count. */
		echo '<section class="dreamax-lm-health-summary ' . esc_attr( $healthy ? 'is-ready' : 'is-warning' ) . '"><span class="dashicons ' . esc_attr( $healthy ? 'dashicons-yes-alt' : 'dashicons-warning' ) . '" aria-hidden="true"></span><div><h2>' . esc_html( $healthy ? __( 'Setup complete', 'dreamax-license-manager' ) : __( 'Setup needs attention', 'dreamax-license-manager' ) ) . '</h2><p>' . esc_html( sprintf( __( '%1$d of %2$d readiness checks pass.', 'dreamax-license-manager' ), $ready_count, $total_count ) ) . '</p></div><span class="dreamax-lm-health-pill">' . esc_html( $healthy ? __( 'Ready', 'dreamax-license-manager' ) : __( 'Review', 'dreamax-license-manager' ) ) . '</span></section><div class="dreamax-lm-status-grid">';
		foreach ( $checks as $id => $check ) {
			echo '<section class="dreamax-lm-status-card ' . esc_attr( $check['ready'] ? 'is-ready' : 'is-warning' ) . '"><div class="dreamax-lm-status-icon"><span class="dashicons dashicons-' . esc_attr( $icons[ $id ] ?? 'info-outline' ) . '" aria-hidden="true"></span></div><div class="dreamax-lm-status-card-heading"><h2>' . esc_html( $check['label'] ) . '</h2><span class="dreamax-lm-status-pill">' . esc_html( $check['ready'] ? __( 'Ready', 'dreamax-license-manager' ) : __( 'Action needed', 'dreamax-license-manager' ) ) . '</span></div><p>' . esc_html( $check['detail'] ) . '</p>';
			if ( ! $check['ready'] && isset( $links[ $id ] ) ) {
				echo '<a class="button" href="' . esc_url( $links[ $id ] ) . '">' . esc_html__( 'Open setup', 'dreamax-license-manager' ) . '</a>';
			}
			echo '</section>';
		}
		echo '</div><section class="dreamax-lm-panel dreamax-lm-diagnostic-actions"><div><h2>' . esc_html__( 'Delivery smoke test', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Send a key-free message to your own administrator email address.', 'dreamax-license-manager' ) . '</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_send_test_email">';
		wp_nonce_field( 'dreamax_lm_send_test_email' );
		echo '<button class="button" type="submit"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span>' . esc_html__( 'Send test email', 'dreamax-license-manager' ) . '</button></form></section>';
		if ( current_user_can( Capabilities::SECURITY ) && ! $crypto_ready ) {
			echo '<section class="dreamax-lm-panel dreamax-lm-recovery-panel"><div><h2>' . esc_html__( 'Configure encryption', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Generate a copy-safe wp-config.php constant once. The generated value is not stored by this plugin.', 'dreamax-license-manager' ) . '</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_generate_master_key">';
			wp_nonce_field( 'dreamax_lm_generate_master_key' );
			echo '<button class="button button-secondary" type="submit"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span>' . esc_html__( 'Generate setup snippet', 'dreamax-license-manager' ) . '</button></form></section>';
		}
		echo '</div>';
	}

	/**
	 * Handles the create license operation.
	 */
	public function create_license(): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_create_license' );
		$product = isset( $_POST['product_public_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['product_public_id'] ) ) : '';
		if ( ! preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', $product ) ) {
			wp_die( esc_html__( 'The product public ID is invalid.', 'dreamax-license-manager' ) );
		}
		$limit_raw = isset( $_POST['activation_limit'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['activation_limit'] ) ) : '';
		$expires   = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expires_at'] ) ) : '';
		$attrs     = array(
			'product_public_id' => $product,
			'lifecycle_status'  => 'assigned',
			'activation_limit'  => '' === $limit_raw ? null : max( 0, (int) $limit_raw ),
			'expires_at'        => '' === $expires ? null : gmdate( 'Y-m-d H:i:s', strtotime( $expires . ' UTC' ) ),
			'actor_type'        => 'administrator',
			'actor_id'          => get_current_user_id(),
			'source'            => 'manual',
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Imported license keys intentionally preserve exact UTF-8 bytes after nonce/capability checks and domain validation.
		$key = isset( $_POST['license_key'] ) ? (string) wp_unslash( $_POST['license_key'] ) : '';
		try {
			$result = '' === $key ? ( new LicenseService() )->create_generated( $attrs ) : ( new LicenseService() )->import( $key, KeyNormalizer::IMPORTED, null, $attrs );
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
		wp_safe_redirect( add_query_arg( 'created', rawurlencode( $result['public_id'] ), admin_url( 'admin.php?page=dreamax-license-manager' ) ) );
		exit;
	}

	/**
	 * Saves internal notes without exposing them in a redirect or audit record.
	 */
	public function save_license_notes(): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_save_license_notes' );
		$public_id = isset( $_POST['license'] ) && is_string( $_POST['license'] ) ? sanitize_text_field( wp_unslash( $_POST['license'] ) ) : '';
		$revision  = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : '';
		$note      = isset( $_POST['internal_note'] ) && is_string( $_POST['internal_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is checked below and then validated against a strict field allowlist by the service.
		$raw_keys = isset( $_POST['field_keys'] ) && is_array( $_POST['field_keys'] ) ? wp_unslash( $_POST['field_keys'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each element is sanitized below and bounded by the service.
		$raw_vals = isset( $_POST['field_values'] ) && is_array( $_POST['field_values'] ) ? wp_unslash( $_POST['field_values'] ) : array();
		if ( count( $raw_keys ) > 10 || count( $raw_keys ) !== count( $raw_vals ) ) {
			wp_die( esc_html__( 'A maximum of 10 paired reference fields is allowed.', 'dreamax-license-manager' ) );
		}
		$fields = array();
		foreach ( $raw_keys as $index => $raw_key ) {
			if ( ! is_string( $raw_key ) || ! isset( $raw_vals[ $index ] ) || ! is_string( $raw_vals[ $index ] ) ) {
				wp_die( esc_html__( 'Reference fields must be text.', 'dreamax-license-manager' ) );
			}
			$key = trim( sanitize_key( $raw_key ) );
			$val = trim( sanitize_text_field( $raw_vals[ $index ] ) );
			if ( '' === $key && '' === $val ) {
				continue;
			}
			if ( '' === $key || isset( $fields[ $key ] ) ) {
				wp_die( esc_html__( 'Every reference value needs a unique field name.', 'dreamax-license-manager' ) );
			}
			$fields[ $key ] = $val;
		}
		try {
			( new MerchantMetadata() )->save( $public_id, $revision, $note, $fields, get_current_user_id() );
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'dreamax-license-manager-license',
					'license'     => $public_id,
					'notes_saved' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the bulk lifecycle operation.
	 */
	public function bulk_lifecycle(): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_bulk_lifecycle' );
		$confirmed = isset( $_POST['confirm_operation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_operation'] ) ) : '';
		if ( '1' !== $confirmed ) {
			wp_die( esc_html__( 'Explicit confirmation is required.', 'dreamax-license-manager' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The collection shape is checked here and every string element is sanitized and format-validated below.
		$raw_ids = isset( $_POST['license_ids'] ) && is_array( $_POST['license_ids'] ) ? wp_unslash( $_POST['license_ids'] ) : array();
		if ( count( $raw_ids ) > 100 ) {
			wp_die( esc_html__( 'A maximum of 100 licenses can be changed in one request.', 'dreamax-license-manager' ) );
		}
		$ids = array();
		foreach ( array_slice( $raw_ids, 0, 100 ) as $raw_id ) {
			if ( is_string( $raw_id ) ) {
				$id = sanitize_text_field( $raw_id );
				if ( preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $id ) ) {
					$ids[] = $id;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( array() === $ids ) {
			wp_die( esc_html__( 'Select at least one valid license.', 'dreamax-license-manager' ) );
		}

		$operation = isset( $_POST['bulk_operation'] ) ? sanitize_key( wp_unslash( (string) $_POST['bulk_operation'] ) ) : '';
		if ( ! in_array( $operation, array( 'suspend', 'restore', 'revoke', 'extend', 'reset', 'delete', 'export' ), true ) ) {
			wp_die( esc_html__( 'The requested operation is invalid.', 'dreamax-license-manager' ) );
		}
		if ( 'delete' === $operation ) {
			$this->authorize( Capabilities::DELETE );
		}

		$reason            = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		$days              = isset( $_POST['extension_days'] ) ? (int) $_POST['extension_days'] : 0;
		$base_operation_id = isset( $_POST['operation_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['operation_id'] ) ) : '';
		if ( ! wp_is_uuid( $base_operation_id, 4 ) ) {
			wp_die( esc_html__( 'The operation identifier is invalid. Reload the page and try again.', 'dreamax-license-manager' ) );
		}
		if ( 'export' === $operation ) {
			( new CsvController() )->export_selected( $ids );
			exit;
		}

		$service = new LifecycleService();
		$changed = 0;
		$failed  = 0;
		foreach ( $ids as $public_id ) {
			try {
				$operation_id = $base_operation_id . ':' . $public_id;
				switch ( $operation ) {
					case 'suspend':
						$service->transition( $public_id, 'suspended', $reason, $operation_id, 'administrator', get_current_user_id() );
						break;
					case 'restore':
						$service->restore( $public_id, $reason, $operation_id, 'administrator', get_current_user_id() );
						break;
					case 'revoke':
						$service->transition( $public_id, 'revoked', $reason, $operation_id, 'administrator', get_current_user_id() );
						break;
					case 'extend':
						$service->extend( $public_id, $days, $reason, $operation_id, 'administrator', get_current_user_id() );
						break;
					case 'reset':
						$service->reset_activations( $public_id, $reason, $operation_id, 'administrator', get_current_user_id() );
						break;
					case 'delete':
						$service->delete_available( $public_id, $reason, $operation_id, 'administrator', get_current_user_id() );
						break;
				}
				++$changed;
			} catch ( Throwable $error ) {
				++$failed;
				$this->record_rejection( $public_id, $operation, $base_operation_id );
			}
		}

		$return_license = isset( $_POST['return_license'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['return_license'] ) ) : '';
		$url            = preg_match( '/^lic_[A-Za-z0-9_-]{22}$/D', $return_license ) && 'delete' !== $operation
			? add_query_arg(
				array(
					'page'    => 'dreamax-license-manager-license',
					'license' => $return_license,
					'changed' => $changed,
					'failed'  => $failed,
				),
				admin_url( 'admin.php' )
			)
			: add_query_arg(
				array(
					'page'    => 'dreamax-license-manager',
					'changed' => $changed,
					'failed'  => $failed,
				),
				admin_url( 'admin.php' )
			);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Handles the reassign license operation.
	 */
	public function reassign_license(): void {
		$this->authorize( Capabilities::MANAGE );
		check_admin_referer( 'dreamax_lm_reassign_license' );
		$confirmed = isset( $_POST['confirm_operation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_operation'] ) ) : '';
		if ( '1' !== $confirmed ) {
			wp_die( esc_html__( 'Explicit confirmation is required.', 'dreamax-license-manager' ) );
		}
		$public_id    = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['license'] ) ) : '';
		$operation_id = isset( $_POST['operation_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['operation_id'] ) ) : '';
		$reason       = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['reason'] ) ) : '';
		$target       = array(
			'customer_id'       => isset( $_POST['target_customer_id'] ) ? (int) $_POST['target_customer_id'] : 0,
			'order_id'          => isset( $_POST['target_order_id'] ) ? (int) $_POST['target_order_id'] : 0,
			'product_public_id' => isset( $_POST['target_product_public_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['target_product_public_id'] ) ) : '',
		);
		try {
			( new LifecycleService() )->reassign( $public_id, $target, isset( $_POST['reset_activations'] ), isset( $_POST['notify_customer'] ), $reason, $operation_id, 'administrator', get_current_user_id() );
		} catch ( Throwable $error ) {
			$this->record_rejection( $public_id, 'reassign', $operation_id );
			wp_die( esc_html( $error->getMessage() ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'dreamax-license-manager-license',
					'license' => $public_id,
					'changed' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the master key operation.
	 */
	public function master_key(): void {
		$this->authorize( Capabilities::SECURITY );
		check_admin_referer( 'dreamax_lm_generate_master_key' );
		nocache_headers();
		$secret  = Base64Url::encode( random_bytes( 32 ) );
		$snippet = "define( 'DREAMAX_LICENSE_MANAGER_MASTER_KEY', '" . $secret . "' );";
		echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'Dreamax encryption setup', 'dreamax-license-manager' ) . '</title><h1>' . esc_html__( 'Copy this once', 'dreamax-license-manager' ) . '</h1><p>' . esc_html__( 'Place this line in wp-config.php before the line that says to stop editing. Back up the value separately with your database backup. This page will not show it again.', 'dreamax-license-manager' ) . '</p><pre>' . esc_html( $snippet ) . '</pre>';
		exit;
	}

	/**
	 * Handles the create credential operation.
	 */
	public function create_credential(): void {
		$this->authorize( Capabilities::CREDENTIALS );
		check_admin_referer( 'dreamax_lm_create_credential' );
		$this->credential_secret_response_headers();
		$name            = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$scopes          = isset( $_POST['scopes'] ) && is_array( $_POST['scopes'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['scopes'] ) ) : array();
		$expires         = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expires_at'] ) ) : '';
		$operation_id    = isset( $_POST['credential_operation_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['credential_operation_id'] ) ) : '';
		$operation_nonce = isset( $_POST['credential_operation_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['credential_operation_nonce'] ) ) : '';
		$actor_id        = get_current_user_id();
		sort( $scopes, SORT_STRING );
		try {
			$outcome = ( new CredentialAdminOperation() )->execute(
				$operation_id,
				$operation_nonce,
				CredentialAdminOperation::CREATE,
				'',
				$actor_id,
				array(
					'name'       => $name,
					'scopes'     => $scopes,
					'expires_at' => $expires,
				),
				static fn(): array => ( new CredentialService() )->create( $name, $scopes, '' === $expires ? null : $expires, $actor_id )
			);
		} catch ( Throwable $error ) {
			wp_die( esc_html__( 'The credential could not be created. Check the submitted fields and try again.', 'dreamax-license-manager' ) );
		}
		if ( ! $outcome['processed'] || ! is_array( $outcome['result'] ) ) {
			$this->credential_operation_rejected();
			return;
		}
		$result = $outcome['result'];
		$this->render_credential_secret_response(
			__( 'Copy this credential once', 'dreamax-license-manager' ),
			__( 'Store it in a server-side secret manager. It cannot be recovered later.', 'dreamax-license-manager' ),
			$result['credential']
		);
		exit;
	}

	/**
	 * Handles nonce-protected, zero-overlap credential rotation.
	 */
	public function rotate_credential(): void {
		$this->authorize( Capabilities::CREDENTIALS );
		$public_id = isset( $_POST['public_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['public_id'] ) ) : '';
		check_admin_referer( 'dreamax_lm_rotate_credential_' . $public_id );
		$confirmed = isset( $_POST['confirm_rotation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_rotation'] ) ) : '';
		$version   = isset( $_POST['expected_version'] ) ? absint( wp_unslash( $_POST['expected_version'] ) ) : 0;
		if ( '1' !== $confirmed || $version < 1 ) {
			wp_die( esc_html__( 'Explicit rotation confirmation and a current secret version are required.', 'dreamax-license-manager' ) );
		}
		$this->credential_secret_response_headers();
		$operation_id    = isset( $_POST['credential_operation_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['credential_operation_id'] ) ) : '';
		$operation_nonce = isset( $_POST['credential_operation_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['credential_operation_nonce'] ) ) : '';
		$actor_id        = get_current_user_id();
		try {
			$outcome = ( new CredentialAdminOperation() )->execute(
				$operation_id,
				$operation_nonce,
				CredentialAdminOperation::ROTATE,
				$this->credential_rotation_context( $public_id, $version ),
				$actor_id,
				array(
					'public_id'        => $public_id,
					'expected_version' => $version,
				),
				static fn(): array => ( new CredentialService() )->rotate( $public_id, $version, array(), $actor_id )
			);
		} catch ( Throwable $error ) {
			wp_die( esc_html__( 'The credential could not be rotated. Refresh the credential list before retrying.', 'dreamax-license-manager' ) );
		}
		if ( ! $outcome['processed'] || ! is_array( $outcome['result'] ) ) {
			$this->credential_operation_rejected();
			return;
		}
		$result = $outcome['result'];
		$this->render_credential_secret_response(
			__( 'Copy this replacement credential once', 'dreamax-license-manager' ),
			__( 'The prior secret is already invalid. Store this replacement in a server-side secret manager; it cannot be recovered later.', 'dreamax-license-manager' ),
			$result['credential']
		);
		exit;
	}

	/**
	 * Renders one cache-protected credential response without the WordPress shell.
	 *
	 * @param string $title One-time response title.
	 * @param string $description One-time response guidance.
	 * @param string $credential Plaintext credential shown only in this response.
	 */
	private function render_credential_secret_response( string $title, string $description, string $credential ): void {
		wp_enqueue_style( 'dreamax-lm-admin', DREAMAX_LM_URL . 'assets/css/admin.css', array(), DREAMAX_LM_VERSION );
		wp_enqueue_script( 'dreamax-lm-admin', DREAMAX_LM_URL . 'assets/js/admin.js', array(), DREAMAX_LM_VERSION, true );
		echo '<!doctype html><html lang="' . esc_attr( get_bloginfo( 'language' ) ) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>' . esc_html( $title ) . '</title>';
		wp_print_styles( 'dreamax-lm-admin' );
		echo '</head><body class="dreamax-lm-secret-screen"><main class="dreamax-lm-admin dreamax-lm-secret-response"><p class="dreamax-lm-eyebrow">' . esc_html__( 'One-time secret', 'dreamax-license-manager' ) . '</p><h1>' . esc_html( $title ) . '</h1><p class="dreamax-lm-secret-intro">' . esc_html( $description ) . '</p><section class="dreamax-lm-secret-card"><div class="dreamax-lm-secret-warning"><strong>' . esc_html__( 'This is the only time the complete credential will be displayed.', 'dreamax-license-manager' ) . '</strong><span>' . esc_html__( 'Do not place it in screenshots, chat, email, browser code, or source control.', 'dreamax-license-manager' ) . '</span></div><div class="dreamax-lm-secret-value"><code id="dreamax-lm-one-time-credential" data-dlm-secret-value>' . esc_html( $credential ) . '</code><button class="button button-primary" data-dlm-copy-secret="#dreamax-lm-one-time-credential" type="button">' . esc_html__( 'Copy credential', 'dreamax-license-manager' ) . '</button></div></section><div class="dreamax-lm-secret-actions"><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=dreamax-license-manager-credentials' ) ) . '">' . esc_html__( 'Return to API credentials', 'dreamax-license-manager' ) . '</a><p>' . esc_html__( 'Leaving this page permanently removes the one-time display.', 'dreamax-license-manager' ) . '</p></div></main>';
		wp_print_scripts( 'dreamax-lm-admin' );
		echo '</body></html>';
	}

	/**
	 * Renders a one-time operation identifier and its user-bound nonce.
	 *
	 * @param array $operation Issued operation fields.
	 * @phpstan-param array{id:string,nonce:string} $operation Issued operation fields.
	 */
	private function credential_operation_fields( array $operation ): void {
		echo '<input type="hidden" name="credential_operation_id" value="' . esc_attr( $operation['id'] ) . '"><input type="hidden" name="credential_operation_nonce" value="' . esc_attr( $operation['nonce'] ) . '">';
	}

	/**
	 * Builds the non-secret target context for one rotation form.
	 *
	 * @param string $public_id Credential public ID.
	 * @param int    $version Expected secret version.
	 */
	private function credential_rotation_context( string $public_id, int $version ): string {
		return $public_id . '|' . $version;
	}

	/**
	 * Prevents browsers and intermediaries from retaining a one-time secret response.
	 */
	private function credential_secret_response_headers(): void {
		nocache_headers();
		header( 'Cache-Control: no-store, private, max-age=0', true );
		header( 'Referrer-Policy: no-referrer', true );
	}

	/**
	 * Returns one generic response for replayed, expired, or foreign operation tokens.
	 */
	private function credential_operation_rejected(): void {
		wp_die( esc_html__( 'This credential operation was already processed or expired. Return to API credentials and start a new operation.', 'dreamax-license-manager' ) );
	}

	/**
	 * Handles nonce-protected immediate credential revocation.
	 */
	public function revoke_credential(): void {
		$this->authorize( Capabilities::CREDENTIALS );
		$public_id = isset( $_POST['public_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['public_id'] ) ) : '';
		check_admin_referer( 'dreamax_lm_revoke_credential_' . $public_id );
		$confirmed = isset( $_POST['confirm_revocation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_revocation'] ) ) : '';
		if ( '1' !== $confirmed ) {
			wp_die( esc_html__( 'Explicit revocation confirmation is required.', 'dreamax-license-manager' ) );
		}
		try {
			( new CredentialService() )->revoke( $public_id, get_current_user_id() );
		} catch ( Throwable $error ) {
			wp_die( esc_html__( 'The credential could not be revoked.', 'dreamax-license-manager' ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'dreamax-license-manager-credentials',
					'credential_revoked' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the authorize operation.
	 *
	 * @param string $capability Capability value.
	 */
	private function authorize( string $capability ): void {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You are not allowed to perform this action.', 'dreamax-license-manager' ), 403 );
		}
	}

	/**
	 * Handles the render events operation.
	 *
	 * @param array $events Events value.
	 * @phpstan-param list<array<string,mixed>> $events Events value.
	 * @param bool  $show_license Show license value.
	 */
	private function render_events( array $events, bool $show_license = false ): void {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Event', 'dreamax-license-manager' ) . '</th>';
		if ( $show_license ) {
			echo '<th>' . esc_html__( 'License', 'dreamax-license-manager' ) . '</th>';
		}
		echo '<th>' . esc_html__( 'Actor', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Details', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			$metadata = json_decode( (string) ( $event['metadata'] ?? '{}' ), true );
			if ( is_array( $metadata ) ) {
				unset( $metadata['public_id'], $metadata['license_public_id'] );
			}
			$event_type = (string) $event['event_type'];
			$event_name = ucwords( str_replace( array( '_', '-' ), ' ', $event_type ) );
			$actor_name = ucwords( str_replace( array( '_', '-' ), ' ', (string) $event['actor_type'] ) );
			echo '<tr><td>' . esc_html( (string) $event['occurred_at'] ) . '</td><td><strong class="dreamax-lm-event-name">' . esc_html( $event_name ) . '</strong><code class="dreamax-lm-event-code">' . esc_html( $event_type ) . '</code></td>';
			if ( $show_license ) {
				$license_id = isset( $event['license_public_id'] ) ? (string) $event['license_public_id'] : '';
				if ( '' !== $license_id ) {
					$link = add_query_arg(
						array(
							'page'    => 'dreamax-license-manager-license',
							'license' => $license_id,
						),
						admin_url( 'admin.php' )
					);
					echo '<td><a href="' . esc_url( $link ) . '"><code>' . esc_html( $license_id ) . '</code></a></td>';
				} else {
					echo '<td>—</td>';
				}
			}
			echo '<td>' . esc_html( $actor_name . ( empty( $event['actor_id'] ) ? '' : ' #' . (int) $event['actor_id'] ) ) . '</td><td class="dreamax-lm-event-details">';
			$this->render_event_details( is_array( $metadata ) ? $metadata : array() );
			echo '</td></tr>';
		}
		if ( array() === $events ) {
			echo '<tr><td class="dreamax-lm-empty dreamax-lm-empty--compact" colspan="' . esc_attr( $show_license ? '5' : '4' ) . '"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><strong>' . esc_html__( 'No activity recorded', 'dreamax-license-manager' ) . '</strong></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Renders already-sanitized audit metadata as readable key-value facts.
	 *
	 * @param array $metadata Sanitized event metadata.
	 * @phpstan-param array<string,mixed> $metadata Sanitized event metadata.
	 */
	private function render_event_details( array $metadata ): void {
		if ( array() === $metadata ) {
			echo '<span class="dreamax-lm-muted">—</span>';
			return;
		}

		echo '<dl class="dreamax-lm-metadata">';
		foreach ( $metadata as $key => $value ) {
			$label = ucwords( str_replace( array( '_', '-' ), ' ', (string) $key ) );
			if ( is_bool( $value ) ) {
				$display = $value ? __( 'Yes', 'dreamax-license-manager' ) : __( 'No', 'dreamax-license-manager' );
			} elseif ( null === $value || '' === $value ) {
				$display = '—';
			} elseif ( is_scalar( $value ) ) {
				$display = (string) $value;
			} else {
				$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$display = is_string( $encoded ) ? $encoded : '—';
			}
			echo '<div><dt>' . esc_html( $label ) . '</dt><dd><code>' . esc_html( $display ) . '</code></dd></div>';
		}
		echo '</dl>';
	}

	/**
	 * Handles the record rejection operation.
	 *
	 * @param string $public_id Public id value.
	 * @param string $operation Operation value.
	 * @param string $request_id Request id value.
	 */
	private function record_rejection( string $public_id, string $operation, string $request_id ): void {
		try {
			$row = ( new LicenseRepository() )->by_public_id( $public_id );
			if ( is_array( $row ) ) {
				( new EventRepository() )->append( AuditEventCatalog::LICENSE_OPERATION_REJECTED, (int) $row['id'], 'administrator', get_current_user_id(), $request_id, array( 'operation' => $operation ), AuditEventCatalog::SCHEMA_V1 );
			}
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Records a fixed operational message without user data or secrets.
			error_log( 'Dreamax could not record a rejected lifecycle operation.' );
		}
	}

	/**
	 * Handles the status label operation.
	 *
	 * @param array $row Row value.
	 * @phpstan-param array<string,mixed> $row Row value.
	 */
	private function status_label( array $row ): string {
		$labels = array(
			'revoked'   => __( 'Revoked', 'dreamax-license-manager' ),
			'suspended' => __( 'Suspended', 'dreamax-license-manager' ),
			'expired'   => __( 'Expired', 'dreamax-license-manager' ),
			'available' => __( 'Available', 'dreamax-license-manager' ),
			'delivered' => __( 'Delivered', 'dreamax-license-manager' ),
			'assigned'  => __( 'Assigned', 'dreamax-license-manager' ),
		);

		return $labels[ $this->status_key( $row ) ];
	}

	/**
	 * Returns a presentation state without changing the persisted lifecycle.
	 *
	 * An assigned license is described as delivered only when it is linked to
	 * a customer or order. This keeps manually created inventory truthful.
	 *
	 * @param array $row License row.
	 * @phpstan-param array<string,mixed> $row License row.
	 */
	private function status_key( array $row ): string {
		$lifecycle = (string) ( $row['lifecycle_status'] ?? '' );
		if ( 'revoked' === $lifecycle || 'suspended' === $lifecycle ) {
			return $lifecycle;
		}
		if ( ! empty( $row['expires_at'] ) && strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() ) {
			return 'expired';
		}
		if ( 'available' === $lifecycle ) {
			return 'available';
		}

		return ! empty( $row['customer_id'] ) || ! empty( $row['order_id'] ) ? 'delivered' : 'assigned';
	}

	/**
	 * Renders one allowlisted, filter-preserving inventory sort link.
	 *
	 * @param string              $label Column label.
	 * @param string              $key Sort key.
	 * @param string              $current Current sort key.
	 * @param string              $direction Current direction.
	 * @param array<string,mixed> $args Current read-only filters.
	 */
	private function sortable_header( string $label, string $key, string $current, string $direction, array $args ): void {
		$active = $key === $current;
		$next   = $active && 'asc' === $direction ? 'desc' : 'asc';
		$url    = add_query_arg(
			array_merge(
				$args,
				array(
					'orderby' => $key,
					'order'   => $next,
				)
			),
			admin_url( 'admin.php' )
		);
		echo '<th scope="col"' . ( $active ? ' aria-sort="' . esc_attr( 'asc' === $direction ? 'ascending' : 'descending' ) . '"' : '' ) . '><a class="dreamax-lm-sort-link" href="' . esc_url( $url ) . '">' . esc_html( $label );
		if ( $active ) {
			echo '<span class="dashicons dashicons-arrow-' . esc_attr( 'asc' === $direction ? 'up-alt2' : 'down-alt2' ) . '" aria-hidden="true"></span>';
		}
		echo '</a></th>';
	}

	/**
	 * Renders a product reference without linking a missing or unauthorized post.
	 *
	 * @param array<string,mixed> $row License row.
	 */
	private function render_product_reference( array $row ): void {
		$public_id  = (string) ( $row['product_public_id'] ?? '' );
		$product_id = (int) ( $row['product_id'] ?? 0 );
		$url        = $product_id > 0 && 'product' === get_post_type( $product_id ) && current_user_can( 'edit_post', $product_id ) ? get_edit_post_link( $product_id, '' ) : '';
		if ( is_string( $url ) && '' !== $url ) {
			echo '<a href="' . esc_url( $url ) . '"><code>' . esc_html( $public_id ) . '</code></a>';
			return;
		}
		echo '<code>' . esc_html( $public_id ) . '</code>';
	}

	/**
	 * Renders customer and order references with separate edit permissions.
	 *
	 * @param array<string,mixed> $row License row.
	 */
	private function render_ownership_references( array $row ): void {
		$customer_id = (int) ( $row['customer_id'] ?? 0 );
		$order_id    = (int) ( $row['order_id'] ?? 0 );
		if ( $customer_id < 1 && $order_id < 1 ) {
			echo esc_html__( 'Not linked', 'dreamax-license-manager' );
			return;
		}
		if ( $customer_id > 0 ) {
			/* translators: %d: WordPress customer ID. */
			$label = sprintf( __( 'Customer #%d', 'dreamax-license-manager' ), $customer_id );
			$url   = get_userdata( $customer_id ) && current_user_can( 'edit_user', $customer_id ) ? get_edit_user_link( $customer_id ) : '';
			echo is_string( $url ) && '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
		}
		if ( $customer_id > 0 && $order_id > 0 ) {
			echo ' <span aria-hidden="true">|</span> ';
		}
		if ( $order_id > 0 ) {
			/* translators: %d: WooCommerce order ID. */
			$label = sprintf( __( 'Order #%d', 'dreamax-license-manager' ), $order_id );
			$order = wc_get_order( $order_id );
			// phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce registers the shop-order edit meta capability, including HPOS storage.
			$url = $order instanceof \WC_Order && current_user_can( 'edit_shop_order', $order_id ) ? $order->get_edit_order_url() : '';
			echo is_string( $url ) && '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : esc_html( $label );
		}
	}

	/**
	 * Renders one inventory summary card.
	 *
	 * @param string $label Card label.
	 * @param int    $value Card value.
	 * @param string $icon WordPress Dashicon name.
	 */
	private function summary_card( string $label, int $value, string $icon ): void {
		echo '<article class="dreamax-lm-summary-card dreamax-lm-summary-card--' . esc_attr( $icon ) . '"><span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span><div><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong><span>' . esc_html( $label ) . '</span></div></article>';
	}
}
