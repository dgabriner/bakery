<?php
/**
 * Purge local quarantine archives once their grace period lapses.
 * Default target: storage/quarantine/ (zip archives from the 2026-08-22 sweep).
 *
 * Usage:
 *   php scripts/purge_local_quarantine.php                 dry-run, 14-day TTL
 *   php scripts/purge_local_quarantine.php --days=7        custom TTL
 *   php scripts/purge_local_quarantine.php --yes           actually delete
 *   php scripts/purge_local_quarantine.php --path=C:\path\to\file.zip   extra targets
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
$days = 14;
$yes = false;
$targets = [$root . '/storage/quarantine'];

foreach (array_slice($GLOBALS['argv'], 1) as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $days = max(0, (int)$m[1]);
    } elseif ($arg === '--yes') {
        $yes = true;
    } elseif (preg_match('/^--path=(.+)$/', $arg, $m)) {
        foreach (explode(',', $m[1]) as $extra) {
            $extra = trim($extra, "\" \r\n");
            if ($extra !== '') {
                $targets[] = $extra;
            }
        }
    } else {
        fwrite(STDERR, "Unknown argument: $arg\n");
        exit(1);
    }
}

$cutoff = time() - ($days * 86400);
$deleted = 0;
$kept = 0;

foreach ($targets as $target) {
    if (!file_exists($target)) {
        echo "skip  (missing) $target\n";
        continue;
    }
    $paths = is_dir($target)
        ? array_merge(glob(rtrim($target, '/\\') . '/*') ?: [], glob(rtrim($target, '/\\') . '/.*') ?: [])
        : [$target];
    foreach ($paths as $path) {
        $name = basename($path);
        if ($name === '.' || $name === '..' || !is_file($path)) {
            continue;
        }
        $ageDays = floor((time() - (int)filemtime($path)) / 86400);
        if ((int)filemtime($path) <= $cutoff) {
            if ($yes) {
                unlink($path);
                echo "DELETED ($ageDays days old) $path\n";
            } else {
                echo "would delete ($ageDays days old) $path\n";
            }
            $deleted++;
        } else {
            echo "keep   ($ageDays days old) $path\n";
            $kept++;
        }
    }
}

echo "\n" . ($yes ? 'Purged' : 'Dry run (use --yes to delete)') . ": $deleted candidate(s), $kept kept, TTL {$days}d\n";
