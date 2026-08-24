<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Licenses;

use RuntimeException;

final class LicenseException extends RuntimeException {
	private string $machine_code;
	private int $http_status;

	public function __construct( string $machine_code, string $message, int $http_status ) {
		parent::__construct( $message );
		$this->machine_code = $machine_code;
		$this->http_status  = $http_status;
	}

	public function machine_code(): string {
		return $this->machine_code;
	}

	public function http_status(): int {
		return $this->http_status;
	}
}
