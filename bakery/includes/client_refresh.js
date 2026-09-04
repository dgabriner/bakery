/**
 * After a production update, reload once so this tab picks up fresh HTML/JS/CSS.
 * Does not clear cookies or localStorage.
 *
 * Mobile notes:
 * - Never hard-reload solely because the page was restored from bfcache
 *   (app switch / lock screen). That felt like the screen was refreshing
 *   and shaking while trying to work.
 * - Persist a reload latch in sessionStorage before reloading so a blocked
 *   or flaky storage environment cannot loop reloads.
 * - Cooldown between automatic reloads so rapid deploy stamps cannot thrash.
 */
(function () {
  if (document.querySelector('meta[name="app-skip-client-refresh"]')) {
    return;
  }
  var BUILD_KEY = 'bakery-client-build';
  var RELOAD_KEY = 'bakery-client-build-reloaded';
  var RELOAD_AT_KEY = 'bakery-client-build-reloaded-at';
  var RELOAD_COOLDOWN_MS = 60 * 1000;
  var meta = document.querySelector('meta[name="app-build"]');
  var build = meta ? String(meta.getAttribute('content') || '') : '';
  if (!build) {
    return;
  }

  var baseMeta = document.querySelector('meta[name="app-base-url"]');
  var baseUrl = baseMeta ? String(baseMeta.getAttribute('content') || '/') : '/';
  if (baseUrl.charAt(baseUrl.length - 1) !== '/') {
    baseUrl += '/';
  }

  function read(key) {
    try {
      return window.sessionStorage.getItem(key);
    } catch (error) {
      return null;
    }
  }

  function write(key, value) {
    try {
      window.sessionStorage.setItem(key, value);
      return read(key) === String(value);
    } catch (error) {
      return false;
    }
  }

  function withinCooldown() {
    var raw = read(RELOAD_AT_KEY);
    var at = raw ? parseInt(raw, 10) : 0;
    if (!at || isNaN(at)) {
      return false;
    }
    return (Date.now() - at) < RELOAD_COOLDOWN_MS;
  }

  function reloadForBuild(nextBuild) {
    if (!nextBuild || read(RELOAD_KEY) === nextBuild || withinCooldown()) {
      return;
    }
    // Require a durable latch. If storage is blocked, skip auto-reload rather
    // than risk an infinite refresh loop on mobile.
    if (!write(RELOAD_KEY, nextBuild) || !write(BUILD_KEY, nextBuild) || !write(RELOAD_AT_KEY, String(Date.now()))) {
      return;
    }
    window.location.reload();
  }

  var seen = read(BUILD_KEY);
  if (seen && seen !== build) {
    reloadForBuild(build);
    return;
  }
  write(BUILD_KEY, build);

  function checkRemoteBuild() {
    if (typeof window.fetch !== 'function') {
      return;
    }
    window.fetch(baseUrl + 'build_id.php', {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (response) {
      return response.ok ? response.json() : null;
    }).then(function (data) {
      if (data && data.build && String(data.build) !== build) {
        reloadForBuild(String(data.build));
      }
    }).catch(function () {
      // Offline or blocked: keep the current page.
    });
  }

  // On bfcache restore, re-check the deploy stamp quietly. Do not reload just
  // because the page was frozen while the phone was locked or another app was open.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      checkRemoteBuild();
    }
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      checkRemoteBuild();
    }
  });

  window.setInterval(checkRemoteBuild, 5 * 60 * 1000);
}());
