<?php
/**
 * What needs a person today and tomorrow. Administrator and manager.
 * GET and HEAD only. ?format=json reuses the daily status token or session.
 * This script never writes.
 */
$wantsJson = (string)($_GET['format'] ?? '') === 'json';
if ($wantsJson) {
    define('BAKERY_SKIP_REQUEST_SECURITY', true);
}
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/today_list.php';

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!bakery_today_method_allowed($method)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    header('Cache-Control: private, no-store');
    $payload = $wantsJson
        ? json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : bakery_t('today.method_not_allowed');
    header('Content-Type: ' . ($wantsJson ? 'application/json' : 'text/plain') . '; charset=utf-8');
    header('Content-Length: ' . strlen((string)$payload));
    if ($method !== 'HEAD') {
        echo $payload;
    }
    exit;
}

if (!isset($db) || !($db instanceof PDO)) {
    $db = check_mysql_connection();
}

if ($wantsJson) {
    $result = bakery_today_dispatch(
        $db,
        $method,
        (string)($_GET['date'] ?? ''),
        $_SERVER
    );
    $json = json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('Content-Length: ' . strlen((string)$json));
    if ($method !== 'HEAD') {
        echo $json;
    }
    exit;
}

bakery_require_role(['administrator', 'manager']);

$selectedDate = bakery_dashboard_resolve_date();
$page_title = bakery_t('today.page_title');
header('Cache-Control: private, no-store');

$collected = null;
try {
    $collected = bakery_today_collect($db, $selectedDate);
} catch (Throwable $e) {
    error_log('today page: ' . $e->getMessage());
    $collected = null;
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<div class="container today-page">
    <header class="today-header">
        <p class="today-kicker"><a href="index.php?date=<?php echo rawurlencode($selectedDate); ?>"><?php echo htmlspecialchars(bakery_t('nav.item.index'), ENT_QUOTES, 'UTF-8'); ?></a></p>
        <h1><?php echo htmlspecialchars(bakery_t('today.heading'), ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars(bakery_t('today.intro'), ENT_QUOTES, 'UTF-8'); ?></p>
    </header>
    <?php
    if (!is_array($collected)) {
        echo '<p class="today-unavailable" role="alert">'
            . htmlspecialchars(bakery_t('today.unavailable'), ENT_QUOTES, 'UTF-8')
            . '</p>';
    } else {
        echo bakery_today_render_items($collected['items'], (bool)$collected['complete']);
    }
    ?>
</div>
<style>
    .today-page {
        padding: 24px 16px 48px;
        color: var(--sf-text, #1c2a26);
        max-width: 44rem;
    }
    .today-kicker {
        margin: 0 0 6px;
        font-size: 0.85rem;
    }
    .today-kicker a {
        color: var(--sf-primary, #24746a);
        font-weight: 700;
    }
    .today-header h1 {
        margin: 0 0 8px;
        font-size: 1.8rem;
    }
    .today-header p {
        margin: 0 0 16px;
        color: var(--sf-text-secondary, #4a5c57);
    }
    .today-clear,
    .today-unavailable,
    .today-partial {
        margin: 0;
        padding: 16px;
        border-radius: var(--sf-radius-sm, 8px);
        background: var(--sf-surface, #fffefb);
        border: 1px solid var(--sf-border-strong, #c9bda9);
    }
    .today-clear {
        background: #e7f4ef;
    }
    .today-unavailable {
        background: #fde8e4;
    }
    .today-partial {
        margin-bottom: 12px;
        background: #fff4d6;
    }
    .today-list {
        list-style: none;
        margin: 0;
        padding: 0;
        display: grid;
        gap: 12px;
    }
    .today-item {
        margin: 0;
        padding: 14px 14px 12px;
        border-radius: var(--sf-radius-sm, 8px);
        background: var(--sf-surface, #fffefb);
        border: 1px solid var(--sf-border-strong, #c9bda9);
        overflow-wrap: anywhere;
    }
    .today-item--critical {
        border-color: #8c2f2f;
    }
    .today-item--warning {
        border-color: #8a5a12;
    }
    .today-item--info {
        border-color: #245c73;
    }
    .today-item__meta {
        margin: 0 0 6px;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.02em;
        text-transform: uppercase;
    }
    .today-item--critical .today-item__severity { color: #8c2f2f; }
    .today-item--warning .today-item__severity { color: #8a5a12; }
    .today-item--info .today-item__severity { color: #245c73; }
    .today-item__when {
        color: var(--sf-text-secondary, #4a5c57);
        font-weight: 600;
    }
    .today-item__sentence {
        margin: 0 0 10px;
        font-size: 1.05rem;
        line-height: 1.4;
    }
    .today-item__fix {
        display: inline-flex;
        align-items: center;
        min-height: var(--sf-touch-min, 44px);
        padding: 0 14px;
        border: 1px solid var(--sf-border-strong, #c9bda9);
        border-radius: var(--sf-radius-sm, 8px);
        background: var(--sf-surface, #fffefb);
        color: var(--sf-primary, #24746a);
        font-weight: 700;
        text-decoration: none;
    }
    .today-item__fix:focus-visible {
        outline: 2px solid var(--sf-primary, #24746a);
        outline-offset: 2px;
    }
    @media (max-width: 420px) {
        .today-page {
            padding: 16px 12px 32px;
        }
        .today-header h1 {
            font-size: 1.5rem;
        }
        .today-item__fix {
            width: 100%;
            justify-content: center;
        }
    }
</style>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
