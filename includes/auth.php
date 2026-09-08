<?php
/**
 * Authentication + session guards.
 *
 * Two audiences share the users table:
 * - admin : email + password, uses the desktop panel
 * - Employee : phone + 4-digit PIN, field app, locked to one device_id (rule 10)
 *
 * Every file in api/ and every admin/ or field/ page (except the login pages)
 * must call require_admin() or require_employee() right after bootstrap.
 */

declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

/** Put the authenticated user into the session and rotate the session id. */
function login_session(array $user): void
{
    session_regenerate_id(true); // security requirement
    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'role' => $user['role'],
        'name' => $user['name'],
        'email' => $user['email'] ?? null,
        'phone' => $user['phone'] ?? null,
        'is_super_admin' => !empty($user['is_super_admin']),
    ];
    $_SESSION['login_at'] = time();
}

function logout_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * DEV ONLY. Log in as the first active user of the given role, no credentials.
 * Called from bootstrap.php, and ONLY when APP_ENV==='development' and the
 * DEV_AUTOLOGIN constant is defined (in secure_config/secure_config.php).
 * Never reachable in production.
 */
function dev_autologin(string $role): void
{
    if (APP_ENV !== 'development') {
        return;
    }
    $role = $role === 'employee' ? 'employee' : 'admin';

    global $pdo;
    try {
        $st = $pdo->prepare(
            'SELECT id, role, is_super_admin, name, email, phone
               FROM users
              WHERE role = ? AND is_active = 1 AND deleted_at IS NULL
              ORDER BY id ASC LIMIT 1'
        );
        $st->execute([$role]);
        $u = $st->fetch();
    } catch (Throwable $e) {
        return;
    }

    if ($u) {
        // Not login_session(): skip the id rotation so the dev session is stable.
        $_SESSION['user'] = [
            'id' => (int) $u['id'],
            'role' => $u['role'],
            'name' => $u['name'],
            'email' => $u['email'] ?? null,
            'phone' => $u['phone'] ?? null,
            'is_super_admin' => !empty($u['is_super_admin']),
        ];
        $_SESSION['login_at'] = time();
        $_SESSION['dev_login'] = true;
    }
}

/** Guard: admin pages / admin api. */
function require_admin(): array
{
    $u = current_user();
    if (!$u || $u['role'] !== 'admin') {
        deny('admin');
    }
    return $u;
}

/**
 * Guard: Super Admin only (Users & Roles, Settings). Call require_admin()
 * first, then this. A Normal Admin IS authenticated - this is a permission
 * refusal, not a login refusal, so it redirects to the dashboard (pages) or
 * returns 403 (api/), never back to the login screen.
 */
function require_super_admin(array $me): array
{
    if (empty($me['is_super_admin'])) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            json_error('Super Admin only.', 403);
        }
        redirect(APP_URL . '/admin/01-dashboard/?err=forbidden');
    }
    return $me;
}

/**
 * Guard: field app pages / field api. The field-app user IS an Employee.
 *
 * Re-checks fresh from the DB on every call (not just at login) - none of
 * these can rely on the session alone reflecting a change another admin
 * makes mid-session:
 *  - is_active = 0 (Lock Employee) or deleted_at set -> suspended screen.
 *  - security_stamp_at newer than this session's login_at -> a PIN reset or
 *    Device reset happened after this session started (e.g. the phone was
 *    lost/stolen and the admin reset it) - the old session must not survive
 *    that, so it's killed the same way as a lock, just with the generic
 *    "signed out" reason instead of "suspended".
 */
function require_employee(): array
{
    global $pdo;

    $u = current_user();
    if (!$u || $u['role'] !== 'employee') {
        deny('employee');
    }

    $row = $pdo->prepare(
        "SELECT is_active, deleted_at, security_stamp_at, phone FROM users WHERE id = ? AND role = 'employee'"
    );
    $row->execute([$u['id']]);
    $state = $row->fetch();

    if ($state === false || (int) $state['is_active'] !== 1 || $state['deleted_at'] !== null) {
        $phone = $state['phone'] ?? ($u['phone'] ?? '');
        logout_session();
        // Pass the phone so the suspended screen can poll status.php and
        // auto-reload the moment the admin unlocks the account, instead of
        // the employee sitting on a dead screen until they manually refresh.
        // A deleted account has no phone to poll with - fine, it just won't.
        $q = $phone !== '' ? '?suspended=1&p=' . rawurlencode($phone) : '?suspended=1';
        redirect(APP_URL . '/field/login/' . $q);
    }

    if ($state['security_stamp_at'] !== null
        && strtotime($state['security_stamp_at']) > (int) ($_SESSION['login_at'] ?? 0)
    ) {
        logout_session();
        redirect(APP_URL . '/field/login/?timeout=1');
    }

    return $u;
}

/** Back-compat alias - the field app's authenticated user is an Employee. */
function require_field(): array
{
    return require_employee();
}

