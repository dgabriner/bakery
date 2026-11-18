<?php
/**
 * Pack List check-offs: shared per delivery date.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/production_plan.php';

function bakery_pack_line_key(int $customerId, int $productId): string
{
    return 'c' . $customerId . '_p' . $productId;
}

function bakery_pack_line_key_valid(string $key): bool
{
    return $key !== '' && strlen($key) <= 64 && (bool)preg_match('/^c\d+_p\d+$/', $key);
}

/**
 * Pack List screen view. Missing or unknown values stay on the route sheet,
 * which is the page default.
 */
function bakery_pack_normalize_view(string $raw): string
{
    switch ($raw) {
        case 'product':
        case 'customer':
        case 'route':
            return $raw;
        default:
            return 'route';
    }
}

/**
 * Customer-card route line. A store with no driver uses the same words as the
 * unassigned route sheet.
 */
function bakery_pack_customer_route_label(string $routeName): string
{
    $routeName = trim($routeName);
    if ($routeName === '') {
        return bakery_t('pack_list.unassigned_route');
    }
    return bakery_t('pack_list.customer_route', ['route' => $routeName]);
}

/**
 * Group operating-demand lines into the product, customer, and route sheets.
 * Zero quantities are dropped. Repeated customer × product lines stay separate.
 * Customer groups carry the route (driver) name when one is assigned.
 *
 * @param list<array<string,mixed>> $lineItems
 * @param array<int,array<string,mixed>> $customerRouteById
 * @param array<int,string> $doughByProduct
 * @return array{
 *   by_product: array<int,array<string,mixed>>,
 *   by_customer: array<int,array<string,mixed>>,
 *   by_route: array<int,array<string,mixed>>,
 *   product_totals: array<int,int>,
 *   customer_totals: array<int,int>,
 *   all_line_keys: array<string,true>,
 *   line_keys_by_driver: array<int,array<string,true>>,
 *   total_units: int
 * }
 */
function bakery_pack_group_lines(array $lineItems, array $customerRouteById, array $doughByProduct, string $unassignedRouteLabel): array
{
    $byProduct = [];
    $byCustomer = [];
    $byRoute = [];
    $productTotals = [];
    $customerTotals = [];
    $allLineKeys = [];
    $lineKeysByDriver = [];
    $totalUnits = 0;

    foreach ($lineItems as $row) {
        $customerId = (int)$row['customer_id'];
        $productId = (int)$row['product_id'];
        $qty = (int)$row['quantity'];
        $customerName = (string)$row['customer_name'];
        $customerZone = trim((string)($row['customer_zone'] ?? ''));
        $productName = (string)$row['product_name'];
        $doughType = (string)($row['dough_type_name'] ?? '');
        if ($doughType === '' && isset($doughByProduct[$productId])) {
            $doughType = (string)$doughByProduct[$productId];
        }
        if ($qty <= 0) {
            continue;
        }

        $totalUnits += $qty;
        $productTotals[$productId] = ($productTotals[$productId] ?? 0) + $qty;
        $customerTotals[$customerId] = ($customerTotals[$customerId] ?? 0) + $qty;
        $lineKey = bakery_pack_line_key($customerId, $productId);
        $allLineKeys[$lineKey] = true;

        if (!isset($byProduct[$productId])) {
            $byProduct[$productId] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'dough_type' => $doughType,
                'total' => 0,
                'customers' => [],
            ];
        }
        $byProduct[$productId]['total'] += $qty;
        $byProduct[$productId]['customers'][] = [
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'zone' => $customerZone,
            'quantity' => $qty,
            'line_key' => $lineKey,
        ];

        $routeMeta = $customerRouteById[$customerId] ?? null;
        $driverId = $routeMeta ? (int)$routeMeta['driver_id'] : 0;
        $driverName = $routeMeta
            ? (string)$routeMeta['driver_name']
            : $unassignedRouteLabel;
        $routeOrder = $routeMeta ? (int)$routeMeta['route_order'] : 2147483647;
        $routeName = $routeMeta ? trim((string)$routeMeta['driver_name']) : '';

        if (!isset($byCustomer[$customerId])) {
            $byCustomer[$customerId] = [
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'zone' => $customerZone,
                'route_name' => $routeName,
                'driver_id' => $driverId,
                'total' => 0,
                'products' => [],
            ];
        }
        $byCustomer[$customerId]['total'] += $qty;
        $byCustomer[$customerId]['products'][] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'dough_type' => $doughType,
            'quantity' => $qty,
            'line_key' => $lineKey,
        ];

        if (!isset($byRoute[$driverId])) {
            $byRoute[$driverId] = [
                'driver_id' => $driverId,
                'driver_name' => $driverName,
                'total' => 0,
                'customers' => [],
            ];
        }
        $byRoute[$driverId]['total'] += $qty;
        if (!isset($byRoute[$driverId]['customers'][$customerId])) {
            $byRoute[$driverId]['customers'][$customerId] = [
                'customer_id' => $customerId,
                'customer_name' => $customerName,
                'zone' => $customerZone,
                'route_order' => $routeOrder,
                'total' => 0,
                'products' => [],
            ];
        }
        $byRoute[$driverId]['customers'][$customerId]['total'] += $qty;
        $byRoute[$driverId]['customers'][$customerId]['products'][] = [
            'product_id' => $productId,
            'product_name' => $productName,
            'dough_type' => $doughType,
            'quantity' => $qty,
            'line_key' => $lineKey,
        ];
        $lineKeysByDriver[$driverId][$lineKey] = true;
    }

    return [
        'by_product' => $byProduct,
        'by_customer' => $byCustomer,
        'by_route' => $byRoute,
        'product_totals' => $productTotals,
        'customer_totals' => $customerTotals,
        'all_line_keys' => $allLineKeys,
        'line_keys_by_driver' => $lineKeysByDriver,
        'total_units' => $totalUnits,
    ];
}

