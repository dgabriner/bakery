<?php
/**
 * SF Baker module — shared helpers for the customer-portal baking journal.
 *
 * Self-contained sfb_* tables (starters, formulas, batches, turns, temps,
 * photos). No coupling to wholesale ingredients, formulas, or inventory.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/schema_sql.php';
require_once __DIR__ . '/sfb_origin.php';
require_once __DIR__ . '/sfb_library.php';
require_once __DIR__ . '/sfb_synthetic_eval.php';

/** Loaf milestone the journey tracks toward. */
function bakery_sfb_loaf_goal() {
    return 1000;
}

/** Render a small, keyboard-accessible hint with optional field guidance. */
function bakery_sfb_tip($message) {
    $message = trim((string)$message);
    if ($message === '') {
        return '';
    }

    $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    return ' <span class="sfb-tip" tabindex="0" role="note" aria-label="' . $safe . '" data-tooltip="' . $safe . '">?</span>';
}

/** Whether the SF Baker tables exist (post-migration 032). */
function bakery_sfb_tables_ready(PDO $db) {
    return table_exists($db, 'sfb_batches') && table_exists($db, 'sfb_formulas');
}

/** Whether the immutable formula snapshots introduced in migration 033 exist. */
function bakery_sfb_formula_snapshots_ready(PDO $db) {
    return table_exists($db, 'sfb_batch_formula_snapshots')
        && table_exists($db, 'sfb_batch_formula_snapshot_lines');
}

/** Whether the batch-level baker/admin conversation table is available. */
function bakery_sfb_discussion_ready(PDO $db) {
    return table_exists($db, 'sfb_batch_messages');
}

/** Whether the opt-in SF Baker community forum tables are available. */
function bakery_sfb_community_ready(PDO $db) {
    return table_exists($db, 'sfb_community_topics')
        && table_exists($db, 'sfb_community_replies')
        && table_exists($db, 'sfb_batch_shares');
}

/** Whether the Batch Builder additions from migration 062 are applied. */
function bakery_sfb_builder_ready(PDO $db) {
    return bakery_sfb_formula_snapshots_ready($db)
        && bakery_sfb_discussion_ready($db)
        && column_exists($db, 'sfb_formulas', 'remixed_from_batch_id')
        && column_exists($db, 'sfb_batch_messages', 'phase');
}

/** Phases a batch question or photo can attach to. */
function bakery_sfb_builder_phases() {
    return ['starter', 'mix', 'development', 'shape', 'bake', 'final'];
}

/** Public circle pages: portal bakers and staff coaches share these URLs. */
function bakery_sfb_community_scripts() {
    return [
        'sfb_community.php',
        'sfb_community_topic.php',
        'sfb_shared_batch.php',
    ];
}

/**
 * Community identity is a stored fact. Missing origin must not be guessed.
 */
function bakery_sfb_community_identity_ready(PDO $db) {
    return bakery_sfb_community_ready($db) && bakery_sfb_origin_column_ready($db);
}

function bakery_sfb_community_author_kind_ready(PDO $db, $table = 'sfb_community_topics') {
    $table = (string)$table;
    return column_exists($db, $table, 'author_kind')
        && column_exists($db, $table, 'author_user_id');
}

function bakery_sfb_community_pinned_ready(PDO $db) {
    return column_exists($db, 'sfb_community_topics', 'is_pinned');
}

/** SF Baker customer-portal page scripts (also listed in bakery_customer_portal_scripts). */
function bakery_sfb_portal_scripts() {
    return [
        'sfb_dashboard.php',
        'sfb_starters.php',
        'sfb_ingredients.php',
        'sfb_formulas.php',
        'sfb_batches.php',
        'sfb_batch.php',
        'sfb_resources.php',
        'sfb_community.php',
        'sfb_community_topic.php',
        'sfb_shared_batch.php',
    ];
}

/**
 * Idempotent schema ensure for pre-migration deploys (mirrors bakery_ensure_portal_schema).
 */
function bakery_ensure_sfb_schema(PDO $db) {
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('bakery_runtime_schema_ddl_allowed') || !bakery_runtime_schema_ddl_allowed()) {
        $done = true;
        return;
    }
    if (!table_exists($db, 'customers')) {
        return;
    }

    if (!column_exists($db, 'customers', 'sf_baker_enabled')) {
        try {
            $db->exec(
                'ALTER TABLE customers
                 ADD COLUMN sf_baker_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER portal_enabled'
            );
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('customers', 'sf_baker_enabled');
            }
        } catch (Throwable $e) {
            // Column may have been added concurrently.
        }
    }

    if (!column_exists($db, 'customers', 'sfb_origin')) {
        try {
            $after = column_exists($db, 'customers', 'sf_baker_enabled')
                ? ' AFTER sf_baker_enabled'
                : '';
            $db->exec(
                "ALTER TABLE customers
                 ADD COLUMN sfb_origin ENUM('human','synthetic') NOT NULL DEFAULT 'human'{$after}"
            );
            if (function_exists('bakery_forget_column_exists')) {
                bakery_forget_column_exists('customers', 'sfb_origin');
            }
        } catch (Throwable $e) {
            // Column may have been added concurrently.
        }
    }

    if (!bakery_sfb_tables_ready($db)) {
        $schema = dirname(__DIR__) . '/database/schema/032_sf_baker.sql';
        bakery_run_sql_file_safe($db, $schema);
        foreach ([
            'sfb_ingredients', 'sfb_starters', 'sfb_starter_feedings', 'sfb_formulas',
            'sfb_formula_ingredients', 'sfb_batches', 'sfb_batch_turns', 'sfb_batch_temps',
            'sfb_batch_photos',
        ] as $table) {
            bakery_forget_table_exists($table);
        }
    }

    if (bakery_sfb_tables_ready($db) && !bakery_sfb_formula_snapshots_ready($db)) {
        $schema = dirname(__DIR__) . '/database/schema/033_sfb_batch_formula_snapshots.sql';
        bakery_run_sql_file_safe($db, $schema);
        bakery_forget_table_exists('sfb_batch_formula_snapshots');
        bakery_forget_table_exists('sfb_batch_formula_snapshot_lines');
    }

    if (bakery_sfb_tables_ready($db) && !bakery_sfb_discussion_ready($db)) {
        $schema = dirname(__DIR__) . '/database/schema/034_sfb_batch_messages.sql';
        bakery_run_sql_file_safe($db, $schema);
        bakery_forget_table_exists('sfb_batch_messages');
    }

    if (bakery_sfb_tables_ready($db) && !bakery_sfb_community_ready($db)) {
        $schema = dirname(__DIR__) . '/database/schema/035_sfb_community.sql';
        bakery_run_sql_file_safe($db, $schema);
        foreach (['sfb_community_topics', 'sfb_community_replies', 'sfb_batch_shares'] as $table) {
            bakery_forget_table_exists($table);
        }
    }

    $done = true;
}

/**
 * The logged-in portal customer with the SF Baker flag, or null.
 * Cached per request.
 */
function bakery_sfb_customer(PDO $db) {
    static $cache = null;
    if ($cache !== null) {
        return $cache ?: null;
    }
    bakery_ensure_sfb_schema($db);
    $id = bakery_portal_customer_id();
    if ($id <= 0 || !column_exists($db, 'customers', 'sf_baker_enabled')) {
        $cache = false;
        return null;
    }
    $originSelect = bakery_sfb_origin_column_ready($db) ? ', sfb_origin' : '';
    $stmt = $db->prepare(
        "SELECT id, name, sf_baker_enabled{$originSelect}
         FROM customers WHERE id = ? AND portal_enabled = 1 AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['sf_baker_enabled'] !== 1) {
        $cache = false;
        return null;
    }
    $cache = $row;
    return $row;
}

function bakery_sfb_enabled(PDO $db) {
    return bakery_sfb_customer($db) !== null;
}

/**
 * Gate for every SF Baker page: portal login + sf_baker flag + schema ready.
 *
 * @return array Customer row (id, name, sf_baker_enabled)
 */
function bakery_sfb_require_access(PDO $db) {
    bakery_ensure_sfb_schema($db);

    if (!function_exists('bakery_portal_require_customer')) {
        require_once __DIR__ . '/customer_portal.php';
    }
    bakery_portal_require_customer($db);

    $customer = bakery_sfb_customer($db);
    if (!$customer) {
        if (function_exists('is_ajax_request') && (is_ajax_request() || bakery_wants_json())) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'SF Baker access is not enabled for this account']);
            exit;
        }
        header('Location: ' . BASE_URL . 'customer_portal.php');
        exit;
    }

    if (!bakery_sfb_tables_ready($db)
        || !bakery_sfb_formula_snapshots_ready($db)
        || !bakery_sfb_discussion_ready($db)) {
        bakery_sfb_render_not_ready();
        exit;
    }

    return $customer;
}

/** Friendly message when the SF Baker database schema is not present yet. */
function bakery_sfb_render_not_ready() {
    $ddlBlocked = function_exists('bakery_runtime_schema_ddl_allowed') && !bakery_runtime_schema_ddl_allowed();
    if (function_exists('is_ajax_request') && (is_ajax_request() || bakery_wants_json())) {
        http_response_code(503);
        header('Content-Type: application/json');
        $msg = $ddlBlocked
            ? 'SF Baker tables are not on this database yet. Run: php scripts/run_migrations.php with USE_PROD_DB=true (local) or run migrations on the server.'
            : 'SF Baker is not set up on this server yet. Please ask Sour Flour to run the database migration.';
        echo json_encode(['success' => false, 'error' => $msg]);
        exit;
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $home = htmlspecialchars(BASE_URL . 'customer_portal.php', ENT_QUOTES, 'UTF-8');
    $body = $ddlBlocked
        ? '<p>Your app is pointed at the <strong>production</strong> database from local dev, and the SF Baker tables are not there yet.</p>'
            . '<p>From the <code>bakery</code> folder, run:</p>'
            . '<pre style="background:#faf6f1;padding:12px;border-radius:8px;overflow:auto">$env:USE_PROD_DB=\'true\'; php scripts/run_migrations.php</pre>'
            . '<p>Or set <code>USE_PROD_DB=false</code> in <code>.env</code> to use your local database for testing.</p>'
        : '<p>The baking journal needs a one-time database update on this server. '
            . 'Your portal login is fine — staff can run <code>php scripts/run_migrations.php</code> on the server, then refresh this page.</p>';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>SF Baker</title></head><body style="font-family:Georgia,serif;background:#fffdf8;color:#33251f;padding:24px;max-width:520px;margin:40px auto;">'
        . '<h1 style="font-weight:normal">SF Baker is almost ready</h1>'
        . $body
        . '<p><a href="' . $home . '">Back to portal home</a></p></body></html>';
}

/** Fail closed when origin cannot be labeled. Do not guess Real vs Synthetic. */
function bakery_sfb_render_origin_missing() {
    $title = function_exists('bakery_t') ? bakery_t('sfb.community_origin_missing_title') : 'Baker identity is not ready';
    $body = function_exists('bakery_t') ? bakery_t('sfb.community_origin_missing') : 'The baker community cannot open until customers.sfb_origin is installed. Origin is a stored fact and cannot be guessed.';
    $home = htmlspecialchars((defined('BASE_URL') ? BASE_URL : '') . 'customer_portal.php', ENT_QUOTES, 'UTF-8');
    if (function_exists('is_ajax_request') && (is_ajax_request() || bakery_wants_json())) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $body]);
        exit;
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title></head>'
        . '<body style="font-family:Georgia,serif;background:#fffdf8;color:#33251f;padding:24px;max-width:520px;margin:40px auto;">'
        . '<h1 style="font-weight:normal">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p><a href="' . $home . '">Back to portal home</a></p></body></html>';
}

/**
 * Gate for public circle pages: SF Baker portal customer and/or administrator.
 *
 * @return array{customer:?array,staff:?array,can_post_as_baker:bool,can_reply_as_coach:bool,coach_user_id:int}
 */
function bakery_sfb_require_community_access(PDO $db) {
    bakery_ensure_sfb_schema($db);

    if (!bakery_sfb_community_ready($db)
        || !bakery_sfb_tables_ready($db)
        || !bakery_sfb_formula_snapshots_ready($db)
        || !bakery_sfb_discussion_ready($db)) {
        bakery_sfb_render_not_ready();
        exit;
    }
    if (!bakery_sfb_origin_column_ready($db)) {
        bakery_sfb_render_origin_missing();
        exit;
    }

    $staff = function_exists('bakery_current_user') ? bakery_current_user() : null;
    $isAdmin = is_array($staff) && (($staff['role_slug'] ?? '') === 'administrator');
    $impersonatorId = (int)($_SESSION['sfb_impersonator_user_id'] ?? 0);
    $customer = bakery_sfb_customer($db);

    if (!$customer && !$isAdmin && $impersonatorId <= 0) {
        return [
            'customer' => bakery_sfb_require_access($db),
            'staff' => null,
            'can_post_as_baker' => true,
            'can_reply_as_coach' => false,
            'coach_user_id' => 0,
        ];
    }
    if (!$customer && !$isAdmin) {
        bakery_sfb_require_access($db);
        exit;
    }

    $coachUserId = $isAdmin ? (int)($staff['id'] ?? 0) : $impersonatorId;
    $canCoach = $coachUserId > 0 && bakery_sfb_community_author_kind_ready($db, 'sfb_community_replies');

    return [
        'customer' => $customer,
        'staff' => $isAdmin ? $staff : null,
        'can_post_as_baker' => $customer && $impersonatorId <= 0,
        'can_reply_as_coach' => $canCoach,
        'coach_user_id' => $canCoach ? $coachUserId : 0,
    ];
}

/** Parse a datetime-local form value to 'Y-m-d H:i:s', or null when blank/invalid. */
function bakery_sfb_parse_datetime($input) {
    $input = trim((string)$input);
    if ($input === '') {
        return null;
    }
    $ts = strtotime($input);
    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
}

/** Format a stored datetime for a datetime-local input value. */
function bakery_sfb_datetime_local_value($stored) {
    if (empty($stored)) {
        return '';
    }
    $ts = strtotime((string)$stored);
    return $ts === false ? '' : date('Y-m-d\TH:i', $ts);
}

/** Current time as a datetime-local input value (for prefilled forms). */
function bakery_sfb_now_local_value() {
    return date('Y-m-d\TH:i');
}

/* ── Starters ─────────────────────────────────────────────────────────── */

function bakery_sfb_starters(PDO $db, $customerId, $activeOnly = true) {
    if (!bakery_sfb_tables_ready($db)) {
        return [];
    }
    $sql = 'SELECT id, name, flour_blend, hydration_pct, is_active, notes, created_at
            FROM sfb_starters WHERE customer_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, name';
    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$customerId]);
    return $stmt->fetchAll();
}

function bakery_sfb_starter(PDO $db, $customerId, $starterId) {
    if (!table_exists($db, 'sfb_starters')) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, customer_id, name, flour_blend, hydration_pct, is_active, notes, created_at
         FROM sfb_starters WHERE id = ? AND customer_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$starterId, (int)$customerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_starter_feedings(PDO $db, $starterId, $limit = 50) {
    if (!table_exists($db, 'sfb_starter_feedings')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, fed_at, starter_g, flour_g, water_g, peak_notes, notes
         FROM sfb_starter_feedings WHERE starter_id = ? ORDER BY fed_at DESC, id DESC LIMIT ' . max(1, (int)$limit)
    );
    $stmt->execute([(int)$starterId]);
    return $stmt->fetchAll();
}

/** Create a starter for this baker. */
function bakery_sfb_create_starter(PDO $db, $customerId, $name, $flourBlend = '', $hydrationPct = 100, $notes = '') {
    $customerId = (int)$customerId;
    $name = trim((string)$name);
    if ($customerId <= 0 || $name === '') {
        throw new InvalidArgumentException('Starter name is required');
    }
    if (strlen($name) > 100) {
        throw new InvalidArgumentException('Starter name must be 100 characters or fewer');
    }
    $hydration = (float)$hydrationPct;
    if ($hydration <= 0 || $hydration > 300) {
        throw new InvalidArgumentException('Hydration must be between 1 and 300');
    }
    $blend = trim((string)$flourBlend);
    $notes = trim((string)$notes);
    $stmt = $db->prepare(
        'INSERT INTO sfb_starters (customer_id, name, flour_blend, hydration_pct, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $customerId,
        $name,
        $blend !== '' ? $blend : null,
        $hydration,
        $notes !== '' ? $notes : null,
    ]);
    return (int)$db->lastInsertId();
}

