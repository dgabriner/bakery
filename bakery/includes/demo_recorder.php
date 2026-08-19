<?php
/**
 * Demo recorder helpers — scenario load/validate, bakerysf_local discovery, publish.
 * Local production-data mirror only. Never live production. Does not print login codes.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

function bakery_demo_recorder_root(): string
{
    return dirname(__DIR__);
}

function bakery_demo_recorder_scenario_dir(): string
{
    return bakery_demo_recorder_root() . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR
        . 'demo-recorder' . DIRECTORY_SEPARATOR . 'scenarios';
}

function bakery_demo_recorder_output_dir(): string
{
    return bakery_demo_recorder_root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
        . 'demo-recordings';
}

function bakery_demo_recorder_publish_dir(): string
{
    return bakery_demo_recorder_root() . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR
        . 'walkthroughs';
}

function bakery_demo_recorder_allowed_actions(): array
{
    return [
        'goto',
        'fill',
        'click',
        'clickIf',
        'dragTo',
        'hover',
        'press',
        'wait',
        'waitForSelector',
        'waitForURL',
        'waitForText',
        'scroll',
        'caption',
        'reload',
    ];
}

function bakery_demo_recorder_locales(): array
{
    return ['en', 'es'];
}

function bakery_demo_recorder_normalize_locale(string $locale): string
{
    $locale = strtolower(trim($locale));
    return in_array($locale, bakery_demo_recorder_locales(), true) ? $locale : 'en';
}

function bakery_demo_recorder_localized($value, string $locale): string
{
    $locale = bakery_demo_recorder_normalize_locale($locale);
    if (is_array($value)) {
        if (isset($value[$locale]) && is_string($value[$locale])) {
            return $value[$locale];
        }
        if (isset($value['en']) && is_string($value['en'])) {
            return $value['en'];
        }
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                return $item;
            }
        }
        return '';
    }
    return is_string($value) ? $value : '';
}

function bakery_demo_recorder_list_scenarios(): array
{
    $dir = bakery_demo_recorder_scenario_dir();
    $out = [];
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
        $data = bakery_demo_recorder_load_scenario($path);
        $out[] = [
            'id' => (string)($data['id'] ?? basename($path, '.json')),
            'title' => $data['title'] ?? '',
            'description' => $data['description'] ?? '',
            'path' => $path,
        ];
    }
    $order = [
        'login' => 0,
        'daily-run' => 1,
        'admin-route-build' => 2,
        'admin-route-reorder' => 3,
        'admin-route-verify' => 4,
        'driver-assignment' => 5,
        'adjust-route' => 6,
        'driver-login' => 10,
        'driver-tomorrow' => 11,
        'driver-complete-stop' => 12,
        'driver-skip-stop' => 13,
        'driver-adjust-route' => 14,
        'driver-call-hq' => 15,
    ];
    usort($out, static function ($a, $b) use ($order) {
        $oa = $order[$a['id']] ?? 50;
        $ob = $order[$b['id']] ?? 50;
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }
        return strcmp($a['id'], $b['id']);
    });
    return $out;
}

function bakery_demo_recorder_resolve_scenario_path(string $idOrPath): string
{
    $idOrPath = trim($idOrPath);
    if ($idOrPath === '') {
        throw new InvalidArgumentException('Scenario id or path is required');
    }
    if (is_file($idOrPath)) {
        return $idOrPath;
    }
    $root = bakery_demo_recorder_root();
    $candidates = [
        $idOrPath,
        $root . DIRECTORY_SEPARATOR . $idOrPath,
        bakery_demo_recorder_scenario_dir() . DIRECTORY_SEPARATOR . $idOrPath,
        bakery_demo_recorder_scenario_dir() . DIRECTORY_SEPARATOR . $idOrPath . '.json',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    throw new InvalidArgumentException('Scenario not found: ' . $idOrPath);
}

function bakery_demo_recorder_load_scenario(string $idOrPath): array
{
    $path = bakery_demo_recorder_resolve_scenario_path($idOrPath);
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('Could not read scenario: ' . $path);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Scenario is not valid JSON: ' . $path);
    }
    $data['_path'] = $path;
    if (empty($data['id'])) {
        $data['id'] = basename($path, '.json');
    }
    bakery_demo_recorder_validate_scenario($data);
    return $data;
}

function bakery_demo_recorder_validate_scenario(array $data): void
{
    if (trim((string)($data['id'] ?? '')) === '') {
        throw new InvalidArgumentException('Scenario needs an id');
    }
    if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', (string)$data['id'])) {
        throw new InvalidArgumentException('Scenario id must be lowercase letters, numbers, and hyphens');
    }
    $steps = $data['steps'] ?? null;
    if (!is_array($steps) || $steps === []) {
        throw new InvalidArgumentException('Scenario needs a non-empty steps array');
    }
    $allowed = bakery_demo_recorder_allowed_actions();
    foreach ($steps as $index => $step) {
        if (!is_array($step)) {
            throw new InvalidArgumentException('Step ' . $index . ' must be an object');
        }
        $action = (string)($step['action'] ?? '');
        if (!in_array($action, $allowed, true)) {
            throw new InvalidArgumentException('Step ' . $index . ' has unknown action: ' . $action);
        }
        if (in_array($action, ['fill', 'click', 'clickIf', 'dragTo', 'hover', 'waitForSelector', 'scroll'], true)
            && trim((string)($step['selector'] ?? '')) === '') {
            throw new InvalidArgumentException('Step ' . $index . ' (' . $action . ') needs selector');
        }
        if ($action === 'dragTo' && trim((string)($step['targetSelector'] ?? '')) === '') {
            throw new InvalidArgumentException('Step ' . $index . ' (dragTo) needs targetSelector');
        }
        if ($action === 'goto' && trim((string)($step['path'] ?? '')) === '') {
            throw new InvalidArgumentException('Step ' . $index . ' (goto) needs path');
        }
        if ($action === 'fill' && !array_key_exists('value', $step)) {
            throw new InvalidArgumentException('Step ' . $index . ' (fill) needs value');
        }
        if ($action === 'wait' && (int)($step['ms'] ?? 0) <= 0) {
            throw new InvalidArgumentException('Step ' . $index . ' (wait) needs ms');
        }
        if ($action === 'waitForURL' && trim((string)($step['includes'] ?? $step['equals'] ?? '')) === '') {
            throw new InvalidArgumentException('Step ' . $index . ' (waitForURL) needs includes or equals');
        }
        if (preg_match('/"(?:value|code)"\\s*:\\s*"\\d{4}"/', json_encode($step) ?: '')) {
            throw new InvalidArgumentException(
                'Step ' . $index . ' hard-codes a login code; use {{ADMIN_CODE}} or {{DRIVER_CODE}}'
            );
        }
    }
}

function bakery_demo_recorder_python_candidates(string $root): array
{
    $venvWin = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'demo-recorder'
        . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    $venvUnix = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'demo-recorder'
        . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
    return array_values(array_filter([$venvWin, $venvUnix], 'is_file'));
}

function bakery_demo_recorder_venv_create_commands(string $venvDir): array
{
    if (PHP_OS_FAMILY === 'Windows') {
        return [
            ['python', '-m', 'venv', $venvDir],
            ['py', '-3', '-m', 'venv', $venvDir],
        ];
    }
    return [
        ['python3', '-m', 'venv', $venvDir],
        ['python', '-m', 'venv', $venvDir],
    ];
}

function bakery_demo_recorder_merged_env(array $extra): array
{
    $env = [];
    foreach (array_merge($_SERVER, $_ENV) as $key => $value) {
        if (is_string($key) && is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            $env[$key] = $value;
        }
    }
    foreach (['PATH', 'Path', 'SystemRoot', 'WINDIR', 'USERPROFILE', 'HOME', 'TEMP', 'TMP', 'PATHEXT', 'LOCALAPPDATA'] as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            $env[$key] = $value;
        }
    }
    return array_merge($env, $extra);
}

function bakery_demo_recorder_run_command(array $command, string $cwd, array $env = []): int
{
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => STDOUT,
        2 => STDERR,
    ];
    $processEnv = $env === [] ? null : bakery_demo_recorder_merged_env($env);
    $proc = proc_open($command, $descriptor, $pipes, $cwd, $processEnv);
    if (!is_resource($proc)) {
        throw new RuntimeException('Could not start: ' . implode(' ', $command));
    }
    fclose($pipes[0]);
    return proc_close($proc);
}

function bakery_demo_recorder_bootstrap_python(string $root, bool $quiet = false): string
{
    $toolDir = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'demo-recorder';
    $requirements = $toolDir . DIRECTORY_SEPARATOR . 'requirements.txt';
    if (!is_readable($requirements)) {
        throw new RuntimeException('Missing tools/demo-recorder/requirements.txt');
    }
    $candidates = bakery_demo_recorder_python_candidates($root);
    if ($candidates === []) {
        if (!$quiet) {
            echo "Creating demo recorder Python venv...\n";
        }
        $venvDir = $toolDir . DIRECTORY_SEPARATOR . '.venv';
        $created = false;
        foreach (bakery_demo_recorder_venv_create_commands($venvDir) as $create) {
            $code = bakery_demo_recorder_run_command($create, $root);
            if ($code === 0) {
                $created = true;
                break;
            }
        }
        if (!$created) {
            throw new RuntimeException('Failed to create Python venv for the demo recorder');
        }
        $candidates = bakery_demo_recorder_python_candidates($root);
    }
    if ($candidates === []) {
        throw new RuntimeException('Python venv exists but python.exe was not found');
    }
    $python = $candidates[0];
    $marker = $toolDir . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . '.deps-ok';
    $needInstall = !is_file($marker) || filemtime($marker) < filemtime($requirements);
    if ($needInstall) {
        if (!$quiet) {
            echo "Installing Playwright + ffmpeg for demo recording...\n";
        }
        $pip = bakery_demo_recorder_run_command(
            [$python, '-m', 'pip', 'install', '--upgrade', 'pip'],
            $toolDir
        );
        if ($pip !== 0) {
            throw new RuntimeException('pip upgrade failed');
        }
        $deps = bakery_demo_recorder_run_command(
            [$python, '-m', 'pip', 'install', '-r', $requirements],
            $toolDir
        );
        if ($deps !== 0) {
            throw new RuntimeException('pip install of demo recorder requirements failed');
        }
        bakery_demo_recorder_run_command(
            [$python, '-m', 'playwright', 'install', 'chromium'],
            $toolDir
        );
        bakery_demo_recorder_run_command(
            [$python, '-m', 'playwright', 'install', 'ffmpeg'],
            $toolDir
        );
        file_put_contents($marker, (string)time());
    }
    return $python;
}

function bakery_demo_recorder_driver_scenario_ids(): array
{
    require_once bakery_demo_recorder_root() . '/includes/walkthroughs.php';
    return array_column(bakery_driver_walkthrough_items(), 'id');
}

function bakery_demo_recorder_assert_local(): void
{
    if (!defined('IS_LOCAL') || !IS_LOCAL || (defined('USE_PROD_DB') && USE_PROD_DB)) {
        throw new RuntimeException('Demo recorder is local only (APP_ENV=local, USE_PROD_DB=false). Live production is refused.');
    }
}

function bakery_demo_recorder_connect(string $root): PDO
{
    bakery_demo_recorder_assert_local();
    require_once $root . '/includes/database.php';
    require_once $root . '/includes/test_target_guard.php';
    $db = check_mysql_connection();
    bakery_assert_local_test_target($db);
    $name = strtolower((string)$db->query('SELECT DATABASE()')->fetchColumn());
    if ($name !== 'bakerysf_local') {
        throw new RuntimeException('Walkthroughs use the local production-data mirror bakerysf_local, not ' . $name);
    }
    return $db;
}

function bakery_demo_recorder_admin_code(): string
{
    require_once bakery_demo_recorder_root() . '/includes/auth.php';
    $code = (string)($_ENV['LOCAL_ADMIN_CODE'] ?? getenv('LOCAL_ADMIN_CODE') ?: '');
    if (defined('BAKERY_ADMIN_CODE') && BAKERY_ADMIN_CODE) {
        $code = $code !== '' ? $code : (string)BAKERY_ADMIN_CODE;
    }
    $code = bakery_normalize_login_code($code);
    if ($code === '') {
        throw new RuntimeException('Set LOCAL_ADMIN_CODE in .env for walkthrough recording');
    }
    return $code;
}

function bakery_demo_recorder_discover_route(PDO $db): array
{
    require_once bakery_demo_recorder_root() . '/includes/auth.php';
    $sql = "
        SELECT doa.driver_id, doa.delivery_date, COUNT(*) AS pending
        FROM daily_order_assignments doa
        INNER JOIN users u ON u.driver_id = doa.driver_id
            AND u.is_active = 1
            AND u.login_code IS NOT NULL
            AND u.login_code <> ''
        INNER JOIN roles r ON r.id = u.role_id AND r.slug IN ('driver', 'driver_assistant')
        WHERE doa.delivery_status IN ('pending', 'in_transit')
        GROUP BY doa.driver_id, doa.delivery_date
        HAVING pending >= 1
        ORDER BY ABS(DATEDIFF(doa.delivery_date, CURDATE())) ASC, pending DESC, doa.delivery_date DESC
        LIMIT 1
    ";
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    $driverCode = '';
    if (!empty($row['driver_id'])) {
        $codeStmt = $db->prepare(
            "SELECT u.login_code
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.driver_id = ? AND u.is_active = 1 AND u.login_code IS NOT NULL AND u.login_code <> ''
               AND r.slug IN ('driver', 'driver_assistant')
             LIMIT 1"
        );
        $codeStmt->execute([(int)$row['driver_id']]);
        $driverCode = bakery_normalize_login_code((string)$codeStmt->fetchColumn());
    }
    return [
        'admin_code' => bakery_demo_recorder_admin_code(),
        'driver_code' => $driverCode,
        'driver_id' => (int)($row['driver_id'] ?? 0),
        'date' => (string)($row['delivery_date'] ?? date('Y-m-d')),
        'pending' => (int)($row['pending'] ?? 0),
    ];
}

/**
 * Find a real dated route that an administrator can safely reorder and inspect.
 * Prefer a driver with at least three pending stops so the recording is legible.
 */