/**
 * Sort the three pack sheets the way the page shows them.
 * Product order follows shortage only when attention=shortfall is on.
 *
 * @param array<int,array<string,mixed>> $byProduct
 * @param array<int,array<string,mixed>> $byCustomer
 * @param array<int,array<string,mixed>> $byRoute
 * @return array{
 *   by_product: list<array<string,mixed>>,
 *   by_customer: list<array<string,mixed>>,
 *   by_route: list<array<string,mixed>>
 * }
 */
function bakery_pack_sort_grouped(array $byProduct, array $byCustomer, array $byRoute, bool $attentionShortfall): array
{
    $products = array_values($byProduct);
    usort($products, static function ($a, $b) use ($attentionShortfall) {
        if ($attentionShortfall) {
            $shortCmp = ((int)($b['short'] ?? 0)) <=> ((int)($a['short'] ?? 0));
            if ($shortCmp !== 0) {
                return $shortCmp;
            }
        }
        return strcasecmp((string)$a['product_name'], (string)$b['product_name']);
    });

    $customers = array_values($byCustomer);
    usort($customers, static function ($a, $b) {
        return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
    });

    $routes = array_values($byRoute);
    usort($routes, static function ($a, $b) {
        $aUnassigned = ((int)$a['driver_id'] === 0) ? 1 : 0;
        $bUnassigned = ((int)$b['driver_id'] === 0) ? 1 : 0;
        if ($aUnassigned !== $bUnassigned) {
            return $aUnassigned <=> $bUnassigned;
        }
        return strcasecmp((string)$a['driver_name'], (string)$b['driver_name']);
    });
    foreach ($routes as &$routeSection) {
        $stops = array_values($routeSection['customers']);
        usort($stops, static function ($a, $b) {
            if ($a['route_order'] !== $b['route_order']) {
                return $a['route_order'] <=> $b['route_order'];
            }
            return strcasecmp((string)$a['customer_name'], (string)$b['customer_name']);
        });
        $routeSection['customers'] = $stops;
        $productRoll = [];
        foreach ($stops as $stop) {
            foreach ($stop['products'] as $prodLine) {
                $pid = (int)$prodLine['product_id'];
                if (!isset($productRoll[$pid])) {
                    $productRoll[$pid] = [
                        'product_id' => $pid,
                        'product_name' => (string)$prodLine['product_name'],
                        'dough_type' => (string)$prodLine['dough_type'],
                        'quantity' => 0,
                        'stores' => [],
                    ];
                }
                $productRoll[$pid]['quantity'] += (int)$prodLine['quantity'];
                $productRoll[$pid]['stores'][] = [
                    'customer_id' => (int)$stop['customer_id'],
                    'customer_name' => (string)$stop['customer_name'],
                    'zone' => (string)$stop['zone'],
                    'quantity' => (int)$prodLine['quantity'],
                    'line_key' => (string)$prodLine['line_key'],
                ];
            }
        }
        uasort($productRoll, static function ($a, $b) {
            return strcasecmp((string)$a['product_name'], (string)$b['product_name']);
        });
        $routeSection['product_totals'] = array_values($productRoll);
    }
    unset($routeSection);

    return [
        'by_product' => $products,
        'by_customer' => $customers,
        'by_route' => $routes,
    ];
}

