<?php
/**
 * Shared transport for the Ox controller: persistent opencode serve on localhost.
 * Credentials come from OPENCODE_SERVER_PASSWORD and are never printed or logged.
 */
function ox_server_base(): string
{
    $file = ($GLOBALS['ox_tmp'] ?? sys_get_temp_dir() . '/ox') . '/server.json';
    if (!empty($GLOBALS['args']['url'])) {
        return rtrim((string)$GLOBALS['args']['url'], '/');
    }
    if (is_file($file)) {
        $j = json_decode((string)file_get_contents($file), true);
        if (!empty($j['url'])) {
            return rtrim((string)$j['url'], '/');
        }
    }
    return 'http://127.0.0.1:4119';
}

function ox_http(string $method, string $path, ?array $body = null, int $timeout = 15)
{
    $url = ox_server_base() . $path;
    $password = getenv('OPENCODE_SERVER_PASSWORD');
    if ($password === false || $password === '') {
        throw new RuntimeException('OPENCODE_SERVER_PASSWORD is not set');
    }
    $headers = ['Authorization: Basic ' . base64_encode('opencode:' . $password)];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($headers, ['Content-Type: application/json']));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) {
        throw new RuntimeException("http {$method} {$path} failed: {$err}");
    }
    $decoded = json_decode((string)$res, true);
    if ($code >= 400) {
        throw new RuntimeException("http {$method} {$path} -> {$code}: " . substr((string)$res, 0, 200));
    }
    return $decoded;
}
