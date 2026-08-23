<?php
/**
 * Phone-first Manager workspace — dated boards on manager.php for the manager role.
 * Mutations reuse Driver Assignment, dated order helpers, skip, and delivery recovery.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/driver_assignments.php';
require_once __DIR__ . '/customer_order_mutations.php';
require_once __DIR__ . '/delivery_skip.php';
require_once __DIR__ . '/demand_confirmation.php';
require_once __DIR__ . '/sfb_origin.php';

function bakery_manager_is_phone_workspace(): bool
{
    return function_exists('bakery_user_has_role') && bakery_user_has_role(['manager']);
}

/** @return list<string> */
function bakery_manager_phone_views(): array
{
    return ['today', 'routes', 'kitchen', 'missed'];
}

function bakery_manager_phone_view(?string $raw): string
{
    $view = strtolower(trim((string)$raw));
    return in_array($view, bakery_manager_phone_views(), true) ? $view : 'today';
}

function bakery_manager_phone_sheet(?string $raw): string
{
    $sheet = strtolower(trim((string)$raw));
    return in_array($sheet, ['move', 'qty', 'skip'], true) ? $sheet : '';
}

function bakery_manager_phone_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function bakery_manager_phone_href(string $date, string $view = 'today', array $extra = []): string
{
    $query = array_merge(['date' => $date, 'view' => $view], $extra);
    return (defined('BASE_URL') ? BASE_URL : '') . 'manager.php?' . http_build_query($query);
}

function bakery_manager_phone_date_label(string $date): string
{
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$dt) {
        return $date;
    }
    $locale = (function_exists('bakery_locale') && bakery_locale() === 'es') ? 'es_MX' : 'en_US';
    if (class_exists('IntlDateFormatter')) {
        $fmt = new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE);
        $label = $fmt ? $fmt->format($dt) : false;
        if (is_string($label) && $label !== '') {
            return $label;
        }
    }
    return $dt->format('Y-m-d');
}

function bakery_manager_phone_tomorrow_needs_work(?array $tomorrow): bool
{
    $state = (string)($tomorrow['state'] ?? 'unavailable');
    return in_array($state, ['not_generated', 'incomplete', 'ready_unconfirmed', 'confirmed_with_changes'], true);
}

