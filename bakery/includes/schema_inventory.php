<?php
/**
 * Canonical schema inventory for Staging vs Live comparison.
 *
 * Structure only: tables/columns/indexes and the schema_migrations ledger.
 * Never includes row counts, AUTO_INCREMENT values, or table data.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_schema_inventory_normalize_type(string $type): string
{
    $type = strtolower(trim($type));
    $type = (string)preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type);

    // MariaDB exposes JSON columns as LONGTEXT while MySQL reports JSON. The
    // application stores JSON text in these fields, so this engine-level alias
    // is not a Staging → Live schema conflict.
    return $type === 'json' ? 'longtext' : $type;
}

function bakery_schema_inventory_normalize_column_definition(string $definition): string
{
    $parts = explode('|', $definition, 3);
    $parts[0] = bakery_schema_inventory_normalize_type((string)($parts[0] ?? ''));
    return implode('|', $parts);
}

/** @return list<string>|null */
function bakery_schema_inventory_enum_values(string $type): ?array
{
    $type = strtolower(trim($type));
    if (!preg_match('/^enum\((.*)\)$/s', $type, $matched)) {
        return null;
    }
    if (!preg_match_all("/'((?:\\\\'|[^'])*)'/", $matched[1], $inner)) {
        return [];
    }
    $values = [];
    foreach ($inner[1] as $value) {
        $values[] = stripcslashes((string)$value);
    }
    return $values;
}

/**
 * Hosted Live migrations may widen an ENUM by appending values. That is
 * Live-behind, not a type clash, as long as Live's values stay the prefix.
 */
function bakery_schema_inventory_is_additive_enum_widen(string $stagingDefinition, string $liveDefinition): bool
{
    $stagingParts = explode('|', bakery_schema_inventory_normalize_column_definition($stagingDefinition), 3);
    $liveParts = explode('|', bakery_schema_inventory_normalize_column_definition($liveDefinition), 3);
    if (($stagingParts[1] ?? '') !== ($liveParts[1] ?? '') || ($stagingParts[2] ?? '') !== ($liveParts[2] ?? '')) {
        return false;
    }
    $stagingEnum = bakery_schema_inventory_enum_values((string)($stagingParts[0] ?? ''));
    $liveEnum = bakery_schema_inventory_enum_values((string)($liveParts[0] ?? ''));
    if ($stagingEnum === null || $liveEnum === null || $liveEnum === [] || $stagingEnum === $liveEnum) {
        return false;
    }
    if (count($liveEnum) >= count($stagingEnum)) {
        return false;
    }
    return array_slice($stagingEnum, 0, count($liveEnum)) === $liveEnum;
}

function bakery_schema_inventory_from_pdo(PDO $db): array
{
    $database = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    $baseTables = [];
    $tableStmt = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    );
    foreach ($tableStmt as $row) {
        $baseTables[(string)$row['TABLE_NAME']] = true;
    }

    $columns = [];
    $stmt = $db->query(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, COLUMN_NAME'
    );
    foreach ($stmt as $row) {
        $table = (string)$row['TABLE_NAME'];
        if (!isset($baseTables[$table])) {
            continue;
        }
        $extra = strtolower((string)($row['EXTRA'] ?? ''));
        $keep = [];
        if (strpos($extra, 'auto_increment') !== false) {
            $keep[] = 'auto_increment';
        }
        $key = $table . '.' . (string)$row['COLUMN_NAME'];
        $columns[$key] = bakery_schema_inventory_normalize_type((string)$row['COLUMN_TYPE'])
            . '|' . strtoupper((string)$row['IS_NULLABLE'])
            . '|' . implode(',', $keep);
    }

    $indexRows = $db->query(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
         ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
    );
    $grouped = [];
    foreach ($indexRows as $row) {
        $table = (string)$row['TABLE_NAME'];
        if (!isset($baseTables[$table])) {
            continue;
        }
        $key = $table . '.' . (string)$row['INDEX_NAME'];
        if (!isset($grouped[$key])) {
            $grouped[$key] = [
                'unique' => ((int)$row['NON_UNIQUE'] === 0) ? '1' : '0',
                'cols' => [],
            ];
        }
        $grouped[$key]['cols'][] = (string)$row['COLUMN_NAME'];
    }
    $indexes = [];
    foreach ($grouped as $key => $group) {
        $indexes[$key] = $group['unique'] . ':' . implode(',', $group['cols']);
    }

    $migrations = [];
    try {
        $ids = $db->query('SELECT id FROM schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids ?: [] as $id) {
            $migrations[] = (string)$id;
        }
    } catch (Throwable $e) {
        $migrations = [];
    }

    ksort($columns, SORT_STRING);
    ksort($indexes, SORT_STRING);
    $canonical = json_encode(['columns' => $columns, 'indexes' => $indexes], JSON_UNESCAPED_SLASHES);
    return [
        'format' => 1,
        'captured_at' => gmdate('c'),
        'database' => $database,
        'hash' => hash('sha256', (string)$canonical),
        'column_count' => count($columns),
        'index_count' => count($indexes),
        'migration_ids' => $migrations,
        'columns' => $columns,
        'indexes' => $indexes,
    ];
}

