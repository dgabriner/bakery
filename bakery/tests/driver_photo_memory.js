/**
 * Android delivery photos must be measured and capped before a full decode.
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

function extractFunction(source, name) {
  const start = source.indexOf('function ' + name + '(');
  if (start < 0) {
    throw new Error('missing function ' + name);
  }
  const brace = source.indexOf('{', start);
  let depth = 0;
  for (let i = brace; i < source.length; i++) {
    const ch = source[i];
    if (ch === '{') depth++;
    else if (ch === '}') {
      depth--;
      if (depth === 0) return source.slice(start, i + 1);
    }
  }
  throw new Error('unclosed function ' + name);
}

function jpegWithSof(width, height) {
  const sof = Buffer.from([
    0xff, 0xc0,
    0x00, 0x0b,
    0x08,
    (height >> 8) & 0xff, height & 0xff,
    (width >> 8) & 0xff, width & 0xff,
    0x01,
    0x01, 0x11, 0x00
  ]);
  return Buffer.concat([Buffer.from([0xff, 0xd8]), sof, Buffer.from([0xff, 0xd9])]);
}

function jpegWithOrientation(width, height, orientation) {
  const exif = Buffer.from([
    0xff, 0xe1,
    0x00, 0x22,
    0x45, 0x78, 0x69, 0x66, 0x00, 0x00,
    0x49, 0x49, 0x2a, 0x00,
    0x08, 0x00, 0x00, 0x00,
    0x01, 0x00,
    0x12, 0x01,
    0x03, 0x00,
    0x01, 0x00, 0x00, 0x00,
    orientation & 0xff, (orientation >> 8) & 0xff, 0x00, 0x00,
    0x00, 0x00, 0x00, 0x00
  ]);
  const body = jpegWithSof(width, height);
  return Buffer.concat([body.subarray(0, 2), exif, body.subarray(2)]);
}

let api;
try {
  const source = fs.readFileSync(path.join(root, 'includes/driver_delivery.js'), 'utf8');
  const names = [
    'isAndroid',
    'isIos',
    'prefersNativeCamera',
    'fitWithinEdge',
    'readExifOrientation',
    'readJpegSize',
    'orientedJpegSize'
  ];
  const extracted = names.map(function (name) {
    return extractFunction(source, name);
  }).join('\n');
  const sandbox = {
    navigator: { userAgent: '', maxTouchPoints: 0 }
  };
  vm.createContext(sandbox);
  vm.runInContext(extracted + '\nthis.api = { isAndroid, isIos, prefersNativeCamera, fitWithinEdge, readJpegSize, orientedJpegSize };', sandbox);
  api = sandbox.api;
} catch (err) {
  fail(err.message);
  process.exit(1);
}

function withNavigator(userAgent, touch, fn) {
  const nav = { userAgent: userAgent, maxTouchPoints: touch };
  const box = { navigator: nav };
  vm.createContext(box);
  const source = fs.readFileSync(path.join(root, 'includes/driver_delivery.js'), 'utf8');
  const extracted = ['isAndroid', 'isIos', 'prefersNativeCamera'].map(function (name) {
    return extractFunction(source, name);
  }).join('\n');
  vm.runInContext(extracted, box);
  return fn(box);
}

const android = withNavigator('Mozilla/5.0 (Linux; Android 13; SM-A146U) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile', 5, function (box) {
  return {
    android: box.isAndroid(),
    nativeCamera: box.prefersNativeCamera()
  };
});
if (android.android !== true || android.nativeCamera !== false) {
  fail('Android must use the in-page camera, got ' + JSON.stringify(android));
} else {
  pass('Android uses the in-page camera');
}

const iphone = withNavigator('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Mobile', 5, function (box) {
  return box.prefersNativeCamera();
});
if (iphone !== true) fail('iPhone should keep the system camera');
else pass('iPhone keeps the system camera');

const landscape = api.readJpegSize(jpegWithSof(4032, 3024));
if (!landscape || landscape.width !== 4032 || landscape.height !== 3024) {
  fail('JPEG size reader missed 4032x3024, got ' + JSON.stringify(landscape));
} else {
  pass('JPEG size reader reads a 12MP frame');
}

const turned = api.orientedJpegSize(landscape, 6);
if (!turned || turned.width !== 3024 || turned.height !== 4032) {
  fail('orientation 6 should swap axes, got ' + JSON.stringify(turned));
} else {
  pass('orientation 6 swaps the stored axes');
}

const fitted = api.fitWithinEdge(turned.width, turned.height, 1600);
if (!fitted || fitted.width !== 1200 || fitted.height !== 1600) {
  fail('portrait 12MP should fit inside 1600, got ' + JSON.stringify(fitted));
} else {
  pass('portrait 12MP fits inside a 1600 edge');
}

const small = api.fitWithinEdge(800, 600, 1600);
if (!small || small.width !== 800 || small.height !== 600) {
  fail('small frames stay unchanged, got ' + JSON.stringify(small));
} else {
  pass('small frames are not upscaled');
}

const exif = api.readJpegSize(jpegWithOrientation(4000, 3000, 6));
if (!exif || exif.orientation !== 6 || exif.width !== 4000 || exif.height !== 3000) {
  fail('EXIF orientation was not read, got ' + JSON.stringify(exif));
} else {
  pass('EXIF orientation 6 is read beside the stored size');
}

if (failures.length) {
  console.log('\n' + failures.length + ' failed');
  process.exit(1);
}
console.log('\nphoto memory helpers passed');
