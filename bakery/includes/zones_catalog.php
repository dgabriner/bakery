<?php
/**
 * Zones catalog — single source of truth for delivery zone names and colors.
 *
 * The zones table (zones.php) owns zone data. These helpers load it
 * table-first with the documented legacy six-zone fallback, mirroring the
 * loading posture landed in map.php: guarded query, trim + dedupe by name,
 * validated #rrggbb colors, graceful degradation when the table is missing
 * or empty. Pages must not declare their own zone name/color arrays.
 */
if (!function_exists('bakery_zones_legacy_rows')) {
    /**
     * Canonical pre-migration zone rows (names + presentation colors).
     * Mirrors the seed block in zones.php and the presentation map in map.php.
     *
     * @return array<int, array{name: string, color: string}>
     */
    function bakery_zones_legacy_rows(): array
    {
        return [
            ['name' => 'Centro', 'color' => '#007bff'],
            ['name' => 'Mission', 'color' => '#dc3545'],
            ['name' => 'Ruta Sour Flour', 'color' => '#28a745'],
            ['name' => 'Daly City/San Mateo', 'color' => '#fd7e14'],
            ['name' => 'North Bay', 'color' => '#6f42c1'],
            ['name' => 'East Bay', 'color' => '#20c997'],
        ];
    }
}

if (!function_exists('bakery_zones_legacy_list')) {
    /**
     * Canonical pre-migration zone names, in their historical fallback order.
     *
     * @return array<int, string>
     */
    function bakery_zones_legacy_list(): array
    {
        return array_column(bakery_zones_legacy_rows(), 'name');
    }
}

if (!function_exists('bakery_zones_catalog')) {
    /**
     * Ordered zone rows: ['name' => .., 'color' => ..].
     *
     * Zones-table rows when the table exists AND has at least one row,
     * otherwise the canonical legacy six-zone fallback. Never throws.
     *
     * @param PDO|null $db
     * @return array<int, array{name: string, color: string}>
     */
    function bakery_zones_catalog($db): array
    {
        $rows = [];
        try {
            if ($db instanceof PDO
                && (!function_exists('table_exists') || table_exists($db, 'zones'))) {
                foreach ($db->query("SELECT name, color FROM zones ORDER BY name")->fetchAll() as $tableZone) {
                    $zoneName = trim((string)$tableZone['name']);
                    if ($zoneName === '' || in_array($zoneName, array_column($rows, 'name'), true)) {
                        continue;
                    }
                    $tableColor = strtolower(trim((string)$tableZone['color']));
                    $rows[] = [
                        'name' => $zoneName,
                        'color' => preg_match('/^#[0-9a-f]{6}$/', $tableColor) ? $tableColor : '#6c757d',
                    ];
                }
            }
        } catch (Exception $e) {
            // zones table not migrated yet — fall back to the legacy list
            $rows = [];
        }
        if (empty($rows)) {
            return bakery_zones_legacy_rows();
        }
        return $rows;
    }
}

if (!function_exists('bakery_zones_catalog_ready')) {
    /**
     * True when the zones table exists and holds at least one row.
     *
     * @param PDO|null $db
     */
    function bakery_zones_catalog_ready($db): bool
    {
        try {
            if (!$db instanceof PDO
                || (function_exists('table_exists') && !table_exists($db, 'zones'))) {
                return false;
            }
            return (int)$db->query("SELECT COUNT(*) FROM zones")->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('bakery_zone_color')) {
    /**
     * Presentation lookup of a zone color from a catalog built by
     * bakery_zones_catalog(). Case-insensitive, trims both sides.
     *
     * @param array<int, array{name: mixed, color: mixed}> $catalog
     */
    function bakery_zone_color(array $catalog, string $zone_name, string $default = ''): string
    {
        $needle = strtolower(trim($zone_name));
        if ($needle === '') {
            return $default;
        }
        foreach ($catalog as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = strtolower(trim((string)($row['name'] ?? '')));
            if ($name !== '' && $name === $needle) {
                $color = trim((string)($row['color'] ?? ''));
                return $color !== '' ? $color : $default;
            }
        }
        return $default;
    }
}

if (!function_exists('bakery_zone_display_cycle')) {
    /**
     * Historical positional tint cycle used by the driver route pages:
     * colors were assigned per zone in encounter order, not per zone name.
     * Moved here verbatim so the palette has one home next to the catalog.
     *
     * @return array<int, string>
     */
    function bakery_zone_display_cycle(): array
    {
        return [
            '#007bff', '#28a745', '#dc3545', '#fd7e14', '#6f42c1',
            '#20c997', '#ffc107', '#e83e8c', '#6c757d', '#17a2b8',
            '#6610f2', '#fd7e14', '#e83e8c', '#6f42c1', '#20c997',
        ];
    }
}

if (!function_exists('bakery_zone_route_color')) {
    /**
     * Route-page tint resolver preserving historical rendering exactly.
     *
     * Byte-preservation policy: the six legacy-named zones keep their
     * encounter-order cycle tints (zero visual change while the table holds
     * the legacy seed values). Zones added later via zones.php light up with
     * their table color; anything unknown still cycles as before.
     *
     * @param array<int, array{name: mixed, color: mixed}> $catalog
     * @param array<int, string> $cycle
     */
    function bakery_zone_route_color(array $catalog, string $zone_name, array $cycle, int $cycle_index): string
    {
        $name = trim($zone_name);
        if ($name !== '' && !in_array($name, bakery_zones_legacy_list(), true)) {
            $tableColor = bakery_zone_color($catalog, $name, '');
            if ($tableColor !== '') {
                return $tableColor;
            }
        }
        return $cycle[$cycle_index % count($cycle)];
    }
}
