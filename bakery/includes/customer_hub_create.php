<?php
/**
 * Customer Hub create. customers.php and Sales Leads convert both call this.
 * Do not copy the insert into another page.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/common_functions.php';
require_once __DIR__ . '/payment_terms.php';
require_once __DIR__ . '/audit_log.php';

/**
 * Insert one Customer Hub customer and return the new id.
 * A positive zone_id that exists wins. Otherwise zone is a name resolved
 * with bakery_zone_id_for_name, the same way the hub form posts it.
 * neighborhood and delivery_instructions are written only when the caller
 * sends them and the column exists.
 *
 * @param array<string, mixed> $input
 */
function bakery_customer_hub_create(PDO $db, array $input): int
{
    $zoneName = null;
    $zoneId = null;
    $postedZoneId = $input['zone_id'] ?? null;
    if ($postedZoneId !== null && $postedZoneId !== '' && (int)$postedZoneId > 0 && function_exists('table_exists') && table_exists($db, 'zones')) {
        $zoneStmt = $db->prepare('SELECT name FROM zones WHERE id = ? LIMIT 1');
        $zoneStmt->execute([(int)$postedZoneId]);
        $foundName = $zoneStmt->fetchColumn();
        if ($foundName !== false) {
            $zoneId = (int)$postedZoneId;
            $zoneName = (string)$foundName;
        }
    }
    if ($zoneId === null) {
        $zoneName = empty($input['zone']) ? null : (string)$input['zone'];
        $zoneId = bakery_zone_id_for_name($db, $zoneName);
    }

    $pay = bakery_customer_payment_terms_from_post($input);
    $columns = [
        'name', 'email', 'phone', 'address', 'zone', 'zone_id', 'deliver_by', 'deliver_after',
        'default_pan_dulce_price', 'portal_phone', 'portal_code', 'portal_enabled',
        'sf_baker_enabled', 'pricing_tier', 'payment_collection',
    ];
    $portalCode = bakery_normalize_login_code($input['portal_code'] ?? '');
    $tier = in_array($input['pricing_tier'] ?? '', ['retail', 'wholesale', 'custom'], true)
        ? (string)$input['pricing_tier']
        : 'retail';
    $params = [
        (string)($input['name'] ?? ''),
        empty($input['email']) ? null : (string)$input['email'],
        array_key_exists('phone', $input) ? (string)$input['phone'] : '',
        (string)($input['address'] ?? ''),
        $zoneName,
        $zoneId,
        empty($input['deliver_by']) ? null : (string)$input['deliver_by'],
        empty($input['deliver_after']) ? null : (string)$input['deliver_after'],
        empty($input['default_pan_dulce_price']) ? null : $input['default_pan_dulce_price'],
        empty($input['portal_phone']) ? null : (string)$input['portal_phone'],
        $portalCode !== '' ? $portalCode : null,
        !empty($input['portal_enabled']) ? 1 : 0,
        !empty($input['sf_baker_enabled']) ? 1 : 0,
        $tier,
        $pay['payment_collection'],
    ];
    if (function_exists('column_exists') && column_exists($db, 'customers', 'payment_terms')) {
        $columns[] = 'payment_terms';
        $params[] = $pay['payment_terms'];
    }
    if (array_key_exists('neighborhood', $input) && function_exists('column_exists') && column_exists($db, 'customers', 'neighborhood')) {
        $neighborhood = trim((string)$input['neighborhood']);
        $columns[] = 'neighborhood';
        $params[] = $neighborhood === '' ? null : $neighborhood;
    }
    if (array_key_exists('delivery_instructions', $input) && function_exists('column_exists') && column_exists($db, 'customers', 'delivery_instructions')) {
        $instructions = trim((string)$input['delivery_instructions']);
        $columns[] = 'delivery_instructions';
        $params[] = $instructions === '' ? null : $instructions;
    }

    $marks = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $db->prepare(
        'INSERT INTO customers (' . implode(', ', $columns) . ') VALUES (' . $marks . ')'
    );
    $stmt->execute($params);
    $newId = (int)$db->lastInsertId();
    if ($newId <= 0) {
        throw new RuntimeException('Customer was not created.');
    }
    bakery_audit_pricing_tier($db, $newId, null, $tier, 'create');
    return $newId;
}