function bakery_demo_recorder_discover_admin_route(PDO $db): array
{
    $sql = "
        SELECT doa.driver_id, doa.delivery_date, COUNT(*) AS pending
        FROM daily_order_assignments doa
        WHERE COALESCE(doa.delivery_status, 'pending') = 'pending'
        GROUP BY doa.driver_id, doa.delivery_date
        HAVING pending >= 3
        ORDER BY ABS(DATEDIFF(doa.delivery_date, CURDATE())) ASC, pending DESC, doa.delivery_date DESC
        LIMIT 1
    ";
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($row['driver_id'] ?? 0) <= 0) {
        throw new RuntimeException('No bakerysf_local route has 3+ pending stops for the Admin route walkthroughs');
    }
    return [
        'driver_id' => (int)$row['driver_id'],
        'date' => (string)$row['delivery_date'],
        'pending' => (int)$row['pending'],
    ];
}

/**
 * Pick an unused near-future operating date with a standing route. The build
 * walkthrough owns only this clean date and removes it again after recording.
 */
function bakery_demo_recorder_discover_clean_route_date(PDO $db): array
{
    $start = new DateTimeImmutable('tomorrow');
    for ($offset = 0; $offset < 35; $offset++) {
        $date = $start->modify('+' . $offset . ' days')->format('Y-m-d');
        $day = (int)(new DateTimeImmutable($date))->format('N');
        $standing = $db->prepare(
            'SELECT driver_id, COUNT(*) AS stops
             FROM standing_routes
             WHERE CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END = ?
             GROUP BY driver_id
             ORDER BY stops DESC, driver_id
             LIMIT 1'
        );
        $standing->execute([$day]);
        $route = $standing->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int)($route['driver_id'] ?? 0) <= 0) {
            continue;
        }
        $used = $db->prepare('SELECT COUNT(*) FROM daily_orders WHERE order_date = ?');
        $used->execute([$date]);
        if ((int)$used->fetchColumn() === 0) {
            return [
                'driver_id' => (int)$route['driver_id'],
                'date' => $date,
                'pending' => (int)$route['stops'],
            ];
        }
    }
    throw new RuntimeException('No clean near-future standing-route date is available for recording');
}

