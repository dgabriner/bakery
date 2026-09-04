<?php
/**
 * Text comms â€” the SMS ledger behind the Texting Command Center.
 *
 * Every outbound attempt (sent, failed, or recorded without credentials)
 * leaves exactly one ledger row; inbound webhook messages append to the same
 * ledger. The Command Center reads only from here, so the screen is honest
 * about what actually left the building (MAIL_DRIVER=log honesty pattern).
 *
 * Invariants:
 *   - No credentials â†’ status 'logged' rows ("recorded, not sent"), never a throw.
 *   - Runtime-tolerant: missing text_messages table degrades to available=false.
 *   - Phone matching links customers by 10-digit tail; never rewrites contacts.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/twilio_config.php';

/**
 * Keep only digits, then canonicalize US numbers to +1XXXXXXXXXX.
 * International inputs that already carry a leading + keep their digits.
 */
function bakery_text_normalize_phone(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $hasPlus = $raw[0] === '+';
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return '';
    }
    if (!$hasPlus && strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }
    if (!$hasPlus && strlen($digits) === 10) {
        return '+1' . $digits;
    }
    return ($hasPlus ? '+' : '') . $digits;
}

/** Last-10-digits tail used for customer linking and thread grouping. */
function bakery_text_phone_tail(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone);
    return strlen($digits) > 10 ? substr($digits, -10) : $digits;
}

function bakery_text_messages_ready(PDO $db): bool
{
    return table_exists($db, 'text_messages');
}

/** Allowed Command Center dashboard views (same page; GET view=). */
function bakery_text_views(): array
{
    return ['inbox', 'feed', 'delivery', 'ops', 'surveys'];
}

/** Audience lanes on one ledger: customer, test, or general. */
function bakery_text_lanes(): array
{
    return ['customer', 'test', 'general'];
}

/**
 * Classify one ledger row. Test context wins so a test to a customer
 * phone stays visible as a test, not hidden in the customer inbox.
 */
function bakery_text_lane(array $row): string
{
    $context = strtolower(trim((string)($row['context_type'] ?? '')));
    if ($context === 'test') {
        return 'test';
    }
    if (!empty($row['customer_id'])) {
        return 'customer';
    }
    return 'general';
}

function bakery_text_normalize_context_type(string $raw): string
{
    $raw = strtolower(trim($raw));
    $allowed = ['manual', 'test', 'general', 'order', 'inbound', 'driver', 'recovery'];
    return in_array($raw, $allowed, true) ? $raw : 'manual';
}

/**
 * Compose purpose â†’ ledger context_type. Customer purpose still uses
 * manual (the customer_id link is the lane), test/general are explicit.
 */
function bakery_text_context_from_purpose(string $purpose): string
{
    $purpose = strtolower(trim($purpose));
    if ($purpose === 'test') {
        return 'test';
    }
    if ($purpose === 'general') {
        return 'general';
    }
    return 'manual';
}

/** True when outbound sends can really leave via Twilio. */
function bakery_text_live_ready(): bool
{
    // Test seams: force either path regardless of local credentials.
    if (!empty($GLOBALS['bakery_text_force_record_only'])) {
        return false;
    }
    if (!empty($GLOBALS['bakery_text_force_live_ready'])) {
        return true;
    }
    // Process-env seam so CLI subprocesses (scripts/text_send.php smoke tests)
    // can force the honest record-only path without touching credentials.
    if (getenv('BAKERY_TEXT_FORCE_RECORD_ONLY') === '1') {
        return false;
    }
    return twilio_is_configured();
}

/**
 * Best-effort link a phone number to a customer by comparing 10-digit tails
 * across the contact columns. Returns customer id or null.
 */