function bakery_pack_progress_ready(PDO $db): bool
{
    return function_exists('table_exists') && table_exists($db, 'pack_progress');
}

/**
 * How many of the day's operating demand lines are checked off on the Pack List.
 * Lines are the same customer x product keys the Pack List uses, so a stale check
 * for a line that no longer exists never inflates the count.
 *
 * @param list<array<string,mixed>> $operatingLines Rows with customer_id, product_id, quantity
 * @return array{tracking:bool,total:int,packed:int}
 */
function bakery_pack_day_progress(PDO $db, string $date, array $operatingLines): array
{
    $keys = [];
    foreach ($operatingLines as $line) {
        $customerId = (int)($line['customer_id'] ?? 0);
        $productId = (int)($line['product_id'] ?? 0);
        if ($customerId <= 0 || $productId <= 0 || (int)($line['quantity'] ?? 0) <= 0) {
            continue;
        }
        $keys[bakery_pack_line_key($customerId, $productId)] = true;
    }
    $progress = ['tracking' => false, 'total' => count($keys), 'packed' => 0];
    if (!bakery_pack_progress_ready($db)) {
        return $progress;
    }
    $progress['tracking'] = true;
    if ($keys === []) {
        return $progress;
    }
    $stmt = $db->prepare('SELECT line_key FROM pack_progress WHERE pack_date = ?');
    $stmt->execute([$date]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $checkedKey) {
        if (isset($keys[(string)$checkedKey])) {
            $progress['packed']++;
        }
    }
    return $progress;
}

