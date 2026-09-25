<?php

/**
 * Minimal autoloader mapping the App\ namespace to /app, so the project can
 * be run and tested with zero Composer setup. If you later add Composer
 * dependencies (e.g. an official bureau SDK), replace this with
 * vendor/autoload.php and a proper composer.json psr-4 mapping.
 */
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/app/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
