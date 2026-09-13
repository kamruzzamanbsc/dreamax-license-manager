<?php
/**
 * Defines the GeneratorAdmin class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Admin;

use Dreamax\LicenseManager\Generators\GeneratorRepository;
use Dreamax\LicenseManager\Generators\KeyGenerator;
use Dreamax\LicenseManager\Support\Capabilities;
use Throwable;

/**
 * Provides generator inventory and editing workflows.
 */
final class GeneratorAdmin {
	/**
	 * Registers administration hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 10 );
		add_action( 'admin_post_dreamax_lm_save_generator', array( $this, 'save' ) );
	}

	/**
	 * Registers the generator screen below the parent plugin menu.
	 */
	public function menu(): void {
		add_submenu_page( 'dreamax-license-manager', __( 'License generators', 'dreamax-license-manager' ), __( 'Generators', 'dreamax-license-manager' ), Capabilities::MANAGE, 'dreamax-license-manager-generators', array( $this, 'page' ) );
	}

	/**
	 * Renders generator inventory and the create/edit form.
	 */
	public function page(): void {
		$this->authorize();
		$repository = new GeneratorRepository();
		$rows       = $repository->all();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only generator selection and post-action notice behind a capability check.
		$selected_id = isset( $_GET['generator'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['generator'] ) ) : '';
		$saved       = isset( $_GET['saved'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$selected         = '' !== $selected_id ? $repository->by_public_id( $selected_id ) : null;
		$config           = is_array( $selected['configuration'] ?? null ) ? $selected['configuration'] : ( new KeyGenerator() )->normalize_configuration( array() );
		$validity_seconds = is_array( $selected ) && array_key_exists( 'valid_for_seconds', $selected ) ? $selected['valid_for_seconds'] : null;
		$default_limit    = is_array( $selected ) && array_key_exists( 'default_activation_limit', $selected ) ? $selected['default_activation_limit'] : null;
		$validity         = null === $validity_seconds ? '' : max( 1, (int) round( (int) $validity_seconds / DAY_IN_SECONDS ) );
		$limit            = null === $default_limit ? '' : (string) (int) $default_limit;

		echo '<div class="wrap dreamax-lm-admin dreamax-lm-generators-page"><header class="dreamax-lm-page-header"><div><p class="dreamax-lm-eyebrow">' . esc_html__( 'Key policy', 'dreamax-license-manager' ) . '</p><h1>' . esc_html__( 'License generators', 'dreamax-license-manager' ) . '</h1><p class="dreamax-lm-page-intro">' . esc_html__( 'Create strong reusable key patterns, then assign them to WooCommerce products or variations.', 'dreamax-license-manager' ) . '</p></div></header>';
		if ( $saved ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Generator saved.', 'dreamax-license-manager' ) . '</p></div>';
		}
		echo '<div class="dreamax-lm-generator-layout"><section class="dreamax-lm-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html__( 'Configured generators', 'dreamax-license-manager' ) . '</h2><p>' . esc_html__( 'Generator public IDs remain stable after edits.', 'dreamax-license-manager' ) . '</p></div><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dreamax-license-manager-generators' ) ) . '">' . esc_html__( 'New generator', 'dreamax-license-manager' ) . '</a></div>';
		echo '<div class="dreamax-lm-table-scroll"><table class="widefat striped dreamax-lm-table"><thead><tr><th>' . esc_html__( 'Name', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Pattern', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Entropy', 'dreamax-license-manager' ) . '</th><th>' . esc_html__( 'Defaults', 'dreamax-license-manager' ) . '</th><th><span class="screen-reader-text">' . esc_html__( 'Actions', 'dreamax-license-manager' ) . '</span></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$row_config = is_array( $row['configuration'] ?? null ) ? $row['configuration'] : array();
			$entropy    = ( new KeyGenerator() )->entropy_bits( (string) ( $row_config['alphabet'] ?? KeyGenerator::DEFAULT_ALPHABET ), (int) ( $row_config['length'] ?? KeyGenerator::DEFAULT_LENGTH ) );
			$defaults   = array();
			/* translators: %d: default concurrent activation limit. */
			$defaults[] = null === $row['default_activation_limit'] ? __( 'Unlimited activations', 'dreamax-license-manager' ) : sprintf( __( '%d activation(s)', 'dreamax-license-manager' ), (int) $row['default_activation_limit'] );
			/* translators: %d: default license validity in days. */
			$defaults[] = null === $row['valid_for_seconds'] ? __( 'Never expires', 'dreamax-license-manager' ) : sprintf( __( '%d day validity', 'dreamax-license-manager' ), max( 1, (int) round( (int) $row['valid_for_seconds'] / DAY_IN_SECONDS ) ) );
			$edit_url   = add_query_arg(
				array(
					'page'      => 'dreamax-license-manager-generators',
					'generator' => (string) $row['public_id'],
				),
				admin_url( 'admin.php' )
			);
			echo '<tr><td><strong>' . esc_html( (string) $row['name'] ) . '</strong><br><code>' . esc_html( (string) $row['public_id'] ) . '</code></td><td><code>' . esc_html( $this->pattern_label( $row_config ) ) . '</code></td><td>' . esc_html( number_format_i18n( $entropy, 1 ) ) . ' ' . esc_html__( 'bits', 'dreamax-license-manager' ) . '</td><td>' . esc_html( implode( ' | ', $defaults ) ) . '</td><td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'dreamax-license-manager' ) . '</a></td></tr>';
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="5" class="dreamax-lm-empty dreamax-lm-empty--compact"><strong>' . esc_html__( 'No generators configured', 'dreamax-license-manager' ) . '</strong><p>' . esc_html__( 'Create the first generator to assign a reusable secure key policy.', 'dreamax-license-manager' ) . '</p></td></tr>';
		}
		echo '</tbody></table></div></section>';

		echo '<section class="dreamax-lm-panel"><div class="dreamax-lm-panel-heading"><div><h2>' . esc_html( is_array( $selected ) ? __( 'Edit generator', 'dreamax-license-manager' ) : __( 'Create generator', 'dreamax-license-manager' ) ) . '</h2><p>' . esc_html__( 'Random positions must provide at least 96 bits of entropy.', 'dreamax-license-manager' ) . '</p></div></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dreamax-lm-generator-form"><input type="hidden" name="action" value="dreamax_lm_save_generator"><input type="hidden" name="generator_public_id" value="' . esc_attr( is_array( $selected ) ? (string) $selected['public_id'] : '' ) . '">';
		wp_nonce_field( 'dreamax_lm_save_generator' );
		echo '<div class="dreamax-lm-generator-fields"><label class="dreamax-lm-field dreamax-lm-field--wide"><span>' . esc_html__( 'Generator name', 'dreamax-license-manager' ) . '</span><input type="text" name="generator_name" maxlength="191" required value="' . esc_attr( is_array( $selected ) ? (string) $selected['name'] : '' ) . '"></label>';
		echo '<label class="dreamax-lm-field"><span>' . esc_html__( 'Prefix', 'dreamax-license-manager' ) . '</span><input type="text" name="prefix" maxlength="32" value="' . esc_attr( (string) $config['prefix'] ) . '"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Suffix', 'dreamax-license-manager' ) . '</span><input type="text" name="suffix" maxlength="32" value="' . esc_attr( (string) $config['suffix'] ) . '"></label>';
		echo '<label class="dreamax-lm-field dreamax-lm-field--wide"><span>' . esc_html__( 'Character set', 'dreamax-license-manager' ) . '</span><input type="text" name="alphabet" maxlength="64" required value="' . esc_attr( (string) $config['alphabet'] ) . '"></label>';
		echo '<label class="dreamax-lm-field"><span>' . esc_html__( 'Random length', 'dreamax-license-manager' ) . '</span><input type="number" name="length" min="1" max="128" required value="' . esc_attr( (string) $config['length'] ) . '"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Group length', 'dreamax-license-manager' ) . '</span><input type="number" name="group" min="0" max="128" required value="' . esc_attr( (string) $config['group'] ) . '"></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Separator', 'dreamax-license-manager' ) . '</span><input type="text" name="separator" minlength="1" maxlength="1" required value="' . esc_attr( (string) $config['separator'] ) . '"></label>';
		echo '<label class="dreamax-lm-field"><span>' . esc_html__( 'Default activation limit', 'dreamax-license-manager' ) . '</span><input type="number" name="activation_limit" min="0" value="' . esc_attr( $limit ) . '"><small>' . esc_html__( 'Leave blank for unlimited.', 'dreamax-license-manager' ) . '</small></label><label class="dreamax-lm-field"><span>' . esc_html__( 'Default validity in days', 'dreamax-license-manager' ) . '</span><input type="number" name="valid_days" min="1" value="' . esc_attr( (string) $validity ) . '"><small>' . esc_html__( 'Leave blank for no expiry.', 'dreamax-license-manager' ) . '</small></label></div>';
		echo '<div class="dreamax-lm-panel-footer"><span><strong>' . esc_html__( 'Pattern preview:', 'dreamax-license-manager' ) . '</strong> <code>' . esc_html( $this->pattern_label( $config ) ) . '</code></span><button class="button button-primary" type="submit">' . esc_html__( 'Save generator', 'dreamax-license-manager' ) . '</button></div></form></section></div></div>';
	}

	/**
	 * Saves a validated generator configuration.
	 */
	public function save(): void {
		$this->authorize();
		check_admin_referer( 'dreamax_lm_save_generator' );
		$public_id = isset( $_POST['generator_public_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['generator_public_id'] ) ) : '';
		$name      = isset( $_POST['generator_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['generator_name'] ) ) : '';
		$config    = array(
			'alphabet'  => isset( $_POST['alphabet'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['alphabet'] ) ) : '',
			'length'    => isset( $_POST['length'] ) ? (int) $_POST['length'] : 0,
			'group'     => isset( $_POST['group'] ) ? (int) $_POST['group'] : 0,
			'separator' => isset( $_POST['separator'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['separator'] ) ) : '',
			'prefix'    => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['prefix'] ) ) : '',
			'suffix'    => isset( $_POST['suffix'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['suffix'] ) ) : '',
		);
		$days      = isset( $_POST['valid_days'] ) && '' !== $_POST['valid_days'] ? max( 1, (int) $_POST['valid_days'] ) : null;
		$limit     = isset( $_POST['activation_limit'] ) && '' !== $_POST['activation_limit'] ? max( 0, (int) $_POST['activation_limit'] ) : null;
		try {
			$public_id = ( new GeneratorRepository() )->save( $public_id, $name, $config, null === $days ? null : $days * DAY_IN_SECONDS, $limit );
		} catch ( Throwable $error ) {
			wp_die( esc_html( $error->getMessage() ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => 'dreamax-license-manager-generators',
					'generator' => $public_id,
					'saved'     => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Produces a non-secret visual representation of a generator pattern.
	 *
	 * @param array $config Generator configuration.
	 * @phpstan-param array<string,mixed> $config Generator configuration.
	 */
	private function pattern_label( array $config ): string {
		$length    = max( 1, (int) ( $config['length'] ?? KeyGenerator::DEFAULT_LENGTH ) );
		$group     = max( 0, (int) ( $config['group'] ?? 5 ) );
		$separator = (string) ( $config['separator'] ?? '-' );
		$body      = $group > 0 ? implode( $separator, str_split( str_repeat( 'X', $length ), $group ) ) : str_repeat( 'X', $length );
		return implode( $separator, array_filter( array( (string) ( $config['prefix'] ?? '' ), $body, (string) ( $config['suffix'] ?? '' ) ), static fn( string $part ): bool => '' !== $part ) );
	}

	/**
	 * Enforces ordinary license-management access.
	 */
	private function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to manage license generators.', 'dreamax-license-manager' ), 403 );
		}
	}
}
