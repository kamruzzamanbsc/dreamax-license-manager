<?php
/**
 * Defines the PublicRoutes class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

use Dreamax\LicenseManager\Activations\ActivationService;
use Dreamax\LicenseManager\Encryption\Crypto;
use Dreamax\LicenseManager\Licenses\LicenseException;
use Dreamax\LicenseManager\Support\Health;
use Dreamax\LicenseManager\Support\PublicId;
use Throwable;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Handles Public routes operations.
 */
final class PublicRoutes {
	private const NAMESPACE = 'dreamax-license-manager/v1';

	/**
	 * Activations value.
	 *
	 * @var ActivationService
	 */
	private ActivationService $activations;
	/**
	 * Transport value.
	 *
	 * @var TransportGuard
	 */
	private TransportGuard $transport;
	/**
	 * Limits value.
	 *
	 * @var RateLimiter
	 */
	private RateLimiter $limits;
	/**
	 * Idempotency value.
	 *
	 * @var IdempotencyRepository
	 */
	private IdempotencyRepository $idempotency;
	/**
	 * Responses value.
	 *
	 * @var ResponseFactory
	 */
	private ResponseFactory $responses;
	/**
	 * Source value.
	 *
	 * @var SourceAddress
	 */
	private SourceAddress $source;
	/**
	 * Encryption readiness guard.
	 *
	 * @var Crypto
	 */
	private Crypto $crypto;

	/**
	 * Initializes the service.
	 */
	public function __construct() {
		$this->activations = new ActivationService();
		$this->transport   = new TransportGuard();
		$this->limits      = new RateLimiter();
		$this->idempotency = new IdempotencyRepository();
		$this->responses   = new ResponseFactory();
		$this->source      = new SourceAddress();
		$this->crypto      = new Crypto();
	}

