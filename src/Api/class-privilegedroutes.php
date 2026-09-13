<?php
/**
 * Defines the PrivilegedRoutes class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

use Dreamax\LicenseManager\Credentials\CredentialService;
use Dreamax\LicenseManager\Database\Transaction;
use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Events\EventRepository;
use Dreamax\LicenseManager\Licenses\KeyNormalizer;
use Dreamax\LicenseManager\Licenses\LicenseException;
use Dreamax\LicenseManager\Licenses\LicenseRepository;
use Dreamax\LicenseManager\Licenses\LicenseService;
use Dreamax\LicenseManager\Licenses\StateMachine;
use Dreamax\LicenseManager\Support\PublicId;
use Throwable;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles Privileged routes operations.
 */
final class PrivilegedRoutes {
	private const NAMESPACE          = 'dreamax-license-manager/v1';
	private const PERMISSION_CONTEXT = '_dreamax_lm_privileged_permission';
	private const PERMISSION_ERROR   = '_dreamax_lm_privileged_permission_error';

	/**
	 * Credentials value.
	 *
	 * @var CredentialService
	 */
	private CredentialService $credentials;
	/**
	 * Transport value.
	 *
	 * @var TransportGuard
	 */
	private TransportGuard $transport;
	/**
	 * Responses value.
	 *
	 * @var ResponseFactory
	 */
	private ResponseFactory $responses;
	/**
	 * Limits value.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limits;
	/**
	 * Source value.
	 *
	 * @var SourceAddress
	 */
	private SourceAddress $source;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->credentials = new CredentialService();
		$this->transport   = new TransportGuard();
		$this->responses   = new ResponseFactory();
		$this->limits      = new RateLimiter();
		$this->source      = new SourceAddress();
	}

	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'normalize_permission_error' ), 10, 3 );
	}

	/**
	 * Handles the routes operation.
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/licenses',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'licenses' ),
					'permission_callback' => array( $this, 'can_read_licenses' ),
					'args'                => RouteSchema::list_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_write_licenses' ),
					'args'                => RouteSchema::create_license_args(),
				),
				'schema' => array( RouteSchema::class, 'envelope' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<public_id>lic_[A-Za-z0-9_-]{22})',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'license' ),
					'permission_callback' => array( $this, 'can_read_licenses' ),
					'args'                => array( 'public_id' => RouteSchema::public_id_arg( 'lic', true ) ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_write_licenses' ),
					'args'                => array_merge( array( 'public_id' => RouteSchema::public_id_arg( 'lic', true ) ), RouteSchema::update_license_args() ),
				),
				'schema' => array( RouteSchema::class, 'envelope' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/licenses/(?P<public_id>lic_[A-Za-z0-9_-]{22})/revoke',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => array( $this, 'can_write_licenses' ),
					'args'                => array( 'public_id' => RouteSchema::public_id_arg( 'lic', true ) ),
				),
				'schema' => array( RouteSchema::class, 'envelope' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/activations',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'activations' ),
					'permission_callback' => array( $this, 'can_read_activations' ),
				),
				'schema' => array( RouteSchema::class, 'envelope' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/generators',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'generators' ),
					'permission_callback' => array( $this, 'can_read_generators' ),
				),
				'schema' => array( RouteSchema::class, 'envelope' ),
			)
		);
	}

	/**
	 * Authorizes a privileged license read before its route callback runs.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @return bool|WP_Error
	 */
	public function can_read_licenses( WP_REST_Request $request ) {
		return $this->permission( $request, 'licenses:read', false );
	}

	/**
	 * Authorizes a privileged license mutation before its route callback runs.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @return bool|WP_Error
	 */
	public function can_write_licenses( WP_REST_Request $request ) {
		return $this->permission( $request, 'licenses:write', true );
	}

	/**
	 * Authorizes privileged activation reads before their route callback runs.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @return bool|WP_Error
	 */
	public function can_read_activations( WP_REST_Request $request ) {
		return $this->permission( $request, 'activations:read', false );
	}

	/**
	 * Authorizes privileged generator reads before their route callback runs.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @return bool|WP_Error
	 */
	public function can_read_generators( WP_REST_Request $request ) {
		return $this->permission( $request, 'generators:read', false );
	}

	/**
	 * Restores the frozen v1 envelope for errors rejected by permission callbacks.
	 *
	 * @param WP_HTTP_Response $response Response value.
	 * @param WP_REST_Server   $server Server value.
	 * @param WP_REST_Request  $request Request value.
	 */
	public function normalize_permission_error( WP_HTTP_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_HTTP_Response {
		unset( $server );
		if ( 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			return $response;
		}
		$data       = $response->get_data();
		$error_data = is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array();
		if ( true !== ( $error_data[ self::PERMISSION_ERROR ] ?? false ) ) {
			return $response;
		}
		$request_id = isset( $error_data['request_id'] ) && is_string( $error_data['request_id'] ) ? $error_data['request_id'] : PublicId::generate( 'req' );
		$code       = isset( $data['code'] ) && is_string( $data['code'] ) ? $data['code'] : 'authentication_required';
		$message    = isset( $data['message'] ) && is_string( $data['message'] ) ? $data['message'] : 'Authentication is required.';
		return $this->responses->make( false, $code, $message, $request_id, array(), $response->get_status() );
	}

	/**
	 * Authenticates and scope-authorizes a privileged request before dispatch.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @param string          $scope Required scope.
	 * @param bool            $has_body Whether this route requires JSON content type.
	 * @return bool|WP_Error
	 * @throws LicenseException Internally converted to a marked permission error.
	 */
	private function permission( WP_REST_Request $request, string $scope, bool $has_body ) {
		$request_id = PublicId::generate( 'req' );
		try {
			$this->transport->assert_privileged_request( $request, $has_body );
			$credential = $this->credentials->authenticate( (string) $request->get_header( 'Authorization' ), $request_id );
			if ( ! is_array( $credential ) ) {
				$this->limits->consume( 'privileged-auth-failed|' . $this->source->network(), 10, 1.0 / 60.0 );
				throw new LicenseException( 'authentication_required', 'Authentication is required.', 401 );
			}
			$this->credentials->assert_scope( $credential, $scope, $request_id, true );
			$request->set_param(
				self::PERMISSION_CONTEXT,
				array(
					'credential' => $credential,
					'request_id' => $request_id,
					'scope'      => $scope,
				)
			);
			return true;
		} catch ( LicenseException $error ) {
			return $this->permission_error( $error->machine_code(), $error->getMessage(), $error->http_status(), $request_id );
		} catch ( Throwable $error ) {
			unset( $error );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Records only an opaque request ID; no request data or secrets are logged.
			error_log( 'Dreamax privileged API permission check failed. Request ID: ' . $request_id );
			return $this->permission_error( 'server_unavailable', 'The licensing service is temporarily unavailable.', 503, $request_id );
		}
	}

	/**
	 * Creates a marked REST error that is normalized to the public v1 envelope.
	 *
	 * @param string $code Machine code.
	 * @param string $message Public message.
	 * @param int    $status HTTP status.
	 * @param string $request_id Opaque request ID.
	 */
	private function permission_error( string $code, string $message, int $status, string $request_id ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'               => $status,
				self::PERMISSION_ERROR => true,
				'request_id'           => $request_id,
			)
		);
	}

	/**
	 * Handles the licenses operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function licenses( WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle(
			$request,
			'licenses:read',
			false,
			function () use ( $request ): array {
				global $wpdb;
				$page = max( 1, (int) $request->get_param( 'page' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT public_id,product_public_id,lifecycle_status,activation_limit,expires_at,order_id,customer_id,created_at,updated_at FROM {$wpdb->prefix}dreamax_lm_licenses ORDER BY id DESC LIMIT 100 OFFSET %d", ( $page - 1 ) * 100 ), ARRAY_A );
				return array(
					'code' => 'licenses_listed',
					'data' => array(
						'items' => is_array( $rows ) ? $rows : array(),
						'page'  => $page,
					),
				);
			}
		);
	}

	/**
	 * Handles the create operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	public function create( WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle(
			$request,
			'licenses:write',
			true,
			function () use ( $request ): array {
				$body = $request->get_json_params();
				if ( ! is_array( $body ) || ! isset( $body['product_public_id'] ) || ! preg_match( '/^prd_[A-Za-z0-9_-]{22}$/D', (string) $body['product_public_id'] ) ) {
					throw new LicenseException( 'invalid_request', 'A valid product_public_id is required.', 400 );
				}
				$attrs = array(
					'product_public_id' => (string) $body['product_public_id'],
					'lifecycle_status'  => isset( $body['lifecycle_status'] ) ? (string) $body['lifecycle_status'] : 'assigned',
					'activation_limit'  => array_key_exists( 'activation_limit', $body ) ? ( null === $body['activation_limit'] ? null : max( 0, (int) $body['activation_limit'] ) ) : 1,
					'expires_at'        => empty( $body['expires_at'] ) ? null : gmdate( 'Y-m-d H:i:s', strtotime( (string) $body['expires_at'] ) ),
					'actor_type'        => 'api_credential',
					'source'            => 'privileged_api',
				);
				if ( isset( $body['license_key'] ) && is_string( $body['license_key'] ) && '' !== $body['license_key'] ) {
					$result = ( new LicenseService() )->import( $body['license_key'], KeyNormalizer::IMPORTED, null, $attrs );
				} else {
					$result = ( new LicenseService() )->create_generated( $attrs );
				}
				return array(
					'code'   => 'license_created',
					'status' => 201,
					'data'   => array(
						'license_public_id' => $result['public_id'],
						'license_key'       => $result['key'],
					),
				);
			}
		);
	}

	/**
	 * Handles the license operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	public function license( WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle(
			$request,
			'licenses:read',
			false,
			function () use ( $request ): array {
				$row = ( new LicenseRepository() )->by_public_id( (string) $request['public_id'] );
				if ( ! $row ) {
					throw new LicenseException( 'invalid_license', 'The license could not be found.', 404 );
				}
				unset( $row['key_ciphertext'], $row['key_fingerprint'], $row['customer_email_enc'], $row['metadata'] );
				return array(
					'code' => 'license_found',
					'data' => $row,
				);
			}
		);
	}

	/**
	 * Handles the update operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	public function update( WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle(
			$request,
			'licenses:write',
			true,
			function () use ( $request ): array {
				$body = $request->get_json_params();
				if ( ! is_array( $body ) ) {
					throw new LicenseException( 'invalid_request', 'The JSON body is invalid.', 400 );
				}
				return $this->change( (string) $request['public_id'], $body, false );
			}
		);
	}

	/**
	 * Handles the revoke operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function revoke( WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle( $request, 'licenses:write', true, fn(): array => $this->change( (string) $request['public_id'], array( 'lifecycle_status' => 'revoked' ), true ) );
	}

	/**
	 * Handles the activations operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function activations( WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle(
			$request,
			'activations:read',
			false,
			function (): array {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$rows = $wpdb->get_results( "SELECT public_id,license_id,instance_label,status,first_activated_at,activated_at,deactivated_at,last_seen_at FROM {$wpdb->prefix}dreamax_lm_activations ORDER BY id DESC LIMIT 100", ARRAY_A );
				return array(
					'code' => 'activations_listed',
					'data' => array( 'items' => is_array( $rows ) ? $rows : array() ),
				);
			}
		);
	}

	/**
	 * Handles the generators operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function generators( WP_REST_Request $request ): \WP_REST_Response {
		return $this->handle(
			$request,
			'generators:read',
			false,
			function (): array {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				$rows = $wpdb->get_results( "SELECT public_id,name,valid_for_seconds,default_activation_limit,created_at,updated_at FROM {$wpdb->prefix}dreamax_lm_generators ORDER BY id DESC LIMIT 100", ARRAY_A );
				return array(
					'code' => 'generators_listed',
					'data' => array( 'items' => is_array( $rows ) ? $rows : array() ),
				);
			}
		);
	}

	/**
	 * Handles the handle operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @param string          $scope Scope value.
	 * @param bool            $has_body Has body value.
	 * @param callable        $callback Callback value.
	 * @phpstan-param callable():array<string,mixed> $callback Callback value.
	 * @throws LicenseException When the operation cannot be completed.
	 */
	private function handle( WP_REST_Request $request, string $scope, bool $has_body, callable $callback ): \WP_REST_Response {
		unset( $has_body );
		$context    = $request->get_param( self::PERMISSION_CONTEXT );
		$request_id = is_array( $context ) && isset( $context['request_id'] ) && is_string( $context['request_id'] ) ? $context['request_id'] : PublicId::generate( 'req' );
		try {
			$credential       = is_array( $context ) && isset( $context['credential'] ) && is_array( $context['credential'] ) ? $context['credential'] : null;
			$authorized_scope = is_array( $context ) && isset( $context['scope'] ) && is_string( $context['scope'] ) ? $context['scope'] : '';
			if ( ! is_array( $credential ) || ! hash_equals( $scope, $authorized_scope ) ) {
				throw new LicenseException( 'authentication_required', 'Authentication is required.', 401 );
			}
			$result = $this->credentials->authorized_use( $credential, $scope, $request_id, $callback );
			$status = isset( $result['status'] ) ? (int) $result['status'] : 200;
			return $this->responses->make( true, (string) $result['code'], 'The request was completed.', $request_id, (array) $result['data'], $status );
		} catch ( LicenseException $error ) {
			return $this->responses->make( false, $error->machine_code(), $error->getMessage(), $request_id, array(), $error->http_status() );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Records only an opaque request ID; no request data or secrets are logged.
			error_log( 'Dreamax privileged API request failed. Request ID: ' . $request_id );
			return $this->responses->make( false, 'server_unavailable', 'The licensing service is temporarily unavailable.', $request_id, array(), 503 );
		}
	}

	/**
	 * Handles the change operation.
	 *
	 * @param string $public_id Public id value.
	 * @param array  $body Body value.
	 * @phpstan-param array<string,mixed> $body Body value.
	 * @param bool   $terminal Terminal value.
	 * @throws LicenseException When the operation cannot be completed.
	 * @return array{
	 *     code: 'license_revoked'|'license_updated',
	 *     data: array{
	 *         license_public_id: string,
	 *         lifecycle_status: mixed,
	 *         expires_at: mixed,
	 *         activation_limit: mixed
	 *     }
	 * }
	 */
	private function change( string $public_id, array $body, bool $terminal ): array {
		global $wpdb;
		$allowed = array( 'lifecycle_status', 'expires_at', 'activation_limit' );
		if ( array_diff( array_keys( $body ), $allowed ) ) {
			throw new LicenseException( 'invalid_request', 'The request contains unexpected fields.', 400 );
		}
		return ( new Transaction() )->run(
			function () use ( $wpdb, $public_id, $body, $terminal ): array {
				$table = $wpdb->prefix . 'dreamax_lm_licenses';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The trusted prefixed table requires a fresh locking read.
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_id=%s FOR UPDATE', $table, $public_id ), ARRAY_A );
				if ( ! is_array( $row ) ) {
						throw new LicenseException( 'invalid_license', 'The license could not be found.', 404 );
				}
				$updates = array( 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
				if ( isset( $body['lifecycle_status'] ) ) {
					$target = (string) $body['lifecycle_status'];
					if ( $target !== $row['lifecycle_status'] && ! ( new StateMachine() )->can_transition( (string) $row['lifecycle_status'], $target ) ) {
						throw new LicenseException( 'invalid_request', 'That lifecycle transition is not allowed.', 409 );
					}
					$updates['lifecycle_status'] = $target;
				}
				if ( array_key_exists( 'expires_at', $body ) ) {
					$updates['expires_at'] = null === $body['expires_at'] ? null : gmdate( 'Y-m-d H:i:s', strtotime( (string) $body['expires_at'] ) );
				}
				if ( array_key_exists( 'activation_limit', $body ) ) {
					$updates['activation_limit'] = null === $body['activation_limit'] ? null : max( 0, (int) $body['activation_limit'] );
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Plugin-owned transactional tables require direct, fresh database reads and writes; object caching would break locking and replay guarantees.
				if ( false === $wpdb->update( $table, $updates, array( 'id' => (int) $row['id'] ) ) ) {
					throw new LicenseException( 'server_unavailable', 'The license could not be updated.', 503 );
				}
				( new EventRepository() )->append( $terminal ? AuditEventCatalog::LICENSE_REVOKED : AuditEventCatalog::LICENSE_UPDATED, (int) $row['id'], 'api_credential', null, null, array( 'changed_fields' => array_keys( $updates ) ), AuditEventCatalog::SCHEMA_V1 );
				return array(
					'code' => $terminal ? 'license_revoked' : 'license_updated',
					'data' => array(
						'license_public_id' => $public_id,
						'lifecycle_status'  => $updates['lifecycle_status'] ?? $row['lifecycle_status'],
						'expires_at'        => $updates['expires_at'] ?? $row['expires_at'],
						'activation_limit'  => array_key_exists( 'activation_limit', $updates ) ? $updates['activation_limit'] : $row['activation_limit'],
					),
				);
			}
		);
	}
}