function bakery_text_link_customer(PDO $db, string $phone): ?int
{
    $tail = bakery_text_phone_tail($phone);
    if ($tail === '' || !table_exists($db, 'customers')) {
        return null;
    }
    $stmt = $db->prepare(
        "SELECT id FROM customers
         WHERE RIGHT(REGEXP_REPLACE(COALESCE(NULLIF(phone,''), ''), '[^0-9]', ''), 10) = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(NULLIF(portal_phone,''), ''), '[^0-9]', ''), 10) = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(NULLIF(ordering_contact_phone,''), ''), '[^0-9]', ''), 10) = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(NULLIF(delivery_contact_phone,''), ''), '[^0-9]', ''), 10) = ?
            OR RIGHT(REGEXP_REPLACE(COALESCE(NULLIF(billing_contact_phone,''), ''), '[^0-9]', ''), 10) = ?
         LIMIT 1"
    );
    try {
        $stmt->execute([$tail, $tail, $tail, $tail, $tail]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (Throwable $e) {
        error_log('text comms link customer: ' . $e->getMessage());
        return null;
    }
}

/**
 * Customer display names for a set of ids in one query.
 *
 * @return array<int, string>
 */
function bakery_text_customer_names(PDO $db, array $customerIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
    if ($ids === [] || !table_exists($db, 'customers')) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT id, name FROM customers WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $names[(int)$row['id']] = (string)$row['name'];
    }
    return $names;
}

/**
 * Insert one ledger row. Returns the new row id.
 *
 * @param array<string, mixed> $fields direction,status,from_number,to_number,body,
 *        twilio_sid,error_message,customer_id,staff_user_id,context_type,context_id,
 *        operating_date,read_at
 */
function bakery_text_record(PDO $db, array $fields): int
{
    $row = [
        'direction' => in_array(($fields['direction'] ?? ''), ['outbound', 'inbound'], true)
            ? (string)$fields['direction']
            : 'outbound',
        'status' => trim((string)($fields['status'] ?? 'queued')),
        'from_number' => bakery_text_normalize_phone((string)($fields['from_number'] ?? '')),
        'to_number' => bakery_text_normalize_phone((string)($fields['to_number'] ?? '')),
        'body' => (string)($fields['body'] ?? ''),
        'twilio_sid' => ($fields['twilio_sid'] ?? null) ?: null,
        'error_message' => ($fields['error_message'] ?? null),
        'customer_id' => ($fields['customer_id'] ?? null) ? (int)$fields['customer_id'] : null,
        'staff_user_id' => ($fields['staff_user_id'] ?? null) ? (int)$fields['staff_user_id'] : null,
        'context_type' => bakery_text_normalize_context_type((string)($fields['context_type'] ?? 'manual')),
        'context_id' => ($fields['context_id'] ?? null) ? (int)$fields['context_id'] : null,
        'operating_date' => ($fields['operating_date'] ?? null) ?: date('Y-m-d'),
        'read_at' => !empty($fields['read_at']) ? (string)$fields['read_at'] : null,
    ];
    if ($row['status'] === '') {
        $row['status'] = 'queued';
    }

    $sql = "INSERT INTO text_messages
            (direction, status, from_number, to_number, body, twilio_sid, error_message,
             customer_id, staff_user_id, context_type, context_id, operating_date, read_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $row['direction'],
        $row['status'],
        $row['from_number'],
        $row['to_number'],
        $row['body'],
        $row['twilio_sid'],
        $row['error_message'],
        $row['customer_id'],
        $row['staff_user_id'],
        $row['context_type'],
        $row['context_id'],
        $row['operating_date'],
        $row['read_at'],
    ]);
    return (int)$db->lastInsertId();
}

/**
 * Send an SMS through Twilio (or record-only when unconfigured) and write the
 * ledger row either way. Never throws for send failures â€” the failure is the
 * record. Returns [ok, recorded_only, status, sid, error, id].
 *
 * @param array{customer_id?:int,staff_user_id?:int,context_type?:string,
 *              context_id?:int,operating_date?:string,media_url?:string} $opts
 */
