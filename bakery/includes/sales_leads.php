<?php
/**
 * Sales Leads. Prospects live on the existing leads table.
 *
 * external_id is the business id (SFL-001). The integer id stays so
 * lead_contacts and leads.customer_id keep working.
 *
 * Phase 2 plugs in by creating a customer from a Won lead and setting
 * leads.customer_id. Phone and email are copied from contact_channel when
 * they can be read, and the older columns are left in place.
 *
 * Phase 3 plugs in by creating one dated order tagged sample and storing
 * its id on leads.sample_daily_order_id. Delivery zone export stays on
 * zones. zone_id is only a link from the route_fit text.
 *
 * The read API uses SALES_LEADS_TOKEN the same way the today endpoint uses
 * DAILY_STATUS_TOKEN. Never commit a real token.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/auth.php';

function bakery_sales_leads_stages(): array
{
    return ['New', 'Researched', 'Contacted', 'Sampling', 'Proposal', 'Won', 'Lost'];
}

function bakery_sales_leads_priorities(): array
{
    return ['A', 'B', 'C'];
}

/** @return list<string> */
function bakery_sales_leads_csv_columns(): array
{
    return [
        'id', 'name', 'type', 'address', 'neighborhood', 'cuisine', 'website',
        'contact_channel', 'contact_verified', 'current_supplier', 'route_fit',
        'fit_score', 'fit_reason', 'priority', 'stage', 'next_action',
        'next_action_date', 'notes', 'source', 'source_url', 'last_updated',
    ];
}

function bakery_sales_leads_stage_to_status(string $stage): string
{
    $map = [
        'New' => 'new',
        'Researched' => 'interested',
        'Contacted' => 'contacted',
        'Sampling' => 'qualified',
        'Proposal' => 'qualified',
        'Won' => 'converted',
        'Lost' => 'closed',
    ];
    return $map[$stage] ?? 'new';
}

function bakery_sales_leads_ready(PDO $db): bool
{
    return function_exists('column_exists')
        && column_exists($db, 'leads', 'external_id')
        && column_exists($db, 'leads', 'stage')
        && column_exists($db, 'leads', 'sample_daily_order_id')
        && function_exists('table_exists')
        && table_exists($db, 'lead_activity');
}

function bakery_sales_leads_role_status(): int
{
    $user = bakery_current_user();
    if (!is_array($user) || (int)($user['id'] ?? 0) <= 0) {
        return 401;
    }
    if (!bakery_user_has_role(['administrator', 'manager'])) {
        return 403;
    }
    return 200;
}

function bakery_sales_leads_require_access(): void
{
    $status = bakery_sales_leads_role_status();
    if ($status === 200) {
        return;
    }
    if ($status === 401) {
        bakery_require_login();
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo bakery_sales_leads_error_text('forbidden');
    exit;
}

function bakery_sales_leads_configured_token(): string
{
    $raw = $_ENV['SALES_LEADS_TOKEN'] ?? getenv('SALES_LEADS_TOKEN');
    if ($raw === false || $raw === null) {
        return '';
    }
    return trim((string)$raw);
}

/**
 * @param array<string, mixed> $server
 */
function bakery_sales_leads_presented_token(array $server): string
{
    $header = (string)($server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string)$name, 'Authorization') === 0) {
                    $header = (string)$value;
                    break;
                }
            }
        }
    }
    if (preg_match('/^Bearer\s+(\S+)\s*$/i', trim($header), $matches) !== 1) {
        return '';
    }
    return (string)$matches[1];
}

/**
 * 200 for an administrator or manager session, or a matching bearer token.
 * 403 for any other signed-in role. 401 when nobody authenticated.
 *
 * @param array<string, mixed> $server
 */
function bakery_sales_leads_api_auth_status(array $server): int
{
    $user = bakery_current_user();
    if (is_array($user) && (int)($user['id'] ?? 0) > 0) {
        if (bakery_user_has_role(['administrator', 'manager'])) {
            return 200;
        }
        return 403;
    }

    $expected = bakery_sales_leads_configured_token();
    $provided = bakery_sales_leads_presented_token($server);
    if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
        return 200;
    }
    return 401;
}

/**
 * Read-only. GET JSON, or GET ?format=csv.
 *
 * @param array<string, mixed> $query
 * @param array<string, mixed> $server
 * @return array{status:int,content_type:string,body:array<string,mixed>|string,filename?:string}
 */
