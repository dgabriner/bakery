const apiKey = (window.__ROUTE_MANAGER__ && window.__ROUTE_MANAGER__.apiKey) || '';
const drivers = (window.__ROUTE_MANAGER__ && window.__ROUTE_MANAGER__.drivers) || [];

const driverColors = [
    '#FF6B6B', '#4ECDC4', '#45B7D1', '#96CEB4', '#FFEAA7',
    '#DDA0DD', '#FF8A65', '#81C784', '#64B5F6', '#FFB74D',
    '#F06292', '#AED581', '#90CAF9', '#FFCC02', '#FF7043'
];

const statusLabels = {
    pending: 'Pending',
    in_transit: 'In transit',
    delivered: 'Delivered',
    failed: 'Failed',
    cancelled: 'Cancelled'
};

const paymentLabels = {
    cod: 'COD',
    signature: 'Signature'
};

function formatMoney(amount) {
    return '$' + Number(amount || 0).toFixed(2);
}

function stopPaymentAmount(delivery) {
    if ((delivery.payment_collection || 'cod') !== 'cod') {
        return null;
    }
    if (delivery.delivery_status === 'delivered') {
        if (delivery.amount_collected != null) {
            return delivery.amount_collected;
        }
        if (delivery.delivery_order_total > 0) {
            return delivery.delivery_order_total;
        }
        return delivery.total_amount > 0 ? delivery.total_amount : delivery.order_total_estimate;
    }
    if (delivery.delivery_order_total > 0) {
        return delivery.delivery_order_total;
    }
    if (delivery.total_amount > 0) {
        return delivery.total_amount;
    }
    return delivery.order_total_estimate || 0;
}

let map;
let geocoder;
let driversData = {};
let pickupGrid = { drivers: [], rows: [], families: [], store_view: [] };
let pickupView = 'product';
let pickupFamily = 'all';
let deliveryMarkers = [];
let markersByOrderId = {};
let driverPaths = {};
let infoWindow;
let pendingGeocode = 0;
let reorderSaveTimer = null;
let didDragStop = false;
let deliveriesRequestSeq = 0;
let trackingRequestSeq = 0;
let deliveriesAbortController = null;
let trackingAbortController = null;

function initMap() {
    const mapEl = document.getElementById('route-map');
    if (!mapEl || typeof google === 'undefined' || !google.maps) {
        return;
    }
    map = new google.maps.Map(mapEl, {
        zoom: 11,
        center: { lat: 37.7749, lng: -122.4194 },
        mapTypeId: 'roadmap',
        streetViewControl: false,
        mapTypeControl: false,
        styles: [
            {
                featureType: 'poi',
                elementType: 'labels',
                stylers: [{ visibility: 'off' }]
            }
        ]
    });
    geocoder = new google.maps.Geocoder();
    infoWindow = new google.maps.InfoWindow();
    // Deliveries load on DOMContentLoaded; refresh map markers if data already arrived.
    if (Object.keys(driversData).length) {
        updateMap();
    }
}

function getSelectedDrivers() {
    const checkboxes = document.querySelectorAll('.driver-select:checked');
    return Array.from(checkboxes).map(cb => parseInt(cb.getAttribute('data-driver-id'), 10));
}

function driverColor(driverId) {
    const driverIndex = drivers.findIndex(d => String(d.id) === String(driverId));
    const index = driverIndex >= 0 ? driverIndex : 0;
    return driverColors[index % driverColors.length];
}

function formatTime(value) {
    if (!value) return '—';
    const parts = String(value).split(':');
    if (parts.length < 2) return value;
    let hours = parseInt(parts[0], 10);
    const minutes = parts[1];
    const ampm = hours >= 12 ? 'PM' : 'AM';
    hours = hours % 12 || 12;
    return hours + ':' + minutes + ' ' + ampm;
}

function loadDeliveries(options) {
    const background = options && options.background === true;
    const skipMap = options && options.skipMap === true;
    const selectedDate = document.getElementById('tracking-date').value;
    const selectedDrivers = getSelectedDrivers();
    const selectedDriversKey = selectedDrivers.slice().sort((a, b) => a - b).join(',');
    const requestSeq = ++deliveriesRequestSeq;

    if (deliveriesAbortController) deliveriesAbortController.abort();
    deliveriesAbortController = typeof AbortController === 'function' ? new AbortController() : null;

    if (selectedDrivers.length === 0) {
        driversData = {};
        pickupGrid = { drivers: [], rows: [], families: [], store_view: [] };
        clearMapElements();
        renderGpsActivity({});
        updateStatistics();
        updateLegend();
        updateDeliveryList();
        renderPickupBoard();
        updateLastRefreshTime();
        return;
    }

    const formData = new FormData();
    formData.append('action', 'get_deliveries');
    formData.append('date', selectedDate);
    formData.append('driver_ids', JSON.stringify(selectedDrivers));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData,
        signal: deliveriesAbortController ? deliveriesAbortController.signal : undefined,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(async response => {
        const data = await response.json().catch(() => null);
        if (!response.ok || !data) {
            throw new Error((data && data.error) || ('HTTP ' + response.status));
        }
        return data;
    })
    .then(data => {
        const currentDriversKey = getSelectedDrivers().slice().sort((a, b) => a - b).join(',');
        if (requestSeq !== deliveriesRequestSeq
            || document.getElementById('tracking-date').value !== selectedDate
            || currentDriversKey !== selectedDriversKey) {
            return;
        }
        if (data.success) {
            driversData = data.data || {};
            pickupGrid = data.pickup_grid || { drivers: [], rows: [], families: [], store_view: [] };
            if (!skipMap) {
                updateMap();
            }
            updateStatistics();
            updateLegend();
            updateDeliveryList();
            if (pickupSheetState && pickupSheetState.kind === 'store') {
                const storeId = pickupSheetState.store && pickupSheetState.store.customer_id;
                const driverId = pickupSheetState.driverId;
                renderPickupBoard();
                if (storeId) {
                    openPickupStoreSheet(null, storeId, driverId);
                }
            } else if (!pickupSheetState) {
                renderPickupBoard();
            }
            updateLastRefreshTime();
            if (!skipMap) {
                loadTrackingOverlay({ background: background });
            }
        } else {
            console.error('Failed to load deliveries:', data.error);
            if (!background) showError('Failed to load deliveries: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        if ((error && error.name === 'AbortError') || requestSeq !== deliveriesRequestSeq) return;
        console.error('Error loading deliveries:', error);
        if (!background) {
            showError('Network error loading deliveries' + (error && error.message ? ': ' + error.message : ''));
        }
    });
}

function loadTrackingOverlay(options) {
    const background = options && options.background === true;
    const selectedDate = document.getElementById('tracking-date').value;
    const selectedDrivers = getSelectedDrivers();
    const selectedDriversKey = selectedDrivers.slice().sort((a, b) => a - b).join(',');
    const requestSeq = ++trackingRequestSeq;

    if (trackingAbortController) trackingAbortController.abort();
    trackingAbortController = typeof AbortController === 'function' ? new AbortController() : null;

    const formData = new FormData();
    formData.append('action', 'get_tracking_data');
    formData.append('date', selectedDate);
    formData.append('driver_ids', JSON.stringify(selectedDrivers));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData,
        signal: trackingAbortController ? trackingAbortController.signal : undefined,
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => response.json())
    .then(data => {
        const currentDriversKey = getSelectedDrivers().slice().sort((a, b) => a - b).join(',');
        if (requestSeq !== trackingRequestSeq
            || document.getElementById('tracking-date').value !== selectedDate
            || currentDriversKey !== selectedDriversKey) {
            return;
        }
        clearTrackingPaths();
        renderGpsActivity(data && data.success ? data.data : {});
        if (!map || !data.success || !data.data || !document.getElementById('show-tracking').checked) return;

        Object.keys(data.data).forEach(driverId => {
            const points = data.data[driverId].points || [];
            if (points.length < 2) return;
            const path = new google.maps.Polyline({
                path: points.map(p => ({ lat: p.lat, lng: p.lng })),
                geodesic: true,
                strokeColor: driverColor(driverId),
                strokeOpacity: 0.45,
                strokeWeight: 3,
                map: map
            });
            driverPaths[driverId] = path;
        });
    })
    .catch(err => {
        if ((err && err.name === 'AbortError') || requestSeq !== trackingRequestSeq) return;
        console.warn('Tracking overlay failed:', err);
        if (!background) renderGpsActivity({});
    });
}

function formatGpsActivityTime(value) {
    if (!value) return 'Time unavailable';
    const parsed = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(parsed.getTime())) return String(value);
    return parsed.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
}

function renderGpsActivity(data) {
    const list = document.getElementById('gps-activity-list');
    const status = document.getElementById('gps-activity-status');
    if (!list || !status) return;

    const events = [];
    Object.keys(data || {}).forEach(driverId => {
        const driver = data[driverId] || {};
        (driver.points || []).forEach(point => {
            events.push({
                driverId: String(driverId),
                driverName: driver.name || 'Driver',
                lat: Number(point.lat),
                lng: Number(point.lng),
                timestamp: point.timestamp || ''
            });
        });
    });

    events.sort((a, b) => String(b.timestamp).localeCompare(String(a.timestamp)));
    if (!events.length) {
        status.textContent = 'No GPS updates yet';
        list.innerHTML = '<p class="gps-activity-empty">No location updates have been recorded for the selected day.</p>';
        return;
    }

    const shown = events.slice(0, 60);
    status.textContent = events.length + (events.length === 1 ? ' update' : ' updates');
    list.innerHTML = shown.map(event =>
        '<button type="button" class="gps-activity-item" data-driver-id="' + escapeHtml(event.driverId) +
        '" data-lat="' + escapeHtml(String(event.lat)) + '" data-lng="' + escapeHtml(String(event.lng)) + '">' +
            '<span class="gps-activity-time">' + escapeHtml(formatGpsActivityTime(event.timestamp)) + '</span>' +
            '<span class="gps-activity-detail"><strong>' + escapeHtml(event.driverName) + '</strong><span>Location updated</span></span>' +
            '<span class="gps-activity-map">View map</span>' +
        '</button>'
    ).join('');

    list.querySelectorAll('.gps-activity-item').forEach(item => {
        item.addEventListener('click', function() {
            const lat = Number(this.dataset.lat);
            const lng = Number(this.dataset.lng);
            if (!map || !Number.isFinite(lat) || !Number.isFinite(lng)) return;
            map.panTo({ lat, lng });
            if (map.getZoom() < 14) map.setZoom(14);
        });
    });
}

function clearTrackingPaths() {
    Object.values(driverPaths).forEach(path => path.setMap(null));
    driverPaths = {};
}

function clearMapElements() {
    deliveryMarkers.forEach(marker => marker.setMap(null));
    deliveryMarkers = [];
    markersByOrderId = {};
    clearTrackingPaths();
    if (infoWindow) infoWindow.close();
}

function markerIcon(color, label) {
    return {
        path: google.maps.SymbolPath.CIRCLE,
        fillColor: color,
        fillOpacity: 1,
        strokeColor: '#ffffff',
        strokeWeight: 2,
        scale: 12,
        labelOrigin: new google.maps.Point(0, 0)
    };
}

