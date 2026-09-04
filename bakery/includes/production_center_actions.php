<?php
/**
 * Production Center action handlers — pure functions for page and API dispatch.
 */

if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

/**
 * @return array<int, true>
 */
function bakery_production_center_allowed_product_ids(PDO $db): array
{
    $bakerProductIds = function_exists('bakery_baker_product_ids') ? bakery_baker_product_ids($db) : null;
    $productClause = '';
    if (is_array($bakerProductIds)) {
        $productClause = empty($bakerProductIds) ? ' WHERE 1 = 0' : ' WHERE p.id IN (' . implode(',', array_fill(0, count($bakerProductIds), '?')) . ')';
    }
    $productStmt = $db->prepare(
        "SELECT p.id
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         {$productClause}
         ORDER BY dt.name, p.name"
    );
    $productStmt->execute($bakerProductIds ?? []);
    $productIds = array_map(static fn(array $product): int => (int)$product['id'], $productStmt->fetchAll(PDO::FETCH_ASSOC));

    return array_fill_keys($productIds, true);
}

/**
 * @return array{
 *   default_date:string,
 *   selected_date:string,
 *   week_start:string,
 *   week_dates:list<string>,
 *   allowed_product_ids:array<int, true>,
 *   plan_table_ready:bool,
 *   kitchen_note:string
 * }
 */
function bakery_production_center_resolve_context(PDO $db, array $input): array
{
    $defaultDate = date('Y-m-d', strtotime('+1 day'));
    $focus = bakery_production_center_resolve_focus(
        (string)($input['date'] ?? $input['delivery_date'] ?? ''),
        (string)($input['week'] ?? ''),
        $defaultDate
    );

    return [
        'default_date' => $defaultDate,
        'selected_date' => $focus['date'],
        'week_start' => $focus['week_start'],
        'week_dates' => $focus['week_dates'],
        'allowed_product_ids' => bakery_production_center_allowed_product_ids($db),
        'plan_table_ready' => table_exists($db, 'production_plan_items'),
        'kitchen_note' => trim((string)($input['kitchen_note'] ?? '')),
    ];
}

function bakery_production_center_action_save_plan(PDO $db, array $input, ?array $user, array $context, bool $wantsJson): array
{
    if (!$context['plan_table_ready']) {
        throw new RuntimeException('Saved production plans are not installed yet. Run scripts/run_migrations.php first.');
    }

    $selectedDate = $context['selected_date'];
    $allowedProductIds = $context['allowed_product_ids'];
    $planned = $input['planned'] ?? [];
    if (!is_array($planned) || $planned === []) {
        throw new InvalidArgumentException('No changed targets to save. Edit a quantity, then save.');
    }
    foreach (array_keys($planned) as $postedDate) {
        if ($postedDate !== $selectedDate) {
            throw new InvalidArgumentException('A submitted plan item is outside the selected day.');
        }
    }

    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $productId = 0;
    $quantity = 0;

    if ($wantsJson) {
        if (count($planned) !== 1) {
            throw new InvalidArgumentException('Autosave accepts one target at a time.');
        }
        $postedDate = (string)array_key_first($planned);
        $postedProducts = $planned[$postedDate];
        if (!is_array($postedProducts) || count($postedProducts) !== 1) {
            throw new InvalidArgumentException('Autosave accepts one target at a time.');
        }
        $productId = (int)array_key_first($postedProducts);
        $quantity = filter_var($postedProducts[$productId], FILTER_VALIDATE_INT);
        $expectedQuantity = filter_var($input['expected_quantity'] ?? null, FILTER_VALIDATE_INT);
        $expectedHasPlan = (string)($input['expected_has_plan'] ?? '') === '1';
        if ($quantity === false || $expectedQuantity === false) {
            throw new InvalidArgumentException('Batch targets must be whole numbers of zero or more.');
        }
        $result = bakery_production_plan_save_target_cas(
            $db,
            $postedDate,
            $productId,
            (int)$quantity,
            $allowedProductIds,
            $userId,
            $expectedHasPlan,
            (int)$expectedQuantity
        );
        $saved = $result['saved'];
    } else {
        $saved = bakery_production_plan_save_targets($db, $planned, $allowedProductIds, $userId);
    }

    bakery_record_operational_event($db, BAKERY_OP_PRODUCTION_PLAN_SAVED,
        'Saved ' . $saved . ' production target' . ($saved === 1 ? '' : 's') . ' for ' . date('D, M j', strtotime($selectedDate)), [
        'operational_date' => $selectedDate,
        'metadata' => ['targets_saved' => $saved, 'delivery_date' => $selectedDate],
    ]);
    $notice = bakery_t('production_center.autosave_notice', ['count' => $saved]);
    $notice .= ' ' . bakery_t('production_center.save_is_not_commit');

    if ($wantsJson) {
        return [
            'response' => 'json',
            'payload' => [
                'ok' => true,
                'saved' => $saved,
                'notice' => $notice,
                'batch_label' => bakery_pack_batch_label($db, $productId, (int)$quantity),
                'planned_quantity' => (int)$quantity,
            ],
        ];
    }

    return [
        'response' => 'page',
        'notice' => $notice,
    ];
}

