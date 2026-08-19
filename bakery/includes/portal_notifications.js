(function () {
  'use strict';

  var i18n = window.PORTAL_NOTIFY_I18N || {};

  function post(action, data) {
    data = data || {};
    data.action = action;
    if (typeof window.bakeryCsrfAppend === 'function') {
      data = window.bakeryCsrfAppend(data);
    }
    return fetch('customer_portal_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(data).toString(),
      credentials: 'same-origin'
    }).then(function (res) { return res.json(); });
  }

  function updateBadge(delta) {
    var badge = document.querySelector('.portal-top__notify-badge');
    if (!badge) return;
    var count = Math.max(0, parseInt(badge.textContent, 10) + delta);
    if (count <= 0) {
      badge.hidden = true;
      badge.textContent = '0';
    } else {
      badge.hidden = false;
      badge.textContent = String(count);
    }
  }

  document.querySelectorAll('.js-mark-read').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var id = btn.getAttribute('data-id');
      post('mark_notification_read', { notification_id: id }).then(function (data) {
        if (!data.success) return;
        var item = btn.closest('.notify-item');
        if (item) {
          item.classList.remove('is-unread');
          item.classList.add('is-read');
          btn.remove();
        }
        updateBadge(-1);
      });
    });
  });

  var markAllBtn = document.querySelector('.js-mark-all-read');
  if (markAllBtn) {
    markAllBtn.addEventListener('click', function () {
      post('mark_all_notifications_read').then(function (data) {
        if (!data.success) return;
        document.querySelectorAll('.notify-item.is-unread').forEach(function (item) {
          item.classList.remove('is-unread');
          item.classList.add('is-read');
          var btn = item.querySelector('.js-mark-read');
          if (btn) btn.remove();
        });
        markAllBtn.remove();
        updateBadge(-9999);
      });
    });
  }

  var prefsForm = document.querySelector('.js-notify-prefs');
  if (prefsForm) {
    prefsForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = prefsForm.querySelector('.js-prefs-status');
      var payload = {};
      ['order_in_app', 'order_email', 'delivery_in_app', 'delivery_email', 'billing_in_app', 'billing_email'].forEach(function (key) {
        payload[key] = prefsForm.querySelector('[name="' + key + '"]') ? (prefsForm.querySelector('[name="' + key + '"]').checked ? '1' : '0') : '0';
      });
      post('save_notification_preferences', payload).then(function (data) {
        if (status) {
          status.textContent = data.success ? (i18n.saved || 'Saved') : (data.error || i18n.saveFailed || 'Save failed');
          status.classList.toggle('is-error', !data.success);
        }
      }).catch(function () {
        if (status) {
          status.textContent = i18n.networkError || 'Network error';
          status.classList.add('is-error');
        }
      });
    });
  }

  document.querySelectorAll('.notify-item__link[href]').forEach(function (link) {
    link.addEventListener('click', function () {
      var item = link.closest('.notify-item');
      if (!item || !item.classList.contains('is-unread')) return;
      var id = item.getAttribute('data-id');
      post('mark_notification_read', { notification_id: id });
      item.classList.remove('is-unread');
      item.classList.add('is-read');
      var btn = item.querySelector('.js-mark-read');
      if (btn) btn.remove();
      updateBadge(-1);
    });
  });
})();
