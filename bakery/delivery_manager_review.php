<?php
/** Read-only delivery review. Managers and administrators only. */
define('ACCESS_ALLOWED', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/delivery_manager_review.php';

$user = bakery_current_user();
$reviewAccess = bakery_delivery_manager_review_access_status($user);
if ($reviewAccess === 302) {
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
if ($reviewAccess !== 200) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    exit;
}

$today = (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
$range = bakery_delivery_manager_review_parse_range(
    $today,
    trim((string)($_GET['from'] ?? '')),
    trim((string)($_GET['to'] ?? ''))
);
$view = bakery_delivery_manager_review_parse_view((string)($_GET['view'] ?? 'drivers'));
$filter = bakery_delivery_manager_review_parse_filter((string)($_GET['filter'] ?? 'all'));
$sort = bakery_delivery_manager_review_parse_sort((string)($_GET['sort'] ?? ''));
$query = bakery_delivery_manager_review_parse_query((string)($_GET['q'] ?? ''));
$driverId = bakery_delivery_manager_review_positive_id($_GET['driver'] ?? 0);
$customerId = bakery_delivery_manager_review_positive_id($_GET['customer'] ?? 0);
$dayParam = bakery_delivery_manager_review_parse_day((string)($_GET['day'] ?? ''));

$report = bakery_delivery_manager_review_load($db, (string)$range['from'], (string)$range['to']);
$from = (string)$report['from'];
$to = (string)$report['to'];
$totals = $report['totals'];
$dates = $report['dates'];
$stripDates = bakery_delivery_manager_review_strip_dates($dates);
$stripCut = count($stripDates) < count($dates);
$summary = bakery_delivery_manager_review_summary($report, $today);
$prior = bakery_delivery_manager_review_previous_range($from, $to);
$priorSummary = bakery_delivery_manager_review_summary(
    bakery_delivery_manager_review_load($db, (string)$prior['from'], (string)$prior['to']),
    $today
);
$insights = bakery_delivery_manager_review_insights($report, $today);
$presets = bakery_delivery_manager_review_presets($today);
$clockReady = !empty($report['clock_ready']);
$closeReady = !empty($report['close_ready']);

$selectedDriver = null;
foreach ($report['drivers'] as $driver) {
    if ((int)$driver['driver_id'] === $driverId) {
        $selectedDriver = $driver;
        break;
    }
}
$selectedCustomer = null;
foreach ($report['customers'] as $customer) {
    if ((int)$customer['customer_id'] === $customerId) {
        $selectedCustomer = $customer;
        break;
    }
}
$hasSelection = ($view === 'drivers' && $selectedDriver !== null) || ($view === 'customers' && $selectedCustomer !== null);

$searchedCustomers = [];
foreach ($report['customers'] as $customer) {
    if (bakery_delivery_manager_review_customer_search_match($customer, $query)) {
        $searchedCustomers[] = $customer;
    }
}
$filterCounts = bakery_delivery_manager_review_filter_counts($searchedCustomers);
$visibleCustomers = [];
foreach ($searchedCustomers as $customer) {
    if (bakery_delivery_manager_review_customer_matches($customer, $filter)) {
        $visibleCustomers[] = $customer;
    }
}
$visibleCustomers = bakery_delivery_manager_review_sort_customers($visibleCustomers, $sort);

$needsOpen = [];
$needsPhoto = [];
foreach ($report['needs'] as $need) {
    if ($selectedDriver !== null && (int)$need['driver_id'] !== (int)$selectedDriver['driver_id']) {
        continue;
    }
    if ((string)$need['reason'] === 'missing_after') {
        $needsPhoto[] = $need;
    } else {
        $needsOpen[] = $need;
    }
}

$base = defined('BASE_URL') ? (string)BASE_URL : '';
$page_title = bakery_t('page.delivery_manager_review');
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';

$h = static function (string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
};
$t = static function (string $key, array $params = []) use ($h): string {
    return $h(bakery_t('delivery_review.' . $key, $params));
};
$dateLabel = static function (string $date) use ($h): string {
    return $h(bakery_delivery_manager_review_display_date($date));
};
$blank = '<span class="delivery-review__muted" aria-hidden="true">&#183;</span>';
$clock = static function ($value): string {
    return bakery_route_photo_journey_format_clock($value === null ? null : (string)$value);
};
$timeCell = static function ($value) use ($h, $clock, $blank): string {
    $text = $clock($value);
    return $text === '' ? $blank : $h($text);
};
$usualClock = static function (?string $hhmm) use ($clock): string {
    return $hhmm === null ? '' : $clock('2000-01-01 ' . $hhmm . ':00');
};
$num = static function (int $value, string $tone = '') use ($blank): string {
    if ($value === 0) {
        return '<span class="delivery-review__muted">0</span>';
    }
    return $tone === '' ? (string)$value : '<strong class="delivery-review__num is-' . $tone . '">' . $value . '</strong>';
};
$badge = static function (string $tone, string $text) use ($h): string {
    return '<span class="sf-badge sf-badge--' . $h($tone) . '">' . $h($text) . '</span>';
};
$rateTone = static function (?int $percent, int $good, int $warn): string {
    if ($percent === null) {
        return 'none';
    }
    if ($percent >= $good) {
        return 'good';
    }
    return $percent >= $warn ? 'warn' : 'bad';
};
$bar = static function (?int $percent, string $tone): string {
    $width = $percent === null ? 0 : max(0, min(100, $percent));
    return '<svg class="delivery-review__bar is-' . $tone . '" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true" focusable="false">'
        . '<rect class="delivery-review__bar-track" x="0" y="0" width="100" height="8"></rect>'
        . '<rect class="delivery-review__bar-fill" x="0" y="0" width="' . $width . '" height="8"></rect></svg>';
};
$rateCell = static function (int $part, int $whole, int $good, int $warn) use ($bar, $rateTone, $t, $blank): string {
    if ($whole <= 0) {
        return $blank;
    }
    $percent = bakery_delivery_manager_review_percent($part, $whole);
    $tone = $rateTone($percent, $good, $warn);
    return '<span class="delivery-review__rate"><strong>' . (int)$percent . '%</strong>' . $bar($percent, $tone)
        . '<span class="delivery-review__muted">' . $t('of', ['part' => $part, 'whole' => $whole]) . '</span></span>';
};
$safePhoto = static function (string $url): bool {
    return $url !== '' && strpos($url, 'driver_photo.php') !== false && strpos($url, 'uploads/driver_photos') === false;
};
$photoCell = static function (string $url, bool $hasAfter, bool $delivered) use ($h, $t, $safePhoto, $blank): string {
    $note = ($delivered && !$hasAfter)
        ? '<span class="delivery-review__photo-note">' . $t('no_photo') . '</span>'
        : '';
    if (!$safePhoto($url)) {
        return $note !== '' ? $note : $blank;
    }
    return '<a class="delivery-review__thumb-link" href="' . $h($url) . '"><img class="delivery-review__thumb" loading="lazy" src="'
        . $h($url) . '" alt="' . $t('photo_alt') . '"></a>' . $note;
};
$cellSymbols = [
    'clean' => '&#10003;',
    'photo_gap' => '&#10003;',
    'problem' => '!',
    'cancelled' => '&#215;',
    'not_out' => '&#9675;',
    'covered' => '&#8764;',
    'paused' => '&#8226;',
    'idle' => '',
];
$cell = static function (string $state, string $date, string $text, string $label, string $href = '') use ($h, $cellSymbols): string {
    $class = 'delivery-review__cell is-' . $state;
    if ((int)(new DateTimeImmutable($date))->format('N') === 1) {
        $class .= ' is-monday';
    }
    $inner = $text !== '' ? $h($text) : ($cellSymbols[$state] ?? '');
    $attrs = ' class="' . $class . '" title="' . $h($label) . '" aria-label="' . $h($label) . '"';
    if ($href !== '') {
        return '<a' . $attrs . ' href="' . $h($href) . '">' . $inner . '</a>';
    }
    return '<span' . $attrs . ' role="img">' . $inner . '</span>';
};
$legend = static function (array $states) use ($h, $cellSymbols): string {
    $out = '<ul class="delivery-review__legend">';
    foreach ($states as $state) {
        $out .= '<li><span class="delivery-review__cell is-' . $state . '" aria-hidden="true">' . ($cellSymbols[$state] ?? '') . '</span>'
            . $h(bakery_t(bakery_delivery_manager_review_state_key($state))) . '</li>';
    }
    return $out . '</ul>';
};
$stripRange = $stripDates === []
    ? ''
    : bakery_t('delivery_review.range_line', [
        'from' => bakery_delivery_manager_review_display_date($stripDates[0]),
        'to' => bakery_delivery_manager_review_display_date($stripDates[count($stripDates) - 1]),
    ]);
$link = static function (array $params, string $anchor = '') use ($from, $to, $h): string {
    return $h(bakery_delivery_manager_review_href(['from' => $from, 'to' => $to] + $params) . $anchor);
};
$storeParams = ['view' => 'customers', 'filter' => $filter, 'sort' => $sort, 'q' => $query];

$trend = static function (?int $now, ?int $before, bool $higherIsBetter): string {
    if ($now === null || $before === null || $now === $before) {
        return '';
    }
    $up = $now > $before;
    $good = $up === $higherIsBetter;
    return '<span class="delivery-review__trend is-' . ($good ? 'good' : 'bad') . '" aria-hidden="true">' . ($up ? '&#9650;' : '&#9660;') . '</span>';
};
$percentText = static function (?int $percent): string {
    return $percent === null ? bakery_t('delivery_review.kpi_empty') : $percent . '%';
};
$problemTone = 'good';
if ((int)$summary['problem'] > 0) {
    $problemTone = ((int)$summary['stale_open'] + (int)$summary['failed']) > 0 ? 'bad' : 'warn';
}
$tiles = [
    [
        'label' => bakery_t('delivery_review.kpi_delivered'),
        'value' => $percentText($summary['delivered_rate']),
        'sub' => bakery_t('delivery_review.kpi_delivered_sub', ['delivered' => (int)$summary['delivered'], 'due' => (int)$summary['due']]),
        'prior' => $percentText($priorSummary['delivered_rate']),
        'trend' => $trend($summary['delivered_rate'], $priorSummary['delivered_rate'], true),
        'tone' => $rateTone($summary['delivered_rate'], 97, 90),
        'href' => $link(['view' => 'drivers']),
    ],
    [
        'label' => bakery_t('delivery_review.kpi_after'),
        'value' => $percentText($summary['after_rate']),
        'sub' => bakery_t('delivery_review.kpi_after_sub', ['after' => (int)$summary['with_after'], 'delivered' => (int)$summary['delivered']]),
        'prior' => $percentText($priorSummary['after_rate']),
        'trend' => $trend($summary['after_rate'], $priorSummary['after_rate'], true),
        'tone' => $rateTone($summary['after_rate'], 95, 80),
        'href' => $link(['view' => 'drivers'], '#delivery-review-photos'),
    ],
    [
        'label' => bakery_t('delivery_review.kpi_problem'),
        'value' => (string)(int)$summary['problem'],
        'sub' => bakery_t('delivery_review.kpi_problem_sub', [
            'open' => (int)$summary['open'],
            'failed' => (int)$summary['failed'],
            'cancelled' => (int)$summary['cancelled'],
        ]),
        'prior' => (string)(int)$priorSummary['problem'],
        'trend' => $trend((int)$summary['problem'], (int)$priorSummary['problem'], false),
        'tone' => $problemTone,
        'href' => $link(['view' => 'drivers'], '#delivery-review-needs'),
    ],
    [
        'label' => bakery_t('delivery_review.kpi_unassigned'),
        'value' => (string)(int)$summary['unassigned'],
        'sub' => bakery_t('delivery_review.kpi_unassigned_sub'),
        'prior' => (string)(int)$priorSummary['unassigned'],
        'trend' => $trend((int)$summary['unassigned'], (int)$priorSummary['unassigned'], false),
        'tone' => (int)$summary['unassigned'] > 0 ? 'bad' : 'good',
        'href' => $link(['view' => 'customers', 'filter' => 'unassigned']),
    ],
    [
        'label' => bakery_t('delivery_review.kpi_standing'),
        'value' => $percentText($summary['standing_rate']),
        'sub' => bakery_t('delivery_review.kpi_standing_sub', ['routed' => (int)$summary['standing_routed'], 'expected' => (int)$summary['expected']]),
        'prior' => $percentText($priorSummary['standing_rate']),
        'trend' => $trend($summary['standing_rate'], $priorSummary['standing_rate'], true),
        'tone' => 'none',
        'href' => $link(['view' => 'customers', 'filter' => 'short']),
    ],
];
$brief = bakery_delivery_manager_review_brief(
    $report,
    $today,
    $view === 'drivers' ? $driverId : 0,
    $view === 'customers' ? $customerId : 0,
    $view === 'customers' ? 'stores' : 'drivers'
);
$briefLead = bakery_t((string)$brief['lead']['key'], is_array($brief['lead']['params']) ? $brief['lead']['params'] : []);
$briefRate = $summary['delivered_rate'] === null
    ? bakery_t('delivery_review.kpi_empty')
    : ((int)$summary['delivered_rate']) . '%';
if ((int)$summary['due'] === 0 && (int)$summary['problem'] === 0 && (int)$summary['unassigned'] === 0) {
    $briefScore = bakery_t('delivery_review.brief_score_empty');
} elseif ((int)$summary['due'] > 0) {
    $briefScore = bakery_t('delivery_review.brief_score', [
        'rate' => $briefRate,
        'problem' => (int)$summary['problem'],
        'unassigned' => (int)$summary['unassigned'],
    ]);
} else {
    $briefScore = bakery_t('delivery_review.brief_score_plain', [
        'problem' => (int)$summary['problem'],
        'unassigned' => (int)$summary['unassigned'],
    ]);
}
$briefPrior = ($summary['delivered_rate'] !== null && $priorSummary['delivered_rate'] !== null)
    ? bakery_t('delivery_review.brief_prior', ['value' => ((int)$priorSummary['delivered_rate']) . '%'])
    : '';
$cardAction = static function (array $card) use ($base): string {
    $date = (string)($card['action_date'] ?? '');
    $driver = (int)($card['action_driver'] ?? 0);
    $customer = (int)($card['action_customer'] ?? 0);
    switch ((string)($card['action'] ?? '')) {
        case 'sheet':
            return $base . 'driver_reports.php?' . http_build_query(['tab' => 'sheet', 'date' => $date]);
        case 'assign':
            return $base . 'driver_assignment.php?' . http_build_query(['date' => $date]);
        case 'photos':
            $query = ['date' => $date];
            if ($driver > 0) {
                $query['driver_id'] = $driver;
            }
            return $base . 'route_photo_journey.php?' . http_build_query($query);
        case 'standing':
            return $base . 'standing_routes.php';
        case 'record':
            return $base . 'customer_record.php?' . http_build_query(['customer_id' => $customer]);
        default:
            return (string)($card['review_href'] ?? '');
    }
};
$cardActionKey = static function (array $card): string {
    switch ((string)($card['action'] ?? '')) {
        case 'sheet':
            return 'open_sheet';
        case 'assign':
            return 'open_assign';
        case 'photos':
            return 'open_photos';
        case 'standing':
            return 'open_standing';
        case 'record':
            return 'open_record';
        default:
            return 'see_here';
    }
};
$wallRows = $brief['wall']['rows'];
$wallCalm = $brief['wall']['calm_rows'];
$wallShown = $wallRows !== [] ? $wallRows : $wallCalm;
$wallExtra = $wallRows !== [] ? $wallCalm : [];
$wallSolo = $hasSelection;
$paintWallRow = static function (array $row) use ($h, $cell, $from, $to): void {
    echo '<div class="delivery-review__wall-row">';
    echo '<a class="delivery-review__wall-name" href="' . $h((string)$row['href']) . '">' . $h((string)$row['name']) . '</a>';
    echo '<span class="delivery-review__strip delivery-review__strip--wall">';
    foreach ($row['cells'] as $stripCell) {
        $state = (string)$stripCell['state'];
        $stops = (int)($stripCell['stops'] ?? 0);
        $what = bakery_t(bakery_delivery_manager_review_state_key($state));
        $date = (string)$stripCell['date'];
        $label = $stops > 0
            ? bakery_t('delivery_review.cell_title_driver', [
                'date' => bakery_delivery_manager_review_display_date($date),
                'what' => $what,
                'stops' => $stops,
                'delivered' => (int)($stripCell['delivered'] ?? 0),
            ])
            : bakery_t('delivery_review.cell_title', [
                'date' => bakery_delivery_manager_review_display_date($date),
                'what' => $what,
            ]);
        $dayHref = '';
        if ($state !== 'idle' && array_key_exists('stops', $stripCell)) {
            $dayHref = bakery_delivery_manager_review_href([
                'view' => 'drivers',
                'from' => $from,
                'to' => $to,
                'driver' => (int)$row['id'],
                'day' => $date,
            ]) . '#day-' . $date;
        } elseif ($state !== 'idle') {
            $dayHref = (string)$row['href'];
        }
        echo $cell($state, $date, $stops > 0 ? (string)$stops : ' ', $label, $dayHref);
    }
    echo '</span></div>';
};
?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/delivery_manager_review.css'); ?>">
<main class="delivery-review">
  <header class="delivery-review__header">
    <div>
      <h1><?php bakery_te('page.delivery_manager_review'); ?></h1>
    </div>
    <div class="delivery-review__controls">
      <nav class="delivery-review__presets" aria-label="<?php echo $t('presets_label'); ?>">
        <?php foreach ($presets as $preset): ?>
          <?php
            $presetOn = $from === $preset['from'] && $to === $preset['to'];
            $presetHref = bakery_delivery_manager_review_href([
                'view' => $view,
                'from' => $preset['from'],
                'to' => $preset['to'],
                'driver' => $driverId,
                'customer' => $customerId,
                'filter' => $filter,
                'sort' => $sort,
                'q' => $query,
            ]);
          ?>
          <a class="delivery-review__chip<?php echo $presetOn ? ' is-active' : ''; ?>" href="<?php echo $h($presetHref); ?>"<?php echo $presetOn ? ' aria-current="true"' : ''; ?>><?php echo $t('preset_days', ['days' => (int)$preset['days']]); ?></a>
        <?php endforeach; ?>
      </nav>
      <details class="delivery-review__dates">
        <summary><?php echo $t('choose_dates'); ?></summary>
      <form class="delivery-review__range" method="get" action="delivery_manager_review.php">
        <input type="hidden" name="view" value="<?php echo $h($view); ?>">
        <?php if ($view === 'drivers' && $driverId > 0): ?>
          <input type="hidden" name="driver" value="<?php echo $driverId; ?>">
        <?php endif; ?>
        <?php if ($view === 'customers'): ?>
          <?php if ($customerId > 0): ?><input type="hidden" name="customer" value="<?php echo $customerId; ?>"><?php endif; ?>
          <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?php echo $h($filter); ?>"><?php endif; ?>
          <?php if ($sort !== 'attention'): ?><input type="hidden" name="sort" value="<?php echo $h($sort); ?>"><?php endif; ?>
          <?php if ($query !== ''): ?><input type="hidden" name="q" value="<?php echo $h($query); ?>"><?php endif; ?>
        <?php endif; ?>
        <label>
          <span><?php echo $t('from'); ?></span>
          <input type="date" name="from" value="<?php echo $h($from); ?>" required>
        </label>
        <label>
          <span><?php echo $t('to'); ?></span>
          <input type="date" name="to" value="<?php echo $h($to); ?>" required>
        </label>
        <button class="sf-btn sf-btn--primary sf-btn--sm" type="submit"><?php echo $t('show'); ?></button>
      </form>
      </details>
    </div>
  </header>
  <?php if (!empty($range['clamped'])): ?>
    <p class="delivery-review__note"><?php echo $t('range_clamped', ['days' => BAKERY_ROUTE_HEALTH_MAX_DAYS]); ?></p>
  <?php endif; ?>

  <section class="delivery-review__brief<?php echo $wallSolo ? ' is-solo' : ''; ?>" aria-labelledby="delivery-review-lead">
    <p id="delivery-review-lead" class="delivery-review__hero is-<?php echo $h((string)$brief['tone']); ?>"><?php echo $h($briefLead); ?></p>
    <p class="delivery-review__score">
      <?php echo $h(bakery_t('delivery_review.range_line', [
          'from' => bakery_delivery_manager_review_display_date($from),
          'to' => bakery_delivery_manager_review_display_date($to),
      ])); ?>.
      <strong><?php echo $h($briefScore); ?></strong>
      <?php if ($briefPrior !== ''): ?><span class="delivery-review__muted"><?php echo $h($briefPrior); ?></span><?php endif; ?>
    </p>
    <?php if ($brief['cards'] !== []): ?>
      <ol class="delivery-review__cards">
        <?php foreach ($brief['cards'] as $card): ?>
          <?php
            $cardTitle = (string)($card['title'] ?? '');
            if ($cardTitle === '' && (string)($card['title_key'] ?? '') !== '') {
                $cardTitle = bakery_t((string)$card['title_key'], is_array($card['title_params'] ?? null) ? $card['title_params'] : []);
            }
            $cardSentence = bakery_t((string)$card['sentence_key'], is_array($card['sentence_params'] ?? null) ? $card['sentence_params'] : []);
            $cardHref = $cardAction($card);
            $cardWhen = (string)($card['when'] ?? '');
          ?>
          <li class="delivery-review__card is-<?php echo $h((string)$card['tone']); ?>">
            <p class="delivery-review__eyebrow"><?php echo $h(bakery_t((string)$card['eyebrow_key'])); ?></p>
            <p class="delivery-review__card-title">
              <?php if ((string)($card['review_href'] ?? '') !== ''): ?>
                <a href="<?php echo $h((string)$card['review_href']); ?>"><?php echo $h($cardTitle); ?></a>
              <?php else: ?>
                <?php echo $h($cardTitle); ?>
              <?php endif; ?>
            </p>
            <?php if ($cardWhen !== ''): ?><p class="delivery-review__card-when"><?php echo $dateLabel($cardWhen); ?></p><?php endif; ?>
            <p><?php echo $h($cardSentence); ?></p>
            <?php if ($safePhoto((string)($card['photo_url'] ?? ''))): ?>
              <a class="delivery-review__thumb-link" href="<?php echo $h((string)$card['photo_url']); ?>"><img class="delivery-review__thumb" loading="lazy" src="<?php echo $h((string)$card['photo_url']); ?>" alt="<?php echo $t('photo_alt'); ?>"></a>
            <?php endif; ?>
            <p class="delivery-review__card-actions">
              <a class="sf-btn sf-btn--primary sf-btn--sm" href="<?php echo $h($cardHref); ?>"><?php echo $t($cardActionKey($card)); ?></a>
              <?php if ((string)($card['review_href'] ?? '') !== '' && (string)$card['review_href'] !== $cardHref): ?>
                <a href="<?php echo $h((string)$card['review_href']); ?>"><?php echo $t('see_here'); ?></a>
              <?php endif; ?>
            </p>
          </li>
        <?php endforeach; ?>
      </ol>
      <?php if ((int)$brief['more'] > 0): ?>
        <p class="delivery-review__more"><a href="#delivery-review-record"><?php echo $t('brief_more', ['count' => (int)$brief['more']]); ?></a></p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($wallShown !== []): ?>
      <div class="delivery-review__wall">
        <h2><?php echo $t('wall_title'); ?></h2>
        <ul class="delivery-review__key">
          <?php foreach (['clean' => 'wall_key_landed', 'photo_gap' => 'wall_key_photo', 'problem' => 'wall_key_look', 'not_out' => 'wall_key_out', 'covered' => 'wall_key_covered'] as $state => $keyName): ?>
            <li><span class="delivery-review__cell is-<?php echo $h($state); ?>" aria-hidden="true"> </span><?php echo $t($keyName); ?></li>
          <?php endforeach; ?>
        </ul>
        <div class="delivery-review__wall-scroll">
          <div class="delivery-review__wall-row is-head" aria-hidden="true">
            <span class="delivery-review__wall-name"></span>
            <span class="delivery-review__strip delivery-review__strip--wall">
              <?php foreach ($brief['wall']['dates'] as $wallDate): ?>
                <span class="delivery-review__cell is-label"><?php echo $h((string)$wallDate['label']); ?></span>
              <?php endforeach; ?>
            </span>
          </div>
          <?php foreach ($wallShown as $wallRow): ?>
            <?php $paintWallRow($wallRow); ?>
          <?php endforeach; ?>
        </div>
        <p class="delivery-review__note"><?php echo $t('strip_note'); ?></p>
        <?php if ($wallExtra !== []): ?>
          <details class="delivery-review__calm">
            <summary><?php echo $t('wall_calm', ['count' => count($wallExtra)]); ?></summary>
            <div class="delivery-review__wall-scroll">
              <?php foreach ($wallExtra as $wallRow): ?>
                <?php $paintWallRow($wallRow); ?>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endif; ?>
        <?php if ((int)$brief['wall']['hidden'] > 0): ?>
          <p class="delivery-review__more"><a href="#delivery-review-record"><?php echo $t('wall_more', ['count' => (int)$brief['wall']['hidden']]); ?></a></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </section>

  <nav class="delivery-review__tabs" aria-label="<?php echo $t('tabs_label'); ?>">
    <a class="delivery-review__tab<?php echo $view === 'drivers' ? ' is-active' : ''; ?>" href="<?php echo $link(['view' => 'drivers', 'driver' => $driverId]); ?>"<?php echo $view === 'drivers' ? ' aria-current="page"' : ''; ?>><?php echo $t('tab_drivers'); ?> <span class="delivery-review__count"><?php echo count($report['drivers']); ?></span></a>
    <a class="delivery-review__tab<?php echo $view === 'customers' ? ' is-active' : ''; ?>" href="<?php echo $link($storeParams + ['customer' => $customerId]); ?>"<?php echo $view === 'customers' ? ' aria-current="page"' : ''; ?>><?php echo $t('tab_customers'); ?> <span class="delivery-review__count"><?php echo count($report['customers']); ?></span></a>
  </nav>

  <?php if (!$hasSelection): ?>
  <details class="delivery-review__record" id="delivery-review-record">
    <summary><?php echo $t('record_title'); ?></summary>
  <ul class="delivery-review__kpis">
    <?php foreach ($tiles as $tile): ?>
      <li class="delivery-review__kpi is-<?php echo $h((string)$tile['tone']); ?>">
        <a href="<?php echo $tile['href']; ?>">
          <span class="delivery-review__kpi-label"><?php echo $h((string)$tile['label']); ?></span>
          <strong class="delivery-review__kpi-value"><?php echo $h((string)$tile['value']); ?></strong>
          <span class="delivery-review__kpi-sub"><?php echo $h((string)$tile['sub']); ?></span>
          <span class="delivery-review__kpi-prior"><?php echo $tile['trend']; ?><?php echo $t('kpi_prior', ['value' => (string)$tile['prior']]); ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>

  <details class="delivery-review__findings">
    <summary><?php echo $t('insights_title'); ?> <span class="delivery-review__count"><?php echo count($insights); ?></span></summary>
    <ul>
      <?php foreach ($insights as $insight): ?>
        <?php $text = $h(bakery_t((string)$insight['key'], $insight['params'])); ?>
        <li class="is-<?php echo $h((string)$insight['tone']); ?>">
          <?php if ((string)$insight['href'] !== ''): ?>
            <a href="<?php echo $h((string)$insight['href']); ?>"><?php echo $text; ?></a>
          <?php else: ?>
            <?php echo $text; ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>

  <?php if ($view === 'drivers'): ?>
    <?php if ($selectedDriver === null): ?>
    <section class="delivery-review__section" aria-labelledby="delivery-review-drivers">
      <h2 id="delivery-review-drivers"><?php echo $t('drivers_heading'); ?></h2>
      <?php if ($driverId > 0 && $selectedDriver === null): ?>
        <p class="delivery-review__note"><?php echo $t('not_in_window'); ?></p>
      <?php endif; ?>
      <?php if ($report['drivers'] === []): ?>
        <p class="delivery-review__empty"><?php echo $t('drivers_empty'); ?></p>
      <?php else: ?>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm delivery-review__table">
            <thead>
              <tr>
                <th><?php echo $t('col_driver'); ?></th>
                <th class="is-num"><?php echo $t('col_days_out'); ?></th>
                <th class="is-num"><?php echo $t('col_stops'); ?></th>
                <th><?php echo $t('col_delivered'); ?></th>
                <th><?php echo $t('col_after'); ?></th>
                <th class="is-num"><?php echo $t('col_problem'); ?></th>
                <th><?php echo $t('col_hours'); ?></th>
                <?php if ($clockReady): ?><th><?php echo $t('col_clock'); ?></th><?php endif; ?>
                <th class="is-wide"><?php echo $t('col_strip'); ?><span class="delivery-review__th-sub"><?php echo $h($stripRange); ?></span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($report['drivers'] as $driver): ?>
                <?php
                  $rowDriverId = (int)$driver['driver_id'];
                  $current = $selectedDriver !== null && $rowDriverId === (int)$selectedDriver['driver_id'];
                  $worked = (int)$driver['days_worked'];
                  $start = $usualClock($driver['usual_start']);
                  $finish = $usualClock($driver['usual_finish']);
                ?>
                <tr class="<?php echo $current ? 'is-current' : ''; ?><?php echo $worked === 0 ? ' is-quiet' : ''; ?>">
                  <td data-label="<?php echo $t('col_driver'); ?>">
                    <a class="delivery-review__name" href="<?php echo $link(['view' => 'drivers', 'driver' => $rowDriverId]); ?>"<?php echo $current ? ' aria-current="true"' : ''; ?>><?php echo $h((string)$driver['driver_name']); ?></a>
                  </td>
                  <td class="is-num" data-label="<?php echo $t('col_days_out'); ?>"><?php echo $num($worked); ?></td>
                  <td class="is-num" data-label="<?php echo $t('col_stops'); ?>"><?php echo $num((int)$driver['due']); ?></td>
                  <td data-label="<?php echo $t('col_delivered'); ?>"><?php echo $rateCell((int)$driver['delivered'], (int)$driver['due'], 97, 90); ?></td>
                  <td data-label="<?php echo $t('col_after'); ?>"><?php echo $rateCell((int)$driver['delivered_with_after'], (int)$driver['delivered'], 95, 80); ?></td>
                  <td class="is-num" data-label="<?php echo $t('col_problem'); ?>"><?php echo $num((int)$driver['open'] + (int)$driver['failed'], 'bad'); ?></td>
                  <td data-label="<?php echo $t('col_hours'); ?>">
                    <?php if ($start !== '' && $finish !== ''): ?>
                      <?php echo $t('hours_range', ['start' => $start, 'end' => $finish]); ?>
                    <?php else: ?>
                      <?php echo $blank; ?>
                    <?php endif; ?>
                  </td>
                  <?php if ($clockReady): ?>
                    <td data-label="<?php echo $t('col_clock'); ?>">
                      <?php if ($worked > 0): ?>
                        <?php $clocked = $worked - (int)$driver['unclocked_days']; ?>
                        <span class="<?php echo $clocked < $worked ? 'delivery-review__num is-warn' : ''; ?>"><?php echo $t('of', ['part' => $clocked, 'whole' => $worked]); ?></span>
                      <?php else: ?>
                        <?php echo $blank; ?>
                      <?php endif; ?>
                    </td>
                  <?php endif; ?>
                  <td data-label="<?php echo $t('col_strip'); ?>">
                    <span class="delivery-review__strip">
                      <?php foreach (bakery_delivery_manager_review_driver_strip($driver, $stripDates) as $stripCell): ?>
                        <?php
                          $state = (string)$stripCell['state'];
                          $what = bakery_t(bakery_delivery_manager_review_state_key($state));
                          $label = (int)$stripCell['stops'] > 0
                              ? bakery_t('delivery_review.cell_title_driver', [
                                  'date' => bakery_delivery_manager_review_display_date((string)$stripCell['date']),
                                  'what' => $what,
                                  'stops' => (int)$stripCell['stops'],
                                  'delivered' => (int)$stripCell['delivered'],
                              ])
                              : bakery_t('delivery_review.cell_title', [
                                  'date' => bakery_delivery_manager_review_display_date((string)$stripCell['date']),
                                  'what' => $what,
                              ]);
                          $cellHref = $state === 'idle'
                              ? ''
                              : bakery_delivery_manager_review_href([
                                  'view' => 'drivers',
                                  'from' => $from,
                                  'to' => $to,
                                  'driver' => $rowDriverId,
                                  'day' => (string)$stripCell['date'],
                              ]) . '#day-' . (string)$stripCell['date'];
                          echo $cell($state, (string)$stripCell['date'], (int)$stripCell['stops'] > 0 ? (string)(int)$stripCell['stops'] : '', $label, $cellHref);
                        ?>
                      <?php endforeach; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php echo $legend(['clean', 'photo_gap', 'problem', 'not_out', 'covered']); ?>
        <p class="delivery-review__note"><?php echo $t('strip_note'); ?><?php if ($stripCut): ?> <?php echo $t('strip_cut', ['days' => BAKERY_DELIVERY_MANAGER_REVIEW_STRIP_DAYS]); ?><?php endif; ?></p>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($selectedDriver === null): ?>
      <section class="delivery-review__section" aria-labelledby="delivery-review-every-day">
        <h2 id="delivery-review-every-day"><?php echo $t('every_day_title'); ?></h2>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm delivery-review__table">
            <thead>
              <tr>
                <th><?php echo $t('col_date'); ?></th>
                <th class="is-num"><?php echo $t('col_drivers_out'); ?></th>
                <th class="is-num"><?php echo $t('col_stops'); ?></th>
                <th><?php echo $t('col_delivered'); ?></th>
                <th><?php echo $t('col_after'); ?></th>
                <th class="is-num"><?php echo $t('col_problem'); ?></th>
                <th class="is-num"><?php echo $t('col_unassigned'); ?></th>
                <th class="is-num"><?php echo $t('col_unrouted'); ?></th>
                <th><?php echo $t('col_links'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (array_reverse($report['days']) as $day): ?>
                <?php
                  $dayDate = (string)$day['date'];
                  $dayDue = max(0, (int)$day['stops'] - (int)$day['cancelled']);
                  $dayProblem = (int)$day['open'] + (int)$day['failed'];
                  $sheet = $base . 'driver_reports.php?' . http_build_query(['tab' => 'sheet', 'date' => $dayDate]);
                  $assign = $base . 'driver_assignment.php?' . http_build_query(['date' => $dayDate]);
                  $rowClass = ($dayProblem + (int)$day['unassigned']) > 0 ? 'is-problem' : ((int)$day['stops'] === 0 ? 'is-quiet' : '');
                ?>
                <tr class="<?php echo $rowClass; ?>">
                  <td data-label="<?php echo $t('col_date'); ?>"><strong><?php echo $dateLabel($dayDate); ?></strong><?php if ($dayDate === $today): ?> <?php echo $badge('info', bakery_t('delivery_review.today')); ?><?php endif; ?></td>
                  <td class="is-num" data-label="<?php echo $t('col_drivers_out'); ?>"><?php echo $num((int)$day['drivers_out']); ?></td>
                  <td class="is-num" data-label="<?php echo $t('col_stops'); ?>"><?php echo $num($dayDue); ?></td>
                  <td data-label="<?php echo $t('col_delivered'); ?>"><?php echo $rateCell((int)$day['delivered'], $dayDue, 97, 90); ?></td>
                  <td data-label="<?php echo $t('col_after'); ?>"><?php echo $rateCell((int)$day['with_after'], (int)$day['delivered'], 95, 80); ?></td>
                  <td class="is-num" data-label="<?php echo $t('col_problem'); ?>"><?php echo $num($dayProblem, 'bad'); ?></td>
                  <td class="is-num" data-label="<?php echo $t('col_unassigned'); ?>"><?php echo $num((int)$day['unassigned'], 'bad'); ?></td>
                  <td class="is-num" data-label="<?php echo $t('col_unrouted'); ?>"><?php echo $num((int)$day['unrouted']); ?></td>
                  <td class="delivery-review__links" data-label="<?php echo $t('col_links'); ?>">
                    <a href="<?php echo $h($sheet); ?>"><?php echo $t('open_sheet'); ?></a>
                    <a href="<?php echo $h($assign); ?>"><?php echo $t('open_assign'); ?></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php else: ?>
      <?php
        $panelDriverId = (int)$selectedDriver['driver_id'];
        $dayRows = array_reverse($selectedDriver['day_rows']);
        $stopsByDate = [];
        foreach ($selectedDriver['stop_rows'] as $stop) {
            $stopsByDate[(string)$stop['date']][] = $stop;
        }
        $openDay = '';
        foreach ($dayRows as $day) {
            if ((string)$day['date'] === $dayParam && (int)$day['stops'] > 0) {
                $openDay = $dayParam;
            }
        }
        if ($openDay === '') {
            foreach ($dayRows as $day) {
                if (in_array((string)$day['state'], ['problem', 'photo_gap'], true)) {
                    $openDay = (string)$day['date'];
                    break;
                }
            }
        }
        if ($openDay === '') {
            foreach ($dayRows as $day) {
                if ((int)$day['stops'] > 0) {
                    $openDay = (string)$day['date'];
                    break;
                }
            }
        }
        $ownStanding = max(0, (int)$selectedDriver['standing_stops'] - (int)$selectedDriver['paused_stops']);
        $facts = [
            [bakery_t('delivery_review.col_days_out'), (string)(int)$selectedDriver['days_worked'], ''],
            [bakery_t('delivery_review.col_stops'), (string)(int)$selectedDriver['due'], ''],
            [bakery_t('delivery_review.col_delivered'), $percentText($selectedDriver['delivered_rate']), bakery_t('delivery_review.of', ['part' => (int)$selectedDriver['delivered'], 'whole' => (int)$selectedDriver['due']])],
            [bakery_t('delivery_review.col_after'), $percentText($selectedDriver['after_rate']), bakery_t('delivery_review.of', ['part' => (int)$selectedDriver['delivered_with_after'], 'whole' => (int)$selectedDriver['delivered']])],
            [bakery_t('delivery_review.col_problem'), (string)((int)$selectedDriver['open'] + (int)$selectedDriver['failed']), ''],
            [bakery_t('delivery_review.fact_own_standing'), (string)(int)$selectedDriver['kept_stops'], bakery_t('delivery_review.of', ['part' => (int)$selectedDriver['kept_stops'], 'whole' => $ownStanding])],
        ];
      ?>
      <section class="delivery-review__section delivery-review__panel" aria-labelledby="delivery-review-driver">
        <div class="delivery-review__panel-head">
          <h2 id="delivery-review-driver"><?php echo $h((string)$selectedDriver['driver_name']); ?></h2>
          <a href="<?php echo $link(['view' => 'drivers']); ?>"><?php echo $t('all_drivers'); ?></a>
        </div>
        <ul class="delivery-review__facts">
          <?php foreach ($facts as $fact): ?>
            <li><span><?php echo $h($fact[0]); ?></span><strong><?php echo $h($fact[1]); ?></strong><?php if ($fact[2] !== ''): ?><em><?php echo $h($fact[2]); ?></em><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
        <ul class="delivery-review__lines">
          <?php foreach (bakery_delivery_manager_review_driver_attention($selectedDriver) as $line): ?>
            <li><?php echo $h(bakery_t((string)$line['key'], $line['params'])); ?></li>
          <?php endforeach; ?>
        </ul>

        <h3><?php echo $t('day_title'); ?></h3>
        <?php foreach ($dayRows as $day): ?>
          <?php
            $dayDate = (string)$day['date'];
            $state = (string)$day['state'];
            $dayStops = $stopsByDate[$dayDate] ?? [];
            $dayProblem = (int)$day['open'] + (int)$day['failed'];
            $sheet = $base . 'driver_reports.php?' . http_build_query(['tab' => 'sheet', 'date' => $dayDate]);
            $photos = $base . 'route_photo_journey.php?' . http_build_query(['date' => $dayDate, 'driver_id' => $panelDriverId]);
            $assign = $base . 'driver_assignment.php?' . http_build_query(['date' => $dayDate]);
            $standingFacts = [];
            if ((int)$day['standing_stops'] > 0) {
                $standingFacts[] = bakery_t('delivery_review.day_standing', ['count' => (int)$day['standing_stops']]);
                foreach ([
                    'kept_stops' => 'delivery_review.day_kept',
                    'covered_stops' => 'delivery_review.day_covered',
                    'paused_stops' => 'delivery_review.day_paused',
                    'unrouted_stops' => 'delivery_review.day_unrouted',
                ] as $field => $factKey) {
                    if ((int)$day[$field] > 0) {
                        $standingFacts[] = bakery_t($factKey, ['count' => (int)$day[$field]]);
                    }
                }
            }
            if ((int)$day['covering_stops'] > 0) {
                $standingFacts[] = bakery_t('delivery_review.day_covering', ['count' => (int)$day['covering_stops']]);
            }
          ?>
          <?php if ((int)$day['stops'] === 0): ?>
            <div class="delivery-review__day is-flat is-<?php echo $h($state); ?>" id="day-<?php echo $h($dayDate); ?>">
              <span class="delivery-review__day-date"><?php echo $dateLabel($dayDate); ?></span>
              <span class="delivery-review__day-facts">
                <span><?php echo $h(bakery_t(bakery_delivery_manager_review_state_key($state))); ?></span>
                <?php foreach ($standingFacts as $factText): ?><span><?php echo $h($factText); ?></span><?php endforeach; ?>
              </span>
              <a href="<?php echo $h($assign); ?>"><?php echo $t('open_assign'); ?></a>
            </div>
          <?php else: ?>
            <details class="delivery-review__day is-<?php echo $h($state); ?>" id="day-<?php echo $h($dayDate); ?>"<?php echo $dayDate === $openDay ? ' open' : ''; ?>>
              <summary>
                <span class="delivery-review__day-date"><?php echo $dateLabel($dayDate); ?></span>
                <span class="delivery-review__day-facts">
                  <span><?php echo $t('day_stops', ['count' => (int)$day['stops']]); ?></span>
                  <span><?php echo $t('day_delivered', ['count' => (int)$day['delivered']]); ?></span>
                  <span<?php echo (int)$day['delivered_with_after'] < (int)$day['delivered'] ? ' class="delivery-review__num is-warn"' : ''; ?>><?php echo $t('day_after', ['count' => (int)$day['delivered_with_after']]); ?></span>
                  <?php if ($dayProblem > 0): ?><span class="delivery-review__num is-bad"><?php echo $t('day_problem', ['count' => $dayProblem]); ?></span><?php endif; ?>
                  <?php if ((int)$day['cancelled'] > 0): ?><span><?php echo $t('day_cancelled', ['count' => (int)$day['cancelled']]); ?></span><?php endif; ?>
                  <?php if ($clock($day['first_arrival']) !== '' && $clock($day['last_close']) !== ''): ?>
                    <span><?php echo $t('hours_range', ['start' => $clock($day['first_arrival']), 'end' => $clock($day['last_close'])]); ?></span>
                  <?php endif; ?>
                </span>
                <span class="delivery-review__day-badges">
                  <?php if ($day['clocked_in'] === false): ?><?php echo $badge('warning', bakery_t('delivery_review.badge_no_clock')); ?><?php endif; ?>
                  <?php if ($day['route_closed'] === true): ?><?php echo $badge('success', bakery_t('delivery_review.badge_closed')); ?><?php endif; ?>
                  <?php if (($day['bulk_stores'] ?? []) !== []): ?><?php echo $badge('warning', bakery_t('delivery_review.badge_bulk')); ?><?php endif; ?>
                </span>
              </summary>
              <div class="delivery-review__day-body">
                <p class="delivery-review__links">
                  <a href="<?php echo $h($sheet); ?>"><?php echo $t('open_sheet'); ?></a>
                  <a href="<?php echo $h($photos); ?>"><?php echo $t('open_photos'); ?></a>
                  <a href="<?php echo $h($assign); ?>"><?php echo $t('open_assign'); ?></a>
                </p>
                <?php if ($standingFacts !== []): ?>
                  <p class="delivery-review__day-facts"><?php foreach ($standingFacts as $factText): ?><span><?php echo $h($factText); ?></span><?php endforeach; ?></p>
                <?php endif; ?>
                <?php if (($day['bulk_stores'] ?? []) !== []): ?>
                  <p class="delivery-review__note"><?php echo $t('bulk_line', ['stores' => implode(', ', $day['bulk_stores'])]); ?></p>
                <?php endif; ?>
                <div class="sf-table-wrap">
                  <table class="sf-table sf-table--stack-sm delivery-review__table">
                    <thead>
                      <tr>
                        <th class="is-num"><?php echo $t('col_order'); ?></th>
                        <th><?php echo $t('col_store'); ?></th>
                        <th><?php echo $t('col_status'); ?></th>
                        <th><?php echo $t('col_arrival'); ?></th>
                        <th><?php echo $t('col_close'); ?></th>
                        <th><?php echo $t('col_photo'); ?></th>
                        <th><?php echo $t('col_note'); ?></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($dayStops as $index => $stop): ?>
                        <?php
                          $status = (string)$stop['status'];
                          $hasAfter = !empty($stop['has_after']);
                          $stopRowClass = '';
                          if ($status === 'failed' || $status === 'open') {
                              $stopRowClass = 'is-problem';
                          } elseif ($status === 'delivered' && !$hasAfter) {
                              $stopRowClass = 'is-gap';
                          }
                        ?>
                        <tr class="<?php echo $stopRowClass; ?>">
                          <td class="is-num" data-label="<?php echo $t('col_order'); ?>"><?php echo (int)$index + 1; ?></td>
                          <td data-label="<?php echo $t('col_store'); ?>"><a href="<?php echo $link(['view' => 'customers', 'customer' => (int)$stop['customer_id']]); ?>"><?php echo $h((string)$stop['customer_name']); ?></a></td>
                          <td data-label="<?php echo $t('col_status'); ?>">
                            <?php echo $badge(bakery_delivery_manager_review_status_tone($status, $hasAfter), bakery_t(bakery_delivery_manager_review_status_key($status))); ?>
                            <?php if (!empty($stop['off_driver'])): ?><?php echo $badge('info', bakery_t('delivery_review.off_driver_mark')); ?><?php endif; ?>
                            <?php if (!empty($stop['bulk'])): ?><?php echo $badge('warning', bakery_t('delivery_review.badge_bulk')); ?><?php endif; ?>
                          </td>
                          <td data-label="<?php echo $t('col_arrival'); ?>"><?php echo $timeCell($stop['arrival_at']); ?></td>
                          <td data-label="<?php echo $t('col_close'); ?>"><?php echo $timeCell($stop['closed_at']); ?></td>
                          <td data-label="<?php echo $t('col_photo'); ?>"><?php echo $photoCell((string)$stop['photo_url'], $hasAfter, $status === 'delivered'); ?></td>
                          <td data-label="<?php echo $t('col_note'); ?>"><?php echo (string)$stop['note'] !== '' ? $h((string)$stop['note']) : $blank; ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </details>
          <?php endif; ?>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

    <?php if (!$hasSelection): ?>
    <section class="delivery-review__section" id="delivery-review-needs" aria-labelledby="delivery-review-needs-title">
      <h2 id="delivery-review-needs-title"><?php echo $t('needs_title'); ?><?php if ($selectedDriver !== null): ?> <span class="delivery-review__muted"><?php echo $h((string)$selectedDriver['driver_name']); ?></span><?php endif; ?></h2>
      <h3><?php echo $t('needs_open_title'); ?> <span class="delivery-review__count"><?php echo count($needsOpen); ?></span></h3>
      <?php if ($needsOpen === []): ?>
        <p class="delivery-review__empty"><?php echo $t('needs_open_empty'); ?></p>
      <?php else: ?>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm delivery-review__table">
            <thead>
              <tr>
                <th><?php echo $t('col_date'); ?></th>
                <th><?php echo $t('col_driver'); ?></th>
                <th><?php echo $t('col_store'); ?></th>
                <th><?php echo $t('col_what'); ?></th>
                <th><?php echo $t('col_note'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($needsOpen as $need): ?>
                <?php $needDate = (string)$need['date']; ?>
                <tr>
                  <td data-label="<?php echo $t('col_date'); ?>"><?php echo $dateLabel($needDate); ?></td>
                  <td data-label="<?php echo $t('col_driver'); ?>"><a href="<?php echo $link(['view' => 'drivers', 'driver' => (int)$need['driver_id'], 'day' => $needDate], '#day-' . $needDate); ?>"><?php echo $h((string)$need['driver_name']); ?></a></td>
                  <td data-label="<?php echo $t('col_store'); ?>"><a href="<?php echo $link(['view' => 'customers', 'customer' => (int)$need['customer_id']]); ?>"><?php echo $h((string)$need['customer_name']); ?></a></td>
                  <td data-label="<?php echo $t('col_what'); ?>">
                    <?php echo $badge('danger', bakery_t(bakery_delivery_manager_review_why_key((string)$need['reason']))); ?>
                    <?php if ((string)$need['reason'] === 'open'): ?>
                      <?php echo $needDate < $today ? $badge('warning', bakery_t('delivery_review.earlier_day')) : $badge('info', bakery_t('delivery_review.today')); ?>
                    <?php endif; ?>
                  </td>
                  <td data-label="<?php echo $t('col_note'); ?>"><?php echo (string)$need['note'] !== '' ? $h((string)$need['note']) : $blank; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <h3 id="delivery-review-photos"><?php echo $t('needs_photo_title'); ?> <span class="delivery-review__count"><?php echo count($needsPhoto); ?></span></h3>
      <?php if ($needsPhoto === []): ?>
        <p class="delivery-review__empty"><?php echo $t('needs_photo_empty'); ?></p>
      <?php else: ?>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm delivery-review__table">
            <thead>
              <tr>
                <th><?php echo $t('col_date'); ?></th>
                <th><?php echo $t('col_driver'); ?></th>
                <th><?php echo $t('col_store'); ?></th>
                <th><?php echo $t('col_close'); ?></th>
                <th><?php echo $t('col_other_photo'); ?></th>
                <th><?php echo $t('col_note'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($needsPhoto as $need): ?>
                <?php $needDate = (string)$need['date']; ?>
                <tr>
                  <td data-label="<?php echo $t('col_date'); ?>"><?php echo $dateLabel($needDate); ?></td>
                  <td data-label="<?php echo $t('col_driver'); ?>"><a href="<?php echo $link(['view' => 'drivers', 'driver' => (int)$need['driver_id'], 'day' => $needDate], '#day-' . $needDate); ?>"><?php echo $h((string)$need['driver_name']); ?></a></td>
                  <td data-label="<?php echo $t('col_store'); ?>"><a href="<?php echo $link(['view' => 'customers', 'customer' => (int)$need['customer_id']]); ?>"><?php echo $h((string)$need['customer_name']); ?></a></td>
                  <td data-label="<?php echo $t('col_close'); ?>"><?php echo $timeCell($need['closed_at']); ?></td>
                  <td data-label="<?php echo $t('col_other_photo'); ?>">
                    <?php if ($safePhoto((string)$need['photo_url'])): ?>
                      <a href="<?php echo $h((string)$need['photo_url']); ?>"><?php echo $t('photo_link'); ?></a>
                    <?php else: ?>
                      <?php echo $blank; ?>
                    <?php endif; ?>
                  </td>
                  <td data-label="<?php echo $t('col_note'); ?>"><?php echo (string)$need['note'] !== '' ? $h((string)$need['note']) : $blank; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
    <?php endif; ?>
  <?php else: ?>
    <section class="delivery-review__section" aria-labelledby="delivery-review-stores">
      <?php if ($selectedCustomer === null): ?>
      <h2 id="delivery-review-stores"><?php echo $t('tab_customers'); ?></h2>
      <form class="delivery-review__range delivery-review__toolbar" method="get" action="delivery_manager_review.php">
        <input type="hidden" name="view" value="customers">
        <input type="hidden" name="from" value="<?php echo $h($from); ?>">
        <input type="hidden" name="to" value="<?php echo $h($to); ?>">
        <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?php echo $h($filter); ?>"><?php endif; ?>
        <label>
          <span><?php echo $t('search'); ?></span>
          <input type="search" name="q" value="<?php echo $h($query); ?>" placeholder="<?php echo $t('search_placeholder'); ?>">
        </label>
        <label>
          <span><?php echo $t('sort'); ?></span>
          <select name="sort">
            <?php foreach (['attention' => 'sort_attention', 'name' => 'sort_name', 'gap' => 'sort_gap', 'last' => 'sort_last'] as $sortValue => $sortKey): ?>
              <option value="<?php echo $h($sortValue); ?>"<?php echo $sort === $sortValue ? ' selected' : ''; ?>><?php echo $t($sortKey); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="sf-btn sf-btn--primary sf-btn--sm" type="submit"><?php echo $t('show'); ?></button>
      </form>
      <nav class="delivery-review__chips" aria-label="<?php echo $t('filters_label'); ?>">
        <?php foreach (bakery_delivery_manager_review_filters() as $chip): ?>
          <?php
            $chipOn = $filter === $chip;
            if (!$chipOn && $chip !== 'all' && (int)$filterCounts[$chip] === 0) {
                continue;
            }
          ?>
          <a class="delivery-review__chip<?php echo $chipOn ? ' is-active' : ''; ?>" href="<?php echo $link(['filter' => $chip] + $storeParams); ?>"<?php echo $chipOn ? ' aria-current="true"' : ''; ?>><?php echo $t('filter_' . $chip); ?> <span class="delivery-review__count"><?php echo (int)$filterCounts[$chip]; ?></span></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($customerId > 0 && $selectedCustomer === null): ?>
        <p class="delivery-review__note"><?php echo $t('not_in_window'); ?></p>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($selectedCustomer !== null): ?>
        <?php
          $reading = $selectedCustomer['reading'];
          $storeStatus = bakery_delivery_manager_review_customer_status($selectedCustomer);
          $record = $base . 'customer_record.php?' . http_build_query(['customer_id' => (int)$selectedCustomer['customer_id']]);
          $actualLabel = bakery_delivery_manager_review_actual_days_label($selectedCustomer['actual_days']);
          $mixLabel = bakery_delivery_manager_review_driver_mix_label($selectedCustomer['driver_mix']);
          $usualClose = $usualClock($selectedCustomer['usual_close']);
          $storeFacts = [
              [bakery_t('delivery_review.col_expected'), (int)$selectedCustomer['expected'], ''],
              [bakery_t('delivery_review.col_routed'), (int)$selectedCustomer['routed'], ''],
              [bakery_t('delivery_review.col_delivered'), (int)$selectedCustomer['delivered'], ''],
              [bakery_t('delivery_review.col_after'), (int)$selectedCustomer['with_after'], (int)$selectedCustomer['missing_after'] > 0 ? 'warn' : ''],
              [bakery_t('delivery_review.col_problem'), (int)$selectedCustomer['open'] + (int)$selectedCustomer['failed'], 'bad'],
              [bakery_t('delivery_review.col_unassigned'), (int)$selectedCustomer['unassigned'], 'bad'],
              [bakery_t('delivery_review.col_unrouted'), (int)$selectedCustomer['not_routed'], ''],
              [bakery_t('delivery_review.col_paused'), (int)$selectedCustomer['paused'], ''],
              [bakery_t('delivery_review.col_cancelled'), (int)$selectedCustomer['cancelled'], ''],
              [bakery_t('delivery_review.off_driver_mark'), (int)$selectedCustomer['off_count'], ''],
          ];
        ?>
        <article class="delivery-review__panel delivery-review__store" id="store-<?php echo (int)$selectedCustomer['customer_id']; ?>">
          <div class="delivery-review__panel-head">
            <h3>
              <?php echo $h((string)$selectedCustomer['name']); ?>
              <?php echo $badge((string)$storeStatus['tone'], bakery_t((string)$storeStatus['key'])); ?>
              <?php if (empty($selectedCustomer['active'])): ?><?php echo $badge('neutral', bakery_t('delivery_review.inactive')); ?><?php endif; ?>
            </h3>
            <a href="<?php echo $link($storeParams); ?>"><?php echo $t('all_customers'); ?></a>
          </div>
          <p class="delivery-review__reading"><?php echo $h(bakery_t((string)$reading['key'], $reading['params'])); ?></p>
          <ul class="delivery-review__facts">
            <?php foreach ($storeFacts as $index => $fact): ?>
              <?php if ($index > 2 && (int)$fact[1] === 0) { continue; } ?>
              <li<?php echo $fact[2] !== '' && (int)$fact[1] > 0 ? ' class="is-' . $h($fact[2]) . '"' : ''; ?>><span><?php echo $h($fact[0]); ?></span><strong><?php echo (int)$fact[1]; ?></strong></li>
            <?php endforeach; ?>
          </ul>
          <dl class="delivery-review__pairs">
            <div><dt><?php echo $t('col_zone'); ?></dt><dd><?php echo (string)$selectedCustomer['zone'] !== '' ? $h((string)$selectedCustomer['zone']) : $t('no_zone'); ?></dd></div>
            <div><dt><?php echo $t('col_pattern'); ?></dt><dd><?php echo $h(bakery_delivery_manager_review_pattern_label($selectedCustomer['pattern'])); ?></dd></div>
            <div><dt><?php echo $t('pair_actual_days'); ?></dt><dd><?php echo $actualLabel !== '' ? $h($actualLabel) : $t('no_last'); ?></dd></div>
            <div><dt><?php echo $t('col_actual_driver'); ?></dt><dd><?php echo $mixLabel !== '' ? $h($mixLabel) : $t('nobody'); ?></dd></div>
            <div><dt><?php echo $t('pair_usual_close'); ?></dt><dd><?php echo $usualClose !== '' ? $h($usualClose) : $t('not_recorded'); ?></dd></div>
            <div><dt><?php echo $t('col_last'); ?></dt><dd><?php echo (string)($selectedCustomer['last_delivered'] ?? '') !== '' ? $dateLabel((string)$selectedCustomer['last_delivered']) : $t('no_last'); ?></dd></div>
          </dl>
          <p class="delivery-review__links">
            <a href="<?php echo $h($record); ?>"><?php echo $t('open_record'); ?></a>
            <a href="<?php echo $h($base . 'standing_routes.php'); ?>"><?php echo $t('open_standing'); ?></a>
          </p>
          <h3><?php echo $t('visit_title'); ?></h3>
          <div class="sf-table-wrap">
            <table class="sf-table sf-table--stack-sm delivery-review__table">
              <thead>
                <tr>
                  <th><?php echo $t('col_date'); ?></th>
                  <th><?php echo $t('col_standing_driver'); ?></th>
                  <th><?php echo $t('col_actual_driver'); ?></th>
                  <th><?php echo $t('col_status'); ?></th>
                  <th><?php echo $t('col_arrival'); ?></th>
                  <th><?php echo $t('col_close'); ?></th>
                  <th><?php echo $t('col_photo'); ?></th>
                  <th><?php echo $t('col_note'); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_reverse($selectedCustomer['visits']) as $visit): ?>
                  <?php
                    $visitDate = (string)$visit['date'];
                    $status = (string)$visit['status'];
                    $hasAfter = !empty($visit['has_after']);
                    $actualId = (int)$visit['actual_driver_id'];
                    $visitState = bakery_delivery_manager_review_visit_state($visit);
                    $rowClass = '';
                    if ($visitState === 'problem') {
                        $rowClass = 'is-problem';
                    } elseif ($visitState === 'photo_gap') {
                        $rowClass = 'is-gap';
                    } elseif (in_array($visitState, ['not_out', 'paused'], true)) {
                        $rowClass = 'is-quiet';
                    }
                  ?>
                  <tr class="<?php echo $rowClass; ?>">
                    <td data-label="<?php echo $t('col_date'); ?>"><?php echo $dateLabel($visitDate); ?></td>
                    <td data-label="<?php echo $t('col_standing_driver'); ?>">
                      <?php if ($visit['standing_drivers'] === []): ?>
                        <span class="delivery-review__muted"><?php echo $t('extra_mark'); ?></span>
                      <?php else: ?>
                        <?php foreach ($visit['standing_drivers'] as $standingDriver): ?>
                          <a href="<?php echo $link(['view' => 'drivers', 'driver' => (int)$standingDriver['driver_id'], 'day' => $visitDate], '#day-' . $visitDate); ?>"><?php echo $h((string)$standingDriver['driver_name']); ?></a>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </td>
                    <td data-label="<?php echo $t('col_actual_driver'); ?>">
                      <?php if ($actualId > 0): ?>
                        <a href="<?php echo $link(['view' => 'drivers', 'driver' => $actualId, 'day' => $visitDate], '#day-' . $visitDate); ?>"><?php echo $h((string)$visit['actual_driver_name']); ?></a>
                      <?php else: ?>
                        <span class="delivery-review__muted"><?php echo $t('nobody'); ?></span>
                      <?php endif; ?>
                    </td>
                    <td data-label="<?php echo $t('col_status'); ?>">
                      <?php echo $badge(bakery_delivery_manager_review_status_tone($status, $hasAfter), bakery_t(bakery_delivery_manager_review_status_key($status))); ?>
                      <?php if (!empty($visit['off_driver'])): ?><?php echo $badge('info', bakery_t('delivery_review.off_driver_mark')); ?><?php endif; ?>
                    </td>
                    <td data-label="<?php echo $t('col_arrival'); ?>"><?php echo $timeCell($visit['arrival_at']); ?></td>
                    <td data-label="<?php echo $t('col_close'); ?>"><?php echo $timeCell($visit['closed_at']); ?></td>
                    <td data-label="<?php echo $t('col_photo'); ?>"><?php echo $photoCell((string)$visit['photo_url'], $hasAfter, $status === 'delivered'); ?></td>
                    <td data-label="<?php echo $t('col_note'); ?>"><?php echo (string)$visit['note'] !== '' ? $h((string)$visit['note']) : $blank; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </article>
      <?php endif; ?>

      <?php if ($selectedCustomer === null): ?>
      <?php if ($report['customers'] === []): ?>
        <p class="delivery-review__empty"><?php echo $t('customers_empty'); ?></p>
      <?php elseif ($visibleCustomers === []): ?>
        <p class="delivery-review__empty"><?php echo $t('filter_empty'); ?></p>
      <?php else: ?>
        <div class="sf-table-wrap">
          <table class="sf-table sf-table--stack-sm delivery-review__table">
            <thead>
              <tr>
                <th><?php echo $t('col_customer'); ?></th>
                <th><?php echo $t('col_pattern'); ?></th>
                <th class="is-wide"><?php echo $t('col_strip'); ?><span class="delivery-review__th-sub"><?php echo $h($stripRange); ?></span></th>
                <th><?php echo $t('col_delivered_of'); ?></th>
                <th><?php echo $t('col_last'); ?></th>
                <th><?php echo $t('col_where'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($visibleCustomers as $customer): ?>
                <?php
                  $rowCustomerId = (int)$customer['customer_id'];
                  $current = $selectedCustomer !== null && $rowCustomerId === (int)$selectedCustomer['customer_id'];
                  $storeStatus = bakery_delivery_manager_review_customer_status($customer);
                  $last = (string)($customer['last_delivered'] ?? '');
                  $expected = (int)$customer['expected'];
                  $delivered = (int)$customer['delivered'];
                ?>
                <tr class="<?php echo $current ? 'is-current' : ''; ?>">
                  <td data-label="<?php echo $t('col_customer'); ?>">
                    <a class="delivery-review__name" href="<?php echo $link(['customer' => $rowCustomerId] + $storeParams, '#store-' . $rowCustomerId); ?>"<?php echo $current ? ' aria-current="true"' : ''; ?>><?php echo $h((string)$customer['name']); ?></a>
                    <?php if ((string)$customer['zone'] !== '' || empty($customer['active'])): ?>
                      <span class="delivery-review__under"><?php echo $h((string)$customer['zone']); ?><?php if (empty($customer['active'])): ?> <?php echo $t('inactive'); ?><?php endif; ?></span>
                    <?php endif; ?>
                  </td>
                  <td data-label="<?php echo $t('col_pattern'); ?>"><?php echo $h(bakery_delivery_manager_review_pattern_label($customer['pattern'])); ?></td>
                  <td data-label="<?php echo $t('col_strip'); ?>">
                    <span class="delivery-review__strip">
                      <?php foreach (bakery_delivery_manager_review_customer_strip($customer, $stripDates) as $stripCell): ?>
                        <?php
                          echo $cell(
                              (string)$stripCell['state'],
                              (string)$stripCell['date'],
                              '',
                              bakery_t('delivery_review.cell_title', [
                                  'date' => bakery_delivery_manager_review_display_date((string)$stripCell['date']),
                                  'what' => bakery_t(bakery_delivery_manager_review_state_key((string)$stripCell['state'])),
                              ])
                          );
                        ?>
                      <?php endforeach; ?>
                    </span>
                  </td>
                  <td data-label="<?php echo $t('col_delivered_of'); ?>">
                    <?php if ($expected > 0): ?>
                      <?php
                        $share = bakery_delivery_manager_review_percent($delivered, $expected);
                        $shareTone = $delivered >= $expected ? 'good' : ($delivered > 0 ? 'warn' : 'bad');
                      ?>
                      <span class="delivery-review__rate"><strong><?php echo $t('of', ['part' => $delivered, 'whole' => $expected]); ?></strong><?php echo $bar($share, $shareTone); ?></span>
                    <?php else: ?>
                      <span class="delivery-review__rate"><strong><?php echo $delivered; ?></strong><span class="delivery-review__muted"><?php echo $t('no_standing_pattern'); ?></span></span>
                    <?php endif; ?>
                  </td>
                  <td data-label="<?php echo $t('col_last'); ?>"><?php echo $last !== '' ? $dateLabel($last) : $blank; ?></td>
                  <td data-label="<?php echo $t('col_where'); ?>"><?php echo $badge((string)$storeStatus['tone'], bakery_t((string)$storeStatus['key'])); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php echo $legend(['clean', 'photo_gap', 'problem', 'cancelled', 'not_out', 'paused']); ?>
        <?php if ($stripCut): ?>
          <p class="delivery-review__note"><?php echo $t('strip_cut', ['days' => BAKERY_DELIVERY_MANAGER_REVIEW_STRIP_DAYS]); ?></p>
        <?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endif; ?>
  <?php if (!$hasSelection): ?>
  </details>
  <?php endif; ?>

  <details class="delivery-review__help">
    <summary><?php echo $t('help_title'); ?></summary>
    <ul>
      <li><?php echo $t('help_proof'); ?></li>
      <li><?php echo $t('help_cancelled'); ?></li>
      <li><?php echo $t('help_standing'); ?></li>
      <li><?php echo $t('help_paused'); ?></li>
      <li><?php echo $t('help_unassigned'); ?></li>
      <li><?php echo $t('help_prior', ['from' => bakery_delivery_manager_review_display_date((string)$prior['from']), 'to' => bakery_delivery_manager_review_display_date((string)$prior['to'])]); ?></li>
      <?php if (!$clockReady): ?><li><?php echo $t('clock_missing'); ?></li><?php endif; ?>
      <?php if (!$closeReady): ?><li><?php echo $t('close_missing'); ?></li><?php endif; ?>
    </ul>
  </details>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