function bakery_production_center_action_product_formula(PDO $db, array $input, ?array $user, array $context): array
{
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0 || empty($context['allowed_product_ids'][$productId])) {
        throw new InvalidArgumentException('Unknown product.');
    }
    $pieces = max(0, (int)($input['pieces'] ?? 0));

    return [
        'response' => 'json',
        'payload' => ['ok' => true, 'formula' => bakery_pack_formula_sheet($db, $productId, $pieces)],
    ];
}

function bakery_production_center_action_store_demand(PDO $db, array $input, ?array $user, array $context): array
{
    $selectedDate = $context['selected_date'];
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0 || empty($context['allowed_product_ids'][$productId])) {
        throw new InvalidArgumentException('Unknown product.');
    }

    $pool = max(0, (int)($input['pool'] ?? 0));
    if ($pool <= 0 && table_exists($db, 'production_plan_items')) {
        $poolStmt = $db->prepare(
            'SELECT planned_quantity FROM production_plan_items WHERE delivery_date = ? AND product_id = ? LIMIT 1'
        );
        $poolStmt->execute([$selectedDate, $productId]);
        $pool = max(0, (int)$poolStmt->fetchColumn());
    }

    $customers = bakery_production_store_demand_rows($db, $selectedDate, $productId, $pool);
    $nameStmt = $db->prepare('SELECT name FROM products WHERE id = ? LIMIT 1');
    $nameStmt->execute([$productId]);

    return [
        'response' => 'json',
        'payload' => [
            'ok' => true,
            'date' => $selectedDate,
            'product_id' => $productId,
            'product_name' => (string)$nameStmt->fetchColumn(),
            'customers' => $customers,
        ],
    ];
}

function bakery_production_center_action_save_store_demand(PDO $db, array $input, ?array $user, array $context): array
{
    $selectedDate = $context['selected_date'];
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0 || empty($context['allowed_product_ids'][$productId])) {
        throw new InvalidArgumentException('Unknown product.');
    }

    $pool = max(0, (int)($input['pool'] ?? 0));
    if ($pool <= 0 && table_exists($db, 'production_plan_items')) {
        $poolStmt = $db->prepare(
            'SELECT planned_quantity FROM production_plan_items WHERE delivery_date = ? AND product_id = ? LIMIT 1'
        );
        $poolStmt->execute([$selectedDate, $productId]);
        $pool = max(0, (int)$poolStmt->fetchColumn());
    }

    $customerId = (int)($input['customer_id'] ?? 0);
    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    if ($customerId <= 0 || $quantity === false || $quantity < 0) {
        throw new InvalidArgumentException('Store quantity must be a whole number of zero or more.');
    }

    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $saved = bakery_production_store_demand_save(
        $db,
        $selectedDate,
        $productId,
        $customerId,
        (int)$quantity,
        $userId,
        $pool
    );

    return [
        'response' => 'json',
        'payload' => [
            'ok' => true,
            'customers' => $saved['customers'],
            'saved_quantity' => $saved['quantity'],
            'demand_total' => $saved['demand_total'],
            'customer_id' => $customerId,
            'product_id' => $productId,
            'notice' => bakery_t('production_center.store_demand_saved'),
        ],
    ];
}

