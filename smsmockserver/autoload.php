<?php

declare(strict_types=1);

// Autoloader — works with or without Composer; shared by webroot/ and bin/.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    // Simple PSR-4 autoloader for development without Composer install
    spl_autoload_register(function (string $class): void {
        $prefix = 'SmsMockServer\\';
        if (!str_starts_with($class, $prefix)) return;

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}
