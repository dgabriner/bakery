<?php
/**
 * Square API configuration — values come from environment (via config.php / .env).
 * Used for ACH invoicing via Square Invoices.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$squareEnv = strtolower((string)($_ENV['SQUARE_ENV'] ?? getenv('SQUARE_ENV') ?: 'sandbox'));
if (!in_array($squareEnv, ['sandbox', 'production'], true)) {
    $squareEnv = 'sandbox';
}

define('SQUARE_ENV', $squareEnv);
define('SQUARE_ACCESS_TOKEN', (string)($_ENV['SQUARE_ACCESS_TOKEN'] ?? getenv('SQUARE_ACCESS_TOKEN') ?: ''));
define('SQUARE_APPLICATION_ID', (string)($_ENV['SQUARE_APPLICATION_ID'] ?? getenv('SQUARE_APPLICATION_ID') ?: ''));
define('SQUARE_LOCATION_ID', (string)($_ENV['SQUARE_LOCATION_ID'] ?? getenv('SQUARE_LOCATION_ID') ?: ''));
define('SQUARE_WEBHOOK_SIGNATURE_KEY', (string)($_ENV['SQUARE_WEBHOOK_SIGNATURE_KEY'] ?? getenv('SQUARE_WEBHOOK_SIGNATURE_KEY') ?: ''));

define(
    'SQUARE_API_BASE',
    SQUARE_ENV === 'production'
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com'
);

/**
 * Whether required Square credentials for API calls are present.
 */
function square_is_configured(): bool
{
    return SQUARE_ACCESS_TOKEN !== '' && SQUARE_LOCATION_ID !== '';
}

/**
 * Perform a Square REST request. Returns decoded JSON array or throws RuntimeException.
 *
 * @param string $method GET|POST|PUT|DELETE
 * @param string $path   Path beginning with /v2/...
 * @param array|null $body JSON body for non-GET
 * @return array
 */
function square_api_request(string $method, string $path, ?array $body = null): array
{
    if (!square_is_configured()) {
        throw new RuntimeException('Square is not configured. Set SQUARE_ACCESS_TOKEN and SQUARE_LOCATION_ID in .env.');
    }

    $url = rtrim(SQUARE_API_BASE, '/') . $path;
    $headers = [
        'Authorization: Bearer ' . SQUARE_ACCESS_TOKEN,
        'Square-Version: 2025-01-23',
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL for Square API.');
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('Square API cURL error: ' . $error);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Square API returned non-JSON (HTTP ' . $status . ').');
    }

    if ($status < 200 || $status >= 300) {
        $detail = '';
        if (!empty($decoded['errors'][0]['detail'])) {
            $detail = (string)$decoded['errors'][0]['detail'];
        } elseif (!empty($decoded['errors'][0]['code'])) {
            $detail = (string)$decoded['errors'][0]['code'];
        }
        throw new RuntimeException('Square API HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
    }

    return $decoded;
}