function deliveryInfoHtml(driverName, delivery, driverId) {
    const status = statusLabels[delivery.delivery_status] || delivery.delivery_status;
    const detailsBtn = `<br><button type="button" class="btn btn-primary map-photos-btn"
                onclick="viewStopDetail(${parseInt(driverId, 10)}, ${parseInt(delivery.daily_order_id, 10)})">
                View stop details
           </button>`;
    return `
        <div class="map-info-window">
            <strong>#${delivery.route_order || '—'} ${escapeHtml(delivery.customer_name)}</strong><br>
            <span>${escapeHtml(driverName)}</span><br>
            <span>${escapeHtml(delivery.address || 'No address')}</span><br>
            <span>Zone: ${escapeHtml(delivery.zone)}</span><br>
            <span>Status: ${escapeHtml(status)}</span><br>
            <span>Payment: ${escapeHtml(paymentLabels[delivery.payment_collection] || delivery.payment_collection || 'Signature')}</span><br>
            ${stopPaymentAmount(delivery) != null ? '<span>Amount: ' + formatMoney(stopPaymentAmount(delivery)) + '</span><br>' : ''}
            <span>Scheduled: ${escapeHtml(formatTime(delivery.scheduled_delivery_time))}</span>
            ${delivery.item_count ? '<br><span>Items: ' + delivery.item_count + '</span>' : ''}
            ${detailsBtn}
        </div>
    `;
}

function placeMarker(position, driverId, driverName, delivery, bounds) {
    const color = driverColor(driverId);
    const labelText = delivery.route_order > 0 ? String(delivery.route_order) : '•';
    const marker = new google.maps.Marker({
        position: position,
        map: map,
        title: delivery.customer_name,
        label: {
            text: labelText,
            color: '#ffffff',
            fontSize: '11px',
            fontWeight: 'bold'
        },
        icon: markerIcon(color, labelText)
    });

    marker.addListener('click', () => {
        infoWindow.setContent(deliveryInfoHtml(driverName, delivery, driverId));
        infoWindow.open(map, marker);
        highlightListItem(driverId, delivery.daily_order_id);
    });

    deliveryMarkers.push(marker);
    markersByOrderId[String(delivery.daily_order_id)] = marker;
    bounds.extend(position);
}

function updateMap() {
    if (!map) return;
    clearMapElements();

    const bounds = new google.maps.LatLngBounds();
    let hasPoints = false;
    pendingGeocode = 0;

    const maybeFit = () => {
        if (pendingGeocode > 0) return;
        if (hasPoints) {
            map.fitBounds(bounds);
            google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
                if (map.getZoom() > 15) map.setZoom(15);
            });
        }
    };

    Object.keys(driversData).forEach(driverId => {
        const driverData = driversData[driverId];
        (driverData.deliveries || []).forEach(delivery => {
            const hasCoords = delivery.latitude != null && delivery.longitude != null
                && !isNaN(delivery.latitude) && !isNaN(delivery.longitude)
                && !(delivery.latitude === 0 && delivery.longitude === 0);

            if (hasCoords) {
                hasPoints = true;
                placeMarker(
                    { lat: delivery.latitude, lng: delivery.longitude },
                    driverId,
                    driverData.name,
                    delivery,
                    bounds
                );
            } else if (delivery.address && geocoder) {
                pendingGeocode++;
                geocoder.geocode({ address: delivery.address }, (results, status) => {
                    pendingGeocode--;
                    if (status === 'OK' && results[0]) {
                        hasPoints = true;
                        placeMarker(
                            results[0].geometry.location,
                            driverId,
                            driverData.name,
                            delivery,
                            bounds
                        );
                    }
                    maybeFit();
                });
            }
        });
    });

    maybeFit();
}

function updateStatistics() {
    let total = 0;
    let pending = 0;
    let delivered = 0;
    let cashOnHand = 0;
    let turnInTotal = 0;
    let totalSold = 0;
    const activeDrivers = Object.keys(driversData).filter(id => (driversData[id].deliveries || []).length > 0).length;

    Object.values(driversData).forEach(driver => {
        const summary = driver.cash_summary || {};
        cashOnHand += Number(summary.cash_on_hand) || 0;
        turnInTotal += Number(summary.turn_in_total) || 0;
        totalSold += Number(summary.total_sold) || 0;
        (driver.deliveries || []).forEach(d => {
            total++;
            if (d.delivery_status === 'delivered') delivered++;
            else if (d.delivery_status === 'pending' || d.delivery_status === 'in_transit') pending++;
        });
    });

    document.getElementById('active-drivers-count').textContent = activeDrivers;
    document.getElementById('total-deliveries-count').textContent = total;
    document.getElementById('pending-count').textContent = pending;
    document.getElementById('delivered-count').textContent = delivered;
    document.getElementById('cod-cash-on-hand').textContent = formatMoney(cashOnHand);
    document.getElementById('cod-turn-in-total').textContent = formatMoney(turnInTotal);
    const soldEl = document.getElementById('route-total-sold');
    if (soldEl) soldEl.textContent = formatMoney(totalSold);
}

function updateLegend() {
    const legendContent = document.getElementById('legend-content');
    const entries = Object.keys(driversData).filter(id => (driversData[id].deliveries || []).length > 0);

    if (entries.length === 0) {
        legendContent.innerHTML = '<p class="text-muted">No assigned deliveries for selected date/drivers</p>';
        return;
    }

    legendContent.innerHTML = entries.map(driverId => {
        const driverData = driversData[driverId];
        const color = driverColor(driverId);
        const count = driverData.deliveries.length;
        const cash = driverData.cash_summary || {};
        const cashLine = (cash.cod_stop_count || 0) > 0
            ? `<div class="legend-details">Cash: ${formatMoney(cash.cash_on_hand)} on hand · ${formatMoney(cash.turn_in_total)} turn-in</div>`
            : '';
        const soldLine = Number(cash.total_sold) > 0
            ? `<div class="legend-details">Sold: ${formatMoney(cash.total_sold)}</div>`
            : '';
        const pickupLine = (driverData.pickup_sku_count || 0) > 0
            ? `<div class="legend-details">Pickup: ${driverData.pickup_sku_count} product${driverData.pickup_sku_count === 1 ? '' : 's'} · ${driverData.pickup_piece_count || 0} pcs</div>`
            : `<div class="legend-details">Pickup: not saved</div>`;
        return `
            <div class="legend-item">
                <div class="legend-color" style="background-color: ${color};"></div>
                <div class="legend-info">
                    <strong>${escapeHtml(driverData.name)}</strong>
                    <div class="legend-details">${count} stop${count === 1 ? '' : 's'}</div>
                    ${pickupLine}
                    ${cashLine}
                    ${soldLine}
                </div>
            </div>
        `;
    }).join('');
}

function pickupCsrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

function pickupSplit(pieces, per) {
    const qty = Number(pieces) || 0;
    const size = Number(per) || 0;
    if (size < 2) return { whole: 0, remainder: qty };
    return { whole: Math.floor(qty / size), remainder: qty % size };
}

function pickupWorkingPieces(cell) {
    const required = Number(cell && cell.required) || 0;
    if (required > 0) return required;
    return Number(cell && cell.pieces) || 0;
}

function pickupCellLabel(cell, unit, perTray, perBox) {
    const pieces = pickupWorkingPieces(cell);
    if (pieces <= 0) return '—';
    if (unit === 'trays') {
        if (!(Number(perTray) > 1)) return String(pieces);
        const split = pickupSplit(pieces, perTray);
        return split.remainder > 0 ? (split.whole + '+' + split.remainder) : String(split.whole);
    }
    if (unit === 'boxes') {
        if (!(Number(perBox) > 1)) return String(pieces);
        const split = pickupSplit(pieces, perBox);
        return split.remainder > 0 ? (split.whole + '+' + split.remainder) : String(split.whole);
    }
    return String(pieces);
}

function pickupVisibleRows() {
    const rows = (pickupGrid && pickupGrid.rows) || [];
    if (pickupFamily === 'all') return rows;
    return rows.filter(row => (row.product_line_name || 'Other') === pickupFamily);
}

function pickupVisibleProductIds() {
    return pickupVisibleRows().map(row => Number(row.product_id)).filter(id => id > 0);
}

function pickupVisibleStoreRows() {
    const stores = (pickupGrid && pickupGrid.store_view) || [];
    if (pickupFamily === 'all') return stores;
    return stores.map(store => {
        const cells = (store.cells || []).map(cell => {
            const products = (cell.products || []).filter(item => (item.product_line_name || 'Other') === pickupFamily);
            const required = products.reduce((n, item) => n + (Number(item.quantity) || 0), 0);
            const expected = products.reduce((n, item) => n + (Number(item.expected_qty) || 0), 0);
            return Object.assign({}, cell, { products: products, required: required, expected: expected });
        });
        const totalRequired = cells.reduce((n, cell) => n + (Number(cell.required) || 0), 0);
        const totalExpected = cells.reduce((n, cell) => n + (Number(cell.expected) || 0), 0);
        return Object.assign({}, store, { cells: cells, total_required: totalRequired, total_expected: totalExpected });
    }).filter(store => store.cells.some(cell => (cell.products || []).length));
}

function pickupMethodButtons(board, extraClass) {
    const items = [
        { method: 'supposed', label: board.dataset.balance || 'Balance to supposed', cls: 'btn btn-primary' },
        { method: 'by_van', label: board.dataset.byVan || 'Balance by van', cls: 'btn' },
        { method: 'little_shop', label: board.dataset.little || 'Extra loaves to little shops', cls: 'btn pickup-surprise' },
        { method: 'trays', label: board.dataset.trays || 'Pack into trays', cls: 'btn' },
        { method: 'standing', label: board.dataset.standingAim || 'Aim at standing', cls: 'btn' }
    ];
    return items.map(item =>
        '<button type="button" class="' + item.cls + (extraClass ? ' ' + extraClass : '') +
        '" data-act="allocate-scope" data-method="' + item.method + '">' + escapeHtml(item.label) + '</button>'
    ).join('');
}

function renderPickupTools() {
    const board = document.getElementById('pickup-manifest-board');
    const tools = document.getElementById('pickup-board-tools');
    if (!board || !tools) return;
    const families = (pickupGrid && pickupGrid.families) || [];
    const familyBtns = ['<button type="button" class="pickup-family' + (pickupFamily === 'all' ? ' is-active' : '') +
        '" data-act="family" data-family="all">' + escapeHtml(board.dataset.familyAll || 'All products') + '</button>']
        .concat(families.map(family =>
            '<button type="button" class="pickup-family' + (pickupFamily === family.name ? ' is-active' : '') +
            '" data-act="family" data-family="' + escapeHtml(family.name) + '">' +
            escapeHtml(family.name) + '</button>'
        ));
    tools.innerHTML =
        '<div class="pickup-view-toggle" role="group">' +
        '<button type="button" class="pickup-view-btn' + (pickupView === 'product' ? ' is-active' : '') +
        '" data-act="view" data-view="product">' + escapeHtml(board.dataset.viewProduct || 'By product') + '</button>' +
        '<button type="button" class="pickup-view-btn' + (pickupView === 'store' ? ' is-active' : '') +
        '" data-act="view" data-view="store">' + escapeHtml(board.dataset.viewStore || 'By store') + '</button>' +
        '</div>' +
        '<div class="pickup-family-row">' + familyBtns.join('') + '</div>' +
        '<p class="pickup-scope-help">' + escapeHtml(board.dataset.scopeHelp || '') + '</p>' +
        '<div class="pickup-sheet-methods pickup-board-methods">' + pickupMethodButtons(board) + '</div>';
}

