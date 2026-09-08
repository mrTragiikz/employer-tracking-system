/* admin/11-users-roles/js/users-roles.js
 * - list: the Filters / Sort dropdowns (.js-menu-toggle / .tool-panel)
 * - list: confirm before delete (.js-delete, data-name)
 * - form: live preview + 200 KB check for the document photo pair
 * - self-edit form: show/hide eye on password fields (.js-pw-toggle)
 */
'use strict';

/* ---- password show/hide eye ---- */
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.js-pw-toggle');
  if (!btn) return;
  const input = document.getElementById(btn.dataset.target);
  if (!input) return;
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});

/* ---- Filters / Sort dropdowns: one open at a time, closes on outside click
 * / Esc. The panel is moved to <body> and positioned with `fixed` coords
 * while open (a "portal") so it floats above the card instead of being
 * clipped or fighting for layout space. It's moved back next to its button
 * on close so the DOM/markup stays normal otherwise.
 *
 * The panel is found via toggle -> parent .tool-menu -> .tool-panel (NOT
 * toggle.nextElementSibling) because after the first portal-move the panel is
 * no longer the toggle's next sibling in the DOM at all - it lives in <body>
 * until closeOpen() puts it back. Using nextElementSibling here found the
 * WRONG node (or none) on a second open and could leave a stray portal panel
 * stuck open in <body> while a new one opened on top of it - two menus
 * visibly stacked at once. */
(function () {
  let openPanel = null;
  let openToggle = null;
  let openHome = null; // {parent, next} to put the panel back where it came from

  function panelFor(toggle) {
    return toggle.closest('.tool-menu')?.querySelector('.tool-panel') || null;
  }

  function place(panel, toggle) {
    const r = toggle.getBoundingClientRect();
    const panelH = panel.offsetHeight;
    // flip above the button when there isn't room below in the viewport
    // (a row near the bottom of a tall page) - otherwise the panel's lower
    // items render past the visible area with no way to reach them
    const fitsBelow = r.bottom + 4 + panelH <= window.innerHeight;
    panel.style.top = fitsBelow
      ? Math.round(r.bottom + 4) + 'px'
      : Math.round(r.top - 4 - panelH) + 'px';
    panel.style.left = Math.round(r.right - panel.offsetWidth) + 'px';
  }

  function closeOpen() {
    if (!openPanel) return;
    openPanel.hidden = true;
    openPanel.classList.remove('tool-panel--portal');
    openPanel.style.top = '';
    openPanel.style.left = '';
    if (openHome) openHome.parent.insertBefore(openPanel, openHome.next);
    if (openToggle) openToggle.setAttribute('aria-expanded', 'false');
    openPanel = null;
    openToggle = null;
    openHome = null;
  }

  // Defensive sweep: hide any OTHER portal panel still marked open in the DOM
  // that closeOpen() above doesn't know about - covers a stale bfcache
  // restore (browser back/forward can resurrect a mid-open panel's DOM state
  // without re-running this script's init) or any other case where two
  // panels ended up visible at once, which otherwise looked like "both menus
  // open together" with no way to close either from a fresh click.
  function sweepStalePanels(except) {
    document.querySelectorAll('.tool-panel--portal').forEach((p) => {
      if (p === except) return;
      p.hidden = true;
      p.classList.remove('ur-menu__panel--portal');
      p.style.top = '';
      p.style.left = '';
    });
    document.querySelectorAll('.js-menu-toggle[aria-expanded="true"]').forEach((t) => {
      if (t !== openToggle) t.setAttribute('aria-expanded', 'false');
    });
  }

  document.addEventListener('click', (e) => {
    const toggle = e.target.closest('.js-menu-toggle');
    if (toggle) {
      const panel = panelFor(toggle);
      const wasOpen = panel === openPanel;
      closeOpen();
      sweepStalePanels(panel);
      if (!wasOpen && panel) {
        openHome = { parent: panel.parentElement, next: panel.nextElementSibling };
        document.body.appendChild(panel);
        panel.classList.add('tool-panel--portal');
        panel.hidden = false;
        place(panel, toggle);
        toggle.setAttribute('aria-expanded', 'true');
        openPanel = panel;
        openToggle = toggle;
      }
      return;
    }
    if (openPanel && !openPanel.contains(e.target)) closeOpen();
  });

  // Also sweep once right after the page finishes loading, in case a bfcache
  // restore handed us a DOM where a panel is already sitting open with no
  // tracked state to close it.
  window.addEventListener('pageshow', () => sweepStalePanels(null));

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeOpen();
  });

  window.addEventListener('scroll', () => {
    if (openPanel && openToggle) place(openPanel, openToggle);
  }, true);
  window.addEventListener('resize', () => {
    if (openPanel && openToggle) place(openPanel, openToggle);
  });
})();