function bakery_manager_phone_run_finished(array $ctx): bool
{
    $date = (string)($ctx['date'] ?? '');
    $today = (string)($ctx['today'] ?? date('Y-m-d'));
    if ($date !== '' && $date < $today) {
        return true;
    }
    $inTransit = (int)($ctx['inTransit'] ?? 0);
    $delivered = (int)($ctx['delivered'] ?? 0);
    $failed = (int)($ctx['failed'] ?? 0);
    return $inTransit === 0 && ($delivered + $failed) > 0;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function bakery_manager_phone_short_products(array $rows, int $limit = 8): array
{
    $short = [];
    foreach ($rows as $row) {
        if ((int)($row['remaining_quantity'] ?? 0) > 0) {
            $short[] = $row;
        }
    }
    return array_slice($short, 0, $limit);
}

/**
 * @param array<string,mixed> $base
 * @return array<string,mixed>
 */
function bakery_manager_phone_build(PDO $db, array $base): array
{
    $date = (string)($base['date'] ?? date('Y-m-d'));
    $view = bakery_manager_phone_view((string)($base['view'] ?? ($_GET['view'] ?? 'today')));
    $sheet = bakery_manager_phone_sheet((string)($base['sheet'] ?? ($_GET['sheet'] ?? '')));
    $ctx = $base;
    $ctx['view'] = $view;
    $ctx['sheet'] = $sheet;
    $ctx['driver_stops'] = bakery_manager_phone_driver_stops($db, $date);
    $ctx['missed'] = bakery_manager_phone_missed($db, $date, $ctx['routePlan'] ?? []);
    $ctx['movable_stops'] = bakery_manager_phone_movable_stops($db, $date);
    $ctx['skip_stops'] = bakery_manager_phone_skip_stops($db, $date);
    $ctx['qty_matches'] = [];
    $ctx['qty_customer'] = null;
    $ctx['qty_items'] = [];
    $ctx['qty_query'] = trim((string)($_GET['q'] ?? ''));
    $qtyCustomerId = (int)($_GET['customer_id'] ?? 0);
    if ($sheet === 'qty') {
        if ($ctx['qty_query'] !== '') {
            $ctx['qty_matches'] = bakery_manager_phone_search_customers($db, $ctx['qty_query']);
        }
        if ($qtyCustomerId > 0) {
            $ctx['qty_customer'] = bakery_manager_phone_customer($db, $qtyCustomerId);
            if ($ctx['qty_customer']) {
                $ctx['qty_items'] = bakery_manager_phone_customer_items($db, $ctx['qty_customer'], $date);
            }
        }
    }
    $tomorrowDate = (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    $ctx['tomorrow_date'] = $tomorrowDate;
    $ctx['tomorrow'] = null;
    try {
        $ctx['tomorrow'] = bakery_demand_readiness($db, $tomorrowDate);
    } catch (Throwable $e) {
        error_log('manager phone tomorrow: ' . $e->getMessage());
    }
    $ctx['dateDisplay'] = bakery_manager_phone_date_label($date);
    $ctx['run_finished'] = bakery_manager_phone_run_finished($ctx);
    $ctx['next_action'] = bakery_manager_phone_next_action($ctx);
    return $ctx;
}

/** @return array{key:string,href:string,tone:string} */
function bakery_manager_phone_next_action(array $ctx): array
{
    $date = (string)($ctx['date'] ?? date('Y-m-d'));
    $failed = (int)($ctx['failed'] ?? 0);
    $unassigned = (int)($ctx['unassigned'] ?? 0);
    $remaining = (int)($ctx['productionSummary']['remaining'] ?? 0);
    $packRequired = (int)($ctx['packingSummary']['required_lines'] ?? 0);
    $packChecked = (int)($ctx['packingSummary']['checked_lines'] ?? 0);
    $open = (int)($ctx['pending'] ?? 0) + (int)($ctx['inTransit'] ?? 0);
    if ($failed > 0) {
        return ['key' => 'missed', 'href' => bakery_manager_phone_href($date, 'missed'), 'tone' => 'attention'];
    }
    if ($unassigned > 0) {
        return ['key' => 'unassigned', 'href' => bakery_manager_phone_href($date, 'routes'), 'tone' => 'attention'];
    }
    if ($remaining > 0) {
        return ['key' => 'bake', 'href' => bakery_manager_phone_href($date, 'kitchen'), 'tone' => 'attention'];
    }
    if ($packRequired > 0 && $packChecked < $packRequired) {
        return ['key' => 'pack', 'href' => bakery_manager_phone_href($date, 'kitchen'), 'tone' => 'attention'];
    }
    if ($open > 0) {
        return ['key' => 'in_progress', 'href' => bakery_manager_phone_href($date, 'routes'), 'tone' => 'ready'];
    }
    return ['key' => 'clear', 'href' => bakery_manager_phone_href($date, 'missed'), 'tone' => 'ready'];
}

/** @return array<int,list<array<string,mixed>>> */
function bakery_manager_phone_driver_stops(PDO $db, string $date): array
{
    if (!table_exists($db, 'daily_order_assignments') || !table_exists($db, 'daily_orders')) {
        return [];
    }
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $stmt = $db->prepare(
        "SELECT doa.driver_id, do.id AS daily_order_id, c.name AS customer_name,
                COALESCE(doa.delivery_status, 'pending') AS delivery_status, doa.route_order
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         {$origin}
         WHERE doa.delivery_date = ?
         ORDER BY doa.driver_id, doa.route_order, c.name"
    );
    $stmt->execute([$date]);
    $byDriver = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byDriver[(int)$row['driver_id']][] = $row;
    }
    return $byDriver;
}

/**
 * @param array<string,mixed> $routePlan
 * @return array{failed:list<array<string,mixed>>,pending:list<array<string,mixed>>,unassigned:list<array<string,mixed>>}
 */
function bakery_manager_phone_missed(PDO $db, string $date, array $routePlan): array
{
    $out = ['failed' => [], 'pending' => [], 'unassigned' => []];
    foreach ($routePlan['unassigned_by_zone'] ?? [] as $zone => $stops) {
        foreach ($stops as $stop) {
            if (!is_array($stop)) {
                continue;
            }
            $stop['zone'] = (string)$zone;
            $out['unassigned'][] = $stop;
        }
    }
    if (!table_exists($db, 'daily_order_assignments')) {
        return $out;
    }
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $stmt = $db->prepare(
        "SELECT doa.id AS assignment_id, doa.daily_order_id, doa.driver_id,
                COALESCE(doa.delivery_status, 'pending') AS delivery_status,
                c.name AS customer_name, COALESCE(d.name, '') AS driver_name,
                COALESCE(NULLIF(c.zone, ''), '') AS zone
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         {$origin}
         LEFT JOIN drivers d ON d.id = doa.driver_id
         WHERE doa.delivery_date = ?
           AND COALESCE(doa.delivery_status, 'pending') IN ('failed', 'pending')
         ORDER BY COALESCE(doa.delivery_status, 'pending') = 'failed' DESC, c.name"
    );
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string)$row['delivery_status'];
        if ($status === 'failed') {
            $out['failed'][] = $row;
        } else {
            $out['pending'][] = $row;
        }
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function bakery_manager_phone_movable_stops(PDO $db, string $date): array
{
    if (!table_exists($db, 'daily_orders')) {
        return [];
    }
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $stmt = $db->prepare(
        "SELECT do.id AS daily_order_id, c.name AS customer_name,
                doa.driver_id, COALESCE(d.name, '') AS driver_name,
                COALESCE(doa.delivery_status, '') AS delivery_status,
                COALESCE(NULLIF(c.zone, ''), '') AS zone
         FROM daily_orders do
         JOIN customers c ON c.id = do.customer_id
         {$origin}
         LEFT JOIN daily_order_assignments doa ON doa.daily_order_id = do.id AND doa.delivery_date = do.order_date
         LEFT JOIN drivers d ON d.id = doa.driver_id
         WHERE do.order_date = ?
           AND (doa.id IS NULL OR COALESCE(doa.delivery_status, 'pending') IN ('pending', 'failed'))
         ORDER BY c.name"
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function bakery_manager_phone_skip_stops(PDO $db, string $date): array
{
    if (!table_exists($db, 'daily_order_assignments')) {
        return [];
    }
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $stmt = $db->prepare(
        "SELECT do.id AS daily_order_id, c.name AS customer_name,
                COALESCE(doa.delivery_status, 'pending') AS delivery_status,
                COALESCE(d.name, '') AS driver_name
         FROM daily_order_assignments doa
         JOIN daily_orders do ON do.id = doa.daily_order_id
         JOIN customers c ON c.id = do.customer_id
         {$origin}
         LEFT JOIN drivers d ON d.id = doa.driver_id
         WHERE doa.delivery_date = ?
           AND COALESCE(doa.delivery_status, 'pending') IN ('pending', 'in_transit', 'failed', 'cancelled')
         ORDER BY c.name"
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string,mixed>> */
function bakery_manager_phone_search_customers(PDO $db, string $query): array
{
    $query = trim($query);
    if ($query === '' || !table_exists($db, 'customers')) {
        return [];
    }
    $origin = function_exists('bakery_sfb_ops_origin_clause') ? bakery_sfb_ops_origin_clause('c', $db) : '';
    $stmt = $db->prepare(
        "SELECT c.id, c.name, COALESCE(NULLIF(c.zone, ''), '') AS zone
         FROM customers c
         {$origin}
         WHERE c.is_active = 1 AND c.name LIKE ?
         ORDER BY c.name
         LIMIT 12"
    );
    $stmt->execute(['%' . $query . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string,mixed>|null */
function bakery_manager_phone_customer(PDO $db, int $customerId): ?array
{
    if ($customerId <= 0 || !table_exists($db, 'customers')) {
        return null;
    }
    $stmt = $db->prepare('SELECT * FROM customers WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @param array<string,mixed> $customer
 * @return list<array<string,mixed>>
 */
function bakery_manager_phone_customer_items(PDO $db, array $customer, string $date): array
{
    $daily = bakery_customer_daily_order_row($db, (int)$customer['id'], $date);
    if (!$daily) {
        return [];
    }
    return bakery_customer_daily_items($db, (int)$daily['id']);
}

function bakery_manager_phone_handle_post(PDO $db, string $date, array $input): ?string
{
    bakery_require_role(['administrator', 'manager']);
    $mutation = (string)($input['manager_mutation'] ?? '');
    if (!in_array($mutation, ['phone_move', 'phone_qty', 'phone_skip', 'phone_unskip'], true)) {
        return null;
    }
    if ($mutation === 'phone_move') {
        $orderId = (int)($input['daily_order_id'] ?? 0);
        $toDriver = (int)($input['to_driver_id'] ?? 0);
        if ($orderId <= 0 || $toDriver <= 0) {
            throw new InvalidArgumentException(bakery_t('manager_phone.err_pick_stop_driver'));
        }
        $assigned = false;
        if (table_exists($db, 'daily_order_assignments')) {
            $check = $db->prepare(
                'SELECT id FROM daily_order_assignments WHERE daily_order_id = ? AND delivery_date = ? LIMIT 1'
            );
            $check->execute([$orderId, $date]);
            $assigned = (bool)$check->fetchColumn();
        }
        if ($assigned) {
            bakery_driver_transfer_assignments($db, [$orderId], $toDriver, $date);
        } else {
            bakery_driver_assign_orders($db, $toDriver, $date, [['daily_order_id' => $orderId]], 'append');
        }
        return bakery_t('manager_phone.notice_moved');
    }
    if ($mutation === 'phone_qty') {
        $customerId = (int)($input['customer_id'] ?? 0);
        $productId = (int)($input['product_id'] ?? 0);
        $quantity = (int)($input['quantity'] ?? 0);
        $customer = bakery_manager_phone_customer($db, $customerId);
        if (!$customer) {
            throw new InvalidArgumentException(bakery_t('manager_phone.err_customer'));
        }
        bakery_customer_save_daily_line($db, $customer, $date, $productId, $quantity);
        return bakery_t('manager_phone.notice_qty');
    }
    if ($mutation === 'phone_skip') {
        $orderId = (int)($input['daily_order_id'] ?? 0);
        $reason = trim((string)($input['skip_reason'] ?? ''));
        bakery_skip_delivery_stop($db, $orderId, $reason);
        return bakery_t('manager_phone.notice_skip');
    }
    bakery_unskip_delivery_stop($db, (int)($input['daily_order_id'] ?? 0));
    return bakery_t('manager_phone.notice_unskip');
}

function bakery_manager_phone_status_label(string $status): string
{
    $map = [
        'pending' => 'manager_phone.status_pending',
        'in_transit' => 'manager_phone.status_in_transit',
        'delivered' => 'manager_phone.status_delivered',
        'failed' => 'manager_phone.status_failed',
        'cancelled' => 'manager_phone.status_skipped',
    ];
    $key = $map[$status] ?? '';
    return $key !== '' ? bakery_t($key) : $status;
}

function bakery_manager_phone_render(array $ctx): void
{
    $date = (string)$ctx['date'];
    $view = (string)$ctx['view'];
    $sheet = (string)$ctx['sheet'];
    $today = (string)$ctx['today'];
    $h = 'bakery_manager_phone_h';
    $failed = (int)($ctx['failed'] ?? 0);
    $unassigned = (int)($ctx['unassigned'] ?? 0);
    $pendingOpen = (int)($ctx['pending'] ?? 0) + (int)($ctx['inTransit'] ?? 0);
    ?>
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/exception_desk.css'); ?>">
<link rel="stylesheet" href="<?php echo bakery_asset_href('css/manager_phone.css'); ?>">
<main class="manager-phone" data-view="<?php echo $h($view); ?>">
  <header class="manager-phone__hero">
    <p class="manager-phone__eyebrow"><?php bakery_te('manager_phone.eyebrow'); ?></p>
    <h1><?php bakery_te('manager_phone.title'); ?></h1>
    <nav class="manager-phone__dates" aria-label="<?php bakery_te('manager_phone.date_aria'); ?>">
      <a href="<?php echo $h(bakery_manager_phone_href($ctx['previousDate'], $view)); ?>"><?php bakery_te('common.prev'); ?></a>
      <?php if ($date !== $today): ?>
        <a class="manager-phone__today" href="<?php echo $h(bakery_manager_phone_href($today, $view)); ?>"><?php bakery_te('common.today'); ?></a>
      <?php endif; ?>
      <form method="get" action="">
        <input type="hidden" name="view" value="<?php echo $h($view); ?>">
        <label class="sf-sr-only" for="manager-phone-date"><?php bakery_te('manager_phone.operating_date'); ?></label>
        <input id="manager-phone-date" type="date" name="date" value="<?php echo $h($date); ?>" onchange="this.form.submit()">
        <button type="submit"><?php bakery_te('common.go'); ?></button>
      </form>
      <a href="<?php echo $h(bakery_manager_phone_href($ctx['nextDate'], $view)); ?>"><?php bakery_te('common.next'); ?></a>
    </nav>
    <p class="manager-phone__when"><?php echo $h((string)$ctx['dateDisplay']); ?></p>
  </header>

  <?php if (!empty($ctx['error'])): ?>
    <div class="sf-alert sf-alert--warning" role="alert"><?php echo $h($ctx['error']); ?></div>
  <?php endif; ?>
  <?php if (!empty($ctx['notice'])): ?>
    <div class="sf-alert sf-alert--success" role="status"><?php echo $h($ctx['notice']); ?></div>
  <?php endif; ?>

  <?php if ($view === 'today') { bakery_manager_phone_render_today($ctx); } ?>
  <?php if ($view === 'routes') { bakery_manager_phone_render_routes($ctx); } ?>
  <?php if ($view === 'kitchen') { bakery_manager_phone_render_kitchen($ctx); } ?>
  <?php if ($view === 'missed') { bakery_manager_phone_render_missed($ctx); } ?>

  <nav class="manager-phone__adjust" aria-label="<?php bakery_te('manager_phone.adjust_aria'); ?>">
    <a class="manager-phone__adjust-btn" data-sheet="move" href="<?php echo $h(bakery_manager_phone_href($date, $view, ['sheet' => 'move'])); ?>"><?php bakery_te('manager_phone.move_stop'); ?></a>
    <a class="manager-phone__adjust-btn" data-sheet="qty" href="<?php echo $h(bakery_manager_phone_href($date, $view, ['sheet' => 'qty'])); ?>"><?php bakery_te('manager_phone.change_qty'); ?></a>
    <a class="manager-phone__adjust-btn" data-sheet="skip" href="<?php echo $h(bakery_manager_phone_href($date, $view, ['sheet' => 'skip'])); ?>"><?php bakery_te('manager_phone.skip_stop'); ?></a>
  </nav>
</main>
<?php
    if ($sheet !== '') {
        bakery_manager_phone_render_sheet($ctx);
    }
}

function bakery_manager_phone_render_today(array $ctx): void
{
    $date = (string)$ctx['date'];
    $h = 'bakery_manager_phone_h';
    $action = $ctx['next_action'];
    $dailyOrders = (int)$ctx['dailyOrders'];
    $assignedStops = (int)$ctx['assignedStops'];
    $delivered = (int)$ctx['delivered'];
    $failed = (int)$ctx['failed'];
    $unassigned = (int)$ctx['unassigned'];
    $pendingOpen = (int)$ctx['pending'] + (int)$ctx['inTransit'];
    $missedCount = (int)$failed + (int)$unassigned;
    ?>
  <section class="manager-phone__next manager-phone__next--<?php echo $h($action['tone']); ?>">
    <span><?php bakery_te('manager_phone.next'); ?></span>
    <strong><?php bakery_te('manager_phone.next_' . $action['key']); ?></strong>
    <a class="manager-phone__btn manager-phone__btn--primary" href="<?php echo $h($action['href']); ?>"><?php bakery_te('manager_phone.open'); ?></a>
  </section>

  <section class="manager-phone__scores" aria-label="<?php bakery_te('manager_phone.scorecard'); ?>">
    <a class="manager-phone__score<?php echo $ctx['missingDaily'] > 0 ? ' is-loud' : ''; ?>" href="<?php echo $h(bakery_ops_link_daily_orders($date, [], 'manager')); ?>">
      <span><?php bakery_te('manager_phone.orders'); ?></span>
      <strong><?php echo number_format($dailyOrders); ?></strong>
    </a>
    <a class="manager-phone__score<?php echo $unassigned > 0 ? ' is-loud' : ''; ?>" href="<?php echo $h(bakery_manager_phone_href($date, 'routes')); ?>">
      <span><?php bakery_te('manager_phone.assigned'); ?></span>
      <strong><?php echo number_format($assignedStops); ?><small>/<?php echo number_format($dailyOrders); ?></small></strong>
    </a>
    <a class="manager-phone__score" href="<?php echo $h(bakery_manager_phone_href($date, 'routes')); ?>">
      <span><?php bakery_te('manager_phone.in_progress'); ?></span>
      <strong><?php echo number_format($pendingOpen); ?></strong>
    </a>
    <a class="manager-phone__score<?php echo $missedCount > 0 ? ' is-loud' : ''; ?>" href="<?php echo $h(bakery_manager_phone_href($date, 'missed')); ?>">
      <span><?php bakery_te('manager_phone.missed'); ?></span>
      <strong><?php echo number_format($missedCount); ?></strong>
    </a>
  </section>

  <?php if ($date === (string)$ctx['today'] && is_array($ctx['tomorrow'] ?? null)): ?>
    <?php
      $tr = $ctx['tomorrow'];
      $trState = (string)($tr['state'] ?? 'unavailable');
      $trNeeds = bakery_manager_phone_tomorrow_needs_work($tr);
    ?>
    <?php if (!in_array($trState, ['unavailable', 'no_demand'], true)): ?>
      <a class="manager-phone__tomorrow<?php echo $trNeeds ? ' manager-phone__tomorrow--needs' : ''; ?>" href="<?php echo $h($trNeeds
          ? bakery_ops_link_daily_orders((string)$ctx['tomorrow_date'], [], 'manager')
          : bakery_manager_phone_href((string)$ctx['tomorrow_date'], 'today')); ?>">
        <span><?php bakery_te('manager_phone.tomorrow'); ?> · <?php echo $h((string)$ctx['tomorrow_date']); ?></span>
        <strong><?php
          if ($trNeeds) {
              bakery_te('manager_phone.tomorrow_needs_work');
          } else {
              bakery_te('manager_phone.tomorrow_ready');
          }
        ?></strong>
      </a>
    <?php endif; ?>
  <?php endif; ?>
  <?php
}

function bakery_manager_phone_render_routes(array $ctx): void
{
    $date = (string)$ctx['date'];
    $h = 'bakery_manager_phone_h';
    $plan = $ctx['routePlan'] ?? [];
    $unassignedCount = (int)($plan['unassigned_count'] ?? $ctx['unassigned'] ?? 0);
    $driverStops = $ctx['driver_stops'] ?? [];
    ?>
  <?php if ($unassignedCount > 0): ?>
    <section class="manager-phone__panel manager-phone__panel--loud">
      <h2><?php echo htmlspecialchars(bakery_t('manager_phone.unassigned_count', ['count' => $unassignedCount]), ENT_QUOTES, 'UTF-8'); ?></h2>
      <?php foreach ($plan['unassigned_by_zone'] ?? [] as $zone => $stops): ?>
        <?php
          $shown = array_slice($stops, 0, 8);
          $extra = count($stops) - count($shown);
        ?>
        <p class="manager-phone__zone"><?php echo $h((string)$zone); ?> · <?php echo count($stops); ?></p>
        <ul class="manager-phone__list">
          <?php foreach ($shown as $stop): ?>
            <li><?php echo $h((string)($stop['customer_name'] ?? '')); ?></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($extra > 0): ?>
          <p><?php echo htmlspecialchars(bakery_t('manager_phone.and_more', ['count' => $extra]), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      <?php endforeach; ?>
      <a class="manager-phone__btn manager-phone__btn--primary" href="<?php echo $h(bakery_ops_link_driver_assignment($date, ['filter' => 'unassigned'], 'manager')); ?>"><?php bakery_te('manager_phone.assign_these'); ?></a>
    </section>
  <?php endif; ?>

  <section class="manager-phone__drivers" aria-label="<?php bakery_te('manager_phone.driver_board'); ?>">
    <?php if (empty($ctx['driverRows'])): ?>
      <p class="manager-phone__cadence"><?php bakery_te('manager_phone.no_drivers'); ?></p>
    <?php endif; ?>
    <?php foreach ($ctx['driverRows'] ?? [] as $driver): ?>
      <?php
        $id = (int)$driver['id'];
        $open = (int)$driver['pending'] + (int)$driver['in_transit'];
        $failed = (int)$driver['failed'];
        $stops = $driverStops[$id] ?? [];
        $loud = $failed > 0 || ((int)$driver['stops'] === 0);
      ?>
      <article class="manager-phone__driver<?php echo $loud ? ' is-loud' : ''; ?>">
        <header>
          <h3><?php echo $h($driver['name']); ?></h3>
          <?php if ((int)$driver['in_transit'] > 0): ?>
            <span class="manager-phone__live"><?php bakery_te('manager_phone.do_not_interrupt'); ?></span>
          <?php endif; ?>
        </header>
        <p class="manager-phone__counts">
          <?php echo number_format((int)$driver['stops']); ?> <?php bakery_te('manager_phone.stops'); ?>
          · <?php echo number_format($open); ?> <?php bakery_te('manager_phone.open_stops'); ?>
          · <?php echo number_format((int)$driver['delivered']); ?> <?php bakery_te('manager_phone.delivered'); ?>
          <?php if ($failed > 0): ?> · <strong><?php echo number_format($failed); ?> <?php bakery_te('manager_phone.failed'); ?></strong><?php endif; ?>
        </p>
        <?php if ($stops): ?>
          <details>
            <summary><?php bakery_te('manager_phone.show_stops'); ?></summary>
            <ul class="manager-phone__list">
              <?php foreach ($stops as $stop): ?>
                <?php $st = (string)$stop['delivery_status']; ?>
                <li class="is-<?php echo $h($st); ?>">
                  <?php echo $h((string)$stop['customer_name']); ?>
                  <small><?php echo $h(bakery_manager_phone_status_label($st)); ?></small>
                </li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
        <a class="manager-phone__btn manager-phone__btn--primary" href="<?php echo $h((defined('BASE_URL') ? BASE_URL : '') . 'driver.php?driver_id=' . $id . '&date=' . rawurlencode($date)); ?>"><?php bakery_te('manager_phone.open_route'); ?></a>
      </article>
    <?php endforeach; ?>
  </section>
  <?php
}

function bakery_manager_phone_render_kitchen(array $ctx): void
{
    $date = (string)$ctx['date'];
    $h = 'bakery_manager_phone_h';
    $summary = $ctx['productionSummary'] ?? [];
    $pack = $ctx['packingSummary'] ?? [];
    $target = (int)($summary['target'] ?? 0);
    $produced = (int)($summary['produced'] ?? 0);
    $remaining = (int)($summary['remaining'] ?? 0);
    $percent = $target > 0 ? min(100, (int)round(($produced / $target) * 100)) : 0;
    $shorts = bakery_manager_phone_short_products($ctx['productionRows'] ?? []);
    $db = $ctx['db'] ?? null;
    $planStateAvailable = $db instanceof PDO
        && function_exists('bakery_production_plan_commits_ready')
        && bakery_production_plan_commits_ready($db);
    $committedAtLabel = '';
    $driftCount = 0;
    if ($planStateAvailable && function_exists('bakery_production_plan_state')) {
        try {
            $planState = bakery_production_plan_state($db, $date);
            if ($planState['commit'] !== null) {
                $committedTs = strtotime((string)($planState['commit']['committed_at'] ?? ''));
                $committedAtLabel = bakery_t('manager_phone.plan_committed_at', [
                    'time' => $committedTs
                        ? date('g:i A', $committedTs)
                        : (string)($planState['commit']['committed_at'] ?? ''),
                ]);
                $driftCount = (int)($planState['changed_since']['count'] ?? 0);
            }
        } catch (Throwable $e) {
            error_log('manager phone kitchen plan state: ' . $e->getMessage());
        }
    }
    ?>
  <p class="manager-phone__cadence"><?php bakery_te('manager_phone.cadence'); ?></p>
  <?php if ($planStateAvailable): ?>
    <div class="manager-phone__chips">
      <?php if ($committedAtLabel === ''): ?>
        <span class="manager-phone__chip manager-phone__chip--loud"><?php bakery_te('manager_phone.plan_not_committed'); ?></span>
      <?php else: ?>
        <span class="manager-phone__chip<?php echo $driftCount > 0 ? ' manager-phone__chip--loud' : ''; ?>"><?php echo $h($committedAtLabel); ?></span>
        <?php if ($driftCount > 0): ?>
          <span class="manager-phone__chip manager-phone__chip--loud"><?php echo $h(bakery_t('manager_phone.plan_drift_count', ['count' => $driftCount])); ?></span>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <section class="manager-phone__panel">
    <h2><?php bakery_te('manager_phone.bake'); ?></h2>
    <p class="manager-phone__metric"><?php echo number_format($produced); ?> / <?php echo number_format($target); ?></p>
    <div class="manager-phone__bar" aria-hidden="true"><span style="width:<?php echo $percent; ?>%"></span></div>
    <p><?php echo $remaining > 0
        ? htmlspecialchars(bakery_t('manager_phone.still_to_make', ['count' => $remaining]), ENT_QUOTES, 'UTF-8')
        : bakery_t('manager_phone.bake_covered'); ?></p>
    <a class="manager-phone__btn manager-phone__btn--primary" href="<?php echo $h(bakery_ops_link_production($date, [], 'manager')); ?>"><?php bakery_te('manager_phone.open_bake'); ?></a>
  </section>

  <section class="manager-phone__panel">
    <h2><?php bakery_te('manager_phone.pack'); ?></h2>
    <p class="manager-phone__metric"><?php echo (int)$pack['percent']; ?>%</p>
    <div class="manager-phone__bar" aria-hidden="true"><span style="width:<?php echo (int)$pack['percent']; ?>%"></span></div>
    <p><?php echo number_format((int)$pack['checked_lines']); ?> / <?php echo number_format((int)$pack['required_lines']); ?> <?php bakery_te('manager_phone.pack_lines'); ?></p>
    <a class="manager-phone__btn manager-phone__btn--primary" href="<?php echo $h(bakery_ops_link_pack_list($date, ['view' => 'product'], 'manager')); ?>"><?php bakery_te('manager_phone.open_pack'); ?></a>
  </section>

  <?php if ($shorts): ?>
    <section class="manager-phone__panel">
      <h2><?php bakery_te('manager_phone.short_products'); ?></h2>
      <ul class="manager-phone__list">
        <?php foreach ($shorts as $row): ?>
          <li>
            <strong><?php echo $h($row['product_name']); ?></strong>
            <small><?php echo number_format((int)$row['remaining_quantity']); ?> <?php bakery_te('manager_phone.to_make'); ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php if (!empty($ctx['bakerRows'])): ?>
    <section class="manager-phone__panel">
      <h2><?php bakery_te('manager_phone.bakers'); ?></h2>
      <ul class="manager-phone__list">
        <?php foreach ($ctx['bakerRows'] as $baker): ?>
          <li>
            <strong><?php echo $h($baker['display_name']); ?></strong>
            <small><?php echo !empty($baker['is_active']) ? bakery_t('manager_phone.baker_active') : bakery_t('manager_phone.baker_idle'); ?>
              <?php if ($baker['last_opened_label'] !== ''): ?> · <?php echo $h($baker['last_opened_label']); ?><?php endif; ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
  <?php
}

function bakery_manager_phone_render_missed(array $ctx): void
{
    $date = (string)$ctx['date'];
    $h = 'bakery_manager_phone_h';
    $missed = $ctx['missed'] ?? ['failed' => [], 'pending' => [], 'unassigned' => []];
    $db = $ctx['db'] ?? null;
    $runFinished = !empty($ctx['run_finished']);
    $pendingKey = $runFinished ? 'manager_phone.bucket_pending' : 'manager_phone.bucket_still_out';
    ?>
  <p class="manager-phone__cadence"><?php bakery_te('manager_phone.missed_intro'); ?></p>

  <?php if ($db instanceof PDO && function_exists('bakery_exception_desk_render')): ?>
    <?php bakery_exception_desk_render($db, $date, $ctx['exceptions'] ?? []); ?>
  <?php endif; ?>

  <section class="manager-phone__panel<?php echo $missed['failed'] ? ' manager-phone__panel--loud' : ''; ?>">
    <h2><?php echo htmlspecialchars(bakery_t('manager_phone.bucket_failed', ['count' => count($missed['failed'])]), ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php if (!$missed['failed']): ?>
      <p><?php bakery_te('manager_phone.none'); ?></p>
    <?php else: ?>
      <ul class="manager-phone__list">
        <?php foreach ($missed['failed'] as $row): ?>
          <li>
            <strong><?php echo $h($row['customer_name']); ?></strong>
            <small><?php echo $h((string)$row['driver_name']); ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="manager-phone__panel<?php echo $runFinished && $missed['pending'] ? ' manager-phone__panel--loud' : ''; ?>">
    <h2><?php echo htmlspecialchars(bakery_t($pendingKey, ['count' => count($missed['pending'])]), ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php if (!$missed['pending']): ?>
      <p><?php bakery_te('manager_phone.none'); ?></p>
    <?php else: ?>
      <ul class="manager-phone__list">
        <?php foreach ($missed['pending'] as $row): ?>
          <li>
            <strong><?php echo $h($row['customer_name']); ?></strong>
            <small><?php echo $h((string)$row['driver_name']); ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="manager-phone__panel">
    <h2><?php echo htmlspecialchars(bakery_t('manager_phone.bucket_unassigned', ['count' => count($missed['unassigned'])]), ENT_QUOTES, 'UTF-8'); ?></h2>
    <?php if (!$missed['unassigned']): ?>
      <p><?php bakery_te('manager_phone.none'); ?></p>
    <?php else: ?>
      <ul class="manager-phone__list">
        <?php foreach ($missed['unassigned'] as $row): ?>
          <li>
            <strong><?php echo $h((string)($row['customer_name'] ?? '')); ?></strong>
            <small><?php echo $h((string)($row['zone'] ?? '')); ?></small>
          </li>
        <?php endforeach; ?>
      </ul>
      <a class="manager-phone__btn" href="<?php echo $h(bakery_ops_link_driver_assignment($date, ['filter' => 'unassigned'], 'manager')); ?>"><?php bakery_te('manager_phone.assign_these'); ?></a>
    <?php endif; ?>
  </section>

  <a class="manager-phone__btn" href="<?php echo $h((defined('BASE_URL') ? BASE_URL : '') . 'route_summary.php?date=' . rawurlencode($date)); ?>"><?php bakery_te('manager_phone.photos'); ?></a>
  <?php
}

function bakery_manager_phone_render_sheet(array $ctx): void
{
    $date = (string)$ctx['date'];
    $view = (string)$ctx['view'];
    $sheet = (string)$ctx['sheet'];
    $h = 'bakery_manager_phone_h';
    $close = bakery_manager_phone_href($date, $view);
    $drivers = $ctx['routePlan']['drivers'] ?? [];
    ?>
<div class="manager-phone-sheet" role="dialog" aria-modal="true" data-sheet="<?php echo $h($sheet); ?>">
  <a class="manager-phone-sheet__backdrop" href="<?php echo $h($close); ?>" aria-label="<?php bakery_te('common.close'); ?>"></a>
  <div class="manager-phone-sheet__card">
    <a class="manager-phone-sheet__close" href="<?php echo $h($close); ?>"><?php bakery_te('common.close'); ?></a>
    <?php if ($sheet === 'move'): ?>
      <h2><?php bakery_te('manager_phone.move_stop'); ?></h2>
      <form method="post" class="manager-phone-sheet__form">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="manager_mutation" value="phone_move">
        <input type="hidden" name="view" value="<?php echo $h($view); ?>">
        <p><?php bakery_te('manager_phone.pick_stop'); ?></p>
        <div class="exception-desk__chips manager-phone-sheet__chips">
          <?php foreach ($ctx['movable_stops'] as $stop): ?>
            <label class="exception-desk__chip">
              <input type="radio" name="daily_order_id" value="<?php echo (int)$stop['daily_order_id']; ?>" required>
              <span><?php echo $h($stop['customer_name']); ?><?php if ($stop['driver_name'] !== ''): ?> · <?php echo $h($stop['driver_name']); ?><?php endif; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <p><?php bakery_te('manager_phone.pick_driver'); ?></p>
        <div class="exception-desk__chips">
          <?php foreach ($drivers as $driver): ?>
            <label class="exception-desk__chip">
              <input type="radio" name="to_driver_id" value="<?php echo (int)$driver['id']; ?>" required>
              <span><?php echo $h((string)$driver['name']); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <button class="manager-phone__btn manager-phone__btn--primary" type="submit"><?php bakery_te('manager_phone.confirm_move'); ?></button>
      </form>
    <?php elseif ($sheet === 'qty'): ?>
      <h2><?php bakery_te('manager_phone.change_qty'); ?></h2>
      <form method="get" class="manager-phone-sheet__form">
        <input type="hidden" name="date" value="<?php echo $h($date); ?>">
        <input type="hidden" name="view" value="<?php echo $h($view); ?>">
        <input type="hidden" name="sheet" value="qty">
        <label><?php bakery_te('common.search'); ?>
          <input type="search" name="q" value="<?php echo $h((string)$ctx['qty_query']); ?>" placeholder="<?php bakery_te('manager_phone.search_customer'); ?>"<?php echo empty($ctx['qty_customer']) ? ' autofocus' : ''; ?>>
        </label>
        <button class="manager-phone__btn" type="submit"><?php bakery_te('common.search'); ?></button>
      </form>
      <?php if ($ctx['qty_matches']): ?>
        <ul class="manager-phone__list">
          <?php foreach ($ctx['qty_matches'] as $match): ?>
            <li>
              <a href="<?php echo $h(bakery_manager_phone_href($date, $view, ['sheet' => 'qty', 'customer_id' => (int)$match['id'], 'q' => (string)$ctx['qty_query']])); ?>">
                <?php echo $h($match['name']); ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($ctx['qty_customer']): ?>
        <h3><?php echo $h((string)$ctx['qty_customer']['name']); ?></h3>
        <?php if (!$ctx['qty_items']): ?>
          <p><?php bakery_te('manager_phone.no_dated_items'); ?></p>
        <?php else: ?>
          <?php foreach ($ctx['qty_items'] as $item): ?>
            <form method="post" class="manager-phone-sheet__qty">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="manager_mutation" value="phone_qty">
              <input type="hidden" name="view" value="<?php echo $h($view); ?>">
              <input type="hidden" name="customer_id" value="<?php echo (int)$ctx['qty_customer']['id']; ?>">
              <input type="hidden" name="product_id" value="<?php echo (int)$item['product_id']; ?>">
              <label><?php echo $h((string)($item['product_name'] ?? ('#' . $item['product_id']))); ?>
                <input type="number" name="quantity" min="0" step="1" value="<?php echo (int)$item['quantity']; ?>">
              </label>
              <button class="manager-phone__btn manager-phone__btn--primary" type="submit"><?php bakery_te('common.save'); ?></button>
            </form>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    <?php else: ?>
      <h2><?php bakery_te('manager_phone.skip_stop'); ?></h2>
      <form method="post" class="manager-phone-sheet__form">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="manager_mutation" value="phone_skip">
        <input type="hidden" name="view" value="<?php echo $h($view); ?>">
        <p><?php bakery_te('manager_phone.pick_stop'); ?></p>
        <div class="exception-desk__chips manager-phone-sheet__chips">
          <?php foreach ($ctx['skip_stops'] as $stop): ?>
            <?php if ((string)$stop['delivery_status'] === 'cancelled') { continue; } ?>
            <label class="exception-desk__chip">
              <input type="radio" name="daily_order_id" value="<?php echo (int)$stop['daily_order_id']; ?>" required>
              <span><?php echo $h($stop['customer_name']); ?> · <?php echo $h(bakery_manager_phone_status_label((string)$stop['delivery_status'])); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <label><?php bakery_te('manager_phone.skip_reason'); ?>
          <input name="skip_reason" maxlength="500" required>
        </label>
        <button class="manager-phone__btn manager-phone__btn--primary" type="submit"><?php bakery_te('manager_phone.confirm_skip'); ?></button>
      </form>
      <h3><?php bakery_te('manager_phone.restore_skipped'); ?></h3>
      <?php foreach ($ctx['skip_stops'] as $stop): ?>
        <?php if ((string)$stop['delivery_status'] !== 'cancelled') { continue; } ?>
        <form method="post" class="manager-phone-sheet__qty">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="manager_mutation" value="phone_unskip">
          <input type="hidden" name="view" value="<?php echo $h($view); ?>">
          <input type="hidden" name="daily_order_id" value="<?php echo (int)$stop['daily_order_id']; ?>">
          <span><?php echo $h($stop['customer_name']); ?></span>
          <button class="manager-phone__btn" type="submit"><?php bakery_te('manager_phone.restore'); ?></button>
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
    <?php
}
