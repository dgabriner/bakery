/**
 * Take a stop, leave the drive document in place, then open the arrival photo.
 * Node only. No browser and no database.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
const failures = [];

function fail(message) {
  failures.push(message);
  console.log('FAIL  ' + message);
}

function pass(message) {
  console.log('PASS  ' + message);
}

function el(id) {
  const node = {
    id: id || '',
    hidden: false,
    disabled: false,
    open: false,
    checked: false,
    value: '',
    textContent: '',
    innerHTML: '',
    className: '',
    files: [],
    parentNode: null,
    children: [],
    attributes: {},
    dataset: {},
    listeners: {},
    clicked: 0
  };
  node.style = {
    display: '',
    cssText: '',
    top: '',
    setProperty: function () {},
    removeProperty: function () {}
  };
  node.classList = {
    add: function () {
      for (let i = 0; i < arguments.length; i++) node.classList._set[arguments[i]] = true;
    },
    remove: function () {
      for (let i = 0; i < arguments.length; i++) delete node.classList._set[arguments[i]];
    },
    toggle: function (name, force) {
      if (force === false) delete node.classList._set[name];
      else node.classList._set[name] = true;
    },
    contains: function (name) {
      return !!node.classList._set[name];
    },
    _set: {}
  };
  node.getAttribute = function (name) {
    return Object.prototype.hasOwnProperty.call(node.attributes, name) ? node.attributes[name] : null;
  };
  node.setAttribute = function (name, value) {
    node.attributes[name] = String(value);
  };
  node.removeAttribute = function (name) {
    delete node.attributes[name];
  };
  node.addEventListener = function (type, fn) {
    (node.listeners[type] = node.listeners[type] || []).push(fn);
  };
  node.removeEventListener = function () {};
  node.appendChild = function (child) {
    child.parentNode = node;
    node.children.push(child);
    return child;
  };
  node.removeChild = function (child) {
    child.parentNode = null;
    return child;
  };
  node.querySelector = function () { return null; };
  node.querySelectorAll = function () { return []; };
  node.closest = function (selector) {
    let cur = node;
    while (cur) {
      if (matches(cur, selector)) return cur;
      cur = cur.parentNode;
    }
    return null;
  };
  node.click = function () { node.clicked += 1; };
  node.focus = function () {};
  node.scrollIntoView = function () {};
  node.getBoundingClientRect = function () {
    return { height: 800, width: 390, top: 0, left: 0 };
  };
  node.contains = function (other) {
    let cur = other;
    while (cur) {
      if (cur === node) return true;
      cur = cur.parentNode;
    }
    return false;
  };
  return node;
}

function matches(node, selector) {
  if (!node || !node.classList) return false;
  if (selector.charAt(0) === '.') return node.classList.contains(selector.slice(1));
  if (selector.charAt(0) === '#') return node.id === selector.slice(1);
  return false;
}

const byId = {};
const documentListeners = {};
const windowListeners = {};
let reloadCount = 0;
const reports = [];
const unhandled = [];

const documentElement = el('html');
const body = el('body');
body.style.top = '';
documentElement.appendChild(body);

const photoBtn = el('noe-arrival');
photoBtn.classList.add('photo-complete-btn');
photoBtn.setAttribute('data-driver-id', '1');
photoBtn.setAttribute('data-driver-name', 'Alex');
photoBtn.setAttribute('data-customer-id', '9');
photoBtn.setAttribute('data-daily-order-id', '42');
photoBtn.setAttribute('data-customer-name', 'Noe Cafe');
photoBtn.setAttribute('data-date', '2026-10-14');
photoBtn.setAttribute('data-start-step', 'photo');
body.appendChild(photoBtn);

const document = {
  documentElement: documentElement,
  body: body,
  readyState: 'complete',
  visibilityState: 'visible',
  activeElement: body,
  getElementById: function (id) {
    return Object.prototype.hasOwnProperty.call(byId, id) ? byId[id] : null;
  },
  createElement: function () { return el('created'); },
  addEventListener: function (type, fn) {
    (documentListeners[type] = documentListeners[type] || []).push(fn);
  },
  removeEventListener: function () {},
  querySelector: function (selector) {
    const panel = /\[data-step-panel="([^"]+)"\]/.exec(selector || '');
    if (panel) return register('panel-' + panel[1]);
    const step = /\.delivery-wizard-step\[data-step="([^"]+)"\]/.exec(selector || '');
    if (step) return register('wizard-step-' + step[1]);
    return null;
  },
  querySelectorAll: function (selector) {
    if (selector === '.photo-complete-btn') return [photoBtn];
    return [];
  }
};

function register(id) {
  if (!byId[id]) byId[id] = el(id);
  return byId[id];
}

[
  'deliveryPhotoModal',
  'deliveryPhotoModalClose',
  'deliveryCaptureBtn',
  'deliveryRetakeBtn',
  'deliveryWizardPrimaryBtn',
  'deliveryWizardBackBtn',
  'deliveryWizardSkipBtn',
  'deliverySkipPhotoInlineBtn',
  'deliveryWizardActions',
  'deliveryVarianceCancelBtn',
  'deliveryVarianceOkBtn',
  'deliveryInvoiceBackBtn',
  'deliveryInvoiceConfirmBtn',
  'deliveryInvoiceEditSavedBtn',
  'deliveryInvoiceActions',
  'deliveryPiecesInput',
  'deliveryCreditsInput',
  'deliveryFileInput',
  'deliveryGalleryInput',
  'deliveryPhotoType',
  'deliveryFilePickerBtn',
  'deliveryGalleryPickerBtn',
  'deliveryReviewActivateCameraBtn',
  'deliveryCameraVideo',
  'routeSuccessToast'
].forEach(register);

const store = {};
const sessionStorage = {
  getItem: function (key) {
    return Object.prototype.hasOwnProperty.call(store, key) ? store[key] : null;
  },
  setItem: function (key, value) { store[key] = String(value); },
  removeItem: function (key) { delete store[key]; }
};

const location = {
  href: 'http://bakery.local/driver.php',
  reload: function () { reloadCount += 1; }
};

const sandbox = {
  console: console,
  setTimeout: setTimeout,
  clearTimeout: clearTimeout,
  setInterval: function () { return 0; },
  clearInterval: function () {},
  Promise: Promise,
  Date: Date,
  Math: Math,
  Number: Number,
  String: String,
  JSON: JSON,
  encodeURIComponent: encodeURIComponent,
  parseInt: parseInt,
  parseFloat: parseFloat,
  isNaN: isNaN,
  Array: Array,
  Object: Object,
  RegExp: RegExp,
  Error: Error,
  document: document,
  navigator: {
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15'
  },
  sessionStorage: sessionStorage,
  location: location,
  confirm: function () { return true; },
  addEventListener: function (type, fn) {
    (windowListeners[type] = windowListeners[type] || []).push(fn);
  },
  removeEventListener: function () {},
  requestAnimationFrame: function (fn) { fn(); return 1; },
  scrollY: 0,
  innerWidth: 390,
  innerHeight: 800,
  scrollTo: function () {},
  URL: { createObjectURL: function () { return 'blob:arrival'; }, revokeObjectURL: function () {} },
  __DRIVER_PAGE_I18N__: {
    arrival_photo_failed: 'Arrival photo could not start. Use Skip photo to continue. Ref :ref',
    skip_photo: 'Skip photo',
    prep_take_confirm: ":name is on :driver's route. Take this stop?",
    prep_added: 'Added :name'
  },
  bakeryReportClientError: function (kind, message, stack, reference) {
    reports.push({ kind: kind, message: message, stack: stack, reference: reference });
  },
  fetch: function () {
    const payload = {
      success: true,
      ok: true,
      csrf_token: 't',
      photos: [],
      ordered_pieces: 4,
      delivered_pieces: 4,
      credits_taken_back: 0,
      order_total: 0,
      items: [],
      pricing_missing: false,
      message: 'Taken'
    };
    return Promise.resolve({
      ok: true,
      status: 200,
      redirected: false,
      url: '',
      json: function () { return Promise.resolve(payload); },
      text: function () { return Promise.resolve(JSON.stringify(payload)); }
    });
  }
};
sandbox.window = sandbox;
sandbox.globalThis = sandbox;
vm.createContext(sandbox);

process.on('unhandledRejection', function (err) {
  unhandled.push(err && err.message ? err.message : String(err));
});

function load(file) {
  vm.runInContext(fs.readFileSync(path.join(root, file), 'utf8'), sandbox, { filename: file });
}

function fireWindow(type, event) {
  (windowListeners[type] || []).forEach(function (fn) { fn(event); });
}

function fireDocumentClick(target) {
  const event = {
    target: target,
    currentTarget: target,
    preventDefault: function () {},
    stopPropagation: function () {}
  };
  (documentListeners.click || []).forEach(function (fn) { fn(event); });
}

async function main() {
  load('includes/driver_route_prep.js');
  const driveListeners = (windowListeners.pageshow || []).length;
  if (driveListeners < 1) fail('drive document did not watch pageshow');
  else pass('drive document watches pageshow before Add / remove stops');

  fireWindow('pageshow', { persisted: true });
  if (reloadCount !== 0) fail('a fresh drive page reloaded with no take-over');
  else pass('pageshow does not reload a drive page that is still current');

  body.classList.add('driver-route-prep');
  const prepRoot = register('routePrepRoot');
  prepRoot.setAttribute('data-driver-id', '1');
  prepRoot.setAttribute('data-date', '2026-10-14');
  const card = el('candidate');
  card.classList.add('route-prep-candidate');
  card.setAttribute('data-customer-id', '8');
  card.setAttribute('data-customer-name', 'Market stop');
  card.setAttribute('data-other-driver', 'Sergio');
  const takeBtn = el('take');
  takeBtn.classList.add('route-prep-candidate-btn');
  takeBtn.setAttribute('data-take', '1');
  card.appendChild(takeBtn);
  load('includes/driver_route_prep.js');

  fireDocumentClick(takeBtn);
  await new Promise(function (resolve) { setTimeout(resolve, 400); });

  const dirtyAt = Number(sessionStorage.getItem('bakeryRouteDirtyAt') || 0);
  if (!(dirtyAt > 0)) fail('take-over did not mark the route dirty');
  else pass('take-over marks the route dirty');
  if (reloadCount !== 1) fail('prep page did not ask to reload after take-over, saw ' + reloadCount);
  else pass('prep page reloads after take-over');

  const beforeBack = reloadCount;
  fireWindow('pageshow', { persisted: true });
  if (reloadCount !== beforeBack) fail('prep pageshow reloaded again and could loop');
  else pass('prep pageshow does not reload on top of its own refresh');

  body.classList.remove('driver-route-prep');
  load('includes/driver_delivery.js');
  const skipsBefore = register('deliveryWizardSkipBtn').hidden;
  const fileClicksBefore = register('deliveryFileInput').clicked;

  (photoBtn.listeners.click || []).forEach(function (fn) {
    fn({
      currentTarget: photoBtn,
      target: photoBtn,
      preventDefault: function () {},
      stopPropagation: function () {}
    });
  });
  await new Promise(function (resolve) { setTimeout(resolve, 30); });

  const modal = register('deliveryPhotoModal');
  if (modal.style.display !== 'flex') fail('arrival photo did not open the modal without a drive reload');
  else pass('arrival photo opens without reloading the drive document');
  if (unhandled.length) fail('arrival photo rejected: ' + unhandled.join('; '));
  else pass('arrival photo has no unhandled rejection');
  if (reports.length) fail('healthy arrival photo reported a client error');
  else pass('healthy arrival photo does not report a client error');
  if (register('deliveryFileInput').clicked !== fileClicksBefore) {
    fail('arrival photo auto-opened the camera input');
  } else pass('arrival photo leaves the camera input for an explicit tap');

  modal.classList.remove = function () {
    throw new Error('stale route node');
  };
  (photoBtn.listeners.click || []).forEach(function (fn) {
    fn({
      currentTarget: photoBtn,
      target: photoBtn,
      preventDefault: function () {},
      stopPropagation: function () {}
    });
  });
  await new Promise(function (resolve) { setTimeout(resolve, 20); });

  const toast = register('routeSuccessToast').textContent || '';
  const refMatch = toast.match(/\bRef ([A-Z0-9]{6})\b/);
  if (!refMatch) fail('failed arrival photo did not show a reference, saw: ' + toast);
  else pass('failed arrival photo shows reference ' + refMatch[1]);
  if (toast.indexOf('Skip photo') === -1) fail('failed arrival photo did not offer Skip photo');
  else pass('failed arrival photo offers Skip photo');
  if (register('deliveryWizardSkipBtn').hidden !== false || register('deliveryWizardSkipBtn').disabled) {
    fail('Skip photo stayed hidden after the failure');
  } else pass('Skip photo is visible after the failure');
  if (reports.length !== 1 || reports[0].reference !== refMatch[1]) {
    fail('client error beacon did not carry the same reference');
  } else pass('client error beacon carries the reference');
  if (unhandled.length) fail('caught arrival failure still rejected: ' + unhandled.join('; '));
  else pass('caught arrival failure stays off the generic shell path');
  if (skipsBefore === true && register('deliverySkipPhotoInlineBtn').hidden) {
    fail('inline skip path stayed hidden');
  }

  const reloadsBeforeRestore = reloadCount;
  fireWindow('pageshow', { persisted: true });
  if (reloadCount <= reloadsBeforeRestore) fail('restored drive page did not reload after take-over');
  else pass('back-forward restore reloads the drive page after take-over');

  if (failures.length) {
    console.log('\n' + failures.length + ' failed');
    process.exit(1);
  }
  console.log('\ntake-over arrival photo: ok');
  process.exit(0);
}

main().catch(function (err) {
  fail(err && err.stack ? err.stack : String(err));
  process.exit(1);
});
