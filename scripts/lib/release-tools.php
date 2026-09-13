<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\ReleaseTools;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class DisposableEnvironmentGuard {
	/**
	 * @param array{marker?:string,database?:string,site_url?:string} $environment
	 */
	public static function assertSafe(array $environment): void {
		$marker = (string) ($environment['marker'] ?? '');
		$database = strtolower((string) ($environment['database'] ?? ''));
		$siteUrl = (string) ($environment['site_url'] ?? '');
		$host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));

		if ('DREAMAX_LM_DISPOSABLE_TEST' !== $marker) {
			throw new RuntimeException('A disposable test marker is required.');
		}
		if (1 !== preg_match('/(?:^|[_-])(?:test|testing|tmp|disposable)(?:[_-]|$)/D', $database)) {
			throw new RuntimeException('The database name is not unmistakably disposable.');
		}
		if (preg_match('/(?:prod|production|live)/D', $database)) {
			throw new RuntimeException('Production-like database names are refused.');
		}
		if (! in_array($host, array('localhost', '127.0.0.1', '::1'), true) && ! str_ends_with($host, '.test') && ! str_ends_with($host, '.invalid')) {
			throw new RuntimeException('The site URL is not local or reserved for testing.');
		}
	}
}

final class PerformanceFixture {
	private string $seed;
	private string $runId;

	public function __construct(string $seed) {
		if (1 !== preg_match('/^[A-Za-z0-9._-]{8,128}$/D', $seed)) {
			throw new InvalidArgumentException('An explicit safe seed of 8-128 characters is required.');
		}
		$this->seed = $seed;
		$this->runId = 'perf_' . substr(hash('sha256', 'run|' . $seed), 0, 20);
	}

	/** @return array{licenses:int,events:int,page_size:int,memory_limit_bytes:int} */
	public static function profile(string $profile): array {
		$profiles = array(
			'smoke' => array('licenses' => 250, 'events' => 1250, 'page_size' => 100, 'memory_limit_bytes' => 67108864),
			'full' => array('licenses' => 10000, 'events' => 50000, 'page_size' => 250, 'memory_limit_bytes' => 268435456),
		);
		if (! isset($profiles[$profile])) {
			throw new InvalidArgumentException('Unknown performance profile.');
		}
		return $profiles[$profile];
	}

	public function runId(): string {
		return $this->runId;
	}

	/**
	 * @param list<string> $existingRunIds
	 * @return array{run_id:string,seed_digest:string,licenses:list<array<string,int|string>>,events:list<array<string,int|string>>}
	 */
	public function generate(string $profile, array $existingRunIds = array()): array {
		if (in_array($this->runId, $existingRunIds, true)) {
			throw new RuntimeException('This deterministic fixture run already exists.');
		}

		$counts = self::profile($profile);
		$licenses = array();
		for ($index = 0; $index < $counts['licenses']; ++$index) {
			$licenses[] = array(
				'fixture_run_id' => $this->runId,
				'public_id' => 'lic_' . substr(hash('sha256', $this->seed . '|license|' . $index), 0, 22),
				'order_item_id' => 100000 + intdiv($index, 2),
				'quantity_slot' => 1 + ($index % 2),
				'lifecycle_status' => 0 === ($index % 3) ? 'available' : 'assigned',
			);
		}

		$events = array();
		for ($index = 0; $index < $counts['events']; ++$index) {
			$license = $licenses[$index % $counts['licenses']];
			$events[] = array(
				'fixture_run_id' => $this->runId,
				'public_id' => 'evt_' . substr(hash('sha256', $this->seed . '|event|' . $index), 0, 22),
				'license_public_id' => $license['public_id'],
				'event_type' => 0 === ($index % 2) ? 'license_created' : 'license_assigned',
				'schema_version' => 1,
			);
		}

		return array(
			'run_id' => $this->runId,
			'seed_digest' => hash('sha256', $this->seed),
			'licenses' => $licenses,
			'events' => $events,
		);
	}

	/**
	 * @param list<array<string,mixed>> $rows
	 * @return array{kept:list<array<string,mixed>>,deleted:int}
	 */
	public function cleanupOwned(array $rows): array {
		$kept = array();
		$deleted = 0;
		foreach ($rows as $row) {
			if (($row['fixture_run_id'] ?? null) === $this->runId) {
				++$deleted;
				continue;
			}
			$kept[] = $row;
		}
		return array('kept' => $kept, 'deleted' => $deleted);
	}

