<?php
/** Public, non-sensitive status for the hosted Staging to Live promotion. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
if (($_SERVER['HTTP_ORIGIN'] ?? '') === 'https://staging.sourflour.org') {
    header('Access-Control-Allow-Origin: https://staging.sourflour.org');
    header('Vary: Origin');
}

$path = __DIR__ . '/storage/deploy/HOSTED_PROMOTION_STATUS.json';
if (!is_file($path)) {
    echo json_encode(['status' => 'idle', 'message' => 'No hosted promotion has run yet.']);
    exit;
}
$data = json_decode((string)@file_get_contents($path), true);
if (!is_array($data)) {
    http_response_code(503);
    echo json_encode(['status' => 'unavailable', 'message' => 'Promotion status is unavailable.']);
    exit;
}
$historyPath = __DIR__ . '/storage/deploy/HOSTED_PROMOTION_HISTORY.json';
$historyRaw = is_file($historyPath) ? json_decode((string)@file_get_contents($historyPath), true) : null;
$history = is_array($historyRaw) ? ($historyRaw['events'] ?? []) : [];
if (!is_array($history)) {
    $history = [];
}
$history = array_slice(array_reverse($history), 0, 40);
echo json_encode([
    'status' => (string)($data['status'] ?? 'unknown'),
    'release_id' => (string)($data['release_id'] ?? ''),
    'requested_at' => (string)($data['requested_at'] ?? ''),
    'started_at' => (string)($data['started_at'] ?? ''),
    'completed_at' => (string)($data['completed_at'] ?? ''),
    'file_count' => (int)($data['file_count'] ?? 0),
    'changed_file_count' => (int)($data['changed_file_count'] ?? 0),
    'health' => (string)($data['health'] ?? ''),
    'message' => (string)($data['public_message'] ?? 'Promotion status updated.'),
    'history' => $history,
], JSON_UNESCAPED_SLASHES);
