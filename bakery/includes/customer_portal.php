<?php
/**
 * Customer portal authentication, pricing, and pause helpers.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/brand.php';

/** Scripts for the customer self-service portal (separate from staff auth). */
function bakery_customer_portal_scripts() {
    // Note: the security gate (bakery_enforce_request_security) also treats any
    // customer_portal_*.php script as portal territory by naming convention.
    // This list is for portal pages whose names do not follow that pattern.
    return [
        'customer_portal.php',
        'customer_portal_regular.php',
        'customer_portal_calendar.php',
        'customer_portal_history.php',
        'customer_portal_delivery.php',
        'customer_portal_deliveries.php',
        'customer_portal_delivery_photo.php',
        'customer_billing.php',
        'customer_portal_billing.php',
        'customer_portal_statement.php',
        'customer_invoice.php',
        'customer_billing_export.php',
        'customer_upcoming_edit.php',
        'customer_upcoming.php',
        'customer_catalog.php',
        'customer_portal_account.php',
        'customer_portal_notifications.php',
        'customer_portal_issue.php',
        'customer_portal_api.php',
        'customer_portal_tip.php',
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

/** Public portal entry points (no staff login required). */
function bakery_customer_portal_public_scripts() {
    return [
        'customer_login.php',
        'customer_qr_login.php',
        'customer_portal_login.php',
        'customer_portal_logout.php',
    ];
}

/** Canonical customer portal login URL. */
function bakery_customer_login_url() {
    return BASE_URL . 'customer_login.php';
}

/** Normalize phone to digits only for login matching. */
function bakery_normalize_phone($phone) {
    $digits = preg_replace('/\D/', '', (string)$phone);
    if ($digits === null) {
        return '';
    }
    // Sour Flour currently serves US phone numbers. Keep the account key
    // consistent whether someone enters +1, 1, or just the local 10 digits.
    if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
        $digits = substr($digits, 1);
    }
    return preg_match('/^\d{10}$/', $digits) ? $digits : '';
}

/** Monday of the week containing the given date. */
function bakery_week_start_monday($date = null) {
    $ts = $date === null ? time() : strtotime((string)$date);
    $dow = (int)date('N', $ts);
    return date('Y-m-d', strtotime('-' . ($dow - 1) . ' days', $ts));
}

/** Ensure portal schema columns exist (idempotent for pre-migration deploys). */
function bakery_ensure_portal_schema(PDO $db) {
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

    $columns = [
        'portal_phone' => "ALTER TABLE customers ADD COLUMN portal_phone VARCHAR(20) NULL DEFAULT NULL AFTER phone",
        'portal_phone_key' => "ALTER TABLE customers ADD COLUMN portal_phone_key CHAR(10) NULL DEFAULT NULL AFTER portal_phone",
        'portal_code' => "ALTER TABLE customers ADD COLUMN portal_code CHAR(4) NULL DEFAULT NULL",
        'portal_code_hash' => "ALTER TABLE customers ADD COLUMN portal_code_hash VARCHAR(255) NULL DEFAULT NULL AFTER portal_code",
        'portal_enabled' => "ALTER TABLE customers ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 0",
        'pricing_tier' => "ALTER TABLE customers ADD COLUMN pricing_tier ENUM('retail', 'wholesale', 'custom') NOT NULL DEFAULT 'retail'",
    ];
    foreach ($columns as $col => $sql) {
        if (!bakery_portal_column_exists($db, 'customers', $col)) {
            try {
                $db->exec($sql);
            } catch (Throwable $e) {
                // Column may have been added concurrently.
            }
        }
    }

    if (bakery_portal_column_exists($db, 'customers', 'portal_phone_key')) {
        try {
            $db->exec('CREATE UNIQUE INDEX uq_customers_portal_phone_key ON customers (portal_phone_key)');
        } catch (Throwable $e) {
            // The index may already exist. New signups still check for an
            // existing phone before attempting an insert.
        }
    }
    if (bakery_portal_column_exists($db, 'customers', 'portal_code')) {
        try {
            $db->exec('CREATE UNIQUE INDEX uq_customers_portal_code ON customers (portal_code)');
        } catch (Throwable $e) {
            // Existing installations may already have the index.
        }
    }

    if (!table_exists($db, 'customer_product_prices')) {
        $schema = dirname(__DIR__) . '/database/schema/018_customer_portal.sql';
        if (is_file($schema)) {
            $sql = file_get_contents($schema);
            if ($sql !== false) {
                foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $statement) {
                    if ($statement !== '') {
                        try {
                            $db->exec($statement);
                        } catch (Throwable $e) {
                            // Idempotent migration — ignore duplicate column/table errors.
                        }
                    }
                }
            }
        }
    }

    $done = true;
}

