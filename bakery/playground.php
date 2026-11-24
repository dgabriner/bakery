<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

bakery_require_login();

$page_title = 'Agent Playground';
require_once 'includes/header.php';
require_once 'includes/nav.php';
?>
<style>
  .pg-scope {
    --pg-dough: #e9d9b8;
    --pg-crust: #b4712f;
    max-width: 1100px;
    margin: 0 auto;
  }
  .pg-scope.pg-dark {
    --sf-bg: #141b19;
    --sf-bg-elevated: #1a2320;
    --sf-surface: #1e2926;
    --sf-surface-muted: #24302c;
    --sf-surface-inset: #16201d;
    --sf-text: #ecf3f0;
    --sf-text-secondary: #b7c6c1;
    --sf-text-muted: #8a9a95;
    --sf-border: #33413c;
    --sf-border-strong: #46564f;
    background: var(--sf-bg);
    color: var(--sf-text);
    border-radius: var(--sf-radius-lg);
    padding: var(--sf-space-4);
    transition: background 0.25s ease, color 0.25s ease;
  }
  .pg-hero {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, var(--sf-brand) 0%, var(--sf-brand-muted) 100%);
    border-radius: var(--sf-radius-lg);
    padding: var(--sf-space-8) var(--sf-space-6);
    color: var(--sf-text-inverse);
    box-shadow: var(--sf-shadow-md);
    margin-bottom: var(--sf-space-6);
  }
  .pg-hero canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    pointer-events: none;
  }
  .pg-hero h1 {
    margin: 0 0 var(--sf-space-2);
    font-size: 2rem;
    letter-spacing: -0.02em;
  }
  .pg-hero p {
    margin: 0;
    color: rgba(248, 250, 249, 0.85);
    max-width: 46ch;
  }
  .pg-toolbar {
    display: flex;
    gap: var(--sf-space-2);
    justify-content: flex-end;
    margin-bottom: var(--sf-space-4);
  }
  .pg-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: var(--sf-space-4);
  }
  .pg-card {
    background: var(--sf-surface);
    border: 1px solid var(--sf-border);
    border-radius: var(--sf-radius-md);
    box-shadow: var(--sf-shadow-sm);
    padding: var(--sf-space-5);
  }
  .pg-card h2 {
    margin: 0 0 var(--sf-space-1);
    font-size: 1.15rem;
  }
  .pg-card > p.pg-sub {
    margin: 0 0 var(--sf-space-4);
    color: var(--sf-text-muted);
    font-size: 0.88rem;
  }
  .pg-row {
    display: flex;
    align-items: center;
    gap: var(--sf-space-3);
    margin-bottom: var(--sf-space-3);
  }
  .pg-row label {
    flex: 1;
    font-weight: 600;
    font-size: 0.92rem;
  }
  .pg-row input[type="number"] {
    width: 96px;
    min-height: var(--sf-touch-min);
    padding: 0.45rem 0.6rem;
    border: 1px solid var(--sf-border-strong);
    border-radius: var(--sf-radius-sm);
    background: var(--sf-surface-muted);
    color: inherit;
    font: inherit;
    text-align: right;
  }
  .pg-row input[type="number"]:focus-visible {
    outline: none;
    box-shadow: var(--sf-focus-ring);
    border-color: var(--sf-primary);
  }
  .pg-pct {
    width: 72px;
    text-align: right;
    font-family: var(--sf-font-mono);
    font-size: 0.85rem;
    color: var(--sf-text-muted);
  }
  .pg-total {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    border-top: 1px dashed var(--sf-border-strong);
    padding-top: var(--sf-space-3);
    margin-top: var(--sf-space-2);
    font-weight: 700;
  }
  .pg-total strong {
    font-family: var(--sf-font-mono);
    font-size: 1.2rem;
    color: var(--sf-primary);
  }
  .pg-timer {
    display: flex;
    gap: var(--sf-space-5);
    align-items: center;
    flex-wrap: wrap;
  }
  .pg-ring-wrap {
    position: relative;
    width: 168px;
    height: 168px;
    flex: none;
  }
  .pg-ring-wrap svg {
    transform: rotate(-90deg);
  }
  .pg-ring-label {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
  }
  .pg-ring-time {
    font-family: var(--sf-font-mono);
    font-size: 1.7rem;
    font-weight: 700;
    line-height: 1.1;
  }
  .pg-ring-stage {
    font-size: 0.78rem;
    color: var(--sf-text-muted);
    margin-top: 0.2rem;
  }
  .pg-timer-side {
    flex: 1;
    min-width: 180px;
  }
  .pg-timer-btns {
    display: flex;
    gap: var(--sf-space-2);
    flex-wrap: wrap;
    margin-bottom: var(--sf-space-3);
  }
  .pg-steps {
    list-style: none;
    margin: 0;
    padding: 0;
    font-size: 0.86rem;
    color: var(--sf-text-secondary);
  }
  .pg-steps li {
    padding: 0.3rem 0 0.3rem 1.4rem;
    position: relative;
  }
  .pg-steps li::before {
    content: "";
    position: absolute;
    left: 0;
    top: 50%;
    transform: translateY(-50%);
    width: 10px;
    height: 10px;
    border-radius: 50%;
    border: 2px solid var(--sf-border-strong);
    background: transparent;
  }
  .pg-steps li.pg-done::before {
    background: var(--sf-success);
    border-color: var(--sf-success);
  }
  .pg-steps li.pg-active {
    color: var(--sf-text);
    font-weight: 650;
  }
  .pg-steps li.pg-active::before {
    border-color: var(--sf-accent);
    box-shadow: 0 0 0 3px rgba(199, 120, 58, 0.25);
  }
  .pg-note {
    margin-top: var(--sf-space-5);
    display: flex;
    gap: var(--sf-space-3);
    align-items: flex-start;
    background: var(--sf-info-bg);
    border: 1px solid var(--sf-info-border);
    border-radius: var(--sf-radius-md);
    padding: var(--sf-space-4);
    font-size: 0.9rem;
    color: var(--sf-text);
  }
  .pg-confetti {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 9999;
  }
  @media (prefers-reduced-motion: reduce) {
    .pg-hero canvas { display: none; }
  }
