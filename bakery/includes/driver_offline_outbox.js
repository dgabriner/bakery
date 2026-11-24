/**
 * Driver offline outbox. IndexedDB queue for photo upload and delivery confirm.
 * No service-worker page caching. Sync when navigator.onLine returns.
 *
 * iOS Safari drops the IndexedDB connection after the page is backgrounded and
 * rejects in-flight work with "Connection to Indexed Database server lost".
 * That rejection was unhandled. This file closes the connection when the page
 * hides, reopens on the next use, retries a lost connection once, and keeps
 * the job in memory when IndexedDB is down so a photo or confirm is not dropped.
 */
(function (global) {
  'use strict';

  var DB_NAME = 'bakery_driver_outbox';
  var DB_VERSION = 1;
  var STORE = 'jobs';
  var MAX_ATTEMPTS = 8;

  var dbPromise = null;
  var liveDb = null;
  var memoryJobs = [];
  var inFlight = {};

  function i18n(key, fallback) {
    var pack = global.__DRIVER_PAGE_I18N__ || {};
    return pack[key] || fallback || key;
  }

  function isConnectionLost(err) {
    var name = String(err && err.name || '');
    var message = String(err && err.message || err || '');
    return name === 'InvalidStateError'
      || /Indexed Database server lost/i.test(message)
      || /database connection is closing/i.test(message);
  }

  function preventDefault(event) {
    if (event && typeof event.preventDefault === 'function') {
      event.preventDefault();
    }
  }

  function releaseConnection() {
    var db = liveDb;
    liveDb = null;
    dbPromise = null;
    if (!db) return;
    db.onclose = null;
    db.onversionchange = null;
    try { db.close(); } catch (closeErr) { /* already closed */ }
  }

  function openDb() {
    if (dbPromise) return dbPromise;
    var pending = new Promise(function (resolve, reject) {
      if (!global.indexedDB) {
        reject(new Error('indexedDB unavailable'));
        return;
      }
      var req;
      try {
        req = global.indexedDB.open(DB_NAME, DB_VERSION);
      } catch (err) {
        reject(err);
        return;
      }
      req.onupgradeneeded = function () {
        var db = req.result;
        if (!db.objectStoreNames.contains(STORE)) {
          var store = db.createObjectStore(STORE, { keyPath: 'id' });
          store.createIndex('status', 'status', { unique: false });
          store.createIndex('dailyOrderId', 'dailyOrderId', { unique: false });
        }
      };
      req.onsuccess = function () {
        var db = req.result;
        liveDb = db;
        db.onclose = function () {
          if (liveDb === db) {
            liveDb = null;
            dbPromise = null;
          }
        };
        db.onversionchange = function () {
          if (liveDb === db) {
            liveDb = null;
            dbPromise = null;
          }
          try { db.close(); } catch (closeErr) { /* ignore */ }
        };
        resolve(db);
      };
      req.onerror = function (event) {
        preventDefault(event);
        reject(req.error || new Error('outbox open failed'));
      };
    });
    dbPromise = pending;
    pending.catch(function () {
      if (dbPromise === pending) dbPromise = null;
    });
    return pending;
  }

  function requestToPromise(req) {
    return new Promise(function (resolve, reject) {
      req.onsuccess = function () { resolve(req.result); };
      req.onerror = function (event) {
        preventDefault(event);
        reject(req.error || new Error('outbox request failed'));
      };
    });
  }

  function runTransaction(db, mode, fn) {
    return new Promise(function (resolve, reject) {
      var tx;
      try {
        tx = db.transaction(STORE, mode);
      } catch (err) {
        reject(err);
        return;
      }
      var store = tx.objectStore(STORE);
      var settled = false;
      var pendingValue;
      function finish(err, value) {
        if (settled) return;
        settled = true;
        if (err) reject(err);
        else resolve(value);
      }
      tx.oncomplete = function () { finish(null, pendingValue); };
      tx.onerror = function (event) {
        preventDefault(event);
        finish(tx.error || new Error('outbox tx failed'));
      };
      tx.onabort = function (event) {
        preventDefault(event);
        finish(tx.error || new Error('outbox tx aborted'));
      };
      Promise.resolve().then(function () {
        return fn(store);
      }).then(function (value) {
        pendingValue = value;
      }).catch(function (err) {
        try { tx.abort(); } catch (abortErr) { /* ignore */ }
        finish(err);
      });
    });
  }

  function withStore(mode, fn, attempt) {
    attempt = attempt || 0;
    return openDb().then(function (db) {
      return runTransaction(db, mode, fn);
    }).catch(function (err) {
      if (attempt < 1 && isConnectionLost(err)) {
        releaseConnection();
        return withStore(mode, fn, attempt + 1);
      }
      throw err;
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

  function rememberJob(job) {
    if (!job || !job.id) return;
    for (var i = 0; i < memoryJobs.length; i++) {
      if (memoryJobs[i].id === job.id) {
        memoryJobs[i] = job;
        return;
      }
    }
    memoryJobs.push(job);
  }

  function forgetJob(id) {
    memoryJobs = memoryJobs.filter(function (job) { return job.id !== id; });
  }

  function mergeJobs(rows, memory) {
    var byId = {};
    (rows || []).forEach(function (job) {
      if (job && job.id) byId[job.id] = job;
    });
    (memory || []).forEach(function (job) {
      if (!job || !job.id) return;
      if (!byId[job.id]) {
        byId[job.id] = job;
        return;
      }
      if (job.photoBlob && !byId[job.id].photoBlob) {
        byId[job.id].photoBlob = job.photoBlob;
      }
    });
    return Object.keys(byId).map(function (id) { return byId[id]; });
  }

  function putJob(job) {
    rememberJob(job);
    return withStore('readwrite', function (store) {
      return requestToPromise(store.put(job)).then(function () { return job; });
    }).catch(function () {
      return job;
    });
  }

  function deleteJob(id) {
    forgetJob(id);
    return withStore('readwrite', function (store) {
      return requestToPromise(store.delete(id)).then(function () { return true; });
    }).catch(function () { return true; });
  }

  function listJobs() {
    return withStore('readonly', function (store) {
      return requestToPromise(store.getAll());
    }).then(function (rows) {
      return mergeJobs(rows || [], memoryJobs);
    }).catch(function () {
      return memoryJobs.slice();
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
    if (status === 'failed') return i18n('outbox_failed', 'Failed. Tap to retry');
    return status;
  }

  function renderChips() {
    listJobs().then(function (jobs) {
      var active = jobs.filter(function (j) { return j.status === 'queued' || j.status === 'failed' || j.status === 'syncing'; });
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
    if (!job || (job.status !== 'queued' && job.status !== 'failed' && job.status !== 'syncing')) return;
    if (inFlight[job.id]) return;
    if (!global.navigator.onLine && !job.force) {
      return;
    }
    inFlight[job.id] = true;
    job.status = 'syncing';
    job.attempts = (job.attempts || 0) + 1;
    try {
      await putJob(job);
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
    } finally {
      delete inFlight[job.id];
    }
  }

  async function flush(opts) {
    opts = opts || {};
    try {
      var jobs = await listJobs();
      jobs.sort(function (a, b) {
        return (a.createdAt || 0) - (b.createdAt || 0);
      });
      var now = Date.now();
      for (var i = 0; i < jobs.length; i++) {
        var job = jobs[i];
        if (job.status === 'syncing') job.status = 'queued';
        if (job.status !== 'queued' && !(opts.force && job.status === 'failed')) continue;
        if (!opts.force && job.nextAttemptAt && job.nextAttemptAt > now) continue;
        job.force = !!opts.force;
        try {
          await processJob(job);
        } catch (e) {
          // Continue remaining jobs. The job stays queued or in memory.
        }
      }
    } catch (err) {
      // IndexedDB can be down. Memory jobs stay put and the next flush retries.
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

  function safeFlush(opts) {
    return flush(opts).catch(function () { /* flush already catches */ });
  }

  function onPageHidden() {
    releaseConnection();
  }

  function onPageVisible() {
    safeFlush();
  }

  function bindUi() {
    global.addEventListener('online', function () { safeFlush({ force: true }); });
    global.addEventListener('pagehide', onPageHidden);
    if (global.document && typeof global.document.addEventListener === 'function') {
      global.document.addEventListener('visibilitychange', function () {
        if (global.document.visibilityState === 'hidden') onPageHidden();
        else if (global.document.visibilityState === 'visible') onPageVisible();
      });
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', renderChips);
    } else {
      renderChips();
    }
    setInterval(function () { safeFlush(); }, 15000);
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
