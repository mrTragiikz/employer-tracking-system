<?php
/**
 * admin/02-employees/api/save.php - create or update an Employee.
 *
 * POST (form-encoded, from form.php):
 * id? int present => update, absent => create
 * name string
 * phone string unique among non-deleted users
 * email? string unique if given
 * region? string
 * area? string
 * code? string unique if given (e.g. DLR001)
 * pin? 4 digits required on create; on update, blank = keep current
 * is_active? "1"
 *
 * On success: redirect to the list with ?ok=created|updated
 * On validation error: re-render form.php with the errors (handled there via
 * a session bag) - here we just stash + redirect back.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // bootstrap + auth gate + CSRF
$me = require_admin();
$me = require_super_admin($me); // adding/editing Employees is Super Admin only
api_method('POST');
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 3) . '/includes/upload.php';

$editingId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;

if ($editingId !== null && !employee_find($pdo, $editingId)) {
    $_SESSION['employee_form_error'] = ['_' => 'That Employee no longer exists.'];
    redirect(APP_URL . '/admin/02-employees/');
}

$check = employee_validate($_POST, $editingId, $pdo);

// ---- photos (all optional, <= 200 KB each) -----------------------------
$noFile   = ['error' => UPLOAD_ERR_NO_FILE];
$uploads  = [
    'photo_path'     => ['field' => 'photo',        'prefix' => 'employee',    'err' => 'photo',          'up' => save_employee_photo($_FILES['photo'] ?? $noFile, 'employee')],
    'id_photo_front' => ['field' => 'id_photo_front','prefix' => 'employee_id', 'err' => 'id_photo_front', 'up' => save_employee_photo($_FILES['id_photo_front'] ?? $noFile, 'employee_id')],
    'id_photo_back'  => ['field' => 'id_photo_back', 'prefix' => 'employee_id', 'err' => 'id_photo_back',  'up' => save_employee_photo($_FILES['id_photo_back'] ?? $noFile, 'employee_id')],
];
foreach ($uploads as $u) {
    if (!$u['up']['ok']) {
        $check['ok'] = false;
        $check['errors'][$u['err']] = $u['up']['error'];
    }
}

if (!$check['ok']) {
    // rejected files were never moved into place - clean up the ones that WERE
    foreach ($uploads as $u) {
        if (!empty($u['up']['stored_path'])) delete_upload($u['up']['stored_path']);
    }
    $_SESSION['employee_form_error'] = $check['errors'];
    $_SESSION['employee_form_old'] = $_POST;
    $back = APP_URL . '/admin/02-employees/form.php' . ($editingId ? '?id=' . $editingId : '');
    redirect($back);
}

$d = $check['data'];

// resolve each photo path: new upload > "remove" flag > keep existing
$before0    = $editingId !== null ? employee_find($pdo, $editingId) : null;
$oldPaths   = [];
$newPaths   = [];
foreach ($uploads as $col => $u) {
    $oldPaths[$col] = $before0[$col] ?? null;
    $fresh = empty($u['up']['skipped']) ? ($u['up']['stored_path'] ?? null) : null;
    $newPaths[$col] = $fresh;
    if ($fresh !== null) {
        $d[$col] = $fresh;
    } elseif (!empty($_POST[$u['field'] . '_remove'])) {
        $d[$col] = null;
    } else {
        $d[$col] = $oldPaths[$col];
    }
}

$pdo->beginTransaction();
try {
    if ($editingId === null) {
        // ---- create ----
        $stmt = $pdo->prepare(
            "INSERT INTO users
               (role, name, phone, email, region, area, code, secret_hash, is_active, created_by,
                dob, gender, id_number, document_type, id_photo_front, id_photo_back,
                emergency_name, emergency_contact, address,
                photo_path, vehicle_type)
             VALUES
               ('employee', :name, :phone, :email, :region, :area, :code, :hash, :active, :by,
                :dob, :gender, :idnum, :doctype, :idfront, :idback,
                :emname, :emphone, :address,
                :photo, :vehicle)"
        );
        $stmt->execute([
            ':name' => $d['name'],
            ':phone' => $d['phone'],
            ':email' => $d['email'],
            ':region' => $d['region'],
            ':area' => $d['area'],
            ':code' => $d['code'],
            ':hash' => password_hash($d['pin'], PASSWORD_DEFAULT),
            ':active' => $d['is_active'],
            ':by' => $me['id'],
            ':dob' => $d['dob'],
            ':gender' => $d['gender'],
            ':idnum' => $d['id_number'],
            ':doctype' => $d['document_type'],
            ':idfront' => $d['id_photo_front'],
            ':idback' => $d['id_photo_back'],
            ':emname' => $d['emergency_name'],
            ':emphone' => $d['emergency_contact'],
            ':address' => $d['address'],
            ':photo' => $d['photo_path'],
            ':vehicle' => $d['vehicle_type'],
        ]);
        $id = (int) $pdo->lastInsertId();
        employee_audit($pdo, $me['id'], 'Employee.create', $id, null, [
            'name' => $d['name'], 'phone' => $d['phone'], 'email' => $d['email'],
            'region' => $d['region'], 'area' => $d['area'], 'code' => $d['code'],
            'is_active' => $d['is_active'],
        ]);
        $pdo->commit();
        redirect(APP_URL . '/admin/02-employees/?ok=created');
    }

    // ---- update ----
    $before = employee_find($pdo, $editingId);

    $sql = "UPDATE users SET
                name = :name, phone = :phone, email = :email,
                region = :region, area = :area, code = :code, is_active = :active,
                dob = :dob, gender = :gender, id_number = :idnum, document_type = :doctype,
                id_photo_front = :idfront, id_photo_back = :idback,
                emergency_name = :emname, emergency_contact = :emphone, address = :address,
                photo_path = :photo, vehicle_type = :vehicle";
    $params = [
        ':name' => $d['name'],
        ':phone' => $d['phone'],
        ':email' => $d['email'],
        ':region' => $d['region'],
        ':area' => $d['area'],
        ':code' => $d['code'],
        ':active' => $d['is_active'],
        ':dob' => $d['dob'],
        ':gender' => $d['gender'],
        ':idnum' => $d['id_number'],
        ':doctype' => $d['document_type'],
        ':idfront' => $d['id_photo_front'],
        ':idback' => $d['id_photo_back'],
        ':emname' => $d['emergency_name'],
        ':emphone' => $d['emergency_contact'],
        ':address' => $d['address'],
        ':photo' => $d['photo_path'],
        ':vehicle' => $d['vehicle_type'],
        ':id' => $editingId,
    ];
    if ($d['pin'] !== '') {
        $sql .= ", secret_hash = :hash, failed_logins = 0, locked_until = NULL";
        $params[':hash'] = password_hash($d['pin'], PASSWORD_DEFAULT);
    }
    $sql .= " WHERE id = :id AND role = 'employee'";
    $pdo->prepare($sql)->execute($params);

    employee_audit($pdo, $me['id'], 'Employee.update', $editingId,
        [
            'name' => $before['name'], 'phone' => $before['phone'], 'email' => $before['email'],
            'region' => $before['region'], 'area' => $before['area'], 'code' => $before['code'],
            'is_active' => (int) $before['is_active'],
        ],
        [
            'name' => $d['name'], 'phone' => $d['phone'], 'email' => $d['email'],
            'region' => $d['region'], 'area' => $d['area'], 'code' => $d['code'],
            'is_active' => $d['is_active'], 'pin_changed' => $d['pin'] !== '',
        ]
    );
    $pdo->commit();

    // old photo files are now orphaned if they were replaced or removed
    foreach ($oldPaths as $col => $oldPath) {
        if ($oldPath && $oldPath !== $d[$col]) {
            delete_upload($oldPath);
        }
    }

    redirect(APP_URL . '/admin/02-employees/?ok=updated');

} catch (PDOException $e) {
    $pdo->rollBack();
    // just-uploaded files are now orphaned - clean them up
    foreach ($newPaths as $newPath) {
        if ($newPath) delete_upload($newPath);
    }
    // Unique-key race that slipped past validation, etc.
    if ($e->getCode() === '23000') {
        $_SESSION['employee_form_error'] = ['_' => 'That phone, email, or code is already in use.'];
    } else {
        $_SESSION['employee_form_error'] = ['_' => 'Could not save. Please try again.'];
        if (APP_ENV === 'development') {
            $_SESSION['employee_form_error']['_'] .= ' (' . $e->getMessage() . ')';
        }
    }
    $_SESSION['employee_form_old'] = $_POST;
    redirect(APP_URL . '/admin/02-employees/form.php' . ($editingId ? '?id=' . $editingId : ''));
}
