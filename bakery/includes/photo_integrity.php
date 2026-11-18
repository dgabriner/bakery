<?php
/**
 * Integrity flags stored on driver_photos at upload.
 *
 * Flags: reused, arrival_equals_after, far_from_store (250 m, from draft
 * #78), out_of_order (from #78, 60 second grace), cluster (3 or more stores
 * inside the same 5 minute window as the bulk-stamp rule).
 * A flagged photo stays on disk. This file never deletes a photo.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/photo_capture.php';
require_once __DIR__ . '/stop_proof.php';

function bakery_photo_integrity_cluster_seconds(): int
{
    return 5 * 60;
}

function bakery_photo_integrity_cluster_stores(): int
{
    return 3;
}

function bakery_photo_integrity_order_grace_seconds(): int
{
    return 60;
}

function bakery_photo_dhash_max_distance(): int
{
    return 4;
}

function bakery_photo_integrity_schema_ready(PDO $db): bool
{
    return function_exists('column_exists')
        && column_exists($db, 'driver_photos', 'sha256')
        && column_exists($db, 'driver_photos', 'integrity_flags');
}

/**
 * Apply 117 once. A second call does nothing. The migration runner records
 * the id and skips the file after that.
 */
function bakery_photo_integrity_ensure_schema(PDO $db): void
{
    if (function_exists('bakery_forget_column_exists')) {
        bakery_forget_column_exists('driver_photos', 'sha256');
        bakery_forget_column_exists('driver_photos', 'integrity_flags');
        bakery_forget_column_exists('driver_photos', 'captured_at');
    }
    if (bakery_photo_integrity_schema_ready($db)) {
        return;
    }
    $path = dirname(__DIR__) . '/database/schema/117_driver_photo_integrity.sql';
    $sql = (string)file_get_contents($path);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = preg_split('/;\s*(?:\r?\n|$)/', (string)$sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        try {
            $db->exec($statement);
        } catch (PDOException $e) {
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('driver_photos', 'sha256');
                bakery_forget_column_exists('driver_photos', 'integrity_flags');
            }
            if (bakery_photo_integrity_schema_ready($db)) {
                return;
            }
            throw $e;
        }
    }
    if (function_exists('bakery_forget_column_exists')) {
        bakery_forget_column_exists('driver_photos', 'sha256');
        bakery_forget_column_exists('driver_photos', 'integrity_flags');
        bakery_forget_column_exists('driver_photos', 'captured_at');
    }
}

/**
 * @param array<int, mixed> $flags
 * @return list<string>
 */
function bakery_photo_integrity_normalize(array $flags): array
{
    $known = array_fill_keys(bakery_stop_proof_integrity_flag_names(), true);
    $out = [];
    foreach ($flags as $flag) {
        $flag = (string)$flag;
        if (isset($known[$flag]) && !in_array($flag, $out, true)) {
            $out[] = $flag;
        }
    }
    sort($out);
    return $out;
}

/**
 * Null, blank, or unreadable flags are unflagged. They are not an error.
 *
 * @return list<string>
 */
