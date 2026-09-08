/**
 * admin/components/mapbox/js/mapbox-route.js
 *
 * Renders one day's route (check-in -> visit 1 -> ... -> check-out) on a
 * Mapbox GL JS map. Shared by every admin page that shows this shape of
 * data: Employee Day Detail, Employee Overview (day mode), Routes & Map,
 * and the Dashboard's "Today's Route" widget.
 *
 * Usage: give a <div> a unique id and a `data-map-points` attribute holding
 * a JSON array of {kind, label, at, lat, lng, no?} in travel order (same
 * shape day_points()/employee_timeline() already produce), then call:
 *
 *   TrackMapboxRoute.render('my-div-id');
 *
 * Points use the app's own lat/lng field names throughout - this file does
 * the [lng, lat] flip Mapbox requires internally, so no other code needs to
 * know Mapbox's coordinate order is reversed from the rest of the app.
 *
 * THE ROUTE LINE FOLLOWS THE ROADS. A straight line is drawn first (map never
 * blank), then swapped for the real road path fetched from the Mapbox
 * Directions API - ONE call per hop (fetchRoadGeometry() fans out over
 * fetchOneHop(), so one un-routable hop only straightens itself, not the
 * whole day; the first/last hop also get a loose road-snap radius since they
 * touch the check-in / check-out point, often logged inside an office). Each
 * leg is then shifted a few metres to the RIGHT of its own direction of travel
 * (offsetLeg / LANE_OFFSET_METRES) - so when the employee drives out and
 * back on the same road, the two legs land on opposite sides like two lanes
 * and read as two separate lines, not one thick stroke with arrows both
 * ways. On any Directions failure the straight line stays. This is VISUAL
 * only - the real ROAD DISTANCE between stops is a SEPARATE server-side
 * figure (includes/distance.php road_km_real() -> visits.hop_road_km /
 * attendance.road_km) and is never derived from anything this file draws.
 */