/** Active starter, or the first starter, or null. */
function bakery_sfb_default_starter(PDO $db, $customerId) {
    $starters = bakery_sfb_starters($db, $customerId, true);
    if ($starters) {
        return $starters[0];
    }
    $all = bakery_sfb_starters($db, $customerId, false);
    return $all ? $all[0] : null;
}

/**
 * Find a starter by id or name, or create one when missing.
 *
 * @return array Starter row
 */
function bakery_sfb_ensure_starter(PDO $db, $customerId, $name = '', $flourBlend = '', $hydrationPct = 100, $notes = '') {
    $customerId = (int)$customerId;
    $name = trim((string)$name);
    if ($name !== '' && ctype_digit($name)) {
        $row = bakery_sfb_starter($db, $customerId, (int)$name);
        if ($row) {
            return $row;
        }
    }
    if ($name !== '') {
        $stmt = $db->prepare(
            'SELECT id, customer_id, name, flour_blend, hydration_pct, is_active, notes, created_at
             FROM sfb_starters WHERE customer_id = ? AND name = ? LIMIT 1'
        );
        $stmt->execute([$customerId, $name]);
        $row = $stmt->fetch();
        if ($row) {
            return $row;
        }
        $id = bakery_sfb_create_starter($db, $customerId, $name, $flourBlend, $hydrationPct, $notes);
        return bakery_sfb_starter($db, $customerId, $id);
    }
    $existing = bakery_sfb_default_starter($db, $customerId);
    if ($existing) {
        return $existing;
    }
    $id = bakery_sfb_create_starter($db, $customerId, 'Home starter', $flourBlend ?: 'bread flour', $hydrationPct, $notes);
    return bakery_sfb_starter($db, $customerId, $id);
}

function bakery_sfb_add_starter_feeding(
    PDO $db,
    $customerId,
    $starterId,
    $starterG,
    $flourG,
    $waterG,
    $fedAt = '',
    $peakNotes = '',
    $notes = ''
) {
    $starter = bakery_sfb_starter($db, $customerId, $starterId);
    if (!$starter) {
        throw new InvalidArgumentException('Starter not found');
    }
    $fedAt = bakery_sfb_parse_datetime($fedAt) ?: date('Y-m-d H:i:s');
    $starterG = max(0, (float)$starterG);
    $flourG = max(0, (float)$flourG);
    $waterG = max(0, (float)$waterG);
    if ($starterG <= 0 && $flourG <= 0 && $waterG <= 0) {
        throw new InvalidArgumentException('Enter at least one gram amount for the feeding');
    }
    $peakNotes = trim((string)$peakNotes);
    $notes = trim((string)$notes);
    $stmt = $db->prepare(
        'INSERT INTO sfb_starter_feedings (starter_id, fed_at, starter_g, flour_g, water_g, peak_notes, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$starter['id'],
        $fedAt,
        $starterG,
        $flourG,
        $waterG,
        $peakNotes !== '' ? $peakNotes : null,
        $notes !== '' ? $notes : null,
    ]);
    return (int)$db->lastInsertId();
}

/** Feeding ratio like "1:2:2" (starter : flour : water), normalized to starter. */
function bakery_sfb_feeding_ratio(array $feeding) {
    $starter = (float)($feeding['starter_g'] ?? 0);
    $flour = (float)($feeding['flour_g'] ?? 0);
    $water = (float)($feeding['water_g'] ?? 0);
    if ($starter <= 0) {
        return '—';
    }
    $fmt = function ($v) {
        $r = round($v, 1);
        return ($r == (int)$r) ? (string)(int)$r : (string)$r;
    };
    return '1:' . $fmt($flour / $starter) . ':' . $fmt($water / $starter);
}

/* ── Ingredients ──────────────────────────────────────────────────────── */

/** Valid ingredient categories (matches the standard library seed). */
function bakery_sfb_ingredient_categories() {
    return [
        'flour' => 'Flour',
        'water' => 'Water',
        'salt' => 'Salt',
        'starter' => 'Starter',
        'fat' => 'Fat',
        'sweetener' => 'Sweetener',
        'leavener' => 'Leavener',
        'other' => 'Other',
    ];
}

function bakery_sfb_ingredient_category_label($category) {
    $categories = bakery_sfb_ingredient_categories();
    return $categories[$category] ?? ucfirst(str_replace('_', ' ', (string)$category));
}

/** Standard library entries plus the customer's own ingredients. */
function bakery_sfb_ingredient_options(PDO $db, $customerId) {
    if (!table_exists($db, 'sfb_ingredients')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, name, category, customer_id
         FROM sfb_ingredients
         WHERE is_active = 1 AND (customer_id IS NULL OR customer_id = ?)
         ORDER BY category, name'
    );
    $stmt->execute([(int)$customerId]);
    return $stmt->fetchAll();
}

/** A customer's own custom ingredients (active and retired). */
function bakery_sfb_custom_ingredients(PDO $db, $customerId, $activeOnly = false) {
    if (!table_exists($db, 'sfb_ingredients')) {
        return [];
    }
    $sql = 'SELECT id, name, category, is_active, created_at
            FROM sfb_ingredients WHERE customer_id = ?';
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY is_active DESC, category, name';
    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$customerId]);
    return $stmt->fetchAll();
}