function bakery_sales_leads_api_dispatch(PDO $db, string $method, array $query, array $server): array
{
    if (strtoupper($method) !== 'GET') {
        return [
            'status' => 405,
            'content_type' => 'application/json; charset=utf-8',
            'body' => ['ok' => false, 'error' => 'read_only'],
        ];
    }
    $auth = bakery_sales_leads_api_auth_status($server);
    if ($auth !== 200) {
        return [
            'status' => $auth,
            'content_type' => 'application/json; charset=utf-8',
            'body' => ['ok' => false, 'error' => $auth === 403 ? 'forbidden' : 'unauthorized'],
        ];
    }
    if (!bakery_sales_leads_ready($db)) {
        return [
            'status' => 500,
            'content_type' => 'application/json; charset=utf-8',
            'body' => ['ok' => false, 'error' => 'schema_missing'],
        ];
    }
    $rows = bakery_sales_leads_list($db, [
        'stage' => (string)($query['stage'] ?? ''),
        'priority' => (string)($query['priority'] ?? ''),
        'zone' => (string)($query['zone'] ?? ''),
    ]);
    if ((string)($query['format'] ?? '') === 'csv') {
        return [
            'status' => 200,
            'content_type' => 'text/csv; charset=utf-8',
            'filename' => 'sales-leads.csv',
            'body' => bakery_sales_leads_export_csv($rows),
        ];
    }
    $leads = [];
    foreach ($rows as $row) {
        $leads[] = bakery_sales_leads_public($row);
    }
    return [
        'status' => 200,
        'content_type' => 'application/json; charset=utf-8',
        'body' => ['ok' => true, 'leads' => $leads],
    ];
}

function bakery_sales_leads_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bakery_sales_leads_error_text(string $code): string
{
    $key = 'leads.error.' . $code;
    if (!function_exists('bakery_t')) {
        return $code;
    }
    $text = bakery_t($key);
    return $text === $key ? $code : $text;
}

/**
 * @return list<array{id:int,name:string}>
 */
function bakery_sales_leads_zones(PDO $db): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'zones')) {
        return [];
    }
    $rows = $db->query(
        'SELECT id, name FROM zones ORDER BY CHAR_LENGTH(name) DESC, name ASC'
    )->fetchAll();
    $zones = [];
    foreach ($rows as $row) {
        $zones[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    }
    return $zones;
}

/**
 * @param list<array{id:int,name:string}> $zones
 */
function bakery_sales_leads_match_zone_id(array $zones, string $routeFit): ?int
{
    if (trim($routeFit) === '') {
        return null;
    }
    foreach ($zones as $zone) {
        $name = trim((string)$zone['name']);
        if (mb_strlen($name) < 3) {
            continue;
        }
        if (mb_stripos($routeFit, $name) !== false) {
            return (int)$zone['id'];
        }
    }
    return null;
}

function bakery_sales_leads_text($value): ?string
{
    if ($value === null) {
        return null;
    }
    $text = trim((string)$value);
    return $text === '' ? null : $text;
}

function bakery_sales_leads_clip($value, int $max): ?string
{
    $text = bakery_sales_leads_text($value);
    if ($text === null) {
        return null;
    }
    if (mb_strlen($text) > $max) {
        $text = mb_substr($text, 0, $max);
    }
    return $text;
}

function bakery_sales_leads_date_only($value): ?string
{
    $text = bakery_sales_leads_text($value);
    if ($text === null) {
        return null;
    }
    $text = substr($text, 0, 10);
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    if (!$parsed || $parsed->format('Y-m-d') !== $text) {
        return null;
    }
    return $text;
}

function bakery_sales_leads_parse_email(?string $channel): ?string
{
    $channel = (string)$channel;
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $channel, $matches) !== 1) {
        return null;
    }
    $email = (string)$matches[0];
    if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return $email;
}

function bakery_sales_leads_parse_phone(?string $channel): ?string
{
    $channel = (string)$channel;
    if (preg_match('/\(?\d{3}\)?[\s.\-]*\d{3}[\s.\-]*\d{4}/', $channel, $matches) !== 1) {
        return null;
    }
    $phone = (string)$matches[0];
    if (strlen($phone) > 20) {
        return null;
    }
    return $phone;
}

/**
 * @param array<string, string> $values
 * @param array<string, string> $extras
 * @return array{ok:bool,error:string,record:?array<string,mixed>}
 */
