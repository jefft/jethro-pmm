<?php

/**
 * smsmockserver — a mock SMS provider server for functional tests.
 *
 * Simulates the Cellcast, 5CentSMS v5 and MobileMessage APIs.
 *
 * Usage (standalone):
 *   SMSMOCK_DSN='mysql:host=127.0.0.1;dbname=smsmock;charset=utf8mb4' \
 *   SMSMOCK_DB_USER=… SMSMOCK_DB_PASS=… php -S 127.0.0.1:8083 -t webroot webroot/index.php
 * or via FrankenPHP/nginx (the front controller needs PATH_INFO set).
 *
 * Environment:
 *   SMSMOCK_PROFILES        PATH_SEPARATOR-separated profile directories
 *                           (default: ./profiles, then the Jethro functest tree)
 *   SMSMOCK_PROFILE_PREFIX  profile URL prefix (default: profiles)
 *   SMSMOCK_DSN             PDO DSN; default is the devbox MariaDB socket
 *                           database jethro_functest_smsmockserver
 *   SMSMOCK_DB_USER/PASS    database credentials (default: jethro/jethro)
 *   DELIVERY_DELAY, APPROVAL_DELAY  state-machine timings (seconds)
 *
 * URL grammar:
 *   http://127.0.0.1:8083/{provider}/{api-path}
 *   http://127.0.0.1:8083/profiles/{profile}/{api-path}
 */

declare(strict_types=1);

// Autoloader — works with or without Composer
require __DIR__ . '/../autoload.php';

use SmsMockServer\Profile;
use SmsMockServer\Meta;
use SmsMockServer\Router;
use SmsMockServer\State;
use SmsMockServer\Store;
use SmsMockServer\Provider\Cellcast;
use SmsMockServer\Provider\FiveCentSms;
use SmsMockServer\Provider\MobileMessage;

// ── Bootstrap ─────────────────────────────────────────────────────────

// Profiles (idempotent — each file registers via init hooks). Defaults cover a
// vendored copy (./profiles) and the Jethro functional-test profiles.
$profileDirs = getenv('SMSMOCK_PROFILES');
$profileDirs = $profileDirs !== false && $profileDirs !== ''
    ? explode(PATH_SEPARATOR, $profileDirs)
    : array_values(array_filter([__DIR__ . '/../profiles', __DIR__ . '/../../tests/functional'], 'is_dir'));
foreach ($profileDirs as $profileDir) {
    Profile::loadProfiles($profileDir);
}
// Register built-in default profiles (no overrides — plain provider passthrough)
(function () { $p = new Profile(); $p->balance = 12345; Profile::register('5centsms', '5centsms', $p); })();
(function () { $p = new Profile(); $p->balance = 12345; Profile::register('cellcast', 'cellcast', $p); })();
(function () { $p = new Profile(); $p->balance = 12345; Profile::register('mobilemessage', 'mobilemessage', $p); })();

// Database connection: SMSMOCK_DSN, else the devbox default. Socket-only
// there (mariadb TCP is disabled): MYSQL_UNIX_PORT is passed through to this
// pool by devbox.d/php/php-fpm.conf.
$dsn = getenv('SMSMOCK_DSN');
if ($dsn === false || $dsn === '') {
    $dsn = 'mysql:unix_socket=' . getenv('MYSQL_UNIX_PORT') . ';dbname=jethro_functest_smsmockserver;charset=utf8mb4';
}
$user = getenv('SMSMOCK_DB_USER') ?: 'jethro';
$pass = getenv('SMSMOCK_DB_PASS') ?: 'jethro';

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
$router = new Router($store, $meta, $state, new Cellcast(), new FiveCentSms(), new MobileMessage());
$router->dispatch();
