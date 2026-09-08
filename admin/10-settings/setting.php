<?php
/**
 * admin/10-settings/setting.php - Settings section.
 * URL: /track/admin/10-settings/
 *
 * Small, focused: the policy rows in `settings` that an admin actually tunes.
 * Right now that is the attendance check-in cut-off. POSTs to itself, validates
 * per-field, writes only what changed, audit-logs the batch.
 *
 * Build order step 7.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
$me = require_super_admin($me); // Settings is Super Admin only
require __DIR__ . '/_repo.php';

$pageTitle     = 'Settings';
$activeSection = 'settings';
$sectionCss    = APP_URL . '/admin/10-settings/css/setting.css';

$schema  = settings_schema();
$errors  = [];
$saved   = 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require();
    [$saved, $errors] = settings_save($pdo, (int) $me['id'], $_POST);
    if ($saved > 0 && !$errors) {
        redirect(APP_URL . '/admin/10-settings/?ok=' . $saved);
    }
}

$okCount = isset($_GET['ok']) ? (int) $_GET['ok'] : 0;
$values  = settings_values($pdo);

// A live preview line so the admin sees the effect of the current values.
$cutEnabled = ($values['attendance_cutoff_enabled'] ?? '0') === '1';
$openTime   = $values['attendance_checkin_open_time'] ?? '09:00';
$cutTime    = $values['attendance_cutoff_time'] ?? '09:30';

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1>Settings</h1>
      <p class="section-note">System policies. Changes apply across the whole panel and the field app.</p>
    </div>
  </div>

  <?php if ($okCount > 0): ?>
    <div class="st-flash st-flash--ok">
      <i class="bi bi-check-circle-fill"></i>
      Saved <?= $okCount ?> setting<?= $okCount === 1 ? '' : 's' ?>.
    </div>
  <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors && $saved === 0): ?>
    <div class="st-flash st-flash--muted">
      <i class="bi bi-info-circle"></i>
      Nothing changed.
    </div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="st-flash st-flash--bad">
      <i class="bi bi-exclamation-triangle-fill"></i>
      <div>
        <strong>Some values were not saved:</strong>
        <ul><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
      </div>
    </div>
  <?php endif; ?>

  <div class="st-grid">
    <form class="st-main" method="post" action="<?= e(APP_URL) ?>/admin/10-settings/">
      <?= csrf_field() ?>

      <?php foreach ($schema as $groupKey => $group): ?>
        <section class="card st-card">
          <div class="st-card__head">
            <div>
              <h2><i class="bi <?= e($group['icon']) ?>"></i> <?= e($group['title']) ?></h2>
              <p class="section-note"><?= e($group['note']) ?></p>
            </div>
          </div>

          <div class="st-fields">
            <?php foreach ($group['fields'] as $key => $field):
              $cur = $values[$key] ?? '';
              $bad = isset($errors[$key]);
            ?>
              <div class="st-field <?= $bad ? 'is-bad' : '' ?>">
                <?php if ($field['type'] === 'bool'): ?>
                  <label class="st-toggle">
                    <input type="checkbox" name="<?= e($key) ?>" value="1" <?= $cur === '1' ? 'checked' : '' ?>>
                    <span class="st-toggle__track"><span class="st-toggle__knob"></span></span>
                    <span class="st-toggle__text">
                      <strong><?= e($field['label']) ?></strong>
                      <span class="section-note"><?= e($field['help']) ?></span>
                    </span>
                  </label>

                <?php else: ?>
                  <label class="st-label" for="f-<?= e($key) ?>"><?= e($field['label']) ?></label>
                  <?php if ($field['type'] === 'select'): ?>
                    <select class="st-input" id="f-<?= e($key) ?>" name="<?= e($key) ?>">
                      <?php foreach ($field['options'] as $val => $lbl): ?>
                        <option value="<?= e($val) ?>" <?= $cur === (string) $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($field['type'] === 'time'): ?>
                    <input class="st-input st-input--sm" type="time" id="f-<?= e($key) ?>"
                           name="<?= e($key) ?>" value="<?= e($cur) ?>" step="60">
                  <?php elseif ($field['type'] === 'time12'):
                    // Three plain <select>s (hour/minute/AM-PM), not the
                    // native <input type="time"> - that widget's 12h/24h
                    // display is controlled by the visitor's OS/browser
                    // locale, not by anything this page can set, so it can
                    // silently show 24h to some admins regardless of what
                    // we ask for. A hand-built 12h picker always reads as
                    // 12-hour, on every browser, forever - no locale
                    // dependency to break in real deployment. The hidden
                    // input is what actually gets posted/validated, in the
                    // same 24h "HH:MM" the rest of the app already expects
                    // (see settings_validate_field() - 'time12' validates
                    // identically to 'time').
                    $p = time24_to_parts($cur !== '' ? $cur : '09:00');
                  ?>
                    <div class="st-time12" data-time12-for="<?= e($key) ?>">
                      <select class="st-input st-time12__part" data-time12="hour" aria-label="Hour">
                        <?php for ($h = 1; $h <= 12; $h++): ?>
                          <option value="<?= $h ?>" <?= $p['hour12'] === $h ? 'selected' : '' ?>><?= $h ?></option>
                        <?php endfor; ?>
                      </select>
                      <span class="st-time12__colon">:</span>
                      <select class="st-input st-time12__part" data-time12="minute" aria-label="Minute">
                        <?php for ($mi = 0; $mi < 60; $mi += 5): ?>
                          <option value="<?= $mi ?>" <?= $p['minute'] === $mi ? 'selected' : '' ?>><?= sprintf('%02d', $mi) ?></option>
                        <?php endfor; ?>
                      </select>
                      <select class="st-input st-time12__part st-time12__ampm" data-time12="ampm" aria-label="AM or PM">
                        <option value="AM" <?= $p['ampm'] === 'AM' ? 'selected' : '' ?>>AM</option>
                        <option value="PM" <?= $p['ampm'] === 'PM' ? 'selected' : '' ?>>PM</option>
                      </select>
                      <input type="hidden" id="f-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($cur) ?>">
                    </div>
                  <?php else: /* int */ ?>
                    <input class="st-input st-input--sm" type="number" id="f-<?= e($key) ?>"
                           name="<?= e($key) ?>" value="<?= e($cur) ?>"
                           min="<?= (int) ($field['min'] ?? 0) ?>" max="<?= (int) ($field['max'] ?? 9999) ?>">
                  <?php endif; ?>
                  <p class="section-note st-field__help"><?= e($field['help']) ?></p>
                  <?php if ($bad): ?><p class="st-field__err"><?= e($errors[$key]) ?></p><?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="st-card__foot">
            <button type="submit" class="btn btn--primary"><i class="bi bi-check-lg"></i> Save changes</button>
          </div>
        </section>
      <?php endforeach; ?>
    </form>

    <!-- ===== right rail: what the rule does right now ===== -->
    <aside class="st-rail">
      <section class="card">
        <div class="card__head"><h2>How check-in behaves now</h2></div>

        <?php if (!$cutEnabled): ?>
          <div class="st-preview st-preview--off">
            <i class="bi bi-unlock"></i>
            <p>
              There is no check-in window enforced. an Employee can check in at
              any hour, and the day is always counted as <strong>Present</strong>.
            </p>
          </div>
        <?php else: ?>
          <div class="st-preview">
            <i class="bi bi-slash-circle"></i>
            <p>
              The check-in portal opens at <strong><?= e(fmt_time_12h($openTime)) ?></strong>
              and closes at <strong><?= e(fmt_time_12h($cutTime)) ?></strong>. an
              Employee who checks in at or after the closing time is turned away
              by the field app with a "too late" message. That day is recorded as
              <strong>Absent</strong>, with no route or visits - exactly like not
              checking in at all.
            </p>
          </div>
        <?php endif; ?>

        <a class="btn st-rail__btn" href="<?= e(APP_URL) ?>/admin/04-attendance/">Open Attendance <i class="bi bi-arrow-right"></i></a>
      </section>

      <section class="card">
        <div class="card__head"><h2>Recent changes</h2></div>
        <?php
        $log = $pdo->query(
            "SELECT al.after_json, al.created_at, u.name AS actor
               FROM audit_log al LEFT JOIN users u ON u.id = al.actor_id
              WHERE al.action = 'setting.update'
              ORDER BY al.id DESC LIMIT 5"
        )->fetchAll();
        ?>
        <?php if (!$log): ?>
          <p class="section-note">No settings changes yet.</p>
        <?php else: foreach ($log as $l):
          $when = new DateTimeImmutable($l['created_at']);
          $keysChanged = array_keys(json_decode((string) $l['after_json'], true) ?: []);
        ?>
          <div class="st-logrow">
            <span class="st-logrow__keys"><?= e(implode(', ', $keysChanged) ?: 'settings') ?></span>
            <span class="c-muted"><?= e($l['actor'] ?: 'System') ?> &middot; <?= e($when->format('j M, g:i A')) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </section>
    </aside>
  </div>

  <script src="<?= e(APP_URL) ?>/admin/10-settings/js/setting.js"></script>
<?php
require dirname(__DIR__) . '/components/footer/footer.php';