function renderPickupBoard() {
    const board = document.getElementById('pickup-manifest-board');
    const tableWrap = document.getElementById('pickup-board-table');
    if (!board || !tableWrap) return;
    const unit = board.dataset.unit || 'pieces';
    const drivers = (pickupGrid && pickupGrid.drivers) || [];
    const rows = pickupVisibleRows();
    board.hidden = false;
    renderPickupTools();
    if (!rows.length) {
        tableWrap.innerHTML = '<p class="text-muted">' + escapeHtml(board.dataset.empty || 'No pickup load saved yet.') + '</p>';
        return;
    }
    const headDrivers = drivers.map(driver =>
        '<th scope="col">' + escapeHtml(driver.name || '') + '</th>'
    ).join('');
    if (pickupView === 'store') {
        const stores = pickupVisibleStoreRows();
        const body = stores.map(store => {
            const cells = (store.cells || []).map(cell => {
                const qty = Number(cell.required) || 0;
                const expected = Number(cell.expected) || 0;
                const extra = expected > 0 && expected !== qty
                    ? '<span class="pickup-required">' + escapeHtml(String(expected)) + '</span>'
                    : '';
                const skuCount = (cell.products || []).length;
                return '<td class="pickup-qty"><button type="button" class="pickup-cell-btn" data-store-id="' +
                    escapeHtml(String(store.customer_id)) + '" data-driver-id="' + escapeHtml(String(cell.driver_id)) + '">' +
                    escapeHtml(qty ? String(qty) : '—') + extra +
                    (skuCount ? '<small>' + skuCount + '</small>' : '') +
                    '</button></td>';
            }).join('');
            return '<tr data-store-id="' + escapeHtml(String(store.customer_id)) + '">' +
                '<th scope="row"><button type="button" class="pickup-store-btn" data-store-id="' +
                escapeHtml(String(store.customer_id)) + '">' + escapeHtml(store.name || '') + '</button></th>' +
                cells +
                '<td class="pickup-qty pickup-qty--total">' + escapeHtml(String(store.total_required || 0)) + '</td>' +
                '</tr>';
        }).join('');
        tableWrap.innerHTML = '<div class="pickup-table-scroll"><table class="pickup-table">' +
            '<thead><tr>' +
            '<th scope="col">' + escapeHtml(board.dataset.colStore || 'Store') + '</th>' +
            headDrivers +
            '<th scope="col">' + escapeHtml(board.dataset.colTotal || 'Total') + '</th>' +
            '</tr></thead><tbody>' + body + '</tbody></table></div>';
        tableWrap.querySelectorAll('.pickup-cell-btn').forEach(btn => {
            btn.addEventListener('click', () => openPickupStoreSheet(btn, Number(btn.dataset.storeId), Number(btn.dataset.driverId)));
        });
        tableWrap.querySelectorAll('.pickup-store-btn').forEach(btn => {
            btn.addEventListener('click', () => openPickupStoreSheet(btn, Number(btn.dataset.storeId), 0));
        });
        return;
    }
    const body = rows.map(row => {
        const perTray = row.pieces_per_tray;
        const perBox = row.pieces_per_box;
        const cells = (row.cells || []).map(cell => {
            const label = pickupCellLabel(cell, unit, perTray, perBox);
            return '<td class="pickup-qty"><button type="button" class="pickup-cell-btn" data-driver-id="' +
                escapeHtml(String(cell.driver_id)) + '">' + escapeHtml(label) + '</button></td>';
        }).join('');
        const totalPieces = Number(row.total_required) > 0 ? row.total_required : row.total_pieces;
        const totalCell = pickupCellLabel({ pieces: totalPieces, required: totalPieces }, unit, perTray, perBox);
        return '<tr data-product-id="' + escapeHtml(String(row.product_id)) + '">' +
            '<th scope="row"><button type="button" class="pickup-product-btn">' + escapeHtml(row.name || '') + '</button></th>' +
            '<td class="pickup-units"><input type="number" min="0" max="500" step="1" inputmode="numeric" class="pickup-per-input" data-field="tray" aria-label="' +
            escapeHtml(board.dataset.colTray || 'Pcs / tray') + '" value="' +
            (perTray ? escapeHtml(String(perTray)) : '') + '"></td>' +
            '<td class="pickup-units"><input type="number" min="0" max="500" step="1" inputmode="numeric" class="pickup-per-input" data-field="box" aria-label="' +
            escapeHtml(board.dataset.colBox || 'Pcs / box') + '" value="' +
            (perBox ? escapeHtml(String(perBox)) : '') + '"></td>' +
            cells +
            '<td class="pickup-qty pickup-qty--total">' + escapeHtml(totalCell) + '</td>' +
            '</tr>';
    }).join('');
    tableWrap.innerHTML = '<div class="pickup-table-scroll"><table class="pickup-table">' +
        '<thead><tr>' +
        '<th scope="col">' + escapeHtml(board.dataset.colProduct || 'Product') + '</th>' +
        '<th scope="col">' + escapeHtml(board.dataset.colTray || '') + '</th>' +
        '<th scope="col">' + escapeHtml(board.dataset.colBox || '') + '</th>' +
        headDrivers +
        '<th scope="col">' + escapeHtml(board.dataset.colTotal || 'Total') + '</th>' +
        '</tr></thead><tbody>' + body + '</tbody></table></div>';

    tableWrap.querySelectorAll('.pickup-per-input').forEach(input => {
        input.addEventListener('change', () => savePickupPackUnits(input));
    });
    tableWrap.querySelectorAll('.pickup-cell-btn').forEach(btn => {
        btn.addEventListener('click', () => openPickupSheet(btn, Number(btn.dataset.driverId)));
    });
    tableWrap.querySelectorAll('.pickup-product-btn').forEach(btn => {
        btn.addEventListener('click', () => openPickupSheet(btn, 0));
    });
}

let pickupSheetState = null;

function pickupSheetClone(gridRow, drivers, focusDriverId, focusCustomerId) {
    return {
        productId: Number(gridRow.product_id),
        productName: gridRow.name || '',
        focusDriverId: Number(focusDriverId) || 0,
        focusCustomerId: Number(focusCustomerId) || 0,
        showAllStops: false,
        hand: 0,
        source: null,
        dest: null,
        chunk: '1',
        tray: Number(gridRow.pieces_per_tray) || 0,
        box: Number(gridRow.pieces_per_box) || 0,
        drivers: drivers.map(driver => {
            const cell = (gridRow.cells || []).find(item => Number(item.driver_id) === Number(driver.id)) || {};
            const stores = (cell.stores || []).map(store => ({
                customer_id: Number(store.customer_id),
                name: store.name || '',
                origin: Number(store.quantity) || 0,
                draft: Number(store.quantity) || 0,
                locked: !!store.locked,
                standing: Number(store.standing_qty) || 0,
                expected: Number(store.expected_qty) || 0,
                source: store.source || 'none'
            }));
            return { id: Number(driver.id), name: driver.name || '', stores: stores };
        })
    };
}

function pickupSheetChunkSize() {
    const state = pickupSheetState;
    if (!state) return 1;
    if (state.chunk === 'five') return 5;
    if (state.chunk === 'tray') return Math.max(2, Number(state.tray) || 0) || 1;
    if (state.chunk === 'box') return Math.max(2, Number(state.box) || 0) || 1;
    return 1;
}

function pickupSheetApplyDraft(store, next) {
    if (!store || store.locked) return 0;
    next = Math.max(0, Math.min(Math.floor(Number(next) || 0), store.draft + pickupSheetState.hand));
    const delta = next - store.draft;
    store.draft = next;
    pickupSheetState.hand -= delta;
    if (delta > 0) pickupSheetState.dest = store.name;
    if (delta < 0) pickupSheetState.source = store.name;
    return delta;
}

function pickupSheetDriverTotal(driver) {
    return (driver.stores || []).reduce((n, store) => n + (Number(store.draft) || 0), 0);
}

function pickupSheetUnlocked(driver) {
    return (driver.stores || []).filter(store => !store.locked);
}

function pickupSheetTakeFromStores(stores, count) {
    let left = count;
    const ordered = stores.slice().sort((a, b) => b.draft - a.draft);
    ordered.forEach(store => {
        if (left <= 0 || store.locked) return;
        const take = Math.min(left, store.draft);
        store.draft -= take;
        left -= take;
    });
    return count - left;
}

function pickupSheetPlaceOnStores(stores, count) {
    let left = count;
    const unlocked = stores.filter(store => !store.locked);
    while (left > 0 && unlocked.length) {
        const preferred = unlocked.filter(store => store.origin > 0 || store.draft > 0);
        const pool = preferred.length ? preferred : unlocked;
        pool.sort((a, b) => a.draft - b.draft);
        pool[0].draft += 1;
        left -= 1;
    }
    return count - left;
}

function pickupSheetLabel(board) {
    const state = pickupSheetState;
    if (!state) return '';
    if (state.hand > 0 && state.source) {
        return (board.dataset.hand || ':n in hand — tap where they go').replace(':n', String(state.hand))
            + (state.source ? ' · −' + escapeHtml(state.source) : '');
    }
    if (state.source && state.dest) {
        return '−' + state.source + ' → +' + state.dest;
    }
    return board.dataset.handReady || 'Take pieces, then place them. The day total stays fixed.';
}

function pickupSheetVisibleStores(driver) {
    const stores = driver.stores || [];
    if (!pickupSheetState || pickupSheetState.showAllStops) return stores;
    const focus = Number(pickupSheetState.focusCustomerId) || 0;
    const useful = stores.filter(store =>
        store.draft > 0 || store.origin > 0 || store.expected > 0 || store.standing > 0 || store.customer_id === focus
    );
    return useful.length ? useful : stores;
}

