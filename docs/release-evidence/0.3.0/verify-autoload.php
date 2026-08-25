<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
$src  = $root . '/src';

/**
 * @return array{class: string, relative_path: string}
 */
function production_class(string $root, string $file): array {
	$source    = (string) file_get_contents($file);
	$tokens    = token_get_all($source);
	$namespace = '';
	$class     = '';
	$count     = count($tokens);

	for ($index = 0; $index < $count; ++$index) {
		$token = $tokens[$index];
		if (! is_array($token)) {
			continue;
		}

		if (T_NAMESPACE === $token[0]) {
			for (++$index; $index < $count; ++$index) {
				$part = $tokens[$index];
				if (';' === $part || '{' === $part) {
					break;
				}
				if (is_array($part) && in_array($part[0], array(T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR), true)) {
					$namespace .= $part[1];
				}
			}
			continue;
		}

		if (T_CLASS !== $token[0]) {
			continue;
		}

		for (++$index; $index < $count; ++$index) {
			$part = $tokens[$index];
			if (is_array($part) && T_STRING === $part[0]) {
				$class = $part[1];
				break 2;
			}
		}
	}

	if ('' === $namespace || '' === $class) {
		throw new RuntimeException('Missing namespace or class declaration: ' . $file);
	}

	$relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
	return array('class' => $namespace . '\\' . $class, 'relative_path' => $relative);
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
$classes  = array();
foreach ($iterator as $item) {
	if (! $item instanceof SplFileInfo || 'php' !== strtolower($item->getExtension())) {
		continue;
	}
	$entry                    = production_class($root, $item->getPathname());
	$classes[$entry['class']] = $entry['relative_path'];
}
ksort($classes, SORT_STRING);

$errors     = array();
$folded     = array();
$class_fold = array();
foreach ($classes as $class => $relative) {
	$class_key = strtolower($class);
	if (isset($class_fold[$class_key])) {
		$errors[] = 'Duplicate class (case-insensitive): ' . $class_fold[$class_key] . ' / ' . $class;
	}
	$class_fold[$class_key] = $class;

	$path_key = strtolower($relative);
	if (isset($folded[$path_key])) {
		$errors[] = 'Case-only path collision: ' . $folded[$path_key] . ' / ' . $relative;
	}
	$folded[$path_key] = $relative;

	$prefix = 'Dreamax\\LicenseManager\\';
	if (0 !== strpos($class, $prefix)) {
		$errors[] = 'Unexpected namespace: ' . $class;
		continue;
	}
	$parts         = explode('\\', substr($class, strlen($prefix)));
	$class_file    = 'class-' . strtolower((string) array_pop($parts)) . '.php';
	$expected_path = 'src/' . ($parts ? implode('/', $parts) . '/' : '') . $class_file;
	if ($expected_path !== $relative) {
		$errors[] = 'Runtime path mismatch: ' . $class . ' => ' . $expected_path . ' (actual ' . $relative . ')';
	}
	if (false !== strpos($expected_path, '\\') || preg_match('/^[A-Za-z]:/', $expected_path)) {
		$errors[] = 'Non-portable runtime path: ' . $expected_path;
	}
}

/** @var array<string, string> $composer_map */
$composer_map = require $root . '/vendor/composer/autoload_classmap.php';
foreach ($classes as $class => $relative) {
	if (! isset($composer_map[$class])) {
		$errors[] = 'Missing Composer classmap entry: ' . $class;
		continue;
	}
	$mapped = str_replace('\\', '/', (string) realpath($composer_map[$class]));
	$actual = str_replace('\\', '/', (string) realpath($root . '/' . $relative));
	if ($mapped !== $actual) {
		$errors[] = 'Composer classmap mismatch: ' . $class;
	}
}

if (! defined('DREAMAX_LM_DIR')) {
	define('DREAMAX_LM_DIR', str_replace('\\', '/', $root) . '/');
}
require_once $root . '/src/Support/class-autoloader.php';
Dreamax\LicenseManager\Support\Autoloader::register();
foreach (array_keys($classes) as $class) {
	if (! class_exists($class, true)) {
		$errors[] = 'Runtime autoload failed: ' . $class;
	}
}

if (49 !== count($classes)) {
	$errors[] = 'Expected 49 production classes; found ' . count($classes);
}

if ($errors) {
	fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
	exit(1);
}

echo 'PASS: 49 unique production classes; exact case-sensitive runtime paths; Composer classmap and runtime autoload both resolved every class; no case-only or non-portable path collision.' . PHP_EOL;
