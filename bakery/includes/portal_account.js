(function () {
  'use strict';

  var i18n = window.PORTAL_ACCOUNT_I18N || {};

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function setStatus(el, message, isError) {
    if (!el) return;
    el.textContent = message || '';
    el.classList.toggle('is-error', !!isError);
  }

  function postAccount(action, fields) {
    var body = new URLSearchParams({ action: action, csrf_token: csrfToken() });
    Object.keys(fields).forEach(function (key) {
      body.set(key, fields[key]);
    });
    return fetch('customer_portal_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      credentials: 'same-origin',
    }).then(function (res) {
      return res.json().then(function (data) {
        if (!res.ok || !data.success) {
          throw new Error((data && data.error) || i18n.saveFailed || 'Save failed');
        }
        return data;
      });
    });
  }

  document.querySelectorAll('.js-account-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var section = form.getAttribute('data-section');
      var statusEl = form.querySelector('.section-status');
      var btn = form.querySelector('button[type="submit"]');
      var fields = {};

      Array.prototype.forEach.call(form.elements, function (el) {
        if (!el.name || el.type === 'submit') return;
        fields[el.name] = el.value;
      });

      if (btn) btn.disabled = true;
      setStatus(statusEl, '', false);

      postAccount('save_account_section', { section: section, fields: JSON.stringify(fields) })
        .then(function (data) {
          if (data.no_changes) {
            setStatus(statusEl, i18n.noChanges || 'No changes');
          } else {
            setStatus(statusEl, i18n.saved || 'Saved');
          }
        })
        .catch(function (err) {
          setStatus(statusEl, err.message || i18n.networkError || 'Network error', true);
        })
        .finally(function () {
          if (btn) btn.disabled = false;
        });
    });
  });

  document.querySelectorAll('.js-request-change').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var panel = btn.closest('.request-panel');
      if (!panel) return;
      var field = btn.getAttribute('data-field');
      var statusEl = panel.querySelector('.section-status');
      var valueEl = panel.querySelector('[name="requested_address"]');
      var noteEl = panel.querySelector('[name="request_note"]');
      var requestedValue = valueEl ? valueEl.value.trim() : '';
      var note = noteEl ? noteEl.value.trim() : '';

      if (!requestedValue) {
        setStatus(statusEl, i18n.requestFailed || 'Please enter the new value', true);
        return;
      }

      btn.disabled = true;
      setStatus(statusEl, '', false);

      postAccount('request_account_change', {
        field: field,
        requested_value: requestedValue,
        message: note,
      })
        .then(function () {
          setStatus(statusEl, i18n.requestSubmitted || 'Request submitted');
          if (valueEl) valueEl.value = '';
          if (noteEl) noteEl.value = '';
        })
        .catch(function (err) {
          setStatus(statusEl, err.message || i18n.networkError || 'Network error', true);
        })
        .finally(function () {
          btn.disabled = false;
        });
    });
  });
})();
