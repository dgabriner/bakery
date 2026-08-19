<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

$notice = '';
$noticeKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'create_starter':
                $hydrationInput = trim((string)($_POST['hydration_pct'] ?? ''));
                if ($hydrationInput === '') {
                    throw new InvalidArgumentException('Enter a hydration percentage');
                }
                bakery_sfb_create_starter(
                    $db,
                    $customerId,
                    $_POST['name'] ?? '',
                    $_POST['flour_blend'] ?? '',
                    $hydrationInput,
                    $_POST['notes'] ?? ''
                );
                header('Location: sfb_starters.php?saved=created');
                exit;

            case 'update_starter':
                $starter = bakery_sfb_starter($db, $customerId, (int)($_POST['starter_id'] ?? 0));
                if (!$starter) {
                    throw new InvalidArgumentException('Starter not found');
                }
                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException('Starter name is required');
                }
                $hydration = (float)($_POST['hydration_pct'] ?? $starter['hydration_pct']);
                if ($hydration <= 0 || $hydration > 300) {
                    $hydration = (float)$starter['hydration_pct'];
                }
                $stmt = $db->prepare(
                    'UPDATE sfb_starters SET name = ?, flour_blend = ?, hydration_pct = ?, notes = ? WHERE id = ?'
                );
                $stmt->execute([
                    $name,
                    trim((string)($_POST['flour_blend'] ?? '')) !== '' ? trim((string)$_POST['flour_blend']) : null,
                    $hydration,
                    trim((string)($_POST['notes'] ?? '')) !== '' ? trim((string)$_POST['notes']) : null,
                    (int)$starter['id'],
                ]);
                header('Location: sfb_starters.php?starter=' . (int)$starter['id'] . '&saved=updated');
                exit;

            case 'toggle_starter':
                $starter = bakery_sfb_starter($db, $customerId, (int)($_POST['starter_id'] ?? 0));
                if (!$starter) {
                    throw new InvalidArgumentException('Starter not found');
                }
                $db->prepare('UPDATE sfb_starters SET is_active = ? WHERE id = ?')
                    ->execute([(int)$starter['is_active'] === 1 ? 0 : 1, (int)$starter['id']]);
                header('Location: sfb_starters.php?saved=toggled');
                exit;

            case 'add_feeding':
                bakery_sfb_add_starter_feeding(
                    $db,
                    $customerId,
                    (int)($_POST['starter_id'] ?? 0),
                    $_POST['starter_g'] ?? 0,
                    $_POST['flour_g'] ?? 0,
                    $_POST['water_g'] ?? 0,
                    $_POST['fed_at'] ?? '',
                    $_POST['peak_notes'] ?? '',
                    $_POST['notes'] ?? ''
                );
                header('Location: sfb_starters.php?starter=' . (int)($_POST['starter_id'] ?? 0) . '&saved=fed');
                exit;

            case 'delete_feeding':
                $feedingId = (int)($_POST['feeding_id'] ?? 0);
                $stmt = $db->prepare(
                    'DELETE f FROM sfb_starter_feedings f
                     JOIN sfb_starters s ON s.id = f.starter_id
                     WHERE f.id = ? AND s.customer_id = ?'
                );
                $stmt->execute([$feedingId, $customerId]);
                $back = (int)($_POST['starter_id'] ?? 0);
                header('Location: sfb_starters.php' . ($back > 0 ? '?starter=' . $back . '&saved=deleted' : '?saved=deleted'));
                exit;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$starters = bakery_sfb_starters($db, $customerId, false);
$selectedStarter = null;
$feedings = [];
$selectedId = (int)($_GET['starter'] ?? 0);
if ($selectedId > 0) {
    $selectedStarter = bakery_sfb_starter($db, $customerId, $selectedId);
}
if (!$selectedStarter && $starters) {
    foreach ($starters as $candidate) {
        if ((int)$candidate['is_active'] === 1) {
            $selectedStarter = $candidate;
            break;
        }
    }
    if (!$selectedStarter) {
        $selectedStarter = $starters[0];
    }
}
if ($selectedStarter) {
    $feedings = bakery_sfb_starter_feedings($db, (int)$selectedStarter['id']);
}

