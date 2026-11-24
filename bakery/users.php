<?php
/**
 * User management — roles, names, and 4-digit login codes.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/users_admin.php';
require_once __DIR__ . '/includes/user_activity.php';

// Role and sign-in-code changes are administrator-only. Managers can run every
// operational workspace module, but cannot change who has access to the app.
bakery_require_role(['administrator']);
bakery_ensure_login_code_column($db);
bakery_ensure_actor_identity_schema($db);

$page_title = bakery_t('page.users');
$error = '';
$success = '';
$roleChangePreview = null;

$roleLabels = bakery_assignable_role_labels();

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
        if ($action === 'reveal_code') {
            header('Content-Type: application/json');
            echo json_encode(bakery_users_reveal_code($db, (int)($_POST['id'] ?? 0)));
            exit;
        }
        if ($action === 'create' || $action === 'update') {
            $saved = bakery_users_save($db, $_POST);
            $success = $saved['message'];
        } elseif ($action === 'deactivate') {
            $id = (int)($_POST['id'] ?? 0);
            $reason = (string)($_POST['reason'] ?? 'admin');
            $success = bakery_users_set_active($db, $id, 0, $reason);
        } elseif ($action === 'activate') {
            $success = bakery_users_set_active($db, (int)($_POST['id'] ?? 0), 1);
        } elseif ($action === 'change_role_review') {
            $roleChangePreview = bakery_users_role_change_preview(
                $db,
                (int)($_POST['id'] ?? 0),
                (string)($_POST['role_slug'] ?? '')
            );
        } elseif ($action === 'change_role') {
            $saved = bakery_users_change_role(
                $db,
                (int)($_POST['id'] ?? 0),
                (string)($_POST['role_slug'] ?? '')
            );
            $success = !empty($saved['driver_cleared'])
                ? bakery_t('users.change_role_saved_driver_cleared')
                : bakery_t('users.change_role_saved');
        }
    } catch (Exception $e) {
        if ($action === 'reveal_code') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
        $error = $e->getMessage();
    }
}

$roles = bakery_users_page_roles($db);
$drivers = bakery_users_page_drivers($db);
$botSelect = function_exists('column_exists') && column_exists($db, 'users', 'is_bot')
    ? 'u.is_bot'
    : '0 AS is_bot';
$users = $db->query(
    "SELECT u.id, u.email, u.login_code, u.display_name, u.driver_id, u.is_active, {$botSelect},
            u.last_login_at, r.slug AS role_slug, r.name AS role_name, d.name AS driver_name
     FROM users u
     JOIN roles r ON r.id = u.role_id
     LEFT JOIN drivers d ON d.id = u.driver_id
     ORDER BY r.name, u.display_name"
)->fetchAll(PDO::FETCH_ASSOC);
$inactiveAccounts = bakery_users_inactive_accounts($db);

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
.users-reveal { margin-left: 0.35rem; border: 0; background: transparent; color: #1a5276; font-weight: 650; cursor: pointer; text-decoration: underline; padding: 0; }
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
  <h1><?php bakery_te('page.users'); ?></h1>
  <p class="lead">Login types, names, and 4-digit codes for bakery staff.</p>

  <?php if ($error): ?>
    <div class="users-alert error"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="users-alert success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <?php
  $changeRolePick = null;
  if (!$roleChangePreview) {
      $changeRoleId = (int)($_GET['change_role'] ?? 0);
      if ($changeRoleId > 0) {
          foreach ($users as $candidate) {
              if ((int)$candidate['id'] === $changeRoleId) {
                  $changeRolePick = $candidate;
                  break;
              }
          }
      }
  }
  ?>
  <?php if ($roleChangePreview || $changeRolePick || (int)($_GET['change_role'] ?? 0) > 0): ?>
    <div class="users-panel" style="margin-bottom: 1.5rem;">
      <h2><?php bakery_te('users.change_role'); ?></h2>
      <p class="lead"><?php bakery_te('users.change_role_only'); ?></p>
      <p class="users-muted"><?php bakery_te('users.change_role_driver_rule'); ?></p>
      <?php if ($roleChangePreview): ?>
        <?php if (!empty($roleChangePreview['same'])): ?>
          <p><?php bakery_te('users.change_role_same'); ?></p>
          <p><a href="<?php echo htmlspecialchars(BASE_URL . 'users.php'); ?>"><?php bakery_te('users.change_role_cancel'); ?></a></p>
        <?php else: ?>
          <p><?php echo htmlspecialchars(bakery_t('users.change_role_confirm', [
              'name' => (string)$roleChangePreview['display_name'],
              'old_role' => (string)$roleChangePreview['old_role'],
              'new_role' => (string)$roleChangePreview['new_role'],
          ])); ?></p>
          <?php if (!empty($roleChangePreview['driver_will_clear'])): ?>
            <p><?php bakery_te('users.change_role_driver_will_clear'); ?></p>
          <?php endif; ?>
          <form method="post" class="users-form">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="change_role">
            <input type="hidden" name="id" value="<?php echo (int)$roleChangePreview['id']; ?>">
            <input type="hidden" name="role_slug" value="<?php echo htmlspecialchars((string)$roleChangePreview['new_slug']); ?>">
            <div class="actions">
              <button type="submit"><?php bakery_te('users.change_role_confirm_button'); ?></button>
              <a class="btn secondary" href="<?php echo htmlspecialchars(BASE_URL . 'users.php'); ?>"><?php bakery_te('users.change_role_cancel'); ?></a>
            </div>
          </form>
        <?php endif; ?>
      <?php elseif ($changeRolePick): ?>
        <form method="post" class="users-form">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="change_role_review">
          <input type="hidden" name="id" value="<?php echo (int)$changeRolePick['id']; ?>">
          <label for="change_role_slug"><?php bakery_te('users.change_role_pick'); ?></label>
          <select id="change_role_slug" name="role_slug" required>
            <?php foreach ($roles as $role):
                if (!isset($roleLabels[$role['slug']])) {
                    continue;
                }
            ?>
              <option value="<?php echo htmlspecialchars($role['slug']); ?>"
                <?php echo (string)$changeRolePick['role_slug'] === (string)$role['slug'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars(bakery_navigation_role_label($role['slug'])); ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="actions">
            <button type="submit"><?php bakery_te('users.change_role_review'); ?></button>
            <a class="btn secondary" href="<?php echo htmlspecialchars(BASE_URL . 'users.php'); ?>"><?php bakery_te('users.change_role_cancel'); ?></a>
          </div>
        </form>
      <?php else: ?>
        <p class="users-muted"><?php bakery_te('users.change_role_missing'); ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="users-panel" style="margin-bottom: 1.5rem;">
    <h2><?php bakery_te('users.inactive_title'); ?></h2>
    <p class="lead"><?php bakery_te('users.inactive_lead'); ?></p>
    <?php if (!$inactiveAccounts): ?>
      <p class="users-muted"><?php bakery_te('users.inactive_none'); ?></p>
    <?php else: ?>
      <table class="users-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Type</th>
            <th><?php bakery_te('users.last_activity'); ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($inactiveAccounts as $quiet): ?>
            <tr>
              <td><strong><?php echo htmlspecialchars((string)$quiet['display_name']); ?></strong></td>
              <td><?php echo htmlspecialchars((string)$quiet['role_name']); ?></td>
              <td class="users-muted">
                <?php echo $quiet['last_activity_at']
                    ? htmlspecialchars((string)$quiet['last_activity_at'])
                    : htmlspecialchars(bakery_t('users.never')); ?>
              </td>
              <td>
                <form method="post" style="display:inline" onsubmit="return confirm('Deactivate this user?');">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="deactivate">
                  <input type="hidden" name="reason" value="inactive_60_days">
                  <input type="hidden" name="id" value="<?php echo (int)$quiet['id']; ?>">
                  <button type="submit" class="danger"><?php bakery_te('users.inactive_deactivate'); ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

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
                <?php if (!empty($u['is_bot'])): ?>
                  <span class="users-badge"><?php bakery_te('actor.bot'); ?></span>
                <?php endif; ?>
                <?php if (bakery_is_driver_route_role($u['role_slug']) && !empty($u['driver_name'])): ?>
                  <div class="users-muted"><?php echo bakery_t($u['role_slug'] === 'driver_assistant' ? 'users.paired_driver' : 'users.driver'); ?>: <?php echo htmlspecialchars($u['driver_name']); ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="users-code" id="users-code-<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars(bakery_audit_mask_staff_code($u['login_code'] ?? '')); ?></span>
                <button type="button" class="users-reveal" data-user-id="<?php echo (int)$u['id']; ?>"><?php echo htmlspecialchars(bakery_t('users.reveal_code')); ?></button>
              </td>
              <td>
                <div class="users-actions">
                  <a href="<?php echo htmlspecialchars(BASE_URL . 'users.php?edit=' . (int)$u['id']); ?>">Edit</a>
                  <a href="<?php echo htmlspecialchars(BASE_URL . 'users.php?change_role=' . (int)$u['id']); ?>"><?php bakery_te('users.change_role'); ?></a>
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
            if (!isset($roleLabels[$role['slug']])) {
                continue;
            }
          ?>
            <option value="<?php echo htmlspecialchars($role['slug']); ?>"
              <?php echo $selectedRole === $role['slug'] ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars(bakery_navigation_role_label($role['slug'])); ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="login_code">4-digit code</label>
        <input type="text" id="login_code" name="login_code" inputmode="numeric"
               pattern="[0-9]{4}" maxlength="4" minlength="4"
               <?php echo $editUser ? '' : 'required'; ?>
               placeholder="<?php echo $editUser ? htmlspecialchars(bakery_audit_mask_staff_code($editUser['login_code'] ?? '')) : ''; ?>"
               value="<?php echo htmlspecialchars($editUser ? (($_POST['login_code'] ?? '') !== '' && $error ? (string)$_POST['login_code'] : '') : (string)($_POST['login_code'] ?? '')); ?>">
        <?php if ($editUser): ?>
          <div class="users-muted"><?php echo htmlspecialchars(bakery_t('users.code_keep')); ?></div>
        <?php endif; ?>

        <div class="driver-link-fields" id="driverFields">
          <label for="driver_id"><?php bakery_te('users.route_driver'); ?></label>
          <select id="driver_id" name="driver_id">
 <option value="0">(None)</option>
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

        <div class="row-check">
          <input type="checkbox" id="is_bot" name="is_bot" value="1"
            <?php
            $botChecked = !empty($editUser['is_bot'])
                || in_array((string)($editUser['display_name'] ?? ($_POST['display_name'] ?? '')), bakery_known_bot_display_names(), true);
            echo $botChecked ? 'checked' : '';
            ?>>
          <label for="is_bot"><?php bakery_te('users.bot_account'); ?></label>
        </div>

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
  document.querySelectorAll('.users-reveal').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var id = btn.getAttribute('data-user-id');
      var token = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
      var body = new URLSearchParams();
      body.set('action', 'reveal_code');
      body.set('id', id);
      body.set('csrf_token', token);
      fetch('users.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: body.toString()
      }).then(function (response) { return response.json(); }).then(function (data) {
        var slot = document.getElementById('users-code-' + id);
        if (data && data.ok && slot) {
          slot.textContent = data.code;
        }
      });
    });
  });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
