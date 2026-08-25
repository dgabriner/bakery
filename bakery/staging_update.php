<?php
/**
 * Public staging landing marker — no auth, no secrets.
 * Visit: https://staging.sourflour.org/staging_update.php
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

$marker = 'STAGING-LANDING-2026-08-25-CLOUD';
$stamp = gmdate('Y-m-d H:i:s') . ' UTC';
$statusLabel = $isStagingHost ? 'On staging' : 'Not staging host';
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
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Sora:wght@400;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --ink: #142019;
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
        radial-gradient(ellipse 70% 55% at 15% 10%, rgba(201, 132, 58, 0.22), transparent 55%),
        radial-gradient(ellipse 60% 50% at 90% 85%, rgba(63, 107, 82, 0.45), transparent 50%),
        linear-gradient(155deg, #0d1712 0%, #1a2f24 48%, #203328 100%);
    }

    .scene {
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 2rem 1.25rem 3rem;
      text-align: center;
    }

    .brand {
      font-family: "Fraunces", serif;
      font-weight: 700;
      font-size: clamp(2.6rem, 10vw, 5.5rem);
      letter-spacing: -0.03em;
      line-height: 0.95;
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    .headline {
      margin-top: 1rem;
      font-size: clamp(1.15rem, 3.5vw, 1.45rem);
      font-weight: 600;
      color: var(--ember);
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.08s both;
    }

    .lede {
      margin: 0.85rem auto 0;
      max-width: 26rem;
      color: var(--mist);
      line-height: 1.55;
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.16s both;
    }

    .meta {
      margin: 1.75rem auto 0;
      max-width: 28rem;
      text-align: left;
      font-size: 0.9rem;
      line-height: 1.7;
      color: rgba(238, 243, 238, 0.88);
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.24s both;
    }

    .meta dt {
      display: inline;
      font-weight: 600;
      color: rgba(238, 243, 238, 0.55);
    }

    .meta dd {
      display: inline;
      margin: 0;
    }

    .meta dd::after {
      content: "";
      display: block;
      height: 0.35rem;
    }

    .marker {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: 0.78rem;
      word-break: break-all;
      color: #f0c27a;
    }

    .actions {
      margin-top: 2rem;
      display: flex;
      flex-wrap: wrap;
      gap: 0.75rem;
      justify-content: center;
      animation: rise 0.9s cubic-bezier(0.22, 1, 0.36, 1) 0.32s both;
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

    .cta-bonus {
      border-color: rgba(201, 132, 58, 0.55);
      background: rgba(201, 132, 58, 0.18);
    }

    .pulse {
      width: 0.65rem;
      height: 0.65rem;
      border-radius: 50%;
      display: inline-block;
      margin-right: 0.45rem;
      background: <?php echo $isStagingHost ? '#6ecf8e' : '#c9843a'; ?>;
      box-shadow: 0 0 0 0 <?php echo $isStagingHost ? 'rgba(110, 207, 142, 0.55)' : 'rgba(201, 132, 58, 0.45)'; ?>;
      animation: ping 2.2s ease-out infinite;
      vertical-align: middle;
    }

    @keyframes rise {
      from { opacity: 0; transform: translateY(1.1rem); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes ping {
      0% { box-shadow: 0 0 0 0 currentColor; }
      70% { box-shadow: 0 0 0 12px transparent; }
      100% { box-shadow: 0 0 0 0 transparent; }
    }
  </style>
</head>
<body>
  <main class="scene">
    <div>
      <h1 class="brand">Sour Flour</h1>
      <p class="headline"><span class="pulse" aria-hidden="true"></span><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></p>
      <p class="lede">A tiny cloud-agent landing page to prove a file sync reached this host.</p>
      <dl class="meta">
        <div><dt>Marker · </dt><dd class="marker"><?php echo htmlspecialchars($marker, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <div><dt>Host · </dt><dd><?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <div><dt>APP_ENV · </dt><dd><?php echo htmlspecialchars($appEnv, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <div><dt>Served · </dt><dd><?php echo htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <?php if ($build !== ''): ?>
        <div><dt>Build · </dt><dd class="marker"><?php echo htmlspecialchars($build, ENT_QUOTES, 'UTF-8'); ?></dd></div>
        <?php endif; ?>
      </dl>
      <div class="actions">
        <a class="cta cta-bonus" href="oven_light.php">Open the surprise</a>
        <a class="cta" href="login.php">Staff login</a>
      </div>
    </div>
  </main>
</body>
</html>
