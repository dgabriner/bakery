(function () {
  var cfg = window.__BAKERY_DELIVERY__ || {};
  var i18n = window.__BAKERY_I18N__ || {};
  var toast = document.getElementById('toast');
  var toastTimer;
  var confirmPanel = document.getElementById('confirm-panel');
  var confirmTitle = document.getElementById('confirm-title');
  var confirmLines = document.getElementById('confirm-lines');
  var confirmUnchanged = document.getElementById('confirm-unchanged');
  var confirmDismiss = document.getElementById('confirm-dismiss');

  function showToast(msg, isError) {
    if (!toast) return;
    toast.textContent = msg;
    toast.className = 'toast' + (isError ? ' error' : '');
    toast.style.display = 'block';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toast.style.display = 'none'; }, 2800);
  }

  function hideConfirmation() {
    if (confirmPanel) confirmPanel.hidden = true;
  }

  function showConfirmation(conf) {
    if (!confirmPanel) {
      showToast(i18n.saved || 'Saved');
      return;
    }
    var title = (conf && conf.title) ? String(conf.title) : '';
    var lines = (conf && conf.lines) ? conf.lines.filter(function (line) { return line; }) : [];
    var unchanged = (conf && conf.unchanged) ? String(conf.unchanged) : '';
    if (!title && !lines.length && !unchanged) {
      showToast(i18n.saved || 'Saved');
      return;
    }
    if (confirmTitle) {
      confirmTitle.textContent = title;
      confirmTitle.hidden = !title;
    }
    if (confirmLines) {
      confirmLines.innerHTML = '';
      lines.forEach(function (line) {
        var li = document.createElement('li');
        li.textContent = line;
        confirmLines.appendChild(li);
      });
      confirmLines.hidden = !lines.length;
    }
    if (confirmUnchanged) {
      confirmUnchanged.textContent = unchanged;
      confirmUnchanged.hidden = !unchanged;
    }
    confirmPanel.hidden = false;
  }

  if (confirmDismiss) {
    confirmDismiss.addEventListener('click', hideConfirmation);
  }
  if (confirmPanel) {
    confirmPanel.addEventListener('click', function (e) {
      if (e.target === confirmPanel) hideConfirmation();
    });
  }

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
  }

  function postAction(action, extra) {
    var body = new URLSearchParams({ action: action, csrf_token: csrfToken(), date: cfg.date });
    if (extra) {
      Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });
    }
    return fetch('customer_portal_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  if (cfg.editable) {
    document.querySelectorAll('.comparison-row').forEach(function (row) {
      var productId = row.getAttribute('data-product-id');
      var valueEl = row.querySelector('.qty-value');
      if (!valueEl) return;

      row.querySelectorAll('.qty-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var delta = parseInt(btn.getAttribute('data-delta'), 10);
          var qty = Math.max(0, parseInt(valueEl.textContent, 10) + delta);
          valueEl.textContent = qty;
          postAction('save_daily_item', { product_id: productId, quantity: qty })
            .then(function (res) {
              if (res.success) {
                if (res.confirmation) showConfirmation(res.confirmation);
                else showToast(i18n.saved);
                var diffEl = row.querySelector('.col-diff');
                if (diffEl && res.result) {
                  var diff = res.result.diff_from_regular;
                  diffEl.textContent = diff > 0 ? '+' + diff : (diff < 0 ? String(diff) : '—');
                  diffEl.className = 'col-diff diff' + (diff === 0 ? ' diff--zero' : '');
                }
              } else {
                showToast(res.error || i18n.save_failed, true);
                location.reload();
              }
            })
            .catch(function () { showToast(i18n.network_error, true); location.reload(); });
        });
      });
    });

    var addSel = document.getElementById('add-daily-product');
    if (addSel) {
      addSel.addEventListener('change', function () {
        var productId = addSel.value;
        if (!productId) return;
        postAction('save_daily_item', { product_id: productId, quantity: 1 })
          .then(function (res) {
            if (res.success) location.reload();
            else { showToast(res.error || i18n.save_failed, true); addSel.value = ''; }
          })
          .catch(function () { showToast(i18n.network_error, true); addSel.value = ''; });
      });
    }

    var btnSkip = document.getElementById('btn-skip');
    if (btnSkip) {
      btnSkip.addEventListener('click', function () {
        if (!confirm('Skip this delivery? Your regular order will not change.')) return;
        btnSkip.disabled = true;
        postAction('skip_delivery')
          .then(function (res) {
            if (res.success) {
              if (res.confirmation) showConfirmation(res.confirmation);
              setTimeout(function () { location.reload(); }, 1500);
            } else {
              showToast(res.error || i18n.save_failed, true);
              btnSkip.disabled = false;
            }
          })
          .catch(function () { showToast(i18n.network_error, true); btnSkip.disabled = false; });
      });
    }

    var btnUnskip = document.getElementById('btn-unskip');
    if (btnUnskip) {
      btnUnskip.addEventListener('click', function () {
        btnUnskip.disabled = true;
        postAction('unskip_delivery')
          .then(function (res) {
            if (res.success) location.reload();
            else { showToast(res.error || i18n.save_failed, true); btnUnskip.disabled = false; }
          })
          .catch(function () { showToast(i18n.network_error, true); btnUnskip.disabled = false; });
      });
    }
  }

  if (cfg.locked) {
    var reqForm = document.getElementById('request-change-form');
    if (reqForm) {
      reqForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var msg = reqForm.querySelector('[name="message"]').value;
        var btn = reqForm.querySelector('button[type="submit"]');
        btn.disabled = true;
        postAction('request_change', { message: msg })
          .then(function (res) {
            if (res.success) {
              if (res.confirmation) showConfirmation(res.confirmation);
              reqForm.reset();
            } else {
              showToast(res.error || i18n.save_failed, true);
            }
            btn.disabled = false;
          })
          .catch(function () { showToast(i18n.network_error, true); btn.disabled = false; });
      });
    }
  }
})();
