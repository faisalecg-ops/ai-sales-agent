/* AI Social Media Expert Agent — small behaviours. No inline scripts (the
   sales agent's Content-Security-Policy blocks them). */
(function () {
  'use strict';

  function copy(text, btn) {
    var done = function () {
      var t = btn.textContent;
      btn.textContent = 'Copied';
      setTimeout(function () { btn.textContent = t; }, 1600);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { window.prompt('Copy this:', text); });
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); done(); } catch (e) { window.prompt('Copy this:', text); }
      document.body.removeChild(ta);
    }
  }

  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var el = document.querySelector(b.getAttribute('data-copy'));
      if (el) { copy(el.value || el.textContent, b); }
    });
  });
  document.querySelectorAll('[data-copy-text]').forEach(function (b) {
    b.addEventListener('click', function () { copy(b.getAttribute('data-copy-text'), b); });
  });

  // Buttons that start slow work say so, and cannot be pressed twice.
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[data-busy]');
      if (b) {
        setTimeout(function () { b.disabled = true; b.textContent = b.getAttribute('data-busy'); }, 0);
      }
    });
  });
  // "Write the article" takes a minute or two.
  document.querySelectorAll('form').forEach(function (f) {
    var d = f.querySelector('input[name="do"][value="write"]');
    if (d) {
      f.addEventListener('submit', function () {
        var b = f.querySelector('button');
        setTimeout(function () { if (b) { b.disabled = true; b.textContent = 'Writing… (1–3 minutes)'; } }, 0);
      });
    }
  });

  // Live character count against the platform's limit.
  document.querySelectorAll('textarea[data-count]').forEach(function (ta) {
    var max = parseInt(ta.getAttribute('data-count'), 10);
    var out = document.createElement('p');
    out.className = 'sm-count';
    ta.parentNode.appendChild(out);
    var tick = function () {
      var n = ta.value.length;
      out.textContent = n + ' / ' + max + ' characters (text only; hashtags and link are added)';
      out.classList.toggle('is-over', n > max);
    };
    ta.addEventListener('input', tick);
    tick();
  });

  // Live preview of the article text while editing.
  document.querySelectorAll('textarea[data-preview]').forEach(function (ta) {
    var box = document.querySelector('[data-preview-of="' + ta.id + '"]');
    if (!box) { return; }
    ta.addEventListener('input', function () {
      var doc = new DOMParser().parseFromString(ta.value, 'text/html');
      doc.querySelectorAll('script,style,iframe,object,img,svg').forEach(function (n) { n.remove(); });
      doc.querySelectorAll('*').forEach(function (n) {
        Array.prototype.slice.call(n.attributes).forEach(function (a) { if (a.name !== 'href') { n.removeAttribute(a.name); } });
      });
      box.innerHTML = doc.body.innerHTML;
    });
  });
})();
