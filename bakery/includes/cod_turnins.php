<?php
/**
 * COD cash turn-in: one canonical amount per driver per operating day.
 * Collected cash still lives on daily_orders.amount_collected; this table
 * only records what HQ received from the driver.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_cod_turnins_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'cod_turnins');
}

/**
 * Upsert turned-in amount for a driver/day. Returns the row id.
 */
function bakery_cod_turnin_record(PDO $db, int $driverId, string $date, float $amount, int $userId): int
{
    if ($driverId <= 0) {
        throw new InvalidArgumentException('Driver is required for COD turn-in');
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException('Turn-in date must be Y-m-d');
    }
    if ($amount < 0) {
        throw new InvalidArgumentException('Turn-in amount cannot be negative');
    }
    if (!bakery_cod_turnins_ready($db)) {
        throw new RuntimeException('COD turn-in table is not installed. Run migration 080.');
    }
    $amount = round($amount, 2);
    $userId = $userId > 0 ? $userId : null;
    $stmt = $db->prepare(
        'INSERT INTO cod_turnins (driver_id, turnin_date, amount, recorded_by_user_id)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           amount = VALUES(amount),
           recorded_by_user_id = VALUES(recorded_by_user_id),
           recorded_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$driverId, $date, $amount, $userId]);
    $idStmt = $db->prepare('SELECT id FROM cod_turnins WHERE driver_id = ? AND turnin_date = ? LIMIT 1');
    $idStmt->execute([$driverId, $date]);
    $id = (int)$idStmt->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('COD turn-in did not persist');
    }
    if (function_exists('bakery_record_operational_event')) {
        bakery_record_operational_event($db, 'cod_turnin_recorded', 'COD turn-in $' . number_format($amount, 2), [
            'operational_date' => $date,
            'driver_id' => $driverId,
            'actor_user_id' => $userId,
            'metadata' => [
                'cod_turnin_id' => $id,
                'amount' => $amount,
            ],
        ]);
    }
    return $id;
}

/** @return float|null */
function bakery_cod_turnin_get(PDO $db, int $driverId, string $date): ?float
{
    if ($driverId <= 0 || !bakery_cod_turnins_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('SELECT amount FROM cod_turnins WHERE driver_id = ? AND turnin_date = ? LIMIT 1');
    $stmt->execute([$driverId, $date]);
    $raw = $stmt->fetchColumn();
    if ($raw === false || $raw === null) {
        return null;
    }
    return round((float)$raw, 2);
}

/**
 * @return array<int,float> driver_id => amount
 */
function bakery_cod_turnins_for_date(PDO $db, string $date): array
{
    if (!bakery_cod_turnins_ready($db)) {
        return [];
    }
    $stmt = $db->prepare('SELECT driver_id, amount FROM cod_turnins WHERE turnin_date = ?');
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['driver_id']] = round((float)$row['amount'], 2);
    }
    return $out;
}
