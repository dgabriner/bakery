/**
 * After a production update, reload once so this tab picks up fresh HTML/JS/CSS.
 * Does not clear cookies or localStorage.
 */
(function () {
  if (document.querySelector('meta[name="app-skip-client-refresh"]')) {
    return;
  }
  var BUILD_KEY = 'bakery-client-build';
  var RELOAD_KEY = 'bakery-client-build-reloaded';
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
    } catch (error) {
      // Private-mode or blocked storage: skip persistence, still reload below.
    }
  }

  function reloadForBuild(nextBuild) {
    if (!nextBuild || read(RELOAD_KEY) === nextBuild) {
      return;
    }
    write(RELOAD_KEY, nextBuild);
    write(BUILD_KEY, nextBuild);
    window.location.reload();
  }

  var seen = read(BUILD_KEY);
  if (seen && seen !== build) {
    reloadForBuild(build);
    return;
  }
  write(BUILD_KEY, build);

  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      window.location.reload();
    }
  });

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

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      checkRemoteBuild();
    }
  });

  window.setInterval(checkRemoteBuild, 5 * 60 * 1000);
}());