function renderPickupSheet() {
    const board = document.getElementById('pickup-manifest-board');
    const sheet = document.getElementById('pickup-sheet');
    const state = pickupSheetState;
    if (!board || !sheet || !state || state.kind === 'store') return;
    const pool = state.drivers.reduce((n, driver) => n + pickupSheetDriverTotal(driver), 0) + state.hand;
    const trail = pickupSheetLabel(board);
    const columns = state.drivers.map(driver => {
        const delta = pickupSheetDriverTotal(driver) - driver.stores.reduce((n, store) => n + store.origin, 0);
        const deltaHtml = delta > 0
            ? '<span class="pickup-delta pickup-delta--up">+' + delta + '</span>'
            : (delta < 0 ? '<span class="pickup-delta pickup-delta--down">' + delta + '</span>' : '');
        const visibleStores = pickupSheetVisibleStores(driver);
        const storesHtml = visibleStores.length
            ? visibleStores.map(store => {
                const storeDelta = store.draft - store.origin;
                const canTake = !store.locked && store.draft > 0;
                const canPlace = !store.locked && state.hand > 0;
                const expected = Number(store.expected) || 0;
                const standing = Number(store.standing) || 0;
                const vsNeed = expected > 0 ? store.draft - expected : 0;
                const hintBits = [];
                if (expected > 0) hintBits.push((board.dataset.supposed || 'supposed :n').replace(':n', String(expected)));
                if (standing > 0 && standing !== expected) hintBits.push((board.dataset.standing || 'standing :n').replace(':n', String(standing)));
                if ((store.source || '') === 'daily' || (store.source || '') === 'dated') hintBits.push(board.dataset.daily || 'today’s order');
                const sliderMax = store.locked ? store.draft : store.draft + state.hand;
                return '<div class="pickup-sheet-store' +
                    (store.customer_id === state.focusCustomerId ? ' is-focus-store' : '') +
                    (storeDelta > 0 ? ' is-up' : '') +
                    (storeDelta < 0 ? ' is-down' : '') +
                    (vsNeed > 0 ? ' is-over' : '') +
                    (expected > 0 && vsNeed < 0 ? ' is-short' : '') +
                    (store.locked ? ' is-locked' : '') +
                    (canPlace ? ' is-target' : '') + '" data-customer="' + store.customer_id + '">' +
                    '<div class="pickup-sheet-store-copy">' +
                    '<div class="pickup-sheet-store-name">' + escapeHtml(store.name) +
                    (store.locked ? ' <em>' + escapeHtml(board.dataset.locked || '') + '</em>' : '') +
                    (storeDelta ? ' <span class="pickup-delta ' + (storeDelta > 0 ? 'pickup-delta--up' : 'pickup-delta--down') + '">' +
                        (storeDelta > 0 ? '+' : '') + storeDelta + '</span>' : '') +
                    '</div>' +
                    (hintBits.length ? '<p class="pickup-sheet-hint">' + escapeHtml(hintBits.join(' · ')) + '</p>' : '') +
                    '</div>' +
                    '<div class="pickup-sheet-store-controls">' +
                    '<div class="pickup-stepper">' +
                    '<button type="button" class="pickup-step" data-act="store-take" data-driver="' + driver.id +
                    '" data-customer="' + store.customer_id + '" ' + (canTake ? '' : 'disabled') + '>−</button>' +
                    '<strong>' + store.draft + '</strong>' +
                    '<button type="button" class="pickup-step" data-act="store-place" data-driver="' + driver.id +
                    '" data-customer="' + store.customer_id + '" ' + (canPlace ? '' : 'disabled') + '>+</button>' +
                    '</div>' +
                    (store.locked ? '' :
                    '<input type="range" min="0" max="' + sliderMax + '" step="1" value="' + store.draft +
                    '" class="pickup-slider" data-act="store-slide" data-driver="' + driver.id +
                    '" data-customer="' + store.customer_id + '" aria-label="' + escapeHtml(store.name) + '">') +
                    (!store.locked && expected > 0 && store.draft !== expected ?
                    '<button type="button" class="btn pickup-mini" data-act="store-need" data-driver="' + driver.id +
                    '" data-customer="' + store.customer_id + '">' + escapeHtml(board.dataset.fillNeed || 'Fill supposed') + '</button>' : '') +
                    '</div></div>';
            }).join('')
            : '<p class="pickup-sheet-empty">' + escapeHtml(board.dataset.noStops || '') + '</p>';
        const canTakeDriver = pickupSheetUnlocked(driver).some(store => store.draft > 0);
        const canPlaceDriver = state.hand > 0 && pickupSheetUnlocked(driver).length > 0;
        const hasExtra = pickupSheetUnlocked(driver).some(store => store.expected > 0 && store.draft > store.expected);
        const hasShort = pickupSheetUnlocked(driver).some(store => store.expected > 0 && store.draft < store.expected);
        return '<section class="pickup-sheet-driver' + (driver.id === state.focusDriverId ? ' is-focus' : '') +
            (delta > 0 ? ' is-up' : '') + (delta < 0 ? ' is-down' : '') + '">' +
            '<header><strong>' + escapeHtml(driver.name) + '</strong> <span>' + pickupSheetDriverTotal(driver) + '</span> ' +
            deltaHtml + '</header>' +
            storesHtml +
            '<div class="pickup-sheet-driver-actions">' +
            '<button type="button" class="btn pickup-mini" data-act="driver-take" data-driver="' + driver.id + '" ' +
            (canTakeDriver ? '' : 'disabled') + '>' + escapeHtml(board.dataset.take || 'Take') + ' ' + pickupSheetChunkSize() + '</button>' +
            '<button type="button" class="btn pickup-mini" data-act="driver-take-all" data-driver="' + driver.id + '" ' +
            (canTakeDriver ? '' : 'disabled') + '>' + escapeHtml(board.dataset.takeAll || 'Take all') + '</button>' +
            '<button type="button" class="btn pickup-mini pickup-mini--place" data-act="driver-place" data-driver="' + driver.id + '" ' +
            (canPlaceDriver ? '' : 'disabled') + '>' + escapeHtml(board.dataset.place || 'Place') + ' ' + pickupSheetChunkSize() + '</button>' +
            '<button type="button" class="btn pickup-mini pickup-mini--place" data-act="driver-place-all" data-driver="' + driver.id + '" ' +
            (canPlaceDriver ? '' : 'disabled') + '>' + escapeHtml(board.dataset.placeAll || 'Place all') + '</button>' +
            '<button type="button" class="btn pickup-mini" data-act="driver-surplus" data-driver="' + driver.id + '" ' +
            (hasExtra ? '' : 'disabled') + '>' + escapeHtml(board.dataset.takeExtra || 'Take extras') + '</button>' +
            '<button type="button" class="btn pickup-mini pickup-mini--place" data-act="driver-fill" data-driver="' + driver.id + '" ' +
            (hasShort && state.hand > 0 ? '' : 'disabled') + '>' + escapeHtml(board.dataset.fillNeed || 'Fill supposed') + '</button>' +
            '</div></section>';
    }).join('');
    const canSave = state.hand === 0 && state.drivers.some(driver =>
        driver.stores.some(store => store.draft !== store.origin)
    );
    const chunk = state.chunk || '1';
    const chunkBtns = [
        { id: '1', label: board.dataset.chunkOne || '1', show: true },
        { id: 'five', label: board.dataset.chunkFive || '5', show: true },
        { id: 'tray', label: board.dataset.chunkTray || 'Tray', show: Number(state.tray) > 1 },
        { id: 'box', label: board.dataset.chunkBox || 'Box', show: Number(state.box) > 1 }
    ].filter(item => item.show).map(item =>
        '<button type="button" class="pickup-chunk' + (chunk === item.id ? ' is-active' : '') +
        '" data-act="chunk" data-chunk="' + item.id + '">' + escapeHtml(item.label) + '</button>'
    ).join('');
    sheet.innerHTML =
        '<div class="pickup-sheet-head">' +
        '<p class="pickup-sheet-kicker">' + escapeHtml((board.dataset.sheetTitle || 'Move :product').replace(':product', '')) + '</p>' +
        '<h3 id="pickup-sheet-heading">' + escapeHtml(state.productName) + '</h3>' +
        '<p class="pickup-sheet-fixed">' + escapeHtml((board.dataset.fixed || ':n pieces today').replace(':n', String(pool))) + '</p>' +
        '<button type="button" class="pickup-sheet-x" data-act="close" aria-label="' +
        escapeHtml(board.dataset.sheetClose || 'Close') + '">×</button>' +
        '</div>' +
        '<p class="pickup-sheet-trail' + (state.hand > 0 ? ' is-holding' : '') + '">' + trail + '</p>' +
        '<div class="pickup-chunk-row" role="group" aria-label="' + escapeHtml(board.dataset.chunk || 'Move by') + '">' +
        chunkBtns +
        '<button type="button" class="pickup-chunk' + (state.showAllStops ? ' is-active' : '') +
        '" data-act="all-stops">' + escapeHtml(board.dataset.allStops || 'All stops') + '</button>' +
        '</div>' +
        '<div class="pickup-sheet-grid">' + columns + '</div>' +
        '<div class="pickup-sheet-methods">' +
        '<button type="button" class="btn btn-primary" data-act="allocate" data-method="supposed">' +
        escapeHtml(board.dataset.balance || 'Balance to supposed') + '</button>' +
        '<button type="button" class="btn" data-act="allocate" data-method="by_van">' +
        escapeHtml(board.dataset.byVan || 'Balance by van') + '</button>' +
        '<button type="button" class="btn pickup-surprise" data-act="allocate" data-method="little_shop">' +
        escapeHtml(board.dataset.little || 'Extra loaves to little shops') + '</button>' +
        (Number(state.tray) > 1
            ? '<button type="button" class="btn" data-act="allocate" data-method="trays">' +
              escapeHtml(board.dataset.trays || 'Pack into trays') + '</button>'
            : '') +
        '<button type="button" class="btn" data-act="allocate" data-method="standing">' +
        escapeHtml(board.dataset.standingAim || 'Aim at standing') + '</button>' +
        '</div>' +
        '<div class="pickup-sheet-foot">' +
        '<button type="button" class="btn" data-act="snap-need">' + escapeHtml(board.dataset.snapNeed || 'Cover supposed') + '</button>' +
        '<button type="button" class="btn" data-act="reset">' + escapeHtml(board.dataset.reset || 'Reset') + '</button>' +
        '<button type="button" class="btn btn-primary" data-act="save" ' + (canSave ? '' : 'disabled') + '>' +
        escapeHtml(board.dataset.savePlan || 'Save moves') + '</button>' +
        '</div>';
    if (state.focusCustomerId) {
        const focused = sheet.querySelector('[data-customer="' + state.focusCustomerId + '"]');
        if (focused && focused.scrollIntoView) {
            focused.scrollIntoView({ block: 'nearest' });
        }
    }
}

function pickupSheetFindStore(driverId, customerId) {
    const driver = pickupSheetState && pickupSheetState.drivers.find(item => item.id === Number(driverId));
    if (!driver) return null;
    return driver.stores.find(store => store.customer_id === Number(customerId)) || null;
}

function pickupSheetAllocate(method) {
    if (!pickupSheetState) return;
    pickupRebalance({
        op: 'allocate',
        product_id: pickupSheetState.productId,
        product_ids: JSON.stringify([pickupSheetState.productId]),
        method: method,
        tray_size: Number(pickupSheetState.tray) || 0
    });
}

function pickupScopeAllocate(method) {
    const ids = pickupVisibleProductIds();
    if (!ids.length) return;
    pickupRebalance({
        op: 'allocate',
        product_ids: JSON.stringify(ids),
        method: method
    });
}

function openPickupStoreSheet(anchor, storeId, driverId) {
    const board = document.getElementById('pickup-manifest-board');
    const store = pickupVisibleStoreRows().find(item => Number(item.customer_id) === Number(storeId));
    if (!board || !store) return;
    let cell = (store.cells || []).find(item => Number(item.driver_id) === Number(driverId));
    if (!cell || !(cell.products || []).length) {
        cell = (store.cells || []).find(item => (item.products || []).length) || { products: [], driver_id: driverId };
        driverId = Number(cell.driver_id) || Number(driverId) || 0;
    }
    pickupSheetState = { kind: 'store', productId: 0, hand: 0, store: store, driverId: Number(driverId), products: cell.products || [] };
    const root = document.getElementById('pickup-sheet-root');
    const sheet = document.getElementById('pickup-sheet');
    if (!root || !sheet) return;
    const alreadyOpen = !root.hidden;
    const driverName = ((pickupGrid && pickupGrid.drivers) || []).find(d => Number(d.id) === Number(driverId));
    const rows = (cell.products || []).map(item => {
        const locked = !!item.locked;
        return '<div class="pickup-store-sku-row">' +
            '<button type="button" class="pickup-store-sku" data-act="open-sku" data-product="' +
            escapeHtml(String(item.product_id)) + '" data-driver="' + escapeHtml(String(driverId)) +
            '" data-customer="' + escapeHtml(String(store.customer_id)) + '">' +
            '<span>' + escapeHtml(item.name || '') + '</span>' +
            (item.expected_qty ? '<small>' + escapeHtml(String(item.expected_qty)) + '</small>' : '') +
            '</button>' +
            '<div class="pickup-stepper">' +
            '<button type="button" class="pickup-step" data-act="sku-bump" data-delta="-1" data-product="' +
            escapeHtml(String(item.product_id)) + '" data-customer="' + escapeHtml(String(store.customer_id)) + '" ' +
            (locked || !(item.quantity > 0) ? 'disabled' : '') + '>−</button>' +
            '<strong>' + escapeHtml(String(item.quantity || 0)) + '</strong>' +
            '<button type="button" class="pickup-step" data-act="sku-bump" data-delta="1" data-product="' +
            escapeHtml(String(item.product_id)) + '" data-customer="' + escapeHtml(String(store.customer_id)) + '" ' +
            (locked ? 'disabled' : '') + '>+</button>' +
            '</div></div>';
    }).join('');
    sheet.innerHTML =
        '<header class="pickup-sheet-head"><div><p class="pickup-sheet-kicker">' +
        escapeHtml(board.dataset.storeSheet || 'Store × driver') + '</p><h3>' +
        escapeHtml(store.name || '') + (driverName ? ' · ' + escapeHtml(driverName.name) : '') +
        '</h3></div><button type="button" class="pickup-sheet-x" data-act="close">' +
        escapeHtml(board.dataset.sheetClose || 'Close') + '</button></header>' +
        '<p class="pickup-sheet-trail">' + escapeHtml(board.dataset.storeEdit || 'Tap − / + to move pieces for this shop. Tap the name for the full product.') + '</p>' +
        '<div class="pickup-sheet-body pickup-store-sku-list">' +
        (rows || '<p class="pickup-sheet-empty">' + escapeHtml(board.dataset.noStops || '') + '</p>') +
        '</div>';
    if (alreadyOpen) {
        root.hidden = false;
        document.body.classList.add('pickup-sheet-open');
        return;
    }
    placePickupSheet(anchor);
}

