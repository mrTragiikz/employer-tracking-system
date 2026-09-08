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

/**
 * Put the authenticated user into the session and rotate the session id.
 *
 * $loginAt lets the field remember-me flow rebuild a session while keeping
 * login_at anchored to the ORIGINAL login (the token's issued_at), not "now".
 * That matters because require_employee()'s security_stamp_at check compares
 * against login_at: if every remember-token rebuild reset it to the current
 * second, an admin action landing in that same second would be missed. A
 * normal login passes null -> now.
 */
function login_session(array $user, ?int $loginAt = null): void
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
    $_SESSION['login_at'] = $loginAt ?? time();
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

// ===========================================================================
// Field app "stay logged in" (remember-me).
//
// Only the field app uses this. A successful employee login calls
// issue_remember_token(); it drops a long-lived HttpOnly cookie
// (FIELD_REMEMBER_COOKIE) whose value is "<rowId>:<rawToken>". Only the
// SHA-256 of the raw token is stored (field_remember_tokens.token_hash).
//
// On a later request where the PHP session is gone but the cookie is present,
// require_employee() calls consume_remember_token(): it looks the row up by
// id, constant-time-compares the hash, loads the user, and REJECTS if the
// account is inactive/deleted or if users.security_stamp_at is at/after the
// token's issued_at (i.e. an admin did Lock / Reset PIN / Reset Device after
// this device chain first logged in). On success the caller rebuilds the
// session and the token is rotated (old row deleted, fresh row + cookie
// issued, issued_at carried forward) so a leaked cookie is good for at most
// one request.
//
// The whole table is disposable: truncating it just asks every field user to
// sign in again. Nothing else depends on it.
// ===========================================================================

/**
 * One source of truth for the remember cookie's attributes.
 * secure + httponly + SameSite=Lax, path '/', lifetime FIELD_REMEMBER_TTL.
 */
