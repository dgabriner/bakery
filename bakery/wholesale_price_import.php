<?php
/**
 * Wholesale price import. Administrator and manager.
 * Preview writes nothing. Apply writes products.wholesale_price only.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/wholesale_price_import.php';

bakery_require_role(bakery_wholesale_price_import_roles());

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD, POST');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo bakery_t('wholesale_import.method_not_allowed');
    exit;
}

$csv = '';
$preview = null;
$error = null;
$flash = null;
if ($method === 'GET' && isset($_SESSION['wholesale_price_import_flash'])) {
    $flash = $_SESSION['wholesale_price_import_flash'];
    unset($_SESSION['wholesale_price_import_flash']);
}

if ($method === 'POST') {
    bakery_require_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'preview') {
            $csv = bakery_wholesale_price_import_request_csv();
            if (trim($csv) === '') {
                $error = 'wholesale_import.empty_input';
            } else {
                $preview = bakery_wholesale_price_import_preview($db, $csv);
            }
        } elseif ($action === 'apply') {
            $csv = (string)($_POST['csv'] ?? '');
            $result = bakery_wholesale_price_import_apply($db, $csv);
            $_SESSION['wholesale_price_import_flash'] = ['applied' => (int)$result['applied']];
            header('Location: wholesale_price_import.php?applied=1');
            exit;
        } else {
            $error = 'wholesale_import.bad_action';
        }
    } catch (RuntimeException $e) {
        $key = $e->getMessage();
        $error = strpos($key, 'wholesale_import.') === 0 ? $key : 'wholesale_import.bad_file';
        $csv = (string)($_POST['csv'] ?? '');
    }
}

$page_title = bakery_t('page.wholesale_price_import');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
echo bakery_wholesale_price_import_render([
    'csv' => $csv,
    'preview' => $preview,
    'error' => $error,
    'flash' => $flash,
]);
require_once __DIR__ . '/includes/footer.php';
