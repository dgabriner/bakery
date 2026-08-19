/**
 * Browser half of login telemetry. Device metadata and precise location
 * are requested automatically; no in-app prompt is shown.
 */
(function () {
  'use strict';
  document.querySelectorAll('[data-login-location-choice]').forEach(function (node) { node.remove(); });
  var meta = document.querySelector('meta[name="csrf-token"]');
  var baseMeta = document.querySelector('meta[name="app-base-url"]');
  if (!meta || !baseMeta) return;

  var endpoint = String(baseMeta.content || '/') + 'login_audit_api.php';
  var csrf = meta.content;
  var auditSessionMeta = document.querySelector('meta[name="login-audit-session"]');
  var promptKey = 'bakery-login-location-choice-' + String(auditSessionMeta ? auditSessionMeta.content : 'current');

  function payload(extra) {
    var nav = window.navigator || {};
    var screenValue = window.screen ? window.screen.width + 'x' + window.screen.height + ' @' + (window.devicePixelRatio || 1) + 'x' : '';
    var viewportValue = window.innerWidth + 'x' + window.innerHeight;
    var data = {
      screen: screenValue,
      viewport: viewportValue,
      platform: String(nav.platform || ''),
      language: String(nav.language || ''),
      languages: Array.isArray(nav.languages) ? nav.languages.join(',') : String(nav.language || ''),
      timezone: (window.Intl && window.Intl.DateTimeFormat) ? String(window.Intl.DateTimeFormat().resolvedOptions().timeZone || '') : '',
      device_memory: String(nav.deviceMemory || ''),
      hardware_concurrency: String(nav.hardwareConcurrency || ''),
      touch_points: String(nav.maxTouchPoints || 0),
      cookie_enabled: nav.cookieEnabled ? 'yes' : 'no',
      online: nav.onLine ? 'yes' : 'no',
      do_not_track: String(nav.doNotTrack || window.doNotTrack || ''),
      color_depth: window.screen ? String(window.screen.colorDepth || '') : '',
      orientation: window.screen && window.screen.orientation ? String(window.screen.orientation.type || '') : '',
      vendor: String(nav.vendor || ''),
      app_version: String(nav.appVersion || ''),
      connection: nav.connection ? [nav.connection.effectiveType, nav.connection.downlink, nav.connection.rtt, nav.connection.saveData ? 'save-data' : ''].join('/') : '',
      storage: (function () { try { return window.localStorage && window.sessionStorage ? 'local+session' : 'limited'; } catch (error) { return 'blocked'; } }()),
      page_path: String(window.location.pathname || ''),
      page_title: String(document.title || '')
    };
    Object.keys(extra || {}).forEach(function (key) { data[key] = extra[key]; });
    return data;
  }

  function send(extra, keepalive) {
    var body = new URLSearchParams();
    body.set('csrf_token', csrf);
    var data = payload(extra);
    Object.keys(data).forEach(function (key) { body.set(key, String(data[key] == null ? '' : data[key])); });
    fetch(endpoint, {
      method: 'POST', credentials: 'same-origin', body: body,
      keepalive: !!keepalive, headers: { 'X-CSRF-Token': csrf }
    }).catch(function () {});
  }

  function requestLocation() {
    if (!navigator.geolocation) {
      send({ location_status: 'unavailable' });
      return;
    }
    try {
      if (window.sessionStorage.getItem(promptKey) === '1') return;
      window.sessionStorage.setItem(promptKey, '1');
    } catch (error) {}
    navigator.geolocation.getCurrentPosition(function (position) {
      send({ location_status: 'granted', gps_latitude: position.coords.latitude, gps_longitude: position.coords.longitude, gps_accuracy_m: position.coords.accuracy });
    }, function () {
      send({ location_status: 'denied' });
    }, { enableHighAccuracy: true, maximumAge: 300000, timeout: 10000 });
  }

  send({ location_status: 'not_requested', event_type: 'page_view' });
  if (navigator.userAgentData && navigator.userAgentData.getHighEntropyValues) {
    navigator.userAgentData.getHighEntropyValues(['architecture', 'bitness', 'model', 'platformVersion', 'fullVersionList']).then(function (hints) {
      send({ user_agent_data: JSON.stringify(hints) });
    }).catch(function () {});
  }
  window.setTimeout(requestLocation, 800);
  window.setInterval(function () { send({}, false); }, 60000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) send({}, false); });
  window.addEventListener('pagehide', function () { send({}, true); });
}());