function bakery_photo_integrity_flags_of(PDO $db, int $photoId): array
{
    if ($photoId <= 0 || !bakery_photo_integrity_schema_ready($db)) {
        return [];
    }
    $stmt = $db->prepare('SELECT integrity_flags, sha256 FROM driver_photos WHERE id = ?');
    $stmt->execute([$photoId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [];
    }
    return bakery_stop_proof_photo_flags([
        'integrity_flags' => $row['integrity_flags'] ?? null,
    ]);
}

/**
 * @param list<string> $flags
 */
function bakery_photo_integrity_store(PDO $db, int $photoId, array $flags): void
{
    if ($photoId <= 0 || !bakery_photo_integrity_schema_ready($db)) {
        return;
    }
    $flags = bakery_photo_integrity_normalize($flags);
    $json = $flags === [] ? null : json_encode($flags);
    $stmt = $db->prepare('UPDATE driver_photos SET integrity_flags = ? WHERE id = ?');
    $stmt->execute([$json, $photoId]);
}

/**
 * @param list<string> $add
 */
function bakery_photo_integrity_add(PDO $db, int $photoId, array $add): void
{
    if ($add === []) {
        return;
    }
    $flags = bakery_photo_integrity_flags_of($db, $photoId);
    foreach ($add as $flag) {
        $flags[] = (string)$flag;
    }
    bakery_photo_integrity_store($db, $photoId, $flags);
}

function bakery_photo_sha256_file(string $path): ?string
{
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $hash = hash_file('sha256', $path);
    return is_string($hash) && $hash !== '' ? $hash : null;
}

/**
 * 64-bit difference hash. Null when GD cannot read the file.
 */
function bakery_photo_dhash(string $path): ?string
{
    if ($path === '' || !is_file($path) || !function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
        return null;
    }
    $bytes = file_get_contents($path);
    if ($bytes === false || $bytes === '') {
        return null;
    }
    $source = @imagecreatefromstring($bytes);
    if ($source === false) {
        return null;
    }
    $small = imagecreatetruecolor(9, 8);
    if ($small === false) {
        imagedestroy($source);
        return null;
    }
    imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $left = imagecolorat($small, $x, $y);
            $right = imagecolorat($small, $x + 1, $y);
            $leftGray = (($left >> 16) & 255) * 0.299 + (($left >> 8) & 255) * 0.587 + ($left & 255) * 0.114;
            $rightGray = (($right >> 16) & 255) * 0.299 + (($right >> 8) & 255) * 0.587 + ($right & 255) * 0.114;
            $bits .= $leftGray > $rightGray ? '1' : '0';
        }
    }
    imagedestroy($source);
    imagedestroy($small);
    return $bits;
}

function bakery_photo_dhash_distance(string $left, string $right): int
{
    $length = min(strlen($left), strlen($right));
    $distance = abs(strlen($left) - strlen($right));
    for ($i = 0; $i < $length; $i++) {
        if ($left[$i] !== $right[$i]) {
            $distance++;
        }
    }
    return $distance;
}

/**
 * @param array<string, mixed> $photo
 */
function bakery_photo_integrity_moment(array $photo): ?int
{
    $raw = trim((string)($photo['captured_at'] ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        $raw = trim((string)($photo['created_at'] ?? ''));
    }
    if ($raw === '' || $raw === '0000-00-00 00:00:00') {
        return null;
    }
    $timestamp = strtotime($raw);
    return $timestamp === false ? null : $timestamp;
}

/**
 * @param array<string, mixed> $photo
 */
function bakery_photo_integrity_disk(array $photo): string
{
    if (!function_exists('bakery_driver_photo_disk_path')) {
        return '';
    }
    return bakery_driver_photo_disk_path((string)($photo['file_path'] ?? ''));
}

/**
 * Compute flags for one saved photo and write them. Peer rows gain reused,
 * arrival_equals_after, out_of_order, or cluster when this upload shows it.
 * Returns the flags stored on this photo. A row with no hash is unflagged.
 *
 * @return list<string>
 */
function bakery_photo_integrity_apply(PDO $db, int $photoId): array
{
    if ($photoId <= 0 || !bakery_photo_integrity_schema_ready($db)) {
        return [];
    }
    $stmt = $db->prepare('SELECT * FROM driver_photos WHERE id = ?');
    $stmt->execute([$photoId]);
    $photo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($photo)) {
        return [];
    }
    $hash = strtolower(trim((string)($photo['sha256'] ?? '')));
    if ($hash === '') {
        return [];
    }
    $flags = [];
    if (bakery_photo_integrity_mark_reused($db, $photo, $hash)) {
        $flags[] = 'reused';
    }
    if (bakery_photo_integrity_mark_arrival_equals_after($db, $photo, $hash)) {
        $flags[] = 'arrival_equals_after';
    }
    if (bakery_photo_integrity_is_far($db, $photo)) {
        $flags[] = 'far_from_store';
    }
    if (bakery_photo_integrity_mark_out_of_order($db, $photo)) {
        $flags[] = 'out_of_order';
    }
    if (bakery_photo_integrity_mark_cluster($db, $photo)) {
        $flags[] = 'cluster';
    }
    $flags = bakery_photo_integrity_normalize($flags);
    bakery_photo_integrity_store($db, $photoId, $flags);
    return $flags;
}