function bakery_sales_leads_normalize(array $values, array $extras): array
{
    $fail = static function (string $error): array {
        return ['ok' => false, 'error' => $error, 'record' => null];
    };

    $name = bakery_sales_leads_clip($values['name'] ?? '', 255);
    if ($name === null) {
        return $fail('missing_name');
    }

    $external = bakery_sales_leads_clip($values['id'] ?? '', 64);
    if ($external !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $external) !== 1) {
        return $fail('bad_id');
    }

    $stageRaw = bakery_sales_leads_text($values['stage'] ?? '');
    $stage = 'New';
    if ($stageRaw !== null) {
        $stage = null;
        foreach (bakery_sales_leads_stages() as $candidate) {
            if (strcasecmp($stageRaw, $candidate) === 0) {
                $stage = $candidate;
                break;
            }
        }
        if ($stage === null) {
            return $fail('bad_stage');
        }
    }

    $priorityRaw = bakery_sales_leads_text($values['priority'] ?? '');
    $priority = null;
    if ($priorityRaw !== null) {
        $priority = strtoupper($priorityRaw);
        if (!in_array($priority, bakery_sales_leads_priorities(), true)) {
            return $fail('bad_priority');
        }
    }

    $scoreRaw = bakery_sales_leads_text($values['fit_score'] ?? '');
    $score = null;
    if ($scoreRaw !== null) {
        if (preg_match('/^[1-5]$/', $scoreRaw) !== 1) {
            return $fail('bad_fit_score');
        }
        $score = (int)$scoreRaw;
    }

    foreach (['next_action_date' => 'bad_next_action_date', 'last_updated' => 'bad_last_updated'] as $field => $error) {
        $raw = bakery_sales_leads_text($values[$field] ?? '');
        if ($raw !== null && bakery_sales_leads_date_only($raw) === null) {
            return $fail($error);
        }
    }

    $channel = bakery_sales_leads_clip($values['contact_channel'] ?? '', 255);
    $record = [
        'external_id' => $external,
        'customer_name' => $name,
        'phone' => bakery_sales_leads_parse_phone($channel),
        'email' => bakery_sales_leads_parse_email($channel),
        'address' => bakery_sales_leads_text($values['address'] ?? ''),
        'notes' => bakery_sales_leads_merge_notes($values['notes'] ?? '', $extras),
        'status' => bakery_sales_leads_stage_to_status($stage),
        'lead_type' => bakery_sales_leads_clip($values['type'] ?? '', 120),
        'neighborhood' => bakery_sales_leads_clip($values['neighborhood'] ?? '', 120),
        'website' => bakery_sales_leads_clip($values['website'] ?? '', 255),
        'contact_channel' => $channel,
        'contact_verified' => bakery_sales_leads_clip($values['contact_verified'] ?? '', 255),
        'current_supplier' => bakery_sales_leads_clip($values['current_supplier'] ?? '', 255),
        'cuisine' => bakery_sales_leads_clip($values['cuisine'] ?? '', 120),
        'fit_score' => $score,
        'fit_reason' => bakery_sales_leads_text($values['fit_reason'] ?? ''),
        'priority' => $priority,
        'stage' => $stage,
        'next_action' => bakery_sales_leads_clip($values['next_action'] ?? '', 255),
        'next_action_date' => bakery_sales_leads_date_only($values['next_action_date'] ?? ''),
        'route_fit' => bakery_sales_leads_text($values['route_fit'] ?? ''),
        'zone_id' => null,
        'source' => bakery_sales_leads_clip($values['source'] ?? '', 255),
        'source_url' => bakery_sales_leads_clip($values['source_url'] ?? '', 512),
        'last_updated' => bakery_sales_leads_date_only($values['last_updated'] ?? ''),
    ];
    return ['ok' => true, 'error' => '', 'record' => $record];
}

/**
 * @param array<string, string> $extras
 */
function bakery_sales_leads_merge_notes($notes, array $extras): ?string
{
    $base = trim((string)$notes);
    $clean = [];
    foreach ($extras as $key => $value) {
        $key = trim((string)$key);
        $value = trim(str_replace(["\r\n", "\r", "\n"], ' ', (string)$value));
        if ($key === '' || $value === '') {
            continue;
        }
        if (mb_strlen($key) > 80) {
            $key = mb_substr($key, 0, 80);
        }
        $clean[$key] = $value;
    }
    if ($clean === []) {
        return $base === '' ? null : $base;
    }
    ksort($clean, SORT_STRING);
    $marker = "\n\nImported extra:\n";
    $pos = strrpos($base, $marker);
    if ($pos !== false) {
        $base = rtrim(substr($base, 0, $pos));
    } elseif (str_starts_with($base, "Imported extra:\n")) {
        $base = '';
    }
    $lines = ['Imported extra:'];
    foreach ($clean as $key => $value) {
        $lines[] = $key . ': ' . $value;
    }
    $block = implode("\n", $lines);
    if ($base === '') {
        return $block;
    }
    return $base . "\n\n" . $block;
}

/**
 * @return array{ok:bool,error:string,rows:list<array{line:int,values:array<string,string>,extras:array<string,string>}>}
 */
