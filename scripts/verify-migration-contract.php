<?php

declare(strict_types=1);

use Dreamax\LicenseManager\Database\Schema;
use Dreamax\LicenseManager\ReleaseTools\DisposableEnvironmentGuard;

require_once dirname(__DIR__) . '/src/Database/class-schema.php';
require_once __DIR__ . '/lib/release-tools.php';

$options = getopt('', array('environment-marker:'));
DisposableEnvironmentGuard::assertSafe(
	array(
		'marker' => (string) ($options['environment-marker'] ?? ''),
		'database' => 'dreamax_lm_migration_test',
		'site_url' => 'https://migration.invalid',
	)
);

$fixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/tests/fixtures/schema-history.json'), true, 512, JSON_THROW_ON_ERROR);
$schema = new Schema();
$versions = Schema::supported_versions();
if ($versions !== array_column($fixture['versions'], 'version') || Schema::VERSION !== $fixture['current_version']) {
	throw new RuntimeException('The historical schema fixture does not match the executable migration plan.');
}

$snapshots = array();
foreach ($fixture['versions'] as $entry) {
	$first = $schema->statements($entry['version'], 'wp_2_dreamax_lm_', 'DEFAULT CHARSET=utf8mb4');
	$second = $schema->statements($entry['version'], 'wp_2_dreamax_lm_', 'DEFAULT CHARSET=utf8mb4');
	if ($first !== $second || count($first) !== $entry['table_count']) {
		throw new RuntimeException('A schema snapshot is not deterministic or idempotent.');
	}
	$encoded = implode("\n", $first);
	if (str_contains($encoded, 'wp_dreamax_lm_') || ! str_contains($encoded, 'wp_2_dreamax_lm_') || ! str_contains($encoded, 'ENGINE=InnoDB')) {
		throw new RuntimeException('The schema is not isolated to the selected site-local prefix.');
	}
	$snapshots[] = array(
		'version' => $entry['version'],
		'table_count' => count($first),
		'snapshot_sha256' => hash('sha256', $encoded),
		'idempotent' => true,
	);
}

echo json_encode(
	array(
		'classification' => 'source_contract_only',
		'current_version' => Schema::VERSION,
		'versions' => $snapshots,
		'database_touched' => false,
		'live_mysql_required' => true,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
) . PHP_EOL;
