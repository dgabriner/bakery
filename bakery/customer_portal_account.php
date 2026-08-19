<?php
/**
 * Customer account & preferences — operational contact info and delivery instructions.
 */
define('ACCESS_ALLOWED', true);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/customer_account.php';

$customer = bakery_portal_require_customer($db);
$customerId = (int)$customer['id'];
$profile = bakery_customer_account_load($db, $customerId);

function bakery_account_time_input_value($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return substr($value, 0, 5);
}

$page_title = bakery_t('page.portal_account');
$currentLocale = bakery_locale();
$portalActivePage = 'account';
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
  <style>
    .page-intro { margin-bottom: 18px; color: var(--muted); font-size: .92rem; line-height: 1.5; }
    .account-section { margin-bottom: 16px; }
    .account-section > h2 { padding: 16px 16px 0; margin: 0 0 4px; font-size: 1.05rem; }
    .account-section .section-note {
      color: var(--muted);
      font-size: .82rem;
      line-height: 1.45;
      margin: 0 16px 12px;
    }
    .account-form { padding: 0 16px 16px; display: grid; gap: 12px; }
    .account-form label { display: grid; gap: 4px; font-size: .82rem; color: var(--muted); }
    .account-form input[type="text"],
    .account-form input[type="email"],
    .account-form input[type="tel"],
    .account-form input[type="time"],
    .account-form textarea {
      border: 1px solid var(--border);
      border-radius: 8px;
      font: inherit;
      font-size: .95rem;
      padding: 10px 12px;
      width: 100%;
      background: #fff;
      color: var(--ink);
    }
    .account-form textarea { min-height: 110px; resize: vertical; }
    .account-form .time-row { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
    .readonly-field {
      background: var(--sand);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 10px 12px;
      font-size: .95rem;
      line-height: 1.45;
      white-space: pre-wrap;
    }
    .readonly-field--empty { color: var(--muted); font-style: italic; }
    .request-panel {
      background: var(--sand);
      border: 1px dashed var(--border);
      border-radius: 10px;
      display: grid;
      gap: 10px;
      margin-top: 8px;
      padding: 12px;
    }
    .request-panel p { margin: 0; color: var(--muted); font-size: .82rem; line-height: 1.45; }
    .section-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding-top: 4px; }
    .section-status { color: var(--green); font-size: .82rem; min-height: 1.2em; }
    .section-status.is-error { color: #b42318; }
    .users-note { padding: 0 16px 16px; color: var(--muted); font-size: .88rem; line-height: 1.5; }
    @media (max-width: 520px) {
      .account-form .time-row { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php require __DIR__ . '/includes/portal_header.php'; ?>

  <main class="container container--wide">
    <p class="page-intro"><?php bakery_te('portal.account_intro'); ?></p>

    <section class="card account-section" id="section-business">
      <h2><?php bakery_te('portal.account_business_heading'); ?></h2>
      <p class="section-note"><?php bakery_te('portal.account_business_note'); ?></p>
      <div class="account-form">
        <label><?php bakery_te('portal.account_field_name'); ?>
          <div class="readonly-field"><?php echo htmlspecialchars($profile['name']); ?></div>
        </label>
        <label><?php bakery_te('portal.account_field_address'); ?>
          <div class="readonly-field<?php echo trim((string)$profile['address']) === '' ? ' readonly-field--empty' : ''; ?>">
            <?php echo trim((string)$profile['address']) !== ''
                ? htmlspecialchars($profile['address'])
                : bakery_t('portal.account_not_on_file'); ?>
          </div>
        </label>
        <label><?php bakery_te('portal.account_field_zone'); ?>
          <div class="readonly-field<?php echo trim((string)$profile['zone']) === '' ? ' readonly-field--empty' : ''; ?>">
            <?php echo trim((string)$profile['zone']) !== ''
                ? htmlspecialchars($profile['zone'])
                : bakery_t('portal.account_not_on_file'); ?>
          </div>
        </label>
        <label><?php bakery_te('portal.account_field_phone'); ?>
          <div class="readonly-field"><?php echo htmlspecialchars($profile['phone'] ?: bakery_t('portal.account_not_on_file')); ?></div>
        </label>
        <label><?php bakery_te('portal.account_field_email'); ?>
          <div class="readonly-field"><?php echo htmlspecialchars($profile['email'] ?: bakery_t('portal.account_not_on_file')); ?></div>
        </label>

        <div class="request-panel" data-request-field="address">
          <p><?php bakery_te('portal.account_address_request_help'); ?></p>
          <label><?php bakery_te('portal.account_new_address'); ?>
            <textarea name="requested_address" rows="3" maxlength="500" placeholder="<?php bakery_te('portal.account_new_address_ph'); ?>"></textarea>
          </label>
          <label><?php bakery_te('portal.account_request_note_optional'); ?>
            <input type="text" name="request_note" maxlength="255">
          </label>
          <div class="section-actions">
            <button type="button" class="btn btn-secondary js-request-change" data-field="address"><?php bakery_te('portal.account_request_change'); ?></button>
            <span class="section-status" aria-live="polite"></span>
          </div>
        </div>
      </div>
    </section>

    <section class="card account-section" id="section-delivery">
      <h2><?php bakery_te('portal.account_delivery_heading'); ?></h2>
      <p class="section-note"><?php bakery_te('portal.account_delivery_note'); ?></p>
      <form class="account-form js-account-form" data-section="delivery">
        <label><?php bakery_te('portal.account_field_delivery_instructions'); ?>
          <textarea name="delivery_instructions" maxlength="4000" placeholder="<?php bakery_te('portal.account_delivery_instructions_ph'); ?>"><?php echo htmlspecialchars((string)($profile['delivery_instructions'] ?? '')); ?></textarea>
        </label>
        <div class="time-row">
          <label><?php bakery_te('portal.account_field_deliver_after'); ?>
            <input type="time" name="deliver_after" value="<?php echo htmlspecialchars(bakery_account_time_input_value($profile['deliver_after'] ?? '')); ?>">
          </label>
          <label><?php bakery_te('portal.account_field_deliver_by'); ?>
            <input type="time" name="deliver_by" value="<?php echo htmlspecialchars(bakery_account_time_input_value($profile['deliver_by'] ?? '')); ?>">
          </label>
        </div>
        <label><?php bakery_te('portal.account_field_delivery_contact_name'); ?>
          <input type="text" name="delivery_contact_name" maxlength="100" value="<?php echo htmlspecialchars((string)($profile['delivery_contact_name'] ?? '')); ?>">
        </label>
        <label><?php bakery_te('portal.account_field_delivery_contact_phone'); ?>
          <input type="tel" name="delivery_contact_phone" maxlength="20" value="<?php echo htmlspecialchars((string)($profile['delivery_contact_phone'] ?? '')); ?>">
        </label>
        <div class="section-actions">
          <button type="submit" class="btn"><?php bakery_te('portal.account_save_delivery'); ?></button>
          <span class="section-status" aria-live="polite"></span>
        </div>
      </form>
    </section>

    <section class="card account-section" id="section-ordering">
      <h2><?php bakery_te('portal.account_ordering_heading'); ?></h2>
      <p class="section-note"><?php bakery_te('portal.account_ordering_note'); ?></p>
      <form class="account-form js-account-form" data-section="ordering">
        <label><?php bakery_te('portal.account_field_ordering_contact_name'); ?>
          <input type="text" name="ordering_contact_name" maxlength="100" value="<?php echo htmlspecialchars((string)($profile['ordering_contact_name'] ?? '')); ?>">
        </label>
        <label><?php bakery_te('portal.account_field_ordering_contact_phone'); ?>
          <input type="tel" name="ordering_contact_phone" maxlength="20" value="<?php echo htmlspecialchars((string)($profile['ordering_contact_phone'] ?? '')); ?>">
        </label>
        <label><?php bakery_te('portal.account_field_ordering_contact_email'); ?>
          <input type="email" name="ordering_contact_email" maxlength="100" value="<?php echo htmlspecialchars((string)($profile['ordering_contact_email'] ?? '')); ?>">
        </label>
        <div class="section-actions">
          <button type="submit" class="btn"><?php bakery_te('portal.account_save_ordering'); ?></button>
          <span class="section-status" aria-live="polite"></span>
        </div>
      </form>
    </section>

    <section class="card account-section" id="section-billing">
      <h2><?php bakery_te('portal.account_billing_heading'); ?></h2>
      <p class="section-note"><?php bakery_te('portal.account_billing_note'); ?></p>
      <form class="account-form js-account-form" data-section="billing">
        <label><?php bakery_te('portal.account_field_billing_contact_name'); ?>
          <input type="text" name="billing_contact_name" maxlength="100" value="<?php echo htmlspecialchars((string)($profile['billing_contact_name'] ?? '')); ?>">
        </label>
        <label><?php bakery_te('portal.account_field_billing_contact_email'); ?>
          <input type="email" name="billing_contact_email" maxlength="100" value="<?php echo htmlspecialchars((string)($profile['billing_contact_email'] ?? '')); ?>">
        </label>
        <label><?php bakery_te('portal.account_field_billing_contact_phone'); ?>
          <input type="tel" name="billing_contact_phone" maxlength="20" value="<?php echo htmlspecialchars((string)($profile['billing_contact_phone'] ?? '')); ?>">
        </label>
        <div class="section-actions">
          <button type="submit" class="btn"><?php bakery_te('portal.account_save_billing'); ?></button>
          <span class="section-status" aria-live="polite"></span>
        </div>
      </form>
    </section>

    <section class="card account-section" id="section-users">
      <h2><?php bakery_te('portal.account_users_heading'); ?></h2>
      <p class="users-note"><?php bakery_te('portal.account_users_note'); ?></p>
    </section>
  </main>

  <?php require __DIR__ . '/includes/portal_nav.php'; ?>

  <script>
    window.PORTAL_ACCOUNT_I18N = <?php echo json_encode([
        'saved' => bakery_t('portal.saved'),
        'noChanges' => bakery_t('portal.account_no_changes'),
        'saveFailed' => bakery_t('portal.save_failed'),
        'networkError' => bakery_t('portal.network_error'),
        'requestSubmitted' => bakery_t('portal.account_request_submitted'),
        'requestFailed' => bakery_t('portal.account_request_failed'),
    ], JSON_UNESCAPED_UNICODE); ?>;
  </script>
  <script src="<?php echo bakery_asset_href('includes/portal_account.js'); ?>" defer></script>
</body>
</html>
