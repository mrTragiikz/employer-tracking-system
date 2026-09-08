/* field/pwa.js - registers the service worker and offers "Add to Home Screen".
 *
 * Loaded on every field page (from the shared header) and on the field login
 * page. Tiny and defensive: any failure here must never affect the page.
 *
 * The service worker (field/sw.js) is what makes the field app installable and
 * gives it the offline fallback page. Registration is the only thing strictly
 * required; the install banner below is a convenience.
 */
(function () {
  'use strict';

  /* Base path of the field app, derived from this script's own URL so it is
     correct whether the app is at a domain root or in a subfolder:
       .../field/pwa.js  ->  .../field/            */
  var FIELD_BASE = (function () {
    try {
      var me = document.currentScript && document.currentScript.src;
      if (!me) {
        var ss = document.getElementsByTagName('script');
        me = ss[ss.length - 1].src;
      }
      return me.replace(/pwa\.js(?:\?.*)?$/, ''); // strip filename + any ?v=
    } catch (e) {
      return '/field/';
    }
  })();

  /* ---- 1. register the service worker ---------------------------------- */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(FIELD_BASE + 'sw.js', { scope: FIELD_BASE })
        .catch(function (err) {
          // Not fatal - the app works without it, just no offline page /
          // shell cache. Common causes: not HTTPS, or private browsing.
          if (window.console) console.warn('[pwa] SW registration failed:', err);
        });
    });
  }

  /* ---- 2. "Add to Home Screen" helper (Android / Chrome) --------------- *
   * Chrome fires 'beforeinstallprompt' when the app qualifies. We stash it
   * and show a slim one-line bar with an Install button. Dismissed choice is
   * remembered so it doesn't nag. Does nothing on iOS (no such event) and
   * nothing once the app is already installed (display-mode: standalone). */

  var deferredPrompt = null;
  var DISMISS_KEY = 'rajdoot_install_dismissed';

  function alreadyInstalled() {
    return window.matchMedia('(display-mode: standalone)').matches
      || window.navigator.standalone === true;
  }
  function dismissedBefore() {
    try { return localStorage.getItem(DISMISS_KEY) === '1'; } catch (e) { return false; }
  }
  function rememberDismissed() {
    try { localStorage.setItem(DISMISS_KEY, '1'); } catch (e) { /* ignore */ }
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();               // stop Chrome's own mini-infobar
    if (alreadyInstalled() || dismissedBefore()) return;
    deferredPrompt = e;
    showBar();
  });

  window.addEventListener('appinstalled', function () {
    rememberDismissed();
    removeBar();
    deferredPrompt = null;
  });

  function showBar() {
    if (document.getElementById('pwa-install-bar')) return;

    var bar = document.createElement('div');
    bar.id = 'pwa-install-bar';
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Install app');
    bar.style.cssText = [
      'position:fixed', 'left:0', 'right:0', 'bottom:0', 'z-index:9999',
      'display:flex', 'align-items:center', 'gap:10px',
      'padding:10px 14px', 'background:#2a2420', 'color:#fff',
      'font:500 13px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif',
      'box-shadow:0 -4px 16px rgba(0,0,0,0.25)'
    ].join(';');

    var text = document.createElement('span');
    text.textContent = 'Install Rajdoot on your phone';
    text.style.cssText = 'flex:1;min-width:0';

    var install = document.createElement('button');
    install.type = 'button';
    install.textContent = 'Install';
    install.style.cssText = [
      'flex:0 0 auto', 'appearance:none', 'border:0', 'border-radius:8px',
      'padding:8px 16px', 'font:600 13px system-ui,sans-serif',
      'color:#2a2420', 'background:#fff', 'cursor:pointer'
    ].join(';');
    install.addEventListener('click', function () {
      if (!deferredPrompt) { removeBar(); return; }
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function () {
        deferredPrompt = null;
        removeBar();
      });
    });

    var close = document.createElement('button');
    close.type = 'button';
    close.setAttribute('aria-label', 'Dismiss');
    close.textContent = '✕';
    close.style.cssText = [
      'flex:0 0 auto', 'appearance:none', 'border:0', 'background:transparent',
      'color:#c9c2ba', 'font-size:16px', 'line-height:1', 'padding:6px',
      'cursor:pointer'
    ].join(';');
    close.addEventListener('click', function () {
      rememberDismissed();
      removeBar();
    });

    bar.appendChild(text);
    bar.appendChild(install);
    bar.appendChild(close);
    document.body.appendChild(bar);
  }

  function removeBar() {
    var bar = document.getElementById('pwa-install-bar');
    if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
  }
})();