/** Guard: any authenticated user. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        deny('any');
    }
    return $u;
}

function deny(string $audience): never
{
    if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
        json_error('Not authenticated.', 401);
    }
    $login = $audience === 'employee' ? APP_URL . '/field/login/' : APP_URL . '/admin/login/';
    redirect($login);
}

/** Record an auth event. */
function auth_event(PDO $pdo, ?int $userId, string $event, ?string $detail = null): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO auth_events (user_id, event, detail, ip, user_agent)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $event, $detail, client_ip_bin(), client_ua()]);
}

/**
 * Throttle check: too many failed attempts for this identifier or IP recently?
 * Returns true if the caller should BLOCK the attempt.
 *
 * This is a blunt, generic spam guard (catches e.g. a script hammering many
 * unknown phone numbers/usernames from one IP) - it must stay ABOVE both
 * account-level lock thresholds (admin: 5, employee: EMPLOYEE_MAX_FAILED_LOGINS)
 * or it fires first and pre-empts the intended per-account "locked" message
 * with a generic "too many attempts" one before the real account lock ever
 * kicks in.
 */
function login_throttled(PDO $pdo, string $identifier): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS n
           FROM login_attempts
          WHERE succeeded = 0
            AND created_at > (NOW() - INTERVAL 15 MINUTE)
            AND (identifier = ? OR ip = ?)'
    );
    $stmt->execute([$identifier, client_ip_bin()]);
    return (int) $stmt->fetchColumn() >= (EMPLOYEE_MAX_FAILED_LOGINS + 5);
}

function record_login_attempt(PDO $pdo, string $identifier, bool $ok): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (identifier, ip, succeeded) VALUES (?, ?, ?)'
    );
    $stmt->execute([$identifier, client_ip_bin(), $ok ? 1 : 0]);
}

/**
 * Admin login by username + password.
 *
 * Returns the user row on success, or a string error code on failure:
 *   'throttled'   too many recent failures for this username or IP
 *   'locked'      the account is locked (failed_logins backoff)
 *   'bad'         unknown username, wrong password, inactive, or not an admin
 *
 * Locking: 5 consecutive bad passwords -> locked_until = now + 15 min.
 * A correct password clears failed_logins + locked_until.
 */
function admin_authenticate(PDO $pdo, string $username, string $password): array|string
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return 'bad';
    }
    if (login_throttled($pdo, $username)) {
        return 'throttled';
    }

    $st = $pdo->prepare(
        "SELECT id, role, is_super_admin, name, username, email, phone, secret_hash,
                is_active, failed_logins, locked_until
           FROM users
          WHERE role = 'admin' AND username = ? AND deleted_at IS NULL
          LIMIT 1"
    );
    $st->execute([$username]);
    $u = $st->fetch();

    // Always burn a bit of time so a missing user is not obviously faster.
    if (!$u) {
        password_verify($password, '$2y$10$usesomesillystringforsalt$0000000000000000000000000000');
        record_login_attempt($pdo, $username, false);
        auth_event($pdo, null, 'login_fail', 'unknown admin username: ' . mb_substr($username, 0, 60));
        return 'bad';
    }

    if ($u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time()) {
        record_login_attempt($pdo, $username, false);
        auth_event($pdo, (int) $u['id'], 'locked', 'login attempt while locked');
        return 'locked';
    }

    if (!password_verify($password, $u['secret_hash']) || (int) $u['is_active'] !== 1) {
        $fails = (int) $u['failed_logins'] + 1;
        $lock  = $fails >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
        $pdo->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
            ->execute([$fails, $lock, $u['id']]);
        record_login_attempt($pdo, $username, false);
        auth_event($pdo, (int) $u['id'], $lock ? 'locked' : 'login_fail',
            (int) $u['is_active'] !== 1 ? 'inactive account' : 'wrong password');
        return $lock ? 'locked' : 'bad';
    }

    // Success.
    $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')
        ->execute([$u['id']]);
    record_login_attempt($pdo, $username, true);
    auth_event($pdo, (int) $u['id'], 'login_ok', null);

    unset($u['secret_hash']);
    return $u;
}

/**
 * Employee login by phone + 4-digit PIN, enforcing the one-device rule (10).
 *
 * Returns the user row on success, or a string error code on failure:
 *   'throttled'  too many recent failures for this phone or IP
 *   'locked'     the account is locked (failed_logins backoff)
 *   'bad'        unknown phone, wrong PIN, inactive, or not an employee
 *   'device'     PIN was correct but this phone is bound to a DIFFERENT device
 *
 * Device binding: NULL device_id on the row means "not yet bound" - the
 * first successful login binds it (and logs field_devices + auth_events
 * device_bound). A subsequent login from a different $deviceId is refused
 * with 'device' - an admin must clear the binding (02-employees/api/
 * reset-device.php) before the employee can log in from a new phone.
 *
 * Locking: 5 consecutive bad PINs -> locked_until = now + 15 min, same as
 * admin_authenticate().
 */
