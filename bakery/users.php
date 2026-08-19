<?php
/**
 * User management — roles, names, and 4-digit login codes.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';

// Role and sign-in-code changes are administrator-only. Managers can run every
// operational workspace module, but cannot change who has access to the app.
bakery_require_role(['administrator']);
bakery_ensure_login_code_column($db);

$page_title = bakery_t('page.users');
$error = '';
$success = '';

$roleLabels = [
    'administrator' => 'Administrator',
    'manager' => 'Manager',
    'baker' => 'Baker',
    'driver' => 'Driver',
    'driver_assistant' => 'Driver Assistant',
];

function bakery_users_page_roles(PDO $db) {
    return $db->query('SELECT id, slug, name FROM roles ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

function bakery_users_page_drivers(PDO $db) {
    if (function_exists('bakery_ensure_drivers_archived_column')) {
        bakery_ensure_drivers_archived_column($db);
    }
    if (function_exists('bakery_get_drivers')) {
        return bakery_get_drivers($db, false);
    }
    return $db->query('SELECT id, name FROM drivers ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create' || $action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            $roleSlug = trim((string)($_POST['role_slug'] ?? ''));
            $code = bakery_normalize_login_code($_POST['login_code'] ?? '');
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $driverId = (int)($_POST['driver_id'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($displayName === '') {
                throw new Exception('Name is required.');
            }
            if (!isset($roleLabels[$roleSlug])) {
                throw new Exception('Invalid user type.');
            }
            if ($code === '') {
                throw new Exception('Login code must be exactly 4 digits.');
            }

            $roleStmt = $db->prepare('SELECT id FROM roles WHERE slug = ? LIMIT 1');
            $roleStmt->execute([$roleSlug]);
            $roleId = $roleStmt->fetchColumn();
            if (!$roleId) {
                throw new Exception('Role not found.');
            }

            if (!bakery_is_driver_route_role($roleSlug)) {
                $driverId = 0;
            }
            $driverIdValue = $driverId > 0 ? $driverId : null;

            if ($email === '') {
                $slug = preg_replace('/[^a-z0-9]+/', '.', strtolower($displayName));
                $slug = trim($slug, '.') ?: 'user';
                $email = $slug . '@sourflour.local';
            }

            $clash = $db->prepare(
                'SELECT id FROM users WHERE login_code = ? AND id <> ? LIMIT 1'
            );
            $clash->execute([$code, $id]);
            if ($clash->fetchColumn()) {
                throw new Exception('That login code is already in use.');
            }

            $emailClash = $db->prepare(
                'SELECT id FROM users WHERE LOWER(email) = LOWER(?) AND id <> ? LIMIT 1'
            );
            $emailClash->execute([$email, $id]);
            if ($emailClash->fetchColumn()) {
                throw new Exception('That email is already in use.');
            }

            $hash = password_hash($code, PASSWORD_DEFAULT);

            if ($action === 'create') {
                $stmt = $db->prepare(
                    'INSERT INTO users (email, password_hash, login_code, display_name, role_id, driver_id, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)'
                );
                $stmt->execute([$email, $hash, $code, $displayName, (int)$roleId, $driverIdValue]);
                $id = (int)$db->lastInsertId();
                $success = 'User created.';
            } else {
                if ($id <= 0) {
                    throw new Exception('Invalid user.');
                }
                $stmt = $db->prepare(
                    'UPDATE users
                     SET email = ?, password_hash = ?, login_code = ?, display_name = ?,
                         role_id = ?, driver_id = ?, is_active = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $email,
                    $hash,
                    $code,
                    $displayName,
                    (int)$roleId,
                    $driverIdValue,
                    $isActive,
                    $id,
                ]);
                $success = 'User updated.';
            }

            $pairingDate = trim((string)($_POST['pairing_date'] ?? ''));
            if ($roleSlug === 'driver_assistant' && $pairingDate !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $pairingDate);
                if (!$date || $date->format('Y-m-d') !== $pairingDate) {
                    throw new Exception('Route pairing date must use YYYY-MM-DD.');
                }
                if ($driverIdValue === null) {
                    throw new Exception('Choose the driver this assistant will pair with.');
                }
                if (!table_exists($db, 'driver_assistant_assignments')) {
                    throw new Exception('Driver Assistant pairing is not installed yet. Run migration 045.');
                }
                $pair = $db->prepare(
                    'INSERT INTO driver_assistant_assignments (assistant_user_id, driver_id, delivery_date)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE driver_id = VALUES(driver_id)'
                );
                $pair->execute([$id, $driverIdValue, $pairingDate]);
                $success .= ' Route pairing saved for ' . $pairingDate . '.';
            }
        } elseif ($action === 'deactivate') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Invalid user.');
            }
            $current = bakery_current_user();
            if ($current && (int)$current['id'] === $id) {
                throw new Exception('You cannot deactivate your own account.');
            }
            $stmt = $db->prepare('UPDATE users SET is_active = 0 WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'User deactivated.';
        } elseif ($action === 'activate') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('Invalid user.');
            }
            $stmt = $db->prepare('UPDATE users SET is_active = 1 WHERE id = ?');
            $stmt->execute([$id]);
            $success = 'User activated.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$roles = bakery_users_page_roles($db);
$drivers = bakery_users_page_drivers($db);
$users = $db->query(
    "SELECT u.id, u.email, u.login_code, u.display_name, u.driver_id, u.is_active,
            u.last_login_at, r.slug AS role_slug, r.name AS role_name, d.name AS driver_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN drivers d ON d.id = u.driver_id
     ORDER BY r.name, u.display_name"
)->fetchAll(PDO::FETCH_ASSOC);

$editId = (int)($_GET['edit'] ?? 0);
$editUser = null;
if ($editId > 0) {
    foreach ($users as $u) {
        if ((int)$u['id'] === $editId) {
            $editUser = $u;
            break;
        }
    }
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/nav.php';
?>
<style>
.users-page { max-width: 960px; margin: 1.5rem auto; padding: 0 1rem 2rem; font-family: Segoe UI, sans-serif; }
.users-page h1 { margin: 0 0 0.35rem; color: #2c3e50; font-size: 1.6rem; }
.users-page .lead { color: #666; margin: 0 0 1.25rem; }
.users-alert { padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
.users-alert.error { background: #f8d7da; color: #721c24; }
.users-alert.success { background: #d4edda; color: #155724; }
.users-grid { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
@media (min-width: 900px) {
  .users-grid { grid-template-columns: 1.2fr 1fr; align-items: start; }
}
.users-panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.25rem; }
.users-panel h2 { margin: 0 0 1rem; font-size: 1.1rem; color: #2c3e50; }
.users-table { width: 100%; border-collapse: collapse; }
.users-table th, .users-table td { text-align: left; padding: 0.65rem 0.5rem; border-bottom: 1px solid #eee; vertical-align: top; }
.users-table th { color: #555; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.03em; }
.users-code { font-family: Consolas, monospace; font-size: 1.05rem; letter-spacing: 0.12em; font-weight: 700; }
.users-muted { color: #888; font-size: 0.85rem; }
.users-badge {
  display: inline-block; padding: 0.15rem 0.5rem; border-radius: 999px;
  background: #eef2f7; color: #2c3e50; font-size: 0.8rem; font-weight: 600;
}
.users-badge.inactive { background: #f8d7da; color: #721c24; }
.users-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; }
.users-actions a, .users-actions button {
  font-size: 0.85rem; padding: 0.3rem 0.55rem; border-radius: 4px; border: 1px solid #ccc;
  background: #fff; color: #2c3e50; text-decoration: none; cursor: pointer;
}
.users-actions button.danger { border-color: #e74c3c; color: #c0392b; }
.users-form label { display: block; margin: 0.7rem 0 0.25rem; font-weight: 600; color: #333; }
.users-form input[type=text], .users-form input[type=email], .users-form select {
  width: 100%; box-sizing: border-box; padding: 0.55rem 0.65rem; border: 1px solid #ccc; border-radius: 4px;
}
.users-form .row-check { margin-top: 0.9rem; display: flex; align-items: center; gap: 0.45rem; }
.users-form .row-check label { margin: 0; font-weight: 500; }
.users-form .actions { margin-top: 1.1rem; display: flex; gap: 0.6rem; flex-wrap: wrap; }
.users-form button, .users-form a.btn {
  padding: 0.6rem 1rem; border: 0; border-radius: 4px; background: #2c3e50; color: #fff;
  font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block;
}
.users-form a.btn.secondary { background: #95a5a6; }
.driver-link-fields[hidden] { display: none !important; }
</style>

<div class="users-page">
  <h1>User Management</h1>
  <p class="lead">Login types, names, and 4-digit codes for bakery staff.</p>

  <?php if ($error): ?>
    <div class="users-alert error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="users-alert success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <div class="users-grid">
    <div class="users-panel">
      <h2>Staff</h2>
      <table class="users-table">
        <thead>
          <tr>
            <th>Type</th>
            <th>Name</th>
            <th>Code</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$users): ?>
            <tr><td colspan="4" class="users-muted">No users yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($users as $u): ?>
            <tr>
              <td>
                <span class="users-badge<?php echo (int)$u['is_active'] === 1 ? '' : ' inactive'; ?>">
                  <?php echo htmlspecialchars($u['role_name']); ?>
                </span>
                <?php if ((int)$u['is_active'] !== 1): ?>
                  <div class="users-muted">Inactive</div>
                <?php endif; ?>
              </td>
              <td>
                <strong><?php echo htmlspecialchars($u['display_name']); ?></strong>
                <?php if (bakery_is_driver_route_role($u['role_slug']) && !empty($u['driver_name'])): ?>
                  <div class="users-muted"><?php echo bakery_t($u['role_slug'] === 'driver_assistant' ? 'users.paired_driver' : 'users.driver'); ?>: <?php echo htmlspecialchars($u['driver_name']); ?></div>
                <?php endif; ?>
              </td>
              <td><span class="users-code"><?php echo htmlspecialchars($u['login_code'] ?? '————'); ?></span></td>
              <td>
                <div class="users-actions">
                  <a href="<?php echo htmlspecialchars(BASE_URL . 'users.php?edit=' . (int)$u['id']); ?>">Edit</a>
                  <?php if ((int)$u['is_active'] === 1): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this user?');">
                      <?php echo bakery_csrf_field(); ?>
                      <input type="hidden" name="action" value="deactivate">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <button type="submit" class="danger">Deactivate</button>
                    </form>
                  <?php else: ?>
                    <form method="post" style="display:inline">
                      <?php echo bakery_csrf_field(); ?>
                      <input type="hidden" name="action" value="activate">
                      <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                      <button type="submit">Activate</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="users-panel">
      <h2><?php echo $editUser ? 'Edit user' : 'Add user'; ?></h2>
      <form method="post" class="users-form" id="userForm">
        <?php echo bakery_csrf_field(); ?>
        <input type="hidden" name="action" value="<?php echo $editUser ? 'update' : 'create'; ?>">
        <?php if ($editUser): ?>
          <input type="hidden" name="id" value="<?php echo (int)$editUser['id']; ?>">
        <?php endif; ?>

        <label for="display_name">Name</label>
        <input type="text" id="display_name" name="display_name" required maxlength="100"
               value="<?php echo htmlspecialchars($editUser['display_name'] ?? ($_POST['display_name'] ?? '')); ?>">

        <label for="role_slug">Type</label>
        <select id="role_slug" name="role_slug" required>
          <?php
          $selectedRole = $editUser['role_slug'] ?? ($_POST['role_slug'] ?? 'driver');
          foreach ($roles as $role):
          ?>
            <option value="<?php echo htmlspecialchars($role['slug']); ?>"
              <?php echo $selectedRole === $role['slug'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($role['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="login_code">4-digit code</label>
        <input type="text" id="login_code" name="login_code" required inputmode="numeric"
               pattern="[0-9]{4}" maxlength="4" minlength="4"
               value="<?php echo htmlspecialchars($editUser['login_code'] ?? ($_POST['login_code'] ?? '')); ?>">

        <div class="driver-link-fields" id="driverFields">
          <label for="driver_id"><?php bakery_te('users.route_driver'); ?></label>
          <select id="driver_id" name="driver_id">
            <option value="0">— None —</option>
            <?php
            $selectedDriver = (int)($editUser['driver_id'] ?? ($_POST['driver_id'] ?? 0));
            foreach ($drivers as $driver):
            ?>
              <option value="<?php echo (int)$driver['id']; ?>"
                <?php echo $selectedDriver === (int)$driver['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($driver['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="driver-link-fields" id="pairingDateFields">
          <label for="pairing_date"><?php bakery_te('users.pairing_date'); ?></label>
          <input type="text" id="pairing_date" name="pairing_date" inputmode="numeric" placeholder="YYYY-MM-DD"
                 value="<?php echo htmlspecialchars($_POST['pairing_date'] ?? ''); ?>">
          <div class="users-muted"><?php bakery_te('users.pairing_date_hint'); ?></div>
        </div>

        <label for="email">Email (optional identifier)</label>
        <input type="email" id="email" name="email"
               value="<?php echo htmlspecialchars($editUser['email'] ?? ($_POST['email'] ?? '')); ?>">

        <?php if ($editUser): ?>
          <div class="row-check">
            <input type="checkbox" id="is_active" name="is_active" value="1"
              <?php echo (int)$editUser['is_active'] === 1 ? 'checked' : ''; ?>>
            <label for="is_active">Active</label>
          </div>
        <?php endif; ?>

        <div class="actions">
          <button type="submit"><?php echo $editUser ? 'Save changes' : 'Add user'; ?></button>
          <?php if ($editUser): ?>
            <a class="btn secondary" href="<?php echo htmlspecialchars(BASE_URL . 'users.php'); ?>">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  var role = document.getElementById('role_slug');
  var fields = document.getElementById('driverFields');
  var code = document.getElementById('login_code');
  function syncDriverFields() {
    if (!role || !fields) return;
    var isRouteWorker = role.value === 'driver' || role.value === 'driver_assistant';
    fields.hidden = !isRouteWorker;
    document.getElementById('pairingDateFields').hidden = role.value !== 'driver_assistant';
  }
  if (role) {
    role.addEventListener('change', syncDriverFields);
    syncDriverFields();
  }
  if (code) {
    code.addEventListener('input', function () {
      this.value = this.value.replace(/\D/g, '').slice(0, 4);
    });
  }
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