function bakery_production_center_action_parse_kitchen_note(PDO $db, array $input, ?array $user, array $context): array
{
    if (!bakery_pack_yields_ready($db)) {
        throw new RuntimeException(bakery_t('pan_dulce.err_pack_not_ready'));
    }
    if ($context['kitchen_note'] === '') {
        throw new InvalidArgumentException(bakery_t('production_center.kitchen_empty'));
    }

    $kitchenParse = bakery_pack_parse_kitchen_note($db, $context['kitchen_note']);
    $routeCapacity = bakery_production_route_desired_vs_bake($db, $context['selected_date'], $kitchenParse['by_product']);

    return [
        'response' => 'page',
        'kitchen_parse' => $kitchenParse,
        'route_capacity' => $routeCapacity,
    ];
}

function bakery_production_center_action_apply_kitchen_note(PDO $db, array $input, ?array $user, array $context): array
{
    if (!bakery_pack_yields_ready($db)) {
        throw new RuntimeException(bakery_t('pan_dulce.err_pack_not_ready'));
    }
    if ($context['kitchen_note'] === '') {
        throw new InvalidArgumentException(bakery_t('production_center.kitchen_empty'));
    }

    $kitchenParse = bakery_pack_parse_kitchen_note($db, $context['kitchen_note']);
    if ($kitchenParse['by_product'] === []) {
        throw new InvalidArgumentException(bakery_t('production_center.kitchen_empty'));
    }
    if (!$context['plan_table_ready']) {
        throw new RuntimeException('Saved production plans are not installed yet. Run scripts/run_migrations.php first.');
    }

    $selectedDate = $context['selected_date'];
    $allowedProductIds = $context['allowed_product_ids'];
    $planQtys = $kitchenParse['by_product'];
    foreach (bakery_pack_kitchen_managed_ids($db) as $pid) {
        if (!isset($planQtys[$pid]) && !empty($allowedProductIds[$pid])) {
            $planQtys[$pid] = 0;
        }
    }
    $planned = [$selectedDate => $planQtys];
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $saved = bakery_production_plan_save_targets($db, $planned, $allowedProductIds, $userId);
    bakery_record_operational_event($db, BAKERY_OP_PRODUCTION_PLAN_SAVED,
        'Saved kitchen-note production targets for ' . date('D, M j', strtotime($selectedDate)), [
        'operational_date' => $selectedDate,
        'metadata' => [
            'targets_saved' => $saved,
            'delivery_date' => $selectedDate,
            'source' => 'kitchen_note',
            'unknown' => $kitchenParse['unknown'],
        ],
    ]);

    return [
        'response' => 'redirect',
        'redirect' => 'production_center.php?date=' . rawurlencode($selectedDate) . '&from_kitchen=1',
    ];
}

function bakery_production_center_action_cut_apply_all(PDO $db, array $input, ?array $user, array $context): array
{
    $cutDate = trim((string)($input['delivery_date'] ?? $input['date'] ?? $context['selected_date']));
    if ($cutDate !== $context['selected_date']) {
        throw new InvalidArgumentException('Cut the day you are viewing.');
    }
    if ($cutDate < date('Y-m-d')) {
        throw new InvalidArgumentException('Cannot cut past deliveries');
    }

    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $result = bakery_production_cut_apply_all_recommended($db, $cutDate, $context['allowed_product_ids'], $userId);
    if ((int)$result['updated'] === 0) {
        $notice = bakery_t('production_center.cut_apply_all_none');
    } else {
        $notice = bakery_t('production_center.cut_apply_all_saved', [
            'products' => (int)$result['products'],
            'count' => (int)$result['updated'],
            'skipped' => (int)$result['skipped'],
        ]);
    }

    return [
        'response' => 'page',
        'notice' => $notice,
    ];
}

