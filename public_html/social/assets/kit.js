(function () {
  'use strict';
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.querySelector(b.getAttribute('data-copy'));
      if (!el) { return; }
      el.select();
      var done = function () { var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1600); };
      if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(el.value).then(done); } else { try { document.execCommand('copy'); done(); } catch (e) {} }
    });
  });
})();