/**
 * @param array<string, mixed> $photo
 */
function bakery_photo_integrity_mark_reused(PDO $db, array $photo, string $hash): bool
{
    $stmt = $db->prepare(
        'SELECT id, customer_id, delivery_date
         FROM driver_photos
         WHERE sha256 = ? AND id <> ?'
    );
    $stmt->execute([$hash, (int)$photo['id']]);
    $reused = false;
    $customerId = (int)($photo['customer_id'] ?? 0);
    $date = (string)($photo['delivery_date'] ?? '');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $other) {
        $otherCustomer = (int)($other['customer_id'] ?? 0);
        $otherDate = (string)($other['delivery_date'] ?? '');
        if ($otherCustomer === $customerId && $otherDate === $date) {
            continue;
        }
        $reused = true;
        bakery_photo_integrity_add($db, (int)$other['id'], ['reused']);
    }
    return $reused;
}

/**
 * @param array<string, mixed> $photo
 */
function bakery_photo_integrity_mark_arrival_equals_after(PDO $db, array $photo, string $hash): bool
{
    $type = bakery_stop_proof_photo_type($photo);
    if (strcasecmp($type, 'Before') !== 0 && strcasecmp($type, 'After') !== 0) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT * FROM driver_photos
         WHERE customer_id = ? AND delivery_date = ? AND id <> ?'
    );
    $stmt->execute([
        (int)($photo['customer_id'] ?? 0),
        (string)($photo['delivery_date'] ?? ''),
        (int)$photo['id'],
    ]);
    $matched = false;
    $ownHash = bakery_photo_dhash(bakery_photo_integrity_disk($photo));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $other) {
        $otherType = bakery_stop_proof_photo_type($other);
        $pair = (strcasecmp($type, 'After') === 0 && strcasecmp($otherType, 'Before') === 0)
            || (strcasecmp($type, 'Before') === 0 && strcasecmp($otherType, 'After') === 0);
        if (!$pair) {
            continue;
        }
        $sameFile = strtolower(trim((string)($other['sha256'] ?? ''))) === $hash && $hash !== '';
        $similar = false;
        if (!$sameFile && $ownHash !== null) {
            $otherHash = bakery_photo_dhash(bakery_photo_integrity_disk($other));
            if ($otherHash !== null) {
                $similar = bakery_photo_dhash_distance($ownHash, $otherHash) <= bakery_photo_dhash_max_distance();
            }
        }
        if (!$sameFile && !$similar) {
            continue;
        }
        $matched = true;
        bakery_photo_integrity_add($db, (int)$other['id'], ['arrival_equals_after']);
    }
    return $matched;
}

/**
 * @param array<string, mixed> $photo
 */
function bakery_photo_integrity_is_far(PDO $db, array $photo): bool
{
    $customerId = (int)($photo['customer_id'] ?? 0);
    if ($customerId <= 0 || !function_exists('column_exists') || !column_exists($db, 'customers', 'latitude')) {
        return false;
    }
    $stmt = $db->prepare('SELECT latitude, longitude FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $store = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($store)) {
        return false;
    }
    return bakery_photo_is_far_from_store(
        $photo['latitude'] ?? null,
        $photo['longitude'] ?? null,
        $store['latitude'] ?? null,
        $store['longitude'] ?? null
    );
}

/**
 * A photo taken before the previous stop on the same route, from #78.
 *
 * @param array<string, mixed> $photo
 */
