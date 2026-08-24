<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

final class ResponseFactory {
	/** @param array<string,mixed> $data */
	public function make( bool $success, string $code, string $message, string $request_id, array $data, int $status ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'success'    => $success,
				'code'       => $code,
				'message'    => $message,
				'request_id' => $request_id,
				'timestamp'  => gmdate( 'c' ),
				'data'       => (object) $data,
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		return $response;
	}
}