	/** @return array<string,int|float|string|bool> */
	public function benchmark(string $profile): array {
		$counts = self::profile($profile);
		$startMemory = memory_get_usage(true);
		$startPeak = memory_get_peak_usage(true);
		$started = hrtime(true);
		$fixture = $this->generate($profile);

		$licenseIds = array_column($fixture['licenses'], 'public_id');
		$orderSlots = array_map(
			static fn(array $license): string => $license['order_item_id'] . ':' . $license['quantity_slot'],
			$fixture['licenses']
		);
		$eventIds = array_column($fixture['events'], 'public_id');
		$pages = array_chunk($fixture['events'], $counts['page_size']);
		$absolutePeak = memory_get_peak_usage(true);
		$peakDelta = max(0, $absolutePeak - min($startMemory, $startPeak));
		$runtime = (hrtime(true) - $started) / 1_000_000_000;

		$checks = array(
			'license_identity_unique' => count($licenseIds) === count(array_unique($licenseIds)),
			'event_identity_unique' => count($eventIds) === count(array_unique($eventIds)),
			'order_slot_unique' => count($orderSlots) === count(array_unique($orderSlots)),
			'pagination_complete' => array_sum(array_map('count', $pages)) === $counts['events'],
			'bounded_memory' => $peakDelta <= $counts['memory_limit_bytes'],
		);
		if (in_array(false, $checks, true)) {
			throw new RuntimeException('A performance fixture invariant failed.');
		}

		return array(
			'profile' => $profile,
			'run_id' => $this->runId,
			'seed_digest' => $fixture['seed_digest'],
			'licenses' => count($fixture['licenses']),
			'events' => count($fixture['events']),
			'pages' => count($pages),
			'runtime_seconds' => round($runtime, 6),
			'peak_memory_bytes' => $absolutePeak,
			'peak_memory_delta_bytes' => $peakDelta,
			'memory_limit_bytes' => $counts['memory_limit_bytes'],
			'checks_passed' => true,
		);
	}
}

final class ReadmeHeaderValidator {
	/** @return array<string,string> */
	public static function validate(string $root): array {
		$main = self::read($root . '/dreamax-license-manager.php');
		$readme = self::read($root . '/readme.txt');
		$headers = self::pluginHeaders($main);
		$readmeHeaders = self::readmeHeaders($readme);
		$required = array('Plugin Name', 'Version', 'Requires at least', 'Requires PHP', 'Author', 'Author URI', 'License', 'License URI', 'Text Domain', 'Requires Plugins', 'WC requires at least', 'WC tested up to');
		foreach ($required as $field) {
			if ('' === ($headers[$field] ?? '')) {
				throw new RuntimeException('Missing required plugin header: ' . $field);
			}
		}
		if ('dreamax-license-manager' !== $headers['Text Domain'] || 'woocommerce' !== $headers['Requires Plugins']) {
			throw new RuntimeException('The plugin slug, text domain, or dependency slug is inconsistent.');
		}
		if (($readmeHeaders['Stable tag'] ?? '') !== $headers['Version']) {
			throw new RuntimeException('The numeric stable tag does not match the plugin version.');
		}
		foreach (array('Requires at least', 'Requires PHP', 'License URI') as $field) {
			if (($readmeHeaders[$field] ?? '') !== $headers[$field]) {
				throw new RuntimeException('The readme and plugin header differ for ' . $field . '.');
			}
		}
		$pluginLicense = strtolower(str_replace(array('-', '.', ' '), '', $headers['License']));
		$readmeLicense = strtolower(str_replace(array('-', '.', ' '), '', $readmeHeaders['License']));
		if (! in_array($pluginLicense, array('gpl2orlater', 'gpl20orlater', 'gplv2orlater'), true) || ! in_array($readmeLicense, array('gpl2orlater', 'gpl20orlater', 'gplv2orlater'), true)) {
			throw new RuntimeException('The plugin and readme must declare GPLv2-or-later compatibility.');
		}
		foreach (array($headers['Version'], $headers['Requires at least'], $headers['Requires PHP'], $headers['WC requires at least'], $headers['WC tested up to'], $readmeHeaders['Tested up to'] ?? '') as $numeric) {
			if (1 !== preg_match('/^\d+(?:\.\d+){1,2}$/D', $numeric)) {
				throw new RuntimeException('A version metadata value is not numeric.');
			}
		}
		foreach (array('Description', 'Installation', 'Frequently Asked Questions', 'Changelog') as $section) {
			if (! str_contains($readme, '== ' . $section . ' ==')) {
				throw new RuntimeException('Missing required readme section: ' . $section);
			}
		}
		if (preg_match('/(?:example\.invalid|TODO|TBD|placeholder)/i', $main . "\n" . $readme)) {
			throw new RuntimeException('Placeholder or invalid release metadata remains.');
		}
		return array_merge($headers, array('Stable tag' => $readmeHeaders['Stable tag'], 'Tested up to' => $readmeHeaders['Tested up to']));
	}

