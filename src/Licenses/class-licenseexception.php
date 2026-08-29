<?php
/**
 * Defines the LicenseException class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use RuntimeException;

/**
 * Handles License exception operations.
 */
final class LicenseException extends RuntimeException {
	/**
	 * Machine code value.
	 *
	 * @var string
	 */
	private string $machine_code;
	/**
	 * Http status value.
	 *
	 * @var int
	 */
	private int $http_status;

	/**
	 * Initializes the service.
	 *
	 * @param string $machine_code Machine code value.
	 * @param string $message Message value.
	 * @param int    $http_status Http status value.
	 */
	public function __construct( string $machine_code, string $message, int $http_status ) {
		parent::__construct( $message );
		$this->machine_code = $machine_code;
		$this->http_status  = $http_status;
	}

	/**
	 * Handles the machine code operation.
	 */
	public function machine_code(): string {
		return $this->machine_code;
	}

	/**
	 * Handles the http status operation.
	 */
	public function http_status(): int {
		return $this->http_status;
	}
}
