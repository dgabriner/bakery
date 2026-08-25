<?php
/**
 * Stage 2 surprise — dawn at the proofing window. Public, no auth.
 */
define('ACCESS_ALLOWED', true);
define('BAKERY_SKIP_REQUEST_SECURITY', true);

header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>The Proof Window — Sour Flour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Sora:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --ink: #1a221c;
      --sky: #8eb4c8;
      --mist: #e6ebe4;
      --moss: #4a6b55;
      --soft: rgba(26, 34, 28, 0.72);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100vh;
      font-family: "Sora", sans-serif;
      color: var(--ink);
      overflow-x: hidden;
    }

    .view {
      position: relative;
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: clamp(1.5rem, 5vw, 3rem);
      background:
        radial-gradient(ellipse 80% 55% at 50% -10%, rgba(255, 214, 170, 0.55), transparent 55%),
        radial-gradient(ellipse 70% 45% at 80% 30%, rgba(142, 180, 200, 0.35), transparent 50%),
        linear-gradient(180deg, #6a8fa3 0%, #b7c9b8 42%, #dfe6d8 72%, #c9d2c4 100%);
    }

    .pane {
      position: absolute;
      inset: 8% 10% 18%;
      border: 1px solid rgba(26, 34, 28, 0.18);
      background:
        linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.02));
      box-shadow:
        inset 0 0 0 1px rgba(255, 255, 255, 0.18),
        0 30px 80px rgba(26, 34, 28, 0.18);
      pointer-events: none;
      animation: pane-breathe 8s ease-in-out infinite;
    }

    .pane::before,
    .pane::after {
      content: "";
      position: absolute;
      background: rgba(26, 34, 28, 0.12);
    }

    .pane::before {
      left: 50%;
      top: 0;
      bottom: 0;
      width: 1px;
      transform: translateX(-50%);
    }

    .pane::after {
      top: 50%;
      left: 0;
      right: 0;
      height: 1px;
      transform: translateY(-50%);
    }

    .steam {
      position: absolute;
      left: 50%;
      bottom: 22%;
      width: min(50vw, 16rem);
      height: min(40vw, 12rem);
      transform: translateX(-50%);
      background:
        radial-gradient(ellipse at 50% 80%, rgba(230, 235, 228, 0.55), transparent 65%);
      filter: blur(14px);
      animation: steam-rise 9s ease-in-out infinite;
      pointer-events: none;
    }

    .dough {
      position: absolute;
      left: 50%;
      bottom: 14%;
      width: min(58vw, 18rem);
      height: min(16vw, 4.5rem);
      transform: translateX(-50%);
      border-radius: 50% 50% 45% 45% / 70% 70% 40% 40%;
      background:
        radial-gradient(ellipse at 50% 40%, #f2e6d2, #d8c3a4 55%, #c4ad8c 100%);
      box-shadow: 0 8px 24px rgba(26, 34, 28, 0.18);
      animation: dough-proof 10s ease-in-out infinite;
      pointer-events: none;
    }

    .copy {
      position: relative;
      z-index: 2;
      width: min(100%, 34rem);
      text-align: center;
      margin-top: clamp(0rem, 8vh, 4rem);
    }

    .stop {
      font-size: 0.75rem;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: var(--moss);
      margin-bottom: 0.65rem;
      animation: rise 1.1s cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    .brand {
      font-family: "Fraunces", serif;
      font-weight: 700;
      font-size: clamp(3rem, 13vw, 6.8rem);
      letter-spacing: -0.04em;
      line-height: 0.9;
      color: var(--ink);
      animation: rise 1.1s cubic-bezier(0.22, 1, 0.36, 1) 0.08s both;
    }

    .line {
      margin-top: 1.1rem;
      font-size: clamp(1rem, 2.8vw, 1.2rem);
      font-weight: 500;
      color: var(--soft);
      max-width: 26rem;
      margin-left: auto;
      margin-right: auto;
      line-height: 1.5;
      animation: rise 1.1s cubic-bezier(0.22, 1, 0.36, 1) 0.16s both;
    }

    .nav {
      margin-top: 1.75rem;
      display: flex;
      flex-wrap: wrap;
      gap: 1rem 1.35rem;
      justify-content: center;
      animation: rise 1.1s cubic-bezier(0.22, 1, 0.36, 1) 0.26s both;
    }

    .nav a {
      color: var(--moss);
      text-decoration: none;
      font-weight: 600;
      border-bottom: 1px solid rgba(74, 107, 85, 0.35);
      padding-bottom: 0.15rem;
      transition: border-color 0.25s ease, color 0.25s ease;
    }

    .nav a:hover {
      color: #2f4a38;
      border-color: rgba(47, 74, 56, 0.65);
    }

    @keyframes rise {
      from { opacity: 0; transform: translateY(1.15rem); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes pane-breathe {
      0%, 100% { opacity: 0.88; }
      50% { opacity: 1; }
    }

    @keyframes steam-rise {
      0%, 100% { opacity: 0.35; transform: translateX(-50%) translateY(0) scale(1); }
      50% { opacity: 0.7; transform: translateX(-50%) translateY(-1.5rem) scale(1.08); }
    }

    @keyframes dough-proof {
      0%, 100% { transform: translateX(-50%) scale(1, 1); }
      50% { transform: translateX(-50%) scale(1.04, 1.12); }
    }

    @media (prefers-reduced-motion: reduce) {
      .pane, .steam, .dough, .stop, .brand, .line, .nav { animation: none; }
    }
  </style>
</head>
<body>
  <main class="view">
    <div class="pane" aria-hidden="true"></div>
    <div class="steam" aria-hidden="true"></div>
    <div class="dough" aria-hidden="true"></div>
    <div class="copy">
      <p class="stop">Stop 2 · Dawn</p>
      <h1 class="brand">Sour Flour</h1>
      <p class="line">Through the proof window, first light finds the dough still listening.</p>
      <nav class="nav" aria-label="Tour">
        <a href="oven_light.php">Back to the oven</a>
        <a href="staging_update.php">Landing marker</a>
      </nav>
    </div>
  </main>
</body>
</html>