</style>

<div class="container">
  <div class="pg-scope" id="pgScope">
    <div class="pg-toolbar">
      <button type="button" class="btn btn-outline" id="pgThemeToggle">🌗 Toggle theme</button>
    </div>

    <div class="pg-hero">
      <canvas id="pgDust" aria-hidden="true"></canvas>
      <h1>🥖 Agent Playground</h1>
      <p>A sandbox page for trying ideas. Everything below runs entirely in your browser — no database writes, no shared files touched.</p>
    </div>

    <div class="pg-grid">
      <section class="pg-card" aria-labelledby="pgHydrationTitle">
        <h2 id="pgHydrationTitle">💧 Dough Hydration Calculator</h2>
        <p class="pg-sub">Baker's percentages update live as you type.</p>
        <div class="pg-row">
          <label for="pgFlour">Flour (g)</label>
          <input type="number" id="pgFlour" value="500" min="0" step="10">
          <span class="pg-pct">100%</span>
        </div>
        <div class="pg-row">
          <label for="pgWater">Water (g)</label>
          <input type="number" id="pgWater" value="350" min="0" step="10">
          <span class="pg-pct" id="pgWaterPct"></span>
        </div>
        <div class="pg-row">
          <label for="pgSalt">Salt (g)</label>
          <input type="number" id="pgSalt" value="10" min="0" step="1">
          <span class="pg-pct" id="pgSaltPct"></span>
        </div>
        <div class="pg-row">
          <label for="pgStarter">Starter (g)</label>
          <input type="number" id="pgStarter" value="100" min="0" step="10">
          <span class="pg-pct" id="pgStarterPct"></span>
        </div>
        <div class="pg-total">
          <span>Total dough</span>
          <strong id="pgTotal">960 g</strong>
        </div>
        <p class="pg-sub" style="margin: var(--sf-space-3) 0 0;" id="pgLoaves"></p>
      </section>

      <section class="pg-card" aria-labelledby="pgTimerTitle">
        <h2 id="pgTimerTitle">⏱️ Proofing Timer</h2>
        <p class="pg-sub">A 90-minute bulk ferment, split into stages.</p>
        <div class="pg-timer">
          <div class="pg-ring-wrap">
            <svg width="168" height="168" viewBox="0 0 168 168">
              <circle cx="84" cy="84" r="74" fill="none" stroke="var(--sf-surface-inset)" stroke-width="12"></circle>
              <circle id="pgRing" cx="84" cy="84" r="74" fill="none" stroke="var(--sf-primary)" stroke-width="12"
                      stroke-linecap="round" stroke-dasharray="464.96" stroke-dashoffset="464.96"></circle>
            </svg>
            <div class="pg-ring-label">
              <span class="pg-ring-time" id="pgClock">90:00</span>
              <span class="pg-ring-stage" id="pgStage">Ready</span>
            </div>
          </div>
          <div class="pg-timer-side">
            <div class="pg-timer-btns">
              <button type="button" class="btn btn-primary" id="pgStart">Start</button>
              <button type="button" class="btn btn-outline" id="pgReset">Reset</button>
            </div>
            <ul class="pg-steps" id="pgSteps">
              <li data-from="0">Autolyse &amp; fold</li>
              <li data-from="30">Bulk ferment</li>
              <li data-from="60">Pre-shape &amp; rest</li>
              <li data-from="80">Final proof</li>
              <li data-from="89">Bake! 🔥</li>
            </ul>
          </div>
        </div>
      </section>
    </div>

    <div class="pg-note">
      <span aria-hidden="true" style="font-size:1.3rem;">🧪</span>
      <div>
        <strong>Sandbox guarantee:</strong> this page is a single new file (<code>playground.php</code>).
        It performs zero database writes and its styles/scripts are namespaced with <code>pg-</code>,
        so nothing else in Sour Flour OS is affected. Delete the file and it's like it never existed.
      </div>
    </div>
  </div>