/** Load a customer-owned ingredient, or null when not found / not owned. */
function bakery_sfb_ingredient(PDO $db, $customerId, $ingredientId) {
    if (!table_exists($db, 'sfb_ingredients')) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, customer_id, name, category, is_active, created_at
         FROM sfb_ingredients WHERE id = ? AND customer_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$ingredientId, (int)$customerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_ingredient_in_use(PDO $db, $ingredientId) {
    if (!table_exists($db, 'sfb_formula_ingredients')) {
        return false;
    }
    $stmt = $db->prepare('SELECT COUNT(*) FROM sfb_formula_ingredients WHERE ingredient_id = ?');
    $stmt->execute([(int)$ingredientId]);
    return (int)$stmt->fetchColumn() > 0;
}

/** Create a custom ingredient for the current baker. */
function bakery_sfb_create_ingredient(PDO $db, $customerId, $name, $category) {
    $customerId = (int)$customerId;
    $name = trim((string)$name);
    if ($name === '') {
        throw new InvalidArgumentException('Ingredient name is required');
    }
    if (strlen($name) > 100) {
        throw new InvalidArgumentException('Ingredient name must be 100 characters or fewer');
    }
    $categories = bakery_sfb_ingredient_categories();
    $category = (string)$category;
    if (!isset($categories[$category])) {
        $category = 'other';
    }

    $dup = $db->prepare(
        'SELECT id FROM sfb_ingredients
         WHERE customer_id = ? AND LOWER(name) = LOWER(?) AND is_active = 1 LIMIT 1'
    );
    $dup->execute([$customerId, $name]);
    if ($dup->fetch()) {
        throw new InvalidArgumentException('You already have an ingredient with that name');
    }

    $stmt = $db->prepare(
        'INSERT INTO sfb_ingredients (customer_id, name, category) VALUES (?, ?, ?)'
    );
    $stmt->execute([$customerId, $name, $category]);
    return (int)$db->lastInsertId();
}

/** Update a customer-owned ingredient. */
function bakery_sfb_update_ingredient(PDO $db, $customerId, $ingredientId, $name, $category) {
    $ingredient = bakery_sfb_ingredient($db, $customerId, $ingredientId);
    if (!$ingredient) {
        throw new InvalidArgumentException('Ingredient not found');
    }
    $name = trim((string)$name);
    if ($name === '') {
        throw new InvalidArgumentException('Ingredient name is required');
    }
    if (strlen($name) > 100) {
        throw new InvalidArgumentException('Ingredient name must be 100 characters or fewer');
    }
    $categories = bakery_sfb_ingredient_categories();
    $category = (string)$category;
    if (!isset($categories[$category])) {
        $category = 'other';
    }

    $dup = $db->prepare(
        'SELECT id FROM sfb_ingredients
         WHERE customer_id = ? AND LOWER(name) = LOWER(?) AND is_active = 1 AND id <> ? LIMIT 1'
    );
    $dup->execute([(int)$customerId, $name, (int)$ingredient['id']]);
    if ($dup->fetch()) {
        throw new InvalidArgumentException('You already have an ingredient with that name');
    }

    $stmt = $db->prepare('UPDATE sfb_ingredients SET name = ?, category = ? WHERE id = ?');
    $stmt->execute([$name, $category, (int)$ingredient['id']]);
    return (int)$ingredient['id'];
}

/** Soft-retire or reactivate a customer-owned ingredient. */
function bakery_sfb_toggle_ingredient(PDO $db, $customerId, $ingredientId) {
    $ingredient = bakery_sfb_ingredient($db, $customerId, $ingredientId);
    if (!$ingredient) {
        throw new InvalidArgumentException('Ingredient not found');
    }
    $next = (int)$ingredient['is_active'] === 1 ? 0 : 1;
    $db->prepare('UPDATE sfb_ingredients SET is_active = ? WHERE id = ?')
        ->execute([$next, (int)$ingredient['id']]);
    return (int)$ingredient['id'];
}

/* ── Formulas ─────────────────────────────────────────────────────────── */

function bakery_sfb_formulas(PDO $db, $customerId) {
    if (!table_exists($db, 'sfb_formulas')) {
        return [];
    }
    $remixColumn = column_exists($db, 'sfb_formulas', 'remixed_from_batch_id') ? ', remixed_from_batch_id' : '';
    $stmt = $db->prepare(
        'SELECT id, name, description, target_dough_g, notes, is_template, updated_at' . $remixColumn . '
         FROM sfb_formulas WHERE customer_id = ? ORDER BY updated_at DESC, id DESC'
    );
    $stmt->execute([(int)$customerId]);
    return $stmt->fetchAll();
}

function bakery_sfb_templates(PDO $db) {
    if (!table_exists($db, 'sfb_formulas')) {
        return [];
    }
    $stmt = $db->query(
        'SELECT id, name, description, target_dough_g
         FROM sfb_formulas WHERE is_template = 1 AND customer_id IS NULL ORDER BY id'
    );
    return $stmt->fetchAll();
}

/** Resolve a standard formula template by id or exact name. */
function bakery_sfb_template(PDO $db, $nameOrId) {
    $templates = bakery_sfb_templates($db);
    if (is_numeric($nameOrId) && (int)$nameOrId > 0 && (string)(int)$nameOrId === (string)$nameOrId) {
        foreach ($templates as $template) {
            if ((int)$template['id'] === (int)$nameOrId) {
                return $template;
            }
        }
    }
    $want = strtolower(trim((string)$nameOrId));
    if ($want === '') {
        return $templates ? $templates[0] : null;
    }
    foreach ($templates as $template) {
        if (strtolower((string)$template['name']) === $want) {
            return $template;
        }
    }
    return null;
}

/** Load a formula the customer may view: their own, or a shared template. */
function bakery_sfb_formula(PDO $db, $customerId, $formulaId) {
    if (!table_exists($db, 'sfb_formulas')) {
        return null;
    }
    $remixColumn = column_exists($db, 'sfb_formulas', 'remixed_from_batch_id') ? ', remixed_from_batch_id' : '';
    $stmt = $db->prepare(
        'SELECT id, customer_id, name, description, target_dough_g, is_template, notes, updated_at' . $remixColumn . '
         FROM sfb_formulas
         WHERE id = ? AND (customer_id = ? OR (customer_id IS NULL AND is_template = 1))
         LIMIT 1'
    );
    $stmt->execute([(int)$formulaId, (int)$customerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_formula_lines(PDO $db, $formulaId) {
    if (!table_exists($db, 'sfb_formula_ingredients')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT fi.id, fi.ingredient_id, fi.starter_id, fi.percentage, fi.sort_order,
                COALESCE(i.name, s.name) AS line_name,
                CASE WHEN fi.starter_id IS NOT NULL THEN "starter" ELSE COALESCE(i.category, "other") END AS line_kind
         FROM sfb_formula_ingredients fi
         LEFT JOIN sfb_ingredients i ON i.id = fi.ingredient_id
         LEFT JOIN sfb_starters s ON s.id = fi.starter_id
         WHERE fi.formula_id = ?
         ORDER BY fi.sort_order, fi.id'
    );
    $stmt->execute([(int)$formulaId]);
    return $stmt->fetchAll();
}

function bakery_sfb_formula_total_pct(array $lines) {
    $total = 0.0;
    foreach ($lines as $line) {
        $total += (float)$line['percentage'];
    }
    return $total;
}

/**
 * Baker's-math gram breakdown for a target dough weight.
 * Same algebra as the wholesale planner:
 *   flour_base = target_dough_g / (SUM(percentages) / 100)
 *   line grams = flour_base × (line percentage / 100)
 *
 * @return array{lines: array, total_pct: float, flour_g: float}
 */
function bakery_sfb_formula_grams(array $lines, $targetDoughG) {
    $targetDoughG = (float)$targetDoughG;
    $totalPct = bakery_sfb_formula_total_pct($lines);
    $flourG = $totalPct > 0 ? $targetDoughG / ($totalPct / 100.0) : 0.0;
    $out = [];
    foreach ($lines as $line) {
        $line['grams'] = $totalPct > 0 ? round($flourG * ((float)$line['percentage'] / 100.0), 1) : 0.0;
        $out[] = $line;
    }
    return ['lines' => $out, 'total_pct' => $totalPct, 'flour_g' => round($flourG, 1)];
}

/** Copy a shared standard formula into a customer-owned editable formula. */
function bakery_sfb_copy_template(PDO $db, $customerId, $templateId) {
    $customerId = (int)$customerId;
    $stmt = $db->prepare(
        'SELECT id, name, description, target_dough_g
         FROM sfb_formulas WHERE id = ? AND is_template = 1 AND customer_id IS NULL LIMIT 1'
    );
    $stmt->execute([(int)$templateId]);
    $template = $stmt->fetch();
    if (!$template) {
        throw new InvalidArgumentException('Standard formula not found');
    }

    $db->beginTransaction();
    try {
        $ins = $db->prepare(
            'INSERT INTO sfb_formulas (customer_id, name, description, target_dough_g, is_template)
             VALUES (?, ?, ?, ?, 0)'
        );
        $ins->execute([$customerId, $template['name'], $template['description'], $template['target_dough_g']]);
        $newId = (int)$db->lastInsertId();

        $copy = $db->prepare(
            'INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, starter_id, percentage, sort_order)
             SELECT ?, ingredient_id, starter_id, percentage, sort_order
             FROM sfb_formula_ingredients WHERE formula_id = ?'
        );
        $copy->execute([$newId, (int)$template['id']]);
        $db->commit();
        return $newId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* ── Batches ──────────────────────────────────────────────────────────── */

/**
 * Store the formula a batch actually used. Snapshot rows deliberately do not
 * reference mutable formula, ingredient, or starter records.
 */
function bakery_sfb_capture_batch_formula_snapshot(PDO $db, $batchId, array $formula) {
    if (!bakery_sfb_formula_snapshots_ready($db)) {
        throw new RuntimeException('The SF Baker formula snapshot migration has not been applied');
    }

    $batchId = (int)$batchId;
    $formulaId = (int)($formula['id'] ?? 0);
    if ($batchId <= 0 || $formulaId <= 0) {
        throw new InvalidArgumentException('A batch and formula are required for a formula snapshot');
    }

    $snapshot = $db->prepare(
        'INSERT INTO sfb_batch_formula_snapshots
         (batch_id, source_formula_id, formula_name, description, target_dough_g, source_updated_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $snapshot->execute([
        $batchId,
        $formulaId,
        (string)$formula['name'],
        $formula['description'] !== '' ? $formula['description'] : null,
        $formula['target_dough_g'],
        $formula['updated_at'] ?? null,
    ]);

    $lineInsert = $db->prepare(
        'INSERT INTO sfb_batch_formula_snapshot_lines
         (batch_id, line_name, line_kind, percentage, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach (bakery_sfb_formula_lines($db, $formulaId) as $line) {
        $lineInsert->execute([
            $batchId,
            (string)($line['line_name'] ?? 'Formula line'),
            (string)($line['line_kind'] ?? 'other'),
            (float)$line['percentage'],
            (int)$line['sort_order'],
        ]);
    }
}

/**
 * Start a batch and capture its exact formula atomically. Only a formula
 * owned by the current customer may be used for a new batch.
 */
function bakery_sfb_start_batch(PDO $db, $customerId, $formulaId, $name, $startedAt) {
    $customerId = (int)$customerId;
    $formula = bakery_sfb_formula($db, $customerId, $formulaId);
    if (!$formula || (int)$formula['customer_id'] !== $customerId) {
        throw new InvalidArgumentException('Choose one of your formulas for this batch');
    }
    if (!bakery_sfb_formula_snapshots_ready($db)) {
        throw new RuntimeException('SF Baker needs a database update before starting new batches');
    }

    $name = trim((string)$name);
    if ($name === '') {
        $name = $formula['name'] . ' — ' . date('M j');
    }
    if (strlen($name) > 120) {
        throw new InvalidArgumentException('Batch name must be 120 characters or fewer');
    }
    $startedAt = bakery_sfb_parse_datetime($startedAt) ?: date('Y-m-d H:i:s');

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $stmt = $db->prepare(
            'INSERT INTO sfb_batches (customer_id, formula_id, name, started_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$customerId, (int)$formula['id'], $name, $startedAt]);
        $batchId = (int)$db->lastInsertId();
        bakery_sfb_capture_batch_formula_snapshot($db, $batchId, $formula);
        if ($ownsTransaction) {
            $db->commit();
        }
        return $batchId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function bakery_sfb_batches(PDO $db, $customerId, $limit = 50) {
    if (!table_exists($db, 'sfb_batches')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT b.*, COALESCE(s.formula_name, f.name) AS formula_name
         FROM sfb_batches b
         LEFT JOIN sfb_formulas f ON f.id = b.formula_id
         LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
         WHERE b.customer_id = ?
         ORDER BY b.started_at DESC, b.id DESC LIMIT ' . max(1, (int)$limit)
    );
    $stmt->execute([(int)$customerId]);
    return $stmt->fetchAll();
}

function bakery_sfb_batch(PDO $db, $customerId, $batchId) {
    if (!table_exists($db, 'sfb_batches')) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT b.*, COALESCE(s.formula_name, f.name) AS formula_name
         FROM sfb_batches b
         LEFT JOIN sfb_formulas f ON f.id = b.formula_id
         LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
         WHERE b.id = ? AND b.customer_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$batchId, (int)$customerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_active_batch(PDO $db, $customerId) {
    if (!table_exists($db, 'sfb_batches')) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT b.*, COALESCE(s.formula_name, f.name) AS formula_name
         FROM sfb_batches b
         LEFT JOIN sfb_formulas f ON f.id = b.formula_id
         LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
         WHERE b.customer_id = ? AND b.status = "in_progress"
         ORDER BY b.started_at DESC, b.id DESC LIMIT 1'
    );
    $stmt->execute([(int)$customerId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The immutable formula metadata captured when a batch was started. */
function bakery_sfb_batch_formula_snapshot(PDO $db, $batchId) {
    if (!bakery_sfb_formula_snapshots_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT batch_id, source_formula_id, formula_name, description, target_dough_g, source_updated_at, captured_at
         FROM sfb_batch_formula_snapshots WHERE batch_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$batchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** The immutable formula lines captured when a batch was started. */
function bakery_sfb_batch_formula_snapshot_lines(PDO $db, $batchId) {
    if (!bakery_sfb_formula_snapshots_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, line_name, line_kind, percentage, sort_order
         FROM sfb_batch_formula_snapshot_lines
         WHERE batch_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([(int)$batchId]);
    return $stmt->fetchAll();
}

function bakery_sfb_batch_turns(PDO $db, $batchId) {
    if (!table_exists($db, 'sfb_batch_turns')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, occurred_at, turn_type, dough_temp_f, notes
         FROM sfb_batch_turns WHERE batch_id = ? ORDER BY occurred_at, id'
    );
    $stmt->execute([(int)$batchId]);
    return $stmt->fetchAll();
}

function bakery_sfb_batch_temps(PDO $db, $batchId) {
    if (!table_exists($db, 'sfb_batch_temps')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, phase, measured_at, temp_f, notes
         FROM sfb_batch_temps WHERE batch_id = ? ORDER BY measured_at, id'
    );
    $stmt->execute([(int)$batchId]);
    return $stmt->fetchAll();
}

/** @return array Batch owned by this customer */
function bakery_sfb_require_owned_batch(PDO $db, $customerId, $batchId, $allowCompleted = false) {
    $batch = bakery_sfb_batch($db, $customerId, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('Batch not found');
    }
    if (!$allowCompleted && ($batch['status'] ?? '') !== 'in_progress') {
        throw new RuntimeException('This batch is closed');
    }
    return $batch;
}

/** @return array Open batch owned by this customer */
function bakery_sfb_require_editable_batch(PDO $db, $customerId, $batchId) {
    return bakery_sfb_require_owned_batch($db, $customerId, $batchId, false);
}

function bakery_sfb_save_batch_mix(
    PDO $db,
    $customerId,
    $batchId,
    $minutes = null,
    $speed = '',
    $notes = '',
    $completedAt = '',
    $allowCompleted = false
) {
    $batch = bakery_sfb_require_owned_batch($db, $customerId, $batchId, $allowCompleted);
    $minutes = (int)$minutes;
    $speed = trim((string)$speed);
    $notes = trim((string)$notes);
    $stmt = $db->prepare(
        'UPDATE sfb_batches
         SET mix_minutes = ?, mix_speed = ?, mix_notes = ?, mix_completed_at = ?
         WHERE id = ? AND customer_id = ?'
    );
    $stmt->execute([
        $minutes > 0 ? $minutes : null,
        $speed !== '' ? $speed : null,
        $notes !== '' ? $notes : null,
        bakery_sfb_parse_datetime($completedAt) ?: ($minutes > 0 ? date('Y-m-d H:i:s') : null),
        (int)$batch['id'],
        (int)$customerId,
    ]);
}

function bakery_sfb_save_batch_bulk(PDO $db, $customerId, $batchId, $startedAt = '', $endedAt = '', $allowCompleted = false) {
    $batch = bakery_sfb_require_owned_batch($db, $customerId, $batchId, $allowCompleted);
    $stmt = $db->prepare(
        'UPDATE sfb_batches SET bulk_started_at = ?, bulk_ended_at = ? WHERE id = ? AND customer_id = ?'
    );
    $stmt->execute([
        bakery_sfb_parse_datetime($startedAt),
        bakery_sfb_parse_datetime($endedAt),
        (int)$batch['id'],
        (int)$customerId,
    ]);
}

function bakery_sfb_save_batch_shape(PDO $db, $customerId, $batchId, $shapedAt = '', $notes = '', $allowCompleted = false) {
    $batch = bakery_sfb_require_owned_batch($db, $customerId, $batchId, $allowCompleted);
    $notes = trim((string)$notes);
    $stmt = $db->prepare(
        'UPDATE sfb_batches SET shaped_at = ?, shape_notes = ? WHERE id = ? AND customer_id = ?'
    );
    $stmt->execute([
        bakery_sfb_parse_datetime($shapedAt) ?: date('Y-m-d H:i:s'),
        $notes !== '' ? $notes : null,
        (int)$batch['id'],
        (int)$customerId,
    ]);
}

function bakery_sfb_save_batch_bake(
    PDO $db,
    $customerId,
    $batchId,
    $ovenTempF = null,
    $startedAt = '',
    $endedAt = '',
    $notes = '',
    $allowCompleted = false
) {
    $batch = bakery_sfb_require_owned_batch($db, $customerId, $batchId, $allowCompleted);
    $notes = trim((string)$notes);
    $oven = trim((string)($ovenTempF ?? ''));
    $stmt = $db->prepare(
        'UPDATE sfb_batches
         SET bake_started_at = ?, bake_ended_at = ?, oven_temp_f = ?, bake_notes = ?
         WHERE id = ? AND customer_id = ?'
    );
    $stmt->execute([
        bakery_sfb_parse_datetime($startedAt) ?: date('Y-m-d H:i:s'),
        bakery_sfb_parse_datetime($endedAt),
        $oven !== '' ? (float)$oven : null,
        $notes !== '' ? $notes : null,
        (int)$batch['id'],
        (int)$customerId,
    ]);
}

function bakery_sfb_add_batch_turn(
    PDO $db,
    $customerId,
    $batchId,
    $turnType = 'stretch_fold',
    $doughTempF = null,
    $occurredAt = '',
    $notes = ''
) {
    $batch = bakery_sfb_require_editable_batch($db, $customerId, $batchId);
    $turnType = (string)$turnType;
    if (!array_key_exists($turnType, bakery_sfb_turn_types())) {
        $turnType = 'other';
    }
    $temp = trim((string)($doughTempF ?? ''));
    $notes = trim((string)$notes);
    $stmt = $db->prepare(
        'INSERT INTO sfb_batch_turns (batch_id, occurred_at, turn_type, dough_temp_f, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$batch['id'],
        bakery_sfb_parse_datetime($occurredAt) ?: date('Y-m-d H:i:s'),
        $turnType,
        $temp !== '' ? (float)$temp : null,
        $notes !== '' ? $notes : null,
    ]);
    return (int)$db->lastInsertId();
}

function bakery_sfb_add_batch_temp(
    PDO $db,
    $customerId,
    $batchId,
    $tempF,
    $phase = 'development',
    $measuredAt = '',
    $notes = ''
) {
    $batch = bakery_sfb_require_editable_batch($db, $customerId, $batchId);
    $tempF = (float)$tempF;
    if ($tempF <= 0) {
        throw new InvalidArgumentException('Enter the dough temperature');
    }
    $phase = (string)$phase;
    if (!in_array($phase, ['mix', 'development', 'shape', 'bake'], true)) {
        $phase = 'development';
    }
    $notes = trim((string)$notes);
    $stmt = $db->prepare(
        'INSERT INTO sfb_batch_temps (batch_id, phase, measured_at, temp_f, notes)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$batch['id'],
        $phase,
        bakery_sfb_parse_datetime($measuredAt) ?: date('Y-m-d H:i:s'),
        $tempF,
        $notes !== '' ? $notes : null,
    ]);
    return (int)$db->lastInsertId();
}

function bakery_sfb_complete_batch(PDO $db, $customerId, $batchId, $loafCount = 0, $finalNotes = '', $endedAt = '') {
    $batch = bakery_sfb_require_editable_batch($db, $customerId, $batchId);
    $loaves = (int)$loafCount;
    if ($loaves < 0 || $loaves > 500) {
        throw new InvalidArgumentException('Loaf count must be between 0 and 500');
    }
    $notes = trim((string)$finalNotes);
    $endedAt = bakery_sfb_parse_datetime($endedAt) ?: date('Y-m-d H:i:s');
    $stmt = $db->prepare(
        'UPDATE sfb_batches
         SET status = "completed", loaf_count = ?, final_notes = ?,
             bake_ended_at = COALESCE(bake_ended_at, ?)
         WHERE id = ?'
    );
    $stmt->execute([
        $loaves,
        $notes !== '' ? $notes : null,
        $endedAt,
        (int)$batch['id'],
    ]);
    return bakery_sfb_batch($db, $customerId, (int)$batch['id']);
}

/** Opt-in public bake card. Sharing is explicit; journals stay private until this runs. */
function bakery_sfb_share_batch(PDO $db, $customerId, $batchId) {
    if (!bakery_sfb_community_ready($db)) {
        throw new RuntimeException('The community forum is not ready yet');
    }
    $batch = bakery_sfb_batch($db, $customerId, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('Choose one of your own batches to share');
    }
    $stmt = $db->prepare(
        'INSERT INTO sfb_batch_shares (batch_id, customer_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE customer_id = VALUES(customer_id)'
    );
    $stmt->execute([(int)$batch['id'], (int)$customerId]);
    return bakery_sfb_batch_share($db, (int)$batch['id']);
}

function bakery_sfb_unshare_batch(PDO $db, $customerId, $batchId) {
    if (!bakery_sfb_community_ready($db)) {
        throw new RuntimeException('The community forum is not ready yet');
    }
    $batch = bakery_sfb_batch($db, $customerId, $batchId);
    if (!$batch) {
        throw new InvalidArgumentException('Batch not found');
    }
    $db->prepare('DELETE FROM sfb_batch_shares WHERE batch_id = ? AND customer_id = ?')
        ->execute([(int)$batch['id'], (int)$customerId]);
    return true;
}

function bakery_sfb_batch_photos(PDO $db, $batchId) {
    if (!table_exists($db, 'sfb_batch_photos')) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, phase, filename, file_path, caption, uploaded_at
         FROM sfb_batch_photos WHERE batch_id = ? ORDER BY uploaded_at, id'
    );
    $stmt->execute([(int)$batchId]);
    return $stmt->fetchAll();
}

function bakery_sfb_photo_url($filePath) {
    return BASE_URL . 'uploads/sfb_photos/' . ltrim((string)$filePath, '/');
}

/* ── Batch conversations ─────────────────────────────────────────────── */

/**
 * Add a message to a batch discussion. Replies may only target a message on
 * the same batch. An administrator reply closes the parent baker question.
 */
function bakery_sfb_add_batch_message(
    PDO $db,
    $batchId,
    $authorType,
    $authorName,
    $body,
    $messageType = 'comment',
    $authorCustomerId = null,
    $authorUserId = null,
    $parentMessageId = null,
    $phase = null
) {
    if (!bakery_sfb_discussion_ready($db)) {
        throw new RuntimeException('Batch discussions are not ready yet');
    }

    $batchId = (int)$batchId;
    $authorType = (string)$authorType;
    $authorName = trim((string)$authorName);
    $body = trim((string)$body);
    $messageType = (string)$messageType;
    $parentMessageId = (int)$parentMessageId;
    $phase = trim((string)$phase);

    if ($batchId <= 0 || !in_array($authorType, ['baker', 'admin'], true)) {
        throw new InvalidArgumentException('Invalid batch discussion message');
    }
    if ($phase !== '' && !in_array($phase, bakery_sfb_builder_phases(), true)) {
        throw new InvalidArgumentException('Unknown batch phase');
    }
    if ($phase !== '' && !column_exists($db, 'sfb_batch_messages', 'phase')) {
        throw new RuntimeException('Batch phases need a database update (migration 062)');
    }
    if ($authorName === '' || strlen($authorName) > 120) {
        throw new InvalidArgumentException('A valid author name is required');
    }
    if (!in_array($messageType, ['comment', 'question'], true)) {
        throw new InvalidArgumentException('Choose a comment or a question');
    }
    if ($authorType === 'admin' && $messageType === 'question') {
        throw new InvalidArgumentException('Administrators can post comments or replies');
    }
    if ($body === '' || strlen($body) > 4000) {
        throw new InvalidArgumentException('Messages must be between 1 and 4,000 characters');
    }

    $parent = null;
    if ($parentMessageId > 0) {
        $stmt = $db->prepare(
            'SELECT id, message_type, author_type FROM sfb_batch_messages
             WHERE id = ? AND batch_id = ? LIMIT 1'
        );
        $stmt->execute([$parentMessageId, $batchId]);
        $parent = $stmt->fetch();
        if (!$parent) {
            throw new InvalidArgumentException('The message you are replying to was not found');
        }
    }

    $customerId = (int)$authorCustomerId;
    $userId = (int)$authorUserId;
    $phaseColumn = column_exists($db, 'sfb_batch_messages', 'phase');
    $stmt = $db->prepare(
        $phaseColumn
            ? 'INSERT INTO sfb_batch_messages
                 (batch_id, parent_message_id, author_customer_id, author_user_id, author_type, author_name, message_type, body, phase)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            : 'INSERT INTO sfb_batch_messages
                 (batch_id, parent_message_id, author_customer_id, author_user_id, author_type, author_name, message_type, body)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $params = [
        $batchId,
        $parentMessageId > 0 ? $parentMessageId : null,
        $customerId > 0 ? $customerId : null,
        $userId > 0 ? $userId : null,
        $authorType,
        $authorName,
        $messageType,
        $body,
    ];
    if ($phaseColumn) {
        $params[] = $phase !== '' ? $phase : null;
    }
    $stmt->execute($params);
    $messageId = (int)$db->lastInsertId();

    if ($authorType === 'admin' && $parent && $parent['message_type'] === 'question') {
        bakery_sfb_resolve_batch_question($db, $batchId, (int)$parent['id'], $userId);
    }

    return $messageId;
}

/** Mark a baker question as handled. */
function bakery_sfb_resolve_batch_question(PDO $db, $batchId, $messageId, $resolvedByUserId = null) {
    if (!bakery_sfb_discussion_ready($db)) {
        return false;
    }
    $stmt = $db->prepare(
        'UPDATE sfb_batch_messages
         SET is_resolved = 1,
             resolved_at = COALESCE(resolved_at, NOW()),
             resolved_by_user_id = COALESCE(?, resolved_by_user_id)
         WHERE id = ? AND batch_id = ? AND message_type = "question" AND author_type = "baker"'
    );
    $userId = (int)$resolvedByUserId;
    $stmt->execute([$userId > 0 ? $userId : null, (int)$messageId, (int)$batchId]);
    return $stmt->rowCount() > 0;
}

/** All messages for a batch, with root messages first and replies in time order. */
function bakery_sfb_batch_messages(PDO $db, $batchId) {
    if (!bakery_sfb_discussion_ready($db)) {
        return [];
    }
    $phaseColumn = column_exists($db, 'sfb_batch_messages', 'phase') ? ', phase' : '';
    $stmt = $db->prepare(
        'SELECT id, batch_id, parent_message_id, author_customer_id, author_user_id,
                author_type, author_name, message_type, body, is_resolved,
                resolved_at, resolved_by_user_id, created_at, updated_at' . $phaseColumn . '
         FROM sfb_batch_messages
         WHERE batch_id = ?
         ORDER BY COALESCE(parent_message_id, id), parent_message_id IS NOT NULL, created_at, id'
    );
    $stmt->execute([(int)$batchId]);
    return $stmt->fetchAll();
}

/** Split a flat batch discussion into root messages and their direct replies. */
function bakery_sfb_message_threads(array $messages) {
    $roots = [];
    $replies = [];
    foreach ($messages as $message) {
        $parentId = (int)($message['parent_message_id'] ?? 0);
        if ($parentId > 0) {
            $replies[$parentId][] = $message;
        } else {
            $roots[] = $message;
        }
    }
    return ['roots' => $roots, 'replies' => $replies];
}

/**
 * Resolved coach answers on a bake: the worked examples a shared bake card
 * shows. Unresolved questions stay private to the baker and the coach.
 */
function bakery_sfb_batch_resolved_qna(PDO $db, $batchId, $limit = 10) {
    $threads = bakery_sfb_message_threads(bakery_sfb_batch_messages($db, $batchId));
    $out = [];
    foreach ($threads['roots'] as $root) {
        if ($root['message_type'] !== 'question' || (int)$root['is_resolved'] !== 1) {
            continue;
        }
        $root['replies'] = $threads['replies'][(int)$root['id']] ?? [];
        $out[] = $root;
        if (count($out) >= max(1, (int)$limit)) {
            break;
        }
    }
    return $out;
}

/**
 * Compare the frozen snapshot lines against the live source formula lines.
 * Pure function; percentage drift is judged at 0.01 tolerance.
 *
 * @return array{drifted: bool, changed: array, added: array, removed: array}
 */
function bakery_sfb_snapshot_drift(array $snapshotLines, array $currentLines) {
    $snap = [];
    foreach ($snapshotLines as $line) {
        $snap[mb_strtolower(trim((string)$line['line_name']))] = (float)$line['percentage'];
    }
    $cur = [];
    foreach ($currentLines as $line) {
        $name = isset($line['line_name']) ? $line['line_name'] : '';
        $pct = isset($line['percentage']) ? (float)$line['percentage'] : 0.0;
        $key = mb_strtolower(trim((string)($line['line_name'] ?? $name)));
        $cur[$key] = $pct;
    }
    $changed = [];
    $added = [];
    foreach ($cur as $key => $pct) {
        if (!array_key_exists($key, $snap)) {
            $added[] = $key;
        } elseif (abs($snap[$key] - $pct) >= 0.01) {
            $changed[] = $key;
        }
    }
    $removed = array_values(array_diff(array_keys($snap), array_keys($cur)));
    return [
        'drifted' => $changed || $added || $removed,
        'changed' => $changed,
        'added' => $added,
        'removed' => $removed,
    ];
}

/**
 * Copy a shared bake's frozen formula into a baker's own journal.
 * Snapshot lines carry names only, so lines are re-attached by name:
 * standard library ingredients match exactly, unknown ones become custom
 * ingredients owned by the remixer, and the original baker's starter line is
 * replaced with the remixer's own starter. Provenance lands on
 * sfb_formulas.remixed_from_batch_id so credit travels with the formula.
 */
function bakery_sfb_remix_shared_formula(PDO $db, $customerId, $batchId) {
    if (!bakery_sfb_community_ready($db)) {
        throw new RuntimeException('The community forum is not ready yet');
    }
    if (!bakery_sfb_builder_ready($db)) {
        throw new RuntimeException('Formula remix needs a database update (migration 062)');
    }
    $customerId = (int)$customerId;
    $batchId = (int)$batchId;

    $shared = bakery_sfb_shared_batch($db, $batchId);
    if (!$shared) {
        throw new InvalidArgumentException('That bake has not been shared');
    }
    $snapshot = bakery_sfb_batch_formula_snapshot($db, $batchId);
    $snapshotLines = bakery_sfb_batch_formula_snapshot_lines($db, $batchId);
    if (!$snapshot || !$snapshotLines) {
        throw new InvalidArgumentException('This bake has no frozen formula to copy');
    }

    // Standard ingredient lookup by exact name (shared library only).
    $standardByName = [];
    foreach ($db->query(
        'SELECT id, name FROM sfb_ingredients WHERE customer_id IS NULL'
    ) as $row) {
        $standardByName[mb_strtolower(trim((string)$row['name']))] = (int)$row['id'];
    }
    // Custom ingredients already owned by the remixer.
    $ownByName = [];
    foreach (bakery_sfb_custom_ingredients($db, $customerId, false) as $row) {
        $ownByName[mb_strtolower(trim((string)$row['name']))] = (int)$row['id'];
    }

    $ownTransaction = !$db->inTransaction();
    if ($ownTransaction) {
        $db->beginTransaction();
    }
    try {
        $ins = $db->prepare(
            'INSERT INTO sfb_formulas (customer_id, name, description, target_dough_g, is_template, remixed_from_batch_id)
             VALUES (?, ?, ?, ?, 0, ?)'
        );
        $ins->execute([
            $customerId,
            (string)$snapshot['formula_name'],
            $snapshot['description'] !== null && (string)$snapshot['description'] !== '' ? (string)$snapshot['description'] : null,
            $snapshot['target_dough_g'],
            $batchId,
        ]);
        $formulaId = (int)$db->lastInsertId();

        $lineIns = $db->prepare(
            'INSERT INTO sfb_formula_ingredients (formula_id, ingredient_id, starter_id, percentage, sort_order)
             VALUES (?, ?, ?, ?, ?)'
        );
        $starterId = null;
        $sort = 0;
        foreach ($snapshotLines as $line) {
            $sort++;
            $kind = (string)$line['line_kind'];
            $percentage = (float)$line['percentage'];
            if ($kind === 'starter') {
                // The original starter belongs to another baker; use mine.
                if ($starterId === null) {
                    $starter = bakery_sfb_ensure_starter($db, $customerId);
                    $starterId = (int)$starter['id'];
                }
                $lineIns->execute([$formulaId, null, $starterId, $percentage, $sort]);
                continue;
            }
            $key = mb_strtolower(trim((string)$line['line_name']));
            $ingredientId = $standardByName[$key] ?? ($ownByName[$key] ?? null);
            if ($ingredientId === null) {
                $category = in_array($kind, array_keys(bakery_sfb_ingredient_categories()), true) ? $kind : 'other';
                $ingredientId = bakery_sfb_create_ingredient($db, $customerId, mb_substr((string)$line['line_name'], 0, 100), $category);
                $ownByName[$key] = (int)$ingredientId;
            }
            $lineIns->execute([$formulaId, (int)$ingredientId, null, $percentage, $sort]);
        }

        if ($ownTransaction) {
            $db->commit();
        }
        return $formulaId;
    } catch (Throwable $e) {
        if ($ownTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

/* ── Learning Center (Prompt 24) ──────────────────────────────────────── */

/** Whether the learning-center tables from migration 063 exist. */
function bakery_sfb_learning_ready(PDO $db) {
    return table_exists($db, 'sfb_courses')
        && table_exists($db, 'sfb_course_lessons')
        && table_exists($db, 'sfb_lesson_steps')
        && table_exists($db, 'sfb_lesson_progress');
}

/** Courses with lesson counts; inactive courses only when asked (admin views). */
function bakery_sfb_courses(PDO $db, $includeInactive = false) {
    if (!bakery_sfb_learning_ready($db)) {
        return [];
    }
    $sql = 'SELECT c.id, c.title, c.description, c.sort_order, c.is_active,
                   (SELECT COUNT(*) FROM sfb_course_lessons l WHERE l.course_id = c.id AND l.is_active = 1) AS lesson_count
            FROM sfb_courses c';
    if (!$includeInactive) {
        $sql .= ' WHERE c.is_active = 1';
    }
    $sql .= ' ORDER BY c.sort_order, c.id';
    return $db->query($sql)->fetchAll();
}

function bakery_sfb_course(PDO $db, $courseId) {
    if (!bakery_sfb_learning_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('SELECT id, title, description, sort_order, is_active FROM sfb_courses WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$courseId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_create_course(PDO $db, $title, $description = '') {
    if (!bakery_sfb_learning_ready($db)) {
        throw new RuntimeException('The learning center needs a database update (migration 063)');
    }
    $title = trim((string)$title);
    if ($title === '' || mb_strlen($title) > 150) {
        throw new InvalidArgumentException('Course title is required (150 characters max)');
    }
    $description = trim((string)$description);
    $next = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sfb_courses')->fetchColumn();
    $ins = $db->prepare('INSERT INTO sfb_courses (title, description, sort_order) VALUES (?, ?, ?)');
    $ins->execute([$title, $description !== '' ? $description : null, $next]);
    return (int)$db->lastInsertId();
}

function bakery_sfb_toggle_course(PDO $db, $courseId) {
    $stmt = $db->prepare('UPDATE sfb_courses SET is_active = 1 - is_active WHERE id = ?');
    $stmt->execute([(int)$courseId]);
    return $stmt->rowCount() > 0;
}

/** Lessons of a course, ordered. Inactive lessons included only for staff. */
function bakery_sfb_course_lessons(PDO $db, $courseId, $includeInactive = false) {
    if (!bakery_sfb_learning_ready($db)) {
        return [];
    }
    $sql = 'SELECT id, course_id, title, summary, external_url, sort_order, is_active
            FROM sfb_course_lessons WHERE course_id = ?';
    if (!$includeInactive) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, id';
    $stmt = $db->prepare($sql);
    $stmt->execute([(int)$courseId]);
    return $stmt->fetchAll();
}

/** One lesson plus its course context. */
function bakery_sfb_lesson(PDO $db, $lessonId) {
    if (!bakery_sfb_learning_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT l.id, l.course_id, l.title, l.summary, l.external_url, l.sort_order, l.is_active,
                c.title AS course_title
         FROM sfb_course_lessons l
         JOIN sfb_courses c ON c.id = l.course_id
         WHERE l.id = ? LIMIT 1'
    );
    $stmt->execute([(int)$lessonId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_create_lesson(PDO $db, $courseId, $title, $summary = '', $externalUrl = '') {
    if (!bakery_sfb_learning_ready($db)) {
        throw new RuntimeException('The learning center needs a database update (migration 063)');
    }
    if (!bakery_sfb_course($db, $courseId)) {
        throw new InvalidArgumentException('Course not found');
    }
    $title = trim((string)$title);
    if ($title === '' || mb_strlen($title) > 150) {
        throw new InvalidArgumentException('Lesson title is required (150 characters max)');
    }
    $summary = trim((string)$summary);
    $externalUrl = trim((string)$externalUrl);
    if ($externalUrl !== '' && !preg_match('#^https?://#i', $externalUrl)) {
        throw new InvalidArgumentException('External links must start with http:// or https://');
    }
    $count = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sfb_course_lessons WHERE course_id = ?');
    $count->execute([(int)$courseId]);
    $next = (int)$count->fetchColumn();
    $ins = $db->prepare(
        'INSERT INTO sfb_course_lessons (course_id, title, summary, external_url, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([
        (int)$courseId,
        $title,
        $summary !== '' ? $summary : null,
        $externalUrl !== '' ? $externalUrl : null,
        $next,
    ]);
    return (int)$db->lastInsertId();
}

function bakery_sfb_toggle_lesson(PDO $db, $lessonId) {
    $stmt = $db->prepare('UPDATE sfb_course_lessons SET is_active = 1 - is_active WHERE id = ?');
    $stmt->execute([(int)$lessonId]);
    return $stmt->rowCount() > 0;
}

/** Steps of a lesson in teaching order. */
function bakery_sfb_lesson_steps(PDO $db, $lessonId) {
    if (!bakery_sfb_learning_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, lesson_id, body_text, media_path, media_kind, sort_order
         FROM sfb_lesson_steps WHERE lesson_id = ? ORDER BY sort_order, id'
    );
    $stmt->execute([(int)$lessonId]);
    return $stmt->fetchAll();
}

/**
 * Store an uploaded photo/video for a lesson step under storage/sfb_media/.
 * Returns the relative path and kind, or throws on invalid input.
 */
function bakery_sfb_save_education_media($file) {
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload failed — try again');
    }
    if ($file['size'] <= 0 || $file['size'] > 256 * 1024 * 1024) {
        throw new InvalidArgumentException('Media must be between 1 byte and 256 MB');
    }

    $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $imageTypes = [
        'jpg' => 'photo', 'jpeg' => 'photo', 'png' => 'photo',
        'gif' => 'photo', 'webp' => 'photo',
    ];
    $videoTypes = [
        'mp4' => 'video', 'webm' => 'video', 'm4v' => 'video', 'mov' => 'video',
    ];
    $kind = $imageTypes[$extension] ?? ($videoTypes[$extension] ?? null);
    if ($kind === null) {
        throw new InvalidArgumentException('Use a photo (jpg, png, gif, webp) or video (mp4, webm, m4v, mov)');
    }

    // Trust the real bytes over the browser's claimed type.
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detected = (string)finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($kind === 'photo' && strpos($detected, 'image/') !== 0) {
            throw new InvalidArgumentException('That file is not an image');
        }
        if ($kind === 'video' && strpos($detected, 'video/') !== 0 && strpos($detected, 'application/octet-stream') !== 0) {
            throw new InvalidArgumentException('That file is not a video');
        }
    }

    $subDir = date('Y') . '/' . date('m');
    $targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sfb_media'
        . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $subDir);
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true)) {
        throw new RuntimeException('Could not create the media folder');
    }
    $unique = substr(md5(uniqid((string)mt_rand(), true)), 0, 12);
    $filename = date('Ymd_His') . '_' . $unique . '.' . $extension;
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
    if (!@move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not store the uploaded file');
    }
    return ['path' => $subDir . '/' . $filename, 'kind' => $kind];
}

function bakery_sfb_media_path_safe($relativePath) {
    $relativePath = (string)$relativePath;
    if ($relativePath === '' || strpos($relativePath, '..') !== false) {
        return false;
    }
    if (!preg_match('/^[A-Za-z0-9._\-\/]+$/', $relativePath)) {
        return false;
    }
    $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'm4v', 'mov'];
    return in_array($extension, $allowed, true);
}

function bakery_sfb_media_content_type($relativePath) {
    static $types = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'm4v' => 'video/mp4',
        'mov' => 'video/quicktime',
    ];
    $extension = strtolower(pathinfo((string)$relativePath, PATHINFO_EXTENSION));
    return $types[$extension] ?? 'application/octet-stream';
}

/** Gated streaming URL — never a direct storage path. */
function bakery_sfb_media_url($relativePath) {
    return BASE_URL . 'sfb_media.php?f=' . rawurlencode((string)$relativePath);
}

function bakery_sfb_add_lesson_step(PDO $db, $lessonId, $bodyText, $mediaPath = '', $mediaKind = 'photo') {
    if (!bakery_sfb_learning_ready($db)) {
        throw new RuntimeException('The learning center needs a database update (migration 063)');
    }
    $lesson = bakery_sfb_lesson($db, $lessonId);
    if (!$lesson) {
        throw new InvalidArgumentException('Lesson not found');
    }
    $bodyText = trim((string)$bodyText);
    $mediaPath = trim((string)$mediaPath);
    if ($bodyText === '' && $mediaPath === '') {
        throw new InvalidArgumentException('A step needs words, a photo, or a video');
    }
    if ($mediaPath !== '' && !in_array($mediaKind, ['photo', 'video'], true)) {
        throw new InvalidArgumentException('Unknown media kind');
    }
    $count = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sfb_lesson_steps WHERE lesson_id = ?');
    $count->execute([(int)$lessonId]);
    $ins = $db->prepare(
        'INSERT INTO sfb_lesson_steps (lesson_id, body_text, media_path, media_kind, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([
        (int)$lessonId,
        $bodyText !== '' ? $bodyText : null,
        $mediaPath !== '' ? $mediaPath : null,
        $mediaPath !== '' ? $mediaKind : 'photo',
        (int)$count->fetchColumn(),
    ]);
    return (int)$db->lastInsertId();
}

/** Delete a step row and its stored media file. */
function bakery_sfb_delete_lesson_step(PDO $db, $stepId) {
    $stmt = $db->prepare('SELECT id, media_path FROM sfb_lesson_steps WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$stepId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Step not found');
    }
    $db->prepare('DELETE FROM sfb_lesson_steps WHERE id = ?')->execute([(int)$row['id']]);
    $relative = (string)($row['media_path'] ?? '');
    if ($relative !== '' && bakery_sfb_media_path_safe($relative)) {
        $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sfb_media'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realBase = realpath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sfb_media');
        $realFile = realpath($absolute);
        if ($realBase !== false && $realFile !== false && strpos($realFile, $realBase) === 0 && is_file($realFile)) {
            @unlink($realFile);
        }
    }
    return true;
}

/** Swap a step one position up or down within its lesson. */
function bakery_sfb_move_lesson_step(PDO $db, $lessonId, $stepId, $direction) {
    $steps = bakery_sfb_lesson_steps($db, $lessonId);
    $index = null;
    foreach ($steps as $i => $step) {
        if ((int)$step['id'] === (int)$stepId) {
            $index = $i;
            break;
        }
    }
    if ($index === null) {
        throw new InvalidArgumentException('Step not found in this lesson');
    }
    $swapWith = $direction === 'up' ? $index - 1 : $index + 1;
    if ($swapWith < 0 || $swapWith >= count($steps)) {
        return false;
    }
    $upd = $db->prepare('UPDATE sfb_lesson_steps SET sort_order = ? WHERE id = ?');
    $upd->execute([$index + 1, (int)$steps[$swapWith]['id']]);
    $upd->execute([$swapWith + 1, (int)$steps[$index]['id']]);
    return true;
}

/** Completed step ids for one baker on one lesson. */
function bakery_sfb_lesson_progress(PDO $db, $customerId, $lessonId) {
    if (!bakery_sfb_learning_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT step_id FROM sfb_lesson_progress WHERE customer_id = ? AND lesson_id = ?'
    );
    $stmt->execute([(int)$customerId, (int)$lessonId]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stepId) {
        $out[] = (int)$stepId;
    }
    return $out;
}

/** Toggle one step checkmark; returns true when the step is now complete. */
function bakery_sfb_toggle_lesson_progress(PDO $db, $customerId, $lessonId, $stepId) {
    if (!bakery_sfb_learning_ready($db)) {
        throw new RuntimeException('The learning center needs a database update (migration 063)');
    }
    $stepOk = $db->prepare('SELECT 1 FROM sfb_lesson_steps WHERE id = ? AND lesson_id = ? LIMIT 1');
    $stepOk->execute([(int)$stepId, (int)$lessonId]);
    if (!$stepOk->fetchColumn()) {
        throw new InvalidArgumentException('Step not found in this lesson');
    }
    $del = $db->prepare('DELETE FROM sfb_lesson_progress WHERE customer_id = ? AND lesson_id = ? AND step_id = ?');
    $del->execute([(int)$customerId, (int)$lessonId, (int)$stepId]);
    if ($del->rowCount() > 0) {
        return false;
    }
    $ins = $db->prepare('INSERT IGNORE INTO sfb_lesson_progress (customer_id, lesson_id, step_id) VALUES (?, ?, ?)');
    $ins->execute([(int)$customerId, (int)$lessonId, (int)$stepId]);
    return true;
}

/** [completed steps, total steps] across a course's active lessons for one baker. */
function bakery_sfb_course_progress(PDO $db, $customerId, $courseId) {
    $total = 0;
    foreach (bakery_sfb_course_lessons($db, $courseId) as $lesson) {
        $total += count(bakery_sfb_lesson_steps($db, (int)$lesson['id']));
    }
    if ($total === 0) {
        return [0, 0];
    }
    $done = 0;
    foreach (bakery_sfb_course_lessons($db, $courseId) as $lesson) {
        $done += count(bakery_sfb_lesson_progress($db, $customerId, (int)$lesson['id']));
    }
    return [$done, $total];
}

/* ── Home Base Onboarding (Prompt 25) ─────────────────────────────────── */

/** Whether the invite table from migration 064 exists. */
function bakery_sfb_invites_ready(PDO $db) {
    return table_exists($db, 'sfb_invites');
}

/**
 * Mint an invite code. Codes avoid ambiguous characters and are stored
 * uppercase; lookup normalizes before matching.
 */
function bakery_sfb_create_invite(PDO $db, $intent = 'learn', $label = '', $createdByUserId = null) {
    if (!bakery_sfb_invites_ready($db)) {
        throw new RuntimeException('Invites need a database update (migration 064)');
    }
    $intent = $intent === 'share' ? 'share' : 'learn';
    $label = trim((string)$label);
    if ($label !== '' && mb_strlen($label) > 150) {
        throw new InvalidArgumentException('Invite label must be 150 characters or fewer');
    }
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $code = 'SFB-';
        for ($i = 0; $i < 6; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        try {
            $ins = $db->prepare(
                'INSERT INTO sfb_invites (code, intent, label, created_by_user_id) VALUES (?, ?, ?, ?)'
            );
            $userId = (int)$createdByUserId;
            $ins->execute([$code, $intent, $label !== '' ? $label : null, $userId > 0 ? $userId : null]);
            return bakery_sfb_invite_lookup($db, $code);
        } catch (PDOException $e) {
            // Unique-key collision on the code: draw another one.
            if (strpos($e->getMessage(), '1062') === false && strpos($e->getMessage(), 'uq_sfb_invites_code') === false) {
                throw $e;
            }
        }
    }
    throw new RuntimeException('Could not generate a unique invite code');
}

function bakery_sfb_normalize_invite_code($code) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim((string)$code)));
}

/** An unused invite row for the given code, or null. */
function bakery_sfb_invite_lookup(PDO $db, $code) {
    if (!bakery_sfb_invites_ready($db)) {
        return null;
    }
    $normalized = bakery_sfb_normalize_invite_code($code);
    if (strlen($normalized) < 4) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT id, code, intent, label FROM sfb_invites
         WHERE REPLACE(code, "-", "") = ? AND used_by_customer_id IS NULL LIMIT 1'
    );
    $stmt->execute([$normalized]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Claim an invite for one customer. Returns true only for the first claim. */
function bakery_sfb_mark_invite_used(PDO $db, $inviteId, $customerId) {
    if (!bakery_sfb_invites_ready($db)) {
        return false;
    }
    $stmt = $db->prepare(
        'UPDATE sfb_invites
         SET used_by_customer_id = ?, used_at = NOW()
         WHERE id = ? AND used_by_customer_id IS NULL'
    );
    $stmt->execute([(int)$customerId, (int)$inviteId]);
    return $stmt->rowCount() > 0;
}

/** Recent invites, newest first, for the staff authoring screen. */
function bakery_sfb_recent_invites(PDO $db, $limit = 12) {
    if (!bakery_sfb_invites_ready($db)) {
        return [];
    }
    return $db->query(
        'SELECT id, code, intent, label, used_by_customer_id, used_at, created_at
         FROM sfb_invites ORDER BY id DESC LIMIT ' . max(1, (int)$limit)
    )->fetchAll();
}

/**
 * First-run next actions for a brand-new baker's home base.
 * Each action carries a done flag so the welcome strip can retire steps
 * as they happen. Lesson action is present only when a course exists.
 */
function bakery_sfb_first_run_actions(PDO $db, $customerId) {
    $customerId = (int)$customerId;
    $hasStarter = count(bakery_sfb_starters($db, $customerId)) > 0;
    $hasFormula = count(bakery_sfb_formulas($db, $customerId)) > 0;
    $firstLesson = null;
    foreach (bakery_sfb_courses($db) as $course) {
        foreach (bakery_sfb_course_lessons($db, (int)$course['id']) as $lesson) {
            $firstLesson = $lesson;
            break 2;
        }
    }
    $actions = [
        ['key' => 'starter', 'done' => $hasStarter],
        ['key' => 'formula', 'done' => $hasFormula],
        ['key' => 'lesson', 'done' => false, 'lesson_id' => $firstLesson !== null ? (int)$firstLesson['id'] : null,
         'lesson_title' => $firstLesson !== null ? (string)$firstLesson['title'] : ''],
    ];
    return $actions;
}

/* ── Education Payments (Prompt 26) ───────────────────────────────────── */

/** Whether the offerings and purchases tables from migration 066 exist. */
function bakery_sfb_payments_ready(PDO $db) {
    return table_exists($db, 'sfb_offerings')
        && table_exists($db, 'sfb_offering_purchases');
}

function bakery_sfb_offerings(PDO $db, $includeInactive = false) {
    if (!bakery_sfb_payments_ready($db)) {
        return [];
    }
    $sql = 'SELECT id, title, description, price_cents, currency, kind, entitlement_days, sort_order, is_active
            FROM sfb_offerings';
    if (!$includeInactive) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY sort_order, id';
    return $db->query($sql)->fetchAll();
}

function bakery_sfb_offering(PDO $db, $offeringId) {
    if (!bakery_sfb_payments_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('SELECT id, title, description, price_cents, currency, kind, entitlement_days, is_active FROM sfb_offerings WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$offeringId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_create_offering(PDO $db, $title, $priceDollars, $kind = 'class', $description = '', $entitlementDays = null) {
    if (!bakery_sfb_payments_ready($db)) {
        throw new RuntimeException('Education payments need a database update (migration 066)');
    }
    if (!in_array((string)$kind, ['class', 'membership', 'kit'], true)) {
        throw new InvalidArgumentException('Choose class, membership, or kit');
    }
    $title = trim((string)$title);
    if ($title === '' || mb_strlen($title) > 150) {
        throw new InvalidArgumentException('Offering title is required (150 characters max)');
    }
    $priceCents = (int)round(((float)$priceDollars) * 100);
    if ($priceCents < 0 || $priceCents > 100000000) {
        throw new InvalidArgumentException('Price must be between 0 and 1,000,000 dollars');
    }
    $days = $entitlementDays === null ? null : max(0, (int)$entitlementDays);
    $description = trim((string)$description);
    $next = (int)$db->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM sfb_offerings')->fetchColumn();
    $ins = $db->prepare(
        'INSERT INTO sfb_offerings (title, description, price_cents, currency, kind, entitlement_days, sort_order)
         VALUES (?, ?, ?, "USD", ?, ?, ?)'
    );
    $ins->execute([$title, $description !== '' ? $description : null, $priceCents, $kind, $days, $next]);
    return (int)$db->lastInsertId();
}

function bakery_sfb_toggle_offering(PDO $db, $offeringId) {
    $stmt = $db->prepare('UPDATE sfb_offerings SET is_active = 1 - is_active WHERE id = ?');
    $stmt->execute([(int)$offeringId]);
    return $stmt->rowCount() > 0;
}

/** One purchase attempt with the offering's title and price frozen in. */
function bakery_sfb_record_purchase_intent(PDO $db, $customerId, $offeringId) {
    if (!bakery_sfb_payments_ready($db)) {
        throw new RuntimeException('Education payments need a database update (migration 066)');
    }
    $offering = bakery_sfb_offering($db, $offeringId);
    if (!$offering || (int)$offering['is_active'] !== 1) {
        throw new InvalidArgumentException('That offering is not available');
    }
    $ins = $db->prepare(
        'INSERT INTO sfb_offering_purchases
            (customer_id, offering_id, offering_title_snapshot, price_cents_snapshot, currency_snapshot, status)
         VALUES (?, ?, ?, ?, ?, "intent")'
    );
    $ins->execute([
        (int)$customerId,
        (int)$offering['id'],
        (string)$offering['title'],
        (int)$offering['price_cents'],
        (string)$offering['currency'],
    ]);
    return (int)$db->lastInsertId();
}

function bakery_sfb_purchase(PDO $db, $purchaseId) {
    if (!bakery_sfb_payments_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT p.*, o.entitlement_days
         FROM sfb_offering_purchases p
         LEFT JOIN sfb_offerings o ON o.id = p.offering_id
         WHERE p.id = ? LIMIT 1'
    );
    $stmt->execute([(int)$purchaseId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Create a Square hosted checkout link for a recorded intent.
 * Requires credentials or the test handler seam; on success the attempt
 * moves to pending. Never invents a paid state.
 */
function bakery_sfb_create_purchase_checkout(PDO $db, $purchaseId) {
    if (!square_is_configured() && !isset($GLOBALS['bakery_square_api_handler'])) {
        throw new RuntimeException('Square is not configured. Set SQUARE_ACCESS_TOKEN and SQUARE_LOCATION_ID.');
    }
    require_once __DIR__ . '/square_config.php';
    $purchase = bakery_sfb_purchase($db, $purchaseId);
    if (!$purchase) {
        throw new InvalidArgumentException('Purchase not found');
    }
    if (!in_array((string)$purchase['status'], ['intent', 'failed'], true)) {
        throw new InvalidArgumentException('This purchase already has a checkout');
    }

    $resp = square_api_request('POST', '/v2/online-checkout/payment-links', [
        'idempotency_key' => 'os-edu-purchase-' . (int)$purchase['id'] . '-' . date('Ymd'),
        'order' => [
            'location_id' => defined('SQUARE_LOCATION_ID') && SQUARE_LOCATION_ID !== '' ? SQUARE_LOCATION_ID : 'test-location',
            'reference_id' => 'os-edu-' . (int)$purchase['id'],
            'line_items' => [[
                'name' => mb_substr((string)$purchase['offering_title_snapshot'], 0, 100),
                'quantity' => '1',
                'base_price_money' => [
                    'amount' => (int)$purchase['price_cents_snapshot'],
                    'currency' => (string)$purchase['currency_snapshot'],
                ],
            ]],
        ],
        'checkout_options' => [
            'redirect_url' => rtrim((string)(defined('BASE_URL') ? BASE_URL : '/'), '/') . 'sfb_offerings.php?purchased=' . (int)$purchase['id'],
        ],
    ]);
    $link = $resp['payment_link'] ?? [];
    $url = (string)($link['url'] ?? '');
    $orderIds = array_map('strval', (array)($link['order_ids'] ?? []));
    if ($url === '') {
        throw new RuntimeException('Square did not return a checkout link.');
    }

    $upd = $db->prepare(
        'UPDATE sfb_offering_purchases
         SET status = "pending", square_payment_link_id = ?, square_order_id = ?, checkout_url = ?
         WHERE id = ?'
    );
    $upd->execute([
        (string)($link['id'] ?? ''),
        $orderIds[0] ?? null,
        $url,
        (int)$purchase['id'],
    ]);
    return ['url' => $url, 'payment_link_id' => (string)($link['id'] ?? ''), 'order_id' => $orderIds[0] ?? null];
}

/**
 * Buy one offering: records the attempt first (one row per attempt), then
 * creates the hosted checkout when Square is available. Without credentials
 * the intent stays honestly recorded and the caller is told so.
 *
 * @return array{configured: bool, url: ?string, purchase_id: int}
 */
function bakery_sfb_buy_offering(PDO $db, $customerId, $offeringId) {
    require_once __DIR__ . '/square_config.php';
    $forceNoSquare = !empty($GLOBALS['bakery_sfb_payments_disabled']);
    $purchaseId = bakery_sfb_record_purchase_intent($db, $customerId, $offeringId);
    if ($forceNoSquare) {
        return ['configured' => false, 'url' => null, 'purchase_id' => $purchaseId];
    }
    try {
        $checkout = bakery_sfb_create_purchase_checkout($db, $purchaseId);
    } catch (Throwable $e) {
        // The intent stays recorded; the caller shows the honest notice.
        return ['configured' => false, 'url' => null, 'purchase_id' => $purchaseId, 'error' => $e->getMessage()];
    }
    return ['configured' => true, 'url' => $checkout['url'], 'purchase_id' => $purchaseId];
}

/**
 * Guarded state transitions. Each returns true only when it changed
 * something, so webhook replays are naturally idempotent.
 */
function bakery_sfb_set_purchase_status(PDO $db, $purchaseId, $status, $squarePaymentId = null, $manualNote = '', $actorUserId = null) {
    if (!bakery_sfb_payments_ready($db)) {
        return false;
    }
    $allowedFrom = [
        'paid' => ['intent', 'pending', 'failed'],
        'refunded' => ['paid'],
        'canceled' => ['pending', 'intent', 'paid'],
        'failed' => ['pending', 'intent'],
    ];
    $status = (string)$status;
    if (!isset($allowedFrom[$status])) {
        throw new InvalidArgumentException('Unknown purchase status');
    }
    $placeholders = implode(', ', array_fill(0, count($allowedFrom[$status]), '?'));
    $sql = 'UPDATE sfb_offering_purchases
            SET status = ?,
                square_payment_id = COALESCE(?, square_payment_id),
                manual_note = ' . ($manualNote !== '' && $manualNote !== null ? '?' : 'manual_note') . ',
                actor_user_id = COALESCE(?, actor_user_id)' .
            ($status === 'paid' ? ', paid_at = COALESCE(paid_at, NOW())' : '') . '
            WHERE id = ? AND status IN (' . $placeholders . ')';
    $values = [$status, $squarePaymentId];
    if ($manualNote !== '' && $manualNote !== null) {
        $values[] = $manualNote;
    }
    $actorId = (int)$actorUserId;
    $values[] = $actorId > 0 ? $actorId : null;
    $values[] = (int)$purchaseId;
    foreach ($allowedFrom[$status] as $from) {
        $values[] = $from;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($values);
    return $stmt->rowCount() > 0;
}

/** Paid, unexpired purchases for a customer: the entitlement set. */
function bakery_sfb_customer_entitlements(PDO $db, $customerId) {
    if (!bakery_sfb_payments_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT p.id AS purchase_id, p.offering_id, p.offering_title_snapshot, p.paid_at,
                COALESCE(o.entitlement_days, 0) AS entitlement_days
         FROM sfb_offering_purchases p
         LEFT JOIN sfb_offerings o ON o.id = p.offering_id
         WHERE p.customer_id = ? AND p.status = "paid"'
    );
    $stmt->execute([(int)$customerId]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $days = (int)$row['entitlement_days'];
        if ($days > 0) {
            $expires = strtotime((string)$row['paid_at']) + $days * 86400;
            if ($expires < time()) {
                continue;
            }
        }
        $out[] = $row;
    }
    return $out;
}

function bakery_sfb_customer_entitled_to(PDO $db, $customerId, $offeringId) {
    foreach (bakery_sfb_customer_entitlements($db, $customerId) as $row) {
        if ((string)$row['offering_id'] === (string)$offeringId) {
            return true;
        }
    }
    return false;
}

/** A baker's own purchase history, newest first. */
function bakery_sfb_customer_purchases(PDO $db, $customerId, $limit = 20) {
    if (!bakery_sfb_payments_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, offering_title_snapshot, price_cents_snapshot, currency_snapshot, status, checkout_url, created_at, paid_at
         FROM sfb_offering_purchases WHERE customer_id = ?
         ORDER BY id DESC LIMIT ' . max(1, (int)$limit)
    );
    $stmt->execute([(int)$customerId]);
    return $stmt->fetchAll();
}

/** Recent attempts across all bakers for the staff ops card. */
function bakery_sfb_recent_purchases(PDO $db, $limit = 15) {
    if (!bakery_sfb_payments_ready($db)) {
        return [];
    }
    return $db->query(
        'SELECT p.id, p.customer_id, c.name AS customer_name, p.offering_title_snapshot,
                p.price_cents_snapshot, p.currency_snapshot, p.status, p.manual_note, p.created_at, p.paid_at
         FROM sfb_offering_purchases p
         LEFT JOIN customers c ON c.id = p.customer_id
         ORDER BY p.id DESC LIMIT ' . max(1, (int)$limit)
    )->fetchAll();
}

/**
 * Education-side Square webhook truth. Handles payment.* and refund.* events;
 * dedupes on event_id against the shared square_webhook_events ledger.
 * Unknown or unmatched events are ignored — never guessed onto a purchase.
 */
function bakery_sfb_handle_education_webhook(PDO $db, array $payload): array {
    if (!bakery_sfb_payments_ready($db)) {
        return ['ok' => true, 'ignored' => true];
    }
    $eventId = (string)($payload['event_id'] ?? $payload['eventId'] ?? '');
    $type = (string)($payload['type'] ?? '');
    $object = $payload['data']['object'] ?? [];

    if ($eventId !== '' && table_exists($db, 'square_webhook_events')) {
        try {
            $db->prepare('INSERT INTO square_webhook_events (event_id, event_type) VALUES (?, ?)')
                ->execute([$eventId, $type]);
        } catch (PDOException $e) {
            return ['ok' => true, 'duplicate' => true, 'event_id' => $eventId];
        }
    }

    $payment = null;
    if (isset($object['payment']) && is_array($object['payment'])) {
        $payment = $object['payment'];
    } elseif (isset($object['id']) && (strpos($type, 'payment.') === 0)) {
        $payment = $object;
    }

    // Refund events carry the refunded payment id on data.object.refund.payment_id.
    if ($payment === null && isset($object['refund']['payment_id'])) {
        $refundPaymentId = (string)$object['refund']['payment_id'];
        $upd = $db->prepare(
            'UPDATE sfb_offering_purchases SET status = "refunded"
             WHERE square_payment_id = ? AND status IN ("paid")'
        );
        $upd->execute([$refundPaymentId]);
        return ['ok' => true, 'refunded' => true, 'event_type' => $type];
    }

    if ($payment === null) {
        return ['ok' => true, 'ignored' => true];
    }

    $paymentId = (string)($payment['id'] ?? '');
    $orderIdRef = (string)($payment['order_id'] ?? ($payment['orderId'] ?? ''));
    $paymentStatus = strtoupper((string)($payment['status'] ?? ''));

    $find = $db->prepare(
        'SELECT id, status FROM sfb_offering_purchases
         WHERE (square_order_id IS NOT NULL AND square_order_id = ?)
            OR (square_payment_id IS NOT NULL AND square_payment_id = ?)
         ORDER BY id DESC LIMIT 1'
    );
    $find->execute([$orderIdRef, $paymentId]);
    $purchase = $find->fetch();
    if (!$purchase) {
        return ['ok' => true, 'unmatched' => true, 'payment_id' => $paymentId];
    }

    $map = ['COMPLETED' => 'paid', 'FAILED' => 'failed', 'CANCELED' => 'canceled', 'VOIDED' => 'canceled'];
    if (!isset($map[$paymentStatus])) {
        return ['ok' => true, 'unmatched_status' => true, 'payment_status' => $paymentStatus];
    }
    $changed = bakery_sfb_set_purchase_status($db, (int)$purchase['id'], $map[$paymentStatus], $paymentId);
    return ['ok' => true, 'changed' => $changed, 'purchase_id' => (int)$purchase['id'], 'status' => $map[$paymentStatus]];
}

/** Circles the product wants. Extra values appear only after the ENUM migration. */
function bakery_sfb_community_category_catalog() {
    return [
        'starter',
        'formula',
        'fermentation',
        'shaping_baking',
        'general',
        'failures',
        'flours_mills',
        'weekend_schedule',
    ];
}

function bakery_sfb_community_category_enum_values(PDO $db = null) {
    $db = $db instanceof PDO ? $db : ($GLOBALS['db'] ?? null);
    $fallback = ['starter', 'formula', 'fermentation', 'shaping_baking', 'general'];
    if (!$db instanceof PDO || !table_exists($db, 'sfb_community_topics')) {
        return $fallback;
    }
    static $cache = [];
    $key = spl_object_id($db);
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    try {
        $stmt = $db->query("SHOW COLUMNS FROM sfb_community_topics LIKE 'category'");
        $row = $stmt ? $stmt->fetch() : null;
        $type = (string)($row['Type'] ?? '');
        if (preg_match_all("/'([^']+)'/", $type, $matches)) {
            return $cache[$key] = $matches[1];
        }
    } catch (Throwable $e) {
        // Keep the original five circles until the ENUM is readable.
    }
    return $cache[$key] = $fallback;
}

/** Community categories intentionally follow the way bakers diagnose a bake. */
function bakery_sfb_community_categories(PDO $db = null) {
    $db = $db instanceof PDO ? $db : ($GLOBALS['db'] ?? null);
    $wanted = bakery_sfb_community_category_catalog();
    if (!$db instanceof PDO) {
        return $wanted;
    }
    return array_values(array_intersect($wanted, bakery_sfb_community_category_enum_values($db)));
}

function bakery_sfb_community_category_key($category) {
    $category = (string)$category;
    return in_array($category, bakery_sfb_community_category_catalog(), true)
        ? 'sfb.community_category_' . $category
        : 'sfb.community_category_general';
}

function bakery_sfb_community_origin_filters() {
    return ['human', 'synthetic', 'both'];
}

function bakery_sfb_community_persist_origin_filter($origin) {
    $origin = in_array($origin, bakery_sfb_community_origin_filters(), true) ? $origin : 'both';
    $showSynthetic = $origin === 'human' ? '0' : '1';
    $_COOKIE['sfb_origin_filter'] = $origin;
    $_COOKIE['sfb_show_synthetic'] = $showSynthetic;
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return $origin;
    }
    $path = (defined('BASE_URL') && BASE_URL !== '') ? BASE_URL : '/';
    $opts = [
        'expires' => time() + (86400 * 365),
        'path' => $path,
        'secure' => function_exists('isHTTPS') && isHTTPS(),
        'httponly' => false,
        'samesite' => 'Lax',
    ];
    setcookie('sfb_origin_filter', $origin, $opts);
    setcookie('sfb_show_synthetic', $showSynthetic, $opts);
    return $origin;
}

/** Real / Synthetic / Both. Default Both. Persists the show-synthetic toggle. */
function bakery_sfb_community_origin_filter() {
    $allowed = bakery_sfb_community_origin_filters();
    $requested = strtolower(trim((string)($_GET['origin'] ?? '')));
    if (in_array($requested, $allowed, true)) {
        return bakery_sfb_community_persist_origin_filter($requested);
    }
    $toggle = strtolower(trim((string)($_GET['show_synthetic'] ?? $_POST['show_synthetic'] ?? '')));
    if ($toggle === '0' || $toggle === 'off' || $toggle === 'false') {
        return bakery_sfb_community_persist_origin_filter('human');
    }
    if ($toggle === '1' || $toggle === 'on' || $toggle === 'true') {
        return bakery_sfb_community_persist_origin_filter('both');
    }
    $cookie = strtolower(trim((string)($_COOKIE['sfb_origin_filter'] ?? '')));
    if (in_array($cookie, $allowed, true)) {
        return $cookie;
    }
    $show = strtolower(trim((string)($_COOKIE['sfb_show_synthetic'] ?? '')));
    if ($show === '0') {
        return 'human';
    }
    return 'both';
}

function bakery_sfb_community_like_pattern($search) {
    $search = trim((string)$search);
    if ($search === '') {
        return '';
    }
    if (function_exists('mb_substr')) {
        $search = mb_substr($search, 0, 80);
    } else {
        $search = substr($search, 0, 80);
    }
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
}

function bakery_sfb_community_origin_sql($originFilter, $customerAlias, $kindAlias, $kindReady) {
    $originFilter = in_array($originFilter, bakery_sfb_community_origin_filters(), true) ? $originFilter : 'both';
    if ($originFilter === 'both') {
        return '';
    }
    $originExpr = "COALESCE({$customerAlias}.sfb_origin, '')";
    if ($originFilter === 'synthetic') {
        return $kindReady
            ? "({$kindAlias}.author_kind <> 'coach' AND {$originExpr} = 'synthetic')"
            : "{$originExpr} = 'synthetic'";
    }
    return $kindReady
        ? "({$kindAlias}.author_kind = 'coach' OR {$originExpr} = 'human')"
        : "{$originExpr} = 'human'";
}

function bakery_sfb_community_context_params(array $overrides = []) {
    $params = [
        'category' => $overrides['category'] ?? (string)($_GET['category'] ?? 'all'),
        'origin' => $overrides['origin'] ?? bakery_sfb_community_origin_filter(),
        'q' => array_key_exists('q', $overrides) ? (string)$overrides['q'] : (string)($_GET['q'] ?? ''),
    ];
    if (($params['category'] ?? '') === '' || ($params['category'] ?? '') === 'all') {
        unset($params['category']);
    }
    if (($params['origin'] ?? '') === '' || ($params['origin'] ?? '') === 'both') {
        unset($params['origin']);
    }
    if (trim((string)($params['q'] ?? '')) === '') {
        unset($params['q']);
    }
    return $params;
}

function bakery_sfb_community_feed_url(array $overrides = []) {
    $params = bakery_sfb_community_context_params($overrides);
    if (!empty($overrides['compose'])) {
        $params['compose'] = '1';
    }
    if (!empty($overrides['batch'])) {
        $params['batch'] = (int)$overrides['batch'];
    }
    if (!empty($overrides['library'])) {
        $library = preg_replace('/[^a-z0-9_]/', '', strtolower((string)$overrides['library']));
        if ($library !== '') {
            $params['library'] = $library;
        }
    }
    $url = 'sfb_community.php' . ($params ? '?' . http_build_query($params) : '');
    if (!empty($overrides['hash'])) {
        $url .= '#' . ltrim((string)$overrides['hash'], '#');
    }
    return $url;
}

function bakery_sfb_community_topic_url($topicId, array $overrides = []) {
    $params = bakery_sfb_community_context_params($overrides);
    $params['topic'] = (int)$topicId;
    return 'sfb_community_topic.php?' . http_build_query($params);
}

function bakery_sfb_community_shared_batch_url($batchId, array $overrides = []) {
    $params = bakery_sfb_community_context_params($overrides);
    $params['batch'] = (int)$batchId;
    return 'sfb_shared_batch.php?' . http_build_query($params);
}

/** Live-feeling timestamps for the room. Falls back to a short date. */
function bakery_sfb_community_relative_time($datetime) {
    $ts = strtotime((string)$datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 45) {
        return function_exists('bakery_t') ? bakery_t('sfb.time_just_now') : 'just now';
    }
    if ($diff < 3600) {
        $count = max(1, intdiv($diff, 60));
        return function_exists('bakery_t') ? bakery_t('sfb.time_minutes_ago', ['count' => $count]) : ($count . 'm ago');
    }
    if ($diff < 86400) {
        $count = max(1, intdiv($diff, 3600));
        return function_exists('bakery_t') ? bakery_t('sfb.time_hours_ago', ['count' => $count]) : ($count . 'h ago');
    }
    if ($diff < 86400 * 7) {
        $count = max(1, intdiv($diff, 86400));
        return function_exists('bakery_t') ? bakery_t('sfb.time_days_ago', ['count' => $count]) : ($count . 'd ago');
    }
    return date('M j', $ts);
}

function bakery_sfb_community_preview($body, $width = 300) {
    if (is_array($body)) {
        $copy = bakery_sfb_community_topic_copy($body);
        $preview = $copy['body'];
    } else {
        $copy = bakery_sfb_community_topic_copy(['body' => (string)$body]);
        $preview = $copy['body'] !== '' ? $copy['body'] : (string)$body;
    }
    $width = max(40, (int)$width);
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($preview, 0, $width, '...');
    }
    if (strlen($preview) > $width) {
        return substr($preview, 0, $width - 3) . '...';
    }
    return $preview;
}

function bakery_sfb_community_author_name_sql(PDO $db) {
    if (column_exists($db, 'sfb_community_topics', 'author_user_id')) {
        return "COALESCE(c.name, u.display_name, '') AS author_name";
    }
    return "COALESCE(c.name, '') AS author_name";
}

function bakery_sfb_community_author_from_sql(PDO $db) {
    $sql = 'FROM sfb_community_topics t
            LEFT JOIN customers c ON c.id = t.author_customer_id';
    if (column_exists($db, 'sfb_community_topics', 'author_user_id')) {
        $sql .= '
            LEFT JOIN users u ON u.id = t.author_user_id';
    }
    return $sql;
}

/**
 * Baker rows must have a stored origin. Coach rows may have a null customer.
 * Unlabeled authors are excluded from the feed.
 */
/** Library pins live in the strip; keep them out of the activity feed unless searched. */
function bakery_sfb_community_exclude_library_sql($alias = 't') {
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias) ?: 't';
    return '(' . $alias . ".body IS NULL OR " . $alias . ".body NOT LIKE 'sfb.library.slug:%')";
}

function bakery_sfb_community_visible_author_clause(PDO $db) {
    $baker = 'c.id IS NOT NULL AND c.sf_baker_enabled = 1 AND c.is_active = 1';
    if (bakery_sfb_origin_column_ready($db)) {
        $baker .= " AND COALESCE(c.sfb_origin, '') IN ('human', 'synthetic')";
    }
    if (bakery_sfb_community_author_kind_ready($db)) {
        return "(t.author_kind = 'coach' OR ({$baker}))";
    }
    return $baker;
}

function bakery_sfb_community_order_sql(PDO $db) {
    $pinned = column_exists($db, 'sfb_community_topics', 'is_pinned')
        ? 't.is_pinned DESC, '
        : '';
    return 'ORDER BY ' . $pinned . 'COALESCE(reply_stats.last_reply_at, t.created_at) DESC, t.id DESC';
}

/** Baker community writes require a stored origin. Fail closed if the column is missing. */
function bakery_sfb_require_community_baker(PDO $db, $customerId) {
    $customerId = (int)$customerId;
    if ($customerId <= 0) {
        throw new InvalidArgumentException('Community posts need a baker identity');
    }
    if (!bakery_sfb_origin_column_ready($db)) {
        throw new RuntimeException('Community posting is paused until baker origin is installed');
    }
    $stmt = $db->prepare(
        'SELECT id, sf_baker_enabled, is_active, sfb_origin FROM customers WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$customerId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('Community posts need a baker identity');
    }
    $origin = strtolower(trim((string)($row['sfb_origin'] ?? '')));
    if ($origin !== 'human' && $origin !== 'synthetic') {
        throw new InvalidArgumentException('Community authors must have a stored origin');
    }
    return $row;
}

/** Latest community conversations, optionally narrowed to one diagnostic category. */
function bakery_sfb_community_topics(PDO $db, $category = 'all', $limit = 50, $originFilter = 'both', $search = '') {
    if (!bakery_sfb_community_identity_ready($db)) {
        return [];
    }

    $where = [bakery_sfb_community_visible_author_clause($db)];
    $params = [];
    if (in_array($category, bakery_sfb_community_categories($db), true)) {
        $where[] = 't.category = ?';
        $params[] = $category;
    }
    $originSql = bakery_sfb_community_origin_sql(
        $originFilter,
        'c',
        't',
        bakery_sfb_community_author_kind_ready($db)
    );
    if ($originSql !== '') {
        $where[] = $originSql;
    }
    $like = bakery_sfb_community_like_pattern($search);
    if ($like !== '') {
        $where[] = '(t.title LIKE ? OR t.body LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    } else {
        $where[] = bakery_sfb_community_exclude_library_sql('t');
    }

    $sql = 'SELECT t.*, ' . bakery_sfb_community_author_name_sql($db) . ', ' . bakery_sfb_origin_select_sql('c', $db) . ',
                   b.name AS batch_name, b.status AS batch_status, b.started_at AS batch_started_at,
                   COALESCE(s.formula_name, f.name) AS batch_formula_name,
                   shares.batch_id AS shared_batch_id,
                   COALESCE(reply_stats.reply_count, 0) AS reply_count,
                   reply_stats.last_reply_at
            ' . bakery_sfb_community_author_from_sql($db) . '
            LEFT JOIN sfb_batches b ON b.id = t.linked_batch_id
            LEFT JOIN sfb_formulas f ON f.id = b.formula_id
            LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
            LEFT JOIN sfb_batch_shares shares ON shares.batch_id = b.id
            LEFT JOIN (
                SELECT topic_id, COUNT(*) AS reply_count, MAX(created_at) AS last_reply_at
                FROM sfb_community_replies GROUP BY topic_id
            ) reply_stats ON reply_stats.topic_id = t.id
            WHERE ' . implode(' AND ', $where) . '
            ' . bakery_sfb_community_order_sql($db) . '
            LIMIT ' . max(1, min(100, (int)$limit));
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** One community conversation, including its optional public bake attachment. */
function bakery_sfb_community_topic(PDO $db, $topicId) {
    if (!bakery_sfb_community_identity_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT t.*, ' . bakery_sfb_community_author_name_sql($db) . ', ' . bakery_sfb_origin_select_sql('c', $db) . ',
                b.name AS batch_name, b.status AS batch_status, b.started_at AS batch_started_at,
                COALESCE(s.formula_name, f.name) AS batch_formula_name,
                shares.batch_id AS shared_batch_id,
                (SELECT COUNT(*) FROM sfb_community_replies r WHERE r.topic_id = t.id) AS reply_count
         ' . bakery_sfb_community_author_from_sql($db) . '
         LEFT JOIN sfb_batches b ON b.id = t.linked_batch_id
         LEFT JOIN sfb_formulas f ON f.id = b.formula_id
         LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
         LEFT JOIN sfb_batch_shares shares ON shares.batch_id = b.id
         WHERE t.id = ? AND ' . bakery_sfb_community_visible_author_clause($db) . '
         LIMIT 1'
    );
    $stmt->execute([(int)$topicId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_sfb_community_replies(PDO $db, $topicId) {
    if (!bakery_sfb_community_identity_ready($db)) {
        return [];
    }
    $originReady = bakery_sfb_origin_column_ready($db);
    $kindReady = column_exists($db, 'sfb_community_replies', 'author_kind');
    $baker = 'c.id IS NOT NULL AND c.sf_baker_enabled = 1 AND c.is_active = 1';
    if ($originReady) {
        $baker .= " AND COALESCE(c.sfb_origin, '') IN ('human', 'synthetic')";
    }
    $visible = $kindReady ? "(r.author_kind = 'coach' OR ({$baker}))" : $baker;
    $nameSql = column_exists($db, 'sfb_community_replies', 'author_user_id')
        ? "COALESCE(c.name, u.display_name, '') AS author_name"
        : "COALESCE(c.name, '') AS author_name";
    $userJoin = column_exists($db, 'sfb_community_replies', 'author_user_id')
        ? ' LEFT JOIN users u ON u.id = r.author_user_id'
        : '';
    $stmt = $db->prepare(
        'SELECT r.*, ' . $nameSql . ', ' . bakery_sfb_origin_select_sql('c', $db) . '
         FROM sfb_community_replies r
         LEFT JOIN customers c ON c.id = r.author_customer_id' . $userJoin . '
         WHERE r.topic_id = ? AND ' . $visible . '
         ORDER BY r.created_at ASC, r.id ASC'
    );
    $stmt->execute([(int)$topicId]);
    return $stmt->fetchAll();
}

/** Compact room activity: new posts, new shares, coach replies. */
function bakery_sfb_community_activity(PDO $db, $originFilter = 'both', $limit = 8) {
    if (!bakery_sfb_community_identity_ready($db)) {
        return [];
    }
    $limit = max(1, min(20, (int)$limit));
    $kindReady = bakery_sfb_community_author_kind_ready($db);
    $replyKindReady = bakery_sfb_community_author_kind_ready($db, 'sfb_community_replies');
    $originSql = bakery_sfb_community_origin_sql($originFilter, 'c', 't', $kindReady);
    $originClause = $originSql !== '' ? ' AND ' . $originSql : '';
    $items = [];

    $userJoin = column_exists($db, 'sfb_community_topics', 'author_user_id')
        ? ' LEFT JOIN users u ON u.id = t.author_user_id'
        : '';
    $nameSql = column_exists($db, 'sfb_community_topics', 'author_user_id')
        ? 'COALESCE(c.name, u.display_name, \'\')'
        : 'COALESCE(c.name, \'\')';
    $kindSql = $kindReady ? 't.author_kind' : "'baker'";
    $topicSql = 'SELECT t.id AS topic_id, t.title, t.body, t.created_at AS occurred_at,
                        ' . $nameSql . ' AS actor_name, ' . bakery_sfb_origin_select_sql('c', $db) . ',
                        ' . $kindSql . ' AS author_kind, NULL AS batch_id, NULL AS batch_name
                 FROM sfb_community_topics t
                 LEFT JOIN customers c ON c.id = t.author_customer_id' . $userJoin . '
                 WHERE ' . bakery_sfb_community_visible_author_clause($db) . $originClause . '
                   AND ' . bakery_sfb_community_exclude_library_sql('t') . '
                 ORDER BY t.created_at DESC, t.id DESC
                 LIMIT ' . $limit;
    foreach ($db->query($topicSql) as $row) {
        $row['activity_type'] = 'topic';
        $copy = bakery_sfb_community_topic_copy($row);
        $row['title'] = $copy['title'];
        $items[] = $row;
    }

    $shareOrigin = bakery_sfb_community_origin_sql($originFilter, 'c', 'c', false);
    $shareClause = $shareOrigin !== '' ? ' AND ' . $shareOrigin : '';
    $shareSql = 'SELECT COALESCE(t.id, 0) AS topic_id, COALESCE(t.title, b.name) AS title,
                        shares.shared_at AS occurred_at, c.name AS actor_name,
                        ' . bakery_sfb_origin_select_sql('c', $db) . ',
                        \'baker\' AS author_kind, b.id AS batch_id, b.name AS batch_name
                 FROM sfb_batch_shares shares
                 JOIN sfb_batches b ON b.id = shares.batch_id
                 JOIN customers c ON c.id = shares.customer_id AND c.sf_baker_enabled = 1 AND c.is_active = 1
                 LEFT JOIN sfb_community_topics t ON t.linked_batch_id = shares.batch_id
                 WHERE COALESCE(c.sfb_origin, \'\') IN (\'human\', \'synthetic\')' . $shareClause . '
                 ORDER BY shares.shared_at DESC
                 LIMIT ' . $limit;
    foreach ($db->query($shareSql) as $row) {
        $row['activity_type'] = 'share';
        $items[] = $row;
    }

    if ($replyKindReady && $originFilter !== 'synthetic') {
        $coachSql = 'SELECT r.topic_id, t.title, r.created_at AS occurred_at,
                            COALESCE(u.display_name, \'Sour Flour\') AS actor_name,
                            NULL AS sfb_origin, r.author_kind,
                            NULL AS batch_id, NULL AS batch_name
                     FROM sfb_community_replies r
                     JOIN sfb_community_topics t ON t.id = r.topic_id
                     LEFT JOIN users u ON u.id = r.author_user_id
                     WHERE r.author_kind = \'coach\'
                     ORDER BY r.created_at DESC, r.id DESC
                     LIMIT ' . $limit;
        foreach ($db->query($coachSql) as $row) {
            $row['activity_type'] = 'coach_reply';
            $items[] = $row;
        }
    }

    usort($items, static function ($left, $right) {
        return strcmp((string)$right['occurred_at'], (string)$left['occurred_at']);
    });
    return array_slice($items, 0, $limit);
}

/** Create a community conversation. Attaching a batch is an explicit sharing action. */
function bakery_sfb_create_community_topic(PDO $db, $customerId, $title, $body, $category = 'general', $batchId = 0) {
    if (!bakery_sfb_community_ready($db)) {
        throw new RuntimeException('The community forum is not ready yet');
    }

    $customerId = (int)$customerId;
    $batchId = (int)$batchId;
    $title = trim((string)$title);
    $body = trim((string)$body);
    $category = (string)$category;
    bakery_sfb_require_community_baker($db, $customerId);
    if ($title === '' || strlen($title) > 160) {
        throw new InvalidArgumentException('Give your discussion a title of 160 characters or fewer');
    }
    if ($body === '' || strlen($body) > 4000) {
        throw new InvalidArgumentException('Your post must be between 1 and 4,000 characters');
    }
    bakery_sfb_guard_synthetic_community_text($db, $customerId, $title, $body);
    if (!in_array($category, bakery_sfb_community_categories($db), true)) {
        $category = 'general';
    }
    if ($category === 'failures' && $batchId <= 0) {
        throw new InvalidArgumentException('Failure discussions need a linked bake card');
    }
    if ($batchId > 0 && !bakery_sfb_batch($db, $customerId, $batchId)) {
        throw new InvalidArgumentException('Choose one of your own batches to share');
    }

    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $columns = 'author_customer_id, linked_batch_id, category, title, body';
        $placeholders = '?, ?, ?, ?, ?';
        $params = [$customerId, $batchId > 0 ? $batchId : null, $category, $title, $body];
        if (bakery_sfb_community_author_kind_ready($db)) {
            $columns .= ', author_kind';
            $placeholders .= ', ?';
            $params[] = 'baker';
        }
        $topic = $db->prepare(
            "INSERT INTO sfb_community_topics ({$columns}) VALUES ({$placeholders})"
        );
        $topic->execute($params);
        $topicId = (int)$db->lastInsertId();

        if ($batchId > 0) {
            bakery_sfb_share_batch($db, $customerId, $batchId);
        }

        if ($ownsTransaction) {
            $db->commit();
        }
        return $topicId;
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function bakery_sfb_add_community_reply(PDO $db, $topicId, $customerId, $body, $authorKind = 'baker', $userId = 0) {
    if (!bakery_sfb_community_ready($db)) {
        throw new RuntimeException('The community forum is not ready yet');
    }
    $topic = bakery_sfb_community_topic($db, $topicId);
    if (!$topic) {
        throw new InvalidArgumentException('That discussion was not found');
    }
    if ((int)$topic['is_locked'] === 1) {
        throw new RuntimeException('This discussion is closed');
    }
    $body = trim((string)$body);
    if ($body === '' || strlen($body) > 4000) {
        throw new InvalidArgumentException('Your reply must be between 1 and 4,000 characters');
    }
    $authorKind = strtolower(trim((string)$authorKind)) === 'coach' ? 'coach' : 'baker';
    $customerId = (int)$customerId;
    $userId = (int)$userId;

    if ($authorKind === 'coach') {
        if (!bakery_sfb_community_author_kind_ready($db, 'sfb_community_replies')) {
            throw new RuntimeException('Coach replies need author_kind and author_user_id');
        }
        if ($userId <= 0) {
            throw new InvalidArgumentException('A staff account is required to reply as a coach');
        }
        $stmt = $db->prepare(
            'INSERT INTO sfb_community_replies
             (topic_id, author_customer_id, author_kind, author_user_id, body)
             VALUES (?, NULL, \'coach\', ?, ?)'
        );
        $stmt->execute([(int)$topicId, $userId, $body]);
        return (int)$db->lastInsertId();
    }

    bakery_sfb_require_community_baker($db, $customerId);
    bakery_sfb_guard_synthetic_community_text($db, $customerId, '', $body);
    $columns = 'topic_id, author_customer_id, body';
    $placeholders = '?, ?, ?';
    $params = [(int)$topicId, $customerId, $body];
    if (column_exists($db, 'sfb_community_replies', 'author_kind')) {
        $columns .= ', author_kind';
        $placeholders .= ', ?';
        $params[] = 'baker';
    }
    $stmt = $db->prepare(
        "INSERT INTO sfb_community_replies ({$columns}) VALUES ({$placeholders})"
    );
    $stmt->execute($params);
    return (int)$db->lastInsertId();
}

/** A read-only community-safe view of an explicitly shared batch. */
function bakery_sfb_shared_batch(PDO $db, $batchId) {
    if (!bakery_sfb_community_identity_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT b.*, c.name AS baker_name, shares.shared_at, ' . bakery_sfb_origin_select_sql('c', $db) . ',
                COALESCE(s.formula_name, f.name) AS formula_name
         FROM sfb_batch_shares shares
         JOIN sfb_batches b ON b.id = shares.batch_id
         JOIN customers c ON c.id = b.customer_id AND c.sf_baker_enabled = 1 AND c.is_active = 1
         LEFT JOIN sfb_formulas f ON f.id = b.formula_id
         LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
         WHERE shares.batch_id = ? LIMIT 1'
    );
    $stmt->execute([(int)$batchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Public discussions that attached this shared bake. */
function bakery_sfb_community_topics_for_batch(PDO $db, $batchId, $limit = 8) {
    $batchId = (int)$batchId;
    if ($batchId <= 0 || !bakery_sfb_community_identity_ready($db)) {
        return [];
    }
    $sql = 'SELECT t.*, ' . bakery_sfb_community_author_name_sql($db) . ', ' . bakery_sfb_origin_select_sql('c', $db) . ',
                   COALESCE(reply_stats.reply_count, 0) AS reply_count,
                   reply_stats.last_reply_at
            ' . bakery_sfb_community_author_from_sql($db) . '
            LEFT JOIN (
                SELECT topic_id, COUNT(*) AS reply_count, MAX(created_at) AS last_reply_at
                FROM sfb_community_replies GROUP BY topic_id
            ) reply_stats ON reply_stats.topic_id = t.id
            WHERE t.linked_batch_id = ? AND ' . bakery_sfb_community_visible_author_clause($db) . '
            ' . bakery_sfb_community_order_sql($db) . '
            LIMIT ' . max(1, min(20, (int)$limit));
    $stmt = $db->prepare($sql);
    $stmt->execute([$batchId]);
    return $stmt->fetchAll();
}

function bakery_sfb_community_duration_label($startedAt, $endedAt) {
    if (!$startedAt || !$endedAt) {
        return null;
    }
    $seconds = max(0, strtotime((string)$endedAt) - strtotime((string)$startedAt));
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    if ($hours > 0) {
        return $hours . 'h ' . $minutes . 'm';
    }
    return $minutes . 'm';
}

/**
 * Compact, community-safe bake facts for in-thread review.
 * Returns null until the owner has shared the batch.
 */
function bakery_sfb_community_bake_summary(PDO $db, $batchId) {
    $batch = bakery_sfb_shared_batch($db, $batchId);
    if (!$batch) {
        return null;
    }
    $batchId = (int)$batch['id'];
    $temps = bakery_sfb_batch_temps($db, $batchId);
    $tempValues = array_map(static function (array $temp): float {
        return (float)$temp['temp_f'];
    }, $temps);
    $photos = bakery_sfb_batch_photos($db, $batchId);
    return [
        'batch' => $batch,
        'formula_name' => (string)($batch['formula_name'] ?? ''),
        'turn_count' => count(bakery_sfb_batch_turns($db, $batchId)),
        'temp_min' => $tempValues ? min($tempValues) : null,
        'temp_max' => $tempValues ? max($tempValues) : null,
        'oven_temp_f' => $batch['oven_temp_f'] !== null ? (float)$batch['oven_temp_f'] : null,
        'loaf_count' => (int)($batch['loaf_count'] ?? 0),
        'bulk_duration' => bakery_sfb_community_duration_label($batch['bulk_started_at'] ?? null, $batch['bulk_ended_at'] ?? null),
        'bake_duration' => bakery_sfb_community_duration_label($batch['bake_started_at'] ?? null, $batch['bake_ended_at'] ?? null),
        'photos' => array_slice($photos, 0, 3),
        'photo_count' => count($photos),
    ];
}

function bakery_sfb_batch_share(PDO $db, $batchId) {
    if (!bakery_sfb_community_ready($db)) {
        return null;
    }
    $stmt = $db->prepare('SELECT batch_id, customer_id, shared_at FROM sfb_batch_shares WHERE batch_id = ? LIMIT 1');
    $stmt->execute([(int)$batchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Admin-only batch lookup across every enabled SF Baker account. */
function bakery_sfb_admin_batch(PDO $db, $batchId) {
    if (!bakery_sfb_tables_ready($db)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT b.*, c.name AS baker_name, ' . bakery_sfb_origin_select_sql('c', $db) . ',
                COALESCE(s.formula_name, f.name) AS formula_name
         FROM sfb_batches b
         JOIN customers c ON c.id = b.customer_id AND c.sf_baker_enabled = 1
         LEFT JOIN sfb_formulas f ON f.id = b.formula_id
         LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
         WHERE b.id = ? LIMIT 1'
    );
    $stmt->execute([(int)$batchId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Enabled SF Bakers for the administrator filter and note composer. */
function bakery_sfb_admin_bakers(PDO $db) {
    if (!bakery_sfb_tables_ready($db)) {
        return [];
    }
    $originGroup = bakery_sfb_origin_column_ready($db) ? ', c.sfb_origin' : '';
    $stmt = $db->query(
        'SELECT c.id, c.name, ' . bakery_sfb_origin_select_sql('c', $db) . ',
                COUNT(b.id) AS batch_count,
                SUM(CASE WHEN b.status = "in_progress" THEN 1 ELSE 0 END) AS active_batch_count,
                COALESCE(SUM(CASE WHEN b.status = "completed" THEN b.loaf_count ELSE 0 END), 0) AS loaf_total
         FROM customers c
         LEFT JOIN sfb_batches b ON b.customer_id = c.id
         WHERE c.sf_baker_enabled = 1
         GROUP BY c.id, c.name' . $originGroup . '
         ORDER BY c.name'
    );
    return $stmt->fetchAll();
}

/** At-a-glance figures used by the administrator engagement workspace. */
function bakery_sfb_admin_summary(PDO $db) {
    $summary = [
        'bakers' => 0,
        'batches' => 0,
        'active_batches' => 0,
        'completed_batches' => 0,
        'open_questions' => 0,
        'completed_loaves' => 0,
    ];
    if (!bakery_sfb_tables_ready($db)) {
        return $summary;
    }
    $stmt = $db->query(
        'SELECT COUNT(DISTINCT c.id) AS bakers,
                COUNT(b.id) AS batches,
                COALESCE(SUM(CASE WHEN b.status = "in_progress" THEN 1 ELSE 0 END), 0) AS active_batches,
                COALESCE(SUM(CASE WHEN b.status = "completed" THEN 1 ELSE 0 END), 0) AS completed_batches,
                COALESCE(SUM(CASE WHEN b.status = "completed" THEN b.loaf_count ELSE 0 END), 0) AS completed_loaves
         FROM customers c
         LEFT JOIN sfb_batches b ON b.customer_id = c.id
         WHERE c.sf_baker_enabled = 1'
    );
    $row = $stmt->fetch();
    if ($row) {
        foreach (array_keys($summary) as $key) {
            if (array_key_exists($key, $row)) {
                $summary[$key] = (int)$row[$key];
            }
        }
    }
    if (bakery_sfb_discussion_ready($db)) {
        $questionCount = $db->query(
            'SELECT COUNT(*)
             FROM sfb_batch_messages m
             JOIN sfb_batches b ON b.id = m.batch_id
             JOIN customers c ON c.id = b.customer_id AND c.sf_baker_enabled = 1
             WHERE m.author_type = "baker" AND m.message_type = "question" AND m.is_resolved = 0'
        )->fetchColumn();
        $summary['open_questions'] = (int)$questionCount;
    }
    return $summary;
}

/** Open baker questions, oldest first, for the administrator response queue. */
function bakery_sfb_open_questions(PDO $db, $limit = 30) {
    if (!bakery_sfb_discussion_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT m.*, b.name AS batch_name, b.status AS batch_status, b.started_at,
                c.id AS baker_id, c.name AS baker_name
         FROM sfb_batch_messages m
         JOIN sfb_batches b ON b.id = m.batch_id
         JOIN customers c ON c.id = b.customer_id AND c.sf_baker_enabled = 1
         WHERE m.author_type = "baker" AND m.message_type = "question" AND m.is_resolved = 0
         ORDER BY m.created_at ASC, m.id ASC
         LIMIT ' . max(1, min(100, (int)$limit))
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Batch list for the administrator overview. */
function bakery_sfb_admin_batches(PDO $db, $customerId = 0, $status = 'all', $engagement = 'all', $limit = 250) {
    if (!bakery_sfb_tables_ready($db) || !bakery_sfb_discussion_ready($db)) {
        return [];
    }
    $where = ['c.sf_baker_enabled = 1'];
    $params = [];
    $customerId = (int)$customerId;
    if ($customerId > 0) {
        $where[] = 'b.customer_id = ?';
        $params[] = $customerId;
    }
    if (in_array($status, ['in_progress', 'completed', 'abandoned'], true)) {
        $where[] = 'b.status = ?';
        $params[] = $status;
    }
    if ($engagement === 'needs_response') {
        $where[] = 'EXISTS (
            SELECT 1 FROM sfb_batch_messages q
            WHERE q.batch_id = b.id
              AND q.author_type = "baker"
              AND q.message_type = "question"
              AND q.is_resolved = 0
        )';
    } elseif ($engagement === 'with_activity') {
        $where[] = 'EXISTS (SELECT 1 FROM sfb_batch_messages m WHERE m.batch_id = b.id)';
    }

    $limit = (int)$limit;
    $limitSql = $limit > 0 ? ' LIMIT ' . min(1000, $limit) : '';
    $sql = 'SELECT b.*, c.name AS baker_name,
                   COALESCE(s.formula_name, f.name) AS formula_name,
                   (SELECT COUNT(*) FROM sfb_batch_messages m WHERE m.batch_id = b.id) AS message_count,
                   (SELECT COUNT(*) FROM sfb_batch_messages q
                    WHERE q.batch_id = b.id AND q.author_type = "baker"
                      AND q.message_type = "question" AND q.is_resolved = 0) AS open_question_count,
                   (SELECT MAX(m.created_at) FROM sfb_batch_messages m WHERE m.batch_id = b.id) AS last_message_at
            FROM sfb_batches b
            JOIN customers c ON c.id = b.customer_id
            LEFT JOIN sfb_formulas f ON f.id = b.formula_id
            LEFT JOIN sfb_batch_formula_snapshots s ON s.batch_id = b.id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY b.status = "in_progress" DESC, b.updated_at DESC, b.id DESC' . $limitSql;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Current phase of a batch, derived from which timestamps are filled in. */
function bakery_sfb_batch_phase(array $batch) {
    if (($batch['status'] ?? '') === 'completed') {
        return 'done';
    }
    if (($batch['status'] ?? '') === 'abandoned') {
        return 'abandoned';
    }
    if (!empty($batch['bake_started_at'])) {
        return 'bake';
    }
    if (!empty($batch['shaped_at'])) {
        return 'shape';
    }
    if (!empty($batch['bulk_started_at']) || !empty($batch['mix_completed_at'])) {
        return 'development';
    }
    return 'mix';
}

function bakery_sfb_phase_label($phase) {
    $keys = [
        'mix' => 'sfb.phase_mix',
        'development' => 'sfb.phase_development',
        'shape' => 'sfb.phase_shape',
        'bake' => 'sfb.phase_bake',
        'done' => 'sfb.phase_done',
        'abandoned' => 'sfb.phase_abandoned',
    ];
    if (isset($keys[$phase]) && function_exists('bakery_t')) {
        return bakery_t($keys[$phase]);
    }
    $fallbacks = [
        'mix' => 'Mix',
        'development' => 'Bulk fermentation',
        'shape' => 'Shape',
        'bake' => 'Bake',
        'done' => 'Complete',
        'abandoned' => 'Set aside',
    ];
    return $fallbacks[$phase] ?? ucfirst((string)$phase);
}

function bakery_sfb_turn_types() {
    return [
        'stretch_fold' => 'Stretch & fold',
        'coil_fold' => 'Coil fold',
        'lamination' => 'Lamination',
        'slap_fold' => 'Slap & fold',
        'other' => 'Other',
    ];
}

function bakery_sfb_turn_type_label($type) {
    $types = bakery_sfb_turn_types();
    return $types[$type] ?? ucfirst(str_replace('_', ' ', (string)$type));
}

/* ── Journey to 1,000 ─────────────────────────────────────────────────── */

/** Total loaves from completed batches (the journey counter). */
function bakery_sfb_loaf_total(PDO $db, $customerId) {
    if (!table_exists($db, 'sfb_batches')) {
        return 0;
    }
    $stmt = $db->prepare(
        'SELECT COALESCE(SUM(loaf_count), 0) FROM sfb_batches
         WHERE customer_id = ? AND status = "completed"'
    );
    $stmt->execute([(int)$customerId]);
    return (int)$stmt->fetchColumn();
}

function bakery_sfb_journey(PDO $db, $customerId) {
    $goal = bakery_sfb_loaf_goal();
    $total = bakery_sfb_loaf_total($db, $customerId);
    return [
        'goal' => $goal,
        'total' => $total,
        'percent' => min(100, (int)round($total / $goal * 100)),
        'remaining' => max(0, $goal - $total),
        'reached' => $total >= $goal,
    ];
}

/**
 * Community-wide 1,000-loaf traction. Synthetics are excluded.
 * Prompt 2 displays this; the filter is sfb_origin = human.
 */
function bakery_sfb_human_loaf_total(PDO $db) {
    if (!table_exists($db, 'sfb_batches') || !table_exists($db, 'customers')) {
        return 0;
    }
    $sql = 'SELECT COALESCE(SUM(b.loaf_count), 0)
            FROM sfb_batches b
            JOIN customers c ON c.id = b.customer_id
            WHERE b.status = "completed"' . bakery_sfb_human_origin_clause('c', $db);
    return (int)$db->query($sql)->fetchColumn();
}
