<?php
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/sf_baker.php';

$customer = bakery_sfb_require_access($db);
$customerId = (int)$customer['id'];

if (!bakery_sfb_payments_ready($db)) {
    http_response_code(503);
    exit('Class payments need the latest database migration before they can be opened.');
}

$notice = '';
$noticeKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'buy') {
    try {
        bakery_require_csrf();
        $result = bakery_sfb_buy_offering($db, $customerId, (int)($_POST['offering_id'] ?? 0));
        if ($result['configured'] && $result['url']) {
            header('Location: ' . $result['url']);
            exit;
        }
        // Recorded intent without a Square session — honest notice on return.
        header('Location: sfb_offerings.php?saved=intent&p=' . (int)$result['purchase_id']);
        exit;
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$offerings = bakery_sfb_offerings($db);
$purchases = bakery_sfb_customer_purchases($db, $customerId);
$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'intent' => bakery_t('sfb.pay_intent_saved'),
];

$page_title = bakery_t('sfb.offerings_title');
$currentLocale = bakery_locale();
$portalActivePage = 'sfb';
$portalCustomerName = $customer['name'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
  <?php require __DIR__ . '/includes/portal_styles.php'; ?>
  <?php require __DIR__ . '/includes/sfb_styles.php'; ?>
</head>
<body class="sfb-body">
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container sfb-app">
    <?php $sfbActiveTab = 'resources'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php elseif (isset($savedMessages[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
    <?php endif; ?>

    <section class="card hero-card">
      <div class="card-body">
        <p class="hero-label"><?php bakery_te('sfb.learn_title'); ?></p>
        <h2 class="hero-date"><?php bakery_te('sfb.offerings_title'); ?></h2>
        <p class="muted"><?php bakery_te('sfb.offerings_intro'); ?></p>
      </div>
    </section>

    <?php if (!$offerings): ?>
      <section class="card"><div class="card-body"><p class="muted"><?php bakery_te('sfb.offerings_none'); ?></p></div></section>
    <?php else: ?>
      <?php foreach ($offerings as $offering): ?>
        <section class="card">
          <div class="card-body">
            <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;">
              <h2 style="margin:0;"><?php echo htmlspecialchars($offering['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
              <strong>$<?php echo number_format((float)$offering['price_cents'] / 100, 2); ?></strong>
            </div>
            <span class="badge badge-info"><?php
              bakery_te('sfb.offering_kind_' . (string)$offering['kind']);
            ?></span>
            <?php if (!empty($offering['description'])): ?>
              <p><?php echo nl2br(htmlspecialchars($offering['description'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>
            <form method="post" style="margin-top:8px;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="buy">
              <input type="hidden" name="offering_id" value="<?php echo (int)$offering['id']; ?>">
              <button type="submit" class="btn btn-block"><?php bakery_te('sfb.offerings_buy'); ?> — $<?php echo number_format((float)$offering['price_cents'] / 100, 2); ?></button>
            </form>
          </div>
        </section>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($purchases): ?>
      <section class="card">
        <div class="card-header"><h2><?php bakery_te('sfb.my_purchases'); ?></h2></div>
        <div class="card-body">
          <ul class="line-list">
            <?php foreach ($purchases as $purchase): ?>
              <li>
                <span>
                  <?php echo htmlspecialchars($purchase['offering_title_snapshot'], ENT_QUOTES, 'UTF-8'); ?>
                  · $<?php echo number_format((float)$purchase['price_cents_snapshot'] / 100, 2); ?>
                  <br><small class="muted"><?php echo htmlspecialchars(date('M j, g:ia', strtotime($purchase['created_at'])), ENT_QUOTES, 'UTF-8'); ?></small>
                </span>
                <span class="line-qty">
                  <span class="badge <?php
                    echo $purchase['status'] === 'paid' ? 'badge-ok' : (($purchase['status'] === 'pending' || $purchase['status'] === 'intent') ? 'badge-info' : 'badge-muted');
                  ?>"><?php bakery_te('sfb.purchase_status_' . (string)$purchase['status']); ?></span>
                  <?php if (!empty($purchase['checkout_url']) && in_array($purchase['status'], ['pending', 'failed'], true)): ?>
                    <a class="btn-link" href="<?php echo htmlspecialchars($purchase['checkout_url'], ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.purchase_resume'); ?></a>
                  <?php endif; ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </section>
    <?php endif; ?>

    <a class="btn btn-secondary btn-block" href="sfb_resources.php"><?php bakery_te('sfb.resources_back_to_center'); ?></a>
  </main>
  <?php require __DIR__ . '/includes/portal_nav.php'; ?>
</body>
</html>
