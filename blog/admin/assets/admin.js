/* Event Blog — admin script. No libraries. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var A = window.ADMIN || {};
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

  // ---- Menu on phones
  var navBtn = $('[data-navtoggle]');
  if (navBtn) navBtn.addEventListener('click', function () { $('#anav').classList.toggle('is-open'); });

  // ---- Upload helper (one file at a time, so big batches never hit hosting limits)
  function uploadFiles(files, statusEl, done) {
    files = Array.prototype.slice.call(files);
    var results = [], i = 0;
    var next = function () {
      if (i >= files.length) { if (statusEl) statusEl.textContent = results.length ? results.length + ' image(s) uploaded.' : statusEl.textContent; done && done(results); return; }
      var f = files[i++];
      if (statusEl) statusEl.textContent = 'Uploading ' + f.name + ' (' + i + ' of ' + files.length + ')…';
      var fd = new FormData(); fd.append('file', f); fd.append('_csrf', A.csrf);
      fetch(A.upload, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        if (j.ok) results.push(j.item); else if (statusEl) { statusEl.textContent = f.name + ': ' + j.error; }
        j.ok ? next() : setTimeout(next, 1200);
      }).catch(function () { if (statusEl) statusEl.textContent = f.name + ': upload failed.'; setTimeout(next, 800); });
    };
    next();
  }

  // ---- Media page drop zone
  var dz = $('[data-dropzone]');
  if (dz) {
    var st = $('[data-uploadstatus]', dz);
    var go = function (files) { uploadFiles(files, st, function (res) { if (res.length) location.reload(); }); };
    $('[data-upload]', dz).addEventListener('change', function (e) { go(e.target.files); });
    ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('is-over'); }); });
    dz.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) go(e.dataTransfer.files); });
  }

  // ---- Image picker
  var picker = $('[data-picker]'), pickCb = null, pickPage = 1, pickQ = '';
  function pickLoad(reset) {
    if (reset) { pickPage = 1; $('[data-pickgrid]').innerHTML = ''; }
    fetch(A.media + '&page=' + pickPage + '&q=' + encodeURIComponent(pickQ), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      var grid = $('[data-pickgrid]');
      j.items.forEach(function (it) {
        var b = document.createElement('button'); b.type = 'button'; b.title = it.name;
        b.innerHTML = '<img src="' + esc(it.thumb) + '" alt="' + esc(it.alt) + '" loading="lazy">';
        b.addEventListener('click', function () { picker.close(); pickCb && pickCb(it); });
        grid.appendChild(b);
      });
      if (!j.items.length && pickPage === 1) grid.innerHTML = '<p class="muted">No images yet. Upload one.</p>';
      $('[data-pickmore]').hidden = !j.more;
    });
  }
  function openPicker(cb) {
    if (!picker) return;
    pickCb = cb; pickQ = ''; $('[data-pickq]').value = ''; $('[data-pickstatus]').textContent = '';
    picker.showModal(); pickLoad(true);
  }
  window.openPicker = openPicker;
  if (picker) {
    $('[data-pickclose]').addEventListener('click', function () { picker.close(); });
    $('[data-pickmore]').addEventListener('click', function () { pickPage++; pickLoad(false); });
    var t; $('[data-pickq]').addEventListener('input', function (e) { clearTimeout(t); t = setTimeout(function () { pickQ = e.target.value; pickLoad(true); }, 300); });
    $('[data-pickupload]').addEventListener('change', function (e) {
      uploadFiles(e.target.files, $('[data-pickstatus]'), function (res) {
        if (res.length === 1) { picker.close(); pickCb && pickCb(res[0]); } else pickLoad(true);
      });
    });
  }
  $$('[data-mediafield]').forEach(function (f) {
    var input = $('input[type=hidden]', f), prev = $('.mediafield__preview', f), clear = $('[data-clear]', f);
    $('[data-pick]', f).addEventListener('click', function () {
      openPicker(function (it) { input.value = it.id; prev.innerHTML = '<img src="' + esc(it.thumb) + '" alt="">'; clear.hidden = false; input.dispatchEvent(new Event('change', { bubbles: true })); });
    });
    clear.addEventListener('click', function () { input.value = ''; prev.innerHTML = '<span>No image</span>'; clear.hidden = true; });
  });

  // ---- Character counters
  $$('[data-count]').forEach(function (el) {
    var max = parseInt(el.getAttribute('data-count'), 10);
    var lab = el.closest('.field') && $('label', el.closest('.field'));
    if (!lab) return;
    var c = document.createElement('span'); c.className = 'counter'; lab.appendChild(c);
    var upd = function () { c.textContent = el.value.length + ' / ' + max; c.classList.toggle('is-over', el.value.length > max); };
    el.addEventListener('input', upd); upd();
  });

  // ---- Copy buttons
  $$('[data-copybtn]').forEach(function (b) {
    b.addEventListener('click', function () {
      var txt = $('[data-copytext]').textContent.trim();
      navigator.clipboard ? navigator.clipboard.writeText(txt).then(function () { b.textContent = 'Copied ✓'; }) : window.prompt('Copy:', txt);
    });
  });

  // ---- Sortable lists (home page sections)
  $$('[data-sortable]').forEach(function (list) {
    var renumber = function () { $$('[data-order]', list).forEach(function (inp, i) { inp.value = i; }); };
    var drag = null;
    list.addEventListener('dragstart', function (e) { drag = e.target.closest('li'); drag.classList.add('is-drag'); e.dataTransfer.effectAllowed = 'move'; });
    list.addEventListener('dragend', function () { if (drag) drag.classList.remove('is-drag'); drag = null; renumber(); });
    list.addEventListener('dragover', function (e) {
      e.preventDefault(); var over = e.target.closest('li'); if (!drag || !over || over === drag) return;
      var r = over.getBoundingClientRect(); list.insertBefore(drag, (e.clientY - r.top) > r.height / 2 ? over.nextSibling : over);
    });
    list.addEventListener('click', function (e) {
      var li = e.target.closest('li'); if (!li) return;
      if (e.target.closest('[data-up]') && li.previousElementSibling) { list.insertBefore(li, li.previousElementSibling); renumber(); }
      if (e.target.closest('[data-down]') && li.nextElementSibling) { list.insertBefore(li.nextElementSibling, li); renumber(); }
    });
  });

  // ---- Menu rows
  $$('[data-menurows]').forEach(function (list) {
    var prefix = list.getAttribute('data-menurows');
    var addRow = function (label, url) {
      var tpl = list.lastElementChild.cloneNode(true);
      $$('input', tpl).forEach(function (i) { i.value = ''; });
      var empty = $$('li', list).filter(function (li) { return !$$('input', li)[0].value && !$$('input', li)[1].value; })[0];
      var row = empty || tpl;
      $$('input', row)[0].value = label || ''; $$('input', row)[1].value = url || '';
      if (!empty) list.appendChild(row);
      if (!label) $$('input', row)[0].focus();
    };
    list.addEventListener('click', function (e) {
      var li = e.target.closest('li'); if (!li) return;
      if (e.target.closest('[data-rowdel]')) { if ($$('li', list).length > 1) li.remove(); else $$('input', li).forEach(function (i) { i.value = ''; }); }
      if (e.target.closest('[data-rowup]') && li.previousElementSibling) list.insertBefore(li, li.previousElementSibling);
    });
    var addBtn = $('[data-rowadd="' + prefix + '"]'); if (addBtn) addBtn.addEventListener('click', function () { addRow('', ''); });
    var q = $('[data-quickadd="' + prefix + '"]');
    if (q) q.addEventListener('change', function () { var o = q.selectedOptions[0]; if (o && o.value) addRow(o.getAttribute('data-label'), o.value); q.value = ''; });
  });

  // ---- Article editor -------------------------------------------------
  var form = $('#articleForm');
  if (form) {
    var area = $('[data-area]', form), body = $('[data-body]', form), src = $('[data-source]', form), bar = $('.wp__bar', form);
    var sourceMode = false, dirty = false, lastReview = null;
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
    var sync = function () { body.value = sourceMode ? src.value : area.innerHTML; };
    var words = function () { var n = (area.innerText || '').trim().split(/\s+/).filter(Boolean).length; $('[data-wordcount]').textContent = n + ' words · about ' + Math.max(1, Math.round(n / 220)) + ' min read'; };
    words();
    area.addEventListener('input', function () { dirty = true; words(); });
    $$('input,textarea,select', form).forEach(function (el) { el.addEventListener('change', function () { dirty = true; }); });
    form.addEventListener('submit', function (e) {
      sync(); dirty = false;
      var btn = e.submitter;
      if (btn && btn.hasAttribute('data-confirm-links') && lastReview && lastReview.flagged > 0) {
        if (!confirm(lastReview.flagged + ' link(s) are not from the link library, this blog, or a declared source. Publish anyway?')) { e.preventDefault(); dirty = true; }
      }
    });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

    var keepSel = null;
    var saveSel = function () { var s = window.getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) keepSel = s.getRangeAt(0).cloneRange(); };
    var restoreSel = function () { area.focus(); if (keepSel) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(keepSel); } };
    area.addEventListener('keyup', saveSel); area.addEventListener('mouseup', saveSel); area.addEventListener('blur', saveSel);
    var insertHTML = function (html) { restoreSel(); document.execCommand('insertHTML', false, html); dirty = true; words(); };
    var cmd = function (c, v) { restoreSel(); document.execCommand(c, false, v || null); dirty = true; };

    // Paste: keep structure (headings, lists, links, bold), drop Word/Google Docs styling.
    area.addEventListener('paste', function (e) {
      var html = e.clipboardData.getData('text/html');
      if (!html) return;
      e.preventDefault();
      var d = new DOMParser().parseFromString(html, 'text/html');
      $$('style,script,meta,link,o\\:p', d).forEach(function (n) { n.remove(); });
      $$('*', d.body).forEach(function (n) {
        Array.prototype.slice.call(n.attributes).forEach(function (a) { if (['href', 'src', 'alt'].indexOf(a.name) < 0) n.removeAttribute(a.name); });
        if (/^(SPAN|FONT|DIV|SECTION|ARTICLE)$/.test(n.tagName)) { while (n.firstChild) n.parentNode.insertBefore(n.firstChild, n); n.remove(); }
      });
      document.execCommand('insertHTML', false, d.body.innerHTML);
    });

    var linkDialog = function () {
      saveSel();
      var sel = window.getSelection().toString();
      var lib = (window.LINKLIB || []).map(function (l, i) { return (i + 1) + '. ' + l.label + ' — ' + l.url; }).join('\n');
      var ans = window.prompt('Link address (https://…)' + (lib ? '\nOr type the number of a link-library address:\n' + lib : ''), '');
      if (!ans) return;
      ans = ans.trim();
      if (/^\d+$/.test(ans) && window.LINKLIB && window.LINKLIB[+ans - 1]) ans = window.LINKLIB[+ans - 1].url;
      if (!/^https?:\/\//i.test(ans) && !/^mailto:/i.test(ans)) { alert('Please give a full address starting with https://'); return; }
      if (sel) cmd('createLink', ans); else insertHTML('<a href="' + esc(ans) + '">' + esc(ans) + '</a>');
    };
    var video = function () {
      saveSel();
      var u = window.prompt('YouTube or Vimeo address:'); if (!u) return;
      var m = u.match(/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/)|youtu\.be\/)([\w-]{11})/), emb = null;
      if (m) emb = 'https://www.youtube-nocookie.com/embed/' + m[1];
      var v = u.match(/vimeo\.com\/(\d+)/); if (v) emb = 'https://player.vimeo.com/video/' + v[1];
      if (!emb) { alert('That does not look like a YouTube or Vimeo address.'); return; }
      insertHTML('<div class="video"><iframe src="' + emb + '" title="Video" allowfullscreen></iframe></div><p><br></p>');
    };
    var table = function () {
      var r = parseInt(window.prompt('How many rows (including the heading row)?', '3'), 10), c = parseInt(window.prompt('How many columns?', '3'), 10);
      if (!(r > 0 && c > 0 && r < 30 && c < 10)) return;
      var h = '<table><thead><tr>' + new Array(c + 1).join('<th>Heading</th>') + '</tr></thead><tbody>';
      for (var i = 1; i < r; i++) h += '<tr>' + new Array(c + 1).join('<td>&nbsp;</td>') + '</tr>';
      insertHTML(h + '</tbody></table><p><br></p>');
    };
    bar.addEventListener('mousedown', function (e) { if (e.target.closest('button')) e.preventDefault(); });
    bar.addEventListener('click', function (e) {
      var b = e.target.closest('[data-cmd]'); if (!b) return;
      var c = b.getAttribute('data-cmd');
      if (c === 'source') {
        sourceMode = !sourceMode;
        if (sourceMode) { src.value = area.innerHTML; src.hidden = false; area.hidden = true; b.classList.add('is-on'); }
        else { area.innerHTML = src.value; src.hidden = true; area.hidden = false; b.classList.remove('is-on'); words(); }
        return;
      }
      if (sourceMode) return;
      if (c === 'link') return linkDialog();
      if (c === 'video') return video();
      if (c === 'table') return table();
      if (c === 'hr') return insertHTML('<hr><p><br></p>');
      if (c === 'quote') return cmd('formatBlock', 'blockquote');
      if (c === 'image') {
        saveSel();
        return openPicker(function (it) {
          var cap = window.prompt('Caption under the image (optional):', it.caption || '') || '';
          insertHTML('<figure><img src="' + esc(it.url) + '" alt="' + esc(it.alt) + '" data-media-id="' + it.id + '" width="' + it.w + '" height="' + it.h + '">' + (cap ? '<figcaption>' + esc(cap) + '</figcaption>' : '') + '</figure><p><br></p>');
        });
      }
      cmd(c);
    });
    var blockSel = $('[data-block]', bar);
    blockSel.addEventListener('change', function () { cmd('formatBlock', blockSel.value); blockSel.value = 'p'; });

    // Google preview
    var gT = $('[data-gtitle]'), gD = $('[data-gdesc]'), gU = $('[data-gurl]');
    var title = $('#f_title'), seoT = $('#f_seo_title'), ex = $('#f_excerpt'), md = $('#f_meta_description'), slug = $('#f_slug');
    var site = document.title.split(' — ').pop().replace(' admin', '');
    var gp = function () {
      var t = (seoT.value || title.value || 'Article title');
      gT.textContent = (t.length > 60 ? t.slice(0, 58) + '…' : t) + ' | ' + site;
      var d = md.value || ex.value || (area.innerText || '').trim().slice(0, 160);
      gD.textContent = d.length > 158 ? d.slice(0, 155) + '…' : d;
    };
    [title, seoT, ex, md].forEach(function (el) { el && el.addEventListener('input', gp); }); gp();
    if (slug && form.getAttribute('data-article') === '0') {
      title.addEventListener('input', function () {
        if (!slug.value) gU.textContent = gU.textContent.replace(/[^\/]*$/, title.value.toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 80));
      });
    }

    // Link check and suggestions
    var panel = $('[data-linkpanel]');
    var kindLabel = { library: ['Link library', 'ok'], article: ['This blog', 'ok'], source: ['Declared source', 'ok'], blog_page: ['Blog page — check', 'meh'], event_site: ['Event site, not in library', 'meh'], unknown: ['Not approved — check', 'bad'] };
    var checkLinks = function () {
      sync();
      var fd = new FormData(); fd.append('_csrf', A.csrf); fd.append('body', body.value); fd.append('category_id', ($('#f_category_id') || {}).value || '');
      $('[data-linklist]', panel).innerHTML = '<p class="muted small">Checking…</p>';
      fetch(form.getAttribute('data-ajax'), { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
        var rv = j.review; lastReview = rv;
        var h = '';
        (rv.warnings || []).forEach(function (w) { h += '<div class="flash flash--warn small">' + esc(w) + '</div>'; });
        if (!rv.links.length) h += '<p class="muted small">No links yet.</p>';
        rv.links.forEach(function (l) {
          var k = kindLabel[l.kind] || ['?', 'meh'];
          h += '<div class="linkrow"><span class="kind kind--' + k[1] + '">' + esc(k[0]) + '</span><div><strong>' + esc(l.text || '(no text)') + '</strong>' + (l.label ? ' — ' + esc(l.label) : '') +
            (l.sponsored ? ' <span class="tag">Sponsored</span>' : '') + (l.external ? ' <span class="tag">New tab</span>' : '') + '<div class="linkrow__url">' + esc(l.url) + '</div></div></div>';
        });
        $('[data-linklist]', panel).innerHTML = h;
        var s = $('[data-suggest]', panel);
        if (j.suggest && j.suggest.length) {
          s.innerHTML = '<div class="suggest"><p class="small"><strong>Suggested links</strong> — select a word in the text, then click a suggestion to link it:</p></div>';
          j.suggest.forEach(function (sg) {
            var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn--small btn--ghost';
            b.textContent = (sg.kind === 'article' ? '📰 ' : '🔗 ') + sg.label + (sg.phrase ? ' (“' + sg.phrase + '”)' : '');
            b.addEventListener('mousedown', function (e) { e.preventDefault(); });
            b.addEventListener('click', function () {
              restoreSel(); var sel = window.getSelection().toString();
              if (!sel) { alert('First select the words in the article that should become the link.'); return; }
              cmd('createLink', sg.url); checkLinks();
            });
            $('.suggest', s).appendChild(b);
          });
        } else s.innerHTML = '';
      }).catch(function () { $('[data-linklist]', panel).innerHTML = '<p class="small">Could not check links. Save, then try again.</p>'; });
    };
    $('[data-checklinks]').addEventListener('click', checkLinks);
    if (area.innerText.trim().length > 20) setTimeout(checkLinks, 400);
  }

  // ---- Branding live preview -----------------------------------------
  var bf = $('[data-branding]');
  if (bf) {
    var frame = $('[data-preview]');
    var fonts = window.FONTS || {};
    var doc = function () { try { return frame.contentDocument; } catch (e) { return null; } };
    var setVar = function (k, v) { var d = doc(); if (d) d.documentElement.style.setProperty(k, v); };
    var loadFont = function (key) {
      var d = doc(), f = fonts[key]; if (!d || !f || d.getElementById('pf-' + key)) return;
      var st = d.createElement('style'); st.id = 'pf-' + key;
      st.textContent = f.files.map(function (w) { return "@font-face{font-family:'" + f.family + "';font-weight:" + w[0] + ";" + (f.variable ? "font-stretch:62% 125%;" : "") + "font-display:swap;src:url(" + w[1] + ") format('woff2')}"; }).join('');
      d.head.appendChild(st);
    };
    var lum = function (hex) { var c = [1, 3, 5].map(function (i) { var v = parseInt(hex.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
    var ratio = function (a, b) { if (!/^#[0-9a-f]{6}$/i.test(a) || !/^#[0-9a-f]{6}$/i.test(b)) return 21; var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
    var val = function (k) { return ($('[data-hex="' + k + '"]') || {}).value || ''; };
    var contrast = function () {
      var checks = [['text', 'background', 'Body text on the background', 4.5], ['muted', 'background', 'Grey text on the background', 4.5], ['primary', 'background', 'Main colour on the background', 3],
        ['#FFFFFF', 'secondary', 'White text on the second colour (buttons, dark areas)', 4.5], ['highlight_ink', 'highlight', 'Text on the highlight colour (call-to-action buttons)', 4.5]];
      var out = '';
      checks.forEach(function (c) { var r = ratio(c[0][0] === '#' ? c[0] : val(c[0]), val(c[1])); if (r < c[3]) out += '<p>⚠ ' + c[2] + ' is hard to read (' + r.toFixed(1) + ':1; needs ' + c[3] + ':1).</p>'; });
      $('[data-contrast]').innerHTML = out || '<p class="ok">✓ All colour pairs are easy to read.</p>';
    };
    var map = { primary: '--primary', secondary: '--secondary', accent: '--accent', highlight: '--highlight', highlight_ink: '--highlight-ink', background: '--bg', surface: '--surface', text: '--text', muted: '--muted' };
    var applyAll = function () {
      Object.keys(map).forEach(function (k) { setVar(map[k], val(k)); });
      ['head', 'body'].forEach(function (w) { var s = $('[data-font="' + w + '"]'); if (s && fonts[s.value]) { loadFont(s.value); setVar('--font-' + w, fonts[s.value].stack);
        if (w === 'head') { setVar('--head-stretch', fonts[s.value].stretch || '100%'); setVar('--head-weight', fonts[s.value].weight || 700); } } });
      var shape = ($('[data-var="btn"]') || {}).value; setVar('--btn-radius', shape === 'pill' ? '999px' : shape === 'rounded' ? '6px' : '0px');
      var rad = $('[data-var="radius"]'); if (rad) setVar('--radius', rad.value + 'px');
      var sh = $('[data-var="shade"]'); if (sh) setVar('--shade', sh.value / 100);
      var d = doc();
      if (d && d.body) {
        var hdr = $('[data-bodyclass="hdr"]').value, cards = $('[data-bodyclass="cards"]').value;
        d.body.className = d.body.className.replace(/\bhdr-\w+\b/g, '').replace(/\bcards-\w+\b/g, '') + ' hdr-' + hdr + ' cards-' + cards;
        if (hdr === 'transparent' && d.querySelector('.slider')) d.body.classList.add('hdr-over'); else d.body.classList.remove('hdr-over');
        var band = $('input[type=checkbox][name="band"]'); if (band) d.body.classList.toggle('has-band', band.checked);
      }
      contrast();
    };
    $$('[data-colour]').forEach(function (c) {
      var k = c.getAttribute('data-colour'), hex = $('[data-hex="' + k + '"]');
      c.addEventListener('input', function () { hex.value = c.value.toUpperCase(); $('[data-presetname]').value = 'custom'; applyAll(); });
      hex.addEventListener('input', function () { if (/^#[0-9a-f]{6}$/i.test(hex.value)) { c.value = hex.value; applyAll(); } });
    });
    $$('[data-preset]').forEach(function (b) {
      b.addEventListener('click', function () {
        var p = JSON.parse(b.getAttribute('data-preset'));
        Object.keys(map).forEach(function (k) { if (p[k]) { $('[data-hex="' + k + '"]').value = p[k]; $('[data-colour="' + k + '"]').value = p[k]; } });
        $('[data-presetname]').value = b.getAttribute('data-key'); applyAll();
      });
    });
    $$('select,input[type=range],input[type=checkbox]', bf).forEach(function (s) { s.addEventListener('input', applyAll); s.addEventListener('change', applyAll); });
    var fit = function () {
      var box = $('.preview-frame'), w = parseInt(frame.getAttribute('data-w') || '1280', 10);
      frame.style.width = w + 'px';
      var sc = Math.min(1, (box.clientWidth - 20) / w); box.style.setProperty('--pscale', sc);
    };
    $$('[data-pw]').forEach(function (b) { b.addEventListener('click', function () { $$('[data-pw]').forEach(function (x) { x.classList.remove('is-active'); }); b.classList.add('is-active'); frame.setAttribute('data-w', b.getAttribute('data-pw')); fit(); }); });
    window.addEventListener('resize', fit); fit();
    frame.addEventListener('load', function () { var d = doc(); if (d) { d.querySelectorAll('.reveal,.band').forEach(function (e) { e.classList.add('is-in'); }); d.documentElement.classList.remove('anim'); } applyAll(); });
    contrast();
  }
})();
