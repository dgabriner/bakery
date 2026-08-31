<?php
/**
 * Route-order survey page body (included from survey.php when kind=route_order).
 * Expects: $db, $survey, $token, $driverId, $verifyDate, $user, $flash, $error, $esc helpers via bakery_survey_text.
 */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}

$isHq = $driverId <= 0;
$hqGroups = $isHq ? bakery_survey_route_order_hq_data($db, $verifyDate) : [];
$driverData = $isHq
    ? ['driver_id' => 0, 'driver_name' => '', 'locked' => [], 'movable' => []]
    : bakery_survey_route_order_data($db, $driverId, $verifyDate);

$driverLinkTokens = [];
if ($isHq) {
    foreach ($hqGroups as $group) {
        $gid = (int)($group['driver_id'] ?? 0);
        if ($gid <= 0) {
            continue;
        }
        try {
            $linkSurvey = bakery_survey_ensure_route_order($db, $gid, $verifyDate, (int)($user['id'] ?? 0));
            $driverLinkTokens[$gid] = (string)($linkSurvey['token'] ?? '');
        } catch (Throwable $e) {
            error_log('route_order driver link: ' . $e->getMessage());
        }
    }
}
$selfUrl = bakery_survey_link_url($token, $verifyDate);
$siblingVerifyUrl = '';
try {
    $sib = bakery_survey_dual_hub_links($db, $driverId, $verifyDate, (int)($user['id'] ?? 0));
    $siblingVerifyUrl = (string)($sib['verify_url'] ?? '');
} catch (Throwable $e) {
    error_log('route_order sibling verify link: ' . $e->getMessage());
}
$pageTitle = (string)bakery_survey_text('survey.page_title', [], 'Survey');
$esc = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

