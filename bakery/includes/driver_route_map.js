/**
 * Compact My Route map: where the driver is, remaining stops, next pin.
 * View + locate this iteration. Marker ids are stable for a later map reorder.
 */
(function () {
  'use strict';

  var SF = { lat: 37.7749, lng: -122.4194 };
  var map = null;
  var directionsService = null;
  var directionsRenderer = null;
  var fallbackLine = null;
  var markers = {};
  var youMarker = null;
  var youCircle = null;
  var watchId = null;
  var youPos = null;
  var follow = false;
  var userDragged = false;
  var selectedId = 0;
  var lastPathKey = '';
  var pathTimer = 0;
  var ready = false;
  // Tiny screens begin with the immediate delivery decision. Drivers can widen to
  // the next three stops or the complete dated route without changing route_order.
  var viewMode = 'next';
  var scopeLoaded = false;
  var routeMetrics = { totalDuration: 0, totalDistance: 0, byStopId: {} };

  function t(key, fallback) {
    var di = window.__DRIVER_PAGE_I18N__ || {};
    var value = di[key];
    return value == null || value === '' ? (fallback || key) : String(value);
  }

  function $(id) {
    return document.getElementById(id);
  }

  function mapsReady() {
    return typeof google !== 'undefined' && google.maps && typeof google.maps.Map === 'function';
  }

  function validCoords(lat, lng) {
    var nlat = Number(lat);
    var nlng = Number(lng);
    return Number.isFinite(nlat) && Number.isFinite(nlng)
      && nlat >= -90 && nlat <= 90
      && nlng >= -180 && nlng <= 180
      && !(nlat === 0 && nlng === 0);
  }

  function fillTemplate(template, vars) {
    var out = String(template || '');
    Object.keys(vars || {}).forEach(function (key) {
      out = out.split(':' + key).join(String(vars[key]));
    });
    return out;
  }

  function haversineMiles(a, b) {
    if (!a || !b) return null;
    var r = 3958.8;
    var dLat = (b.lat - a.lat) * Math.PI / 180;
    var dLng = (b.lng - a.lng) * Math.PI / 180;
    var lat1 = a.lat * Math.PI / 180;
    var lat2 = b.lat * Math.PI / 180;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
      + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * r * Math.asin(Math.min(1, Math.sqrt(h)));
  }

  function formatDistance(miles) {
    if (miles == null || !Number.isFinite(miles)) return '';
    if (miles < 0.1) {
      var feet = Math.max(1, Math.round(miles * 5280));
      return fillTemplate(t('feet_short', ':n ft'), { n: String(feet) });
    }
    var rounded = miles < 10 ? miles.toFixed(1) : String(Math.round(miles));
    return fillTemplate(t('miles_short', ':n mi'), { n: rounded });
  }

  function formatMeters(meters) {
    if (!Number.isFinite(meters) || meters <= 0) return '';
    return formatDistance(meters / 1609.344);
  }

  function formatDuration(seconds) {
    if (!Number.isFinite(seconds) || seconds <= 0) return '';
    var minutes = Math.max(1, Math.round(seconds / 60));
    if (minutes < 60) {
      return fillTemplate(t('map_minutes_short', ':count min'), { count: String(minutes) });
    }
    return fillTemplate(t('map_hour_minutes_short', ':hours h :minutes min'), {
      hours: String(Math.floor(minutes / 60)),
      minutes: String(minutes % 60)
    });
  }

  function formatMetric(metric) {
    if (!metric) return '';
    var duration = formatDuration(metric.duration || 0);
    var distance = formatMeters(metric.distance || 0);
    if (!duration) return distance;
    if (!distance) return duration;
    return fillTemplate(t('map_duration_distance', ':duration · :distance'), {
      duration: duration,
      distance: distance
    });
  }

  function clockParts(value) {
    var match = String(value || '').match(/^(\d{1,2}):(\d{2})/);
    if (!match) return null;
    var hours = Number(match[1]);
    var minutes = Number(match[2]);
    if (hours < 0 || hours > 23 || minutes < 0 || minutes > 59) return null;
    return { hours: hours, minutes: minutes };
  }

  function displayTime(value) {
    var parts = clockParts(value);
    if (!parts) return '';
    var suffix = parts.hours >= 12 ? 'PM' : 'AM';
    var hours = parts.hours % 12 || 12;
    return String(hours) + ':' + String(parts.minutes).padStart(2, '0') + ' ' + suffix;
  }

  function routeIsToday() {
    var root = $('driverRouteMap');
    var routeDate = root ? root.getAttribute('data-route-date') : '';
    var now = new Date();
    var localDate = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0')
      + '-' + String(now.getDate()).padStart(2, '0');
    return routeDate === localDate;
  }

  function windowRange(stop) {
    var after = displayTime(stop.deliverAfter);
    var by = displayTime(stop.deliverBy);
    if (after && by) return fillTemplate(t('map_window_range', ':from–:to'), { from: after, to: by });
    if (by) return fillTemplate(t('map_window_by', 'By :time'), { time: by });
    return after;
  }

  function windowStatus(stop) {
    if (!stop || !routeIsToday()) return null;
    var now = new Date();
    var minutesNow = now.getHours() * 60 + now.getMinutes();
    var after = clockParts(stop.deliverAfter);
    var by = clockParts(stop.deliverBy);
    var afterMinutes = after ? after.hours * 60 + after.minutes : null;
    var byMinutes = by ? by.hours * 60 + by.minutes : null;
    if (afterMinutes != null && minutesNow < afterMinutes) {
      return { tone: 'wait', text: fillTemplate(t('map_window_opens', 'Opens :time'), { time: displayTime(stop.deliverAfter) }) };
    }
    if (byMinutes != null && minutesNow > byMinutes) {
      return { tone: 'late', text: fillTemplate(t('map_window_late', 'Late — by :time'), { time: displayTime(stop.deliverBy) }) };
    }
    if (byMinutes != null && minutesNow >= byMinutes - 30) {
      return { tone: 'due', text: fillTemplate(t('map_window_due', 'Due by :time'), { time: displayTime(stop.deliverBy) }) };
    }
    if (byMinutes != null) {
      return { tone: 'normal', text: fillTemplate(t('map_window_by', 'By :time'), { time: displayTime(stop.deliverBy) }) };
    }
    return null;
  }

  function pinSvg(fill, ring) {
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="36" height="44" viewBox="0 0 36 44">'
      + '<path fill="' + fill + '" stroke="' + (ring || '#fff') + '" stroke-width="2.4" '
      + 'd="M18 2.2c7.4 0 13.4 6 13.4 13.4 0 9.6-13.4 26-13.4 26S4.6 25.2 4.6 15.6C4.6 8.2 10.6 2.2 18 2.2z"/>'
      + '</svg>';
    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
  }

  function youSvg() {
    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 22 22">'
      + '<circle cx="11" cy="11" r="10" fill="#1d4ed8" stroke="#fff" stroke-width="3"/>'
      + '</svg>';
    return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svg);
  }

  function pinIcon(kind) {
    var fill = '#5b6b70';
    var size = 28;
    if (kind === 'next') {
      fill = '#0f766e';
      size = 40;
    } else if (kind === 'remaining') {
      fill = '#1f8a78';
      size = 34;
    } else if (kind === 'failed') {
      fill = '#b45309';
      size = 32;
    } else if (kind === 'selected') {
      fill = '#0f172a';
      size = 40;
    }
    return {
      url: pinSvg(fill, kind === 'selected' ? '#fbbf24' : '#fff'),
      scaledSize: new google.maps.Size(size, Math.round(size * 1.22)),
      anchor: new google.maps.Point(size / 2, Math.round(size * 1.18)),
      labelOrigin: new google.maps.Point(size / 2, Math.round(size * 0.42))
    };
  }

  function readStops() {
    var out = [];
    document.querySelectorAll('#stopList .stop-item, #pastStopsList .stop-item').forEach(function (el) {
      var lat = el.getAttribute('data-lat');
      var lng = el.getAttribute('data-lng');
      var status = el.getAttribute('data-status') || 'pending';
      var remaining = !!el.closest('#stopList') && status !== 'delivered' && status !== 'cancelled';
      out.push({
        el: el,
        id: parseInt(el.getAttribute('data-daily-order-id') || '0', 10),
        name: el.getAttribute('data-customer-name') || '',
        address: el.getAttribute('data-address') || '',
        phone: el.getAttribute('data-phone') || '',
        hours: el.getAttribute('data-receiving-hours') || '',
        zone: el.getAttribute('data-zone') || '',
        pieces: parseInt(el.getAttribute('data-ordered-pieces') || '0', 10),
        notes: el.getAttribute('data-stop-notes') || '',
        time: el.getAttribute('data-scheduled-time') || '',
        deliverAfter: el.getAttribute('data-deliver-after') || '',
        deliverBy: el.getAttribute('data-deliver-by') || '',
        status: status,
        routeOrder: el.getAttribute('data-route-order') || '',
        lat: validCoords(lat, lng) ? Number(lat) : null,
        lng: validCoords(lat, lng) ? Number(lng) : null,
        mapsUrl: el.getAttribute('data-maps-url') || '',
        remaining: remaining
      });
    });
    return out;
  }

  function remainingStops(stops) {
    return (stops || readStops()).filter(function (stop) { return stop.remaining; });
  }

  function visibleStops(stops) {
    var remaining = remainingStops(stops);
    if (viewMode === 'next') return remaining.slice(0, 1);
    if (viewMode === 'nearby') return remaining.slice(0, 3);
    return remaining;
  }

  function mapped(stops) {
    return (stops || []).filter(function (stop) { return stop.lat != null && stop.lng != null; });
  }

  function setLiveText(text) {
    var el = $('routeMapLive');
    if (el) el.textContent = text;
  }

  function updateLive(stops) {
    var remaining = remainingStops(stops);
    var next = remaining[0] || null;
    var after = Math.max(0, remaining.length - 1);
    var unmapped = remaining.filter(function (stop) { return stop.lat == null; }).length;
    var bits = [];
    bits.push(t('map_scope_' + viewMode, 'Next stop'));
    if (next) {
      var dist = '';
      if (youPos && next.lat != null) {
        dist = formatDistance(haversineMiles(youPos, { lat: next.lat, lng: next.lng }));
      }
      if (dist) {
        bits.push(fillTemplate(t('map_next_distance', ':distance to :name'), {
          distance: dist,
          name: next.name
        }));
      } else {
        bits.push(next.name);
      }
      if (after > 0) {
        bits.push(fillTemplate(t('map_remaining_after', ':count after'), { count: String(after) }));
      }
    } else {
      bits.push(t('route_complete', 'Route complete'));
    }
    if (!youPos) {
      bits.push(t('map_no_location', 'Location off'));
    }
    setLiveText(bits.filter(Boolean).join(' · '));

    var unmappedEl = $('routeMapUnmapped');
    if (unmappedEl) {
      if (unmapped > 0) {
        unmappedEl.hidden = false;
        unmappedEl.textContent = fillTemplate(t('map_unmapped', ':count stops have no pin'), {
          count: String(unmapped)
        });
      } else {
        unmappedEl.hidden = true;
        unmappedEl.textContent = '';
      }
    }
    renderMapInsights(stops);
    renderHorizon(stops);
  }

  function renderMapInsights(stops) {
    var driveEl = $('routeMapDrive');
    var windowEl = $('routeMapWindow');
    var remaining = remainingStops(stops);
    var next = remaining[0] || null;
    if (driveEl) {
      var metric = viewMode === 'next' && next
        ? routeMetrics.byStopId[String(next.id)]
        : (routeMetrics.totalDuration || routeMetrics.totalDistance ? routeMetrics : null);
      var metricText = formatMetric(metric);
      driveEl.hidden = !metricText;
      driveEl.textContent = metricText
        ? (viewMode === 'next' ? t('map_drive', 'Drive') : t('map_day_drive', 'Route')) + ' · ' + metricText
        : '';
    }
    if (windowEl) {
      var status = windowStatus(next);
      windowEl.hidden = !status;
      windowEl.textContent = status ? status.text : '';
      windowEl.setAttribute('data-tone', status ? status.tone : 'normal');
    }
  }

  function renderHorizon(stops) {
    var root = $('routeMapHorizon');
    var items = $('routeMapHorizonItems');
    var count = $('routeMapHorizonCount');
    if (!root || !items || !count) return;
    var nextStops = remainingStops(stops).slice(0, 3);
    root.hidden = nextStops.length === 0;
    count.textContent = nextStops.length ? String(nextStops.length) : '';
    while (items.firstChild) items.removeChild(items.firstChild);
    nextStops.forEach(function (stop, index) {
      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'route-map-horizon-stop' + (index === 0 ? ' is-next' : '');
      button.setAttribute('data-daily-order-id', String(stop.id));
      var order = document.createElement('span');
      order.className = 'route-map-horizon-order';
      order.textContent = String(index + 1);
      var copy = document.createElement('span');
      copy.className = 'route-map-horizon-copy';
      var name = document.createElement('strong');
      name.textContent = stop.name;
      var detail = document.createElement('small');
      var metricText = formatMetric(routeMetrics.byStopId[String(stop.id)]);
      var status = index === 0 ? windowStatus(stop) : null;
      detail.textContent = [metricText, status ? status.text : (windowRange(stop) || stop.time)].filter(Boolean).join(' · ');
      copy.appendChild(name);
      if (detail.textContent) copy.appendChild(detail);
      button.appendChild(order);
      button.appendChild(copy);
      button.addEventListener('click', function () {
        selectStop(stop.id, true);
      });
      items.appendChild(button);
    });
  }

  function hideSheet() {
    selectedId = 0;
    var sheet = $('routeMapSheet');
    if (sheet) sheet.hidden = true;
    document.body.classList.remove('route-map-sheet-open');
  }

  function fillSheet(stop, remainingIndex, remainingCount) {
    var sheet = $('routeMapSheet');
    if (!sheet || !stop) return;
    selectedId = stop.id;
    sheet.hidden = false;
    document.body.classList.add('route-map-sheet-open');
    var kicker = $('routeMapSheetKicker');
    var name = $('routeMapSheetName');
    var meta = $('routeMapSheetMeta');
    var address = $('routeMapSheetAddress');
    var nav = $('routeMapSheetNavigate');
    var goNext = $('routeMapSheetGoNext');
    var photo = $('routeMapSheetPhoto');
    if (kicker) {
      var label = remainingIndex === 0
        ? t('next_label', 'Next')
        : (stop.routeOrder ? '#' + stop.routeOrder : t('this_stop', 'This stop'));
      kicker.textContent = label;
    }
    if (name) name.textContent = stop.name;
    if (address) address.textContent = stop.address || t('no_address_short', 'No address');
    if (meta) {
      var chips = [];
      if (stop.pieces > 0 && stop.remaining) {
        chips.push(fillTemplate(t('map_pieces', ':count pcs'), { count: String(stop.pieces) }));
      }
      if (stop.hours) chips.push(stop.hours);
      if (stop.time) chips.push(stop.time);
      if (stop.zone) chips.push(stop.zone);
      if (!stop.remaining) chips.push(t('map_done', 'Done'));
      meta.textContent = chips.join(' · ');
    }
    if (nav) {
      var href = stop.mapsUrl || (window.DriverRoute && window.DriverRoute.mapsDirectionsUrl
        ? window.DriverRoute.mapsDirectionsUrl(stop.address, stop.lat, stop.lng)
        : '');
      nav.hidden = !href;
      if (href) nav.setAttribute('href', href);
    }
    if (goNext) {
      goNext.hidden = !stop.remaining || remainingIndex === 0 || remainingCount < 2;
      goNext.setAttribute('data-daily-order-id', String(stop.id));
    }
    if (photo) {
      photo.hidden = !stop.remaining;
      photo.setAttribute('data-daily-order-id', String(stop.id));
    }
  }

  function markerKind(stop, remainingIndex, selected) {
    if (selected) return 'selected';
    if (!stop.remaining) return 'done';
    if (stop.status === 'failed') return 'failed';
    if (remainingIndex === 0) return 'next';
    return 'remaining';
  }

  function markerLabel(stop, remainingIndex) {
    if (!stop.remaining) return { text: '✓', color: '#fff', fontSize: '11px', fontWeight: '800' };
    var text = remainingIndex >= 0 ? String(remainingIndex + 1) : (stop.routeOrder || '•');
    return { text: text, color: '#fff', fontSize: remainingIndex === 0 ? '14px' : '12px', fontWeight: '800' };
  }

  function clearMarkers() {
    Object.keys(markers).forEach(function (id) {
      markers[id].setMap(null);
    });
    markers = {};
  }

  function bindMarker(stop, remainingIndex, remainingCount) {
    if (!map || stop.lat == null) return;
    var selected = selectedId === stop.id;
    var marker = new google.maps.Marker({
      map: map,
      position: { lat: stop.lat, lng: stop.lng },
      icon: pinIcon(markerKind(stop, remainingIndex, selected)),
      label: markerLabel(stop, remainingIndex),
      title: stop.name,
      zIndex: selected ? 400 : (stop.remaining ? (remainingIndex === 0 ? 300 : 200 - remainingIndex) : 50),
      optimized: false
    });
    marker.addListener('click', function () {
      onMarkerTap(stop.id);
    });
    markers[String(stop.id)] = marker;
  }

  function onMarkerTap(id) {
    var route = window.DriverRoute;
    if (route && typeof route.isAdjustOpen === 'function' && route.isAdjustOpen()) {
      if (typeof route.tapAdjustStop === 'function') {
        route.tapAdjustStop(id);
      }
      return;
    }
    selectStop(id, true);
  }

  function selectStop(id, pan) {
    var stops = readStops();
    var remaining = remainingStops(stops);
    var stop = null;
    var remainingIndex = -1;
    remaining.forEach(function (row, index) {
      if (row.id === id) remainingIndex = index;
    });
    stops.forEach(function (row) {
      if (row.id === id) stop = row;
    });
    if (!stop) return;
    if (remainingIndex > 0 && viewMode === 'next') {
      setViewMode('nearby');
    }
    fillSheet(stop, remainingIndex, remaining.length);
    syncMarkers(stops);
    if (pan && map && stop.lat != null) {
      follow = false;
      userDragged = true;
      syncFollowButton();
      map.panTo({ lat: stop.lat, lng: stop.lng });
      if (map.getZoom() < 14) map.setZoom(14);
    }
  }

  function syncMarkers(stops) {
    if (!map) return;
    var remaining = remainingStops(stops);
    var remainingIndexById = {};
    remaining.forEach(function (stop, index) {
      remainingIndexById[stop.id] = index;
    });
    clearMarkers();
    mapped(visibleStops(stops)).forEach(function (stop) {
      var idx = Object.prototype.hasOwnProperty.call(remainingIndexById, stop.id)
        ? remainingIndexById[stop.id]
        : -1;
      bindMarker(stop, idx, remaining.length);
    });
  }

  function drawFallbackPath(points) {
    if (fallbackLine) {
      fallbackLine.setMap(null);
      fallbackLine = null;
    }
    if (!map || points.length < 2) return;
    fallbackLine = new google.maps.Polyline({
      map: map,
      path: points,
      geodesic: true,
      strokeColor: '#0f766e',
      strokeOpacity: 0.9,
      strokeWeight: 4,
      zIndex: 20
    });
  }

  function drawPath(stops) {
    if (!map) return;
    var remaining = mapped(visibleStops(stops));
    var points = remaining.map(function (stop) {
      return { lat: stop.lat, lng: stop.lng };
    });
    if (youPos) {
      points = [{ lat: youPos.lat, lng: youPos.lng }].concat(points);
    }
    var key = viewMode + '|' + points.map(function (pt) {
      return pt.lat.toFixed(5) + ',' + pt.lng.toFixed(5);
    }).join('|');
    if (key === lastPathKey) return;
    lastPathKey = key;
    routeMetrics = { totalDuration: 0, totalDistance: 0, byStopId: {} };
    renderMapInsights(stops);
    renderHorizon(stops);

    if (directionsRenderer) {
      directionsRenderer.setMap(null);
    }
    if (fallbackLine) {
      fallbackLine.setMap(null);
      fallbackLine = null;
    }
    if (points.length < 2) {
      drawFallbackPath(points);
      return;
    }

    if (!directionsService || !directionsRenderer || points.length > 27) {
      drawFallbackPath(points);
      return;
    }

    var origin = points[0];
    var destination = points[points.length - 1];
    var waypoints = points.slice(1, -1).map(function (pt) {
      return { location: pt, stopover: true };
    });
    directionsRenderer.setMap(map);
    var hasDriverOrigin = !!youPos;
    directionsService.route({
      origin: origin,
      destination: destination,
      waypoints: waypoints,
      optimizeWaypoints: false,
      travelMode: google.maps.TravelMode.DRIVING
    }, function (result, status) {
      if (key !== lastPathKey) return;
      if (status === 'OK' && result) {
        directionsRenderer.setDirections(result);
        applyDirectionsMetrics(result, remaining, hasDriverOrigin);
        renderMapInsights(stops);
        renderHorizon(stops);
      } else {
        drawFallbackPath(points);
      }
    });
  }

  function applyDirectionsMetrics(result, stops, hasDriverOrigin) {
    var legs = result && result.routes && result.routes[0] && result.routes[0].legs;
    if (!Array.isArray(legs)) return;
    var byStopId = {};
    var totalDuration = 0;
    var totalDistance = 0;
    legs.forEach(function (leg, index) {
      var duration = Number(leg && leg.duration && leg.duration.value) || 0;
      var distance = Number(leg && leg.distance && leg.distance.value) || 0;
      totalDuration += duration;
      totalDistance += distance;
      var targetIndex = hasDriverOrigin ? index : index + 1;
      var target = stops[targetIndex];
      if (target) byStopId[String(target.id)] = { duration: duration, distance: distance };
    });
    routeMetrics = { totalDuration: totalDuration, totalDistance: totalDistance, byStopId: byStopId };
  }

  function queuePath(stops) {
    window.clearTimeout(pathTimer);
    pathTimer = window.setTimeout(function () {
      drawPath(stops || readStops());
    }, 280);
  }

  function fitView(stops) {
    if (!map) return;
    var bounds = new google.maps.LatLngBounds();
    var count = 0;
    mapped(visibleStops(stops)).forEach(function (stop) {
      bounds.extend({ lat: stop.lat, lng: stop.lng });
      count += 1;
    });
    if (youPos) {
      bounds.extend(youPos);
      count += 1;
    }
    if (count === 0) {
      mapped(stops).forEach(function (stop) {
        bounds.extend({ lat: stop.lat, lng: stop.lng });
        count += 1;
      });
    }
    if (count === 0) {
      map.setCenter(SF);
      map.setZoom(12);
      return;
    }
    if (count === 1) {
      map.setCenter(bounds.getCenter());
      map.setZoom(15);
      return;
    }
    map.fitBounds(bounds, { top: 48, right: 36, bottom: 72, left: 36 });
  }

  function syncFollowButton() {
    var btn = $('routeMapFollow');
    if (!btn) return;
    btn.setAttribute('aria-pressed', follow ? 'true' : 'false');
    btn.textContent = follow ? t('map_following', 'Following') : t('map_follow', 'Follow');
  }

  function syncScopeButtons() {
    var root = $('driverRouteMap');
    if (root) root.setAttribute('data-map-mode', viewMode);
    document.querySelectorAll('#driverRouteMap [data-map-scope]').forEach(function (button) {
      button.setAttribute('aria-pressed', button.getAttribute('data-map-scope') === viewMode ? 'true' : 'false');
    });
  }

  function scopeStorageKey() {
    var root = $('driverRouteMap');
    if (!root) return '';
    var date = root.getAttribute('data-route-date') || '';
    var driverId = root.getAttribute('data-driver-id') || '';
    return date && driverId ? 'bakery-route-map-scope:' + driverId + ':' + date : '';
  }

  function restoreViewMode() {
    if (scopeLoaded) return;
    scopeLoaded = true;
    try {
      var saved = window.sessionStorage.getItem(scopeStorageKey());
      if (['next', 'nearby', 'day'].indexOf(saved) !== -1) viewMode = saved;
    } catch (ignore) {
      // Private browsing may block storage; the safe default remains next stop.
    }
  }

  function rememberViewMode() {
    try {
      var key = scopeStorageKey();
      if (key) window.sessionStorage.setItem(key, viewMode);
    } catch (ignore) {
      // Map scope is a convenience only, never a required route record.
    }
  }

  function setViewMode(mode) {
    if (['next', 'nearby', 'day'].indexOf(mode) === -1) return;
    viewMode = mode;
    rememberViewMode();
    hideSheet();
    syncScopeButtons();
    var stops = readStops();
    updateLive(stops);
    lastPathKey = '';
    if (map) {
      syncMarkers(stops);
      queuePath(stops);
      fitView(stops);
    }
  }

  function changeZoom(delta) {
    if (!map) return;
    follow = false;
    userDragged = true;
    syncFollowButton();
    var zoom = map.getZoom();
    if (typeof zoom === 'number') map.setZoom(Math.max(3, Math.min(20, zoom + delta)));
  }

  function setYou(pos, accuracy) {
    var hadLocation = !!youPos;
    youPos = pos;
    if (!map) {
      updateLive(readStops());
      return;
    }
    if (!youMarker) {
      youMarker = new google.maps.Marker({
        map: map,
        position: pos,
        icon: {
          url: youSvg(),
          scaledSize: new google.maps.Size(22, 22),
          anchor: new google.maps.Point(11, 11)
        },
        title: t('map_you_are_here', 'You'),
        zIndex: 500,
        optimized: false
      });
    } else {
      youMarker.setPosition(pos);
    }
    if (accuracy && accuracy < 250) {
      if (!youCircle) {
        youCircle = new google.maps.Circle({
          map: map,
          center: pos,
          radius: accuracy,
          fillColor: '#3b82f6',
          fillOpacity: 0.12,
          strokeColor: '#2563eb',
          strokeOpacity: 0.4,
          strokeWeight: 1,
          clickable: false,
          zIndex: 10
        });
      } else {
        youCircle.setCenter(pos);
        youCircle.setRadius(accuracy);
      }
    }
    if (follow && !userDragged) {
      map.panTo(pos);
    }
    var stops = readStops();
    updateLive(stops);
    lastPathKey = '';
    queuePath(stops);
    // The first GPS fix is the moment the default next-leg view can genuinely
    // frame the driver and destination together. Do not steal the view again
    // after the driver has panned or zoomed manually.
    if (!hadLocation && !userDragged) fitView(stops);
  }

  function startWatch() {
    if (!navigator.geolocation || watchId != null) return;
    watchId = navigator.geolocation.watchPosition(
      function (pos) {
        setYou(
          { lat: pos.coords.latitude, lng: pos.coords.longitude },
          pos.coords.accuracy || 0
        );
      },
      function () {
        youPos = youPos || null;
        updateLive(readStops());
      },
      {
        enableHighAccuracy: true,
        maximumAge: 4000,
        timeout: 12000
      }
    );
  }

  function locateOnce(thenFollow) {
    if (!navigator.geolocation) {
      setLiveText(t('map_need_location', 'Allow location to see where you are'));
      return;
    }
    navigator.geolocation.getCurrentPosition(
      function (pos) {
        setYou(
          { lat: pos.coords.latitude, lng: pos.coords.longitude },
          pos.coords.accuracy || 0
        );
        follow = !!thenFollow;
        userDragged = false;
        syncFollowButton();
        if (map) map.panTo(youPos);
        startWatch();
      },
      function () {
        setLiveText(t('map_need_location', 'Allow location to see where you are'));
      },
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 5000 }
    );
  }

  function resizeMap() {
    if (!map) return;
    google.maps.event.trigger(map, 'resize');
  }

  function setExpanded(open) {
    document.body.classList.toggle('route-map-expanded', !!open);
    var btn = $('routeMapExpand');
    if (btn) {
      btn.setAttribute('aria-pressed', open ? 'true' : 'false');
      btn.textContent = open ? t('map_collapse', 'Close') : t('map_expand', 'Expand');
    }
    window.setTimeout(function () {
      resizeMap();
      fitView(readStops());
    }, 80);
  }

  function createMap() {
    var canvas = $('routeMapCanvas');
    if (!canvas || map || !mapsReady()) return false;
    canvas.classList.remove('route-map-canvas--fallback');
    var fallbackNote = canvas.querySelector('.route-map-fallback');
    if (fallbackNote) fallbackNote.remove();
    map = new google.maps.Map(canvas, {
      center: SF,
      zoom: 12,
      mapTypeControl: false,
      streetViewControl: false,
      fullscreenControl: false,
      rotateControl: false,
      keyboardShortcuts: false,
      clickableIcons: false,
      gestureHandling: 'greedy',
      zoomControl: false,
      styles: [
        { featureType: 'poi', stylers: [{ visibility: 'off' }] },
        { featureType: 'transit', stylers: [{ visibility: 'off' }] },
        { featureType: 'administrative.neighborhood', stylers: [{ visibility: 'off' }] }
      ]
    });
    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
      map: map,
      suppressMarkers: true,
      preserveViewport: true,
      polylineOptions: {
        strokeColor: '#0f766e',
        strokeOpacity: 0.92,
        strokeWeight: 5
      }
    });
    map.addListener('dragstart', function () {
      userDragged = true;
      if (follow) {
        follow = false;
        syncFollowButton();
      }
    });
    map.addListener('click', function () {
      hideSheet();
      syncMarkers(readStops());
    });
    return true;
  }

  function bindChrome() {
    var root = $('driverRouteMap');
    if (!root || root.getAttribute('data-map-bound') === '1') return;
    root.setAttribute('data-map-bound', '1');
    var locate = $('routeMapLocate');
    var followBtn = $('routeMapFollow');
    var zoomOut = $('routeMapZoomOut');
    var zoomIn = $('routeMapZoomIn');
    var expand = $('routeMapExpand');
    var closeSheet = $('routeMapSheetClose');
    var goNext = $('routeMapSheetGoNext');
    var photo = $('routeMapSheetPhoto');
    if (locate) {
      locate.addEventListener('click', function () {
        locateOnce(false);
      });
    }
    if (followBtn) {
      followBtn.addEventListener('click', function () {
        follow = !follow;
        userDragged = false;
        syncFollowButton();
        if (follow) locateOnce(true);
      });
    }
    root.querySelectorAll('[data-map-scope]').forEach(function (button) {
      button.addEventListener('click', function () {
        setViewMode(button.getAttribute('data-map-scope') || 'next');
      });
    });
    if (zoomOut) zoomOut.addEventListener('click', function () { changeZoom(-1); });
    if (zoomIn) zoomIn.addEventListener('click', function () { changeZoom(1); });
    if (expand) {
      expand.addEventListener('click', function () {
        setExpanded(!document.body.classList.contains('route-map-expanded'));
      });
    }
    if (closeSheet) {
      closeSheet.addEventListener('click', function () {
        hideSheet();
        syncMarkers(readStops());
      });
    }
    if (goNext) {
      goNext.addEventListener('click', function () {
        var id = goNext.getAttribute('data-daily-order-id');
        if (window.DriverRoute && typeof window.DriverRoute.goNext === 'function') {
          window.DriverRoute.goNext(id).catch(function (err) {
            window.alert((err && err.message) || t('route_order_error', 'Could not update the route.'));
          });
        }
      });
    }
    if (photo) {
      photo.addEventListener('click', function () {
        var id = parseInt(photo.getAttribute('data-daily-order-id') || '0', 10);
        if (window.DriverRoute && typeof window.DriverRoute.openStop === 'function') {
          window.DriverRoute.openStop(id, { autoOpenCamera: true });
        }
      });
    }
  }

  function showFallback(message) {
    var canvas = $('routeMapCanvas');
    if (!canvas) return;
    canvas.classList.add('route-map-canvas--fallback');
    if (!canvas.querySelector('.route-map-fallback')) {
      var note = document.createElement('p');
      note.className = 'route-map-fallback';
      note.textContent = message || t('map_unavailable', 'Map unavailable. Stops and directions still work below.');
      canvas.appendChild(note);
    }
  }

  function refresh() {
    var root = $('driverRouteMap');
    if (!root) return;
    if (document.body.classList.contains('route-adjust-open')) {
      hideSheet();
    }
    var stops = readStops();
    updateLive(stops);
    if (!mapsReady()) return;
    if (!map && !createMap()) return;
    syncMarkers(stops);
    queuePath(stops);
    if (!ready) {
      ready = true;
      fitView(stops);
      startWatch();
    }
  }

  function init() {
    bindChrome();
    restoreViewMode();
    syncFollowButton();
    syncScopeButtons();
    if (!mapsReady()) {
      showFallback();
      updateLive(readStops());
      startWatch();
      return;
    }
    refresh();
  }

  window.DriverRouteMap = {
    init: init,
    refresh: refresh,
    selectStop: function (id) { selectStop(parseInt(String(id || 0), 10), true); },
    setFollow: function (on) {
      follow = !!on;
      userDragged = false;
      syncFollowButton();
      if (follow) locateOnce(true);
    },
    setViewMode: setViewMode
  };

  window.bakeryInitDriverRouteMap = function () {
    init();
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
