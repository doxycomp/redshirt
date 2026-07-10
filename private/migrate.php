<?php

/**
 * Einmaliges Migrations-Script: legt das Datenbankschema an.
 *
 * Aufruf per CLI:  php private/migrate.php
 */

require __DIR__ . '/db.php';

ensureTablesExist(getDb());

echo "Schema ist auf dem aktuellen Stand.\n";