function bakery_pack_set_checked(PDO $db, string $date, string $lineKey, bool $checked, ?int $userId): void
{
    if (!bakery_pack_progress_ready($db) || !bakery_pack_line_key_valid($lineKey)) {
        throw new InvalidArgumentException('Invalid pack line');
    }
    if ($checked) {
        $stmt = $db->prepare(
            'INSERT IGNORE INTO pack_progress (pack_date, line_key, checked_by_user_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$date, $lineKey, $userId]);
        return;
    }
    $stmt = $db->prepare('DELETE FROM pack_progress WHERE pack_date = ? AND line_key = ?');
    $stmt->execute([$date, $lineKey]);
}

/**
 * Mark many pack lines packed. Existing checks stay. Returns rows present for the date.
 *
 * @param list<string> $lineKeys
 */
function bakery_pack_mark_keys(PDO $db, string $date, array $lineKeys, ?int $userId): int
{
    if (!bakery_pack_progress_ready($db)) {
        throw new RuntimeException('Packing check-offs are unavailable until database migrations are complete.');
    }
    $stmt = $db->prepare(
        'INSERT IGNORE INTO pack_progress (pack_date, line_key, checked_by_user_id) VALUES (?, ?, ?)'
    );
    $seen = [];
    foreach ($lineKeys as $key) {
        $key = trim((string)$key);
        if (!bakery_pack_line_key_valid($key) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $stmt->execute([$date, $key, $userId]);
    }
    $count = $db->prepare('SELECT COUNT(*) FROM pack_progress WHERE pack_date = ?');
    $count->execute([$date]);
    return (int)$count->fetchColumn();
}

/**
 * True when commit tracking is installed and this delivery date has no committed
 * bake plan. When commit tables are missing the answer is false: the page says
 * nothing rather than guessing.
 */
function bakery_pack_plan_uncommitted(PDO $db, string $date): bool
{
    try {
        return bakery_production_plan_commits_ready($db)
            && bakery_production_plan_commit_get($db, $date) === null;
    } catch (Throwable $e) {
        error_log('pack_list plan state: ' . $e->getMessage());
        return false;
    }
}

/**
 * Pack lines in $lineKeys that are not yet checked off.
 *
 * @param list<string> $lineKeys
 * @param array<string,bool> $checkedMap line_key => true
 */
function bakery_pack_unpacked_count(array $lineKeys, array $checkedMap): int
{
    $count = 0;
    foreach ($lineKeys as $key) {
        if (!isset($checkedMap[$key])) {
            $count++;
        }
    }
    return $count;
}

/**
 * onsubmit attribute (with leading space) that asks the user to confirm.
 * Empty message returns an empty string.
 */
function bakery_pack_confirm_attr(string $message): string
{
    if ($message === '') {
        return '';
    }
    $encoded = json_encode(
        $message,
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    return ' onsubmit="return confirm(' . htmlspecialchars((string)$encoded, ENT_QUOTES, 'UTF-8') . ')"';
}

/**
 * Compact POST form to mark one SKU or the whole day's missing bake as produced.
 *
 * @param array{product_id?:int,confirm?:string,class?:string,button_class?:string,driver_id?:int} $opts
 */
function bakery_pack_backfill_form_html(string $action, string $date, string $view, string $label, array $opts = []): string
{
    $productId = (int)($opts['product_id'] ?? 0);
    $confirm = (string)($opts['confirm'] ?? '');
    $class = (string)($opts['class'] ?? 'pack-all-form');
    $btnClass = (string)($opts['button_class'] ?? 'pack-btn');
    $formId = (string)($opts['form_id'] ?? '');
    $buttonOnly = !empty($opts['button_only']);
    $html = '';
    if (!$buttonOnly) {
        $html .= '<form method="post"';
        if ($formId !== '') {
            $html .= ' id="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '"';
        }
        $html .= ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"';
        if ($confirm !== '') {
            $encoded = json_encode(
                $confirm,
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            );
            $html .= ' onsubmit="return confirm(' . htmlspecialchars((string)$encoded, ENT_QUOTES, 'UTF-8') . ')"';
        }
        $html .= '>';
        $html .= function_exists('bakery_csrf_field') ? bakery_csrf_field() : '';
        $html .= '<input type="hidden" name="action" value="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="view" value="' . htmlspecialchars($view, ENT_QUOTES, 'UTF-8') . '">';
        $html .= '<input type="hidden" name="delivery_date" value="' . htmlspecialchars($date, ENT_QUOTES, 'UTF-8') . '">';
        if ($productId > 0) {
            $html .= '<input type="hidden" name="product_id" value="' . $productId . '">';
        }
        if (isset($opts['driver_id'])) {
            $html .= '<input type="hidden" name="board_driver_id" value="' . (int)$opts['driver_id'] . '">';
        }
    }
    $html .= '<button type="submit" class="' . htmlspecialchars($btnClass, ENT_QUOTES, 'UTF-8') . '"';
    if ($buttonOnly && $formId !== '') {
        $html .= ' form="' . htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') . '"';
    }
    $html .= '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>';
    if (!$buttonOnly) {
        $html .= '</form>';
    }
    return $html;
}

/**
 * Packer day board rows: supposed bake/demand vs finished goods on hand.
 *
 * @param list<int>|null $allowedProductIds Null = all products with supposed or stock
 * @return list<array{
 *   product_id:int,
 *   product_name:string,
 *   dough_type:string,
 *   supposed:int,
 *   available:int,
 *   loaded:int,
 *   produced:int,
 *   covered:int,
 *   matches:bool,
 *   short:int,
 *   source:string
 * }>
 */
function bakery_pack_day_count_rows(PDO $db, string $date, ?array $allowedProductIds = null): array
{
    if (!function_exists('bakery_production_produce_targets_by_product')) {
        require_once __DIR__ . '/production_plan.php';
    }
    $targets = bakery_production_produce_targets_by_product($db, $date);
    $supposedByProduct = $targets['by_product'] ?? [];
    $source = (string)($targets['source'] ?? 'demand');

    $availableByProduct = [];
    $loadedByProduct = [];
    $producedByProduct = [];
    if (function_exists('bakery_inventory_ready') && bakery_inventory_ready($db)) {
        $invStmt = $db->prepare(
            'SELECT product_id, available_quantity, produced_quantity, loaded_quantity
             FROM product_inventory_days WHERE delivery_date = ?'
        );
        $invStmt->execute([$date]);
        foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pid = (int)$row['product_id'];
            $availableByProduct[$pid] = (int)$row['available_quantity'];
            $producedByProduct[$pid] = (int)$row['produced_quantity'];
            $loadedByProduct[$pid] = (int)($row['loaded_quantity'] ?? 0);
        }
    }

    $productIds = array_keys($supposedByProduct);
    foreach (array_keys($availableByProduct + $producedByProduct + $loadedByProduct) as $pid) {
        $productIds[] = (int)$pid;
    }
    $productIds = array_values(array_unique(array_map('intval', $productIds)));
    if (is_array($allowedProductIds)) {
        $allowed = array_flip(array_map('intval', $allowedProductIds));
        $productIds = array_values(array_filter($productIds, static function ($pid) use ($allowed) {
            return isset($allowed[(int)$pid]);
        }));
    }
    if ($productIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($productIds), '?'));
    $nameStmt = $db->prepare(
        "SELECT p.id, p.name, COALESCE(dt.name, '') AS dough_type_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         WHERE p.id IN ($placeholders)"
    );
    $nameStmt->execute($productIds);
    $meta = [];
    foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta[(int)$row['id']] = [
            'name' => (string)$row['name'],
            'dough' => (string)$row['dough_type_name'],
        ];
    }

    $rows = [];
    foreach ($productIds as $productId) {
        $supposed = (int)($supposedByProduct[$productId] ?? 0);
        $available = (int)($availableByProduct[$productId] ?? 0);
        $loaded = (int)($loadedByProduct[$productId] ?? 0);
        $produced = (int)($producedByProduct[$productId] ?? 0);
        $covered = $available + $loaded;
        if ($supposed <= 0 && $covered <= 0 && $produced <= 0) {
            continue;
        }
        $rows[] = [
            'product_id' => $productId,
            'product_name' => (string)($meta[$productId]['name'] ?? ('Product #' . $productId)),
            'dough_type' => (string)($meta[$productId]['dough'] ?? ''),
            'supposed' => $supposed,
            'available' => $available,
            'loaded' => $loaded,
            'produced' => $produced,
            'covered' => $covered,
            'matches' => $supposed > 0 && $covered >= $supposed,
            'short' => max(0, $supposed - $covered),
            'source' => $source,
        ];
    }
    usort($rows, static function ($a, $b) {
        return strcasecmp($a['product_name'], $b['product_name']);
    });
    return $rows;
}

