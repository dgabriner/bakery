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
$purchaseHomeReady = bakery_sfb_purchase_home_ready($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        bakery_require_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'buy') {
            $offeringId = (int)($_POST['offering_id'] ?? 0);
            $result = bakery_sfb_buy_offering($db, $customerId, $offeringId);
            if ($result['configured'] && $result['url']) {
                header('Location: ' . $result['url']);
                exit;
            }
            if (!empty($result['error'])) {
                $_SESSION['sfb_purchase_flash'] = (string)$result['error'];
                header('Location: sfb_offerings.php?saved=failed&p=' . (int)$result['purchase_id']);
                exit;
            }
            header('Location: sfb_offerings.php?saved=intent&p=' . (int)$result['purchase_id']);
            exit;
        }
        if ($action === 'pay_credit') {
            $purchaseId = bakery_sfb_pay_with_credit($db, $customerId, (int)($_POST['offering_id'] ?? 0));
            header('Location: sfb_offerings.php?saved=credit&p=' . $purchaseId);
            exit;
        }
        if ($action === 'buy_private_workshop') {
            if (!$purchaseHomeReady) {
                throw new RuntimeException(bakery_t('sfb.purchase_home_unavailable'));
            }
            $result = bakery_sfb_buy_private_workshop($db, $customerId, $_POST);
            if (!empty($result['configured']) && !empty($result['url'])) {
                header('Location: ' . $result['url']);
                exit;
            }
            if (!empty($result['error'])) {
                $_SESSION['sfb_purchase_flash'] = (string)$result['error'];
                header('Location: sfb_offerings.php?saved=failed&p=' . (int)$result['purchase_id'] . '#private-workshop');
                exit;
            }
            header('Location: sfb_offerings.php?saved=intent&p=' . (int)$result['purchase_id'] . '#private-workshop');
            exit;
        }
        if ($action === 'buy_gift') {
            if (!$purchaseHomeReady) {
                throw new RuntimeException(bakery_t('sfb.purchase_home_unavailable'));
            }
            $result = bakery_sfb_buy_gift_certificate($db, $customerId, $_POST);
            if (!empty($result['configured']) && !empty($result['url'])) {
                header('Location: ' . $result['url']);
                exit;
            }
            if (!empty($result['error'])) {
                $_SESSION['sfb_purchase_flash'] = (string)$result['error'];
                header('Location: sfb_offerings.php?saved=failed&p=' . (int)$result['purchase_id'] . '#gift-certificate');
                exit;
            }
            header('Location: sfb_offerings.php?saved=intent&p=' . (int)$result['purchase_id'] . '#gift-certificate');
            exit;
        }
        if ($action === 'redeem_gift') {
            if (!$purchaseHomeReady) {
                throw new RuntimeException(bakery_t('sfb.purchase_home_unavailable'));
            }
            $purchaseId = bakery_sfb_redeem_gift_certificate($db, $customerId, (string)($_POST['gift_code'] ?? ''));
            header('Location: sfb_offerings.php?saved=gift_redeemed&p=' . $purchaseId . '#gift-certificate');
            exit;
        }
    } catch (Throwable $e) {
        $notice = $e->getMessage();
        $noticeKind = 'warn';
    }
}

$offerings = bakery_sfb_offerings($db);
$starterJarTitles = [
    bakery_sfb_starter_jar_offering_title('pickup'),
    bakery_sfb_starter_jar_offering_title('ship'),
    bakery_sfb_first_loaf_kit_offering_title(),
];
$giftTitle = bakery_sfb_gift_certificate_offering_title();
$paidOfferings = array_values(array_filter($offerings, static function (array $o) use ($starterJarTitles, $giftTitle): bool {
    $kind = (string)($o['kind'] ?? '');
    $title = (string)($o['title'] ?? '');
    if ($kind === 'donation' || $kind === 'gift') {
        return false;
    }
    if (in_array($title, $starterJarTitles, true) || $title === $giftTitle) {
        return false;
    }
    return true;
}));
$donations = array_values(array_filter($offerings, static function (array $o): bool {
    return ($o['kind'] ?? '') === 'donation';
}));
$purchases = bakery_sfb_customer_purchases($db, $customerId);
$creditBalance = bakery_sfb_credit_balance($db, $customerId);
$myGifts = $purchaseHomeReady ? bakery_sfb_gift_certificates_for_buyer($db, $customerId) : [];
$giftOffering = $purchaseHomeReady ? bakery_sfb_gift_certificate_offering($db) : null;
$unlockMap = [];
foreach ($offerings as $unlockOffering) {
    $unlockMap[(int)$unlockOffering['id']] = bakery_sfb_courses_requiring($db, (int)$unlockOffering['id']);
}

