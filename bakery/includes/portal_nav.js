(function () {
  function wireMoreSheet(btn, sheet, backdrop) {
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
  }

  // Legacy portal ids
  wireMoreSheet(
    document.getElementById('portalMoreBtn'),
    document.getElementById('portalMoreSheet'),
    document.getElementById('portalSheetBackdrop')
  );

  // SFB / data-attribute variants (and any future shells)
  document.querySelectorAll('[data-more-btn]').forEach(function (btn) {
    if (btn.id === 'portalMoreBtn') return;
    var sheetId = btn.getAttribute('data-more-sheet');
    var backdropId = btn.getAttribute('data-more-backdrop');
    wireMoreSheet(
      btn,
      sheetId ? document.getElementById(sheetId) : null,
      backdropId ? document.getElementById(backdropId) : null
    );
  });
})();
