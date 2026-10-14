/**
 * Standing Orders Manager editor aids.
 * Copy arrives from standing_orders_manager.php as window.standingOrdersEditorCopy.
 */
(function () {
    var incoming = window.standingOrdersEditorCopy || {};
    var t = {
        dayTotal: incoming.dayTotal || '',
        unsaved: incoming.unsaved || '',
        saved: incoming.saved || '',
        saveFailed: incoming.saveFailed || '',
        leave: incoming.leave || ''
    };
    var allowLeave = false;
    var saveTimer = 0;

    var style = document.createElement('style');
    style.setAttribute('data-standing-orders-editor', '1');
    style.textContent = [
        '.som-container .customer-orders,',
        '.som-container .customer-full-details { overflow-x: auto; }',
        '.som-container .days-header,',
        '.som-container .product-row,',
        '.som-container .som-day-totals {',
        '  grid-template-columns: minmax(9rem, 14rem) repeat(var(--som-days, 7), minmax(4.5rem, 1fr)) minmax(4.5rem, 5.5rem);',
        '  min-width: calc(9rem + (var(--som-days, 7) * 4.5rem) + 4.5rem);',
        '}',
        '.som-container .quantity-cell { display: flex; justify-content: center; }',
        '.som-container .quantity-cell,',
        '.som-container .day-column,',
        '.som-container .total-cell,',
        '.som-container .product-info,',
        '.som-container .som-day-total,',
        '.som-container .som-week-total { min-width: 0; }',
        '.som-container .quantity-input {',
        '  width: 100%;',
        '  max-width: 6.5rem;',
        '  min-width: 0;',
        '  min-height: 3rem;',
        '  height: 3rem;',
        '  box-sizing: border-box;',
        '  font-size: 1.35rem;',
        '  font-weight: 700;',
        '  text-align: center;',
        '  padding: 0.2rem;',
        '  border-width: 2px;',
        '  border-radius: 8px;',
        '}',
        '.som-container .quantity-input:focus {',
        '  border-color: #1d4e89;',
        '  outline: 3px solid rgba(29, 78, 137, 0.35);',
        '  outline-offset: 1px;',
        '}',
        '.som-container .total-qty { font-size: 1.35rem; }',
        '.som-container .som-day-totals {',
        '  display: grid;',
        '  gap: 8px;',
        '  align-items: center;',
        '  margin: 0 0 12px;',
        '  padding: 8px 12px;',
        '  background: #e7f1fb;',
        '  border: 1px solid #b9d3ee;',
        '  border-radius: 8px;',
        '  font-weight: 700;',
        '}',
        '.som-container .som-day-total,',
        '.som-container .som-week-total {',
        '  text-align: center;',
        '  font-size: 1.2rem;',
        '  font-variant-numeric: tabular-nums;',
        '}',
        '.som-container .som-week-total { color: #1d4e89; }',
        '.som-dirty-banner[hidden],',
        '.som-save-confirm[hidden] { display: none !important; }',
        '.som-dirty-banner {',
        '  margin: 0 0 14px;',
        '  padding: 12px 14px;',
        '  border-radius: 8px;',
        '  background: #fff6db;',
        '  border: 2px solid #b58105;',
        '  color: #6a4b00;',
        '  font-weight: 700;',
        '}',
        '.som-save-confirm {',
        '  position: fixed;',
        '  left: 50%;',
        '  bottom: 16px;',
        '  transform: translateX(-50%);',
        '  z-index: 80;',
        '  width: min(36rem, calc(100% - 24px));',
        '  margin: 0;',
        '  padding: 14px 16px;',
        '  border-radius: 10px;',
        '  font-weight: 700;',
        '  font-size: 1.05rem;',
        '  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.18);',
        '}',
        '.som-save-confirm.is-saved { background: #e5f6ea; border: 2px solid #1e7a3a; color: #145c2c; }',
        '.som-save-confirm.is-error { background: #fdecea; border: 2px solid #a12622; color: #7a1b18; }',
        '@media (max-width: 420px) {',
        '  .som-container { padding: 10px; }',
        '  .som-header { flex-direction: column; align-items: stretch; gap: 10px; }',
        '  .som-header h1 { font-size: 1.4rem; }',
        '  .som-actions { flex-wrap: wrap; }',
        '  .som-actions .btn, .som-actions .auto-save-status { min-height: 44px; }',
        '  .coverage-table-wrap { overflow-x: auto; }',
        '  .som-container .customer-orders,',
        '  .som-container .customer-full-details { padding: 8px; }',
        '  .som-container .dough-products-container,',
        '  .som-container .product-line-container { padding-left: 0; padding-right: 0; }',
        '  .som-container .days-header,',
        '  .som-container .product-row,',
        '  .som-container .som-day-totals {',
        '    width: 100%;',
        '    min-width: 0;',
        '    gap: 6px;',
        '    padding-left: 2px;',
        '    padding-right: 2px;',
        '    grid-template-columns: repeat(var(--som-days, 7), minmax(2.75rem, 1fr)) minmax(2.4rem, 2.8rem);',
        '  }',
        '  .som-container .days-header .product-column,',
        '  .som-container .product-info,',
        '  .som-container .som-day-totals-label {',
        '    grid-column: 1 / -1;',
        '    position: static;',
        '    text-align: left;',
        '    background: transparent;',
        '  }',
        '  .som-container .quantity-input {',
        '    max-width: none;',
        '    min-height: 3rem;',
        '    height: 3rem;',
        '    font-size: 1.25rem;',
        '    padding: 0;',
        '  }',
        '  .som-container .product-name { font-size: 1rem; }',
        '}'
    ].join('\n');
    document.head.appendChild(style);

    function qtyValue(input) {
        return parseInt(input.value, 10) || 0;
    }

    function isDirty() {
        var inputs = document.querySelectorAll('.quantity-input');
        for (var i = 0; i < inputs.length; i++) {
            var original = parseInt(inputs[i].dataset.original, 10) || 0;
            if (qtyValue(inputs[i]) !== original) {
                return true;
            }
        }
        var status = document.getElementById('auto-save-status');
        return !!(status && status.classList.contains('saving'));
    }

    function refreshDayTotals(root) {
        var totals = root.querySelector('.som-day-totals');
        if (!totals) {
            return;
        }
        var label = totals.querySelector('.som-day-totals-label');
        if (label) {
            label.textContent = t.dayTotal;
        }
        var sums = {};
        var week = 0;
        root.querySelectorAll('.quantity-input').forEach(function (input) {
            var day = input.dataset.day;
            var qty = qtyValue(input);
            sums[day] = (sums[day] || 0) + qty;
            week += qty;
        });
        totals.querySelectorAll('.som-day-total').forEach(function (cell) {
            cell.textContent = String(sums[cell.dataset.day] || 0);
        });
        var weekCell = totals.querySelector('.som-week-total');
        if (weekCell) {
            weekCell.textContent = String(week);
        }
    }

    function refreshAllDayTotals() {
        document.querySelectorAll('.customer-orders, .customer-full-details').forEach(refreshDayTotals);
    }

    function updateDirtyBanner() {
        var banner = document.getElementById('som-dirty-banner');
        if (!banner) {
            return;
        }
        var dirty = isDirty();
        banner.hidden = !dirty;
        if (dirty) {
            banner.textContent = t.unsaved;
        }
    }

    function showSaveConfirm(ok) {
        var el = document.getElementById('som-save-confirm');
        if (!el) {
            return;
        }
        el.hidden = false;
        el.className = 'som-save-confirm ' + (ok ? 'is-saved' : 'is-error');
        el.textContent = ok ? t.saved : t.saveFailed;
        window.clearTimeout(saveTimer);
        if (ok) {
            saveTimer = window.setTimeout(function () {
                el.hidden = true;
            }, 6000);
        }
        updateDirtyBanner();
    }

    function visibleInputs() {
        return Array.prototype.filter.call(document.querySelectorAll('.quantity-input'), function (input) {
            return input.getClientRects().length > 0 && !input.disabled;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        refreshAllDayTotals();
        document.querySelectorAll('.quantity-input').forEach(function (input) {
            input.setAttribute('inputmode', 'numeric');
        });
        document.querySelectorAll('.day-copy-btn, .days-header .copy-all-btn, .total-cell .quick-clear').forEach(function (button) {
            button.tabIndex = -1;
        });

        document.addEventListener('input', function (event) {
            var input = event.target.closest ? event.target.closest('.quantity-input') : null;
            if (!input) {
                return;
            }
            var root = input.closest('.customer-orders, .customer-full-details');
            if (root) {
                refreshDayTotals(root);
            }
            var confirmEl = document.getElementById('som-save-confirm');
            if (confirmEl && !confirmEl.hidden && confirmEl.classList.contains('is-saved')) {
                confirmEl.hidden = true;
            }
            updateDirtyBanner();
        });

        var status = document.getElementById('auto-save-status');
        if (status) {
            new MutationObserver(function () {
                if (status.classList.contains('success')) {
                    showSaveConfirm(true);
                } else if (status.classList.contains('error')) {
                    showSaveConfirm(false);
                } else {
                    updateDirtyBanner();
                }
            }).observe(status, { attributes: true, attributeFilter: ['class'], subtree: true, childList: true, characterData: true });
        }

        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                Array.prototype.forEach.call(mutation.addedNodes, function (node) {
                    if (!node || node.nodeType !== 1 || !node.classList) {
                        return;
                    }
                    if (node.classList.contains('notification-success')) {
                        showSaveConfirm(true);
                    } else if (node.classList.contains('notification-error')) {
                        showSaveConfirm(false);
                    }
                });
            });
        }).observe(document.body, { childList: true });

        document.querySelectorAll('.customer-section, .customer-summary-card').forEach(function (section) {
            new MutationObserver(function () {
                if (section.classList.contains('loading')) {
                    return;
                }
                var grid = section.querySelector('.customer-orders, .customer-full-details');
                if (grid) {
                    refreshDayTotals(grid);
                }
            }).observe(section, { attributes: true, attributeFilter: ['class'] });
        });

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Tab' || event.altKey || event.metaKey || event.ctrlKey) {
                return;
            }
            var current = event.target;
            if (!current || !current.classList || !current.classList.contains('quantity-input')) {
                return;
            }
            var inputs = visibleInputs();
            var index = inputs.indexOf(current);
            if (index < 0) {
                return;
            }
            var nextIndex = index + (event.shiftKey ? -1 : 1);
            if (nextIndex < 0 || nextIndex >= inputs.length) {
                return;
            }
            event.preventDefault();
            inputs[nextIndex].focus();
            if (typeof inputs[nextIndex].select === 'function') {
                inputs[nextIndex].select();
            }
        });

        document.addEventListener('click', function (event) {
            var toggle = event.target.closest ? event.target.closest('#week-view-toggle') : null;
            if (!toggle) {
                return;
            }
            allowLeave = true;
            window.setTimeout(function () {
                allowLeave = false;
            }, 0);
        }, true);

        document.addEventListener('click', function (event) {
            if (allowLeave || !isDirty()) {
                return;
            }
            var link = event.target.closest ? event.target.closest('a[href]') : null;
            if (!link || link.target === '_blank' || link.hasAttribute('download')) {
                return;
            }
            var href = link.getAttribute('href') || '';
            if (href === '' || href.charAt(0) === '#') {
                return;
            }
            if (!window.confirm(t.leave)) {
                event.preventDefault();
                event.stopPropagation();
                return;
            }
            allowLeave = true;
        }, true);

        window.addEventListener('beforeunload', function (event) {
            if (allowLeave || !isDirty()) {
                return;
            }
            event.preventDefault();
            event.returnValue = t.leave;
        });
    });
}());
