# sql/updates/

One-off SQL changes to apply to an **already-populated** database (dev or live).

`../database.sql` is the full schema and `DROP`s every table — it can only be
used for a **fresh** install. Once a database has real data in it, schema
changes go here instead, one file per change, applied in date order.

## Naming

```
YYYY-MM-DD_short-description.sql
```

## How to run

**Local XAMPP**

```
D:\xampp\mysql\bin\mysql -u root trying < sql\updates\2026-09-08_field-remember-tokens.sql
```

**cPanel / shared hosting**

phpMyAdmin → select the database → **Import** → choose the file.

## Rules for each file

- Idempotent where possible (`CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS`
  on MariaDB 10.5+, or guarded with a check) so a re-run is harmless.
- A comment block at the top saying what it does, how to run it, and how to undo it.
- Never `DROP` or `TRUNCATE` an existing data table.
- After adding a file here, also fold the same change into `../database.sql`
  so a fresh install still gets it.

## Applied

| Date | File | What |
|------|------|------|
| 2026-09-08 | `2026-09-08_field-remember-tokens.sql` | `field_remember_tokens` table — field-app "stay logged in" (Phase 1) |
| 2026-09-08 | `2026-09-08_left-job.sql` | `users.left_job_at` — employee "Left the Job" / Former Employees |