function bakery_demo_recorder_snapshot_route(PDO $db, int $driverId, string $date): array
{
    if ($driverId <= 0 || $date === '') {
        return [];
    }
    $cols = 'daily_order_id, route_order, delivery_status';
    if (function_exists('column_exists') && column_exists($db, 'daily_order_assignments', 'notes')) {
        $cols .= ', notes';
    }
    $stmt = $db->prepare(
        "SELECT {$cols}
         FROM daily_order_assignments
         WHERE driver_id = ? AND delivery_date = ?
         ORDER BY route_order, id"
    );
    $stmt->execute([$driverId, $date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bakery_demo_recorder_assignment_statuses(PDO $db): array
{
    $row = $db->query("SHOW COLUMNS FROM daily_order_assignments LIKE 'delivery_status'")->fetch(PDO::FETCH_ASSOC) ?: [];
    $type = (string)($row['Type'] ?? '');
    if (preg_match_all("/'([^']+)'/", $type, $matches)) {
        return $matches[1];
    }
    return ['pending', 'in_transit', 'delivered', 'failed', 'rescheduled'];
}

function bakery_demo_recorder_legal_assignment_status(string $status, array $allowed): ?string
{
    if ($status === '') {
        return null;
    }
    if (in_array($status, $allowed, true)) {
        return $status;
    }
    // Skip in the app writes cancelled. On mirrors without that ENUM value,
    // restore the stop as pending so the walkthrough can submit and roll back.
    if ($status === 'cancelled' && in_array('pending', $allowed, true)) {
        return 'pending';
    }
    return null;
}

function bakery_demo_recorder_discover_consecutive_dates(PDO $db, int $driverId): array
{
    if ($driverId <= 0) {
        return [];
    }
    $sql = "
        SELECT a.delivery_date AS today_date,
               DATE_ADD(a.delivery_date, INTERVAL 1 DAY) AS tomorrow_date
        FROM daily_order_assignments a
        INNER JOIN daily_order_assignments b
            ON b.driver_id = a.driver_id
           AND b.delivery_date = DATE_ADD(a.delivery_date, INTERVAL 1 DAY)
           AND b.delivery_status IN ('pending', 'in_transit')
        WHERE a.driver_id = ?
          AND a.delivery_status IN ('pending', 'in_transit')
        GROUP BY a.delivery_date
        ORDER BY ABS(DATEDIFF(a.delivery_date, CURDATE())) ASC, a.delivery_date DESC
        LIMIT 1
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$driverId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $today = (string)($row['today_date'] ?? '');
    $tomorrow = (string)($row['tomorrow_date'] ?? '');
    if ($today === '' || $tomorrow === '') {
        return [];
    }
    return ['today' => $today, 'tomorrow' => $tomorrow];
}

function bakery_demo_recorder_restore_route(PDO $db, int $driverId, string $date, array $snapshot): void
{
    if ($snapshot === [] || $driverId <= 0) {
        return;
    }
    $hasNotes = array_key_exists('notes', $snapshot[0]);
    $allowed = bakery_demo_recorder_assignment_statuses($db);
    try {
        $park = $db->prepare(
            'UPDATE daily_order_assignments
             SET route_order = ? + 10000
             WHERE driver_id = ? AND delivery_date = ? AND daily_order_id = ?'
        );
        foreach ($snapshot as $i => $row) {
            $park->execute([(int)$i + 1, $driverId, $date, (int)$row['daily_order_id']]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'Restore park skipped: ' . $e->getMessage() . "\n");
    }
    foreach ($snapshot as $row) {
        try {
            $status = bakery_demo_recorder_legal_assignment_status((string)($row['delivery_status'] ?? ''), $allowed);
            $set = ['route_order = ?'];
            $params = [(int)$row['route_order']];
            if ($status !== null) {
                $set[] = 'delivery_status = ?';
                $params[] = $status;
            }
            if ($hasNotes) {
                $set[] = 'notes = ?';
                $params[] = $row['notes'];
            }
            $params[] = $driverId;
            $params[] = $date;
            $params[] = (int)$row['daily_order_id'];
            $sql = 'UPDATE daily_order_assignments SET ' . implode(', ', $set)
                . ' WHERE driver_id = ? AND delivery_date = ? AND daily_order_id = ?';
            $db->prepare($sql)->execute($params);
        } catch (Throwable $e) {
            fwrite(STDERR, 'Restore row skipped: ' . $e->getMessage() . "\n");
        }
    }
}

/** Remove only the dated demand/route rows created by an Admin build recording. */
function bakery_demo_recorder_cleanup_generated_date(PDO $db, string $date, int $eventWatermark = 0): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new InvalidArgumentException('Cleanup date must be YYYY-MM-DD');
    }
    $db->beginTransaction();
    try {
        $orderIdsStmt = $db->prepare('SELECT id FROM daily_orders WHERE order_date = ?');
        $orderIdsStmt->execute([$date]);
        $orderIds = array_map('intval', $orderIdsStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        $db->prepare('DELETE FROM daily_order_assignments WHERE delivery_date = ?')->execute([$date]);
        if ($orderIds !== []) {
            $marks = implode(',', array_fill(0, count($orderIds), '?'));
            $db->prepare("DELETE FROM daily_order_items WHERE daily_order_id IN ($marks)")->execute($orderIds);
        }
        $db->prepare('DELETE FROM daily_orders WHERE order_date = ?')->execute([$date]);
        if ($eventWatermark > 0 && table_exists($db, 'operational_events')) {
            $db->prepare('DELETE FROM operational_events WHERE id > ? AND operational_date = ?')
                ->execute([$eventWatermark, $date]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function bakery_demo_recorder_prepare(string $root, array $scenario): array
{
    $db = bakery_demo_recorder_connect($root);
    $codes = bakery_demo_recorder_discover_route($db);
    $kind = (string)($scenario['prepare'] ?? '');
    if ($kind === 'admin-route-build') {
        $adminRoute = bakery_demo_recorder_discover_clean_route_date($db);
        $codes = array_merge($codes, $adminRoute);
        $codes['cleanup_generated_date'] = true;
        $codes['event_watermark'] = table_exists($db, 'operational_events')
            ? (int)$db->query('SELECT COALESCE(MAX(id), 0) FROM operational_events')->fetchColumn()
            : 0;
    } elseif (in_array($kind, ['admin-route-reorder', 'admin-route-verify'], true)) {
        $codes = array_merge($codes, bakery_demo_recorder_discover_admin_route($db));
    }
    $needsDriverCode = in_array($kind, ['driver-login', 'driver-route', 'adjust-route', 'skip-stop'], true);
    if ($needsDriverCode && ((int)$codes['driver_id'] <= 0 || (string)$codes['driver_code'] === '')) {
        throw new RuntimeException('No driver login on bakerysf_local with remaining stops to record');
    }
    if ($kind === 'adjust-route' && (int)$codes['pending'] < 2) {
        throw new RuntimeException('No local-mirror route with 2+ remaining stops to demonstrate adjust');
    }
    if (in_array($kind, ['driver-route', 'skip-stop'], true) && (int)$codes['pending'] < 1) {
        throw new RuntimeException('No local-mirror remaining stop to demonstrate this driver walkthrough');
    }
    $codes['today'] = (string)$codes['date'];
    $codes['tomorrow'] = date('Y-m-d', strtotime((string)$codes['date'] . ' +1 day'));
    $pair = bakery_demo_recorder_discover_consecutive_dates($db, (int)$codes['driver_id']);
    if ($pair !== []) {
        $codes['today'] = $pair['today'];
        $codes['tomorrow'] = $pair['tomorrow'];
    }
    $codes['route_snapshot'] = [];
    if (in_array($kind, ['adjust-route', 'skip-stop', 'admin-route-reorder'], true)) {
        $codes['route_snapshot'] = bakery_demo_recorder_snapshot_route(
            $db,
            (int)$codes['driver_id'],
            (string)$codes['date']
        );
    }
    return $codes;
}

function bakery_demo_recorder_publish(string $mp4, string $scenarioId, string $locale): string
{
    $locale = bakery_demo_recorder_normalize_locale($locale);
    if (!is_file($mp4)) {
        throw new RuntimeException('MP4 not found to publish');
    }
    $dir = bakery_demo_recorder_publish_dir();
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create assets/walkthroughs');
    }
    $dest = $dir . DIRECTORY_SEPARATOR . $scenarioId . '-' . $locale . '.mp4';
    if (!copy($mp4, $dest)) {
        throw new RuntimeException('Could not copy MP4 into the walkthroughs gallery');
    }
    return $dest;
}
