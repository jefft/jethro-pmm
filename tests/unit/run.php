#!/usr/bin/env php
<?php

/**
 * Unit test runner.
 *
 * Usage:
 *   php tests/unit/run.php                      # run all tests
 *   php tests/unit/run.php sms/cellcast         # run a subset
 *
 * Test files go in tests/unit/<area>/ and use the Test\ namespace helpers.
 * They are discovered by scanning for test_*.php files.
 *
 * Process isolation: all test files normally share one PHP process, so
 * constants defined by one file are visible to all others.  A test file
 * that needs its own constant table (e.g. to define SMS_SENDER, which
 * would break other tests) can declare `@isolated-process` in its header
 * docblock; the runner executes it in a child `php tests/unit/run.php
 * --isolated <file>` process after the in-process tests, and folds the
 * child's pass/fail counts into the grand total.
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

// If a vendor autoloader exists, load it.  Otherwise the jethro codebase
// uses require_once-style includes — tests just include what they need.
$autoloadPaths = [
	__DIR__ . '/../../vendor/autoload.php',
];
foreach ($autoloadPaths as $path) {
	if (file_exists($path)) {
		require_once $path;
		break;
	}
}

// Load helpers — must come before any test file
require_once __DIR__ . '/_entries.php';
require_once __DIR__ . '/_entries.php';
require_once __DIR__ . '/helpers.php';

// ---------------------------------------------------------------------------
// Child mode: run a single @isolated-process file in this (fresh) process
// ---------------------------------------------------------------------------

if (($argv[1] ?? null) === '--isolated') {
	$file = $argv[2] ?? '';
	if ($file === '' || !is_file($file)) {
		fwrite(STDERR, "--isolated requires a test file path\n");
		exit(2);
	}
	require_once $file;
	exit(\Test\run_all());
}

// ---------------------------------------------------------------------------
// Discover test files
// ---------------------------------------------------------------------------

$filter = $argv[1] ?? null;
$root = realpath(__DIR__ . '/../..');

$testFiles = [];
$isolatedFiles = [];
$dirs = [
	__DIR__ . '/../sms',
	$root . '/jethro-sms/tests',
];

// Flat test_*.php files directly in tests/unit/ (unit tests for core classes)
foreach (glob(__DIR__ . '/test_*.php') as $filepath) {
	$filename = basename($filepath);
	$relativePath = str_replace($root . '/', '', $filepath);
	if ($filter !== null && !str_contains($relativePath, $filter)) continue;
	$head = (string) file_get_contents($filepath, false, null, 0, 2048);
	if (str_contains($head, '@isolated-process')) {
		$isolatedFiles[] = $filepath;
	} else {
		$testFiles[] = $filepath;
	}
}

foreach ($dirs as $dir) {
	if (!is_dir($dir)) continue;
	$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
	foreach ($it as $file) {
		if ($file->getExtension() !== 'php') continue;
		$filename = $file->getBasename();
		if (!str_starts_with($filename, 'test_')) continue;

		$relativePath = str_replace($root . '/', '', $file->getPathname());

		if ($filter !== null && !str_contains($relativePath, $filter)) {
			continue;
		}

		// @isolated-process in the header docblock → run in a child process
		// with a clean constant table, after the in-process tests.
		$head = (string) file_get_contents($file->getPathname(), false, null, 0, 2048);
		if (str_contains($head, '@isolated-process')) {
			$isolatedFiles[] = $file->getPathname();
			continue;
		}

		$testFiles[] = $file->getPathname();
	}
}

if ($testFiles === [] && $isolatedFiles === []) {
	echo $filter !== null
		? "No test files matching '$filter'\n"
		: "No test files found.\n";
	exit(1);
}

// ---------------------------------------------------------------------------
// Load test files
// ---------------------------------------------------------------------------

echo "Loading test files:\n";
foreach ($testFiles as $path) {
	echo '  ' . str_replace($root . '/', '', $path) . "\n";
	require_once $path;
}
echo "\n";

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

$exitCode = $testFiles !== [] ? \Test\run_all() : 0;

// Run each @isolated-process file in its own child process and fold its
// pass/fail counts into a grand total.
if ($isolatedFiles !== []) {
	$isoPassed = $isoFailed = 0;
	foreach ($isolatedFiles as $path) {
		$rel = str_replace($root . '/', '', $path);
		echo "\nIsolated process: $rel\n";
		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
			. ' --isolated ' . escapeshellarg($path) . ' 2>&1';
		$output = [];
		$status = 0;
		exec($cmd, $output, $status);
		echo '  ' . implode("\n  ", $output) . "\n";
		// Fold the child's "N passed, M failed, T total" tail into our counts.
		if (preg_match('/(\d+) passed, (\d+) failed, \d+ total/', implode("\n", $output), $m)) {
			$isoPassed += (int) $m[1];
			$isoFailed += (int) $m[2];
		} else {
			// Child crashed before printing a summary — count as a failure.
			$isoFailed++;
		}
		if ($status !== 0) {
			$exitCode = 1;
		}
	}
	echo "\n=== Grand total (including isolated processes) ===\n";
	$grandPassed = \Test\Registry::$passCount + $isoPassed;
	$grandFailed = count(\Test\Registry::$failures) + $isoFailed;
	echo $grandPassed . ' passed, ' . $grandFailed . ' failed, ' . ($grandPassed + $grandFailed) . ' total' . "\n";
	if ($isoFailed > 0) {
		$exitCode = 1;
	}
}

exit($exitCode);