function bakery_schema_inventory_is_view_table(string $table): bool
{
    return strpos($table, 'v_') === 0;
}

function bakery_schema_inventory_strip_views(array $inventory): array
{
    $columns = [];
    foreach ((array)($inventory['columns'] ?? []) as $key => $definition) {
        $table = strpos((string)$key, '.') === false ? (string)$key : explode('.', (string)$key, 2)[0];
        if (bakery_schema_inventory_is_view_table($table)) {
            continue;
        }
        $columns[(string)$key] = bakery_schema_inventory_normalize_column_definition((string)$definition);
    }
    $indexes = [];
    foreach ((array)($inventory['indexes'] ?? []) as $key => $definition) {
        $table = strpos((string)$key, '.') === false ? (string)$key : explode('.', (string)$key, 2)[0];
        if (bakery_schema_inventory_is_view_table($table)) {
            continue;
        }
        $indexes[(string)$key] = $definition;
    }
    ksort($columns, SORT_STRING);
    ksort($indexes, SORT_STRING);
    $canonical = json_encode(['columns' => $columns, 'indexes' => $indexes], JSON_UNESCAPED_SLASHES);
    $inventory['columns'] = $columns;
    $inventory['indexes'] = $indexes;
    $inventory['column_count'] = count($columns);
    $inventory['index_count'] = count($indexes);
    $inventory['hash'] = hash('sha256', (string)$canonical);
    return $inventory;
}

function bakery_schema_inventory_public(array $inventory): array
{
    $inventory = bakery_schema_inventory_strip_views($inventory);
    return [
        'format' => (int)($inventory['format'] ?? 1),
        'captured_at' => (string)($inventory['captured_at'] ?? ''),
        'database' => (string)($inventory['database'] ?? ''),
        'hash' => (string)($inventory['hash'] ?? ''),
        'column_count' => (int)($inventory['column_count'] ?? 0),
        'index_count' => (int)($inventory['index_count'] ?? 0),
        'migration_ids' => array_values(array_map('strval', $inventory['migration_ids'] ?? [])),
        'columns' => is_array($inventory['columns'] ?? null) ? $inventory['columns'] : [],
        'indexes' => is_array($inventory['indexes'] ?? null) ? $inventory['indexes'] : [],
    ];
}

function bakery_schema_inventory_cache_path(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'deploy'
        . DIRECTORY_SEPARATOR . 'HOSTED_SCHEMA_STATUS.json';
}

function bakery_schema_inventory_cache_read(int $maxAgeSeconds = 900): ?array
{
    $path = bakery_schema_inventory_cache_path();
    if (!is_file($path)) {
        return null;
    }
    if ($maxAgeSeconds > 0 && (time() - (int)@filemtime($path)) > $maxAgeSeconds) {
        return null;
    }
    $data = json_decode((string)@file_get_contents($path), true);
    if (!is_array($data) || (string)($data['hash'] ?? '') === '' || !isset($data['columns'], $data['indexes'])) {
        return null;
    }
    return bakery_schema_inventory_public($data);
}

function bakery_schema_inventory_cache_write(array $inventory): void
{
    $public = bakery_schema_inventory_public($inventory);
    $path = bakery_schema_inventory_cache_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, json_encode($public, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) {
        @rename($tmp, $path);
        @unlink($tmp);
    }
}

function bakery_schema_inventory_for_live_publish(PDO $db, bool $forceRefresh = false): array
{
    if (!$forceRefresh) {
        $cached = bakery_schema_inventory_cache_read(900);
        if ($cached !== null) {
            return $cached;
        }
    }
    $inventory = bakery_schema_inventory_public(bakery_schema_inventory_from_pdo($db));
    bakery_schema_inventory_cache_write($inventory);
    return $inventory;
}

function bakery_schema_inventory_index_table(string $key): string
{
    $dot = strpos($key, '.');
    return $dot === false ? $key : substr($key, 0, $dot);
}

