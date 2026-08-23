<?php
/**
 * Text comms media — MMS images and full Twilio history for the Command Center.
 *
 * Two jobs:
 *   1. Inbound capture: download webhook media to storage/text_media/ and attach
 *      it to the ledger row (kind=mms, media_count, media_json).
 *   2. History sync: page through the Twilio Messages API and upsert everything
 *      already on the account into the ledger, so the Center shows ALL traffic —
 *      not just what flowed through this app.
 *
 * Media is stored locally because Twilio media URLs require the account
 * credentials to fetch; staff view images through text_media.php instead.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/text_comms.php';

/** Absolute directory that holds downloaded text media. */
function bakery_text_media_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'text_media';
}

/** File extension for a Twilio media content type. */
function bakery_text_media_extension(string $contentType): string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'video/mp4' => 'mp4',
    ];
    $type = strtolower(trim($contentType));
    if (isset($map[$type])) {
        return $map[$type];
    }
    if (strpos($type, 'image/') === 0) {
        return 'img';
    }
    return 'bin';
}

/**
 * Fetch one Twilio media resource. Returns [body(bytes), content_type] or null.
 * Test seam: $GLOBALS['bakery_twilio_media_fetch'] = fn(string $url): ?array{0:string,1:string}
 *
 * @return array{0:string,1:string}|null
 */
function bakery_text_media_fetch(string $url): ?array
{
    if (isset($GLOBALS['bakery_twilio_media_fetch']) && is_callable($GLOBALS['bakery_twilio_media_fetch'])) {
        $fetched = call_user_func($GLOBALS['bakery_twilio_media_fetch'], $url);
        return is_array($fetched) ? $fetched : null;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || $status < 200 || $status >= 300 || !is_string($body) || $body === '') {
        error_log("text media fetch failed ($status): $url");
        return null;
    }
    return [$body, $type !== '' ? $type : 'application/octet-stream'];
}

/**
 * Download one media item into storage/text_media/YYYY-MM-DD/ and return its
 * ledger descriptor, or null when the download fails. Never overwrites an
 * existing file (sid + index are unique per message).
 *
 * @return array{url:string,content_type:string,path:string,bytes:int}|null
 */
function bakery_text_media_store(string $sid, int $index, string $url, ?array $preFetched = null): ?array
{
    $sid = trim($sid);
    $url = trim($url);
    if ($sid === '' || $url === '') {
        return null;
    }
    $fetched = $preFetched ?? bakery_text_media_fetch($url);
    if ($fetched === null) {
        return null;
    }
    [$body, $contentType] = $fetched;

    $dir = bakery_text_media_dir() . DIRECTORY_SEPARATOR . date('Y-m-d');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_log("text media store: cannot create $dir");
        return null;
    }

    $filename = $sid . '-' . $index . '-' . substr(md5($url), 0, 8) . '.' . bakery_text_media_extension($contentType);
    $path = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!file_exists($path) && @file_put_contents($path, $body) === false) {
        error_log("text media store: cannot write $path");
        return null;
    }

    // Store the path relative to the project root so it survives folder moves.
    $relative = 'storage/text_media/' . date('Y-m-d') . '/' . $filename;
    return [
        'url' => $url,
        'content_type' => $contentType,
        'path' => $relative,
        'bytes' => strlen($body),
    ];
}

/** Decode a row's media_json into a list of descriptors. */
function bakery_text_media_decode(?string $mediaJson): array
{
    if ($mediaJson === null || trim($mediaJson) === '') {
        return [];
    }
    $decoded = json_decode($mediaJson, true);
    return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
}

/** True when a relative path stays inside storage/text_media/. */
function bakery_text_media_path_safe(string $relative): bool
{
    if (strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
        return false;
    }
    $normalized = str_replace('\\', '/', $relative);
    return strpos($normalized, 'storage/text_media/') === 0;
}

/**
 * Attach captured/downloaded media to a ledger row and mark it mms.
 *
 * @param list<array{url:string,content_type:string,path:string,bytes:int}> $media
 */
