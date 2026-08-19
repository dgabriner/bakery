<?php
/**
 * Minimal probe — no app bootstrap. Upload, visit, delete.
 * Add ?probe=1 for dashboard/demand diagnostics (admin debugging).
 */
header('Content-Type: text/plain; charset=utf-8');
echo "ping ok\n";
echo 'PHP ' . PHP_VERSION . "\n";
echo 'dir ' . __DIR__ . "\n";
echo 'time ' . date('c') . "\n";

$watch = [
    'driver_list.php', 'index.php', 'pack_list.php',
    'includes/demand_review.php', 'includes/dashboard_command_center.php',
    'includes/customer_order_mutations.php', 'includes/customer_notifications.php',
    'includes/pan_dulce_standards.php', 'includes/daily_brief.php',
];
foreach ($watch as $rel) {
    $target = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (is_file($target)) {
        echo $rel . ' bytes ' . filesize($target) . "\n";
    } else {
        echo $rel . " MISSING\n";
    }
}

if (!isset($_GET['probe'])) {
    return;
}

echo "\n=== probe ===\n";
define('ACCESS_ALLOWED', true);

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    echo "FATAL: {$err['message']}\n";
    echo "File: {$err['file']}:{$err['line']}\n";
});

try {
    require_once __DIR__ . '/includes/config.php';
    require_once __DIR__ . '/includes/database.php';
    echo "bootstrap ok\n";

    $date = date('Y-m-d', strtotime('+1 day'));
    require_once __DIR__ . '/includes/demand_review.php';
    echo "demand_review loaded\n";

    $step = static function (string $label, callable $fn): void {
        echo $label . '... ';
        @ob_flush();
        flush();
        try {
            $fn();
            echo "ok\n";
        } catch (Throwable $e) {
            echo "FAIL\n";
            echo '  ' . $e->getMessage() . "\n";
            echo '  ' . $e->getFile() . ':' . $e->getLine() . "\n";
            throw $e;
        }
        @ob_flush();
        flush();
    };

    $step('demand_review_build', static function () use ($db, $date): void {
        $review = bakery_demand_review_build($db, $date);
        echo '(' . count($review['customers'] ?? []) . ' customers) ';
    });

    $step('operating_demand_lines', static function () use ($db, $date): void {
        $lines = bakery_operating_demand_lines($db, $date);
        echo '(' . count($lines) . ' lines) ';
    });

    $step('dashboard_command_center load', static function (): void {
        require_once __DIR__ . '/includes/dashboard_command_center.php';
    });

    $step('command_center build', static function () use ($db, $date): void {
        $cc = bakery_dashboard_command_center($db, $date);
        echo '(' . count($cc['exceptions']) . ' exceptions) ';
    });
} catch (Throwable $e) {
    echo 'STOPPED: ' . $e->getMessage() . "\n";
}