	/** @return array<string,string> */
	private static function pluginHeaders(string $source): array {
		$fields = array('Plugin Name', 'Plugin URI', 'Description', 'Version', 'Requires at least', 'Requires PHP', 'Author', 'Author URI', 'License', 'License URI', 'Text Domain', 'Domain Path', 'Requires Plugins', 'WC requires at least', 'WC tested up to');
		$result = array();
		foreach ($fields as $field) {
			preg_match('/^\s*\*\s*' . preg_quote($field, '/') . ':\s*(.*?)\s*$/mi', $source, $match);
			$result[$field] = trim((string) ($match[1] ?? ''));
		}
		return $result;
	}

	/** @return array<string,string> */
	private static function readmeHeaders(string $source): array {
		$result = array();
		foreach (array('Contributors', 'Tags', 'Requires at least', 'Tested up to', 'Requires PHP', 'Stable tag', 'License', 'License URI') as $field) {
			preg_match('/^' . preg_quote($field, '/') . ':\s*(.*?)\s*$/mi', $source, $match);
			$result[$field] = trim((string) ($match[1] ?? ''));
			if ('' === $result[$field]) {
				throw new RuntimeException('Missing readme header: ' . $field);
			}
		}
		return $result;
	}

	private static function read(string $path): string {
		$value = file_get_contents($path);
		if (false === $value) {
			throw new RuntimeException('A required release file could not be read.');
		}
		return $value;
	}
}

final class BuildPolicy {
	private const REQUIRED = array(
		'LICENSE',
		'docs/BUILDING.md',
		'docs/DEPENDENCIES.md',
		'dreamax-license-manager.php',
		'readme.txt',
		'uninstall.php',
	);

	/** @return list<string> */
	public static function distributionFiles(string $source): array {
		$files = array();
		foreach (self::REQUIRED as $relative) {
			if (! is_file($source . '/' . $relative)) {
				throw new RuntimeException('Required distribution file is missing: ' . $relative);
			}
			$files[] = $relative;
		}
		foreach (array('src', 'assets', 'languages') as $directory) {
			$absolute = $source . '/' . $directory;
			if (! is_dir($absolute)) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS));
			foreach ($iterator as $item) {
				if ($item instanceof \SplFileInfo && $item->isFile() && ! $item->isLink()) {
					$files[] = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
				}
			}
		}
		$files = array_values(array_unique($files));
		sort($files, SORT_STRING);
		foreach ($files as $file) {
			self::assertAllowed($file);
		}
		return $files;
	}

	public static function assertAllowed(string $relative): void {
		$path = str_replace('\\', '/', $relative);
		if (str_starts_with($path, '/') || str_contains($path, '../') || preg_match('/^[A-Za-z]:/D', $path)) {
			throw new RuntimeException('Private or escaping paths are prohibited.');
		}
		$prohibited = '#(?:^|/)(?:\.git|\.github|\.idea|\.vscode|vendor|tests?|fixtures?|examples?|scripts?|build|dist|cache|caches|logs?|node_modules)(?:/|$)|(?:^|/)(?:\.env(?:\..*)?|php\.ini|phpunit(?:\.xml(?:\.dist)?)?|phpstan\.neon|phpcs\.xml\.dist|composer\.(?:json|lock)|credentials?\.(?:json|ya?ml|ini|txt)|tokens?\.(?:json|ya?ml|ini|txt)|secrets?\.(?:json|ya?ml|ini|txt))$|\.(?:zip|tar|gz|log|pem|key|p12|pfx)$#i';
		if (preg_match($prohibited, $path)) {
			throw new RuntimeException('A prohibited distribution artifact was selected.');
		}
		if (str_starts_with($path, 'docs/') && ! in_array($path, array('docs/BUILDING.md', 'docs/DEPENDENCIES.md'), true)) {
			throw new RuntimeException('Development-only documentation is excluded.');
		}
	}
}

