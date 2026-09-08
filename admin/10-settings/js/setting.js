/* admin/10-settings/js/setting.js
 * - keeps each 12-hour time picker's hidden 24h input in sync with its
 *   Hour/Minute/AM-PM <select>s (see setting.php's 'time12' field markup) -
 *   the hidden input is what actually gets posted/validated
 * - greys out the check-in window detail fields while the master toggle is
 *   off, and warns before leaving with unsaved edits
 * Server-side validation is authoritative either way. */
'use strict';

(function () {
  // ---- 12-hour time pickers: sync 3 <select>s -> one hidden 24h input ----
  document.querySelectorAll('.st-time12').forEach(function (wrap) {
    var hidden = wrap.querySelector('input[type="hidden"]');
    var hourEl = wrap.querySelector('[data-time12="hour"]');
    var minEl  = wrap.querySelector('[data-time12="minute"]');
    var ampmEl = wrap.querySelector('[data-time12="ampm"]');
    if (!hidden || !hourEl || !minEl || !ampmEl) return;

    function sync() {
      var h12 = parseInt(hourEl.value, 10);
      var min = parseInt(minEl.value, 10);
      var isPM = ampmEl.value === 'PM';
      var h24 = h12 % 12; // 12 -> 0
      if (isPM) h24 += 12;
      hidden.value = String(h24).padStart(2, '0') + ':' + String(min).padStart(2, '0');
      hidden.dispatchEvent(new Event('change', { bubbles: true })); // so the dirty-form guard below still sees it
    }
    [hourEl, minEl, ampmEl].forEach(function (el) { el.addEventListener('change', sync); });
  });

  // ---- grey out the check-in window detail fields while the toggle is off ----
  var master = document.querySelector('input[name="attendance_cutoff_enabled"]');
  var depWraps = ['attendance_checkin_open_time', 'attendance_cutoff_time']
    .map(function (n) { return document.querySelector('[data-time12-for="' + n + '"]'); })
    .filter(Boolean);

  function syncMaster() {
    if (!master) return;
    var on = master.checked;
    depWraps.forEach(function (wrap) {
      wrap.querySelectorAll('select').forEach(function (el) { el.disabled = !on; });
      var field = wrap.closest('.st-field');
      if (field) field.style.opacity = on ? '' : '0.55';
    });
  }
  if (master) {
    master.addEventListener('change', syncMaster);
    syncMaster();
  }

  // ---- unsaved-changes guard ----
  var form = document.querySelector('.st-main');
  if (form) {
    var dirty = false;
    form.addEventListener('change', function () { dirty = true; });
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) {
      if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });
  }
})();
