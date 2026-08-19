(function () {
  var btn = document.getElementById('portalMoreBtn');
  var sheet = document.getElementById('portalMoreSheet');
  var backdrop = document.getElementById('portalSheetBackdrop');
  if (!btn || !sheet || !backdrop) return;

  function openSheet() {
    sheet.hidden = false;
    backdrop.hidden = false;
    requestAnimationFrame(function () {
      sheet.classList.add('open');
      backdrop.classList.add('open');
    });
    btn.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }

  function closeSheet() {
    sheet.classList.remove('open');
    backdrop.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    window.setTimeout(function () {
      if (!sheet.classList.contains('open')) {
        sheet.hidden = true;
        backdrop.hidden = true;
      }
    }, 260);
  }

  btn.addEventListener('click', function () {
    if (sheet.classList.contains('open')) {
      closeSheet();
    } else {
      openSheet();
    }
  });

  backdrop.addEventListener('click', closeSheet);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeSheet();
  });
})();
