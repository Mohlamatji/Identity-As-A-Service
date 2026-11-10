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
        $dbFile = getenv('DB_SQLITE_PATH') ?: (__DIR__ . '/../storage/database.sqlite');
        $needsBootstrap = !file_exists($dbFile);
        $pdo = new PDO('sqlite:' . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        // An empty/pre-created file counts as needing the base schema too.
        $needsBootstrap = $needsBootstrap || !$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
        if ($needsBootstrap) {
            $schema = file_get_contents(__DIR__ . '/../database/schema.sqlite.sql');
            $pdo->exec($schema);
        }
        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');
        \App\Support\Migrator::run($pdo);
        return $pdo;
    }

    // MySQL path (production)
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'identity_vault';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $pdo = new PDO("mysql:host={$host};dbname={$name};charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    \App\Support\Migrator::run($pdo);
    return $pdo;
}
