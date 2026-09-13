<?php
/**
 * Defines the ContractException class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

use RuntimeException;

/**
 * Reports invalid commercial contract use without exposing secret values.
 */
final class ContractException extends RuntimeException {
}
