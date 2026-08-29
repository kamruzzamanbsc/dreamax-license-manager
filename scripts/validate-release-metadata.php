<?php

declare(strict_types=1);

use Dreamax\LicenseManager\ReleaseTools\ReadmeHeaderValidator;

require_once __DIR__ . '/lib/release-tools.php';

$result = ReadmeHeaderValidator::validate(dirname(__DIR__));
echo 'PASS: plugin header and WordPress readme metadata are structurally valid and internally consistent at version ' . $result['Version'] . '.' . PHP_EOL;