function bakery_text_media_attach(PDO $db, int $rowId, array $media): bool
{
    if (!bakery_text_messages_ready($db)) {
        return false;
    }
    $stmt = $db->prepare(
        "UPDATE text_messages
         SET kind = ?, media_count = ?, media_json = ?
         WHERE id = ?"
    );
    try {
        $stmt->execute([
            'mms',
            count($media),
            json_encode($media, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $rowId,
        ]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('text media attach: ' . $e->getMessage());
        return false;
    }
}

/**
 * Collect webhook media params (MediaUrl0..9 / MediaContentType0..9), download
 * each, and attach to the freshly recorded inbound row. Safe no-op without media.
 */
function bakery_text_media_capture_inbound(PDO $db, int $rowId, string $sid, array $params): int
{
    $stored = [];
    for ($i = 0; $i < 10; $i++) {
        $url = trim((string)($params['MediaUrl' . $i] ?? ''));
        if ($url === '') {
            continue;
        }
        $contentType = trim((string)($params['MediaContentType' . $i] ?? ''));
        $descriptor = bakery_text_media_store($sid !== '' ? $sid : ('inbound-' . $rowId), $i, $url);
        if ($descriptor !== null) {
            if ($contentType !== '') {
                $descriptor['content_type'] = $contentType;
            }
            $stored[] = $descriptor;
        }
    }
    if ($stored === [] || !bakery_text_messages_ready($db)) {
        return 0;
    }
    return bakery_text_media_attach($db, $rowId, $stored) ? count($stored) : 0;
}

/**
 * Upsert one Twilio message resource into the ledger by sid.
 * Returns the row id, or 0 when the resource is unusable.
 *
 * @param array<string, mixed> $message Twilio Messages API resource
 */
function bakery_text_sync_upsert(PDO $db, array $message): int
{
    $sid = trim((string)($message['sid'] ?? ''));
    if ($sid === '' || stripos($sid, 'SM') !== 0 && stripos($sid, 'MM') !== 0) {
        return 0;
    }
    $from = bakery_text_normalize_phone((string)($message['from'] ?? ''));
    $to = bakery_text_normalize_phone((string)($message['to'] ?? ''));
    $body = (string)($message['body'] ?? '');
    $status = strtolower(trim((string)($message['status'] ?? 'queued')));
    $errorCode = isset($message['error_code']) ? (string)$message['error_code'] : '';
    $errorMessage = $errorCode !== ''
        ? 'Twilio error code ' . $errorCode . (isset($message['error_message']) ? ': ' . (string)$message['error_message'] : '')
        : '';

    $ourTail = bakery_text_phone_tail(defined('TWILIO_FROM_NUMBER') ? TWILIO_FROM_NUMBER : '');
    $fromTail = bakery_text_phone_tail($from);
    $direction = ($ourTail !== '' && $fromTail === $ourTail) ? 'outbound' : 'inbound';
    $counterpart = $direction === 'outbound' ? $to : $from;
    $customerId = bakery_text_link_customer($db, $counterpart);

    $dateSent = trim((string)($message['date_sent'] ?? ''));
    $parsedDate = $dateSent !== '' ? strtotime($dateSent) : false;
    // Twilio returns RFC-2822 dates ("Sat, 22 Aug 2026 ..."); normalize safely.
    $operatingDate = $parsedDate !== false ? date('Y-m-d', $parsedDate) : date('Y-m-d');

    $numMedia = (int)($message['num_media'] ?? 0);
    $existingStmt = $db->prepare('SELECT id FROM text_messages WHERE twilio_sid = ? LIMIT 1');
    $existingStmt->execute([$sid]);
    $existingId = $existingStmt->fetchColumn();

    if ($existingId !== false) {
        $upd = $db->prepare(
            "UPDATE text_messages
             SET status = ?, error_message = ?, customer_id = COALESCE(?, customer_id)
             WHERE id = ?"
        );
        $upd->execute([$status, $errorMessage !== '' ? $errorMessage : null, $customerId, (int)$existingId]);
        $rowId = (int)$existingId;
    } else {
        try {
            $rowId = bakery_text_record($db, [
                'direction' => $direction,
                'status' => $status !== '' ? $status : 'queued',
                'from_number' => $from,
                'to_number' => $to,
                'body' => $body,
                'twilio_sid' => $sid,
                'customer_id' => $customerId,
                'context_type' => 'sync',
                'operating_date' => $operatingDate,
                'read_at' => $direction === 'inbound' ? null : date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            error_log('text sync upsert insert: ' . $e->getMessage());
            return 0;
        }
    }

    // Backfill media for messages that carry some but have none stored yet.
    if ($numMedia > 0) {
        $current = $db->prepare('SELECT media_json FROM text_messages WHERE id = ?');
        $current->execute([$rowId]);
        $already = bakery_text_media_decode((string)($current->fetchColumn() ?: ''));
        if (count($already) < $numMedia) {
            $stored = bakery_text_media_backfill_for_row($db, $rowId, $sid, $numMedia);
            if ($stored > 0) {
                return $rowId;
            }
        }
    }

    return $rowId;
}

/**
 * Fetch the Media subresource for one message and store every item.
 * Returns how many items were newly stored.
 */
function bakery_text_media_backfill_for_row(PDO $db, int $rowId, string $sid, int $expectedCount): int
{
    if (!defined('TWILIO_ACCOUNT_SID') || TWILIO_ACCOUNT_SID === '') {
        return 0;
    }
    $path = '/2010-04-01/Accounts/' . rawurlencode(TWILIO_ACCOUNT_SID) . '/Messages/' . rawurlencode($sid) . '/Media.json?PageSize=10';
    try {
        $listing = twilio_api_request('GET', $path);
    } catch (Throwable $e) {
        error_log('text media backfill list: ' . $e->getMessage());
        return 0;
    }

    $stored = [];
    foreach (($listing['media_items'] ?? []) as $i => $item) {
        $uri = (string)($item['uri'] ?? '');
        if ($uri === '') {
            continue;
        }
        // The JSON uri becomes the binary content when the .json suffix is dropped.
        $contentUrl = preg_replace('/\.json$/', '', TWILIO_API_BASE . $uri) ?: '';
        $fetched = bakery_text_media_fetch($contentUrl);
        if ($fetched === null) {
            continue;
        }
        [$body, $contentType] = $fetched;
        $descriptor = bakery_text_media_store($sid, $i, $contentUrl, $fetched);
        if ($descriptor === null) {
            continue;
        }
        $descriptor['content_type'] = $contentType;
        $stored[] = $descriptor;
        if (count($stored) >= max(1, $expectedCount)) {
            break;
        }
    }

    if ($stored === []) {
        return 0;
    }
    return bakery_text_media_attach($db, $rowId, $stored) ? count($stored) : 0;
}

/**
 * Page through Twilio's Messages API and upsert recent history.
 * Returns [found, inserted, updated, skipped].
 *
 * @return array{found:int,inserted:int,updated:int,skipped:int,started_at:string,ended_at:string}
 */
function bakery_text_sync_history(PDO $db, int $days = 30, int $maxPages = 8): array
{
    $result = ['found' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'started_at' => date('c'), 'ended_at' => ''];
    if (!twilio_is_configured()) {
        $result['ended_at'] = date('c');
        return $result;
    }
    if (!bakery_text_messages_ready($db)) {
        $result['ended_at'] = date('c');
        return $result;
    }

    $beforeIds = [];
    $idStmt = $db->query('SELECT twilio_sid FROM text_messages WHERE twilio_sid IS NOT NULL');
    foreach ($idStmt->fetchAll(PDO::FETCH_COLUMN) as $knownSid) {
        $beforeIds[(string)$knownSid] = true;
    }

    $dateFrom = date('Y-m-d', strtotime('-' . max(1, min(180, $days)) . ' days')) . 'T00:00:00Z';
    $path = '/2010-04-01/Accounts/' . rawurlencode(TWILIO_ACCOUNT_SID) . '/Messages.json'
        . '?PageSize=50&DateSent>=' . rawurlencode($dateFrom);

    $pages = 0;
    while ($pages < $maxPages) {
        try {
            $page = twilio_api_request('GET', $path);
        } catch (Throwable $e) {
            error_log('text sync history: ' . $e->getMessage());
            break;
        }
        $pages++;
        foreach (($page['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $result['found']++;
            $sid = (string)($message['sid'] ?? '');
            $isNew = $sid !== '' && !isset($beforeIds[$sid]);
            $rowId = bakery_text_sync_upsert($db, $message);
            if ($rowId <= 0) {
                $result['skipped']++;
                continue;
            }
            if ($isNew) {
                $result['inserted']++;
                $beforeIds[$sid] = true;
            } else {
                $result['updated']++;
            }
        }
        $nextUri = (string)($page['next_page_uri'] ?? '');
        if ($nextUri === '') {
            break;
        }
        $path = $nextUri;
    }

    $result['ended_at'] = date('c');
    return $result;
}
