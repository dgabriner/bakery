/**
 * Staff alerts bell — fetches the live alert summary and renders the nav panel.
 * Progressive enhancement: without JS, or when the endpoint is unavailable,
 * the toggle stays hidden and nothing else changes.
 */
(function () {
  'use strict';

  var STALE_MS = 60000;

  function init(root) {
    var endpoint = root.getAttribute('data-endpoint');
    var toggle = root.querySelector('.bakery-nav__alerts-toggle');
    var badge = root.querySelector('.bakery-nav__alerts-badge');
    var panel = root.querySelector('.bakery-nav__alerts-panel');
    var list = root.querySelector('.bakery-nav__alerts-list');
    if (!endpoint || !toggle || !badge || !panel || !list) return;

    var labels = {};
    var lastFetched = 0;
    var inFlight = false;
    var loaded = false;

    function el(tag, className, text) {
      var node = document.createElement(tag);
      if (className) node.className = className;
      if (text !== undefined && text !== null) node.textContent = String(text);
      return node;
    }

    function setBadge(counts) {
      var critical = counts && parseInt(counts.critical, 10) || 0;
      var total = counts && parseInt(counts.total, 10) || 0;
      if (total <= 0) {
        badge.hidden = true;
        badge.textContent = '';
        return;
      }
      badge.hidden = false;
      badge.textContent = critical > 0 ? String(critical) : String(total);
      badge.classList.toggle('bakery-nav__alerts-badge--critical', critical > 0);
    }

    function renderAlert(item) {
      var li = el('li', 'bakery-nav__alerts-item bakery-nav__alerts-item--' + (item.severity || 'info'));
      var link = document.createElement('a');
      link.className = 'bakery-nav__alerts-link';
      if (item.href) link.setAttribute('href', item.href);

      var top = el('span', 'bakery-nav__alerts-top');
      top.appendChild(el('span', 'bakery-nav__alerts-day', item.day_label || ''));
      if (item.assigned) {
        top.appendChild(el('span', 'bakery-nav__alerts-assigned', labels.assigned_to_you || ''));
      }
      li.appendChild(top);

      var titleText = item.title || '';
      if (item.count !== null && item.count !== undefined && item.count !== '') {
        titleText += ' (' + item.count + ')';
      }
      var body = el('div', 'bakery-nav__alerts-copy');
      body.appendChild(el('span', 'bakery-nav__alerts-item-title', titleText));
      if (item.detail) {
        body.appendChild(el('span', 'bakery-nav__alerts-item-detail', item.detail));
      }
      link.appendChild(body);

      if (item.assigned && item.due_label) {
        link.appendChild(el('span', 'bakery-nav__alerts-due', item.due_label));
      }
      li.appendChild(link);
      list.appendChild(li);
    }

    function render(data) {
      list.textContent = '';
      labels = data.labels || labels;
      var alerts = (data && data.alerts) || [];
      if (!alerts.length) {
        var empty = el('li', 'bakery-nav__alerts-empty', (data.labels && data.labels.all_clear) || '');
        list.appendChild(empty);
      } else {
        alerts.forEach(renderAlert);
      }
      setBadge(data.counts);
      toggle.hidden = false;
      loaded = true;
      lastFetched = Date.now();
    }

    function fetchSummary(force) {
      if (inFlight) return;
      if (!force && loaded && Date.now() - lastFetched < STALE_MS) return;
      inFlight = true;
      fetch(endpoint, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
      }).then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      }).then(function (data) {
        if (data && data.success) render(data);
      }).catch(function () {
        // Leave the bell hidden — never show a broken control.
      }).finally(function () {
        inFlight = false;
      });
    }

    function setOpen(open) {
      panel.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) fetchSummary(true);
    }

    toggle.addEventListener('click', function (event) {
      event.stopPropagation();
      setOpen(panel.hidden);
    });

    document.addEventListener('click', function (event) {
      if (!panel.hidden && !root.contains(event.target)) setOpen(false);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !panel.hidden) {
        setOpen(false);
        toggle.focus();
      }
    });

    fetchSummary(false);
  }

  function boot() {
    document.querySelectorAll('.js-staff-alerts').forEach(init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
