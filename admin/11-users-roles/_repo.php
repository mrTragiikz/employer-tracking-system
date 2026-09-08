<?php
/**
 * admin/11-users-roles/_repo.php
 *
 * Data access for the Users & Roles section - admin accounts only (the field
 * employee roster lives in 02-employees/). Included by users-roles.php,
 * form.php and the api/ endpoints. Pure functions over $pdo - no output.
 *
 * Every admin row has role = 'admin'. Exactly one may have is_super_admin = 1
 * - that account is protected: it can never be deleted, and can only edit its
 * own name and password (never its own or anyone's role/flag).
 */

declare(strict_types=1);

/** The one super admin, or null if none is flagged (should not happen post-migration). */
function super_admin_find(PDO $pdo): ?array
{
    $st = $pdo->query(
        "SELECT * FROM users WHERE role = 'admin' AND is_super_admin = 1 AND deleted_at IS NULL LIMIT 1"
    );
    $row = $st->fetch();
    return $row ?: null;
}

/** Regular (non-super) admins, newest first. */
function admins_list(PDO $pdo): array
{
    return $pdo->query(
        "SELECT * FROM users
          WHERE role = 'admin' AND is_super_admin = 0 AND deleted_at IS NULL
          ORDER BY created_at DESC"
    )->fetchAll();
}

/**
 * The MANAGEABLE admin accounts for the Users grid - regular (non-super)
 * admins only. The Super Admin's own row is never a row in this list (it
 * has its own "Current Admin" card instead) - per spec, it should not
 * appear as something that can be managed.
 *
 * @param string $q      optional name/phone/email/username search
 * @param string $status '' (all) | 'active' | 'inactive'
 * @param string $sort   'newest' (default) | 'oldest' | 'name'
 */
function admins_all(PDO $pdo, string $q = '', string $status = '', string $sort = 'newest'): array
{
    $where = ["role = 'admin'", 'is_super_admin = 0', 'deleted_at IS NULL'];
    $args  = [];
    if ($q !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ? OR email LIKE ? OR username LIKE ?)';
        $like = '%' . $q . '%';
        array_push($args, $like, $like, $like, $like);
    }
    if ($status === 'active' || $status === 'inactive') {
        $where[] = 'is_active = ?';
        $args[] = $status === 'active' ? 1 : 0;
    }
    $orderBy = match ($sort) {
        'oldest' => 'created_at ASC',
        'name'   => 'name ASC',
        default  => 'created_at DESC',
    };
    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare(
        "SELECT * FROM users
          WHERE $whereSql
          ORDER BY $orderBy"
    );
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/** One admin (super or not) by id, or null. */
function admin_find(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare(
        "SELECT * FROM users WHERE id = ? AND role = 'admin' AND deleted_at IS NULL"
    );
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Initials for the avatar placeholder. */
function admin_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $a = mb_substr($parts[0] ?? '', 0, 1);
    $b = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
    return mb_strtoupper($a . $b);
}

/**
 * Validate a submitted admin form.
 *
 * @param array    $in        raw $_POST
 * @param ?int     $editingId null on create
 * @param bool     $isSelfEdit true when the super admin is editing their own
 *                              row (name + password only, everything else locked)
 * @return array{ok:bool, errors:array<string,string>, data:array}
 */
function admin_validate(array $in, ?int $editingId, PDO $pdo, bool $isSelfEdit = false): array
{
    $errors = [];

    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') {
        $errors['name'] = 'Name is required.';
    } elseif (mb_strlen($name) > 120) {
        $errors['name'] = 'Name is too long.';
    }

    $password = (string) ($in['password'] ?? '');
    $confirm  = (string) ($in['password_confirm'] ?? '');
    if ($editingId === null && $password === '') {
        $errors['password'] = 'Password is required.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif ($password !== '' && $password !== $confirm) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    // Self-edit (the super admin editing their own account) only ever
    // touches name + password - nothing else is read from $in for that path.
    if ($isSelfEdit) {
        return [
            'ok'     => empty($errors),
            'errors' => $errors,
            'data'   => ['name' => $name, 'password' => $password],
        ];
    }

    $username = trim((string) ($in['username'] ?? ''));
    if ($username === '') {
        $errors['username'] = 'Username is required.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.]{3,60}$/', $username)) {
        $errors['username'] = 'Username may only use letters, numbers, dot and underscore (3-60 characters).';
    } else {
        $dupe = $pdo->prepare(
            'SELECT id FROM users WHERE username = ? AND deleted_at IS NULL' .
            ($editingId ? ' AND id <> ?' : '')
        );
        $dupe->execute($editingId ? [$username, $editingId] : [$username]);
        if ($dupe->fetch()) {
            $errors['username'] = 'That username is already in use.';
        }
    }

    $email = trim((string) ($in['email'] ?? ''));
    if ($email !== '') {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'That does not look like a valid email.';
        } else {
            $dupe = $pdo->prepare(
                'SELECT id FROM users WHERE email = ? AND deleted_at IS NULL' .
                ($editingId ? ' AND id <> ?' : '')
            );
            $dupe->execute($editingId ? [$email, $editingId] : [$email]);
            if ($dupe->fetch()) {
                $errors['email'] = 'That email is already in use.';
            }
        }
    }

    $phone = trim((string) ($in['phone'] ?? ''));
    if ($phone !== '') {
        if (!preg_match('/^[0-9+ ()-]{6,20}$/', $phone)) {
            $errors['phone'] = 'That does not look like a valid phone number.';
        } else {
            $dupe = $pdo->prepare(
                'SELECT id FROM users WHERE phone = ? AND deleted_at IS NULL' .
                ($editingId ? ' AND id <> ?' : '')
            );
            $dupe->execute($editingId ? [$phone, $editingId] : [$phone]);
            if ($dupe->fetch()) {
                $errors['phone'] = 'That phone number is already in use.';
            }
        }
    }

    $idNumber = trim((string) ($in['id_number'] ?? ''));
    if (mb_strlen($idNumber) > 40) {
        $errors['id_number'] = 'Document number is too long.';
    }

    $isActive = !empty($in['is_active']) ? 1 : 0;

    return [
        'ok'     => empty($errors),
        'errors' => $errors,
        'data'   => [
            'name'      => $name,
            'username'  => $username,
            'email'     => $email !== '' ? $email : null,
            'phone'     => $phone !== '' ? $phone : null,
            'id_number' => $idNumber !== '' ? $idNumber : null,
            'is_active' => $isActive,
            'password'  => $password,
        ],
    ];
}

/** Write an audit_log row. */
function admin_audit(PDO $pdo, int $actorId, string $action, int $targetId, ?array $before, ?array $after): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO audit_log (actor_id, action, entity, entity_id, before_json, after_json, ip)
         VALUES (?, ?, 'admin', ?, ?, ?, ?)"
    );
    $stmt->execute([
        $actorId, $action, $targetId,
        $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
        $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        client_ip_bin(),
    ]);
}
