/* admin/login/js/login.js
   - show / hide the password
   - loading state (spinner) on submit, with a double-submit guard */
(function () {
  'use strict';

  var toggle = document.querySelector('[data-toggle-password]');
  var pw = document.getElementById('lg-password');

  if (toggle && pw) {
    toggle.addEventListener('click', function () {
      var reveal = pw.type === 'password';
      pw.type = reveal ? 'text' : 'password';
      toggle.querySelector('i').className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
      toggle.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
      var end = pw.value.length;
      pw.focus();
      try { pw.setSelectionRange(end, end); } catch (e) { /* ignore */ }
    });
  }

  var form = document.querySelector('.lg-fields');
  if (form) {
    var sent = false;
    form.addEventListener('submit', function (e) {
      if (sent) { e.preventDefault(); return; }
      sent = true;
      var btn = form.querySelector('.lg-submit');
      if (btn) {
        btn.classList.add('is-loading');
        // do NOT disable the button - a disabled submit control is left out of
        // the form submission on some browsers. The `sent` flag blocks repeats.
        btn.setAttribute('aria-busy', 'true');
      }
    });
  }
})();
