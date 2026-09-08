/* admin/08-evidence-photos/js/evidence-photos.js
 * - date-range <select>: show/hide the "specific date" input, submit on change
 * - lightbox: click a photo to view it full size */
'use strict';

/* Called inline from the range <select>'s onchange. */
function epRangeChange(sel) {
  var form = sel.form;
  var onDate = form.querySelector('.js-on-date');
  if (sel.value === 'on') {
    if (onDate) {
      onDate.hidden = false;
      if (!onDate.value) { onDate.focus(); return; } // wait for a date
    }
  } else if (onDate) {
    onDate.hidden = true;
    onDate.value = '';
  }
  form.submit();
}

(function () {
  var box   = document.getElementById('ep-lightbox');
  if (!box) return;

  var img   = document.getElementById('ep-lightbox-img');
  var shop  = document.getElementById('ep-lightbox-shop');
  var meta  = document.getElementById('ep-lightbox-meta');
  var lastFocus = null;

  function open(trigger) {
    lastFocus = trigger;
    img.src = trigger.dataset.src;
    img.alt = trigger.dataset.shop || '';
    shop.textContent = trigger.dataset.shop || 'Photo';
    var bits = [trigger.dataset.kind, trigger.dataset.employee, trigger.dataset.when]
      .filter(Boolean);
    meta.textContent = bits.join('  ·  ');
    box.hidden = false;
    requestAnimationFrame(function () { box.classList.add('is-open'); });
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey);
    box.querySelector('[data-lightbox-close]').focus();
  }

  function close() {
    box.classList.remove('is-open');
    document.removeEventListener('keydown', onKey);
    document.body.style.overflow = '';
    var done = function () {
      box.hidden = true;
      img.src = '';
      box.removeEventListener('transitionend', done);
    };
    box.addEventListener('transitionend', done);
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function onKey(e) {
    if (e.key === 'Escape') close();
  }

  document.querySelectorAll('[data-lightbox]').forEach(function (btn) {
    btn.addEventListener('click', function () { open(btn); });
  });
  box.querySelector('[data-lightbox-close]').addEventListener('click', close);
  box.addEventListener('click', function (e) {
    if (e.target === box) close();
  });
})();