function bakery_pack_qty_html(PDO $db, int $productId, int $qty): string
{
    $qty = max(0, $qty);
    $html = '<div class="pack-line__qtywrap"><span class="pack-line__qty">' . number_format($qty) . '</span>';
    if ($qty > 0 && function_exists('bakery_pack_count_breakdown')) {
        $break = bakery_pack_count_breakdown($db, $productId, $qty);
        if (($break['trays'] ?? 0) > 0 || ($break['boxes'] ?? 0) > 0) {
            $html .= '<span class="pack-convert">' . htmlspecialchars((string)$break['label'], ENT_QUOTES, 'UTF-8') . '</span>';
        }
    }
    return $html . '</div>';
}

/**
 * Focus keys for baker phone mode (one product / customer / driver at a time).
 *
 * @param list<array<string,mixed>> $byProduct
 * @param list<array<string,mixed>> $byCustomer
 * @param list<array<string,mixed>> $byRouteList
 * @return list<int>
 */
function bakery_pack_phone_focus_keys(string $viewMode, array $byProduct, array $byCustomer, array $byRouteList): array
{
    switch ($viewMode) {
        case 'product':
            return array_values(array_map(static function ($row): int {
                return (int)($row['product_id'] ?? 0);
            }, $byProduct));
        case 'customer':
            return array_values(array_map(static function ($row): int {
                return (int)($row['customer_id'] ?? 0);
            }, $byCustomer));
        case 'route':
            return array_values(array_map(static function ($row): int {
                return (int)($row['driver_id'] ?? 0);
            }, $byRouteList));
        default:
            return [];
    }
}

