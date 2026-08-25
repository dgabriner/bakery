<?php
/**
 * Public staging landing marker — no auth, no secrets.
 * Visit: https://staging.sourflour.org/staging_update.php
 * Stage 2: night-shift tour hub.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$host = strtolower((string) preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'unknown'));
$isStagingHost = ($host === 'staging.sourflour.org');
$appEnv = 'unknown';
$build = '';

try {
    require_once __DIR__ . '/includes/config.php';
    $appEnv = defined('APP_ENV') ? (string) APP_ENV : $appEnv;
    if (function_exists('bakery_client_build_id')) {
        $build = (string) bakery_client_build_id();
    }
} catch (Throwable $e) {
    // Still render the marker even if config is unavailable.
}

$marker = 'STAGING-LANDING-2026-08-25-STAGE2';
$stamp = gmdate('Y-m-d H:i:s') . ' UTC';
$statusLabel = $isStagingHost ? 'On staging · Stage 2' : 'Not staging host';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Staging update — Sour Flour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Sora:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --foam: #eef3ee;
      --leaf: #3f6b52;
      --ember: #c9843a;
      --mist: rgba(238, 243, 238, 0.78);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100vh;
      font-family: "Sora", sans-serif;
      color: var(--foam);
      background:
        radial-gradient(ellipse 70% 55% at 12% 8%, rgba(201, 132, 58, 0.24), transparent 55%),
        radial-gradient(ellipse 65% 50% at 92% 88%, rgba(63, 107, 82, 0.48), transparent 52%),
        linear-gradient(155deg, #0d1712 0%, #1a2f24 48%, #203328 100%);
    }

    .scene {
      position: relative;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 2rem 1.25rem 3rem;
      text-align: center;
      overflow: hidden;
    }

    .grain {
      position: absolute;
      inset: -10%;
      pointer-events: none;
      background-image:
        radial-gradient(circle, rgba(238, 243, 238, 0.07) 0 1px, transparent 1.5px);
      background-size: 52px 52px;
      animation: drift 32s linear infinite;
      opacity: 0.55;
    }

    .inner { position: relative; z-index: 1; max-width: 36rem; }

    .brand {
      font-family: "Fraunces", serif;
      font-weight: 700;
      font-size: clamp(2.8rem, 11vw, 5.8rem);
      letter-spacing: -0.03em;
      line-height: 0.92;
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    .headline {
      margin-top: 1rem;
      font-size: clamp(1.1rem, 3.4vw, 1.4rem);
      font-weight: 600;
      color: var(--ember);
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.08s both;
    }

    .lede {
      margin: 0.85rem auto 0;
      max-width: 28rem;
      color: var(--mist);
      line-height: 1.55;
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.16s both;
    }

    .meta {
      margin: 1.6rem auto 0;
      text-align: left;
      font-size: 0.88rem;
      line-height: 1.7;
      color: rgba(238, 243, 238, 0.88);
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.22s both;
    }

    .meta dt {
      display: inline;
      font-weight: 600;
      color: rgba(238, 243, 238, 0.52);
    }

    .meta dd {
      display: inline;
      margin: 0;
    }

    .meta dd::after {
      content: "";
      display: block;
      height: 0.3rem;
    }

    .marker {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 0.76rem;
      word-break: break-all;
      color: #f0c27a;
    }

    .tour {
      margin-top: 2rem;
      text-align: left;
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.3s both;
    }

    .tour-label {
      font-size: 0.75rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: rgba(238, 243, 238, 0.45);
      margin-bottom: 0.75rem;
    }

    .stops {
      list-style: none;
      display: grid;
      gap: 0.65rem;
    }

    .stops a {
      display: block;
      padding: 0.95rem 1.1rem;
      border: 1px solid rgba(238, 243, 238, 0.22);
      background: rgba(13, 23, 18, 0.35);
      color: var(--foam);
      text-decoration: none;
      transition: border-color 0.25s ease, background 0.25s ease, transform 0.25s ease;
    }

    .stops a:hover {
      border-color: var(--ember);
      background: rgba(63, 107, 82, 0.35);
      transform: translateY(-2px);
    }

    .stops strong {
      display: block;
      font-family: "Fraunces", serif;
      font-size: 1.15rem;
      font-weight: 600;
      margin-bottom: 0.2rem;
    }

    .stops span {
      font-size: 0.88rem;
      color: var(--mist);
      line-height: 1.4;
    }

    .actions {
      margin-top: 1.5rem;
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      justify-content: center;
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.38s both;
    }

    .cta {
      display: inline-block;
      padding: 0.85rem 1.45rem;
      border: 1px solid rgba(238, 243, 238, 0.35);
      background: rgba(20, 32, 25, 0.4);
      color: var(--foam);
      text-decoration: none;
      font-weight: 600;
      transition: border-color 0.25s ease, transform 0.25s ease, background 0.25s ease;
    }

    .cta:hover {
      border-color: var(--ember);
      background: rgba(63, 107, 82, 0.4);
      transform: translateY(-2px);
    }

    .pulse {
      width: 0.65rem;
      height: 0.65rem;
      border-radius: 50%;
      display: inline-block;
      margin-right: 0.45rem;
      background: <?php echo $isStagingHost ? '#6ecf8e' : '#c9843a'; ?>;
      animation: ping 2.2s ease-out infinite;
      vertical-align: middle;
    }

    @keyframes rise {
      from { opacity: 0; transform: translateY(1.1rem); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes ping {
      0% { box-shadow: 0 0 0 0 rgba(110, 207, 142, 0.55); }
      70% { box-shadow: 0 0 0 12px transparent; }
      100% { box-shadow: 0 0 0 0 transparent; }
    }

    @keyframes drift {
      from { transform: translate3d(0, 0, 0); }
      to { transform: translate3d(-20px, 14px, 0); }
    }

    @media (prefers-reduced-motion: reduce) {
      .grain, .brand, .headline, .lede, .meta, .tour, .actions, .pulse { animation: none; }
    }
  </style>
</head>
<body>
  <main class="scene">
    <div class="grain" aria-hidden="true"></div>
    <div class="inner">
      <h1 class="brand">Sour Flour</h1>
      <p class="headline"><span class="pulse" aria-hidden="true"></span><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></p>
      <p class="lede">Cloud sync landed. Walk the night shift — oven glow, then the proofing window at dawn.</p>
      <dl class="meta">
        <div><dt>Marker · </dt><dd class="marker"><?php echo htmlspecialchars($marker, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <div><dt>Host · </dt><dd><?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <div><dt>APP_ENV · </dt><dd><?php echo htmlspecialchars($appEnv, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <div><dt>Served · </dt><dd><?php echo htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <?php if ($build !== ''): ?>
        <div><dt>Build · </dt><dd class="marker"><?php echo htmlspecialchars($build, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <?php endif; ?>
      </dl>
      <nav class="tour" aria-label="Night shift tour">
        <p class="tour-label">Night shift · two stops</p>
        <ul class="stops">
          <li>
            <a href="oven_light.php">
              <strong>1 · The Oven Light</strong>
              <span>While the city sleeps, the deck stays warm.</span>
            </a>
          </li>
          <li>
            <a href="proof_window.php">
              <strong>2 · The Proof Window</strong>
              <span>Dawn presses against the glass. Dough listens.</span>
            </a>
          </li>
        </ul>
      </nav>
      <div class="actions">
        <a class="cta" href="login.php">Staff login</a>
      </div>
    </div>
  </main>
</body>
</html>