function bakery_photo_integrity_mark_out_of_order(PDO $db, array $photo): bool
{
    $driverId = (int)($photo['driver_id'] ?? 0);
    $date = (string)($photo['delivery_date'] ?? '');
    if ($driverId <= 0 || $date === '' || !function_exists('table_exists') || !table_exists($db, 'daily_order_assignments')) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT do.customer_id, doa.route_order
         FROM daily_order_assignments doa
         INNER JOIN daily_orders do ON do.id = doa.daily_order_id
         WHERE doa.driver_id = ? AND doa.delivery_date = ? AND do.order_date = doa.delivery_date'
    );
    $stmt->execute([$driverId, $date]);
    $orders = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orders[(int)$row['customer_id']] = (int)$row['route_order'];
    }
    $photoStmt = $db->prepare(
        'SELECT id, customer_id, captured_at, created_at
         FROM driver_photos
         WHERE driver_id = ? AND delivery_date = ?'
    );
    $photoStmt->execute([$driverId, $date]);
    $earliest = [];
    $idsByCustomer = [];
    foreach ($photoStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $customerId = (int)($row['customer_id'] ?? 0);
        $moment = bakery_photo_integrity_moment($row);
        if ($moment === null) {
            continue;
        }
        if (!isset($earliest[$customerId]) || $moment < $earliest[$customerId]) {
            $earliest[$customerId] = $moment;
        }
        $idsByCustomer[$customerId][] = (int)$row['id'];
    }
    $indexed = [];
    foreach ($orders as $customerId => $order) {
        $indexed[] = [
            'customer_id' => $customerId,
            'order' => $order,
            'time' => $earliest[$customerId] ?? null,
        ];
    }
    usort($indexed, static function (array $left, array $right): int {
        $order = $left['order'] <=> $right['order'];
        return $order !== 0 ? $order : ($left['customer_id'] <=> $right['customer_id']);
    });
    $previousTime = null;
    $flaggedCustomers = [];
    foreach ($indexed as $row) {
        if ($row['time'] === null) {
            continue;
        }
        if ($previousTime !== null && ($row['time'] + bakery_photo_integrity_order_grace_seconds()) < $previousTime) {
            $flaggedCustomers[$row['customer_id']] = true;
        }
        if ($previousTime === null || $row['time'] > $previousTime) {
            $previousTime = $row['time'];
        }
    }
    $currentCustomer = (int)($photo['customer_id'] ?? 0);
    foreach ($flaggedCustomers as $customerId => $flagged) {
        if ((int)$customerId === $currentCustomer) {
            continue;
        }
        foreach ($idsByCustomer[$customerId] ?? [] as $otherId) {
            bakery_photo_integrity_add($db, (int)$otherId, ['out_of_order']);
        }
    }
    return !empty($flaggedCustomers[$currentCustomer]);
}

/**
 * @param array<string, mixed> $photo
 */
function bakery_photo_integrity_mark_cluster(PDO $db, array $photo): bool
{
    $moment = bakery_photo_integrity_moment($photo);
    $driverId = (int)($photo['driver_id'] ?? 0);
    if ($moment === null || $driverId <= 0) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT id, customer_id, captured_at, created_at
         FROM driver_photos
         WHERE driver_id = ? AND delivery_date = ?'
    );
    $stmt->execute([$driverId, (string)($photo['delivery_date'] ?? '')]);
    $window = bakery_photo_integrity_cluster_seconds();
    $inside = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rowMoment = bakery_photo_integrity_moment($row);
        if ($rowMoment === null || abs($rowMoment - $moment) > $window) {
            continue;
        }
        $inside[] = $row;
    }
    $stores = [];
    foreach ($inside as $row) {
        $stores[(int)($row['customer_id'] ?? 0)] = true;
    }
    unset($stores[0]);
    if (count($stores) < bakery_photo_integrity_cluster_stores()) {
        return false;
    }
    $currentId = (int)($photo['id'] ?? 0);
    foreach ($inside as $row) {
        $otherId = (int)($row['id'] ?? 0);
        if ($otherId > 0 && $otherId !== $currentId) {
            bakery_photo_integrity_add($db, $otherId, ['cluster']);
        }
    }
    return true;
}