function bakery_production_center_action_assign_preview(PDO $db, array $input, ?array $user, array $context): array
{
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0 || empty($context['allowed_product_ids'][$productId])) {
        throw new InvalidArgumentException('Unknown product.');
    }
    $assignDate = trim((string)($input['delivery_date'] ?? $input['date'] ?? $context['selected_date']));
    if ($assignDate !== $context['selected_date']) {
        throw new InvalidArgumentException('Assign the day you are viewing.');
    }
    $pool = max(0, (int)($input['pool'] ?? 0));
    $customers = bakery_production_assign_preview($db, $assignDate, $productId, $pool);
    $demand = 0;
    foreach ($customers as $row) {
        $demand += (int)$row['quantity'];
    }

    return [
        'response' => 'json',
        'payload' => [
            'ok' => true,
            'date' => $assignDate,
            'product_id' => $productId,
            'pool' => $pool,
            'demand' => $demand,
            'customers' => $customers,
        ],
    ];
}

function bakery_production_center_action_cut_preview(PDO $db, array $input, ?array $user, array $context): array
{
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0 || empty($context['allowed_product_ids'][$productId])) {
        throw new InvalidArgumentException('Unknown product.');
    }
    $assignDate = trim((string)($input['delivery_date'] ?? $input['date'] ?? $context['selected_date']));
    if ($assignDate !== $context['selected_date']) {
        throw new InvalidArgumentException('Cut the day you are viewing.');
    }
    $pool = max(0, (int)($input['pool'] ?? 0));
    $customers = bakery_production_cut_preview($db, $assignDate, $productId, $pool);
    $demand = 0;
    foreach ($customers as $row) {
        $demand += (int)$row['quantity'];
    }

    return [
        'response' => 'json',
        'payload' => [
            'ok' => true,
            'date' => $assignDate,
            'product_id' => $productId,
            'pool' => $pool,
            'demand' => $demand,
            'customers' => $customers,
        ],
    ];
}

/**
 * @return list<array{customer_id:int, quantity:int}>
 */
function bakery_production_center_parse_assignments(array $input, string $assignAction): array
{
    $raw = $input['assignments'] ?? [];
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($raw) || $raw === []) {
        throw new InvalidArgumentException($assignAction === 'cut_apply' ? 'No customer quantities to cut.' : 'No customer quantities to assign.');
    }

    $assignments = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $assignments[] = [
            'customer_id' => (int)($row['customer_id'] ?? $row['id'] ?? 0),
            'quantity' => (int)($row['quantity'] ?? 0),
        ];
    }

    return $assignments;
}

function bakery_production_center_action_assign_apply(PDO $db, array $input, ?array $user, array $context): array
{
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0 || empty($context['allowed_product_ids'][$productId])) {
        throw new InvalidArgumentException('Unknown product.');
    }
    $assignDate = trim((string)($input['delivery_date'] ?? $input['date'] ?? $context['selected_date']));
    if ($assignDate !== $context['selected_date']) {
        throw new InvalidArgumentException('Assign the day you are viewing.');
    }

    $assignments = bakery_production_center_parse_assignments($input, 'assign_apply');
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $scope = (string)($input['scope'] ?? 'standing');
    $result = bakery_production_assign_apply(
        $db,
        $assignDate,
        $productId,
        $assignments,
        $scope,
        $userId
    );
    $notice = bakery_t('production_center.assign_saved', [
        'count' => (int)$result['updated'],
        'skipped' => (int)$result['skipped'],
    ]);

    return [
        'response' => 'json',
        'payload' => [
            'ok' => true,
            'result' => $result,
            'notice' => $notice,
        ],
    ];
}

function bakery_production_center_action_cut_apply(PDO $db, array $input, ?array $user, array $context): array
{
    $productId = (int)($input['product_id'] ?? 0);
    if ($productId <= 0 || empty($context['allowed_product_ids'][$productId])) {
        throw new InvalidArgumentException('Unknown product.');
    }
    $assignDate = trim((string)($input['delivery_date'] ?? $input['date'] ?? $context['selected_date']));
    if ($assignDate !== $context['selected_date']) {
        throw new InvalidArgumentException('Cut the day you are viewing.');
    }

    $assignments = bakery_production_center_parse_assignments($input, 'cut_apply');
    $userId = isset($user['id']) ? (int)$user['id'] : null;
    $result = bakery_production_cut_apply($db, $assignDate, $productId, $assignments, $userId);
    $notice = bakery_t('production_center.cut_saved', [
        'count' => (int)$result['updated'],
        'skipped' => (int)$result['skipped'],
    ]);

    return [
        'response' => 'json',
        'payload' => [
            'ok' => true,
            'result' => $result,
            'notice' => $notice,
        ],
    ];
}

