<?php
/**
 * Database configuration.
 *
 * Defaults to SQLite so the app can be tested locally with zero setup.
 * To use MySQL in production, set DB_DRIVER=mysql and the DB_* env vars,
 * then run database/schema.mysql.sql against your MySQL instance instead
 * of relying on the auto-bootstrap below.
 */

function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $driver = getenv('DB_DRIVER') ?: 'sqlite';

    if ($driver === 'sqlite') {
        $dbFile = __DIR__ . '/../storage/database.sqlite';
        $needsBootstrap = !file_exists($dbFile);
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        if ($needsBootstrap) {
            $schema = file_get_contents(__DIR__ . '/../database/schema.sqlite.sql');
            $pdo->exec($schema);
        }
        return $pdo;
    }

    // MySQL path (production)
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'identity_vault';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}
