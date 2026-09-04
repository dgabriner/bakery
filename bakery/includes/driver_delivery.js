/**
 * Driver stop Photos / Complete modal: gallery, camera capture, upload, delete, complete.
 */
(function () {
  'use strict';

  var state = {
    stream: null,
    cameraActive: false,
    blob: null,
    driverId: 0,
    driverName: '',
    customerId: 0,
    dailyOrderId: 0,
    customerName: '',
    address: '',
    date: '',
    uploading: false,
    preparing: false,
    previewUrl: '',
    photos: [],
    viewingPhotoId: null,
    retakePhotoId: null,
    photoMode: 'capture',
    orderedPieces: 0,
    orderTotal: 0,
    pricingLabel: '',
    pricingMissing: false,
    pricePerPieceOverride: null,
    invoiceItems: [],
    savedTotal: 0,
    isSaved: false,
    paymentCollection: 'signature',
    amountCollected: null,
    summaryReady: false,
    invoiceReady: false,
    submitting: false,
    previousFocus: null,
    closeTimer: null,
    currentStep: 'photo',
    pendingVarianceConfirm: null,
    photoReturnStep: null,
    confirmationSaved: false,
    completionMessage: '',
    stopFinished: false,
    sessionRefreshPromise: null,
    scrollLockY: 0
  };

  var STEPS = ['photo', 'delivery', 'invoice'];

  function i18n(key, fallback) {
    var di = window.__DRIVER_PAGE_I18N__ || {};
    return di[key] || fallback || key;
  }

  function $(id) {
    return document.getElementById(id);
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function setStatus(message, kind) {
    var el = $('deliveryPhotoStatus');
    var cameraEl = $('deliveryCameraStatus');
    if (el) {
      el.textContent = message || '';
      el.className = 'photo-upload-status' + (kind ? ' is-' + kind : '');
    }
    if (cameraEl) {
      cameraEl.textContent = message || '';
      cameraEl.className = 'delivery-camera-status' + (kind ? ' is-' + kind : '');
    }
  }

  function revokePreviewUrl() {
    if (!state.previewUrl) return;
    try {
      URL.revokeObjectURL(state.previewUrl);
    } catch (err) {}
    state.previewUrl = '';
  }

  function resetOrderDetailsPanel() {
    var toggle = $('deliveryOrdersToggle');
    var panel = $('deliveryOrderDetails');
    var loading = panel && panel.querySelector('.order-details-loading');
    var content = panel && panel.querySelector('.order-details-content');
    if (toggle) toggle.setAttribute('aria-expanded', 'false');
    if (panel) panel.hidden = true;
    if (loading) loading.hidden = false;
    if (content) {
      content.hidden = true;
      content.innerHTML = '';
    }
  }

  function setOrderDetailsExpanded(expanded) {
    var toggle = $('deliveryOrdersToggle');
    var panel = $('deliveryOrderDetails');
    if (toggle) toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    if (panel) panel.hidden = !expanded;
  }

  async function loadModalOrderDetails() {
    var panel = $('deliveryOrderDetails');
    if (!panel || !state.dailyOrderId) return;
    var loading = panel.querySelector('.order-details-loading');
    var content = panel.querySelector('.order-details-content');
    setOrderDetailsExpanded(true);
    if (loading) loading.hidden = false;
    if (content) {
      content.hidden = true;
      content.innerHTML = '';
    }
    try {
      var response = await fetch('get_customer_order_details.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: 'daily_order_id=' + encodeURIComponent(String(state.dailyOrderId))
      });
      var data = await readJsonResponse(response, i18n('order_details_load_error'));
      if (loading) loading.hidden = true;
      if (!content) return;
      content.hidden = false;
      if (!data || !data.success) {
        content.innerHTML =
          '<div class="no-products">' +
          escapeHtml((data && (data.error || data.message)) || i18n('order_details_error')) +
          '</div>';
        return;
      }
      content.innerHTML = data.html ||
        ('<div class="no-products">' + escapeHtml(i18n('no_products_found')) + '</div>');
    } catch (err) {
      if (loading) loading.hidden = true;
      if (content) {
        content.hidden = false;
        content.innerHTML =
          '<div class="no-products">' +
          escapeHtml((err && err.message) || i18n('order_details_load_error')) +
          '</div>';
      }
    }
  }

  function toggleModalOrderDetails() {
    var panel = $('deliveryOrderDetails');
    if (!panel) return;
    if (!panel.hidden) {
      setOrderDetailsExpanded(false);
      return;
    }
    loadModalOrderDetails();
  }

  function formatTime(value) {
    if (!value) return '';
    var d = new Date(value.replace(' ', 'T'));
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString(window.__BAKERY_LOCALE__ === 'es' ? 'es-ES' : 'en-US', {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  async function readJsonResponse(response, fallbackMessage) {
    var raw = await response.text();
    var data = null;
    try {
      data = raw ? JSON.parse(raw) : null;
    } catch (err) {
      var statusHint = response.status === 401 || response.status === 403
        ? i18n('session_expired')
        : i18n('photo_service_invalid');
      throw new Error(statusHint);
    }
    if (response.status === 401 || response.status === 403) {
      var authError = new Error(i18n('session_expired'));
      authError.isSessionError = true;
      throw authError;
    }
    if (!response.ok) {
      throw new Error((data && (data.error || data.message)) || fallbackMessage || i18n('request_failed'));
    }
    return data;
  }

  function applyCsrfToken(token) {
    if (!token) return false;
    if (typeof window.bakerySetCsrfToken === 'function') {
      return window.bakerySetCsrfToken(token);
    }
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return false;
    meta.setAttribute('content', token);
    return true;
  }

  async function refreshRouteSession() {
    if (state.sessionRefreshPromise) {
      return state.sessionRefreshPromise;
    }

    state.sessionRefreshPromise = (async function () {
      var response = await fetch('driver_session_ping.php', {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      if (response.redirected && /login\.php(?:[?#]|$)/i.test(response.url || '')) {
        var redirectError = new Error(i18n('session_expired'));
        redirectError.isSessionError = true;
        throw redirectError;
      }
      var data = await readJsonResponse(response, i18n('session_expired'));
      if (!data || !data.success || !data.csrf_token) {
        var sessionError = new Error(i18n('session_expired'));
        sessionError.isSessionError = true;
        throw sessionError;
      }
      applyCsrfToken(data.csrf_token);
      return true;
    })();

    try {
      return await state.sessionRefreshPromise;
    } finally {
      state.sessionRefreshPromise = null;
    }
  }

  function stopCamera() {
    if (state.stream) {
      state.stream.getTracks().forEach(function (track) {
        track.stop();
      });
      state.stream = null;
    }
    state.cameraActive = false;
    var video = $('deliveryCameraVideo');
    if (video) {
      video.srcObject = null;
    }
  }

  function showLivePreview() {
    var video = $('deliveryCameraVideo');
    var canvas = $('deliveryCameraCanvas');
    var preview = $('deliveryPhotoPreview');
    var frame = $('deliveryCameraFrame');
    if (video) video.style.display = 'block';
    if (canvas) canvas.style.display = 'none';
    if (preview) {
      preview.style.display = 'none';
      preview.removeAttribute('src');
    }
    revokePreviewUrl();
    if (frame) frame.classList.toggle('is-camera-idle', !state.cameraActive);
    var modal = $('deliveryPhotoModal');
    if (modal) modal.classList.remove('camera-unavailable');
    state.blob = null;
    $('deliveryCaptureBtn').classList.remove('hidden');
    $('deliveryCaptureBtn').textContent = state.cameraActive ? i18n('take_photo') : i18n('activate_camera');
    $('deliveryRetakeBtn').classList.add('hidden');
  }

  function showCapturedPreview(objectUrl) {
    var video = $('deliveryCameraVideo');
    var canvas = $('deliveryCameraCanvas');
    var preview = $('deliveryPhotoPreview');
    var frame = $('deliveryCameraFrame');
    if (video) video.style.display = 'none';
    if (canvas) canvas.style.display = 'none';
    if (preview) {
      revokePreviewUrl();
      state.previewUrl = objectUrl || '';
      preview.src = objectUrl;
      preview.style.display = 'block';
    }
    if (frame) frame.classList.remove('is-camera-idle');
    $('deliveryCaptureBtn').classList.add('hidden');
    $('deliveryRetakeBtn').classList.remove('hidden');
  }

  function isLikelyMobile() {
    return /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
  }

  function usesCompactCapture() {
    if (isLikelyMobile()) return true;
    if (window.matchMedia) {
      return window.matchMedia('(max-width: 768px), (pointer: coarse)').matches;
    }
    return window.innerWidth <= 768;
  }

  function lockPhotoModalViewport() {
    if (!usesCompactCapture()) return;
    var modal = $('deliveryPhotoModal');
    if (!modal) return;
    state.scrollLockY = window.scrollY || document.documentElement.scrollTop || 0;
    document.body.style.top = '-' + state.scrollLockY + 'px';
    var height = window.innerHeight;
    if (window.visualViewport && window.visualViewport.height > 0) {
      height = Math.round(window.visualViewport.height);
    }
    modal.style.setProperty('--photo-modal-locked-height', height + 'px');
  }

  function unlockPhotoModalViewport() {
    var modal = $('deliveryPhotoModal');
    if (modal) {
      modal.style.removeProperty('--photo-modal-locked-height');
    }
    document.body.style.top = '';
    if (state.scrollLockY) {
      window.scrollTo(0, state.scrollLockY);
    }
    state.scrollLockY = 0;
  }

  function updateNativeCameraControls() {
    var pickerBtn = $('deliveryFilePickerBtn');
    var captureBtn = $('deliveryCaptureBtn');
    var skipBtn = $('deliverySkipPhotoInlineBtn');
    var isDeparture = state.photoReturnStep === 'complete';
    if (pickerBtn) {
      pickerBtn.textContent = isDeparture ? i18n('take_departure_photo') : i18n('phone_camera');
    }
    if (captureBtn) {
      captureBtn.textContent = isDeparture
        ? i18n('take_departure_photo')
        : (state.cameraActive ? i18n('take_photo') : i18n('activate_camera'));
    }
    if (skipBtn) {
      skipBtn.textContent = isDeparture ? i18n('skip_departure_photo') : i18n('skip_photo');
    }
  }

  function setPhotoControlsBusy(isBusy) {
    ['deliveryCaptureBtn', 'deliveryRetakeBtn', 'deliveryFilePickerBtn', 'deliveryGalleryPickerBtn', 'deliverySkipPhotoInlineBtn']
      .forEach(function (id) {
        var button = $(id);
        if (button) button.disabled = isBusy;
      });
    var cameraInput = $('deliveryFileInput');
    var galleryInput = $('deliveryGalleryInput');
    if (cameraInput) cameraInput.disabled = isBusy;
    if (galleryInput) galleryInput.disabled = isBusy;
  }

  function useNativeCamera() {
    stopCamera();
    showLivePreview();
    var modal = $('deliveryPhotoModal');
    var frame = $('deliveryCameraFrame');
    var video = $('deliveryCameraVideo');
    if (modal) {
      modal.classList.add('native-camera-mode');
      modal.classList.remove('camera-unavailable');
    }
    if (frame) frame.classList.add('is-camera-idle');
    if (video) video.style.display = 'none';
    setStatus('', '');
  }

  function openNativeCameraPicker() {
    var input = $('deliveryFileInput');
    if (!input || input.disabled || state.uploading || state.preparing) {
      return false;
    }

    // iPhone and Android only allow the native camera picker to open while the
    // original tap is still active. Keep this call synchronous: do not move it
    // behind requestAnimationFrame, a promise, or a network request.
    try {
      input.click();
      return true;
    } catch (err) {
      return false;
    }
  }

  function beginPhotoCapture() {
    if (usesCompactCapture()) {
      useNativeCamera();
      return;
    }
    startCamera();
  }

  function hasPhotoType(type) {
    return state.photos.some(function (photo) {
      return String(photo.photo_type || '').toLowerCase() === String(type).toLowerCase();
    });
  }

  function updatePhotoWorkflowUi(applySuggestedType) {
    var typeSelect = $('deliveryPhotoType');
    var heading = $('deliveryCameraHeading');
    var guidance = $('deliveryPhotoGuidance');
    if (!typeSelect) return;

    if (applySuggestedType && !state.retakePhotoId) {
      if (state.photoMode === 'review') {
        typeSelect.value = hasPhotoType('Before') ? 'After' : 'Before';
      } else {
        typeSelect.value = state.photoReturnStep === 'complete' ? 'After' : 'Before';
      }
    }

    var type = typeSelect.value || 'Before';
    if (heading) {
      heading.textContent = state.retakePhotoId
        ? i18n('retake_photo_type', type.toLowerCase())
        : type === 'Before'
          ? i18n('take_arrival_photo')
          : type === 'After'
            ? i18n('take_departure_photo')
            : i18n('take_receipt_photo');
    }
    if (guidance) {
      guidance.textContent = type === 'Before'
        ? i18n('photo_guidance')
        : type === 'After'
          ? i18n('departure_guidance')
          : i18n('receipt_guidance');
    }
    updateNativeCameraControls();
  }

  function setPhotoMode(mode) {
    state.photoMode = mode === 'review' ? 'review' : 'capture';

    var isReview = state.photoMode === 'review';
    var cameraSection = $('deliveryCameraSection');
    var reviewActions = $('deliveryReviewActions');
    var gallery = $('deliveryPhotosGallerySection');
    var title = $('deliveryPhotoModalTitle');
    var eyebrow = $('deliveryModalEyebrow');
    var wizardSteps = $('deliveryWizardSteps');
    var wizardActions = $('deliveryWizardActions');
    var invoiceFooter = $('deliveryInvoiceFooterActions');

    if (cameraSection) cameraSection.hidden = isReview;
    if (reviewActions) reviewActions.hidden = !isReview;
    if (gallery) gallery.open = isReview;
    if (wizardSteps) wizardSteps.hidden = isReview;
    if (wizardActions) wizardActions.hidden = isReview;
    if (invoiceFooter) invoiceFooter.hidden = !isReview && state.currentStep !== 'invoice';
    if (title) title.textContent = isReview ? i18n('delivery_photos') : i18n('arrival_photo');
    if (eyebrow) eyebrow.textContent = isReview ? i18n('delivery_photos') : i18n('stop_workflow');
  }

  function goToStep(step) {
    if (STEPS.indexOf(step) === -1) step = 'photo';
    state.currentStep = step;
    state.invoiceReady = step === 'invoice';
    var isDeparturePhoto = step === 'photo' && state.photoReturnStep === 'complete';
    var visualStep = isDeparturePhoto ? 'invoice' : step;

    STEPS.forEach(function (s) {
      var panel = document.querySelector('[data-step-panel="' + s + '"]');
      var stepBtn = document.querySelector('.delivery-wizard-step[data-step="' + s + '"]');
      var isReview = state.photoMode === 'review';
      var showPanel = isReview
        ? (s === 'photo' || s === 'invoice')
        : (s === step);
      if (panel) {
        panel.hidden = !showPanel;
        panel.classList.toggle('is-active', showPanel);
      }
      if (stepBtn && !isReview) {
        stepBtn.classList.toggle('is-active', s === visualStep);
        stepBtn.classList.toggle('is-done', STEPS.indexOf(s) < STEPS.indexOf(visualStep));
        stepBtn.disabled = state.submitting || isDeparturePhoto;
      }
    });

    var modal = $('deliveryPhotoModal');
    if (modal) {
      modal.classList.toggle('delivery-step-invoice', step === 'invoice');
      modal.classList.toggle('delivery-step-delivery', step === 'delivery');
      modal.classList.toggle('delivery-step-photo', step === 'photo');
      modal.classList.toggle('delivery-step-departure', step === 'photo' && state.photoReturnStep === 'complete');
      modal.classList.toggle('delivery-mode-review', state.photoMode === 'review');
    }

    if (state.photoMode === 'review') {
      var invoiceFooter = $('deliveryInvoiceFooterActions');
      if (invoiceFooter) invoiceFooter.hidden = false;
      return;
    }

    var wizardActions = $('deliveryWizardActions');
    var invoiceFooter = $('deliveryInvoiceFooterActions');
    var backBtn = $('deliveryWizardBackBtn');
    var skipBtn = $('deliveryWizardSkipBtn');
    var primaryBtn = $('deliveryWizardPrimaryBtn');
    var title = $('deliveryPhotoModalTitle');
    var eyebrow = $('deliveryModalEyebrow');

    if (step === 'delivery') {
      if (title) title.textContent = i18n('confirm_delivery');
      if (eyebrow) eyebrow.textContent = i18n('confirm');
    } else if (step === 'invoice') {
      if (title) title.textContent = i18n('delivery_invoice');
      if (eyebrow) eyebrow.textContent = i18n('confirm');
    }

    updateNativeCameraControls();

    if (step === 'invoice') {
      if (wizardActions) wizardActions.hidden = true;
      if (invoiceFooter) invoiceFooter.hidden = false;
      stopCamera();
    } else {
      if (wizardActions) wizardActions.hidden = false;
      if (invoiceFooter) invoiceFooter.hidden = true;
      if (step === 'photo') {
        showLivePreview();
        if (title) title.textContent = isDeparturePhoto ? i18n('take_departure_photo') : i18n('arrival_photo');
        if (eyebrow) eyebrow.textContent = isDeparturePhoto ? i18n('leave') : i18n('stop_workflow');
        if (state.photoMode !== 'review') {
          beginPhotoCapture();
        }
      } else if (step === 'delivery') {
        stopCamera();
      }
    }

    if (backBtn) backBtn.hidden = step === 'photo';
    if (skipBtn) skipBtn.hidden = step !== 'photo';
    if (primaryBtn) {
      if (step === 'photo') {
        primaryBtn.hidden = true;
        primaryBtn.classList.remove('has-saved-photo');
      } else if (step === 'delivery') {
        primaryBtn.hidden = false;
        primaryBtn.textContent = i18n('save_and_leave_photo');
        primaryBtn.classList.remove('has-saved-photo');
      }
      primaryBtn.disabled = state.submitting;
    }
    var reviewBtn = $('deliveryWizardReviewBtn');
    if (reviewBtn) {
      reviewBtn.hidden = step !== 'delivery' || usesCompactCapture();
      reviewBtn.disabled = state.submitting;
    }

    if (step === 'delivery') {
      var piecesInput = $('deliveryPiecesInput');
      if (piecesInput && !state.submitting && !usesCompactCapture()) {
        setTimeout(function () {
          piecesInput.focus({ preventScroll: true });
        }, 80);
      }
    }

    if (step === 'invoice') {
      var scrollEl = $('deliveryStepInvoice');
      if (scrollEl) scrollEl.scrollTop = 0;
    }

    setStatus('');
  }

  function updateWizardPrimaryLabel() {
    var primaryBtn = $('deliveryWizardPrimaryBtn');
    if (!primaryBtn || state.currentStep !== 'photo') return;
    primaryBtn.classList.remove('has-saved-photo');
  }

  function showReviewCameraControls() {
    var cameraSection = $('deliveryCameraSection');
    var reviewActions = $('deliveryReviewActions');
    if (cameraSection) cameraSection.hidden = false;
    if (reviewActions) reviewActions.hidden = true;
    showLivePreview();
  }

  async function startCamera() {
    stopCamera();
    showLivePreview();
    updatePhotoWorkflowUi(false);
    var cameraModal = $('deliveryPhotoModal');
    if (cameraModal) cameraModal.classList.remove('native-camera-mode');

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      var unsupportedModal = $('deliveryPhotoModal');
      if (unsupportedModal) unsupportedModal.classList.add('camera-unavailable');
      setStatus(i18n('camera_api_unavailable'), 'error');
      return;
    }

    setStatus(i18n('starting_camera'), 'loading');

    var constraints = {
      audio: false,
      video: {
        facingMode: { ideal: isLikelyMobile() ? 'environment' : 'user' },
        width: { ideal: 1280 },
        height: { ideal: 720 }
      }
    };

    try {
      state.stream = await navigator.mediaDevices.getUserMedia(constraints);
      state.cameraActive = true;
      var captureButton = $('deliveryCaptureBtn');
      if (captureButton) captureButton.textContent = i18n('take_photo');
    } catch (err) {
      try {
        state.stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: true
        });
        state.cameraActive = true;
      } catch (err2) {
        var msg = i18n('could_not_open_camera');
        if (err2 && err2.name === 'NotAllowedError') {
          msg = i18n('camera_permission_denied');
        } else if (
          location.protocol === 'http:' &&
          location.hostname !== 'localhost' &&
          location.hostname !== '127.0.0.1'
        ) {
          msg = i18n('camera_https');
        }
        var unavailableModal = $('deliveryPhotoModal');
        if (unavailableModal) unavailableModal.classList.add('camera-unavailable');
        setStatus(msg, 'error');
        return;
      }
    }

    var video = $('deliveryCameraVideo');
    var frame = $('deliveryCameraFrame');
    var activeModal = $('deliveryPhotoModal');
    if (activeModal && activeModal.getAttribute('aria-hidden') === 'true') {
      stopCamera();
      return;
    }
    if (frame) frame.classList.remove('is-camera-idle');
    video.srcObject = state.stream;
    try {
      await video.play();
    } catch (playErr) {
      // Autoplay can fail; user can still tap capture after gesture.
    }
    setStatus(
      state.retakePhotoId
        ? i18n('camera_ready_replacement')
        : i18n('camera_ready'),
      'success'
    );
  }

  function captureFromVideo() {
    if (!state.stream) {
      startCamera();
      return;
    }
    var video = $('deliveryCameraVideo');
    var frame = $('deliveryCameraFrame');
    if (frame) frame.classList.remove('is-camera-idle');
    var canvas = $('deliveryCameraCanvas');
    if (!video || !canvas || !video.videoWidth) {
      setStatus(i18n('camera_not_ready'), 'error');
      return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob(function (blob) {
      if (!blob) {
        setStatus(i18n('capture_failed'), 'error');
        return;
      }
      state.blob = blob;
      showCapturedPreview(URL.createObjectURL(blob));
      setStatus(i18n('photo_captured_saving'), 'loading');
      uploadBlob(blob, 'arrival-photo.jpg');
    }, 'image/jpeg', 0.88);
  }

  function retakeCapture(options) {
    options = options || {};
    if (state.blob) {
      state.blob = null;
    }
    if (usesCompactCapture()) {
      useNativeCamera();
      if (options.message) {
        setStatus(options.message, options.kind || '');
      }
      return;
    }
    showLivePreview();
    if (state.stream) {
      var video = $('deliveryCameraVideo');
      if (video) {
        video.srcObject = state.stream;
        video.play().catch(function () {});
      }
      setStatus(i18n('camera_ready'), 'success');
    } else {
      startCamera();
    }
  }

  function getCoords(options) {
    options = options || {};
    return new Promise(function (resolve) {
      if (!navigator.geolocation) {
        resolve({ latitude: '', longitude: '', accuracy_m: '', status: 'unavailable', client_at: '' });
        return;
      }
      var settled = false;
      var timeoutMs = options.timeout || 1500;
      var finish = function (payload) {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve(payload);
      };
      var timer = setTimeout(function () {
        finish({ latitude: '', longitude: '', accuracy_m: '', status: 'timeout', client_at: '' });
      }, timeoutMs + 100);
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          finish({
            latitude: String(pos.coords.latitude),
            longitude: String(pos.coords.longitude),
            accuracy_m: pos.coords.accuracy != null ? String(pos.coords.accuracy) : '',
            status: 'captured',
            client_at: pos.timestamp ? new Date(pos.timestamp).toISOString() : ''
          });
        },
        function (err) {
          var status = 'error';
          if (err && err.code === 1) {
            status = 'denied';
          }
          finish({ latitude: '', longitude: '', accuracy_m: '', status: status, client_at: '' });
        },
        {
          enableHighAccuracy: options.enableHighAccuracy === true,
          timeout: timeoutMs,
          maximumAge: options.maximumAge || 120000
        }
      );
    });
  }

  function appendGpsParams(body, coords) {
    coords = coords || {};
    return body
      + '&gps_latitude=' + encodeURIComponent(coords.latitude || '')
      + '&gps_longitude=' + encodeURIComponent(coords.longitude || '')
      + '&gps_accuracy_m=' + encodeURIComponent(coords.accuracy_m || '')
      + '&gps_status=' + encodeURIComponent(coords.status || 'unavailable')
      + '&gps_client_at=' + encodeURIComponent(coords.client_at || '');
  }

  function findPhoto(photoId) {
    return state.photos.find(function (p) {
      return Number(p.id) === Number(photoId);
    });
  }

  function renderPhotos() {
    var grid = $('deliveryPhotosGrid');
    var countEl = $('deliveryPhotoCount');
    if (!grid) return;

    if (countEl) {
      countEl.textContent = state.photos.length
        ? '(' + state.photos.length + ')'
        : '';
    }
    var modal = $('deliveryPhotoModal');
    if (modal) {
      modal.classList.toggle('has-saved-photo', state.photos.length > 0);
    }
    updateWizardPrimaryLabel();

    if (!state.photos.length) {
      grid.innerHTML = '<div class="no-photos">' + escapeHtml(i18n('no_photos')) + '</div>';
      return;
    }

    grid.innerHTML = state.photos
      .map(function (photo) {
        var type = escapeHtml(photo.photo_type || i18n('photo'));
        var time = escapeHtml(formatTime(photo.created_at));
        var url = escapeHtml(photo.url || '');
        var fallback = escapeHtml(photo.fallback_url || '');
        var id = Number(photo.id);
        return (
          '<div class="existing-photo-item" data-photo-id="' +
          id +
          '">' +
          '<button type="button" class="delivery-photo-thumb-btn" data-action="view" data-photo-id="' +
          id +
          '" aria-label="' + escapeHtml(i18n('view_photo').replace(':type', type)) + ' ' +
          type +
          ' photo">' +
          '<img src="' +
          url +
          '" alt="' +
          type +
          '" loading="lazy" data-fallback="' +
          fallback +
          '">' +
          '</button>' +
          '<div class="photo-label">' +
          '<span class="photo-type-label">' +
          type +
          '</span>' +
          '<span class="photo-time">' +
          time +
          '</span>' +
          '</div>' +
          '<div class="delivery-photo-item-actions">' +
          '<button type="button" class="btn btn-outline btn-sm" data-action="view" data-photo-id="' +
          id +
          '">' + escapeHtml(i18n('view')) + '</button>' +
          '<button type="button" class="btn btn-outline btn-sm" data-action="retake" data-photo-id="' +
          id +
          '">' + escapeHtml(i18n('retake')) + '</button>' +
          '<button type="button" class="btn btn-danger btn-sm" data-action="remove" data-photo-id="' +
          id +
          '">' + escapeHtml(i18n('remove')) + '</button>' +
          '</div>' +
          '</div>'
        );
      })
      .join('');

    grid.querySelectorAll('img[data-fallback]').forEach(function (img) {
      img.addEventListener('error', function onErr() {
        var fb = img.getAttribute('data-fallback');
        if (fb && img.src !== fb) {
          img.src = fb;
        }
        img.removeEventListener('error', onErr);
      });
    });
  }

  async function loadPhotos() {
    var grid = $('deliveryPhotosGrid');
    var requestCustomerId = state.customerId;
    var requestDate = state.date;
    if (grid) {
            grid.innerHTML = '<div class="loading-photos">' + i18n('loading_photos') + '</div>';
    }

    try {
      var body =
        'action=list' +
        '&driver_id=' +
        encodeURIComponent(String(state.driverId)) +
        '&customer_id=' +
        encodeURIComponent(String(requestCustomerId)) +
        '&date=' +
        encodeURIComponent(requestDate);

      var response = await fetch('upload_driver_photo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
      });
      var data = await readJsonResponse(response, i18n('could_not_load_photos'));
      if (!data || !data.success) {
        throw new Error((data && data.error) || i18n('could_not_load_photos'));
      }
      if (requestCustomerId !== state.customerId || requestDate !== state.date) return;
      state.photos = data.photos || [];
      renderPhotos();
      updatePhotoWorkflowUi(true);
      if (state.photos.length > 0) {
        maybeEnableGpsTracking();
      }
    } catch (err) {
      if (requestCustomerId !== state.customerId || requestDate !== state.date) return;
      if (grid) {
        grid.innerHTML =
          '<div class="error-photos">' +
          escapeHtml(err.message || i18n('could_not_load_photos')) +
          '</div>';
      }
    }
  }

  async function deletePhoto(photoId, options) {
    options = options || {};
    var body =
      'action=delete' +
      '&driver_id=' +
      encodeURIComponent(String(state.driverId)) +
      '&photo_id=' +
      encodeURIComponent(String(photoId));

    await refreshRouteSession();
    var response = await fetch('upload_driver_photo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    });
      var data = await readJsonResponse(response, i18n('could_not_remove_photo'));
    if (!data || !data.success) {
      throw new Error((data && data.error) || i18n('could_not_remove_photo'));
    }

    state.photos = state.photos.filter(function (p) {
      return Number(p.id) !== Number(photoId);
    });
    renderPhotos();
    updatePhotoWorkflowUi(true);

    if (!options.silent) {
      setStatus(i18n('photo_removed'), 'success');
    }
  }

  function openViewer(photoId) {
    var photo = findPhoto(photoId);
    if (!photo) return;
    state.viewingPhotoId = Number(photoId);

    var viewer = $('deliveryPhotoViewer');
    var img = $('deliveryViewerImage');
    var title = $('deliveryViewerTitle');
    var meta = $('deliveryViewerMeta');
    if (img) {
      img.src = photo.url;
      img.onerror = function () {
        if (photo.fallback_url && img.src !== photo.fallback_url) {
          img.src = photo.fallback_url;
        }
      };
    }
    if (title) title.textContent = i18n('view_photo').replace(':type', photo.photo_type || i18n('photo'));
    if (meta) {
      var lines = [];
      if (photo.created_at) lines.push(formatTime(photo.created_at));
      if (photo.notes) lines.push(photo.notes);
      meta.textContent = lines.join('\n');
    }
    if (viewer) {
      viewer.style.display = 'flex';
      viewer.setAttribute('aria-hidden', 'false');
    }
  }

  function closeViewer() {
    state.viewingPhotoId = null;
    var viewer = $('deliveryPhotoViewer');
    var img = $('deliveryViewerImage');
    if (viewer) {
      viewer.style.display = 'none';
      viewer.setAttribute('aria-hidden', 'true');
    }
    if (img) img.removeAttribute('src');
  }

  async function removePhotoFlow(photoId) {
    var photo = findPhoto(photoId);
    var label = photo ? photo.photo_type || 'this' : 'this';
    if (!window.confirm(i18n('confirm_remove_photo').replace(':label', label))) {
      return;
    }
    try {
      await deletePhoto(photoId);
      if (state.viewingPhotoId === Number(photoId)) {
        closeViewer();
      }
    } catch (err) {
      setStatus(err.message || i18n('could_not_remove_photo'), 'error');
    }
  }

  function retakePhotoFlow(photoId) {
    var photo = findPhoto(photoId);
    var label = photo ? photo.photo_type || 'this' : 'this';
    if (
      !window.confirm(
        i18n('confirm_retake_photo').replace(':label', label)
      )
    ) {
      return;
    }

    closeViewer();

    // Keep the saved proof until its replacement uploads successfully. A camera
    // denial, interrupted picker, or weak connection must not destroy evidence.
    state.retakePhotoId = Number(photoId);
    showReviewCameraControls();
    if (photo && photo.photo_type && $('deliveryPhotoType')) {
      $('deliveryPhotoType').value = photo.photo_type;
    }
    updatePhotoWorkflowUi(false);
    var section = $('deliveryCameraFrame');
    if (section && section.scrollIntoView && !usesCompactCapture()) {
      section.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    if (usesCompactCapture()) {
      useNativeCamera();
      openNativeCameraPicker();
    } else {
      startCamera();
    }
  }

  function maybeEnableGpsTracking() {
    if (typeof window.bakeryEnableGpsTracking !== 'function' || !state.driverId) {
      return;
    }
    window.bakeryEnableGpsTracking(state.driverId, state.date);
  }

  function loadPhotoImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var image = new Image();
      image.onload = function () {
        URL.revokeObjectURL(url);
        resolve(image);
      };
      image.onerror = function () {
        URL.revokeObjectURL(url);
        reject(new Error(i18n('choose_image')));
      };
      image.src = url;
    });
  }

  function looksLikeImageFile(file) {
    var type = String((file && file.type) || '').toLowerCase();
    if (type.indexOf('image/') === 0) return true;
    // Some iPhone/Android picker versions omit File.type even for a real
    // camera image. The server still performs authoritative MIME validation.
    return /\.(jpe?g|png|webp|gif|heic|heif)$/i.test(String((file && file.name) || ''));
  }

  function canvasToJpeg(canvas, quality) {
    return new Promise(function (resolve, reject) {
      canvas.toBlob(function (blob) {
        if (blob) resolve(blob);
        else reject(new Error(i18n('capture_failed')));
      }, 'image/jpeg', quality);
    });
  }

  async function preparePhotoForUpload(file) {
    var image;
    try {
      image = await loadPhotoImage(file);
    } catch (err) {
      // Some iPhone HEIC versions can upload a valid image that the browser
      // cannot decode into canvas. Let the server's MIME validation decide.
      return { blob: file, filename: file.name || 'delivery-photo' };
    }

    var width = image.naturalWidth || image.width || 0;
    var height = image.naturalHeight || image.height || 0;
    var maxDimension = 1920;
    var largest = Math.max(width, height);
    var isSmallJpeg = /^image\/jpe?g$/i.test(file.type || '')
      && file.size <= 1500 * 1024
      && largest > 0
      && largest <= maxDimension;
    if (isSmallJpeg) {
      return { blob: file, filename: file.name || 'delivery-photo.jpg' };
    }

    if (!width || !height) {
      return { blob: file, filename: file.name || 'delivery-photo' };
    }
    var scale = largest > maxDimension ? maxDimension / largest : 1;
    var canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(width * scale));
    canvas.height = Math.max(1, Math.round(height * scale));
    var context = canvas.getContext('2d', { alpha: false });
    if (!context) {
      return { blob: file, filename: file.name || 'delivery-photo' };
    }
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(image, 0, 0, canvas.width, canvas.height);
    var blob = await canvasToJpeg(canvas, 0.82);
    var baseName = (file.name || 'delivery-photo').replace(/\.[^.]+$/, '');
    return { blob: blob, filename: baseName + '.jpg' };
  }

  async function uploadBlob(blob, filename) {
    if (state.uploading) return;
    if (!blob) {
      setStatus(i18n('take_or_choose_photo'), 'error');
      return;
    }

    state.uploading = true;
    setPhotoControlsBusy(true);
    var uploadModal = $('deliveryPhotoModal');
    if (uploadModal) uploadModal.classList.add('is-photo-uploading');
    setStatus(i18n('uploading_photo'), 'loading');
    var progress = $('deliveryPhotoProgress');
    var fill = $('deliveryPhotoProgressFill');
    if (progress) progress.classList.add('is-active');
    if (fill) fill.style.width = '35%';

    try {
      await refreshRouteSession();
      var formData = new FormData();
      formData.append('action', 'upload');
      formData.append('photo', blob, filename || 'capture.jpg');
      formData.append('driver_id', String(state.driverId));
      formData.append('customer_id', String(state.customerId));
      formData.append('daily_order_id', String(state.dailyOrderId));
      formData.append('date', state.date);
      var photoType = ($('deliveryPhotoType') && $('deliveryPhotoType').value) || 'Before';
      formData.append('photo_type', photoType);
      formData.append('notes', ($('deliveryPhotoNotes') && $('deliveryPhotoNotes').value) || '');
      // Do not hold a proof photo behind an iPhone location prompt. Route GPS
      // tracking and the final delivery event capture location independently.
      formData.append('latitude', '');
      formData.append('longitude', '');

      if (fill) fill.style.width = '70%';

      var response = await fetch('upload_driver_photo.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      });
      var data = await readJsonResponse(response, i18n('photo_upload_failed'));
      if (fill) fill.style.width = '100%';

      if (!data || !data.success) {
        throw new Error((data && data.error) || i18n('upload_failed'));
      }

      var replacedPhotoId = state.retakePhotoId;
      state.retakePhotoId = null;
      if (replacedPhotoId) {
        try {
          await deletePhoto(replacedPhotoId, { silent: true });
        } catch (deleteErr) {
          // The new proof is safely stored. Leaving the older proof is safer
          // than treating a cleanup failure as a failed upload.
        }
      }
      maybeEnableGpsTracking();

      if ($('deliveryPhotoNotes')) $('deliveryPhotoNotes').value = '';
      stopCamera();
      await loadPhotos();
      if (state.photoMode === 'review') {
        state.photoReturnStep = null;
        setStatus(
          photoType === 'Before' ? i18n('arrival_saved') : i18n('departure_saved'),
          'success'
        );
        goToStep('invoice');
      } else if (state.photoReturnStep === 'complete') {
        state.photoReturnStep = null;
        setStatus(i18n('departure_saved'), 'success');
        finishDeliveryUi(state.completionMessage || i18n('delivery_confirmed'));
      } else if (photoType === 'Before') {
        // Arrival proof is enough to move directly to quantity confirmation.
        state.photoReturnStep = null;
        setStatus(i18n('arrival_saved'), 'success');
        goToStep('delivery');
      } else {
        // Extra photos outside the guided flow return to delivery confirmation.
        setStatus(i18n('departure_saved'), 'success');
        goToStep('delivery');
      }
    } catch (err) {
      retakeCapture({
        message: err.message || i18n('upload_failed'),
        kind: 'error'
      });
    } finally {
      state.uploading = false;
      setPhotoControlsBusy(false);
      if (uploadModal) uploadModal.classList.remove('is-photo-uploading');
      if (progress) {
        setTimeout(function () {
          progress.classList.remove('is-active');
          if (fill) fill.style.width = '0%';
        }, 400);
      }
    }
  }

  function getEffectivePricePerPiece() {
    if (state.pricingMissing) {
      var override = state.pricePerPieceOverride;
      if (Number.isFinite(override) && override > 0) {
        return override;
      }
      return 0;
    }
    return state.orderedPieces > 0 ? state.orderTotal / state.orderedPieces : 0;
  }

  function readPricePerPieceInput() {
    var confirmInput = $('deliveryPricePerPieceInput');
    var invoiceInput = $('deliveryInvoicePriceInput');
    var source = (invoiceInput && !invoiceInput.hidden) ? invoiceInput : confirmInput;
    if (!source) return null;
    var value = parseFloat(source.value);
    return Number.isFinite(value) && value > 0 ? value : null;
  }

  function syncPricePerPieceInputs(value) {
    var formatted = value != null && Number.isFinite(value) && value > 0 ? value.toFixed(2) : '';
    var confirmInput = $('deliveryPricePerPieceInput');
    var invoiceInput = $('deliveryInvoicePriceInput');
    if (confirmInput && confirmInput.value !== formatted) confirmInput.value = formatted;
    if (invoiceInput && invoiceInput.value !== formatted) invoiceInput.value = formatted;
    state.pricePerPieceOverride = formatted ? parseFloat(formatted) : null;
  }

  function setInvoiceText(id, text) {
    var el = $(id);
    if (el) el.textContent = text;
  }

  function canEditPricing() {
    return state.pricingMissing && !(state.photoMode === 'review' && state.isSaved);
  }

  function updatePricingUi() {
    var pricingRow = $('deliveryPricingRow');
    var confirmInput = $('deliveryPricePerPieceInput');
    var invoicePrice = $('deliveryInvoicePrice');
    var invoiceInput = $('deliveryInvoicePriceInput');
    var editable = canEditPricing();
    if (pricingRow) pricingRow.hidden = !editable;
    if (confirmInput) confirmInput.disabled = state.submitting || (state.photoMode === 'review' && state.isSaved && state.invoiceReady);
    if (invoicePrice) invoicePrice.hidden = editable;
    if (invoiceInput) invoiceInput.hidden = !editable;
  }

  function setSubmitting(isSubmitting) {
    state.submitting = isSubmitting;
    var primaryBtn = $('deliveryWizardPrimaryBtn');
    var confirmBtn = $('deliveryInvoiceConfirmBtn');
    var backBtn = $('deliveryInvoiceBackBtn');
    var wizardBackBtn = $('deliveryWizardBackBtn');
    var skipBtn = $('deliveryWizardSkipBtn');
    var closeBtn = $('deliveryPhotoModalClose');
    if (primaryBtn) {
      primaryBtn.disabled = isSubmitting;
      primaryBtn.setAttribute('aria-busy', isSubmitting ? 'true' : 'false');
      if (isSubmitting && state.currentStep === 'delivery') {
        primaryBtn.textContent = i18n('saving');
      } else if (state.currentStep === 'delivery') {
        primaryBtn.textContent = i18n('save_and_leave_photo');
      }
    }
    var reviewBtn = $('deliveryWizardReviewBtn');
    if (reviewBtn) reviewBtn.disabled = isSubmitting;
    if (confirmBtn) {
      confirmBtn.disabled = isSubmitting;
      confirmBtn.setAttribute('aria-busy', isSubmitting ? 'true' : 'false');
      confirmBtn.textContent = isSubmitting ? i18n('saving') : i18n('confirm_save');
    }
    if (backBtn) backBtn.disabled = isSubmitting;
    if (wizardBackBtn) wizardBackBtn.disabled = isSubmitting;
    if (skipBtn) skipBtn.disabled = isSubmitting;
    if (closeBtn) closeBtn.disabled = isSubmitting;
    document.querySelectorAll('.delivery-wizard-step').forEach(function (btn) {
      btn.disabled = isSubmitting;
    });
    var steppers = document.querySelectorAll('.quantity-stepper-btn');
    steppers.forEach(function (btn) {
      btn.disabled = isSubmitting;
    });
    var piecesInput = $('deliveryPiecesInput');
    var creditsInput = $('deliveryCreditsInput');
    if (piecesInput) piecesInput.disabled = isSubmitting || (state.photoMode === 'review' && state.isSaved && state.invoiceReady);
    if (creditsInput) creditsInput.disabled = isSubmitting || (state.photoMode === 'review' && state.isSaved && state.invoiceReady);
    var priceInput = $('deliveryPricePerPieceInput');
    var invoicePriceInput = $('deliveryInvoicePriceInput');
    if (priceInput) priceInput.disabled = isSubmitting || (state.photoMode === 'review' && state.isSaved && state.invoiceReady);
    if (invoicePriceInput) invoicePriceInput.disabled = isSubmitting || (state.photoMode === 'review' && state.isSaved && state.invoiceReady);
    updatePricingUi();
  }

  function updateVarianceUi() {
    var alertEl = $('deliveryVarianceAlert');
    var textEl = $('deliveryVarianceText');
    var orderedRef = $('deliveryOrderedRef');
    var piecesInput = $('deliveryPiecesInput');
    var creditsInput = $('deliveryCreditsInput');
    if (!alertEl || !piecesInput) return;

    var pieces = Math.max(0, parseInt(piecesInput.value, 10) || 0);
    var credits = Math.max(0, parseInt(creditsInput && creditsInput.value, 10) || 0);
    var ordered = state.orderedPieces;
    var diff = pieces - ordered;

    if (orderedRef) {
      orderedRef.textContent = ordered > 0
        ? i18n('ordered_recording').replace(':ordered', ordered).replace(':pieces', pieces).replace(':credits', credits > 0 ? i18n('credits_suffix').replace(':credits', credits) : '')
        : '';
    }

    if (ordered > 0 && diff !== 0) {
      alertEl.hidden = false;
      if (textEl) {
        textEl.textContent = diff > 0
          ? i18n('delivering_more').replace(':count', diff).replace(':plural', diff === 1 ? '' : 's')
          : i18n('delivering_fewer').replace(':count', Math.abs(diff)).replace(':plural', Math.abs(diff) === 1 ? '' : 's');
      }
    } else {
      alertEl.hidden = true;
      if (textEl) textEl.textContent = '';
      var ack = $('deliveryVarianceAck');
      if (ack) ack.checked = false;
    }
  }

  function updatePaymentCollectionUi() {
    var isCod = state.paymentCollection === 'cod';
    var codRow = $('deliveryCodRow');
    var cashInput = $('deliveryCashCollectedInput');
    var hint = $('deliveryConfirmationHint');
    var cashRow = $('deliveryInvoiceCashRow');
    if (codRow) codRow.hidden = !isCod;
    if (cashRow) cashRow.hidden = !isCod;
    if (hint) {
      hint.textContent = isCod
        ? i18n('enter_cod_details')
        : i18n('enter_delivery_details');
    }
    if (isCod && cashInput && !cashInput.dataset.userEdited) {
      syncCashCollectedToTotal();
    }
  }

  function syncCashCollectedToTotal() {
    var cashInput = $('deliveryCashCollectedInput');
    if (!cashInput || state.paymentCollection !== 'cod') return;
    var pieces = Math.max(0, parseInt($('deliveryPiecesInput') && $('deliveryPiecesInput').value, 10) || 0);
    var credits = Math.max(0, parseInt($('deliveryCreditsInput') && $('deliveryCreditsInput').value, 10) || 0);
    var billable = Math.max(0, pieces - credits);
    var perPiece = getEffectivePricePerPiece();
    var total = state.photoMode === 'review' && state.isSaved ? state.savedTotal : billable * perPiece;
    if (!cashInput.dataset.userEdited) {
      cashInput.value = total.toFixed(2);
    }
  }

  function creditAllocationPreview(credits) {
    var items = (state.invoiceItems || []).slice().sort(function (a, b) {
      return (Number(a.id) || 0) - (Number(b.id) || 0);
    });
    var mixed = items.length > 1;
    var remaining = Math.max(0, credits);
    var parts = [];
    items.forEach(function (item) {
      var delivered = item.delivered_quantity != null && item.delivered_quantity !== ''
        ? Math.max(0, parseInt(item.delivered_quantity, 10) || 0)
        : Math.max(0, parseInt(item.quantity, 10) || 0);
      var take = Math.min(remaining, delivered);
      if (take > 0) {
        parts.push((item.product_name || i18n('product')) + ' (' + take + ')');
        remaining -= take;
      }
    });
    return {
      mixed: mixed,
      detail: parts.join(', ')
    };
  }

  function updateCreditAllocationUi(credits) {
    var allocEl = $('deliveryCreditAllocNote');
    if (!allocEl) return;
    var items = state.invoiceItems || [];
    if (credits <= 0 || items.length <= 1) {
      allocEl.hidden = true;
      allocEl.textContent = '';
      return;
    }
    var preview = creditAllocationPreview(credits);
    var text = i18n('credits_allocation_rule');
    if (preview.detail) {
      text += ' ' + i18n('credits_allocation_preview').replace(':detail', preview.detail);
    }
    allocEl.textContent = text;
    allocEl.hidden = false;
  }

  function updateDeliveryPreview() {
    var piecesInput = $('deliveryPiecesInput');
    var creditsInput = $('deliveryCreditsInput');
    var totalEl = $('deliveryConfirmationTotal');
    var breakdownEl = $('deliveryConfirmationBreakdown');
    var pieces = Math.max(0, parseInt(piecesInput && piecesInput.value, 10) || 0);
    var credits = Math.max(0, parseInt(creditsInput && creditsInput.value, 10) || 0);
    var billable = Math.max(0, pieces - credits);
    var perPiece = getEffectivePricePerPiece();
    var total = state.photoMode === 'review' && state.isSaved ? state.savedTotal : billable * perPiece;
    if (totalEl) totalEl.textContent = '$' + total.toFixed(2);
    var breakdown = i18n('billable_pieces').replace(':count', billable) + ' · ' + (perPiece ? i18n('average_per_piece').replace(':price', '$' + perPiece.toFixed(2)) + ' · ' + state.pricingLabel : (state.pricingMissing ? i18n('enter_price_below') : i18n('no_priced_items')));
    if (state.paymentCollection === 'cod') {
      breakdown += ' · ' + i18n('collect_cash_cod');
    } else {
      breakdown += ' · ' + i18n('signature_receipt');
    }
    if (breakdownEl) breakdownEl.textContent = breakdown;
    updateCreditAllocationUi(credits);
    syncCashCollectedToTotal();
    updateVarianceUi();
    updatePricingUi();
  }

  function formatInvoiceDate(value) {
    if (!value) return '';
    var parts = String(value).split('-');
    if (parts.length !== 3) return value;
    var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    return date.toLocaleDateString(window.__BAKERY_LOCALE__ === 'es' ? 'es-ES' : 'en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function setInvoiceStepOpen(isOpen) {
    if (isOpen) {
      goToStep('invoice');
    }
  }

  function validateDeliveryInputs() {
    var piecesInput = $('deliveryPiecesInput');
    var creditsInput = $('deliveryCreditsInput');
    var pieces = Math.max(0, parseInt(piecesInput && piecesInput.value, 10) || 0);
    var credits = Math.max(0, parseInt(creditsInput && creditsInput.value, 10) || 0);
    if (credits > pieces) {
      setStatus(i18n('credits_exceed_pieces'), 'error');
      return null;
    }
    if (canEditPricing()) {
      var enteredPrice = readPricePerPieceInput();
      if (enteredPrice) syncPricePerPieceInputs(enteredPrice);
    }
    return { pieces: pieces, credits: credits };
  }

  function showVarianceConfirm(pieces, onConfirm) {
    var panel = $('deliveryVarianceConfirm');
    var textEl = $('deliveryVarianceConfirmText');
    if (!panel) {
      onConfirm();
      return;
    }
    var diff = pieces - state.orderedPieces;
    if (textEl) {
      textEl.textContent = diff > 0
        ? i18n('variance_more_confirm').replace(':count', diff).replace(':plural', diff === 1 ? '' : 's').replace(':ordered', state.orderedPieces)
        : i18n('variance_fewer_confirm').replace(':count', Math.abs(diff)).replace(':plural', Math.abs(diff) === 1 ? '' : 's').replace(':ordered', state.orderedPieces);
    }
    state.pendingVarianceConfirm = onConfirm;
    panel.hidden = false;
  }

  function hideVarianceConfirm() {
    var panel = $('deliveryVarianceConfirm');
    if (panel) panel.hidden = true;
    state.pendingVarianceConfirm = null;
  }

  function promptDeparturePhoto() {
    if ($('deliveryPhotoType')) {
      $('deliveryPhotoType').value = 'After';
    }
    updatePhotoWorkflowUi(false);
    setStatus(i18n('take_departure_photo'), 'success');
    goToStep('photo');
  }

  function populateInvoicePreview() {
    if (state.submitting) return;
    if (!state.summaryReady) {
      setStatus(i18n('still_loading_pricing'), 'loading');
      loadDeliverySummary();
      return;
    }
    var validated = validateDeliveryInputs();
    if (!validated) return;
    var pieces = validated.pieces;
    var credits = validated.credits;
    var billable = Math.max(0, pieces - credits);
    var perPiece = getEffectivePricePerPiece();
    var total = state.photoMode === 'review' && state.isSaved ? state.savedTotal : billable * perPiece;
    setInvoiceText('deliveryInvoiceDate', formatInvoiceDate(state.date));
    setInvoiceText('deliveryInvoiceCustomer', state.customerName || '—');
    setInvoiceText('deliveryInvoiceAddress', state.address || i18n('no_address_invoice'));
    setInvoiceText('deliveryInvoiceDriver', state.driverName || '—');
    setInvoiceText('deliveryInvoiceOrderedPieces', String(state.orderedPieces));
    setInvoiceText('deliveryInvoicePieces', String(pieces));
    setInvoiceText('deliveryInvoiceCredits', String(credits));
    updatePricingUi();
    if (canEditPricing()) {
      var invoicePriceInput = $('deliveryInvoicePriceInput');
      if (invoicePriceInput && state.pricePerPieceOverride != null) {
        invoicePriceInput.value = state.pricePerPieceOverride.toFixed(2);
      }
      setInvoiceText('deliveryInvoicePrice', '—');
    } else {
      setInvoiceText('deliveryInvoicePrice', perPiece > 0 ? '$' + perPiece.toFixed(2) : '—');
    }
    setInvoiceText('deliveryInvoiceTotal', '$' + total.toFixed(2));
    var cashRow = $('deliveryInvoiceCashRow');
    var cashEl = $('deliveryInvoiceCash');
    if (state.paymentCollection === 'cod') {
      if (cashRow) cashRow.hidden = false;
      var cashInput = $('deliveryCashCollectedInput');
      var cashAmount = cashInput ? parseFloat(cashInput.value) : total;
      if (!Number.isFinite(cashAmount)) cashAmount = total;
      if (cashEl) cashEl.textContent = '$' + cashAmount.toFixed(2);
    } else if (cashRow) {
      cashRow.hidden = true;
    }
    var pricingNote = state.pricingMissing && perPiece > 0 ? i18n('driver_entered_price') : state.pricingLabel;
    if (state.pricingMissing && perPiece <= 0 && canEditPricing()) {
      pricingNote = i18n('enter_price_below');
    }
    setInvoiceText('deliveryInvoicePricingNote', pricingNote + ' · ' + i18n('billable_pieces').replace(':count', billable));
    var noteEl = $('deliveryInvoicePricingNote');
    if (noteEl && state.orderedPieces > 0 && pieces !== state.orderedPieces) {
      noteEl.textContent += ' · ' + i18n('adjusted_from_ordered').replace(':count', state.orderedPieces);
    }
    if (noteEl && credits > 0) {
      noteEl.textContent += ' · ' + i18n('credits_return_stock');
    }
    var itemsEl = $('deliveryInvoiceItems');
    if (itemsEl) {
      var displayItems = state.invoiceItems;
      if (state.pricingMissing && perPiece > 0) {
        displayItems = state.invoiceItems.map(function (item) {
          var qty = Number(item.quantity || 0);
          return {
            product_name: item.product_name,
            quantity: qty,
            unit_price: perPiece,
            line_total: qty * perPiece
          };
        });
      }
      itemsEl.innerHTML = displayItems.length
        ? '<div class="delivery-invoice-items-heading"><span>' + escapeHtml(i18n('order_pricing_basis')) + '</span><span>' + escapeHtml(i18n('amount')) + '</span></div>' + displayItems.map(function (item) {
            return '<div class="delivery-invoice-item"><span><strong>' + escapeHtml(item.product_name || i18n('product')) + '</strong><small>' + escapeHtml(String(item.quantity || 0)) + ' × $' + Number(item.unit_price || 0).toFixed(2) + '</small></span><strong>$' + Number(item.line_total || 0).toFixed(2) + '</strong></div>';
          }).join('')
        : '<p class="delivery-invoice-empty">' + escapeHtml(i18n('no_item_pricing')) + '</p>';
    }
    state.invoiceReady = true;
    goToStep('invoice');
  }

  function returnToDeliveryEdit() {
    state.invoiceReady = false;
    goToStep('delivery');
    updateDeliveryPreview();
  }

  async function loadDeliverySummary() {
    var piecesInput = $('deliveryPiecesInput');
    var creditsInput = $('deliveryCreditsInput');
    if (!piecesInput || !creditsInput) return;
    var requestOrderId = state.dailyOrderId;
    piecesInput.disabled = true;
    creditsInput.disabled = true;
    setSubmitting(false);
    try {
      var response = await fetch('complete_delivery.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_delivery_summary&daily_order_id=' + encodeURIComponent(String(requestOrderId))
      });
      var data = await readJsonResponse(response, i18n('could_not_load_total'));
      if (!data || !data.success) throw new Error((data && data.error) || i18n('could_not_load_total'));
      if (requestOrderId !== state.dailyOrderId) return;
      state.orderedPieces = Number(data.ordered_pieces) || 0;
      state.orderTotal = Number(data.order_total) || 0;
      state.pricingLabel = data.pricing_label || i18n('order_pricing');
      state.pricingMissing = data.pricing_missing === true;
      state.pricePerPieceOverride = null;
      if (data.customer_name) {
        state.customerName = data.customer_name;
        var headerCustomer = $('deliveryModalCustomer');
        if (headerCustomer) headerCustomer.textContent = state.customerName;
      }
      if (data.customer_address) state.address = data.customer_address;
      if (data.order_date) state.date = data.order_date;
      if (data.driver_name) state.driverName = data.driver_name;
      state.invoiceItems = Array.isArray(data.items) ? data.items : [];
      state.savedTotal = Number(data.saved_total) || 0;
      state.isSaved = data.is_saved === true;
      state.paymentCollection = data.payment_collection === 'cod' ? 'cod' : 'signature';
      state.amountCollected = data.amount_collected != null ? Number(data.amount_collected) : null;
      state.summaryReady = true;
      piecesInput.value = Number(data.delivered_pieces) || 0;
      creditsInput.value = Number(data.credits_taken_back) || 0;
      var cashInput = $('deliveryCashCollectedInput');
      if (cashInput) {
        cashInput.dataset.userEdited = state.amountCollected != null ? '1' : '';
        cashInput.value = state.amountCollected != null
          ? state.amountCollected.toFixed(2)
          : '';
      }
      updatePaymentCollectionUi();
      updateDeliveryPreview();
      updatePricingUi();
    } catch (err) {
      if (requestOrderId !== state.dailyOrderId) return;
      state.summaryReady = false;
      setStatus(err.message || i18n('could_not_load_total'), 'error');
    } finally {
      if (requestOrderId !== state.dailyOrderId) return;
      if (!state.submitting) {
        piecesInput.disabled = false;
        creditsInput.disabled = false;
      }
      var primaryBtn = $('deliveryWizardPrimaryBtn');
      if (primaryBtn && state.currentStep === 'delivery') {
        primaryBtn.disabled = state.photoMode === 'review' || state.submitting;
      }
      if (state.photoMode === 'review' && state.summaryReady) {
        populateInvoicePreview();
        $('deliveryInvoiceActions').hidden = true;
        $('deliveryInvoiceEditSavedBtn').hidden = false;
        var wizardSteps = $('deliveryWizardSteps');
        var wizardActions = $('deliveryWizardActions');
        if (wizardSteps) wizardSteps.hidden = true;
        if (wizardActions) wizardActions.hidden = true;
        var invoiceFooter = $('deliveryInvoiceFooterActions');
        if (invoiceFooter) invoiceFooter.hidden = false;
        var photoPanel = $('deliveryStepPhoto');
        if (photoPanel) {
          photoPanel.hidden = false;
          photoPanel.classList.add('is-active');
        }
      }
    }
  }

  async function confirmDelivery(skipVarianceCheck) {
    if (state.submitting) return;
    var pieces = parseInt($('deliveryPiecesInput') && $('deliveryPiecesInput').value, 10);
    var credits = parseInt($('deliveryCreditsInput') && $('deliveryCreditsInput').value, 10);
    if (!Number.isInteger(pieces) || pieces < 0 || !Number.isInteger(credits) || credits < 0) {
      setStatus(i18n('whole_numbers_required'), 'error');
      return;
    }
    if (credits > pieces) {
      setStatus(i18n('credits_exceed_pieces'), 'error');
      return;
    }

    if (!skipVarianceCheck && state.orderedPieces > 0 && pieces !== state.orderedPieces) {
      var ack = $('deliveryVarianceAck');
      if (!ack || !ack.checked) {
        var alertEl = $('deliveryVarianceAlert');
        if (alertEl) alertEl.hidden = false;
        setStatus(i18n('variance_ack_needed'), 'error');
        if (ack) ack.focus();
        return;
      }
    }
    hideVarianceConfirm();

    if (state.paymentCollection === 'cod') {
      var cashInput = $('deliveryCashCollectedInput');
      var cashAmount = cashInput ? parseFloat(cashInput.value) : NaN;
      if (!Number.isFinite(cashAmount) || cashAmount < 0) {
        setStatus(i18n('cash_required'), 'error');
        return;
      }
    }

    if (state.pricingMissing && canEditPricing()) {
      var pricePerPiece = readPricePerPieceInput();
      if (!pricePerPiece) {
        setStatus(i18n('price_required'), 'error');
        return;
      }
      syncPricePerPieceInputs(pricePerPiece);
    }

    setSubmitting(true);
    setStatus(i18n('saving_delivery'), 'loading');

    try {
      var coords = await getCoords({ timeout: 1500, maximumAge: 120000 });
      await refreshRouteSession();
      var body = 'action=confirm_delivery&daily_order_id=' + encodeURIComponent(String(state.dailyOrderId)) +
        '&delivered_pieces=' + encodeURIComponent(String(pieces)) +
        '&credits_taken_back=' + encodeURIComponent(String(credits));
      if (state.pricingMissing && canEditPricing() && state.pricePerPieceOverride != null) {
        body += '&price_per_piece=' + encodeURIComponent(String(state.pricePerPieceOverride));
      }
      if (state.paymentCollection === 'cod') {
        body += '&amount_collected=' + encodeURIComponent(String(parseFloat($('deliveryCashCollectedInput').value)));
      }
      body = appendGpsParams(body, coords);
      var response = await fetch('complete_delivery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
      });
      var data = await readJsonResponse(response, i18n('could_not_complete_delivery'));
      if (!data || !data.success) {
        throw new Error((data && data.error) || i18n('could_not_complete_delivery'));
      }
      var successMessage = (data.message || i18n('delivery_confirmed')) + ' ' + i18n('total_prefix').replace(':total', Number(data.total || 0).toFixed(2));
      if (data.payment_collection === 'cod' && data.amount_collected != null) {
        successMessage += ' · ' + i18n('cash_collected_prefix').replace(':amount', Number(data.amount_collected).toFixed(2));
      }
      state.confirmationSaved = true;
      state.completionMessage = successMessage;
      state.savedTotal = Number(data.total || 0);
      state.isSaved = true;
      state.photoReturnStep = 'complete';
      setSubmitting(false);
      promptDeparturePhoto();
    } catch (err) {
      if (err && err.isSessionError) {
        setStatus(err.message, 'error');
        setSubmitting(false);
        return;
      }
      setStatus((err.message || i18n('could_not_complete_delivery')) + ' — ' + i18n('nothing_saved_retry'), 'error');
      setSubmitting(false);
    }
  }

  function finishDeliveryUi(message) {
    if (state.stopFinished) return;
    state.stopFinished = true;
    state.confirmationSaved = false;
    if (window.DriverRoute && typeof window.DriverRoute.afterDeliveryComplete === 'function') {
      window.DriverRoute.afterDeliveryComplete(state.dailyOrderId, message);
    } else {
      var stop = document.querySelector('.stop-item[data-daily-order-id="' + state.dailyOrderId + '"]');
      if (stop) {
        stop.setAttribute('data-status', 'delivered');
        var badge = stop.querySelector('.status-badge');
        if (badge) { badge.textContent = i18n('status_delivered'); badge.className = 'status-badge status-badge--delivered'; }
      }
    }
    state.submitting = false;
    setTimeout(function () { closeModal({ focusRoute: true, deliveryFinished: true }); }, 650);
  }

  function openModal(opts) {
    state.driverId = opts.driverId;
    state.driverName = opts.driverName || '';
    state.customerId = opts.customerId;
    state.dailyOrderId = opts.dailyOrderId;
    state.customerName = opts.customerName || '';
    state.address = opts.address || '';
    state.date = opts.date || '';
    var photoModal = document.getElementById('deliveryPhotoModal');
    if (photoModal) {
      photoModal.setAttribute('data-daily-order-id', String(state.dailyOrderId || 0));
      photoModal.setAttribute('data-customer-name', state.customerName || '');
      photoModal.setAttribute('data-assignment-id', String(opts.assignmentId || 0));
    }
    state.blob = null;
    state.preparing = false;
    revokePreviewUrl();
    state.photos = [];
    state.retakePhotoId = null;
    state.viewingPhotoId = null;
    state.orderedPieces = 0;
    state.orderTotal = 0;
    state.pricingMissing = false;
    state.pricePerPieceOverride = null;
    state.invoiceItems = [];
    state.savedTotal = 0;
    state.isSaved = false;
    state.paymentCollection = 'signature';
    state.amountCollected = null;
    state.summaryReady = false;
    state.invoiceReady = false;
    state.submitting = false;
    state.currentStep = 'photo';
    state.pendingVarianceConfirm = null;
    var varianceAck = $('deliveryVarianceAck');
    if (varianceAck) varianceAck.checked = false;
    state.confirmationSaved = false;
    state.completionMessage = '';
    state.stopFinished = false;

    var modal = $('deliveryPhotoModal');
    var confirm = $('deliveryPhotoAssignment');
    if (state.closeTimer) {
      clearTimeout(state.closeTimer);
      state.closeTimer = null;
    }
    hideVarianceConfirm();
    state.previousFocus = document.activeElement;
    if (confirm) {
      confirm.textContent = state.customerName;
    }
    var headerCustomer = $('deliveryModalCustomer');
    if (headerCustomer) headerCustomer.textContent = state.customerName;
    if ($('deliveryPhotoNotes')) $('deliveryPhotoNotes').value = '';
    if ($('deliveryPhotoType')) $('deliveryPhotoType').value = 'Before';
    setPhotoMode(opts.photoMode);
    state.photoReturnStep = null;
    var startStep = opts.startStep || (opts.photoMode === 'review' ? 'invoice' : 'photo');
    if (opts.photoMode !== 'review' && opts.startStep === 'delivery') {
      startStep = 'delivery';
    }
    $('deliveryInvoiceEditSavedBtn').hidden = true;
    $('deliveryInvoiceActions').hidden = opts.photoMode === 'review';
    var invoiceFooter = $('deliveryInvoiceFooterActions');
    if (invoiceFooter) invoiceFooter.hidden = true;
    setSubmitting(false);
    var varianceAlert = $('deliveryVarianceAlert');
    if (varianceAlert) varianceAlert.hidden = true;
    var pricingRow = $('deliveryPricingRow');
    if (pricingRow) pricingRow.hidden = true;
    syncPricePerPieceInputs(null);
    var orderedRef = $('deliveryOrderedRef');
    if (orderedRef) orderedRef.textContent = '';
    resetOrderDetailsPanel();
    var arriveOrders = $('deliveryArriveOrders');
    if (arriveOrders) arriveOrders.hidden = opts.photoMode === 'review';
    updatePhotoWorkflowUi(true);
    modal.classList.remove('is-closing', 'has-saved-photo', 'delivery-invoice-open', 'delivery-invoice-fullstep', 'native-camera-mode', 'camera-unavailable', 'is-photo-uploading');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    var routeRoot = $('driverRouteRoot');
    if (routeRoot) routeRoot.setAttribute('inert', '');
    document.body.classList.add('photo-mode-open');
    lockPhotoModalViewport();
    requestAnimationFrame(function () {
      modal.classList.add('is-open');
      var closeButton = $('deliveryPhotoModalClose');
      if (closeButton) {
        closeButton.textContent = i18n('cancel');
        closeButton.setAttribute('aria-label', i18n('cancel_photo'));
      }
    });
    if (state.photoMode === 'review') {
      stopCamera();
      setStatus(i18n('review_saved_photos'), '');
      goToStep('invoice');
    } else {
      // goToStep('photo') starts the camera; other steps keep it off.
      goToStep(startStep);
      if (opts.autoOpenCamera && startStep === 'photo' && usesCompactCapture()) {
        openNativeCameraPicker();
      }
    }
    refreshRouteSession().then(function () {
      if (opts.expandOrders) {
        loadModalOrderDetails();
      }
      loadPhotos();
      loadDeliverySummary();
    }).catch(function (err) {
      setStatus(err.message || i18n('session_expired'), 'error');
    });
  }

  function closeModal(options) {
    options = options || {};
    if (state.uploading || state.preparing || state.submitting) return;
    if (!options.deliveryFinished && state.confirmationSaved && state.photoReturnStep === 'complete') {
      state.photoReturnStep = null;
      finishDeliveryUi(state.completionMessage || i18n('delivery_confirmed'));
      return;
    }
    closeViewer();
    stopCamera();
    resetOrderDetailsPanel();
    state.blob = null;
    revokePreviewUrl();
    state.retakePhotoId = null;
    state.submitting = false;
    state.preparing = false;
    setPhotoControlsBusy(false);
    setSubmitting(false);
    var modal = $('deliveryPhotoModal');
    if (modal) {
      modal.setAttribute('aria-hidden', 'true');
      modal.classList.remove('is-open');
      modal.classList.add('is-closing');
      state.closeTimer = setTimeout(function () {
        modal.style.display = 'none';
        modal.classList.remove('is-closing', 'has-saved-photo');
        state.closeTimer = null;
      }, 240);
    }
    var routeRoot = $('driverRouteRoot');
    if (routeRoot) routeRoot.removeAttribute('inert');
    document.body.classList.remove('photo-mode-open');
    unlockPhotoModalViewport();
    hideVarianceConfirm();
    state.currentStep = 'photo';
    state.photoReturnStep = null;
    STEPS.forEach(function (s) {
      var panel = document.querySelector('[data-step-panel="' + s + '"]');
      if (panel) {
        panel.hidden = s !== 'photo';
        panel.classList.toggle('is-active', s === 'photo');
      }
    });
    var modalEl = $('deliveryPhotoModal');
    if (modalEl) {
      modalEl.classList.remove('delivery-step-photo', 'delivery-step-delivery', 'delivery-step-invoice', 'delivery-step-departure', 'delivery-mode-review', 'native-camera-mode', 'camera-unavailable', 'is-photo-uploading');
    }
    setStatus('');
    if (options.focusRoute) {
      setTimeout(function () {
        var next = $('nextStopCard');
        if (next && !next.hidden && next.scrollIntoView) {
          next.scrollIntoView({ behavior: 'smooth', block: 'start' });
          var mainAction = next.querySelector('.route-btn--navigate, .photo-complete-btn');
          if (mainAction) mainAction.focus({ preventScroll: true });
        }
      }, 280);
    } else if (state.previousFocus && typeof state.previousFocus.focus === 'function') {
      state.previousFocus.focus({ preventScroll: true });
    }
    state.previousFocus = null;
  }

  async function onFileChosen(event) {
    var file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    if (!looksLikeImageFile(file)) {
      setStatus(i18n('choose_image'), 'error');
      return;
    }
    state.blob = file;
    showCapturedPreview(URL.createObjectURL(file));
    state.preparing = true;
    setPhotoControlsBusy(true);
    var modal = $('deliveryPhotoModal');
    if (modal) modal.classList.add('is-photo-uploading');
    setStatus(i18n('preparing_photo'), 'loading');
    try {
      var prepared = await preparePhotoForUpload(file);
      state.blob = prepared.blob;
      state.preparing = false;
      setPhotoControlsBusy(false);
      setStatus(i18n('photo_selected_saving'), 'loading');
      await uploadBlob(prepared.blob, prepared.filename || 'arrival-photo.jpg');
    } catch (err) {
      state.preparing = false;
      setPhotoControlsBusy(false);
      if (modal) modal.classList.remove('is-photo-uploading');
      retakeCapture({
        message: err.message || i18n('upload_failed'),
        kind: 'error'
      });
    }
  }

  function onGalleryClick(event) {
    var btn = event.target.closest('[data-action][data-photo-id]');
    if (!btn) return;
    var photoId = parseInt(btn.getAttribute('data-photo-id'), 10);
    var action = btn.getAttribute('data-action');
    if (!photoId) return;

    if (action === 'view') {
      openViewer(photoId);
    } else if (action === 'remove') {
      removePhotoFlow(photoId);
    } else if (action === 'retake') {
      retakePhotoFlow(photoId);
    }
  }

  function onPhotoButtonClick(e) {
    var btn = e.currentTarget;
    if (state.submitting) return;
    e.preventDefault();
    e.stopPropagation();
    openModal({
      driverId: parseInt(btn.getAttribute('data-driver-id'), 10) || 0,
      driverName: btn.getAttribute('data-driver-name') || '',
      customerId: parseInt(btn.getAttribute('data-customer-id'), 10) || 0,
      dailyOrderId: parseInt(btn.getAttribute('data-daily-order-id'), 10) || 0,
      customerName: btn.getAttribute('data-customer-name') || '',
      address: btn.getAttribute('data-address') || (btn.closest('.stop-item') && btn.closest('.stop-item').getAttribute('data-address')) || '',
      date: btn.getAttribute('data-date') || '',
      assignmentId: parseInt(btn.getAttribute('data-assignment-id'), 10) || 0,
      photoMode: btn.getAttribute('data-photo-mode') || 'capture',
      startStep: btn.getAttribute('data-start-step') || 'photo',
      autoOpenCamera: true
    });
  }

  function bindPhotoButtons(root) {
    var scope = root || document;
    scope.querySelectorAll('.photo-complete-btn').forEach(function (btn) {
      if (btn.dataset.photoBound === '1') return;
      btn.dataset.photoBound = '1';
      btn.addEventListener('click', onPhotoButtonClick);
    });
  }

  function bind() {
    var modal = $('deliveryPhotoModal');
    if (!modal) return;

    bindPhotoButtons(document);

    $('deliveryPhotoModalClose').addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) closeModal();
    });
    var ordersToggle = $('deliveryOrdersToggle');
    if (ordersToggle) {
      ordersToggle.addEventListener('click', function (e) {
        e.preventDefault();
        toggleModalOrderDetails();
      });
    }
    $('deliveryCaptureBtn').addEventListener('click', captureFromVideo);
    $('deliveryRetakeBtn').addEventListener('click', retakeCapture);
    $('deliveryWizardPrimaryBtn').addEventListener('click', function () {
      if (state.submitting) return;
      if (state.currentStep === 'photo') {
        goToStep('delivery');
      } else if (state.currentStep === 'delivery') {
        confirmDelivery(false);
      }
    });
    var reviewBtn = $('deliveryWizardReviewBtn');
    if (reviewBtn) {
      reviewBtn.addEventListener('click', function () {
        if (state.submitting) return;
        populateInvoicePreview();
      });
    }
    $('deliveryWizardBackBtn').addEventListener('click', function () {
      if (state.submitting) return;
      if (state.currentStep === 'delivery') goToStep('photo');
      else if (state.currentStep === 'invoice') returnToDeliveryEdit();
    });
    function skipPhotoStep() {
      if (state.submitting) return;
      if (state.photoReturnStep === 'complete') {
        state.photoReturnStep = null;
        finishDeliveryUi(state.completionMessage || i18n('delivery_confirmed'));
        return;
      }
      goToStep('delivery');
    }
    $('deliveryWizardSkipBtn').addEventListener('click', skipPhotoStep);
    var skipInlineBtn = $('deliverySkipPhotoInlineBtn');
    if (skipInlineBtn) skipInlineBtn.addEventListener('click', skipPhotoStep);
    document.querySelectorAll('.delivery-wizard-step').forEach(function (stepBtn) {
      stepBtn.addEventListener('click', function () {
        if (state.submitting || state.photoMode === 'review') return;
        var target = stepBtn.getAttribute('data-step');
        if (target === 'invoice') {
          if (!state.summaryReady) return;
          var validated = validateDeliveryInputs();
          if (!validated) return;
          populateInvoicePreview();
        } else if (target === 'delivery') {
          goToStep('delivery');
        } else {
          goToStep('photo');
        }
      });
    });
    $('deliveryVarianceCancelBtn').addEventListener('click', hideVarianceConfirm);
    $('deliveryVarianceOkBtn').addEventListener('click', function () {
      if (typeof state.pendingVarianceConfirm === 'function') {
        state.pendingVarianceConfirm();
      }
    });
    $('deliveryInvoiceBackBtn').addEventListener('click', returnToDeliveryEdit);
    $('deliveryInvoiceConfirmBtn').addEventListener('click', function () {
      confirmDelivery(false);
    });
    $('deliveryInvoiceEditSavedBtn').addEventListener('click', function () {
      state.photoMode = 'capture';
      $('deliveryInvoiceEditSavedBtn').hidden = true;
      $('deliveryInvoiceActions').hidden = false;
      var wizardSteps = $('deliveryWizardSteps');
      var wizardActions = $('deliveryWizardActions');
      if (wizardSteps) wizardSteps.hidden = false;
      if (wizardActions) wizardActions.hidden = false;
      goToStep('delivery');
      updateDeliveryPreview();
    });
    $('deliveryPiecesInput').addEventListener('input', updateDeliveryPreview);
    $('deliveryCreditsInput').addEventListener('input', updateDeliveryPreview);
    function onPricePerPieceInput() {
      var value = readPricePerPieceInput();
      state.pricePerPieceOverride = value;
      syncPricePerPieceInputs(value);
      updateDeliveryPreview();
      if (state.invoiceReady) {
        populateInvoicePreview();
      }
    }
    var priceInput = $('deliveryPricePerPieceInput');
    if (priceInput) priceInput.addEventListener('input', onPricePerPieceInput);
    var invoicePriceInput = $('deliveryInvoicePriceInput');
    if (invoicePriceInput) invoicePriceInput.addEventListener('input', onPricePerPieceInput);
    var cashInput = $('deliveryCashCollectedInput');
    if (cashInput) {
      cashInput.addEventListener('input', function () {
        cashInput.dataset.userEdited = '1';
      });
    }
    document.querySelectorAll('.quantity-stepper-btn').forEach(function (button) {
      button.addEventListener('click', function () {
        var input = $(button.getAttribute('data-quantity-target'));
        if (!input || input.disabled) return;
        var next = Math.max(0, (parseInt(input.value, 10) || 0) + parseInt(button.getAttribute('data-quantity-step') || '0', 10));
        input.value = next;
        updateDeliveryPreview();
      });
    });
    $('deliveryFileInput').addEventListener('change', onFileChosen);
    $('deliveryGalleryInput').addEventListener('change', onFileChosen);
    $('deliveryPhotoType').addEventListener('change', function () {
      updatePhotoWorkflowUi(false);
    });
    $('deliveryFilePickerBtn').addEventListener('click', function () {
      openNativeCameraPicker();
    });
    $('deliveryGalleryPickerBtn').addEventListener('click', function () {
      $('deliveryGalleryInput').click();
    });
    $('deliveryReviewActivateCameraBtn').addEventListener('click', function () {
      showReviewCameraControls();
      var frame = $('deliveryCameraFrame');
      if (frame && frame.scrollIntoView && !usesCompactCapture()) {
        frame.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      if (usesCompactCapture()) {
        useNativeCamera();
        openNativeCameraPicker();
      } else {
        startCamera();
      }
    });

    var grid = $('deliveryPhotosGrid');
    if (grid) {
      grid.addEventListener('click', onGalleryClick);
    }

    var viewer = $('deliveryPhotoViewer');
    if (viewer) {
      $('deliveryPhotoViewerClose').addEventListener('click', closeViewer);
      viewer.addEventListener('click', function (e) {
        if (e.target === viewer) closeViewer();
      });
      $('deliveryViewerRemoveBtn').addEventListener('click', function () {
        if (state.viewingPhotoId) removePhotoFlow(state.viewingPhotoId);
      });
      $('deliveryViewerRetakeBtn').addEventListener('click', function () {
        if (state.viewingPhotoId) retakePhotoFlow(state.viewingPhotoId);
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      if (viewer && viewer.style.display === 'flex') {
        closeViewer();
        return;
      }
      if (modal.style.display === 'flex') {
        closeModal();
      }
    });
  }

  // A route can be open for many hours between stops. Refresh the authenticated
  // session while the page is visible so a valid driver is not interrupted at
  // the moment they need to save a quantity.
  function keepRouteSessionAlive() {
    if (document.visibilityState && document.visibilityState !== 'visible') return;
    refreshRouteSession().catch(function () {});
  }

  window.DriverDelivery = {
    bindPhotoButtons: bindPhotoButtons,
    openModal: openModal,
    closeModal: closeModal
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
  setInterval(keepRouteSessionAlive, 4 * 60 * 1000);
  document.addEventListener('visibilitychange', keepRouteSessionAlive);
  // Native phone cameras background the browser on many devices. Refresh as soon
  // as it resumes, before the driver continues the photo-to-invoice workflow.
  window.addEventListener('focus', keepRouteSessionAlive);
  window.addEventListener('pageshow', keepRouteSessionAlive);
})();
