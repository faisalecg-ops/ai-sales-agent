/* Event Blog — small page script: menu, slider, countdown, banner rotation,
   view counting, reveal-on-scroll, copy link. No libraries. */
(function () {
  'use strict';
  var doc = document.documentElement;
  doc.classList.add('js');
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var cfg = window.BLOG || {};

  // Mobile menu
  var burger = document.querySelector('.burger');
  var nav = document.getElementById('site-nav');
  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Hero slider
  var slider = document.querySelector('[data-slider]');
  if (slider) {
    var slides = slider.querySelectorAll('.slide');
    var dots = slider.querySelectorAll('[data-dot]');
    var idx = 0, timer = null, paused = reduce;
    var speed = Math.max(3, parseInt(slider.getAttribute('data-speed') || '6', 10)) * 1000;
    var pauseBtn = slider.querySelector('[data-pause]');
    var go = function (n) {
      if (slides.length < 2) return;
      var prev = idx;
      idx = (n + slides.length) % slides.length;
      slides.forEach(function (s, i) {
        s.classList.toggle('is-active', i === idx);
        s.classList.toggle('is-prev', i === prev && i !== idx);
        s.setAttribute('aria-hidden', i === idx ? 'false' : 'true');
        s.querySelectorAll('a').forEach(function (a) { a.tabIndex = i === idx ? 0 : -1; });
      });
      dots.forEach(function (d, i) { d.classList.toggle('is-active', i === idx); });
    };
    var start = function () { stop(); if (!paused && slides.length > 1) timer = setInterval(function () { go(idx + 1); }, speed); };
    var stop = function () { if (timer) clearInterval(timer); timer = null; };
    var n = slider.querySelector('[data-next]'), p = slider.querySelector('[data-prev]');
    if (n) n.addEventListener('click', function () { go(idx + 1); start(); });
    if (p) p.addEventListener('click', function () { go(idx - 1); start(); });
    dots.forEach(function (d) { d.addEventListener('click', function () { go(parseInt(d.getAttribute('data-dot'), 10)); start(); }); });
    if (pauseBtn) {
      var showPause = function () {
        pauseBtn.setAttribute('aria-label', paused ? 'Play slides' : 'Pause slides');
        pauseBtn.innerHTML = paused
          ? '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M8 5l11 7-11 7z" fill="currentColor"/></svg>'
          : '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M8 5v14M16 5v14" stroke="currentColor" stroke-width="2.5"/></svg>';
      };
      showPause();
      pauseBtn.addEventListener('click', function () { paused = !paused; showPause(); paused ? stop() : start(); });
    }
    slider.addEventListener('mouseenter', stop);
    slider.addEventListener('mouseleave', start);
    slider.addEventListener('focusin', stop);
    slider.addEventListener('focusout', start);
    // Swipe on phones
    var x0 = null;
    slider.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    slider.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0;
      if (Math.abs(dx) > 40) { go(idx + (dx < 0 ? 1 : -1)); start(); }
      x0 = null;
    });
    start();
  }

  // Countdown to the show
  document.querySelectorAll('[data-countdown]').forEach(function (el) {
    var target = parseInt(el.getAttribute('data-countdown'), 10) * 1000;
    var parts = { d: el.querySelector('[data-d]'), h: el.querySelector('[data-h]'), m: el.querySelector('[data-m]'), s: el.querySelector('[data-s]') };
    var tick = function () {
      var left = Math.max(0, target - Date.now());
      if (left === 0) {
        el.querySelectorAll('.countdown__cell').forEach(function (c) { c.hidden = true; });
        var live = el.querySelector('.countdown__live'); if (live) live.hidden = false;
        return;
      }
      var s = Math.floor(left / 1000);
      parts.d.textContent = Math.floor(s / 86400);
      parts.h.textContent = String(Math.floor(s % 86400 / 3600)).padStart(2, '0');
      parts.m.textContent = String(Math.floor(s % 3600 / 60)).padStart(2, '0');
      parts.s.textContent = String(s % 60).padStart(2, '0');
      setTimeout(tick, 1000);
    };
    tick();
  });

  // Sponsor banners: show one of the banners in each position at random,
  // and count which ones were actually seen.
  var seen = [];
  document.querySelectorAll('[data-rotate]').forEach(function (box) {
    var items = box.querySelectorAll('.sponsor__item');
    if (!items.length) return;
    var pick = Math.floor(Math.random() * items.length);
    items.forEach(function (it, i) { it.hidden = i !== pick; });
    seen.push(parseInt(items[pick].getAttribute('data-banner'), 10));
  });

  // One small message to count the article view and banner views.
  var send = function () {
    var data = {};
    if (cfg.article) data.a = cfg.article;
    if (seen.length) data.b = seen;
    if (!cfg.beacon || (!data.a && !data.b)) return;
    var body = JSON.stringify(data);
    if (navigator.sendBeacon) {
      navigator.sendBeacon(cfg.beacon, new Blob([body], { type: 'application/json' }));
    } else {
      fetch(cfg.beacon, { method: 'POST', body: body, headers: { 'Content-Type': 'application/json' }, keepalive: true }).catch(function () {});
    }
  };
  setTimeout(send, 1500);

  // Reveal on scroll
  var reveals = document.querySelectorAll('.reveal');
  if (!reduce && 'IntersectionObserver' in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -40px 0px', threshold: 0.05 });
    reveals.forEach(function (el) { io.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add('is-in'); });
  }

  // Back to top
  var top = document.querySelector('.totop');
  if (top) {
    var onScroll = function () { top.classList.toggle('is-shown', window.scrollY > 700); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  // Copy link
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var u = b.getAttribute('data-copy');
      var done = function () { var t = b.textContent; b.textContent = cfg.copied || 'Copied'; setTimeout(function () { b.textContent = t; }, 1800); };
      if (navigator.clipboard) navigator.clipboard.writeText(u).then(done, done); else { window.prompt('', u); }
    });
  });
})();
