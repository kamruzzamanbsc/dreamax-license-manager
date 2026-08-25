<?php

declare(strict_types=1);

use Dreamax\LicenseManager\ReleaseTools\BuildPolicy;
use Dreamax\LicenseManager\ReleaseTools\DeterministicZip;
use Dreamax\LicenseManager\ReleaseTools\Filesystem;
use Dreamax\LicenseManager\ReleaseTools\ProcessRunner;
use Dreamax\LicenseManager\ReleaseTools\ReadmeHeaderValidator;

require_once __DIR__ . '/lib/release-tools.php';

$options = getopt('', array('commit:', 'output::'));
$requestedCommit = (string) ($options['commit'] ?? '');
if ('' === $requestedCommit) {
	throw new RuntimeException('An explicit recorded source commit is required: --commit=<hash>.');
}

$root = dirname(__DIR__);
$gitSafeDirectory = str_replace('\\', '/', $root);
$output = (string) ($options['output'] ?? ($root . '/build'));
if (1 !== preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#D', $output)) {
	$output = $root . '/' . ltrim(str_replace('\\', '/', $output), '/');
}
$resolvedOutput = str_replace('\\', '/', $output);
$allowedOutput = str_replace('\\', '/', $root . '/build');
if ($resolvedOutput !== $allowedOutput && ! str_starts_with($resolvedOutput . '/', $allowedOutput . '/')) {
	throw new RuntimeException('Release artifacts may only be written below the ignored build directory.');
}
if (! is_dir($output) && ! mkdir($output, 0777, true) && ! is_dir($output)) {
	throw new RuntimeException('The release output directory could not be created.');
}

$commit = ProcessRunner::run(array('git', '-c', 'safe.directory=' . $gitSafeDirectory, 'rev-parse', '--verify', $requestedCommit . '^{commit}'), $root);
if (1 !== preg_match('/^[a-f0-9]{40}$/D', $commit)) {
	throw new RuntimeException('The requested source is not a recorded Git commit.');
}
$epoch = (int) ProcessRunner::run(array('git', '-c', 'safe.directory=' . $gitSafeDirectory, 'show', '-s', '--format=%ct', $commit), $root);
$temporaryBase = sys_get_temp_dir();
$temporary = $temporaryBase . DIRECTORY_SEPARATOR . 'dreamax-lm-' . bin2hex(random_bytes(8));
if (! mkdir($temporary, 0777, true) && ! is_dir($temporary)) {
	throw new RuntimeException('The isolated build directory could not be created.');
}

/**
 * @return array{zip:string,sha256:string,lock_sha256:string,inventory:list<array{path:string,size:int,sha256:string}>,paths:list<string>,version:string}
 */
function build_once(string $root, string $gitSafeDirectory, string $commit, int $epoch, string $temporary, string $label): array {
	$snapshot = $temporary . DIRECTORY_SEPARATOR . 'dreamax-lm-' . $label;
	$source = $snapshot . DIRECTORY_SEPARATOR . 'source';
	$staged = $snapshot . DIRECTORY_SEPARATOR . 'stage';
	if (! mkdir($source, 0777, true) || ! mkdir($staged, 0777, true)) {
		throw new RuntimeException('An isolated build snapshot could not be created.');
	}

	$archive = $snapshot . DIRECTORY_SEPARATOR . 'source.tar';
	ProcessRunner::run(array('git', '-c', 'safe.directory=' . $gitSafeDirectory, 'archive', '--format=tar', '--output=' . $archive, $commit), $root);
	(new PharData($archive))->extractTo($source, null, true);
	unlink($archive);

	ProcessRunner::run(array('composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress', '--optimize-autoloader', '--no-scripts'), $source);
	$headers = ReadmeHeaderValidator::validate($source);
	$files = BuildPolicy::distributionFiles($source);
	foreach ($files as $relative) {
		Filesystem::copyFile($source . '/' . $relative, $staged . '/' . $relative);
	}
	foreach ($files as $relative) {
		if ('php' === strtolower(pathinfo($relative, PATHINFO_EXTENSION))) {
			ProcessRunner::run(array(PHP_BINARY, '-l', $staged . '/' . $relative), $staged);
		}
	}

	$zip = $snapshot . DIRECTORY_SEPARATOR . 'dreamax-license-manager-' . $headers['Version'] . '.zip';
	$inventory = DeterministicZip::create($staged, $files, $zip, $epoch);
	$paths = DeterministicZip::paths($zip);
	$expectedPaths = array_map(static fn(string $path): string => 'dreamax-license-manager/' . $path, $files);
	sort($expectedPaths, SORT_STRING);
	if ($paths !== $expectedPaths || ! in_array('dreamax-license-manager/dreamax-license-manager.php', $paths, true)) {
		throw new RuntimeException('The exact ZIP inventory or top-level plugin structure is invalid.');
	}

	return array(
		'zip' => $zip,
		'sha256' => hash_file('sha256', $zip),
		'lock_sha256' => hash_file('sha256', $source . '/composer.lock'),
		'inventory' => $inventory,
		'paths' => $paths,
		'version' => $headers['Version'],
	);
}

