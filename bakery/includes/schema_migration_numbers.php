<?php
/**
 * Schema file numbers are unique going forward.
 *
 * schema_migrations is keyed by the full id (filename without .sql), so two
 * files can share 062 and both apply. Competing agents already did that
 * (010, 021, 025, 062, 073). Those historical pairs stay; new files take 074+.
 * Never rename an applied file. Live and Staging already recorded the old ids.
 *
 * Live schema_migrations also has 114_user_112_role_probe and
 * 115_user_112_sales_role. Those rows are not files in git. Do not reuse
 * 114 or 115. The next schema file starts at 116.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** @return array<string, list<string>> */
function bakery_schema_historical_duplicate_prefixes(): array
{
    return [
        '010' => ['010_baker_product_lines', '010_pan_dulce_quantity_standards'],
        '021' => ['021_operating_day_closeout', '021_operational_events'],
        '025' => ['025_customer_account_preferences', '025_customer_notifications'],
        '062' => ['062_bread_education', '062_surveys_custom'],
        '073' => ['073_starter_price_upgrade', '073_survey_interactions'],
    ];
}

/**
 * schema_migrations.id is this filename without .sql.
 * The number prefix is not the key, and the table has no checksum column.
 */
function bakery_schema_migration_ledger_id(string $pathOrId): string
{
    $name = basename(str_replace('\\', '/', $pathOrId));
    if (strlen($name) > 4 && substr($name, -4) === '.sql') {
        $name = substr($name, 0, -4);
    }
    return $name;
}

/**
 * True only when a ledger row equals the full filename id.
 * 086_time_clock_overrides does not count as 086_ingredient_prices.
 *
 * @param list<string> $appliedIds
 */
function bakery_schema_migration_id_is_applied(array $appliedIds, string $pathOrId): bool
{
    return in_array(bakery_schema_migration_ledger_id($pathOrId), $appliedIds, true);
}

/** @return list<string> */
function bakery_schema_migration_ids_from_dir(?string $dir = null): array
{
    $dir = $dir ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema');
    $ids = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9]_*.sql') ?: [] as $path) {
        $name = basename($path, '.sql');
        if (preg_match('/^\d{3}_[A-Za-z0-9_]+$/', $name)) {
            $ids[] = $name;
        }
    }
    sort($ids, SORT_STRING);
    return $ids;
}

/** @param list<string> $ids
 *  @return array<string, list<string>> */
function bakery_schema_migration_prefix_groups(array $ids): array
{
    $groups = [];
    foreach ($ids as $id) {
        $prefix = substr((string)$id, 0, 3);
        $groups[$prefix][] = (string)$id;
    }
    foreach ($groups as &$group) {
        sort($group, SORT_STRING);
    }
    unset($group);
    ksort($groups);
    return $groups;
}

/** @param list<string>|null $ids
 *  @return array<string, list<string>> */
function bakery_schema_unexpected_duplicate_prefixes(?array $ids = null): array
{
    $ids = $ids ?? bakery_schema_migration_ids_from_dir();
    $allowed = bakery_schema_historical_duplicate_prefixes();
    $unexpected = [];
    foreach (bakery_schema_migration_prefix_groups($ids) as $prefix => $group) {
        if (count($group) < 2) {
            continue;
        }
        $ok = $allowed[$prefix] ?? [];
        sort($ok, SORT_STRING);
        if ($group !== $ok) {
            $unexpected[$prefix] = $group;
        }
    }
    return $unexpected;
}

/**
 * 093 through 096 stay reserved. 114 and 115 are Live-only ledger rows
 * with no file in git. New files start at 116.
 *
 * @return list<int>
 */
function bakery_schema_live_only_ledger_numbers(): array
{
    return [114, 115];
}

/** @return list<int> */
function bakery_schema_reserved_migration_numbers(): array
{
    return array_merge([93, 94, 95, 96], bakery_schema_live_only_ledger_numbers());
}

/**
 * Schema files whose number is already a Live ledger row with no git file.
 *
 * @param list<string>|null $ids
 * @return list<string>
 */
function bakery_schema_files_reusing_live_ledger(?array $ids = null): array
{
    $ids = $ids ?? bakery_schema_migration_ids_from_dir();
    $taken = array_fill_keys(bakery_schema_live_only_ledger_numbers(), true);
    $bad = [];
    foreach ($ids as $id) {
        $number = (int)substr((string)$id, 0, 3);
        if (isset($taken[$number])) {
            $bad[] = (string)$id;
        }
    }
    return $bad;
}

/** @param list<string>|null $ids */
function bakery_schema_next_migration_number(?array $ids = null): int
{
    $ids = $ids ?? bakery_schema_migration_ids_from_dir();
    $max = 0;
    foreach ($ids as $id) {
        $max = max($max, (int)substr((string)$id, 0, 3));
    }
    $next = $max + 1;
    $reserved = array_fill_keys(bakery_schema_reserved_migration_numbers(), true);
    while (isset($reserved[$next])) {
        $next++;
    }
    return $next;
}

/** @param list<string>|null $ids */
function bakery_schema_next_migration_id(string $slug, ?array $ids = null): string
{
    $slug = strtolower(trim($slug));
    $slug = (string)preg_replace('/[^a-z0-9_]+/', '_', $slug);
    $slug = trim($slug, '_');
    if ($slug === '') {
        throw new InvalidArgumentException('Migration slug is required.');
    }
    return sprintf('%03d_%s', bakery_schema_next_migration_number($ids), $slug);
}