function employee_authenticate(PDO $pdo, string $phone, string $pin, string $deviceId): array|string
{
    $phone = trim($phone);
    if ($phone === '' || !preg_match('/^\d{4}$/', $pin) || $deviceId === '') {
        return 'bad';
    }
    if (login_throttled($pdo, $phone)) {
        return 'throttled';
    }

    $st = $pdo->prepare(
        "SELECT id, role, name, phone, secret_hash, is_active,
                failed_logins, locked_until, device_id
           FROM users
          WHERE role = 'employee' AND phone = ? AND deleted_at IS NULL
          LIMIT 1"
    );
    $st->execute([$phone]);
    $u = $st->fetch();

    // Always burn a bit of time so a missing user is not obviously faster.
    if (!$u) {
        password_verify($pin, '$2y$10$usesomesillystringforsalt$0000000000000000000000000000');
        record_login_attempt($pdo, $phone, false);
        auth_event($pdo, null, 'login_fail', 'unknown employee phone: ' . mb_substr($phone, 0, 20));
        return 'bad';
    }

    if ($u['locked_until'] !== null && strtotime((string) $u['locked_until']) > time()) {
        record_login_attempt($pdo, $phone, false);
        auth_event($pdo, (int) $u['id'], 'locked', 'login attempt while locked');
        return 'locked';
    }

    // Suspended by an admin (Lock Employee) - a distinct case from a wrong
    // PIN. Checked BEFORE password_verify() touches failed_logins/lockout,
    // so a correctly-typed PIN on a locked account can never trip the
    // 5-attempt auto-lock or be shown as "wrong phone number or PIN".
    if ((int) $u['is_active'] !== 1) {
        record_login_attempt($pdo, $phone, false);
        auth_event($pdo, (int) $u['id'], 'login_fail', 'suspended account');
        return 'suspended';
    }

    if (!password_verify($pin, $u['secret_hash'])) {
        $fails = (int) $u['failed_logins'] + 1;
        // Employees get 12 tries before a 15-minute lock (a field employee
        // fat-fingering a 4-digit PIN a few times shouldn't get banned as
        // fast as an admin login would) - see EMPLOYEE_MAX_FAILED_LOGINS.
        $lock  = $fails >= EMPLOYEE_MAX_FAILED_LOGINS ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
        $pdo->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
            ->execute([$fails, $lock, $u['id']]);
        record_login_attempt($pdo, $phone, false);
        auth_event($pdo, (int) $u['id'], $lock ? 'locked' : 'login_fail', 'wrong PIN');
        return $lock ? 'locked' : 'bad';
    }

    // PIN correct - enforce the one-device rule (10).
    //
    // DEV_SKIP_DEVICE_LOCK (secure_config/secure_config.php, APP_ENV=development only):
    // while the field app is still being built, testing from several
    // browsers/phones for the same demo account keeps tripping rule 10 and
    // getting in the way, not catching anything real. Same hard-gate
    // pattern as DEV_AUTOLOGIN - define it locally, remove it before any
    // real deployment, and it can never fire outside development even if
    // left in by mistake.
    $skipDeviceLock = APP_ENV === 'development' && defined('DEV_SKIP_DEVICE_LOCK') && DEV_SKIP_DEVICE_LOCK;

    if (!$skipDeviceLock && $u['device_id'] !== null && $u['device_id'] !== $deviceId) {
        record_login_attempt($pdo, $phone, false);
        auth_event($pdo, (int) $u['id'], 'device_rejected', 'login from an unbound device');
        return 'device';
    }

    if (!$skipDeviceLock && $u['device_id'] === null) {
        // First login ever (or since a reset) - bind this device now.
        $pdo->prepare('UPDATE users SET device_id = ?, device_bound_at = NOW() WHERE id = ?')
            ->execute([$deviceId, $u['id']]);
        $pdo->prepare(
            "INSERT INTO field_devices (user_id, device_id, user_agent, status)
             VALUES (?, ?, ?, 'active')
             ON DUPLICATE KEY UPDATE last_seen = NOW(), status = 'active'"
        )->execute([$u['id'], $deviceId, client_ua()]);
        auth_event($pdo, (int) $u['id'], 'device_bound', mb_substr($deviceId, 0, 60));
    } else {
        // Same device as before - just bump last_seen.
        $pdo->prepare(
            "UPDATE field_devices SET last_seen = NOW() WHERE user_id = ? AND device_id = ?"
        )->execute([$u['id'], $deviceId]);
    }

    // Success.
    $pdo->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')
        ->execute([$u['id']]);
    record_login_attempt($pdo, $phone, true);
    auth_event($pdo, (int) $u['id'], 'login_ok', null);

    unset($u['secret_hash']);
    return $u;
}
