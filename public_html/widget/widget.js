/*!
 * AI Sales Agent Platform — embeddable widget
 *
 * Embed with a single tag on the exhibition website:
 *   <script src="https://salesagent.example.net/widget/widget.js" data-agent="buildasia"></script>
 *
 * Notes on the two things that matter most here:
 *
 * 1. Nothing the model produces is ever assigned to innerHTML. The API
 *    returns a structured block list and this file builds DOM nodes,
 *    setting text through textContent only.
 * 2. The widget carries no secret of any kind. The agent slug is a public
 *    lookup key; every decision is made server-side.
 */
(function () {
  'use strict';

  // Bumped whenever this file changes, so the diagnostic page can report
  // which copy is actually live and rule out a stale cached version.
  var WIDGET_VERSION = '1.1.0';

  if (window.__aisaWidgetLoaded) { return; }
  window.__aisaWidgetLoaded = true;
  window.__aisaWidgetVersion = WIDGET_VERSION;

  // ---------------------------------------------------------------------
  // Configuration
  // ---------------------------------------------------------------------
  var script = document.currentScript || (function () {
    var all = document.getElementsByTagName('script');
    for (var i = all.length - 1; i >= 0; i--) {
      if (all[i].src && all[i].src.indexOf('widget.js') !== -1) { return all[i]; }
    }
    return null;
  })();

  if (!script) {
    if (window.console) {
      console.warn('[AI Sales Agent] Could not identify the embed tag; widget not loaded.');
    }
    return;
  }

  var AGENT = script.getAttribute('data-agent') || '';
  if (!AGENT) {
    if (window.console) { console.warn('[AI Sales Agent] data-agent is missing from the embed tag.'); }
    return;
  }

  var BASE = (function () {
    var src = script.src;
    var idx = src.lastIndexOf('/widget/');
    return idx === -1 ? src.replace(/\/[^/]*$/, '') : src.substring(0, idx);
  })();

  var POSITION = (script.getAttribute('data-position') || 'right').toLowerCase();
  var LAUNCH_LABEL = script.getAttribute('data-label') || 'Chat with us';
  var AUTO_OPEN = script.getAttribute('data-auto-open') === 'true';

  var STORAGE_VISITOR = 'aisa_visitor_id';
  var STORAGE_SESSION = 'aisa_session_' + AGENT;

  // ---------------------------------------------------------------------
  // Small helpers
  // ---------------------------------------------------------------------
  function randomId(bytes) {
    var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    var out = '';
    var buf;
    if (window.crypto && window.crypto.getRandomValues) {
      buf = new Uint8Array(bytes);
      window.crypto.getRandomValues(buf);
      for (var i = 0; i < bytes; i++) { out += chars[buf[i] % chars.length]; }
    } else {
      for (var j = 0; j < bytes; j++) {
        out += chars[Math.floor(Math.random() * chars.length)];
      }
    }
    return out;
  }

  function storageGet(key) {
    try { return window.localStorage.getItem(key); } catch (e) { return null; }
  }
  function storageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) { /* private mode */ }
  }
  function sessionGet(key) {
    try { return window.sessionStorage.getItem(key); } catch (e) { return null; }
  }
  function sessionSet(key, value) {
    try { window.sessionStorage.setItem(key, value); } catch (e) { /* private mode */ }
  }

  /**
   * The visitor identifier is generated here and kept in the host page's own
   * localStorage — first-party, so third-party cookie restrictions do not
   * apply. It is sent explicitly with every call.
   */
  function visitorId() {
    var id = storageGet(STORAGE_VISITOR);
    if (!id || id.length < 16) {
      id = 'v' + randomId(23);
      storageSet(STORAGE_VISITOR, id);
    }
    return id;
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = String(text); }
    return node;
  }

  function svg(paths, opts) {
    opts = opts || {};
    var ns = 'http://www.w3.org/2000/svg';
    var node = document.createElementNS(ns, 'svg');
    node.setAttribute('viewBox', opts.viewBox || '0 0 24 24');
    node.setAttribute('fill', 'none');
    node.setAttribute('stroke', 'currentColor');
    node.setAttribute('stroke-width', opts.width || '1.6');
    node.setAttribute('stroke-linecap', 'round');
    node.setAttribute('stroke-linejoin', 'round');
    node.setAttribute('aria-hidden', 'true');
    node.setAttribute('focusable', 'false');
    for (var i = 0; i < paths.length; i++) {
      var p = document.createElementNS(ns, 'path');
      p.setAttribute('d', paths[i]);
      node.appendChild(p);
    }
    return node;
  }

  var ICON = {
    chat:    ['M21 11.5a8.4 8.4 0 0 1-9 8.4 9.7 9.7 0 0 1-2.8-.4L3 21l1.6-4.6A8.2 8.2 0 0 1 3 11.5 8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z'],
    close:   ['M18 6 6 18', 'm6 6 12 12'],
    send:    ['M22 2 11 13', 'M22 2 15 22l-4-9-9-4 20-7z'],
    expand:  ['M15 3h6v6', 'M9 21H3v-6', 'M21 3l-7 7', 'M3 21l7-7'],
    collapse:['M4 14h6v6', 'M20 10h-6V4', 'M14 10l7-7', 'M3 21l7-7'],
    person:  ['M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2', 'M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z'],
    check:   ['m20 6-11 11-5-5'],
    alert:   ['M12 9v4', 'M12 17h.01', 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z']
  };

  // ---------------------------------------------------------------------
  // Styles. Scoped inside the shadow root, so the host site cannot reach in
  // and the widget cannot leak out.
  // ---------------------------------------------------------------------
  var CSS = [
    ':host{all:initial}',
    '*,*::before,*::after{box-sizing:border-box}',

    '.root{',
    '--ink:#0F2138;--ink-soft:#4A5C72;--ink-faint:#7A8899;',
    '--paper:#FFFFFF;--wash:#F4F6F9;--line:#DCE3EC;',
    '--accent:#1B4E8F;--accent-ink:#FFFFFF;--accent-wash:#ECF2F9;',
    '--signal:#8A5100;--signal-wash:#FBF3E6;',
    '--danger:#A32318;',
    '--radius:6px;',
    '--font:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;',
    'font-family:var(--font);color:var(--ink);',
    'font-size:15px;line-height:1.55;-webkit-font-smoothing:antialiased;',
    'position:fixed;z-index:2147483000;bottom:0;',
    '}',
    '.root[data-pos="right"]{right:0}',
    '.root[data-pos="left"]{left:0}',

    // ---- Launcher ----
    '.launcher{position:fixed;bottom:20px;display:inline-flex;align-items:center;gap:9px;',
    'padding:12px 18px 12px 15px;border:0;border-radius:999px;cursor:pointer;',
    'background:var(--accent);color:var(--accent-ink);font-family:inherit;font-size:15px;',
    'font-weight:500;letter-spacing:.005em;box-shadow:0 6px 22px rgba(15,33,56,.22);}',
    '.root[data-pos="right"] .launcher{right:20px}',
    '.root[data-pos="left"] .launcher{left:20px}',
    '.launcher:hover{background:#17427A}',
    '.launcher:focus-visible{outline:3px solid #9DBCE4;outline-offset:2px}',
    '.launcher svg{width:20px;height:20px;flex:0 0 auto}',
    '.launcher[hidden]{display:none}',

    // ---- Panel ----
    '.panel{position:fixed;bottom:20px;width:380px;height:600px;max-height:calc(100vh - 40px);',
    'background:var(--paper);border:1px solid var(--line);border-radius:10px;',
    'box-shadow:0 18px 50px rgba(15,33,56,.20);display:flex;flex-direction:column;overflow:hidden}',
    '.root[data-pos="right"] .panel{right:20px}',
    '.root[data-pos="left"] .panel{left:20px}',
    '.panel[hidden]{display:none}',
    '.panel.is-wide{width:min(720px,calc(100vw - 40px));height:min(820px,calc(100vh - 40px))}',

    // ---- Header ----
    '.head{display:flex;align-items:center;gap:10px;padding:13px 12px 13px 16px;',
    'background:var(--ink);color:#fff;flex:0 0 auto}',
    '.head-title{font-size:15px;font-weight:600;letter-spacing:.005em;margin:0;flex:1 1 auto;',
    'overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.head-status{font-size:12px;color:#B7C6D8;font-weight:400;display:block;margin-top:1px}',
    '.icon-btn{appearance:none;background:transparent;border:0;color:#C9D6E5;cursor:pointer;',
    'padding:7px;border-radius:5px;display:inline-flex;line-height:0}',
    '.icon-btn:hover{background:rgba(255,255,255,.12);color:#fff}',
    '.icon-btn:focus-visible{outline:2px solid #9DBCE4;outline-offset:1px}',
    '.icon-btn svg{width:17px;height:17px}',

    // ---- Disclosure strip ----
    '.disclosure{padding:8px 16px;background:var(--wash);border-bottom:1px solid var(--line);',
    'font-size:12px;color:var(--ink-soft);flex:0 0 auto}',
    '.disclosure a{color:var(--accent);text-decoration:underline}',

    // ---- Message log ----
    '.log{flex:1 1 auto;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:14px;',
    'scroll-behavior:smooth;overscroll-behavior:contain}',
    '@media (prefers-reduced-motion:reduce){.log{scroll-behavior:auto}}',

    '.msg{max-width:88%;display:flex;flex-direction:column;gap:7px}',
    '.msg.from-agent{align-self:flex-start}',
    '.msg.from-visitor{align-self:flex-end;align-items:flex-end}',
    '.bubble{padding:10px 13px;border-radius:var(--radius);font-size:14.5px;line-height:1.55;',
    'white-space:normal;word-wrap:break-word;overflow-wrap:anywhere}',
    '.from-agent .bubble{background:var(--wash);border:1px solid var(--line);color:var(--ink);',
    'border-top-left-radius:2px}',
    '.from-visitor .bubble{background:var(--accent);color:var(--accent-ink);border-bottom-right-radius:2px}',
    '.bubble p{margin:0 0 8px}',
    '.bubble p:last-child{margin-bottom:0}',
    '.bubble ul,.bubble ol{margin:6px 0 8px;padding-left:19px}',
    '.bubble li{margin:2px 0}',
    '.bubble a{color:inherit;text-decoration:underline}',
    '.from-agent .bubble a{color:var(--accent)}',
    '.bubble strong{font-weight:600}',

    // ---- Package card: the one place the design raises its voice ----
    '.card{border:1px solid var(--line);border-left:3px solid var(--accent);border-radius:5px;',
    'background:var(--paper);padding:13px 14px;width:100%}',
    '.card + .card{margin-top:9px}',
    '.card-top{display:flex;align-items:baseline;justify-content:space-between;gap:12px}',
    '.card-name{font-size:14.5px;font-weight:600;margin:0;color:var(--ink);line-height:1.3}',
    '.card-price{font-size:16px;font-weight:600;color:var(--ink);white-space:nowrap;',
    'font-variant-numeric:tabular-nums;font-feature-settings:"tnum" 1}',
    '.card-basis{display:block;font-size:11.5px;font-weight:400;color:var(--ink-faint);text-align:right;margin-top:1px}',
    '.card-meta{margin:3px 0 0;font-size:12.5px;color:var(--ink-soft)}',
    '.card-sums{display:grid;grid-template-columns:1fr auto;gap:3px 14px;margin:11px 0 0;',
    'padding-top:10px;border-top:1px solid var(--line);font-size:12.5px}',
    '.card-sums dt{color:var(--ink-soft);margin:0}',
    '.card-sums dd{margin:0;text-align:right;color:var(--ink);',
    'font-variant-numeric:tabular-nums;font-feature-settings:"tnum" 1}',
    '.card-sums dt.is-total,.card-sums dd.is-total{font-weight:600;color:var(--ink);padding-top:4px}',
    '.card-list{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-direction:column;gap:4px}',
    '.card-list li{display:flex;gap:7px;align-items:flex-start;font-size:13px;color:var(--ink-soft);line-height:1.45}',
    '.card-list svg{width:13px;height:13px;flex:0 0 auto;margin-top:3px;color:var(--accent)}',
    '.card-tax{margin:10px 0 0;padding-top:9px;border-top:1px solid var(--line);',
    'font-size:12px;color:var(--ink-soft)}',
    '.card-tax.is-unspecified{color:var(--signal)}',

    // ---- Quick replies ----
    '.quick{display:flex;flex-wrap:wrap;gap:7px;padding:0 16px 4px}',
    '.quick button{appearance:none;font-family:inherit;font-size:13.5px;cursor:pointer;',
    'padding:7px 13px;border-radius:999px;border:1px solid var(--line);background:var(--paper);',
    'color:var(--accent);font-weight:500}',
    '.quick button:hover{background:var(--accent-wash);border-color:#BCCFE6}',
    '.quick button:focus-visible{outline:2px solid var(--accent);outline-offset:1px}',

    // ---- Typing indicator ----
    '.typing{display:flex;gap:4px;align-items:center;padding:12px 13px;background:var(--wash);',
    'border:1px solid var(--line);border-radius:var(--radius);border-top-left-radius:2px;width:fit-content}',
    '.typing span{width:6px;height:6px;border-radius:50%;background:var(--ink-faint);',
    'animation:blink 1.3s infinite ease-in-out}',
    '.typing span:nth-child(2){animation-delay:.18s}',
    '.typing span:nth-child(3){animation-delay:.36s}',
    '@keyframes blink{0%,80%,100%{opacity:.25}40%{opacity:1}}',
    '@media (prefers-reduced-motion:reduce){.typing span{animation:none;opacity:.55}}',
    '.typing-note{font-size:12px;color:var(--ink-faint);margin:4px 2px 0}',
    '.retry-btn{margin-left:6px;font:inherit;font-size:12.5px;font-weight:600;color:var(--accent);background:none;border:0;padding:0;text-decoration:underline;cursor:pointer}',

    // ---- Notice ----
    '.notice{display:flex;gap:9px;align-items:flex-start;margin:0 16px;padding:11px 12px;',
    'background:var(--signal-wash);border:1px solid #EBDCC2;border-radius:5px;',
    'font-size:13px;color:var(--signal)}',
    '.notice svg{width:15px;height:15px;flex:0 0 auto;margin-top:2px}',

    // ---- Composer ----
    '.foot{flex:0 0 auto;border-top:1px solid var(--line);background:var(--paper);padding:10px 12px 12px}',
    '.row{display:flex;gap:8px;align-items:flex-end}',
    '.input{flex:1 1 auto;font-family:inherit;font-size:14.5px;line-height:1.45;color:var(--ink);',
    'border:1px solid var(--line);border-radius:var(--radius);padding:10px 12px;resize:none;',
    'max-height:120px;min-height:42px;background:var(--paper);width:100%}',
    '.input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-wash)}',
    '.input::placeholder{color:var(--ink-faint)}',
    '.send{appearance:none;border:0;background:var(--accent);color:var(--accent-ink);cursor:pointer;',
    'width:42px;height:42px;border-radius:var(--radius);display:inline-flex;align-items:center;',
    'justify-content:center;flex:0 0 auto}',
    '.send:hover:not(:disabled){background:#17427A}',
    '.send:disabled{background:#B9C6D6;cursor:not-allowed}',
    '.send:focus-visible{outline:3px solid #9DBCE4;outline-offset:1px}',
    '.send svg{width:18px;height:18px}',
    '.foot-row{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:8px}',
    '.human{appearance:none;background:none;border:0;cursor:pointer;font-family:inherit;',
    'font-size:12.5px;color:var(--ink-soft);display:inline-flex;align-items:center;gap:5px;padding:2px 0}',
    '.human:hover{color:var(--accent)}',
    '.human:focus-visible{outline:2px solid var(--accent);outline-offset:2px;border-radius:3px}',
    '.human svg{width:14px;height:14px}',
    '.counter{font-size:11.5px;color:var(--ink-faint);font-variant-numeric:tabular-nums}',
    '.counter.is-over{color:var(--danger)}',

    // ---- Contact form ----
    '.form{padding:2px 16px 16px;display:flex;flex-direction:column;gap:10px}',
    '.field{display:flex;flex-direction:column;gap:4px}',
    '.field label{font-size:12.5px;font-weight:500;color:var(--ink-soft)}',
    '.field input,.field textarea{font-family:inherit;font-size:14px;color:var(--ink);',
    'border:1px solid var(--line);border-radius:var(--radius);padding:9px 11px;background:var(--paper);width:100%}',
    '.field input:focus,.field textarea:focus{outline:none;border-color:var(--accent);',
    'box-shadow:0 0 0 3px var(--accent-wash)}',
    '.field textarea{resize:vertical;min-height:62px}',
    '.form-error{font-size:12.5px;color:var(--danger)}',
    '.submit{appearance:none;border:0;background:var(--accent);color:var(--accent-ink);cursor:pointer;',
    'font-family:inherit;font-size:14.5px;font-weight:500;padding:11px 16px;border-radius:var(--radius)}',
    '.submit:hover:not(:disabled){background:#17427A}',
    '.submit:disabled{background:#B9C6D6;cursor:not-allowed}',
    '.hp{position:absolute;left:-9999px;width:1px;height:1px;opacity:0}',

    // ---- Introduction card (first name, company, country, exhibit or visit) ----
    '.intro{margin:4px 0 6px;padding:14px;border:1px solid var(--line);border-radius:8px;background:var(--paper)}',
    '.intro-lead{margin:0;font-size:13.5px;color:var(--ink-soft)}',
    '.field select{font-family:inherit;font-size:14px;color:var(--ink);border:1px solid var(--line);',
    'border-radius:var(--radius);padding:9px 11px;background:var(--paper);width:100%}',
    '.field select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-wash)}',
    '.choice{border:0;margin:0;padding:0;display:flex;flex-direction:column;gap:6px}',
    '.choice legend{font-size:12.5px;font-weight:500;color:var(--ink-soft);padding:0;margin-bottom:4px}',
    '.choice-row{display:flex;gap:8px}',
    '.choice label{flex:1;display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;',
    'border:1px solid var(--line);border-radius:var(--radius);padding:10px 11px;color:var(--ink)}',
    '.choice label:has(input:checked){border-color:var(--accent);background:var(--accent-wash)}',
    '.choice input{accent-color:var(--accent);margin:0}',
    '.skip{appearance:none;border:0;background:none;cursor:pointer;font-family:inherit;font-size:13px;',
    'color:var(--ink-soft);text-decoration:underline;padding:2px;align-self:center}',
    '.skip:hover{color:var(--accent)}',
    '.skip:focus-visible,.choice label:focus-within{outline:2px solid var(--accent);outline-offset:1px}',

    // ---- Mobile: full screen from the moment it opens ----
    '@media (max-width:560px){',
    '.panel,.panel.is-wide{right:0!important;left:0!important;bottom:0!important;top:0!important;',
    'width:100%!important;height:100%!important;max-height:100%!important;border-radius:0;border:0}',
    '.launcher{bottom:16px}',
    '.root[data-pos="right"] .launcher{right:16px}',
    '.root[data-pos="left"] .launcher{left:16px}',
    '.expand-btn{display:none}',
    '}'
  ].join('');

  // ---------------------------------------------------------------------
  // Widget
  // ---------------------------------------------------------------------
  var W = {
    session: null,
    secret: null,
    open: false,
    wide: false,
    busy: false,
    started: false,
    agentName: '',
    disclosure: '',
    privacyUrl: null,
    maxChars: 1200,
    pendingHuman: false
  };

  var host = document.createElement('div');
  host.setAttribute('data-aisa-widget', AGENT);
  var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : null;

  if (!shadow) {
    // Without shadow DOM the host site's CSS could break the panel, and the
    // isolation guarantee no longer holds. Better to stay out of the page.
    if (window.console) { console.warn('[AI Sales Agent] Shadow DOM unsupported; widget not loaded.'); }
    return;
  }

  var style = document.createElement('style');
  style.textContent = CSS;
  shadow.appendChild(style);

  var root = el('div', 'root');
  root.setAttribute('data-pos', POSITION === 'left' ? 'left' : 'right');
  shadow.appendChild(root);

  // ---- Launcher --------------------------------------------------------
  var launcher = el('button', 'launcher');
  launcher.type = 'button';
  launcher.setAttribute('aria-haspopup', 'dialog');
  launcher.setAttribute('aria-expanded', 'false');
  launcher.appendChild(svg(ICON.chat));
  launcher.appendChild(el('span', null, LAUNCH_LABEL));
  root.appendChild(launcher);

  // ---- Panel -----------------------------------------------------------
  var panel = el('div', 'panel');
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-modal', 'false');
  panel.setAttribute('aria-label', 'Chat with our sales assistant');
  root.appendChild(panel);

  // Header
  var head = el('div', 'head');
  var headText = el('div', null);
  headText.style.cssText = 'flex:1 1 auto;min-width:0';
  var headTitle = el('p', 'head-title', 'Sales assistant');
  var headStatus = el('span', 'head-status', 'Connecting');
  headText.appendChild(headTitle);
  headText.appendChild(headStatus);

  var expandBtn = el('button', 'icon-btn expand-btn');
  expandBtn.type = 'button';
  expandBtn.title = 'Make the panel larger';
  expandBtn.setAttribute('aria-label', 'Make the panel larger');
  expandBtn.appendChild(svg(ICON.expand));

  var closeBtn = el('button', 'icon-btn');
  closeBtn.type = 'button';
  closeBtn.title = 'Close the chat';
  closeBtn.setAttribute('aria-label', 'Close the chat');
  closeBtn.appendChild(svg(ICON.close));

  head.appendChild(headText);
  head.appendChild(expandBtn);
  head.appendChild(closeBtn);
  panel.appendChild(head);

  // Disclosure
  var disclosure = el('div', 'disclosure');
  panel.appendChild(disclosure);

  // Log
  var log = el('div', 'log');
  log.setAttribute('role', 'log');
  log.setAttribute('aria-live', 'polite');
  log.setAttribute('aria-relevant', 'additions');
  panel.appendChild(log);

  // Quick replies
  var quick = el('div', 'quick');
  quick.hidden = true;
  panel.appendChild(quick);

  // Composer
  var foot = el('div', 'foot');
  var row = el('div', 'row');

  var input = document.createElement('textarea');
  input.className = 'input';
  input.rows = 1;
  input.placeholder = 'Type your message';
  input.setAttribute('aria-label', 'Your message');

  var hp = document.createElement('input');
  hp.className = 'hp';
  hp.type = 'text';
  hp.name = 'company_website_hp';
  hp.tabIndex = -1;
  hp.setAttribute('autocomplete', 'off');
  hp.setAttribute('aria-hidden', 'true');

  var sendBtn = el('button', 'send');
  sendBtn.type = 'button';
  sendBtn.title = 'Send';
  sendBtn.setAttribute('aria-label', 'Send message');
  sendBtn.appendChild(svg(ICON.send));

  row.appendChild(input);
  row.appendChild(sendBtn);

  var footRow = el('div', 'foot-row');
  var humanBtn = el('button', 'human');
  humanBtn.type = 'button';
  humanBtn.appendChild(svg(ICON.person));
  humanBtn.appendChild(el('span', null, 'Talk to a person'));
  var counter = el('span', 'counter', '');
  footRow.appendChild(humanBtn);
  footRow.appendChild(counter);

  foot.appendChild(row);
  foot.appendChild(hp);
  foot.appendChild(footRow);
  panel.appendChild(foot);

  // The embed instructions say to paste the tag before </body>, but people
  // reasonably put it in <head> instead, and there document.body does not
  // exist yet — appending to it would throw and the widget would never
  // appear, with nothing but a console error to explain why. So mount now if
  // the body is ready and otherwise wait for it.
  (function mount() {
    if (document.body) {
      document.body.appendChild(host);
      return;
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', mount, { once: true });
      return;
    }
    // Body missing outside of loading is unexpected; try once more next tick
    // rather than failing silently.
    window.setTimeout(function () {
      if (document.body) {
        document.body.appendChild(host);
      } else if (window.console) {
        console.error('[AI Sales Agent] No <body> to attach to; widget not loaded.');
      }
    }, 0);
  })();

  // ---------------------------------------------------------------------
  // Rendering
  // ---------------------------------------------------------------------

  /**
   * Build DOM from the server's block list. Text is only ever written via
   * textContent, so model output cannot become markup.
   */
  function renderBlocks(blocks, into) {
    if (!blocks || !blocks.length) { return; }
    for (var i = 0; i < blocks.length; i++) {
      var b = blocks[i];
      if (b.type === 'p') {
        into.appendChild(renderSpans(b.spans, 'p'));
      } else if (b.type === 'ul' || b.type === 'ol') {
        var list = document.createElement(b.type === 'ol' ? 'ol' : 'ul');
        for (var j = 0; j < (b.items || []).length; j++) {
          list.appendChild(renderSpans(b.items[j], 'li'));
        }
        into.appendChild(list);
      }
    }
  }

  function renderSpans(spans, tag) {
    var node = document.createElement(tag);
    for (var i = 0; i < (spans || []).length; i++) {
      var s = spans[i];
      if (s.t === 'b') {
        node.appendChild(el('strong', null, s.v));
      } else if (s.t === 'a' && typeof s.href === 'string' && /^https:\/\//i.test(s.href)) {
        // The server has already restricted these to approved domains.
        var a = document.createElement('a');
        a.href = s.href;
        a.target = '_blank';
        a.rel = 'noopener noreferrer nofollow';
        a.textContent = s.v;
        node.appendChild(a);
      } else {
        // Preserve single line breaks inside a paragraph.
        var parts = String(s.v).split('\n');
        for (var k = 0; k < parts.length; k++) {
          if (k > 0) { node.appendChild(document.createElement('br')); }
          node.appendChild(document.createTextNode(parts[k]));
        }
      }
    }
    return node;
  }

  function addMessage(from, blocks, cards) {
    var wrap = el('div', 'msg from-' + from);
    var bubble = el('div', 'bubble');
    renderBlocks(blocks, bubble);
    wrap.appendChild(bubble);

    if (cards && cards.length) {
      for (var i = 0; i < cards.length; i++) {
        wrap.appendChild(renderCard(cards[i]));
      }
    }
    log.appendChild(wrap);
    scrollDown();
    return wrap;
  }

  function addVisitorText(text) {
    addMessage('visitor', [{ type: 'p', spans: [{ t: 'text', v: text }] }], null);
  }

  /**
   * Package card. Every figure here came from the server, rendered from an
   * approved database record — the model never supplies a number.
   */
  function renderCard(card) {
    var box = el('div', 'card');

    var top = el('div', 'card-top');
    top.appendChild(el('h4', 'card-name', card.title));

    var lines = card.price_lines || [];
    var headline = lines.length ? lines[lines.length - 1] : null;
    var priceWrap = el('div', 'card-price', headline ? headline.value : card.price_text);
    if (card.price_basis) {
      priceWrap.appendChild(el('span', 'card-basis', card.price_basis));
    }
    top.appendChild(priceWrap);
    box.appendChild(top);

    if (card.meta && card.meta.length) {
      box.appendChild(el('p', 'card-meta', card.meta.join(' \u2013 ')));
    }

    // The full breakdown. Every figure was calculated server-side from an
    // approved record; the panel only lays them out.
    if (lines.length > 1) {
      var table = el('dl', 'card-sums');
      for (var n = 0; n < lines.length; n++) {
        var dt = el('dt', lines[n].emphasis ? 'is-total' : null, lines[n].label);
        var dd = el('dd', lines[n].emphasis ? 'is-total' : null, lines[n].value);
        table.appendChild(dt);
        table.appendChild(dd);
      }
      box.appendChild(table);
    }

    if (card.items && card.items.length) {
      var ul = el('ul', 'card-list');
      for (var i = 0; i < card.items.length; i++) {
        var li = document.createElement('li');
        li.appendChild(svg(ICON.check, { width: '2.2' }));
        li.appendChild(el('span', null, card.items[i]));
        ul.appendChild(li);
      }
      box.appendChild(ul);
    }

    if (card.tax_note) {
      var tax = el('p', 'card-tax', card.tax_note);
      if (/not specified/i.test(card.tax_note)) {
        tax.className = 'card-tax is-unspecified';
      }
      box.appendChild(tax);
    }

    return box;
  }

  var typingNode = null;
  var slowTimer = null;
  // The dots, and — if the answer takes a while because the AI is busy — a
  // short line underneath so the visitor knows nothing has gone wrong.
  function showTyping(note) {
    hideTyping();
    typingNode = el('div', 'msg from-agent');
    var t = el('div', 'typing');
    t.appendChild(el('span'));
    t.appendChild(el('span'));
    t.appendChild(el('span'));
    t.setAttribute('aria-label', 'Assistant is typing');
    typingNode.appendChild(t);
    var line = el('div', 'typing-note', note || '');
    line.setAttribute('aria-live', 'polite');
    if (!note) { line.hidden = true; }
    typingNode.appendChild(line);
    log.appendChild(typingNode);
    scrollDown();
    slowTimer = window.setTimeout(function () {
      if (line.parentNode && line.hidden) {
        line.textContent = 'Still working on your answer\u2026';
        line.hidden = false;
        scrollDown();
      }
    }, 7000);
  }
  function hideTyping() {
    if (slowTimer) { window.clearTimeout(slowTimer); slowTimer = null; }
    if (typingNode && typingNode.parentNode) { typingNode.parentNode.removeChild(typingNode); }
    typingNode = null;
  }

  // Still busy after several tries: a button to try once more, without
  // typing the message again.
  function showRetry(text) {
    var note = el('div', 'notice');
    note.appendChild(svg(ICON.alert));
    note.appendChild(el('span', null, 'Sorry \u2014 we are very busy right now. '));
    var b = el('button', 'retry-btn', 'Try again');
    b.type = 'button';
    b.addEventListener('click', function () {
      if (W.busy) { return; }
      if (note.parentNode) { note.parentNode.removeChild(note); }
      setBusy(true);
      showTyping('Trying again\u2026');
      sendTurn(text, 1);
    });
    note.appendChild(b);
    log.appendChild(note);
    scrollDown();
  }

  function showNotice(text) {
    var note = el('div', 'notice');
    note.appendChild(svg(ICON.alert));
    note.appendChild(el('span', null, text));
    log.appendChild(note);
    scrollDown();
  }

  function scrollDown() {
    window.requestAnimationFrame(function () { log.scrollTop = log.scrollHeight; });
  }

  function setQuickReplies(items) {
    while (quick.firstChild) { quick.removeChild(quick.firstChild); }
    if (!items || !items.length) { quick.hidden = true; return; }
    for (var i = 0; i < items.length; i++) {
      (function (label) {
        var b = el('button', null, label);
        b.type = 'button';
        b.addEventListener('click', function () {
          quick.hidden = true;
          send(label);
        });
        quick.appendChild(b);
      })(items[i]);
    }
    quick.hidden = false;
  }

  // ---------------------------------------------------------------------
  // Contact form (works with no model call at all)
  // ---------------------------------------------------------------------
  var formNode = null;

  function showContactForm(introText) {
    if (formNode) { return; }
    foot.hidden = true;
    quick.hidden = true;

    if (introText) {
      addMessage('agent', [{ type: 'p', spans: [{ t: 'text', v: introText }] }], null);
    }

    formNode = el('div', 'form');

    var fields = {};
    [
      ['name', 'Your name', 'text', 'name'],
      ['company', 'Company', 'text', 'organization'],
      ['email', 'Email address', 'email', 'email'],
      ['phone', 'Phone number', 'tel', 'tel']
    ].forEach(function (f) {
      var wrap = el('div', 'field');
      var id = 'aisa-' + f[0];
      var label = el('label', null, f[1]);
      label.setAttribute('for', id);
      var inp = document.createElement('input');
      inp.type = f[2];
      inp.id = id;
      inp.setAttribute('autocomplete', f[3]);
      wrap.appendChild(label);
      wrap.appendChild(inp);
      formNode.appendChild(wrap);
      fields[f[0]] = inp;
    });

    var noteWrap = el('div', 'field');
    var noteLabel = el('label', null, 'Anything we should know');
    noteLabel.setAttribute('for', 'aisa-note');
    var note = document.createElement('textarea');
    note.id = 'aisa-note';
    noteWrap.appendChild(noteLabel);
    noteWrap.appendChild(note);
    formNode.appendChild(noteWrap);

    var fhp = document.createElement('input');
    fhp.className = 'hp';
    fhp.type = 'text';
    fhp.tabIndex = -1;
    fhp.setAttribute('aria-hidden', 'true');
    formNode.appendChild(fhp);

    var error = el('p', 'form-error');
    error.hidden = true;
    formNode.appendChild(error);

    var submit = el('button', 'submit', 'Send my details');
    submit.type = 'button';
    formNode.appendChild(submit);

    submit.addEventListener('click', function () {
      error.hidden = true;
      if (!fields.email.value.trim() && !fields.phone.value.trim()) {
        error.textContent = 'Enter an email address or a phone number so the team can reach you.';
        error.hidden = false;
        fields.email.focus();
        return;
      }
      submit.disabled = true;
      submit.textContent = 'Sending';

      post('contact.php', {
        request_id: 'r' + randomId(23),
        session_id: W.session,
        visitor_id: visitorId(),
        page_url: location.href.slice(0, 500),
        name: fields.name.value,
        company: fields.company.value,
        email: fields.email.value,
        phone: fields.phone.value,
        note: note.value,
        company_website_hp: fhp.value
      }).then(function (res) {
        if (res && res.ok) {
          if (formNode && formNode.parentNode) { formNode.parentNode.removeChild(formNode); }
          formNode = null;
          addMessage('agent', [{ type: 'p', spans: [{ t: 'text', v: res.message || 'Thank you. A member of the team will be in touch.' }] }], null);
        } else {
          error.textContent = (res && res.message) || 'That did not send. Check your details and try again.';
          error.hidden = false;
          submit.disabled = false;
          submit.textContent = 'Send my details';
        }
      }).catch(function () {
        error.textContent = 'That did not send. Check your connection and try again.';
        error.hidden = false;
        submit.disabled = false;
        submit.textContent = 'Send my details';
      });
    });

    panel.insertBefore(formNode, foot);
    scrollDown();
  }

  // ---------------------------------------------------------------------
  // Introduction card
  //
  // Four questions before the conversation: first name, company, the country
  // the company is based in, and exhibiting or visiting. Light on purpose,
  // and always skippable — a visitor with a quick question should never have
  // to fill in a form to ask it. Built with text nodes only, like everything
  // else here; nothing the server sends is ever parsed as markup.
  // ---------------------------------------------------------------------
  var introNode = null;

  function showIntroCard(countries) {
    if (introNode || formNode) { return; }
    foot.hidden = true;
    quick.hidden = true;

    introNode = el('div', 'form intro');
    introNode.appendChild(el('p', 'intro-lead', 'So we can help you properly, tell us a little about yourself.'));

    function textField(key, label, auto) {
      var wrap = el('div', 'field');
      var id = 'aisa-intro-' + key;
      var lab = el('label', null, label);
      lab.setAttribute('for', id);
      var inp = document.createElement('input');
      inp.type = 'text';
      inp.id = id;
      inp.maxLength = key === 'first_name' ? 60 : 160;
      inp.setAttribute('autocomplete', auto);
      wrap.appendChild(lab);
      wrap.appendChild(inp);
      introNode.appendChild(wrap);
      return inp;
    }

    var firstName = textField('first_name', 'First name', 'given-name');
    var company = textField('company', 'Company', 'organization');

    var cWrap = el('div', 'field');
    var cLab = el('label', null, 'Country your company is based in');
    cLab.setAttribute('for', 'aisa-intro-country');
    var country = document.createElement('select');
    country.id = 'aisa-intro-country';
    var none = document.createElement('option');
    none.value = '';
    none.textContent = 'Choose a country';
    country.appendChild(none);
    (countries || []).forEach(function (c) {
      if (!c || c.length < 2) { return; }
      var o = document.createElement('option');
      o.value = String(c[0]);
      o.textContent = String(c[1]);
      country.appendChild(o);
    });
    cWrap.appendChild(cLab);
    cWrap.appendChild(country);
    introNode.appendChild(cWrap);

    var choice = document.createElement('fieldset');
    choice.className = 'choice';
    var legend = document.createElement('legend');
    legend.textContent = 'I am interested in';
    choice.appendChild(legend);
    var row = el('div', 'choice-row');
    var radios = {};
    [['exhibit', 'Exhibiting'], ['visit', 'Visiting']].forEach(function (opt) {
      var lab = document.createElement('label');
      var r = document.createElement('input');
      r.type = 'radio';
      r.name = 'aisa-intro-interest';
      r.value = opt[0];
      lab.appendChild(r);
      lab.appendChild(document.createTextNode(opt[1]));
      row.appendChild(lab);
      radios[opt[0]] = r;
    });
    choice.appendChild(row);
    introNode.appendChild(choice);

    var ihp = document.createElement('input');
    ihp.className = 'hp';
    ihp.type = 'text';
    ihp.tabIndex = -1;
    ihp.setAttribute('aria-hidden', 'true');
    introNode.appendChild(ihp);

    var error = el('p', 'form-error');
    error.hidden = true;
    error.setAttribute('role', 'alert');
    introNode.appendChild(error);

    var submit = el('button', 'submit', 'Continue');
    submit.type = 'button';
    introNode.appendChild(submit);

    var skip = el('button', 'skip', 'Skip — just ask a question');
    skip.type = 'button';
    introNode.appendChild(skip);

    function fail(text, focusOn) {
      error.textContent = text;
      error.hidden = false;
      if (focusOn) { focusOn.focus(); }
    }

    function closeCard() {
      if (introNode && introNode.parentNode) { introNode.parentNode.removeChild(introNode); }
      introNode = null;
      foot.hidden = false;
    }

    skip.addEventListener('click', function () {
      closeCard();
      setQuickReplies(W.quickReplies || []);
      input.focus();
    });

    submit.addEventListener('click', function () {
      error.hidden = true;
      var interest = radios.exhibit.checked ? 'exhibit' : (radios.visit.checked ? 'visit' : '');
      var name = firstName.value.trim();
      var org = company.value.trim();
      var code = country.value;

      if (!name) { fail('Please tell us your first name.', firstName); return; }
      if (!interest) { fail('Please choose exhibiting or visiting.', radios.exhibit); return; }
      if (interest === 'exhibit' && !org) {
        fail('To help with exhibiting, we need your company name.', company); return;
      }
      if (interest === 'exhibit' && !code) {
        fail('To help with exhibiting, choose the country your company is based in.', country); return;
      }

      submit.disabled = true;
      submit.textContent = 'One moment';

      // What the visitor said, shown as their own message — the same words
      // the conversation record keeps.
      var countryName = code ? country.options[country.selectedIndex].textContent : '';
      var said = 'My name is ' + name + '.';
      if (org) { said += ' I am from ' + org + (countryName ? ', based in ' + countryName : '') + '.'; }
      else if (countryName) { said += ' I am based in ' + countryName + '.'; }
      said += interest === 'exhibit' ? ' I am interested in exhibiting.' : ' I am interested in visiting.';

      post('intro.php', {
        request_id: 'r' + randomId(23),
        session_id: W.session,
        session_secret: W.secret,
        visitor_id: visitorId(),
        first_name: name,
        company: org,
        country: code,
        interest: interest,
        company_website_hp: ihp.value
      }).then(function (res) {
        if (res && res.ok) {
          closeCard();
          addVisitorText(said);
          addMessage('agent', res.blocks || [], res.cards || null);
          if (res.contact_form) { showContactForm(null); }
          else { input.focus(); }
          return;
        }
        submit.disabled = false;
        submit.textContent = 'Continue';
        if (res && res.error === 'session_expired') {
          fail('This chat timed out. Reload the page to start again.');
        } else if (res && res.error === 'already_done') {
          closeCard();
          input.focus();
        } else {
          fail((res && res.message) || 'That did not go through. Check the details and try again.');
        }
      }).catch(function () {
        submit.disabled = false;
        submit.textContent = 'Continue';
        fail('That did not go through. Check your connection and try again.');
      });
    });

    // Inside the conversation, so it scrolls with it on a small screen.
    log.appendChild(introNode);
    scrollDown();
  }

  // ---------------------------------------------------------------------
  // Networking
  // ---------------------------------------------------------------------
  function post(endpoint, body) {
    body.agent = AGENT;
    // The slug also goes in the query string. A JSON content type makes this
    // a non-simple cross-origin request, so the browser sends an OPTIONS
    // pre-flight first — and a pre-flight carries no body, so the server can
    // only identify the agent from the URL. Without this the pre-flight is
    // refused and the real request is never sent, from any other domain.
    var url = BASE + '/api/' + endpoint + '?agent=' + encodeURIComponent(AGENT);
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      credentials: 'omit',
      mode: 'cors'
    }).then(function (r) {
      return r.json().then(function (data) {
        data.__status = r.status;
        return data;
      }).catch(function () {
        return { ok: false, error: 'bad_response', __status: r.status };
      });
    });
  }

  function startSession() {
    if (W.started) { return Promise.resolve(true); }
    W.started = true;
    headStatus.textContent = 'Connecting';

    return post('session.php', {
      visitor_id: visitorId(),
      page_url: location.href.slice(0, 500)
    }).then(function (res) {
      if (!res || !res.ok) {
        headStatus.textContent = 'Unavailable';
        showNotice('The assistant could not be reached. Leave your details and the team will follow up.');
        showContactForm(null);
        return false;
      }

      W.session = res.session_id;
      W.secret = res.session_secret;
      W.agentName = res.agent_name || 'Sales assistant';
      headTitle.textContent = W.agentName;
      headStatus.textContent = res.unavailable ? 'Offline' : 'Usually replies in a moment';

      sessionSet(STORAGE_SESSION, JSON.stringify({ id: W.session, s: W.secret }));

      // AI disclosure and privacy link (Section 44).
      while (disclosure.firstChild) { disclosure.removeChild(disclosure.firstChild); }
      disclosure.appendChild(document.createTextNode(res.disclosure || 'You are chatting with an AI assistant.'));
      if (res.privacy_url && /^https:\/\//i.test(res.privacy_url)) {
        disclosure.appendChild(document.createTextNode(' '));
        var pa = document.createElement('a');
        pa.href = res.privacy_url;
        pa.target = '_blank';
        pa.rel = 'noopener noreferrer';
        pa.textContent = 'Privacy notice';
        disclosure.appendChild(pa);
      }

      if (res.blocks && res.blocks.length) {
        addMessage('agent', res.blocks, null);
      }

      W.quickReplies = res.quick_replies || [];
      if (res.unavailable || res.contact_form) {
        showContactForm(null);
      } else if (res.intro_form) {
        showIntroCard(res.countries);
      } else {
        setQuickReplies(res.quick_replies);
      }
      return true;
    }).catch(function () {
      headStatus.textContent = 'Unavailable';
      showNotice('The assistant could not be reached. Leave your details and the team will follow up.');
      showContactForm(null);
      return false;
    });
  }

  function setBusy(state) {
    W.busy = state;
    sendBtn.disabled = state;
    input.disabled = state;
  }

  function send(text) {
    text = String(text || '').trim();
    if (!text || W.busy || !W.session) { return; }
    if (text.length > W.maxChars) { text = text.slice(0, W.maxChars); }

    quick.hidden = true;
    addVisitorText(text);
    input.value = '';
    autoGrow();
    updateCounter();
    setBusy(true);
    showTyping();
    sendTurn(text, 0);
  }

  // The AI was busy even after the server waited. The message is kept on the
  // server, so the same message is sent again a little later, marked as a
  // retry so it is answered rather than stored twice.
  var MAX_BUSY_RETRIES = 5;

  function sendTurn(text, attempt) {
    var requestId = 'r' + randomId(23);

    post('chat.php', {
      request_id: requestId,
      session_id: W.session,
      session_secret: W.secret,
      visitor_id: visitorId(),
      message: text,
      retry: attempt > 0,
      company_website_hp: hp.value
    }).then(function (res) {
      if (res && res.ok && res.fallback === 'llm_busy') {
        if (attempt < MAX_BUSY_RETRIES) {
          var wait = Math.max(5, Math.min(60, parseInt(res.retry_after, 10) || 12));
          showTyping('Many people are asking right now \u2014 your answer is on its way\u2026');
          window.setTimeout(function () { sendTurn(text, attempt + 1); }, wait * 1000);
          return;
        }
        hideTyping();
        setBusy(false);
        showRetry(text);
        return;
      }

      hideTyping();
      setBusy(false);

      if (!res || !res.ok) {
        var code = res && res.error;
        if (code === 'rate_limited') {
          showNotice('You are sending messages faster than we can process. Wait a moment and try again.');
        } else if (code === 'message_too_long') {
          showNotice('That message is too long. Shorten it and send again.');
        } else if (code === 'session_expired') {
          showNotice('This chat timed out. Reload the page to start again.');
          setBusy(true);
        } else {
          showNotice('That did not send. Try again in a moment.');
        }
        return;
      }

      addMessage('agent', res.blocks, res.cards);

      if (res.contact_form) {
        showContactForm(null);
      }
      input.focus();
    }).catch(function () {
      hideTyping();
      setBusy(false);
      showNotice('That did not send. Check your connection and try again.');
    });
  }

  // ---------------------------------------------------------------------
  // Interaction
  // ---------------------------------------------------------------------
  function openPanel() {
    if (W.open) { return; }
    W.open = true;
    panel.hidden = false;
    launcher.hidden = true;
    launcher.setAttribute('aria-expanded', 'true');

    startSession().then(function () {
      if (!formNode && !introNode) { input.focus(); }
    });
  }

  function closePanel() {
    W.open = false;
    panel.hidden = true;
    launcher.hidden = false;
    launcher.setAttribute('aria-expanded', 'false');
    launcher.focus();
  }

  function toggleWide() {
    W.wide = !W.wide;
    panel.className = W.wide ? 'panel is-wide' : 'panel';
    while (expandBtn.firstChild) { expandBtn.removeChild(expandBtn.firstChild); }
    expandBtn.appendChild(svg(W.wide ? ICON.collapse : ICON.expand));
    var label = W.wide ? 'Return to the smaller panel' : 'Make the panel larger';
    expandBtn.title = label;
    expandBtn.setAttribute('aria-label', label);
    scrollDown();
  }

  function autoGrow() {
    input.style.height = 'auto';
    input.style.height = Math.min(120, input.scrollHeight) + 'px';
  }

  function updateCounter() {
    var len = input.value.length;
    if (len > W.maxChars * 0.8) {
      counter.textContent = len + ' / ' + W.maxChars;
      counter.className = len > W.maxChars ? 'counter is-over' : 'counter';
    } else {
      counter.textContent = '';
      counter.className = 'counter';
    }
  }

  launcher.addEventListener('click', openPanel);
  closeBtn.addEventListener('click', closePanel);
  expandBtn.addEventListener('click', toggleWide);
  sendBtn.addEventListener('click', function () { send(input.value); });

  humanBtn.addEventListener('click', function () {
    if (W.busy) { return; }
    send('I would like to speak to someone from your team.');
  });

  input.addEventListener('input', function () { autoGrow(); updateCounter(); });
  input.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey) {
      ev.preventDefault();
      send(input.value);
    }
  });

  shadow.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && W.open) { closePanel(); }
  });

  if (AUTO_OPEN) {
    window.setTimeout(openPanel, 1200);
  }

  // Public hook, so a site can open the widget from its own button.
  window.AISalesAgent = {
    version: WIDGET_VERSION,
    agent: AGENT,
    base: BASE,
    mounted: function () { return !!(host && host.parentNode); },
    open: openPanel,
    close: closePanel,
    isOpen: function () { return W.open; }
  };
})();
