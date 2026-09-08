/* admin/components/confirm-modal/js/confirm-modal.js
 * One shared confirm/prompt modal for the whole admin area - replaces every
 * window.confirm()/window.prompt() with a styled dialog matching the app's
 * design, same interaction pattern as the header's logout-modal.
 *
 * Markers (put on the <form>, not the button):
 *   class="js-confirm" [data-confirm-title] [data-confirm-body]
 *     [data-confirm-label] [data-confirm-tone="danger|primary"]
 *   class="js-delete" data-name="..."   (a preset "Remove {name}?" confirm -
 *     kept for back-compat with existing markup, same as before)
 *   class="js-prompt" data-prompt-label data-prompt-name
 *     [data-prompt-type] [data-prompt-pattern] [data-prompt-maxlength]
 *     [data-confirm-title] [data-confirm-body] [data-confirm-label]
 *     - the typed value is written into a hidden input named
 *       data-prompt-name before the form submits.
 */
'use strict';

(function () {
  const modal = document.getElementById('cf-modal');
  if (!modal) return;

  const dialog     = modal.querySelector('.cf-modal__dialog');
  const iconBox    = document.getElementById('cf-modal-icon');
  const iconEl     = iconBox.querySelector('i');
  const titleEl    = document.getElementById('cf-modal-title');
  const bodyEl     = document.getElementById('cf-modal-body');
  const fieldBox   = document.getElementById('cf-modal-field');
  const fieldLabel = document.getElementById('cf-modal-field-label');
  const inputEl    = document.getElementById('cf-modal-input');
  const fieldErr   = document.getElementById('cf-modal-field-err');
  const confirmBtn = document.getElementById('cf-modal-confirm');
  const cancels    = modal.querySelectorAll('[data-cf-cancel]');

  let pendingForm = null;
  let pendingPromptName = null;
  let lastFocus = null;

  function open() {
    lastFocus = document.activeElement;
    modal.hidden = false;
    document.body.classList.add('cf-modal-open');
    requestAnimationFrame(() => modal.classList.add('is-open'));
    (pendingPromptName ? inputEl : confirmBtn).focus();
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
    document.body.classList.remove('cf-modal-open');
    confirmBtn.disabled = false;
    confirmBtn.textContent = confirmBtn.dataset.defaultLabel || 'Confirm';
    inputEl.value = '';
    fieldErr.hidden = true;
    pendingForm = null;
    pendingPromptName = null;
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function onKey(e) {
    if (e.key === 'Escape') { close(); return; }
    if (e.key === 'Tab') {
      const focusable = Array.from(modal.querySelectorAll('button, input')).filter((el) => !el.hidden && el.offsetParent !== null);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  function configureFrom(form, { promptName = null } = {}) {
    const tone = form.dataset.confirmTone === 'primary' ? 'primary' : 'danger';
    iconBox.className = 'cf-modal__icon' + (tone === 'primary' ? ' cf-modal__icon--primary' : '');
    iconEl.className = tone === 'primary' ? 'bi bi-question-circle-fill' : 'bi bi-exclamation-triangle-fill';

    titleEl.textContent = form.dataset.confirmTitle || 'Are you sure?';
    bodyEl.textContent = form.dataset.confirmBody || form.dataset.confirm || 'This action cannot be undone.';

    const label = form.dataset.confirmLabel || (promptName ? 'Save' : 'Confirm');
    confirmBtn.textContent = label;
    confirmBtn.dataset.defaultLabel = label;
    confirmBtn.className = 'cf-modal__btn ' + (tone === 'primary' ? 'cf-modal__btn--primary' : 'cf-modal__btn--danger');

    if (promptName) {
      fieldBox.hidden = false;
      fieldLabel.textContent = form.dataset.promptLabel || 'Enter a value:';
      inputEl.type = form.dataset.promptType || 'text';
      if (form.dataset.promptPattern) inputEl.pattern = form.dataset.promptPattern; else inputEl.removeAttribute('pattern');
      if (form.dataset.promptMaxlength) inputEl.maxLength = Number(form.dataset.promptMaxlength); else inputEl.removeAttribute('maxlength');
      fieldErr.hidden = true;
    } else {
      fieldBox.hidden = true;
    }
  }

  function openForConfirm(form) {
    pendingForm = form;
    pendingPromptName = null;
    configureFrom(form);
    open();
  }

  function openForPrompt(form) {
    pendingForm = form;
    pendingPromptName = form.dataset.promptName || null;
    configureFrom(form, { promptName: pendingPromptName });
    open();
  }

  confirmBtn.addEventListener('click', () => {
    if (!pendingForm) return;

    if (pendingPromptName) {
      const val = inputEl.value.trim();
      const pattern = inputEl.pattern ? new RegExp('^(?:' + inputEl.pattern + ')$') : null;
      if (!val || (pattern && !pattern.test(val))) {
        fieldErr.textContent = pendingForm.dataset.promptError || 'Enter a valid value.';
        fieldErr.hidden = false;
        inputEl.focus();
        return;
      }
      let hidden = pendingForm.querySelector('input[name="' + pendingPromptName + '"]');
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = pendingPromptName;
        pendingForm.appendChild(hidden);
      }
      hidden.value = val;
    }

    const form = pendingForm;
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Please wait...';
    form.dataset.cfConfirmed = '1';
    form.submit();
    close();
  });

  cancels.forEach((el) => el.addEventListener('click', close));

  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.dataset.cfConfirmed === '1') return; // already confirmed, let it through

    if (form.classList.contains('js-prompt')) {
      e.preventDefault();
      openForPrompt(form);
      return;
    }

    if (form.classList.contains('js-delete')) {
      const name = form.dataset.name || 'this item';
      if (!form.dataset.confirmBody) form.dataset.confirmBody = `Remove ${name}? This cannot be undone from here.`;
      if (!form.dataset.confirmTitle) form.dataset.confirmTitle = 'Remove this?';
      e.preventDefault();
      openForConfirm(form);
      return;
    }

    if (form.classList.contains('js-confirm')) {
      e.preventDefault();
      openForConfirm(form);
    }
  });
})();