/* Confirm-before-delete (.js-delete) is handled globally by the shared
   admin/components/confirm-modal - see that component instead of adding
   per-section confirm logic here. */

/* ---- document photo viewer modal (.js-photo-view -> #photo-modal) ---- */
(function () {
  const modal = document.getElementById('photo-modal');
  if (!modal) return; // not a page with the modal (view.php / super-admin.php)

  const img   = document.getElementById('photo-modal-img');
  const title = document.getElementById('photo-modal-title');
  let lastFocused = null;

  function open(src, label) {
    img.src = src;
    img.alt = label || '';
    title.textContent = label || '';
    lastFocused = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('photo-modal-open');
    modal.querySelector('.photo-modal__close').focus();
  }

  function close() {
    modal.hidden = true;
    img.src = '';
    document.body.classList.remove('photo-modal-open');
    if (lastFocused && lastFocused.focus) lastFocused.focus();
  }

  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.js-photo-view');
    if (trigger) {
      open(trigger.dataset.src, trigger.dataset.label);
      return;
    }
    if (e.target.closest('[data-photo-modal-close]')) close();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden) close();
  });
})();

(function () {
  const submit = document.getElementById('f-submit');
  if (!submit) return; // not the form page

  const MAX = 200 * 1024;
  const oversize = {};

  function refreshSubmit() {
    submit.disabled = Object.keys(oversize).some((k) => oversize[k]);
  }

  function wire(input, thumb, hint, onImage, onClear) {
    if (!input) return;
    input.addEventListener('change', () => {
      hint.textContent = '';
      hint.className = hint.className.replace(/\s*is-(ok|bad)/g, '');
      oversize[input.id] = false;
      refreshSubmit();

      const f = input.files && input.files[0];
      if (!f) { onClear(); return; }

      if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
        hint.textContent = 'Not a JPEG, PNG or WebP image.';
        hint.className += ' is-bad';
        input.value = '';
        onClear();
        return;
      }
      if (f.size > MAX) {
        hint.textContent = 'Too large: ' + Math.round(f.size / 1024) + ' KB. Must be 200 KB or smaller.';
        hint.className += ' is-bad';
        oversize[input.id] = true;
        refreshSubmit();
        return;
      }
      hint.textContent = 'OK - ' + Math.round(f.size / 1024) + ' KB';
      hint.className += ' is-ok';
      onImage(URL.createObjectURL(f));
    });
  }

  document.querySelectorAll('.js-id-file').forEach((input) => {
    const thumb = document.getElementById(input.dataset.thumb);
    const hint = document.getElementById(input.dataset.hint);
    wire(input, thumb, hint,
      (url) => { thumb.innerHTML = '<img src="' + url + '" alt="">'; },
      () => { thumb.innerHTML = '<i class="bi bi-person-vcard"></i>'; });
  });

  // ---- profile photo ----
  const pInput   = document.getElementById('f-photo');
  const pPreview = document.getElementById('photo-preview');
  const pHint    = document.getElementById('photo-hint');
  const nameEl   = document.getElementById('f-name');

  function initials(s) {
    const p = (s || 'A').trim().split(/\s+/);
    return ((p[0] || 'A')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
  }
  function pPlaceholder() {
    if (!pPreview) return;
    pPreview.innerHTML = '<span class="admin-photo__ph">' + initials(nameEl ? nameEl.value : 'A') + '</span>';
  }
  if (nameEl && pPreview) {
    nameEl.addEventListener('input', () => {
      if (!pPreview.querySelector('img[data-user]')) pPlaceholder();
    });
  }
  wire(pInput, pPreview, pHint,
    (url) => { pPreview.innerHTML = '<img data-user src="' + url + '" alt="">'; },
    pPlaceholder);
})();
