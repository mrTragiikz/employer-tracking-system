/* field/checkinout/js/checkinout.js
 * Shared behaviour for both the check-in form and the check-out form (only
 * one renders at a time, depending on today's state) - same IDs, same
 * pattern as field/attendance's original check-in JS:
 * - GPS location grabbed on tap (the red "Click Me" button), never typed
 * - live camera photo preview for the odometer shot
 * - gate the submit button until location + photo + KM are all present
 * - loading state on submit, with a double-submit guard
 */
(function () {
  'use strict';

  var latInput    = document.getElementById('fa-lat');
  var lngInput    = document.getElementById('fa-lng');
  var accInput    = document.getElementById('fa-accuracy');
  var deniedInput = document.getElementById('fa-location-denied');
  var box         = document.getElementById('fa-location-box');
  var locateBtn   = document.getElementById('fa-locate-btn');
  var submit      = document.getElementById('fa-submit');
  var kmInput     = document.getElementById('fa-km');
  var shotInput   = document.getElementById('fa-shot-input');
  if (!submit) return;

  var state = { hasLocation: false, hasPhoto: false };

  function refreshSubmit() {
    var hasKm = kmInput && kmInput.value.trim() !== '';
    submit.disabled = !(state.hasLocation && state.hasPhoto && hasKm);
  }

  function showLocation(html, cls) {
    box.hidden = false;
    box.className = 'fa-location ' + cls;
    box.innerHTML = html;
  }

  function captureLocation() {
    if (locateBtn) {
      locateBtn.disabled = true;
      locateBtn.classList.add('is-loading');
    }
    box.hidden = false;
    box.className = 'fa-location is-waiting';
    box.innerHTML = '';

    if (!('geolocation' in navigator)) {
      deniedInput.value = '1';
      showLocation('<i class="bi bi-exclamation-triangle-fill"></i><span>Location is not available on this device/browser.</span>', 'is-bad');
      state.hasLocation = false;
      if (locateBtn) { locateBtn.disabled = false; locateBtn.classList.remove('is-loading'); }
      refreshSubmit();
      return;
    }

    navigator.geolocation.getCurrentPosition(
      function (pos) {
        latInput.value = pos.coords.latitude;
        lngInput.value = pos.coords.longitude;
        accInput.value = pos.coords.accuracy != null ? Math.round(pos.coords.accuracy) : '';
        deniedInput.value = '0';
        showLocation(
          '<i class="bi bi-check-circle-fill"></i><span>' +
          pos.coords.latitude.toFixed(6) + ', ' + pos.coords.longitude.toFixed(6) +
          (pos.coords.accuracy != null ? ' &middot; accuracy &plusmn;' + Math.round(pos.coords.accuracy) + 'm' : '') +
          '</span>',
          'is-ok'
        );
        state.hasLocation = true;
        if (locateBtn) { locateBtn.disabled = false; locateBtn.classList.remove('is-loading'); locateBtn.hidden = true; }
        refreshSubmit();
      },
      function () {
        deniedInput.value = '1';
        showLocation('<i class="bi bi-exclamation-triangle-fill"></i><span>Location access was denied. Tap the button to try again.</span>', 'is-bad');
        state.hasLocation = false;
        if (locateBtn) { locateBtn.disabled = false; locateBtn.classList.remove('is-loading'); }
        refreshSubmit();
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
  }

  if (locateBtn) {
    locateBtn.addEventListener('click', captureLocation);
  }

  /* ---- odometer photo preview ---- */
  var shotEmpty   = document.getElementById('fa-shot-empty');
  var shotPreview = document.getElementById('fa-shot-preview');

  if (shotInput) {
    shotInput.addEventListener('change', function () {
      if (!shotInput.files || !shotInput.files[0]) {
        state.hasPhoto = false;
        shotEmpty.hidden = false;
        shotPreview.hidden = true;
        refreshSubmit();
        return;
      }

      // Shrink the camera shot to ~70 KB in the browser before it's
      // previewed or uploaded (the live cPanel host has no GD/Imagick).
      // Resolves even on failure, leaving the original file in place.
      var compress = (window.TrackPhotoCompress && window.TrackPhotoCompress.compress)
        ? window.TrackPhotoCompress.compress(shotInput)
        : Promise.resolve();

      compress.then(function () {
        var f = shotInput.files && shotInput.files[0];
        if (!f) {
          state.hasPhoto = false;
          shotEmpty.hidden = false;
          shotPreview.hidden = true;
          refreshSubmit();
          return;
        }
        shotPreview.src = URL.createObjectURL(f);
        shotPreview.hidden = false;
        shotEmpty.hidden = true;
        state.hasPhoto = true;
        refreshSubmit();
      });
    });
  }

  if (kmInput) {
    kmInput.addEventListener('input', refreshSubmit);
  }

  /* ---- submit loading state ---- */
  var form = document.querySelector('.fa-form');
  if (form) {
    var sent = false;
    form.addEventListener('submit', function (e) {
      if (sent) { e.preventDefault(); return; }
      sent = true;
      submit.classList.add('is-loading');
      submit.setAttribute('aria-busy', 'true');
    });
  }
})();
