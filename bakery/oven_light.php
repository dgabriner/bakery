<?php
/**
 * Bonus atmospheric page — public, no auth.
 * Stop 1 of the Stage 2 night-shift tour.
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
  <title>The Oven Light — Sour Flour</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,700&family=Sora:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --night: #100e0c;
      --ash: #d7cfc4;
      --ember: #e08a3a;
      --core: #ffc56a;
      --soft: rgba(215, 207, 196, 0.78);
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      min-height: 100vh;
      font-family: "Sora", sans-serif;
      color: var(--ash);
      background: var(--night);
      overflow: hidden;
    }

    .stage {
      position: relative;
      min-height: 100vh;
      display: grid;
      place-items: end center;
      padding: clamp(1.5rem, 5vw, 3.5rem);
      background:
        radial-gradient(ellipse 55% 42% at 50% 72%, rgba(224, 138, 58, 0.55), transparent 62%),
        radial-gradient(ellipse 90% 55% at 50% 100%, rgba(255, 197, 106, 0.18), transparent 55%),
        linear-gradient(180deg, #090807 0%, #16120f 48%, #1c1611 100%);
    }

    .dust {
      position: absolute;
      inset: 0;
      pointer-events: none;
      background-image:
        radial-gradient(circle, rgba(255, 220, 170, 0.55) 0 1px, transparent 1.6px),
        radial-gradient(circle, rgba(215, 207, 196, 0.28) 0 1px, transparent 1.5px);
      background-size: 90px 90px, 140px 140px;
      background-position: 0 0, 40px 60px;
      opacity: 0.35;
      animation: dust-drift 36s linear infinite;
    }

    .oven {
      position: absolute;
      left: 50%;
      bottom: 18%;
      width: min(72vw, 28rem);
      height: min(38vw, 14rem);
      transform: translateX(-50%);
      border-radius: 50% 50% 42% 42% / 70% 70% 30% 30%;
      background:
        radial-gradient(ellipse at 50% 60%, rgba(255, 220, 140, 0.95), rgba(224, 138, 58, 0.55) 38%, transparent 68%);
      filter: blur(2px);
      animation: oven-breathe 5.5s ease-in-out infinite;
      pointer-events: none;
    }

    .heat {
      position: absolute;
      left: 50%;
      bottom: 28%;
      width: min(36vw, 12rem);
      height: min(50vw, 18rem);
      transform: translateX(-50%);
      background: linear-gradient(180deg, transparent, rgba(224, 138, 58, 0.12), transparent);
      filter: blur(18px);
      animation: heat-rise 7s ease-in-out infinite;
      pointer-events: none;
    }

    .flicker {
      position: absolute;
      left: 50%;
      bottom: 22%;
      width: min(18vw, 5rem);
      height: min(18vw, 5rem);
      transform: translateX(-50%);
      border-radius: 50%;
      background: radial-gradient(circle, rgba(255, 236, 180, 0.9), transparent 70%);
      filter: blur(6px);
      animation: flicker 2.8s ease-in-out infinite;
      pointer-events: none;
    }

    .copy {
      position: relative;
      z-index: 2;
      width: min(100%, 34rem);
      text-align: center;
      padding-bottom: clamp(1rem, 8vh, 4rem);
    }

    .stop {
      font-size: 0.75rem;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: rgba(255, 197, 106, 0.65);
      margin-bottom: 0.65rem;
      animation: rise 1.15s cubic-bezier(0.22, 1, 0.36, 1) both;
    }

    .brand {
      font-family: "Fraunces", serif;
      font-weight: 700;
      font-size: clamp(3.2rem, 14vw, 7.5rem);
      letter-spacing: -0.04em;
      line-height: 0.9;
      color: var(--ash);
      text-shadow: 0 18px 50px rgba(0, 0, 0, 0.55);
      animation: rise 1.15s cubic-bezier(0.22, 1, 0.36, 1) 0.06s both;
    }

    .line {
      margin-top: 1.1rem;
      font-size: clamp(1rem, 2.8vw, 1.2rem);
      font-weight: 500;
      color: var(--soft);
      animation: rise 1.15s cubic-bezier(0.22, 1, 0.36, 1) 0.14s both;
    }

    .nav {
      margin-top: 1.75rem;
      display: flex;
      flex-wrap: wrap;
      gap: 1rem 1.35rem;
      justify-content: center;
      animation: rise 1.15s cubic-bezier(0.22, 1, 0.36, 1) 0.24s both;
    }

    .nav a {
      color: var(--core);
      text-decoration: none;
      font-weight: 600;
      letter-spacing: 0.01em;
      border-bottom: 1px solid rgba(255, 197, 106, 0.35);
      padding-bottom: 0.15rem;
      transition: border-color 0.25s ease, color 0.25s ease;
    }

    .nav a:hover {
      color: #ffe1a8;
      border-color: rgba(255, 225, 168, 0.7);
    }

    @keyframes rise {
      from { opacity: 0; transform: translateY(1.2rem); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes oven-breathe {
      0%, 100% { opacity: 0.72; transform: translateX(-50%) scale(1); }
      50% { opacity: 1; transform: translateX(-50%) scale(1.06); }
    }

    @keyframes heat-rise {
      0%, 100% { opacity: 0.35; transform: translateX(-50%) translateY(0); }
      50% { opacity: 0.7; transform: translateX(-50%) translateY(-1.25rem); }
    }

    @keyframes dust-drift {
      from { transform: translate3d(0, 0, 0); }
      to { transform: translate3d(-28px, -36px, 0); }
    }

    @keyframes flicker {
      0%, 100% { opacity: 0.45; transform: translateX(-50%) scale(1); }
      40% { opacity: 0.85; transform: translateX(-50%) scale(1.12); }
      55% { opacity: 0.5; transform: translateX(-50%) scale(0.96); }
      70% { opacity: 0.9; transform: translateX(-50%) scale(1.08); }
    }

    @media (prefers-reduced-motion: reduce) {
      .dust, .oven, .heat, .flicker, .stop, .brand, .line, .nav { animation: none; }
    }
  </style>
</head>
<body>
  <main class="stage">
    <div class="dust" aria-hidden="true"></div>
    <div class="heat" aria-hidden="true"></div>
    <div class="oven" aria-hidden="true"></div>
    <div class="flicker" aria-hidden="true"></div>
    <div class="copy">
      <p class="stop">Stop 1 · Night</p>
      <h1 class="brand">Sour Flour</h1>
      <p class="line">The oven light stays on while the city sleeps.</p>
      <nav class="nav" aria-label="Tour">
        <a href="proof_window.php">Continue to the proof window</a>
        <a href="staging_update.php">Back to landing</a>
      </nav>
    </div>
  </main>
</body>
</html>
