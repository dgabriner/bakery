/**
 * Sour Flour OS — lightweight shell interactions (no business logic).
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-sf-loading]').forEach(function (form) {
      form.addEventListener('submit', function () {
        form.classList.add('sf-is-loading');
        var submitter = form.querySelector('[type="submit"]');
        if (submitter && !submitter.dataset.sfOriginalText) {
          submitter.dataset.sfOriginalText = submitter.textContent || '';
          submitter.textContent = submitter.dataset.sfLoadingText || (window.__BAKERY_I18N__ && window.__BAKERY_I18N__.saving) || 'Saving…';
          submitter.disabled = true;
        }
      });
    });
  });
})();
