<?php
/**
 * Defines the GeneratorRepository class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Generators;

use Dreamax\LicenseManager\Support\PublicId;
use RuntimeException;

/**
 * Persists validated license-key generator policies.
 */
final class GeneratorRepository {
	/**
	 * Returns configured generators for administration and product assignment.
	 *
	 * @param int $limit Maximum rows.
	 * @return list<array<string,mixed>>
	 */
	public function all( int $limit = 200 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generator policies are plugin-owned operational configuration.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_generators ORDER BY name ASC,id ASC LIMIT %d", min( 500, max( 1, $limit ) ) ),
			ARRAY_A
		);
		return is_array( $rows ) ? array_map( array( $this, 'hydrate' ), $rows ) : array();
	}

	/**
	 * Finds one generator by its public identifier.
	 *
	 * @param string $public_id Public identifier.
	 * @return array<string,mixed>|null
	 */
	public function by_public_id( string $public_id ): ?array {
		if ( 1 !== preg_match( '/^gen_[A-Za-z0-9_-]{8,60}$/D', $public_id ) ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generator policies are plugin-owned operational configuration.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_generators WHERE public_id=%s LIMIT 1", $public_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Creates or updates one validated generator policy.
	 *
	 * @param string $public_id Existing public identifier, or empty for a new policy.
	 * @param string $name Merchant-facing name.
	 * @param array  $configuration Key pattern configuration.
	 * @phpstan-param array<string,mixed> $configuration Key pattern configuration.
	 * @param ?int   $valid_for_seconds Default validity duration.
	 * @param ?int   $activation_limit Default activation limit.
	 * @throws RuntimeException When validation or persistence fails.
	 * @return string Public identifier.
	 */
	public function save( string $public_id, string $name, array $configuration, ?int $valid_for_seconds, ?int $activation_limit ): string {
		global $wpdb;
		$name = sanitize_text_field( $name );
		if ( '' === $name || strlen( $name ) > 191 ) {
			throw new RuntimeException( 'Enter a generator name no longer than 191 characters.' );
		}
		$configuration = ( new KeyGenerator() )->normalize_configuration( $configuration );
		$encoded       = wp_json_encode( $configuration );
		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( 'The generator configuration could not be encoded.' );
		}
		if ( null !== $valid_for_seconds && $valid_for_seconds < DAY_IN_SECONDS ) {
			throw new RuntimeException( 'Generator validity must be at least one day or unlimited.' );
		}
		if ( null !== $activation_limit && $activation_limit < 0 ) {
			throw new RuntimeException( 'Generator activation limits cannot be negative.' );
		}

		$now     = gmdate( 'Y-m-d H:i:s' );
		$record  = array(
			'name'                     => $name,
			'configuration'            => $encoded,
			'valid_for_seconds'        => $valid_for_seconds,
			'default_activation_limit' => $activation_limit,
			'updated_at'               => $now,
		);
		$formats = array( '%s', '%s', '%d', '%d', '%s' );

		if ( '' === $public_id ) {
			$public_id            = PublicId::generate( 'gen' );
			$record['public_id']  = $public_id;
			$record['created_at'] = $now;
			$formats[]            = '%s';
			$formats[]            = '%s';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Generator policies are plugin-owned operational configuration.
			$result = $wpdb->insert( $wpdb->prefix . 'dreamax_lm_generators', $record, $formats );
		} else {
			if ( null === $this->by_public_id( $public_id ) ) {
				throw new RuntimeException( 'The selected generator could not be found.' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Generator policies are plugin-owned operational configuration.
			$result = $wpdb->update( $wpdb->prefix . 'dreamax_lm_generators', $record, array( 'public_id' => $public_id ), $formats, array( '%s' ) );
		}

		if ( false === $result ) {
			throw new RuntimeException( 'The generator could not be saved.' );
		}
		return $public_id;
	}

	/**
	 * Decodes a persisted generator row into a bounded configuration shape.
	 *
	 * @param array $row Database row.
	 * @phpstan-param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	private function hydrate( array $row ): array {
		$configuration = json_decode( (string) ( $row['configuration'] ?? '' ), true );
		try {
			$row['configuration'] = ( new KeyGenerator() )->normalize_configuration( is_array( $configuration ) ? $configuration : array() );
		} catch ( \Throwable $error ) {
			unset( $error );
			$row['configuration']         = ( new KeyGenerator() )->normalize_configuration( array() );
			$row['configuration_invalid'] = true;
		}
		$row['id']                       = (int) ( $row['id'] ?? 0 );
		$row['valid_for_seconds']        = null === ( $row['valid_for_seconds'] ?? null ) ? null : (int) $row['valid_for_seconds'];
		$row['default_activation_limit'] = null === ( $row['default_activation_limit'] ?? null ) ? null : (int) $row['default_activation_limit'];
		return $row;
	}
}
