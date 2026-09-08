/* field/visit/js/visit.js
 * field/visit/index.php (My Visits) - the "Log a Visit" modal (only present
 * when no visit is currently open):
 * - open/close the sheet
 * - GPS location grabbed on tap (the red "Click Me" button), never typed
 * - live camera photo preview for the shop evidence shot
 * - gate the Confirm button until shop name + area + location + photo are set
 * - loading state on submit, with a double-submit guard
 *
 * The "View" button on each visit row is a plain link to its own page
 * (field/visit/view.php) - no modal, no JS needed for it.
 */
(function () {
  'use strict';

  var openBtn  = document.getElementById('fh-visit-open');
  var modal    = document.getElementById('fh-visit-modal');
  var closeBtn = document.getElementById('fh-visit-close');
  if (!modal) return;

  function openModal() { modal.hidden = false; }
  function closeModal() { modal.hidden = true; }

  if (openBtn) openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', function (e) {
    if (e.target === modal) closeModal();
  });

  // If the page reloaded with field errors (server-side validation failed),
  // the form re-renders with the modal present but hidden - reopen it so the
  // employee sees what went wrong instead of a silently closed sheet.
  var hasErrors = modal.querySelector('.fa-flash, .fa-field-err');
  if (hasErrors) openModal();

  var shopInput  = document.getElementById('fv-shop');
  var areaInput  = document.getElementById('fv-area');
  var latInput   = document.getElementById('fv-lat');
  var lngInput   = document.getElementById('fv-lng');
  var accInput   = document.getElementById('fv-accuracy');
  var box        = document.getElementById('fv-location-box');
  var locateBtn  = document.getElementById('fv-locate-btn');
  var submit     = document.getElementById('fv-submit');
  var shotInput  = document.getElementById('fv-shot-input');
  var shotEmpty  = document.getElementById('fv-shot-empty');
  var shotPreview = document.getElementById('fv-shot-preview');

  var state = { hasLocation: false, hasPhoto: false };

  function refreshSubmit() {
    var hasShop = shopInput && shopInput.value.trim() !== '';
    var hasArea = areaInput && areaInput.value.trim() !== '';
    submit.disabled = !(hasShop && hasArea && state.hasLocation && state.hasPhoto);
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
        showLocation('<i class="bi bi-exclamation-triangle-fill"></i><span>Location access was denied. Tap the button to try again.</span>', 'is-bad');
        state.hasLocation = false;
        if (locateBtn) { locateBtn.disabled = false; locateBtn.classList.remove('is-loading'); }
        refreshSubmit();
      },
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
  }

  if (locateBtn) locateBtn.addEventListener('click', captureLocation);
  if (shopInput) shopInput.addEventListener('input', refreshSubmit);
  if (areaInput) areaInput.addEventListener('input', refreshSubmit);

  if (shotInput) {
    shotInput.addEventListener('change', function () {
      if (!shotInput.files || !shotInput.files[0]) {
        state.hasPhoto = false;
        shotEmpty.hidden = false;
        shotPreview.hidden = true;
        refreshSubmit();
        return;
      }

      // Shrink the shop evidence shot to ~70 KB in the browser before it's
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

  var form = document.querySelector('.fh-visit-form');
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
