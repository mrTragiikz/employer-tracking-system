<?php
/**
 * admin/components/confirm-modal/confirm-modal.php
 *
 * One shared, generic confirmation modal for the WHOLE admin area -
 * replaces every plain window.confirm()/window.prompt() (the "192.168.x.x
 * says..." browser popup) with a styled dialog matching the app's design.
 * Same visual pattern as the existing logout-modal in
 * admin/components/header/header.php, generalized to work for any form.
 *
 * Usage - mark a form (not a link) with class="js-confirm" and these
 * data attributes (see js/confirm-modal.js for the full list):
 *   data-confirm-title   heading text (default: "Are you sure?")
 *   data-confirm-body    the message (default: "This action cannot be undone.")
 *   data-confirm-label   confirm button text (default: "Confirm")
 *   data-confirm-tone    "danger" (red, default) or "primary" (brown) -
 *                        for a destructive vs. a neutral confirm action
 *
 * For a form that ALSO needs a typed value first (the old window.prompt()
 * cases, e.g. "type the new 4-digit PIN"), mark it class="js-prompt" and add:
 *   data-prompt-label    the input's label text
 *   data-prompt-name     the hidden input name to fill with the typed value
 *   data-prompt-type     "text" (default) or "password"
 *   data-prompt-pattern  optional HTML pattern attribute (e.g. "\d{4}")
 *   data-prompt-maxlength optional maxlength
 *
 * Include ONCE per page, right after header.php (same include point as any
 * other shared component) - admin/components/footer/footer.php does NOT
 * include this automatically since not every page needs it, but most do;
 * a page that has no .js-confirm/.js-prompt forms can skip it.
 */

declare(strict_types=1);
?>
<div class="cf-modal" id="cf-modal" hidden>
  <div class="cf-modal__backdrop" data-cf-cancel></div>
  <div class="cf-modal__dialog" role="dialog" aria-modal="true"
       aria-labelledby="cf-modal-title" aria-describedby="cf-modal-body">
    <div class="cf-modal__icon" id="cf-modal-icon" aria-hidden="true">
      <i class="bi bi-exclamation-triangle-fill"></i>
    </div>
    <h2 class="cf-modal__title" id="cf-modal-title">Are you sure?</h2>
    <p class="cf-modal__body" id="cf-modal-body">This action cannot be undone.</p>

    <div class="cf-modal__field" id="cf-modal-field" hidden>
      <label class="cf-modal__field-label" id="cf-modal-field-label" for="cf-modal-input"></label>
      <input class="cf-modal__input" id="cf-modal-input" type="text" autocomplete="off">
      <span class="cf-modal__field-err" id="cf-modal-field-err" hidden></span>
    </div>

    <div class="cf-modal__actions">
      <button type="button" class="cf-modal__btn cf-modal__btn--ghost" data-cf-cancel>
        Cancel
      </button>
      <button type="button" class="cf-modal__btn cf-modal__btn--danger" id="cf-modal-confirm">
        Confirm
      </button>
    </div>
  </div>
</div>
