#!/usr/bin/env php
<?php

/**
 * Resolve a Jethro view URL to the file that renders it.
 *
 * Usage:
 *   ./bin/view2file.php '/?view=persons__sms'
 *   ./bin/view2file.php 'admin__sms'
 *   ./bin/view2file.php '/?view=persons&personid=1'
 *
 * Output: the relative path from JETHRO_ROOT, e.g.
 *   views/view_3_persons__5_messages.class.php
 */

if ($argc < 2 || in_array($argv[1], ['-h', '--help'])) {
    fwrite(STDERR, "Usage: view2file.php <url-or-viewname>\n");
    fwrite(STDERR, "  e.g. view2file.php '/?view=persons__sms'\n");
    fwrite(STDERR, "       view2file.php 'admin__sms'\n");
    exit($argc < 2 ? 1 : 0);
}

$input = $argv[1];

// Parse the view parameter from a path-like or URL-like string
$viewName = null;
if (str_contains($input, 'view=')) {
    $query = parse_url($input, PHP_URL_QUERY) ?? $input;
    parse_str($query, $params);
    $viewName = $params['view'] ?? null;
} else {
    $viewName = trim($input, '/? ');
}

if ($viewName === null || $viewName === '') {
    fwrite(STDERR, "Error: could not extract view name from '$input'\n");
    exit(1);
}

$jethroRoot = getenv('JETHRO_ROOT') ?: dirname(__DIR__);
$filename = resolveViewName($viewName, $jethroRoot);

if ($filename === null) {
    fwrite(STDERR, "Error: no view file found for '$viewName'\n");
    exit(1);
}

echo "views/{$filename}\n";

// ---------------------------------------------------------------------------
// Resolver — mirrors the filename-parsing logic in System_Controller's
// _scanViews() but as a pure function, no session or bootstrap needed.
// ---------------------------------------------------------------------------

function resolveViewName(string $viewName, string $baseDir): ?string
{
    $bits = explode('__', $viewName);
    $rawFilenames = glob($baseDir . '/views/*.class.php');
    natsort($rawFilenames);

    foreach ($rawFilenames as $path) {
        $filename = basename($path);
        if (count($bits) > 1) {
            // Parent-child: view_N_parent__M_child.class.php → parent__child
            if (preg_match('/^view_[0-9.]*_(' . preg_quote($bits[0], '/') . ')__[0-9]*_(' . preg_quote($bits[1], '/') . ')\.class\.php/', $filename)) {
                return $filename;
            }
        } else {
            // Top-level: view_N_name.class.php → name
            if (preg_match('/^view_[0-9]*_(' . preg_quote($bits[0], '/') . ')\.class\.php/', $filename)) {
                return $filename;
            }
        }
    }

    return null;
}
