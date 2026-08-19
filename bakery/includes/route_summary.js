(function () {
  var lightbox = document.getElementById('rsLightbox');
  if (!lightbox) {
    return;
  }

  var image = document.getElementById('rsLightboxImage');
  var title = document.getElementById('rsLightboxTitle');
  var meta = document.getElementById('rsLightboxMeta');
  var prevBtn = document.getElementById('rsLightboxPrev');
  var nextBtn = document.getElementById('rsLightboxNext');
  var photos = [];
  var index = 0;
  var customer = '';

  function show(i) {
    if (!photos.length) {
      return;
    }
    index = (i + photos.length) % photos.length;
    var photo = photos[index];
    image.onerror = function () {
      if (photo.fallback_url && image.src !== photo.fallback_url) {
        image.src = photo.fallback_url;
      }
    };
    image.src = photo.url || photo.fallback_url || '';
    image.alt = photo.photo_type || customer;
    title.textContent = customer;
    meta.textContent = [photo.photo_type, photo.created_at, (index + 1) + ' / ' + photos.length]
      .filter(Boolean)
      .join(' · ');
    var many = photos.length > 1;
    prevBtn.hidden = !many;
    nextBtn.hidden = !many;
  }

  function openFromButton(button) {
    try {
      photos = JSON.parse(button.getAttribute('data-photos') || '[]');
    } catch (error) {
      photos = [];
    }
    if (!photos.length) {
      return;
    }
    customer = button.getAttribute('data-customer') || '';
    lightbox.hidden = false;
    document.body.style.overflow = 'hidden';
    show(0);
  }

  function closeLightbox() {
    lightbox.hidden = true;
    document.body.style.overflow = '';
    photos = [];
  }

  document.querySelectorAll('.rs-card__photo').forEach(function (button) {
    button.addEventListener('click', function () {
      openFromButton(button);
    });
  });

  lightbox.querySelectorAll('[data-rs-close]').forEach(function (el) {
    el.addEventListener('click', closeLightbox);
  });
  prevBtn.addEventListener('click', function () { show(index - 1); });
  nextBtn.addEventListener('click', function () { show(index + 1); });

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
})();
