<?php
/**
 * admin/11-users-roles/api/save.php - create/update an Admin, or any admin's
 * own self-edit.
 *
 * POST (form-encoded, from form.php):
 *   id?       int   present => update, absent => create
 *   self?     "1"   the signed-in admin (super or regular) editing their own
 *                    row (name + password only) - the only mode a Normal
 *                    Admin may use
 *   name      string
 *   password  string  required on create; blank on update = keep current
 *   username, email?, phone?, id_number?, is_active?  (not on self-edit)
 *   photo  file upload, profile photo (not on self-edit)
 *   id_photo_front / id_photo_back  file uploads (not on self-edit)
 *
 * On success: redirect to the list with ?ok=created|updated
 * On validation error: re-render form.php with errors via a session bag.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // bootstrap + auth gate + CSRF
$me = require_admin();
api_method('POST');
require dirname(__DIR__) . '/_repo.php';
require dirname(__DIR__, 3) . '/includes/upload.php';

$isSelf    = ($_POST['self'] ?? '') === '1';
$editingId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;

if (!$isSelf) {
    // Managing OTHER admins is Super Admin only; self-edit is open to all.
    $me = require_super_admin($me);
}

$super = super_admin_find($pdo);

if ($isSelf) {
    // Any admin, only their own row.
    if ($editingId !== (int) $me['id']) {
        redirect(APP_URL . '/admin/11-users-roles/');
    }
} elseif ($editingId !== null) {
    $target = admin_find($pdo, $editingId);
    if (!$target) {
        $_SESSION['admin_form_error'] = ['_' => 'That admin no longer exists.'];
        redirect(APP_URL . '/admin/11-users-roles/');
    }
    if (!empty($target['is_super_admin'])) {
        // the super admin row can never be edited through the regular form
        redirect(APP_URL . '/admin/11-users-roles/');
    }
}

$check = admin_validate($_POST, $editingId, $pdo, $isSelf);

// ---- photos (regular admin only, not on self-edit) ------------------------
$uploads = [];
if (!$isSelf) {
    $noFile  = ['error' => UPLOAD_ERR_NO_FILE];
    $uploads = [
        'photo_path'     => ['field' => 'photo',           'err' => 'photo',          'up' => save_employee_photo($_FILES['photo'] ?? $noFile, 'admin')],
        'id_photo_front' => ['field' => 'id_photo_front', 'err' => 'id_photo_front', 'up' => save_employee_photo($_FILES['id_photo_front'] ?? $noFile, 'admin_id')],
        'id_photo_back'  => ['field' => 'id_photo_back',  'err' => 'id_photo_back',  'up' => save_employee_photo($_FILES['id_photo_back'] ?? $noFile, 'admin_id')],
    ];
    foreach ($uploads as $u) {
        if (!$u['up']['ok']) {
            $check['ok'] = false;
            $check['errors'][$u['err']] = $u['up']['error'];
        }
    }
}

if (!$check['ok']) {
    foreach ($uploads as $u) {
        if (!empty($u['up']['stored_path'])) delete_upload($u['up']['stored_path']);
    }
    $_SESSION['admin_form_error'] = $check['errors'];
    $_SESSION['admin_form_old']   = $_POST;
    $back = APP_URL . '/admin/11-users-roles/form.php'
          . ($isSelf ? '?self=1' : ($editingId ? '?id=' . $editingId : ''));
    redirect($back);
}

$d = $check['data'];

// resolve each photo path: new upload > "remove" flag > keep existing
$before0  = $editingId !== null ? admin_find($pdo, $editingId) : null;
$oldPaths = [];
$newPaths = [];
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
    if ($isSelf) {
        // ---- self-edit (any admin, super or regular): name + password only ----
        $before = admin_find($pdo, $editingId);
        $sql = "UPDATE users SET name = :name";
        $params = [':name' => $d['name'], ':id' => $editingId];
        if ($d['password'] !== '') {
            $sql .= ", secret_hash = :hash, failed_logins = 0, locked_until = NULL";
            $params[':hash'] = password_hash($d['password'], PASSWORD_DEFAULT);
        }
        $sql .= " WHERE id = :id AND role = 'admin'";
        $pdo->prepare($sql)->execute($params);

        admin_audit($pdo, $me['id'], 'admin.self_update', $editingId,
            ['name' => $before['name'] ?? null],
            ['name' => $d['name'], 'password_changed' => $d['password'] !== '']);
        $pdo->commit();

        // the topbar/session reads $_SESSION['user']['name'] - keep it in
        // sync so the new name shows immediately, not just after re-login
        $_SESSION['user']['name'] = $d['name'];

        // a Normal Admin has no Users & Roles page to land back on
        $selfOk = !empty($me['is_super_admin'])
            ? APP_URL . '/admin/11-users-roles/?ok=updated'
            : APP_URL . '/admin/01-dashboard/?ok=updated';
        redirect($selfOk);
    }

    if ($editingId === null) {
        // ---- create ----
        $stmt = $pdo->prepare(
            "INSERT INTO users
               (role, is_super_admin, name, username, email, phone, id_number,
                photo_path, id_photo_front, id_photo_back, secret_hash, is_active, created_by)
             VALUES
               ('admin', 0, :name, :username, :email, :phone, :idnum,
                :photo, :idfront, :idback, :hash, :active, :by)"
        );
        $stmt->execute([
            ':name'     => $d['name'],
            ':username' => $d['username'],
            ':email'    => $d['email'],
            ':phone'    => $d['phone'],
            ':idnum'    => $d['id_number'],
            ':photo'    => $d['photo_path'],
            ':idfront'  => $d['id_photo_front'],
            ':idback'   => $d['id_photo_back'],
            ':hash'     => password_hash($d['password'], PASSWORD_DEFAULT),
            ':active'   => $d['is_active'],
            ':by'       => $me['id'],
        ]);
        $id = (int) $pdo->lastInsertId();
        admin_audit($pdo, $me['id'], 'admin.create', $id, null, [
            'name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'],
            'phone' => $d['phone'], 'is_active' => $d['is_active'],
        ]);
        $pdo->commit();
        redirect(APP_URL . '/admin/11-users-roles/?ok=created');
    }

    // ---- update (regular admin) ----
    $before = admin_find($pdo, $editingId);

    $sql = "UPDATE users SET
                name = :name, username = :username, email = :email, phone = :phone,
                id_number = :idnum, photo_path = :photo,
                id_photo_front = :idfront, id_photo_back = :idback,
                is_active = :active";
    $params = [
        ':name'     => $d['name'],
        ':username' => $d['username'],
        ':email'    => $d['email'],
        ':phone'    => $d['phone'],
        ':idnum'    => $d['id_number'],
        ':photo'    => $d['photo_path'],
        ':idfront'  => $d['id_photo_front'],
        ':idback'   => $d['id_photo_back'],
        ':active'   => $d['is_active'],
        ':id'       => $editingId,
    ];
    if ($d['password'] !== '') {
        $sql .= ", secret_hash = :hash, failed_logins = 0, locked_until = NULL";
        $params[':hash'] = password_hash($d['password'], PASSWORD_DEFAULT);
    }
    $sql .= " WHERE id = :id AND role = 'admin' AND is_super_admin = 0";
    $pdo->prepare($sql)->execute($params);

    admin_audit($pdo, $me['id'], 'admin.update', $editingId,
        ['name' => $before['name'], 'username' => $before['username'], 'email' => $before['email'],
         'phone' => $before['phone'], 'is_active' => (int) $before['is_active']],
        ['name' => $d['name'], 'username' => $d['username'], 'email' => $d['email'],
         'phone' => $d['phone'], 'is_active' => $d['is_active'], 'password_changed' => $d['password'] !== '']
    );
    $pdo->commit();

    // keep the topbar/session name in sync when an admin edits their own row
    if ((int) $editingId === (int) $me['id']) {
        $_SESSION['user']['name'] = $d['name'];
    }

    foreach ($oldPaths as $col => $oldPath) {
        if ($oldPath && $oldPath !== $d[$col]) {
            delete_upload($oldPath);
        }
    }

    redirect(APP_URL . '/admin/11-users-roles/?ok=updated');

} catch (PDOException $e) {
    $pdo->rollBack();
    foreach ($newPaths as $newPath) {
        if ($newPath) delete_upload($newPath);
    }
    if ($e->getCode() === '23000') {
        $_SESSION['admin_form_error'] = ['_' => 'That username, email, or phone is already in use.'];
    } else {
        $_SESSION['admin_form_error'] = ['_' => 'Could not save. Please try again.'];
        if (APP_ENV === 'development') {
            $_SESSION['admin_form_error']['_'] .= ' (' . $e->getMessage() . ')';
        }
    }
    $_SESSION['admin_form_old'] = $_POST;
    $back = APP_URL . '/admin/11-users-roles/form.php'
          . ($isSelf ? '?self=1' : ($editingId ? '?id=' . $editingId : ''));
    redirect($back);
}
