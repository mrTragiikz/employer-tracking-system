/* field/checkinout/js/checkinout-lock.js
 *
 * Live-locks the check-in form the instant the check-in window closes,
 * with NO page reload and no tap needed - only loaded when the check-in
 * form is on screen AND the window policy is enabled (see index.php).
 *
 * Polls this same page's own ?partial=status endpoint every POLL_MS and,
 * the moment it reports blocked:true, replaces the whole #fa-checkin-live
 * form with the same "Too Late to Check In" card index.php itself renders
 * server-side for a fresh page load - keep the two in sync if either one's
 * copy/markup changes.
 *
 * Deliberately re-checks the SERVER's clock on every poll (via
 * attendance_checkin_blocked() on the backend) rather than counting down
 * locally against the phone's own clock - this app's fraud rules already
 * treat phone clocks as untrustworthy (server time only, see includes/
 * distance.php's Rule 4), and the same reasoning applies to locking the
 * check-in window: a phone with a wrong or deliberately changed clock must
 * never be the thing deciding the window is still open.
 */
'use strict';

(function () {
  var POLL_MS = 15000;

  var liveBox = document.getElementById('fa-checkin-live');
  if (!liveBox) return;

  var BLOCKED_HTML =
    '<section class="fa-card fa-card--blocked">' +
      '<div class="fa-card__head">' +
        '<span class="fa-card__icon fa-card__icon--bad"><i class="bi bi-x-circle-fill"></i></span>' +
        '<div>' +
          '<h2>Too Late to Check In</h2>' +
          '<p>You are too late for today\'s attendance. Sorry, you have been marked absent.</p>' +
        '</div>' +
      '</div>' +
    '</section>';

  var locked = false; // once true, the window is closed for the day - stop polling for good

  function poll() {
    if (locked) return;
    fetch(window.location.pathname + '?partial=status', { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (data) {
        if (data && data.blocked) {
          locked = true;
          liveBox.innerHTML = BLOCKED_HTML;
          stop();
        }
      })
      .catch(function () { /* a dropped poll just tries again next tick - the
                               server-side POST guard in checkin.php is the
                               real enforcement either way, this is only the
                               friendly live UI on top of it */ });
  }

  var timer = setInterval(poll, POLL_MS);
  function stop() { clearInterval(timer); }

  // pause polling when the tab is hidden, catch up immediately when shown
  // again - same pattern as the admin dashboard's live poll. Never restarts
  // once locked - there's nothing left to watch for.
  document.addEventListener('visibilitychange', function () {
    if (locked) return;
    if (document.hidden) { stop(); } else { poll(); timer = setInterval(poll, POLL_MS); }
  });
})();
