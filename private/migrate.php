<?php

declare(strict_types=1);

/**
 * One-off schema migration. Run once per environment (and after each schema
 * change):
 *
 *     php private/migrate.php
 *
 * DDL intentionally lives out of the request path (public/index.php), so that
 * CREATE TABLE no longer runs on every web hit. Schema definitions come from
 * the single source of truth in private/db.php (ensureTablesExist), so there
 * is no second, divergent copy here.
 */

require __DIR__ . '/db.php';

$pdo = getDb();

// Create tables on a fresh install.
ensureTablesExist($pdo);

// Bring EXISTING installs up to date. CREATE TABLE IF NOT EXISTS never alters
// an already-present table, so columns added later must be back-filled here.
// `ADD COLUMN IF NOT EXISTS` is idempotent on MariaDB.
$migrations = [
    // Added after the first deploy; missing on installs created before it,
    // which caused "1054 Unknown column 'note'" on the audit_log INSERT.
    "ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS note TEXT DEFAULT NULL AFTER id",

    // endpoint.php (Raspi API): free-text summary + raw JSON payload.
    "ALTER TABLE heartbeat_log ADD COLUMN IF NOT EXISTS message VARCHAR(255) DEFAULT NULL AFTER source",
    "ALTER TABLE heartbeat_log ADD COLUMN IF NOT EXISTS payload TEXT DEFAULT NULL AFTER message",
];

foreach ($migrations as $sql) {
    $pdo->exec($sql);
}

echo "Schema is up to date.\n";
