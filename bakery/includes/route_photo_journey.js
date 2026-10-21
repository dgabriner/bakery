(function () {
  function initMaps() {
    if (typeof L === 'undefined') {
      return [];
    }
    var maps = [];
    document.querySelectorAll('[data-journey-map]').forEach(function (el) {
      var source = document.getElementById(el.getAttribute('data-journey-map') || '');
      if (!source) {
        return;
      }
      var data;
      try {
        data = JSON.parse(source.textContent || '');
      } catch (error) {
        return;
      }
      var map = L.map(el, { scrollWheelZoom: false });
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
      }).addTo(map);
      var bounds = [];
      var line = [];
      var bakery = data.bakery || null;
      if (bakery && bakery.lat && bakery.lng) {
        var bakeryPoint = [bakery.lat, bakery.lng];
        L.marker(bakeryPoint, {
          icon: L.divIcon({
            className: 'journey-pin journey-pin--bakery',
            html: 'P',
            iconSize: [26, 26],
            iconAnchor: [13, 13]
          })
        }).addTo(map);
        bounds.push(bakeryPoint);
        line.push(bakeryPoint);
      }
      (data.stops || []).forEach(function (stop) {
        if (stop.lat == null || stop.lng == null) {
          return;
        }
        var point = [stop.lat, stop.lng];
        var pending = stop.kind === 'pending';
        var label = pending ? '!' : String(stop.n || '');
        var tone = pending ? 'journey-pin--pending' : (stop.badge === 'arrival' ? 'journey-pin--arrival' : 'journey-pin--pod');
        L.marker(point, {
          icon: L.divIcon({
            className: 'journey-pin ' + tone,
            html: label,
            iconSize: [26, 26],
            iconAnchor: [13, 13]
          })
        }).addTo(map);
        bounds.push(point);
        if (!pending) {
          line.push(point);
        }
      });
      if (line.length > 1) {
        L.polyline(line, { color: '#5b3a20', weight: 3, opacity: 0.85 }).addTo(map);
      }
      if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [18, 18] });
      } else if (bounds.length === 1) {
        map.setView(bounds[0], 13);
      } else {
        map.setView([37.7749, -122.4194], 12);
      }
      maps.push(map);
    });
    window.addEventListener('beforeprint', function () {
      maps.forEach(function (map) {
        map.invalidateSize();
      });
    });
    return maps;
  }

  function initLightbox() {
    var lightbox = document.getElementById('journeyLightbox');
    if (!lightbox) {
      return;
    }
    var image = document.getElementById('journeyLightboxImage');
    var title = document.getElementById('journeyLightboxTitle');
    var meta = document.getElementById('journeyLightboxMeta');
    var photos = [];
    var index = 0;
    var store = '';

    function show(next) {
      if (!photos.length) {
        return;
      }
      index = (next + photos.length) % photos.length;
      var photo = photos[index];
      image.src = photo.url || '';
      image.alt = photo.photo_type || store;
      title.textContent = store;
      meta.textContent = [photo.photo_type, photo.created_at, (index + 1) + ' / ' + photos.length]
        .filter(Boolean)
        .join(' | ');
    }

    function openFrom(button) {
      try {
        photos = JSON.parse(button.getAttribute('data-photos') || '[]');
      } catch (error) {
        photos = [];
      }
      if (!photos.length) {
        return;
      }
      store = button.getAttribute('data-store') || '';
      lightbox.hidden = false;
      document.body.style.overflow = 'hidden';
      show(parseInt(button.getAttribute('data-index') || '0', 10) || 0);
    }

    function closeLightbox() {
      lightbox.hidden = true;
      document.body.style.overflow = '';
      image.src = '';
      photos = [];
    }

    document.querySelectorAll('[data-journey-photo]').forEach(function (button) {
      button.addEventListener('click', function () {
        openFrom(button);
      });
    });
    lightbox.querySelectorAll('[data-journey-close]').forEach(function (el) {
      el.addEventListener('click', closeLightbox);
    });
    var prev = document.getElementById('journeyLightboxPrev');
    var nextBtn = document.getElementById('journeyLightboxNext');
    if (prev) {
      prev.addEventListener('click', function () { show(index - 1); });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function () { show(index + 1); });
    }
    document.addEventListener('keydown', function (event) {
      if (lightbox.hidden) {
        return;
      }
      if (event.key === 'Escape') {
        closeLightbox();
      } else if (event.key === 'ArrowLeft') {
        show(index - 1);
      } else if (event.key === 'ArrowRight') {
        show(index + 1);
      }
    });
  }

  initMaps();
  initLightbox();
})();