try {
	$first = build_once($root, $gitSafeDirectory, $commit, $epoch, $temporary, 'first');
	$second = build_once($root, $gitSafeDirectory, $commit, $epoch, $temporary, 'second');
	$identical = hash_equals($first['sha256'], $second['sha256']) && hash_equals($first['lock_sha256'], $second['lock_sha256']) && $first['inventory'] === $second['inventory'] && $first['paths'] === $second['paths'];
	if (! $identical) {
		throw new RuntimeException('The two clean builds differ; no release-candidate artifact was published.');
	}

	$artifactName = basename($first['zip']);
	$artifact = $output . DIRECTORY_SEPARATOR . $artifactName;
	if (! copy($first['zip'], $artifact)) {
		throw new RuntimeException('The verified release-candidate artifact could not be copied.');
	}
	$inventoryPath = $output . DIRECTORY_SEPARATOR . 'release-inventory.json';
	file_put_contents($inventoryPath, json_encode($first['inventory'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

	$composerVersion = strtok(ProcessRunner::run(array('composer', '--version'), $root), "\r\n") ?: 'unknown';
	$gitVersion = ProcessRunner::run(array('git', '--version'), $root);
	$manifest = array(
		'manifest_schema' => 'docs/release-manifest.schema.json',
		'plugin_name' => 'Dreamax License Manager',
		'plugin_version' => $first['version'],
		'artifact_filename' => $artifactName,
		'artifact_status' => 'unshipped_release_candidate_verification',
		'source_git_commit' => $commit,
		'source_state' => 'clean_recorded_commit',
		'build_command' => 'php scripts/build-release.php --commit=' . $commit . ' --output=build',
		'build_profile' => 'wordpress-org-release-candidate',
		'distribution_zip_sha256' => $first['sha256'],
		'dependency_lock_sha256' => $first['lock_sha256'],
		'dependency_license_inventory_reference' => 'docs/DEPENDENCIES.md',
		'tested_versions' => array('wordpress' => array(), 'woocommerce' => array(), 'php' => array(PHP_VERSION)),
		'build_test_utc' => gmdate('Y-m-d\TH:i:s\Z'),
		'release_gate_evidence_reference' => 'docs/release-evidence/0.3.0/',
		'build_tool_versions' => array('php' => PHP_VERSION, 'composer' => $composerVersion, 'git' => $gitVersion, 'ziparchive' => (string) phpversion('zip')),
		'inventory_filename' => basename($inventoryPath),
		'inventory_sha256' => hash_file('sha256', $inventoryPath),
		'file_count' => count($first['inventory']),
		'source_date_epoch' => $epoch,
		'reproducibility_result' => array('status' => 'identical', 'builds' => 2, 'sha256_match' => true, 'inventory_match' => true),
		'limitations' => array('No WordPress or WooCommerce runtime version was tested by this build command.', 'Manual release gates remain; this is not a production-ready release.'),
	);
	$encodedManifest = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
	if (preg_match('#(?:[A-Za-z]:[\\\\/]|/Users/|/home/|Authorization|Bearer\s|password|secret|token)#i', $encodedManifest)) {
		throw new RuntimeException('The release manifest contains a private path or secret-bearing term.');
	}
	$manifestPath = $output . DIRECTORY_SEPARATOR . 'release-manifest.json';
	file_put_contents($manifestPath, $encodedManifest);

	echo json_encode(
		array(
			'artifact_filename' => $artifactName,
			'distribution_zip_sha256' => $first['sha256'],
			'file_count' => count($first['inventory']),
			'reproducibility' => 'identical',
			'manifest_filename' => basename($manifestPath),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
	) . PHP_EOL;
} finally {
	Filesystem::removeTree($temporary, $temporaryBase);
}
