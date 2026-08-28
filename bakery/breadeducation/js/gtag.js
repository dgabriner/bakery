/**
 * Sour Flour Google tag for the Learning Zone (same Site Kit tag as sourflour.org).
 * Destinations behind GT-5MGVGM88: GA4 G-FEZ1KFZKPK, Google Ads AW-987675312.
 * Configure gtag with the Google tag ID only. Do not emit Universal Analytics.
 * Live Learning Zone is bakery.sourflour.org/breadeducation/; skip localhost.
 */
(function () {
  var host = String(window.location && window.location.hostname ? window.location.hostname : '')
    .toLowerCase()
    .replace(/:\d+$/, '');
  if (host.charAt(0) === '[' && host.charAt(host.length - 1) === ']') {
    host = host.slice(1, -1);
  }
  if (!host || host === 'localhost' || host === '127.0.0.1' || host === '::1') {
    return;
  }
  if (host !== 'bakery.sourflour.org' && host !== 'www.bakery.sourflour.org') {
    return;
  }
  window.dataLayer = window.dataLayer || [];
  function gtag() {
    window.dataLayer.push(arguments);
  }
  window.gtag = gtag;
  gtag('js', new Date());
  gtag('config', 'GT-5MGVGM88');
  var el = document.createElement('script');
  el.async = true;
  el.src = 'https://www.googletagmanager.com/gtag/js?id=GT-5MGVGM88';
  var parent = document.head || document.documentElement;
  parent.appendChild(el);
})();