$wsForm = [
    'workshop_type' => (string)($_POST['workshop_type'] ?? 'starter'),
    'headcount' => (int)($_POST['headcount'] ?? 4),
    'bites' => !empty($_POST['bites']),
    'drinks' => !empty($_POST['drinks']),
    'contact_name' => (string)($_POST['contact_name'] ?? $customer['name'] ?? ''),
    'preferred_date' => (string)($_POST['preferred_date'] ?? ''),
    'notes' => (string)($_POST['notes'] ?? ''),
];
if ($wsForm['headcount'] < 1) {
    $wsForm['headcount'] = 4;
}
try {
    $wsQuote = bakery_sfb_private_workshop_quote($wsForm);
} catch (Throwable $e) {
    $wsQuote = ['price_cents' => 0];
}

$saved = (string)($_GET['saved'] ?? '');
$savedMessages = [
    'intent' => bakery_t('sfb.pay_intent_saved'),
    'credit' => bakery_t('sfb.credit_applied_saved'),
    'gift_redeemed' => bakery_t('sfb.gift_redeemed_saved'),
];

$purchaseFlash = isset($_SESSION['sfb_purchase_flash']) ? trim((string)$_SESSION['sfb_purchase_flash']) : '';
unset($_SESSION['sfb_purchase_flash']);
if ($saved === 'failed') {
    $noticeKind = 'warn';
    $notice = bakery_t('sfb.pay_failed_notice', ['reason' => $purchaseFlash !== '' ? $purchaseFlash : '-']);
}

$purchasedId = (int)($_GET['purchased'] ?? 0);
$issuedGift = null;
if ($purchasedId > 0 && $purchaseHomeReady) {
    $giftStmt = $db->prepare('SELECT * FROM sfb_gift_certificates WHERE purchase_id = ? LIMIT 1');
    $giftStmt->execute([$purchasedId]);
    $issuedGift = $giftStmt->fetch() ?: null;
}

$page_title = bakery_t('sfb.offerings_title');
$currentLocale = bakery_locale();
$portalActivePage = 'sfb_purchase';
$portalCustomerName = $customer['name'];
$starterJarReady = bakery_sfb_starter_jar_ready($db);
$kitReady = bakery_sfb_first_loaf_kit_ready($db);

