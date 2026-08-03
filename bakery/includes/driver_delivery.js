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
    customerId: 0,
    dailyOrderId: 0,
    customerName: '',
    address: '',
    date: '',
    uploading: false,
    photos: [],
    viewingPhotoId: null,
    retakePhotoId: null,
    photoMode: 'capture',
    orderedPieces: 0,
    orderTotal: 0,
    pricingLabel: 'Order pricing',
    invoiceItems: [],
    savedTotal: 0,
    isSaved: false,
    summaryReady: false,
    invoiceReady: false,
    previousFocus: null,
    closeTimer: null
  };

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
    if (!el) return;
    el.textContent = message || '';
    el.className = 'photo-upload-status' + (kind ? ' is-' + kind : '');
  }

  function formatTime(value) {
    if (!value) return '';
    var d = new Date(value.replace(' ', 'T'));
    if (isNaN(d.getTime())) return value;
    return d.toLocaleString([], {
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
        ? 'Your session may have expired. Refresh the route and try again.'
        : 'The photo service returned an invalid response.';
      throw new Error(statusHint);
    }
    if (!response.ok) {
      throw new Error((data && (data.error || data.message)) || fallbackMessage || 'Request failed');
    }
    return data;
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
    if (frame) frame.classList.toggle('is-camera-idle', !state.cameraActive);
    state.blob = null;
    $('deliveryCaptureBtn').classList.remove('hidden');
    $('deliveryCaptureBtn').textContent = state.cameraActive ? 'Take photo' : 'Activate camera';
    $('deliveryRetakeBtn').classList.add('hidden');
    $('deliveryUploadBtn').classList.add('hidden');
  }

  function showCapturedPreview(objectUrl) {
    var video = $('deliveryCameraVideo');
    var canvas = $('deliveryCameraCanvas');
    var preview = $('deliveryPhotoPreview');
    if (video) video.style.display = 'none';
    if (canvas) canvas.style.display = 'none';
    if (preview) {
      preview.src = objectUrl;
      preview.style.display = 'block';
    }
    $('deliveryCaptureBtn').classList.add('hidden');
    $('deliveryRetakeBtn').classList.remove('hidden');
    $('deliveryUploadBtn').classList.remove('hidden');
  }

  function isLikelyMobile() {
    return /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
  }

  function updateCameraHeading() {
    var heading = $('deliveryCameraHeading');
    if (!heading) return;
    heading.textContent = state.retakePhotoId
      ? 'Retake photo'
      : 'Take delivery photo';
  }

  function setPhotoMode(mode) {
    state.photoMode = mode === 'review' ? 'review' : 'capture';

    var isReview = state.photoMode === 'review';
    var cameraSection = $('deliveryCameraSection');
    var reviewActions = $('deliveryReviewActions');
    var gallery = $('deliveryPhotosGallerySection');
    var title = $('deliveryPhotoModalTitle');
    var finishHint = $('deliveryFinishHint');
    var completeButton = $('deliveryCompleteBtn');
    var confirmation = $('deliveryConfirmation');

    if (cameraSection) cameraSection.hidden = isReview;
    if (reviewActions) reviewActions.hidden = !isReview;
    if (gallery) gallery.open = isReview;
    if (finishHint) finishHint.hidden = isReview;
    if (confirmation) confirmation.hidden = isReview;
    if (completeButton) completeButton.hidden = isReview;
    if (title) title.textContent = isReview ? 'Delivery photos' : 'Photo & finish';
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
    updateCameraHeading();

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      setStatus('Camera API unavailable. Use Phone camera / library below.', 'error');
      return;
    }

    setStatus('Starting camera…', 'loading');

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
      if (captureButton) captureButton.textContent = 'Take photo';
    } catch (err) {
      try {
        state.stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: true
        });
        state.cameraActive = true;
      } catch (err2) {
        var msg = 'Could not open camera. Use Phone camera / library below.';
        if (err2 && err2.name === 'NotAllowedError') {
          msg = 'Camera permission denied. Allow access, or use Phone camera / library.';
        } else if (
          location.protocol === 'http:' &&
          location.hostname !== 'localhost' &&
          location.hostname !== '127.0.0.1'
        ) {
          msg = 'Camera needs HTTPS (or localhost). Use Phone camera / library below.';
        }
        setStatus(msg, 'error');
        return;
      }
    }

    var video = $('deliveryCameraVideo');
    var frame = $('deliveryCameraFrame');
    if (frame) frame.classList.remove('is-camera-idle');
    video.srcObject = state.stream;
    try {
      await video.play();
    } catch (playErr) {
      // Autoplay can fail; user can still tap capture after gesture.
    }
    setStatus(
      state.retakePhotoId
        ? 'Camera ready — capture the replacement photo.'
        : 'Camera ready — take a photo, then upload.',
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
      setStatus('Camera not ready yet. Wait a moment or use file picker.', 'error');
      return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

    canvas.toBlob(function (blob) {
      if (!blob) {
        setStatus('Could not capture frame. Try the file picker.', 'error');
        return;
      }
      state.blob = blob;
      showCapturedPreview(URL.createObjectURL(blob));
      setStatus('Photo captured. Upload it, or retake.', 'success');
    }, 'image/jpeg', 0.88);
  }

  function retakeCapture() {
    if (state.blob) {
      state.blob = null;
    }
    showLivePreview();
    if (state.stream) {
      var video = $('deliveryCameraVideo');
      if (video) {
        video.srcObject = state.stream;
        video.play().catch(function () {});
      }
      setStatus('Camera ready — take a photo, then upload.', 'success');
    } else {
      startCamera();
    }
  }

  function getCoords() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) {
        resolve({ latitude: '', longitude: '' });
        return;
      }
      navigator.geolocation.getCurrentPosition(
        function (pos) {
          resolve({
            latitude: String(pos.coords.latitude),
            longitude: String(pos.coords.longitude)
          });
        },
        function () {
          resolve({ latitude: '', longitude: '' });
        },
        { enableHighAccuracy: true, timeout: 4000, maximumAge: 60000 }
      );
    });
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

    if (!state.photos.length) {
      grid.innerHTML = '<div class="no-photos">No photos yet for this stop.</div>';
      return;
    }

    grid.innerHTML = state.photos
      .map(function (photo) {
        var type = escapeHtml(photo.photo_type || 'Photo');
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
          '" aria-label="View ' +
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
          '">View</button>' +
          '<button type="button" class="btn btn-outline btn-sm" data-action="retake" data-photo-id="' +
          id +
          '">Retake</button>' +
          '<button type="button" class="btn btn-danger btn-sm" data-action="remove" data-photo-id="' +
          id +
          '">Remove</button>' +
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
    if (grid) {
      grid.innerHTML = '<div class="loading-photos">Loading photos…</div>';
    }

    try {
      var body =
        'action=list' +
        '&driver_id=' +
        encodeURIComponent(String(state.driverId)) +
        '&customer_id=' +
        encodeURIComponent(String(state.customerId)) +
        '&date=' +
        encodeURIComponent(state.date);

      var response = await fetch('upload_driver_photo.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
      });
      var data = await readJsonResponse(response, 'Could not load photos');
      if (!data || !data.success) {
        throw new Error((data && data.error) || 'Could not load photos');
      }
      state.photos = data.photos || [];
      renderPhotos();
    } catch (err) {
      if (grid) {
        grid.innerHTML =
          '<div class="error-photos">' +
          escapeHtml(err.message || 'Could not load photos') +
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

    var response = await fetch('upload_driver_photo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    });
    var data = await readJsonResponse(response, 'Could not remove photo');
    if (!data || !data.success) {
      throw new Error((data && data.error) || 'Could not remove photo');
    }

    state.photos = state.photos.filter(function (p) {
      return Number(p.id) !== Number(photoId);
    });
    renderPhotos();

    if (!options.silent) {
      setStatus('Photo removed.', 'success');
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
    if (title) title.textContent = (photo.photo_type || 'Photo') + ' photo';
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
    state.cameraActive = false;
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
    if (!window.confirm('Remove ' + label + ' photo? This cannot be undone.')) {
      return;
    }
    try {
      await deletePhoto(photoId);
      if (state.viewingPhotoId === Number(photoId)) {
        closeViewer();
      }
    } catch (err) {
      setStatus(err.message || 'Could not remove photo', 'error');
    }
  }

  async function retakePhotoFlow(photoId) {
    var photo = findPhoto(photoId);
    var label = photo ? photo.photo_type || 'this' : 'this';
    if (
      !window.confirm(
        'Retake ' + label + ' photo? The current photo will be removed so you can capture a replacement.'
      )
    ) {
      return;
    }

    closeViewer();

    try {
      // Remove immediately so the bad photo is gone, then open camera for replacement.
      await deletePhoto(photoId, { silent: true });
      state.retakePhotoId = Number(photoId);
      showReviewCameraControls();
      if (photo && photo.photo_type && $('deliveryPhotoType')) {
        $('deliveryPhotoType').value = photo.photo_type;
      }
      updateCameraHeading();
      var section = $('deliveryCameraFrame');
      if (section && section.scrollIntoView) {
        section.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      await startCamera();
      setStatus('Previous photo removed. Capture and upload the replacement.', 'success');
    } catch (err) {
      setStatus(err.message || 'Could not start retake', 'error');
    }
  }

  async function uploadBlob(blob, filename) {
    if (state.uploading) return;
    if (!blob) {
      setStatus('Take or choose a photo first.', 'error');
      return;
    }

    state.uploading = true;
    setStatus('Uploading photo…', 'loading');
    var progress = $('deliveryPhotoProgress');
    var fill = $('deliveryPhotoProgressFill');
    if (progress) progress.classList.add('is-active');
    if (fill) fill.style.width = '35%';

    try {
      var coords = await getCoords();
      var formData = new FormData();
      formData.append('action', 'upload');
      formData.append('photo', blob, filename || 'capture.jpg');
      formData.append('driver_id', String(state.driverId));
      formData.append('customer_id', String(state.customerId));
      formData.append('daily_order_id', String(state.dailyOrderId));
      formData.append('date', state.date);
      formData.append('photo_type', ($('deliveryPhotoType') && $('deliveryPhotoType').value) || 'After');
      formData.append('notes', ($('deliveryPhotoNotes') && $('deliveryPhotoNotes').value) || '');
      formData.append('latitude', coords.latitude);
      formData.append('longitude', coords.longitude);

      if (fill) fill.style.width = '70%';

      var response = await fetch('upload_driver_photo.php', {
        method: 'POST',
        body: formData
      });
      var data = await readJsonResponse(response, 'Photo upload failed');
      if (fill) fill.style.width = '100%';

      if (!data || !data.success) {
        throw new Error((data && data.error) || 'Upload failed');
      }

      state.retakePhotoId = null;
      updateCameraHeading();
      setStatus(data.message || 'Photo saved.', 'success');
      if ($('deliveryPhotoNotes')) $('deliveryPhotoNotes').value = '';
      retakeCapture();
      await loadPhotos();
    } catch (err) {
      setStatus(err.message || 'Upload failed', 'error');
    } finally {
      state.uploading = false;
      if (progress) {
        setTimeout(function () {
          progress.classList.remove('is-active');
          if (fill) fill.style.width = '0%';
        }, 400);
      }
    }
  }

  function updateDeliveryPreview() {
    var piecesInput = $('deliveryPiecesInput');
    var creditsInput = $('deliveryCreditsInput');
    var totalEl = $('deliveryConfirmationTotal');
    var breakdownEl = $('deliveryConfirmationBreakdown');
    var pieces = Math.max(0, parseInt(piecesInput && piecesInput.value, 10) || 0);
    var credits = Math.max(0, parseInt(creditsInput && creditsInput.value, 10) || 0);
    var billable = Math.max(0, pieces - credits);
    var perPiece = state.orderedPieces > 0 ? state.orderTotal / state.orderedPieces : 0;
    var total = state.photoMode === 'review' && state.isSaved ? state.savedTotal : billable * perPiece;
    if (totalEl) totalEl.textContent = '$' + total.toFixed(2);
    if (breakdownEl) breakdownEl.textContent = billable + ' billable pieces · ' + (perPiece ? '$' + perPiece.toFixed(2) + ' average per piece · ' + state.pricingLabel : 'No priced pieces on this order');
  }

  function formatInvoiceDate(value) {
    if (!value) return '';
    var parts = String(value).split('-');
    if (parts.length !== 3) return value;
    var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
  }

  function populateInvoicePreview() {
    if (!state.summaryReady) {
      setStatus('Still loading the order pricing. Please try again in a moment.', 'error');
      return;
    }
    var pieces = Math.max(0, parseInt($('deliveryPiecesInput').value, 10) || 0);
    var credits = Math.max(0, parseInt($('deliveryCreditsInput').value, 10) || 0);
    if (credits > pieces) {
      setStatus('Credits taken back cannot exceed pieces delivered.', 'error');
      return;
    }
    var billable = Math.max(0, pieces - credits);
    var perPiece = state.orderedPieces > 0 ? state.orderTotal / state.orderedPieces : 0;
    var total = state.photoMode === 'review' && state.isSaved ? state.savedTotal : billable * perPiece;
    $('deliveryInvoiceDate').textContent = formatInvoiceDate(state.date);
    $('deliveryInvoiceCustomer').textContent = state.customerName || '—';
    $('deliveryInvoiceAddress').textContent = state.address || 'No address on file';
    $('deliveryInvoiceOrderedPieces').textContent = String(state.orderedPieces);
    $('deliveryInvoicePieces').textContent = String(pieces);
    $('deliveryInvoiceCredits').textContent = String(credits);
    $('deliveryInvoicePrice').textContent = '$' + perPiece.toFixed(2);
    $('deliveryInvoiceTotal').textContent = '$' + total.toFixed(2);
    $('deliveryInvoicePricingNote').textContent = state.pricingLabel + ' · ' + billable + ' billable pieces';
    var itemsEl = $('deliveryInvoiceItems');
    if (itemsEl) {
      itemsEl.innerHTML = state.invoiceItems.length
        ? '<div class="delivery-invoice-items-heading"><span>Order pricing basis</span><span>Amount</span></div>' + state.invoiceItems.map(function (item) {
            return '<div class="delivery-invoice-item"><span><strong>' + escapeHtml(item.product_name || 'Product') + '</strong><small>' + escapeHtml(String(item.quantity || 0)) + ' × $' + Number(item.unit_price || 0).toFixed(2) + '</small></span><strong>$' + Number(item.line_total || 0).toFixed(2) + '</strong></div>';
          }).join('')
        : '<p class="delivery-invoice-empty">No item pricing details available.</p>';
    }
    state.invoiceReady = true;
    $('deliveryConfirmation').hidden = true;
    $('deliveryInvoicePreview').hidden = false;
    $('deliveryCompleteBtn').hidden = true;
  }

  function returnToDeliveryEdit() {
    state.invoiceReady = false;
    $('deliveryInvoicePreview').hidden = true;
    $('deliveryInvoiceActions').hidden = false;
    $('deliveryInvoiceEditSavedBtn').hidden = true;
    $('deliveryConfirmation').hidden = false;
    $('deliveryCompleteBtn').hidden = false;
    updateDeliveryPreview();
    $('deliveryPiecesInput').focus({ preventScroll: true });
  }

  async function loadDeliverySummary() {
    var piecesInput = $('deliveryPiecesInput');
    var creditsInput = $('deliveryCreditsInput');
    if (!piecesInput || !creditsInput) return;
    piecesInput.disabled = true;
    creditsInput.disabled = true;
    try {
      var response = await fetch('complete_delivery.php', {
        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_delivery_summary&daily_order_id=' + encodeURIComponent(String(state.dailyOrderId))
      });
      var data = await readJsonResponse(response, 'Could not load delivery total');
      if (!data || !data.success) throw new Error((data && data.error) || 'Could not load delivery total');
      state.orderedPieces = Number(data.ordered_pieces) || 0;
      state.orderTotal = Number(data.order_total) || 0;
      state.pricingLabel = data.pricing_label || 'Order pricing';
      state.invoiceItems = Array.isArray(data.items) ? data.items : [];
      state.savedTotal = Number(data.saved_total) || 0;
      state.isSaved = data.is_saved === true;
      state.summaryReady = true;
      piecesInput.value = Number(data.delivered_pieces) || 0;
      creditsInput.value = Number(data.credits_taken_back) || 0;
      updateDeliveryPreview();
      if (state.photoMode === 'review') {
        populateInvoicePreview();
        $('deliveryInvoiceActions').hidden = true;
        $('deliveryInvoiceEditSavedBtn').hidden = false;
      }
    } catch (err) {
      state.summaryReady = false;
      setStatus(err.message || 'Could not load delivery total', 'error');
    } finally {
      piecesInput.disabled = false;
      creditsInput.disabled = false;
      if ($('deliveryCompleteBtn')) $('deliveryCompleteBtn').disabled = state.photoMode === 'review';
    }
  }

  async function confirmDelivery() {
    var btn = $('deliveryInvoiceConfirmBtn');
    if (btn) btn.disabled = true;
    var pieces = parseInt($('deliveryPiecesInput') && $('deliveryPiecesInput').value, 10);
    var credits = parseInt($('deliveryCreditsInput') && $('deliveryCreditsInput').value, 10);
    if (!Number.isInteger(pieces) || pieces < 0 || !Number.isInteger(credits) || credits < 0) {
      setStatus('Enter whole numbers for pieces and credits.', 'error');
      if (btn) btn.disabled = false;
      return;
    }
    if (credits > pieces) {
      setStatus('Credits taken back cannot exceed pieces delivered.', 'error');
      if (btn) btn.disabled = false;
      return;
    }
    setStatus('Saving delivery confirmation…', 'loading');

    try {
      var body = 'action=confirm_delivery&daily_order_id=' + encodeURIComponent(String(state.dailyOrderId)) +
        '&delivered_pieces=' + encodeURIComponent(String(pieces)) +
        '&credits_taken_back=' + encodeURIComponent(String(credits));
      var response = await fetch('complete_delivery.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body
      });
      var data = await readJsonResponse(response, 'Could not complete delivery');
      if (!data || !data.success) {
        throw new Error((data && data.error) || 'Could not complete delivery');
      }
      finishDeliveryUi((data.message || 'Delivery confirmed.') + ' Total: $' + Number(data.total || 0).toFixed(2));
    } catch (err) {
      setStatus(err.message || 'Could not complete delivery', 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  function finishDeliveryUi(message) {
    setStatus(message, 'success');
    if (window.DriverRoute && typeof window.DriverRoute.afterDeliveryComplete === 'function') {
      window.DriverRoute.afterDeliveryComplete(state.dailyOrderId);
    } else {
      var stop = document.querySelector('.stop-item[data-daily-order-id="' + state.dailyOrderId + '"]');
      if (stop) {
        var badge = stop.querySelector('.status-badge');
        if (badge) { badge.textContent = 'Delivered'; badge.className = 'status-badge status-badge--delivered'; }
      }
    }
    setTimeout(function () { closeModal({ focusRoute: true }); }, 450);
  }

  function openModal(opts) {
    state.driverId = opts.driverId;
    state.customerId = opts.customerId;
    state.dailyOrderId = opts.dailyOrderId;
    state.customerName = opts.customerName || '';
    state.address = opts.address || '';
    state.date = opts.date || '';
    state.blob = null;
    state.photos = [];
    state.retakePhotoId = null;
    state.viewingPhotoId = null;
    state.orderedPieces = 0;
    state.orderTotal = 0;
    state.invoiceItems = [];
    state.savedTotal = 0;
    state.isSaved = false;
    state.summaryReady = false;
    state.invoiceReady = false;

    var modal = $('deliveryPhotoModal');
    var confirm = $('deliveryPhotoAssignment');
    if (state.closeTimer) {
      clearTimeout(state.closeTimer);
      state.closeTimer = null;
    }
    state.previousFocus = document.activeElement;
    if (confirm) {
      confirm.textContent = state.customerName;
    }
    if ($('deliveryPhotoNotes')) $('deliveryPhotoNotes').value = '';
    if ($('deliveryPhotoType')) $('deliveryPhotoType').value = 'After';
    setPhotoMode(opts.photoMode);
    $('deliveryConfirmation').hidden = opts.photoMode === 'review';
    $('deliveryInvoicePreview').hidden = true;
    $('deliveryInvoiceActions').hidden = opts.photoMode === 'review';
    $('deliveryInvoiceEditSavedBtn').hidden = true;
    $('deliveryCompleteBtn').hidden = opts.photoMode === 'review';
    $('deliveryCompleteBtn').disabled = true;
    updateCameraHeading();
    modal.classList.remove('is-closing', 'has-saved-photo');
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
    var routeRoot = $('driverRouteRoot');
    if (routeRoot) routeRoot.setAttribute('inert', '');
    document.body.classList.add('photo-mode-open');
    requestAnimationFrame(function () {
      modal.classList.add('is-open');
      var closeButton = $('deliveryPhotoModalClose');
      if (closeButton) closeButton.focus({ preventScroll: true });
    });
    if (state.photoMode === 'review') {
      stopCamera();
      setStatus('Review saved delivery photos. Activate the camera only if you want to add one.', '');
    } else {
      showLivePreview();
      setStatus('Camera is off. Tap Activate camera when you are ready.', '');
    }
    loadPhotos();
    loadDeliverySummary();
  }

  function closeModal(options) {
    options = options || {};
    closeViewer();
    stopCamera();
    state.blob = null;
    state.retakePhotoId = null;
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

  function onFileChosen(event) {
    var file = event.target.files && event.target.files[0];
    event.target.value = '';
    if (!file) return;
    if (!file.type || file.type.indexOf('image/') !== 0) {
      setStatus('Please choose an image file.', 'error');
      return;
    }
    state.blob = file;
    showCapturedPreview(URL.createObjectURL(file));
    setStatus('Photo selected. Upload it, or retake.', 'success');
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
    e.preventDefault();
    e.stopPropagation();
    openModal({
      driverId: parseInt(btn.getAttribute('data-driver-id'), 10) || 0,
      customerId: parseInt(btn.getAttribute('data-customer-id'), 10) || 0,
      dailyOrderId: parseInt(btn.getAttribute('data-daily-order-id'), 10) || 0,
      customerName: btn.getAttribute('data-customer-name') || '',
      address: btn.getAttribute('data-address') || (btn.closest('.stop-item') && btn.closest('.stop-item').getAttribute('data-address')) || '',
      date: btn.getAttribute('data-date') || '',
      photoMode: btn.getAttribute('data-photo-mode') || 'capture'
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
    $('deliveryCaptureBtn').addEventListener('click', captureFromVideo);
    $('deliveryRetakeBtn').addEventListener('click', retakeCapture);
    $('deliveryUploadBtn').addEventListener('click', function () {
      var name = state.blob && state.blob.name ? state.blob.name : 'capture.jpg';
      uploadBlob(state.blob, name);
    });
    $('deliveryCompleteBtn').addEventListener('click', populateInvoicePreview);
    $('deliveryInvoiceBackBtn').addEventListener('click', returnToDeliveryEdit);
    $('deliveryInvoiceConfirmBtn').addEventListener('click', confirmDelivery);
    $('deliveryInvoiceEditSavedBtn').addEventListener('click', function () {
      state.photoMode = 'capture';
      $('deliveryInvoiceEditSavedBtn').hidden = true;
      $('deliveryInvoicePreview').hidden = true;
      $('deliveryInvoiceActions').hidden = false;
      $('deliveryConfirmation').hidden = false;
      $('deliveryCompleteBtn').hidden = false;
      $('deliveryCompleteBtn').disabled = false;
      updateDeliveryPreview();
      $('deliveryPiecesInput').focus({ preventScroll: true });
    });
    $('deliveryPiecesInput').addEventListener('input', updateDeliveryPreview);
    $('deliveryCreditsInput').addEventListener('input', updateDeliveryPreview);
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
    $('deliveryFilePickerBtn').addEventListener('click', function () {
      $('deliveryFileInput').click();
    });
    $('deliveryReviewActivateCameraBtn').addEventListener('click', async function () {
      showReviewCameraControls();
      var frame = $('deliveryCameraFrame');
      if (frame && frame.scrollIntoView) {
        frame.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      await startCamera();
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
})();
