<?php
/**
 * Wholesale account health. Read-only GET for an administrator or manager.
 * Opened from the customer list and Customer Hub. Not a navigation module.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/account_health.php';

bakery_require_role(['administrator', 'manager']);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET' && $method !== 'HEAD') {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo bakery_t('account_health.method_not_allowed');
    exit;
}

$asOf = date('Y-m-d');
$query = [
    'status' => bakery_account_health_normalize_status((string)($_GET['status'] ?? 'all')),
    'sort' => bakery_account_health_normalize_sort((string)($_GET['sort'] ?? 'status')),
    'dir' => bakery_account_health_normalize_dir((string)($_GET['dir'] ?? 'asc')),
];

$allRows = bakery_account_health_rows($db, $asOf);
$rows = bakery_account_health_sort(
    bakery_account_health_filter($allRows, $query['status']),
    $query['sort'],
    $query['dir']
);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="account-health-' . $asOf . '.csv"');
    header('Cache-Control: no-store');
    echo bakery_account_health_csv($rows);
    exit;
}

$page_title = bakery_t('account_health.title');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<div class="container container--wide">
    <h1><?php echo htmlspecialchars(bakery_t('account_health.title'), ENT_QUOTES, 'UTF-8'); ?></h1>
    <?php echo bakery_account_health_render($rows, $allRows, $query); ?>
<?php
require_once __DIR__ . '/includes/footer.php';