function bakery_portal_column_exists(PDO $db, $table, $column) {
    if (!function_exists('table_exists') || !table_exists($db, $table)) {
        return false;
    }
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([(string)$table, (string)$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function bakery_portal_customer_id() {
    return !empty($_SESSION['portal_customer_id']) ? (int)$_SESSION['portal_customer_id'] : 0;
}

function bakery_portal_customer(PDO $db) {
    $id = bakery_portal_customer_id();
    if ($id <= 0) {
        return null;
    }
    bakery_ensure_portal_schema($db);
    $stmt = $db->prepare(
        'SELECT id, name, phone, portal_phone, portal_enabled, pricing_tier, default_pan_dulce_price, is_active
         FROM customers WHERE id = ? AND portal_enabled = 1 AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function bakery_portal_start_session(array $row, string $credentialCode = '', array $auditExtra = []) {
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        session_start();
    }
    // Keep in-progress public funnels (starter jar draft, flash) across the
    // session id rotate so signup does not orphan the request.
    $carryKeys = ['starter_jar_draft', 'starter_jar_flash'];
    $carry = [];
    foreach ($carryKeys as $key) {
        if (array_key_exists($key, $_SESSION ?? [])) {
            $carry[$key] = $_SESSION[$key];
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent() && PHP_SAPI !== 'cli') {
        session_regenerate_id(true);
    }
    foreach ($carry as $key => $value) {
        $_SESSION[$key] = $value;
    }

    $_SESSION['portal_customer_id'] = (int)$row['id'];
    $_SESSION['portal_customer_name'] = $row['name'];
    $_SESSION['portal_login_at'] = time();
    $_SESSION['portal_last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        $identity = array_merge([
            'customer_id' => (int)$row['id'],
            'principal' => (string)$row['name'],
            'credential_code' => $credentialCode,
        ], $auditExtra);
        bakery_login_audit_start($GLOBALS['db'], 'customer', $identity);
    }

    if (function_exists('bakery_apply_locale_default_for_user')) {
        bakery_apply_locale_default_for_user(null, true);
    }
}

function bakery_portal_login(PDO $db, $phone, $code) {
    bakery_ensure_portal_schema($db);

    if (!function_exists('bakery_login_attempt_allowed') || !bakery_login_attempt_allowed($db, 'customer')) {
        return false;
    }

    $phoneNorm = bakery_normalize_phone($phone);
    $code = bakery_normalize_login_code($code);
    if ($phoneNorm === '' || $code === '') {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT id, name, portal_code, portal_code_hash
         FROM customers
         WHERE portal_enabled = 1 AND is_active = 1
           AND (
             portal_phone_key = ?
             OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(portal_phone, '-', ''), '(', ''), ')', ''), ' ', ''), '+', '') IN (?, ?)
             OR (portal_phone IS NULL OR portal_phone = '')
               AND REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, '-', ''), '(', ''), ')', ''), ' ', ''), '+', '') IN (?, ?)
           )
         LIMIT 1"
    );
    $stmt->execute([$phoneNorm, $phoneNorm, '1' . $phoneNorm, $phoneNorm, '1' . $phoneNorm]);
    $row = $stmt->fetch();
    if (!$row || !bakery_portal_code_matches($row, $code)) {
        return false;
    }

    bakery_portal_start_session($row, $code);
    return true;
}

/** Verify a modern PIN hash, with a safe fallback for pre-hash accounts. */
function bakery_portal_code_matches(array $customer, string $code): bool {
    $hash = trim((string)($customer['portal_code_hash'] ?? ''));
    if ($hash !== '') {
        return password_verify($code, $hash);
    }
    $legacy = bakery_normalize_login_code($customer['portal_code'] ?? '');
    return $legacy !== '' && hash_equals($legacy, $code);
}