$renderBoard = static function (array $locked, array $movable, int $formDriverId) use ($esc): void {
    $total = count($movable);
    ?>
    <div class="ro-board" data-ro-board data-driver-id="<?php echo (int)$formDriverId; ?>" data-total="<?php echo (int)$total; ?>">
      <div class="ro-top">
        <div class="ro-progress" data-ro-progress><?php echo $esc(bakery_survey_text('survey.route_order_progress', ['done' => '0', 'total' => (string)$total], 'Ordered :done / :total')); ?></div>
        <div class="ro-actions">
          <button type="button" class="btn ghost" data-ro-undo disabled><?php echo $esc(bakery_survey_text('survey.route_order_undo', [], 'Undo')); ?></button>
          <button type="button" class="btn ghost" data-ro-reset disabled><?php echo $esc(bakery_survey_text('survey.route_order_reset', [], 'Start over')); ?></button>
        </div>
      </div>
      <?php if ($locked !== []): ?>
        <p class="meta"><?php echo $esc(bakery_survey_text('survey.route_order_locked', ['count' => count($locked)], 'Already locked (:count) — stay at the front of the route')); ?>:
          <?php
            $names = [];
            foreach ($locked as $row) {
                $names[] = (string)($row['name'] ?? ('#' . (int)($row['daily_order_id'] ?? 0)));
            }
            echo $esc(implode(', ', $names));
          ?>
        </p>
      <?php endif; ?>
      <div class="ro-ordered-label meta"><?php echo $esc(bakery_survey_text('survey.route_order_ordered', [], 'Order so far')); ?></div>
      <div class="ro-ordered" data-ro-ordered></div>
      <div class="ro-remaining-label"><?php echo $esc(bakery_survey_text('survey.route_order_remaining', [], 'Remaining')); ?></div>
      <div class="ro-remaining" data-ro-remaining>
        <?php if ($movable === []): ?>
          <p class="meta"><?php echo $esc(bakery_survey_text('survey.route_order_empty', [], 'No stops to order for this day yet.')); ?></p>
        <?php endif; ?>
        <?php foreach ($movable as $row):
            $oid = (int)($row['daily_order_id'] ?? 0);
            $name = (string)($row['name'] ?? ('#' . $oid));
            ?>
          <button type="button" class="ro-store" data-ro-store data-order-id="<?php echo $oid; ?>" data-name="<?php echo $esc($name); ?>">
            <span class="ro-name"><?php echo $esc($name); ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="driver_id" value="<?php echo (int)$formDriverId; ?>">
      <div data-ro-hidden></div>
      <div class="submit-bar">
        <button type="submit" class="btn primary" data-ro-save disabled><?php echo $esc(bakery_survey_text('survey.route_order_submit', [], 'Save order')); ?></button>
      </div>
    </div>
    <?php
};
?>
<!DOCTYPE html>
<html lang="<?php echo $esc(bakery_locale()); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $esc($pageTitle); ?></title>
<style>
  body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f6f3ee; color: #24303e; }
  main { max-width: 520px; margin: 0 auto; padding: 10px 10px 56px; }
  h1 { font-size: 18px; margin: 6px 0 2px; }
  .sub, .meta, .who { font-size: 12px; opacity: .72; margin: 0 0 8px; }
  .who { font-size: 15px; font-weight: 700; opacity: 1; color: #24303e; }
  .flash { background: #e8f3ea; border: 1px solid #bcd9c2; color: #276b33; padding: 8px 10px; border-radius: 8px; margin-bottom: 8px; font-size: 13px; }
  .flash.err { background: #fdeaea; border-color: #eec3c3; color: #a33; }
  button, .btn { font: inherit; border: none; border-radius: 8px; padding: 8px 11px; cursor: pointer; font-weight: 600; }
  .primary { background: #2c5aa0; color: #fff; }
  .ghost { background: #efe9df; color: #24303e; }
  .btn:disabled, button:disabled { opacity: .45; cursor: default; }
  .date-bar { display: flex; gap: 8px; align-items: end; margin: 0 0 10px; flex-wrap: wrap; }
  .date-bar label { font-size: 11px; font-weight: 700; opacity: .7; display: grid; gap: 3px; flex: 1; min-width: 130px; }
  .date-bar input[type="date"] { font: inherit; padding: 8px 10px; border-radius: 8px; border: 1px solid #d8d0c2; background: #fff; }
  .links-card { background: #fff; border: 1px solid #e4ddd2; border-radius: 10px; padding: 8px 10px; margin: 0 0 10px; font-size: 13px; }
  .links-card .row { display: flex; justify-content: space-between; gap: 8px; padding: 5px 0; border-bottom: 1px solid #efe9df; }
  .links-card .row:last-child { border-bottom: none; }
  .links-card button.linkish { font: inherit; color: #2c5aa0; background: none; border: none; padding: 0; cursor: pointer; font-weight: 600; }
  .driver-block { background: #fff; border: 1px solid #e4ddd2; border-radius: 12px; margin: 0 0 10px; overflow: hidden; }
  .driver-block > summary { list-style: none; cursor: pointer; padding: 12px; font-weight: 700; font-size: 15px; display: flex; justify-content: space-between; gap: 8px; }
  .driver-block > summary::-webkit-details-marker { display: none; }
  .driver-block .body { padding: 0 8px 8px; }
  .ro-top { position: sticky; top: 0; z-index: 4; background: #f6f3ee; padding: 6px 0 8px; display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
  .ro-progress { font-size: 13px; font-weight: 700; }
  .ro-actions { display: flex; gap: 6px; }
  .ro-actions .btn { font-size: 12px; padding: 7px 10px; }
  .ro-ordered-label, .ro-remaining-label { font-size: 11px; font-weight: 700; letter-spacing: .02em; text-transform: uppercase; opacity: .55; margin: 6px 0 4px; }
  .ro-ordered { display: flex; flex-wrap: wrap; gap: 4px; min-height: 28px; margin: 0 0 6px; }
  .ro-chip { font-size: 11px; font-weight: 700; background: #e8f3ea; color: #276b33; border-radius: 999px; padding: 3px 8px; max-width: 100%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .ro-remaining { display: grid; gap: 5px; }
  .ro-store { width: 100%; text-align: left; background: #fff; border: 1.5px solid #d8d0c2; border-radius: 10px; padding: 9px 11px; min-height: 40px; cursor: pointer; font-weight: 600; font-size: 14px; -webkit-tap-highlight-color: transparent; box-sizing: border-box; }
  .ro-store:active { background: #eef3fa; border-color: #2c5aa0; }
  .ro-store.is-picked { display: none; }
  .submit-bar { position: sticky; bottom: 0; background: #f6f3ee; padding: 8px 0 2px; z-index: 5; }
  .submit-bar .btn { width: 100%; padding: 12px 14px; font-size: 15px; }
  .bakery-lang-switch--inline .bakery-lang-switch__btn { border-radius: 999px; color: #6b6256; font-size: .82rem; padding: 6px 12px; text-decoration: none; }
</style>
</head>
<body>
<main>
  <div class="lang-row"><?php $langSwitchVariant = 'inline'; require __DIR__ . '/language_switch.php'; ?></div>
  <?php if ($flash !== ''): ?><div class="flash"><?php echo $esc($flash); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="flash err"><?php echo $esc($error); ?></div><?php endif; ?>

  <h1><?php echo $esc(bakery_survey_text($isHq ? 'survey.route_order_hq_title' : 'survey.route_order_title', [], 'Delivery order')); ?></h1>
  <?php if ($siblingVerifyUrl !== ''): ?>
  <p class="sub"><a href="<?php echo $esc($siblingVerifyUrl); ?>" style="color:#2c5aa0;font-weight:700;"><?php echo $esc(bakery_survey_text('survey.sibling_to_verify', [], '← Back: lock which stores')); ?></a></p>
  <?php endif; ?>
  <?php if ($isHq): ?>
    <p class="sub"><?php echo $esc(bakery_survey_text('survey.route_order_sub', ['date' => $verifyDate], 'Tap stores in the order you will deliver on :date')); ?></p>
  <?php else: ?>
    <p class="who"><?php echo $esc(bakery_survey_text('survey.route_order_driver', ['name' => $driverData['driver_name'] !== '' ? $driverData['driver_name'] : ''], 'Driver: :name')); ?></p>
    <p class="sub"><?php echo $esc(bakery_survey_text('survey.route_order_sub', ['date' => $verifyDate], 'Tap stores in the order you will deliver on :date')); ?></p>
  <?php endif; ?>
  <p class="sub"><?php echo $esc(bakery_survey_text('survey.route_order_hint', [], 'Tap remaining stores one by one.')); ?></p>

  <form class="date-bar" method="get" action="survey.php">
    <input type="hidden" name="t" value="<?php echo $esc($token); ?>">
    <label><?php echo $esc(bakery_survey_text('survey.store_verify_date', [], 'Delivery day')); ?>
      <input type="date" name="date" value="<?php echo $esc($verifyDate); ?>">
    </label>
    <button type="submit" class="btn ghost"><?php echo $esc(bakery_survey_text('survey.store_verify_date_go', [], 'Show day')); ?></button>
  </form>

  <?php if ($isHq): ?>
  <div class="links-card">
    <div class="meta"><?php echo $esc(bakery_survey_text('survey.route_order_links', [], 'Share order links')); ?></div>
    <div class="row">
      <span><?php echo $esc(bakery_survey_text('survey.route_order_link_manager', [], 'Manager — all drivers')); ?></span>
      <button type="button" class="linkish" data-copy-url="<?php echo $esc($selfUrl); ?>"><?php echo $esc(bakery_survey_text('survey.store_verify_copy_link', [], 'Copy link')); ?></button>
    </div>
    <?php foreach ($hqGroups as $group):
        $gid = (int)$group['driver_id'];
        $dtok = $driverLinkTokens[$gid] ?? '';
        if ($dtok === '') {
            continue;
        }
        $durl = bakery_survey_link_url($dtok, $verifyDate);
        ?>
      <div class="row">
        <span><?php echo $esc((string)$group['driver_name']); ?></span>
        <button type="button" class="linkish" data-copy-url="<?php echo $esc($durl); ?>"><?php echo $esc(bakery_survey_text('survey.store_verify_copy_link', [], 'Copy link')); ?></button>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($isHq): ?>
    <?php foreach ($hqGroups as $gi => $group):
        $gid = (int)$group['driver_id'];
        $movableCount = count($group['movable'] ?? []);
        ?>
      <details class="driver-block" <?php echo $gi === 0 ? 'open' : ''; ?>>
        <summary>
          <span><?php echo $esc((string)$group['driver_name']); ?></span>
          <span class="meta"><?php echo (int)$movableCount; ?></span>
        </summary>
        <div class="body">
          <form method="post" action="survey.php?t=<?php echo $esc($token); ?>&amp;date=<?php echo $esc($verifyDate); ?>">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="order_route">
            <?php $renderBoard($group['locked'] ?? [], $group['movable'] ?? [], $gid); ?>
          </form>
        </div>
      </details>
    <?php endforeach; ?>
  <?php else: ?>
    <form method="post" action="survey.php?t=<?php echo $esc($token); ?>&amp;date=<?php echo $esc($verifyDate); ?>">
      <?php echo bakery_csrf_field(); ?>
      <input type="hidden" name="action" value="order_route">
      <?php $renderBoard($driverData['locked'], $driverData['movable'], (int)$driverData['driver_id']); ?>
    </form>
  <?php endif; ?>
</main>
<script>
(function () {
  var progressTpl = <?php echo json_encode(bakery_survey_text('survey.route_order_progress', ['done' => '__D__', 'total' => '__T__'], 'Ordered :done / :total'), JSON_UNESCAPED_UNICODE); ?>;
  function wireBoard(board) {
    if (!board) return;
    var remaining = board.querySelector('[data-ro-remaining]');
    var ordered = board.querySelector('[data-ro-ordered]');
    var hidden = board.querySelector('[data-ro-hidden]');
    var progress = board.querySelector('[data-ro-progress]');
    var undoBtn = board.querySelector('[data-ro-undo]');
    var resetBtn = board.querySelector('[data-ro-reset]');
    var saveBtn = board.querySelector('[data-ro-save]');
    var total = parseInt(board.getAttribute('data-total') || '0', 10) || 0;
    var stack = [];

    function sync() {
      if (hidden) {
        hidden.innerHTML = '';
        stack.forEach(function (item) {
          var input = document.createElement('input');
          input.type = 'hidden';
          input.name = 'order_ids[]';
          input.value = String(item.id);
          hidden.appendChild(input);
        });
      }
      if (ordered) {
        ordered.innerHTML = '';
        stack.forEach(function (item, idx) {
          var chip = document.createElement('span');
          chip.className = 'ro-chip';
          chip.textContent = (idx + 1) + '. ' + item.name;
          ordered.appendChild(chip);
        });
      }
      if (progress) {
        progress.textContent = progressTpl.replace('__D__', String(stack.length)).replace('__T__', String(total))
          .replace(':done', String(stack.length)).replace(':total', String(total));
      }
      if (undoBtn) undoBtn.disabled = stack.length === 0;
      if (resetBtn) resetBtn.disabled = stack.length === 0;
      if (saveBtn) saveBtn.disabled = !(total > 0 && stack.length === total);
    }

    board.querySelectorAll('[data-ro-store]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        if (btn.classList.contains('is-picked')) return;
        var id = parseInt(btn.getAttribute('data-order-id') || '0', 10);
        var name = btn.getAttribute('data-name') || '';
        if (!id) return;
        btn.classList.add('is-picked');
        stack.push({ id: id, name: name, el: btn });
        sync();
      });
    });
    if (undoBtn) {
      undoBtn.addEventListener('click', function () {
        var item = stack.pop();
        if (!item) return;
        if (item.el) item.el.classList.remove('is-picked');
        sync();
      });
    }
    if (resetBtn) {
      resetBtn.addEventListener('click', function () {
        while (stack.length) {
          var item = stack.pop();
          if (item && item.el) item.el.classList.remove('is-picked');
        }
        sync();
      });
    }
    sync();
  }
  document.querySelectorAll('[data-ro-board]').forEach(wireBoard);
  document.querySelectorAll('[data-copy-url]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var rel = btn.getAttribute('data-copy-url') || '';
      if (!rel) return;
      var url;
      try { url = new URL(rel, window.location.href).href; } catch (e) { return; }
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function () {
          var prev = btn.textContent;
          btn.textContent = '✓';
          setTimeout(function () { btn.textContent = prev; }, 1200);
        }).catch(function () { window.prompt('Copy', url); });
      } else {
        window.prompt('Copy', url);
      }
    });
  });
})();
</script>
</body>
</html>
<?php
exit;