function bakery_text_send(PDO $db, string $to, string $body, array $opts = []): array
{
    $to = bakery_text_normalize_phone($to);
    $body = trim($body);
    $result = [
        'ok' => false,
        'recorded_only' => false,
        'status' => 'failed',
        'sid' => null,
        'error' => '',
        'id' => 0,
    ];

    if ($to === '') {
        $result['error'] = 'missing_to';
        return $result;
    }
    if ($body === '') {
        $result['error'] = 'missing_body';
        return $result;
    }

    $customerId = isset($opts['customer_id']) && (int)$opts['customer_id'] > 0
        ? (int)$opts['customer_id']
        : bakery_text_link_customer($db, $to);

    if (!bakery_text_live_ready()) {
        // Honesty mode: record exactly what would have gone out.
        $result['recorded_only'] = true;
        $result['status'] = 'logged';
        $result['id'] = bakery_text_record($db, [
            'direction' => 'outbound',
            'status' => 'logged',
            'to_number' => $to,
            'from_number' => TWILIO_FROM_NUMBER !== '' ? TWILIO_FROM_NUMBER : '',
            'body' => $body,
            'customer_id' => $customerId,
            'staff_user_id' => $opts['staff_user_id'] ?? null,
            'context_type' => bakery_text_normalize_context_type((string)($opts['context_type'] ?? 'manual')),
            'context_id' => $opts['context_id'] ?? null,
            'operating_date' => $opts['operating_date'] ?? date('Y-m-d'),
        ]);
        return $result;
    }

    $form = [
        'To' => $to,
        'Body' => $body,
    ];
    $mediaUrl = trim((string)($opts['media_url'] ?? ''));
    if ($mediaUrl !== '') {
        if (!preg_match('#^https://#i', $mediaUrl)) {
            $result['error'] = 'media_url must be https';
            return $result;
        }
        $form['MediaUrl'] = $mediaUrl;
    }
    if (TWILIO_MESSAGING_SERVICE_SID !== '') {
        $form['MessagingServiceSid'] = TWILIO_MESSAGING_SERVICE_SID;
    } else {
        $form['From'] = TWILIO_FROM_NUMBER;
    }
    $statusCallback = defined('TWILIO_STATUS_CALLBACK_URL') ? TWILIO_STATUS_CALLBACK_URL : '';
    if ($statusCallback !== '') {
        $form['StatusCallback'] = $statusCallback;
    }

    $path = '/2010-04-01/Accounts/' . rawurlencode(TWILIO_ACCOUNT_SID) . '/Messages.json';

    try {
        $response = twilio_api_request('POST', $path, $form);
        $sid = (string)($response['sid'] ?? '');
        $status = strtolower((string)($response['status'] ?? 'queued'));
        if ($sid === '') {
            throw new RuntimeException('Twilio response missing message sid.');
        }
        $result['ok'] = true;
        $result['status'] = $status !== '' ? $status : 'queued';
        $result['sid'] = $sid;
        $result['id'] = bakery_text_record($db, [
            'direction' => 'outbound',
            'status' => $result['status'],
            'to_number' => $to,
            'from_number' => (string)($response['from'] ?? TWILIO_FROM_NUMBER),
            'body' => $body,
            'twilio_sid' => $sid,
            'customer_id' => $customerId,
            'staff_user_id' => $opts['staff_user_id'] ?? null,
            'context_type' => bakery_text_normalize_context_type((string)($opts['context_type'] ?? 'manual')),
            'context_id' => $opts['context_id'] ?? null,
            'operating_date' => $opts['operating_date'] ?? date('Y-m-d'),
        ]);
    } catch (Throwable $e) {
        error_log('text comms send: ' . $e->getMessage());
        $result['error'] = $e->getMessage();
        $result['id'] = bakery_text_record($db, [
            'direction' => 'outbound',
            'status' => 'failed',
            'to_number' => $to,
            'from_number' => TWILIO_FROM_NUMBER,
            'body' => $body,
            'error_message' => $e->getMessage(),
            'customer_id' => $customerId,
            'staff_user_id' => $opts['staff_user_id'] ?? null,
            'context_type' => bakery_text_normalize_context_type((string)($opts['context_type'] ?? 'manual')),
            'context_id' => $opts['context_id'] ?? null,
            'operating_date' => $opts['operating_date'] ?? date('Y-m-d'),
        ]);
    }

    return $result;
}