/** A PIN is the returning-customer credential, so it must be unique. */
function bakery_portal_code_available(PDO $db, string $code, int $exceptCustomerId = 0): bool {
    $stmt = $db->prepare('SELECT id FROM customers WHERE portal_code = ? AND id <> ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$code, $exceptCustomerId]);
    return !$stmt->fetchColumn();
}

/**
 * Find an existing account by its phone number. This intentionally includes
 * inactive portal accounts so a real customer record is resumed, not cloned.
 */
function bakery_portal_find_by_phone(PDO $db, string $phoneNorm, bool $forUpdate = false): ?array {
    $lock = $forUpdate ? ' FOR UPDATE' : '';
    $stmt = $db->prepare(
        "SELECT id, name, phone, portal_phone, portal_phone_key, portal_code, portal_code_hash,
                portal_enabled, is_active
         FROM customers
         WHERE portal_phone_key = ?
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(NULLIF(portal_phone, ''), phone), '-', ''), '(', ''), ')', ''), ' ', ''), '+', '') IN (?, ?)
         ORDER BY id ASC LIMIT 1{$lock}"
    );
    $stmt->execute([$phoneNorm, $phoneNorm, '1' . $phoneNorm]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Make the required name unique while keeping it obviously provisional. */
function bakery_portal_provisional_name(PDO $db, string $phoneNorm): string {
    $base = 'Baker ' . $phoneNorm;
    $name = $base;
    $suffix = 2;
    $exists = $db->prepare('SELECT 1 FROM customers WHERE name = ? LIMIT 1');
    while (true) {
        $exists->execute([$name]);
        if (!$exists->fetchColumn()) {
            return $name;
        }
        $name = $base . ' ' . $suffix;
        $suffix++;
    }
}

/**
 * Create the smallest possible baking account. Returning customers sign in
 * through bakery_portal_login_by_code(), so a phone is only needed once.
 */
function bakery_portal_sign_in_or_register(PDO $db, $phone, $code): array {
    bakery_ensure_portal_schema($db);
    $phoneNorm = bakery_normalize_phone($phone);
    $code = bakery_normalize_login_code($code);
    if ($phoneNorm === '' || $code === '') {
        return ['success' => false, 'error' => 'Enter a 10-digit phone number and a 4-digit PIN.'];
    }
    if (!function_exists('bakery_login_attempt_allowed') || !bakery_login_attempt_allowed($db, 'customer')) {
        return ['success' => false, 'error' => 'Please wait a few minutes and try again.'];
    }

    $db->beginTransaction();
    try {
        $customer = bakery_portal_find_by_phone($db, $phoneNorm, true);
        $firstBatch = false;
        if ($customer) {
            if (!(int)$customer['is_active']) {
                $db->rollBack();
                return ['success' => false, 'error' => 'This account is unavailable. Please contact Sour Flour.'];
            }
            $hasCredential = bakery_normalize_login_code($customer['portal_code'] ?? '') !== '';
            if ($hasCredential) {
                $db->rollBack();
                return ['success' => false, 'error' => 'That phone number already has an account. Sign in with its 4-digit code.'];
            }
            if (!bakery_portal_code_available($db, $code, (int)$customer['id'])) {
                $db->rollBack();
                return ['success' => false, 'error' => 'That 4-digit code is already in use. Choose another one.'];
            }

            $sets = ['portal_phone = ?', 'portal_phone_key = ?', 'portal_enabled = 1'];
            $values = ['+1' . $phoneNorm, $phoneNorm];
            $firstBatch = true;
            $sets[] = 'portal_code = ?';
            $sets[] = 'portal_code_hash = ?';
            $values[] = $code;
            $values[] = password_hash($code, PASSWORD_DEFAULT);
            if (bakery_portal_column_exists($db, 'customers', 'sf_baker_enabled')) {
                $sets[] = 'sf_baker_enabled = 1';
            }
            $values[] = (int)$customer['id'];
            $db->prepare('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
            $customer['portal_enabled'] = 1;
        } else {
            $firstBatch = true;
            if (!bakery_portal_code_available($db, $code)) {
                $db->rollBack();
                return ['success' => false, 'error' => 'That 4-digit code is already in use. Choose another one.'];
            }
            $fields = [
                'name' => bakery_portal_provisional_name($db, $phoneNorm),
                'phone' => '+1' . $phoneNorm,
                'portal_phone' => '+1' . $phoneNorm,
                'portal_phone_key' => $phoneNorm,
                'portal_code' => $code,
                'portal_code_hash' => password_hash($code, PASSWORD_DEFAULT),
                'portal_enabled' => 1,
                'pricing_tier' => 'retail',
                'is_active' => 1,
            ];
            if (bakery_portal_column_exists($db, 'customers', 'sf_baker_enabled')) {
                $fields['sf_baker_enabled'] = 1;
            }
            if (bakery_portal_column_exists($db, 'customers', 'sfb_origin')) {
                $fields['sfb_origin'] = 'human';
            }
            $columns = array_keys($fields);
            $db->prepare('INSERT INTO customers (' . implode(', ', $columns) . ') VALUES ('
                . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute(array_values($fields));
            $customer = ['id' => (int)$db->lastInsertId(), 'name' => $fields['name'], 'portal_enabled' => 1];
        }
        $db->commit();
        bakery_portal_start_session($customer, $code, ['started_by' => 'phone_pin']);
        return ['success' => true, 'first_batch' => $firstBatch, 'customer' => $customer];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Customer phone/PIN account error: ' . $e->getMessage());
        return ['success' => false, 'error' => 'We could not set up your account right now. Please try again.'];
    }
}

/** Sign in with 4-digit passcode only (must match exactly one enabled customer). */
function bakery_portal_login_by_code(PDO $db, $code) {
    bakery_ensure_portal_schema($db);

    if (!function_exists('bakery_login_attempt_allowed') || !bakery_login_attempt_allowed($db, 'customer')) {
        return false;
    }

    $code = bakery_normalize_login_code($code);
    if ($code === '') {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT id, name
         FROM customers
         WHERE portal_enabled = 1 AND is_active = 1 AND portal_code = ?
         LIMIT 2"
    );
    $stmt->execute([$code]);
    $rows = $stmt->fetchAll();
    if (count($rows) !== 1) {
        return false;
    }

    bakery_portal_start_session($rows[0], $code);
    return true;
}

function bakery_portal_logout() {
    if (function_exists('bakery_login_audit_current_id') && bakery_login_audit_current_id()
        && isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        bakery_login_audit_close($GLOBALS['db']);
    }
    unset(
        $_SESSION['portal_customer_id'],
        $_SESSION['portal_customer_name'],
        $_SESSION['portal_login_at'],
        $_SESSION['portal_last_activity']
    );
}

function bakery_portal_touch_session() {
    $now = time();
    if (empty($_SESSION['portal_login_at'])) {
        return;
    }
    $maxIdle = 8 * 60 * 60;
    $maxAbsolute = 12 * 60 * 60;
    if (($now - (int)$_SESSION['portal_login_at']) > $maxAbsolute) {
        bakery_portal_logout();
        return;
    }
    if (isset($_SESSION['portal_last_activity']) &&
        ($now - (int)$_SESSION['portal_last_activity']) > $maxIdle) {
        bakery_portal_logout();
        return;
    }
    $_SESSION['portal_last_activity'] = $now;
}

function bakery_require_portal_login(PDO $db = null) {
    bakery_portal_touch_session();
    if (bakery_portal_customer_id() <= 0) {
        if (is_ajax_request() || bakery_wants_json()) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Portal login required']);
            exit;
        }
        $next = $_SERVER['REQUEST_URI'] ?? (BASE_URL . 'customer_portal.php');
        header('Location: ' . bakery_customer_login_url() . '?next=' . urlencode($next));
        exit;
    }

    if ($db instanceof PDO) {
        $customer = bakery_portal_customer($db);
        if (!$customer) {
            bakery_portal_logout();
            if (is_ajax_request() || bakery_wants_json()) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Portal session expired']);
                exit;
            }
            header('Location: ' . bakery_customer_login_url());
            exit;
        }
        // Adopt portal sessions that predate the login telemetry deployment.
        if (!bakery_login_audit_current_id()) {
            bakery_login_audit_start($db, 'customer', [
                'customer_id' => (int)$customer['id'],
                'principal' => (string)$customer['name'],
                'login_at' => $_SESSION['portal_login_at'] ?? null,
            ]);
        }
    }
}

/**
 * Return the authenticated portal customer or exit with login redirect.
 *
 * @return array
 */
function bakery_portal_require_customer(PDO $db) {
    bakery_require_portal_login($db);
    $customer = bakery_portal_customer($db);
    if (!$customer) {
        bakery_portal_logout();
        header('Location: ' . bakery_customer_login_url());
        exit;
    }
    return $customer;
}

/**
 * Portal-home SF Baker bridge POST handler (PRG).
 *
 * The quiet bridge card posts action=enable_sfb_baker back to
 * customer_portal.php; call this before any output on that page. CSRF is
 * required; a refusal redirects home with nothing changed.
 */
function bakery_portal_handle_sfb_bridge_post(PDO $db) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || ($_POST['action'] ?? '') !== 'enable_sfb_baker') {
        return;
    }
    bakery_require_csrf();
    $customer = bakery_portal_require_customer($db);
    require_once __DIR__ . '/sf_baker.php';
    $enabled = bakery_sfb_enable_for_customer($db, (int)$customer['id']);
    header('Location: ' . BASE_URL . 'customer_portal.php'
        . ($enabled ? '?sfb_bridge=done' : ''));
    exit;
}

