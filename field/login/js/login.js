/* field/login/js/login.js
   - generate/persist a stable per-browser device_id (localStorage), used to
     enforce the one-device login rule (rule 10) server-side
   - show / hide the PIN
   - loading state (spinner) on submit, with a double-submit guard */
(function () {
  'use strict';

  /* ---- device id: created once, persisted, sent with every login ---- */
  var DEVICE_KEY = 'track_field_device_id';
  var deviceInput = document.getElementById('fl-device-id');

  function makeDeviceId() {
    if (window.crypto && window.crypto.randomUUID) {
      return window.crypto.randomUUID();
    }
    // fallback for older browsers
    return 'dev-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12);
  }

  function getDeviceId() {
    try {
      var id = window.localStorage.getItem(DEVICE_KEY);
      if (!id) {
        id = makeDeviceId();
        window.localStorage.setItem(DEVICE_KEY, id);
      }
      return id;
    } catch (e) {
      // localStorage unavailable (private mode, blocked storage) - fall back
      // to a per-page-load id; the server will just re-bind every time,
      // which is a degraded but working experience rather than a dead form.
      return makeDeviceId();
    }
  }

  if (deviceInput) {
    deviceInput.value = getDeviceId();
  }

  /* ---- PIN show/hide ---- */
  var toggle = document.querySelector('[data-toggle-pin]');
  var pin = document.getElementById('fl-pin');

  if (toggle && pin) {
    toggle.addEventListener('click', function () {
      var reveal = pin.type === 'password';
      pin.type = reveal ? 'text' : 'password';
      toggle.querySelector('i').className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
      toggle.setAttribute('aria-label', reveal ? 'Hide PIN' : 'Show PIN');
      pin.focus();
    });
  }

  // digits only
  if (pin) {
    pin.addEventListener('input', function () {
      pin.value = pin.value.replace(/\D/g, '').slice(0, 4);
    });
  }
  var phone = document.getElementById('fl-phone');
  if (phone) {
    phone.addEventListener('input', function () {
      phone.value = phone.value.replace(/[^\d+ ()-]/g, '');
    });
  }

  /* ---- submit loading state ---- */
  var form = document.querySelector('.fl-fields');
  if (form) {
    var sent = false;
    form.addEventListener('submit', function (e) {
      if (sent) { e.preventDefault(); return; }
      sent = true;
      var btn = form.querySelector('.fl-submit');
      if (btn) {
        btn.classList.add('is-loading');
        btn.setAttribute('aria-busy', 'true');
      }
    });
  }

  /* ---- suspended screen: poll for unlock, auto-reload ----
     When an admin locks an employee, require_employee() kills the session
     and drops the phone here on ?suspended=1&p=<phone>. This page is static,
     so without this the employee stays stuck even after the admin unlocks.
     Poll status.php every 10s; the moment it says suspended:false, reload -
     the block clears and the normal login form shows. */
  var susp = document.querySelector('.fl-suspended[data-poll-phone]');
  if (susp) {
    var pollPhone = susp.getAttribute('data-poll-phone');
    var idleNote  = susp.querySelector('[data-poll-idle]');
    var tries = 0;
    // Show the "waiting..." line after the first check, so a phone that
    // reconnects to an already-unlocked account reloads instantly with no
    // flash of that text.
    // status.php sits at  <this page>/api/status.php  - build the URL from the
    // current path so it works whether the app is at the domain root or under
    // /track (dev). Ensure a trailing slash on the directory part first.
    var dir = window.location.pathname.replace(/[^/]*$/, ''); // .../field/login/
    var statusUrl = dir + 'api/status.php';
    var checkStatus = function () {
      tries++;
      fetch(
        statusUrl + '?p=' + encodeURIComponent(pollPhone) + '&_=' + Date.now(),
        { credentials: 'omit', cache: 'no-store' }
      )
        .then(function (r) { return r.ok ? r.json() : { suspended: true }; })
        .then(function (d) {
          if (d && d.suspended === false) {
            window.location.replace(window.location.pathname); // drop ?suspended -> clean login
            return;
          }
          if (idleNote && tries >= 1) { idleNote.hidden = false; }
        })
        .catch(function () { /* offline / server hiccup - just try again next tick */ });
    };
    checkStatus();
    setInterval(checkStatus, 10000);
  }
})();
