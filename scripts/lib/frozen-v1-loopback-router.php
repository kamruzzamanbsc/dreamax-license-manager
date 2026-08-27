<?php
/**
 * Adapts the frozen client's pretty REST path to a local query-route endpoint.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

$upstream = rtrim( (string) getenv( 'DREAMAX_LM_F28_UPSTREAM' ), '/' );
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Strict path regex validation follows immediately; WordPress is not loaded in this tiny router.
$request_path = (string) ( parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ) ?? '' );
if ( ! preg_match( '#^/wp-json(/dreamax-license-manager/v1/.+)$#D', $request_path, $match )
	|| ! preg_match( '#^http://(?:localhost|127\.0\.0\.1)(?::\d+)?(?:/|$)#D', $upstream ) ) {
	http_response_code( 404 );
	exit;
}

$headers = array( 'Accept: application/json' );
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only selected headers are relayed after CR/LF removal; WordPress is not loaded in this standalone router.
foreach ( array(
	'CONTENT_TYPE'         => 'Content-Type',
	'HTTP_IDEMPOTENCY_KEY' => 'Idempotency-Key',
) as $server_key => $header_name ) {
	if ( isset( $_SERVER[ $server_key ] ) && '' !== (string) $_SERVER[ $server_key ] ) {
		$headers[] = $header_name . ': ' . str_replace( array( "\r", "\n" ), '', (string) $_SERVER[ $server_key ] );
	}
}
// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash
$body    = file_get_contents( 'php://input' );
$context = stream_context_create(
	array(
		'http' => array(
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The built-in server supplies the method; upstream WordPress enforces its route method.
			'method'        => (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ),
			'header'        => implode( "\r\n", $headers ),
			'content'       => false === $body ? '' : $body,
			'ignore_errors' => true,
			'timeout'       => 10,
		),
	)
);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The standalone loopback router has no WordPress HTTP API available.
$response = file_get_contents( $upstream . '/index.php?rest_route=' . rawurlencode( $match[1] ), false, $context );
if ( false === $response ) {
	http_response_code( 502 );
	exit;
}
if ( isset( $http_response_header[0] ) && preg_match( '#\s(\d{3})\s#', $http_response_header[0], $status ) ) {
	http_response_code( (int) $status[1] );
}
header( 'Content-Type: application/json; charset=UTF-8' );
header( 'Cache-Control: no-store' );
echo $response; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Relays upstream JSON byte-for-byte on loopback only.
