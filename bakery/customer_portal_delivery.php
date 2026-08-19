<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/portal_command_center.php';
require_once __DIR__ . '/includes/customer_delivery.php';
require_once __DIR__ . '/includes/customer_delivery_issues.php';

$customer = bakery_portal_require_customer($db);
$customerId = (int)$customer['id'];

$date = trim((string)($_GET['date'] ?? ''));
$dailyOrderId = (int)($_GET['id'] ?? 0);
$reorderFrom = (int)($_GET['reorder_from'] ?? 0);
$error = '';

if ($dailyOrderId > 0 && $date === '') {
    try {
        $owned = bakery_customer_delivery_assert_ownership($db, $customerId, $dailyOrderId);
        $date = (string)$owned['order_date'];
    } catch (Throwable $e) {
        $error = bakery_t('delivery.not_found');
    }
}

if ($date === '' && $reorderFrom > 0) {
    $next = bakery_portal_cmd_next_delivery($db, $customerId);
    if ($next) {
        $date = $next['date'];
    }
}

try {
    if ($date === '') {
        throw new InvalidArgumentException(bakery_t('portal.invalid_delivery_date'));
    }
    $context = bakery_portal_cmd_assert_delivery_date($db, $customerId, $date);
} catch (Throwable $e) {
    $error = $e->getMessage();
    $context = null;
}

$card = $context ? bakery_portal_cmd_build_delivery_card($db, $customerId, $date, $context) : null;
$canEdit = $card ? !empty($card['can_edit']) : false;
$photos = ($card && !empty($card['daily_order_id']))
    ? bakery_portal_cmd_delivery_photos($db, $customerId, (int)$card['daily_order_id'])
    : [];

$proofDetail = null;
$deliveryIssues = [];
if ($card && !empty($card['daily_order_id']) && !$canEdit) {
    try {
        $proofDetail = bakery_customer_delivery_detail($db, $customerId, (int)$card['daily_order_id']);
        if (($card['status']['key'] ?? '') === 'delivered') {
            $deliveryIssues = bakery_delivery_issues_for_delivery($db, $customerId, (int)$card['daily_order_id']);
        }
    } catch (Throwable $e) {
        $proofDetail = null;
    }
}