/**
 * Day strip counts for one operating date plus availability flags.
 *
 * @return array{
 *   available:bool, live:bool, today:string,
 *   counts:array{outbound:int,sent:int,delivered:int,failed:int,logged:int,received:int,unread:int},
 *   lanes:array{customer:int,test:int,general:int}
 * }
 */
function bakery_text_summary(PDO $db, ?string $date = null): array
{
    $date = $date ?: date('Y-m-d');
    $summary = [
        'available' => false,
        'live' => twilio_is_configured(),
        'today' => $date,
        'counts' => ['outbound' => 0, 'sent' => 0, 'delivered' => 0, 'failed' => 0, 'logged' => 0, 'received' => 0, 'unread' => 0],
        'lanes' => ['customer' => 0, 'test' => 0, 'general' => 0],
    ];
    if (!bakery_text_messages_ready($db)) {
        return $summary;
    }
    $summary['available'] = true;

    $stmt = $db->prepare(
        "SELECT direction, status, COUNT(*) AS n,
                SUM(CASE WHEN direction = 'inbound' AND read_at IS NULL THEN 1 ELSE 0 END) AS unread
         FROM text_messages
         WHERE operating_date = ?
         GROUP BY direction, status"
    );
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $n = (int)$row['n'];
        if ((string)$row['direction'] === 'inbound') {
            $summary['counts']['received'] += $n;
            $summary['counts']['unread'] += (int)$row['unread'];
            continue;
        }
        $summary['counts']['outbound'] += $n;
        $status = (string)$row['status'];
        if ($status === 'logged') {
            $summary['counts']['logged'] += $n;
        } elseif ($status === 'failed' || $status === 'undelivered') {
            $summary['counts']['failed'] += $n;
        } elseif ($status === 'delivered') {
            $summary['counts']['delivered'] += $n;
        } elseif (in_array($status, ['queued', 'accepted', 'sent'], true)) {
            $summary['counts']['sent'] += $n;
        }
    }

    $laneStmt = $db->prepare(
        "SELECT
            CASE
                WHEN context_type = 'test' THEN 'test'
                WHEN customer_id IS NOT NULL THEN 'customer'
                ELSE 'general'
            END AS lane,
            COUNT(*) AS n
         FROM text_messages
         WHERE operating_date = ?
         GROUP BY lane"
    );
    $laneStmt->execute([$date]);
    foreach ($laneStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lane = (string)$row['lane'];
        if (isset($summary['lanes'][$lane])) {
            $summary['lanes'][$lane] += (int)$row['n'];
        }
    }
    return $summary;
}

/**
 * Conversation list: one row per counterpart number with activity in the
 * lookback window, newest first. Unread inbound drives the badge.
 *
 * @return array{available:bool, conversations:list<array<string,mixed>>}
 */
