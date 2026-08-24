<?php

declare(strict_types=1);

$base     = rtrim( (string) getenv( 'DREAMAX_LM_BASE_URL' ), '/' );
$license  = (string) getenv( 'DREAMAX_LM_LICENSE' );
$product  = (string) getenv( 'DREAMAX_LM_PRODUCT' );
$instance = (string) getenv( 'DREAMAX_LM_INSTANCE' );
if ( '' === $base || '' === $license || '' === $product || '' === $instance ) {
	fwrite( STDERR, "Set DREAMAX_LM_BASE_URL, DREAMAX_LM_LICENSE, DREAMAX_LM_PRODUCT, and DREAMAX_LM_INSTANCE.\n" );
	exit( 2 );
}

/** @return array<string,mixed> */
function request_v1( string $base, string $path, array $payload, ?string $idempotency = null ): array {
	$headers = array( 'Content-Type: application/json', 'Accept: application/json' );
	if ( null !== $idempotency ) {
		$headers[] = 'Idempotency-Key: ' . $idempotency;
	}
	$context = stream_context_create( array( 'http' => array( 'method' => 'POST', 'header' => implode( "\r\n", $headers ), 'content' => json_encode( $payload, JSON_THROW_ON_ERROR ), 'ignore_errors' => true, 'timeout' => 10 ) ) );
	$raw = file_get_contents( $base . '/wp-json/dreamax-license-manager/v1' . $path, false, $context );
	if ( false === $raw ) {
		throw new RuntimeException( 'Network request failed.' );
	}
	$result = json_decode( $raw, true, 32, JSON_THROW_ON_ERROR );
	foreach ( array( 'success', 'code', 'message', 'request_id', 'timestamp', 'data' ) as $field ) {
		if ( ! array_key_exists( $field, $result ) ) {
			throw new RuntimeException( 'Missing envelope field: ' . $field );
		}
	}
	new DateTimeImmutable( (string) $result['timestamp'] );
	return $result;
}

$payload = array( 'license_key' => $license, 'product_public_id' => $product, 'instance_id' => $instance, 'instance_label' => 'Frozen v1 fixture' );
$key     = 'fixture-' . bin2hex( random_bytes( 16 ) );
$first   = request_v1( $base, '/licenses/activate', $payload, $key );
$replay  = request_v1( $base, '/licenses/activate', $payload, $key );
if ( ! $first['success'] || ! $replay['success'] || $first['code'] !== $replay['code'] ) {
	throw new RuntimeException( 'Activation replay contract failed.' );
}
$conflict_payload = $payload;
$conflict_payload['instance_label'] = 'Different payload';
$conflict = request_v1( $base, '/licenses/activate', $conflict_payload, $key );
if ( 'idempotency_conflict' !== $conflict['code'] ) {
	throw new RuntimeException( 'Idempotency conflict contract failed.' );
}
$valid = request_v1( $base, '/licenses/validate', $payload );
if ( ! $valid['success'] || 'license_valid' !== $valid['code'] ) {
	throw new RuntimeException( 'Validation contract failed.' );
}
$deactivate = request_v1( $base, '/licenses/deactivate', $payload, 'fixture-' . bin2hex( random_bytes( 16 ) ) );
if ( ! $deactivate['success'] || 'license_deactivated' !== $deactivate['code'] ) {
	throw new RuntimeException( 'Deactivation contract failed.' );
}
fwrite( STDOUT, "Frozen v1 reference flow passed.\n" );
