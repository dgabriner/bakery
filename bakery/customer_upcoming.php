<?php
/**
 * Legacy customer upcoming-deliveries link — quarantined redirect-only stub.
 * Canonical screens: customer_portal_calendar.php (Deliveries tab) and
 * customer_upcoming_edit.php (one dated delivery). Do not rebuild pages here.
 */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';

$date = trim((string)($_GET['date'] ?? ''));
$dateObj = DateTime::createFromFormat('!Y-m-d', $date);
if ($dateObj && $dateObj->format('Y-m-d') === $date) {
    $target = 'customer_upcoming_edit.php?date=' . rawurlencode($date);
} else {
    $target = 'customer_portal_calendar.php';
}

$return = trim((string)($_GET['return'] ?? ''));
if ($return !== '' && preg_match('/^[a-z0-9_-]+\.php(?:[?#][^\r\n]*)?$/i', $return)) {
    $target .= (strpos($target, '?') === false ? '?' : '&') . 'return=' . rawurlencode($return);
}

header('Location: ' . BASE_URL . $target);
exit;
