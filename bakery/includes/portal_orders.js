(function () {
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
    var body = new URLSearchParams({ action: action, csrf_token: csrfToken() });
    if (extra) {
      Object.keys(extra).forEach(function (k) { body.append(k, extra[k]); });
    }
    return fetch('customer_portal_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
      body: body.toString()
    }).then(function (r) { return r.json(); });
  }

  document.querySelectorAll('[data-pause]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      postAction('pause_week', { week_start: btn.getAttribute('data-pause') })
        .then(function (res) {
          if (res.success) location.reload();
          else { showToast(res.error || i18n.could_not_pause, true); btn.disabled = false; }
        })
        .catch(function () { showToast(i18n.network_error, true); btn.disabled = false; });
    });
  });

  document.querySelectorAll('[data-unpause]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      postAction('unpause_week', { week_start: btn.getAttribute('data-unpause') })
        .then(function (res) {
          if (res.success) location.reload();
          else { showToast(res.error || i18n.could_not_resume, true); btn.disabled = false; }
        })
        .catch(function () { showToast(i18n.network_error, true); btn.disabled = false; });
    });
  });

  document.querySelectorAll('[data-remove-pause]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      btn.disabled = true;
      postAction('remove_pause_range', { pause_id: btn.getAttribute('data-remove-pause') })
        .then(function (res) {
          if (res.success) {
            if (res.confirmation) showConfirmation(res.confirmation);
            setTimeout(function () { location.reload(); }, 1200);
          } else {
            showToast(res.error || i18n.save_failed, true);
            btn.disabled = false;
          }
        })
        .catch(function () { showToast(i18n.network_error, true); btn.disabled = false; });
    });
  });

  var pauseRangeForm = document.getElementById('pause-range-form');
  if (pauseRangeForm) {
    pauseRangeForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var fd = new FormData(pauseRangeForm);
      var btn = pauseRangeForm.querySelector('button[type="submit"]');
      btn.disabled = true;
      postAction('pause_range', {
        pause_start: fd.get('pause_start'),
        pause_end: fd.get('pause_end')
      }).then(function (res) {
        if (res.success) {
          if (res.confirmation) showConfirmation(res.confirmation);
          setTimeout(function () { location.reload(); }, 1500);
        } else {
          showToast(res.error || i18n.could_not_pause, true);
          btn.disabled = false;
        }
      }).catch(function () { showToast(i18n.network_error, true); btn.disabled = false; });
    });
  }

  function parseQty(inputEl) {
    var n = parseInt(inputEl.value, 10);
    return isNaN(n) ? 0 : Math.max(0, n);
  }

  function setQty(inputEl, qty) {
    inputEl.value = Math.max(0, qty);
  }

  function removeRowFromDom(row) {
    var section = row.closest('.day-section');
    row.remove();
    if (!section || section.querySelector('.order-row')) return;
    var empty = document.createElement('div');
    empty.className = 'empty-day';
    empty.textContent = section.getAttribute('data-empty-text') || '';
    var addRow = section.querySelector('.add-row');
    if (addRow) section.insertBefore(empty, addRow);
    else section.appendChild(empty);
  }

  document.querySelectorAll('.order-row').forEach(function (row) {
    var productId = row.getAttribute('data-product-id');
    var day = row.getAttribute('data-day');
    var inputEl = row.querySelector('.qty-input');
    if (!inputEl) return;

    var saving = false;
    var lastSaved = parseQty(inputEl);

    function commitQty(qty) {
      qty = Math.max(0, qty);
      setQty(inputEl, qty);
      if (qty === lastSaved || saving) return;

      saving = true;
      row.classList.add('qty-saving');

      postAction('save_standing', { product_id: productId, day_of_week: day, quantity: qty })
        .then(function (res) {
          if (res.success) {
            lastSaved = qty;
            if (qty === 0) {
              removeRowFromDom(row);
            }
            if (res.confirmation) showConfirmation(res.confirmation);
            else showToast(i18n.saved);
          } else {
            setQty(inputEl, lastSaved);
            showToast(res.error || i18n.save_failed, true);
          }
        })
        .catch(function () {
          setQty(inputEl, lastSaved);
          showToast(i18n.network_error, true);
        })
        .finally(function () {
          saving = false;
          if (row.parentNode) row.classList.remove('qty-saving');
        });
    }

    row.querySelectorAll('.qty-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var delta = parseInt(btn.getAttribute('data-delta'), 10);
        commitQty(parseQty(inputEl) + delta);
      });
    });

    var removeBtn = row.querySelector('.qty-remove');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        commitQty(0);
      });
    }

    inputEl.addEventListener('blur', function () {
      var qty = parseQty(inputEl);
      if (inputEl.value.trim() === '') {
        setQty(inputEl, lastSaved);
        return;
      }
      commitQty(qty);
    });

    inputEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        inputEl.blur();
      }
    });

    var controls = row.querySelector('.qty-controls');
    if (controls) {
      controls.addEventListener('wheel', function (e) {
        e.preventDefault();
        var delta = e.deltaY < 0 ? 1 : -1;
        commitQty(parseQty(inputEl) + delta);
      }, { passive: false });
    }
  });

  document.querySelectorAll('.add-product-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var productId = sel.value;
      if (!productId) return;
      var day = sel.getAttribute('data-day');
      postAction('save_standing', { product_id: productId, day_of_week: day, quantity: 1 })
        .then(function (res) {
          if (res.success) location.reload();
          else { showToast(res.error || i18n.save_failed, true); sel.value = ''; }
        })
        .catch(function () { showToast(i18n.network_error, true); sel.value = ''; });
    });
  });
})();