function bakery_text_conversations(PDO $db, int $lookbackDays = 7): array
{
    if (!bakery_text_messages_ready($db)) {
        return ['available' => false, 'conversations' => []];
    }
    $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $lookbackDays) . ' days'));

    $stmt = $db->prepare(
        "SELECT m.id, m.direction, m.status, m.from_number, m.to_number, m.body,
                m.customer_id, m.staff_user_id, m.context_type, m.context_id,
                m.operating_date, m.read_at, m.created_at, m.twilio_sid, m.error_message
         FROM text_messages m
         WHERE m.created_at >= ?
         ORDER BY m.created_at DESC, m.id DESC
         LIMIT 500"
    );
    $stmt->execute([$since]);

    $byNumber = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counterpart = (string)$row['direction'] === 'outbound'
            ? (string)$row['to_number']
            : (string)$row['from_number'];
        if ($counterpart === '') {
            continue;
        }
        $tail = bakery_text_phone_tail($counterpart);
        $key = $tail !== '' ? $tail : $counterpart;
        if (!isset($byNumber[$key])) {
            $byNumber[$key] = [
                'phone' => $counterpart,
                'customer_id' => null,
                'last_body' => '',
                'last_direction' => '',
                'last_status' => '',
                'last_at' => '',
                'last_operating_date' => '',
                'last_context_type' => '',
                'saw_test' => false,
                'outbound' => 0,
                'inbound' => 0,
                'unread' => 0,
                'failed' => 0,
                'message_ids' => [],
            ];
        }
        $bucket = &$byNumber[$key];
        if ((string)$row['direction'] === 'outbound') {
            $bucket['outbound']++;
            if (in_array((string)$row['status'], ['failed', 'undelivered'], true)) {
                $bucket['failed']++;
            }
        } else {
            $bucket['inbound']++;
            if (empty($row['read_at'])) {
                $bucket['unread']++;
            }
        }
        if ($bucket['customer_id'] === null && !empty($row['customer_id'])) {
            $bucket['customer_id'] = (int)$row['customer_id'];
        }
        if (bakery_text_lane($row) === 'test') {
            $bucket['saw_test'] = true;
        }
        if ($bucket['last_at'] === '') {
            $bucket['last_at'] = (string)$row['created_at'];
            $bucket['last_body'] = (string)$row['body'];
            $bucket['last_direction'] = (string)$row['direction'];
            $bucket['last_status'] = (string)$row['status'];
            $bucket['last_operating_date'] = (string)($row['operating_date'] ?? '');
            $bucket['last_context_type'] = (string)($row['context_type'] ?? '');
        }
        unset($bucket);
    }

    $conversations = array_values($byNumber);
    usort($conversations, static function (array $a, array $b): int {
        if (($a['unread'] > 0) !== ($b['unread'] > 0)) {
            return $a['unread'] > 0 ? -1 : 1;
        }
        return strcmp((string)$b['last_at'], (string)$a['last_at']);
    });

    $names = bakery_text_customer_names($db, array_filter(array_column($conversations, 'customer_id')));
    foreach ($conversations as &$conversation) {
        $cid = $conversation['customer_id'];
        $conversation['label'] = $cid && isset($names[$cid]) ? $names[$cid] : '';
        $conversation['lane'] = !empty($conversation['saw_test'])
            ? 'test'
            : ($cid ? 'customer' : 'general');
        unset($conversation['saw_test']);
    }
    unset($conversation);

    return ['available' => true, 'conversations' => $conversations];
}

/**
 * Full message thread for one counterpart phone (oldest first).
 * Marks inbound rows read when staff open it.
 *
 * @return array{available:bool, messages:list<array<string,mixed>>}
 */
