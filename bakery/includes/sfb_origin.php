<?php
/**
 * Real vs synthetic SF Baker identity.
 *
 * Origin is a stored fact on customers.sfb_origin. Wholesale ops queries
 * must append bakery_sfb_ops_origin_clause() so synthetics never enter
 * Daily Run, routes, pack, or invoices. Community queries SELECT origin
 * so every author can be badged.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_sfb_origin_column_ready(PDO $db = null) {
    $db = $db instanceof PDO ? $db : ($GLOBALS['db'] ?? null);
    return $db instanceof PDO
        && function_exists('column_exists')
        && column_exists($db, 'customers', 'sfb_origin');
}

function bakery_sfb_normalize_origin($origin) {
    $origin = strtolower(trim((string)$origin));
    return $origin === 'synthetic' ? 'synthetic' : 'human';
}

/**
 * @param array|string|null $rowOrOrigin Customer row or origin string
 */
function bakery_sfb_is_synthetic($rowOrOrigin) {
    if (is_array($rowOrOrigin)) {
        $origin = $rowOrOrigin['sfb_origin'] ?? 'human';
    } else {
        $origin = $rowOrOrigin;
    }
    return bakery_sfb_normalize_origin($origin) === 'synthetic';
}

/**
 * SQL fragment that excludes synthetic bakers from wholesale ops.
 * Empty string when the column is not installed yet.
 *
 * @param string $alias customers table alias
 */
/** False when this customer must not enter wholesale ops. */
function bakery_sfb_ops_customer_allowed(PDO $db, $customerId) {
    $customerId = (int)$customerId;
    if ($customerId <= 0) {
        return false;
    }
    if (!bakery_sfb_origin_column_ready($db)) {
        return true;
    }
    $stmt = $db->prepare('SELECT sfb_origin FROM customers WHERE id = ? LIMIT 1');
    $stmt->execute([$customerId]);
    $origin = $stmt->fetchColumn();
    if ($origin === false) {
        return false;
    }
    return !bakery_sfb_is_synthetic($origin);
}

function bakery_sfb_ops_origin_clause($alias = 'c', PDO $db = null) {
    $db = $db instanceof PDO ? $db : ($GLOBALS['db'] ?? null);
    if (!bakery_sfb_origin_column_ready($db)) {
        return '';
    }
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias);
    if ($alias === '') {
        $alias = 'c';
    }
    return " AND COALESCE({$alias}.sfb_origin, 'human') <> 'synthetic'";
}

/** SQL fragment that keeps only human bakers (journey / owner metrics). */
function bakery_sfb_human_origin_clause($alias = 'c', PDO $db = null) {
    return bakery_sfb_ops_origin_clause($alias, $db);
}

/**
 * SELECT expression so community queries always expose sfb_origin.
 *
 * @param string $alias customers table alias
 */
function bakery_sfb_origin_select_sql($alias = 'c', PDO $db = null) {
    $db = $db instanceof PDO ? $db : ($GLOBALS['db'] ?? null);
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias);
    if ($alias === '') {
        $alias = 'c';
    }
    if (!bakery_sfb_origin_column_ready($db)) {
        return "'human' AS sfb_origin";
    }
    return "COALESCE({$alias}.sfb_origin, 'human') AS sfb_origin";
}

/**
 * @param array|string|null $rowOrOrigin
 * @param string $authorKind baker|coach
 */
function bakery_sfb_origin_badge_key($rowOrOrigin, $authorKind = '') {
    if (strtolower(trim((string)$authorKind)) === 'coach') {
        return 'sfb.origin_coach';
    }
    return bakery_sfb_is_synthetic($rowOrOrigin) ? 'sfb.origin_synthetic' : 'sfb.origin_human';
}

/**
 * Always-visible origin badge. Origin is a stored fact, not CSS.
 *
 * @param array|string|null $rowOrOrigin
 * @param string $authorKind baker|coach
 */
function bakery_sfb_render_origin_badge($rowOrOrigin, $authorKind = '') {
    $key = bakery_sfb_origin_badge_key($rowOrOrigin, $authorKind);
    $label = function_exists('bakery_t') ? bakery_t($key) : $key;
    $kind = strtolower(trim((string)$authorKind));
    if ($kind === 'coach') {
        $mod = 'coach';
    } elseif (bakery_sfb_is_synthetic($rowOrOrigin)) {
        $mod = 'synthetic';
    } else {
        $mod = 'human';
    }
    return '<span class="sfb-origin-badge sfb-origin-badge--' . $mod . '">'
        . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8')
        . '</span>';
}
