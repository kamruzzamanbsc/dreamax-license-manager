<?php
/**
 * Loopback-only reverse intermediary for the guarded private-cache verifier.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The loopback-only router compares the raw path against three fixed literals and does not render it.
$request_uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$request_path = explode( '?', $request_uri, 2 )[0];
if ( '/health' === $request_path ) {
	http_response_code( 204 );
	exit;
}

$target = '/document' === $request_path
	? getenv( 'DREAMAX_LM_CACHE_DOCUMENT_TARGET' )
	: ( '/reveal' === $request_path ? getenv( 'DREAMAX_LM_CACHE_REVEAL_TARGET' ) : false );
if ( ! is_string( $target ) || '' === $target ) {
	http_response_code( 404 );
	exit;
}

// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The exact HTTP method is constrained to GET or POST below.
$method  = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
$method  = in_array( $method, array( 'GET', 'POST' ), true ) ? $method : 'GET';
$headers = array();
if ( isset( $_SERVER['HTTP_COOKIE'] ) ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- An authenticated proxy must forward the Cookie header byte-exact and never renders or logs it.
	$headers[] = 'Cookie: ' . (string) $_SERVER['HTTP_COOKIE'];
}
if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Content-Type is forwarded byte-exact to the fixed local target.
	$headers[] = 'Content-Type: ' . (string) $_SERVER['CONTENT_TYPE'];
}
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Standalone proxy reads its incoming request stream without loading WordPress.
$request_body     = (string) file_get_contents( 'php://input' );
$response_headers = array();

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- This standalone local reverse intermediary cannot use the WordPress HTTP API.
$handle = curl_init( $target );
if ( false === $handle ) {
	http_response_code( 502 );
	exit;
}
curl_setopt( $handle, CURLOPT_RETURNTRANSFER, true );
curl_setopt( $handle, CURLOPT_FOLLOWLOCATION, false );
curl_setopt( $handle, CURLOPT_TIMEOUT, 15 );
curl_setopt( $handle, CURLOPT_CUSTOMREQUEST, $method );
curl_setopt( $handle, CURLOPT_HTTPHEADER, $headers );
if ( 'GET' !== $method && '' !== $request_body ) {
	curl_setopt( $handle, CURLOPT_POSTFIELDS, $request_body );
}
curl_setopt(
	$handle,
	CURLOPT_HEADERFUNCTION,
	static function ( $unused, string $line ) use ( &$response_headers ): int {
		unset( $unused );
		$length = strlen( $line );
		if ( str_starts_with( $line, 'HTTP/' ) ) {
			$response_headers = array();
			return $length;
		}
		$position = strpos( $line, ':' );
		if ( false !== $position ) {
			$name  = strtolower( trim( substr( $line, 0, $position ) ) );
			$value = trim( substr( $line, $position + 1 ) );
			if ( in_array( $name, array( 'cache-control', 'pragma', 'expires', 'content-type', 'x-content-type-options' ), true ) ) {
				$response_headers[ $name ] = $value;
			}
		}
		return $length;
	}
);
$response_body   = curl_exec( $handle );
$response_status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
curl_close( $handle );
// phpcs:enable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close

if ( ! is_string( $response_body ) || $response_status < 100 ) {
	http_response_code( 502 );
	exit;
}
http_response_code( $response_status );
foreach ( $response_headers as $name => $value ) {
	header( $name . ': ' . $value, true );
}
echo $response_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The local intermediary forwards the byte-exact upstream response only to the guarded verifier.
