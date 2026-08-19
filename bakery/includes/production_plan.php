<?php
/**
 * Production plan commit — the "Commit Production Plan" ritual.
 *
 * Saving production_plan_items is a draft. Commit copies those quantities into
 * production_plan_commit_items and stamps production_plan_commits for the
 * delivery date. Daily Production bakes the snapshot. Demand stays visible
 * beside it. Dated demand that moves after commit raises plan-drift; it does
 * not rewrite the bake sheet. Re-commit is allowed after review.
 *
 * Runtime-tolerant: when production_plan_commits is missing, lookups report
 * available=false and Daily Run falls back to saved-target coverage.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/demand_review.php';
require_once __DIR__ . '/demand_confirmation.php';
if (file_exists(__DIR__ . '/operational_timeline.php')) {
    require_once __DIR__ . '/operational_timeline.php';
}
if (file_exists(__DIR__ . '/schema_sql.php')) {
    require_once __DIR__ . '/schema_sql.php';
}

function bakery_production_plan_commits_ready(PDO $db): bool
{
    return table_exists($db, 'production_plan_commits');
}

function bakery_production_plan_commit_items_ready(PDO $db): bool
{
    return table_exists($db, 'production_plan_commit_items');
}

/**
 * Create commit tables from the schema file when missing (local/dev convenience;
 * production gets them via scripts/run_migrations.php).
 */
function bakery_production_plan_commits_ensure(PDO $db): void
{
    if (bakery_production_plan_commits_ready($db) && bakery_production_plan_commit_items_ready($db)) {
        return;
    }
    $path = dirname(__DIR__) . '/database/schema/048_production_plan_commits.sql';
    if (function_exists('bakery_run_sql_file_safe')) {
        bakery_run_sql_file_safe($db, $path);
    } elseif (is_readable($path)) {
        $sql = file_get_contents($path);
        if ($sql !== false && trim($sql) !== '') {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                foreach (preg_split('/;\s*(?=CREATE\b)/i', $sql) as $statement) {
                    $statement = trim($statement, " \t\n\r\0\x0B;");
                    if ($statement === '') {
                        continue;
                    }
                    try {
                        $db->exec($statement);
                    } catch (Throwable $ignored) {
                        // Duplicate table during ensure.
                    }
                }
            }
        }
    }
    if (function_exists('bakery_forget_table_exists')) {
        bakery_forget_table_exists('production_plan_commits');
        bakery_forget_table_exists('production_plan_commit_items');
    }
}

/**
 * Fetch the commit header for a date, or null.
 */
