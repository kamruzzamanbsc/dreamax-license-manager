<?php
/**
 * Defines the CredentialService class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Credentials;

use Dreamax\LicenseManager\Api\RateLimiter;
use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\LicenseException;
use RuntimeException;
use Throwable;

/**
 * Manages the complete site-local privileged credential lifecycle.
 */
final class CredentialService {
	/**
	 * Token codec and verifier.
	 *
	 * @var CredentialToken
	 */
	private CredentialToken $tokens;

	/**
	 * Lifecycle policy.
	 *
	 * @var CredentialPolicy
	 */
	private CredentialPolicy $policy;

	/**
	 * Transaction runner.
	 *
	 * @var Transaction
	 */
	private Transaction $transaction;

	/**
	 * Audit repository.
	 *
	 * @var EventRepository
	 */
	private EventRepository $events;

	/**
	 * Per-credential limiter.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limits;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->tokens      = new CredentialToken();
		$this->policy      = new CredentialPolicy();
		$this->transaction = new Transaction();
		$this->events      = new EventRepository();
		$this->limits      = new RateLimiter();
	}

	/**
	 * Creates an independently scoped credential and returns its plaintext once.
	 *
	 * @param string      $name Human-readable name.
	 * @param array       $scopes Exact scopes.
	 * @phpstan-param list<string> $scopes
	 * @param string|null $expires_at Optional future UTC expiration.
	 * @param int|null    $actor_id Administrator user ID.
	 * @return array{credential:string,public_id:string,secret_version:int}
	 * @throws Throwable When creation cannot be completed atomically.
	 */
	public function create( string $name, array $scopes, ?string $expires_at = null, ?int $actor_id = null ): array {
		global $wpdb;
		$name       = $this->name( $name );
		$scopes     = $this->policy->scopes( $scopes );
		$expires_at = $this->policy->expiration( $expires_at );
		$generated  = $this->tokens->generate();
		$public_id  = $generated['public_id'];
		$secret     = $generated['secret'];
		$hash       = $this->tokens->hash( $secret );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$actor_id   = $actor_id ?? get_current_user_id();

		try {
			$this->transaction->run(
				function () use ( $wpdb, $public_id, $name, $hash, $scopes, $expires_at, $now, $actor_id ): void {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Credential creation and its audit record are one transaction.
					$stored = $wpdb->insert(
						$wpdb->prefix . 'dreamax_lm_api_credentials',
						array(
							'public_id'      => $public_id,
							'name'           => $name,
							'visible_prefix' => substr( $public_id, 0, 8 ),
							'secret_hash'    => $hash,
							'secret_version' => 1,
							'scopes'         => wp_json_encode( $scopes ),
							'status'         => 'active',
							'expires_at'     => $expires_at,
							'created_at'     => $now,
							'updated_at'     => $now,
						),
						array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
					);
					if ( false === $stored ) {
						throw new RuntimeException( 'The credential could not be stored.' );
					}
					$this->events->append(
						AuditEventCatalog::CREDENTIAL_CREATED,
						null,
						'administrator',
						$actor_id,
						null,
						array(
							'credential_public_id' => $public_id,
							'scopes'               => $scopes,
							'expires'              => null !== $expires_at,
						),
						AuditEventCatalog::SCHEMA_V1
					);
				}
			);
		} catch ( Throwable $error ) {
			sodium_memzero( $secret );
			throw $error;
		}

		return array(
			'credential'     => $generated['credential'],
			'public_id'      => $public_id,
			'secret_version' => 1,
		);
	}

