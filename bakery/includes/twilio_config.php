<?php
/**
 * Twilio configuration — values come from environment (via config.php / .env).
 * Used for the Texting Command Center: outbound SMS and inbound webhook.
 *
 * Never commit tokens. Set TWILIO_ACCOUNT_SID / TWILIO_AUTH_TOKEN /
 * TWILIO_FROM_NUMBER (or TWILIO_MESSAGING_SERVICE_SID) in bakery/.env.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

define('TWILIO_ACCOUNT_SID', (string)($_ENV['TWILIO_ACCOUNT_SID'] ?? getenv('TWILIO_ACCOUNT_SID') ?: ''));
define('TWILIO_AUTH_TOKEN', (string)($_ENV['TWILIO_AUTH_TOKEN'] ?? getenv('TWILIO_AUTH_TOKEN') ?: ''));
define('TWILIO_FROM_NUMBER', (string)($_ENV['TWILIO_FROM_NUMBER'] ?? getenv('TWILIO_FROM_NUMBER') ?: ''));
define('TWILIO_MESSAGING_SERVICE_SID', (string)($_ENV['TWILIO_MESSAGING_SERVICE_SID'] ?? getenv('TWILIO_MESSAGING_SERVICE_SID') ?: ''));

// Optional public URL that receives delivery status callbacks (sent → delivered/failed).
define('TWILIO_STATUS_CALLBACK_URL', (string)($_ENV['TWILIO_STATUS_CALLBACK_URL'] ?? getenv('TWILIO_STATUS_CALLBACK_URL') ?: ''));

// Webhook signature validation defaults ON whenever an auth token is present;
// set TWILIO_VALIDATE_WEBHOOK=0 only for local curl experiments.
$twilioValidateRaw = strtolower((string)($_ENV['TWILIO_VALIDATE_WEBHOOK'] ?? getenv('TWILIO_VALIDATE_WEBHOOK') ?: ''));
if ($twilioValidateRaw === '') {
    $twilioValidateWebhook = TWILIO_AUTH_TOKEN !== '';
} else {
    $twilioValidateWebhook = !in_array($twilioValidateRaw, ['0', 'false', 'no', 'off'], true);
}
define('TWILIO_VALIDATE_WEBHOOK', $twilioValidateWebhook);
// True only when an operator wrote TWILIO_VALIDATE_WEBHOOK=0|false|no|off — the
// sole way an unsigned inbound POST may be processed (local curl experiments).
define('TWILIO_WEBHOOK_VALIDATION_EXPLICITLY_OFF', $twilioValidateRaw !== '' && !$twilioValidateWebhook);

define('TWILIO_API_BASE', 'https://api.twilio.com');

/**
 * Whether outbound sends can reach Twilio (credentials + a sender).
 */
function twilio_is_configured(): bool
{
    if (TWILIO_ACCOUNT_SID === '' || TWILIO_AUTH_TOKEN === '') {
        return false;
    }
    return TWILIO_FROM_NUMBER !== '' || TWILIO_MESSAGING_SERVICE_SID !== '';
}

/**
 * True when the account SID has the canonical Twilio shape (AC + 32 hex).
 * Catches paste mistakes like putting the SID into the token field —
 * both fields being identical is the common slip this catches.
 */
function twilio_credentials_look_sane(): bool
{
    if (TWILIO_ACCOUNT_SID === '' || TWILIO_AUTH_TOKEN === '') {
        return false;
    }
    if (!preg_match('/^AC[0-9a-f]{32}$/', TWILIO_ACCOUNT_SID)) {
        return false;
    }
    if (TWILIO_AUTH_TOKEN === TWILIO_ACCOUNT_SID) {
        return false;
    }
    if (!preg_match('/^[0-9a-f]{32}$/', TWILIO_AUTH_TOKEN)) {
        return false;
    }
    return true;
}

/**
 * Perform a Twilio REST request (form-encoded, basic auth).
 * Returns decoded assoc array or throws RuntimeException.
 *
 * Test seam: set $GLOBALS['bakery_twilio_api_handler'] to a callable
 * ($method, $path, array $formFields) => array.
 *
 * @param string $method GET|POST
 * @param string $path   Path beginning with / (e.g. /2010-04-01/Accounts/.../Messages.json)
 * @param array  $form   Form fields for POST
 * @return array
 */
function twilio_api_request(string $method, string $path, array $form = []): array
{
    if (isset($GLOBALS['bakery_twilio_api_handler']) && is_callable($GLOBALS['bakery_twilio_api_handler'])) {
        return (array)call_user_func($GLOBALS['bakery_twilio_api_handler'], $method, $path, $form);
    }

    if (!twilio_is_configured()) {
        throw new RuntimeException(
            'Twilio is not configured. Set TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN plus TWILIO_FROM_NUMBER (or TWILIO_MESSAGING_SERVICE_SID) in .env.'
        );
    }

    $url = rtrim(TWILIO_API_BASE, '/') . $path;

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Unable to initialize cURL for Twilio API.');
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
    ];
    // Windows PHP often has no curl.cainfo; use the OS trust store when available.
    if (defined('CURLOPT_SSL_OPTIONS') && defined('CURLSSLOPT_NATIVE_CA')) {
        $opts[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
    }
    if ($form !== []) {
        $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
    }
    curl_setopt_array($ch, $opts);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        throw new RuntimeException('Twilio API cURL error: ' . $error);
    }

    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Twilio API returned non-JSON (HTTP ' . $status . ').');
    }

    if ($status < 200 || $status >= 300) {
        $detail = '';
        if (!empty($decoded['message'])) {
            $detail = (string)$decoded['message'];
            if (!empty($decoded['code'])) {
                $detail .= ' (code ' . $decoded['code'] . ')';
            }
        }
        throw new RuntimeException('Twilio API HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''));
    }

    return $decoded;
}

/**
 * Validate an X-Twilio-Signature header per Twilio's algorithm:
 * BASE string = full request URL + sorted "keyvalue" concatenation of POST params,
 * HMAC-SHA1 with the auth token, base64-encoded.
 *
 * @param string $signature X-Twilio-Signature header value
 * @param string $url       Full public URL Twilio called
 * @param array  $params    Parsed POST params
 */
function twilio_validate_signature(string $signature, string $url, array $params): bool
{
    if (TWILIO_AUTH_TOKEN === '') {
        return false;
    }
    $data = $url;
    $keys = array_keys($params);
    sort($keys);
    foreach ($keys as $key) {
        $data .= $key . (string)$params[$key];
    }
    $expected = base64_encode(hash_hmac('sha1', $data, TWILIO_AUTH_TOKEN, true));
    return hash_equals($expected, $signature);
}
