<?php
/**
 * Public front door for the Community Bread Education Center.
 * Three honest doors: preview courses, join in a minute, or sign back in.
 * No POST here — account creation happens on the existing phone-PIN flow.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/customer_portal.php';
require_once __DIR__ . '/includes/sf_baker.php';

if (bakery_portal_customer_id() > 0) {
    header('Location: ' . BASE_URL . 'sfb_dashboard.php');
    exit;
}

$invite = bakery_sfb_invite_lookup($db, (string)($_GET['invite'] ?? ''));

$courses = [];
if (bakery_sfb_learning_ready($db)) {
    $courses = array_slice(bakery_sfb_courses($db), 0, 6);
}

$offeringsById = [];
foreach (bakery_sfb_offerings($db) as $offeringRow) {
    $offeringsById[(int)$offeringRow['id']] = $offeringRow;
}

$joinUrl = BASE_URL . 'customer_login.php?create=1';
$signInUrl = BASE_URL . 'customer_login.php';
if ($invite) {
    $joinUrl .= '&invite=' . rawurlencode((string)$invite['code']);
    $signInUrl .= '&invite=' . rawurlencode((string)$invite['code']);
}

$page_title = bakery_t('sfb.join_page_title');
$currentLocale = bakery_locale();
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLocale, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title><?php echo htmlspecialchars($page_title); ?></title>
  <style>
    :root { color-scheme: light; --ink: #1c2a26; --cream: #fffaf2; --terracotta: #c7783a; --muted: #6b7d78; --line: #e8ddcf; }
    * { box-sizing: border-box; }
    body { background: var(--cream); color: var(--ink); font-family: Georgia, 'Times New Roman', serif; margin: 0; padding: 32px 20px 48px; }
    .wrap { margin: 0 auto; max-width: 560px; }
    .logo { display: block; height: auto; margin: 0 auto 24px; max-width: 200px; mix-blend-mode: multiply; width: 50vw; }
    h1 { font-size: 1.5rem; font-weight: normal; text-align: center; margin: 0 0 8px; }
    .lede { color: var(--muted); font-size: .95rem; line-height: 1.5; margin: 0 auto 28px; max-width: 440px; text-align: center; }
    .card { background: #fff; border: 1px solid var(--line); border-radius: 12px; margin: 0 0 14px; overflow: hidden; }
    .card-body { padding: 18px; }
    .eyebrow { color: var(--terracotta); font-size: .78rem; letter-spacing: .08em; text-transform: uppercase; margin: 0 0 6px; }
    h2 { font-size: 1.08rem; font-weight: normal; margin: 0 0 8px; }
    p { line-height: 1.5; }
    ul { list-style: none; margin: 10px 0 0; padding: 0; }
    li { border-top: 1px solid var(--line); display: flex; justify-content: space-between; gap: 10px; padding: 9px 2px; }
    li:first-child { border-top: 0; }
    button, a.btn { background: var(--terracotta); border: 0; border-radius: 8px; color: #fff; cursor: pointer; display: block; font: inherit; padding: 12px 16px; text-align: center; text-decoration: none; width: 100%; }
    a.quiet { background: transparent; border: 1px solid var(--line); color: var(--ink); }
    .staff { color: var(--muted); display: block; font-size: .85rem; margin-top: 26px; text-align: center; }
    .staff a { color: var(--terracotta); }
    .invite-chip { background: #f3ead9; border-radius: 999px; color: var(--ink); display: block; font-size: .88rem; margin: 0 auto 22px; max-width: 420px; padding: 9px 16px; text-align: center; }
    .muted { color: var(--muted); }
    .chip { background: #f3ead9; border-radius: 999px; color: var(--muted); display: block; font-size: .72rem; letter-spacing: .02em; margin-top: 4px; max-width: fit-content; padding: 2px 9px; }
    small.mono { font-family: monospace; letter-spacing: .06em; }
  </style>
</head>
<body>
  <div class="wrap">
    <?php echo bakery_sour_flour_logo_img('logo'); ?>
    <h1><?php bakery_te('sfb.join_heading'); ?></h1>
    <p class="lede"><?php bakery_te('sfb.join_lede'); ?></p>

    <?php if ($invite): ?>
      <p class="invite-chip"><?php
        echo htmlspecialchars(bakery_t('sfb.join_invited', ['label' => (string)($invite['label'] !== null && $invite['label'] !== '' ? $invite['label'] : $invite['code'])]));
      ?></p>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <p class="eyebrow"><?php bakery_te('sfb.join_door_preview'); ?></p>
        <h2><?php bakery_te('sfb.join_preview_title'); ?></h2>
        <?php if (!$courses): ?>
          <p class="muted"><?php bakery_te('sfb.join_preview_empty'); ?></p>
        <?php else: ?>
          <ul>
            <?php foreach ($courses as $course): ?>
              <?php
              $gatedOffering = !empty($course['required_offering_id']) ? ($offeringsById[(int)$course['required_offering_id']] ?? null) : null;
              if ($gatedOffering) {
                  $chipText = bakery_t('sfb.course_included_with', ['label' => (string)$gatedOffering['title']]);
                  if ((int)($gatedOffering['price_cents'] ?? 0) > 0) {
                      $chipText .= ' · $' . number_format(((int)$gatedOffering['price_cents']) / 100, 2);
                  }
              } else {
                  $chipText = bakery_t('sfb.course_free_label');
              }
              ?>
              <li>
                <span>
                  <?php echo htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8'); ?>
                  <span class="chip"><?php echo htmlspecialchars($chipText, ENT_QUOTES, 'UTF-8'); ?></span>
                </span>
                <span class="muted"><?php echo (int)$course['lesson_count']; ?> <?php bakery_te('sfb.lessons'); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <p class="eyebrow"><?php bakery_te('sfb.join_door_workshops'); ?></p>
        <h2><?php bakery_te('sfb.join_workshops_title'); ?></h2>
        <?php
        $workshopTeaser = null;
        $publicOfferings = bakery_sfb_payments_ready($db) ? array_slice(bakery_sfb_offerings($db), 0, 3) : [];
        foreach ($publicOfferings as $po) {
            if (($po['kind'] ?? '') === 'class') { $workshopTeaser = $po; break; }
        }
        ?>
        <?php if ($workshopTeaser): ?>
          <ul>
            <li>
              <span><?php echo htmlspecialchars($workshopTeaser['title'], ENT_QUOTES, 'UTF-8'); ?></span>
              <span class="muted">$<?php echo number_format((float)$workshopTeaser['price_cents'] / 100, 2); ?></span>
            </li>
          </ul>
        <?php endif; ?>
        <p class="muted"><?php bakery_te('sfb.join_workshops_copy'); ?></p>
        <a class="btn" href="<?php echo htmlspecialchars($joinUrl . '&next=' . rawurlencode('/sfb_offerings.php'), ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.join_cta_workshops'); ?></a>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <p class="eyebrow"><?php bakery_te('sfb.join_door_join'); ?></p>
        <h2><?php bakery_te('sfb.join_join_title'); ?></h2>
        <p class="muted"><?php bakery_te('sfb.join_join_copy'); ?></p>
        <a class="btn" href="<?php echo htmlspecialchars($joinUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.join_cta_create'); ?></a>
      </div>
    </div>

    <div class="card">
      <div class="card-body">
        <p class="eyebrow"><?php bakery_te('sfb.join_door_return'); ?></p>
        <h2><?php bakery_te('sfb.join_return_title'); ?></h2>
        <a class="btn quiet" href="<?php echo htmlspecialchars($signInUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php bakery_te('sfb.join_cta_signin'); ?></a>
      </div>
    </div>

    <a class="staff" href="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>login.php"><?php bakery_te('portal.staff_link'); ?></a>
  </div>
</body>
</html>