/**
 * Whether the quiet SF Baker bridge card may show for this portal customer:
 * schema ready, flag still off, and not a synthetic baker. Degrades to
 * hidden when the columns are missing rather than erroring.
 */
function bakery_portal_sfb_bridge_eligible(PDO $db, array $customer) {
    require_once __DIR__ . '/sf_baker.php';
    try {
        if (!column_exists($db, 'customers', 'sf_baker_enabled')) {
            return false;
        }
        $select = 'SELECT sf_baker_enabled';
        if (bakery_sfb_origin_column_ready($db)) {
            $select .= ', sfb_origin';
        }
        $stmt = $db->prepare(
            $select . '
             FROM customers WHERE id = ? AND portal_enabled = 1 AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([(int)$customer['id']]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['sf_baker_enabled'] === 1) {
            return false;
        }
        return !bakery_sfb_is_synthetic($row);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Render the quiet SF Baker bridge card (or its done flash) on the wholesale
 * portal home. Echoes nothing when the customer is not eligible.
 */
function bakery_portal_sfb_bridge_card(PDO $db, array $customer) {
    require_once __DIR__ . '/sf_baker.php';
    if (($_GET['sfb_bridge'] ?? '') === 'done') {
        echo '<section class="card" style="background:var(--ok-bg,#f0fdf4);border-left:4px solid var(--ok,#16a34a)">'
            . '<div class="card-body" style="padding:14px 16px">'
            . '<strong>' . htmlspecialchars(bakery_t('sfb.bridge_done'), ENT_QUOTES, 'UTF-8') . '</strong>'
            . '</div></section>';
        return;
    }
    if (!bakery_portal_sfb_bridge_eligible($db, $customer)) {
        return;
    }
    echo '<section class="card" style="border-left:4px solid var(--accent,#b45309)">'
        . '<div class="card-body" style="padding:14px 16px">'
        . '<h2 style="margin:0 0 4px;font-size:1.02rem;font-weight:600">'
        . htmlspecialchars(bakery_t('sfb.bridge_title'), ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p style="margin:0 0 12px;color:var(--muted);font-size:.88rem">'
        . htmlspecialchars(bakery_t('sfb.bridge_copy'), ENT_QUOTES, 'UTF-8') . '</p>'
        . '<form method="post" action="' . htmlspecialchars(BASE_URL . 'customer_portal.php', ENT_QUOTES, 'UTF-8') . '">'
        . bakery_csrf_field()
        . '<input type="hidden" name="action" value="enable_sfb_baker">'
        . '<button type="submit" class="btn btn-secondary" style="min-width:120px">'
        . htmlspecialchars(bakery_t('sfb.first_run_go'), ENT_QUOTES, 'UTF-8')
        . '</button></form></div></section>';
}

/**
 * Resolve unit price for a customer/product pair.
 *
 * @param array $customer Row with id, pricing_tier, default_pan_dulce_price
 * @param array $product Row with id, price, wholesale_price, product_line_name (optional)
 */
function bakery_resolve_customer_price(PDO $db, array $customer, array $product) {
    $customerId = (int)$customer['id'];
    $productId = (int)$product['id'];
    $tier = $customer['pricing_tier'] ?? 'retail';

    if ($tier === 'custom' && table_exists($db, 'customer_product_prices')) {
        $stmt = $db->prepare(
            'SELECT unit_price FROM customer_product_prices WHERE customer_id = ? AND product_id = ? LIMIT 1'
        );
        $stmt->execute([$customerId, $productId]);
        $custom = $stmt->fetchColumn();
        if ($custom !== false && $custom !== null) {
            return (float)$custom;
        }
    }

    if ($tier === 'wholesale' && !empty($product['wholesale_price'])) {
        return (float)$product['wholesale_price'];
    }

    if (($product['product_line_name'] ?? '') === 'Pan Dulce' && !empty($customer['default_pan_dulce_price'])) {
        return (float)$customer['default_pan_dulce_price'];
    }

    return (float)($product['price'] ?? 0);
}

/**
 * Paused week starts (Monday) for one customer — cached per request.
 *
 * @return array<string, true>
 */
function bakery_customer_paused_week_starts(PDO $db, $customerId) {
    static $cache = [];
    $customerId = (int)$customerId;
    if (array_key_exists($customerId, $cache)) {
        return $cache[$customerId];
    }
    if (!table_exists($db, 'standing_order_pauses')) {
        $cache[$customerId] = [];
        return $cache[$customerId];
    }
    $stmt = $db->prepare(
        'SELECT week_start FROM standing_order_pauses WHERE customer_id = ?'
    );
    $stmt->execute([$customerId]);
    $weeks = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $weekStart) {
        $weeks[(string)$weekStart] = true;
    }
    $cache[$customerId] = $weeks;
    return $cache[$customerId];
}

function bakery_customer_week_is_paused(PDO $db, $customerId, $weekStart) {
    $weeks = bakery_customer_paused_week_starts($db, $customerId);
    return !empty($weeks[(string)$weekStart]);
}

function bakery_pricing_tier_label($tier) {
    $labels = [
        'retail' => 'Retail',
        'wholesale' => 'Wholesale',
        'custom' => 'Custom',
    ];
    return $labels[$tier] ?? 'Retail';
}

function bakery_standing_day_labels() {
    if (function_exists('bakery_standing_day_labels_localized')) {
        return bakery_standing_day_labels_localized();
    }
    return [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];
}

function bakery_product_primary_image_url(PDO $db, $productId) {
    if (!table_exists($db, 'product_images')) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT file_path FROM product_images WHERE product_id = ? AND is_primary = 1 ORDER BY sort_order, id LIMIT 1'
    );
    $stmt->execute([(int)$productId]);
    $path = $stmt->fetchColumn();
    if (!$path) {
        $stmt = $db->prepare(
            'SELECT file_path FROM product_images WHERE product_id = ? ORDER BY sort_order, id LIMIT 1'
        );
        $stmt->execute([(int)$productId]);
        $path = $stmt->fetchColumn();
    }
    return $path ? (BASE_URL . 'uploads/product_photos/' . ltrim($path, '/')) : null;
}

/** Daily order statuses customers may edit through the catalog. */
function bakery_portal_daily_order_editable_statuses() {
    return ['pending', 'confirmed'];
}

function bakery_portal_daily_order_is_editable($status) {
    return in_array((string)$status, bakery_portal_daily_order_editable_statuses(), true);
}

/**
 * Weekdays (1=Mon … 7=Sun) when this customer normally receives deliveries.
 * Derived from standing orders with quantity and/or standing route assignments.
 *
 * @return int[]
 */
function bakery_portal_customer_delivery_weekdays(PDO $db, $customerId) {
    $days = [];
    $customerId = (int)$customerId;

    $stmt = $db->prepare(
        'SELECT DISTINCT CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END AS dow
         FROM standing_orders
         WHERE customer_id = ? AND quantity > 0'
    );
    $stmt->execute([$customerId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dow) {
        $days[(int)$dow] = true;
    }

    if (table_exists($db, 'standing_routes')) {
        $stmt = $db->prepare(
            'SELECT DISTINCT CASE WHEN day_of_week = 0 THEN 7 ELSE day_of_week END AS dow
             FROM standing_routes
             WHERE customer_id = ?'
        );
        $stmt->execute([$customerId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $dow) {
            $days[(int)$dow] = true;
        }
    }

    $result = array_keys($days);
    sort($result);
    return $result;
}

/**
 * Upcoming delivery dates the customer may add catalog items to.
 *
 * @return array<int, array{date: string, label: string, day_of_week: int, day_label: string, daily_order_id: ?int, has_daily_order: bool}>
 */
function bakery_portal_upcoming_deliveries(PDO $db, $customerId, array $customer, $daysAhead = 21) {
    $customerId = (int)$customerId;
    $deliveryDays = bakery_portal_customer_delivery_weekdays($db, $customerId);
    if ($deliveryDays === []) {
        return [];
    }

    $dayLabels = bakery_standing_day_labels();
    $deliveries = [];
    $today = date('Y-m-d');
    $orderStmt = $db->prepare(
        'SELECT id, status FROM daily_orders WHERE customer_id = ? AND order_date = ? LIMIT 1'
    );

    for ($offset = 1; $offset <= (int)$daysAhead; $offset++) {
        $date = date('Y-m-d', strtotime($today . ' +' . $offset . ' days'));
        $dow = bakery_standing_day_from_date($date);
        if (!in_array($dow, $deliveryDays, true)) {
            continue;
        }

        $weekStart = bakery_week_start_monday($date);
        if (bakery_customer_week_is_paused($db, $customerId, $weekStart)) {
            continue;
        }

        $orderStmt->execute([$customerId, $date]);
        $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
        if ($order && !bakery_portal_daily_order_is_editable($order['status'])) {
            continue;
        }

        $deliveries[] = [
            'date' => $date,
            'label' => date('l, M j', strtotime($date)),
            'day_of_week' => $dow,
            'day_label' => $dayLabels[$dow] ?? '',
            'daily_order_id' => $order ? (int)$order['id'] : null,
            'has_daily_order' => (bool)$order,
        ];
    }

    return $deliveries;
}

/**
 * Standing weekdays available for recurring additions (distinct from dated delivery).
 *
 * @return array<int, array{day_of_week: int, day_label: string}>
 */
function bakery_portal_standing_order_days(PDO $db, $customerId) {
    $weekdays = bakery_portal_customer_delivery_weekdays($db, (int)$customerId);
    $dayLabels = bakery_standing_day_labels();
    $days = [];
    foreach ($weekdays as $dow) {
        $days[] = [
            'day_of_week' => $dow,
            'day_label' => $dayLabels[$dow] ?? (string)$dow,
        ];
    }
    return $days;
}

function bakery_portal_load_product(PDO $db, $productId) {
    $stmt = $db->prepare(
        'SELECT p.id, p.name, p.description, p.price, p.wholesale_price, p.weight_grams,
                pl.name AS product_line_name, pl.sort_order AS product_line_sort,
                dt.name AS dough_type_name
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         WHERE p.id = ?
         LIMIT 1'
    );
    $stmt->execute([(int)$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_portal_product_default_quantity(PDO $db, array $product) {
    if (($product['product_line_name'] ?? '') === 'Pan Dulce'
        && table_exists($db, 'pan_dulce_product_quantity_standards')) {
        $stmt = $db->prepare(
            'SELECT standard_quantity FROM pan_dulce_product_quantity_standards WHERE product_id = ? LIMIT 1'
        );
        $stmt->execute([(int)$product['id']]);
        $qty = $stmt->fetchColumn();
        if ($qty !== false && $qty !== null) {
            return max(1, (int)$qty);
        }
        return 12;
    }
    return 1;
}

function bakery_portal_product_ordering_unit(array $product) {
    if (($product['product_line_name'] ?? '') === 'Pan Dulce') {
        return 'piece';
    }
    if (!empty($product['weight_grams'])) {
        return 'each';
    }
    return 'each';
}

function bakery_portal_price_is_reliable(array $customer, array $product, $unitPrice) {
    $price = (float)$unitPrice;
    if ($price <= 0) {
        return false;
    }
    if (($customer['pricing_tier'] ?? 'retail') === 'custom') {
        return true;
    }
    return true;
}

/**
 * Product IDs in the customer's current standing schedule.
 *
 * @return int[]
 */
function bakery_portal_standing_product_ids(PDO $db, $customerId) {
    $stmt = $db->prepare(
        'SELECT DISTINCT product_id FROM standing_orders WHERE customer_id = ? AND quantity > 0'
    );
    $stmt->execute([(int)$customerId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Product IDs the customer has ordered on past dated deliveries.
 *
 * @return int[]
 */
function bakery_portal_ordered_product_ids(PDO $db, $customerId) {
    $stmt = $db->prepare(
        'SELECT DISTINCT doi.product_id
         FROM daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         WHERE do.customer_id = ? AND do.order_date < CURDATE() AND doi.quantity > 0'
    );
    $stmt->execute([(int)$customerId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Catalog rows with customer pricing and discovery metadata.
 */
function bakery_portal_catalog_products(PDO $db, array $customer) {
    $customerId = (int)$customer['id'];
    $productsStmt = $db->query(
        'SELECT p.id, p.name, p.description, p.price, p.wholesale_price, p.weight_grams,
                pl.id AS product_line_id, pl.name AS product_line_name,
                pl.description AS product_line_description, pl.sort_order AS product_line_sort,
                dt.id AS dough_type_id, dt.name AS dough_type_name,
                dt.description AS dough_type_description
         FROM products p
         LEFT JOIN dough_types dt ON dt.id = p.dough_type_id
         LEFT JOIN product_lines pl ON pl.id = dt.product_line_id
         ORDER BY pl.sort_order, pl.name, p.name'
    );
    $rows = $productsStmt->fetchAll(PDO::FETCH_ASSOC);

    $standingIds = array_flip(bakery_portal_standing_product_ids($db, $customerId));
    $orderedIds = array_flip(bakery_portal_ordered_product_ids($db, $customerId));

    // Keep the catalog useful as a personal reference: customers can see not
    // only whether they tried a product, but when and how often.
    $historyStmt = $db->prepare(
        'SELECT doi.product_id, do.order_date, doi.quantity, do.status
         FROM daily_order_items doi
         JOIN daily_orders do ON do.id = doi.daily_order_id
         WHERE do.customer_id = ? AND do.order_date < CURDATE() AND doi.quantity > 0
         ORDER BY do.order_date DESC, do.id DESC'
    );
    $historyStmt->execute([$customerId]);
    $historyByProduct = [];
    foreach ($historyStmt->fetchAll(PDO::FETCH_ASSOC) as $historyRow) {
        $productId = (int)$historyRow['product_id'];
        if (!isset($historyByProduct[$productId])) {
            $historyByProduct[$productId] = [
                'order_count' => 0,
                'lifetime_quantity' => 0,
                'first_ordered' => (string)$historyRow['order_date'],
                'last_ordered' => (string)$historyRow['order_date'],
                'recent_deliveries' => [],
            ];
        }
        $historyByProduct[$productId]['order_count']++;
        $historyByProduct[$productId]['lifetime_quantity'] += (int)$historyRow['quantity'];
        $historyByProduct[$productId]['first_ordered'] = min(
            $historyByProduct[$productId]['first_ordered'],
            (string)$historyRow['order_date']
        );
        if (count($historyByProduct[$productId]['recent_deliveries']) < 6) {
            $historyByProduct[$productId]['recent_deliveries'][] = [
                'date' => (string)$historyRow['order_date'],
                'quantity' => (int)$historyRow['quantity'],
                'status' => (string)($historyRow['status'] ?? ''),
            ];
        }
    }

    $standingStmt = $db->prepare(
        'SELECT product_id, day_of_week, quantity
         FROM standing_orders
         WHERE customer_id = ? AND quantity > 0
         ORDER BY day_of_week, product_id'
    );
    $standingStmt->execute([$customerId]);
    $standingByProduct = [];
    foreach ($standingStmt->fetchAll(PDO::FETCH_ASSOC) as $standingRow) {
        $productId = (int)$standingRow['product_id'];
        $dayOfWeek = (int)$standingRow['day_of_week'];
        if ($dayOfWeek === 0) {
            $dayOfWeek = 7;
        }
        $standingByProduct[$productId][] = [
            'day_of_week' => $dayOfWeek,
            'quantity' => (int)$standingRow['quantity'],
        ];
    }
    $dayLabels = bakery_standing_day_labels();
    $catalog = [];

    foreach ($rows as $product) {
        $productId = (int)$product['id'];
        $unitPrice = bakery_resolve_customer_price($db, $customer, $product);
        $discovery = 'never_ordered';
        if (isset($standingIds[$productId])) {
            $discovery = 'current';
        } elseif (isset($orderedIds[$productId])) {
            $discovery = 'ordered_before';
        }

        $history = $historyByProduct[$productId] ?? [
            'order_count' => 0,
            'lifetime_quantity' => 0,
            'first_ordered' => null,
            'last_ordered' => null,
            'recent_deliveries' => [],
        ];
        $standing = $standingByProduct[$productId] ?? [];
        foreach ($standing as &$standingRow) {
            $standingRow['day_label'] = $dayLabels[$standingRow['day_of_week']] ?? (string)$standingRow['day_of_week'];
        }
        unset($standingRow);

        $catalog[] = [
            'id' => $productId,
            'name' => $product['name'],
            'description' => $product['description'] ?? '',
            'product_line_id' => $product['product_line_id'] !== null ? (int)$product['product_line_id'] : null,
            'product_line_name' => $product['product_line_name'] ?? '',
            'product_line_description' => $product['product_line_description'] ?? '',
            'product_line_sort' => (int)($product['product_line_sort'] ?? 0),
            'dough_type_id' => $product['dough_type_id'] !== null ? (int)$product['dough_type_id'] : null,
            'dough_type_name' => $product['dough_type_name'] ?? '',
            'dough_type_description' => $product['dough_type_description'] ?? '',
            'weight_grams' => $product['weight_grams'] !== null ? (int)$product['weight_grams'] : null,
            'unit_price' => $unitPrice,
            'price_reliable' => bakery_portal_price_is_reliable($customer, $product, $unitPrice),
            'ordering_unit' => bakery_portal_product_ordering_unit($product),
            'default_quantity' => bakery_portal_product_default_quantity($db, $product),
            'image_url' => bakery_product_primary_image_url($db, $productId),
            'discovery' => $discovery,
            'available_to_order' => true,
            'history_order_count' => (int)$history['order_count'],
            'history_lifetime_quantity' => (int)$history['lifetime_quantity'],
            'history_first_ordered' => $history['first_ordered'],
            'history_last_ordered' => $history['last_ordered'],
            'history_recent_deliveries' => $history['recent_deliveries'],
            'standing_orders' => array_values($standing),
        ];
    }

    return $catalog;
}

function bakery_portal_update_daily_order_total(PDO $db, $orderId) {
    $stmt = $db->prepare(
        'UPDATE daily_orders
         SET total_amount = (
             SELECT COALESCE(SUM(line_total), 0)
             FROM daily_order_items
             WHERE daily_order_id = ?
         )
         WHERE id = ?'
    );
    $stmt->execute([(int)$orderId, (int)$orderId]);
}

/**
 * Ensure a dated order shell exists and is still customer-editable.
 */
function bakery_portal_ensure_daily_order(PDO $db, $customerId, $orderDate) {
    $customerId = (int)$customerId;
    $stmt = $db->prepare(
        'INSERT IGNORE INTO daily_orders (customer_id, order_date, status, total_amount)
         VALUES (?, ?, ?, 0)'
    );
    $stmt->execute([$customerId, $orderDate, 'pending']);

    $stmt = $db->prepare(
        'SELECT id, status FROM daily_orders WHERE customer_id = ? AND order_date = ? LIMIT 1'
    );
    $stmt->execute([$customerId, $orderDate]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('Could not create delivery order');
    }
    if (!bakery_portal_daily_order_is_editable($row['status'])) {
        throw new RuntimeException('This delivery can no longer be changed');
    }
    return (int)$row['id'];
}

/**
 * Canonical portal mutation: add/increase a product on a specific delivery date.
 * Uses bakery_resolve_customer_price (full tier + Pan Dulce rules), not staff daily_orders pricing.
 *
 * Agent 2 handoff: if shared bakery_daily_order_add_item() is introduced, delegate here.
 */
function bakery_portal_add_to_daily_order(PDO $db, array $customer, $orderDate, $productId, $quantity) {
    $customerId = (int)$customer['id'];
    $productId = (int)$productId;
    $quantity = (int)$quantity;
    if ($quantity <= 0) {
        throw new InvalidArgumentException('Quantity must be at least 1');
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', (string)$orderDate);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $orderDate) {
        throw new InvalidArgumentException('Invalid delivery date');
    }

    $allowed = false;
    foreach (bakery_portal_upcoming_deliveries($db, $customerId, $customer) as $delivery) {
        if ($delivery['date'] === $orderDate) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        throw new InvalidArgumentException('That delivery date is not available for changes');
    }

    $product = bakery_portal_load_product($db, $productId);
    if (!$product) {
        throw new InvalidArgumentException('Product not found');
    }

    $orderId = bakery_portal_ensure_daily_order($db, $customerId, $orderDate);
    $unitPrice = bakery_resolve_customer_price($db, $customer, $product);
    $lineTotal = $quantity * $unitPrice;

    $stmt = $db->prepare(
        'INSERT INTO daily_order_items (daily_order_id, product_id, quantity, unit_price, line_total)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
             quantity = quantity + VALUES(quantity),
             line_total = quantity * unit_price'
    );
    $stmt->execute([$orderId, $productId, $quantity, $unitPrice, $lineTotal]);
    bakery_portal_update_daily_order_total($db, $orderId);

    $qtyStmt = $db->prepare(
        'SELECT quantity FROM daily_order_items WHERE daily_order_id = ? AND product_id = ? LIMIT 1'
    );
    $qtyStmt->execute([$orderId, $productId]);
    $newQty = (int)$qtyStmt->fetchColumn();

    if (function_exists('bakery_record_operational_event') && defined('BAKERY_OP_DAILY_ORDER_ITEM_ADDED')) {
        bakery_record_operational_event($db, BAKERY_OP_DAILY_ORDER_ITEM_ADDED,
            'Customer added ' . $quantity . ' × ' . $product['name'] . ' via portal', [
            'operational_date' => $orderDate,
            'customer_id' => $customerId,
            'daily_order_id' => $orderId,
            'product_id' => $productId,
            'metadata' => [
                'quantity_added' => $quantity,
                'new_quantity' => $newQty,
                'source' => 'customer_portal_catalog',
            ],
        ]);
    }

    return [
        'daily_order_id' => $orderId,
        'order_date' => $orderDate,
        'order_date_label' => date('l, M j', strtotime($orderDate)),
        'product_id' => $productId,
        'product_name' => $product['name'],
        'quantity_added' => $quantity,
        'new_quantity' => $newQty,
        'unit_price' => $unitPrice,
    ];
}

/**
 * Add/increase a product on the customer's recurring standing schedule.
 */
function bakery_portal_add_to_standing_order(PDO $db, array $customer, $dayOfWeek, $productId, $quantity) {
    $customerId = (int)$customer['id'];
    $productId = (int)$productId;
    $quantity = (int)$quantity;
    $dayOfWeek = bakery_normalize_standing_day($dayOfWeek);

    if ($quantity <= 0) {
        throw new InvalidArgumentException('Quantity must be at least 1');
    }
    if ($dayOfWeek < 1 || $dayOfWeek > 7) {
        throw new InvalidArgumentException('Invalid weekday');
    }

    $allowedDays = array_column(bakery_portal_standing_order_days($db, $customerId), 'day_of_week');
    if (!in_array($dayOfWeek, $allowedDays, true)) {
        throw new InvalidArgumentException('That weekday is not on your delivery schedule');
    }

    $product = bakery_portal_load_product($db, $productId);
    if (!$product) {
        throw new InvalidArgumentException('Product not found');
    }

    $dayClause = bakery_standing_day_in_clause($dayOfWeek);
    $existingStmt = $db->prepare(
        'SELECT COALESCE(SUM(quantity), 0) FROM standing_orders
         WHERE customer_id = ? AND product_id = ? AND day_of_week ' . $dayClause['sql']
    );
    $existingStmt->execute(array_merge([$customerId, $productId], $dayClause['values']));
    $existingQty = (int)$existingStmt->fetchColumn();
    $newQty = $existingQty + $quantity;

    $deleteStmt = $db->prepare(
        'DELETE FROM standing_orders
         WHERE customer_id = ? AND product_id = ? AND day_of_week ' . $dayClause['sql']
    );
    $deleteStmt->execute(array_merge([$customerId, $productId], $dayClause['values']));

    $stmt = $db->prepare(
        'INSERT INTO standing_orders (customer_id, product_id, day_of_week, quantity)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([$customerId, $productId, $dayOfWeek, $newQty]);

    $dayLabels = bakery_standing_day_labels();
    return [
        'day_of_week' => $dayOfWeek,
        'day_label' => $dayLabels[$dayOfWeek] ?? (string)$dayOfWeek,
        'product_id' => $productId,
        'product_name' => $product['name'],
        'quantity_added' => $quantity,
        'new_quantity' => $newQty,
    ];
}
