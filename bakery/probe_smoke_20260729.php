<?php
/**
 * One-file smoke test. Upload to bakery root, visit, delete.
 * Unique name so it cannot collide with cached old probes.
 */
header('Content-Type: text/plain; charset=utf-8');
echo "SMOKE_OK build=20260729c\n";
echo 'PHP ' . PHP_VERSION . "\n";
echo 'dir ' . __DIR__ . "\n";
echo 'time ' . date('c') . "\n\n";

$files = [
    'driver_list.php',
    'includes/page_probe.php',
    'includes/database.php',
    'includes/common_functions.php',
];

foreach ($files as $rel) {
    $path = __DIR__ . '/' . $rel;
    echo "--- {$rel} ---\n";
    if (!is_file($path)) {
        echo "MISSING\n\n";
        continue;
    }
    echo 'bytes=' . filesize($path) . "\n";
    echo 'mtime=' . date('c', filemtime($path)) . "\n";
    $src = file_get_contents($path);
    echo 'has probe build 20260729b: ' . (strpos($src, '20260729b') !== false ? 'YES' : 'NO') . "\n";
    echo 'has bakery_page_probe_bootstrap: ' . (strpos($src, 'bakery_page_probe_bootstrap') !== false ? 'YES' : 'NO') . "\n";
    echo 'has "config loaded" string: ' . (strpos($src, "config loaded") !== false ? 'YES (old probe text)' : 'NO') . "\n";
    echo 'has bakery_json_for_html: ' . (strpos($src, 'bakery_json_for_html') !== false ? 'YES' : 'NO') . "\n";
    echo "\n";
}

echo "If driver_list.php shows NO for 20260729b, your upload did not overwrite the live file.\n";
echo "Delete this file (probe_smoke_20260729.php) when done.\n";
