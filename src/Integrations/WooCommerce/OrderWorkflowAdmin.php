<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Integrations\WooCommerce;

use Dreamax\LicenseManager\Support\Capabilities;
use Throwable;

final class OrderWorkflowAdmin {
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_dreamax_lm_execute_order_tool', array( $this, 'execute' ) );
	}

	public function menu(): void {
		add_submenu_page( 'dreamax-license-manager', __( 'Order tools', 'dreamax-license-manager' ), __( 'Order tools', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-order-tools', array( $this, 'page' ) );
	}

	public function page(): void {
		$this->authorize();
		$input = isset( $_POST['order_ids'] ) && is_string( $_POST['order_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['order_ids'] ) ) : '';
		$ids   = array();
		if ( isset( $_POST['dreamax_lm_preview'] ) ) {
			check_admin_referer( 'dreamax_lm_preview_order_tools' );
			$ids = $this->parse_ids( $input );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Dreamax order tools', 'dreamax-license-manager' ) . '</h1><p>' . esc_html__( 'Preview up to 50 paid orders before creating missing licenses or resending assigned keys. Preview never reveals a key and never changes an order.', 'dreamax-license-manager' ) . '</p>';
		if ( isset( $_GET['completed'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html( sprintf( __( '%1$d order operation(s) completed; %2$d failed or were rejected.', 'dreamax-license-manager' ), max( 0, (int) $_GET['completed'] ), max( 0, (int) ( $_GET['failed'] ?? 0 ) ) ) ) . '</p></div>';
		}
		echo '<form method="post"><textarea name="order_ids" rows="4" class="large-text" required placeholder="' . esc_attr__( 'Order IDs separated by commas or new lines', 'dreamax-license-manager' ) . '">' . esc_textarea( $input ) . '</textarea>';
		wp_nonce_field( 'dreamax_lm_preview_order_tools' );
		submit_button( __( 'Preview orders', 'dreamax-license-manager' ), 'secondary', 'dreamax_lm_preview', false );
		echo '</form>';

		if ( array() !== $ids ) {
			$service  = new OrderLicensing();
			$previews = array();
			foreach ( $ids as $order_id ) {
				try {
					$previews[] = $service->preview( $order_id );
				} catch ( Throwable $error ) {
					$previews[] = array( 'order_id' => $order_id, 'status' => 'not-found', 'customer_id' => 0, 'license_count' => 0, 'missing' => 0, 'items' => array(), 'eligible' => false );
				}
			}
			$this->render_preview( $previews );
			$this->confirmation_form( $ids, 'allocate_missing', __( 'Create only the missing quantity slots shown above. Existing and decreased-quantity licenses remain unchanged.', 'dreamax-license-manager' ), __( 'Confirm allocation / backfill', 'dreamax-license-manager' ) );
			$this->confirmation_form( $ids, 'resend', __( 'Email currently assigned keys to each order’s current billing email. Suspended and revoked keys are excluded.', 'dreamax-license-manager' ), __( 'Confirm resend', 'dreamax-license-manager' ) );
		}
		echo '</div>';
	}

	public function execute(): void {
		$this->authorize();
		check_admin_referer( 'dreamax_lm_execute_order_tool' );
		if ( ! isset( $_POST['confirm_operation'] ) || '1' !== (string) $_POST['confirm_operation'] ) {
			wp_die( esc_html__( 'Explicit confirmation is required.', 'dreamax-license-manager' ) );
		}
		$operation = isset( $_POST['tool_operation'] ) && is_string( $_POST['tool_operation'] ) ? sanitize_key( wp_unslash( $_POST['tool_operation'] ) ) : '';
		if ( ! in_array( $operation, array( 'allocate_missing', 'resend' ), true ) ) {
			wp_die( esc_html__( 'The order operation is invalid.', 'dreamax-license-manager' ) );
		}
		$ids = $this->parse_ids( isset( $_POST['order_ids'] ) && is_string( $_POST['order_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['order_ids'] ) ) : '' );
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

		wp_safe_redirect( add_query_arg( array( 'page' => 'dreamax-license-manager-order-tools', 'completed' => $completed, 'failed' => $failed ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @param list<array<string,mixed>> $previews */
	private function render_preview( array $previews ): void {
		echo '<h2>' . esc_html__( 'Preview', 'dreamax-license-manager' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Order', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Status', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Customer', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Current licenses', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Missing slots', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Effect', 'dreamax-license-manager' ) . '</th></tr></thead><tbody>';
		foreach ( $previews as $preview ) {
			$effect = $preview['eligible']
				? ( (int) $preview['missing'] > 0 ? sprintf( __( 'Will create up to %d missing license(s).', 'dreamax-license-manager' ), (int) $preview['missing'] ) : __( 'No allocation change; resend remains available when assigned keys exist.', 'dreamax-license-manager' ) )
				: __( 'Blocked until the order exists and payment is confirmed.', 'dreamax-license-manager' );
			echo '<tr><td>#' . esc_html( (string) $preview['order_id'] ) . '</td><td>' . esc_html( (string) $preview['status'] ) . '</td><td>' . esc_html( (string) ( $preview['customer_id'] ?: __( 'Guest', 'dreamax-license-manager' ) ) ) . '</td><td>' . esc_html( (string) $preview['license_count'] ) . '</td><td>' . esc_html( (string) $preview['missing'] ) . '</td><td>' . esc_html( $effect ) . '</td></tr>';
			foreach ( $preview['items'] as $item ) {
				echo '<tr><td></td><td colspan="5">' . esc_html( sprintf( __( 'Item #%1$d: %2$s — quantity %3$d, mode %4$s, existing %5$d, missing %6$d', 'dreamax-license-manager' ), (int) $item['order_item_id'], (string) $item['product'], (int) $item['quantity'], (string) $item['issuance'], (int) $item['existing'], (int) $item['missing'] ) ) . '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}

	/** @param list<int> $ids */
	private function confirmation_form( array $ids, string $operation, string $description, string $button ): void {
		$operation_id = wp_generate_uuid4();
		set_transient( $this->transient_key( $operation_id ), hash( 'sha256', $operation . '|' . implode( ',', $ids ) ), 15 * MINUTE_IN_SECONDS );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em"><input type="hidden" name="action" value="dreamax_lm_execute_order_tool"><input type="hidden" name="tool_operation" value="' . esc_attr( $operation ) . '"><input type="hidden" name="order_ids" value="' . esc_attr( implode( ',', $ids ) ) . '"><input type="hidden" name="operation_id" value="' . esc_attr( $operation_id ) . '">';
		wp_nonce_field( 'dreamax_lm_execute_order_tool' );
		echo '<p>' . esc_html( $description ) . '</p><label><input type="checkbox" name="confirm_operation" value="1" required> ' . esc_html__( 'I reviewed the preview and confirm this operation.', 'dreamax-license-manager' ) . '</label> ';
		submit_button( $button, 'primary', 'submit', false );
		echo '</form>';
	}

	/** @return list<int> */
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

	private function transient_key( string $operation_id ): string {
		return 'dreamax_lm_preview_' . get_current_user_id() . '_' . $operation_id;
	}

	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to use order tools.', 'dreamax-license-manager' ), '', array( 'response' => 403 ) );
		}
	}
}