final class DeterministicZip {
	/**
	 * @param list<string> $files
	 * @return list<array{path:string,size:int,sha256:string}>
	 */
	public static function create(string $source, array $files, string $target, int $epoch): array {
		if ($epoch < 315532800) {
			throw new InvalidArgumentException('SOURCE_DATE_EPOCH must be at least 1980-01-01 for ZIP metadata.');
		}
		$zip = new ZipArchive();
		if (true !== $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
			throw new RuntimeException('The release-candidate ZIP could not be created.');
		}
		$root = 'dreamax-license-manager/';
		$zip->addEmptyDir($root);
		$zip->setMtimeName($root, $epoch);
		$zip->setExternalAttributesName($root, ZipArchive::OPSYS_UNIX, 040755 << 16);
		$directories = array();
		$inventory = array();
		foreach ($files as $relative) {
			BuildPolicy::assertAllowed($relative);
			$entry = $root . $relative;
			$directory = dirname($entry);
			while ('.' !== $directory && 'dreamax-license-manager' !== $directory) {
				$directories[$directory . '/'] = true;
				$directory = dirname($directory);
			}
		}
		ksort($directories, SORT_STRING);
		foreach (array_keys($directories) as $directory) {
			$zip->addEmptyDir($directory);
			$zip->setMtimeName($directory, $epoch);
			$zip->setExternalAttributesName($directory, ZipArchive::OPSYS_UNIX, 040755 << 16);
		}
		foreach ($files as $relative) {
			$absolute = $source . '/' . $relative;
			$content = file_get_contents($absolute);
			if (false === $content) {
				$zip->close();
				throw new RuntimeException('A selected distribution file could not be read.');
			}
			$entry = $root . $relative;
			$zip->addFromString($entry, $content);
			$zip->setMtimeName($entry, $epoch);
			$zip->setExternalAttributesName($entry, ZipArchive::OPSYS_UNIX, 0100644 << 16);
			$inventory[] = array('path' => $entry, 'size' => strlen($content), 'sha256' => hash('sha256', $content));
		}
		if (! $zip->close()) {
			throw new RuntimeException('The release-candidate ZIP could not be finalized.');
		}
		return $inventory;
	}

	/** @return list<string> */
	public static function paths(string $archive): array {
		$zip = new ZipArchive();
		if (true !== $zip->open($archive)) {
			throw new RuntimeException('The release-candidate ZIP could not be inspected.');
		}
		$paths = array();
		for ($index = 0; $index < $zip->numFiles; ++$index) {
			$name = $zip->getNameIndex($index);
			if (false !== $name && ! str_ends_with($name, '/')) {
				$paths[] = $name;
			}
		}
		$zip->close();
		sort($paths, SORT_STRING);
		return $paths;
	}
}

final class ProcessRunner {
	/** @param list<string> $command */
	public static function run(array $command, string $workingDirectory): string {
		if ('Windows' === PHP_OS_FAMILY && 'composer' === strtolower($command[0])) {
			foreach ($command as $argument) {
				if (1 !== preg_match('/^[A-Za-z0-9._:=\/-]+$/D', $argument)) {
					throw new RuntimeException('An unsafe Composer build argument was rejected.');
				}
			}
			$processCommand = 'cmd.exe /d /c ' . implode(' ', $command);
		} else {
			$processCommand = implode(' ', array_map('escapeshellarg', $command));
		}
		$spec = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
		$process = proc_open($processCommand, $spec, $pipes, $workingDirectory);
		if (! is_resource($process)) {
			throw new RuntimeException('A required build process could not start.');
		}
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exit = proc_close($process);
		if (0 !== $exit) {
			throw new RuntimeException('A required build process failed: ' . basename($command[0]) . "\n" . trim((string) $stderr));
		}
		return trim((string) $stdout);
	}
}

final class Filesystem {
	public static function copyFile(string $from, string $to): void {
		$directory = dirname($to);
		if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
			throw new RuntimeException('A build directory could not be created.');
		}
		if (! copy($from, $to)) {
			throw new RuntimeException('A build file could not be copied.');
		}
	}

	public static function removeTree(string $path, string $requiredPrefix): void {
		$normalized = str_replace('\\', '/', $path);
		$prefix = rtrim(str_replace('\\', '/', $requiredPrefix), '/') . '/';
		if (! str_starts_with($normalized . '/', $prefix) || ! str_contains(basename($normalized), 'dreamax-lm-')) {
			throw new RuntimeException('Refusing unsafe temporary cleanup.');
		}
		if (! is_dir($path)) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $item) {
			if ($item->isDir() && ! $item->isLink()) {
				rmdir($item->getPathname());
			} else {
				unlink($item->getPathname());
			}
		}
		rmdir($path);
	}
}
