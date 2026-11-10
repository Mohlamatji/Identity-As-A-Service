<?php

namespace App\Support;

use PDO;

/**
 * Idempotent schema upgrader. Runs on boot (gated by schema_meta.version so
 * it costs one tiny SELECT per request once up to date) and layers the v2
 * tables/columns on top of whatever base schema the database was created
 * from - fresh SQLite installs, existing SQLite files, or MySQL databases
 * created from database/schema.mysql.sql. Safe to run repeatedly.
 */
final class Migrator
{
    public const VERSION = 2;

    public static function run(PDO $pdo): void
    {
        if (self::currentVersion($pdo) >= self::VERSION) {
            return;
        }

        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $tail = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

        $pdo->exec("CREATE TABLE IF NOT EXISTS api_clients (
            id $pk,
            name VARCHAR(120) NOT NULL,
            key_prefix VARCHAR(24) NOT NULL,
            key_hash VARCHAR(64) NOT NULL UNIQUE,
            scopes VARCHAR(255) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'active',
            rate_limit_per_min INTEGER NOT NULL DEFAULT 120,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS challenges (
            id VARCHAR(64) PRIMARY KEY,
            user_id INTEGER NULL,
            actions VARCHAR(255) NOT NULL,
            amount_cents INTEGER NULL,
            payee VARCHAR(120) NULL,
            client_id VARCHAR(64) NULL,
            ip VARCHAR(64) NULL,
            issued_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            consumed_at INTEGER NULL
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS verification_sessions (
            id $pk,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            user_id INTEGER NOT NULL,
            challenge_id VARCHAR(64) NULL,
            liveness_passed INTEGER NOT NULL,
            liveness_mode VARCHAR(16) NOT NULL,
            liveness_score DOUBLE NULL,
            liveness_reason VARCHAR(500) NULL,
            vault_matched INTEGER NOT NULL,
            vault_first INTEGER NOT NULL,
            vault_confidence DOUBLE NULL,
            vault_distance DOUBLE NULL,
            vault_reason VARCHAR(500) NULL,
            signals TEXT NULL,
            behavior_json TEXT NULL,
            device_fingerprint VARCHAR(255) NULL,
            amount_cents INTEGER NULL,
            payee VARCHAR(120) NULL,
            ip VARCHAR(64) NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            consumed_at INTEGER NULL
        )$tail");
        self::index($pdo, 'idx_vs_user', 'verification_sessions', 'user_id, created_at');

        $pdo->exec("CREATE TABLE IF NOT EXISTS idempotency_keys (
            id $pk,
            client_id VARCHAR(64) NOT NULL,
            idem_key VARCHAR(128) NOT NULL,
            request_hash VARCHAR(64) NOT NULL,
            status_code INTEGER NOT NULL DEFAULT 0,
            response_json MEDIUMTEXT NULL,
            created_at INTEGER NOT NULL,
            UNIQUE (client_id, idem_key)
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS paypal_orders (
            id $pk,
            transaction_id INTEGER NOT NULL,
            paypal_order_id VARCHAR(64) NOT NULL UNIQUE,
            decision_id VARCHAR(64) NOT NULL,
            mode VARCHAR(16) NOT NULL,
            status VARCHAR(32) NOT NULL,
            amount_value VARCHAR(32) NOT NULL,
            currency VARCHAR(8) NOT NULL,
            capture_id VARCHAR(64) NULL,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )$tail");

        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (k VARCHAR(32) PRIMARY KEY, v VARCHAR(32) NOT NULL)$tail");

        // Additive columns on pre-existing tables.
        $columns = [
            ['transactions', 'decision_id', 'VARCHAR(64) NULL'],
            ['transactions', 'factors_json', 'TEXT NULL'],
            ['transactions', 'required_action', 'VARCHAR(32) NULL'],
            ['transactions', 'engine_version', 'VARCHAR(32) NULL'],
            ['transactions', 'receipt', 'TEXT NULL'],
            ['transactions', 'client_id', 'VARCHAR(64) NULL'],
            ['transactions', 'verification_session_id', 'INTEGER NULL'],
            ['transactions', 'payee', 'VARCHAR(120) NULL'],
            ['transactions', 'ai_analysis', 'TEXT NULL'],
            ['behavior_profiles', 'typing_mean_ms', 'DOUBLE NULL'],
            ['behavior_profiles', 'typing_std_ms', 'DOUBLE NULL'],
            ['behavior_profiles', 'typing_samples', 'INTEGER NOT NULL DEFAULT 0'],
            ['audit_log', 'details', 'TEXT NULL'],
            ['audit_log', 'prev_hash', 'VARCHAR(64) NULL'],
            ['audit_log', 'entry_hash', 'VARCHAR(64) NULL'],
        ];
        foreach ($columns as [$table, $column, $definition]) {
            if (!self::columnExists($pdo, $table, $column)) {
                $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            }
        }
        self::index($pdo, 'idx_tx_decision', 'transactions', 'decision_id');

        $pdo->exec("DELETE FROM schema_meta WHERE k = 'version'");
        $stmt = $pdo->prepare("INSERT INTO schema_meta (k, v) VALUES ('version', ?)");
        $stmt->execute([(string) self::VERSION]);
    }

    private static function currentVersion(PDO $pdo): int
    {
        try {
            $v = $pdo->query("SELECT v FROM schema_meta WHERE k = 'version'")->fetchColumn();
            return $v === false ? 0 : (int) $v;
        } catch (\PDOException) {
            return 0; // table doesn't exist yet
        }
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM $table LIKE ?");
            $stmt->execute([$column]);
            return (bool) $stmt->fetch();
        }
        foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if ($col['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    private static function index(PDO $pdo, string $name, string $table, string $columns): void
    {
        try {
            $pdo->exec("CREATE INDEX $name ON $table ($columns)");
        } catch (\PDOException) {
            // already exists (MySQL has no CREATE INDEX IF NOT EXISTS)
        }
    }
}
