<?php
/**
 * field/components/announcement/announcement.php
 *
 * The admin->employee announcement popup. Included ONCE, by
 * field/components/footer/footer.php, so it is present on every field page.
 *
 * How it works:
 *   - the markup below is an empty, hidden modal
 *   - the script polls field/api/announcement.php on load, then every ~8s
 *     while the page is visible, and again on focus / tap - so a just-posted
 *     announcement shows on its own within a few seconds, no click needed
 *   - when that returns an announcement the employee has not closed, the
 *     title + body are filled (via textContent - never innerHTML, so an
 *     announcement can never inject markup/script into the field app) and
 *     the modal is shown
 *   - Close (the x or the button) POSTs to field/api/announcement-dismiss.php
 *     and hides it; that announcement will not reopen (an admin EDIT clears
 *     the dismissal, so a revised message shows again)
 *
 * No external assets. The CSRF token for the dismiss POST is rendered in
 * once, here.
 */

declare(strict_types=1);
?>
<link rel="stylesheet" href="<?= e(asset_url(APP_URL . '/field/components/announcement/css/announcement.css')) ?>">

<div class="fann" id="fann" hidden aria-hidden="true">
  <div class="fann__backdrop" data-fann-close></div>
  <div class="fann__card" role="dialog" aria-modal="true" aria-labelledby="fann-title" aria-describedby="fann-body">
    <button type="button" class="fann__x" data-fann-close aria-label="Close">&times;</button>
    <div class="fann__head">
      <span class="fann__icon" aria-hidden="true"><i class="bi bi-megaphone-fill"></i></span>
      <h2 class="fann__title" id="fann-title"></h2>
    </div>
    <p class="fann__body" id="fann-body"></p>
    <button type="button" class="fann__ok" data-fann-close>Got it</button>
  </div>
</div>

<script>
  (function () {
    'use strict';

    var API_GET     = <?= json_encode(APP_URL . '/field/api/announcement.php') ?>;
    var API_DISMISS = <?= json_encode(APP_URL . '/field/api/announcement-dismiss.php') ?>;
    var CSRF        = <?= json_encode(csrf_token()) ?>;
    // Short interval so a just-posted announcement shows on its own, on the
    // screen the employee is already sitting on, within a few seconds - no tap
    // or navigation needed. One tiny GET; at 40 employees this is nothing.
    var POLL_MS     = 8000;

    var modal = document.getElementById('fann');
    if (!modal) return;
    var titleEl = document.getElementById('fann-title');
    var bodyEl  = document.getElementById('fann-body');

    var shownId = null;   // the announcement id currently on screen
    var dismissedIds = {}; // ids closed this page-load (belt-and-braces vs a racing poll)
    var timer = null;

    function show(a) {
      if (modal.hidden === false && shownId === a.id) return; // already showing this one
      shownId = a.id;
      titleEl.textContent = a.title;   // textContent, never innerHTML
      bodyEl.textContent  = a.body;
      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('fann-open');
    }

    function hide() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('fann-open');
    }

    function dismiss() {
      var id = shownId;
      hide();
      if (id == null) return;
      dismissedIds[id] = true;
      var body = new URLSearchParams({ id: String(id), csrf_token: CSRF });
      fetch(API_DISMISS, { method: 'POST', body: body, credentials: 'same-origin' })
        .catch(function () { /* a failed dismiss just means it pops again next poll - harmless */ });
    }

    modal.addEventListener('click', function (e) {
      if (e.target.hasAttribute && e.target.hasAttribute('data-fann-close')) dismiss();
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && modal.hidden === false) dismiss();
    });

    var inFlight = false;
    function poll() {
      if (document.hidden || inFlight) return; // background, or a poll already running
      inFlight = true;
      fetch(API_GET, { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) return;
          var a = data.announcement;
          if (a && a.id != null && !dismissedIds[a.id]) {
            show(a);
          } else if (!a && modal.hidden === false) {
            // the live announcement was turned off / deleted by the admin
            hide();
            shownId = null;
          }
        })
        .catch(function () { /* offline / transient - try again next tick */ })
        .then(function () { inFlight = false; });
    }

    // Check immediately on load, then keep checking on a short interval so it
    // pops on its own while the employee sits still. Also re-check the moment
    // the app/tab regains focus (came back from another app, unlocked phone)
    // and right after any tap - both make it feel instant without a real
    // push connection.
    poll();
    timer = setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    window.addEventListener('focus', poll);
    window.addEventListener('pageshow', poll); // bfcache restore (back button)

    // A tap anywhere triggers a check too - throttled so a fast tapper can't
    // spam the endpoint. This is what makes it appear "the instant" someone
    // touches the screen after you post.
    var lastTapPoll = 0;
    document.addEventListener('pointerdown', function () {
      var now = Date.now();
      if (now - lastTapPoll > 3000) { lastTapPoll = now; poll(); }
    }, { passive: true });
  })();
</script>