function bakery_production_center_action_commit_plan(PDO $db, array $input, ?array $user, array $context): array
{
    $postedFocus = bakery_production_center_resolve_focus('', (string)($input['week'] ?? ''), $context['default_date']);
    if ($postedFocus['week_start'] !== $context['week_start']) {
        throw new InvalidArgumentException('The production week changed. Reload the page and try again.');
    }
    $commitDate = trim((string)($input['delivery_date'] ?? ''));
    if ($commitDate !== $context['selected_date']) {
        throw new InvalidArgumentException('Commit the day you are viewing.');
    }

    $result = bakery_production_plan_commit($db, $commitDate, isset($user['id']) ? (int)$user['id'] : null);
    $notice = bakery_t('production_center.commit_notice', [
        'date' => date('l, M j', strtotime($commitDate)),
        'products' => (int)$result['products_count'],
        'units' => number_format((int)$result['units_count']),
    ]);

    return [
        'response' => 'page',
        'notice' => $notice,
    ];
}

function bakery_production_center_dispatch(PDO $db, array $input, ?array $user = null, array $options = []): array
{
    $action = (string)($input['action'] ?? '');
    $wantsJson = (bool)($options['wants_json'] ?? false);
    $context = bakery_production_center_resolve_context($db, $input);

    if (in_array($action, ['product_formula', 'store_demand', 'save_store_demand'], true)) {
        bakery_require_csrf();
    }

    switch ($action) {
        case 'save_plan':
            return bakery_production_center_action_save_plan($db, $input, $user, $context, $wantsJson);
        case 'product_formula':
            return bakery_production_center_action_product_formula($db, $input, $user, $context);
        case 'store_demand':
            return bakery_production_center_action_store_demand($db, $input, $user, $context);
        case 'save_store_demand':
            return bakery_production_center_action_save_store_demand($db, $input, $user, $context);
        case 'parse_kitchen_note':
            return bakery_production_center_action_parse_kitchen_note($db, $input, $user, $context);
        case 'apply_kitchen_note':
            return bakery_production_center_action_apply_kitchen_note($db, $input, $user, $context);
        case 'cut_apply_all':
            return bakery_production_center_action_cut_apply_all($db, $input, $user, $context);
        case 'assign_preview':
            return bakery_production_center_action_assign_preview($db, $input, $user, $context);
        case 'cut_preview':
            return bakery_production_center_action_cut_preview($db, $input, $user, $context);
        case 'assign_apply':
            return bakery_production_center_action_assign_apply($db, $input, $user, $context);
        case 'cut_apply':
            return bakery_production_center_action_cut_apply($db, $input, $user, $context);
        case 'commit_plan':
            return bakery_production_center_action_commit_plan($db, $input, $user, $context);
        default:
            throw new InvalidArgumentException('Unknown action');
    }
}

function bakery_production_center_format_dispatch_error(Throwable $e, bool $wantsJson): array
{
    $error = bakery_error_message_for_user($e);
    if ($wantsJson && str_starts_with($error, 'production_plan_conflict:')) {
        $current = substr($error, strlen('production_plan_conflict:'));
        return [
            'response' => 'json',
            'http_status' => 409,
            'payload' => [
                'ok' => false,
                'conflict' => true,
                'current_has_plan' => $current !== 'none',
                'current_quantity' => $current === 'none' ? 0 : (int)$current,
                'error' => bakery_t('production_center.autosave_conflict'),
            ],
        ];
    }

    if ($wantsJson) {
        return [
            'response' => 'json',
            'http_status' => 400,
            'payload' => ['ok' => false, 'error' => $error],
        ];
    }

    return [
        'response' => 'page',
        'error' => $error,
    ];
}