function pickupAdjustStoreProduct(productId, customerId, delta) {
    const gridRow = ((pickupGrid && pickupGrid.rows) || []).find(item => Number(item.product_id) === Number(productId));
    const board = document.getElementById('pickup-manifest-board');
    const status = document.getElementById('pickup-board-status');
    if (!gridRow || !delta) return;
    const clone = pickupSheetClone(gridRow, (pickupGrid && pickupGrid.drivers) || [], 0, customerId);
    const all = [];
    clone.drivers.forEach(driver => driver.stores.forEach(store => all.push(store)));
    const target = all.find(store => store.customer_id === Number(customerId) && !store.locked);
    if (!target) {
        if (status) status.textContent = (board && board.dataset.locked) || 'Locked';
        return;
    }
    const others = all.filter(store => store !== target && !store.locked);
    if (delta > 0) {
        let need = delta;
        others.slice().sort((a, b) => {
            const ae = a.expected ? a.draft - a.expected : a.draft;
            const be = b.expected ? b.draft - b.expected : b.draft;
            return be - ae;
        }).forEach(store => {
            if (need <= 0 || store.draft <= 0) return;
            const take = Math.min(need, store.draft);
            store.draft -= take;
            target.draft += take;
            need -= take;
        });
        if (need > 0) {
            if (status) status.textContent = (board && board.dataset.needPlace) || 'No unlocked pieces left to move here.';
            return;
        }
    } else {
        const give = Math.min(-delta, target.draft);
        if (give <= 0) return;
        const receiver = others.slice().sort((a, b) => {
            const ae = a.expected ? a.draft - a.expected : 0;
            const be = b.expected ? b.draft - b.expected : 0;
            return ae - be;
        })[0];
        if (!receiver) {
            if (status) status.textContent = (board && board.dataset.needPlace) || 'Nowhere else to place these pieces.';
            return;
        }
        target.draft -= give;
        receiver.draft += give;
    }
    const lines = [];
    clone.drivers.forEach(driver => {
        driver.stores.forEach(store => {
            lines.push({ customer_id: store.customer_id, quantity: store.draft });
        });
    });
    pickupRebalance({
        op: 'apply_plan',
        product_id: productId,
        lines: JSON.stringify(lines)
    }, { keepSheet: true });
}

function pickupSheetActStore(act, dataset) {
    if (act === 'close') return closePickupSheet();
    if (act === 'sku-bump') {
        pickupAdjustStoreProduct(Number(dataset.product), Number(dataset.customer), Number(dataset.delta) || 0);
        return;
    }
    if (act === 'open-sku') {
        const productId = Number(dataset.product);
        const driverId = Number(dataset.driver);
        const customerId = Number(dataset.customer) || (pickupSheetState.store && pickupSheetState.store.customer_id) || 0;
        const gridRow = ((pickupGrid && pickupGrid.rows) || []).find(item => Number(item.product_id) === productId);
        if (!gridRow) return;
        pickupSheetState = pickupSheetClone(gridRow, (pickupGrid && pickupGrid.drivers) || [], driverId, customerId);
        renderPickupSheet();
        const sheet = document.getElementById('pickup-sheet');
        if (sheet) {
            sheet.style.maxHeight = (window.innerHeight - 24) + 'px';
        }
    }
}

function pickupSheetTake(driver, count, label) {
    const taken = pickupSheetTakeFromStores(pickupSheetUnlocked(driver), count);
    if (taken <= 0) return;
    pickupSheetState.hand += taken;
    pickupSheetState.source = (label || driver.name) + ' ' + taken;
    pickupSheetState.dest = null;
    pickupSheetState.focusDriverId = driver.id;
}

function pickupSheetPlace(driver, count, label) {
    const placed = pickupSheetPlaceOnStores(pickupSheetUnlocked(driver), Math.min(count, pickupSheetState.hand));
    if (placed <= 0) return;
    pickupSheetState.hand -= placed;
    pickupSheetState.dest = (label || driver.name) + ' ' + placed;
    pickupSheetState.focusDriverId = driver.id;
}

function pickupSheetAct(act, dataset) {
    if (!pickupSheetState) return;
    if (pickupSheetState.kind === 'store') {
        pickupSheetActStore(act, dataset);
        return;
    }
    const driver = pickupSheetState.drivers.find(item => item.id === Number(dataset.driver));
    if (act === 'close') return closePickupSheet();
    if (act === 'reset') {
        pickupSheetState.hand = 0;
        pickupSheetState.source = null;
        pickupSheetState.dest = null;
        pickupSheetState.drivers.forEach(item => item.stores.forEach(store => { store.draft = store.origin; }));
        renderPickupSheet();
        return;
    }
    if (act === 'save') return savePickupSheet();
    if (act === 'allocate') return pickupSheetAllocate(dataset.method || 'supposed');
    if (act === 'chunk') {
        pickupSheetState.chunk = dataset.chunk || '1';
        renderPickupSheet();
        return;
    }
    if (act === 'all-stops') {
        pickupSheetState.showAllStops = !pickupSheetState.showAllStops;
        renderPickupSheet();
        return;
    }
    if (act === 'snap-need') {
        pickupSheetState.drivers.forEach(item => {
            pickupSheetUnlocked(item).forEach(store => {
                if (store.expected > 0 && store.draft > store.expected) {
                    pickupSheetApplyDraft(store, store.expected);
                }
            });
        });
        pickupSheetState.drivers.forEach(item => {
            pickupSheetUnlocked(item).forEach(store => {
                if (store.expected > 0 && store.draft < store.expected && pickupSheetState.hand > 0) {
                    pickupSheetApplyDraft(store, Math.min(store.expected, store.draft + pickupSheetState.hand));
                }
            });
        });
        renderPickupSheet();
        return;
    }
    if (!driver) return;
    if (act === 'store-take') {
        const store = pickupSheetFindStore(driver.id, dataset.customer);
        if (!store) return;
        pickupSheetApplyDraft(store, store.draft - pickupSheetChunkSize());
    } else if (act === 'store-place') {
        const store = pickupSheetFindStore(driver.id, dataset.customer);
        if (!store) return;
        pickupSheetApplyDraft(store, store.draft + pickupSheetChunkSize());
    } else if (act === 'store-need') {
        const store = pickupSheetFindStore(driver.id, dataset.customer);
        if (!store || store.expected <= 0) return;
        pickupSheetApplyDraft(store, store.expected);
    } else if (act === 'driver-take') {
        pickupSheetTake(driver, pickupSheetChunkSize(), driver.name);
    } else if (act === 'driver-take-all') {
        const all = pickupSheetUnlocked(driver).reduce((n, store) => n + store.draft, 0);
        pickupSheetTake(driver, all, driver.name);
    } else if (act === 'driver-place') {
        pickupSheetPlace(driver, pickupSheetChunkSize(), driver.name);
    } else if (act === 'driver-place-all') {
        pickupSheetPlace(driver, pickupSheetState.hand, driver.name);
    } else if (act === 'driver-surplus') {
        pickupSheetUnlocked(driver).forEach(store => {
            if (store.expected > 0 && store.draft > store.expected) {
                pickupSheetApplyDraft(store, store.expected);
            }
        });
        pickupSheetState.focusDriverId = driver.id;
    } else if (act === 'driver-fill') {
        pickupSheetUnlocked(driver).forEach(store => {
            if (store.expected > 0 && store.draft < store.expected && pickupSheetState.hand > 0) {
                pickupSheetApplyDraft(store, Math.min(store.expected, store.draft + pickupSheetState.hand));
            }
        });
        pickupSheetState.focusDriverId = driver.id;
    }
    renderPickupSheet();
}

function placePickupSheet(anchor) {
    const root = document.getElementById('pickup-sheet-root');
    const sheet = document.getElementById('pickup-sheet');
    if (!root || !sheet) return;
    root.hidden = false;
    document.body.classList.add('pickup-sheet-open');
    if (!pickupSheetState || pickupSheetState.kind !== 'store') {
        renderPickupSheet();
    }
    const width = Math.min(720, window.innerWidth - 24);
    sheet.style.width = width + 'px';
    const rect = anchor && anchor.getBoundingClientRect ? anchor.getBoundingClientRect() : { top: 80, bottom: 80, left: 24, right: 24 };
    const height = Math.min(sheet.offsetHeight || 480, window.innerHeight - 24);
    let top = rect.bottom + 10;
    if (top + height > window.innerHeight - 12) {
        top = Math.max(12, rect.top - height - 10);
    }
    if (top + height > window.innerHeight - 12) {
        top = Math.max(12, (window.innerHeight - height) / 2);
    }
    let left = rect.left;
    if (left + width > window.innerWidth - 12) left = window.innerWidth - width - 12;
    if (left < 12) left = 12;
    sheet.style.top = Math.round(top) + 'px';
    sheet.style.left = Math.round(left) + 'px';
    sheet.style.maxHeight = (window.innerHeight - 24) + 'px';
}

function openPickupSheet(anchor, focusDriverId) {
    const board = document.getElementById('pickup-manifest-board');
    const row = anchor && anchor.closest ? anchor.closest('tr') : null;
    if (!board || !row) return;
    const productId = Number(row.dataset.productId);
    const gridRow = ((pickupGrid && pickupGrid.rows) || []).find(item => Number(item.product_id) === productId);
    if (!gridRow) return;
    pickupSheetState = pickupSheetClone(gridRow, (pickupGrid && pickupGrid.drivers) || [], focusDriverId);
    placePickupSheet(anchor);
}

function closePickupSheet() {
    pickupSheetState = null;
    const root = document.getElementById('pickup-sheet-root');
    if (root) root.hidden = true;
    document.body.classList.remove('pickup-sheet-open');
    renderPickupBoard();
}

function savePickupSheet() {
    const board = document.getElementById('pickup-manifest-board');
    const status = document.getElementById('pickup-board-status');
    if (!pickupSheetState || pickupSheetState.kind === 'store') return;
    if (pickupSheetState.hand > 0) {
        if (status) status.textContent = (board && board.dataset.needPlace) || 'Place the pieces you picked up before saving.';
        return;
    }
    const lines = [];
    pickupSheetState.drivers.forEach(driver => {
        driver.stores.forEach(store => {
            lines.push({ customer_id: store.customer_id, quantity: store.draft });
        });
    });
    pickupRebalance({
        op: 'apply_plan',
        product_id: pickupSheetState.productId,
        lines: JSON.stringify(lines)
    });
}

