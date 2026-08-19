<?php
/**
 * Agent-controlled SFAdmin operator.
 *
 * SFAdmin is a real administrator (GUI login works). This library adds
 * non-GUI powers: create SF Baker customers, impersonate their portal
 * session, and start batches. CLI-only automation is local/test scoped.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/customer_portal.php';
require_once __DIR__ . '/sf_baker.php';
require_once __DIR__ . '/sfb_synthetic_eval.php';

define('BAKERY_SFB_AGENT_EMAIL_DEFAULT', 'sfadmin@sourflour.org');
define('BAKERY_SFB_AGENT_NAME_DEFAULT', 'SFAdmin');
define('BAKERY_SFB_AGENT_CODE_DEFAULT', '9099');

/**
 * @return array{admin:?array, customer:?array}
 */
function &bakery_sfb_agent_state() {
    if (!isset($GLOBALS['_bakery_sfb_agent']) || !is_array($GLOBALS['_bakery_sfb_agent'])) {
        $GLOBALS['_bakery_sfb_agent'] = [
            'admin' => null,
            'customer' => null,
        ];
    }
    return $GLOBALS['_bakery_sfb_agent'];
}

function bakery_sfb_agent_email() {
    $email = strtolower(trim((string)($_ENV['SFB_AGENT_ADMIN_EMAIL'] ?? getenv('SFB_AGENT_ADMIN_EMAIL') ?: BAKERY_SFB_AGENT_EMAIL_DEFAULT)));
    return $email !== '' ? $email : BAKERY_SFB_AGENT_EMAIL_DEFAULT;
}

function bakery_sfb_agent_display_name() {
    $name = trim((string)($_ENV['SFB_AGENT_ADMIN_NAME'] ?? getenv('SFB_AGENT_ADMIN_NAME') ?: BAKERY_SFB_AGENT_NAME_DEFAULT));
    return $name !== '' ? $name : BAKERY_SFB_AGENT_NAME_DEFAULT;
}

function bakery_sfb_agent_preferred_code() {
    $code = bakery_normalize_login_code($_ENV['SFB_AGENT_ADMIN_CODE'] ?? getenv('SFB_AGENT_ADMIN_CODE') ?: BAKERY_SFB_AGENT_CODE_DEFAULT);
    return $code !== '' ? $code : BAKERY_SFB_AGENT_CODE_DEFAULT;
}

/** Isolated test databases only — never the live production host. */
function bakery_sfb_agent_assert_local(PDO $db) {
    require_once __DIR__ . '/test_target_guard.php';
    bakery_assert_local_test_target($db);
}

function bakery_sfb_agent_boot_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    if (PHP_SAPI === 'cli' && !headers_sent()) {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bakery_sfb_agent_sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir)) {
            session_save_path($dir);
        }
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

