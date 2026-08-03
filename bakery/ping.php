<?php
/**
 * Minimal probe — no app bootstrap. Upload, visit, delete.
 * If this is empty too, PHP is not running in this folder or the file did not upload.
 */
header('Content-Type: text/plain; charset=utf-8');
echo "ping ok\n";
echo 'PHP ' . PHP_VERSION . "\n";
echo 'dir ' . __DIR__ . "\n";
echo 'time ' . date('c') . "\n";

$target = __DIR__ . '/driver_list.php';
if (is_file($target)) {
    echo 'driver_list.php bytes ' . filesize($target) . "\n";
} else {
    echo "driver_list.php MISSING\n";
}
