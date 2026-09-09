/**
 * admin/02-employees/js/live-track.js
 *
 * The "Live Track" button on the employee detail page. Opens a full-screen
 * Mapbox modal that follows the worker's bike:
 *   - LIVE (checked in)    : a moving marker + the trail so far, polled every
 *                            `interval_s` seconds, "updated Ns ago"
 *   - CHECKED OUT          : the full day's path, frozen
 *   - NOT STARTED / WEB    : a plain message, no map
 *
 * Reads window.TRACK_LIVE = { endpoint, token } set inline by view.php.
 * Polling stops when the modal is closed OR the browser tab is hidden -
 * so an admin who leaves the tab open does not hammer the server.
 *
 * Display only. Nothing here feeds the audit.
 */
(function () {
  'use strict';

  var CFG = window.TRACK_LIVE || {};
  var btn = document.getElementById('emp-live-track');
  if (!btn || !CFG.endpoint) return;

  var STATE_TEXT = {
    live: 'Live',
    checked_out: 'Checked out',
    not_started: 'Not checked in today',
    web_user: 'Uses the web app - no live tracking',
    tracking_off: 'Live tracking is turned off in Settings'
  };

  btn.addEventListener('click', function () {
    openModal(btn.getAttribute('data-live-track-id'), btn.getAttribute('data-live-track-name'));
  });

  function openModal(empId, empName) {
    var overlay = document.createElement('div');
    overlay.className = 'lt-modal';
    overlay.innerHTML =
      '<div class="lt-modal__dialog">' +
        '<div class="lt-modal__head">' +
          '<div class="lt-modal__title">' +
            '<span class="lt-dot" id="lt-dot"></span>' +
            '<strong>' + escapeHtml(empName || 'Employee') + '</strong>' +
            '<span class="lt-modal__state" id="lt-state">Loading...</span>' +
          '</div>' +
          '<button type="button" class="lt-modal__close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="lt-modal__map" id="lt-map">' +
          '<div class="lt-modal__msg" id="lt-msg">Loading live location...</div>' +
        '</div>' +
        '<div class="lt-modal__foot">' +
          '<span id="lt-foot-left">-</span>' +
          '<span id="lt-foot-right"></span>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    var closed = false;
    var pollTimer = null;
    var agoTimer = null;
    var map = null;
    var trailSource = null;
    var bikeMarker = null;
    var checkpointMarkers = [];
    var lastPingIso = null;
    var lastPayloadAt = null;
    var intervalMs = 90000;
    var allPoints = [];

    function close() {
      if (closed) return;
      closed = true;
      if (pollTimer) clearTimeout(pollTimer);
      if (agoTimer) clearInterval(agoTimer);
      document.removeEventListener('visibilitychange', onVis);
      document.removeEventListener('keydown', onKey);
      if (map) { try { map.remove(); } catch (e) {} }
      overlay.remove();
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    overlay.querySelector('.lt-modal__close').addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });

    function onVis() {
      if (document.hidden) {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
      } else if (!closed && !pollTimer) {
        poll();
      }
    }
    document.addEventListener('visibilitychange', onVis);

    // "updated Ns ago" ticker
    agoTimer = setInterval(function () {
      if (!lastPayloadAt) return;
      var secs = Math.round((Date.now() - lastPayloadAt) / 1000);
      var right = document.getElementById('lt-foot-right');
      if (right) right.textContent = 'updated ' + humanAgo(secs) + ' ago';
    }, 1000);

    function url(extra) {
      var u = CFG.endpoint + '?id=' + encodeURIComponent(empId);
      if (extra) u += extra;
      return u;
    }

    function poll() {
      if (closed) return;
      var u = url(lastPingIso ? '&since=' + encodeURIComponent(lastPingIso) : '');
      fetch(u, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (closed || !data || !data.ok) { scheduleNext(); return; }
          apply(data);
          scheduleNext(data.state);
        })
        .catch(function () { scheduleNext(); });
    }

    function scheduleNext(state) {
      if (closed || document.hidden) return;
      // stop polling once the day is closed - the path is final
      if (state === 'checked_out' || state === 'not_started' ||
          state === 'web_user' || state === 'tracking_off') {
        return;
      }
      if (pollTimer) clearTimeout(pollTimer);
      pollTimer = setTimeout(poll, intervalMs);
    }

    function apply(data) {
      lastPayloadAt = Date.now();
      if (data.interval_s) intervalMs = Math.max(5, data.interval_s) * 1000;

      var dot = document.getElementById('lt-dot');
      var stEl = document.getElementById('lt-state');
      var msg = document.getElementById('lt-msg');
      var footL = document.getElementById('lt-foot-left');

      dot.className = 'lt-dot lt-dot--' + data.state;
      stEl.textContent = STATE_TEXT[data.state] || data.state;

      var noMap = (data.state === 'not_started' || data.state === 'web_user' || data.state === 'tracking_off');
      if (noMap) {
        if (map) { try { map.remove(); } catch (e) {} map = null; }
        if (msg) {
          msg.style.display = '';
          msg.textContent =
            data.state === 'web_user'
              ? 'This employee uses the web field app. A browser cannot record location in the background, so there is no live route to show.'
            : data.state === 'tracking_off'
              ? 'Live location tracking is turned off. Turn it on in Settings to use this.'
              : 'This employee has not checked in today. Their live route will appear here once they do.';
        }
        footL.textContent = '-';
        return;
      }

      // merge new points
      var incoming = Array.isArray(data.points) ? data.points : [];
      if (incoming.length) {
        allPoints = allPoints.concat(incoming);
        lastPingIso = incoming[incoming.length - 1].recorded_at;
      }

      var footBits = [];
      footBits.push(allPoints.length + ' point' + (allPoints.length === 1 ? '' : 's'));
      if (data.speed_kmh != null) footBits.push(Math.round(data.speed_kmh) + ' km/h');
      if (data.checked_in_at) footBits.push('in ' + fmtTime(data.checked_in_at));
      if (data.checked_out_at) footBits.push('out ' + fmtTime(data.checked_out_at));
      footL.textContent = footBits.join('  ·  ');

      ensureMap(data, function () {
        drawTrail();
        drawCheckpoints(data.checkpoints || []);
        moveBike();
      });
    }

    function ensureMap(data, ready) {
      if (map) { ready(); return; }
      if (typeof mapboxgl === 'undefined' || !CFG.token) {
        var msg = document.getElementById('lt-msg');
        if (msg) { msg.style.display = ''; msg.textContent = 'Map could not load.'; }
        return;
      }
      var msgEl = document.getElementById('lt-msg');
      if (msgEl) msgEl.style.display = 'none';

      var center = firstCoord(data) || [85.32, 27.7];
      mapboxgl.accessToken = CFG.token;
      map = new mapboxgl.Map({
        container: 'lt-map',
        style: 'mapbox://styles/mapbox/standard',
        center: center,
        zoom: 15
      });
      map.addControl(new mapboxgl.NavigationControl(), 'bottom-right');
      map.on('load', function () {
        map.resize();
        map.addSource('lt-trail', {
          type: 'geojson',
          data: { type: 'Feature', geometry: { type: 'LineString', coordinates: [] } }
        });
        map.addLayer({
          id: 'lt-trail-case', type: 'line', source: 'lt-trail',
          layout: { 'line-join': 'round', 'line-cap': 'round' },
          paint: { 'line-color': '#ffffff', 'line-width': 6 }
        });
        map.addLayer({
          id: 'lt-trail-core', type: 'line', source: 'lt-trail',
          layout: { 'line-join': 'round', 'line-cap': 'round' },
          paint: { 'line-color': '#1a53d1', 'line-width': 3.5 }
        });
        trailSource = map.getSource('lt-trail');
        ready();
      });
    }

    function drawTrail() {
      if (!trailSource) return;
      var coords = allPoints.map(function (p) { return [p.lng, p.lat]; });
      trailSource.setData({ type: 'Feature', geometry: { type: 'LineString', coordinates: coords } });
      if (coords.length >= 2) {
        try {
          var b = new mapboxgl.LngLatBounds();
          coords.forEach(function (c) { b.extend(c); });
          map.fitBounds(b, { padding: 60, maxZoom: 16, duration: 400 });
        } catch (e) {}
      }
    }

    function drawCheckpoints(cps) {
      checkpointMarkers.forEach(function (m) { m.remove(); });
      checkpointMarkers = [];
      cps.forEach(function (c) {
        if (c.lat == null || c.lng == null) return;
        var color = c.kind === 'checkin' ? '#26964a' : (c.kind === 'checkout' ? '#dc4b4b' : '#3977c9');
        var label = c.kind === 'checkin' ? 'A' : (c.kind === 'checkout' ? 'Z' : String(c.no || ''));
        var el = document.createElement('div');
        el.className = 'lt-cp';
        el.style.background = color;
        el.textContent = label;
        var mk = new mapboxgl.Marker({ element: el, anchor: 'center' })
          .setLngLat([c.lng, c.lat])
          .setPopup(new mapboxgl.Popup({ offset: 16 }).setText(c.label || ''))
          .addTo(map);
        checkpointMarkers.push(mk);
      });
    }

    function moveBike() {
      if (!allPoints.length) return;
      var last = allPoints[allPoints.length - 1];
      var ll = [last.lng, last.lat];
      if (!bikeMarker) {
        var el = document.createElement('div');
        el.className = 'lt-bike';
        el.innerHTML = '<i class="bi bi-bicycle"></i>';
        bikeMarker = new mapboxgl.Marker({ element: el, anchor: 'center' }).setLngLat(ll).addTo(map);
      } else {
        bikeMarker.setLngLat(ll);
      }
    }

    function firstCoord(data) {
      if (Array.isArray(data.points) && data.points.length) {
        return [data.points[0].lng, data.points[0].lat];
      }
      var cps = data.checkpoints || [];
      for (var i = 0; i < cps.length; i++) {
        if (cps[i].lat != null) return [cps[i].lng, cps[i].lat];
      }
      return null;
    }

    poll();
  }

  // ---- helpers ----
  function humanAgo(secs) {
    if (secs < 5) return 'just now';
    if (secs < 60) return secs + 's';
    var m = Math.floor(secs / 60);
    if (m < 60) return m + ' min';
    var h = Math.floor(m / 60);
    return h + ' hr';
  }
  function fmtTime(iso) {
    try {
      return new Date(iso).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    } catch (e) { return ''; }
  }
  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }
})();
