/**
 * Attach CSRF token to same-origin fetch POST/PUT/PATCH/DELETE requests.
 */
(function () {
  function getToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function setToken(token) {
    token = String(token || '');
    if (!token) return false;
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (meta) meta.setAttribute('content', token);
    document.querySelectorAll('input[name="csrf_token"]').forEach(function (input) {
      input.value = token;
    });
    return true;
  }

  function attachTokenToHeaders(init, token) {
    var headers = init.headers || {};
    if (headers instanceof Headers) {
      if (!headers.has('X-CSRF-Token')) {
        headers.set('X-CSRF-Token', token);
      }
      init.headers = headers;
      return;
    }

    var normalized = {};
    Object.keys(headers).forEach(function (k) {
      normalized[k] = headers[k];
    });
    if (!normalized['X-CSRF-Token'] && !normalized['x-csrf-token']) {
      normalized['X-CSRF-Token'] = token;
    }
    init.headers = normalized;
  }

  function attachTokenToBody(init, token) {
    var body = init.body;
    if (typeof body === 'string') {
      if (body.indexOf('csrf_token=') === -1) {
        init.body += (body.length ? '&' : '') + 'csrf_token=' + encodeURIComponent(token);
      }
      return;
    }
    if (typeof URLSearchParams !== 'undefined' && body instanceof URLSearchParams) {
      if (!body.has('csrf_token')) {
        body.append('csrf_token', token);
      }
      return;
    }
    if (typeof FormData !== 'undefined' && body instanceof FormData) {
      if (!body.has('csrf_token')) {
        body.append('csrf_token', token);
      }
    }
  }

  function ensureCsrfInit(init) {
    init = init || {};
    var method = (init.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(method) === -1) {
      return init;
    }

    var token = getToken();
    if (!token) {
      return init;
    }

    attachTokenToHeaders(init, token);
    attachTokenToBody(init, token);
    return init;
  }

  window.bakeryCsrfToken = getToken;
  window.bakerySetCsrfToken = setToken;

  if (!window.fetch) return;
  var originalFetch = window.fetch.bind(window);

  window.fetch = function (input, init) {
    return originalFetch(input, ensureCsrfInit(init));
  };
})();