</div>

<canvas class="pg-confetti" id="pgConfetti" aria-hidden="true"></canvas>

<script>
(function () {
  'use strict';
  var scope = document.getElementById('pgScope');
  var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  document.getElementById('pgThemeToggle').addEventListener('click', function () {
    scope.classList.toggle('pg-dark');
  });

  var flour = document.getElementById('pgFlour');
  var water = document.getElementById('pgWater');
  var salt = document.getElementById('pgSalt');
  var starter = document.getElementById('pgStarter');

  function pct(part, total) {
    return total > 0 ? Math.round((part / total) * 1000) / 10 + '%' : '—';
  }

  function recalc() {
    var f = Math.max(0, parseFloat(flour.value) || 0);
    var w = Math.max(0, parseFloat(water.value) || 0);
    var s = Math.max(0, parseFloat(salt.value) || 0);
    var st = Math.max(0, parseFloat(starter.value) || 0);
    var baseFlour = f + st / 2;
    document.getElementById('pgWaterPct').textContent = pct(w, baseFlour);
    document.getElementById('pgSaltPct').textContent = pct(s, baseFlour);
    document.getElementById('pgStarterPct').textContent = pct(st, baseFlour || undefined);
    var total = f + w + s + st;
    document.getElementById('pgTotal').textContent = Math.round(total) + ' g';
    var loaves = Math.floor(total / 900);
    document.getElementById('pgLoaves').textContent =
      total > 0 ? '≈ ' + loaves + ' × 900 g loaf' + (loaves === 1 ? '' : 's') : '';
  }

  [flour, water, salt, starter].forEach(function (input) {
    input.addEventListener('input', recalc);
  });
  recalc();

  var TOTAL_MS = 90 * 60 * 1000;
  var ring = document.getElementById('pgRing');
  var clock = document.getElementById('pgClock');
  var stageEl = document.getElementById('pgStage');
  var steps = Array.prototype.slice.call(document.querySelectorAll('#pgSteps li'));
  var CIRCUMFERENCE = 464.96;
  var remaining = TOTAL_MS;
  var running = false;
  var lastTick = 0;

  function fmt(ms) {
    var totalSec = Math.max(0, Math.ceil(ms / 1000));
    var m = Math.floor(totalSec / 60);
    var s = totalSec % 60;
    return m + ':' + (s < 10 ? '0' : '') + s;
  }

  function paint() {
    var elapsed = TOTAL_MS - remaining;
    ring.style.strokeDashoffset = String(CIRCUMFERENCE * (remaining / TOTAL_MS));
    clock.textContent = fmt(remaining);
    var minute = elapsed / 60000;
    var activeIdx = 0;
    steps.forEach(function (li, i) {
      var from = parseFloat(li.getAttribute('data-from'));
      if (minute >= from) activeIdx = i;
      li.classList.toggle('pg-done', minute >= from && i < activeIdx);
      li.classList.toggle('pg-active', i === activeIdx);
    });
    stageEl.textContent = running ? steps[activeIdx].textContent.replace(' 🔥', '') : (remaining <= 0 ? 'Done 🍞' : 'Paused');
  }

  function tick(now) {
    if (!running) return;
    remaining -= now - lastTick;
    lastTick = now;
    if (remaining <= 0) {
      remaining = 0;
      running = false;
      paint();
      celebrate();
      document.getElementById('pgStart').textContent = 'Start';
      return;
    }
    paint();
    requestAnimationFrame(tick);
  }

  document.getElementById('pgStart').addEventListener('click', function () {
    if (running) {
      running = false;
      this.textContent = 'Resume';
      paint();
      return;
    }
    if (remaining <= 0) remaining = TOTAL_MS;
    running = true;
    lastTick = performance.now();
    this.textContent = 'Pause';
    requestAnimationFrame(tick);
  });

  document.getElementById('pgReset').addEventListener('click', function () {
    running = false;
    remaining = TOTAL_MS;
    document.getElementById('pgStart').textContent = 'Start';
    paint();
  });

  paint();

  function celebrate() {
    if (reduceMotion) return;
    var canvas = document.getElementById('pgConfetti');
    var ctx = canvas.getContext('2d');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    var colors = ['#c7783a', '#24746a', '#e9d9b8', '#153834'];
    var bits = [];
    for (var i = 0; i < 120; i++) {
      bits.push({
        x: Math.random() * canvas.width,
        y: -20 - Math.random() * canvas.height * 0.3,
        vy: 2 + Math.random() * 3,
        vx: -1.5 + Math.random() * 3,
        size: 4 + Math.random() * 6,
        rot: Math.random() * Math.PI,
        vr: -0.1 + Math.random() * 0.2,
        color: colors[i % colors.length]
      });
    }
    var frames = 0;
    function frame() {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      bits.forEach(function (b) {
        b.x += b.vx;
        b.y += b.vy;
        b.rot += b.vr;
        ctx.save();
        ctx.translate(b.x, b.y);
        ctx.rotate(b.rot);
        ctx.fillStyle = b.color;
        ctx.fillRect(-b.size / 2, -b.size / 2, b.size, b.size * 0.6);
        ctx.restore();
      });
      frames++;
      if (frames < 240) requestAnimationFrame(frame);
      else ctx.clearRect(0, 0, canvas.width, canvas.height);
    }
    frame();
  }

  var heroCanvas = document.getElementById('pgDust');
  if (heroCanvas && !reduceMotion) {
    var hctx = heroCanvas.getContext('2d');
    var motes = [];
    function sizeHero() {
      heroCanvas.width = heroCanvas.offsetWidth;
      heroCanvas.height = heroCanvas.offsetHeight;
    }
    sizeHero();
    window.addEventListener('resize', sizeHero);
    for (var i = 0; i < 40; i++) {
      motes.push({
        x: Math.random(), y: Math.random(),
        r: 1 + Math.random() * 2.5,
        vy: 0.15 + Math.random() * 0.35,
        drift: Math.random() * Math.PI * 2,
        alpha: 0.15 + Math.random() * 0.3
      });
    }
    (function drawMotes() {
      hctx.clearRect(0, 0, heroCanvas.width, heroCanvas.height);
      motes.forEach(function (m) {
        m.y -= m.vy / heroCanvas.height;
        m.drift += 0.01;
        if (m.y < -0.05) { m.y = 1.05; m.x = Math.random(); }
        hctx.beginPath();
        hctx.arc(m.x * heroCanvas.width + Math.sin(m.drift) * 12, m.y * heroCanvas.height, m.r, 0, Math.PI * 2);
        hctx.fillStyle = 'rgba(255, 250, 242, ' + m.alpha + ')';
        hctx.fill();
      });
      requestAnimationFrame(drawMotes);
    })();
  }
}());
</script>

<?php require_once 'includes/footer.php'; ?>