function bakery_sfb_render_offering_card(array $offering, int $creditBalance, array $unlockedCourses = []): void {
    $kind = (string)$offering['kind'];
    ?>
    <section class="card" id="<?php echo htmlspecialchars('offering-' . $offering['id'], ENT_QUOTES, 'UTF-8'); ?>">
      <div class="card-body">
        <div style="display:flex;justify-content:space-between;gap:10px;align-items:baseline;">
          <h2 style="margin:0;"><?php echo htmlspecialchars($offering['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
          <strong>$<?php echo number_format((float)$offering['price_cents'] / 100, 2); ?></strong>
        </div>
        <span class="badge badge-info"><?php bakery_te('sfb.offering_kind_' . $kind); ?></span>
        <?php if ($kind === 'credits' && (int)($offering['units'] ?? 0) > 0): ?>
          <span class="badge badge-ok"><?php echo bakery_t('sfb.credit_pack_units', ['units' => (string)(int)$offering['units']]); ?></span>
        <?php endif; ?>
        <?php if (!empty($offering['description'])): ?>
          <p><?php echo nl2br(htmlspecialchars($offering['description'], ENT_QUOTES, 'UTF-8')); ?></p>
        <?php endif; ?>
        <form method="post" style="margin-top:8px;">
          <?php echo bakery_csrf_field(); ?>
          <input type="hidden" name="action" value="buy">
          <input type="hidden" name="offering_id" value="<?php echo (int)$offering['id']; ?>">
          <button type="submit" class="btn btn-block"><?php bakery_te('sfb.offerings_buy'); ?> — $<?php echo number_format((float)$offering['price_cents'] / 100, 2); ?></button>
        </form>
        <?php if ($creditBalance > 0 && !in_array($kind, ['credits', 'donation', 'gift'], true)): ?>
          <form method="post" style="margin-top:8px;">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="pay_credit">
            <input type="hidden" name="offering_id" value="<?php echo (int)$offering['id']; ?>">
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.credit_use_one'); ?></button>
          </form>
        <?php endif; ?>
        <?php if ($unlockedCourses): ?>
          <p class="muted" style="margin:10px 0 0;"><strong><?php bakery_te('sfb.offerings_unlocks_label'); ?>:</strong>
            <?php echo htmlspecialchars(implode(', ', array_column($unlockedCourses, 'title')), ENT_QUOTES, 'UTF-8'); ?></p>
        <?php elseif ($kind === 'class'): ?>
          <p class="muted" style="margin:10px 0 0;"><?php bakery_te('sfb.offerings_no_courses_hint'); ?></p>
        <?php endif; ?>
      </div>
    </section>
    <?php
}
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
    <?php $sfbActiveTab = 'purchase'; require __DIR__ . '/includes/sfb_tabs.php'; ?>

    <?php if ($notice !== ''): ?>
      <div class="notice notice--<?php echo $noticeKind === 'warn' ? 'warn' : 'info'; ?>"><?php echo htmlspecialchars($notice); ?></div>
    <?php elseif (isset($savedMessages[$saved])): ?>
      <div class="notice notice--info"><?php echo htmlspecialchars($savedMessages[$saved]); ?></div>
    <?php endif; ?>

    <section class="card hero-card">
      <div class="card-body">
        <p class="hero-label"><?php bakery_te('sfb.offerings_eyebrow'); ?></p>
        <h2 class="hero-date"><?php bakery_te('sfb.offerings_hero_title'); ?></h2>
        <p class="muted"><?php bakery_te('sfb.offerings_hero_copy'); ?></p>
        <?php if ($creditBalance > 0): ?>
          <p style="margin-bottom:0;"><span class="badge badge-ok"><?php
            echo bakery_t('sfb.credit_balance_chip', ['units' => (string)$creditBalance]);
          ?></span></p>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($starterJarReady): ?>
      <section class="card" id="starter-jar">
        <div class="card-body">
          <p class="hero-label"><?php bakery_te('sfb.starter_jar_dash_eyebrow'); ?></p>
          <h2 style="margin-top:0;"><?php bakery_te('sfb.purchase_home_starter_title'); ?></h2>
          <p class="muted"><?php bakery_te('sfb.purchase_home_starter_copy'); ?></p>
          <a class="btn btn-block" href="starter.php"><?php bakery_te('sfb.starter_jar_dash_cta'); ?></a>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($kitReady): ?>
      <section class="card" id="first-loaf-kit">
        <div class="card-body">
          <p class="hero-label"><?php bakery_te('sfb.starter_jar_kit_price'); ?></p>
          <h2 style="margin-top:0;"><?php bakery_te('sfb.purchase_home_kit_title'); ?></h2>
          <p class="muted"><?php bakery_te('sfb.purchase_home_kit_copy'); ?></p>
          <a class="btn btn-block" href="starter.php?kit=1"><?php bakery_te('sfb.purchase_home_kit_cta'); ?></a>
        </div>
      </section>
    <?php endif; ?>

    <section class="card" id="gear-list">
      <div class="card-body">
        <h2 style="margin-top:0;"><?php bakery_te('sfb.purchase_home_gear_title'); ?></h2>
        <p class="muted"><?php bakery_te('sfb.purchase_home_gear_copy'); ?></p>
        <a class="btn btn-secondary btn-block" href="https://bakery.sourflour.org/breadeducation/start/first-loaf-shopping.html" target="_blank" rel="noopener"><?php bakery_te('sfb.purchase_home_gear_cta'); ?></a>
      </div>
    </section>

    <?php if ($purchaseHomeReady): ?>
      <section class="card" id="private-workshop">
        <div class="card-body">
          <h2 style="margin-top:0;"><?php bakery_te('sfb.private_ws_title'); ?></h2>
          <p class="muted"><?php bakery_te('sfb.private_ws_copy'); ?></p>
          <form method="post">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="buy_private_workshop">
            <fieldset style="border:0;margin:0;padding:0;">
              <legend class="muted" style="padding:0;"><?php bakery_te('sfb.private_ws_type_label'); ?></legend>
              <label style="display:block;margin:6px 0;">
                <input type="radio" name="workshop_type" value="starter"<?php echo $wsForm['workshop_type'] !== 'pizza' ? ' checked' : ''; ?>>
                <?php bakery_te('sfb.private_ws_type_starter'); ?>
              </label>
              <label style="display:block;margin:6px 0;">
                <input type="radio" name="workshop_type" value="pizza"<?php echo $wsForm['workshop_type'] === 'pizza' ? ' checked' : ''; ?>>
                <?php bakery_te('sfb.private_ws_type_pizza'); ?>
              </label>
            </fieldset>
            <label style="display:block;margin:12px 0;">
              <span><?php bakery_te('sfb.private_ws_headcount'); ?></span>
              <input type="number" name="headcount" min="1" max="40" value="<?php echo (int)$wsForm['headcount']; ?>" required style="display:block;width:100%;margin-top:4px;padding:10px;">
            </label>
            <label style="display:block;margin:8px 0;">
              <input type="checkbox" name="bites" value="1"<?php echo $wsForm['bites'] ? ' checked' : ''; ?>>
              <?php bakery_te('sfb.private_ws_bites'); ?>
            </label>
            <label style="display:block;margin:8px 0;">
              <input type="checkbox" name="drinks" value="1"<?php echo $wsForm['drinks'] ? ' checked' : ''; ?>>
              <?php bakery_te('sfb.private_ws_drinks'); ?>
            </label>
            <label style="display:block;margin:12px 0;">
              <span><?php bakery_te('sfb.private_ws_contact'); ?></span>
              <input type="text" name="contact_name" maxlength="120" required value="<?php echo htmlspecialchars($wsForm['contact_name'], ENT_QUOTES, 'UTF-8'); ?>" style="display:block;width:100%;margin-top:4px;padding:10px;">
            </label>
            <label style="display:block;margin:12px 0;">
              <span><?php bakery_te('sfb.private_ws_date'); ?></span>
              <input type="text" name="preferred_date" maxlength="40" value="<?php echo htmlspecialchars($wsForm['preferred_date'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="<?php bakery_te('sfb.private_ws_date_ph'); ?>" style="display:block;width:100%;margin-top:4px;padding:10px;">
            </label>
            <label style="display:block;margin:12px 0;">
              <span><?php bakery_te('sfb.private_ws_notes'); ?></span>
              <textarea name="notes" maxlength="255" rows="2" style="display:block;width:100%;margin-top:4px;padding:10px;"><?php echo htmlspecialchars($wsForm['notes'], ENT_QUOTES, 'UTF-8'); ?></textarea>
            </label>
            <p><strong><?php bakery_te('sfb.private_ws_total'); ?>:
              $<?php echo number_format(((int)($wsQuote['price_cents'] ?? 0)) / 100, 2); ?></strong></p>
            <button type="submit" class="btn btn-block"><?php bakery_te('sfb.private_ws_cta'); ?></button>
          </form>
        </div>
      </section>

      <section class="card" id="gift-certificate">
        <div class="card-body">
          <h2 style="margin-top:0;"><?php bakery_te('sfb.gift_title'); ?></h2>
          <p class="muted"><?php bakery_te('sfb.gift_copy'); ?></p>
          <?php if ($issuedGift && in_array((string)$issuedGift['status'], ['available', 'pending'], true)): ?>
            <p class="notice notice--info"><?php
              echo htmlspecialchars(bakery_t('sfb.gift_code_ready', [
                  'code' => (string)$issuedGift['code'],
                  'status' => bakery_t('sfb.gift_status_' . (string)$issuedGift['status']),
              ]), ENT_QUOTES, 'UTF-8');
            ?></p>
          <?php endif; ?>
          <?php if ($giftOffering): ?>
            <form method="post" style="margin-bottom:16px;">
              <?php echo bakery_csrf_field(); ?>
              <input type="hidden" name="action" value="buy_gift">
              <label style="display:block;margin:0 0 10px;">
                <span><?php bakery_te('sfb.gift_recipient'); ?></span>
                <input type="text" name="recipient_name" maxlength="120" value="<?php echo htmlspecialchars((string)($_POST['recipient_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" style="display:block;width:100%;margin-top:4px;padding:10px;">
              </label>
              <button type="submit" class="btn btn-block"><?php
                echo bakery_t('sfb.gift_buy_cta', [
                    'price' => number_format((float)$giftOffering['price_cents'] / 100, 2),
                ]);
              ?></button>
            </form>
          <?php endif; ?>
          <form method="post">
            <?php echo bakery_csrf_field(); ?>
            <input type="hidden" name="action" value="redeem_gift">
            <label style="display:block;margin:0 0 10px;">
              <span><?php bakery_te('sfb.gift_redeem_label'); ?></span>
              <input type="text" name="gift_code" maxlength="24" required placeholder="SFG-XXXXXXXX" style="display:block;width:100%;margin-top:4px;padding:10px;text-transform:uppercase;">
            </label>
            <button type="submit" class="btn btn-secondary btn-block"><?php bakery_te('sfb.gift_redeem_cta'); ?></button>
          </form>
          <?php if ($myGifts): ?>
            <p style="margin:16px 0 6px;"><strong><?php bakery_te('sfb.gift_my_codes'); ?></strong></p>
            <ul class="line-list">
              <?php foreach ($myGifts as $giftRow): ?>
                <li>
                  <span>
                    <code><?php echo htmlspecialchars((string)$giftRow['code'], ENT_QUOTES, 'UTF-8'); ?></code>
                    <?php if (!empty($giftRow['recipient_name'])): ?>
                      · <?php echo htmlspecialchars((string)$giftRow['recipient_name'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php endif; ?>
                  </span>
                  <span class="badge badge-info"><?php bakery_te('sfb.gift_status_' . (string)$giftRow['status']); ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </section>
    <?php elseif (!$purchaseHomeReady): ?>
      <section class="card">
        <div class="card-body">
          <p class="muted" style="margin:0;"><?php bakery_te('sfb.purchase_home_unavailable'); ?></p>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($paidOfferings): ?>
      <?php foreach ($paidOfferings as $offering) { bakery_sfb_render_offering_card($offering, $creditBalance, $unlockMap[(int)$offering['id']] ?? []); } ?>
    <?php elseif (!$offerings && !$starterJarReady && !$purchaseHomeReady): ?>
      <section class="card"><div class="card-body"><p class="muted"><?php bakery_te('sfb.offerings_none'); ?></p></div></section>
    <?php endif; ?>

    <?php if ($donations): ?>
      <section class="card" id="donate">
        <div class="card-header"><h2><?php bakery_te('sfb.donate_title'); ?></h2></div>
        <div class="card-body">
          <p class="muted" style="margin-top:0;"><?php bakery_te('sfb.donate_copy'); ?></p>
          <?php foreach ($donations as $offering) { bakery_sfb_render_offering_card($offering, $creditBalance); } ?>
        </div>
      </section>
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
                  <?php if (($purchase['paid_with'] ?? '') === 'credit'): ?>
                    <span class="badge badge-info"><?php bakery_te('sfb.paid_with_credit'); ?></span>
                  <?php elseif (($purchase['paid_with'] ?? '') === 'gift'): ?>
                    <span class="badge badge-info"><?php bakery_te('sfb.paid_with_gift'); ?></span>
                  <?php endif; ?>
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