	/**
	 * Authenticates one exact Bearer value with a uniform failure envelope.
	 *
	 * @param string      $header Authorization header.
	 * @param string|null $request_id Opaque request correlation ID.
	 * @return array<string,mixed>|null
	 */
	public function authenticate( string $header, ?string $request_id = null ): ?array {
		global $wpdb;
		$parsed = $this->tokens->parse( $header );
		if ( ! is_array( $parsed ) ) {
			$this->tokens->dummy_verify( str_repeat( 'A', CredentialToken::SECRET_LENGTH ) );
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Authentication requires a fresh site-local credential record.
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id=%s LIMIT 1", $parsed['public_id'] ), ARRAY_A );
		$verified = is_array( $row )
			? $this->tokens->verify( $parsed['secret'], (string) $row['secret_hash'] )
			: $this->dummy_verification( $parsed['secret'] );
		$failure  = $this->policy->authentication_failure( is_array( $row ) ? $row : null, $verified, time() );
		sodium_memzero( $parsed['secret'] );

		if ( null !== $failure ) {
			if ( 'expired' === $failure && is_array( $row ) ) {
				$this->events->append(
					AuditEventCatalog::CREDENTIAL_EXPIRED_AUTHENTICATION_FAILED,
					null,
					'api_credential',
					(int) $row['id'],
					$request_id,
					array( 'credential_public_id' => (string) $row['public_id'] ),
					AuditEventCatalog::SCHEMA_V1
				);
			}
			return null;
		}

		return $this->safe_row( $row );
	}

	/**
	 * Rechecks authorization under a site-local usage lock and runs business work.
	 *
	 * @param array    $credential Previously authenticated safe record.
	 * @phpstan-param array<string,mixed> $credential
	 * @param string   $required_scope Exact required scope.
	 * @param string   $request_id Opaque request correlation ID.
	 * @param callable $callback Privileged business callback.
	 * @phpstan-param callable():T $callback
	 * @template T
	 * @return T
	 * @throws Throwable When authorization or business processing fails.
	 */
	public function authorized_use( array $credential, string $required_scope, string $request_id, callable $callback ) {
		$public_id             = (string) ( $credential['public_id'] ?? '' );
		$authenticated_version = (int) ( $credential['secret_version'] ?? 0 );
		return $this->with_lock(
			$public_id,
			function () use ( $public_id, $authenticated_version, $required_scope, $request_id, $callback ) {
				$row = $this->row_by_public_id( $public_id, false );
				if ( ! is_array( $row ) || ! $this->policy->usable( $row, $authenticated_version, time() ) ) {
					if ( is_array( $row ) && $this->policy->expired( $row['expires_at'] ?? null, time() ) ) {
						$this->events->append( AuditEventCatalog::CREDENTIAL_EXPIRED_AUTHENTICATION_FAILED, null, 'api_credential', (int) $row['id'], $request_id, array( 'credential_public_id' => $public_id ), AuditEventCatalog::SCHEMA_V1 );
					}
					$this->throw_authentication_failure();
				}

				$this->limits->consume( 'privileged-credential|' . get_current_blog_id() . '|' . $public_id, CredentialPolicy::RATE_CAPACITY, CredentialPolicy::RATE_REFILL );
				$safe = $this->safe_row( $row );
				if ( ! $this->has_scope( $safe, $required_scope ) ) {
					$this->events->append(
						AuditEventCatalog::CREDENTIAL_INSUFFICIENT_SCOPE,
						null,
						'api_credential',
						(int) $row['id'],
						$request_id,
						array(
							'credential_public_id' => $public_id,
							'required_scope'       => $required_scope,
						),
						AuditEventCatalog::SCHEMA_V1
					);
					throw new LicenseException( 'insufficient_scope', 'The credential does not have the required scope.', 403 );
				}

				$this->touch_last_used( $row );
				$this->events->append(
					AuditEventCatalog::CREDENTIAL_AUTHENTICATION_USED,
					null,
					'api_credential',
					(int) $row['id'],
					$request_id,
					array(
						'credential_public_id' => $public_id,
						'required_scope'       => $required_scope,
					),
					AuditEventCatalog::SCHEMA_V1
				);
				return $callback();
			}
		);
	}

	/**
	 * Atomically replaces a secret with zero overlap.
	 *
	 * @param string   $public_id Credential public ID.
	 * @param int      $expected_version Version rendered to the authorized actor.
	 * @param array    $changes Optional explicit name/scopes/expiration changes.
	 * @phpstan-param array<string,mixed> $changes
	 * @param int|null $actor_id Administrator user ID.
	 * @return array{credential:string,public_id:string,secret_version:int}
	 * @throws RuntimeException When rotation cannot be completed.
	 */
	public function rotate( string $public_id, int $expected_version, array $changes = array(), ?int $actor_id = null ): array {
		$secret      = '';
		$new_version = 0;
		$actor_id    = $actor_id ?? get_current_user_id();
		$public_id   = $this->public_id( $public_id );
		try {
			$normalized  = $this->rotation_changes( $changes );
			$secret      = $this->tokens->generate_secret();
			$hash        = $this->tokens->hash( $secret );
			$new_version = $this->with_lock(
				$public_id,
				function () use ( $public_id, $expected_version, $normalized, $hash, $actor_id ): int {
					global $wpdb;
					return $this->transaction->run(
						function () use ( $wpdb, $public_id, $expected_version, $normalized, $hash, $actor_id ): int {
							$row = $this->row_by_public_id( $public_id, true );
							if ( ! is_array( $row ) || ! $this->policy->rotation_allowed( (string) $row['status'], (int) $row['secret_version'], $expected_version ) ) {
								throw new RuntimeException( 'The credential rotation could not be completed.' );
							}
							$now         = gmdate( 'Y-m-d H:i:s' );
							$new_version = (int) $row['secret_version'] + 1;
							$updates     = array_merge(
								$normalized,
								array(
									'secret_hash'    => $hash,
									'secret_version' => $new_version,
									'rotated_at'     => $now,
									'updated_at'     => $now,
								)
							);
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Version-guarded rotation is atomic under the row and advisory locks.
							$updated = $wpdb->update(
								$wpdb->prefix . 'dreamax_lm_api_credentials',
								$updates,
								array(
									'id'             => (int) $row['id'],
									'secret_version' => $expected_version,
									'status'         => 'active',
								)
							);
							if ( 1 !== $updated ) {
								throw new RuntimeException( 'The credential rotation could not be completed.' );
							}
							$this->events->append(
								AuditEventCatalog::CREDENTIAL_ROTATION_SUCCEEDED,
								null,
								'administrator',
								$actor_id,
								null,
								array(
									'credential_public_id' => $public_id,
									'previous_version'     => $expected_version,
									'new_version'          => $new_version,
								),
								AuditEventCatalog::SCHEMA_V1
							);
							return $new_version;
						}
					);
				}
			);
		} catch ( Throwable ) {
			if ( '' !== $secret ) {
				sodium_memzero( $secret );
			}
			$this->events->append(
				AuditEventCatalog::CREDENTIAL_ROTATION_FAILED,
				null,
				'administrator',
				$actor_id,
				null,
				array(
					'credential_public_id' => $public_id,
					'expected_version'     => $expected_version,
					'failure'              => 'not_completed',
				),
				AuditEventCatalog::SCHEMA_V1
			);
			throw new RuntimeException( 'The credential rotation could not be completed.' );
		}

		return array(
			'credential'     => $this->tokens->format( $public_id, $secret ),
			'public_id'      => $public_id,
			'secret_version' => $new_version,
		);
	}

	/**
	 * Immediately and idempotently revokes one credential.
	 *
	 * @param string   $public_id Credential public ID.
	 * @param int|null $actor_id Administrator user ID.
	 * @throws Throwable When revocation cannot be completed.
	 */
	public function revoke( string $public_id, ?int $actor_id = null ): bool {
		$public_id = $this->public_id( $public_id );
		$actor_id  = $actor_id ?? get_current_user_id();
		return $this->with_lock(
			$public_id,
			function () use ( $public_id, $actor_id ): bool {
				global $wpdb;
				return $this->transaction->run(
					function () use ( $wpdb, $public_id, $actor_id ): bool {
						$row = $this->row_by_public_id( $public_id, true );
						if ( ! is_array( $row ) ) {
							throw new RuntimeException( 'The credential revocation could not be completed.' );
						}
						if ( ! $this->policy->revocation_changes( (string) $row['status'] ) ) {
							return false;
						}
						$replacement_secret = $this->tokens->generate_secret();
						try {
							$now = gmdate( 'Y-m-d H:i:s' );
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Revocation is an immediate guarded state transition under both locks.
							$updated = $wpdb->update(
								$wpdb->prefix . 'dreamax_lm_api_credentials',
								array(
									'secret_hash'    => $this->tokens->hash( $replacement_secret ),
									'status'         => 'revoked',
									'secret_version' => (int) $row['secret_version'] + 1,
									'revoked_at'     => $now,
									'updated_at'     => $now,
								),
								array(
									'id'     => (int) $row['id'],
									'status' => (string) $row['status'],
								)
							);
							if ( 1 !== $updated ) {
								throw new RuntimeException( 'The credential revocation could not be completed.' );
							}
							$this->events->append( AuditEventCatalog::CREDENTIAL_REVOKED, null, 'administrator', $actor_id, null, array( 'credential_public_id' => $public_id ), AuditEventCatalog::SCHEMA_V1 );
						} finally {
							sodium_memzero( $replacement_secret );
						}
						return true;
					}
				);
			}
		);
	}

	/**
	 * Lists only safe administrative credential facts.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function list_safe(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Administrative inventory needs fresh site-local state and explicitly excludes verifiers.
		$rows = $wpdb->get_results( "SELECT public_id,name,visible_prefix,scopes,status,expires_at,last_used_at,secret_version,created_at,updated_at,rotated_at,revoked_at FROM {$wpdb->prefix}dreamax_lm_api_credentials ORDER BY id DESC LIMIT 200", ARRAY_A );
		$safe = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$entry                     = $this->safe_row( $row );
			$entry['effective_status'] = 'active' === $entry['status'] && $this->policy->expired( $entry['expires_at'] ?? null, time() ) ? 'expired' : $entry['status'];
			$safe[]                    = $entry;
		}
		return $safe;
	}

	/**
	 * Checks one exact scope against a safe record.
	 *
	 * @param array  $credential Credential record.
	 * @phpstan-param array<string,mixed> $credential
	 * @param string $scope Exact scope.
	 */
	public function has_scope( array $credential, string $scope ): bool {
		$scopes = $credential['scopes'] ?? array();
		if ( is_string( $scopes ) ) {
			$scopes = json_decode( $scopes, true );
		}
		return is_array( $scopes ) && in_array( $scope, $scopes, true );
	}

	/**
	 * Performs one dummy verification and returns false.
	 *
	 * @param string $secret Candidate secret.
	 */
	private function dummy_verification( string $secret ): bool {
		$this->tokens->dummy_verify( $secret );
		return false;
	}

	/**
	 * Loads one credential, optionally with a row lock.
	 *
	 * @param string $public_id Credential public ID.
	 * @param bool   $lock Whether to lock the row.
	 * @return array<string,mixed>|null
	 */
	private function row_by_public_id( string $public_id, bool $lock ): ?array {
		global $wpdb;
		$suffix = $lock ? ' FOR UPDATE' : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The suffix is a fixed optional lock clause and lifecycle reads must be fresh.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dreamax_lm_api_credentials WHERE public_id=%s LIMIT 1{$suffix}", $public_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Removes the secret verifier and decodes scopes for consumers.
	 *
	 * @param array $row Raw credential row.
	 * @phpstan-param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function safe_row( array $row ): array {
		unset( $row['secret_hash'] );
		$scopes        = json_decode( (string) ( $row['scopes'] ?? '[]' ), true );
		$row['scopes'] = is_array( $scopes ) ? array_values( array_intersect( $this->policy->allowed_scopes(), array_map( 'strval', $scopes ) ) ) : array();
		return $row;
	}

	/**
	 * Coalesces last-used writes to one update per five-minute window.
	 *
	 * @param array $row Fresh credential row.
	 * @phpstan-param array<string,mixed> $row
	 * @throws RuntimeException When the conditional update fails.
	 */
	private function touch_last_used( array $row ): void {
		if ( ! $this->policy->last_used_due( $row['last_used_at'] ?? null, time() ) ) {
			return;
		}
		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - CredentialPolicy::LAST_USED_INTERVAL );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional write coalescing avoids a write for every authenticated request.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}dreamax_lm_api_credentials SET last_used_at=%s WHERE id=%d AND status='active' AND (last_used_at IS NULL OR last_used_at<=%s)",
				$now,
				(int) $row['id'],
				$cutoff
			)
		);
		if ( false === $updated ) {
			throw new RuntimeException( 'Credential usage could not be recorded.' );
		}
	}

	/**
	 * Executes work under the site-local named credential lock.
	 *
	 * @param string   $public_id Credential public ID.
	 * @param callable $callback Locked callback.
	 * @phpstan-param callable():T $callback
	 * @template T
	 * @return T
	 * @throws RuntimeException When the advisory lock cannot be acquired.
	 */
	private function with_lock( string $public_id, callable $callback ) {
		global $wpdb;
		$lock_name = $this->lock_name( $public_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locking serializes API use with rotation/revocation across transactions.
		$acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,5)', $lock_name ) );
		if ( 1 !== $acquired ) {
			throw new RuntimeException( 'The credential is temporarily busy.' );
		}
		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release the exact advisory lock acquired above.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Builds a bounded site-local MySQL advisory-lock name.
	 *
	 * @param string $public_id Credential public ID.
	 */
	private function lock_name( string $public_id ): string {
		global $wpdb;
		return 'dlm_cred_' . substr( hash( 'sha256', get_current_blog_id() . '|' . $wpdb->prefix . '|' . $public_id ), 0, 48 );
	}

	/**
	 * Validates a human-readable credential name.
	 *
	 * @param string $name Submitted name.
	 * @throws RuntimeException When the name is invalid.
	 */
	private function name( string $name ): string {
		$name = sanitize_text_field( $name );
		if ( '' === trim( $name ) || strlen( $name ) > 191 ) {
			throw new RuntimeException( 'A credential name of at most 191 characters is required.' );
		}
		return $name;
	}

	/**
	 * Validates the fixed public credential identifier.
	 *
	 * @param string $public_id Submitted public ID.
	 * @throws RuntimeException When the public ID is invalid.
	 */
	private function public_id( string $public_id ): string {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{22}$/D', $public_id ) ) {
			throw new RuntimeException( 'The credential operation could not be completed.' );
		}
		return $public_id;
	}

	/**
	 * Normalizes only explicitly supplied rotation changes.
	 *
	 * @param array $changes Rotation changes.
	 * @phpstan-param array<string,mixed> $changes
	 * @return array<string,mixed>
	 * @throws RuntimeException When a change is invalid.
	 */
	private function rotation_changes( array $changes ): array {
		if ( array_diff( array_keys( $changes ), array( 'name', 'scopes', 'expires_at' ) ) ) {
			throw new RuntimeException( 'The credential rotation changes are invalid.' );
		}
		$updates = array();
		if ( array_key_exists( 'name', $changes ) ) {
			$updates['name'] = $this->name( (string) $changes['name'] );
		}
		if ( array_key_exists( 'scopes', $changes ) ) {
			if ( ! is_array( $changes['scopes'] ) ) {
				throw new RuntimeException( 'The credential rotation scopes are invalid.' );
			}
			$updates['scopes'] = wp_json_encode( $this->policy->scopes( array_values( array_map( 'strval', $changes['scopes'] ) ) ) );
		}
		if ( array_key_exists( 'expires_at', $changes ) ) {
			$updates['expires_at'] = $this->policy->expiration( is_string( $changes['expires_at'] ) ? $changes['expires_at'] : null );
		}
		return $updates;
	}

	/**
	 * Throws the fixed authentication failure envelope.
	 *
	 * @throws LicenseException Always.
	 */
	private function throw_authentication_failure(): void {
		throw new LicenseException( 'authentication_required', 'Authentication is required.', 401 );
	}
}
