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
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LifecycleService;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\Support\Base64Url;
use Dreamax\LicenseManager\Support\Capabilities;
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
		add_action( 'admin_post_dreamax_lm_create_license', array( $this, 'create_license' ) );
		add_action( 'admin_post_dreamax_lm_bulk_lifecycle', array( $this, 'bulk_lifecycle' ) );
		add_action( 'admin_post_dreamax_lm_reassign_license', array( $this, 'reassign_license' ) );
		add_action( 'admin_post_dreamax_lm_generate_master_key', array( $this, 'master_key' ) );
		add_action( 'admin_post_dreamax_lm_create_credential', array( $this, 'create_credential' ) );
		add_action( 'admin_post_dreamax_lm_rotate_credential', array( $this, 'rotate_credential' ) );
		add_action( 'admin_post_dreamax_lm_revoke_credential', array( $this, 'revoke_credential' ) );
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
		$status      = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$order_id    = isset( $_GET['order_id'] ) ? max( 0, (int) $_GET['order_id'] ) : 0;
		$customer_id = isset( $_GET['customer_id'] ) ? max( 0, (int) $_GET['customer_id'] ) : 0;
		$expiry      = isset( $_GET['expiry'] ) ? sanitize_key( wp_unslash( (string) $_GET['expiry'] ) ) : '';
		$clauses     = array();
		$args        = array();
		if ( '' !== $search ) {
			$clauses[] = '(l.public_id LIKE %s OR l.product_public_id LIKE %s)';
			$args[]    = '%' . $wpdb->esc_like( $search ) . '%';
			$args[]    = '%' . $wpdb->esc_like( $search ) . '%';
		}
		if ( in_array( $status, array( 'available', 'assigned', 'suspended', 'revoked' ), true ) ) {
			$clauses[] = 'l.lifecycle_status=%s';
			$args[]    = $status;
		}
		if ( $order_id > 0 ) {
			$clauses[] = 'l.order_id=%d';
			$args[]    = $order_id;
		}
		if ( $customer_id > 0 ) {
			$clauses[] = 'l.customer_id=%d';
			$args[]    = $customer_id;
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( 'expired' === $expiry ) {
			$clauses[] = 'l.expires_at IS NOT NULL AND l.expires_at<=%s';
			$args[]    = $now;
		} elseif ( 'soon' === $expiry ) {
			$clauses[] = 'l.expires_at>%s AND l.expires_at<=%s';
			$args[]    = $now;
			$args[]    = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
		} elseif ( 'lifetime' === $expiry ) {
			$clauses[] = 'l.expires_at IS NULL';
		}
		$where            = $clauses ? ' WHERE ' . implode( ' AND ', $clauses ) : '';
		$table            = $wpdb->prefix . 'dreamax_lm_licenses';
		$activation_table = $wpdb->prefix . 'dreamax_lm_activations';
		$sql              = "SELECT l.*, COALESCE(a.active_count,0) AS active_count FROM {$table} l LEFT JOIN (SELECT license_id,COUNT(*) AS active_count FROM {$activation_table} WHERE status='active' GROUP BY license_id) a ON a.license_id=l.id{$where} ORDER BY l.created_at DESC LIMIT 50 OFFSET %d";
		$args[]           = ( $page - 1 ) * 50;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted table/clause fragments are combined with prepared values; this administration query must be fresh.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ), ARRAY_A );

		echo '<div class="wrap"><h1>' . esc_html__( 'Licenses', 'dreamax-license-manager' ) . ' <a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=dreamax-license-manager-add' ) ) . '">' . esc_html__( 'Add license', 'dreamax-license-manager' ) . '</a></h1>';
		if ( isset( $_GET['changed'] ) ) {
			/* translators: %d: Number of completed license operations. */
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%d license operation(s) completed.', 'dreamax-license-manager' ), max( 0, (int) $_GET['changed'] ) ) ) . '</p></div>';
		}
		if ( isset( $_GET['failed'] ) && (int) $_GET['failed'] > 0 ) {
			/* translators: %d: Number of rejected license operations. */
			echo '<div class="notice notice-warning"><p>' . esc_html( sprintf( __( '%d operation(s) were rejected. Review the activity log and license state.', 'dreamax-license-manager' ), (int) $_GET['failed'] ) ) . '</p></div>';
		}
		/* phpcs:enable WordPress.Security.NonceVerification.Recommended */
		echo '<form method="get"><input type="hidden" name="page" value="dreamax-license-manager"><p class="tablenav top">';
		echo '<input type="search" name="s" placeholder="' . esc_attr__( 'Public or product ID', 'dreamax-license-manager' ) . '" value="' . esc_attr( $search ) . '"> ';
		echo '<select name="status"><option value="">' . esc_html__( 'All states', 'dreamax-license-manager' ) . '</option>';
		foreach ( array(
			'available' => __( 'Available', 'dreamax-license-manager' ),
			'assigned'  => __( 'Assigned', 'dreamax-license-manager' ),
			'suspended' => __( 'Suspended', 'dreamax-license-manager' ),
			'revoked'   => __( 'Revoked', 'dreamax-license-manager' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <input type="number" min="1" name="order_id" placeholder="' . esc_attr__( 'Order ID', 'dreamax-license-manager' ) . '" value="' . esc_attr( $order_id ? $order_id : '' ) . '"> <input type="number" min="1" name="customer_id" placeholder="' . esc_attr__( 'Customer ID', 'dreamax-license-manager' ) . '" value="' . esc_attr( $customer_id ? $customer_id : '' ) . '"> ';
		echo '<select name="expiry"><option value="">' . esc_html__( 'Any expiry', 'dreamax-license-manager' ) . '</option><option value="expired"' . selected( $expiry, 'expired', false ) . '>' . esc_html__( 'Expired', 'dreamax-license-manager' ) . '</option><option value="soon"' . selected( $expiry, 'soon', false ) . '>' . esc_html__( 'Expires within 30 days', 'dreamax-license-manager' ) . '</option><option value="lifetime"' . selected( $expiry, 'lifetime', false ) . '>' . esc_html__( 'Never expires', 'dreamax-license-manager' ) . '</option></select> <button class="button">' . esc_html__( 'Filter', 'dreamax-license-manager' ) . '</button></p></form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_bulk_lifecycle"><input type="hidden" name="operation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'dreamax_lm_bulk_lifecycle' );
		echo '<table class="widefat striped"><thead><tr><td class="check-column"><input id="dreamax-lm-select-all" type="checkbox" aria-label="' . esc_attr__( 'Select all visible licenses', 'dreamax-license-manager' ) . '"></td><th>' . esc_html__( 'Public ID', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Product ID', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Customer / order', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Activation use', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Expires', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$count = (int) $row['active_count'];
			/* translators: 1: Current activation count. 2: Activation limit. */
			$usage  = null === $row['activation_limit'] ? sprintf( __( 'Unlimited; %d in use', 'dreamax-license-manager' ), $count ) : sprintf( __( '%1$d of %2$d in use', 'dreamax-license-manager' ), $count, (int) $row['activation_limit'] );
			$detail = add_query_arg(
				array(
					'page'    => 'dreamax-license-manager-license',
					'license' => (string) $row['public_id'],
				),
				admin_url( 'admin.php' )
			);
			/* translators: 1: License public ID. 2: Customer ID. 3: Order ID. */
			echo '<tr><th class="check-column"><input class="dreamax-license-select" type="checkbox" name="license_ids[]" value="' . esc_attr( (string) $row['public_id'] ) . '" aria-label="' . esc_attr( sprintf( __( 'Select license %s', 'dreamax-license-manager' ), (string) $row['public_id'] ) ) . '"></th><td><a href="' . esc_url( $detail ) . '"><code>' . esc_html( (string) $row['public_id'] ) . '</code></a></td><td><code>' . esc_html( (string) $row['product_public_id'] ) . '</code></td><td>' . esc_html( sprintf( __( 'Customer %1$s / Order %2$s', 'dreamax-license-manager' ), $row['customer_id'] ? $row['customer_id'] : '—', $row['order_id'] ? $row['order_id'] : '—' ) ) . '</td><td>' . esc_html( $this->status_label( $row ) ) . '</td><td>' . esc_html( $usage ) . '</td><td>' . esc_html( $row['expires_at'] ? $row['expires_at'] : __( 'Never', 'dreamax-license-manager' ) ) . '</td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No licenses found.', 'dreamax-license-manager' ) . '</td></tr>';
		}
		echo '</tbody></table><div class="tablenav bottom"><div class="alignleft actions"><select name="bulk_operation" required><option value="">' . esc_html__( 'Bulk action', 'dreamax-license-manager' ) . '</option><option value="suspend">' . esc_html__( 'Suspend', 'dreamax-license-manager' ) . '</option><option value="restore">' . esc_html__( 'Restore', 'dreamax-license-manager' ) . '</option><option value="revoke">' . esc_html__( 'Permanently revoke', 'dreamax-license-manager' ) . '</option><option value="extend">' . esc_html__( 'Extend expiry', 'dreamax-license-manager' ) . '</option><option value="reset">' . esc_html__( 'Reset activations', 'dreamax-license-manager' ) . '</option>';
		if ( current_user_can( Capabilities::DELETE ) ) {
			echo '<option value="delete">' . esc_html__( 'Permanently delete eligible pool records', 'dreamax-license-manager' ) . '</option>';
		}
		echo '</select> <input type="number" name="extension_days" min="1" max="3650" value="30" aria-label="' . esc_attr__( 'Extension days', 'dreamax-license-manager' ) . '"> <input type="text" name="reason" minlength="3" maxlength="500" required placeholder="' . esc_attr__( 'Required reason', 'dreamax-license-manager' ) . '"> <label><input type="checkbox" name="confirm_operation" value="1" required> ' . esc_html__( 'I confirm this operation', 'dreamax-license-manager' ) . '</label> <button class="button action">' . esc_html__( 'Apply', 'dreamax-license-manager' ) . '</button></div></div></form>';
		wp_print_inline_script_tag( "document.getElementById('dreamax-lm-select-all')?.addEventListener('change',function(){document.querySelectorAll('.dreamax-license-select').forEach(function(box){box.checked=this.checked;},this);});" );
		$base = remove_query_arg( 'paged' );
		echo '<p>';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $page - 1, $base ) ) . '">' . esc_html__( 'Previous', 'dreamax-license-manager' ) . '</a> ';
		}
		if ( is_array( $rows ) && 50 === count( $rows ) ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $page + 1, $base ) ) . '">' . esc_html__( 'Next', 'dreamax-license-manager' ) . '</a>';
		}
		echo '</p></div>';
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

		echo '<div class="wrap"><h1>' . esc_html__( 'License details', 'dreamax-license-manager' ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=dreamax-license-manager' ) ) . '">&larr; ' . esc_html__( 'Back to licenses', 'dreamax-license-manager' ) . '</a></p>';
		if ( isset( $_GET['changed'] ) && (int) $_GET['changed'] > 0 ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'The license operation was completed.', 'dreamax-license-manager' ) . '</p></div>';
		}
		if ( isset( $_GET['failed'] ) && (int) $_GET['failed'] > 0 ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The operation was rejected because it did not match the current license state or policy.', 'dreamax-license-manager' ) . '</p></div>';
		}
		/* phpcs:enable WordPress.Security.NonceVerification.Recommended */
		echo '<table class="widefat striped"><tbody><tr><th>' . esc_html__( 'Public ID', 'dreamax-license-manager' ) . '</th><td><code>' . esc_html( $public_id ) . '</code></td></tr><tr><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><td>' . esc_html( $this->status_label( $license ) ) . '</td></tr><tr><th>' . esc_html__( 'Product public ID', 'dreamax-license-manager' ) . '</th><td><code>' . esc_html( (string) $license['product_public_id'] ) . '</code></td></tr><tr><th>' . esc_html__( 'Customer', 'dreamax-license-manager' ) . '</th><td>' . esc_html( (string) ( $license['customer_id'] ? $license['customer_id'] : '—' ) ) . '</td></tr><tr><th>' . esc_html__( 'Order', 'dreamax-license-manager' ) . '</th><td>' . esc_html( (string) ( $license['order_id'] ? $license['order_id'] : '—' ) ) . '</td></tr><tr><th>' . esc_html__( 'Activation limit', 'dreamax-license-manager' ) . '</th><td>' . esc_html( null === $license['activation_limit'] ? __( 'Unlimited', 'dreamax-license-manager' ) : (string) $license['activation_limit'] ) . '</td></tr><tr><th>' . esc_html__( 'Expiry (UTC)', 'dreamax-license-manager' ) . '</th><td>' . esc_html( $license['expires_at'] ? $license['expires_at'] : __( 'Never', 'dreamax-license-manager' ) ) . '</td></tr></tbody></table>';

		echo '<h2>' . esc_html__( 'Lifecycle operation', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Extension adds days from the later of the current expiry or the current server time. A lifetime license cannot be extended. Revocation is permanent.', 'dreamax-license-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_bulk_lifecycle"><input type="hidden" name="license_ids[]" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="return_license" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="operation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'dreamax_lm_bulk_lifecycle' );
		echo '<p><label>' . esc_html__( 'Action', 'dreamax-license-manager' ) . ' <select name="bulk_operation" required><option value="suspend">' . esc_html__( 'Suspend temporarily', 'dreamax-license-manager' ) . '</option><option value="restore">' . esc_html__( 'Restore from suspension', 'dreamax-license-manager' ) . '</option><option value="revoke">' . esc_html__( 'Permanently revoke', 'dreamax-license-manager' ) . '</option><option value="extend">' . esc_html__( 'Extend expiry', 'dreamax-license-manager' ) . '</option><option value="reset">' . esc_html__( 'Reset all active installations', 'dreamax-license-manager' ) . '</option>';
		if ( current_user_can( Capabilities::DELETE ) ) {
			echo '<option value="delete">' . esc_html__( 'Permanently delete eligible pool record', 'dreamax-license-manager' ) . '</option>';
		}
		echo '</select></label> <label>' . esc_html__( 'Extension days', 'dreamax-license-manager' ) . ' <input type="number" name="extension_days" min="1" max="3650" value="30"></label></p><p><label>' . esc_html__( 'Reason', 'dreamax-license-manager' ) . ' <input class="regular-text" name="reason" minlength="3" maxlength="500" required></label></p><p><label><input type="checkbox" name="confirm_operation" value="1" required> ' . esc_html__( 'I understand and confirm this operation.', 'dreamax-license-manager' ) . '</label></p>';
		submit_button( __( 'Apply operation', 'dreamax-license-manager' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Reassign ownership', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Reassignment removes the old order-item link so the former owner can no longer view the key through that order. If an order is supplied, it must belong to the target customer.', 'dreamax-license-manager' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_reassign_license"><input type="hidden" name="license" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="operation_id" value="' . esc_attr( wp_generate_uuid4() ) . '">';
		wp_nonce_field( 'dreamax_lm_reassign_license' );
		echo '<table class="form-table"><tr><th><label for="target_customer_id">' . esc_html__( 'Target customer ID', 'dreamax-license-manager' ) . '</label></th><td><input type="number" min="1" required id="target_customer_id" name="target_customer_id"></td></tr><tr><th><label for="target_order_id">' . esc_html__( 'Target order ID', 'dreamax-license-manager' ) . '</label></th><td><input type="number" min="1" id="target_order_id" name="target_order_id"><p class="description">' . esc_html__( 'Optional. Leaving this blank removes the old order association.', 'dreamax-license-manager' ) . '</p></td></tr><tr><th><label for="target_product_public_id">' . esc_html__( 'Target product public ID', 'dreamax-license-manager' ) . '</label></th><td><input class="regular-text" pattern="prd_[A-Za-z0-9_-]{22}" id="target_product_public_id" name="target_product_public_id" value="' . esc_attr( (string) $license['product_public_id'] ) . '"></td></tr><tr><th><label for="reassign_reason">' . esc_html__( 'Reason', 'dreamax-license-manager' ) . '</label></th><td><input class="regular-text" minlength="3" maxlength="500" required id="reassign_reason" name="reason"></td></tr></table><p><label><input type="checkbox" name="reset_activations" value="1"> ' . esc_html__( 'Deactivate all existing installations', 'dreamax-license-manager' ) . '</label></p><p><label><input type="checkbox" name="notify_customer" value="1"> ' . esc_html__( 'Email the target customer without including the key', 'dreamax-license-manager' ) . '</label></p><p><label><input type="checkbox" name="confirm_operation" value="1" required> ' . esc_html__( 'I verified the old and new ownership and confirm reassignment.', 'dreamax-license-manager' ) . '</label></p>';
		submit_button( __( 'Reassign license', 'dreamax-license-manager' ), 'primary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'Installations', 'dreamax-license-manager' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Activation ID', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Label', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Activated', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Deactivated', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( is_array( $activations ) ? $activations : array() as $activation ) {
			echo '<tr><td><code>' . esc_html( (string) $activation['public_id'] ) . '</code></td><td>' . esc_html( (string) ( $activation['instance_label'] ? $activation['instance_label'] : '—' ) ) . '</td><td>' . esc_html( (string) $activation['status'] ) . '</td><td>' . esc_html( (string) $activation['activated_at'] ) . '</td><td>' . esc_html( (string) ( $activation['deactivated_at'] ? $activation['deactivated_at'] : '—' ) ) . '</td></tr>';
		}
		if ( ! $activations ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No installations recorded.', 'dreamax-license-manager' ) . '</td></tr>';
		}
		echo '</tbody></table><h2>' . esc_html__( 'Audit trail', 'dreamax-license-manager' ) . '</h2>';
		$this->render_events( $events );
		echo '</div>';
	}

	/**
	 * Handles the activity page operation.
	 */
	public function activity_page(): void {
		$this->authorize( Capabilities::MANAGE );
		echo '<div class="wrap"><h1>' . esc_html__( 'Recent license activity', 'dreamax-license-manager' ) . '</h1>';
		$this->render_events( ( new EventRepository() )->recent( 200 ), true );
		echo '</div>';
	}

	/**
	 * Handles the add page operation.
	 */
	public function add_page(): void {
		$this->authorize( Capabilities::MANAGE );
		echo '<div class="wrap"><h1>' . esc_html__( 'Add license', 'dreamax-license-manager' ) . '</h1><p>' . esc_html__( 'Create a secure generated key, or import one exact key. The product public ID must belong to the product that will validate this license.', 'dreamax-license-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_create_license">';
		wp_nonce_field( 'dreamax_lm_create_license' );
		echo '<table class="form-table"><tr><th><label for="product_public_id">' . esc_html__( 'Product public ID', 'dreamax-license-manager' ) . '</label></th><td><input class="regular-text" required pattern="prd_[A-Za-z0-9_-]{22}" id="product_public_id" name="product_public_id"></td></tr>';
		echo '<tr><th><label for="license_key">' . esc_html__( 'Imported key', 'dreamax-license-manager' ) . '</label></th><td><input class="regular-text" id="license_key" name="license_key"><p class="description">' . esc_html__( 'Leave blank to generate a key with the secure default. Imported keys are exact and case-sensitive.', 'dreamax-license-manager' ) . '</p></td></tr>';
		echo '<tr><th><label for="activation_limit">' . esc_html__( 'Activation limit', 'dreamax-license-manager' ) . '</label></th><td><input type="number" min="0" id="activation_limit" name="activation_limit" value="1"><p class="description">' . esc_html__( 'Blank means unlimited. Zero disables activation.', 'dreamax-license-manager' ) . '</p></td></tr>';
		echo '<tr><th><label for="expires_at">' . esc_html__( 'Expiry (UTC)', 'dreamax-license-manager' ) . '</label></th><td><input type="datetime-local" id="expires_at" name="expires_at"></td></tr></table>';
		submit_button( __( 'Create license', 'dreamax-license-manager' ) );
		echo '</form></div>';
	}

	/**
	 * Handles the transfer page operation.
	 */
	public function transfer_page(): void {
		$this->authorize( Capabilities::MANAGE );
		echo '<div class="wrap"><h1>' . esc_html__( 'Import and export', 'dreamax-license-manager' ) . '</h1><h2>' . esc_html__( 'Import CSV', 'dreamax-license-manager' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_import_csv">';
		wp_nonce_field( 'dreamax_lm_import_csv' );
		echo '<input type="file" name="csv" accept=".csv,text/csv" required> <label><input type="checkbox" name="dry_run" value="1" checked> ' . esc_html__( 'Preview only', 'dreamax-license-manager' ) . '</label>';
		submit_button( __( 'Check import', 'dreamax-license-manager' ), 'primary', 'submit', false );
		echo '</form><p>' . esc_html__( 'Required columns: license_key, product_public_id. Optional: activation_limit, expires_at, normalization_profile, separator.', 'dreamax-license-manager' ) . '</p>';
		echo '<h2>' . esc_html__( 'Export CSV', 'dreamax-license-manager' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_export_csv">';
		wp_nonce_field( 'dreamax_lm_export_csv' );
		if ( current_user_can( Capabilities::EXPORT ) ) {
			echo '<label><input type="checkbox" name="full_keys" value="1"> ' . esc_html__( 'Include full keys (audited)', 'dreamax-license-manager' ) . '</label>';
		}
		submit_button( __( 'Download export', 'dreamax-license-manager' ), 'secondary', 'submit', false );
		echo '</form></div>';
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
		echo '<div class="wrap"><h1>' . esc_html__( 'API credentials', 'dreamax-license-manager' ) . '</h1><p>' . esc_html__( 'A new or rotated secret is shown once. Store it in a server-side secret manager; never embed it in distributed client software. Lost or revoked secrets cannot be recovered.', 'dreamax-license-manager' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only post-action status notice behind the credential capability.
		if ( isset( $_GET['credential_revoked'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'The credential is revoked. Repeating revocation has no additional effect.', 'dreamax-license-manager' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_create_credential">';
		$this->credential_operation_fields( $create_operation );
		wp_nonce_field( 'dreamax_lm_create_credential' );
		echo '<p><label>' . esc_html__( 'Name', 'dreamax-license-manager' ) . ' <input name="name" maxlength="191" required></label></p><p><label>' . esc_html__( 'Expiration (UTC, optional)', 'dreamax-license-manager' ) . ' <input type="datetime-local" name="expires_at"></label></p>';
		foreach ( array( 'licenses:read', 'licenses:write', 'activations:read', 'generators:read' ) as $scope ) {
			echo '<label style="display:block"><input type="checkbox" name="scopes[]" value="' . esc_attr( $scope ) . '"> ' . esc_html( $scope ) . '</label>';
		}
		submit_button( __( 'Create credential', 'dreamax-license-manager' ) );
		echo '</form><h2>' . esc_html__( 'Existing credentials', 'dreamax-license-manager' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name / public ID', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Scopes', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Expiration / last used', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Actions', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$public_id = (string) $row['public_id'];
			$scopes    = is_array( $row['scopes'] ) ? implode( ', ', array_map( 'strval', $row['scopes'] ) ) : '';
			$status    = (string) $row['effective_status'];
			echo '<tr><td><strong>' . esc_html( (string) $row['name'] ) . '</strong><br><code>' . esc_html( $public_id ) . '</code><br>' . esc_html__( 'Secret version:', 'dreamax-license-manager' ) . ' ' . esc_html( (string) $row['secret_version'] ) . '</td><td>' . esc_html( $scopes ) . '</td><td>' . esc_html( $status ) . '</td><td>' . esc_html( $row['expires_at'] ? (string) $row['expires_at'] . ' UTC' : __( 'Never', 'dreamax-license-manager' ) ) . '<br>' . esc_html( $row['last_used_at'] ? (string) $row['last_used_at'] . ' UTC' : __( 'Never used', 'dreamax-license-manager' ) ) . '</td><td>';
			if ( 'active' === $status ) {
				$rotate_operation = $operations->issue( CredentialAdminOperation::ROTATE, $this->credential_rotation_context( $public_id, (int) $row['secret_version'] ), $actor_id );
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_rotate_credential"><input type="hidden" name="public_id" value="' . esc_attr( $public_id ) . '"><input type="hidden" name="expected_version" value="' . esc_attr( (string) $row['secret_version'] ) . '">';
				$this->credential_operation_fields( $rotate_operation );
				wp_nonce_field( 'dreamax_lm_rotate_credential_' . $public_id );
				echo '<label><input type="checkbox" name="confirm_rotation" value="1" required> ' . esc_html__( 'Invalidate the old secret immediately', 'dreamax-license-manager' ) . '</label>';
				submit_button( __( 'Rotate', 'dreamax-license-manager' ), 'secondary', 'submit', false );
				echo '</form>';
			}
			if ( 'revoked' !== $status ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:0.5em"><input type="hidden" name="action" value="dreamax_lm_revoke_credential"><input type="hidden" name="public_id" value="' . esc_attr( $public_id ) . '">';
				wp_nonce_field( 'dreamax_lm_revoke_credential_' . $public_id );
				echo '<label><input type="checkbox" name="confirm_revocation" value="1" required> ' . esc_html__( 'Permanently revoke', 'dreamax-license-manager' ) . '</label>';
				submit_button( __( 'Revoke', 'dreamax-license-manager' ), 'delete', 'submit', false );
				echo '</form>';
			}
			echo '</td></tr>';
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No API credentials exist.', 'dreamax-license-manager' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Handles the status page operation.
	 */
	public function status_page(): void {
		global $wpdb;
		$this->authorize( Capabilities::DIAGNOSTICS );
		$crypto = new Crypto();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$engine = $wpdb->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$wpdb->prefix}dreamax_lm_licenses'" );
		echo '<div class="wrap"><h1>' . esc_html__( 'System status', 'dreamax-license-manager' ) . '</h1><table class="widefat striped"><tbody>';
		echo '<tr><th>' . esc_html__( 'Encryption', 'dreamax-license-manager' ) . '</th><td>' . esc_html( $crypto->ready() ? __( 'Ready', 'dreamax-license-manager' ) : __( 'Recovery mode', 'dreamax-license-manager' ) ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'License table engine', 'dreamax-license-manager' ) . '</th><td>' . esc_html( (string) $engine ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Cleanup job', 'dreamax-license-manager' ) . '</th><td>' . esc_html( wp_next_scheduled( 'dreamax_lm_cleanup' ) ? __( 'Scheduled', 'dreamax-license-manager' ) : __( 'Not scheduled', 'dreamax-license-manager' ) ) . '</td></tr></tbody></table>';
		if ( current_user_can( Capabilities::SECURITY ) && ! $crypto->ready() ) {
			echo '<h2>' . esc_html__( 'Configure encryption', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Generate a copy-safe wp-config.php constant once. The generated value is not stored by this plugin.', 'dreamax-license-manager' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="dreamax_lm_generate_master_key">';
			wp_nonce_field( 'dreamax_lm_generate_master_key' );
			submit_button( __( 'Generate setup snippet', 'dreamax-license-manager' ), 'secondary' );
			echo '</form>';
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
		if ( ! in_array( $operation, array( 'suspend', 'restore', 'revoke', 'extend', 'reset', 'delete' ), true ) ) {
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
		echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'API credential created', 'dreamax-license-manager' ) . '</title><h1>' . esc_html__( 'Copy this credential once', 'dreamax-license-manager' ) . '</h1><p>' . esc_html__( 'Store it in a server-side secret manager. It cannot be recovered later.', 'dreamax-license-manager' ) . '</p><pre>' . esc_html( $result['credential'] ) . '</pre>';
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
		echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'API credential rotated', 'dreamax-license-manager' ) . '</title><h1>' . esc_html__( 'Copy this replacement credential once', 'dreamax-license-manager' ) . '</h1><p>' . esc_html__( 'The prior secret is already invalid. Store this replacement in a server-side secret manager; it cannot be recovered later.', 'dreamax-license-manager' ) . '</p><pre>' . esc_html( $result['credential'] ) . '</pre>';
		exit;
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
			$details  = is_array( $metadata ) && $metadata ? (string) wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '—';
			echo '<tr><td>' . esc_html( (string) $event['occurred_at'] ) . '</td><td><code>' . esc_html( (string) $event['event_type'] ) . '</code></td>';
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
			echo '<td>' . esc_html( (string) $event['actor_type'] . ( empty( $event['actor_id'] ) ? '' : ' #' . (int) $event['actor_id'] ) ) . '</td><td><code>' . esc_html( $details ) . '</code></td></tr>';
		}
		if ( array() === $events ) {
			echo '<tr><td colspan="' . esc_attr( $show_license ? '5' : '4' ) . '">' . esc_html__( 'No activity recorded.', 'dreamax-license-manager' ) . '</td></tr>';
		}
		echo '</tbody></table>';
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
		if ( 'revoked' === $row['lifecycle_status'] ) {
			return __( 'Permanently revoked', 'dreamax-license-manager' );
		}
		if ( 'suspended' === $row['lifecycle_status'] ) {
			return __( 'Temporarily disabled', 'dreamax-license-manager' );
		}
		if ( $row['expires_at'] && strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() ) {
			return __( 'Expired', 'dreamax-license-manager' );
		}
		return 'available' === $row['lifecycle_status'] ? __( 'Available for sale', 'dreamax-license-manager' ) : __( 'Delivered', 'dreamax-license-manager' );
	}
}