function field_remember_cookie_options(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'domain'   => '',
        'secure'   => defined('COOKIE_SECURE') ? (bool) COOKIE_SECURE : false,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Set (or refresh) the remember cookie to "<rowId>:<rawToken>". */
function field_remember_cookie_set(int $rowId, string $rawToken): void
{
    $ttl = defined('FIELD_REMEMBER_TTL') ? (int) FIELD_REMEMBER_TTL : 60 * 60 * 24 * 400;
    $value = $rowId . ':' . $rawToken;
    setcookie(FIELD_REMEMBER_COOKIE, $value, field_remember_cookie_options(time() + $ttl));
    $_COOKIE[FIELD_REMEMBER_COOKIE] = $value; // visible to the rest of this request
}

/** Clear the remember cookie from the browser. */
function field_remember_cookie_clear(): void
{
    if (!defined('FIELD_REMEMBER_COOKIE')) {
        return;
    }
    setcookie(FIELD_REMEMBER_COOKIE, '', field_remember_cookie_options(time() - 42000));
    unset($_COOKIE[FIELD_REMEMBER_COOKIE]);
}

// How many remember tokens one employee may hold at once. Normal use is one
// (the rotation on every request keeps it at one). Extra rows accumulate only
// when a rotation's Set-Cookie never reaches the client (browser cache wiped
// mid-flight, a crash between the response and the next request) - those rows
// are dead weight, nobody holds their raw token. Prune to the newest few on
// every issue so the table can't grow without bound for a churny device.
const FIELD_REMEMBER_MAX_PER_USER = 5;

/**
 * Issue a fresh remember token for an employee and drop the cookie.
 * Best-effort: a DB error here must never break the login itself, so the
 * caller does not depend on the return value.
 *
 * $issuedAt: NULL for a real phone+PIN login (the chain starts now). On a
 * rotation, consume_remember_token() passes the ORIGINAL issued_at forward
 * unchanged, so the security_stamp_at check always compares against a stable,
 * login-time anchor - not a value that moves on every request.
 *
 * Also prunes this user's stale token rows: anything past its cookie lifetime
 * (a genuinely expired token) and anything beyond the newest
 * FIELD_REMEMBER_MAX_PER_USER.
 */
function issue_remember_token(PDO $pdo, int $userId, ?string $deviceId, ?string $issuedAt = null): void
{
    if (!defined('FIELD_REMEMBER_COOKIE')) {
        return; // feature not configured - behave exactly as before
    }
    try {
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        if ($issuedAt !== null && $issuedAt !== '') {
            $pdo->prepare(
                'INSERT INTO field_remember_tokens (user_id, token_hash, device_id, user_agent, issued_at, last_used_at)
                 VALUES (?, ?, ?, ?, ?, NOW())'
            )->execute([$userId, $hash, $deviceId !== '' ? $deviceId : null, client_ua(), $issuedAt]);
        } else {
            $pdo->prepare(
                'INSERT INTO field_remember_tokens (user_id, token_hash, device_id, user_agent, issued_at, last_used_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())'
            )->execute([$userId, $hash, $deviceId !== '' ? $deviceId : null, client_ua()]);
        }
        field_remember_cookie_set((int) $pdo->lastInsertId(), $raw);

        // --- prune this user's stale rows (best-effort) --------------------
        $ttlDays = (int) ceil(
            (defined('FIELD_REMEMBER_TTL') ? (int) FIELD_REMEMBER_TTL : 60 * 60 * 24 * 400) / 86400
        );
        // 1. genuinely expired: the device chain first logged in longer ago
        //    than the cookie could possibly still live (issued_at is the chain
        //    start, carried through rotations; created_at moves each rotation).
        $pdo->prepare(
            'DELETE FROM field_remember_tokens
              WHERE user_id = ? AND issued_at < (NOW() - INTERVAL ? DAY)'
        )->execute([$userId, $ttlDays]);

        // 2. keep only the newest FIELD_REMEMBER_MAX_PER_USER rows.
        //    (subquery wrapper so MySQL allows deleting from the same table)
        $keep = FIELD_REMEMBER_MAX_PER_USER;
        $pdo->prepare(
            "DELETE FROM field_remember_tokens
              WHERE user_id = ?
                AND id NOT IN (
                    SELECT id FROM (
                        SELECT id FROM field_remember_tokens
                         WHERE user_id = ?
                         ORDER BY id DESC
                         LIMIT $keep
                    ) AS keeprows
                )"
        )->execute([$userId, $userId]);
    } catch (Throwable $e) {
        // Table missing / DB hiccup - the user is still logged in via the
        // normal session; they just won't get the long-lived cookie.
    }
}

/**
 * Parse the remember cookie, verify the token, and return the user row it
 * authenticates - or null (also clearing the bad cookie) if anything is off.
 *
 * On success the matched token row is DELETED and a fresh one issued (with a
 * new cookie, carrying issued_at forward) - rotation, so a stolen cookie
 * survives one request at most. The returned array carries a private
 * '_login_at' key (the token's issued_at as a unix ts) for the caller to
 * anchor the rebuilt session's login_at; strip it before storing.
 */
function consume_remember_token(PDO $pdo): ?array
{
    if (!defined('FIELD_REMEMBER_COOKIE') || empty($_COOKIE[FIELD_REMEMBER_COOKIE])) {
        return null;
    }

    $cookie = (string) $_COOKIE[FIELD_REMEMBER_COOKIE];
    $parts  = explode(':', $cookie, 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_xdigit($parts[1])) {
        field_remember_cookie_clear();
        return null;
    }
    [$rowId, $raw] = [(int) $parts[0], $parts[1]];

    try {
        $st = $pdo->prepare(
            'SELECT t.id, t.user_id, t.token_hash, t.device_id, t.issued_at,
                    u.role, u.name, u.email, u.phone, u.is_active, u.deleted_at,
                    u.security_stamp_at, u.is_super_admin
               FROM field_remember_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.id = ?
              LIMIT 1'
        );
        $st->execute([$rowId]);
        $row = $st->fetch();

        // Unknown row, or token mismatch (constant-time) - reject.
        if (!$row || !hash_equals((string) $row['token_hash'], hash('sha256', $raw))) {
            field_remember_cookie_clear();
            return null;
        }

        // The account must still be a live employee.
        if ($row['role'] !== 'employee'
            || (int) $row['is_active'] !== 1
            || $row['deleted_at'] !== null
        ) {
            $pdo->prepare('DELETE FROM field_remember_tokens WHERE id = ?')->execute([$rowId]);
            field_remember_cookie_clear();
            return null;
        }

        // An admin Lock / Reset PIN / Reset Device bumps security_stamp_at -
        // the same gate require_employee() applies to a live session. A
        // "remembered" session must not slip past it. Anchored to issued_at
        // (the ORIGINAL login of this device chain, carried forward unchanged
        // on every rotation) so the comparison is stable, exactly like a live
        // session's login_at. require_employee() then re-runs this same check
        // against the rebuilt session - belt and braces.
        if ($row['security_stamp_at'] !== null
            && strtotime((string) $row['security_stamp_at']) >= strtotime((string) $row['issued_at'])
        ) {
            $pdo->prepare('DELETE FROM field_remember_tokens WHERE id = ?')->execute([$rowId]);
            field_remember_cookie_clear();
            return null;
        }

        // Good. Rotate: delete this row, issue a fresh token + cookie -
        // carrying the ORIGINAL issued_at forward so the check above (and the
        // rebuilt session's login_at) stay anchored to the real login.
        $pdo->prepare('DELETE FROM field_remember_tokens WHERE id = ?')->execute([$rowId]);
        issue_remember_token(
            $pdo, (int) $row['user_id'], $row['device_id'] ?: null, (string) $row['issued_at']
        );

        return [
            'id'             => (int) $row['user_id'],
            'role'           => 'employee',
            'name'           => $row['name'],
            'email'          => $row['email'] ?? null,
            'phone'          => $row['phone'] ?? null,
            'is_super_admin' => !empty($row['is_super_admin']),
            '_login_at'      => strtotime((string) $row['issued_at']) ?: time(),
        ];
    } catch (Throwable $e) {
        // DB problem - don't clear the cookie (the row may be fine next time),
        // just decline to auto-login this request.
        return null;
    }
}

/** Delete the token named by the current cookie and clear the cookie. Logout. */
function revoke_remember_token(PDO $pdo): void
{
    if (!defined('FIELD_REMEMBER_COOKIE') || empty($_COOKIE[FIELD_REMEMBER_COOKIE])) {
        field_remember_cookie_clear();
        return;
    }
    $parts = explode(':', (string) $_COOKIE[FIELD_REMEMBER_COOKIE], 2);
    if (count($parts) === 2 && ctype_digit($parts[0])) {
        try {
            $pdo->prepare('DELETE FROM field_remember_tokens WHERE id = ?')->execute([(int) $parts[0]]);
        } catch (Throwable $e) {
            // best effort
        }
    }
    field_remember_cookie_clear();
}

/** Drop every remember token for one user (admin Reset Device). */
function revoke_all_remember_tokens(PDO $pdo, int $userId): void
{
    try {
        $pdo->prepare('DELETE FROM field_remember_tokens WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        // best effort - security_stamp_at already invalidates them
    }
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
 * Stay-logged-in: if there is no live session but a valid remember-me cookie
 * (see consume_remember_token()), the session is rebuilt here before the
 * checks below run. This ONLY creates a session - it never bypasses a check:
 * every guard that follows still runs against the fresh DB row.
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

    // Stay-logged-in: no live session, but a remember-me cookie is present ->
    // verify the token and rebuild the session. consume_remember_token() has
    // already applied the account-active and security_stamp_at gates, but the
    // DB re-check below runs again anyway - belt and braces.
    if (!current_user()
        && defined('FIELD_REMEMBER_COOKIE')
        && !empty($_COOKIE[FIELD_REMEMBER_COOKIE])
    ) {
        $remembered = consume_remember_token($pdo);
        if ($remembered !== null) {
            // Anchor the rebuilt session's login_at to the token's original
            // issue time, not "now" - so the security_stamp_at check below
            // stays meaningful (a normal login passes null -> now).
            $loginAt = $remembered['_login_at'] ?? null;
            unset($remembered['_login_at']);
            login_session($remembered, $loginAt);
        }
    }

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
        revoke_remember_token($pdo); // kill the stay-logged-in cookie too
        logout_session();
        // Pass the phone so the suspended screen can poll status.php and
        // auto-reload the moment the admin unlocks the account, instead of
        // the employee sitting on a dead screen until they manually refresh.
        // A deleted account has no phone to poll with - fine, it just won't.
        $q = $phone !== '' ? '?suspended=1&p=' . rawurlencode($phone) : '?suspended=1';
        redirect(APP_URL . '/field/login/' . $q);
    }

    // >= not > : DATETIME is second-resolution, and the field remember-me flow
    // rebuilds the session (resetting login_at) on many requests - a strict >
    // would let an admin Lock / PIN reset / Device reset that lands in the same
    // second as a request slip through. The only cost of >= is that an admin
    // action in the exact second a worker logs in also ends that brand-new
    // session - vanishingly rare, and fail-safe (they just log in again).
    if ($state['security_stamp_at'] !== null
        && strtotime($state['security_stamp_at']) >= (int) ($_SESSION['login_at'] ?? 0)
    ) {
        revoke_remember_token($pdo); // kill the stay-logged-in cookie too
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
