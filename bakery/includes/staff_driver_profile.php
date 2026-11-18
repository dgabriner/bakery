<?php
/**
 * Staff who also drive (for example a manager covering a route).
 *
 * A manager or administrator keeps one role. When their name matches exactly
 * one active driver profile, My Route opens that profile directly and the
 * driver screens use the focused Driving nav, with a one-tap way back to the
 * manager tools. This never reads or writes users.driver_id and never changes
 * users.role_slug: Staff-page role rules stay the only way to change a role.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/** Roles that can also drive their own route without being a route worker. */
function bakery_staff_driving_roles(): array
{
    return ['manager', 'administrator'];
}

function bakery_staff_can_drive_as_self(?array $user): bool
{
    return $user !== null
        && in_array((string)($user['role_slug'] ?? ''), bakery_staff_driving_roles(), true);
}

/** Lowercase, single-spaced, accent-free key so "Laura" matches " laura " and "Lauré". */
function bakery_staff_name_key(string $name): string
{
    $name = trim((string)preg_replace('/\s+/u', ' ', $name));
    if ($name === '') {
        return '';
    }
    $name = mb_strtolower($name, 'UTF-8');
    return strtr($name, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ]);
}

/**
 * Pick the one active driver profile that belongs to this staff member, from
 * names alone. Exact full-name match wins. A single-word staff name ("Laura")
 * may also match one driver who starts with that word. Anything ambiguous or
 * missing returns 0 and the caller falls back to the normal driver picker.
 *
 * @param array<int, array{id:int|string,name:string}> $drivers Active drivers.
 */
function bakery_staff_pick_own_driver(array $drivers, string $displayName): int
{
    $key = bakery_staff_name_key($displayName);
    if ($key === '') {
        return 0;
    }
    $singleWord = strpos($key, ' ') === false;
    $exact = [];
    $sameFirstWord = [];
    foreach ($drivers as $row) {
        $driverKey = bakery_staff_name_key((string)($row['name'] ?? ''));
        if ($driverKey === '') {
            continue;
        }
        if ($driverKey === $key) {
            $exact[] = (int)$row['id'];
            continue;
        }
        $driverFirst = explode(' ', $driverKey)[0];
        if ($singleWord && $driverFirst === $key) {
            $sameFirstWord[] = (int)$row['id'];
        }
    }
    if (count($exact) === 1) {
        return $exact[0];
    }
    if ($exact === [] && count($sameFirstWord) === 1) {
        return $sameFirstWord[0];
    }
    return 0;
}

/** The signed-in manager/administrator's own driver profile id, or 0. */
function bakery_staff_own_driver_id(?PDO $db, ?array $user): int
{
    static $cache = [];
    if (!$db || !bakery_staff_can_drive_as_self($user)) {
        return 0;
    }
    $userId = (int)($user['id'] ?? 0);
    $name = (string)($user['display_name'] ?? '');
    $cacheKey = $userId . '|' . $name;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    $found = 0;
    try {
        $rows = $db->query('SELECT id, name FROM drivers WHERE COALESCE(archived, 0) = 0')->fetchAll(PDO::FETCH_ASSOC);
        $found = bakery_staff_pick_own_driver($rows ?: [], $name);
    } catch (Throwable $e) {
        $found = 0;
    }
    return $cache[$cacheKey] = $found;
}

/** True when this staff member is looking at their own driver profile. */
function bakery_staff_driving_as_self(?PDO $db, ?array $user, int $selectedDriverId): bool
{
    if ($selectedDriverId <= 0) {
        return false;
    }
    $own = bakery_staff_own_driver_id($db, $user);
    return $own > 0 && $own === $selectedDriverId;
}

/** Direct My Route link: skips the "who is driving" picker when a profile matches. */
function bakery_staff_my_route_href(string $baseUrl, ?string $date = null): string
{
    $query = ['me' => 1];
    if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $query['date'] = $date;
    }
    return $baseUrl . 'driver.php?' . http_build_query($query);
}
