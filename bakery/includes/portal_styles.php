<?php
/** Shared portal styles — mobile-first customer shell. */
if (!defined('ACCESS_ALLOWED')) {
    die('Direct access not permitted');
}
?>
<meta name="app-base-url" content="<?php echo htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'); ?>">
<?php require __DIR__ . '/client_refresh.php'; ?>
<?php require_once __DIR__ . '/google_analytics.php'; ?>
<meta name="csrf-token" content="<?php echo htmlspecialchars(bakery_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
<meta name="login-audit-session" content="<?php echo (int)bakery_login_audit_current_id(); ?>">
<script>
(function () {
  function removeLegacyLocationPrompt() {
    document.querySelectorAll('[data-login-location-choice]').forEach(function (node) { node.remove(); });
  }
  removeLegacyLocationPrompt();
  document.addEventListener('DOMContentLoaded', removeLegacyLocationPrompt);
  if (typeof MutationObserver === 'function') {
    document.addEventListener('DOMContentLoaded', function () {
      if (document.body) {
        new MutationObserver(removeLegacyLocationPrompt).observe(document.body, { childList: true, subtree: true });
      }
    });
  }
  window.setTimeout(removeLegacyLocationPrompt, 900);
  window.setTimeout(removeLegacyLocationPrompt, 1500);
}());
</script>
<script src="<?php echo bakery_asset_href('includes/csrf.js'); ?>"></script>
<script defer src="<?php echo bakery_asset_href('includes/login_audit.js'); ?>"></script>
<style>
  :root {
    --ink: var(--sf-portal-ink, #33251f);
    --cream: var(--sf-portal-cream, #fffdf8);
    --terracotta: var(--sf-portal-terracotta, #b75c3f);
    --muted: var(--sf-portal-muted, #7a6a5c);
    --border: var(--sf-portal-border, #e8ddd2);
    --green: var(--sf-portal-green, #2d6a4f);
    --amber: var(--sf-portal-amber, #b8860b);
    --sand: var(--sf-portal-sand, #faf6f1);
    --nav-h: 64px;
    --top-h: 52px;
    --safe-b: env(safe-area-inset-bottom, 0px);
    --safe-t: env(safe-area-inset-top, 0px);
  }
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  html { -webkit-text-size-adjust: 100%; }
  body {
    background: var(--cream);
    color: var(--ink);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    margin: 0;
    min-height: 100vh;
    min-height: 100dvh;
    padding-bottom: calc(var(--nav-h) + var(--safe-b) + 12px);
  }

  /* ── Top bar ── */
  .portal-top {
    align-items: center;
    background: #fff;
    border-bottom: 1px solid var(--border);
    display: flex;
    gap: 10px;
    min-height: var(--top-h);
    padding: calc(8px + var(--safe-t)) 16px 8px;
    position: sticky;
    top: 0;
    z-index: 20;
  }
  .portal-top__brand {
    align-items: center;
    display: flex;
    flex-shrink: 0;
    line-height: 0;
    text-decoration: none;
  }
  .portal-top__brand-logo {
    display: block;
    height: auto;
    max-height: 32px;
    max-width: 118px;
    mix-blend-mode: multiply;
    width: auto;
  }
  .portal-top__name {
    flex: 1;
    font-family: Georgia, serif;
    font-size: .92rem;
    font-weight: normal;
    margin: 0;
    min-width: 0;
    overflow: hidden;
    text-align: center;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .portal-top__more {
    background: none;
    border: 1px solid var(--border);
    border-radius: 999px;
    color: var(--ink);
    cursor: pointer;
    flex-shrink: 0;
    font-size: .82rem;
    line-height: 1;
    min-height: var(--sf-touch-min, 44px);
    min-width: var(--sf-touch-min, 44px);
    padding: 8px 12px;
  }
  .portal-top__more[aria-expanded="true"] {
    background: var(--sand);
    border-color: var(--terracotta);
    color: var(--terracotta);
  }

  /* ── Bottom tab bar ── */
  .portal-tabs {
    align-items: stretch;
    background: #fff;
    border-top: 1px solid var(--border);
    bottom: 0;
    box-shadow: 0 -4px 20px rgba(51, 37, 31, .06);
    display: flex;
    left: 0;
    padding-bottom: var(--safe-b);
    position: fixed;
    right: 0;
    z-index: 30;
  }
  .portal-tabs a {
    align-items: center;
    color: var(--muted);
    display: flex;
    flex: 1;
    flex-direction: column;
    font-size: .68rem;
    font-weight: 500;
    gap: 3px;
    justify-content: center;
    letter-spacing: .01em;
    min-height: var(--nav-h);
    padding: 6px 4px 8px;
    text-decoration: none;
    -webkit-user-select: none;
    user-select: none;
  }
  .portal-tabs a.active { color: var(--terracotta); font-weight: 600; }
  .portal-tabs a svg { flex-shrink: 0; height: 22px; opacity: .75; width: 22px; }
  .portal-tabs a.active svg { opacity: 1; stroke: var(--terracotta); }

  /* ── More sheet ── */
  .portal-sheet-backdrop {
    background: rgba(51, 37, 31, .4);
    inset: 0;
    opacity: 0;
    pointer-events: none;
    position: fixed;
    transition: opacity .2s;
    z-index: 40;
  }
  .portal-sheet-backdrop.open { opacity: 1; pointer-events: auto; }
  .portal-sheet {
    background: #fff;
    border-radius: 16px 16px 0 0;
    bottom: 0;
    left: 0;
    max-height: 70vh;
    overflow: auto;
    padding: 12px 16px calc(16px + var(--safe-b));
    position: fixed;
    right: 0;
    transform: translateY(100%);
    transition: transform .25s ease;
    z-index: 50;
  }
  .portal-sheet.open { transform: translateY(0); }
  .portal-sheet__handle {
    background: var(--border);
    border-radius: 999px;
    height: 4px;
    margin: 0 auto 16px;
    width: 36px;
  }
  .portal-sheet__title {
    color: var(--muted);
    font-size: .72rem;
    letter-spacing: .06em;
    margin: 0 0 8px;
    text-transform: uppercase;
  }
  .portal-sheet__link {
    align-items: center;
    border-bottom: 1px solid var(--border);
    color: var(--ink);
    display: flex;
    font-size: 1rem;
    gap: 12px;
    min-height: 52px;
    padding: 12px 4px;
    text-decoration: none;
  }
  .portal-sheet__link:last-child { border-bottom: 0; }
  .portal-sheet__link svg { flex-shrink: 0; height: 20px; opacity: .6; width: 20px; }
  .portal-sheet__link--danger { color: #9b332c; }
  .portal-sheet__lang { justify-content: space-between; padding: 10px 4px; }
  .bakery-lang-switch--portal { background: var(--sand); border-radius: 999px; display: inline-flex; gap: 2px; padding: 2px; }
  .bakery-lang-switch--portal .bakery-lang-switch__btn { border-radius: 999px; color: var(--muted); font-size: .78rem; padding: 5px 10px; text-decoration: none; }
  .bakery-lang-switch--portal .bakery-lang-switch__btn--active { background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,.12); color: var(--ink); font-weight: 600; }

  /* ── Layout ── */
  .container { margin: 0 auto; max-width: 640px; padding: 16px; }
  .container--wide { max-width: 960px; }

  /* ── Typography ── */
  .section-title { font-family: Georgia, serif; font-size: 1.15rem; font-weight: normal; margin: 0 0 12px; }
  .page-intro { color: var(--muted); font-size: .92rem; line-height: 1.45; margin: 0 0 16px; }
  .muted { color: var(--muted); font-size: .88rem; line-height: 1.45; margin: 0; }

  /* ── Cards ── */
  .card, .day-section, .delivery-card {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 14px;
    margin-bottom: 14px;
    overflow: hidden;
  }
  .card { padding: 0; }
  .card-body { padding: 16px; }
  .card-header { border-bottom: 1px solid var(--border); padding: 14px 16px; }
  .card-header h2, .card h2 { font-family: Georgia, serif; font-size: 1rem; font-weight: normal; margin: 0; }
  .card > h2 { padding: 16px 16px 0; }
  .day-section { padding: 0; }

  /* ── Hero (next delivery) ── */
  .hero-card { border-color: #d4c4b8; }
  .hero-card .card-body { padding: 20px 18px; }
  .hero-label { color: var(--muted); font-size: .78rem; letter-spacing: .06em; margin: 0 0 6px; text-transform: uppercase; }
  .hero-date { font-family: Georgia, serif; font-size: 1.65rem; font-weight: normal; line-height: 1.2; margin: 0 0 14px; }

  /* ── Delivery cards ── */
  .delivery-card { padding: 16px; }
  .delivery-card-top { align-items: flex-start; display: flex; gap: 12px; justify-content: space-between; margin-bottom: 12px; }
  .delivery-card-date { font-family: Georgia, serif; font-size: 1.05rem; font-weight: normal; margin: 0 0 4px; }
  .delivery-card-summary { color: var(--muted); font-size: .88rem; line-height: 1.4; margin: 0; }
  .delivery-card-actions { margin-top: 12px; }
  .delivery-progress { color: var(--green); font-size: .9rem; font-weight: 600; margin: 8px 0 0; }

  /* ── Line lists ── */
  .line-list { list-style: none; margin: 0 0 14px; padding: 0; }
  .line-list li { align-items: center; border-bottom: 1px solid var(--border); display: flex; font-size: .95rem; justify-content: space-between; padding: 10px 0; }
  .line-list li:last-child { border-bottom: 0; }
  .line-qty { color: var(--terracotta); font-size: 1.05rem; font-variant-numeric: tabular-nums; font-weight: 600; min-width: 32px; text-align: right; }

  /* ── Meta / badges ── */
  .meta-row { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
  .badge { border-radius: 999px; display: inline-block; font-size: .75rem; font-weight: 600; letter-spacing: .02em; padding: 4px 10px; white-space: nowrap; }
  .badge-ok, .badge--active { background: #e8f5ee; color: var(--green); }
  .badge-info { background: #eef2ff; color: #3730a3; }
  .badge-warn, .badge--locked { background: #fff3cd; color: var(--amber); }
  .badge-muted, .badge--skipped { background: #eee; color: var(--muted); }
  .badge-danger, .badge--paused { background: #fde8e8; color: #9b332c; }

  /* ── Buttons ── */
  .btn {
    background: var(--terracotta);
    border: 0;
    border-radius: 10px;
    color: #fff;
    cursor: pointer;
    display: inline-block;
    font-size: .92rem;
    font-weight: 600;
    min-height: 48px;
    padding: 12px 20px;
    text-align: center;
    text-decoration: none;
    touch-action: manipulation;
    -webkit-user-select: none;
    user-select: none;
  }
  .btn-secondary { background: #fff; border: 1px solid var(--border); color: var(--ink); }
  .btn-link { background: none; border: 0; color: var(--terracotta); cursor: pointer; font-size: .88rem; padding: 0; text-decoration: underline; }
  .btn:disabled { opacity: .55; pointer-events: none; }
  .btn-row, .actions-row { display: flex; flex-direction: column; gap: 10px; margin-top: 14px; }
  .btn-block { display: block; text-align: center; width: 100%; }

  /* ── Notices ── */
  .notice { border-radius: 10px; font-size: .92rem; line-height: 1.45; margin-bottom: 16px; padding: 14px 16px; }
  .notice--info { background: #f0f7ff; border: 1px solid #c5daf5; }
  .notice--warn { background: #fff8e6; border: 1px solid #f0d88a; }
  .notice--locked { background: #fde8e8; border: 1px solid #f0b8b8; }
  .empty-state { color: var(--muted); font-size: .95rem; line-height: 1.45; padding: 24px 0; text-align: center; }

  /* ── Order editing rows ── */
  .order-row, .comparison-row {
    align-items: center;
    border-bottom: 1px solid var(--border);
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: space-between;
    min-height: 56px;
    padding: 12px 16px;
  }
  .order-row:last-child, .comparison-row:last-child { border-bottom: 0; }
  .product-name { flex: 1 1 140px; font-size: .95rem; line-height: 1.3; min-width: 0; }
  .qty-controls { align-items: center; display: flex; flex-shrink: 0; gap: 6px; }
  .qty-btn {
    background: var(--sand);
    border: 1px solid var(--border);
    border-radius: 8px;
    color: var(--ink);
    cursor: pointer;
    flex-shrink: 0;
    font-size: 1.2rem;
    height: 44px;
    line-height: 1;
    touch-action: manipulation;
    width: 44px;
  }
  .qty-input {
    -moz-appearance: textfield;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 1.05rem;
    height: 44px;
    text-align: center;
    width: 56px;
  }
  .qty-input:focus { border-color: var(--terracotta); outline: none; }
  .qty-input::-webkit-outer-spin-button, .qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
  .qty-value { font-size: 1.1rem; min-width: 28px; text-align: center; }
  .qty-remove { background: none; border: 0; color: var(--muted); cursor: pointer; flex-shrink: 0; font-size: 1.35rem; height: 44px; line-height: 1; min-width: 44px; padding: 0; }
  .qty-remove:hover { color: #9b332c; }
  .order-row.qty-saving { opacity: .65; pointer-events: none; }
  .empty-day { color: var(--muted); font-size: .88rem; padding: 16px; }
  .day-header { background: var(--sand); border-bottom: 1px solid var(--border); font-family: Georgia, serif; font-size: 1rem; padding: 12px 16px; }

  /* ── Forms ── */
  .add-row { border-top: 1px solid var(--border); padding: 12px 16px; }
  .add-product-select, .inline-form input, .inline-form textarea, .inline-form select {
    border: 1px solid var(--border);
    border-radius: 10px;
    font-size: 1rem;
    min-height: 48px;
    padding: 10px 12px;
    width: 100%;
  }
  .inline-form { display: grid; gap: 10px; grid-template-columns: 1fr; margin-top: 10px; }
  .inline-form label span { color: var(--muted); display: block; font-size: .78rem; margin-bottom: 4px; }

  /* ── Pause controls ── */
  .pause-row { align-items: center; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; margin-bottom: 10px; }
  .pause-label { font-size: .92rem; }
  .pause-list { list-style: none; margin: 12px 0 0; padding: 0; }
  .pause-list li { align-items: center; display: flex; flex-wrap: wrap; font-size: .9rem; gap: 10px; justify-content: space-between; min-height: 48px; padding: 8px 0; }

  /* ── Attention list ── */
  .attention-list { list-style: none; margin: 0; padding: 0; }
  .attention-list li { border-bottom: 1px solid var(--border); font-size: .92rem; line-height: 1.4; padding: 12px 0; }
  .attention-list li:last-child { border-bottom: 0; }
  .attention-list a { color: var(--terracotta); font-weight: 500; }
  .attention-list .level-warn a, .attention-list .level-warn { color: #9b332c; }

  /* ── Delivery list items ── */
  .delivery-list { display: grid; gap: 10px; }
  .delivery-item {
    align-items: center;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    color: inherit;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: space-between;
    min-height: 56px;
    padding: 14px 16px;
    text-decoration: none;
  }
  .delivery-item:active { background: var(--sand); }
  .delivery-item__date { font-family: Georgia, serif; font-size: 1rem; }
  .delivery-item__meta { color: var(--muted); font-size: .85rem; }

  /* ── Comparison table ── */
  .comparison-labels { color: var(--muted); display: none; font-size: .75rem; gap: 4px; grid-template-columns: 1fr auto auto auto; padding: 0 16px 8px; text-transform: uppercase; }
  .comparison-row { display: flex; }
  .comparison-row .col-regular, .comparison-row .col-delivery, .comparison-row .col-diff { font-size: .95rem; min-width: 48px; text-align: center; }
  .diff { color: var(--terracotta); font-size: .85rem; min-width: 48px; text-align: right; }
  .diff--zero { color: var(--muted); }

  /* ── Confirm / toast ── */
  .confirm-panel { align-items: flex-end; background: rgba(0,0,0,.35); inset: 0; justify-content: center; padding: 20px; position: fixed; z-index: 60; }
  .confirm-panel[hidden] { display: none !important; }
  .confirm-panel:not([hidden]) { display: flex; }
  .confirm-panel__inner { background: #fff; border-radius: 16px 16px 0 0; max-width: 480px; padding: 20px; position: relative; width: 100%; z-index: 1; }
  .confirm-panel__inner h3 { font-family: Georgia, serif; font-size: 1.05rem; margin: 0 0 10px; }
  .confirm-panel__inner ul { margin: 0 0 10px; padding-left: 18px; }
  .toast {
    background: var(--green);
    border-radius: 10px;
    bottom: calc(var(--nav-h) + var(--safe-b) + 12px);
    color: #fff;
    display: none;
    font-size: .92rem;
    left: 50%;
    max-width: calc(100% - 32px);
    padding: 12px 18px;
    position: fixed;
    transform: translateX(-50%);
    z-index: 70;
  }
  .toast.error { background: #9b332c; }
  .request-form textarea { min-height: 100px; resize: vertical; }

  /* ── Billing helpers ── */
  .filter-row { align-items: end; display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
  .filter-row label { display: flex; flex-direction: column; font-size: .78rem; gap: 4px; width: 100%; }
  .filter-row input { border: 1px solid var(--border); border-radius: 10px; font-size: 1rem; min-height: 48px; padding: 10px 12px; width: 100%; }
  .preset-links { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
  .preset-links a { border: 1px solid var(--border); border-radius: 999px; color: var(--ink); font-size: .85rem; min-height: var(--sf-touch-min, 44px); padding: 8px 14px; text-decoration: none; }
  .preset-links a.active { background: var(--sand); border-color: var(--terracotta); color: var(--terracotta); font-weight: 600; }
  .metrics { display: grid; gap: 10px; grid-template-columns: 1fr 1fr; margin-bottom: 14px; }
  .metric { background: var(--sand); border-radius: 10px; padding: 14px; }
  .metric strong { display: block; font-size: 1.2rem; }
  .metric span { color: var(--muted); font-size: .78rem; }
  .table-scroll { -webkit-overflow-scrolling: touch; margin: 0 -16px; overflow-x: auto; padding: 0 16px; }
  table { border-collapse: collapse; font-size: .88rem; min-width: 480px; width: 100%; }
  th, td { border-bottom: 1px solid var(--border); padding: 12px 8px; text-align: left; }
  th { color: var(--muted); font-size: .72rem; letter-spacing: .04em; text-transform: uppercase; }
  .num { font-variant-numeric: tabular-nums; text-align: right; }
  .download-grid { display: grid; gap: 10px; }
  .download-item { background: var(--sand); border-radius: 10px; padding: 14px; }
  .download-item p { color: var(--muted); font-size: .82rem; margin: 6px 0 10px; }

  /* ── Tablet / desktop ── */
  @media (min-width: 640px) {
    .container { padding: 20px 24px; }
    .btn-row, .actions-row { flex-direction: row; flex-wrap: wrap; }
    .btn-row .btn, .actions-row .btn { flex: 0 0 auto; width: auto; }
    .inline-form { grid-template-columns: 1fr 1fr auto; }
    .filter-row { flex-direction: row; flex-wrap: wrap; }
    .filter-row label { width: auto; }
    .metrics { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
    .download-grid { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .comparison-labels { display: grid; }
    .comparison-row { display: grid; grid-template-columns: 1fr auto auto auto; }
    .confirm-panel { align-items: center; }
    .confirm-panel__inner { border-radius: 16px; }
    .portal-tabs a { font-size: .72rem; }
  }

  @media (min-width: 768px) {
    body { padding-bottom: 0; }
    .portal-tabs {
      border-top: 0;
      box-shadow: none;
      margin: 0 auto;
      max-width: 640px;
      position: static;
      padding-bottom: 0;
    }
    .portal-tabs a { min-height: 56px; }
    .portal-top__more { display: none; }
    .portal-sheet-backdrop, .portal-sheet { display: none !important; }
    .portal-more-desktop {
      border-top: 1px solid var(--border);
      display: flex !important;
      flex-wrap: wrap;
      gap: 8px;
      justify-content: center;
      margin: 0 auto;
      max-width: 640px;
      padding: 10px 16px 16px;
    }
    .portal-more-desktop a {
      border: 1px solid var(--border);
      border-radius: 999px;
      color: var(--ink);
      font-size: .85rem;
      padding: 8px 14px;
      text-decoration: none;
    }
    .portal-more-desktop a.active { background: var(--sand); border-color: var(--terracotta); color: var(--terracotta); }
  }

  .portal-more-desktop { display: none; }

  /* ── Notifications ── */
  .portal-top__notify {
    align-items: center;
    color: var(--ink);
    display: inline-flex;
    flex-shrink: 0;
    justify-content: center;
    min-height: var(--sf-touch-min, 44px);
    min-width: var(--sf-touch-min, 44px);
    padding: 6px;
    position: relative;
    text-decoration: none;
  }
  .portal-top__notify svg { height: 22px; width: 22px; }
  .portal-top__notify-badge {
    background: var(--terracotta);
    border-radius: 999px;
    color: #fff;
    font-size: .65rem;
    font-weight: 700;
    line-height: 1;
    min-width: 16px;
    padding: 2px 5px;
    position: absolute;
    right: 0;
    top: 0;
  }
  .page-intro { color: var(--muted); font-size: .92rem; line-height: 1.5; margin: 0 0 14px; }
  .notify-toolbar { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; margin-bottom: 12px; }
  .notify-toolbar .page-intro { flex: 1; margin: 0; min-width: 200px; }
  .btn-sm { font-size: .82rem; min-height: var(--sf-touch-min, 44px); padding: 8px 14px; width: auto; }
  .notify-list { list-style: none; margin: 0 0 16px; padding: 0; }
  .notify-item {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    margin-bottom: 10px;
    overflow: hidden;
    position: relative;
  }
  .notify-item.is-unread { border-left: 3px solid var(--terracotta); }
  .notify-item__link {
    color: inherit;
    display: block;
    padding: 14px 16px 10px;
    text-decoration: none;
  }
  .notify-item__head { align-items: flex-start; display: flex; gap: 10px; justify-content: space-between; margin-bottom: 6px; }
  .notify-item__title { font-size: .95rem; font-weight: 600; line-height: 1.35; }
  .notify-item__time { color: var(--muted); flex-shrink: 0; font-size: .72rem; white-space: nowrap; }
  .notify-item__message { color: var(--muted); font-size: .88rem; line-height: 1.45; margin: 0; }
  .notify-item__read {
    background: none;
    border: none;
    border-top: 1px solid var(--border);
    color: var(--terracotta);
    cursor: pointer;
    font-size: .82rem;
    padding: 10px 16px;
    text-align: left;
    width: 100%;
  }
  .notify-prefs { margin-top: 8px; }
  .notify-prefs .card-title { font-size: 1.05rem; margin: 0 0 6px; }
  .notify-prefs .section-note { color: var(--muted); font-size: .82rem; line-height: 1.45; margin: 0 0 12px; }
  .notify-email-unavailable { color: var(--amber); }
  .notify-prefs-form { display: grid; gap: 14px; }
  .notify-prefs-group { border: 1px solid var(--border); border-radius: 10px; margin: 0; padding: 12px 14px; }
  .notify-prefs-group legend { font-size: .88rem; font-weight: 600; padding: 0 4px; }
  .notify-check { align-items: center; display: flex; font-size: .88rem; gap: 8px; margin-top: 8px; }
  .section-actions { align-items: center; display: flex; flex-wrap: wrap; gap: 8px; }
  .section-status { color: var(--green); font-size: .82rem; min-height: 1.2em; }
  .section-status.is-error { color: #b42318; }
</style>