function bakery_text_thread(PDO $db, string $phone, bool $markRead = true): array
{
    if (!bakery_text_messages_ready($db)) {
        return ['available' => false, 'messages' => []];
    }
    $tail = bakery_text_phone_tail(bakery_text_normalize_phone($phone));
    if ($tail === '') {
        return ['available' => true, 'messages' => []];
    }

    // Mark inbound read BEFORE selecting so returned rows reflect the opened state.
    if ($markRead) {
        $upd = $db->prepare(
            "UPDATE text_messages SET read_at = NOW()
             WHERE direction = 'inbound' AND read_at IS NULL
               AND (RIGHT(REGEXP_REPLACE(from_number, '[^0-9]', ''), 10) = ?)"
        );
        try {
            $upd->execute([$tail]);
        } catch (Throwable $e) {
            error_log('text comms mark read: ' . $e->getMessage());
        }
    }

    $stmt = $db->prepare(
        "SELECT id, direction, status, from_number, to_number, body, customer_id,
                staff_user_id, context_type, context_id, operating_date, read_at,
                created_at, twilio_sid, error_message, kind, media_count, media_json
         FROM text_messages
         WHERE RIGHT(REGEXP_REPLACE(to_number, '[^0-9]', ''), 10) = ?
            OR RIGHT(REGEXP_REPLACE(from_number, '[^0-9]', ''), 10) = ?
          ORDER BY created_at ASC, id ASC
         LIMIT 500"
    );
    try {
        $stmt->execute([$tail, $tail]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('text comms thread: ' . $e->getMessage());
        $rows = [];
    }

    foreach ($rows as &$row) {
        $row['lane'] = bakery_text_lane($row);
    }
    unset($row);

    return ['available' => true, 'messages' => $rows];
}

/**
 * Chronological activity feed (newest first) across every lane.
 *
 * @return array{available:bool, messages:list<array<string,mixed>>}
 */
function bakery_text_feed(PDO $db, int $lookbackDays = 14, int $limit = 200): array
{
    if (!bakery_text_messages_ready($db)) {
        return ['available' => false, 'messages' => []];
    }
    $limit = max(1, min(500, $limit));
    $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $lookbackDays) . ' days'));
    $stmt = $db->prepare(
        "SELECT id, direction, status, from_number, to_number, body, customer_id,
                staff_user_id, context_type, context_id, operating_date, read_at,
                created_at, twilio_sid, error_message, kind, media_count, media_json
         FROM text_messages
         WHERE created_at >= ?
         ORDER BY created_at DESC, id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([$since]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $names = bakery_text_customer_names($db, array_filter(array_column($rows, 'customer_id')));
    foreach ($rows as &$row) {
        $row['lane'] = bakery_text_lane($row);
        $cid = (int)($row['customer_id'] ?? 0);
        $row['label'] = $cid && isset($names[$cid]) ? $names[$cid] : '';
        $row['counterpart'] = (string)$row['direction'] === 'outbound'
            ? (string)$row['to_number']
            : (string)$row['from_number'];
    }
    unset($row);
    return ['available' => true, 'messages' => $rows];
}

/**
 * Delivery health: failed, in-flight, and recorded-only rows.
 *
 * @return array{available:bool, failed:list, in_flight:list, logged:list}
 */
function bakery_text_delivery(PDO $db, int $lookbackDays = 14): array
{
    $empty = ['available' => false, 'failed' => [], 'in_flight' => [], 'logged' => []];
    if (!bakery_text_messages_ready($db)) {
        return $empty;
    }
    $since = date('Y-m-d H:i:s', strtotime('-' . max(1, $lookbackDays) . ' days'));
    $stmt = $db->prepare(
        "SELECT id, direction, status, from_number, to_number, body, customer_id,
                context_type, operating_date, created_at, twilio_sid, error_message
         FROM text_messages
         WHERE direction = 'outbound'
           AND created_at >= ?
           AND status IN ('failed','undelivered','queued','accepted','sent','logged')
         ORDER BY created_at DESC, id DESC
         LIMIT 300"
    );
    $stmt->execute([$since]);
    $out = ['available' => true, 'failed' => [], 'in_flight' => [], 'logged' => []];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['lane'] = bakery_text_lane($row);
        $row['counterpart'] = (string)$row['to_number'];
        $status = (string)$row['status'];
        if ($status === 'logged') {
            $out['logged'][] = $row;
        } elseif (in_array($status, ['failed', 'undelivered'], true)) {
            $out['failed'][] = $row;
        } else {
            $out['in_flight'][] = $row;
        }
    }
    return $out;
}

/**
 * Ops snapshot: Twilio capability plus mix of lanes and context types.
 *
 * @return array<string, mixed>
 */
function bakery_text_ops_snapshot(PDO $db, ?string $date = null): array
{
    $date = $date ?: date('Y-m-d');
    $summary = bakery_text_summary($db, $date);
    $ops = [
        'available' => $summary['available'],
        'live' => twilio_is_configured(),
        'credentials_sane' => twilio_credentials_look_sane(),
        'from_number' => defined('TWILIO_FROM_NUMBER') ? TWILIO_FROM_NUMBER : '',
        'messaging_service' => defined('TWILIO_MESSAGING_SERVICE_SID') && TWILIO_MESSAGING_SERVICE_SID !== '',
        'webhook_validate' => defined('TWILIO_VALIDATE_WEBHOOK') ? (bool)TWILIO_VALIDATE_WEBHOOK : false,
        'today' => $date,
        'counts' => $summary['counts'],
        'lanes' => $summary['lanes'],
        'contexts' => [],
        'lanes_window' => ['customer' => 0, 'test' => 0, 'general' => 0],
    ];
    if (!$summary['available']) {
        return $ops;
    }

    $ctx = $db->prepare(
        "SELECT context_type, COUNT(*) AS n
         FROM text_messages
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         GROUP BY context_type
         ORDER BY n DESC"
    );
    $ctx->execute();
    foreach ($ctx->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ops['contexts'][] = [
            'context_type' => (string)$row['context_type'],
            'n' => (int)$row['n'],
        ];
    }

    $laneWin = $db->query(
        "SELECT
            CASE
                WHEN context_type = 'test' THEN 'test'
                WHEN customer_id IS NOT NULL THEN 'customer'
                ELSE 'general'
            END AS lane,
            COUNT(*) AS n
         FROM text_messages
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         GROUP BY lane"
    );
    foreach ($laneWin->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $lane = (string)$row['lane'];
        if (isset($ops['lanes_window'][$lane])) {
            $ops['lanes_window'][$lane] = (int)$row['n'];
        }
    }
    return $ops;
}

