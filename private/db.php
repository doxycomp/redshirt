<?php

function getDb(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    // INI_SCANNER_RAW: sonst werden Werte mit '#', ';', '"' etc. (z. B. in
    // Passwoertern) stillschweigend abgeschnitten oder umgedeutet.
    $env = parse_ini_file(__DIR__ . '/../.env', false, INI_SCANNER_RAW);

    if ($env === false) {
        throw new RuntimeException('.env file not found or unreadable');
    }

    $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    foreach ($required as $key) {
        // Bewusst kein empty(): ein legitimer Wert wie '0' darf nicht als
        // fehlend gelten.
        if (!isset($env[$key]) || $env[$key] === '') {
            throw new RuntimeException("Missing required .env key: $key");
        }
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'], $env['DB_NAME']);
    if (isset($env['DB_PORT']) && $env['DB_PORT'] !== '') {
        $dsn .= ';port=' . $env['DB_PORT'];
    }

    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Legt das Datenbankschema an, falls noch nicht vorhanden.
 *
 * Bewusst NICHT Teil von getDb(): DDL gehoert nicht in den Runtime-Pfad
 * (impliziter Commit, Vermischung von Verantwortlichkeiten). Einmalig ueber
 * private/migrate.php aufrufen.
 */
function ensureTablesExist(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_log (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
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
            audit_log_id    INT UNSIGNED DEFAULT NULL COMMENT 'FK auf audit_log bei Web-Heartbeats',
            created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_device_id (device_id),
            INDEX idx_created_at (created_at),
            CONSTRAINT fk_heartbeat_audit
                FOREIGN KEY (audit_log_id) REFERENCES audit_log(id)
                ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}
