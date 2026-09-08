/* admin/02-employees/js/employee.js
 * - view: day/month picker toggles the right input + auto-submits
 * Filters + page-size submit via inline onchange in the markup.
 *
 * Confirm-before-destructive-action (.js-delete, .js-confirm) and the
 * "type a new PIN" prompt (.js-prompt) are handled globally by the shared
 * admin/components/confirm-modal - see that component instead of adding
 * per-section confirm/prompt logic here.
 */
'use strict';

/* The single-day / whole-month picker is now plain links + a small form that
   submits on change - no JS needed for it. */

/* ---- detail page: edit the Notes box ---- */
(function () {
  const card = document.querySelector('.notes-form');
  if (!card) return;
  const view   = document.querySelector('.notes-view');
  const editBtn = document.querySelector('.js-notes-edit');
  const cancelBtn = document.querySelector('.js-notes-cancel');

  const show = (editing) => {
    card.hidden = !editing;
    if (view) view.hidden = editing;
    if (editBtn) editBtn.hidden = editing;
    if (editing) card.querySelector('textarea').focus();
  };
  editBtn && editBtn.addEventListener('click', () => show(true));
  cancelBtn && cancelBtn.addEventListener('click', () => show(false));
})();

