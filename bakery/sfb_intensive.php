<?php
/**
 * Customer Full Week Intensive. One loaf, one next step, one shared formula.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

if (!bakery_sfb_intensive_ready($db)) {
    http_response_code(503);
    exit('Your Intensive needs a database update before it can open.');
}

bakery_sfb_intensive_ensure_catalog($db);
bakery_sfb_intensive_claim_paid($db, $customerId);

$notice = '';
$noticeKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'formula') {
            bakery_sfb_intensive_save_working_formula($db, $customerId, (array)($_POST['pct'] ?? []));
            header('Location: sfb_intensive.php?saved=formula');
            exit;
        }
        if ($action === 'dough_temp') {
            bakery_sfb_intensive_save_dough_temp($db, $customerId, (string)($_POST['dough_temp_f'] ?? ''));
            header('Location: sfb_intensive.php?saved=temp');
            exit;
        }
        if ($action === 'add_ingredient') {
            bakery_sfb_intensive_add_formula_line(
                $db,
                $customerId,
                (string)($_POST['ingredient_name'] ?? ''),
                (string)($_POST['ingredient_kind'] ?? ''),
                (string)($_POST['ingredient_pct'] ?? '')
            );
            header('Location: sfb_intensive.php?saved=ingredient');
            exit;
        }
        if ($action === 'remove_ingredient') {
            bakery_sfb_intensive_remove_formula_line($db, $customerId, (int)($_POST['line_id'] ?? 0));
            header('Location: sfb_intensive.php?saved=ingredient');
            exit;
        }
        if ($action === 'continue') {
            if (!empty($_POST['pct']) && is_array($_POST['pct'])) {
                bakery_sfb_intensive_save_working_formula($db, $customerId, (array)$_POST['pct']);
            }
            bakery_sfb_intensive_advance_workshop($db, $customerId);
            header('Location: sfb_intensive.php?saved=continued');
            exit;
        }
        if ($action === 'back') {
            $steps = bakery_sfb_intensive_workshop_steps();
            $current = (string)($_POST['step'] ?? 'formula');
            $index = array_search($current, $steps, true);
            $previous = ($index !== false && $index > 0) ? $steps[$index - 1] : 'formula';
            bakery_sfb_intensive_set_workshop_step($db, $customerId, $previous);
            header('Location: sfb_intensive.php');
            exit;
        }
        $bakeActions = [
            'save_mix', 'save_development', 'add_turn', 'delete_turn',
            'save_shape', 'save_bake', 'add_temp', 'delete_temp',
            'upload_photo', 'delete_photo', 'add_discussion',
        ];
        if (in_array($action, $bakeActions, true)) {
            bakery_sfb_intensive_record_bake($db, $customerId, $action, $_POST, $_FILES, (string)$customer['name']);
            header('Location: sfb_intensive.php?saved=bake');
            exit;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
$view = $enrollment ? bakery_sfb_intensive_present($db, $enrollment, true) : null;
if ($view && empty($view['complete']) && empty($view['paused']) && ($view['next_action'] ?? '') === 'start') {
    try {
        bakery_sfb_intensive_start_next_bake($db, $customerId);
        $enrollment = bakery_sfb_intensive_for_customer($db, $customerId);
        $view = $enrollment ? bakery_sfb_intensive_present($db, $enrollment, true) : $view;
    } catch (Throwable $e) {
        if ($notice === '') {
            $notice = $e->getMessage();
            $noticeKind = 'warn';
        }
    }
}
$workshopBatch = null;
$workshopTurns = [];
$workshopTemps = [];
$workshopPhotos = [];
$workshopThreads = ['roots' => [], 'replies' => []];
if ($view && (int)($view['batch_id'] ?? 0) > 0 && empty($view['complete'])) {
    $workshopBatch = bakery_sfb_batch($db, $customerId, (int)$view['batch_id']);
    if ($workshopBatch) {
        $workshopTurns = bakery_sfb_batch_turns($db, (int)$workshopBatch['id']);
        $workshopTemps = bakery_sfb_batch_temps($db, (int)$workshopBatch['id']);
        $workshopPhotos = bakery_sfb_batch_photos($db, (int)$workshopBatch['id']);
        $workshopThreads = bakery_sfb_message_threads(bakery_sfb_batch_messages($db, (int)$workshopBatch['id']));
    }
}
$saved = (string)($_GET['saved'] ?? '');
$savedText = [
    'formula' => bakery_t('sfb.intensive_saved_formula'),
    'temp' => bakery_t('sfb.intensive_saved_temp'),
    'ingredient' => bakery_t('sfb.intensive_saved_ingredient'),
    'continued' => bakery_t('sfb.intensive_saved_continued'),
    'bake' => bakery_t('sfb.intensive_saved_bake'),
];

$page_title = bakery_t('sfb.intensive_title');
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'];
$workshopStepName = is_array($view) ? (string)($view['step'] ?? 'formula') : 'formula';
$workshopContinueKey = 'sfb.intensive_continue';
if ($workshopStepName === 'bake') {
    $workshopContinueKey = 'sfb.intensive_finish_loaf';
} elseif ($workshopStepName === 'done') {
    $workshopContinueKey = 'sfb.intensive_next_loaf';
}
$workshopDock = $view && empty($view['complete']) && empty($view['paused']);
$workshopFormulaDock = $workshopDock
    && $workshopStepName === 'formula'
    && !empty($view['formula']['editable']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <?php require __DIR__ . '/includes/sfb_styles.php'; ?>
  <link rel="stylesheet" href="css/sfb_workshop.css">
</head>
<body class="sfb-body workshop-body">
  <header class="workshop-bar">
    <span><?php echo bakery_sour_flour_logo_img('logo'); ?></span>
    <span class="workshop-tools">
      <?php $langSwitchVariant = 'inline'; require __DIR__ . '/includes/language_switch.php'; ?>
      <a href="customer_portal_logout.php"><?php bakery_te('portal.sign_out'); ?></a>
    </span>
  </header>
  <main class="container sfb-app">

    <?php if ($notice !== ''): ?>
      <div class="notice notice--warn"><?php echo htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif (isset($savedText[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedText[$saved], ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if (!$view): ?>
      <section class="card hero-card">
        <div class="card-body">
          <h2><?php bakery_te('sfb.intensive_title'); ?></h2>
          <p><?php bakery_te('sfb.intensive_absent'); ?></p>
          <a class="btn btn-block" href="sfb_offerings.php"><?php bakery_te('sfb.intensive_shop'); ?></a>
        </div>
      </section>
    <?php elseif (!empty($view['complete'])): ?>
      <section class="card hero-card">
        <div class="card-body">
          <p class="hero-label"><?php bakery_te('sfb.intensive_state_complete'); ?></p>
          <h2 style="margin-top:0;"><?php echo htmlspecialchars((string)$view['goal'], ENT_QUOTES, 'UTF-8'); ?></h2>
          <?php if (!empty($view['summary']['changed'])): ?>
            <p><?php echo nl2br(htmlspecialchars((string)$view['summary']['changed'], ENT_QUOTES, 'UTF-8')); ?></p>
          <?php endif; ?>
          <?php if (!empty($view['summary']['next'])): ?>
            <p><strong><?php bakery_te('sfb.intensive_summary_next'); ?></strong><br><?php echo nl2br(htmlspecialchars((string)$view['summary']['next'], ENT_QUOTES, 'UTF-8')); ?></p>
          <?php endif; ?>
        </div>
      </section>
    <?php else: ?>
      <section class="card hero-card">
        <div class="card-body">
          <p class="hero-label"><?php echo htmlspecialchars(bakery_t('sfb.intensive_loaf_n', ['n' => (string)$view['progress_n']]), ENT_QUOTES, 'UTF-8'); ?> <?php bakery_te('sfb.intensive_of_three'); ?> · <?php bakery_te('sfb.intensive_step_' . $workshopStepName); ?></p>
          <h2 style="margin-top:0;"><?php echo nl2br(htmlspecialchars((string)$view['instruction'], ENT_QUOTES, 'UTF-8')); ?></h2>
          <?php if ($workshopStepName !== 'formula' && empty($view['paused'])): ?>
            <form method="post" class="ws-flow-back" style="margin-top:8px;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="back">
              <input type="hidden" name="step" value="<?php echo htmlspecialchars($workshopStepName, ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.intensive_back'); ?></button>
            </form>
          <?php endif; ?>
          <?php if (empty($view['paused']) && ($workshopStepName !== 'formula' || empty($view['formula']['editable']))): ?>
            <form method="post" class="ws-flow-continue" style="margin-top:10px;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="continue">
              <button type="submit" class="btn btn-block"><?php bakery_te($workshopContinueKey); ?></button>
            </form>
          <?php endif; ?>
        </div>
      </section>

      <?php if (!empty($view['formula']['lines'])): ?>
        <?php
        $flourCount = 0;
        foreach ($view['formula']['lines'] as $flourLine) {
            if (($flourLine['kind'] ?? '') === 'flour') {
                $flourCount++;
            }
        }
        ?>
        <section class="card">
          <div class="card-header"><h2><?php bakery_te('sfb.intensive_formula_title'); ?></h2></div>
          <div class="card-body">
            <p class="muted" style="margin-top:0;"><?php bakery_te($view['formula']['editable'] ? 'sfb.intensive_flour_rule' : 'sfb.intensive_formula_locked'); ?></p>
            <?php if ($workshopStepName === 'formula' && !empty($view['formula']['editable'])): ?>
              <form method="post" id="ws-formula">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="formula">
                <?php foreach ($view['formula']['lines'] as $line): ?>
                  <?php $isFlour = ($line['kind'] ?? '') === 'flour'; ?>
                  <div class="ws-line">
                    <span class="ws-line__name"><?php echo htmlspecialchars($line['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($isFlour && $flourCount === 1): ?>
                      <span class="ws-line__pct">100%</span>
                    <?php else: ?>
                      <input type="number" name="pct[<?php echo (int)$line['id']; ?>]" min="0.1" max="500" step="0.1" inputmode="decimal" value="<?php echo htmlspecialchars((string)$line['percentage'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($line['name'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <span class="muted"><?php echo htmlspecialchars((string)$line['grams'], ENT_QUOTES, 'UTF-8'); ?> g</span>
                  </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.intensive_formula_save'); ?></button>
                <button type="submit" class="btn btn-block ws-flow-continue" style="margin-top:8px;" name="action" value="continue"><?php bakery_te('sfb.intensive_continue'); ?></button>
              </form>
              <?php foreach ($view['formula']['lines'] as $line): ?>
                <?php $isFlour = ($line['kind'] ?? '') === 'flour'; ?>
                <?php if ($isFlour && $flourCount === 1) { continue; } ?>
                <form method="post" style="margin-top:4px;">
                  <?php echo bakery_csrf_field(); ?>
                  <input type="hidden" name="action" value="remove_ingredient">
                  <input type="hidden" name="line_id" value="<?php echo (int)$line['id']; ?>">
                  <button type="submit" class="btn-link"><?php echo htmlspecialchars(bakery_t('sfb.intensive_remove_named', ['name' => (string)$line['name']]), ENT_QUOTES, 'UTF-8'); ?></button>
                </form>
              <?php endforeach; ?>
              <form method="post" class="ws-fields" style="margin-top:16px;">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="add_ingredient">
                <p style="margin:0 0 8px;"><?php bakery_te('sfb.intensive_add_ingredient'); ?></p>
                <label style="display:block;margin:0 0 8px;">
                  <span class="muted"><?php bakery_te('sfb.intensive_ingredient_name'); ?></span>
                  <input type="text" name="ingredient_name" maxlength="100" required style="display:block;width:100%;margin-top:4px;padding:10px;">
                </label>
                <label style="display:block;margin:0 0 8px;">
                  <span class="muted"><?php bakery_te('sfb.intensive_ingredient_kind'); ?></span>
                  <select name="ingredient_kind" style="display:block;width:100%;margin-top:4px;padding:10px;">
                    <?php foreach (['flour', 'water', 'salt', 'starter', 'other'] as $kind): ?>
                      <option value="<?php echo $kind; ?>"><?php bakery_te('sfb.intensive_kind_' . $kind); ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label style="display:block;margin:0 0 8px;">
                  <span class="muted"><?php bakery_te('sfb.intensive_ingredient_pct'); ?></span>
                  <input type="number" name="ingredient_pct" min="0.1" max="500" step="0.1" value="10" style="display:block;width:8rem;margin-top:4px;padding:10px;">
                </label>
                <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.intensive_add'); ?></button>
              </form>
            <?php else: ?>
              <ul class="line-list">
                <?php foreach ($view['formula']['lines'] as $line): ?>
                  <li>
                    <span><?php echo htmlspecialchars($line['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <span><?php echo htmlspecialchars((string)$line['percentage'], ENT_QUOTES, 'UTF-8'); ?>% · <?php echo htmlspecialchars((string)$line['grams'], ENT_QUOTES, 'UTF-8'); ?> g</span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($workshopBatch): ?>
        <?php require __DIR__ . '/includes/sfb_workshop_bake.php'; ?>
      <?php endif; ?>

      <?php if (!empty($view['facts_editable']) || ($view['dough_temp_f'] ?? '') !== ''): ?>
        <details class="card" <?php echo ($view['dough_temp_f'] ?? '') !== '' ? 'open' : ''; ?>>
          <summary style="cursor:pointer;padding:16px;"><?php bakery_te('sfb.intensive_thermometer'); ?></summary>
          <div class="card-body" style="padding-top:0;">
            <?php if (!empty($view['facts_editable'])): ?>
              <form method="post">
                <?php echo bakery_csrf_field(); ?>
                <input type="hidden" name="action" value="dough_temp">
                <label style="display:block;">
                  <span><?php bakery_te('sfb.intensive_dough_temp'); ?></span>
                  <input type="number" name="dough_temp_f" min="40" max="120" step="0.1" value="<?php echo htmlspecialchars((string)($view['dough_temp_f'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="display:block;width:8rem;margin-top:6px;padding:10px;">
                </label>
                <button type="submit" class="btn btn-secondary btn-block" style="margin-top:10px;"><?php bakery_te('sfb.intensive_detail_save'); ?></button>
              </form>
            <?php else: ?>
              <p><?php echo htmlspecialchars((string)$view['dough_temp_f'], ENT_QUOTES, 'UTF-8'); ?>°F</p>
            <?php endif; ?>
          </div>
        </details>
      <?php endif; ?>
    <?php endif; ?>
  </main>
  <?php if ($workshopDock): ?>
    <div class="ws-dock">
      <?php if ($workshopStepName !== 'formula'): ?>
        <form method="post">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="back">
          <input type="hidden" name="step" value="<?php echo htmlspecialchars($workshopStepName, ENT_QUOTES, 'UTF-8'); ?>">
          <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.intensive_back'); ?></button>
        </form>
      <?php endif; ?>
      <?php if ($workshopFormulaDock): ?>
        <button type="submit" class="btn btn-block" form="ws-formula" name="action" value="continue"><?php bakery_te($workshopContinueKey); ?></button>
      <?php else: ?>
        <form method="post">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="continue">
          <button type="submit" class="btn btn-block"><?php bakery_te($workshopContinueKey); ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</body>
</html>
