/* admin/components/header/js/header.js
 * Theme toggle (light <-> dark). Persists per browser.
 * The actual dark palette is added later; this just flips the attribute + icon.
 */
'use strict';

(function () {
  const btn = document.querySelector('[data-theme-toggle]');
  if (!btn) return;

  const KEY = 'track.admin.theme';
  const root = document.documentElement;

  try {
    const saved = localStorage.getItem(KEY);
    if (saved) root.setAttribute('data-theme', saved);
  } catch (e) { /* ignore */ }

  btn.addEventListener('click', () => {
    const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem(KEY, next); } catch (e) { /* ignore */ }
  });
})();

/* ---- logout confirmation modal ---------------------------------------- */
(function () {
  'use strict';

  const form   = document.getElementById('logout-form');
  const modal  = document.getElementById('logout-modal');
  if (!form || !modal) return;

  const trigger = form.querySelector('[data-logout-trigger]');
  const confirmBtn = modal.querySelector('[data-logout-confirm]');
  const cancels = modal.querySelectorAll('[data-logout-cancel]');
  let lastFocus = null;

  function open() {
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('logout-modal-open');
    // let the browser paint the hidden->shown swap before animating
    requestAnimationFrame(() => modal.classList.add('is-open'));
    confirmBtn.focus();
    document.addEventListener('keydown', onKey);
  }

  function close() {
    modal.classList.remove('is-open');
    document.removeEventListener('keydown', onKey);
    const done = () => {
      modal.hidden = true;
      modal.removeEventListener('transitionend', done);
    };
    modal.addEventListener('transitionend', done);
    document.body.classList.remove('logout-modal-open');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function onKey(e) {
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'Tab') {
      // trap focus inside the two buttons
      const focusable = modal.querySelectorAll('button');
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  form.addEventListener('submit', (e) => {
    if (form.dataset.confirmed === '1') return; // let it through
    e.preventDefault();
    open();
  });

  if (trigger) {
    trigger.addEventListener('click', (e) => {
      // buttons submit the form; the submit handler above catches it, but
      // guard here too for browsers that fire click without submit
      if (form.dataset.confirmed !== '1') { e.preventDefault(); open(); }
    });
  }

  confirmBtn.addEventListener('click', () => {
    form.dataset.confirmed = '1';
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Signing out...';
    form.submit();
  });

  cancels.forEach((el) => el.addEventListener('click', close));
})();
