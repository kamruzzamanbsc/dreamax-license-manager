<?php
/**
 * Defines deterministic non-secret wrong key material for the guarded F11 worker.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

define(
	'DREAMAX_LICENSE_MANAGER_MASTER_KEY',
	// phpcs:ignore -- Generates deterministic synthetic non-secret key-shaped test material; it does not obfuscate executable code or a secret.
	rtrim( strtr( base64_encode( str_repeat( chr( 165 ), 32 ) ), '+/', '-_' ), '=' )
);
