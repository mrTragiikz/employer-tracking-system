/* admin/07-alerts/js/alert.js
 * Live activity feed: poll the feed partial every 25s and prepend new rows.
 * Only runs on the "live" view (no ?date= filter) - the <ul> carries data-poll.
 */
'use strict';

(function () {
  var list = document.getElementById('fd-list');
  if (!list) return;
  var url = list.dataset.poll;
  if (!url) return;                       // a specific day is pinned - no polling

  var status = document.getElementById('fd-status');
  var INTERVAL = 25000;
  var timer = null;

  function keyOf(li) {
    // a row's identity: its time + text, good enough to dedupe
    var t = li.querySelector('time');
    return (t ? t.getAttribute('title') : '') + '|' + li.textContent.replace(/\s+/g, ' ').trim();
  }

  function seen() {
    var s = {};
    list.querySelectorAll('.fd-row').forEach(function (li) { s[keyOf(li)] = 1; });
    return s;
  }

  function poll() {
    fetch(url, { headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(r.status); })
      .then(function (html) {
        var tmp = document.createElement('ul');
        tmp.innerHTML = html.trim();
        var known = seen();
        var fresh = [];
        tmp.querySelectorAll('.fd-row').forEach(function (li) {
          if (!known[keyOf(li)]) fresh.push(li);
        });
        if (!fresh.length) { tick('auto-refreshing'); return; }

        // prepend newest-first (fresh is already newest-first)
        for (var i = fresh.length - 1; i >= 0; i--) {
          var li = fresh[i];
          li.classList.add('is-new');
          list.insertBefore(li, list.firstChild);
        }
        // trim to a sane length
        var rows = list.querySelectorAll('.fd-row');
        for (var j = rows.length - 1; j >= 80; j--) rows[j].remove();

        tick(fresh.length + ' new');
        setTimeout(function () {
          list.querySelectorAll('.fd-row.is-new').forEach(function (n) { n.classList.remove('is-new'); });
        }, 2500);
      })
      .catch(function () { tick('offline - retrying'); });
  }

  function tick(msg) { if (status) status.textContent = msg; }

  function start() { stop(); timer = setInterval(poll, INTERVAL); }
  function stop() { if (timer) { clearInterval(timer); timer = null; } }

  // pause polling when the tab is hidden
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { stop(); }
    else { poll(); start(); }
  });

  start();
})();