	/**
	 * Handles the register operation.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'cors' ), 20, 4 );
	}

	/**
	 * Handles the routes operation.
	 */
	public function routes(): void {
		foreach ( array( 'activate', 'deactivate', 'validate', 'status' ) as $operation ) {
			register_rest_route(
				self::NAMESPACE,
				'/licenses/' . $operation,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $operation ),
					'permission_callback' => '__return_true',
				)
			);
		}

		register_rest_route(
			self::NAMESPACE,
			'/system/ping',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ping' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handles the activate operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function activate( WP_REST_Request $request ): \WP_REST_Response {
		return $this->mutation( $request, 'activate' );
	}

	/**
	 * Handles the deactivate operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function deactivate( WP_REST_Request $request ): \WP_REST_Response {
		return $this->mutation( $request, 'deactivate' );
	}

	/**
	 * Handles the validate operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function validate( WP_REST_Request $request ): \WP_REST_Response {
		return $this->read_operation( $request, 'validate' );
	}

	/**
	 * Handles the status operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 */
	public function status( WP_REST_Request $request ): \WP_REST_Response {
		return $this->read_operation( $request, 'status' );
	}

	/**
	 * Handles the ping operation.
	 */
	public function ping(): \WP_REST_Response {
		$request_id = PublicId::generate( 'req' );
		try {
			$this->assert_ready();
		} catch ( Throwable $error ) {
			unset( $error );
			return $this->responses->make( false, 'server_unavailable', 'The licensing service is temporarily unavailable.', $request_id, array(), 503 );
		}
		return $this->responses->make(
			true,
			'system_available',
			'The licensing endpoint is available.',
			$request_id,
			array(
				'api_version'    => 'v1',
				'plugin_version' => DREAMAX_LM_VERSION,
			),
			200
		);
	}

	/**
	 * Handles the cors operation.
	 *
	 * @param  bool             $served Whether the request has already been served.
	 * @param  WP_HTTP_Response $result Result to send to the client.
	 * @param WP_REST_Request  $request Request value.
	 * @param  WP_REST_Server   $server REST server instance.
	 * @return bool
	 */
	public function cors( $served, $result, WP_REST_Request $request, $server ) {
		unset( $result, $server );
		if ( 0 !== strpos( $request->get_route(), '/' . self::NAMESPACE . '/' ) ) {
			return $served;
		}
		header_remove( 'Access-Control-Allow-Origin' );
		$origin  = get_http_origin();
		$allowed = get_option( 'dreamax_lm_allowed_origins', array() );
		if ( $origin && is_array( $allowed ) && in_array( $origin, $allowed, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $origin ) );
			header( 'Vary: Origin', false );
		}
		return $served;
	}

	/**
	 * Handles the mutation operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @param string          $operation Operation value.
	 */
	private function mutation( WP_REST_Request $request, string $operation ): \WP_REST_Response {
		$request_id = PublicId::generate( 'req' );
		$scope      = null;
		try {
			$this->assert_ready();
			$this->transport->assert_public_request();
			$payload = $this->payload( $request, true );
			$this->apply_protective_limits( $operation );

			$idempotency_key = (string) $request->get_header( 'Idempotency-Key' );
			if ( '' !== $idempotency_key ) {
				$reservation = $this->idempotency->reserve( $idempotency_key, $operation, $payload );
				$scope       = $reservation['scope'];
				if ( is_array( $reservation['replay'] ) ) {
					$replay = $reservation['replay'];
					return $this->responses->make( $replay['status'] < 400, $replay['code'], $this->message( $replay['code'] ), $request_id, $replay['data'], $replay['status'] );
				}
			}
			$this->apply_operation_limit( $operation, $payload );

			if ( 'activate' === $operation ) {
				$data = $this->activations->activate( $payload['license_key'], $payload['product_public_id'], $payload['instance_id'], $payload['instance_label'], $request_id );
				$code = 'license_activated';
			} else {
				$data = $this->activations->deactivate( $payload['license_key'], $payload['product_public_id'], $payload['instance_id'], $request_id );
				$code = 'license_deactivated';
			}

			if ( is_string( $scope ) ) {
				$this->idempotency->complete( $scope, 200, $code, $data );
			}
			return $this->responses->make( true, $code, $this->message( $code ), $request_id, $data, 200 );
		} catch ( LicenseException $error ) {
			$rate_error = $this->failure_limit( $operation );
			if ( $rate_error instanceof LicenseException && ! in_array( $error->machine_code(), array( 'rate_limited', 'server_unavailable' ), true ) ) {
				$error = $rate_error;
			}
			if ( is_string( $scope ) ) {
				$this->idempotency->complete( $scope, $error->http_status(), $error->machine_code(), array() );
			}
			return $this->responses->make( false, $error->machine_code(), $error->getMessage(), $request_id, array(), $error->http_status() );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Records only an opaque request ID; no request data or secrets are logged.
			error_log( 'Dreamax License Manager request failed. Request ID: ' . $request_id );
			if ( is_string( $scope ) ) {
				$this->idempotency->complete( $scope, 503, 'server_unavailable', array() );
			}
			return $this->responses->make( false, 'server_unavailable', 'The licensing service is temporarily unavailable.', $request_id, array(), 503 );
		}
	}

	/**
	 * Handles the read operation operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @param string          $operation Operation value.
	 */
	private function read_operation( WP_REST_Request $request, string $operation ): \WP_REST_Response {
		$request_id = PublicId::generate( 'req' );
		try {
			$this->assert_ready();
			$this->transport->assert_public_request();
			$payload = $this->payload( $request, false );
			$this->apply_protective_limits( $operation );
			$this->apply_operation_limit( $operation, $payload );
			$data = $this->activations->validate( $payload['license_key'], $payload['product_public_id'], $payload['instance_id'] );
			return $this->responses->make( true, 'license_valid', 'The license is valid.', $request_id, $data, 200 );
		} catch ( LicenseException $error ) {
			$rate_error = $this->failure_limit( $operation );
			if ( $rate_error instanceof LicenseException && ! in_array( $error->machine_code(), array( 'rate_limited', 'server_unavailable' ), true ) ) {
				$error = $rate_error;
			}
			return $this->responses->make( false, $error->machine_code(), $error->getMessage(), $request_id, array(), $error->http_status() );
		} catch ( Throwable $error ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Records only an opaque request ID; no request data or secrets are logged.
			error_log( 'Dreamax License Manager request failed. Request ID: ' . $request_id );
			return $this->responses->make( false, 'server_unavailable', 'The licensing service is temporarily unavailable.', $request_id, array(), 503 );
		}
	}

	/**
	 * Fails closed before any key-dependent rate, idempotency, or license work.
	 *
	 * @throws LicenseException When encryption is not ready.
	 */
	private function assert_ready(): void {
		if ( ! $this->crypto->ready() || ! Health::storage_ready() ) {
			throw new LicenseException( 'server_unavailable', 'The licensing service is temporarily unavailable.', 503 );
		}
	}

	/**
	 * Handles the payload operation.
	 *
	 * @param WP_REST_Request $request Request value.
	 * @param bool            $instance_required Instance required value.
	 * @throws LicenseException When the operation cannot be completed.
	 * @return array{license_key:string,product_public_id:string,instance_id:string,instance_label:?string}
	 */
	private function payload( WP_REST_Request $request, bool $instance_required ): array {
		$data = $request->get_json_params();
		if ( ! is_array( $data ) ) {
			throw new LicenseException( 'invalid_request', 'The JSON body is invalid.', 400 );
		}
		$allowed = array( 'license_key', 'product_public_id', 'instance_id', 'instance_label' );
		if ( array_diff( array_keys( $data ), $allowed ) ) {
			throw new LicenseException( 'invalid_request', 'The request contains unexpected fields.', 400 );
		}
		$key      = isset( $data['license_key'] ) && is_string( $data['license_key'] ) ? $data['license_key'] : '';
		$product  = isset( $data['product_public_id'] ) && is_string( $data['product_public_id'] ) ? $data['product_public_id'] : '';
		$instance = isset( $data['instance_id'] ) && is_string( $data['instance_id'] ) ? $data['instance_id'] : '';
		$label    = isset( $data['instance_label'] ) && is_string( $data['instance_label'] ) ? $data['instance_label'] : null;
		if ( '' === $key || strlen( $key ) > 512 || '' === $product || ( $instance_required && '' === $instance ) ) {
			throw new LicenseException( 'invalid_request', 'Required request fields are missing or too long.', 400 );
		}
		return array(
			'license_key'       => $key,
			'product_public_id' => $product,
			'instance_id'       => $instance,
			'instance_label'    => $label,
		);
	}

	/**
	 * Handles the apply protective limits operation.
	 *
	 * @param string $operation Operation value.
	 */
	private function apply_protective_limits( string $operation ): void {
		$network = $this->source->network();
		$this->limits->consume( 'breaker|' . $operation, 600, 10.0, 503 );
		$this->limits->consume( 'aggregate|' . $network, 120, 2.0 );
	}

	/**
	 * Handles the apply operation limit operation.
	 *
	 * @param string $operation Operation value.
	 * @param array  $payload Payload value.
	 * @phpstan-param array<string,mixed> $payload Payload value.
	 */
	private function apply_operation_limit( string $operation, array $payload ): void {
		$key_scope = hash( 'sha256', $payload['license_key'] . '|' . $payload['product_public_id'] . '|' . $operation );
		if ( in_array( $operation, array( 'activate', 'deactivate' ), true ) ) {
			$this->limits->consume( 'mutation|' . $key_scope . '|' . $payload['instance_id'], 10, 1.0 / 6.0 );
		} else {
			$this->limits->consume( 'read|' . $key_scope, 60, 1.0 );
		}
	}

	/**
	 * Handles the failure limit operation.
	 *
	 * @param string $operation Operation value.
	 */
	private function failure_limit( string $operation ): ?LicenseException {
		try {
			$this->limits->consume( 'failure|' . $this->source->network() . '|' . $operation, 20, 1.0 / 30.0 );
			return null;
		} catch ( LicenseException $error ) {
			return $error;
		} catch ( Throwable $ignored ) {
			return new LicenseException( 'server_unavailable', 'The licensing service is temporarily unavailable.', 503 );
		}
	}

	/**
	 * Handles the message operation.
	 *
	 * @param string $code Code value.
	 */
	private function message( string $code ): string {
		$messages = array(
			'license_activated'    => 'The license was activated.',
			'license_deactivated'  => 'The activation was deactivated.',
			'license_valid'        => 'The license is valid.',
			'invalid_license'      => 'The license could not be validated.',
			'product_mismatch'     => 'The license does not belong to the requested product.',
			'license_expired'      => 'The license has expired.',
			'license_suspended'    => 'The license is temporarily disabled.',
			'license_revoked'      => 'The license is permanently revoked.',
			'idempotency_conflict' => 'This idempotency key was used with different request data.',
		);
		return $messages[ $code ] ?? 'The request was processed.';
	}
}
