<?php

declare(strict_types=1);

use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;
use Dreamax\LicenseManager\ReleaseTools\PerformanceFixture;

require_once __DIR__ . '/lib/release-tools.php';

$options = getopt('', array('profile:', 'seed:', 'environment-marker:'));
$profile = (string) ($options['profile'] ?? '');
$seed = (string) ($options['seed'] ?? '');
$marker = (string) ($options['environment-marker'] ?? '');

DisposableEnvironmentGuard::assertSafe(
	array(
		'marker' => $marker,
		'database' => 'dreamax_lm_disposable_test',
		'site_url' => 'https://benchmark.invalid',
	)
);

$result = (new PerformanceFixture($seed))->benchmark($profile);
$result['php_version'] = PHP_VERSION;
$result['generated_at_utc'] = gmdate('Y-m-d\TH:i:s\Z');
$result['limitations'] = 'Synthetic deterministic in-memory smoke evidence; no WordPress, WooCommerce, MySQL, mail, HTTP, or concurrency runtime.';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
