/**
 * Driver offline outbox — IndexedDB queue for photo upload + delivery confirm.
 * No service-worker page caching; sync when navigator.onLine returns.
 */
(function (global) {
  'use strict';

  var DB_NAME = 'bakery_driver_outbox';
  var DB_VERSION = 1;
  var STORE = 'jobs';
  var MAX_ATTEMPTS = 8;

  function i18n(key, fallback) {
    var pack = global.__DRIVER_PAGE_I18N__ || {};
    return pack[key] || fallback || key;
  }

  function openDb() {
    return new Promise(function (resolve, reject) {
      if (!global.indexedDB) {
        reject(new Error('indexedDB unavailable'));
        return;
      }
      var req = global.indexedDB.open(DB_NAME, DB_VERSION);
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains(STORE)) {
          var store = db.createObjectStore(STORE, { keyPath: 'id' });
          store.createIndex('status', 'status', { unique: false });
          store.createIndex('dailyOrderId', 'dailyOrderId', { unique: false });
        }
      };
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function () { reject(req.error || new Error('outbox open failed')); };
    });
  }

  function withStore(mode, fn) {
    return openDb().then(function (db) {
      return new Promise(function (resolve, reject) {
        var tx = db.transaction(STORE, mode);
        var store = tx.objectStore(STORE);
        Promise.resolve(fn(store)).then(function (value) {
          tx.oncomplete = function () { resolve(value); };
          tx.onerror = function () { reject(tx.error || new Error('outbox tx failed')); };
        }).catch(reject);
      });
    });
  }

  function newId(prefix) {
    var rand = '';
    try {
      var bytes = new Uint8Array(8);
      global.crypto.getRandomValues(bytes);
      rand = Array.prototype.map.call(bytes, function (b) {
        return ('0' + b.toString(16)).slice(-2);
      }).join('');
    } catch (e) {
      rand = String(Date.now()) + Math.random().toString(16).slice(2);
    }
    return String(prefix || 'req') + '-' + rand;
  }

  function putJob(job) {
    return withStore('readwrite', function (store) {
      store.put(job);
      return job;
    });
  }

  function getJob(id) {
    return withStore('readonly', function (store) {
      return new Promise(function (resolve, reject) {
        var req = store.get(id);
        req.onsuccess = function () { resolve(req.result || null); };
        req.onerror = function () { reject(req.error); };
      });
    });
  }

  function deleteJob(id) {
    return withStore('readwrite', function (store) {
      store.delete(id);
      return true;
    });
  }

  function listJobs() {
    return withStore('readonly', function (store) {
      return new Promise(function (resolve, reject) {
        var req = store.getAll();
        req.onsuccess = function () { resolve(req.result || []); };
        req.onerror = function () { reject(req.error); };
      });
    });
  }

  function emit(status, detail) {
    try {
      global.dispatchEvent(new CustomEvent('bakery-outbox', { detail: Object.assign({ status: status }, detail || {}) }));
    } catch (e) { /* ignore */ }
    renderChips();
  }

  function chipLabel(status) {
    if (status === 'queued') return i18n('outbox_queued', 'Queued');
    if (status === 'synced') return i18n('outbox_synced', 'Synced');
    if (status === 'failed') return i18n('outbox_failed', 'Failed — tap to retry');
    return status;
  }

  function renderChips() {
    listJobs().then(function (jobs) {
      var active = jobs.filter(function (j) { return j.status === 'queued' || j.status === 'failed'; });
      var header = document.getElementById('driverOutboxChip');
      if (header) {
        if (!active.length) {
          header.hidden = true;
          header.textContent = '';
          header.onclick = null;
        } else {
          var failed = active.some(function (j) { return j.status === 'failed'; });
          header.hidden = false;
          header.textContent = failed ? chipLabel('failed') : chipLabel('queued') + ' (' + active.length + ')';
          header.classList.toggle('is-failed', failed);
          header.onclick = function () { flush({ force: true }); };
        }
      }
      document.querySelectorAll('.stop-item[data-daily-order-id]').forEach(function (el) {
        var orderId = String(el.getAttribute('data-daily-order-id') || '');
        var chip = el.querySelector('[data-outbox-chip]');
        if (!chip) return;
        var match = active.filter(function (j) { return String(j.dailyOrderId) === orderId; });
        if (!match.length) {
          chip.hidden = true;
          chip.textContent = '';
          return;
        }
        var failed = match.some(function (j) { return j.status === 'failed'; });
        chip.hidden = false;
        chip.textContent = failed ? chipLabel('failed') : chipLabel('queued');
        chip.classList.toggle('is-failed', failed);
      });
    }).catch(function () { /* ignore */ });
  }

  function backoffMs(attempt) {
    return Math.min(30000, 800 * Math.pow(2, Math.max(0, attempt - 1)));
  }

  async function sendPhoto(job) {
    var form = new FormData();
    form.append('action', 'upload');
    form.append('photo', job.photoBlob, job.filename || 'delivery-photo.jpg');
    Object.keys(job.payload || {}).forEach(function (key) {
      form.append(key, job.payload[key] == null ? '' : String(job.payload[key]));
    });
    form.append('client_request_id', job.id);
    var response = await fetch('upload_driver_photo.php', { method: 'POST', body: form, credentials: 'same-origin' });
    var data = await response.json();
    if (!data || !data.success) {
      throw new Error((data && data.error) || 'photo upload failed');
    }
    return data;
  }

  async function sendConfirm(job) {
    var body = job.body || '';
    if (body.indexOf('client_request_id=') === -1) {
      body += (body ? '&' : '') + 'client_request_id=' + encodeURIComponent(job.id);
    }
    var response = await fetch('complete_delivery.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body,
      credentials: 'same-origin'
    });
    var data = await response.json();
    if (!data || !data.success) {
      throw new Error((data && data.error) || 'confirm failed');
    }
    return data;
  }

  async function processJob(job) {
    if (!job || (job.status !== 'queued' && job.status !== 'failed')) return;
    if (!global.navigator.onLine && !job.force) {
      return;
    }
    job.status = 'syncing';
    job.attempts = (job.attempts || 0) + 1;
    await putJob(job);
    try {
      if (job.kind === 'photo') {
        await sendPhoto(job);
      } else if (job.kind === 'confirm') {
        await sendConfirm(job);
      } else {
        throw new Error('unknown outbox kind');
      }
      await deleteJob(job.id);
      emit('synced', { id: job.id, kind: job.kind, dailyOrderId: job.dailyOrderId });
    } catch (err) {
      job.status = job.attempts >= MAX_ATTEMPTS ? 'failed' : 'queued';
      job.lastError = String(err && err.message ? err.message : err);
      job.nextAttemptAt = Date.now() + backoffMs(job.attempts);
      await putJob(job);
      emit('failed', { id: job.id, kind: job.kind, error: job.lastError, dailyOrderId: job.dailyOrderId });
      throw err;
    }
  }

  async function flush(opts) {
    opts = opts || {};
    var jobs = await listJobs();
    var now = Date.now();
    for (var i = 0; i < jobs.length; i++) {
      var job = jobs[i];
      if (job.status !== 'queued' && !(opts.force && job.status === 'failed')) continue;
      if (!opts.force && job.nextAttemptAt && job.nextAttemptAt > now) continue;
      job.force = !!opts.force;
      try {
        await processJob(job);
      } catch (e) {
        // Continue remaining jobs.
      }
    }
    renderChips();
  }

  async function enqueuePhoto(fields) {
    var id = fields.id || newId('photo');
    var job = {
      id: id,
      kind: 'photo',
      status: 'queued',
      dailyOrderId: fields.dailyOrderId || 0,
      payload: fields.payload || {},
      photoBlob: fields.photoBlob,
      filename: fields.filename || 'delivery-photo.jpg',
      attempts: 0,
      createdAt: Date.now()
    };
    await putJob(job);
    emit('queued', { id: id, kind: 'photo', dailyOrderId: job.dailyOrderId });
    if (global.navigator.onLine) {
      try { await processJob(job); } catch (e) { /* stays queued */ }
    }
    return { id: id, queued: true };
  }

  async function enqueueConfirm(fields) {
    var id = fields.id || newId('confirm');
    var job = {
      id: id,
      kind: 'confirm',
      status: 'queued',
      dailyOrderId: fields.dailyOrderId || 0,
      body: fields.body || '',
      attempts: 0,
      createdAt: Date.now()
    };
    await putJob(job);
    emit('queued', { id: id, kind: 'confirm', dailyOrderId: job.dailyOrderId });
    if (global.navigator.onLine) {
      try { await processJob(job); } catch (e) { /* stays queued */ }
    }
    return { id: id, queued: true };
  }

  function bindUi() {
    global.addEventListener('online', function () { flush({ force: true }); });
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', renderChips);
    } else {
      renderChips();
    }
    setInterval(function () { flush(); }, 15000);
  }

  global.BakeryDriverOutbox = {
    newId: newId,
    enqueuePhoto: enqueuePhoto,
    enqueueConfirm: enqueueConfirm,
    flush: flush,
    list: listJobs,
    render: renderChips
  };

  bindUi();
}(window));