/**
 * Same unique/non-unique column list under different names is one index, not a gap.
 */
function bakery_schema_inventory_pair_equivalent_indexes(
    array $stagingIndexes,
    array $liveIndexes,
    array $missingIndexes,
    array $extraIndexes
): array {
    foreach ($missingIndexes as $missingIndex => $missingName) {
        $missingDef = (string)($stagingIndexes[$missingName] ?? '');
        $missingTable = bakery_schema_inventory_index_table((string)$missingName);
        if ($missingDef === '') {
            continue;
        }
        foreach ($extraIndexes as $extraIndex => $extraName) {
            if (bakery_schema_inventory_index_table((string)$extraName) !== $missingTable) {
                continue;
            }
            if ((string)($liveIndexes[$extraName] ?? '') !== $missingDef) {
                continue;
            }
            unset($missingIndexes[$missingIndex], $extraIndexes[$extraIndex]);
            break;
        }
    }
    return [array_values($missingIndexes), array_values($extraIndexes)];
}

function bakery_schema_inventory_compare(array $staging, array $live): array
{
    $staging = bakery_schema_inventory_strip_views($staging);
    $live = bakery_schema_inventory_strip_views($live);
    $stagingColumns = is_array($staging['columns'] ?? null) ? $staging['columns'] : [];
    $liveColumns = is_array($live['columns'] ?? null) ? $live['columns'] : [];
    $stagingIndexes = is_array($staging['indexes'] ?? null) ? $staging['indexes'] : [];
    $liveIndexes = is_array($live['indexes'] ?? null) ? $live['indexes'] : [];
    $stagingMigrations = array_values(array_map('strval', $staging['migration_ids'] ?? []));
    $liveMigrations = array_values(array_map('strval', $live['migration_ids'] ?? []));

    $missingColumns = array_values(array_diff(array_keys($stagingColumns), array_keys($liveColumns)));
    $extraColumns = array_values(array_diff(array_keys($liveColumns), array_keys($stagingColumns)));
    $missingIndexes = array_values(array_diff(array_keys($stagingIndexes), array_keys($liveIndexes)));
    $extraIndexes = array_values(array_diff(array_keys($liveIndexes), array_keys($stagingIndexes)));
    [$missingIndexes, $extraIndexes] = bakery_schema_inventory_pair_equivalent_indexes(
        $stagingIndexes,
        $liveIndexes,
        $missingIndexes,
        $extraIndexes
    );
    $stagingOnlyMigrations = array_values(array_diff($stagingMigrations, $liveMigrations));
    $liveOnlyMigrations = array_values(array_diff($liveMigrations, $stagingMigrations));

    $mismatches = [];
    foreach ($stagingColumns as $key => $definition) {
        if (!isset($liveColumns[$key]) || (string)$liveColumns[$key] === (string)$definition) {
            continue;
        }
        if (bakery_schema_inventory_is_additive_enum_widen((string)$definition, (string)$liveColumns[$key])) {
            continue;
        }
        $mismatches[] = $key;
    }
    foreach ($stagingIndexes as $key => $definition) {
        if (isset($liveIndexes[$key]) && (string)$liveIndexes[$key] !== (string)$definition) {
            $mismatches[] = 'index:' . $key;
        }
    }

    $liveDatabase = (string)($live['database'] ?? '');
    $unexpectedDatabase = $liveDatabase !== '' && $liveDatabase !== 'bakerysf';

    // Live-only indexes (stricter uniqueness on production) are listed, not Stop.
    // Extra columns or different types still block a database update.
    if ($unexpectedDatabase || $extraColumns || $mismatches) {
        $state = 'discrepancy';
    } elseif ($missingColumns || $missingIndexes) {
        $state = 'live_behind';
    } else {
        $state = 'equal';
    }

    return [
        'state' => $state,
        'staging_hash' => (string)($staging['hash'] ?? ''),
        'live_hash' => (string)($live['hash'] ?? ''),
        'live_database' => $liveDatabase,
        'unexpected_database' => $unexpectedDatabase,
        'missing_on_live' => array_merge(
            array_map(static fn($name) => $name, $missingColumns),
            array_map(static fn($name) => 'index:' . $name, $missingIndexes)
        ),
        'extra_on_live' => array_merge(
            array_map(static fn($name) => $name, $extraColumns),
            array_map(static fn($name) => 'index:' . $name, $extraIndexes)
        ),
        'mismatches' => $mismatches,
        'staging_only_migrations' => $stagingOnlyMigrations,
        'live_only_migrations' => $liveOnlyMigrations,
    ];
}
