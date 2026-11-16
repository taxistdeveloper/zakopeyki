#!/usr/bin/env php
<?php

declare(strict_types=1);

// Печатает SQL-миграцию модуля конкурсов из единого источника DDL.
// Использование: php bin/contests_schema_sql.php > database/migrate_contests.sql
require __DIR__ . '/bootstrap.php';

echo \App\Services\Contest\ContestSchema::sqlFile();
