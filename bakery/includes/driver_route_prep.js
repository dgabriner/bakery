/**
 * Night-before route prep: add, remove, and auto-save reorder on a phone.
 */
(function () {
  'use strict';

  function t(key, fallback, params) {
    var di = window.__DRIVER_PAGE_I18N__ || {};
    var value = di[key];
    var text = value == null || value === '' ? (fallback || key) : String(value);
    if (params) {
      Object.keys(params).forEach(function (name) {
        text = text.split(':' + name).join(String(params[name]));
        text = text.split('__' + name.toUpperCase() + '__').join(String(params[name]));
      });
    }
    return text;
  }

  function root() {
    return document.getElementById('routePrepRoot');
  }

  function toast(message) {
    var el = document.getElementById('routeSuccessToast');
    if (!el || !message) return;
    el.hidden = false;
    el.textContent = message;
    el.classList.add('is-visible');
    window.setTimeout(function () {
      el.classList.remove('is-visible');
    }, 2200);
  }

  async function post(action, extra) {
    var node = root();
    if (!node) throw new Error(t('route_order_error', 'Could not update the route.'));
    var body = 'action=' + encodeURIComponent(action)
      + '&driver_id=' + encodeURIComponent(node.getAttribute('data-driver-id') || '0')
      + '&date=' + encodeURIComponent(node.getAttribute('data-date') || '');
    Object.keys(extra || {}).forEach(function (key) {
      body += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(extra[key]);
    });
    var response = await fetch('complete_delivery.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    });
    var data = null;
    try {
      data = await response.json();
    } catch (ignore) {
      throw new Error(t('route_order_error', 'Could not update the route.'));
    }
    if (!response.ok || !data) {
      throw new Error((data && (data.error || data.message)) || t('route_order_error', 'Could not update the route.'));
    }
    return data;
  }

  function movableStops() {
    return Array.prototype.slice.call(document.querySelectorAll('#routePrepList .route-prep-stop:not(.route-prep-stop--locked):not(.route-prep-stop--skipped)'));
  }

  function paintMoveButtons() {
    var stops = movableStops();
    stops.forEach(function (el, index) {
      var up = el.querySelector('.route-prep-move-up');
      var down = el.querySelector('.route-prep-move-down');
      if (up) up.disabled = index === 0;
      if (down) down.disabled = index === stops.length - 1;
      var num = el.querySelector('.route-prep-stop-num');
      var order = el.getAttribute('data-route-order') || String(index + 1);
      if (num) num.textContent = order;
    });
  }

  var savingOrder = false;

  async function saveCurrentOrder() {
    if (savingOrder) return;
    var ids = movableStops().map(function (el) {
      return parseInt(el.getAttribute('data-daily-order-id') || '0', 10);
    }).filter(function (id) { return id > 0; });
    if (ids.length < 2) return;
    savingOrder = true;
    document.body.classList.add('route-prep-saving');
    try {
      var data = await post('reorder_route', { order_ids: ids.join(',') });
      (data.stops || []).forEach(function (row) {
        var el = document.querySelector('#routePrepList .route-prep-stop[data-daily-order-id="' + row.daily_order_id + '"]');
        if (!el) return;
        el.setAttribute('data-route-order', String(row.route_order));
      });
      paintMoveButtons();
      if (window.DriverRouteMap && typeof window.DriverRouteMap.refresh === 'function') {
        window.DriverRouteMap.refresh();
      }
    } finally {
      savingOrder = false;
      document.body.classList.remove('route-prep-saving');
    }
  }

  async function moveStop(stopEl, direction) {
    var list = document.getElementById('routePrepList');
    if (!list || !stopEl) return;
    var stops = movableStops();
    var index = stops.indexOf(stopEl);
    var swapWith = direction === 'up' ? stops[index - 1] : stops[index + 1];
    if (!swapWith) return;
    if (direction === 'up') {
      list.insertBefore(stopEl, swapWith);
    } else {
      list.insertBefore(swapWith, stopEl);
    }
    paintMoveButtons();
    try {
      await saveCurrentOrder();
      toast(t('route_order_saved', 'Route updated.'));
    } catch (err) {
      toast(err.message || t('route_order_error', 'Could not update the route.'));
    }
  }

  async function removeStop(stopEl) {
    var name = stopEl.getAttribute('data-customer-name') || '';
    var confirmText = t('prep_remove_confirm', 'Remove :name from your route? The order stays so packing can still fill it.', { name: name });
    if (!window.confirm(confirmText)) return;
    try {
      var data = await post('plan_remove_stop', {
        daily_order_id: stopEl.getAttribute('data-daily-order-id') || '0'
      });
      toast(data.message || t('prep_removed', 'Stop removed from your route.'));
      window.setTimeout(function () { window.location.reload(); }, 350);
    } catch (err) {
      toast(err.message || t('route_order_error', 'Could not update the route.'));
    }
  }

  function openSheet() {
    var sheet = document.getElementById('routePrepSheet');
    if (!sheet) return;
    sheet.hidden = false;
    document.body.classList.add('route-prep-sheet-open');
    var input = document.getElementById('routePrepSearch');
    if (input) {
      input.value = '';
      window.setTimeout(function () { input.focus(); }, 50);
    }
    loadCandidates('');
  }

  function closeSheet() {
    var sheet = document.getElementById('routePrepSheet');
    if (sheet) sheet.hidden = true;
    document.body.classList.remove('route-prep-sheet-open');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function candidateButton(row) {
    if (row.state === 'mine') {
      return '<span class="route-prep-candidate-state">' + escapeHtml(t('prep_already', 'Already on your route', { name: row.customer_name })) + '</span>';
    }
    if (row.state === 'other') {
      return '<button type="button" class="route-prep-candidate-btn" data-take="1">' + escapeHtml(t('prep_take', 'Take')) + '</button>';
    }
    return '<button type="button" class="route-prep-candidate-btn">' + escapeHtml(t('prep_add_this', 'Add')) + '</button>';
  }

  function renderGroup(title, rows) {
    if (!rows || !rows.length) return '';
    var html = '<section class="route-prep-group"><h3>' + escapeHtml(title) + '</h3>';
    rows.forEach(function (row) {
      var meta = [];
      if (row.zone) meta.push(row.zone);
      if (row.pieces > 0) meta.push(t('prep_pieces', ':count pcs', { count: row.pieces, COUNT: row.pieces }));
      if (row.state === 'other' && row.assigned_driver_name) {
        meta.push(t('prep_on_other', ':name is on :driver\'s route', {
          name: row.customer_name,
          driver: row.assigned_driver_name
        }));
      }
      html += '<article class="route-prep-candidate" data-customer-id="' + String(row.customer_id) + '" data-customer-name="' + escapeHtml(row.customer_name) + '" data-other-driver="' + escapeHtml(row.assigned_driver_name || '') + '">'
        + '<div><strong>' + escapeHtml(row.customer_name) + '</strong>'
        + '<p>' + escapeHtml(row.customer_address || '') + '</p>'
        + (meta.length ? '<p class="route-prep-candidate-meta">' + escapeHtml(meta.join(' · ')) + '</p>' : '')
        + '</div>' + candidateButton(row) + '</article>';
    });
    return html + '</section>';
  }

  var searchTimer = 0;
  var searchSeq = 0;

  async function loadCandidates(query) {
    var box = document.getElementById('routePrepResults');
    if (!box) return;
    var seq = ++searchSeq;
    box.innerHTML = '<p class="route-prep-results-status">' + escapeHtml((window.__BAKERY_I18N__ && window.__BAKERY_I18N__.loading) || '…') + '</p>';
    try {
      var data = await post('plan_search', { q: query || '' });
      if (seq !== searchSeq) return;
      var html = '';
      html += renderGroup(t('prep_unassigned', 'Needs a driver'), data.unassigned || []);
      html += renderGroup(t('prep_usual', 'Usually on your route'), data.usual || []);
      html += renderGroup(t('prep_matches', 'Matching stores'), data.matches || []);
      if (!html) {
        html = '<p class="route-prep-results-status">' + escapeHtml(
          query ? t('prep_no_matches', 'No stores match that search.') : t('prep_no_suggestions', 'Search for a store to add it to this route.')
        ) + '</p>';
      }
      box.innerHTML = html;
    } catch (err) {
      if (seq !== searchSeq) return;
      box.innerHTML = '<p class="route-prep-results-status">' + escapeHtml(err.message || t('route_order_error', 'Could not update the route.')) + '</p>';
    }
  }

  async function addCandidate(card, take) {
    var customerId = card.getAttribute('data-customer-id') || '0';
    var name = card.getAttribute('data-customer-name') || '';
    var other = card.getAttribute('data-other-driver') || '';
    if (take) {
      var ok = window.confirm(t('prep_take_confirm', ':name is on :driver\'s route. Take this stop?', {
        name: name,
        driver: other
      }));
      if (!ok) return;
    }
    try {
      var data = await post('plan_add_stop', {
        customer_id: customerId,
        take: take ? '1' : '0'
      });
      if (!data.success && data.code === 'on_other_route') {
        var confirmed = window.confirm(t('prep_take_confirm', ':name is on :driver\'s route. Take this stop?', {
          name: data.customer_name || name,
          driver: data.other_driver_name || other
        }));
        if (!confirmed) return;
        data = await post('plan_add_stop', { customer_id: customerId, take: '1' });
      }
      if (!data.success) {
        toast(data.error || data.message || t('route_order_error', 'Could not update the route.'));
        return;
      }
      toast(data.message || t('prep_added', 'Added :name', { name: name }));
      window.setTimeout(function () { window.location.reload(); }, 350);
    } catch (err) {
      toast(err.message || t('route_order_error', 'Could not update the route.'));
    }
  }

  function bind() {
    if (!root()) return;
    paintMoveButtons();

    document.addEventListener('click', function (e) {
      if (e.target.closest('#routePrepAddBtn, #routePrepAddDockBtn')) {
        openSheet();
        return;
      }
      if (e.target.closest('#routePrepSheetClose')) {
        closeSheet();
        return;
      }
      var up = e.target.closest('.route-prep-move-up');
      if (up) {
        moveStop(up.closest('.route-prep-stop'), 'up');
        return;
      }
      var down = e.target.closest('.route-prep-move-down');
      if (down) {
        moveStop(down.closest('.route-prep-stop'), 'down');
        return;
      }
      var remove = e.target.closest('.route-prep-remove');
      if (remove) {
        var stopEl = remove.closest('.route-prep-stop');
        if (stopEl) removeStop(stopEl);
        return;
      }
      var addBtn = e.target.closest('.route-prep-candidate-btn');
      if (addBtn) {
        var card = addBtn.closest('.route-prep-candidate');
        if (card) addCandidate(card, addBtn.getAttribute('data-take') === '1');
      }
    });

    var search = document.getElementById('routePrepSearch');
    if (search) {
      search.addEventListener('input', function () {
        window.clearTimeout(searchTimer);
        var value = search.value;
        searchTimer = window.setTimeout(function () {
          loadCandidates(value);
        }, 220);
      });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
