/* admin/components/sidebar/js/sidebar.js
 * PC only. The topbar "collapse" button toggles the rail between full width
 * and a narrow icon-only strip (see .is-collapsed rules in sidebar.css).
 * State is remembered per browser via localStorage.
 */
'use strict';

(function () {
  const shell = document.querySelector('.admin-shell');
  const toggle = document.querySelector('[data-sidebar-collapse]');
  if (!shell || !toggle) return;

  const KEY = 'track.admin.sidebarCollapsed';

  try {
    if (localStorage.getItem(KEY) === '1') shell.classList.add('is-collapsed');
  } catch (e) { /* storage blocked - ignore */ }

  toggle.addEventListener('click', () => {
    const collapsed = shell.classList.toggle('is-collapsed');
    try { localStorage.setItem(KEY, collapsed ? '1' : '0'); } catch (e) { /* ignore */ }
  });
})();
