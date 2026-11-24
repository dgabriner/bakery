(function () {
  'use strict';

  var boot = window.__SF_QUICK_PHOTO__;
  if (!boot) return;

  var stores = Array.isArray(boot.stores) ? boot.stores.slice() : [];
  var i18nMap = boot.i18n || {};
  var baseUrl = String(boot.baseUrl || '/');
  if (baseUrl.charAt(baseUrl.length - 1) !== '/') baseUrl += '/';

  var state = {
    open: false,
    selectedStore: null,
    pickerExpanded: true,
    photoType: 'Before',
    pendingBlob: null,
    pendingPreviewUrl: '',
    lastSavedThumbUrl: '',
    uploading: false,
    preparing: false,
    coords: null,
    date: String(boot.defaultDate || '')
  };

  function $(id) {
    return document.getElementById(id);
  }

  function t(key, vars) {
    var out = String(i18nMap[key] || key);
    if (vars) {
      Object.keys(vars).forEach(function (k) {
        out = out.split(':' + k).join(String(vars[k]));
      });
    }
    return out;
  }

  function escapeHtml(val) {
    return String(val == null ? '' : val)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function currentCsrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? String(meta.getAttribute('content') || '') : '';
  }

  async function refreshSessionCsrf() {
    if (boot.shopOnly) return;
    try {
      var res = await fetch(baseUrl + 'driver_session_ping.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': currentCsrfToken() }
      });
      if (!res.ok) return;
      var data = await res.json();
      if (data && data.csrf_token && typeof window.bakerySetCsrfToken === 'function') {
        window.bakerySetCsrfToken(data.csrf_token);
      }
    } catch (ignore) {}
  }

  function makeClientRequestId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return 'sqp-' + window.crypto.randomUUID();
    }
    return 'sqp-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
  }

  function looksLikeImageFile(file) {
    if (!file) return false;
    var mime = String(file.type || '').toLowerCase();
    if (mime.indexOf('image/') === 0) return true;
    return /\.(jpe?g|png|webp|gif|heic|heif)$/i.test(String(file.name || ''));
  }

  function normalizeImageBlob(file) {
    return new Promise(function (resolve) {
      if (!file || typeof document === 'undefined') {
        resolve(file);
        return;
      }
      var url = '';
      try {
        url = URL.createObjectURL(file);
      } catch (e) {
        resolve(file);
        return;
      }
      var img = new Image();
      img.onload = function () {
        try {
          var w = img.naturalWidth || img.width || 0;
          var h = img.naturalHeight || img.height || 0;
          URL.revokeObjectURL(url);
          if (!w || !h) {
            resolve(file);
            return;
          }
          var maxDim = 1280;
          var scale = Math.min(1, maxDim / Math.max(w, h));
          var outW = Math.max(1, Math.round(w * scale));
          var outH = Math.max(1, Math.round(h * scale));
          var canvas = document.createElement('canvas');
          canvas.width = outW;
          canvas.height = outH;
          var ctx = canvas.getContext('2d');
          if (!ctx) {
            resolve(file);
            return;
          }
          ctx.drawImage(img, 0, 0, outW, outH);
          canvas.toBlob(function (blob) {
            resolve(blob || file);
          }, 'image/jpeg', 0.74);
        } catch (err) {
          resolve(file);
        }
      };
      img.onerror = function () {
        try { URL.revokeObjectURL(url); } catch (ignore) {}
        resolve(file);
      };
      img.src = url;
    });
  }

  function findStoreById(id) {
    var key = String(id || '');
    if (!key) return null;
    for (var i = 0; i < stores.length; i++) {
      if (String(stores[i].id) === key) return stores[i];
    }
    return null;
  }

  function distanceMeters(lat1, lng1, lat2, lng2) {
    var R = 6371000;
    var toRad = Math.PI / 180;
    var dLat = (lat2 - lat1) * toRad;
    var dLng = (lng2 - lng1) * toRad;
    var a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.cos(lat1 * toRad) * Math.cos(lat2 * toRad) *
      Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function detectPageStoreContext() {
    if (boot.shopOnly) {
      return findStoreById('shop');
    }
    try {
      var params = new URLSearchParams(window.location.search || '');
      var urlCid = params.get('customer_id');
      if (urlCid) {
        var fromUrl = findStoreById(urlCid);
        if (fromUrl) return fromUrl;
      }
    } catch (ignore) {}

    var crSelect = document.getElementById('customer_id');
    if (crSelect && crSelect.value) {
      var fromSelect = findStoreById(crSelect.value);
      if (fromSelect) return fromSelect;
    }

    var nextBtn = document.querySelector('#nextStopCard .photo-complete-btn[data-customer-id]');
    if (nextBtn) {
      var nextCid = nextBtn.getAttribute('data-customer-id');
      var found = findStoreById(nextCid);
      if (found) {
        found = Object.assign({}, found, {
          dailyOrderId: parseInt(nextBtn.getAttribute('data-daily-order-id') || '0', 10) || found.dailyOrderId || 0,
          driverId: parseInt(nextBtn.getAttribute('data-driver-id') || '0', 10) || found.driverId || 0
        });
        return found;
      }
    }
    return null;
  }

  function updateNearbyFromGps() {
    if (!navigator.geolocation || typeof navigator.geolocation.getCurrentPosition !== 'function') {
      return;
    }
    try {
      navigator.geolocation.getCurrentPosition(function (pos) {
        if (!pos || !pos.coords) return;
        state.coords = {
          lat: pos.coords.latitude,
          lng: pos.coords.longitude
        };
        var closest = null;
        var closestDist = Infinity;
        stores.forEach(function (s) {
          if (s.lat != null && s.lng != null) {
            var d = distanceMeters(state.coords.lat, state.coords.lng, Number(s.lat), Number(s.lng));
            s.distanceM = Math.round(d);
            if (d < closestDist) {
              closestDist = d;
              closest = s;
            }
          }
        });
        if (!state.selectedStore && closest && closestDist <= 180) {
          state.selectedStore = closest;
          state.pickerExpanded = false;
        }
        if (state.open) {
          renderSheet();
        }
      }, function () {}, { enableHighAccuracy: false, timeout: 3500, maximumAge: 180000 });
    } catch (ignore) {}
  }

  function setStatus(msg, kind) {
    var el = $('sfQuickPhotoStatus');
    if (!el) return;
    if (!msg) {
      el.hidden = true;
      el.textContent = '';
      el.className = 'sf-qp-status';
      return;
    }
    el.hidden = false;
    el.textContent = msg;
    el.className = 'sf-qp-status' + (kind ? ' is-' + kind : '');
  }

  function revokePendingUrl() {
    if (state.pendingPreviewUrl) {
      try { URL.revokeObjectURL(state.pendingPreviewUrl); } catch (ignore) {}
      state.pendingPreviewUrl = '';
    }
  }

  function openNativeCameraSync() {
    var input = $('sfQuickCameraInput');
    if (!input || input.disabled || state.uploading || state.preparing) return false;
    try {
      input.value = '';
      input.click();
      return true;
    } catch (err) {
      return false;
    }
  }

  function openLibrarySync() {
    var input = $('sfQuickLibraryInput');
    if (!input || input.disabled || state.uploading || state.preparing) return false;
    try {
      input.value = '';
      input.click();
      return true;
    } catch (err) {
      return false;
    }
  }

  function renderStoreList() {
    var listEl = $('sfQuickStoreList');
    var searchEl = $('sfQuickStoreSearch');
    if (!listEl) return;

    var q = searchEl ? String(searchEl.value || '').trim().toLowerCase() : '';
    var filtered = stores.filter(function (s) {
      if (!q) return true;
      var hay = (String(s.name || '') + ' ' + String(s.address || '') + ' ' + String(s.zone || '')).toLowerCase();
      return hay.indexOf(q) !== -1;
    });

    if (!filtered.length) {
      listEl.innerHTML = '<p class="sf-qp-prompt">' + escapeHtml(t('no_match')) + '</p>';
      return;
    }

    var nearby = [];
    var todayStops = [];
    var rest = [];

    filtered.forEach(function (s) {
      if (s.distanceM != null && s.distanceM <= 500) {
        nearby.push(s);
      } else if (s.todayStop) {
        todayStops.push(s);
      } else {
        rest.push(s);
      }
    });
    nearby.sort(function (a, b) { return (a.distanceM || 0) - (b.distanceM || 0); });

    var html = '';
    function appendGroup(label, items) {
      if (!items.length) return;
      if (label && !q) {
        html += '<div class="sf-qp-section-label">' + escapeHtml(label) + '</div>';
      }
      items.forEach(function (s) {
        var isSel = state.selectedStore && String(state.selectedStore.id) === String(s.id);
        var sub = s.address || s.zone || '';
        html += '<button type="button" class="sf-qp-store-row' + (isSel ? ' is-selected' : '') + '" data-qp-store-id="' + escapeHtml(s.id) + '">' +
          '<span class="sf-qp-store-row__text">' +
            '<span class="sf-qp-store-row__name">' + escapeHtml(s.name) + '</span>' +
            (sub ? '<span class="sf-qp-store-row__sub">' + escapeHtml(sub) + '</span>' : '') +
          '</span>' +
          '<span class="sf-qp-store-row__badges">' +
            (s.distanceM != null && s.distanceM <= 500 ? '<span class="sf-qp-badge sf-qp-badge--nearby">' + escapeHtml(t('nearby_badge')) + '</span>' : '') +
            (s.todayStop ? '<span class="sf-qp-badge">' + escapeHtml(t('today_badge')) + '</span>' : '') +
            '<span class="sf-qp-cam-pill" aria-hidden="true">&#128247;</span>' +
          '</span>' +
        '</button>';
      });
    }

    appendGroup(t('section_nearby'), nearby);
    appendGroup(t('section_today'), todayStops);
    appendGroup(nearby.length || todayStops.length ? t('section_all') : '', rest);
    listEl.innerHTML = html;
  }

  function renderSheet() {
    var selectedBox = $('sfQuickSelectedBox');
    var selectedName = $('sfQuickSelectedName');
    var selectedSub = $('sfQuickSelectedSub');
    var pickerBox = $('sfQuickPickerBox');
    var promptEl = $('sfQuickPrompt');
    var takeBtn = $('sfQuickTakeBtn');
    var libBtn = $('sfQuickLibraryBtn');
    var previewCard = $('sfQuickPreviewCard');
    var previewImg = $('sfQuickPreviewImg');
    var retryBtn = $('sfQuickRetryBtn');
    var anotherBtn = $('sfQuickAnotherBtn');
    var doneBtn = $('sfQuickDoneBtn');
    var typesBox = $('sfQuickTypes');

    var busy = state.uploading || state.preparing;
    if (takeBtn) {
      takeBtn.disabled = busy;
      takeBtn.textContent = state.selectedStore
        ? t('take_for_store', { store: state.selectedStore.name })
        : t('take_now');
    }
    if (libBtn) libBtn.disabled = busy;

    if (selectedBox) {
      if (state.selectedStore) {
        selectedBox.hidden = false;
        if (selectedName) selectedName.textContent = state.selectedStore.name;
        if (selectedSub) selectedSub.textContent = state.selectedStore.address || state.selectedStore.zone || '';
      } else {
        selectedBox.hidden = true;
      }
    }

    if (typesBox) {
      var isShop = state.selectedStore && String(state.selectedStore.id) === 'shop';
      typesBox.hidden = !!isShop;
      typesBox.querySelectorAll('[data-qp-type]').forEach(function (chip) {
        chip.setAttribute('aria-pressed', chip.getAttribute('data-qp-type') === state.photoType ? 'true' : 'false');
      });
    }

    var showPreview = !!(state.pendingPreviewUrl || state.lastSavedThumbUrl);
    if (previewCard) {
      previewCard.hidden = !showPreview;
      if (showPreview && previewImg) {
        previewImg.src = state.pendingPreviewUrl || state.lastSavedThumbUrl;
      }
    }
    if (retryBtn) retryBtn.hidden = !(state.pendingBlob && state.selectedStore && !busy);
    if (anotherBtn) anotherBtn.hidden = !(!state.pendingBlob && state.lastSavedThumbUrl && !busy);
    if (doneBtn) doneBtn.hidden = !(!state.pendingBlob && state.lastSavedThumbUrl && !busy);

    var showPicker = !state.selectedStore || state.pickerExpanded;
    if (pickerBox) {
      pickerBox.hidden = !showPicker;
      if (showPicker) {
        if (promptEl) {
          promptEl.textContent = state.pendingBlob
            ? t('photo_ready_pick_store')
            : t('pick_store_prompt');
        }
        renderStoreList();
      }
    }
  }

  async function uploadPendingPhoto() {
    if (!state.pendingBlob || !state.selectedStore || state.uploading) return;
    var store = state.selectedStore;
    state.uploading = true;
    setStatus(t('saving', { store: store.name }), '');
    renderSheet();

    try {
      await refreshSessionCsrf();
      var noteEl = $('sfQuickNote');
      var note = noteEl ? String(noteEl.value || '').trim() : '';
      var form = new FormData();
      var filename = 'store-photo-' + Date.now() + '.jpg';
      form.append('photo', state.pendingBlob, filename);

      var endpoint = baseUrl + 'upload_driver_photo.php';
      if (String(store.id) === 'shop') {
        endpoint = baseUrl + 'upload_shop_photo.php';
        form.append('action', 'upload');
        form.append('photo_date', state.date || boot.defaultDate || '');
        form.append('category', 'general');
        form.append('caption', note);
      } else {
        form.append('action', 'upload');
        form.append('store_visit', '1');
        form.append('customer_id', String(store.id));
        if (store.driverId) form.append('driver_id', String(store.driverId));
        else if (boot.defaultDriverId) form.append('driver_id', String(boot.defaultDriverId));
        if (store.dailyOrderId) form.append('daily_order_id', String(store.dailyOrderId));
        form.append('date', state.date || boot.defaultDate || '');
        form.append('photo_type', state.photoType || 'Before');
        form.append('notes', note);
        form.append('client_request_id', makeClientRequestId());
        if (state.coords) {
          form.append('latitude', String(state.coords.lat));
          form.append('longitude', String(state.coords.lng));
        }
      }

      var response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-CSRF-Token': currentCsrfToken() },
        body: form
      });
      var data = await response.json();
      if (!response.ok || !data || !data.success) {
        throw new Error((data && data.error) || t('upload_failed'));
      }

      state.lastSavedThumbUrl = (data.photo && (data.photo.url || data.photo.fallback_url)) || state.pendingPreviewUrl;
      state.pendingBlob = null;
      if (noteEl) noteEl.value = '';
      setStatus(t('saved', { store: store.name }), 'success');

      if (typeof CustomEvent === 'function') {
        document.dispatchEvent(new CustomEvent('bakery:store-photo-saved', {
          detail: {
            customerId: store.id,
            dailyOrderId: store.dailyOrderId || 0,
            photo: data.photo || null
          }
        }));
      }
    } catch (err) {
      setStatus((err && err.message) || t('upload_failed'), 'error');
    } finally {
      state.uploading = false;
      renderSheet();
    }
  }

  async function handleChosenFile(file) {
    if (!file || !looksLikeImageFile(file)) return;
    state.preparing = true;
    state.lastSavedThumbUrl = '';
    setStatus(t('preparing'), '');
    renderSheet();

    var normalized = await normalizeImageBlob(file);
    revokePendingUrl();
    state.pendingBlob = normalized;
    try {
      state.pendingPreviewUrl = URL.createObjectURL(normalized);
    } catch (ignore) {
      state.pendingPreviewUrl = '';
    }
    state.preparing = false;

    if (state.selectedStore) {
      state.pickerExpanded = false;
      await uploadPendingPhoto();
    } else {
      state.pickerExpanded = true;
      setStatus(t('photo_ready_pick_store'), '');
      renderSheet();
    }
  }

  function openQuickPhoto(triggerOpts) {
    triggerOpts = triggerOpts || {};
    var modal = $('sfQuickPhotoModal');
    if (!modal) return;

    state.open = true;
    state.lastSavedThumbUrl = '';
    setStatus('', '');

    if (triggerOpts.date) {
      state.date = String(triggerOpts.date);
    } else {
      state.date = String(boot.defaultDate || '');
    }

    var explicitStore = null;
    if (triggerOpts.customerId) {
      explicitStore = findStoreById(triggerOpts.customerId);
      if (!explicitStore && triggerOpts.customerName) {
        explicitStore = {
          id: triggerOpts.customerId,
          name: triggerOpts.customerName,
          address: triggerOpts.address || '',
          zone: ''
        };
      }
      if (explicitStore) {
        explicitStore = Object.assign({}, explicitStore, {
          dailyOrderId: parseInt(triggerOpts.dailyOrderId || '0', 10) || explicitStore.dailyOrderId || 0,
          driverId: parseInt(triggerOpts.driverId || '0', 10) || explicitStore.driverId || 0
        });
      }
    }

    state.selectedStore = explicitStore || detectPageStoreContext();
    state.pickerExpanded = !state.selectedStore;

    modal.hidden = false;
    document.body.classList.add('sf-quick-photo-open');
    var openNavMenu = document.querySelector('.bakery-nav__more[open]');
    if (openNavMenu) openNavMenu.removeAttribute('open');

    renderSheet();
    updateNearbyFromGps();
  }

  function closeQuickPhoto() {
    if (state.uploading || state.preparing) return;
    var modal = $('sfQuickPhotoModal');
    if (modal) modal.hidden = true;
    state.open = false;
    state.pendingBlob = null;
    revokePendingUrl();
    document.body.classList.remove('sf-quick-photo-open');
  }

  function bind() {
    var modal = $('sfQuickPhotoModal');
    if (!modal) return;

    document.addEventListener('click', function (e) {
      var trigger = e.target.closest && e.target.closest('[data-open-store-photo]');
      if (!trigger) return;
      e.preventDefault();
      var cid = trigger.getAttribute('data-customer-id') || '';
      openQuickPhoto({
        customerId: cid,
        customerName: trigger.getAttribute('data-customer-name') || '',
        dailyOrderId: trigger.getAttribute('data-daily-order-id') || '',
        driverId: trigger.getAttribute('data-driver-id') || '',
        address: trigger.getAttribute('data-address') || '',
        date: trigger.getAttribute('data-date') || ''
      });
      if (cid && trigger.getAttribute('data-auto-camera') === '1') {
        openNativeCameraSync();
      }
    });

    var closeBtn = $('sfQuickPhotoClose');
    if (closeBtn) closeBtn.addEventListener('click', closeQuickPhoto);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeQuickPhoto();
    });

    var takeBtn = $('sfQuickTakeBtn');
    if (takeBtn) {
      takeBtn.addEventListener('click', function () {
        openNativeCameraSync();
      });
    }

    var libBtn = $('sfQuickLibraryBtn');
    if (libBtn) {
      libBtn.addEventListener('click', function () {
        openLibrarySync();
      });
    }

    var anotherBtn = $('sfQuickAnotherBtn');
    if (anotherBtn) {
      anotherBtn.addEventListener('click', function () {
        openNativeCameraSync();
      });
    }

    var doneBtn = $('sfQuickDoneBtn');
    if (doneBtn) {
      doneBtn.addEventListener('click', closeQuickPhoto);
    }

    var retryBtn = $('sfQuickRetryBtn');
    if (retryBtn) {
      retryBtn.addEventListener('click', function () {
        uploadPendingPhoto();
      });
    }

    var changeBtn = $('sfQuickChangeStoreBtn');
    if (changeBtn) {
      changeBtn.addEventListener('click', function () {
        state.pickerExpanded = !state.pickerExpanded;
        renderSheet();
        var search = $('sfQuickStoreSearch');
        if (state.pickerExpanded && search) {
          search.focus();
        }
      });
    }

    var searchEl = $('sfQuickStoreSearch');
    if (searchEl) {
      searchEl.addEventListener('input', renderStoreList);
    }

    var listEl = $('sfQuickStoreList');
    if (listEl) {
      listEl.addEventListener('click', function (e) {
        var row = e.target.closest && e.target.closest('[data-qp-store-id]');
        if (!row || state.uploading || state.preparing) return;
        var chosen = findStoreById(row.getAttribute('data-qp-store-id'));
        if (!chosen) return;
        state.selectedStore = chosen;
        state.pickerExpanded = false;
        setStatus('', '');
        renderSheet();
        if (state.pendingBlob) {
          uploadPendingPhoto();
        } else {
          openNativeCameraSync();
        }
      });
    }

    var typesBox = $('sfQuickTypes');
    if (typesBox) {
      typesBox.addEventListener('click', function (e) {
        var chip = e.target.closest && e.target.closest('[data-qp-type]');
        if (!chip) return;
        state.photoType = chip.getAttribute('data-qp-type') || 'Before';
        renderSheet();
      });
    }

    var camInput = $('sfQuickCameraInput');
    if (camInput) {
      camInput.addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0];
        if (f) handleChosenFile(f);
      });
    }

    var libInput = $('sfQuickLibraryInput');
    if (libInput) {
      libInput.addEventListener('change', function (e) {
        var f = e.target.files && e.target.files[0];
        if (f) handleChosenFile(f);
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && state.open) {
        closeQuickPhoto();
      }
    });
  }

  window.BakeryQuickStorePhoto = {
    open: openQuickPhoto,
    close: closeQuickPhoto
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();
