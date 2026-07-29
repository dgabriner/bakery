<?php
/**
 * HTTP login smoke test (CLI). Args: base_url email password
 * Does not print the password.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$base = rtrim($argv[1] ?? 'http://localhost:8080/bakery', '/');
$email = $argv[2] ?? '';
$password = $argv[3] ?? '';
if ($email === '' || $password === '') {
    fwrite(STDERR, "Usage: php http_login_test.php base_url email password\n");
    exit(1);
}

$cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bakery_http_login_cookies.txt';
@unlink($cookieFile);

function http_req($url, $cookieFile, $post = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return [0, '', '', $err];
    }
    $parts = explode("\r\n\r\n", $raw, 2);
    $headers = $parts[0] ?? '';
    $body = $parts[1] ?? '';
    return [$code, $headers, $body, ''];
}

[$code, $headers, $body, $err] = http_req($base . '/login.php', $cookieFile);
if ($code !== 200) {
    echo "GET_LOGIN_FAIL code={$code} err={$err}\n";
    echo substr($body, 0, 400) . "\n";
    exit(1);
}

if (!preg_match('/name="csrf_token"\s+value="([^"]+)"/', $body, $m)) {
    echo "CSRF_MISS\n";
    echo substr($body, 0, 400) . "\n";
    exit(1);
}
$csrf = $m[1];
echo "GET_LOGIN_OK csrf_len=" . strlen($csrf) . "\n";

[$code, $headers, $body, $err] = http_req($base . '/login.php', $cookieFile, [
    'csrf_token' => $csrf,
    'email' => $email,
    'password' => $password,
    'next' => '/bakery/index.php',
]);

$location = '';
if (preg_match('/^Location:\s*(.+)$/mi', $headers, $lm)) {
    $location = trim($lm[1]);
}

echo "POST_CODE={$code}\n";
echo "LOCATION={$location}\n";

if ($code >= 300 && $code < 400 && strpos($location, 'index.php') !== false) {
    echo "HTTP_LOGIN_OK\n";
    exit(0);
}

if (preg_match('/class="error">([^<]+)/', $body, $em)) {
    echo "ERROR={$em[1]}\n";
}
echo "HTTP_LOGIN_FAIL\n";
echo substr($body, 0, 500) . "\n";
exit(1);
