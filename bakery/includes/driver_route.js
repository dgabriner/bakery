(function () {
    var di = window.__DRIVER_PAGE_I18N__ || {};
    var boot = window.__DRIVER_ROUTE_BOOT__ || {};
    function mapsDirectionsUrl(address, lat, lng) {
        var q = '';
        var nlat = Number(lat);
        var nlng = Number(lng);
        if (Number.isFinite(nlat) && Number.isFinite(nlng)
            && nlat >= -90 && nlat <= 90
            && nlng >= -180 && nlng <= 180
            && !(nlat === 0 && nlng === 0)) {
            q = encodeURIComponent(nlat + ',' + nlng);
        } else if (address) {
            q = encodeURIComponent(address);
        }
        if (!q) return '';
        if (preferredMapsApp() === 'apple') {
            return 'https://maps.apple.com/?daddr=' + q + '&dirflg=d';
        }
        return 'https://www.google.com/maps/dir/?api=1&destination=' + q + '&travelmode=driving';
    }

    function applyNavigateLinks(root) {
        (root || document).querySelectorAll('.js-navigate-link[data-address]').forEach(function (link) {
            var url = mapsDirectionsUrl(
                link.getAttribute('data-address') || '',
                link.getAttribute('data-lat'),
                link.getAttribute('data-lng')
            );
            if (url) link.setAttribute('href', url);
        });
    }

    function phoneHref(phone) {
        var digits = String(phone || '').replace(/\D+/g, '');
        return digits ? 'tel:' + digits : '';
    }

    function escapeHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function openStopDeliveryFromEl(stop, opts) {
        opts = opts || {};
        if (document.body.classList.contains('route-adjust-open')) {
            return false;
        }
        if (!stop || !window.DriverDelivery || typeof window.DriverDelivery.openModal !== 'function') {
            return false;
        }
        var status = stop.getAttribute('data-status') || '';
        if (status === 'cancelled') {
            return false;
        }
        var photoBtn = stop.querySelector('.photo-complete-btn');
        var root = document.getElementById('driverRouteRoot');
        var dailyOrderId = parseInt(stop.getAttribute('data-daily-order-id') || '0', 10);
        if (!dailyOrderId) {
            return false;
        }
        window.DriverDelivery.openModal({
            driverId: parseInt(
                (photoBtn && photoBtn.getAttribute('data-driver-id')) ||
                (root && root.getAttribute('data-driver-id')) ||
                '0',
                10
            ),
            driverName:
                (photoBtn && photoBtn.getAttribute('data-driver-name')) ||
                (root && root.getAttribute('data-driver-name')) ||
                '',
            customerId: parseInt(stop.getAttribute('data-customer-id') || '0', 10),
            dailyOrderId: dailyOrderId,
            customerName: stop.getAttribute('data-customer-name') || '',
            address: stop.getAttribute('data-address') || '',
            date:
                (photoBtn && photoBtn.getAttribute('data-date')) ||
                (root && root.getAttribute('data-date')) ||
                '',
            photoMode:
                (photoBtn && photoBtn.getAttribute('data-photo-mode')) ||
                (status === 'delivered' ? 'review' : 'capture'),
            expandOrders: !!opts.expandOrders,
            autoOpenCamera: !!opts.autoOpenCamera,
            startStep: opts.startStep || ''
        });
        return true;
    }

    function tryOpenStopFromUrl() {
        var params = new URLSearchParams(window.location.search);
        if (params.get('open_stop') !== '1') {
            return;
        }
        var orderId = parseInt(params.get('daily_order_id') || '0', 10);
        if (!orderId) {
            return;
        }
        var expandOrders = params.get('view_orders') === '1';
        var stop = document.querySelector('.stop-item[data-daily-order-id="' + orderId + '"]');
        if (!stop || !openStopDeliveryFromEl(stop, { expandOrders: expandOrders })) {
            return;
        }
        params.delete('open_stop');
        params.delete('view_orders');
        params.delete('daily_order_id');
        var qs = params.toString();
        history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
    }

    function loadOrderDetails(container, dailyOrderId) {
        var loadingDiv = container.querySelector('.order-details-loading');
        var contentDiv = container.querySelector('.order-details-content');
        if (container.style.display === 'block') {
            container.style.display = 'none';
            return;
        }
        container.style.display = 'block';
        if (loadingDiv) loadingDiv.style.display = 'block';
        if (contentDiv) {
            contentDiv.style.display = 'none';
            contentDiv.innerHTML = '';
        }

        fetch('get_customer_order_details.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'daily_order_id=' + encodeURIComponent(String(dailyOrderId || 0))
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (loadingDiv) loadingDiv.style.display = 'none';
                if (!contentDiv) return;
                contentDiv.style.display = 'block';
                if (!data || !data.success) {
                    var err = (data && (data.error || data.message)) || di.order_details_error;
                    contentDiv.innerHTML = '<div class="no-products">Error: ' + escapeHtml(err) + '</div>';
                    return;
                }
                if (data.html) {
                    contentDiv.innerHTML = data.html;
                    return;
                }
                displayInlineOrderDetails(contentDiv, data.products || []);
            })
            .catch(function () {
                if (loadingDiv) loadingDiv.style.display = 'none';
                if (contentDiv) {
                    contentDiv.style.display = 'block';
                    contentDiv.innerHTML = '<div class="no-products">' + escapeHtml(di.order_details_load_error) + '</div>';
                }
            });
    }

    function displayInlineOrderDetails(container, products) {
        if (!products || !products.length) {
            container.innerHTML = '<div class="no-products">' + escapeHtml(di.no_products_found) + '</div>';
            return;
        }
        var html = '<div class="product-groups">';
        products.forEach(function (product) {
            var totalPrice = product.line_total
                ? parseFloat(product.line_total).toFixed(2)
                : (product.quantity * product.unit_price).toFixed(2);
            html +=
                '<div class="product-item">' +
                '<div class="product-details">' +
                '<div class="product-name">' + escapeHtml(product.product_name || di.unknown_product) + '</div>' +
                '<div class="product-meta">' +
                escapeHtml(product.product_line || product.product_line_name || di.other) +
                ' · ' +
                escapeHtml(product.dough_type || product.dough_type_name || di.standard) +
                '</div></div>' +
                '<div class="product-info">' +
                '<span class="quantity">×' + escapeHtml(String(product.quantity || 0)) + '</span>' +
                '<span class="total-price">$' + totalPrice + '</span>' +
                '</div></div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function getActiveStops() {
        return Array.prototype.slice.call(document.querySelectorAll('#stopList .stop-item')).filter(function (el) {
            var status = el.getAttribute('data-status') || '';
            return status !== 'delivered' && status !== 'cancelled' && status !== 'failed' && status !== 'office_flag';
        });
    }

    function parseStopLines(stopEl) {
        try {
            var raw = JSON.parse(stopEl.getAttribute('data-lines') || '[]');
            return Array.isArray(raw) ? raw : [];
        } catch (err) {
            return [];
        }
    }

    function fillTemplate(template, vars) {
        var out = String(template || '');
        Object.keys(vars || {}).forEach(function (key) {
            out = out.split(':' + key).join(String(vars[key]));
            out = out.split('__' + key.toUpperCase() + '__').join(String(vars[key]));
        });
        return out;
    }

    function photoButtonAttrs(stopEl) {
        var root = document.getElementById('driverRouteRoot');
        return ' data-driver-id="' + escapeHtml(root ? root.getAttribute('data-driver-id') : '0') + '"' +
            ' data-driver-name="' + escapeHtml(root ? root.getAttribute('data-driver-name') || '' : '') + '"' +
            ' data-customer-id="' + escapeHtml(stopEl.getAttribute('data-customer-id') || '0') + '"' +
            ' data-daily-order-id="' + escapeHtml(stopEl.getAttribute('data-daily-order-id') || '0') + '"' +
            ' data-assignment-id="' + escapeHtml(stopEl.getAttribute('data-assignment-id') || '0') + '"' +
            ' data-customer-name="' + escapeHtml(stopEl.getAttribute('data-customer-name') || '') + '"' +
            ' data-address="' + escapeHtml(stopEl.getAttribute('data-address') || '') + '"' +
            ' data-date="' + escapeHtml(root ? root.getAttribute('data-date') || '' : '') + '"';
    }

    function problemButtonAttrs(stopEl) {
        return ' data-daily-order-id="' + escapeHtml(stopEl.getAttribute('data-daily-order-id') || '0') + '"' +
            ' data-assignment-id="' + escapeHtml(stopEl.getAttribute('data-assignment-id') || '0') + '"' +
            ' data-customer-name="' + escapeHtml(stopEl.getAttribute('data-customer-name') || '') + '"';
    }

    function navigateLink(className, stopEl, label) {
        var address = stopEl.getAttribute('data-address') || '';
        var lat = stopEl.getAttribute('data-lat') || '';
        var lng = stopEl.getAttribute('data-lng') || '';
        var maps = mapsDirectionsUrl(address, lat, lng);
        if (!maps) return '';
        return '<a class="' + className + ' js-navigate-link" href="' + escapeHtml(maps) +
            '" data-address="' + escapeHtml(address) +
            '" data-lat="' + escapeHtml(lat) +
            '" data-lng="' + escapeHtml(lng) +
            '" target="_blank" rel="noopener">' + escapeHtml(label) + '</a>';
    }

    var NOW_LINE_LIMIT = 6;

    function renderNowLines(stopEl) {
        var lines = parseStopLines(stopEl);
        if (!lines.length) return '';
        var shown = lines.slice(0, NOW_LINE_LIMIT);
        var html = '<ul class="now-lines">' + shown.map(function (line) {
            return '<li><span class="now-line-qty">' + escapeHtml(String(line[1])) + '</span><span class="now-line-name">' + escapeHtml(String(line[0])) + '</span></li>';
        }).join('');
        if (lines.length > NOW_LINE_LIMIT) {
            html += '<li class="now-lines-more">' + escapeHtml(fillTemplate(di.lines_more || '+:count more', { count: lines.length - NOW_LINE_LIMIT })) + '</li>';
        }
        return html + '</ul>';
    }

    function renderNextStop(stopEl) {
        var card = document.getElementById('nextStopCard');
        var done = document.getElementById('routeDoneBanner');
        var stickyDock = document.getElementById('routeStickyDock');
        var stickyInner = document.getElementById('routeStickyDockInner');
        if (!card) return;

        if (!stopEl) {
            card.hidden = true;
            card.innerHTML = '';
            if (done) done.hidden = false;
            if (stickyDock) {
                stickyDock.hidden = true;
                stickyDock.setAttribute('aria-hidden', 'true');
            }
            if (stickyInner) stickyInner.innerHTML = '';
            document.body.classList.remove('route-has-sticky-dock');
            return;
        }
        if (done) done.hidden = true;
        card.hidden = false;

        var name = stopEl.getAttribute('data-customer-name') || '';
        var address = stopEl.getAttribute('data-address') || '';
        var phone = stopEl.getAttribute('data-phone') || '';
        var routeOrder = stopEl.getAttribute('data-route-order') || '';
        var orderedPieces = parseInt(stopEl.getAttribute('data-ordered-pieces') || '0', 10);
        var stopNotes = stopEl.getAttribute('data-stop-notes') || '';
        var windowLabel = stopEl.getAttribute('data-receiving-hours') || stopEl.getAttribute('data-scheduled-time') || '';
        var status = stopEl.getAttribute('data-status') || 'pending';
        var tel = phoneHref(phone);
        var statusLabel = di['status_' + status] || status;
        var active = getActiveStops();
        var isFirst = active[0] === stopEl;

        card.setAttribute('data-daily-order-id', stopEl.getAttribute('data-daily-order-id') || '0');
        card.innerHTML =
            '<div class="now-card-head">' +
            '<p class="next-stop-eyebrow"><span>' + escapeHtml(di.next_stop) +
            (routeOrder ? ' · #' + escapeHtml(routeOrder) : '') + '</span>' +
            (windowLabel ? '<span class="now-window-chip">' + escapeHtml(windowLabel) + '</span>' : '') +
            (status !== 'pending' ? '<span class="status-badge status-badge--' + escapeHtml(status) + '" id="nextStopStatus">' + escapeHtml(statusLabel) + '</span>' : '') +
            '</p>' +
            '<h2 class="next-stop-store">' + escapeHtml(name) + '</h2>' +
            '<p class="next-stop-address">' + escapeHtml(address || di.no_address) + '</p>' +
            (tel ? '<a class="next-stop-phone" href="' + tel + '">' + escapeHtml(di.call_store || phone) + '</a>' : '') +
            '</div>' +
            renderNowLines(stopEl) +
            (orderedPieces > 0
                ? '<p class="now-lines-total">' + escapeHtml(String(di.pieces_ordered || '').replace('__COUNT__', String(orderedPieces))) + '</p>'
                : '') +
            (stopNotes
                ? '<button type="button" class="next-stop-notes is-clamped" id="nextStopNotes" aria-expanded="false"><strong>' +
                  escapeHtml(di.delivery_notes) + '</strong> <span>' + escapeHtml(stopNotes) + '</span></button>'
                : '') +
            (!isFirst && active.length > 1
                ? '<p class="now-not-first"><button type="button" class="stop-go-next" data-daily-order-id="' +
                  escapeHtml(stopEl.getAttribute('data-daily-order-id') || '0') + '">' + escapeHtml(di.make_next) + '</button></p>'
                : '') +
            '<div class="next-stop-actions">' +
            '<button type="button" class="route-btn route-btn--photo photo-complete-btn"' + photoButtonAttrs(stopEl) + '>' + escapeHtml(di.im_here || di.arrival_photo) + '</button>' +
            navigateLink('route-btn route-btn--navigate', stopEl, di.directions) +
            '<button type="button" class="route-btn route-btn--problem stop-problem-btn"' + problemButtonAttrs(stopEl) + '>' + escapeHtml(di.problem) + '</button>' +
            '</div>';

        applyNavigateLinks(card);
        if (window.DriverDelivery && typeof window.DriverDelivery.bindPhotoButtons === 'function') {
            window.DriverDelivery.bindPhotoButtons(card);
        }

        if (stickyDock && stickyInner) {
            stickyDock.hidden = false;
            stickyDock.setAttribute('aria-hidden', 'false');
            stickyInner.innerHTML =
                navigateLink('route-sticky-btn route-sticky-btn--nav', stopEl, di.directions) +
                '<button type="button" class="route-sticky-btn route-sticky-btn--finish photo-complete-btn"' + photoButtonAttrs(stopEl) + '>' + escapeHtml(di.im_here || di.arrival_photo) + '</button>';
            applyNavigateLinks(stickyInner);
            if (window.DriverDelivery && typeof window.DriverDelivery.bindPhotoButtons === 'function') {
                window.DriverDelivery.bindPhotoButtons(stickyInner);
            }
            document.body.classList.add('route-has-sticky-dock');
        }
    }

    function showSuccessToast(message, action) {
        var toast = document.getElementById('routeSuccessToast');
        if (!toast) return;
        toast.textContent = message || di.delivery_saved;
        toast.classList.toggle('has-action', !!action);
        if (action && action.label && typeof action.run === 'function') {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'route-toast-action';
            button.textContent = action.label;
            button.addEventListener('click', function () {
                clearTimeout(showSuccessToast._timer);
                toast.classList.remove('is-visible');
                toast.hidden = true;
                action.run();
            });
            toast.appendChild(button);
        }
        toast.hidden = false;
        toast.classList.add('is-visible');
        clearTimeout(showSuccessToast._timer);
        showSuccessToast._timer = setTimeout(function () {
            toast.classList.remove('is-visible');
            toast.hidden = true;
        }, action ? 9000 : 4200);
    }

    function routeOrderValue(stopEl) {
        var raw = stopEl ? stopEl.getAttribute('data-route-order') || '' : '';
        var num = parseInt(String(raw), 10);
        return isNaN(num) ? Number.MAX_SAFE_INTEGER : num;
    }

    function sortStopsByRouteOrder(listEl) {
        if (!listEl) return;
        var items = Array.prototype.slice.call(listEl.querySelectorAll('.stop-item'));
        items.sort(function (a, b) {
            return routeOrderValue(a) - routeOrderValue(b);
        });
        items.forEach(function (el) {
            listEl.appendChild(el);
        });
    }

    function findRouteStop(dailyOrderId) {
        var selector = '.stop-item[data-daily-order-id="' + String(dailyOrderId) + '"]';
        return document.querySelector('#pastStopsList ' + selector)
            || document.querySelector('#stopList ' + selector);
    }

    function moveStopToPastList(stopEl) {
        var pastList = document.getElementById('pastStopsList');
        if (!stopEl || !pastList) return;
        stopEl.classList.remove('stop-item--next', 'stop-item--upcoming');
        stopEl.classList.add('stop-item--past');
        pastList.appendChild(stopEl);
        sortStopsByRouteOrder(pastList);
    }

    function moveStopToActiveList(stopEl) {
        var activeList = document.getElementById('stopList');
        if (!stopEl || !activeList) return;
        stopEl.classList.remove('stop-item--past', 'stop-item--skipped');
        activeList.appendChild(stopEl);
        sortStopsByRouteOrder(activeList);
    }

    function rowActionsHtml(stopEl) {
        var id = escapeHtml(stopEl.getAttribute('data-daily-order-id') || '0');
        var name = stopEl.getAttribute('data-customer-name') || '';
        return '<button type="button" class="stop-go-next" data-daily-order-id="' + id + '" data-customer-name="' + escapeHtml(name) + '">' + escapeHtml(di.make_next) + '</button>' +
            '<div class="stop-adjust-moves">' +
            '<button type="button" class="stop-move-up" data-daily-order-id="' + id + '" aria-label="' + escapeHtml(di.move_up) + '">&#9650;</button>' +
            '<button type="button" class="stop-move-down" data-daily-order-id="' + id + '" aria-label="' + escapeHtml(di.move_down) + '">&#9660;</button>' +
            '</div>' +
            '<button type="button" class="stop-problem-btn"' + problemButtonAttrs(stopEl) +
            ' aria-label="' + escapeHtml(String(di.stop_problem_aria || '').replace(':name', name)) + '">&#8943;</button>';
    }

    function rebuildStopContactActions(stopEl) {
        var actions = stopEl.querySelector('.contact-actions');
        if (!actions) return;
        var status = stopEl.getAttribute('data-status') || 'pending';
        var isDone = status === 'delivered' || status === 'cancelled' || status === 'failed' || status === 'office_flag';
        var rowActions = stopEl.querySelector('.stop-row-actions');
        var leg = stopEl.querySelector('[data-stop-leg]');

        if (!isDone) {
            if (!rowActions) {
                rowActions = document.createElement('div');
                rowActions.className = 'stop-row-actions';
                actions.parentNode.insertBefore(rowActions, actions);
            }
            rowActions.innerHTML = rowActionsHtml(stopEl);
            actions.innerHTML = '';
            actions.hidden = true;
            stopEl.classList.remove('stop-item--skipped');
            return;
        }

        if (rowActions) rowActions.parentNode.removeChild(rowActions);
        if (leg) leg.hidden = true;
        var name = stopEl.getAttribute('data-customer-name') || '';
        var dailyOrderId = stopEl.getAttribute('data-daily-order-id') || '0';
        var html = '';
        if (status === 'delivered') {
            html += '<button type="button" class="contact-link photo-complete-btn" data-photo-mode="review"' +
                photoButtonAttrs(stopEl) + '>' + escapeHtml(di.view_invoice_photos) + '</button>';
        }
        if (status === 'cancelled') {
            stopEl.classList.add('stop-item--skipped');
            html += '<button type="button" class="contact-link contact-link--unskip unskip-stop-btn"' +
                ' data-daily-order-id="' + escapeHtml(dailyOrderId) + '"' +
                ' data-customer-name="' + escapeHtml(name) + '">' +
                escapeHtml(di.unskip_stop) + '</button>';
        } else {
            stopEl.classList.remove('stop-item--skipped');
        }
        actions.innerHTML = html;
        actions.hidden = html === '';
        if (window.DriverDelivery && typeof window.DriverDelivery.bindPhotoButtons === 'function') {
            window.DriverDelivery.bindPhotoButtons(actions);
        }
        bindUnskipStopButtons(actions);
    }

    function updateStopStatusBadge(stopEl, status) {
        var badge = stopEl.querySelector('.status-badge');
        if (!badge) return;
        var statusLabel = status.replace(/_/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
        if (status === 'cancelled' && di.status_cancelled) statusLabel = di.status_cancelled;
        if (status === 'delivered' && di.status_delivered) statusLabel = di.status_delivered;
        if (status === 'office_flag' && di.status_office_flag) statusLabel = di.status_office_flag;
        badge.textContent = statusLabel;
        badge.className = 'status-badge status-badge--' + status;
    }

    function getPastStops() {
        var pastList = document.getElementById('pastStopsList');
        if (!pastList) return [];
        return Array.prototype.slice.call(pastList.querySelectorAll('.stop-item'));
    }

    var routeAdjust = { open: false, picked: [], snapshot: [], saving: false };

    function formatDoneCount(done, total) {
        var template = di.done_count || ':done / :total done';
        return String(template).split(':done').join(String(done)).split(':total').join(String(total));
    }

    function refreshRouteUi() {
        var list = document.getElementById('stopList');
        if (!list) return;

        sortStopsByRouteOrder(list);
        var pastList = document.getElementById('pastStopsList');
        if (pastList) sortStopsByRouteOrder(pastList);

        var items = Array.prototype.slice.call(list.querySelectorAll('.stop-item'));
        var nextAssigned = false;
        items.forEach(function (el) {
            var status = el.getAttribute('data-status') || '';
            el.classList.remove('stop-item--next', 'stop-item--upcoming', 'stop-item--past');
            if (status === 'delivered' || status === 'cancelled' || status === 'failed' || status === 'office_flag') {
                moveStopToPastList(el);
                return;
            }
            if (!nextAssigned) {
                el.classList.add('stop-item--next');
                nextAssigned = true;
            } else {
                el.classList.add('stop-item--upcoming');
            }
        });

        var past = getPastStops();
        var active = getActiveStops();
        renderNextStop(active[0] || null);

        var root = document.getElementById('driverRouteRoot');
        var total = root ? parseInt(root.getAttribute('data-total') || '0', 10) : 0;
        var completed = past.length;
        if (root) root.setAttribute('data-completed', String(completed));
        var text = document.getElementById('routeProgressText');
        var fill = document.getElementById('routeProgressFill');
        var doneLabel = formatDoneCount(completed, total);
        if (text) text.textContent = doneLabel;
        if (fill && total > 0) fill.style.width = Math.round((completed / total) * 100) + '%';
        var mobileText = document.getElementById('routeMobileProgressText');
        var mobileFill = document.getElementById('routeMobileProgressFill');
        if (mobileText) mobileText.textContent = doneLabel;
        if (mobileFill && total > 0) mobileFill.style.width = Math.round((completed / total) * 100) + '%';
        var progressPercent = document.getElementById('routeProgressPercent');
        var progressRing = document.querySelector('.route-progress-ring');
        var completedCount = document.getElementById('routeCompletedCount');
        var remainingCount = document.getElementById('routeRemainingCount');
        var totalCount = document.getElementById('routeTotalCount');
        var pastCount = document.getElementById('routePastCount');
        var queueCount = document.getElementById('queueCount');
        var percent = total > 0 ? Math.round((completed / total) * 100) : 0;
        if (progressPercent) progressPercent.textContent = percent + '%';
        if (progressRing) progressRing.style.setProperty('--route-progress', percent + '%');
        if (completedCount) completedCount.textContent = completed;
        if (remainingCount) remainingCount.textContent = active.length;
        if (totalCount) totalCount.textContent = total;
        if (pastCount) pastCount.textContent = past.length;
        if (queueCount) queueCount.textContent = active.length;

        var pastDetails = document.getElementById('pastStopsDetails');
        var completedButton = document.getElementById('routeCompletedButton');
        if (pastDetails) {
            var summary = pastDetails.querySelector('summary');
            if (summary) summary.innerHTML = '<span>' + escapeHtml(di.past_stops) + '</span><span class="past-stops-summary-count">' + past.length + '</span>';
            pastDetails.hidden = past.length === 0;
        }
        if (completedButton) completedButton.disabled = past.length === 0;
        var pastButton = document.getElementById('routePastStopsButton');
        if (pastButton) pastButton.disabled = past.length === 0;
        var canAdjust = active.length > 1;
        var toggle = document.getElementById('routeAdjustToggle');
        if (toggle) toggle.hidden = !canAdjust;
        document.querySelectorAll('.stop-go-next, .change-next-btn').forEach(function (btn) {
            btn.hidden = !canAdjust;
        });
        if (!canAdjust && routeAdjust.open) {
            routeAdjust.open = false;
            routeAdjust.picked = [];
            routeAdjust.snapshot = [];
            setRouteAdjustOpen(false);
        }
        var suggestBtn = document.getElementById('routeSuggestBtn');
        if (suggestBtn) suggestBtn.hidden = active.length < 3 || !!(window.DriverDataSaver && window.DriverDataSaver.active);
        var stripProgress = document.getElementById('nowStripProgress');
        if (stripProgress) stripProgress.textContent = completed + '/' + total;
        paintStraightLineLegs(true);
        syncLoadVan();
        syncWakeLock();
        if (window.DriverRouteMap && typeof window.DriverRouteMap.refresh === 'function') {
            window.DriverRouteMap.refresh();
        }
    }

    function remainingAdjustIds() {
        var picked = routeAdjust.picked.slice();
        var rest = [];
        var source = routeAdjust.snapshot.length
            ? routeAdjust.snapshot
            : getActiveStops().map(function (el) {
                return { id: el.getAttribute('data-daily-order-id') || '' };
            });
        source.forEach(function (row) {
            var id = parseInt(String(row.id || 0), 10);
            if (id > 0 && picked.indexOf(id) === -1) {
                rest.push(id);
            }
        });
        return picked.concat(rest);
    }

    function paintAdjustOrder() {
        var list = document.getElementById('stopList');
        if (!list) return;
        var ids = remainingAdjustIds();
        ids.forEach(function (id) {
            var el = list.querySelector('.stop-item[data-daily-order-id="' + String(id) + '"]');
            if (el) list.appendChild(el);
        });
        ids.forEach(function (id, index) {
            var el = list.querySelector('.stop-item[data-daily-order-id="' + String(id) + '"]');
            if (!el) return;
            var pick = el.querySelector('.stop-item-pick');
            if (pick) pick.textContent = String(index + 1);
            el.classList.toggle('is-adjust-picked', routeAdjust.picked.indexOf(id) !== -1);
            el.classList.toggle('is-adjust-next', index === 0);
        });
        if (window.DriverRouteMap && typeof window.DriverRouteMap.refresh === 'function') {
            window.DriverRouteMap.refresh();
        }
    }

    function setRouteAdjustOpen(open) {
        routeAdjust.open = !!open;
        document.body.classList.toggle('route-adjust-open', routeAdjust.open);
        var dock = document.getElementById('routeAdjustDock');
        if (dock) dock.hidden = !routeAdjust.open;
        var toggle = document.getElementById('routeAdjustToggle');
        if (toggle) {
            toggle.setAttribute('aria-pressed', routeAdjust.open ? 'true' : 'false');
            toggle.textContent = routeAdjust.open ? (di.cancel || 'Cancel') : (di.reorder || di.adjust_route || 'Reorder');
        }
    }

    function openRouteAdjust() {
        var active = getActiveStops();
        if (active.length < 2) return;
        routeAdjust.picked = [];
        routeAdjust.tapCount = 0;
        routeAdjust.snapshot = active.map(function (el) {
            return {
                id: el.getAttribute('data-daily-order-id') || '',
                order: el.getAttribute('data-route-order') || ''
            };
        });
        setRouteAdjustOpen(true);
        pushLayer('adjust');
        paintAdjustOrder();
        var section = document.querySelector('.stop-list-section');
        if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function closeRouteAdjust(restore) {
        if (!routeAdjust.open && !restore) {
            setRouteAdjustOpen(false);
            return;
        }
        if (restore && routeAdjust.snapshot.length) {
            routeAdjust.snapshot.forEach(function (row) {
                var el = findRouteStop(row.id);
                if (!el) return;
                el.setAttribute('data-route-order', row.order);
                var badge = el.querySelector('.stop-item-order');
                if (badge) badge.textContent = row.order ? '#' + row.order : '#-';
            });
            routeAdjust.open = false;
            refreshRouteUi();
        }
        routeAdjust.picked = [];
        routeAdjust.snapshot = [];
        getActiveStops().forEach(function (el) {
            el.classList.remove('is-adjust-picked', 'is-adjust-next');
            var pick = el.querySelector('.stop-item-pick');
            if (pick) pick.textContent = '';
        });
        setRouteAdjustOpen(false);
        popLayerIfOurs('adjust');
    }

    // Map taps in Reorder build the order: each tapped stop goes right after the last one tapped.
    function tapStopForAdjust(stopEl) {
        var id = parseInt(stopEl.getAttribute('data-daily-order-id') || '0', 10);
        var ids = remainingAdjustIds();
        var index = ids.indexOf(id);
        if (index === -1) return;
        ids.splice(index, 1);
        var position = Math.min(routeAdjust.tapCount || 0, ids.length);
        ids.splice(position, 0, id);
        routeAdjust.tapCount = position + 1;
        routeAdjust.picked = ids;
        paintAdjustOrder();
    }

    function moveStopInAdjust(dailyOrderId, where) {
        var id = parseInt(String(dailyOrderId || 0), 10);
        if (id <= 0) return;
        var ids = remainingAdjustIds();
        var index = ids.indexOf(id);
        if (index === -1) return;
        var target = where === 'up' ? index - 1 : index + 1;
        if (target < 0 || target >= ids.length) return;
        ids.splice(index, 1);
        ids.splice(target, 0, id);
        routeAdjust.picked = ids;
        paintAdjustOrder();
    }

    function readListOrderIntoAdjust() {
        var list = document.getElementById('stopList');
        if (!list) return;
        routeAdjust.picked = Array.prototype.slice.call(list.querySelectorAll('.stop-item')).filter(function (el) {
            return getActiveStops().indexOf(el) !== -1;
        }).map(function (el) {
            return parseInt(el.getAttribute('data-daily-order-id') || '0', 10);
        }).filter(function (id) { return id > 0; });
        paintAdjustOrder();
    }

    var LONG_PRESS_MS = 350;
    var dragState = { timer: 0, el: null, active: false, startY: 0, pointerId: null };

    function cancelDragPress() {
        window.clearTimeout(dragState.timer);
        dragState.timer = 0;
    }

    function endDrag() {
        cancelDragPress();
        if (dragState.el) dragState.el.classList.remove('is-dragging');
        var wasActive = dragState.active;
        dragState.el = null;
        dragState.active = false;
        dragState.pointerId = null;
        document.body.classList.remove('route-dragging');
        if (wasActive) readListOrderIntoAdjust();
    }

    function dragMoveTo(clientY) {
        var list = document.getElementById('stopList');
        if (!list || !dragState.el) return;
        var siblings = getActiveStops().filter(function (el) { return el !== dragState.el; });
        var before = null;
        for (var i = 0; i < siblings.length; i++) {
            var rect = siblings[i].getBoundingClientRect();
            if (clientY < rect.top + rect.height / 2) {
                before = siblings[i];
                break;
            }
        }
        if (before) list.insertBefore(dragState.el, before);
        else if (siblings.length) list.insertBefore(dragState.el, siblings[siblings.length - 1].nextSibling);
    }

    function bindReorderDrag() {
        var list = document.getElementById('stopList');
        if (!list) return;
        list.addEventListener('pointerdown', function (e) {
            if (!routeAdjust.open || e.button > 0) return;
            if (e.target.closest('button, a')) return;
            var stopEl = e.target.closest('.stop-item');
            if (!stopEl || getActiveStops().indexOf(stopEl) === -1) return;
            dragState.el = stopEl;
            dragState.startY = e.clientY;
            dragState.pointerId = e.pointerId;
            cancelDragPress();
            dragState.timer = window.setTimeout(function () {
                dragState.active = true;
                stopEl.classList.add('is-dragging');
                document.body.classList.add('route-dragging');
                if (navigator.vibrate) navigator.vibrate(15);
            }, LONG_PRESS_MS);
        });
        list.addEventListener('pointermove', function (e) {
            if (!dragState.el || e.pointerId !== dragState.pointerId) return;
            if (!dragState.active) {
                if (Math.abs(e.clientY - dragState.startY) > 8) {
                    cancelDragPress();
                    dragState.el = null;
                }
                return;
            }
            dragMoveTo(e.clientY);
        });
        // A long press must stop the page from scrolling under the finger.
        list.addEventListener('touchmove', function (e) {
            if (dragState.active) e.preventDefault();
        }, { passive: false });
        ['pointerup', 'pointercancel'].forEach(function (type) {
            list.addEventListener(type, function () {
                if (dragState.el) endDrag();
            });
        });
    }

    var suggestState = { orderIds: null, busy: false };

    function hideSuggestPreview() {
        suggestState.orderIds = null;
        var preview = document.getElementById('routeSuggestPreview');
        if (preview) {
            preview.hidden = true;
            preview.innerHTML = '';
        }
    }

    async function suggestRouteOrder() {
        var preview = document.getElementById('routeSuggestPreview');
        var map = window.DriverRouteMap;
        if (!preview || suggestState.busy) return;
        if (!map || typeof map.suggestOrder !== 'function' || (typeof map.isReady === 'function' && !map.isReady())) {
            preview.hidden = false;
            preview.innerHTML = '<p>' + escapeHtml(di.suggest_failed) + '</p>';
            return;
        }
        suggestState.busy = true;
        preview.hidden = false;
        preview.innerHTML = '<p>' + escapeHtml(di.loading || '') + '</p>';
        try {
            var result = await map.suggestOrder();
            if (!result || !result.moves) {
                preview.innerHTML = '<p>' + escapeHtml(di.suggest_no_gain) + '</p>' +
                    '<div class="route-suggest-actions"><button type="button" class="route-suggest-dismiss">' + escapeHtml(di.suggest_dismiss) + '</button></div>';
                return;
            }
            suggestState.orderIds = result.orderIds;
            var minutes = result.savedSeconds == null ? '?' : Math.round(result.savedSeconds / 60);
            preview.innerHTML =
                '<p><strong>' + escapeHtml(fillTemplate(di.suggest_preview, { moves: result.moves, minutes: minutes })) + '</strong></p>' +
                (result.windowNames && result.windowNames.length
                    ? '<p class="route-suggest-warn">' + escapeHtml(fillTemplate(di.suggest_window_flag, { names: result.windowNames.join(', ') })) + '</p>'
                    : '') +
                '<div class="route-suggest-actions">' +
                '<button type="button" class="route-suggest-dismiss">' + escapeHtml(di.suggest_dismiss) + '</button>' +
                '<button type="button" class="route-suggest-accept">' + escapeHtml(di.suggest_accept) + '</button>' +
                '</div>';
        } catch (err) {
            preview.innerHTML = '<p>' + escapeHtml(di.suggest_failed) + '</p>' +
                '<div class="route-suggest-actions"><button type="button" class="route-suggest-dismiss">' + escapeHtml(di.suggest_dismiss) + '</button></div>';
        } finally {
            suggestState.busy = false;
        }
    }

    async function acceptSuggestedOrder() {
        if (!suggestState.orderIds) return;
        var ids = suggestState.orderIds.slice();
        hideSuggestPreview();
        try {
            await submitRouteOrder(ids);
        } catch (err) {
            showSuccessToast(err.message || di.route_order_error);
        }
    }

    function applyRouteOrders(stops) {
        (stops || []).forEach(function (row) {
            var el = findRouteStop(row.daily_order_id);
            if (!el) return;
            el.setAttribute('data-route-order', String(row.route_order));
            var badge = el.querySelector('.stop-item-order');
            if (badge) badge.textContent = '#' + row.route_order;
        });
    }

    async function submitRouteOrder(orderIds) {
        var root = document.getElementById('driverRouteRoot');
        if (!root) throw new Error(di.route_order_error);
        var body = 'action=reorder_route'
            + '&driver_id=' + encodeURIComponent(root.getAttribute('data-driver-id') || '0')
            + '&date=' + encodeURIComponent(root.getAttribute('data-date') || '')
            + '&order_ids=' + encodeURIComponent((orderIds || []).join(','));
        var response = await fetch('complete_delivery.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        });
        var data = null;
        try {
            data = await response.json();
        } catch (parseErr) {
            throw new Error(di.route_order_error);
        }
        if (!response.ok || !data || !data.success) {
            throw new Error((data && (data.error || data.message)) || di.route_order_error);
        }
        applyRouteOrders(data.stops || []);
        refreshRouteUi();
        showSuccessToast(data.message || di.route_order_saved);
        return data;
    }

    async function goNextNow(dailyOrderId) {
        var id = parseInt(String(dailyOrderId || 0), 10);
        if (id <= 0) return;
        var rest = getActiveStops().map(function (el) {
            return parseInt(el.getAttribute('data-daily-order-id') || '0', 10);
        }).filter(function (item) { return item > 0 && item !== id; });
        rest.unshift(id);
        await submitRouteOrder(rest);
    }

    async function saveRouteAdjust() {
        if (routeAdjust.saving) return;
        routeAdjust.saving = true;
        var saveBtn = document.getElementById('routeAdjustSave');
        if (saveBtn) {
            saveBtn.disabled = true;
            saveBtn.setAttribute('aria-busy', 'true');
        }
        try {
            await submitRouteOrder(remainingAdjustIds());
            closeRouteAdjust(false);
        } catch (err) {
            alert(err.message || di.route_order_error);
        } finally {
            routeAdjust.saving = false;
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.removeAttribute('aria-busy');
            }
        }
    }

    window.DriverRoute = {
        mapsDirectionsUrl: mapsDirectionsUrl,
        showSuccessToast: showSuccessToast,
        goNext: goNextNow,
        isAdjustOpen: function () { return !!routeAdjust.open; },
        tapAdjustStop: function (dailyOrderId) {
            var stop = findRouteStop(dailyOrderId);
            if (stop) tapStopForAdjust(stop);
        },
        openStop: function (dailyOrderId, opts) {
            return openStopDeliveryFromEl(findRouteStop(dailyOrderId), opts || {});
        },
        afterOfficeFlag: function (dailyOrderId, message) {
            var stop = findRouteStop(dailyOrderId);
            if (stop) {
                stop.setAttribute('data-status', 'office_flag');
                updateStopStatusBadge(stop, 'office_flag');
                rebuildStopContactActions(stop);
                moveStopToPastList(stop);
            }
            refreshRouteUi();
            showSuccessToast(message || di.empty_order_flagged);
        },
        afterDeliveryComplete: function (dailyOrderId, message, options) {
            var stop = findRouteStop(dailyOrderId);
            var photoAction = null;
            if (stop) {
                stop.setAttribute('data-status', 'delivered');
                updateStopStatusBadge(stop, 'delivered');
                rebuildStopContactActions(stop);
                moveStopToPastList(stop);
                var reviewBtn = stop.querySelector('.photo-complete-btn[data-photo-mode="review"]');
                if (options && options.canAddPhoto && reviewBtn && window.DriverDelivery && typeof window.DriverDelivery.openReviewAddPhoto === 'function') {
                    photoAction = {
                        label: di.second_photo_toast_action,
                        run: function () { window.DriverDelivery.openReviewAddPhoto(reviewBtn); }
                    };
                }
            }
            refreshRouteUi();
            showSuccessToast(message || di.delivery_saved_next, photoAction);
        },
        afterStopSkipped: function (dailyOrderId, message) {
            if (document.getElementById('routePrepRoot')) {
                patchPrepStop(dailyOrderId, true);
                showSuccessToast(message || di.skip_success);
                return;
            }
            var stop = findRouteStop(dailyOrderId);
            if (stop) {
                stop.setAttribute('data-status', 'cancelled');
                updateStopStatusBadge(stop, 'cancelled');
                rebuildStopContactActions(stop);
                moveStopToPastList(stop);
            }
            refreshRouteUi();
            showSuccessToast(message || di.skip_success);
        },
        afterStopUnskipped: function (dailyOrderId, message) {
            if (document.getElementById('routePrepRoot')) {
                patchPrepStop(dailyOrderId, false);
                showSuccessToast(message || di.unskip_success);
                return;
            }
            var stop = findRouteStop(dailyOrderId);
            if (!stop) {
                showSuccessToast(message || di.unskip_success);
                refreshRouteSnapshot();
                return;
            }
            stop.setAttribute('data-status', 'pending');
            updateStopStatusBadge(stop, 'pending');
            rebuildStopContactActions(stop);
            moveStopToActiveList(stop);
            refreshRouteUi();
            showSuccessToast(message || di.unskip_success);
            setTimeout(function () {
                stop.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 80);
        },
        afterStopFailed: function (dailyOrderId, message) {
            var stop = findRouteStop(dailyOrderId);
            if (stop) {
                stop.setAttribute('data-status', 'failed');
                updateStopStatusBadge(stop, 'failed');
                rebuildStopContactActions(stop);
                moveStopToPastList(stop);
            }
            refreshRouteUi();
            showSuccessToast(message || di.fail_success);
        }
    };

    var skipModalState = { dailyOrderId: 0, submitting: false };

    function openSkipStopModal(dailyOrderId, customerName) {
        var modal = document.getElementById('skipStopModal');
        var customerEl = document.getElementById('skipStopModalCustomer');
        var reasonInput = document.getElementById('skipStopReasonInput');
        var errorEl = document.getElementById('skipStopError');
        if (!modal) return;
        skipModalState.dailyOrderId = parseInt(String(dailyOrderId || 0), 10);
        skipModalState.submitting = false;
        if (customerEl) customerEl.textContent = customerName || '';
        if (reasonInput) {
            reasonInput.value = '';
            reasonInput.classList.remove('is-invalid');
        }
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = '';
        }
        var confirmBtn = document.getElementById('skipStopConfirmBtn');
        if (confirmBtn) {
            confirmBtn.disabled = false;
            confirmBtn.removeAttribute('aria-busy');
        }
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('skip-stop-modal-open');
        if (reasonInput) reasonInput.focus();
    }

    function closeSkipStopModal() {
        var modal = document.getElementById('skipStopModal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('skip-stop-modal-open');
        skipModalState.dailyOrderId = 0;
        skipModalState.submitting = false;
    }

    async function submitSkipStop() {
        if (skipModalState.submitting) return;
        var reasonInput = document.getElementById('skipStopReasonInput');
        var errorEl = document.getElementById('skipStopError');
        var confirmBtn = document.getElementById('skipStopConfirmBtn');
        var reason = reasonInput ? String(reasonInput.value || '').trim() : '';
        if (!reason) {
            if (reasonInput) reasonInput.classList.add('is-invalid');
            if (errorEl) {
                errorEl.textContent = di.skip_reason_required;
                errorEl.hidden = false;
            }
            if (reasonInput) reasonInput.focus();
            return;
        }
        skipModalState.submitting = true;
        if (confirmBtn) {
            confirmBtn.disabled = true;
            confirmBtn.setAttribute('aria-busy', 'true');
        }
        if (errorEl) errorEl.hidden = true;
        try {
            var body = 'action=skip_stop&daily_order_id=' + encodeURIComponent(String(skipModalState.dailyOrderId)) +
                '&reason=' + encodeURIComponent(reason);
            var response = await fetch('complete_delivery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            var data = await response.json();
            if (!data || !data.success) {
                throw new Error((data && (data.error || data.message)) || di.skip_error);
            }
            var skippedOrderId = skipModalState.dailyOrderId;
            closeSkipStopModal();
            window.DriverRoute.afterStopSkipped(skippedOrderId, data.message);
        } catch (err) {
            skipModalState.submitting = false;
            if (confirmBtn) {
                confirmBtn.disabled = false;
                confirmBtn.removeAttribute('aria-busy');
            }
            if (errorEl) {
                errorEl.textContent = err.message || di.skip_error;
                errorEl.hidden = false;
            }
        }
    }

    function bindUnskipStopButtons(root) {
        (root || document).querySelectorAll('.unskip-stop-btn').forEach(function (btn) {
            if (btn.getAttribute('data-unskip-bound') === '1') return;
            btn.setAttribute('data-unskip-bound', '1');
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                submitUnskipStop(
                    btn.getAttribute('data-daily-order-id'),
                    btn.getAttribute('data-customer-name') || ''
                );
            });
        });
    }

    async function submitUnskipStop(dailyOrderId, customerName) {
        var orderId = parseInt(String(dailyOrderId || 0), 10);
        if (!orderId) return;
        var label = customerName ? '"' + customerName + '"' : di.this_stop;
        if (!confirm(di.confirm_restore.replace(':label', label))) return;

        try {
            var body = 'action=unskip_stop&daily_order_id=' + encodeURIComponent(String(orderId));
            var response = await fetch('complete_delivery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
            var data = null;
            try {
                data = await response.json();
            } catch (parseErr) {
                throw new Error(di.unskip_error);
            }
            if (!response.ok || !data || !data.success) {
                throw new Error((data && (data.error || data.message)) || di.unskip_error);
            }
            window.DriverRoute.afterStopUnskipped(orderId, data.message);
        } catch (err) {
            alert(err.message || di.unskip_error);
        }
    }

    function bindFailStopButtons(root) {
        (root || document).querySelectorAll('.fail-stop-btn').forEach(function (btn) {
            if (btn.getAttribute('data-fail-bound') === '1') return;
            btn.setAttribute('data-fail-bound', '1');
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                var orderId = btn.getAttribute('data-daily-order-id');
                var assignmentId = btn.getAttribute('data-assignment-id') || '';
                var customerName = btn.getAttribute('data-customer-name') || '';
                if (btn.id === 'deliveryWizardFailBtn') {
                    var modal = document.getElementById('deliveryPhotoModal');
                    if (modal) {
                        orderId = modal.getAttribute('data-daily-order-id') || orderId;
                        assignmentId = modal.getAttribute('data-assignment-id') || assignmentId;
                        customerName = modal.getAttribute('data-customer-name') || customerName;
                    }
                }
                openFailStopModal(orderId, assignmentId, customerName);
            });
        });
    }

    var failModalState = { dailyOrderId: 0, assignmentId: 0, customerName: '', submitting: false };

    function failHqReasonLabel() {
        var form = document.getElementById('failStopForm');
        var checked = form ? form.querySelector('input[name="reason_code"]:checked') : null;
        if (!checked) return '';
        var chip = checked.closest('label');
        var span = chip ? chip.querySelector('span') : null;
        return (span ? span.textContent : checked.value || '').trim();
    }

    function updateFailHqLinks() {
        var wrap = document.getElementById('failStopHqLinks');
        if (!wrap) return;
        var phone = wrap.getAttribute('data-hq-phone') || '+14155091210';
        var digits = phone.replace(/\D+/g, '');
        var store = failModalState.customerName || '';
        var orderId = String(failModalState.dailyOrderId || '');
        var reason = failHqReasonLabel();
        var message = (di.hq_message || "Can't deliver: :store, order #:id, :reason")
            .replace(':store', store)
            .replace(':id', orderId)
            .replace(':reason', reason);
        var encoded = encodeURIComponent(message);
        var callLink = document.getElementById('failStopCallLink');
        var smsLink = document.getElementById('failStopSmsLink');
        var waLink = document.getElementById('failStopWaLink');
        if (callLink) callLink.setAttribute('href', 'tel:' + phone);
        if (smsLink) smsLink.setAttribute('href', 'sms:' + phone + '?body=' + encoded);
        if (waLink) waLink.setAttribute('href', 'https://wa.me/' + digits + '?text=' + encoded);
    }

    function openFailStopModal(dailyOrderId, assignmentId, customerName) {
        var modal = document.getElementById('failStopModal');
        if (!modal) return;
        failModalState.dailyOrderId = parseInt(String(dailyOrderId || 0), 10);
        failModalState.assignmentId = parseInt(String(assignmentId || 0), 10);
        failModalState.customerName = customerName || '';
        failModalState.submitting = false;
        var customerEl = document.getElementById('failStopModalCustomer');
        var orderInput = document.getElementById('failStopOrderId');
        var assignmentInput = document.getElementById('failStopAssignmentId');
        var noteInput = document.getElementById('failStopNote');
        var errorEl = document.getElementById('failStopError');
        if (customerEl) customerEl.textContent = customerName || '';
        if (orderInput) orderInput.value = String(failModalState.dailyOrderId);
        if (assignmentInput) assignmentInput.value = String(failModalState.assignmentId);
        if (noteInput) noteInput.value = '';
        if (errorEl) {
            errorEl.hidden = true;
            errorEl.textContent = '';
        }
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('fail-stop-modal-open');
        paintProblemChips();
        pushLayer('problem');
        updateFailHqLinks();
        var form = document.getElementById('failStopForm');
        if (form && form.getAttribute('data-hq-bound') !== '1') {
            form.setAttribute('data-hq-bound', '1');
            form.addEventListener('change', function (event) {
                if (event.target && event.target.name === 'reason_code') {
                    updateFailHqLinks();
                }
            });
        }
    }

    function closeFailStopModal() {
        var modal = document.getElementById('failStopModal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('fail-stop-modal-open');
        failModalState.submitting = false;
        popLayerIfOurs('problem');
    }

    async function submitFailStop() {
        if (failModalState.submitting) return;
        var form = document.getElementById('failStopForm');
        var errorEl = document.getElementById('failStopError');
        var confirmBtn = document.getElementById('failStopConfirmBtn');
        if (!form) return;
        var reason = '';
        var checked = form.querySelector('input[name="reason_code"]:checked');
        if (checked) reason = String(checked.value || '');
        var note = String((document.getElementById('failStopNote') || {}).value || '').trim();
        if (reason === 'other' && !note) {
            if (errorEl) {
                errorEl.textContent = di.fail_other_note;
                errorEl.hidden = false;
            }
            return;
        }
        failModalState.submitting = true;
        if (confirmBtn) confirmBtn.disabled = true;
        try {
            var body = new URLSearchParams();
            body.set('action', 'report_failed_stop');
            body.set('daily_order_id', String(failModalState.dailyOrderId));
            body.set('assignment_id', String(failModalState.assignmentId));
            body.set('reason_code', reason);
            body.set('manager_note', note);
            var csrf = document.querySelector('meta[name="csrf-token"]');
            if (csrf && csrf.getAttribute('content')) {
                body.set('csrf_token', csrf.getAttribute('content'));
            }
            var response = await fetch('complete_delivery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            });
            var data = await response.json();
            if (!data || !data.success) {
                throw new Error((data && (data.error || data.message)) || di.fail_error);
            }
            closeFailStopModal();
            if (window.DriverRoute && window.DriverRoute.afterStopFailed) {
                window.DriverRoute.afterStopFailed(failModalState.dailyOrderId, data.message);
            }
        } catch (err) {
            failModalState.submitting = false;
            if (confirmBtn) confirmBtn.disabled = false;
            if (errorEl) {
                errorEl.textContent = err.message || di.fail_error;
                errorEl.hidden = false;
            }
        }
    }

    function bindSkipStopButtons(root) {
        (root || document).querySelectorAll('.skip-stop-btn').forEach(function (btn) {
            if (btn.getAttribute('data-skip-bound') === '1') return;
            btn.setAttribute('data-skip-bound', '1');
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                openSkipStopModal(
                    btn.getAttribute('data-daily-order-id'),
                    btn.getAttribute('data-customer-name')
                );
            });
        });
    }

    function patchPrepStop(dailyOrderId, skipped) {
        var el = document.querySelector('#routePrepList .route-prep-stop[data-daily-order-id="' + String(dailyOrderId) + '"]');
        if (!el) return false;
        var name = el.getAttribute('data-customer-name') || '';
        var id = escapeHtml(String(dailyOrderId));
        el.classList.toggle('route-prep-stop--skipped', skipped);
        el.setAttribute('data-status', skipped ? 'cancelled' : 'pending');
        var actions = el.querySelector('.route-prep-stop-actions');
        if (actions) {
            if (skipped) {
                el._prepActionsHtml = actions.innerHTML;
                actions.innerHTML = '<button type="button" class="route-prep-action unskip-stop-btn" data-daily-order-id="' + id +
                    '" data-customer-name="' + escapeHtml(name) + '">' + escapeHtml(di.unskip_stop) + '</button>';
            } else {
                actions.innerHTML = el._prepActionsHtml ||
                    ('<button type="button" class="route-prep-action skip-stop-btn" data-daily-order-id="' + id + '" data-customer-name="' + escapeHtml(name) + '">' + escapeHtml(di.skip_stop || '') + '</button>' +
                    '<button type="button" class="route-prep-action route-prep-action--danger route-prep-remove" data-daily-order-id="' + id + '" data-customer-name="' + escapeHtml(name) + '">' + escapeHtml(di.prep_remove || '') + '</button>');
                actions.querySelectorAll('[data-skip-bound], [data-unskip-bound]').forEach(function (btn) {
                    btn.removeAttribute('data-skip-bound');
                    btn.removeAttribute('data-unskip-bound');
                });
            }
            bindSkipStopButtons(actions);
            bindUnskipStopButtons(actions);
        }
        var move = el.querySelector('.route-prep-move');
        if (!move && !skipped) {
            var top = el.querySelector('.route-prep-stop-top');
            if (top) {
                move = document.createElement('div');
                move.className = 'route-prep-move';
                move.innerHTML = '<button type="button" class="route-prep-move-btn route-prep-move-up" aria-label="' + escapeHtml(di.prep_move_up || '') + '">&#9650;</button>' +
                    '<button type="button" class="route-prep-move-btn route-prep-move-down" aria-label="' + escapeHtml(di.prep_move_down || '') + '">&#9660;</button>';
                top.appendChild(move);
            }
        }
        if (move) move.hidden = skipped;
        if (window.DriverRoutePrep && typeof window.DriverRoutePrep.listChanged === 'function') {
            window.DriverRoutePrep.listChanged();
        }
        return true;
    }

    function routeRoot() {
        return document.getElementById('driverRouteRoot');
    }

    function currentRouteView() {
        var root = routeRoot();
        return root && root.getAttribute('data-route-view') === 'route' ? 'route' : 'now';
    }

    var historyState = { ignorePop: false };

    function pushLayer(name) {
        if (!window.history || typeof window.history.pushState !== 'function') return;
        try {
            window.history.pushState({ driverLayer: name }, '');
        } catch (err) { /* ignore */ }
    }

    function popLayerIfOurs(name) {
        var state = window.history ? window.history.state : null;
        if (state && state.driverLayer === name) {
            historyState.ignorePop = true;
            window.history.back();
        }
    }

    function setRouteView(view, opts) {
        opts = opts || {};
        var root = routeRoot();
        if (!root || boot.prepMode) return;
        view = view === 'route' ? 'route' : 'now';
        var previous = currentRouteView();
        root.setAttribute('data-route-view', view);
        document.body.setAttribute('data-route-view', view);
        document.querySelectorAll('[data-route-view-target]').forEach(function (btn) {
            var on = btn.getAttribute('data-route-view-target') === view;
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.classList.toggle('is-active', on);
            if (btn.classList.contains('bakery-nav__direct')) {
                btn.classList.toggle('bakery-nav__direct--active', on);
            }
        });
        if (view === 'now' && routeAdjust.open) closeRouteAdjust(true);
        if (view === 'now') hideSuggestPreview();
        if (window.DriverRouteMap && typeof window.DriverRouteMap.setViewMode === 'function') {
            window.DriverRouteMap.setViewMode(view === 'route' ? 'day' : 'next');
        }
        if (view !== previous) {
            if (opts.push && view === 'route') pushLayer('view-route');
            if (!opts.fromPop && view === 'now' && previous === 'route') popLayerIfOurs('view-route');
        }
        syncWakeLock();
    }

    function initRouteView() {
        var root = routeRoot();
        if (!root) return;
        if (!root.getAttribute('data-route-view')) root.setAttribute('data-route-view', 'now');
        if (history.state && history.state.driverLayer && typeof history.replaceState === 'function') {
            try { history.replaceState(null, ''); } catch (e) {}
        }
        setRouteView(currentRouteView());

        window.bakeryClientRefreshHold = function () {
            var delivery = window.DriverDelivery;
            if (delivery && typeof delivery.isOpen === 'function' && delivery.isOpen()) return true;
            var failModal = document.getElementById('failStopModal');
            if (failModal && !failModal.hidden) return true;
            return !!routeAdjust.open || dragState.active;
        };

        document.addEventListener('driver:capture-open', function () { pushLayer('capture'); });
        document.addEventListener('driver:capture-close', function () { popLayerIfOurs('capture'); });

        window.addEventListener('popstate', function () {
            if (historyState.ignorePop) {
                historyState.ignorePop = false;
                return;
            }
            var delivery = window.DriverDelivery;
            if (delivery && typeof delivery.isOpen === 'function' && delivery.isOpen()) {
                if (typeof delivery.isBusy === 'function' && delivery.isBusy()) {
                    pushLayer('capture');
                    return;
                }
                delivery.closeModal();
                return;
            }
            var failModal = document.getElementById('failStopModal');
            if (failModal && !failModal.hidden) {
                closeFailStopModal({ fromPop: true });
                return;
            }
            if (routeAdjust.open) {
                closeRouteAdjust(true);
                return;
            }
            if (currentRouteView() === 'route') {
                setRouteView('now', { fromPop: true });
            }
        });
    }

    var wakeLock = { sentinel: null, pending: false };

    function wakeLockWanted() {
        return !!boot.isToday && !boot.prepMode
            && document.visibilityState === 'visible'
            && currentRouteView() === 'now'
            && getActiveStops().length > 0;
    }

    function syncWakeLock() {
        if (!('wakeLock' in navigator)) return;
        if (wakeLockWanted()) {
            if (wakeLock.sentinel || wakeLock.pending) return;
            wakeLock.pending = true;
            navigator.wakeLock.request('screen').then(function (sentinel) {
                wakeLock.sentinel = sentinel;
                sentinel.addEventListener('release', function () { wakeLock.sentinel = null; });
            }).catch(function () { /* battery saver or no permission */ }).then(function () {
                wakeLock.pending = false;
            });
        } else if (wakeLock.sentinel) {
            var sentinel = wakeLock.sentinel;
            wakeLock.sentinel = null;
            sentinel.release().catch(function () { /* ignore */ });
        }
    }

    function initWakeLock() {
        document.addEventListener('visibilitychange', syncWakeLock);
        syncWakeLock();
    }

    var MAPS_APP_KEY = 'bakeryDriverMapsApp';

    function storedMapsApp() {
        try {
            var value = window.localStorage.getItem(MAPS_APP_KEY);
            if (value === 'google' || value === 'apple') return value;
        } catch (err) { /* ignore */ }
        return '';
    }

    function preferredMapsApp() {
        return storedMapsApp() || (/iPhone|iPad|iPod/i.test(navigator.userAgent || '') ? 'apple' : 'google');
    }

    function paintMapsAppChoice() {
        var current = preferredMapsApp();
        document.querySelectorAll('[data-maps-app]').forEach(function (btn) {
            btn.setAttribute('aria-pressed', btn.getAttribute('data-maps-app') === current ? 'true' : 'false');
        });
    }

    function setMapsApp(value) {
        if (value !== 'google' && value !== 'apple') return;
        try {
            window.localStorage.setItem(MAPS_APP_KEY, value);
        } catch (err) { /* ignore */ }
        applyNavigateLinks(document);
        paintMapsAppChoice();
    }

    function initMapsAppChoice() {
        paintMapsAppChoice();
    }

    function initDataSaverChoice() {
        var saver = window.DriverDataSaver;
        if (!saver) return;
        document.querySelectorAll('[data-data-saver]').forEach(function (btn) {
            var mode = btn.getAttribute('data-data-saver');
            var on = (mode === 'on' && saver.active) || (mode === 'off' && !saver.active);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    }

    function problemContextLines() {
        var stop = findRouteStop(failModalState.dailyOrderId);
        return stop ? parseStopLines(stop) : [];
    }

    function setProblemError(message) {
        var errorEl = document.getElementById('problemSheetError');
        if (!errorEl) return;
        errorEl.textContent = message || '';
        errorEl.hidden = !message;
    }

    async function postStopAction(body, fallbackError) {
        var csrf = document.querySelector('meta[name="csrf-token"]');
        if (csrf && csrf.getAttribute('content')) body.set('csrf_token', csrf.getAttribute('content'));
        var response = await fetch('complete_delivery.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        });
        var data = null;
        try {
            data = await response.json();
        } catch (parseErr) {
            throw new Error(fallbackError);
        }
        if (!response.ok || !data || !data.success) {
            throw new Error((data && (data.error || data.message)) || fallbackError);
        }
        return data;
    }

    async function runProblemAction(action) {
        var orderId = failModalState.dailyOrderId;
        if (!orderId || failModalState.submitting) return;
        setProblemError('');
        if (action === 'different_amount') {
            closeFailStopModal();
            var stop = findRouteStop(orderId);
            if (stop && openStopDeliveryFromEl(stop, { startStep: 'delivery' })) {
                var adjust = document.getElementById('deliveryAdjustDetails');
                if (adjust) adjust.open = true;
            }
            return;
        }
        failModalState.submitting = true;
        try {
            var body = new URLSearchParams();
            if (action === 'not_on_route') {
                var note = String((document.getElementById('failStopNote') || {}).value || '').trim();
                body.set('action', 'skip_stop');
                body.set('daily_order_id', String(orderId));
                body.set('reason', note ? di.not_on_route_reason + ': ' + note : di.not_on_route_reason);
                var skipped = await postStopAction(body, di.skip_error);
                closeFailStopModal();
                window.DriverRoute.afterStopSkipped(orderId, skipped.message);
            } else if (action === 'empty_order') {
                body.set('action', 'flag_empty_order');
                body.set('daily_order_id', String(orderId));
                var flagged = await postStopAction(body, di.fail_error);
                closeFailStopModal();
                window.DriverRoute.afterOfficeFlag(orderId, flagged.message);
            }
        } catch (err) {
            setProblemError(err.message || di.fail_error);
        } finally {
            failModalState.submitting = false;
        }
    }

    function paintProblemChips() {
        var lines = problemContextLines();
        var pieces = lines.reduce(function (sum, line) { return sum + (Number(line[1]) || 0); }, 0);
        var empty = document.querySelector('[data-problem-action="empty_order"]');
        if (empty) empty.hidden = pieces > 0;
        setProblemError('');
    }

    function vanLoadedKey() {
        var root = routeRoot();
        if (!root) return '';
        return 'bakeryVanLoaded:' + (root.getAttribute('data-driver-id') || '0') + ':' + (root.getAttribute('data-date') || '');
    }

    function vanLoaded() {
        try {
            return window.localStorage.getItem(vanLoadedKey()) === '1';
        } catch (err) {
            return false;
        }
    }

    function syncLoadVan() {
        var manifest = document.querySelector('.driver-pickup-manifest');
        var show = !!manifest && !!manifest.querySelector('li') && !boot.prepMode && !!boot.isToday
            && getPastStops().length === 0 && getActiveStops().length > 0 && !vanLoaded();
        document.body.classList.toggle('now-load-van', show);
        if (!manifest) return;
        var button = document.getElementById('vanLoadedBtn');
        if (show && !button) {
            var body = manifest.querySelector('.driver-pickup-manifest-body') || manifest;
            button = document.createElement('button');
            button.type = 'button';
            button.id = 'vanLoadedBtn';
            button.className = 'route-btn route-btn--primary van-loaded-btn';
            button.textContent = di.load_van_done || '';
            body.appendChild(button);
        }
        if (button) button.hidden = !show;
        if (show && 'open' in manifest) manifest.open = true;
    }

    function markVanLoaded() {
        try {
            window.localStorage.setItem(vanLoadedKey(), '1');
        } catch (err) { /* ignore */ }
        var manifest = document.querySelector('.driver-pickup-manifest');
        if (manifest && 'open' in manifest) manifest.open = false;
        syncLoadVan();
    }

    function haversineMeters(a, b) {
        var rad = Math.PI / 180;
        var dLat = (b.lat - a.lat) * rad;
        var dLng = (b.lng - a.lng) * rad;
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    function stopPoint(stopEl) {
        var lat = parseFloat(stopEl.getAttribute('data-lat') || '');
        var lng = parseFloat(stopEl.getAttribute('data-lng') || '');
        if (!isFinite(lat) || !isFinite(lng) || (lat === 0 && lng === 0)) return null;
        return { lat: lat, lng: lng };
    }

    function straightLineLabel(meters) {
        var feet = meters * 3.28084;
        if (feet < 1000) {
            return String(di.feet_short || ':n ft').replace(':n', String(Math.max(50, Math.round(feet / 50) * 50)));
        }
        return String(di.miles_short || ':n mi').replace(':n', (meters / 1609.344).toFixed(1));
    }

    // Order changes make Directions legs stale; straight lines cover the gap until
    // the map paints real drive times again (and are all a data-saver phone gets).
    function paintStraightLineLegs(resetDirections) {
        var previous = null;
        getActiveStops().forEach(function (stopEl) {
            var badge = stopEl.querySelector('[data-stop-leg]');
            var here = stopPoint(stopEl);
            if (badge && (resetDirections || badge.getAttribute('data-leg-source') !== 'directions')) {
                if (previous && here) {
                    badge.textContent = straightLineLabel(haversineMeters(previous, here));
                    badge.setAttribute('data-leg-source', 'line');
                    badge.hidden = false;
                } else {
                    badge.textContent = '';
                    badge.removeAttribute('data-leg-source');
                    badge.hidden = true;
                }
            }
            if (here) previous = here;
        });
    }

    var SNAPSHOT_KEY = 'bakeryDriverRouteSnapshot';
    var snapshotState = { hash: String(boot.snapshotHash || ''), busy: false, lastAt: Date.now() };
    var SNAPSHOT_MIN_GAP_MS = 20000;

    function storeSnapshot(data) {
        var root = routeRoot();
        if (!root) return;
        try {
            window.localStorage.setItem(SNAPSHOT_KEY, JSON.stringify({
                driver: root.getAttribute('data-driver-id') || '',
                date: root.getAttribute('data-date') || '',
                hash: data.hash,
                stops: data.stops,
                at: Date.now()
            }));
        } catch (err) { /* ignore quota */ }
    }

    function applySnapshot(stops) {
        var changed = false;
        var missing = false;
        var seen = {};
        (stops || []).forEach(function (row) {
            var id = String(row.id);
            seen[id] = true;
            var el = findRouteStop(id);
            if (!el) {
                missing = true;
                return;
            }
            var order = String(row.order == null ? '' : row.order);
            if ((el.getAttribute('data-route-order') || '') !== order) {
                el.setAttribute('data-route-order', order);
                var badge = el.querySelector('.stop-item-order');
                if (badge) badge.textContent = order ? '#' + order : '#-';
                changed = true;
            }
            var status = String(row.status || 'pending');
            if ((el.getAttribute('data-status') || 'pending') !== status) {
                el.setAttribute('data-status', status);
                updateStopStatusBadge(el, status);
                rebuildStopContactActions(el);
                if (status === 'pending' || status === 'in_transit') moveStopToActiveList(el);
                else moveStopToPastList(el);
                changed = true;
            }
        });
        document.querySelectorAll('#stopList .stop-item, #pastStopsList .stop-item').forEach(function (el) {
            if (!seen[String(el.getAttribute('data-daily-order-id') || '')]) {
                el.parentNode.removeChild(el);
                changed = true;
            }
        });
        var root = routeRoot();
        if (root && changed) root.setAttribute('data-total', String((stops || []).length));
        if (changed) {
            refreshRouteUi();
            showSuccessToast(di.route_updated);
        }
        var refresh = document.getElementById('nowStripRefresh');
        if (refresh) refresh.hidden = !missing;
    }

    async function refreshRouteSnapshot(opts) {
        opts = opts || {};
        var root = routeRoot();
        if (!root || boot.prepMode || snapshotState.busy) return;
        if (!opts.force && Date.now() - snapshotState.lastAt < SNAPSHOT_MIN_GAP_MS) return;
        var delivery = window.DriverDelivery;
        if (delivery && typeof delivery.isOpen === 'function' && delivery.isOpen()) return;
        if (routeAdjust.open || dragState.active) return;
        snapshotState.busy = true;
        snapshotState.lastAt = Date.now();
        try {
            var body = new URLSearchParams();
            body.set('action', 'route_snapshot');
            body.set('driver_id', root.getAttribute('data-driver-id') || '0');
            body.set('date', root.getAttribute('data-date') || '');
            body.set('hash', snapshotState.hash);
            var response = await fetch('complete_delivery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin'
            });
            var data = await response.json();
            if (!data || !data.success || data.unchanged) return;
            snapshotState.hash = String(data.hash || '');
            storeSnapshot(data);
            applySnapshot(data.stops || []);
        } catch (err) {
            /* offline: keep the page as it is */
        } finally {
            snapshotState.busy = false;
        }
    }

    function initSnapshotRefresh() {
        if (!routeRoot() || boot.prepMode) return;
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') refreshRouteSnapshot();
        });
        window.addEventListener('bakery-outbox', function (event) {
            if (event && event.detail && event.detail.status === 'synced') refreshRouteSnapshot({ force: true });
        });
        window.addEventListener('online', function () { refreshRouteSnapshot({ force: true }); });
        var refresh = document.getElementById('nowStripRefresh');
        if (refresh) {
            refresh.addEventListener('click', function () { window.location.reload(); });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        applyNavigateLinks(document);
        refreshRouteUi();
        bindSkipStopButtons(document);
        bindUnskipStopButtons(document);
        bindFailStopButtons(document);
        tryOpenStopFromUrl();

        var skipCancelBtn = document.getElementById('skipStopCancelBtn');
        var skipConfirmBtn = document.getElementById('skipStopConfirmBtn');
        var skipBackdrop = document.getElementById('skipStopModalBackdrop');
        var skipReasonInput = document.getElementById('skipStopReasonInput');
        if (skipCancelBtn) skipCancelBtn.addEventListener('click', closeSkipStopModal);
        if (skipBackdrop) skipBackdrop.addEventListener('click', closeSkipStopModal);
        if (skipConfirmBtn) skipConfirmBtn.addEventListener('click', submitSkipStop);
        if (skipReasonInput) {
            skipReasonInput.addEventListener('input', function () {
                skipReasonInput.classList.remove('is-invalid');
                var errorEl = document.getElementById('skipStopError');
                if (errorEl) errorEl.hidden = true;
            });
        }
        var failCancelBtn = document.getElementById('failStopCancelBtn');
        var failBackdrop = document.getElementById('failStopModalBackdrop');
        var failForm = document.getElementById('failStopForm');
        if (failCancelBtn) failCancelBtn.addEventListener('click', closeFailStopModal);
        if (failBackdrop) failBackdrop.addEventListener('click', closeFailStopModal);
        if (failForm) failForm.addEventListener('submit', function (event) {
            event.preventDefault();
            submitFailStop();
        });
        document.addEventListener('keydown', function (event) {
            var skipModal = document.getElementById('skipStopModal');
            var failModal = document.getElementById('failStopModal');
            if (event.key === 'Escape' && skipModal && !skipModal.hidden) {
                closeSkipStopModal();
            }
            if (event.key === 'Escape' && failModal && !failModal.hidden) {
                closeFailStopModal();
            }
        });

        var dayStrip = document.querySelector('.route-day-strip');
        var selectedDay = dayStrip && dayStrip.querySelector('.route-day-chip.is-selected');
        if (selectedDay && selectedDay.scrollIntoView) {
            selectedDay.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
        }

        var dateDisclosure = document.getElementById('routeDateDisclosure');
        var dateNavToggle = document.getElementById('routeDateNavToggle');
        var dateNavOpen = document.getElementById('routeDayNavOpen');
        var dateBackdrop = document.getElementById('routeDateBackdrop');
        var dateOpenKey = 'driver-route-date-open';

        function syncRouteDateChrome() {
            var isOpen = !!(dateDisclosure && dateDisclosure.open);
            document.body.classList.toggle('route-date-sheet-open', isOpen);
            if (dateNavToggle) {
                dateNavToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                dateNavToggle.classList.toggle('bakery-nav__direct--active', isOpen);
            }
            if (dateNavOpen) {
                dateNavOpen.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            }
            if (dateBackdrop) {
                dateBackdrop.hidden = !isOpen;
            }
            try {
                window.sessionStorage.setItem(dateOpenKey, isOpen ? '1' : '0');
            } catch (err) { /* ignore */ }
            if (isOpen && selectedDay && selectedDay.scrollIntoView) {
                selectedDay.scrollIntoView({ behavior: 'auto', block: 'nearest', inline: 'center' });
            }
        }

        if (dateDisclosure) {
            try {
                if (window.sessionStorage.getItem(dateOpenKey) === '1') {
                    dateDisclosure.open = true;
                }
            } catch (err) { /* ignore */ }
            dateDisclosure.addEventListener('toggle', syncRouteDateChrome);
            syncRouteDateChrome();
        }

        if (dateNavToggle && dateDisclosure) {
            dateNavToggle.addEventListener('click', function () {
                dateDisclosure.open = !dateDisclosure.open;
            });
        }
        if (dateNavOpen && dateDisclosure) {
            dateNavOpen.addEventListener('click', function () {
                dateDisclosure.open = !dateDisclosure.open;
            });
        }
        if (dateBackdrop && dateDisclosure) {
            dateBackdrop.addEventListener('click', function () {
                dateDisclosure.open = false;
            });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && dateDisclosure && dateDisclosure.open) {
                dateDisclosure.open = false;
            }
        });

        var dateInput = document.getElementById('routeDateInput');
        if (dateInput) {
            dateInput.addEventListener('change', function () {
                if (!dateInput.value) return;
                document.body.classList.add('route-day-loading');
                window.location.assign(
                    '?driver_id=' + encodeURIComponent(dateInput.getAttribute('data-driver-id') || '0') +
                    '&date=' + encodeURIComponent(dateInput.value)
                );
            });
        }

        document.querySelectorAll('.js-day-link').forEach(function (link) {
            link.addEventListener('click', function (event) {
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                var href = link.href;
                document.body.classList.add('route-day-loading');
                var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                setTimeout(function () { window.location.assign(href); }, reduceMotion ? 0 : 110);
            });
        });

        var touchStart = null;
        var routeSurface = document.getElementById('driverRouteRoot');
        if (routeSurface) {
            routeSurface.addEventListener('touchstart', function (event) {
                if (document.body.classList.contains('photo-mode-open')) return;
                if (event.target.closest('a, button, input, select, textarea, summary, .route-day-strip, .route-day-nav, .route-date-disclosure, .route-date-dock, .route-date-backdrop, .order-details-container')) return;
                var touch = event.changedTouches && event.changedTouches[0];
                if (touch) touchStart = { x: touch.clientX, y: touch.clientY };
            }, { passive: true });
            routeSurface.addEventListener('touchend', function (event) {
                if (!touchStart) return;
                if (document.body.classList.contains('route-adjust-open')) {
                    touchStart = null;
                    return;
                }
                var touch = event.changedTouches && event.changedTouches[0];
                if (!touch) return;
                var dx = touch.clientX - touchStart.x;
                var dy = touch.clientY - touchStart.y;
                touchStart = null;
                if (Math.abs(dx) < 90 || Math.abs(dx) < Math.abs(dy) * 1.4) return;
                var targetUrl = dx < 0 ? boot.nextDayUrl : boot.previousDayUrl;
                if (!targetUrl) return;
                document.body.classList.add('route-day-loading');
                window.location.assign(String(targetUrl));
            }, { passive: true });
        }

        var root = document.getElementById('driverRouteRoot');
        var selectedId = root && root.getAttribute('data-driver-id');
        if (selectedId && parseInt(selectedId, 10) > 0) {
            try {
                localStorage.setItem('tracking_driver_id', String(selectedId));
            } catch (err) { /* ignore */ }
        }

        var pastDetails = document.getElementById('pastStopsDetails');
        function openPastHistory(button) {
            if (!pastDetails || button.disabled) return;
            pastDetails.hidden = false;
            pastDetails.open = true;
            setTimeout(function () {
                var target = document.querySelector('#pastStopsList .stop-item') || pastDetails;
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 50);
        }
        var completedButton = document.getElementById('routeCompletedButton');
        var pastButton = document.getElementById('routePastStopsButton');
        if (completedButton) completedButton.addEventListener('click', function () { openPastHistory(completedButton); });
        if (pastButton) pastButton.addEventListener('click', function () { openPastHistory(pastButton); });

        document.addEventListener('click', function (e) {
            var adjustToggle = e.target.closest('#routeAdjustToggle');
            if (adjustToggle) {
                e.preventDefault();
                if (routeAdjust.open) {
                    closeRouteAdjust(true);
                } else {
                    openRouteAdjust();
                }
                return;
            }
            var changeNext = e.target.closest('.change-next-btn');
            if (changeNext) {
                e.preventDefault();
                openRouteAdjust();
                return;
            }
            var cancelAdjust = e.target.closest('#routeAdjustCancel');
            if (cancelAdjust) {
                e.preventDefault();
                closeRouteAdjust(true);
                return;
            }
            var saveAdjust = e.target.closest('#routeAdjustSave');
            if (saveAdjust) {
                e.preventDefault();
                saveRouteAdjust();
                return;
            }
            var goNextBtn = e.target.closest('.stop-go-next');
            if (goNextBtn) {
                e.preventDefault();
                e.stopPropagation();
                goNextNow(goNextBtn.getAttribute('data-daily-order-id')).catch(function (err) {
                    alert(err.message || di.route_order_error);
                });
                return;
            }
            var moveUpBtn = e.target.closest('.stop-move-up, .stop-move-down');
            if (moveUpBtn) {
                e.preventDefault();
                e.stopPropagation();
                if (!routeAdjust.open) openRouteAdjust();
                moveStopInAdjust(moveUpBtn.getAttribute('data-daily-order-id'), moveUpBtn.classList.contains('stop-move-up') ? 'up' : 'down');
                return;
            }
            var problemBtn = e.target.closest('.stop-problem-btn');
            if (problemBtn) {
                e.preventDefault();
                e.stopPropagation();
                openFailStopModal(
                    problemBtn.getAttribute('data-daily-order-id'),
                    problemBtn.getAttribute('data-assignment-id') || '',
                    problemBtn.getAttribute('data-customer-name') || ''
                );
                return;
            }
            var problemChip = e.target.closest('[data-problem-action]');
            if (problemChip) {
                e.preventDefault();
                runProblemAction(problemChip.getAttribute('data-problem-action'));
                return;
            }
            var viewTarget = e.target.closest('[data-route-view-target]');
            if (viewTarget) {
                e.preventDefault();
                setRouteView(viewTarget.getAttribute('data-route-view-target'), { push: true });
                return;
            }
            if (e.target.closest('#routeSuggestBtn')) {
                e.preventDefault();
                suggestRouteOrder();
                return;
            }
            if (e.target.closest('.route-suggest-accept')) {
                e.preventDefault();
                acceptSuggestedOrder();
                return;
            }
            if (e.target.closest('.route-suggest-dismiss')) {
                e.preventDefault();
                hideSuggestPreview();
                return;
            }
            var notesBtn = e.target.closest('#nextStopNotes');
            if (notesBtn) {
                var clamped = notesBtn.classList.toggle('is-clamped');
                notesBtn.setAttribute('aria-expanded', clamped ? 'false' : 'true');
                return;
            }
            if (e.target.closest('#vanLoadedBtn')) {
                e.preventDefault();
                markVanLoaded();
                return;
            }
            if (e.target.closest('#nowStripDate')) {
                e.preventDefault();
                var disclosure = document.getElementById('routeDateDisclosure');
                if (disclosure) disclosure.open = !disclosure.open;
                return;
            }
            if (e.target.closest('#driverNavProblem')) {
                e.preventDefault();
                var nextCard = document.getElementById('nextStopCard');
                var cardProblem = nextCard && nextCard.querySelector('.stop-problem-btn');
                if (cardProblem) cardProblem.click();
                return;
            }
            var saverToggle = e.target.closest('[data-data-saver]');
            if (saverToggle && window.DriverDataSaver) {
                e.preventDefault();
                window.DriverDataSaver.set(saverToggle.getAttribute('data-data-saver'));
                return;
            }
            var prepOrdersBtn = e.target.closest('.route-prep-stop .stop-orders-btn');
            if (prepOrdersBtn) {
                e.preventDefault();
                var prepStop = prepOrdersBtn.closest('.route-prep-stop');
                var prepWrap = prepStop && prepStop.querySelector('.order-details-container');
                if (prepWrap) loadOrderDetails(prepWrap, prepOrdersBtn.getAttribute('data-daily-order-id') || '0');
                return;
            }
            var mapsChoice = e.target.closest('[data-maps-app]');
            if (mapsChoice) {
                e.preventDefault();
                setMapsApp(mapsChoice.getAttribute('data-maps-app'));
                return;
            }

            var stop = e.target.closest('.stop-item');
            if (!stop) return;
            if (e.target.closest('.contact-actions, .stop-row-actions, a, button, input, select, textarea, label')) {
                return;
            }
            if (document.body.classList.contains('route-adjust-open')) {
                return;
            }
            var status = stop.getAttribute('data-status') || '';
            if (stop.closest('#stopList') && status !== 'delivered' && status !== 'cancelled' && status !== 'failed') {
                renderNextStop(stop);
                setRouteView('now', { push: true });
                var card = document.getElementById('nextStopCard');
                if (card && card.scrollIntoView) card.scrollIntoView({ block: 'start' });
                return;
            }
            openStopDeliveryFromEl(stop, { autoOpenCamera: false });
        });

        bindReorderDrag();
        initRouteView();
        initWakeLock();
        initMapsAppChoice();
        initDataSaverChoice();
        initSnapshotRefresh();
        syncLoadVan();
        paintStraightLineLegs();
    });
})();
