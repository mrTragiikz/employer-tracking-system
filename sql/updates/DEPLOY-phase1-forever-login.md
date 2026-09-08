# Deploy: Phase 1 - Field app "stay logged in"

The field app (employee phone / installed APK) now stays signed in across app
closes, phone restarts and long idle gaps. It ends only on an explicit **Logout**
or an admin action (**Lock / Reset PIN / Reset Device**). The admin panel is
unchanged - it still expires after 8 hours.

## Deploy order (do these in sequence)

### 1. Database - run the update against the LIVE database

phpMyAdmin -> select `prabinsharma_track` -> **Import** ->
`sql/updates/2026-09-08_field-remember-tokens.sql`

Adds one new empty table (`field_remember_tokens`). Touches nothing else. No
downtime - the site can stay up during the import. Safe to re-run.

### 2. secure_config.php - add four constants on the live server

`secure_config/secure_config.php` is git-ignored, so these do NOT arrive via a
code upload. Add them by hand, in the shared "Security" section (near
`SESSION_LIFETIME`):

```php
define('FIELD_REMEMBER_COOKIE',   'trackfield_r');
define('FIELD_REMEMBER_TTL',      60 * 60 * 24 * 400); // ~13 months
define('FIELD_SESSION_LIFETIME',  60 * 60 * 24 * 30);  // 30 days
define('FIELD_CSRF_TOKEN_TTL',    60 * 60 * 24 * 30);  // 30 days
```

If these constants are absent the code silently behaves exactly as before
(no remember-me) - so a momentary gap between the code upload and adding them
is harmless.

### 3. Upload the changed code

- `includes/auth.php`
- `includes/bootstrap.php`
- `includes/csrf.php`
- `field/login/api/authenticate.php`
- `field/logout/api/logout.php`
- `admin/02-employees/api/reset-device.php`
- `sql/database.sql`   (fresh-install schema, kept in sync; not executed on an existing DB)

## Verify after deploy

1. On a phone: sign in to `/field/`. Close the browser/app completely. Reopen
   `/field/` -> should land on the home screen, still signed in.
2. Profile -> Log Out -> reopen `/field/` -> should show the login screen.
3. Admin: Lock that employee -> their next field page shows the "suspended"
   screen. Unlock -> they must sign in again.

## Rollback

- Remove the four `FIELD_*` constants from `secure_config.php` -> the feature
  is off, field sessions revert to `SESSION_LIFETIME`.
- `DROP TABLE field_remember_tokens;` (optional) - every field user just signs
  in again on their next visit; nothing else uses the table.
