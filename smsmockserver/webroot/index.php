<?php

/**
 * smsmockserver — PHP-based mock SMS server for Playwright functional tests.
 *
 * Simulates Cellcast and 5CentSMS v5 APIs. Listens on 127.0.0.1:8083
 * (PHP built-in server) or via FrankenPHP.
 *
 * Usage:
 *   composer start
 *   # or: php -S 127.0.0.1:8083 -t public public/index.php
 *
 * URL grammar:
 *   http://127.0.0.1:8083/{provider}[/{profile}]/{api-path}
 */

declare(strict_types=1);

// Autoloader — works with or without Composer
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
} else {
    // Simple PSR-4 autoloader for development without Composer install
    spl_autoload_register(function (string $class): void {
        $prefix = 'SmsMockServer\\';
        if (!str_starts_with($class, $prefix)) return;

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

use SmsMockServer\Profile;
use SmsMockServer\Meta;
use SmsMockServer\Router;
use SmsMockServer\State;
use SmsMockServer\Store;
use SmsMockServer\Provider\Cellcast;
use SmsMockServer\Provider\FiveCentSms;

// ── Bootstrap ─────────────────────────────────────────────────────────

// Load test profiles (idempotent — each file registers via init hooks)
Profile::loadProfiles(__DIR__ . '/../../tests/functional/');
// Register built-in default profiles (no overrides — plain provider passthrough)
(function () { $p = new Profile(); $p->balance = 12345; Profile::register('5centsms', '5centsms', $p); })();
(function () { $p = new Profile(); $p->balance = 12345; Profile::register('cellcast', 'cellcast', $p); })();



// Database connection
$socket = getenv('MYSQL_UNIX_PORT') ?: '/run/mysqld/mysqld.sock';
$dsn = sprintf(
    'mysql:unix_socket=%s;dbname=jethro_functest_smsmockserver;charset=utf8mb4',
    $socket,
);
$user = 'jethro_functest_smsmockserver';
$pass = 'jethro_functest_smsmockserver';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

// Run migrations
$store = new Store($pdo);
$store->migrate();

// State machine config
$deliveryDelay = (int) (getenv('DELIVERY_DELAY') ?: 5);
$approvalDelay = (int) (getenv('APPROVAL_DELAY') ?: 0);
$state = new State(deliveryDelay: $deliveryDelay, approvalDelay: $approvalDelay);

// Meta recorder
$tmpDir = getenv('TMPDIR') ?: sys_get_temp_dir();
$meta = new Meta($tmpDir);

// Router
$router = new Router($store, $meta, $state, new Cellcast(), new FiveCentSms());
$router->dispatch();