function pickupRebalance(fields, options) {
    const board = document.getElementById('pickup-manifest-board');
    const status = document.getElementById('pickup-board-status');
    const dateEl = document.getElementById('tracking-date');
    const keepSheet = options && options.keepSheet === true;
    if (board && board.dataset.pickupBusy === '1') return;
    if (board) board.dataset.pickupBusy = '1';
    board && board.querySelectorAll('[data-act="allocate-scope"]').forEach(btn => { btn.disabled = true; });
    const formData = new FormData();
    formData.append('action', 'pickup_rebalance');
    formData.append('date', dateEl ? dateEl.value : '');
    formData.append('csrf_token', pickupCsrfToken());
    Object.keys(fields).forEach(key => formData.append(key, String(fields[key])));
    fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': pickupCsrfToken() } })
        .then(async response => {
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || !data.success) {
                throw new Error((data && data.error) || 'Could not update pickup');
            }
            return data;
        })
        .then(() => {
            if (!keepSheet) closePickupSheet();
            if (status) status.textContent = (board && board.dataset.rebalanced) || 'Updated.';
            if (typeof loadDeliveries === 'function') loadDeliveries({ background: true, skipMap: true });
        })
        .catch(error => {
            if (status) status.textContent = error.message || 'Could not update pickup';
        })
        .finally(() => {
            if (board) board.dataset.pickupBusy = '0';
            board && board.querySelectorAll('[data-act="allocate-scope"]').forEach(btn => { btn.disabled = false; });
        });
}

function savePickupPackUnits(input) {
    const row = input.closest('tr');
    const board = document.getElementById('pickup-manifest-board');
    const status = document.getElementById('pickup-board-status');
    if (!row || !board) return;
    const productId = Number(row.dataset.productId);
    const trayInput = row.querySelector('input[data-field="tray"]');
    const boxInput = row.querySelector('input[data-field="box"]');
    const formData = new FormData();
    formData.append('action', 'save_pack_units');
    formData.append('product_id', String(productId));
    formData.append('pieces_per_tray', trayInput ? trayInput.value : '');
    formData.append('pieces_per_box', boxInput ? boxInput.value : '');
    formData.append('csrf_token', pickupCsrfToken());
    fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': pickupCsrfToken() } })
        .then(async response => {
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || !data.success) {
                throw new Error((data && data.error) || 'Could not save pack sizes');
            }
            return data;
        })
        .then(data => {
            const saved = data.product || {};
            (pickupGrid.rows || []).forEach(gridRow => {
                if (Number(gridRow.product_id) !== productId) return;
                gridRow.pieces_per_tray = saved.pieces_per_tray;
                gridRow.pieces_per_box = saved.pieces_per_box;
            });
            if (status) status.textContent = board.dataset.saved || 'Saved.';
            renderPickupBoard();
        })
        .catch(error => {
            if (status) status.textContent = error.message || 'Could not save pack sizes';
        });
}

function pickupManifestHtml(listEl, driverData) {
    const title = listEl.dataset.pickupTitle || 'Pickup manifest';
    const empty = listEl.dataset.pickupEmpty || 'No pickup load saved yet.';
    const editLabel = listEl.dataset.pickupEdit || 'Edit pickup loads';
    const summaryTpl = listEl.dataset.pickupSummary || ':skus products · :pcs pieces';
    const items = Array.isArray(driverData.pickup_manifest) ? driverData.pickup_manifest : [];
    const skuCount = Number(driverData.pickup_sku_count) || items.length;
    const pieceCount = Number(driverData.pickup_piece_count) || items.reduce((n, item) => n + (Number(item.loaded_quantity) || 0), 0);
    const summary = summaryTpl.replace(':skus', String(skuCount)).replace(':pcs', String(pieceCount));
    const date = document.getElementById('tracking-date').value || '';
    const editHref = 'driver_load.php?date=' + encodeURIComponent(date);
    const body = items.length
        ? `<ul class="driver-pickup-list">${items.map(item =>
            `<li><strong>${escapeHtml(String(item.loaded_quantity))}</strong> ${escapeHtml(item.name || '')}</li>`
          ).join('')}</ul>`
        : `<p class="driver-pickup-empty">${escapeHtml(empty)}</p>`;
    return `
        <details class="driver-pickup-manifest"${items.length ? ' open' : ''}>
            <summary>
                <strong>${escapeHtml(title)}</strong>
                <span>${items.length ? escapeHtml(summary) : escapeHtml(empty)}</span>
            </summary>
            <div class="driver-pickup-body">
                ${body}
                <a class="driver-pickup-edit" href="${escapeHtml(editHref)}">${escapeHtml(editLabel)}</a>
            </div>
        </details>
    `;
}

function updateDeliveryList() {
    const listEl = document.getElementById('delivery-list');
    const entries = Object.keys(driversData).filter(id => (driversData[id].deliveries || []).length > 0);

    if (entries.length === 0) {
        listEl.innerHTML = '<p class="text-muted">No assigned deliveries for this date and driver selection.</p>';
        return;
    }

    // Sort drivers by name for stable list order
    entries.sort((a, b) => (driversData[a].name || '').localeCompare(driversData[b].name || ''));

    listEl.innerHTML = entries.map(driverId => {
        const driverData = driversData[driverId];
        const color = driverColor(driverId);
        const stops = (driverData.deliveries || []).slice().sort((a, b) => {
            if (a.route_order !== b.route_order) return a.route_order - b.route_order;
            return (a.customer_name || '').localeCompare(b.customer_name || '');
        });

        const stopRows = stops.map((d, stopIndex) => {
            const status = statusLabels[d.delivery_status] || d.delivery_status;
            const isDelivered = d.delivery_status === 'delivered';
            const isRouteLocked = ['delivered', 'cancelled', 'in_transit'].includes(d.delivery_status);
            const photoCount = d.photo_count || 0;
            const isFirst = stopIndex === 0;
            const isLast = stopIndex === stops.length - 1;
            const isCod = (d.payment_collection || 'cod') === 'cod';
            const cashAmount = stopPaymentAmount(d);
            const paymentBadge = isCod
                ? `<span class="payment-badge payment-badge--cod">${isDelivered ? 'COD cash ' + formatMoney(cashAmount) : 'COD expected ' + formatMoney(cashAmount)}</span>`
                : `<span class="payment-badge payment-badge--signature">Signature</span>`;
            const mapsLink = d.address
                ? `<a class="stop-external-link" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(d.address)}" target="_blank" rel="noopener">Map</a>`
                : '';
            const photosHint = isDelivered
                ? (photoCount > 0
                    ? `<span class="photo-badge">${photoCount} photo${photoCount === 1 ? '' : 's'}</span>`
                    : `<span class="photo-badge photo-badge-empty">No photos</span>`)
                : (photoCount > 0
                    ? `<span class="photo-badge">${photoCount} photo${photoCount === 1 ? '' : 's'}</span>`
                    : '');
            return `
                <li class="delivery-stop status-${escapeHtml(d.delivery_status)}${isDelivered ? ' has-photos-action' : ''}"
                    data-driver-id="${driverId}"
                    data-order-id="${d.daily_order_id}"
                    data-customer-id="${d.customer_id}"
                    data-status="${escapeHtml(d.delivery_status)}"
                    draggable="${isRouteLocked ? 'false' : 'true'}"
                    tabindex="0"
                    title="${isRouteLocked ? 'This stop is locked in route history' : 'Move with ↑ ↓ · Tap to view stop details'}">
                    ${isRouteLocked ? '' : '<span class="drag-handle" title="Drag to reorder" aria-hidden="true">⋮⋮</span>'}
                    <div class="stop-order">${d.route_order > 0 ? d.route_order : '—'}</div>
                    <div class="stop-body">
                        <div class="stop-name">
                            <button type="button" class="customer-hub-link stop-detail-trigger">${escapeHtml(d.customer_name)}</button>
                            <a class="stop-external-link customer-record-link" href="customer_record.php?customer_id=${encodeURIComponent(d.customer_id)}&amp;date=${encodeURIComponent(document.getElementById('tracking-date').value || '')}" title="Open Customer Record" aria-label="Open Customer Record">↗</a>
                            ${paymentBadge}
                            ${photosHint}
                        </div>
                        <div class="stop-meta">${escapeHtml(d.address || 'No address')}</div>
                        <div class="stop-meta">
                            ${escapeHtml(d.zone)}
                            · ${escapeHtml(status)}
                            · ${escapeHtml(formatTime(d.scheduled_delivery_time))}
                            ${d.item_count ? ' · ' + d.item_count + ' items' : ''}
                            ${mapsLink ? ' · ' + mapsLink : ''}
                            · <span class="photos-action-hint">View details</span>
                        </div>
                    </div>
                    <div class="stop-move-controls" role="group" aria-label="Reorder stop">
                        <button type="button" class="stop-move-btn" data-move="up"
                            aria-label="Move stop up"
                            title="Move up"
                            ${isRouteLocked || isFirst ? 'disabled' : ''}>↑</button>
                        <button type="button" class="stop-move-btn" data-move="down"
                            aria-label="Move stop down"
                            title="Move down"
                            ${isRouteLocked || isLast ? 'disabled' : ''}>↓</button>
                    </div>
                </li>
            `;
        }).join('');

        const cash = driverData.cash_summary || {};
        const cashBits = [];
        if ((cash.cod_stop_count || 0) > 0) {
            cashBits.push(`Cash on hand: <strong>${formatMoney(cash.cash_on_hand)}</strong>`);
            cashBits.push(`Turn-in: <strong>${formatMoney(cash.turn_in_total)}</strong>`);
        }
        if (Number(cash.total_sold) > 0 || (cash.cod_stop_count || 0) > 0) {
            cashBits.push(`Sold: <strong>${formatMoney(cash.total_sold)}</strong>`);
        }
        const cashHeader = cashBits.length
            ? `<span class="driver-cash-summary">
                    ${cashBits.join(' · ')}
                    ${(cash.cod_stop_count || 0) > 0
                        ? `<span class="driver-cash-meta">(${cash.cod_delivered_count || 0}/${cash.cod_stop_count || 0} COD/Pan Dulce stop${(cash.cod_stop_count || 0) === 1 ? '' : 's'} delivered)</span>`
                        : ''}
               </span>`
            : '';

        const pickupCopy = pickupManifestHtml(listEl, driverData);

        return `
            <section class="driver-delivery-group" data-driver-id="${driverId}">
                <header class="driver-delivery-header">
                    <span class="legend-color" style="background-color: ${color};"></span>
                    <div class="driver-delivery-title">
                        <strong>${escapeHtml(driverData.name)}</strong>
                        <span class="stop-count">${stops.length} stop${stops.length === 1 ? '' : 's'}</span>
                        ${cashHeader}
                    </div>
                </header>
                ${pickupCopy}
                <ol class="delivery-stops" data-driver-id="${driverId}">${stopRows}</ol>
            </section>
        `;
    }).join('');

    listEl.querySelectorAll('.stop-external-link').forEach(link => {
        link.addEventListener('click', (e) => e.stopPropagation());
    });

    listEl.querySelectorAll('.stop-detail-trigger').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const item = btn.closest('.delivery-stop');
            if (!item) return;
            viewStopDetail(item.dataset.driverId, item.dataset.orderId);
        });
    });

    listEl.querySelectorAll('.stop-move-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const item = btn.closest('.delivery-stop');
            const routeList = btn.closest('.delivery-stops');
            if (!item || !routeList || btn.disabled) return;
            moveStopInList(routeList, item, btn.dataset.move === 'up' ? -1 : 1);
        });
        // Prevent drag starting from the buttons on touch/desktop
        btn.addEventListener('mousedown', (e) => e.stopPropagation());
        btn.addEventListener('touchstart', (e) => e.stopPropagation(), { passive: true });
    });

    listEl.querySelectorAll('.delivery-stop').forEach(item => {
        const activate = () => {
            if (didDragStop) {
                didDragStop = false;
                return;
            }
            const driverId = item.dataset.driverId;
            const orderId = item.dataset.orderId;
            viewStopDetail(driverId, orderId);
        };
        item.addEventListener('click', activate);
        item.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });
    });

    setupDeliveryListDragAndDrop();
}