(function () {
  'use strict';

  var MARK_COLORS = { checkin: '#26964a', visit: '#3977c9', checkout: '#dc4b4b' };

  // Pin-drop icon for the "exact location" modal's title (a specific place).
  var PIN_ICON_SVG = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" '
    + 'stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
    + '<path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/>'
    + '<circle cx="12" cy="9.5" r="2.3"/></svg>';

  // Crosshair/locate-target icon for the coordinates button - reads as
  // "locate/zoom to this point" rather than "this is a place", matching the
  // click action (opens a zoomed-in view centered exactly here).
  var LOCATE_ICON_SVG = '<svg viewBox="0 0 24 24" fill="none" '
    + 'stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'
    + '<circle cx="12" cy="12" r="3"/>'
    + '<path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>';

  function coordsButtonHtml(lat, lng) {
    return '<button type="button" class="mb-popup-coords" data-lat="' + lat + '" data-lng="' + lng + '">'
      + LOCATE_ICON_SVG + '<span>' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '</span></button>';
  }

  function markerLabel(p) {
    if (p.kind === 'checkin') return 'A';
    if (p.kind === 'checkout') return 'Z';
    return String(p.no || '');
  }

  function popupHtml(p) {
    var time = '';
    try {
      time = new Date(p.at.replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    } catch (e) { /* leave blank if the timestamp doesn't parse */ }
    return '<strong>' + escapeHtml(p.label) + '</strong><br>' + escapeHtml(time)
      + '<br>' + coordsButtonHtml(p.lat, p.lng);
  }

  /**
   * Wire up a just-opened popup's coordinate button to open the zoom-in
   * modal on click. routePoints (optional) is the WHOLE route's points
   * array (same {kind, label, at, lat, lng, no} shape as data-map-points),
   * in order - when given, the modal also draws the same route line AND
   * the same labeled A/1/2/.../Z pins (so a click from the route map shows
   * this point in full context), not just an isolated pin. Omit it for
   * contexts with no single route to show (e.g. the live team map, where
   * each pin is a different person, not one journey).
   */
  function wirePopupZoom(popup, token, routePoints) {
    var el = popup.getElement();
    if (!el) return;
    var btn = el.querySelector('.mb-popup-coords');
    if (!btn) return;
    btn.addEventListener('click', function () {
      openLocationModal(parseFloat(btn.getAttribute('data-lat')), parseFloat(btn.getAttribute('data-lng')), token, routePoints);
    });
  }

  /**
   * Full-screen-ish modal showing one exact point on a zoomed-in Mapbox
   * Satellite map (real aerial imagery, not drawn roads) - built fresh each
   * time and torn down on close, so it never fights the small route/team
   * map already on the page for map instances or memory. If routePoints is
   * given, also draws the full route line AND every labeled A/1/2/.../Z pin
   * (same styling as the main map, via addRouteLine()/buildPinElement()) so
   * the clicked point is shown in full context, not just as an isolated dot.
   */
  function openLocationModal(lat, lng, token, routePoints) {
    var overlay = document.createElement('div');
    overlay.className = 'mb-loc-modal';
    overlay.innerHTML =
      '<div class="mb-loc-modal__dialog">' +
        '<div class="mb-loc-modal__head">' +
          '<span class="mb-loc-modal__title">' + PIN_ICON_SVG + '<span>Exact location</span></span>' +
          '<button type="button" class="mb-loc-modal__close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="mb-loc-modal__frame"><div class="mb-loc-modal__map"></div></div>' +
        '<div class="mb-loc-modal__foot">' +
          '<span class="mb-loc-modal__coords">' + lat.toFixed(6) + ', ' + lng.toFixed(6) + '</span>' +
          '<button type="button" class="mb-loc-modal__close-btn">Close Map</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    // Guarded so close() is safe to call more than once - the backdrop
    // click, the close button, and Escape can all fire in overlapping ways
    // (e.g. a click on the button also bubbling to the backdrop listener),
    // and without this guard a second call tried to remove() the map/overlay
    // twice, throwing and leaving the first click looking like it did nothing.
    var closed = false;
    function close() {
      if (closed) return;
      closed = true;
      if (modalMap) modalMap.remove();
      overlay.remove();
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.mb-loc-modal__close').addEventListener('click', close);
    overlay.querySelector('.mb-loc-modal__close-btn').addEventListener('click', close);

    var hasRoute = Array.isArray(routePoints) && routePoints.length >= 2;
    var routeCoords = hasRoute ? routePoints.map(function (p) { return [p.lng, p.lat]; }) : null;

    mapboxgl.accessToken = token;
    var modalMap = new mapboxgl.Map({
      container: overlay.querySelector('.mb-loc-modal__map'),
      // Standard Satellite: real aerial imagery + the same actively-
      // maintained label/road engine as Standard, rather than the legacy
      // "Satellite Streets" classic style.
      style: 'mapbox://styles/mapbox/standard-satellite',
      center: [lng, lat],
      // 17, not 19 - some rural areas only have lower-resolution satellite
      // coverage, and pushing the default zoom past what's actually
      // available just shows blurry upscaled tiles. The viewer can still
      // zoom in further themselves if better imagery exists there. Only
      // used when there's no whole route to fit (see fitBounds below).
      zoom: 17
    });
    modalMap.addControl(new mapboxgl.NavigationControl(), 'bottom-right');
    applyCleanBasemap(modalMap);
    modalMap.on('load', function () {
      modalMap.resize();

      if (hasRoute) {
        addRouteLine(modalMap, routeCoords, token);

        // Every point gets its real labeled pin (A, 1, 2, ..., Z) - same
        // colors/labels as the main route map - so the clicked point is
        // shown in full A-to-Z context, not as an isolated dot. No separate
        // "clicked" marker on top of these: the clicked point IS one of
        // these points already, so a second pin at the same spot would just
        // stack redundantly on it.
        routePoints.forEach(function (p, i) {
          var pin = buildPinElement(MARK_COLORS[p.kind] || '#6f6862', markerLabel(p));
          new mapboxgl.Marker({ element: pin, anchor: 'bottom' })
            .setLngLat(routeCoords[i])
            .addTo(modalMap);
        });

        // Fit the WHOLE route in view (not just zoom to the clicked point) -
        // this modal is "see this point in context", not "see only this
        // point", so cutting off the rest of the day's journey would defeat
        // the purpose of drawing the line at all.
        var bounds = new mapboxgl.LngLatBounds();
        routeCoords.forEach(function (c) { bounds.extend(c); });
        modalMap.fitBounds(bounds, { padding: 60, maxZoom: 18, duration: 0 });
      } else {
        // No route context (e.g. opened from the live team map, where each
        // pin is a different person) - just a single highlighted pin at the
        // clicked point.
        new mapboxgl.Marker({ element: buildPinElement('#dc4b4b', ''), anchor: 'bottom' })
          .setLngLat([lng, lat])
          .addTo(modalMap);
      }
    });
  }

  /**
   * Full-screen-ish modal showing the WHOLE route (not one zoomed-in point) -
   * for a card's "expand to inspect" button. Same overlay/dialog chrome as
   * openLocationModal() (shares its .mb-loc-modal* CSS - see mapbox-route.css)
   * but on the normal road-map style (not satellite), sized to give the
   * whole route more room, with real popups on each pin (same as the small
   * card map) since inspecting is the whole point of opening this.
   */
  function openRouteModal(routePoints, token) {
    if (!Array.isArray(routePoints) || routePoints.length < 1) return;

    var overlay = document.createElement('div');
    overlay.className = 'mb-loc-modal';
    overlay.innerHTML =
      '<div class="mb-loc-modal__dialog mb-loc-modal__dialog--route">' +
        '<div class="mb-loc-modal__head">' +
          '<span class="mb-loc-modal__title">' + PIN_ICON_SVG + '<span>Route - check-in to check-out</span></span>' +
          '<button type="button" class="mb-loc-modal__close" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="mb-loc-modal__frame mb-loc-modal__frame--route"><div class="mb-loc-modal__map"></div></div>' +
        '<div class="mb-loc-modal__foot">' +
          '<span class="mb-loc-modal__coords">' + routePoints.length + ' points</span>' +
          '<button type="button" class="mb-loc-modal__close-btn">Close Map</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(overlay);

    var closed = false;
    function close() {
      if (closed) return;
      closed = true;
      if (modalMap) modalMap.remove();
      overlay.remove();
      document.removeEventListener('keydown', onKey);
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    overlay.querySelector('.mb-loc-modal__close').addEventListener('click', close);
    overlay.querySelector('.mb-loc-modal__close-btn').addEventListener('click', close);

    var coords = routePoints.map(function (p) { return [p.lng, p.lat]; });

    mapboxgl.accessToken = token;
    var modalMap = new mapboxgl.Map({
      container: overlay.querySelector('.mb-loc-modal__map'),
      style: 'mapbox://styles/mapbox/standard',
      center: coords[0],
      zoom: 15
    });
    modalMap.addControl(new mapboxgl.NavigationControl(), 'bottom-right');
    applyCleanBasemap(modalMap);

    modalMap.on('load', function () {
      modalMap.resize();

      if (coords.length >= 2) {
        addRouteLine(modalMap, coords, token);
      }

      routePoints.forEach(function (p, i) {
        var pin = buildPinElement(MARK_COLORS[p.kind] || '#6f6862', markerLabel(p));
        var popup = new mapboxgl.Popup({ offset: 32 }).setHTML(popupHtml(p));
        popup.on('open', function () { wirePopupZoom(popup, token, routePoints); });
        new mapboxgl.Marker({ element: pin, anchor: 'bottom' })
          .setLngLat(coords[i])
          .setPopup(popup)
          .addTo(modalMap);
      });

      if (coords.length >= 2) {
        var bounds = new mapboxgl.LngLatBounds();
        coords.forEach(function (c) { bounds.extend(c); });
        var tight = spanMeters(coords) < SAME_PLACE_METERS;
        modalMap.fitBounds(bounds, { padding: 50, maxZoom: tight ? 19 : 17, duration: 0 });
      }
    });
  }

  /**
   * Draws a small blue ">" chevron onto an offscreen canvas and registers it
   * with the map as image id "direction-arrow", so the
   * 'symbol-placement: line' layer can stamp direction-of-travel markers
   * along the route ('icon-rotation-alignment': 'map' turns each stamped
   * copy to match the line's own heading, so the chevron always points the
   * way the route is actually travelled - "from -> to"). Blue so it stands
   * out clearly against the red route line. No external image file needed.
   * Skips re-adding if this map instance already has it.
   */
  function registerDirectionArrow(map) {
    if (map.hasImage('direction-arrow')) return;

    // A high-res canvas (downscaled by icon-size when stamped) so the
    // chevron's strokes stay crisp instead of pixelated.
    var size = 40;
    var canvas = document.createElement('canvas');
    canvas.width = size;
    canvas.height = size;
    var ctx = canvas.getContext('2d');
    var cx = size / 2, cy = size / 2;
    var w = 8;   // half-width of the chevron opening
    var h = 9;   // how far the tip juts forward (+x = travel direction)

    // ">" shape: two arms meeting at a forward point, drawn as a thick
    // stroked open path (not a filled triangle) so it reads as a chevron
    // arrow, not a solid arrowhead.
    ctx.beginPath();
    ctx.moveTo(cx - h, cy - w);
    ctx.lineTo(cx + h, cy);
    ctx.lineTo(cx - h, cy + w);
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';

    // White halo first (thicker), then the blue chevron on top - keeps it
    // legible over both the red line and the map underneath.
    ctx.lineWidth = 9;
    ctx.strokeStyle = '#ffffff';
    ctx.stroke();
    ctx.lineWidth = 5;
    ctx.strokeStyle = '#1a53d1'; // blue
    ctx.stroke();

    map.addImage('direction-arrow', ctx.getImageData(0, 0, size, size), { sdf: false });
  }

  // A whole day whose points all sit inside this radius is treated as "one
  // place", not a journey - used only to pick a tighter default zoom so the
  // A/1/2/Z pins don't stack into one blob (see spanMeters() callers).
  var SAME_PLACE_METERS = 10;

  /** Great-circle distance in meters between two [lng, lat] pairs. */
  function metersBetween(a, b) {
    var R = 6371000;
    var toRad = Math.PI / 180;
    var lat1 = a[1] * toRad, lat2 = b[1] * toRad;
    var dLat = lat2 - lat1;
    var dLng = (b[0] - a[0]) * toRad;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
      + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
    return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
  }

  /**
   * Largest distance in meters between any two points in the list - i.e. how
   * far the day actually spread out. Cheap O(n^2), and n is a day's stops.
   */
  function spanMeters(coords) {
    var max = 0;
    for (var i = 0; i < coords.length; i++) {
      for (var j = i + 1; j < coords.length; j++) {
        var d = metersBetween(coords[i], coords[j]);
        if (d > max) max = d;
      }
    }
    return max;
  }

  // How wide the route line is at each zoom level. Shared [zoom, width] stop
  // pairs (a Mapbox 'interpolate' expression), not a flat pixel value: thin
  // when the whole day is in view so a busy/self-crossing route stays
  // readable, thicker when zoomed in to inspect one stretch.
  var CASE_WIDTH = ['interpolate', ['linear'], ['zoom'], 12, 4, 16, 5.5, 19, 10];
  var CORE_WIDTH = ['interpolate', ['linear'], ['zoom'], 12, 2, 16, 3, 19, 6];
  // icon-size is a MULTIPLIER on the 40px chevron canvas (see
  // registerDirectionArrow) - kept small so the chevrons read as neat
  // direction ticks along the line, growing a little as you zoom in.
  var ARROW_SIZE = ['interpolate', ['linear'], ['zoom'], 12, 0.32, 16, 0.44, 19, 0.62];

  // A day whose points all sit within this radius is "one place", not a
  // journey - skip Directions (Mapbox would snap the near-identical points to
  // a road and return a bogus drive-around-the-block loop).
  var SAME_PLACE_ROUTE_METERS = 25;

  // How far (metres) each leg is shifted sideways from the road centreline,
  // always to the RIGHT of ITS OWN direction of travel. Two legs that run the
  // same road in opposite directions therefore land on OPPOSITE sides - like
  // the two lanes of a two-way road - so "going" and "coming back" read as
  // two separate lines instead of one thick stacked stroke with arrows
  // pointing both ways. Small enough to still read as "the same road".
  var LANE_OFFSET_METRES = 4.0;

  // How close (metres) a GPS point must be to a mapped road for Mapbox to
  // snap it, per leg. SHOP-TO-SHOP hops use the tight value: a shop visit is
  // logged from on/beside a road, and a loose radius there let Mapbox snap a
  // point a few metres into a yard/side-lane and then route a little in-and-
  // out LOOP to "reach" it - the rounding artifact near a stop the client did
  // not want. The FIRST and LAST hop use the loose value because those touch
  // the check-in / check-out point, which is often NOT beside a road - the
  // worker punches in from inside the office/depot/home, tens of metres off
  // the nearest road. A tight radius there makes Mapbox reject that point with
  // NoSegment, and (when it was one whole-day call) that killed the entire
  // route back to straight lines. Loose here just snaps that one end to the
  // nearest road and the line follows roads from there.
  var SNAP_TIGHT_M = 25;
  var SNAP_LOOSE_M = 200;

  /**
   * The road-following path for the whole day, built one HOP AT A TIME rather
   * than in a single Directions call. steps=true gives each hop's geometry so
   * we can offset each independently (see offsetLeg / the leg loop in
   * addRouteLine). Resolves to { legs: [[ [lng,lat], ... ], ...] } - one
   * coordinate array per hop (check-in -> visit 1, visit 1 -> visit 2, ...).
   *
   * WHY PER-HOP, NOT ONE CALL: one bad point (an office check-in far from any
   * road) made the single whole-day call return NoSegment, and the code then
   * drew the ENTIRE day as straight lines. Per-hop, a hop Mapbox can't route
   * falls back to a straight line for THAT HOP ONLY - every other hop still
   * follows the roads. The first and last hop also get a much looser snap
   * radius (SNAP_LOOSE_M) since they touch the check-in / check-out point.
   *
   * On ANY failure a hop falls back to its straight segment; the function
   * always resolves with a full legs[] (never null) so the map is never left
   * broken. NoRoute/NoSegment come back as HTTP 200 with body code != 'Ok' -
   * the body's own code must be checked; res.ok alone misses it.
   */
  function fetchRoadGeometry(coords, token) {
    var straightLegs = [];
    for (var s = 0; s < coords.length - 1; s++) straightLegs.push([coords[s], coords[s + 1]]);
    var fail = { legs: straightLegs };

    if (spanMeters(coords) < SAME_PLACE_ROUTE_METERS) {
      return Promise.resolve(fail);
    }

    var hopCount = coords.length - 1;
    var hops = [];
    for (var h = 0; h < hopCount; h++) {
      // Loose snap on the first hop (starts at check-in) and the last hop
      // (ends at check-out); tight on every shop-to-shop hop in between.
      var isEndHop = (h === 0 || h === hopCount - 1);
      hops.push(fetchOneHop(coords[h], coords[h + 1], isEndHop ? SNAP_LOOSE_M : SNAP_TIGHT_M,
        token, straightLegs[h]));
    }

    return Promise.all(hops).then(function (legs) {
      return { legs: legs };
    }).catch(function (err) {
      console.warn('[TrackMapboxRoute] Directions per-hop batch failed: '
        + (err && err.message) + ' - keeping the straight line.');
      return fail;
    });
  }

  /**
   * One Directions call for a single hop (two waypoints). Resolves to that
   * hop's [ [lng,lat], ... ] road path, or - on any HTTP error, non-'Ok' body
   * code (NoSegment / NoRoute), timeout, or malformed response - to
   * straightSeg ([start, end]) so the caller always gets a drawable leg.
   */
  function fetchOneHop(a, b, radiusM, token, straightSeg) {
    var coordStr = a[0] + ',' + a[1] + ';' + b[0] + ',' + b[1];
    var url = 'https://api.mapbox.com/directions/v5/mapbox/driving/' + coordStr
      + '?geometries=geojson&overview=full&steps=true'
      + '&radiuses=' + radiusM + ';' + radiusM
      // approaches=unrestricted: don't loop around to hit a waypoint from a
      // particular side. continue_straight=false: allowed to carry on the
      // same way through a via-point instead of a forced U-turn.
      + '&approaches=unrestricted;unrestricted'
      + '&continue_straight=false'
      + '&access_token=' + encodeURIComponent(token);

    var controller = (typeof AbortController === 'function') ? new AbortController() : null;
    var timer = controller ? setTimeout(function () { controller.abort(); }, 8000) : null;

    return fetch(url, controller ? { signal: controller.signal } : {})
      .then(function (res) {
        if (!res.ok) {
          console.warn('[TrackMapboxRoute] Directions HTTP ' + res.status
            + (res.status === 401 ? ' - Mapbox token invalid'
              : res.status === 403 ? ' - Mapbox token is URL-restricted; this origin is not allowed'
              : res.status === 429 ? ' - Mapbox rate limit hit' : '')
            + '. Straight line for this hop.');
        }
        return res.json();
      })
      .then(function (data) {
        if (timer) clearTimeout(timer);
        if (data.code !== 'Ok') {
          console.warn('[TrackMapboxRoute] Directions code=' + data.code
            + (data.message ? ' (' + data.message + ')' : '')
            + ' - straight line for this hop.');
          return straightSeg;
        }
        var route = data.routes && data.routes[0];
        var leg = route && Array.isArray(route.legs) && route.legs[0];
        if (!leg) return straightSeg;
        var pts = [];
        (leg.steps || []).forEach(function (step) {
          var sc = step.geometry && step.geometry.coordinates;
          if (!Array.isArray(sc)) return;
          var start = pts.length ? 1 : 0; // step[0] == prev step's last pt
          for (var p = start; p < sc.length; p++) pts.push(sc[p]);
        });
        return pts.length >= 2 ? pts : straightSeg;
      })
      .catch(function (err) {
        if (timer) clearTimeout(timer);
        console.warn('[TrackMapboxRoute] Directions hop failed: ' + (err && err.message)
          + ' - straight line for this hop.');
        return straightSeg;
      });
  }

  /**
   * Shifts a leg's polyline sideways by LANE_OFFSET_METRES, perpendicular to
   * each segment's own heading, always to the RIGHT of travel. The offset is
   * TAPERED to 0 over the first/last few points so a leg's ends still meet
   * its neighbours' ends on the centreline (no visible jump at a stop).
   */
  function offsetLeg(pts) {
    if (!Array.isArray(pts) || pts.length < 2) return (pts || []).slice();
    var taper = Math.min(4, Math.floor((pts.length - 1) / 2));
    var out = [];
    for (var i = 0; i < pts.length; i++) {
      var a = pts[Math.max(0, i - 1)];
      var b = pts[Math.min(pts.length - 1, i + 1)];
      var kx = Math.cos(pts[i][1] * Math.PI / 180) * 111320;
      var ky = 110540;
      var dx = (b[0] - a[0]) * kx, dy = (b[1] - a[1]) * ky;
      var len = Math.hypot(dx, dy) || 1;
      // perpendicular, to the RIGHT of the direction of travel
      var nx = dy / len, ny = -dx / len;

      var strength = 1;
      if (taper > 0) {
        var edge = Math.min(i, pts.length - 1 - i);
        if (edge < taper) strength = edge / taper;
      }
      out.push([
        pts[i][0] + (nx * LANE_OFFSET_METRES * strength) / kx,
        pts[i][1] + (ny * LANE_OFFSET_METRES * strength) / ky
      ]);
    }
    return out;
  }

  /**
   * Adds the route line to a map that has finished loading. Draws the STRAIGHT
   * line between stops first (map never blank), then swaps in the real
   * road-following path from fetchRoadGeometry() - each hop (leg) offset to
   * its own right so an out-and-back on the same road shows as two parallel
   * lines. Styling: white outline case + blue core + blue chevron arrows.
   *
   * @param map    Mapbox map instance, already past its 'load' event
   * @param coords array of [lng, lat] pairs, in travel order, length >= 2
   * @param token  Mapbox access token (needed for the Directions lookup)
   */
  function addRouteLine(map, coords, token) {
    registerDirectionArrow(map);

    map.addSource('route-line', {
      type: 'geojson',
      data: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: coords } }
    });

    // slot: 'top' places these above Standard's roads/buildings/POI labels
    // (Standard's internal layers aren't addressable by id; positioning goes
    // through its bottom/middle/top slots). Harmless no-op on plain satellite.
    map.addLayer({
      id: 'route-line-case',
      type: 'line',
      source: 'route-line',
      slot: 'top',
      layout: { 'line-join': 'round', 'line-cap': 'round' },
      paint: { 'line-color': '#ffffff', 'line-width': CASE_WIDTH, 'line-opacity': 1 }
    });
    map.addLayer({
      id: 'route-line',
      type: 'line',
      source: 'route-line',
      slot: 'top',
      layout: { 'line-join': 'round', 'line-cap': 'round' },
      paint: { 'line-color': '#1a53d1', 'line-width': CORE_WIDTH, 'line-opacity': 1 }
    });
    map.addLayer({
      id: 'route-line-arrows',
      type: 'symbol',
      source: 'route-line',
      slot: 'top',
      layout: {
        'symbol-placement': 'line',
        'symbol-spacing': 70,
        'icon-image': 'direction-arrow',
        'icon-size': ARROW_SIZE,
        'icon-rotation-alignment': 'map',
        'icon-allow-overlap': true
      }
    });

    if (!token || token.indexOf('REPLACE-WITH') === 0) return;

    var mapRemoved = false;
    map.on('remove', function () { mapRemoved = true; });

    fetchRoadGeometry(coords, token).then(function (result) {
      if (mapRemoved || !result.legs) return;

      // Each leg offset to its own right -> out-and-back on the same road
      // lands on opposite sides. Kept as separate LineString features (not
      // one joined path) so a shared road never draws a straight jump
      // between two offset legs.
      var features = result.legs.map(function (legPts) {
        return { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: offsetLeg(legPts) } };
      }).filter(function (f) { return f.geometry.coordinates.length >= 2; });

      var src = map.getSource('route-line');
      if (src && features.length) {
        src.setData({ type: 'FeatureCollection', features: features });
      }
      // A real road can bulge outside the straight-line bounding box of the
      // stops - widen the view to include the whole path, without re-fitting
      // from scratch (that would fight the caller's chosen view).
      try {
        var b = map.getBounds();
        features.forEach(function (f) { f.geometry.coordinates.forEach(function (c) { b.extend(c); }); });
        map.fitBounds(b, { padding: 56, duration: 300, maxZoom: 17 });
      } catch (e) { /* fitBounds can throw mid-teardown - ignore */ }
    });
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
  }

  /**
   * Configures Mapbox Standard's basemap: full-detail 'default' theme so
   * road names and place/area labels stay rich and readable (the 'faded'
   * theme we tried first muted these too much), while still hiding
   * restaurant/shop/transit icons - the exact clutter that made the old
   * Streets style feel busy. Must run on 'style.load' (not the 'load' event
   * used for adding custom layers) - see Mapbox's Standard style config
   * reference: https://docs.mapbox.com/map-styles/reference/standard/
   */
  function applyCleanBasemap(map) {
    map.on('style.load', function () {
      try {
        map.setConfigProperty('basemap', 'theme', 'default');
        map.setConfigProperty('basemap', 'showPointOfInterestLabels', false);
        map.setConfigProperty('basemap', 'showTransitLabels', false);
        map.setConfigProperty('basemap', 'showRoadLabels', true);
        map.setConfigProperty('basemap', 'showPlaceLabels', true);
        // Just the building shapes for visual context/depth - NOT the wider
        // show3dObjects (which also turns on trees, landmark icons, and
        // shadows - more visual noise than the route/pins should compete
        // with on a small card).
        map.setConfigProperty('basemap', 'show3dBuildings', true);
      } catch (e) {
        // Older Mapbox GL JS or a non-Standard style - config API may not
        // exist; the map still renders fine with its normal defaults.
      }
    });
  }

  /**
   * Guards against a real, common Mapbox GL JS gotcha: if the container
   * element has 0 (or a stale) width/height at the exact instant
   * new mapboxgl.Map() runs - e.g. because its size comes from CSS
   * aspect-ratio / a flex/grid parent that hasn't finished laying out yet on
   * a just-inserted element - the map can initialize against a 0px canvas
   * and render nothing, and a single resize() inside 'load' isn't always
   * late enough to catch it. This calls resize() on a couple of delayed
   * ticks AND on a ResizeObserver so a late-arriving real size is always
   * picked up, however the container ended up getting it.
   */
  function scheduleResizeFixups(map, el) {
    setTimeout(function () { map.resize(); }, 0);
    setTimeout(function () { map.resize(); }, 250);

    if (typeof ResizeObserver === 'function') {
      var ro = new ResizeObserver(function () { map.resize(); });
      ro.observe(el);
      map.on('remove', function () { ro.disconnect(); });
    }
  }

  /**
   * Builds a teardrop map-pin element: a colored circular head with a
   * pointed tip, a text label centered in the head, and a soft ground
   * shadow. Used with new mapboxgl.Marker({ element, anchor: 'bottom' }) -
   * 'bottom' is what makes the pin's TIP (not its visual center) sit
   * exactly on the coordinate, matching every familiar map pin convention.
   */
  function buildPinElement(color, label, extraClass) {
    var wrap = document.createElement('div');
    wrap.className = 'mb-route-pin' + (extraClass ? ' ' + extraClass : '');

    var head = document.createElement('div');
    head.className = 'mb-route-pin__head';
    head.style.background = color;
    wrap.appendChild(head);

    var text = document.createElement('div');
    text.className = 'mb-route-pin__label';
    text.textContent = label;
    wrap.appendChild(text);

    var shadow = document.createElement('div');
    shadow.className = 'mb-route-pin__shadow';
    wrap.appendChild(shadow);

    return wrap;
  }

  /**
   * Render a route map into the element with this id. Reads its points from
   * the element's data-map-points attribute (a JSON string). Does nothing
   * (leaves whatever fallback HTML was already in the div) if: the Mapbox
   * script hasn't loaded, no token is configured, or there are fewer than 1
   * point to plot.
   */
  function render(elementId) {
    var el = document.getElementById(elementId);
    if (!el) return;

    if (typeof mapboxgl === 'undefined') {
      return; // CDN script missing/blocked - leave the existing fallback content
    }
    var token = el.getAttribute('data-map-token') || '';
    if (!token || token.indexOf('REPLACE-WITH') === 0) {
      return; // no real token configured yet - leave the existing fallback content
    }

    var points;
    try {
      points = JSON.parse(el.getAttribute('data-map-points') || '[]');
    } catch (e) {
      return;
    }
    if (!points.length) return;

    el.innerHTML = ''; // clear the fallback content only once we know we can actually render
    mapboxgl.accessToken = token;

    var coords = points.map(function (p) { return [p.lng, p.lat]; }); // Mapbox wants [lng, lat]

    var map = new mapboxgl.Map({
      container: el,
      // Mapbox Standard: the actively-maintained modern default (clean
      // roads/labels, subtle 3D buildings, dynamic lighting) - the old
      // Streets classic style is no longer updated by Mapbox.
      style: 'mapbox://styles/mapbox/standard',
      center: coords[0],
      zoom: 16
    });
    map.addControl(new mapboxgl.NavigationControl(), 'top-right');
    applyCleanBasemap(map);
    scheduleResizeFixups(map, el);

    map.on('load', function () {
      map.resize();
      if (coords.length >= 2) {
        addRouteLine(map, coords, token);
      }

      points.forEach(function (p, i) {
        var pin = buildPinElement(MARK_COLORS[p.kind] || '#6f6862', markerLabel(p));

        var popup = new mapboxgl.Popup({ offset: 32 }).setHTML(popupHtml(p));
        popup.on('open', function () { wirePopupZoom(popup, token, points); });

        new mapboxgl.Marker({ element: pin, anchor: 'bottom' })
          .setLngLat(coords[i])
          .setPopup(popup)
          .addTo(map);
      });

      if (coords.length >= 2) {
        var bounds = new mapboxgl.LngLatBounds();
        coords.forEach(function (c) { bounds.extend(c); });
        // A tightly clustered day (all stops within SAME_PLACE_METERS) needs
        // to zoom in past the usual cap - at 16 a few meters of spread is
        // sub-pixel, so every pin stacks into what looks like a single
        // marker. 19 keeps the individual A/1/2/Z pins distinguishable.
        var tight = spanMeters(coords) < SAME_PLACE_METERS;
        map.fitBounds(bounds, { padding: 56, maxZoom: tight ? 19 : 16, duration: 0 });
      }
    });
  }

  /**
   * Render a "live team" map: one pin per employee, at each one's most
   * recent known location - no connecting line, since these are different
   * people, not one person's journey. Reads the same data-map-token
   * attribute as render(), plus data-team-points holding a JSON array of
   * {employee, label, at, lat, lng}.
   */
  function renderTeam(elementId) {
    var el = document.getElementById(elementId);
    if (!el) return;

    if (typeof mapboxgl === 'undefined') return;
    var token = el.getAttribute('data-map-token') || '';
    if (!token || token.indexOf('REPLACE-WITH') === 0) return;

    var people;
    try {
      people = JSON.parse(el.getAttribute('data-team-points') || '[]');
    } catch (e) {
      return;
    }
    if (!people.length) return;

    el.innerHTML = '';
    mapboxgl.accessToken = token;

    var coords = people.map(function (p) { return [p.lng, p.lat]; });

    var map = new mapboxgl.Map({
      container: el,
      // Mapbox Standard: the actively-maintained modern default (clean
      // roads/labels, subtle 3D buildings, dynamic lighting) - the old
      // Streets classic style is no longer updated by Mapbox.
      style: 'mapbox://styles/mapbox/standard',
      center: coords[0],
      zoom: 15
    });
    map.addControl(new mapboxgl.NavigationControl(), 'top-right');
    applyCleanBasemap(map);
    scheduleResizeFixups(map, el);

    map.on('load', function () {
      map.resize();
      people.forEach(function (p, i) {
        var initial = escapeHtml(p.employee).charAt(0).toUpperCase();
        var pin = buildPinElement('#6b4423', initial, 'mb-team-pin');

        var time = '';
        try {
          time = new Date(p.at.replace(' ', 'T')).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        } catch (e) { /* leave blank */ }
        var html = '<strong>' + escapeHtml(p.employee) + '</strong><br>'
          + escapeHtml(p.label) + ' &middot; ' + escapeHtml(time)
          + '<br>' + coordsButtonHtml(p.lat, p.lng);

        var popup = new mapboxgl.Popup({ offset: 32 }).setHTML(html);
        popup.on('open', function () { wirePopupZoom(popup, token); });

        new mapboxgl.Marker({ element: pin, anchor: 'bottom' })
          .setLngLat(coords[i])
          .setPopup(popup)
          .addTo(map);
      });

      if (coords.length >= 2) {
        var bounds = new mapboxgl.LngLatBounds();
        coords.forEach(function (c) { bounds.extend(c); });
        map.fitBounds(bounds, { padding: 56, maxZoom: 15, duration: 0 });
      }
    });
  }

  /**
   * Open the full-screen route-inspect modal for the map rendered into
   * elementId - reads the same data-map-points/data-map-token attributes
   * render() already used, so a card's own "Expand" button just needs the
   * same element id, no data to pass separately.
   */
  function openRoute(elementId) {
    var el = document.getElementById(elementId);
    if (!el || typeof mapboxgl === 'undefined') return;
    var token = el.getAttribute('data-map-token') || '';
    if (!token || token.indexOf('REPLACE-WITH') === 0) return;
    var points;
    try {
      points = JSON.parse(el.getAttribute('data-map-points') || '[]');
    } catch (e) {
      return;
    }
    openRouteModal(points, token);
  }

  window.TrackMapboxRoute = { render: render, renderTeam: renderTeam, openRoute: openRoute };
})();
