/**
 * Attach CSRF token to same-origin fetch POST/PUT/PATCH/DELETE requests.
 */
(function () {
  function getToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  if (!window.fetch) return;
  var originalFetch = window.fetch.bind(window);

  window.fetch = function (input, init) {
    init = init || {};
    var method = (init.method || 'GET').toUpperCase();
    if (['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(method) !== -1) {
      var token = getToken();
      if (token) {
        var headers = init.headers || {};
        if (headers instanceof Headers) {
          if (!headers.has('X-CSRF-Token')) {
            headers.set('X-CSRF-Token', token);
          }
        } else {
          var normalized = {};
          Object.keys(headers).forEach(function (k) {
            normalized[k] = headers[k];
          });
          if (!normalized['X-CSRF-Token'] && !normalized['x-csrf-token']) {
            normalized['X-CSRF-Token'] = token;
          }
          init.headers = normalized;
        }

        // Also append to URLSearchParams / form-urlencoded body when possible
        if (typeof init.body === 'string' && init.body.indexOf('csrf_token=') === -1) {
          init.body += (init.body.length ? '&' : '') + 'csrf_token=' + encodeURIComponent(token);
        }
      }
    }
    return originalFetch(input, init);
  };
})();