function moveStopInList(routeList, item, delta) {
    const items = Array.from(routeList.querySelectorAll('.delivery-stop')).filter(isMovableRouteItem);
    const fromIndex = items.indexOf(item);
    if (fromIndex < 0) return;

    const toIndex = fromIndex + delta;
    if (toIndex < 0 || toIndex >= items.length) return;

    const target = items[toIndex];
    if (delta < 0) {
        routeList.insertBefore(item, target);
    } else {
        routeList.insertBefore(item, target.nextSibling);
    }

    item.classList.add('just-moved');
    setTimeout(() => item.classList.remove('just-moved'), 350);

    applyRouteOrderFromList(routeList);
}

function updateMoveButtons(routeList) {
    const allItems = Array.from(routeList.querySelectorAll('.delivery-stop'));
    const items = allItems.filter(isMovableRouteItem);
    allItems.filter(item => !isMovableRouteItem(item)).forEach(item => {
        item.querySelectorAll('.stop-move-btn').forEach(btn => { btn.disabled = true; });
    });
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.stop-move-btn[data-move="up"]');
        const downBtn = item.querySelector('.stop-move-btn[data-move="down"]');
        if (upBtn) upBtn.disabled = index === 0;
        if (downBtn) downBtn.disabled = index === items.length - 1;
    });
}

function isMovableRouteItem(item) {
    return item && !['delivered', 'cancelled', 'in_transit'].includes(item.dataset.status || 'pending');
}

function setReorderStatus(message, tone) {
    const el = document.getElementById('reorder-status');
    if (!el) return;
    el.textContent = message || '';
    el.className = 'reorder-status' + (tone ? ' is-' + tone : '');
}

function prefersTouchReorder() {
    return window.matchMedia('(hover: none) and (pointer: coarse)').matches
        || window.matchMedia('(max-width: 980px)').matches;
}

function setupDeliveryListDragAndDrop() {
    const touchMode = prefersTouchReorder();

    document.querySelectorAll('.delivery-stops').forEach(routeList => {
        let draggedItem = null;
        updateMoveButtons(routeList);

        routeList.querySelectorAll('.delivery-stop').forEach(item => {
            if (!isMovableRouteItem(item)) {
                item.draggable = false;
                return;
            }
            // On phones/tablets, use ↑ ↓ buttons only — native drag fights with scrolling
            if (touchMode) {
                item.draggable = false;
                return;
            }

            item.addEventListener('dragstart', function(e) {
                draggedItem = this;
                didDragStop = true;
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                try {
                    e.dataTransfer.setData('text/plain', this.dataset.orderId || '');
                } catch (err) { /* IE/older Safari */ }
            });

            item.addEventListener('dragend', function() {
                this.classList.remove('dragging');
                routeList.querySelectorAll('.delivery-stop').forEach(el => el.classList.remove('drag-over'));
                draggedItem = null;
                // Keep didDragStop true briefly so the click after drag is ignored
                setTimeout(() => { didDragStop = false; }, 50);
            });

            item.addEventListener('dragover', function(e) {
                if (!isMovableRouteItem(this)) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });

            item.addEventListener('dragenter', function(e) {
                if (!isMovableRouteItem(this)) return;
                e.preventDefault();
                if (draggedItem && draggedItem !== this && draggedItem.parentNode === this.parentNode) {
                    this.classList.add('drag-over');
                }
            });

            item.addEventListener('dragleave', function() {
                this.classList.remove('drag-over');
            });

            item.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
                if (!draggedItem || draggedItem === this) return;
                if (draggedItem.parentNode !== routeList) return;

                const items = Array.from(routeList.querySelectorAll('.delivery-stop'));
                const fromIndex = items.indexOf(draggedItem);
                const toIndex = items.indexOf(this);
                if (fromIndex < 0 || toIndex < 0 || fromIndex === toIndex) return;

                if (fromIndex < toIndex) {
                    routeList.insertBefore(draggedItem, this.nextSibling);
                } else {
                    routeList.insertBefore(draggedItem, this);
                }

                applyRouteOrderFromList(routeList);
            });
        });
    });
}

function applyRouteOrderFromList(routeList) {
    const driverId = routeList.dataset.driverId;
    const items = Array.from(routeList.querySelectorAll('.delivery-stop'));
    const orderIds = items.filter(isMovableRouteItem).map(item => parseInt(item.dataset.orderId, 10));

    items.forEach((item, index) => {
        const orderEl = item.querySelector('.stop-order');
        if (orderEl) orderEl.textContent = String(index + 1);
    });
    updateMoveButtons(routeList);

    // Keep in-memory data + map marker labels in sync immediately
    if (driversData[driverId] && Array.isArray(driversData[driverId].deliveries)) {
        const byId = {};
        driversData[driverId].deliveries.forEach(d => {
            byId[String(d.daily_order_id)] = d;
        });
        const reordered = [];
        orderIds.forEach((id, index) => {
            const delivery = byId[String(id)];
            if (!delivery) return;
            delivery.route_order = index + 1;
            reordered.push(delivery);

            const marker = markersByOrderId[String(id)];
            if (marker && marker.getLabel) {
                const label = marker.getLabel() || {};
                marker.setLabel(Object.assign({}, label, { text: String(index + 1) }));
            }
        });
        if (reordered.length === orderIds.length) {
            driversData[driverId].deliveries = reordered;
        }
    }

    saveRouteOrder(driverId, orderIds);
}

function saveRouteOrder(driverId, orderIds) {
    const selectedDate = document.getElementById('tracking-date').value;
    setReorderStatus('Saving…', 'saving');

    if (reorderSaveTimer) {
        clearTimeout(reorderSaveTimer);
        reorderSaveTimer = null;
    }

    const formData = new FormData();
    formData.append('action', 'reorder_deliveries');
    formData.append('date', selectedDate);
    formData.append('driver_id', String(driverId));
    formData.append('order_ids', JSON.stringify(orderIds));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            setReorderStatus('Order saved', 'saved');
            updateLastRefreshTime();
            reorderSaveTimer = setTimeout(() => setReorderStatus(''), 2000);
        } else {
            setReorderStatus('Save failed', 'error');
            console.error('Failed to save route order:', data.error);
            showError('Failed to save route order: ' + (data.error || 'Unknown error'));
            // Reload to restore canonical order from the server
            loadDeliveries();
        }
    })
    .catch(error => {
        console.error('Error saving route order:', error);
        setReorderStatus('Save failed', 'error');
        showError('Network error saving route order');
        loadDeliveries();
    });
}

function highlightListItem(driverId, orderId) {
    document.querySelectorAll('.delivery-stop').forEach(el => el.classList.remove('is-active'));
    const el = document.querySelector(`.delivery-stop[data-driver-id="${driverId}"][data-order-id="${orderId}"]`);
    if (el) {
        el.classList.add('is-active');
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
}

function focusDelivery(driverId, orderId) {
    highlightListItem(driverId, orderId);
    const driverData = driversData[driverId];
    if (!driverData) return;
    const delivery = (driverData.deliveries || []).find(d => String(d.daily_order_id) === String(orderId));
    if (!delivery) return;

    const marker = markersByOrderId[String(orderId)];
    if (marker) {
        map.panTo(marker.getPosition());
        if (map.getZoom() < 13) map.setZoom(14);
        infoWindow.setContent(deliveryInfoHtml(driverData.name, delivery, driverId));
        infoWindow.open(map, marker);
        return;
    }

    if (delivery.latitude != null && delivery.longitude != null) {
        const pos = { lat: delivery.latitude, lng: delivery.longitude };
        map.panTo(pos);
        if (map.getZoom() < 13) map.setZoom(14);
        infoWindow.setContent(deliveryInfoHtml(driverData.name, delivery, driverId));
        infoWindow.setPosition(pos);
        infoWindow.open(map);
    }
}

function findDelivery(driverId, orderId) {
    const driverData = driversData[driverId];
    if (!driverData) return null;
    const delivery = (driverData.deliveries || []).find(d => String(d.daily_order_id) === String(orderId));
    if (!delivery) return null;
    return { driverData, delivery };
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString(undefined, {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit'
    });
}

function formatReceivingWindow(deliverAfter, deliverBy) {
    if (!deliverAfter && !deliverBy) return '—';
    if (deliverAfter && deliverBy) {
        return formatTime(deliverAfter) + ' – ' + formatTime(deliverBy);
    }
    if (deliverAfter) return 'After ' + formatTime(deliverAfter);
    return 'By ' + formatTime(deliverBy);
}

function renderDetailGrid(items) {
    return items.map(([label, value]) => `
        <div class="stop-detail-item">
            <dt>${escapeHtml(label)}</dt>
            <dd>${value}</dd>
        </div>
    `).join('');
}

function renderStopDetailInvoiceItems(items) {
    if (!items || items.length === 0) {
        return '<p class="text-muted stop-detail-empty">No priced items for this order.</p>';
    }
    return `
        <div class="stop-detail-invoice-list">
            <div class="stop-detail-invoice-heading">
                <span>Item</span>
                <span>Amount</span>
            </div>
            ${items.map(item => `
                <div class="stop-detail-invoice-row">
                    <span>
                        <strong>${escapeHtml(item.product_name || 'Product')}</strong>
                        <small>${escapeHtml(String(item.quantity || 0))} × ${formatMoney(item.unit_price || 0)}</small>
                    </span>
                    <strong>${formatMoney(item.line_total || 0)}</strong>
                </div>
            `).join('')}
        </div>
    `;
}

function renderStopDetailPhotos(photos, gridEl) {
    if (!photos || photos.length === 0) {
        return;
    }
    gridEl.innerHTML = photos.map(photo => `
        <div class="photo-thumb" tabindex="0" role="button"
             data-url="${escapeHtml(photo.url)}"
             data-fallback="${escapeHtml(photo.fallback_url || '')}"
             title="${escapeHtml(photo.photo_type || 'Photo')}">
            <img src="${escapeHtml(photo.url)}"
                 alt="${escapeHtml(photo.photo_type || 'Delivery photo')}"
                 loading="lazy"
                 onerror="if (this.dataset.fallbackTried) return; this.dataset.fallbackTried='1'; this.src=this.parentNode.dataset.fallback;">
            <div class="photo-info">
                <span class="photo-type">${escapeHtml(photo.photo_type || 'Photo')}</span>
                ${photo.created_at ? `<span class="customer-name">${escapeHtml(photo.created_at)}</span>` : ''}
            </div>
        </div>
    `).join('');

    gridEl.querySelectorAll('.photo-thumb').forEach(thumb => {
        const open = () => openPhotoLightbox(thumb.dataset.url, thumb.dataset.fallback);
        thumb.addEventListener('click', open);
        thumb.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open();
            }
        });
    });
}

