<?php
/**
 * Defines the CredentialService class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Credentials;

use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Support\Base64Url;
use Dreamax\LicenseManager\Database\Transaction;
use RuntimeException;

/**
 * Handles Credential service operations.
 */
final class CredentialService {
	/**
	 * Handles the create operation.
	 *
	 * @param string  $name Name value.
	 * @param array   $scopes Scopes value.
	 * @phpstan-param list<string> $scopes Scopes value.
	 * @param ?string $expires_at Expires at value.
	 * @phpstan-param string|null $expires_at Expires at value.
	 * @throws RuntimeException When the operation cannot be completed.
	 * @return array{credential:string,public_id:string}
	 */
	public function create( string $name, array $scopes, ?string $expires_at = null ): array {
		global $wpdb;
		$allowed = array( 'licenses:read', 'licenses:write', 'activations:read', 'generators:read' );
		$scopes  = array_values( array_intersect( array_unique( $scopes ), $allowed ) );
		if ( '' === trim( $name ) || array() === $scopes ) {
			throw new RuntimeException( 'A name and at least one valid scope are required.' );
		}

		$public_id = Base64Url::encode( random_bytes( 16 ) );
		$secret    = Base64Url::encode( random_bytes( 32 ) );
		$hash      = password_hash( $secret, defined( 'PASSWORD_ARGON2ID' ) ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT );
		$now       = gmdate( 'Y-m-d H:i:s' );
		( new Transaction() )->run(
			function () use ( $wpdb, $public_id, $name, $hash, $scopes, $expires_at, $now ): void {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$ok = $wpdb->insert(
					$wpdb->prefix . 'dreamax_lm_api_credentials',
					array(
						'public_id'      => $public_id,
						'name'           => sanitize_text_field( $name ),
						'visible_prefix' => substr( $public_id, 0, 8 ),
						'secret_hash'    => $hash,
						'scopes'         => wp_json_encode( $scopes ),
						'status'         => 'active',
						'expires_at'     => $expires_at,
						'created_at'     => $now,
						'updated_at'     => $now,
					),
					array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				if ( false === $ok ) {
					throw new RuntimeException( 'The credential could not be stored.' );
				}
				( new EventRepository() )->append(
					'credential_created',
					null,
					'administrator',
					get_current_user_id(),
					null,
					array(
						'credential_public_id' => $public_id,
						'scopes'               => $scopes,
					)
				);
			}
		);
		return array(
			'credential' => 'dlm_v1_' . $public_id . '.' . $secret,
			'public_id'  => $public_id,
		);
	}

	/**
	 * Handles the authenticate operation.
	 *
	 * @param string $header Header value.
	 * @param string $required_scope Required scope value.
	 * @return array<string,mixed>|null
	 */
	public function authenticate( string $header, string $required_scope ): ?array {
		global $wpdb;
		if ( strlen( $header ) > 256 || false !== strpos( $header, ',' ) || ! preg_match( '/^Bearer dlm_v1_([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{43})$/D', $header, $matches ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id = %s LIMIT 1", $matches[1] ), ARRAY_A );
		if ( ! is_array( $row ) || 'active' !== $row['status'] || ( $row['expires_at'] && strtotime( (string) $row['expires_at'] . ' UTC' ) <= time() ) ) {
			password_verify( $matches[2], '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi' );
			return null;
		}
		if ( ! password_verify( $matches[2], (string) $row['secret_hash'] ) ) {
			return null;
		}
		$scopes = json_decode( (string) $row['scopes'], true );
		if ( ! is_array( $scopes ) || ( '' !== $required_scope && ! in_array( $required_scope, $scopes, true ) ) ) {
			return null;
		}
		if ( empty( $row['last_used_at'] ) || strtotime( (string) $row['last_used_at'] . ' UTC' ) < time() - 300 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
			$wpdb->update( $wpdb->prefix . 'dreamax_lm_api_credentials', array( 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => (int) $row['id'] ), array( '%s' ), array( '%d' ) );
		}
		return $row;
	}

	/**
	 * Handles the has scope operation.
	 *
	 * @param array  $credential Credential value.
	 * @phpstan-param array<string,mixed> $credential Credential value.
	 * @param string $scope Scope value.
	 */
	public function has_scope( array $credential, string $scope ): bool {
		$scopes = json_decode( (string) ( $credential['scopes'] ?? '[]' ), true );
		return is_array( $scopes ) && in_array( $scope, $scopes, true );
	}
}
