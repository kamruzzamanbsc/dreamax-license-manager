<?php
/**
 * Defines the OrderWorkflowAdmin class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

use Dreamax\LicenseManager\CustomerPortal\GuestClaimService;
use Dreamax\LicenseManager\CustomerPortal\GuestClaimPolicy;
use Dreamax\LicenseManager\Support\Capabilities;
use Throwable;

/**
 * Handles Order workflow admin operations.
 */
final class OrderWorkflowAdmin {
	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_dreamax_lm_execute_order_tool', array( $this, 'execute' ) );
		add_action( 'admin_post_dreamax_lm_guest_claim_admin', array( $this, 'claim_admin' ) );
	}

	/**
	 * Handles the menu operation.
	 */
	public function menu(): void {
		add_submenu_page( 'dreamax-license-manager', __( 'Order tools', 'dreamax-license-manager' ), __( 'Order tools', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-order-tools', array( $this, 'page' ) );
	}

	/**
	 * Handles the page operation.
	 */
	public function page(): void {
		$this->authorize();
		$input    = isset( $_POST['order_ids'] ) && is_string( $_POST['order_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['order_ids'] ) ) : '';
		$ids      = array();
		$page_url = admin_url( 'admin.php?page=dreamax-license-manager-order-tools' );
		if ( isset( $_POST['dreamax_lm_preview'] ) ) {
			check_admin_referer( 'dreamax_lm_preview_order_tools' );
			$ids = $this->parse_ids( $input );
		}

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-order-tools-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'WooCommerce operations', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'Order tools', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Preview license coverage, recover missing allocations, and manage verified guest ownership.', 'dreamax-license-manager' ) . '</p></div></header>';
		if ( isset( $_GET['completed'] ) ) {
			$completed = absint( wp_unslash( $_GET['completed'] ) );
			$failed    = absint( wp_unslash( $_GET['failed'] ?? 0 ) );
			$tone      = $failed > 0 ? ( $completed > 0 ? 'notice-warning' : 'notice-error' ) : 'notice-success';
			/* translators: 1: Number of completed operations. 2: Number of failed operations. */
			echo '<div class="notice ' . esc_attr( $tone ) . ' is-dismissible dreamax-lm-notice"><p><strong>' . esc_html__( 'Order operation finished.', 'dreamax-license-manager' ) . '</strong> ' . esc_html( sprintf( __( '%1$d completed; %2$d failed or were rejected.', 'dreamax-license-manager' ), $completed, $failed ) ) . '</p></div>';
		}
		echo '<div class="dreamax-lm-order-tools-layout"><section class="dreamax-lm-panel dreamax-lm-order-preview-card"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Preview paid orders', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Check up to 50 orders before any recovery action is available.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-search" aria-hidden="true"></span></div><form method="post" action="' . esc_url( $page_url ) . '" data-dlm-order-preview><div class="dreamax-lm-order-preview-body"><label class="dreamax-lm-field" for="dreamax-lm-order-ids"><span>' . esc_html__( 'Order IDs', 'dreamax-license-manager' ) . '</span><textarea id="dreamax-lm-order-ids" name="order_ids" rows="5" required data-dlm-order-ids placeholder="' . esc_attr__( 'Example: 1042, 1048, 1051', 'dreamax-license-manager' ) . '">' . esc_textarea( $input ) . '</textarea></label><p class="dreamax-lm-help">' . esc_html__( 'Use WooCommerce order IDs separated by commas, spaces, or new lines.', 'dreamax-license-manager' ) . '</p>';
		wp_nonce_field( 'dreamax_lm_preview_order_tools' );
		echo '<button class="button button-secondary dreamax-lm-order-preview-button" type="submit" name="dreamax_lm_preview" value="1" data-dlm-order-preview-submit disabled><span class="dashicons dashicons-search" aria-hidden="true"></span>' . esc_html__( 'Preview orders', 'dreamax-license-manager' ) . '</button></div></form></section><aside class="dreamax-lm-panel dreamax-lm-order-guidance"><div class="dreamax-lm-guidance-icon"><span class="dashicons dashicons-shield" aria-hidden="true"></span></div><h2>' . esc_html__( 'Preview is read-only', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'No key is revealed, no email is sent, and no order or license changes during preview.', 'dreamax-license-manager' ) . '</p><ul><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span>' . esc_html__( 'Only paid, eligible orders can be recovered.', 'dreamax-license-manager' ) . '</span></li><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span>' . esc_html__( 'Existing and decreased-quantity licenses stay unchanged.', 'dreamax-license-manager' ) . '</span></li><li><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><span>' . esc_html__( 'Every confirmed operation is bound to this preview.', 'dreamax-license-manager' ) . '</span></li></ul></aside></div>';

		if ( array() !== $ids ) {
			$service  = new OrderLicensing();
			$previews = array();
			foreach ( $ids as $order_id ) {
				try {
					$previews[] = $service->preview( $order_id );
				} catch ( Throwable $error ) {
					$previews[] = array(
						'order_id'          => $order_id,
						'status'            => 'not-found',
						'customer_id'       => 0,
						'license_count'     => 0,
						'assigned_count'    => 0,
						'has_billing_email' => false,
						'missing'           => 0,
						'items'             => array(),
						'eligible'          => false,
					);
				}
			}
			$allocation_available = false;
			$resend_available     = false;
			foreach ( $previews as $preview ) {
				$allocation_available = $allocation_available || ( ! empty( $preview['eligible'] ) && (int) ( $preview['missing'] ?? 0 ) > 0 );
				$resend_available     = $resend_available || ( (int) ( $preview['assigned_count'] ?? 0 ) > 0 && ! empty( $preview['has_billing_email'] ) );
			}
			$this->render_preview( $previews );
			echo '<div class="dreamax-lm-order-action-grid">';
			$this->confirmation_form( $ids, 'allocate_missing', __( 'Create only the missing quantity slots shown above. Existing and decreased-quantity licenses remain unchanged.', 'dreamax-license-manager' ), __( 'Confirm allocation / backfill', 'dreamax-license-manager' ), $allocation_available, __( 'Unavailable: no paid, eligible order has a missing license slot.', 'dreamax-license-manager' ) );
			$this->confirmation_form( $ids, 'resend', __( 'Email currently assigned keys to each order\'s current billing email. Suspended and revoked keys are excluded.', 'dreamax-license-manager' ), __( 'Confirm resend', 'dreamax-license-manager' ), $resend_available, __( 'Unavailable: no order has both an assigned license and a valid billing email.', 'dreamax-license-manager' ) );
			echo '</div>';
		}
		echo '<section class="dreamax-lm-panel dreamax-lm-claim-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Guest claim ownership', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Release an order to guest ownership or link it to one verified WordPress customer.', 'dreamax-license-manager' ) . '</p></div><span class="dashicons dashicons-admin-users" aria-hidden="true"></span></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-dlm-confirmed-form data-dlm-claim-form><input type="hidden" name="action" value="dreamax_lm_guest_claim_admin">';
		wp_nonce_field( 'dreamax_lm_guest_claim_admin' );
		echo '<div class="dreamax-lm-claim-body"><div class="dreamax-lm-claim-fields"><label class="dreamax-lm-field"><span>' . esc_html__( 'Order ID', 'dreamax-license-manager' ) . '</span><input type="number" name="order_id" min="1" required placeholder="' . esc_attr__( 'WooCommerce order ID', 'dreamax-license-manager' ) . '"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Ownership action', 'dreamax-license-manager' ) . '</span><select name="claim_operation" data-dlm-claim-operation><option value="release">' . esc_html__( 'Release to guest', 'dreamax-license-manager' ) . '</option><option value="override">' . esc_html__( 'Override account owner', 'dreamax-license-manager' ) . '</option></select></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Target customer ID', 'dreamax-license-manager' ) . ' <span class="dreamax-lm-optional">' . esc_html__( 'For override', 'dreamax-license-manager' ) . '</span></span><input type="number" name="target_customer_id" min="1" data-dlm-claim-target disabled placeholder="' . esc_attr__( 'Verified WordPress user ID', 'dreamax-license-manager' ) . '"></label></div><div class="dreamax-lm-operation-note dreamax-lm-operation-note--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><span>' . esc_html__( 'Both actions invalidate outstanding claim codes, update associated license ownership, and are recorded in the audit trail.', 'dreamax-license-manager' ) . '</span></div><label class="dreamax-lm-confirm"><input type="checkbox" name="confirm_operation" value="1" required data-dlm-confirm><span>' . esc_html__( 'I verified the authoritative ownership and confirm this change.', 'dreamax-license-manager' ) . '</span></label><button class="button button-secondary" type="submit" data-dlm-submit disabled><span class="dashicons dashicons-admin-users" aria-hidden="true"></span>' . esc_html__( 'Apply ownership change', 'dreamax-license-manager' ) . '</button></div></form></section></div>';
	}

	/**
	 * Handles confirmed administrator claim release and override actions.
	 */
	public function claim_admin(): void {
		$this->authorize();
		check_admin_referer( 'dreamax_lm_guest_claim_admin' );
		$confirmed = isset( $_POST['confirm_operation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_operation'] ) ) : '';
		if ( '1' !== $confirmed ) {
			wp_die( esc_html__( 'Explicit confirmation is required.', 'dreamax-license-manager' ) );
		}
		$order_id    = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$operation   = isset( $_POST['claim_operation'] ) ? sanitize_key( wp_unslash( (string) $_POST['claim_operation'] ) ) : '';
		$customer_id = isset( $_POST['target_customer_id'] ) ? absint( wp_unslash( $_POST['target_customer_id'] ) ) : 0;
		$allowed     = ( new GuestClaimPolicy() )->admin_action_allowed( current_user_can( Capabilities::MANAGE ), $operation, $customer_id );
		if ( $order_id < 1 || ! $allowed ) {
			wp_die( esc_html__( 'The ownership operation is invalid.', 'dreamax-license-manager' ) );
		}
		try {
			$claims = new GuestClaimService();
			if ( 'release' === $operation ) {
				$claims->admin_release( $order_id, get_current_user_id() );
			} else {
				$claims->admin_override( $order_id, $customer_id, get_current_user_id() );
			}
			$completed = 1;
			$failed    = 0;
		} catch ( Throwable $error ) {
			$completed = 0;
			$failed    = 1;
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'dreamax-license-manager-order-tools',
					'completed' => $completed,
					'failed'    => $failed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the execute operation.
	 */
	public function execute(): void {
		$this->authorize();
		check_admin_referer( 'dreamax_lm_execute_order_tool' );
		$confirmed = isset( $_POST['confirm_operation'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['confirm_operation'] ) ) : '';
		if ( '1' !== $confirmed ) {
			wp_die( esc_html__( 'Explicit confirmation is required.', 'dreamax-license-manager' ) );
		}
		$operation = isset( $_POST['tool_operation'] ) && is_string( $_POST['tool_operation'] ) ? sanitize_key( wp_unslash( $_POST['tool_operation'] ) ) : '';
		if ( ! in_array( $operation, array( 'allocate_missing', 'resend' ), true ) ) {
			wp_die( esc_html__( 'The order operation is invalid.', 'dreamax-license-manager' ) );
		}
		$ids          = $this->parse_ids( isset( $_POST['order_ids'] ) && is_string( $_POST['order_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['order_ids'] ) ) : '' );
		$operation_id = isset( $_POST['operation_id'] ) && is_string( $_POST['operation_id'] ) ? sanitize_text_field( wp_unslash( $_POST['operation_id'] ) ) : '';
		if ( ! wp_is_uuid( $operation_id, 4 ) ) {
			wp_die( esc_html__( 'The preview identifier is invalid. Preview the orders again.', 'dreamax-license-manager' ) );
		}
		$transient = $this->transient_key( $operation_id );
		$expected  = get_transient( $transient );
		$actual    = hash( 'sha256', $operation . '|' . implode( ',', $ids ) );
		if ( ! is_string( $expected ) || ! hash_equals( $expected, $actual ) ) {
			wp_die( esc_html__( 'The preview expired or does not match this operation. Preview the orders again.', 'dreamax-license-manager' ) );
		}
		delete_transient( $transient );

		$service   = new OrderLicensing();
		$completed = 0;
		$failed    = 0;
		foreach ( $ids as $order_id ) {
			try {
				$request_id = substr( $operation_id, 0, 36 ) . ':' . $order_id;
				if ( 'allocate_missing' === $operation ) {
					$result = $service->allocate_missing( $order_id, true, 'administrator', get_current_user_id(), $request_id );
					if ( $result['failed'] > 0 ) {
						++$failed;
					} else {
						++$completed;
					}
				} else {
					$service->resend( $order_id, $request_id, 'administrator', get_current_user_id() );
					++$completed;
				}
			} catch ( Throwable $error ) {
				++$failed;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'dreamax-license-manager-order-tools',
					'completed' => $completed,
					'failed'    => $failed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handles the render preview operation.
	 *
	 * @param array $previews Previews value.
	 * @phpstan-param list<array<string,mixed>> $previews Previews value.
	 */
	private function render_preview( array $previews ): void {
		$eligible_count = 0;
		$license_count  = 0;
		$missing_count  = 0;
		foreach ( $previews as $preview ) {
			$eligible_count += ! empty( $preview['eligible'] ) ? 1 : 0;
			$license_count  += (int) ( $preview['license_count'] ?? 0 );
			$missing_count  += (int) ( $preview['missing'] ?? 0 );
		}

		echo '<section class="dreamax-lm-summary dreamax-lm-order-summary" aria-label="' . esc_attr__( 'Order preview summary', 'dreamax-license-manager' ) . '"><article class="dreamax-lm-summary-card"><span class="dashicons dashicons-clipboard" aria-hidden="true"></span><div><strong>' . esc_html( (string) count( $previews ) ) . '</strong><span>' . esc_html__( 'Orders reviewed', 'dreamax-license-manager' ) . '</span></div></article><article class="dreamax-lm-summary-card dreamax-lm-summary-card--yes-alt"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><div><strong>' . esc_html( (string) $eligible_count ) . '</strong><span>' . esc_html__( 'Eligible orders', 'dreamax-license-manager' ) . '</span></div></article><article class="dreamax-lm-summary-card dreamax-lm-summary-card--admin-users"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span><div><strong>' . esc_html( (string) $license_count ) . '</strong><span>' . esc_html__( 'Current licenses', 'dreamax-license-manager' ) . '</span></div></article><article class="dreamax-lm-summary-card dreamax-lm-summary-card--warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span><div><strong>' . esc_html( (string) $missing_count ) . '</strong><span>' . esc_html__( 'Missing slots', 'dreamax-license-manager' ) . '</span></div></article></section>';
		echo '<section class="dreamax-lm-panel dreamax-lm-order-results"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Order preview', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Review eligibility and exact recovery impact before confirming an operation.', 'dreamax-license-manager' ) . '</p></div><span class="dreamax-lm-count">' . esc_html( (string) count( $previews ) ) . '</span></div><div class="dreamax-lm-table-scroll"><table class="widefat dreamax-lm-table dreamax-lm-order-preview-table"><thead><tr><th>' . esc_html__( 'Order', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Payment status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Ownership', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Current licenses', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Missing slots', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Expected effect', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $previews as $preview ) {
			if ( ! $preview['eligible'] ) {
				$effect = __( 'Blocked until the order exists and payment is confirmed.', 'dreamax-license-manager' );
			} elseif ( (int) $preview['missing'] > 0 ) {
				/* translators: %d: Maximum number of missing licenses to create. */
				$effect = sprintf( __( 'Will create up to %d missing license(s).', 'dreamax-license-manager' ), (int) $preview['missing'] );
			} elseif ( (int) ( $preview['assigned_count'] ?? 0 ) > 0 && ! empty( $preview['has_billing_email'] ) ) {
				$effect = __( 'No allocation change; assigned licenses are available to resend.', 'dreamax-license-manager' );
			} elseif ( (int) ( $preview['assigned_count'] ?? 0 ) > 0 ) {
				$effect = __( 'No allocation change; resend requires a valid billing email.', 'dreamax-license-manager' );
			} else {
				$effect = __( 'No allocation change; no assigned licenses are available to resend.', 'dreamax-license-manager' );
			}
			$status_label   = ucwords( str_replace( array( '-', '_' ), ' ', (string) $preview['status'] ) );
			$customer       = $preview['customer_id']
				? sprintf( /* translators: %d: WordPress customer ID. */ __( 'Customer #%d', 'dreamax-license-manager' ), (int) $preview['customer_id'] )
				: __( 'Guest order', 'dreamax-license-manager' );
			$ready_class    = $preview['eligible'] ? 'is-ready' : 'is-blocked';
			$ready_label    = $preview['eligible'] ? __( 'Eligible', 'dreamax-license-manager' ) : __( 'Blocked', 'dreamax-license-manager' );
			$assigned_label = sprintf( /* translators: %d: Number of assigned licenses. */ __( '%d assigned', 'dreamax-license-manager' ), (int) ( $preview['assigned_count'] ?? 0 ) );
			echo '<tr><td><strong>#' . esc_html( (string) $preview['order_id'] ) . '</strong><span class="dreamax-lm-cell-meta dreamax-lm-order-readiness ' . esc_attr( $ready_class ) . '">' . esc_html( $ready_label ) . '</span></td><td>' . esc_html( $status_label ) . '</td><td>' . esc_html( $customer ) . '</td><td><strong>' . esc_html( (string) $preview['license_count'] ) . '</strong><span class="dreamax-lm-cell-meta">' . esc_html( $assigned_label ) . '</span></td><td><strong>' . esc_html( (string) $preview['missing'] ) . '</strong></td><td>' . esc_html( $effect ) . '</td></tr>';
			foreach ( $preview['items'] as $item ) {
				/* translators: 1: Order item ID. 2: Product name. */
				$item_label = sprintf( __( 'Item #%1$d: %2$s', 'dreamax-license-manager' ), (int) $item['order_item_id'], (string) $item['product'] );
				/* translators: 1: Quantity. 2: Issuance mode. 3: Existing licenses. 4: Missing licenses. */
				$item_meta = sprintf( __( 'Quantity %1$d · Mode %2$s · Existing %3$d · Missing %4$d', 'dreamax-license-manager' ), (int) $item['quantity'], (string) $item['issuance'], (int) $item['existing'], (int) $item['missing'] );
				echo '<tr class="dreamax-lm-order-item-row"><td></td><td colspan="5"><div class="dreamax-lm-order-item-detail"><strong>' . esc_html( $item_label ) . '</strong><span>' . esc_html( $item_meta ) . '</span></div></td></tr>';
			}
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Handles the confirmation form operation.
	 *
	 * @param array  $ids Ids value.
	 * @phpstan-param list<int> $ids Ids value.
	 * @param string $operation Operation value.
	 * @param string $description Description value.
	 * @param string $button Button value.
	 * @param bool   $available Whether the preview contains an actionable order.
	 * @param string $unavailable_message Explanation shown when the action is unavailable.
	 */
	private function confirmation_form( array $ids, string $operation, string $description, string $button, bool $available, string $unavailable_message ): void {
		$operation_id = $available ? wp_generate_uuid4() : '';
		if ( $available ) {
			set_transient( $this->transient_key( $operation_id ), hash( 'sha256', $operation . '|' . implode( ',', $ids ) ), 15 * MINUTE_IN_SECONDS );
		}
		$is_allocation = 'allocate_missing' === $operation;
		$title         = $is_allocation ? __( 'Recover missing licenses', 'dreamax-license-manager' ) : __( 'Resend assigned keys', 'dreamax-license-manager' );
		$icon          = $is_allocation ? 'plus-alt2' : 'email-alt';
		$state_class   = $available ? '' : ' is-unavailable';
		echo '<form class="dreamax-lm-panel dreamax-lm-order-action-card' . esc_attr( $state_class ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-dlm-confirmed-form aria-disabled="' . esc_attr( $available ? 'false' : 'true' ) . '"><input type="hidden" name="action" value="dreamax_lm_execute_order_tool"><input type="hidden" name="tool_operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="order_ids" value="' . esc_attr( implode( ',', $ids ) ) . '"><input type="hidden" name="operation_id" value="' . esc_attr( $operation_id ) . '"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html( $title ) . '</h2><p>' . esc_html( $description ) . '</p></div><span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span></div><div class="dreamax-lm-order-action-body">';
		wp_nonce_field( 'dreamax_lm_execute_order_tool' );
		if ( ! $available ) {
			echo '<div class="dreamax-lm-operation-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span>' . esc_html( $unavailable_message ) . '</span></div>';
		}
		echo '<label class="dreamax-lm-confirm"><input type="checkbox" name="confirm_operation" value="1" data-dlm-confirm' . ( $available ? ' required' : ' disabled' ) . '><span>' . esc_html__( 'I reviewed the preview and confirm this operation.', 'dreamax-license-manager' ) . '</span></label><button class="button button-primary" type="submit" data-dlm-submit disabled><span class="dashicons dashicons-' . esc_attr( $icon ) . '" aria-hidden="true"></span>' . esc_html( $button ) . '</button></div></form>';
	}

	/**
	 * Handles the parse ids operation.
	 *
	 * @param string $input Input value.
	 * @return list<int>
	 */
	private function parse_ids( string $input ): array {
		$parts = preg_split( '/[\s,]+/', trim( $input ) );
		$parts = is_array( $parts ) ? $parts : array();
		if ( count( $parts ) > 50 ) {
			wp_die( esc_html__( 'A maximum of 50 order IDs can be processed at once.', 'dreamax-license-manager' ) );
		}
		$ids = array();
		foreach ( $parts as $part ) {
			$id = absint( $part );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		if ( array() === $ids ) {
			wp_die( esc_html__( 'Enter at least one valid order ID.', 'dreamax-license-manager' ) );
		}
		return $ids;
	}

	/**
	 * Handles the transient key operation.
	 *
	 * @param string $operation_id Operation id value.
	 */
	private function transient_key( string $operation_id ): string {
		return 'dreamax_lm_preview_' . get_current_user_id() . '_' . $operation_id;
	}

	/**
	 * Handles the authorize operation.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to use order tools.', 'dreamax-license-manager' ), '', array( 'response' => 403 ) );
		}
	}
}