function bakery_sales_leads_parse_csv(string $csv): array
{
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
    $csv = str_replace(["\r\n", "\r"], "\n", $csv);
    if (trim($csv) === '') {
        return ['ok' => false, 'error' => 'empty_file', 'rows' => []];
    }
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'empty_file', 'rows' => []];
    }
    fwrite($handle, $csv);
    rewind($handle);
    $header = fgetcsv($handle, 0, ',', '"', '');
    if (!is_array($header)) {
        fclose($handle);
        return ['ok' => false, 'error' => 'empty_file', 'rows' => []];
    }
    $known = array_fill_keys(bakery_sales_leads_csv_columns(), true);
    $columns = [];
    foreach ($header as $index => $label) {
        $columns[$index] = strtolower(trim((string)$label));
    }
    if (!in_array('name', $columns, true)) {
        fclose($handle);
        return ['ok' => false, 'error' => 'missing_name_column', 'rows' => []];
    }
    $rows = [];
    $line = 1;
    while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
        $line++;
        if ($cells === [null] || (count($cells) === 1 && trim((string)$cells[0]) === '')) {
            continue;
        }
        $values = [];
        $extras = [];
        foreach ($columns as $index => $label) {
            if ($label === '') {
                continue;
            }
            $cell = isset($cells[$index]) ? trim((string)$cells[$index]) : '';
            if (isset($known[$label])) {
                $values[$label] = $cell;
            } else {
                $extras[$label] = $cell;
            }
        }
        $rows[] = ['line' => $line, 'values' => $values, 'extras' => $extras];
    }
    fclose($handle);
    return ['ok' => true, 'error' => '', 'rows' => $rows];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function bakery_sales_leads_record_from_row(array $row): array
{
    $stage = bakery_sales_leads_text($row['stage'] ?? '');
    if ($stage === null || !in_array($stage, bakery_sales_leads_stages(), true)) {
        $stage = 'New';
    }
    $score = $row['fit_score'] ?? null;
    $zone = $row['zone_id'] ?? null;
    return [
        'external_id' => bakery_sales_leads_text($row['external_id'] ?? null),
        'customer_name' => trim((string)($row['customer_name'] ?? '')),
        'phone' => bakery_sales_leads_text($row['phone'] ?? null),
        'email' => bakery_sales_leads_text($row['email'] ?? null),
        'address' => bakery_sales_leads_text($row['address'] ?? null),
        'notes' => bakery_sales_leads_text($row['notes'] ?? null),
        'status' => bakery_sales_leads_stage_to_status($stage),
        'lead_type' => bakery_sales_leads_text($row['lead_type'] ?? null),
        'neighborhood' => bakery_sales_leads_text($row['neighborhood'] ?? null),
        'website' => bakery_sales_leads_text($row['website'] ?? null),
        'contact_channel' => bakery_sales_leads_text($row['contact_channel'] ?? null),
        'contact_verified' => bakery_sales_leads_text($row['contact_verified'] ?? null),
        'current_supplier' => bakery_sales_leads_text($row['current_supplier'] ?? null),
        'cuisine' => bakery_sales_leads_text($row['cuisine'] ?? null),
        'fit_score' => ($score === null || $score === '') ? null : (int)$score,
        'fit_reason' => bakery_sales_leads_text($row['fit_reason'] ?? null),
        'priority' => bakery_sales_leads_text($row['priority'] ?? null),
        'stage' => $stage,
        'next_action' => bakery_sales_leads_text($row['next_action'] ?? null),
        'next_action_date' => bakery_sales_leads_date_only($row['next_action_date'] ?? null),
        'route_fit' => bakery_sales_leads_text($row['route_fit'] ?? null),
        'zone_id' => ($zone === null || $zone === '') ? null : (int)$zone,
        'source' => bakery_sales_leads_text($row['source'] ?? null),
        'source_url' => bakery_sales_leads_text($row['source_url'] ?? null),
        'last_updated' => bakery_sales_leads_date_only($row['last_updated'] ?? null),
    ];
}

/**
 * @param array<string, mixed> $left
 * @param array<string, mixed> $right
 */
function bakery_sales_leads_same_record(array $left, array $right): bool
{
    $keys = [
        'external_id', 'customer_name', 'phone', 'email', 'address', 'notes', 'status',
        'lead_type', 'neighborhood', 'website', 'contact_channel', 'contact_verified',
        'current_supplier', 'cuisine', 'fit_score', 'fit_reason', 'priority', 'stage',
        'next_action', 'next_action_date', 'route_fit', 'zone_id', 'source', 'source_url',
        'last_updated',
    ];
    foreach ($keys as $key) {
        if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
            return false;
        }
    }
    return true;
}

/**
 * @return array<string, mixed>|null
 */