$reorderSource = null;
$reorderPreview = null;
if ($reorderFrom > 0 && $card) {
    $reorderSource = bakery_portal_cmd_load_owned_daily_order($db, $customerId, $reorderFrom);
    if ($reorderSource) {
        $itemsStmt = $db->prepare(
            'SELECT doi.product_id, doi.quantity, p.name AS product_name
             FROM daily_order_items doi
             JOIN products p ON p.id = doi.product_id
             WHERE doi.daily_order_id = ? AND doi.quantity > 0
             ORDER BY p.name'
        );
        $itemsStmt->execute([$reorderFrom]);
        $reorderPreview = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

function bakery_portal_status_badge_class($tone) {
    $map = [
        'ok' => 'badge-ok',
        'info' => 'badge-info',
        'warn' => 'badge-warn',
        'muted' => 'badge-muted',
        'danger' => 'badge-danger',
    ];
    return $map[$tone] ?? 'badge-muted';
}

$page_title = bakery_t('page.portal_delivery');
$currentLocale = bakery_locale();
$portalActivePage = 'delivery';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container">
    <?php if ($error): ?>
      <p class="empty-state"><?php echo htmlspecialchars($error); ?></p>
      <a class="btn btn-secondary" href="customer_portal.php"><?php bakery_te('portal.back_home'); ?></a>
    <?php else: ?>
      <p><a href="customer_portal_calendar.php" class="delivery-card-summary"><?php bakery_te('portal.back_calendar'); ?></a></p>

      <?php if ($reorderPreview): ?>
        <div class="reorder-banner">
          <strong><?php bakery_te('portal.reorder_preview_title'); ?></strong>
          <p class="delivery-card-summary">
            <?php echo htmlspecialchars(bakery_t('portal.reorder_preview_body', [
                'source_date' => date('M j, Y', strtotime($reorderSource['order_date'])),
                'target_date' => $card['date_label'],
            ])); ?>
          </p>
          <ul class="line-list">
            <?php foreach ($reorderPreview as $line): ?>
              <li>
                <span><?php echo htmlspecialchars($line['product_name']); ?></span>
                <span class="line-qty"><?php echo (int)$line['quantity']; ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <p class="delivery-card-summary"><?php bakery_te('portal.reorder_note'); ?></p>
          <div class="btn-row">
            <button type="button" class="btn" id="applyReorderBtn"
                    data-source="<?php echo (int)$reorderFrom; ?>"
                    data-date="<?php echo htmlspecialchars($date); ?>">
              <?php bakery_te('portal.apply_reorder'); ?>
            </button>
            <a class="btn btn-secondary" href="customer_portal_delivery.php?date=<?php echo urlencode($date); ?>">
              <?php bakery_te('portal.cancel_reorder'); ?>
            </a>
          </div>
        </div>
      <?php endif; ?>

      <section class="card hero-card">
        <div class="card-body">
          <p class="hero-label"><?php bakery_te('portal.this_delivery'); ?></p>
          <h1 class="hero-date"><?php echo htmlspecialchars($card['date_label']); ?></h1>
          <div class="meta-row">
            <span class="badge <?php echo bakery_portal_status_badge_class($card['status']['tone']); ?>">
              <?php echo htmlspecialchars($card['status']['label']); ?>
            </span>
            <span><?php echo htmlspecialchars($card['schedule_note']); ?></span>
            <?php if ($canEdit): ?>
              <span><?php bakery_te('portal.changes_allowed'); ?></span>
            <?php else: ?>
              <span><?php bakery_te('portal.changes_locked'); ?></span>
            <?php endif; ?>
          </div>
          <?php if (!empty($card['status_message'])): ?>
            <p class="delivery-card-summary"><?php echo htmlspecialchars($card['status_message']); ?></p>
          <?php endif; ?>
          <?php if (!empty($card['progress'])): ?>
            <p class="delivery-progress"><?php echo htmlspecialchars($card['progress']); ?></p>
          <?php endif; ?>
          <?php if ($proofDetail && !empty($proofDetail['delivered_time_label'])): ?>
            <p class="delivery-card-summary">
              <strong><?php bakery_te('delivery.delivered_at'); ?></strong>
              <?php echo htmlspecialchars($proofDetail['delivered_time_label']); ?>
            </p>
          <?php endif; ?>
        </div>
      </section>

      <section class="card">
        <div class="card-header"><h2><?php bakery_te('portal.delivery_items'); ?></h2></div>
        <?php if ($proofDetail && !empty($proofDetail['has_quantity_variance'])): ?>
          <div class="notice notice--warn" style="margin:12px 16px 0"><?php bakery_te('delivery.variance_notice'); ?></div>
        <?php endif; ?>
        <?php if (!$card['lines'] && empty($proofDetail['items'])): ?>
          <div class="empty-day"><?php bakery_te('portal.no_items_scheduled'); ?></div>
        <?php elseif ($proofDetail && !empty($proofDetail['items']) && !$canEdit): ?>
          <table class="items-table" style="width:100%;border-collapse:collapse;font-size:.92rem;margin:0 16px 12px">
            <thead>
              <tr>
                <th style="text-align:left;padding:8px 4px;color:var(--muted);font-size:.75rem"><?php bakery_te('delivery.product'); ?></th>
                <th style="text-align:right;padding:8px 4px;color:var(--muted);font-size:.75rem"><?php bakery_te('delivery.ordered'); ?></th>
                <th style="text-align:right;padding:8px 4px;color:var(--muted);font-size:.75rem"><?php bakery_te('delivery.delivered_qty'); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($proofDetail['items'] as $item): ?>
                <tr>
                  <td style="padding:8px 4px;border-top:1px solid var(--border)">
                    <?php echo htmlspecialchars($item['product_name']); ?>
                    <?php if (!empty($item['has_variance'])): ?>
                      <span style="background:#fde8e8;color:#9b332c;border-radius:6px;font-size:.78rem;font-weight:600;margin-left:6px;padding:2px 6px">
                        <?php echo ($item['variance'] > 0 ? '+' : '') . (int)$item['variance']; ?>
                      </span>
                    <?php endif; ?>
                  </td>
                  <td style="padding:8px 4px;border-top:1px solid var(--border);text-align:right"><?php echo (int)$item['ordered']; ?></td>
                  <td style="padding:8px 4px;border-top:1px solid var(--border);text-align:right">
                    <?php echo $item['delivered'] !== null ? (int)$item['delivered'] : '—'; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <?php foreach ($card['lines'] as $line): ?>
            <div class="order-row"
                 data-product-id="<?php echo (int)$line['product_id']; ?>"
                 data-editable="<?php echo $canEdit ? '1' : '0'; ?>">
              <span class="product-name"><?php echo htmlspecialchars($line['product_name']); ?></span>
              <?php if ($canEdit): ?>
                <div class="qty-controls">
                  <button type="button" class="qty-btn" data-delta="-1" aria-label="<?php bakery_te('portal.decrease'); ?>">−</button>
                  <span class="qty-value"><?php echo (int)$line['quantity']; ?></span>
                  <button type="button" class="qty-btn" data-delta="1" aria-label="<?php bakery_te('portal.increase'); ?>">+</button>
                </div>
              <?php else: ?>
                <span class="line-qty">
                  <?php
                    $ordered = (int)$line['quantity'];
                    $delivered = $line['delivered_quantity'];
                    echo $delivered !== null ? (int)$delivered . ' / ' . $ordered : (string)$ordered;
                  ?>
                </span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <?php if ($proofDetail && ($card['status']['key'] ?? '') === 'delivered'): ?>
        <section class="card" id="proof">
          <div class="card-header"><h2><?php bakery_te('portal.proof_of_delivery'); ?></h2></div>
          <div class="card-body">
            <?php if (!empty($proofDetail['driver_name'])): ?>
              <p class="delivery-card-summary"><strong><?php bakery_te('delivery.driver'); ?>:</strong> <?php echo htmlspecialchars($proofDetail['driver_name']); ?></p>
            <?php endif; ?>
            <?php if (!empty($proofDetail['location_summary'])): ?>
              <p class="delivery-card-summary"><strong><?php bakery_te('delivery.location'); ?>:</strong> <?php echo htmlspecialchars($proofDetail['location_summary']); ?></p>
            <?php endif; ?>
            <?php if (!empty($proofDetail['invoice_number'])): ?>
              <p class="delivery-card-summary"><strong><?php bakery_te('delivery.invoice'); ?>:</strong> <?php echo htmlspecialchars($proofDetail['invoice_number']); ?></p>
            <?php endif; ?>
            <?php if ($photos): ?>
              <div class="photo-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));margin-top:12px">
                <?php foreach ($photos as $photo): ?>
                  <a href="<?php echo htmlspecialchars($photo['url']); ?>" target="_blank" rel="noopener">
                    <img src="<?php echo htmlspecialchars($photo['url']); ?>" alt="<?php echo htmlspecialchars($photo['photo_type']); ?>" style="width:100%;height:120px;object-fit:cover;border-radius:8px;border:1px solid var(--border)">
                  </a>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p class="muted"><?php bakery_te('delivery.no_photo'); ?></p>
            <?php endif; ?>
          </div>
        </section>
      <?php elseif (!empty($card['invoice_number']) && !empty($card['delivery_confirmed_at'])): ?>
        <section class="card">
          <div class="card-body">
            <p class="delivery-card-summary">
              <?php echo htmlspecialchars(bakery_t('portal.invoice_ref', ['number' => $card['invoice_number']])); ?>
            </p>
          </div>
        </section>
      <?php endif; ?>

      <?php if ($proofDetail && ($card['status']['key'] ?? '') === 'delivered' && !empty($card['daily_order_id'])): ?>
        <section class="card" id="report-issue">
          <div class="card-header"><h2><?php bakery_te('issue.report_heading'); ?></h2></div>
          <div class="card-body">
            <?php if ($deliveryIssues): ?>
              <div class="issue-list" style="margin-bottom:16px">
                <p class="delivery-card-summary"><strong><?php bakery_te('issue.your_reports'); ?></strong></p>
                <?php foreach ($deliveryIssues as $issue): ?>
                  <a href="customer_portal_issue.php?id=<?php echo (int)$issue['id']; ?>" class="issue-row" style="display:block;padding:10px 0;border-top:1px solid var(--border);text-decoration:none;color:inherit">
                    <span class="badge badge-<?php echo htmlspecialchars($issue['status_tone']); ?>"><?php echo htmlspecialchars($issue['status_label']); ?></span>
                    <span><?php echo htmlspecialchars($issue['category_label']); ?></span>
                    <?php if ($issue['product_name']): ?>
                      <span class="muted"> — <?php echo htmlspecialchars($issue['product_name']); ?></span>
                    <?php endif; ?>
                    <span class="muted" style="float:right;font-size:.85rem"><?php echo htmlspecialchars($issue['created_at_label']); ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <form id="issueForm" class="issue-form">
              <label class="form-label"><?php bakery_te('issue.category_label'); ?></label>
              <select name="category" id="issueCategory" required class="form-input" style="width:100%;margin-bottom:12px;padding:8px">
                <?php foreach (bakery_delivery_issue_categories() as $key => $meta): ?>
                  <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars(bakery_t($meta['label_key'])); ?></option>
                <?php endforeach; ?>
              </select>

              <div id="issueProductFields" style="display:none">
                <label class="form-label"><?php bakery_te('issue.product_label'); ?></label>
                <select name="product_id" id="issueProduct" class="form-input" style="width:100%;margin-bottom:12px;padding:8px">
                  <option value=""><?php bakery_te('issue.select_product'); ?></option>
                  <?php if ($proofDetail && !empty($proofDetail['items'])): ?>
                    <?php foreach ($proofDetail['items'] as $item): ?>
                      <option value="<?php echo htmlspecialchars((string)($item['product_id'] ?? '')); ?>"
                              data-ordered="<?php echo (int)$item['ordered']; ?>"
                              data-delivered="<?php echo $item['delivered'] !== null ? (int)$item['delivered'] : ''; ?>">
                        <?php echo htmlspecialchars($item['product_name']); ?>
                        (<?php bakery_te('delivery.ordered'); ?>: <?php echo (int)$item['ordered']; ?>,
                         <?php bakery_te('delivery.delivered_qty'); ?>: <?php echo $item['delivered'] !== null ? (int)$item['delivered'] : '—'; ?>)
                      </option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
                <p id="issueQtyFacts" class="delivery-card-summary muted" style="display:none"></p>
                <label class="form-label"><?php bakery_te('issue.received_qty_label'); ?></label>
                <input type="number" name="customer_reported_quantity" id="issueReportedQty" min="0" step="1" class="form-input" style="width:100%;margin-bottom:12px;padding:8px">
              </div>

              <label class="form-label"><?php bakery_te('issue.description_label'); ?></label>
              <textarea name="description" id="issueDescription" required maxlength="2000" rows="3" class="form-input" style="width:100%;margin-bottom:12px;padding:8px" placeholder="<?php bakery_te('issue.description_placeholder'); ?>"></textarea>

              <label style="display:flex;align-items:center;gap:8px;margin-bottom:12px;font-size:.92rem">
                <input type="checkbox" name="credit_requested" id="issueCreditRequested" value="1">
                <?php bakery_te('issue.credit_requested_label'); ?>
              </label>

              <button type="submit" class="btn" id="issueSubmitBtn"><?php bakery_te('issue.submit'); ?></button>
            </form>
          </div>
        </section>
      <?php endif; ?>

      <div class="btn-row">
        <a class="btn btn-secondary" href="customer_portal_regular.php"><?php bakery_te('portal.view_regular_order'); ?></a>
        <a class="btn btn-secondary" href="customer_portal_history.php"><?php bakery_te('portal.view_past_deliveries'); ?></a>
      </div>
    <?php endif; ?>
  </main>

  <div class="toast" id="toast" role="status"></div>

  <script>
    window.__BAKERY_I18N__ = <?php echo json_encode([
        'saved' => bakery_t('portal.saved'),
        'network_error' => bakery_t('portal.network_error'),
        'save_failed' => bakery_t('portal.save_failed'),
        'reorder_applied' => bakery_t('portal.reorder_applied'),
        'reorder_failed' => bakery_t('portal.reorder_failed'),
        'issue_submitted' => bakery_t('issue.confirm_submitted_title'),
        'issue_failed' => bakery_t('issue.submit_failed'),
    ], JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script>
    (function () {
      var i18n = window.__BAKERY_I18N__ || {};
      var toast = document.getElementById('toast');
      var toastTimer;
      var deliveryDate = <?php echo json_encode($date); ?>;

      function showToast(msg, isError) {
        if (!toast) return;
        toast.textContent = msg;
        toast.className = 'toast' + (isError ? ' error' : '');
        toast.style.display = 'block';
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toast.style.display = 'none'; }, 2400);
      }

      function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
      }

      function postAction(action, extra) {
        var body = new URLSearchParams({ action: action, csrf_token: csrfToken() });
        if (extra) {
          Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });
        }
        return fetch('customer_portal_api.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
          body: body.toString()
        }).then(function (r) { return r.json(); });
      }

      document.querySelectorAll('.order-row[data-editable="1"]').forEach(function (row) {
        var productId = row.getAttribute('data-product-id');
        var valueEl = row.querySelector('.qty-value');
        row.querySelectorAll('.qty-btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            var delta = parseInt(btn.getAttribute('data-delta'), 10);
            var qty = Math.max(0, parseInt(valueEl.textContent, 10) + delta);
            valueEl.textContent = qty;
            postAction('save_daily_item', {
              date: deliveryDate,
              product_id: productId,
              quantity: qty
            }).then(function (res) {
              if (res.success) showToast(i18n.saved);
              else { showToast(res.error || i18n.save_failed, true); location.reload(); }
            }).catch(function () {
              showToast(i18n.network_error, true);
              location.reload();
            });
          });
        });
      });

      var reorderBtn = document.getElementById('applyReorderBtn');
      if (reorderBtn) {
        reorderBtn.addEventListener('click', function () {
          reorderBtn.disabled = true;
          postAction('apply_reorder', {
            source_order_id: reorderBtn.getAttribute('data-source'),
            target_date: reorderBtn.getAttribute('data-date')
          }).then(function (res) {
            if (res.success) {
              showToast(i18n.reorder_applied);
              window.location.href = 'customer_portal_delivery.php?date=' + encodeURIComponent(deliveryDate);
            } else {
              showToast(res.error || i18n.reorder_failed, true);
              reorderBtn.disabled = false;
            }
          }).catch(function () {
            showToast(i18n.network_error, true);
            reorderBtn.disabled = false;
          });
        });
      }

      var issueForm = document.getElementById('issueForm');
      if (issueForm) {
        var categoryEl = document.getElementById('issueCategory');
        var productFields = document.getElementById('issueProductFields');
        var productEl = document.getElementById('issueProduct');
        var qtyFacts = document.getElementById('issueQtyFacts');
        var needsProduct = { missing_quantity:1, wrong_product:1, damaged:1, quality:1 };

        function syncIssueProductFields() {
          var cat = categoryEl.value;
          var show = !!needsProduct[cat];
          productFields.style.display = show ? 'block' : 'none';
          productEl.required = show;
        }

        function syncQtyFacts() {
          var opt = productEl.options[productEl.selectedIndex];
          if (!opt || !opt.value) {
            qtyFacts.style.display = 'none';
            return;
          }
          var ordered = opt.getAttribute('data-ordered');
          var delivered = opt.getAttribute('data-delivered');
          qtyFacts.textContent = 'Ordered: ' + ordered + ' · Driver recorded: ' + (delivered !== '' ? delivered : '—');
          qtyFacts.style.display = 'block';
        }

        categoryEl.addEventListener('change', syncIssueProductFields);
        productEl.addEventListener('change', syncQtyFacts);
        syncIssueProductFields();

        issueForm.addEventListener('submit', function (e) {
          e.preventDefault();
          var btn = document.getElementById('issueSubmitBtn');
          btn.disabled = true;
          var payload = {
            daily_order_id: <?php echo (int)($card['daily_order_id'] ?? 0); ?>,
            category: categoryEl.value,
            description: document.getElementById('issueDescription').value,
          };
          if (productFields.style.display !== 'none' && productEl.value) {
            payload.product_id = productEl.value;
            var rq = document.getElementById('issueReportedQty').value;
            if (rq !== '') payload.customer_reported_quantity = rq;
          }
          if (document.getElementById('issueCreditRequested').checked) {
            payload.credit_requested = '1';
          }
          postAction('submit_issue', payload).then(function (res) {
            if (res.success) {
              showToast(i18n.issue_submitted);
              window.location.reload();
            } else {
              showToast(res.error || i18n.issue_failed, true);
              btn.disabled = false;
            }
          }).catch(function () {
            showToast(i18n.network_error, true);
            btn.disabled = false;
          });
        });
      }
    })();
  </script>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
