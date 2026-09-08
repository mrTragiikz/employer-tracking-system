<?php
/**
 * admin/09-announcements/form.php - add or edit an announcement.
 *   form.php        -> create
 *   form.php?id=N   -> edit
 *
 * POSTs to api/save.php. On a validation failure that endpoint stashes the
 * errors + old input in the session and redirects back here.
 *
 * Super Admin only.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
$me = require_super_admin($me);
require __DIR__ . '/_repo.php';

$pageTitle     = 'Announcements';
$activeSection  = 'announcements';
$sectionCss     = APP_URL . '/admin/09-announcements/css/announcements.css';

$id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$row = $id ? announcement_find($pdo, $id) : null;

if ($id && $row === null) {
    require dirname(__DIR__) . '/components/header/header.php';
    echo '<div class="card"><p class="section-note">That announcement was not found. '
       . '<a href="' . e(APP_URL) . '/admin/09-announcements/">Back to list</a>.</p></div>';
    require dirname(__DIR__) . '/components/footer/footer.php';
    exit;
}

$isEdit = $row !== null;

// errors / old input from a failed save
$errors = $_SESSION['announcement_form_error'] ?? [];
$old    = $_SESSION['announcement_form_old']   ?? [];
unset($_SESSION['announcement_form_error'], $_SESSION['announcement_form_old']);

/** field value: old input wins (after a failed save), then the DB row, then default. */
$fv = static function (string $key, string $default = '') use ($old, $row): string {
    if (array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($row !== null && array_key_exists($key, $row)) {
        return (string) ($row[$key] ?? '');
    }
    return $default;
};

// Audience: 'all' (default) or 'selected'.
$audience = $old['audience'] ?? ($isEdit ? ($row['audience'] ?? 'all') : 'all');
$audience = $audience === 'selected' ? 'selected' : 'all';

// Which employee ids are ticked: old input after a failed save, else the
// saved targets on an edit, else none.
$checkedTargets = array_key_exists('targets', $old)
    ? array_map('intval', (array) $old['targets'])
    : ($isEdit ? announcement_target_ids($pdo, (int) $row['id']) : []);
$checkedTargets = array_fill_keys($checkedTargets, true);

$employeeChoices = announcement_employee_choices($pdo);

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1><?= $isEdit ? 'Edit announcement' : 'New announcement' ?></h1>
      <p class="section-note">
        <?= $isEdit
          ? 'Saving shows the updated message again, even to people who had closed it.'
          : 'This pops up on the chosen employees\' field screens within a few seconds.' ?>
      </p>
    </div>
    <div class="page-head__actions">
      <a class="btn" href="<?= e(APP_URL) ?>/admin/09-announcements/"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if (!empty($errors['_'])): ?>
    <div class="flash flash--err"><?= e($errors['_']) ?></div>
  <?php endif; ?>

  <form class="card form-card an-form" method="post"
        action="<?= e(APP_URL) ?>/admin/09-announcements/api/save.php" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><?php endif; ?>

    <div class="field <?= isset($errors['title']) ? 'has-error' : '' ?>">
      <label for="an-title">Title</label>
      <input class="input" id="an-title" name="title" type="text" maxlength="120" required
             value="<?= e($fv('title')) ?>" placeholder="e.g. Public holiday tomorrow">
      <?php if (isset($errors['title'])): ?><span class="field-err"><?= e($errors['title']) ?></span><?php endif; ?>
    </div>

    <div class="field <?= isset($errors['body']) ? 'has-error' : '' ?>">
      <label for="an-body">Message</label>
      <textarea class="input an-textarea" id="an-body" name="body" rows="5" maxlength="2000" required
                placeholder="Short and clear. Line breaks are kept."><?= e($fv('body')) ?></textarea>
      <p class="section-note"><span id="an-count">0</span> / 2000 characters</p>
      <?php if (isset($errors['body'])): ?><span class="field-err"><?= e($errors['body']) ?></span><?php endif; ?>
    </div>

    <?php /* ---- who sees it ---- */ ?>
    <div class="field <?= isset($errors['targets']) ? 'has-error' : '' ?>">
      <label>Who sees this</label>
      <div class="an-audience">
        <label class="an-radio">
          <input type="radio" name="audience" value="all" <?= $audience === 'all' ? 'checked' : '' ?> data-an-audience>
          <span><strong>Everyone</strong><small class="section-note">All active field employees.</small></span>
        </label>
        <label class="an-radio">
          <input type="radio" name="audience" value="selected" <?= $audience === 'selected' ? 'checked' : '' ?> data-an-audience>
          <span><strong>Specific employees</strong><small class="section-note">Only the people you tick below.</small></span>
        </label>
      </div>

      <div class="an-picker" id="an-picker" <?= $audience === 'selected' ? '' : 'hidden' ?>>
        <?php if (!$employeeChoices): ?>
          <p class="section-note">No active field employees to choose from.</p>
        <?php else: ?>
          <div class="an-picker__bar">
            <button type="button" class="an-picker__all" data-an-pick-all>Select all</button>
            <button type="button" class="an-picker__none" data-an-pick-none>Clear</button>
            <span class="an-picker__count" id="an-picker-count"></span>
          </div>
          <ul class="an-picker__list">
            <?php foreach ($employeeChoices as $emp): ?>
              <li>
                <label class="an-emp">
                  <input type="checkbox" name="targets[]" value="<?= (int) $emp['id'] ?>"
                         <?= isset($checkedTargets[(int) $emp['id']]) ? 'checked' : '' ?> data-an-emp>
                  <span class="an-emp__name"><?= e($emp['name']) ?></span>
                  <?php if (!empty($emp['code'])): ?>
                    <span class="an-emp__code"><?= e($emp['code']) ?></span>
                  <?php endif; ?>
                </label>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
      <?php if (isset($errors['targets'])): ?><span class="field-err"><?= e($errors['targets']) ?></span><?php endif; ?>
    </div>

    <p class="an-golive section-note">
      <i class="bi bi-broadcast"></i>
      <?= $isEdit
        ? 'Saving pushes this to the chosen employees now, even those who had closed it. It replaces any other live announcement.'
        : 'Posting shows this on the chosen employees\' screens right away. It replaces any other live announcement.' ?>
    </p>

    <div class="form-actions">
      <a class="btn" href="<?= e(APP_URL) ?>/admin/09-announcements/">Cancel</a>
      <button type="submit" class="btn btn--primary">
        <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save changes' : 'Post announcement' ?>
      </button>
    </div>
  </form>

  <script>
    (function () {
      // ---- character counter ----
      var ta = document.getElementById('an-body');
      var out = document.getElementById('an-count');
      if (ta && out) {
        var tick = function () { out.textContent = ta.value.length; };
        ta.addEventListener('input', tick);
        tick();
      }

      // ---- audience radio -> show/hide the employee picker ----
      var picker = document.getElementById('an-picker');
      var radios = document.querySelectorAll('[data-an-audience]');
      var empBoxes = function () { return document.querySelectorAll('[data-an-emp]'); };
      var countEl = document.getElementById('an-picker-count');

      function refreshCount() {
        if (!countEl) return;
        var n = 0;
        empBoxes().forEach(function (b) { if (b.checked) n++; });
        countEl.textContent = n === 0 ? 'none picked' : n + ' picked';
      }
      function syncPicker() {
        var sel = document.querySelector('[data-an-audience][value="selected"]');
        if (picker) picker.hidden = !(sel && sel.checked);
        refreshCount();
      }
      radios.forEach(function (r) { r.addEventListener('change', syncPicker); });

      var allBtn = document.querySelector('[data-an-pick-all]');
      var noneBtn = document.querySelector('[data-an-pick-none]');
      if (allBtn) allBtn.addEventListener('click', function () {
        empBoxes().forEach(function (b) { b.checked = true; }); refreshCount();
      });
      if (noneBtn) noneBtn.addEventListener('click', function () {
        empBoxes().forEach(function (b) { b.checked = false; }); refreshCount();
      });
      document.addEventListener('change', function (e) {
        if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-an-emp')) refreshCount();
      });

      syncPicker();
    })();
  </script>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