$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'created' => 'Starter created.',
    'updated' => 'Starter updated.',
    'toggled' => 'Starter updated.',
    'fed' => 'Feeding logged.',
    'deleted' => 'Feeding removed.',
];

$page_title = 'SF Baker — Starters';
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <?php require __DIR__ . '/includes/sfb_styles.php'; ?>
</head>
<body class="sfb-body">
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container sfb-app">
    <?php $sfbActiveTab = 'starters'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php elseif (isset($savedMessages[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
    <?php endif; ?>

    <section class="card">
      <div class="card-header"><h2><?php bakery_te('sfb.starters_title'); ?></h2></div>
      <div class="card-body">
        <?php if (!$starters): ?>
          <p class="empty-state"><?php bakery_te('sfb.no_starters'); ?></p>
        <?php else: ?>
          <ul class="line-list">
            <?php foreach ($starters as $starter): ?>
              <li>
                <span>
                  <a href="sfb_starters.php?starter=<?php echo (int)$starter['id']; ?>" style="color:inherit;">
                    <?php echo htmlspecialchars($starter['name']); ?>
                  </a>
                  <?php if ((int)$starter['is_active'] !== 1): ?>
                    <span class="badge badge-muted"><?php bakery_te('sfb.retired'); ?></span>
                  <?php endif; ?>
                  <br>
                  <small class="muted">
                    <?php echo htmlspecialchars($starter['flour_blend'] ?? ''); ?>
                    <?php echo $starter['flour_blend'] ? ' · ' : ''; ?>
                    <?php echo (float)$starter['hydration_pct']; ?>% <?php bakery_te('sfb.hydration'); ?>
                  </small>
                </span>
                <form method="post" style="margin:0;">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="toggle_starter">
                  <input type="hidden" name="starter_id" value="<?php echo (int)$starter['id']; ?>">
                  <button type="submit" class="btn-link"><?php echo (int)$starter['is_active'] === 1 ? bakery_t('sfb.retire') : bakery_t('sfb.reactivate'); ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <div class="add-row">
        <form method="post" class="inline-form" style="grid-template-columns:1fr;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="create_starter">
          <div class="sfb-field">
            <label><span><?php bakery_te('sfb.starter_name'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_starter_name')); ?></span>
              <input type="text" name="name" required maxlength="100" placeholder="<?php bakery_te('sfb.enter_starter_name'); ?>">
            </label>
          </div>
          <div class="sfb-grid2">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.flour_blend'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_flour_blend')); ?></span>
                <input type="text" name="flour_blend" maxlength="255" placeholder="<?php bakery_te('sfb.enter_flour_blend'); ?>">
              </label>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.hydration_pct'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_hydration')); ?></span>
                <input type="number" name="hydration_pct" min="1" max="300" step="0.5" placeholder="<?php bakery_te('sfb.enter_percentage'); ?>">
              </label>
            </div>
          </div>
          <button type="submit" class="btn btn-block"><?php bakery_te('sfb.add_starter'); ?></button>
        </form>
      </div>
    </section>

    <?php if ($selectedStarter): ?>
      <section class="card">
        <div class="card-header">
          <h2><?php echo htmlspecialchars($selectedStarter['name']); ?> — <?php bakery_te('sfb.feedings'); ?></h2>
        </div>
        <div class="card-body">
          <?php if (!$feedings): ?>
            <p class="empty-state"><?php bakery_te('sfb.no_feedings'); ?></p>
          <?php else: ?>
            <?php foreach ($feedings as $feeding): ?>
              <div class="sfb-feeding">
                <div class="sfb-feeding__top">
                  <strong><?php echo htmlspecialchars(date('D, M j · g:ia', strtotime($feeding['fed_at']))); ?></strong>
                  <span class="sfb-ratio"><?php echo htmlspecialchars(bakery_sfb_feeding_ratio($feeding)); ?></span>
                </div>
                <p class="muted">
                  <?php echo (float)$feeding['starter_g']; ?>g <?php bakery_te('sfb.starter'); ?> ·
                  <?php echo (float)$feeding['flour_g']; ?>g <?php bakery_te('sfb.flour'); ?> ·
                  <?php echo (float)$feeding['water_g']; ?>g <?php bakery_te('sfb.water'); ?>
                  <?php if (!empty($feeding['peak_notes'])): ?>
                    <br><?php echo htmlspecialchars($feeding['peak_notes']); ?>
                  <?php endif; ?>
                  <?php if (!empty($feeding['notes'])): ?>
                    <br><?php echo htmlspecialchars($feeding['notes']); ?>
                  <?php endif; ?>
                </p>
                <form method="post" style="margin:4px 0 0;">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="delete_feeding">
                  <input type="hidden" name="feeding_id" value="<?php echo (int)$feeding['id']; ?>">
                  <input type="hidden" name="starter_id" value="<?php echo (int)$selectedStarter['id']; ?>">
                  <button type="submit" class="btn-link"><?php bakery_te('sfb.delete'); ?></button>
                </form>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div class="add-row">
          <form method="post" class="inline-form" style="grid-template-columns:1fr;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="add_feeding">
            <input type="hidden" name="starter_id" value="<?php echo (int)$selectedStarter['id']; ?>">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.fed_at'); ?></span>
                <input type="datetime-local" name="fed_at" value="<?php echo bakery_sfb_now_local_value(); ?>">
              </label>
            </div>
            <div class="sfb-grid3">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.starter_g'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_feeding_weight')); ?></span>
                  <input type="number" name="starter_g" min="0" step="0.1" placeholder="<?php bakery_te('sfb.enter_grams'); ?>">
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.flour_g'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_feeding_weight')); ?></span>
                  <input type="number" name="flour_g" min="0" step="0.1" placeholder="<?php bakery_te('sfb.enter_grams'); ?>">
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.water_g'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_feeding_weight')); ?></span>
                  <input type="number" name="water_g" min="0" step="0.1" placeholder="<?php bakery_te('sfb.enter_grams'); ?>">
                </label>
              </div>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.peak_notes'); ?><?php echo bakery_sfb_tip(bakery_t('sfb.tip_peak_notes')); ?></span>
                <input type="text" name="peak_notes" maxlength="255" placeholder="<?php bakery_te('sfb.enter_notes'); ?>">
              </label>
            </div>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.log_feeding'); ?></button>
          </form>
        </div>
      </section>

      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.edit_starter'); ?></h2></div>
        <div class="card-body">
          <form method="post" class="inline-form" style="grid-template-columns:1fr;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="update_starter">
            <input type="hidden" name="starter_id" value="<?php echo (int)$selectedStarter['id']; ?>">
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.starter_name'); ?></span>
                <input type="text" name="name" required maxlength="100" value="<?php echo htmlspecialchars($selectedStarter['name']); ?>">
              </label>
            </div>
            <div class="sfb-grid2">
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.flour_blend'); ?></span>
                  <input type="text" name="flour_blend" maxlength="255" value="<?php echo htmlspecialchars($selectedStarter['flour_blend'] ?? ''); ?>">
                </label>
              </div>
              <div class="sfb-field">
                <label><span><?php bakery_te('sfb.hydration_pct'); ?></span>
                  <input type="number" name="hydration_pct" min="1" max="300" step="0.5" value="<?php echo (float)$selectedStarter['hydration_pct']; ?>">
                </label>
              </div>
            </div>
            <div class="sfb-field">
              <label><span><?php bakery_te('sfb.notes'); ?></span>
                <textarea name="notes" rows="2"><?php echo htmlspecialchars($selectedStarter['notes'] ?? ''); ?></textarea>
              </label>
            </div>
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.save_starter'); ?></button>
          </form>
        </div>
      </section>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
