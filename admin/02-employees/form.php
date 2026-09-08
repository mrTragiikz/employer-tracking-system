<?php
/**
 * admin/02-employees/form.php - add or edit an Employee.
 * /track/admin/02-employees/form.php -> create
 * /track/admin/02-employees/form.php?id=N -> edit
 *
 * POSTs to api/save.php. On validation failure that endpoint stashes the errors
 * + old input in the session and redirects back here.
 *
 * Build order step 3.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
$me = require_admin();
$me = require_super_admin($me); // adding/editing Employees is Super Admin only
require __DIR__ . '/_repo.php';

$pageTitle = 'Employees';
$activeSection = 'employees';
$sectionCss = APP_URL . '/admin/02-employees/css/employee.css';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$employee = $id ? employee_find($pdo, $id) : null;

if ($id && !$employee) {
    require dirname(__DIR__) . '/components/header/header.php';
    echo '<div class="card"><p class="section-note">That Employee was not found. <a href="' . e(APP_URL) . '/admin/02-employees/">Back to list</a>.</p></div>';
    require dirname(__DIR__) . '/components/footer/footer.php';
    exit;
}

$isEdit = $employee !== null;

// errors / old input from a failed save
$errors = $_SESSION['employee_form_error'] ?? [];
$old = $_SESSION['employee_form_old'] ?? [];
unset($_SESSION['employee_form_error'], $_SESSION['employee_form_old']);

/** field value: old input wins (after a failed save), then the DB row, then ''. */
function fv(string $key, ?array $old, ?array $employee, string $default = ''): string
{
    if ($old !== null && array_key_exists($key, $old)) {
        return (string) $old[$key];
    }
    if ($employee !== null && array_key_exists($key, $employee)) {
        return (string) ($employee[$key] ?? '');
    }
    return $default;
}

$activeChecked = $isEdit
    ? (array_key_exists('is_active', $old) ? !empty($old['is_active']) : (int) $employee['is_active'] === 1)
    : (array_key_exists('is_active', $old) ? !empty($old['is_active']) : true);