function bakery_sfb_agent_load_admin(PDO $db) {
    $stmt = $db->prepare(
        "SELECT u.id, u.email, u.display_name, u.login_code, u.is_active, r.slug AS role_slug
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE LOWER(u.email) = LOWER(?)
         LIMIT 1"
    );
    $stmt->execute([bakery_sfb_agent_email()]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function bakery_sfb_agent_allocate_staff_code(PDO $db, $preferred, $exceptEmail) {
    $candidates = [$preferred];
    for ($n = 9080; $n <= 9098; $n++) {
        $candidates[] = (string)$n;
    }
    $candidates = array_values(array_unique($candidates));
    $stmt = $db->prepare(
        'SELECT id FROM users WHERE login_code = ? AND LOWER(email) <> LOWER(?) LIMIT 1'
    );
    foreach ($candidates as $code) {
        $code = bakery_normalize_login_code($code);
        if ($code === '') {
            continue;
        }
        $stmt->execute([$code, $exceptEmail]);
        if (!$stmt->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('No free 4-digit staff code is available for SFAdmin.');
}

/**
 * Create or refresh the agent-controlled SFAdmin administrator.
 *
 * @return array Admin user row including login_code
 */
function bakery_sfb_agent_ensure_admin(PDO $db) {
    bakery_ensure_login_code_column($db);

    $email = bakery_sfb_agent_email();
    $name = bakery_sfb_agent_display_name();
    $existing = bakery_sfb_agent_load_admin($db);
    $preferred = bakery_normalize_login_code($existing['login_code'] ?? '') ?: bakery_sfb_agent_preferred_code();
    $code = bakery_sfb_agent_allocate_staff_code($db, $preferred, $email);

    if (!bakery_upsert_code_user($db, [
        'email' => $email,
        'display_name' => $name,
        'role' => 'administrator',
        'code' => $code,
        'driver_id' => null,
    ])) {
        throw new RuntimeException('Could not create or update SFAdmin.');
    }

    $admin = bakery_sfb_agent_load_admin($db);
    if (!$admin || (int)$admin['is_active'] !== 1 || ($admin['role_slug'] ?? '') !== 'administrator') {
        throw new RuntimeException('SFAdmin was not stored as an active administrator.');
    }
    return $admin;
}

/**
 * Establish a staff session as SFAdmin (GUI-equivalent login, no throttle).
 *
 * @return array Admin user row
 */
function bakery_sfb_agent_login(PDO $db) {
    $admin = bakery_sfb_agent_ensure_admin($db);
    bakery_sfb_agent_boot_session();

    $_SESSION['user_id'] = (int)$admin['id'];
    $_SESSION['user_email'] = $admin['email'];
    $_SESSION['user_display_name'] = $admin['display_name'];
    $_SESSION['user_role_slug'] = 'administrator';
    $_SESSION['user_driver_id'] = null;
    $_SESSION['auth_login_at'] = time();
    $_SESSION['auth_last_activity'] = time();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $upd = $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?');
    $upd->execute([(int)$admin['id']]);

    bakery_login_audit_start($db, 'staff', [
        'user_id' => (int)$admin['id'],
        'principal' => (string)$admin['email'],
        'credential_code' => (string)$admin['login_code'],
        'started_by' => 'sfb_agent',
    ]);

    $state = &bakery_sfb_agent_state();
    $state['admin'] = $admin;
    return $admin;
}

function bakery_sfb_agent_current_admin(PDO $db) {
    $state = &bakery_sfb_agent_state();
    if (!empty($state['admin']['id'])) {
        return $state['admin'];
    }
    $sessionUser = bakery_current_user();
    if ($sessionUser && ($sessionUser['role_slug'] ?? '') === 'administrator') {
        $stmt = $db->prepare(
            "SELECT u.id, u.email, u.display_name, u.login_code, u.is_active, r.slug AS role_slug
             FROM users u
             JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? LIMIT 1"
        );
        $stmt->execute([(int)$sessionUser['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $state['admin'] = $row;
            return $row;
        }
    }
    return bakery_sfb_agent_login($db);
}

function bakery_sfb_agent_portal_code_free(PDO $db, $code, $exceptId = 0) {
    $code = bakery_normalize_login_code($code);
    if ($code === '') {
        return false;
    }
    $stmt = $db->prepare('SELECT id FROM customers WHERE portal_code = ? AND id <> ? LIMIT 1');
    $stmt->execute([$code, (int)$exceptId]);
    return !$stmt->fetchColumn();
}

function bakery_sfb_agent_allocate_portal_code(PDO $db, $preferred, $exceptId = 0) {
    $candidates = [];
    $preferred = bakery_normalize_login_code($preferred);
    if ($preferred !== '') {
        $candidates[] = $preferred;
    }
    for ($n = 1101; $n <= 1199; $n++) {
        $candidates[] = (string)$n;
    }
    foreach (array_unique($candidates) as $code) {
        if (bakery_sfb_agent_portal_code_free($db, $code, $exceptId)) {
            return $code;
        }
    }
    throw new RuntimeException('No free portal code is available for this SF Baker customer.');
}

function bakery_sfb_agent_reserved_reuse_names() {
    return ['Customer1', 'Customer2'];
}

function bakery_sfb_agent_find_customer(PDO $db, $nameOrId) {
    if (is_numeric($nameOrId) && (int)$nameOrId > 0 && (string)(int)$nameOrId === (string)$nameOrId) {
        $stmt = $db->prepare('SELECT * FROM customers WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$nameOrId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }
    $stmt = $db->prepare('SELECT * FROM customers WHERE name = ? LIMIT 1');
    $stmt->execute([trim((string)$nameOrId)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Create or update an SF Baker portal customer. Idempotent by customer name.
 *
 * @return array{customer:array, created:bool, portal_code:string}
 */
function bakery_sfb_agent_create_customer(PDO $db, $name, $portalCode = '', array $opts = []) {
    bakery_ensure_sfb_schema($db);
    bakery_ensure_portal_schema($db);

    $name = trim((string)$name);
    if ($name === '') {
        throw new InvalidArgumentException('Customer name is required.');
    }

    $existing = bakery_sfb_agent_find_customer($db, $name);
    $id = $existing ? (int)$existing['id'] : 0;
    $code = bakery_sfb_agent_allocate_portal_code(
        $db,
        $portalCode !== '' ? $portalCode : (string)($existing['portal_code'] ?? ''),
        $id
    );
    $phone = trim((string)($opts['phone'] ?? ($existing['phone'] ?? '')));
    if ($phone === '') {
        $phone = '555-' . $code;
    }
    $email = trim((string)($opts['email'] ?? ($existing['email'] ?? '')));
    if ($email === '') {
        $slug = preg_replace('/[^a-z0-9]+/', '.', strtolower($name));
        $email = (trim((string)$slug, '.') ?: 'customer') . '@sfbaker.local';
    }
    $address = trim((string)($opts['address'] ?? ($existing['address'] ?? 'SF Baker agent fixture')));
    if ($address === '') {
        $address = 'SF Baker agent fixture';
    }

    $requestedOrigin = bakery_sfb_normalize_origin($opts['origin'] ?? 'synthetic');
    $existingOrigin = $existing
        ? bakery_sfb_normalize_origin($existing['sfb_origin'] ?? 'human')
        : 'synthetic';
    $adoptReserved = !empty($opts['adopt_reserved'])
        && in_array($name, bakery_sfb_agent_reserved_reuse_names(), true);
    if ($adoptReserved && $id > 0 && $existingOrigin === 'human') {
        $allowProdAdopt = !empty($opts['allow_production'])
            && defined('USE_PROD_DB') && USE_PROD_DB;
        if ($allowProdAdopt) {
            $existingOrigin = 'synthetic';
        } else {
            try {
                bakery_sfb_agent_assert_local($db);
                $existingOrigin = 'synthetic';
            } catch (Throwable $e) {
                $adoptReserved = false;
            }
        }
    }
    $origin = $requestedOrigin;
    if ($id > 0 && $existingOrigin === 'human' && !$adoptReserved) {
        $origin = 'human';
    }
    if ($origin === 'synthetic' && !column_exists($db, 'customers', 'sfb_origin')) {
        throw new RuntimeException(
            'customers.sfb_origin is required before creating synthetic bakers. Do not insert unlabeled synthetics.'
        );
    }

    $fields = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'is_active' => 1,
    ];
    if (column_exists($db, 'customers', 'portal_phone')) {
        $fields['portal_phone'] = $phone;
    }
    if (column_exists($db, 'customers', 'portal_code')) {
        $fields['portal_code'] = $code;
    }
    if (column_exists($db, 'customers', 'portal_enabled')) {
        $fields['portal_enabled'] = 1;
    }
    if (column_exists($db, 'customers', 'sf_baker_enabled')) {
        $fields['sf_baker_enabled'] = 1;
    }
    if (column_exists($db, 'customers', 'sfb_origin')) {
        $fields['sfb_origin'] = $origin;
    }
    if ($origin === 'synthetic') {
        if (column_exists($db, 'customers', 'zone')) {
            $fields['zone'] = null;
        }
        if (column_exists($db, 'customers', 'zone_id')) {
            $fields['zone_id'] = null;
        }
        if (column_exists($db, 'customers', 'deliver_by')) {
            $fields['deliver_by'] = null;
        }
        if (column_exists($db, 'customers', 'deliver_after')) {
            $fields['deliver_after'] = null;
        }
        if (column_exists($db, 'customers', 'delivery_time')) {
            $fields['delivery_time'] = null;
        }
        if (column_exists($db, 'customers', 'default_pan_dulce_price')) {
            $fields['default_pan_dulce_price'] = null;
        }
    }
    if (column_exists($db, 'customers', 'pricing_tier')) {
        $fields['pricing_tier'] = 'retail';
    }
    if (column_exists($db, 'customers', 'payment_collection')) {
        $fields['payment_collection'] = 'cod';
    }

    if ($id > 0) {
        $sets = [];
        $values = [];
        foreach ($fields as $col => $value) {
            if ($col === 'name') {
                continue;
            }
            $sets[] = $col . ' = ?';
            $values[] = $value;
        }
        $values[] = $id;
        $db->prepare('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
        $created = false;
    } else {
        $cols = array_keys($fields);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $db->prepare(
            'INSERT INTO customers (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')'
        )->execute(array_values($fields));
        $id = (int)$db->lastInsertId();
        $created = true;
    }

    $customer = bakery_sfb_agent_find_customer($db, $id);
    if (!$customer) {
        throw new RuntimeException('SF Baker customer was not stored.');
    }
    if (bakery_sfb_is_synthetic($customer)) {
        bakery_sfb_agent_strip_wholesale($db, $id);
    }
    $persona = trim((string)($opts['persona'] ?? ''));
    $locale = strtolower(trim((string)($opts['locale'] ?? 'en')));
    if ($locale !== 'es') {
        $locale = 'en';
    }
    if ($persona !== '' && function_exists('bakery_sfb_persona_save_profile')) {
        bakery_sfb_persona_save_profile($db, $id, [
            'key' => $persona,
            'cohort' => (string)($opts['cohort'] ?? $persona),
            'locale' => $locale,
            'mentor' => !empty($opts['mentor']),
        ]);
    }
    return [
        'customer' => $customer,
        'created' => $created,
        'portal_code' => $code,
        'origin' => bakery_sfb_normalize_origin($customer['sfb_origin'] ?? $origin),
        'persona' => $persona,
        'locale' => $locale,
    ];
}

/** Synthetics never keep standing orders or routes. */
function bakery_sfb_agent_strip_wholesale(PDO $db, $customerId) {
    $customerId = (int)$customerId;
    if ($customerId <= 0) {
        return;
    }
    if (table_exists($db, 'standing_orders')) {
        $db->prepare('DELETE FROM standing_orders WHERE customer_id = ?')->execute([$customerId]);
    }
    if (table_exists($db, 'standing_routes')) {
        $db->prepare('DELETE FROM standing_routes WHERE customer_id = ?')->execute([$customerId]);
    }
}

function bakery_sfb_agent_create_baker(PDO $db, $name, $portalCode = '', array $opts = []) {
    $opts['origin'] = bakery_sfb_normalize_origin($opts['origin'] ?? 'synthetic');
    $opts['locale'] = strtolower(trim((string)($opts['locale'] ?? 'en'))) === 'es' ? 'es' : 'en';
    return bakery_sfb_agent_create_customer($db, $name, $portalCode, $opts);
}

function bakery_sfb_agent_impersonating() {
    if (empty($_SESSION['sfb_impersonator_user_id']) || bakery_portal_customer_id() <= 0) {
        return null;
    }
    return [
        'admin_user_id' => (int)$_SESSION['sfb_impersonator_user_id'],
        'admin_name' => (string)($_SESSION['sfb_impersonator_name'] ?? 'SFAdmin'),
        'customer_id' => bakery_portal_customer_id(),
        'customer_name' => (string)($_SESSION['portal_customer_name'] ?? ''),
    ];
}

/**
 * Open a portal session as an SF Baker customer while keeping the staff session.
 *
 * @return array Customer row
 */
function bakery_sfb_agent_login_as_customer(PDO $db, $nameOrId) {
    $admin = bakery_sfb_agent_current_admin($db);
    $customer = bakery_sfb_agent_find_customer($db, $nameOrId);
    if (!$customer) {
        throw new InvalidArgumentException('SF Baker customer not found.');
    }
    if ((int)($customer['is_active'] ?? 0) !== 1) {
        throw new InvalidArgumentException('That customer is inactive.');
    }
    if (column_exists($db, 'customers', 'portal_enabled') && (int)($customer['portal_enabled'] ?? 0) !== 1) {
        throw new InvalidArgumentException('Portal access is not enabled for this customer.');
    }
    if (column_exists($db, 'customers', 'sf_baker_enabled') && (int)($customer['sf_baker_enabled'] ?? 0) !== 1) {
        throw new InvalidArgumentException('SF Baker is not enabled for this customer.');
    }

    bakery_sfb_agent_boot_session();
    $_SESSION['sfb_impersonator_user_id'] = (int)$admin['id'];
    $_SESSION['sfb_impersonator_name'] = (string)$admin['display_name'];
    $_SESSION['sfb_impersonator_email'] = (string)$admin['email'];
    bakery_portal_start_session($customer, (string)($customer['portal_code'] ?? ''), [
        'started_by' => 'sfb_agent_impersonation',
        'impersonated_by_user_id' => (int)$admin['id'],
        'impersonated_by' => (string)$admin['display_name'],
    ]);
    $_SESSION['sfb_impersonator_user_id'] = (int)$admin['id'];
    $_SESSION['sfb_impersonator_name'] = (string)$admin['display_name'];
    $_SESSION['sfb_impersonator_email'] = (string)$admin['email'];

    $state = &bakery_sfb_agent_state();
    $state['admin'] = $admin;
    $state['customer'] = $customer;
    return $customer;
}

function bakery_sfb_agent_stop_impersonation() {
    bakery_portal_logout();
    unset(
        $_SESSION['sfb_impersonator_user_id'],
        $_SESSION['sfb_impersonator_name'],
        $_SESSION['sfb_impersonator_email']
    );
    $state = &bakery_sfb_agent_state();
    $state['customer'] = null;
}

function bakery_sfb_agent_acting_customer(PDO $db, $nameOrId = '') {
    if ($nameOrId !== '' && $nameOrId !== null) {
        return bakery_sfb_agent_login_as_customer($db, $nameOrId);
    }
    $state = &bakery_sfb_agent_state();
    if (!empty($state['customer']['id'])) {
        return $state['customer'];
    }
    $id = bakery_portal_customer_id();
    if ($id > 0) {
        $customer = bakery_sfb_agent_find_customer($db, $id);
        if ($customer) {
            $state['customer'] = $customer;
            return $customer;
        }
    }
    throw new InvalidArgumentException('SFAdmin is not logged in as a customer.');
}

function bakery_sfb_agent_ensure_formula(PDO $db, $customerId, $templateNameOrId = '') {
    $formulas = bakery_sfb_formulas($db, $customerId);
    if ($formulas && $templateNameOrId === '') {
        return (int)$formulas[0]['id'];
    }
    $template = bakery_sfb_template($db, $templateNameOrId);
    if (!$template) {
        $template = bakery_sfb_template($db, '');
    }
    if (!$template) {
        throw new RuntimeException('No SF Baker formula templates are available to copy.');
    }
    if ($formulas) {
        foreach ($formulas as $formula) {
            if (strcasecmp((string)$formula['name'], (string)$template['name']) === 0) {
                return (int)$formula['id'];
            }
        }
    }
    return bakery_sfb_copy_template($db, (int)$customerId, (int)$template['id']);
}

function bakery_sfb_agent_copy_formula(PDO $db, $templateNameOrId = '', $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $formulaId = bakery_sfb_agent_ensure_formula($db, (int)$customer['id'], $templateNameOrId);
    return $formulaId;
}

function bakery_sfb_agent_resolve_batch(PDO $db, $customerId, $batchId = 0) {
    $batchId = (int)$batchId;
    if ($batchId > 0) {
        $batch = bakery_sfb_batch($db, $customerId, $batchId);
        if (!$batch) {
            throw new InvalidArgumentException('Batch not found for this baker.');
        }
        return $batch;
    }
    $batch = bakery_sfb_active_batch($db, $customerId);
    if (!$batch) {
        throw new InvalidArgumentException('No in-progress batch. Pass --batch= or start-batch first.');
    }
    return $batch;
}

/**
 * Start a batch as the impersonated (or named) SF Baker customer.
 *
 * @return array{batch_id:int, customer:array, formula_id:int, name:string}
 */
function bakery_sfb_agent_start_batch(PDO $db, $batchName = '', $formulaId = 0, $customerNameOrId = '', $startedAt = '') {
    bakery_ensure_sfb_schema($db);
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $customerId = (int)$customer['id'];
    $formulaId = (int)$formulaId;
    if ($formulaId <= 0) {
        $formulaId = bakery_sfb_agent_ensure_formula($db, $customerId);
    }
    $batchId = bakery_sfb_start_batch(
        $db,
        $customerId,
        $formulaId,
        $batchName,
        $startedAt !== '' ? $startedAt : date('Y-m-d H:i:s')
    );
    $batch = bakery_sfb_batch($db, $customerId, $batchId);
    return [
        'batch_id' => $batchId,
        'customer' => $customer,
        'formula_id' => $formulaId,
        'name' => (string)($batch['name'] ?? $batchName),
        'formula_name' => (string)($batch['formula_name'] ?? ''),
        'status' => (string)($batch['status'] ?? 'in_progress'),
    ];
}

function bakery_sfb_agent_feed_starter(PDO $db, array $opts = [], $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $starter = bakery_sfb_ensure_starter(
        $db,
        (int)$customer['id'],
        (string)($opts['starter'] ?? ''),
        (string)($opts['flour-blend'] ?? $opts['flour_blend'] ?? ''),
        $opts['hydration'] ?? 100
    );
    $feedingId = bakery_sfb_add_starter_feeding(
        $db,
        (int)$customer['id'],
        (int)$starter['id'],
        $opts['starter-g'] ?? $opts['starter_g'] ?? 50,
        $opts['flour-g'] ?? $opts['flour_g'] ?? 100,
        $opts['water-g'] ?? $opts['water_g'] ?? 100,
        $opts['fed-at'] ?? $opts['fed_at'] ?? '',
        $opts['peak'] ?? '',
        $opts['notes'] ?? ''
    );
    return [
        'feeding_id' => $feedingId,
        'starter' => $starter,
        'customer' => $customer,
        'ratio' => bakery_sfb_feeding_ratio([
            'starter_g' => $opts['starter-g'] ?? $opts['starter_g'] ?? 50,
            'flour_g' => $opts['flour-g'] ?? $opts['flour_g'] ?? 100,
            'water_g' => $opts['water-g'] ?? $opts['water_g'] ?? 100,
        ]),
    ];
}

function bakery_sfb_agent_log_turn(PDO $db, array $opts = [], $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $batch = bakery_sfb_agent_resolve_batch($db, (int)$customer['id'], (int)($opts['batch'] ?? 0));
    $turnId = bakery_sfb_add_batch_turn(
        $db,
        (int)$customer['id'],
        (int)$batch['id'],
        $opts['type'] ?? $opts['turn-type'] ?? 'stretch_fold',
        $opts['temp'] ?? $opts['dough-temp'] ?? $opts['temp_f'] ?? '',
        $opts['at'] ?? $opts['occurred-at'] ?? '',
        $opts['notes'] ?? ''
    );
    return ['turn_id' => $turnId, 'batch_id' => (int)$batch['id'], 'customer' => $customer];
}

function bakery_sfb_agent_log_temp(PDO $db, array $opts = [], $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $batch = bakery_sfb_agent_resolve_batch($db, (int)$customer['id'], (int)($opts['batch'] ?? 0));
    $tempId = bakery_sfb_add_batch_temp(
        $db,
        (int)$customer['id'],
        (int)$batch['id'],
        $opts['temp'] ?? $opts['temp_f'] ?? 0,
        $opts['phase'] ?? 'development',
        $opts['at'] ?? $opts['measured-at'] ?? '',
        $opts['notes'] ?? ''
    );
    return ['temp_id' => $tempId, 'batch_id' => (int)$batch['id'], 'customer' => $customer];
}

function bakery_sfb_agent_complete_batch(PDO $db, array $opts = [], $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $batch = bakery_sfb_agent_resolve_batch($db, (int)$customer['id'], (int)($opts['batch'] ?? 0));
    $completed = bakery_sfb_complete_batch(
        $db,
        (int)$customer['id'],
        (int)$batch['id'],
        $opts['loaves'] ?? $opts['loaf-count'] ?? 2,
        $opts['notes'] ?? $opts['final-notes'] ?? ''
    );
    return ['batch' => $completed, 'customer' => $customer];
}

function bakery_sfb_agent_share_batch(PDO $db, array $opts = [], $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $batch = bakery_sfb_agent_resolve_batch($db, (int)$customer['id'], (int)($opts['batch'] ?? 0));
    $share = bakery_sfb_share_batch($db, (int)$customer['id'], (int)$batch['id']);
    return ['share' => $share, 'batch_id' => (int)$batch['id'], 'customer' => $customer];
}

function bakery_sfb_agent_post_topic(PDO $db, array $opts = [], $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $title = trim((string)($opts['title'] ?? ''));
    $body = trim((string)($opts['body'] ?? ''));
    $category = (string)($opts['category'] ?? 'general');
    $batchId = (int)($opts['batch'] ?? 0);
    if (bakery_sfb_is_synthetic($customer)) {
        bakery_sfb_synthetic_eval_assert_post([
            'title' => $title,
            'body' => $body,
            'customer' => $customer,
            'origin' => $customer['sfb_origin'] ?? 'synthetic',
            'is_mentor' => function_exists('bakery_sfb_persona_is_mentor')
                && bakery_sfb_persona_is_mentor($db, (int)$customer['id']),
            'author_kind' => 'baker',
            'author_type' => 'baker',
        ]);
    }
    $topicId = bakery_sfb_create_community_topic(
        $db,
        (int)$customer['id'],
        $title,
        $body,
        $category,
        $batchId
    );
    return ['topic_id' => $topicId, 'customer' => $customer, 'batch_id' => $batchId];
}

function bakery_sfb_agent_reply(PDO $db, array $opts = [], $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $topicId = (int)($opts['topic'] ?? 0);
    $body = trim((string)($opts['body'] ?? ''));
    if (bakery_sfb_is_synthetic($customer)) {
        bakery_sfb_synthetic_eval_assert_post([
            'body' => $body,
            'customer' => $customer,
            'origin' => $customer['sfb_origin'] ?? 'synthetic',
            'is_mentor' => function_exists('bakery_sfb_persona_is_mentor')
                && bakery_sfb_persona_is_mentor($db, (int)$customer['id']),
            'author_kind' => 'baker',
            'author_type' => 'baker',
        ]);
    }
    $replyId = bakery_sfb_add_community_reply($db, $topicId, (int)$customer['id'], $body);
    return ['reply_id' => $replyId, 'topic_id' => $topicId, 'customer' => $customer];
}

function bakery_sfb_agent_ask_coach(PDO $db, array $opts = [], $customerNameOrId = '') {
    $customer = bakery_sfb_agent_acting_customer($db, $customerNameOrId);
    $batch = bakery_sfb_agent_resolve_batch($db, (int)$customer['id'], (int)($opts['batch'] ?? 0));
    $messageId = bakery_sfb_add_batch_message(
        $db,
        (int)$batch['id'],
        'baker',
        (string)$customer['name'],
        (string)($opts['body'] ?? ''),
        'question',
        (int)$customer['id']
    );
    return ['message_id' => $messageId, 'batch_id' => (int)$batch['id'], 'customer' => $customer];
}

function bakery_sfb_agent_status(PDO $db, $origin = 'all') {
    $admin = bakery_sfb_agent_load_admin($db);
    $state = bakery_sfb_agent_state();
    $impersonation = bakery_sfb_agent_impersonating();
    $bakers = [];
    if (bakery_sfb_tables_ready($db)) {
        $bakers = bakery_sfb_admin_bakers($db);
    }
    $origin = strtolower(trim((string)$origin));
    if (in_array($origin, ['synthetic', 'human'], true)) {
        $bakers = array_values(array_filter($bakers, function ($baker) use ($origin) {
            return bakery_sfb_normalize_origin($baker['sfb_origin'] ?? 'human') === $origin;
        }));
    }
    return [
        'admin' => $admin,
        'logged_in_admin' => $state['admin'],
        'acting_customer' => $state['customer'] ?: ($impersonation ?: null),
        'impersonating' => $impersonation,
        'origin' => $origin === '' ? 'all' : $origin,
        'bakers' => $bakers,
    ];
}

/**
 * Create Customer1 and Customer2, log in as each, and start a test batch.
 *
 * @return array
 */
/** True only for isolated test databases — never bakerysf_local or production. */
function bakery_sfb_agent_demo_database_allowed($name) {
    $name = strtolower(trim((string)$name));
    if ($name === '' || $name === 'bakerysf') {
        return false;
    }
    if (str_contains($name, '_local')) {
        return false;
    }
    return str_contains($name, 'test');
}

function bakery_sfb_agent_assert_demo_target(PDO $db) {
    bakery_sfb_agent_assert_local($db);
    $actual = strtolower(trim((string)$db->query('SELECT DATABASE()')->fetchColumn()));
    $configured = strtolower(trim((string)(defined('DB_NAME') ? DB_NAME : '')));
    if (!bakery_sfb_agent_demo_database_allowed($actual)
        || ($configured !== '' && !bakery_sfb_agent_demo_database_allowed($configured))
    ) {
        throw new RuntimeException('Refusing demo: isolated test database required (never bakerysf_local or production)');
    }
}

function bakery_sfb_agent_run_customer_batch_demo(PDO $db) {
    bakery_sfb_agent_assert_demo_target($db);
    $admin = bakery_sfb_agent_login($db);
    $results = [
        'admin' => [
            'id' => (int)$admin['id'],
            'email' => $admin['email'],
            'display_name' => $admin['display_name'],
            'login_code' => $admin['login_code'],
        ],
        'customers' => [],
    ];

    $specs = [
        ['name' => 'Customer1', 'code' => '1101', 'phone' => '555-1101'],
        ['name' => 'Customer2', 'code' => '1102', 'phone' => '555-1102'],
    ];
    foreach ($specs as $spec) {
        $created = bakery_sfb_agent_create_customer($db, $spec['name'], $spec['code'], [
            'phone' => $spec['phone'],
            'address' => 'SF Baker agent fixture',
            'origin' => 'synthetic',
        ]);
        $customer = bakery_sfb_agent_login_as_customer($db, (int)$created['customer']['id']);
        $batch = bakery_sfb_agent_start_batch(
            $db,
            'Agent test batch — ' . $spec['name'],
            0,
            (int)$customer['id']
        );
        $results['customers'][] = [
            'id' => (int)$customer['id'],
            'name' => $customer['name'],
            'created' => $created['created'],
            'portal_code' => $created['portal_code'],
            'logged_in_as' => (string)$customer['name'],
            'batch' => $batch,
        ];
    }

    bakery_sfb_agent_stop_impersonation();
    return $results;
}

require_once __DIR__ . '/sfb_personas.php';
