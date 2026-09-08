/* admin/01-dashboard/js/dashboard.js
 *
 * Live dashboard: polls ?partial=live every POLL_MS and patches the stat
 * cards, the activity feed, Top Employees, Latest Visits, and the selected
 * Employee's route map in place - no page reload, same approach as
 * admin/07-alerts' feed poll (see its alert.js), just carrying more than one
 * fragment per tick since the dashboard has more than one thing that can
 * change while it's open.
 */
'use strict';

(function () {
  var POLL_MS = 8000;

  var root = document.getElementById('todays-route');
  if (!root) return; // not on the dashboard page

  var statusDot = document.getElementById('dash-live-dot');
  var activitiesBox = document.getElementById('dash-activities');
  var routeEmployeeId = root.dataset.routeEmployee || '0';

  function pollUrl() {
    var qs = 'partial=live';
    if (routeEmployeeId && routeEmployeeId !== '0') qs += '&route_employee=' + encodeURIComponent(routeEmployeeId);
    return window.location.pathname + '?' + qs;
  }

  function setStat(key, value) {
    var card = document.querySelector('.stat-card[data-stat="' + key + '"] [data-stat-value]');
    if (!card) return;
    if (card.textContent === value) return;
    card.textContent = value;
    card.closest('.stat-card').classList.add('is-new');
    setTimeout(function () {
      var c = document.querySelector('.stat-card[data-stat="' + key + '"]');
      if (c) c.classList.remove('is-new');
    }, 1400);
  }

  function knownActivityKeys() {
    var keys = {};
    activitiesBox.querySelectorAll('.activity[data-key]').forEach(function (el) {
      keys[el.dataset.key] = true;
    });
    return keys;
  }

  function applyActivities(html) {
    if (!activitiesBox) return;
    if (!html) {
      // server sent no rows - only replace if we don't already have some
      // (avoids flashing "No activity yet" over real rows on a hiccup)
      if (!activitiesBox.querySelector('.activity')) {
        activitiesBox.innerHTML = '<p class="stat-card__foot" id="dash-activities-empty">No activity yet today.</p>';
      }
      return;
    }

    var known = knownActivityKeys();
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    var incoming = Array.prototype.slice.call(tmp.querySelectorAll('.activity'));
    if (!incoming.length) return;

    var anyNew = incoming.some(function (el) { return !known[el.dataset.key]; });
    if (!anyNew && activitiesBox.querySelector('.activity')) return; // nothing changed, leave DOM (and any open state) alone

    var empty = activitiesBox.querySelector('#dash-activities-empty');
    if (empty) empty.remove();

    incoming.forEach(function (el) {
      if (!known[el.dataset.key]) el.classList.add('is-new');
    });
    activitiesBox.innerHTML = '';
    incoming.forEach(function (el) { activitiesBox.appendChild(el); });

    setTimeout(function () {
      activitiesBox.querySelectorAll('.activity.is-new').forEach(function (el) { el.classList.remove('is-new'); });
    }, 1600);
  }

  function applyIfChanged(el, html) {
    if (!el || html == null) return;
    if (el.innerHTML.trim() === html.trim()) return; // identical - skip the repaint
    el.innerHTML = html;
  }

  function applyRoute(route) {
    var mapEl = document.getElementById('mb-dashboard-route');
    if (!mapEl || !route || !route.points || route.points.length < 2) return;

    var next = JSON.stringify(route.points);
    if (mapEl.dataset.mapPoints === next) return; // no change - don't re-render the map for nothing

    mapEl.dataset.mapPoints = next;
    if (typeof TrackMapboxRoute !== 'undefined') TrackMapboxRoute.render('mb-dashboard-route');
  }

  function tick(label) { if (statusDot) statusDot.title = label; }

  function poll() {
    fetch(pollUrl(), { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
      .then(function (data) {
        if (data.stats) {
          setStat('total_employees', String(data.stats.total_employees));
          setStat('attended_today', String(data.stats.attended_today));
          setStat('visits_today', String(data.stats.visits_today));
        }
        applyActivities(data.activities_html);
        applyIfChanged(document.getElementById('dash-top-employees'), data.top_employees_html);
        applyIfChanged(document.getElementById('dash-latest-visits'), data.latest_visits_html);
        applyRoute(data.route);
        tick('live - updated just now');
      })
      .catch(function () { tick('live - retrying...'); });
  }

  var timer = null;
  function start() { stop(); timer = setInterval(poll, POLL_MS); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }

  // pause polling when the tab is hidden - resume (with an immediate catch-up
  // poll) when it's shown again, same behaviour as the alerts feed.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stop(); } else { poll(); start(); }
  });

  start();
})();
