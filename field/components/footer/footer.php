<?php
/**
 * field/components/footer/footer.php
 *
 * Closes </main>, .field-shell, renders the bottom tab bar, and </body></html>
 * opened by header.php. Last line of every field page:
 *
 *   require dirname(__DIR__) . '/components/footer/footer.php';
 *
 * Expects $activeTab (string) set by the page, matching one of the slugs
 * below. Tabs that don't have a page yet (visits/attendance/routes/more)
 * link out but the destination folders are still empty placeholders -
 * built one at a time, same as the rest of this app.
 */

declare(strict_types=1);

$activeTab = $activeTab ?? '';

/** slug => [label, bootstrap-icon class, folder] */
$tabs = [
    'dashboard'  => ['Dashboard',    'bi-grid-1x2-fill',      'home'],
    'checkinout' => ['Check In/Out', 'bi-box-arrow-in-right', 'checkinout'],
    'visits'     => ['My Visits',    'bi-geo-alt-fill',       'visit'],
    'routes'     => ['Routes',       'bi-signpost-split',     'routes'],
    'attendance' => ['Attendance',   'bi-clock-history',      'attendance'],
];
?>
  </main><!-- .field-main -->

  <nav class="field-tabbar field-tabbar--5">
    <?php foreach ($tabs as $slug => [$label, $icon, $folder]): ?>
      <a class="field-tabbar__item<?= $activeTab === $slug ? ' is-active' : '' ?>"
         href="<?= e(APP_URL) ?>/field/<?= e($folder) ?>/">
        <i class="bi <?= e($icon) ?>"></i>
        <span><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

</div><!-- .field-shell -->
</body>
</html>
