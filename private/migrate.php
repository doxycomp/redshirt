<?php

declare(strict_types=1);

/**
 * One-off schema migration. Run once per environment:
 *
 *     php private/migrate.php
 *
 * The DDL intentionally lives here and NOT in public/index.php, so that
 * CREATE TABLE no longer runs on every web request.
 *
 * NOTE: the connection bootstrap is duplicated from public/index.php for now.
 * Once a shared getDb() helper (private/db.php) lands, both should use it.
 */

$env = parse_ini_file(__DIR__ . '/../.env', false, INI_SCANNER_RAW);
if ($env === false) {
    fwrite(STDERR, ".env not found or unreadable\n");
    exit(1);
}

$required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
foreach ($required as $key) {
    if (!isset($env[$key]) || $env[$key] === '') {
        fwrite(STDERR, "Missing required .env key: $key\n");
        exit(1);
    }
}

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_NAME']);
if (isset($env['DB_PORT']) && $env['DB_PORT'] !== '') {
    $dsn .= ';port=' . $env['DB_PORT'];
}

$pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec("
    CREATE TABLE IF NOT EXISTS audit_log (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        note            TEXT         DEFAULT NULL,
        ip              VARCHAR(45)  NOT NULL DEFAULT '',
        user_agent      VARCHAR(512) NOT NULL DEFAULT '',
        resolution      VARCHAR(20)  DEFAULT NULL,
        timezone        VARCHAR(64)  DEFAULT NULL,
        session_id      VARCHAR(64)  DEFAULT NULL,
        last_heartbeat  DATETIME     DEFAULT NULL,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at),
        INDEX idx_session_id (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS heartbeat_log (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        device_id       VARCHAR(128) NOT NULL,
        hostname        VARCHAR(255) DEFAULT NULL,
        ip              VARCHAR(45)  NOT NULL DEFAULT '',
        source          ENUM('web','api') NOT NULL DEFAULT 'api',
        audit_log_id    INT UNSIGNED DEFAULT NULL,
        created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_device_id (device_id),
        INDEX idx_created_at (created_at),
        CONSTRAINT fk_heartbeat_audit
            FOREIGN KEY (audit_log_id) REFERENCES audit_log(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

echo "Schema is up to date.\n";