function closeStopDetailModal() {
    const modal = document.getElementById('stopDetailModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function viewStopDetail(driverId, orderId) {
    const found = findDelivery(driverId, orderId);
    if (!found) {
        showError('Delivery not found');
        return;
    }

    const { driverData, delivery } = found;
    const selectedDate = document.getElementById('tracking-date').value;
    const modal = document.getElementById('stopDetailModal');
    const title = document.getElementById('stopDetailModalTitle');
    const kicker = document.getElementById('stopDetailKicker');
    const actions = document.getElementById('stopDetailActions');
    const timing = document.getElementById('stopDetailTiming');
    const statusEl = document.getElementById('stopDetailStatus');
    const invoiceStatus = document.getElementById('stopDetailInvoiceStatus');
    const invoiceEl = document.getElementById('stopDetailInvoice');
    const photosStatus = document.getElementById('stopDetailPhotosStatus');
    const photosGrid = document.getElementById('stopDetailPhotos');

    const status = statusLabels[delivery.delivery_status] || delivery.delivery_status;
    const paymentType = paymentLabels[delivery.payment_collection] || delivery.payment_collection || 'Signature';
    const paymentAmount = stopPaymentAmount(delivery);
    const customerRecordUrl = `customer_record.php?customer_id=${encodeURIComponent(delivery.customer_id)}&date=${encodeURIComponent(selectedDate)}`;
    const mapsUrl = delivery.address
        ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(delivery.address)}`
        : '';

    kicker.textContent = `${driverData.name} · Stop #${delivery.route_order || '—'} · ${selectedDate}`;
    title.textContent = delivery.customer_name;

    actions.innerHTML = `
        <a class="btn btn-primary stop-detail-action" href="${escapeHtml(customerRecordUrl)}">Customer Record</a>
        ${mapsUrl ? `<a class="btn btn-secondary stop-detail-action" href="${escapeHtml(mapsUrl)}" target="_blank" rel="noopener">Open in Maps</a>` : ''}
        ${delivery.phone ? `<a class="btn btn-secondary stop-detail-action" href="tel:${escapeHtml(String(delivery.phone).replace(/[^\d+]/g, ''))}">${escapeHtml(delivery.phone)}</a>` : ''}
    `;

    timing.innerHTML = renderDetailGrid([
        ['Scheduled', escapeHtml(formatTime(delivery.scheduled_delivery_time))],
        ['Actual delivery', escapeHtml(formatDateTime(delivery.actual_delivery_time))],
        ['Receiving window', escapeHtml(formatReceivingWindow(delivery.deliver_after, delivery.deliver_by))],
        ['Confirmed at', escapeHtml(formatDateTime(delivery.delivery_confirmed_at))],
    ]);

    statusEl.innerHTML = renderDetailGrid([
        ['Status', `<span class="stop-detail-status status-${escapeHtml(delivery.delivery_status)}">${escapeHtml(status)}</span>`],
        ['Zone', escapeHtml(delivery.zone || '—')],
        ['Address', escapeHtml(delivery.address || 'No address')],
        ['Payment', escapeHtml(paymentType)],
        ['Amount', paymentAmount != null ? formatMoney(paymentAmount) : '—'],
        ['Collected', delivery.amount_collected != null ? formatMoney(delivery.amount_collected) : '—'],
        ['Items ordered', delivery.item_count ? String(delivery.item_count) : '—'],
    ]);

    invoiceStatus.textContent = 'Loading order details…';
    invoiceStatus.style.display = 'block';
    invoiceEl.innerHTML = '';
    photosStatus.textContent = 'Loading photos…';
    photosStatus.style.display = 'block';
    photosGrid.innerHTML = '';

    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('stopDetailModalClose').focus();

    focusDelivery(driverId, orderId);

    const summaryBody = 'action=get_delivery_summary&daily_order_id=' + encodeURIComponent(String(orderId));
    fetch('complete_delivery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: summaryBody
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            invoiceStatus.textContent = 'Could not load order details: ' + (data.error || 'Unknown error');
            return;
        }

        invoiceStatus.style.display = 'none';
        const billable = Math.max(0, Number(data.delivered_pieces || 0) - Number(data.credits_taken_back || 0));
        const summaryBits = [
            `<div class="stop-detail-invoice-summary">
                <span><strong>Ordered:</strong> ${Number(data.ordered_pieces || 0)} pcs</span>
                <span><strong>Delivered:</strong> ${Number(data.delivered_pieces || 0)} pcs</span>
                <span><strong>Credits:</strong> ${Number(data.credits_taken_back || 0)}</span>
                <span><strong>Billable:</strong> ${billable} pcs</span>
            </div>`,
            `<div class="stop-detail-invoice-totals">
                <span>Order total: <strong>${formatMoney(data.order_total || 0)}</strong></span>
                <span>Saved total: <strong>${formatMoney(data.saved_total || 0)}</strong></span>
                ${data.pricing_label ? `<span class="stop-detail-pricing-label">${escapeHtml(data.pricing_label)}</span>` : ''}
            </div>`,
            renderStopDetailInvoiceItems(data.items || [])
        ].join('');
        invoiceEl.innerHTML = summaryBits;
    })
    .catch(err => {
        console.error(err);
        invoiceStatus.textContent = 'Network error loading order details';
    });

    const formData = new FormData();
    formData.append('action', 'get_delivery_photos');
    formData.append('date', selectedDate);
    formData.append('driver_id', String(driverId));
    formData.append('customer_id', String(delivery.customer_id));

    fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            photosStatus.textContent = 'Failed to load photos: ' + (data.error || 'Unknown error');
            return;
        }

        const photos = data.photos || [];
        if (photos.length === 0) {
            photosStatus.textContent = 'No photos uploaded for this stop yet.';
            return;
        }

        photosStatus.style.display = 'none';
        renderStopDetailPhotos(photos, photosGrid);
    })
    .catch(err => {
        console.error(err);
        photosStatus.textContent = 'Network error loading photos';
    });
}

function viewDeliveryPhotos(driverId, orderId) {
    viewStopDetail(driverId, orderId);
}

function closeDeliveryPhotosModal() {
    const modal = document.getElementById('deliveryPhotosModal');
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
}

function openPhotoLightbox(url, fallbackUrl) {
    const lightbox = document.getElementById('photoLightbox');
    const img = document.getElementById('photoLightboxImage');
    img.onerror = function() {
        if (fallbackUrl && img.src !== fallbackUrl) {
            img.src = fallbackUrl;
        }
    };
    img.src = url;
    lightbox.style.display = 'flex';
    lightbox.setAttribute('aria-hidden', 'false');
}

function closePhotoLightbox() {
    const lightbox = document.getElementById('photoLightbox');
    lightbox.style.display = 'none';
    lightbox.setAttribute('aria-hidden', 'true');
    document.getElementById('photoLightboxImage').src = '';
}

window.viewDeliveryPhotos = viewDeliveryPhotos;
window.viewStopDetail = viewStopDetail;

function updateLastRefreshTime() {
    document.getElementById('last-update-time').textContent = new Date().toLocaleTimeString();
}

function showError(message) {
    alert(message);
}

function escapeHtml(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.pickup-unit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const board = document.getElementById('pickup-manifest-board');
            if (!board) return;
            board.dataset.unit = btn.dataset.unit || 'pieces';
            document.querySelectorAll('.pickup-unit-btn').forEach(other => {
                other.classList.toggle('is-active', other === btn);
            });
            renderPickupBoard();
        });
    });

    const pickupTools = document.getElementById('pickup-board-tools');
    if (pickupTools) {
        pickupTools.addEventListener('click', function(event) {
            const btn = event.target.closest('[data-act]');
            if (!btn || !pickupTools.contains(btn)) return;
            const act = btn.dataset.act;
            if (act === 'view') {
                pickupView = btn.dataset.view === 'store' ? 'store' : 'product';
                renderPickupBoard();
                return;
            }
            if (act === 'family') {
                pickupFamily = btn.dataset.family || 'all';
                renderPickupBoard();
                return;
            }
            if (act === 'allocate-scope') {
                pickupScopeAllocate(btn.dataset.method || 'supposed');
            }
        });
    }

    const pickupRoot = document.getElementById('pickup-sheet-root');
    if (pickupRoot) {
        pickupRoot.addEventListener('click', function(event) {
            if (event.target.closest('.pickup-sheet-backdrop')) {
                closePickupSheet();
                return;
            }
            const actionBtn = event.target.closest('[data-act]');
            if (!actionBtn || !pickupRoot.contains(actionBtn)) return;
            if (actionBtn.matches('input')) return;
            pickupSheetAct(actionBtn.dataset.act, actionBtn.dataset);
        });
        pickupRoot.addEventListener('input', function(event) {
            const slider = event.target.closest('.pickup-slider');
            if (!slider || !pickupSheetState) return;
            const store = pickupSheetFindStore(slider.dataset.driver, slider.dataset.customer);
            if (!store) return;
            pickupSheetApplyDraft(store, Number(slider.value) || 0);
            const strong = slider.closest('.pickup-sheet-store-controls') && slider.closest('.pickup-sheet-store-controls').querySelector('strong');
            if (strong) strong.textContent = String(store.draft);
            const trail = document.querySelector('.pickup-sheet-trail');
            const board = document.getElementById('pickup-manifest-board');
            if (trail && board) {
                trail.classList.toggle('is-holding', pickupSheetState.hand > 0);
                trail.innerHTML = pickupSheetLabel(board);
            }
        });
        pickupRoot.addEventListener('change', function(event) {
            if (!event.target.closest('.pickup-slider')) return;
            renderPickupSheet();
        });
    }
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && pickupSheetState) {
            closePickupSheet();
        }
    });

    document.getElementById('tracking-date').addEventListener('change', loadDeliveries);

    document.getElementById('select-all-drivers').addEventListener('change', function() {
        const isChecked = this.checked;
        document.querySelectorAll('.driver-select').forEach(checkbox => {
            checkbox.checked = isChecked;
        });
        loadDeliveries();
    });

    document.querySelectorAll('.driver-select').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const allCheckboxes = document.querySelectorAll('.driver-select');
            const checkedCheckboxes = document.querySelectorAll('.driver-select:checked');
            const selectAllCheckbox = document.getElementById('select-all-drivers');

            if (checkedCheckboxes.length === 0) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            } else if (checkedCheckboxes.length === allCheckboxes.length) {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = true;
            } else {
                selectAllCheckbox.indeterminate = true;
            }

            loadDeliveries();
        });
    });

    document.getElementById('refresh-data').addEventListener('click', loadDeliveries);

    // Load route/cash data immediately — do not wait for Google Maps.
    // Previously totals stayed at $0.00 whenever Maps failed to call initMap.
    loadDeliveries();

    document.getElementById('show-tracking').addEventListener('change', function() {
        if (this.checked) {
            loadTrackingOverlay();
        } else {
            clearTrackingPaths();
        }
    });

    // Keep delivery, COD, photo, and GPS state current without interrupting an edit.
    window.setInterval(function() {
        if (document.hidden
            || document.querySelector('.delivery-stop.dragging')
            || document.querySelector('#reorder-status.is-saving')) return;
        loadDeliveries({ background: true });
    }, 60000);

    const photosClose = document.getElementById('deliveryPhotosModalClose');
    const photosModal = document.getElementById('deliveryPhotosModal');
    photosClose.addEventListener('click', closeDeliveryPhotosModal);
    photosClose.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            closeDeliveryPhotosModal();
        }
    });
    photosModal.addEventListener('click', (e) => {
        if (e.target === photosModal) closeDeliveryPhotosModal();
    });

    document.getElementById('photoLightboxClose').addEventListener('click', closePhotoLightbox);
    document.getElementById('photoLightbox').addEventListener('click', (e) => {
        if (e.target.id === 'photoLightbox' || e.target.id === 'photoLightboxClose') {
            closePhotoLightbox();
        }
    });

    const stopDetailClose = document.getElementById('stopDetailModalClose');
    const stopDetailBackdrop = document.getElementById('stopDetailBackdrop');
    const stopDetailModal = document.getElementById('stopDetailModal');
    stopDetailClose.addEventListener('click', closeStopDetailModal);
    stopDetailBackdrop.addEventListener('click', closeStopDetailModal);
    stopDetailModal.addEventListener('click', (e) => {
        if (e.target === stopDetailModal) closeStopDetailModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        if (document.getElementById('photoLightbox').style.display === 'flex') {
            closePhotoLightbox();
            return;
        }
        if (document.getElementById('stopDetailModal').style.display === 'flex') {
            closeStopDetailModal();
            return;
        }
        if (document.getElementById('deliveryPhotosModal').style.display === 'flex') {
            closeDeliveryPhotosModal();
        }
    });
});

window.initMap = initMap;