/**
 * Resolve current/prev/next focus for baker phone packing.
 *
 * @param list<int> $keys
 * @return array{current:int|null,index:int,prev:int|null,next:int|null,total:int}
 */
function bakery_pack_phone_focus_state(array $keys, $requested): array
{
    $keys = array_values(array_map('intval', $keys));
    $total = count($keys);
    if ($total === 0) {
        return ['current' => null, 'index' => -1, 'prev' => null, 'next' => null, 'total' => 0];
    }
    $requested = is_numeric($requested) ? (int)$requested : null;
    $index = 0;
    if ($requested !== null) {
        $found = array_search($requested, $keys, true);
        if ($found !== false) {
            $index = (int)$found;
        }
    }
    return [
        'current' => $keys[$index],
        'index' => $index,
        'prev' => $index > 0 ? $keys[$index - 1] : null,
        'next' => $index < ($total - 1) ? $keys[$index + 1] : null,
        'total' => $total,
    ];
}

/**
 * Sticky prev/next chrome for baker phone packing.
 *
 * @param array{current:int|null,index:int,prev:int|null,next:int|null,total:int} $state
 */
function bakery_pack_phone_focus_nav_html(array $state, string $date, string $view, array $extraQuery = []): string
{
    if ((int)($state['total'] ?? 0) < 1 || $state['current'] === null) {
        return '';
    }
    $base = ['date' => $date, 'view' => $view];
    foreach ($extraQuery as $k => $v) {
        if ($v !== null && $v !== '') {
            $base[$k] = (string)$v;
        }
    }
    $hrefFor = static function (?int $focus) use ($base): string {
        if ($focus === null) {
            return '#';
        }
        $q = $base;
        $q['focus'] = (string)$focus;
        return 'pack_list.php?' . http_build_query($q);
    };
    $prev = $state['prev'];
    $next = $state['next'];
    $label = bakery_t('pack_list.phone_focus_of', [
        'current' => (string)((int)$state['index'] + 1),
        'total' => (string)(int)$state['total'],
    ]);
    $html = '<nav class="pack-phone-focus" aria-label="' . htmlspecialchars(bakery_t('pack_list.phone_focus_aria'), ENT_QUOTES, 'UTF-8') . '">';
    $html .= '<a class="pack-phone-focus__btn" href="' . htmlspecialchars($hrefFor($prev), ENT_QUOTES, 'UTF-8') . '"'
        . ($prev === null ? ' aria-disabled="true"' : '') . '>'
        . htmlspecialchars(bakery_t('pack_list.phone_prev'), ENT_QUOTES, 'UTF-8') . '</a>';
    $html .= '<span class="pack-phone-focus__meta">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    $html .= '<a class="pack-phone-focus__btn" href="' . htmlspecialchars($hrefFor($next), ENT_QUOTES, 'UTF-8') . '"'
        . ($next === null ? ' aria-disabled="true"' : '') . '>'
        . htmlspecialchars(bakery_t('pack_list.phone_next'), ENT_QUOTES, 'UTF-8') . '</a>';
    $html .= '</nav>';
    return $html;
}