/**
 * Rank Twilio message statuses by how far the message has travelled.
 * Unknown statuses return null so a malformed or future callback can never
 * write vocabulary the ledger does not understand.
 */
function bakery_text_status_rank(string $status): ?int
{
    static $ranks = [
        'queued' => 0,
        'accepted' => 1,
        'scheduled' => 1,
        'sent' => 2,
        'delivered' => 3,
        'undelivered' => 3,
        'failed' => 3,
        'canceled' => 3,
    ];
    return $ranks[$status] ?? null;
}

/**
 * Apply a Twilio status callback (sent/delivered/undelivered/failed...) to the
 * ledger row carrying that message sid.
 *
 * Ledger honesty rules:
 *   - Only known Twilio statuses are written; anything else is refused.
 *   - A replayed or out-of-order callback cannot move a row backwards
 *     (delivered stays delivered even if a stale "queued" webhook arrives).
 *   - A follow-up callback without an error message never erases stored
 *     failure evidence.
 *
 * @param string $sid          outbound message sid
 * @param string $status       new MessageStatus from Twilio
 * @param string $errorMessage optional ErrorMessage from Twilio
 * @return bool true when the row changed
 */
function bakery_text_apply_status_callback(PDO $db, string $sid, string $status, string $errorMessage = ''): bool
{
    $sid = trim($sid);
    $status = strtolower(trim($status));
    if ($sid === '' || !bakery_text_messages_ready($db)) {
        return false;
    }
    $newRank = bakery_text_status_rank($status);
    if ($newRank === null) {
        error_log('text comms status callback: refused unknown status "' . $status . '" for sid ' . $sid);
        return false;
    }
    $errorMessage = trim($errorMessage);

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $read = $db->prepare(
            "SELECT status FROM text_messages
             WHERE twilio_sid = ? AND direction = 'outbound'
             FOR UPDATE"
        );
        $read->execute([$sid]);
        $currentStatus = $read->fetchColumn();
        if ($currentStatus === false) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            return false;
        }
        $currentRank = bakery_text_status_rank(strtolower((string)$currentStatus));
        if ($currentRank !== null && $newRank < $currentRank) {
            // Stale webhook: the ledger already knows more than this callback.
            if ($ownTransaction) {
                $db->rollBack();
            }
            return false;
        }

        $update = $db->prepare(
            "UPDATE text_messages
             SET status = ?,
                 error_message = CASE WHEN ? <> '' THEN ? ELSE error_message END
             WHERE twilio_sid = ? AND direction = 'outbound'"
        );
        $update->execute([$status, $errorMessage, $errorMessage, $sid]);
        $updated = $update->rowCount() > 0;
        if ($ownTransaction) {
            $db->commit();
        }
        return $updated;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/**
 * True when an inbound message with this Twilio sid is already on the ledger.
 * Twilio retries webhook POSTs until they answer 200; combined with the unique
 * sid key this keeps one inbound fact as exactly one row.
 */
function bakery_text_inbound_already_recorded(PDO $db, string $sid): bool
{
    $sid = trim($sid);
    if ($sid === '' || !bakery_text_messages_ready($db)) {
        return false;
    }
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM text_messages WHERE twilio_sid = ? AND direction = 'inbound'"
        );
        $stmt->execute([$sid]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('text comms inbound dedup check: ' . $e->getMessage());
        return false;
    }
}