function bakery_production_plan_commit_get(PDO $db, string $date): ?array
{
    if (!bakery_production_plan_commits_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('
        SELECT delivery_date, committed_at, committed_by_user_id,
               products_count, units_count
        FROM production_plan_commits
        WHERE delivery_date = ?
        LIMIT 1
    ');
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Saved (draft) plan quantities for a date. Not the baker's bake list until committed.
 *
 * @return array<int,int> product_id => planned_quantity
 */
function bakery_production_plan_draft_quantities(PDO $db, string $date): array
{
    if (!table_exists($db, 'production_plan_items')) {
        return [];
    }
    $stmt = $db->prepare('
        SELECT product_id, planned_quantity
        FROM production_plan_items
        WHERE delivery_date = ?
    ');
    $stmt->execute([$date]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['product_id']] = (int)$row['planned_quantity'];
    }
    return $out;
}

/**
 * Committed bake quantities for a date, or null when the date is not committed.
 *
 * @return array<int,int>|null product_id => committed_quantity
 */
function bakery_production_plan_committed_quantities(PDO $db, string $date): ?array
{
    $commit = bakery_production_plan_commit_get($db, $date);
    if ($commit === null) {
        return null;
    }
    if (bakery_production_plan_commit_items_ready($db)) {
        $stmt = $db->prepare('
            SELECT product_id, committed_quantity
            FROM production_plan_commit_items
            WHERE delivery_date = ?
        ');
        $stmt->execute([$date]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['product_id']] = (int)$row['committed_quantity'];
        }
        return $out;
    }
    // Header exists but snapshot table is missing — use current saved items.
    return bakery_production_plan_draft_quantities($db, $date);
}

/**
 * Demand-affecting events recorded after a plan commit.
 *
 * @return array{count:int, latest:?string, examples:list<string>}
 */
function bakery_production_plan_changes_since(PDO $db, string $date, string $committedAt): array
{
    if (function_exists('bakery_demand_changes_since')) {
        return bakery_demand_changes_since($db, $date, $committedAt);
    }
    return ['count' => 0, 'latest' => null, 'examples' => []];
}

/**
 * Composite commit state for one delivery date.
 *
 * @return array{available:bool, commit:?array, changed_since:array}
 */
function bakery_production_plan_state(PDO $db, string $date): array
{
    $commit = bakery_production_plan_commit_get($db, $date);
    $changedSince = ['count' => 0, 'latest' => null, 'examples' => []];
    if ($commit !== null && !empty($commit['committed_at'])) {
        $changedSince = bakery_production_plan_changes_since($db, $date, (string)$commit['committed_at']);
    }
    return [
        'available' => bakery_production_plan_commits_ready($db),
        'commit' => $commit,
        'changed_since' => $changedSince,
    ];
}

/**
 * Bake-sheet quantities for Daily Production.
 *
 * Uncommitted: bake_quantity is dated demand (saved targets are not truth).
 * Committed: bake_quantity is the committed snapshot; demand stays readable.
 *
 * @return array{
 *   available:bool,
 *   committed:bool,
 *   commit:?array,
 *   changed_since:array,
 *   has_daily:bool,
 *   items:list<array{product_id:int, demand_quantity:int, bake_quantity:int, source:string}>
 * }
 */
function bakery_production_bake_list(PDO $db, string $date): array
{
    $demand = bakery_operating_demand_by_product($db, $date);
    $state = bakery_production_plan_state($db, $date);
    $committedMap = bakery_production_plan_committed_quantities($db, $date);
    $isCommitted = is_array($committedMap);

    $productIds = array_keys($demand['by_product']);
    if ($isCommitted) {
        $productIds = array_values(array_unique(array_merge($productIds, array_keys($committedMap))));
    }

    $items = [];
    foreach ($productIds as $productId) {
        $productId = (int)$productId;
        $demandQty = (int)($demand['by_product'][$productId] ?? 0);
        if ($isCommitted) {
            $bakeQty = (int)($committedMap[$productId] ?? 0);
            $source = 'committed_plan';
        } else {
            $bakeQty = $demandQty;
            $source = 'demand';
        }
        if ($bakeQty <= 0 && $demandQty <= 0) {
            continue;
        }
        $items[] = [
            'product_id' => $productId,
            'demand_quantity' => $demandQty,
            'bake_quantity' => $bakeQty,
            'source' => $source,
        ];
    }

    return [
        'available' => $state['available'],
        'committed' => $isCommitted,
        'commit' => $state['commit'],
        'changed_since' => $state['changed_since'],
        'has_daily' => !empty($demand['has_daily']),
        'items' => $items,
    ];
}

/**
 * Record (or refresh) the manager's production-plan commit for a delivery date.
 *
 * Snapshots current production_plan_items. Does not touch produced_quantity
 * or inventory production movements.
 *
 * @throws RuntimeException
 */
function bakery_production_plan_commit(PDO $db, string $date, ?int $userId): array
{
    bakery_production_plan_commits_ensure($db);
    if (!bakery_production_plan_commits_ready($db)) {
        throw new RuntimeException('Production plan commit is not installed. Run database migrations.');
    }
    if (!table_exists($db, 'production_plan_items')) {
        throw new RuntimeException('Saved production plans are not installed. Run database migrations.');
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new RuntimeException('Invalid delivery date');
    }

    $draft = bakery_production_plan_draft_quantities($db, $date);
    if ($draft === []) {
        throw new RuntimeException('Save production targets for this delivery date before committing.');
    }

    $productsCount = count($draft);
    $unitsCount = (int)array_sum($draft);

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $stmt = $db->prepare('
            INSERT INTO production_plan_commits
                (delivery_date, committed_at, committed_by_user_id, products_count, units_count)
            VALUES (?, NOW(), ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                committed_at = NOW(),
                committed_by_user_id = VALUES(committed_by_user_id),
                products_count = VALUES(products_count),
                units_count = VALUES(units_count)
        ');
        $stmt->execute([$date, $userId, $productsCount, $unitsCount]);

        if (bakery_production_plan_commit_items_ready($db)) {
            $db->prepare('DELETE FROM production_plan_commit_items WHERE delivery_date = ?')->execute([$date]);
            $insertItem = $db->prepare('
                INSERT INTO production_plan_commit_items (delivery_date, product_id, committed_quantity)
                VALUES (?, ?, ?)
            ');
            foreach ($draft as $productId => $qty) {
                $insertItem->execute([$date, (int)$productId, (int)$qty]);
            }
        }

        if ($ownTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_PRODUCTION_PLAN_COMMITTED')) {
        bakery_record_operational_event($db, BAKERY_OP_PRODUCTION_PLAN_COMMITTED, 'Manager committed production plan for ' . $date, [
            'operational_date' => $date,
            'metadata' => [
                'products_count' => $productsCount,
                'units_count' => $unitsCount,
            ],
        ]);
    }

    return [
        'products_count' => $productsCount,
        'units_count' => $unitsCount,
        'delivery_date' => $date,
    ];
}

/**
 * Commit rows for a set of dates (Production Center week strip).
 *
 * @param list<string> $dates
 * @return array<string,array>
 */
function bakery_production_plan_commits_for_dates(PDO $db, array $dates): array
{
    if ($dates === [] || !bakery_production_plan_commits_ready($db)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($dates), '?'));
    $stmt = $db->prepare("
        SELECT delivery_date, committed_at, committed_by_user_id, products_count, units_count
        FROM production_plan_commits
        WHERE delivery_date IN ({$placeholders})
    ");
    $stmt->execute(array_values($dates));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(string)$row['delivery_date']] = $row;
    }
    return $out;
}
