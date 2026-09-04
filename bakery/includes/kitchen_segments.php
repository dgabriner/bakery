<?php
/**
 * Sticky Mix / Bake / Pack segment control for the baker Today workspace.
 * URLs stay baker_mix.php / production.php / pack_list.php; the segment is nav.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * @return 'mix'|'bake'|'pack'|''
 */
function bakery_kitchen_segment_for_page(?string $page = null): string
{
    $page = $page ?? basename((string)($_SERVER['PHP_SELF'] ?? ''), '.php');
    switch ($page) {
        case 'baker_mix':
            return 'mix';
        case 'production':
            return 'bake';
        case 'pack_list':
            return 'pack';
        default:
            return '';
    }
}

function bakery_kitchen_segment_date(?string $date = null): string
{
    $raw = $date ?? ($_GET['date'] ?? date('Y-m-d', strtotime('+1 day')));
    $raw = trim((string)$raw);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        return date('Y-m-d', strtotime('+1 day'));
    }
    return $raw;
}

/**
 * Preserve line filter query when hopping Mix ↔ Bake ↔ Pack.
 */
function bakery_kitchen_segment_query(string $date): string
{
    $params = ['date' => $date];
    foreach (['line', 'product_line_id', 'view', 'driver_id'] as $key) {
        if (isset($_GET[$key]) && (string)$_GET[$key] !== '') {
            $params[$key] = (string)$_GET[$key];
        }
    }
    return http_build_query($params);
}

function bakery_kitchen_segments_html(?string $active = null, ?string $date = null): string
{
    $active = $active ?: bakery_kitchen_segment_for_page();
    if ($active === '') {
        return '';
    }
    $date = bakery_kitchen_segment_date($date);
    $q = bakery_kitchen_segment_query($date);
    $base = defined('BASE_URL') ? BASE_URL : '';
    $segments = [
        'mix' => ['href' => $base . 'baker_mix.php?' . $q, 'label' => bakery_t('kitchen.segment_mix')],
        'bake' => ['href' => $base . 'production.php?' . $q, 'label' => bakery_t('kitchen.segment_bake')],
        'pack' => ['href' => $base . 'pack_list.php?' . $q, 'label' => bakery_t('kitchen.segment_pack')],
    ];
    $html = '<nav class="kitchen-segments" aria-label="' . htmlspecialchars(bakery_t('kitchen.segments_aria'), ENT_QUOTES, 'UTF-8') . '">';
    foreach ($segments as $key => $seg) {
        $isActive = $key === $active;
        $html .= '<a class="kitchen-segments__btn' . ($isActive ? ' is-active' : '') . '" href="'
            . htmlspecialchars($seg['href'], ENT_QUOTES, 'UTF-8') . '"'
            . ($isActive ? ' aria-current="page"' : '') . '>'
            . htmlspecialchars($seg['label'], ENT_QUOTES, 'UTF-8')
            . '</a>';
    }
    $html .= '</nav>';
    return $html;
}

function bakery_kitchen_segments_render(?string $active = null, ?string $date = null): void
{
    echo bakery_kitchen_segments_html($active, $date);
}
