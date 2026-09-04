/**
 * Sour Flour OS — lightweight shell interactions (no business logic).
 * Also: global browser error / unhandledrejection beacon (mission 36).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-sf-loading]').forEach(function (form) {
      form.addEventListener('submit', function () {
        form.classList.add('sf-is-loading');
        var submitter = form.querySelector('[type="submit"]');
        if (submitter && !submitter.dataset.sfOriginalText) {
          submitter.dataset.sfOriginalText = submitter.textContent || '';
          submitter.textContent = submitter.dataset.sfLoadingText || (window.__BAKERY_I18N__ && window.__BAKERY_I18N__.saving) || 'Saving…';
          submitter.disabled = true;
        }
      });
    });
  });

  function somethingFailedMessage() {
    return (window.__BAKERY_I18N__ && window.__BAKERY_I18N__.something_failed)
      || 'Something went wrong. Please try again.';
  }

  function isOpsWorkspace() {
    var body = document.body;
    if (!body || !body.className) return false;
    return /\bworkspace-(driver|baker|cashier)\b/.test(body.className);
  }

  function showShellToast(message) {
    if (!message || !isOpsWorkspace()) return;
    var routeToast = document.getElementById('routeSuccessToast');
    if (routeToast) {
      routeToast.hidden = false;
      routeToast.textContent = message;
      routeToast.classList.add('is-visible');
      window.setTimeout(function () {
        routeToast.classList.remove('is-visible');
      }, 3200);
      return;
    }
    var el = document.getElementById('sf-shell-toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'sf-shell-toast';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.style.cssText = 'position:fixed;left:50%;bottom:1.25rem;transform:translateX(-50%);z-index:9999;max-width:90vw;padding:.65rem 1rem;background:#1c1917;color:#fafaf9;border-radius:6px;font:600 0.9rem/1.35 system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.25);opacity:0;transition:opacity .2s ease;pointer-events:none;';
      document.body.appendChild(el);
    }
    el.textContent = message;
    el.style.opacity = '1';
    window.setTimeout(function () {
      el.style.opacity = '0';
    }, 3200);
  }

  function beaconPayload(kind, message, stack) {
    var buildMeta = document.querySelector('meta[name="app-build"]');
    var body = new URLSearchParams();
    body.set('kind', kind || 'error');
    body.set('message', String(message || '').slice(0, 500));
    body.set('stack_head', String(stack || '').slice(0, 2000));
    body.set('page', String(window.location.pathname || '').slice(0, 1024));
    body.set('href', String(window.location.href || '').slice(0, 1024));
    body.set('build', buildMeta ? String(buildMeta.content || '') : '');
    return body;
  }

  function sendClientErrorBeacon(kind, message, stack) {
    var baseMeta = document.querySelector('meta[name="app-base-url"]');
    if (!baseMeta) return;
    var url = String(baseMeta.content || '/') + 'client_error_api.php';
    var body = beaconPayload(kind, message, stack);
    try {
      if (navigator.sendBeacon) {
        navigator.sendBeacon(url, body);
        return;
      }
    } catch (ignore) {}
    try {
      fetch(url, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        keepalive: true
      }).catch(function () {});
    } catch (ignore2) {}
  }

  window.addEventListener('unhandledrejection', function (event) {
    var reason = event && event.reason;
    var message = 'unhandledrejection';
    var stack = '';
    if (reason && typeof reason === 'object') {
      message = String(reason.message || reason.error || message);
      stack = String(reason.stack || '');
    } else if (reason != null) {
      message = String(reason);
    }
    sendClientErrorBeacon('unhandledrejection', message, stack);
    showShellToast(somethingFailedMessage());
  });

  window.addEventListener('error', function (event) {
    var message = (event && event.message) ? String(event.message) : 'error';
    var stack = (event && event.error && event.error.stack) ? String(event.error.stack) : '';
    sendClientErrorBeacon('error', message, stack);
    showShellToast(somethingFailedMessage());
  });
})();
