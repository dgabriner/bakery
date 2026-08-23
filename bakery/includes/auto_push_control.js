/**
 * Local admin controls: toggle auto-push to DreamHost + manual sync.
 */
(function () {
  var root = document.getElementById('auto-push-controls');
  if (!root) return;

  var base = root.getAttribute('data-base-url') || '/';
  var apiUrl = base + 'auto_push_api.php';
  var toggle = document.getElementById('auto-push-toggle');
  var syncBtn = document.getElementById('auto-push-sync');
  var statusEl = document.getElementById('auto-push-status');
  var busy = false;

  function setStatus(text, kind) {
    if (!statusEl) return;
    statusEl.textContent = text || '';
    statusEl.className = 'auto-push-status' + (kind ? ' auto-push-status--' + kind : '');
  }

  function applyEnabled(enabled) {
    if (toggle) {
      toggle.checked = !!enabled;
      toggle.setAttribute('aria-checked', enabled ? 'true' : 'false');
    }
    root.classList.toggle('auto-push-on', !!enabled);
    root.classList.toggle('auto-push-off', !enabled);
    if (syncBtn) {
      syncBtn.classList.toggle('auto-push-sync-emphasis', !enabled);
    }
  }

  function api(action, extra) {
    extra = extra || {};
    var form = 'action=' + encodeURIComponent(action);
    Object.keys(extra).forEach(function (key) { form += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(extra[key]); });
    return fetch(apiUrl + '?action=' + encodeURIComponent(action), {
      method: action === 'status' ? 'GET' : 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: action === 'status' ? undefined : form,
      credentials: 'same-origin',
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try {
          data = text ? JSON.parse(text) : null;
        } catch (parseErr) {
          throw new Error('HTTP ' + res.status + (text ? ': ' + text.slice(0, 180) : ''));
        }
        if (!res.ok || (data && data.ok === false)) {
          var err =
            (data && (data.error || data.message)) ||
            ('HTTP ' + res.status);
          if (data && data.output) {
            var tail = String(data.output).trim().split(/\r?\n/).slice(-4).join(' | ');
            if (tail) {
              err += (err ? ' — ' : '') + tail;
            }
          }
          throw new Error(err);
        }
        return data || {};
      });
    });
  }

  function statusText(data) {
    if (!data.enabled) {
      return 'Auto-push off';
    }
    if (data.watching) {
      return 'Watching local files (Cursor + outside)';
    }
    return 'Auto-push on (starting file watcher…)';
  }

  function refresh() {
    return api('status').then(function (data) {
      applyEnabled(!!data.enabled);
      setStatus(statusText(data), data.enabled && data.watching ? 'ok' : 'muted');
      return data;
    });
  }

  if (toggle) {
    toggle.addEventListener('change', function () {
      if (busy) {
        toggle.checked = !toggle.checked;
        return;
      }
      busy = true;
      var wantOn = !!toggle.checked;
      setStatus(wantOn ? 'Enabling…' : 'Disabling…', 'busy');
      api(wantOn ? 'enable' : 'disable')
        .then(function (data) {
          applyEnabled(!!data.enabled);
          setStatus(data.message || statusText(data), data.enabled && data.watching ? 'ok' : (data.enabled ? 'warn' : 'warn'));
        })
        .catch(function (err) {
          applyEnabled(!wantOn);
          setStatus(err.message || 'Toggle failed', 'error');
        })
        .then(function () {
          busy = false;
        });
    });
  }

  if (syncBtn) {
    syncBtn.addEventListener('click', function () {
      if (busy) return;
      busy = true;
      syncBtn.disabled = true;
      setStatus('Syncing to staging…', 'busy');
      api('sync')
        .then(function (data) {
          applyEnabled(!!data.enabled);
          var msg = data.message || 'Sync finished';
          if (data.output && /Nothing to upload/i.test(data.output)) {
            msg = 'Already in sync — nothing new to upload';
          } else if (data.output && /Uploading\s+(\d+)/i.test(data.output)) {
            var m = data.output.match(/Uploading\s+(\d+)/i);
            msg = 'Uploaded ' + m[1] + ' file(s) to staging';
          }
          setStatus(msg, data.ok ? 'ok' : 'error');
        })
        .catch(function (err) {
          setStatus(err.message || 'Sync failed', 'error');
        })
        .then(function () {
          busy = false;
          syncBtn.disabled = false;
        });
    });
  }

  refresh().catch(function (err) {
    setStatus(err.message || 'Could not load sync status', 'error');
  });
})();