require dirname(__DIR__) . '/components/header/header.php';
?>

  <div class="page-head">
    <div>
      <h1><?= $isEdit ? 'Edit Employee' : 'Add Employee' ?></h1>
      <p class="section-note">
        <?= $isEdit ? e($employee['name']) . ' · ' . e($employee['code'] ?: 'no code') : 'Create a field employee account.' ?>
      </p>
    </div>
    <div class="page-head__actions">
      <a class="btn" href="<?= e(APP_URL) ?>/admin/02-employees/"><i class="bi bi-arrow-left"></i> Back</a>
    </div>
  </div>

  <?php if (!empty($errors['_'])): ?>
    <div class="flash flash--err"><?= e($errors['_']) ?></div>
  <?php endif; ?>

  <form class="card form-card" method="post" enctype="multipart/form-data"
        action="<?= e(APP_URL) ?>/admin/02-employees/api/save.php" autocomplete="off">
    <?= csrf_field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int) $employee['id'] ?>"><?php endif; ?>

    <!-- ===== photo ===== -->
    <div class="employee-photo <?= isset($errors['photo']) ? 'has-error' : '' ?>">
      <?php $curPhoto = $isEdit && !empty($employee['photo_path']) ? UPLOAD_URL . '/' . $employee['photo_path'] : ''; ?>
      <div class="employee-photo__preview" id="photo-preview"
           data-fallback="<?= e(employee_initials(fv('name', $old, $employee) ?: 'D')) ?>">
        <?php if ($curPhoto !== ''): ?>
          <img src="<?= e($curPhoto) ?>" alt="">
        <?php else: ?>
          <span class="employee-photo__ph"><?= e(employee_initials(fv('name', $old, $employee) ?: 'D')) ?></span>
        <?php endif; ?>
      </div>
      <div class="employee-photo__body">
        <label for="f-photo">Employee photo <span class="c-muted">(optional, JPEG/PNG/WebP, max 180 KB)</span></label>
        <input class="input" id="f-photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp">
        <?php if ($isEdit && $curPhoto !== ''): ?>
          <label class="check employee-photo__remove">
            <input type="checkbox" name="photo_remove" value="1">
            <span>Remove the current photo</span>
          </label>
        <?php endif; ?>
        <p class="employee-photo__hint" id="photo-hint"></p>
        <?php if (isset($errors['photo'])): ?><span class="field-err"><?= e($errors['photo']) ?></span><?php endif; ?>
      </div>
    </div>

    <div class="form-grid">
      <div class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
        <label for="f-name">Full name</label>
        <input class="input" id="f-name" name="name" required maxlength="120"
               value="<?= e(fv('name', $old, $employee)) ?>">
        <?php if (isset($errors['name'])): ?><span class="field-err"><?= e($errors['name']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['code']) ? 'has-error' : '' ?>">
        <label for="f-code">Employee code <span class="c-muted">(optional)</span></label>
        <input class="input" id="f-code" name="code" maxlength="32" placeholder="e.g. DLR001"
               value="<?= e(fv('code', $old, $employee)) ?>">
        <?php if (isset($errors['code'])): ?><span class="field-err"><?= e($errors['code']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
        <label for="f-phone">Phone <span class="c-muted">(login)</span></label>
        <input class="input" id="f-phone" name="phone" required inputmode="tel" maxlength="20"
               value="<?= e(fv('phone', $old, $employee)) ?>">
        <?php if (isset($errors['phone'])): ?><span class="field-err"><?= e($errors['phone']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['email']) ? 'has-error' : '' ?>">
        <label for="f-email">Email <span class="c-muted">(optional)</span></label>
        <input class="input" id="f-email" name="email" type="email" maxlength="190"
               value="<?= e(fv('email', $old, $employee)) ?>">
        <?php if (isset($errors['email'])): ?><span class="field-err"><?= e($errors['email']) ?></span><?php endif; ?>
      </div>

      <div class="field">
        <label for="f-region">Region</label>
        <input class="input" id="f-region" name="region" maxlength="80" list="region-list"
               value="<?= e(fv('region', $old, $employee)) ?>">
        <datalist id="region-list">
          <?php foreach (employee_regions($pdo) as $r): ?><option value="<?= e($r) ?>"><?php endforeach; ?>
        </datalist>
      </div>

      <div class="field">
        <label for="f-area">Area</label>
        <input class="input" id="f-area" name="area" maxlength="120" list="area-list"
               value="<?= e(fv('area', $old, $employee)) ?>">
        <datalist id="area-list">
          <?php foreach (employee_areas($pdo) as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?>
        </datalist>
      </div>

      <div class="field <?= isset($errors['vehicle_type']) ? 'has-error' : '' ?>">
        <label>Vehicle <span class="c-muted">(for field visits)</span></label>
        <?php $veh = fv('vehicle_type', $old, $employee); ?>
        <div class="veh-pick">
          <?php foreach (['bike' => 'bi-bicycle', 'car' => 'bi-car-front', 'auto' => 'bi-truck'] as $vk => $vi): ?>
            <label class="veh-opt">
              <input type="radio" name="vehicle_type" value="<?= $vk ?>" <?= $veh === $vk ? 'checked' : '' ?>>
              <span><i class="bi <?= $vi ?>"></i> <?= ucfirst($vk) ?></span>
            </label>
          <?php endforeach; ?>
          <label class="veh-opt veh-opt--none">
            <input type="radio" name="vehicle_type" value="" <?= $veh === '' ? 'checked' : '' ?>>
            <span>Not set</span>
          </label>
        </div>
        <?php if (isset($errors['vehicle_type'])): ?><span class="field-err"><?= e($errors['vehicle_type']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['pin']) ? 'has-error' : '' ?>">
        <label for="f-pin">
          <?= $isEdit ? '4-digit PIN' : 'Set a 4-digit PIN' ?>
          <?php if ($isEdit): ?><span class="c-muted">(leave blank to keep)</span><?php endif; ?>
        </label>
        <div class="pin-input">
          <input class="input" id="f-pin" name="pin" type="password" inputmode="numeric" pattern="\d{4}" maxlength="4"
                 <?= $isEdit ? '' : 'required' ?> placeholder="&bull;&bull;&bull;&bull;" autocomplete="new-password">
          <button type="button" class="pin-input__eye" id="f-pin-toggle" aria-label="Show PIN" tabindex="-1">
            <i class="bi bi-eye"></i>
          </button>
        </div>
        <?php if (isset($errors['pin'])): ?><span class="field-err"><?= e($errors['pin']) ?></span><?php endif; ?>
      </div>

      <div class="field field--check">
        <label class="check">
          <input type="checkbox" name="is_active" value="1" <?= $activeChecked ? 'checked' : '' ?>>
          <span>Active - can log in and record visits</span>
        </label>
      </div>
    </div>

    <h3 class="form-section-h">Personal details <span class="c-muted">(optional)</span></h3>
    <div class="form-grid">
      <div class="field <?= isset($errors['dob']) ? 'has-error' : '' ?>">
        <label for="f-dob">Date of birth</label>
        <input class="input" id="f-dob" name="dob" type="date" max="<?= e(server_today()) ?>"
               value="<?= e(fv('dob', $old, $employee)) ?>">
        <?php if (isset($errors['dob'])): ?><span class="field-err"><?= e($errors['dob']) ?></span><?php endif; ?>
      </div>

      <div class="field <?= isset($errors['gender']) ? 'has-error' : '' ?>">
        <label for="f-gender">Gender</label>
        <?php $g = fv('gender', $old, $employee); ?>
        <select class="select" id="f-gender" name="gender">
          <option value="">Not set</option>
          <option value="male"   <?= $g === 'male' ? 'selected' : '' ?>>Male</option>
          <option value="female" <?= $g === 'female' ? 'selected' : '' ?>>Female</option>
          <option value="other"  <?= $g === 'other' ? 'selected' : '' ?>>Other</option>
        </select>
      </div>

      <div class="field <?= isset($errors['document_type']) ? 'has-error' : '' ?>">
        <label for="f-doctype">Document type</label>
        <?php $docType = fv('document_type', $old, $employee); ?>
        <select class="select" id="f-doctype" name="document_type">
          <option value="">Not set</option>
          <option value="citizenship"      <?= $docType === 'citizenship' ? 'selected' : '' ?>>Nagrita (Citizenship)</option>
          <option value="driving_license"  <?= $docType === 'driving_license' ? 'selected' : '' ?>>Driving License</option>
          <option value="passport"         <?= $docType === 'passport' ? 'selected' : '' ?>>Passport</option>
          <option value="national_id"      <?= $docType === 'national_id' ? 'selected' : '' ?>>NID Card</option>
        </select>
        <?php if (isset($errors['document_type'])): ?><span class="field-err"><?= e($errors['document_type']) ?></span><?php endif; ?>
      </div>

      <div class="field field--wide">
        <label for="f-idnum">ID No.</label>
        <input class="input" id="f-idnum" name="id_number" maxlength="40"
               value="<?= e(fv('id_number', $old, $employee)) ?>">
      </div>

      <?php
      $idFront = $isEdit && !empty($employee['id_photo_front']) ? UPLOAD_URL . '/' . $employee['id_photo_front'] : '';
      $idBack  = $isEdit && !empty($employee['id_photo_back'])  ? UPLOAD_URL . '/' . $employee['id_photo_back']  : '';
      $idShots = [
          ['front', 'ID card - front', $idFront, $errors['id_photo_front'] ?? null],
          ['back',  'ID card - back',  $idBack,  $errors['id_photo_back'] ?? null],
      ];
      foreach ($idShots as [$side, $label, $cur, $err]): ?>
        <div class="field id-shot <?= $err ? 'has-error' : '' ?>">
          <label for="f-id-<?= $side ?>"><?= e($label) ?> <span class="c-muted">(optional, max 180 KB)</span></label>
          <div class="id-shot__row">
            <span class="id-shot__thumb" id="id-<?= $side ?>-thumb">
              <?php if ($cur !== ''): ?>
                <a href="<?= e($cur) ?>" target="_blank" rel="noopener"><img src="<?= e($cur) ?>" alt=""></a>
              <?php else: ?>
                <i class="bi bi-person-vcard"></i>
              <?php endif; ?>
            </span>
            <div class="id-shot__body">
              <input class="input js-id-file" id="f-id-<?= $side ?>" name="id_photo_<?= $side ?>" type="file"
                     accept="image/jpeg,image/png,image/webp" data-thumb="id-<?= $side ?>-thumb" data-hint="id-<?= $side ?>-hint">
              <?php if ($isEdit && $cur !== ''): ?>
                <label class="check id-shot__remove">
                  <input type="checkbox" name="id_photo_<?= $side ?>_remove" value="1">
                  <span>Remove</span>
                </label>
              <?php endif; ?>
              <p class="id-shot__hint" id="id-<?= $side ?>-hint"></p>
              <?php if ($err): ?><span class="field-err"><?= e($err) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="field field--wide">
        <label for="f-address">Address</label>
        <input class="input" id="f-address" name="address" maxlength="255"
               value="<?= e(fv('address', $old, $employee)) ?>">
      </div>

      <div class="field">
        <label for="f-emname">Emergency contact name</label>
        <input class="input" id="f-emname" name="emergency_name" maxlength="120"
               value="<?= e(fv('emergency_name', $old, $employee)) ?>">
      </div>

      <div class="field <?= isset($errors['emergency_contact']) ? 'has-error' : '' ?>">
        <label for="f-emphone">Emergency contact number</label>
        <input class="input" id="f-emphone" name="emergency_contact" inputmode="tel" maxlength="20"
               value="<?= e(fv('emergency_contact', $old, $employee)) ?>">
        <?php if (isset($errors['emergency_contact'])): ?><span class="field-err"><?= e($errors['emergency_contact']) ?></span><?php endif; ?>
      </div>
    </div>

    <div class="form-actions">
      <a class="btn" href="<?= e(APP_URL) ?>/admin/02-employees/">Cancel</a>
      <button type="submit" class="btn btn--primary" id="f-submit">
        <i class="bi bi-check-lg"></i> <?= $isEdit ? 'Save changes' : 'Create Employee' ?>
      </button>
    </div>
  </form>

  <script>
    (function () {
      var MAX = 180 * 1024; // must match EMPLOYEE_PHOTO_MAX_BYTES in secure_config.php
      var submit  = document.getElementById('f-submit');
      var oversize = {};                          // track which inputs are too big

      function refreshSubmit() {
        submit.disabled = Object.keys(oversize).some(function (k) { return oversize[k]; });
      }

      // A file input + its preview node + its hint node. `onImage(url)` fills the
      // preview when a valid file is picked; `onClear()` restores the placeholder.
      function wire(input, thumb, hint, onImage, onClear) {
        if (!input) return;
        input.addEventListener('change', function () {
          hint.textContent = ''; hint.className = hint.className.replace(/\s*is-(ok|bad)/g, '');
          oversize[input.id] = false; refreshSubmit();

          var f = input.files && input.files[0];
          if (!f) { onClear(); return; }

          if (!/^image\/(jpeg|png|webp)$/.test(f.type)) {
            hint.textContent = 'Not a JPEG, PNG or WebP image.';
            hint.className += ' is-bad';
            input.value = ''; onClear(); return;
          }
          if (f.size > MAX) {
            hint.textContent = 'Too large: ' + Math.round(f.size / 1024) + ' KB. Must be 180 KB or smaller.';
            hint.className += ' is-bad';
            oversize[input.id] = true; refreshSubmit();
            return;
          }
          hint.textContent = 'OK - ' + Math.round(f.size / 1024) + ' KB';
          hint.className += ' is-ok';
          onImage(URL.createObjectURL(f));
        });
      }

      // ---- profile photo ----
      var pInput   = document.getElementById('f-photo');
      var pPreview = document.getElementById('photo-preview');
      var pHint    = document.getElementById('photo-hint');
      var nameEl   = document.getElementById('f-name');
      function initials(s) {
        var p = (s || 'D').trim().split(/\s+/);
        return ((p[0] || 'D')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
      }
      function pPlaceholder() {
        pPreview.innerHTML = '<span class="employee-photo__ph">' + initials(nameEl ? nameEl.value : 'D') + '</span>';
      }
      nameEl && nameEl.addEventListener('input', function () {
        if (!pPreview.querySelector('img[data-user]')) pPlaceholder();
      });
      wire(pInput, pPreview, pHint,
        function (url) { pPreview.innerHTML = '<img data-user src="' + url + '" alt="">'; },
        pPlaceholder);

      // ---- ID card photos (front / back) ----
      document.querySelectorAll('.js-id-file').forEach(function (input) {
        var thumb = document.getElementById(input.dataset.thumb);
        var hint  = document.getElementById(input.dataset.hint);
        wire(input, thumb, hint,
          function (url) { thumb.innerHTML = '<img src="' + url + '" alt="">'; },
          function () { thumb.innerHTML = '<i class="bi bi-person-vcard"></i>'; });
      });

      // ---- PIN show/hide toggle ----
      var pinInput  = document.getElementById('f-pin');
      var pinToggle = document.getElementById('f-pin-toggle');
      if (pinInput && pinToggle) {
        pinToggle.addEventListener('click', function () {
          var reveal = pinInput.type === 'password';
          pinInput.type = reveal ? 'text' : 'password';
          pinToggle.querySelector('i').className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
          pinToggle.setAttribute('aria-label', reveal ? 'Hide PIN' : 'Show PIN');
          pinInput.focus();
        });
      }
    })();
  </script>

<?php
require dirname(__DIR__) . '/components/footer/footer.php';