function bakery_sales_leads_row_by_external_id(PDO $db, string $externalId): ?array
{
    $stmt = $db->prepare('SELECT * FROM leads WHERE external_id = ? LIMIT 1');
    $stmt->execute([$externalId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_sales_leads_rows_by_name(PDO $db, string $name): array
{
    $stmt = $db->prepare('SELECT * FROM leads WHERE LOWER(customer_name) = LOWER(?) ORDER BY id ASC');
    $stmt->execute([$name]);
    return $stmt->fetchAll() ?: [];
}

/**
 * @param array<string, mixed> $record
 */
function bakery_sales_leads_insert(PDO $db, array $record): int
{
    $stmt = $db->prepare(
        'INSERT INTO leads (
            external_id, customer_name, contact_name, phone, email, address, notes, status,
            lead_type, neighborhood, website, contact_channel, contact_verified, current_supplier,
            cuisine, fit_score, fit_reason, priority, stage, next_action, next_action_date,
            route_fit, zone_id, source, source_url, last_updated
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?
        )'
    );
    $stmt->execute([
        $record['external_id'],
        $record['customer_name'],
        $record['customer_name'],
        $record['phone'],
        $record['email'],
        $record['address'],
        $record['notes'],
        $record['status'],
        $record['lead_type'],
        $record['neighborhood'],
        $record['website'],
        $record['contact_channel'],
        $record['contact_verified'],
        $record['current_supplier'],
        $record['cuisine'],
        $record['fit_score'],
        $record['fit_reason'],
        $record['priority'],
        $record['stage'],
        $record['next_action'],
        $record['next_action_date'],
        $record['route_fit'],
        $record['zone_id'],
        $record['source'],
        $record['source_url'],
        $record['last_updated'],
    ]);
    return (int)$db->lastInsertId();
}

/**
 * @param array<string, mixed> $record
 */
function bakery_sales_leads_update(PDO $db, int $id, array $record): void
{
    $stmt = $db->prepare(
        'UPDATE leads SET
            external_id = ?, customer_name = ?, phone = ?, email = ?, address = ?, notes = ?, status = ?,
            lead_type = ?, neighborhood = ?, website = ?, contact_channel = ?, contact_verified = ?,
            current_supplier = ?, cuisine = ?, fit_score = ?, fit_reason = ?, priority = ?, stage = ?,
            next_action = ?, next_action_date = ?, route_fit = ?, zone_id = ?, source = ?, source_url = ?,
            last_updated = ?
         WHERE id = ?'
    );
    $stmt->execute([
        $record['external_id'],
        $record['customer_name'],
        $record['phone'],
        $record['email'],
        $record['address'],
        $record['notes'],
        $record['status'],
        $record['lead_type'],
        $record['neighborhood'],
        $record['website'],
        $record['contact_channel'],
        $record['contact_verified'],
        $record['current_supplier'],
        $record['cuisine'],
        $record['fit_score'],
        $record['fit_reason'],
        $record['priority'],
        $record['stage'],
        $record['next_action'],
        $record['next_action_date'],
        $record['route_fit'],
        $record['zone_id'],
        $record['source'],
        $record['source_url'],
        $record['last_updated'],
        $id,
    ]);
}

function bakery_sales_leads_log(
    PDO $db,
    int $leadId,
    int $userId,
    ?string $note,
    ?string $from,
    ?string $to,
    ?string $date = null
): void {
    $day = bakery_sales_leads_date_only($date) ?? date('Y-m-d');
    $stmt = $db->prepare(
        'INSERT INTO lead_activity (lead_id, activity_date, user_id, note, stage_from, stage_to)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $leadId,
        $day,
        $userId > 0 ? $userId : null,
        bakery_sales_leads_text($note),
        $from,
        $to,
    ]);
}

function bakery_sales_leads_next_external_id(PDO $db): string
{
    $rows = $db->query("SELECT external_id FROM leads WHERE external_id LIKE 'SFL-%'")->fetchAll();
    $max = 0;
    foreach ($rows as $row) {
        if (preg_match('/^SFL-(\d+)$/i', (string)$row['external_id'], $matches) === 1) {
            $max = max($max, (int)$matches[1]);
        }
    }
    return sprintf('SFL-%03d', $max + 1);
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function bakery_sales_leads_import(PDO $db, string $csv, array $options = []): array
{
    $dry = !empty($options['dry_run']);
    $userId = (int)($options['user_id'] ?? 0);
    $empty = [
        'ok' => false,
        'dry_run' => $dry,
        'error' => 'empty_file',
        'new' => 0,
        'updated' => 0,
        'unchanged' => 0,
        'rejected' => 0,
        'rows' => [],
    ];
    if (strlen($csv) > 2000000) {
        $empty['error'] = 'file_too_large';
        return $empty;
    }
    $parsed = bakery_sales_leads_parse_csv($csv);
    if (!$parsed['ok']) {
        $empty['error'] = (string)$parsed['error'];
        return $empty;
    }

    $zones = bakery_sales_leads_zones($db);
    $counts = ['new' => 0, 'updated' => 0, 'unchanged' => 0, 'rejected' => 0];
    $preview = [];
    /** @var array<string, array{id:int,record:array<string,mixed>}> $byId */
    $byId = [];
    /** @var array<string, array{id:int,record:array<string,mixed>}> $byName */
    $byName = [];

    $apply = static function () use (
        $db,
        $parsed,
        $zones,
        $dry,
        $userId,
        &$counts,
        &$preview,
        &$byId,
        &$byName
    ): void {
        foreach ($parsed['rows'] as $row) {
            $line = (int)$row['line'];
            $values = $row['values'];
            $normalized = bakery_sales_leads_normalize($values, $row['extras']);
            $labelId = trim((string)($values['id'] ?? ''));
            $labelName = trim((string)($values['name'] ?? ''));
            if (!$normalized['ok'] || !is_array($normalized['record'])) {
                $counts['rejected']++;
                $preview[] = [
                    'line' => $line,
                    'id' => $labelId,
                    'name' => $labelName,
                    'outcome' => 'rejected',
                    'reason' => (string)$normalized['error'],
                ];
                continue;
            }
            $record = $normalized['record'];
            $record['zone_id'] = bakery_sales_leads_match_zone_id($zones, (string)($record['route_fit'] ?? ''));
            $externalKey = strtolower((string)($record['external_id'] ?? ''));
            $nameKey = strtolower((string)$record['customer_name']);

            $existingId = null;
            $current = null;
            if ($externalKey !== '' && isset($byId[$externalKey])) {
                $existingId = (int)$byId[$externalKey]['id'];
                $current = $byId[$externalKey]['record'];
            } elseif ($externalKey !== '') {
                $found = bakery_sales_leads_row_by_external_id($db, (string)$record['external_id']);
                if ($found) {
                    $existingId = (int)$found['id'];
                    $current = bakery_sales_leads_record_from_row($found);
                }
            }
            if ($current === null) {
                if (isset($byName[$nameKey])) {
                    $named = $byName[$nameKey];
                    $namedExternal = strtolower((string)($named['record']['external_id'] ?? ''));
                    if ($externalKey !== '' && $namedExternal !== '' && $namedExternal !== $externalKey) {
                        $counts['rejected']++;
                        $preview[] = [
                            'line' => $line,
                            'id' => $labelId,
                            'name' => $labelName,
                            'outcome' => 'rejected',
                            'reason' => 'id_name_conflict',
                        ];
                        continue;
                    }
                    $existingId = (int)$named['id'];
                    $current = $named['record'];
                } else {
                    $matches = bakery_sales_leads_rows_by_name($db, (string)$record['customer_name']);
                    if (count($matches) > 1) {
                        $counts['rejected']++;
                        $preview[] = [
                            'line' => $line,
                            'id' => $labelId,
                            'name' => $labelName,
                            'outcome' => 'rejected',
                            'reason' => 'ambiguous_name',
                        ];
                        continue;
                    }
                    if (count($matches) === 1) {
                        $namedExternal = strtolower(trim((string)($matches[0]['external_id'] ?? '')));
                        if ($externalKey !== '' && $namedExternal !== '' && $namedExternal !== $externalKey) {
                            $counts['rejected']++;
                            $preview[] = [
                                'line' => $line,
                                'id' => $labelId,
                                'name' => $labelName,
                                'outcome' => 'rejected',
                                'reason' => 'id_name_conflict',
                            ];
                            continue;
                        }
                        $existingId = (int)$matches[0]['id'];
                        $current = bakery_sales_leads_record_from_row($matches[0]);
                    }
                }
            }

            if ($current !== null) {
                if ($record['phone'] === null) {
                    $record['phone'] = $current['phone'];
                }
                if ($record['email'] === null) {
                    $record['email'] = $current['email'];
                }
                if ($record['external_id'] === null) {
                    $record['external_id'] = $current['external_id'];
                }
            }

            $outcome = 'new';
            if ($current !== null) {
                $outcome = bakery_sales_leads_same_record($current, $record) ? 'unchanged' : 'updated';
            }
            $counts[$outcome]++;
            $preview[] = [
                'line' => $line,
                'id' => (string)($record['external_id'] ?? $labelId),
                'name' => (string)$record['customer_name'],
                'outcome' => $outcome,
                'reason' => '',
            ];

            $storedId = (int)($existingId ?? 0);
            if (!$dry && $outcome === 'new') {
                $storedId = bakery_sales_leads_insert($db, $record);
                bakery_sales_leads_log($db, $storedId, $userId, null, null, (string)$record['stage']);
            } elseif (!$dry && $outcome === 'updated' && $storedId > 0) {
                $previous = (string)($current['stage'] ?? '');
                bakery_sales_leads_update($db, $storedId, $record);
                if ($previous !== (string)$record['stage']) {
                    bakery_sales_leads_log(
                        $db,
                        $storedId,
                        $userId,
                        null,
                        $previous !== '' ? $previous : null,
                        (string)$record['stage']
                    );
                }
            }

            $cached = ['id' => $storedId, 'record' => $record];
            if ($externalKey !== '' || ($record['external_id'] ?? null)) {
                $cacheKey = strtolower((string)($record['external_id'] ?? ''));
                if ($cacheKey !== '') {
                    $byId[$cacheKey] = $cached;
                }
            }
            $byName[$nameKey] = $cached;
        }
    };

    if ($dry) {
        $apply();
    } else {
        $db->beginTransaction();
        try {
            $apply();
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('sales leads import: ' . $error->getMessage());
            return [
                'ok' => false,
                'dry_run' => false,
                'error' => 'save_failed',
                'new' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'rejected' => 0,
                'rows' => [],
            ];
        }
    }

    return [
        'ok' => true,
        'dry_run' => $dry,
        'error' => '',
        'new' => $counts['new'],
        'updated' => $counts['updated'],
        'unchanged' => $counts['unchanged'],
        'rejected' => $counts['rejected'],
        'rows' => $preview,
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function bakery_sales_leads_save(PDO $db, array $input, int $userId): array
{
    $values = [
        'id' => (string)($input['external_id'] ?? $input['id'] ?? ''),
        'name' => (string)($input['name'] ?? ''),
        'type' => (string)($input['type'] ?? ''),
        'address' => (string)($input['address'] ?? ''),
        'neighborhood' => (string)($input['neighborhood'] ?? ''),
        'cuisine' => (string)($input['cuisine'] ?? ''),
        'website' => (string)($input['website'] ?? ''),
        'contact_channel' => (string)($input['contact_channel'] ?? ''),
        'contact_verified' => (string)($input['contact_verified'] ?? ''),
        'current_supplier' => (string)($input['current_supplier'] ?? ''),
        'route_fit' => (string)($input['route_fit'] ?? ''),
        'fit_score' => (string)($input['fit_score'] ?? ''),
        'fit_reason' => (string)($input['fit_reason'] ?? ''),
        'priority' => (string)($input['priority'] ?? ''),
        'stage' => (string)($input['stage'] ?? ''),
        'next_action' => (string)($input['next_action'] ?? ''),
        'next_action_date' => (string)($input['next_action_date'] ?? ''),
        'notes' => (string)($input['notes'] ?? ''),
        'source' => (string)($input['source'] ?? ''),
        'source_url' => (string)($input['source_url'] ?? ''),
        'last_updated' => (string)($input['last_updated'] ?? ''),
    ];
    $normalized = bakery_sales_leads_normalize($values, []);
    if (!$normalized['ok'] || !is_array($normalized['record'])) {
        return ['ok' => false, 'error' => (string)$normalized['error']];
    }
    $record = $normalized['record'];
    $zoneRaw = trim((string)($input['zone_id'] ?? ''));
    $record['zone_id'] = ($zoneRaw !== '' && ctype_digit($zoneRaw)) ? (int)$zoneRaw : null;
    if ($record['last_updated'] === null) {
        $record['last_updated'] = date('Y-m-d');
    }
    $note = bakery_sales_leads_text($input['change_note'] ?? '');
    $internalId = (int)($input['internal_id'] ?? 0);

    $db->beginTransaction();
    try {
        if ($internalId > 0) {
            $stmt = $db->prepare('SELECT * FROM leads WHERE id = ? FOR UPDATE');
            $stmt->execute([$internalId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'not_found'];
            }
            if ($record['external_id'] === null) {
                $record['external_id'] = bakery_sales_leads_text($existing['external_id'] ?? null);
            }
            if ($record['external_id'] !== null) {
                $clash = $db->prepare('SELECT id FROM leads WHERE external_id = ? AND id <> ? LIMIT 1');
                $clash->execute([$record['external_id'], $internalId]);
                if ($clash->fetchColumn()) {
                    $db->rollBack();
                    return ['ok' => false, 'error' => 'id_taken'];
                }
            }
            $current = bakery_sales_leads_record_from_row($existing);
            // Legacy phone and email live on the lead even when contact_channel
            // is still blank. A manual save keeps them until the channel itself
            // contains a replacement number or address.
            if ($record['phone'] === null) {
                $record['phone'] = $current['phone'];
            }
            if ($record['email'] === null) {
                $record['email'] = $current['email'];
            }
            $changed = !bakery_sales_leads_same_record($current, $record);
            if ($changed) {
                bakery_sales_leads_update($db, $internalId, $record);
            }
            $stageChanged = (string)$current['stage'] !== (string)$record['stage'];
            if ($stageChanged) {
                bakery_sales_leads_log(
                    $db,
                    $internalId,
                    $userId,
                    $note,
                    (string)$current['stage'],
                    (string)$record['stage']
                );
            } elseif ($note !== null) {
                bakery_sales_leads_log($db, $internalId, $userId, $note, null, null);
            }
            $savedId = $internalId;
        } else {
            if ($record['external_id'] === null) {
                $record['external_id'] = bakery_sales_leads_next_external_id($db);
            }
            $clash = $db->prepare('SELECT id FROM leads WHERE external_id = ? LIMIT 1');
            $clash->execute([$record['external_id']]);
            if ($clash->fetchColumn()) {
                $db->rollBack();
                return ['ok' => false, 'error' => 'id_taken'];
            }
            $savedId = bakery_sales_leads_insert($db, $record);
            bakery_sales_leads_log($db, $savedId, $userId, $note, null, (string)$record['stage']);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('sales leads save: ' . $error->getMessage());
        return ['ok' => false, 'error' => 'save_failed'];
    }

    return [
        'ok' => true,
        'id' => $savedId,
        'external_id' => (string)($record['external_id'] ?? ''),
    ];
}

/**
 * @return array{ok:bool,error?:string}
 */
function bakery_sales_leads_add_note(PDO $db, int $leadId, int $userId, string $note, string $date): array
{
    $clean = bakery_sales_leads_text($note);
    if ($clean === null) {
        return ['ok' => false, 'error' => 'missing_note'];
    }
    if (bakery_sales_leads_date_only($date) === null) {
        return ['ok' => false, 'error' => 'bad_date'];
    }
    $stmt = $db->prepare('SELECT id FROM leads WHERE id = ?');
    $stmt->execute([$leadId]);
    if (!$stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    bakery_sales_leads_log($db, $leadId, $userId, $clean, null, null, $date);
    return ['ok' => true];
}

/**
 * @param array<string, mixed> $filters
 * @return list<array<string, mixed>>
 */
function bakery_sales_leads_list(PDO $db, array $filters = []): array
{
    $where = [];
    $params = [];
    $stage = (string)($filters['stage'] ?? '');
    if ($stage !== '' && in_array($stage, bakery_sales_leads_stages(), true)) {
        $where[] = 'l.stage = ?';
        $params[] = $stage;
    } elseif ((string)($filters['show_lost'] ?? '') !== '1') {
        $where[] = "(l.stage IS NULL OR l.stage <> 'Lost')";
    }
    $priority = strtoupper(trim((string)($filters['priority'] ?? '')));
    if (in_array($priority, bakery_sales_leads_priorities(), true)) {
        $where[] = 'l.priority = ?';
        $params[] = $priority;
    }
    $zone = trim((string)($filters['zone'] ?? ''));
    if ($zone === 'none') {
        $where[] = 'l.zone_id IS NULL';
    } elseif ($zone !== '' && ctype_digit($zone)) {
        $where[] = 'l.zone_id = ?';
        $params[] = (int)$zone;
    }
    $joinZone = function_exists('table_exists') && table_exists($db, 'zones');
    $sql = 'SELECT l.*' . ($joinZone ? ', z.name AS zone_name' : '') . ' FROM leads l';
    if ($joinZone) {
        $sql .= ' LEFT JOIN zones z ON z.id = l.zone_id';
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY (l.next_action_date IS NULL) ASC, l.next_action_date ASC,
        CASE l.priority WHEN \'A\' THEN 1 WHEN \'B\' THEN 2 WHEN \'C\' THEN 3 ELSE 4 END,
        l.customer_name ASC, l.id ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function bakery_sales_leads_find(PDO $db, string $key): ?array
{
    $key = trim($key);
    if ($key === '' || $key === 'new') {
        return null;
    }
    $joinZone = function_exists('table_exists') && table_exists($db, 'zones');
    $sql = 'SELECT l.*' . ($joinZone ? ', z.name AS zone_name' : '') . ' FROM leads l';
    if ($joinZone) {
        $sql .= ' LEFT JOIN zones z ON z.id = l.zone_id';
    }
    if (ctype_digit($key)) {
        $sql .= ' WHERE l.id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute([(int)$key]);
    } else {
        $sql .= ' WHERE l.external_id = ?';
        $stmt = $db->prepare($sql);
        $stmt->execute([$key]);
    }
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_sales_leads_activity(PDO $db, int $leadId): array
{
    $stmt = $db->prepare(
        'SELECT a.*, u.display_name AS user_name
         FROM lead_activity a
         LEFT JOIN users u ON u.id = a.user_id
         WHERE a.lead_id = ?
         ORDER BY a.activity_date DESC, a.id DESC'
    );
    $stmt->execute([$leadId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function bakery_sales_leads_contacts(PDO $db, int $leadId): array
{
    if (!function_exists('table_exists') || !table_exists($db, 'lead_contacts')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT * FROM lead_contacts WHERE lead_id = ? ORDER BY contact_date DESC, id DESC'
    );
    $stmt->execute([$leadId]);
    return $stmt->fetchAll() ?: [];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function bakery_sales_leads_public(array $row): array
{
    $external = trim((string)($row['external_id'] ?? ''));
    $score = $row['fit_score'] ?? null;
    $zone = $row['zone_id'] ?? null;
    $customer = $row['customer_id'] ?? null;
    $sample = $row['sample_daily_order_id'] ?? null;
    return [
        'id' => $external !== '' ? $external : (string)$row['id'],
        'name' => (string)($row['customer_name'] ?? ''),
        'type' => bakery_sales_leads_text($row['lead_type'] ?? null),
        'address' => bakery_sales_leads_text($row['address'] ?? null),
        'neighborhood' => bakery_sales_leads_text($row['neighborhood'] ?? null),
        'cuisine' => bakery_sales_leads_text($row['cuisine'] ?? null),
        'website' => bakery_sales_leads_text($row['website'] ?? null),
        'contact_channel' => bakery_sales_leads_text($row['contact_channel'] ?? null),
        'contact_verified' => bakery_sales_leads_text($row['contact_verified'] ?? null),
        'current_supplier' => bakery_sales_leads_text($row['current_supplier'] ?? null),
        'route_fit' => bakery_sales_leads_text($row['route_fit'] ?? null),
        'fit_score' => ($score === null || $score === '') ? null : (int)$score,
        'fit_reason' => bakery_sales_leads_text($row['fit_reason'] ?? null),
        'priority' => bakery_sales_leads_text($row['priority'] ?? null),
        'stage' => bakery_sales_leads_text($row['stage'] ?? null),
        'next_action' => bakery_sales_leads_text($row['next_action'] ?? null),
        'next_action_date' => bakery_sales_leads_date_only($row['next_action_date'] ?? null),
        'notes' => bakery_sales_leads_text($row['notes'] ?? null),
        'source' => bakery_sales_leads_text($row['source'] ?? null),
        'source_url' => bakery_sales_leads_text($row['source_url'] ?? null),
        'last_updated' => bakery_sales_leads_date_only($row['last_updated'] ?? null),
        'zone_id' => ($zone === null || $zone === '') ? null : (int)$zone,
        'zone_name' => bakery_sales_leads_text($row['zone_name'] ?? null),
        'customer_id' => ($customer === null || $customer === '') ? null : (int)$customer,
        'sample_daily_order_id' => ($sample === null || $sample === '') ? null : (int)$sample,
    ];
}

/**
 * @param list<array<string, mixed>> $rows
 */
function bakery_sales_leads_export_csv(array $rows): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        return '';
    }
    $columns = bakery_sales_leads_csv_columns();
    fputcsv($handle, $columns, ',', '"', '');
    foreach ($rows as $row) {
        $public = bakery_sales_leads_public($row);
        $line = [];
        foreach ($columns as $column) {
            $value = $public[$column] ?? '';
            $line[] = $value === null ? '' : (string)$value;
        }
        fputcsv($handle, $line, ',', '"', '');
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);
    return $csv === false ? '' : $csv;
}
