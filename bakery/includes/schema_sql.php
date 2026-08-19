<?php
/**
 * Shared SQL migration file runner (CLI migrations + runtime schema ensure).
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_run_sql_file(PDO $db, $path) {
    foreach (bakery_parse_sql_file($path) as $statement) {
        $db->exec($statement);
    }
}

/** Run statements from a SQL file; ignore errors (idempotent runtime ensure). */
function bakery_run_sql_file_safe(PDO $db, $path) {
    if (!is_readable($path)) {
        return false;
    }
    foreach (bakery_parse_sql_file($path) as $statement) {
        try {
            $db->exec($statement);
        } catch (Throwable $e) {
            // Duplicate table/column/seed rows — safe to ignore during ensure.
        }
    }
    return true;
}

/** @return list<string> */
function bakery_parse_sql_file($path) {
    if (!is_readable($path)) {
        throw new RuntimeException("SQL file not readable: {$path}");
    }
    $sql = file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $buf = '';
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (strpos($trim, '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    $statements = [];
    $current = '';
    $inString = false;
    $len = strlen($buf);
    for ($i = 0; $i < $len; $i++) {
        $ch = $buf[$i];
        if ($ch === "'" && ($i === 0 || $buf[$i - 1] !== '\\')) {
            $inString = !$inString;
            $current .= $ch;
            continue;
        }
        if ($ch === ';' && !$inString) {
            $statement = trim($current);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $current = '';
            continue;
        }
        $current .= $ch;
    }
    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }
    return $statements;
}
