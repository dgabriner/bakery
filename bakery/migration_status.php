<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
$path = __DIR__ . '/storage/deploy/HOSTED_MIGRATION_STATUS.json';
$data = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
echo json_encode(is_array($data) ? [
    'status' => (string)($data['status'] ?? 'unknown'),
    'release_id' => (string)($data['release_id'] ?? ''),
    'migration_id' => (string)($data['migration_id'] ?? ''),
    'phase' => (string)($data['phase'] ?? ''),
    'started_at' => (string)($data['started_at'] ?? ''),
    'completed_at' => (string)($data['completed_at'] ?? ''),
    'statement_count' => (int)($data['statement_count'] ?? 0),
    'completed_statements' => (int)($data['completed_statements'] ?? 0),
    'message' => (string)($data['public_message'] ?? ''),
] : ['status' => 'idle', 'message' => 'No hosted migration has run yet.']);
