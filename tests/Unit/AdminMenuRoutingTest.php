<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			unset( $domain );
			return $text;
		}
	}

	if ( ! function_exists( 'add_action' ) ) {
		function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool {
			$GLOBALS['dreamax_lm_menu_test_actions'][] = array(
				'hook'          => $hook,
				'callback'      => $callback,
				'priority'      => $priority,
				'accepted_args' => $accepted_args,
				'sequence'      => count( $GLOBALS['dreamax_lm_menu_test_actions'] ),
			);
			return true;
		}
	}

	if ( ! function_exists( 'dreamax_lm_menu_test_hook' ) ) {
		function dreamax_lm_menu_test_hook( string $menu_slug, ?string $parent_slug ): string {
			$page_type = null !== $parent_slug && isset( $GLOBALS['dreamax_lm_menu_test_parents'][ $parent_slug ] )
				? $GLOBALS['dreamax_lm_menu_test_parents'][ $parent_slug ]
				: 'admin';

			return $page_type . '_page_' . $menu_slug;
		}
	}

	if ( ! function_exists( 'add_menu_page' ) ) {
		function add_menu_page( string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback, string $icon_url = '', ?int $position = null ): string {
			unset( $page_title, $capability, $callback, $icon_url, $position );
			$GLOBALS['dreamax_lm_menu_test_parents'][ $menu_slug ] = strtolower( str_replace( ' ', '-', $menu_title ) );
			$hook = dreamax_lm_menu_test_hook( $menu_slug, null );
			$GLOBALS['dreamax_lm_menu_test_registered_hooks'][ $hook ] = true;
			return $hook;
		}
	}

	if ( ! function_exists( 'add_submenu_page' ) ) {
		function add_submenu_page( ?string $parent_slug, string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback, ?int $position = null ): string {
			unset( $page_title, $menu_title, $position );
			$hook = dreamax_lm_menu_test_hook( $menu_slug, $parent_slug );
			$GLOBALS['dreamax_lm_menu_test_registered_hooks'][ $hook ] = true;
			$GLOBALS['dreamax_lm_menu_test_submenus'][ $menu_slug ] = array(
				'parent'     => $parent_slug,
				'capability' => $capability,
				'callback'   => $callback,
				'hook'       => $hook,
			);
			return $hook;
		}
	}
}

namespace Dreamax\LicenseManager\Tests\Unit {
	use Dreamax\LicenseManager\Admin\Admin;
	use Dreamax\LicenseManager\Integrations\WooCommerce\OrderWorkflowAdmin;
	use Dreamax\LicenseManager\Support\Capabilities;
	use PHPUnit\Framework\TestCase;

	final class AdminMenuRoutingTest extends TestCase {
		private const PARENT_SLUG = 'dreamax-license-manager';

		protected function setUp(): void {
			$GLOBALS['dreamax_lm_menu_test_actions']          = array();
			$GLOBALS['dreamax_lm_menu_test_parents']          = array();
			$GLOBALS['dreamax_lm_menu_test_registered_hooks'] = array();
			$GLOBALS['dreamax_lm_menu_test_submenus']         = array();
		}

		public function test_every_visible_submenu_resolves_through_admin_php(): void {
			$this->register_production_menu_callbacks();
			$this->run_admin_menu_callbacks();

			foreach ( array_keys( $this->expected_submenus() ) as $slug ) {
				self::assertSame( 'admin.php?page=' . $slug, $this->rendered_url( $slug ), $slug );
			}
		}

		public function test_every_visible_submenu_keeps_its_registered_slug_and_capability(): void {
			$this->register_production_menu_callbacks();
			$this->run_admin_menu_callbacks();

			$visible = array_filter(
				$GLOBALS['dreamax_lm_menu_test_submenus'],
				static fn(array $page): bool => self::PARENT_SLUG === $page['parent']
			);
			self::assertSame( array_keys( $this->expected_submenus() ), array_keys( $visible ) );

			foreach ( $this->expected_submenus() as $slug => $capability ) {
				self::assertSame( $capability, $visible[ $slug ]['capability'], $slug );
				self::assertIsCallable( $visible[ $slug ]['callback'], $slug );
			}
		}

		private function register_production_menu_callbacks(): void {
			( new OrderWorkflowAdmin() )->register();
			( new Admin() )->register();
		}

		private function run_admin_menu_callbacks(): void {
			$callbacks = array_values(
				array_filter(
					$GLOBALS['dreamax_lm_menu_test_actions'],
					static fn(array $action): bool => 'admin_menu' === $action['hook']
				)
			);
			usort(
				$callbacks,
				static fn(array $left, array $right): int => array( $left['priority'], $left['sequence'] ) <=> array( $right['priority'], $right['sequence'] )
			);

			foreach ( $callbacks as $action ) {
				call_user_func( $action['callback'] );
			}
		}

		private function rendered_url( string $slug ): string {
			$hook = dreamax_lm_menu_test_hook( $slug, self::PARENT_SLUG );
			return isset( $GLOBALS['dreamax_lm_menu_test_registered_hooks'][ $hook ] )
				? 'admin.php?page=' . $slug
				: $slug;
		}

		/**
		 * @return array<string, string>
		 */
		private function expected_submenus(): array {
			return array(
				'dreamax-license-manager-add'         => Capabilities::MANAGE,
				'dreamax-license-manager-transfer'    => Capabilities::MANAGE,
				'dreamax-license-manager-activity'    => Capabilities::MANAGE,
				'dreamax-license-manager-credentials' => Capabilities::CREDENTIALS,
				'dreamax-license-manager-status'      => Capabilities::DIAGNOSTICS,
				'dreamax-license-manager-order-tools' => Capabilities::MANAGE,
			);
		}
	}
}
