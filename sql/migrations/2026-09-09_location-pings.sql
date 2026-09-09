-- 2026-09-09  Live location tracking (Android app only)
--
-- One row per GPS fix an APK worker's phone reports while they are checked in.
-- Purely a DISPLAY feed for the admin "Live Track" modal and the route maps -
-- NOTHING in the audit path (compute_day / distance.php) ever reads this table.
-- Drop the whole table and every KM / time figure in the system is unchanged.
--
-- Web / iOS field users never write here (a browser cannot track in the
-- background). The feature is gated behind the `live_tracking_enabled` setting.
--
-- Retention: cron/prune-location-pings.php deletes rows older than 90 days so
-- the table never grows without bound (12 workers ~= 85k rows / month).

CREATE TABLE IF NOT EXISTS location_pings (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    attendance_id INT UNSIGNED    NOT NULL,
    employee_id   INT UNSIGNED    NOT NULL,
    lat           DECIMAL(10,7)   NOT NULL,
    lng           DECIMAL(10,7)   NOT NULL,
    accuracy_m    DECIMAL(6,1)        NULL,
    speed_kmh     DECIMAL(5,1)        NULL,
    recorded_at   DATETIME        NOT NULL COMMENT 'phone clock - when the fix happened',
    received_at   DATETIME        NOT NULL COMMENT 'server clock - when we stored it',
    device_id     VARCHAR(64)         NULL,
    PRIMARY KEY (id),
    KEY ix_emp_time (employee_id, recorded_at),
    KEY ix_att (attendance_id),
    CONSTRAINT fk_ping_att FOREIGN KEY (attendance_id)
        REFERENCES attendance (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kill switch + tunable interval, both read via includes/settings.php.
-- Defaults keep the feature OFF until an admin turns it on in Settings.
INSERT INTO settings (key_name, value, value_type) VALUES
    ('live_tracking_enabled',    '0',  'bool'),
    ('live_tracking_interval_s', '90', 'int')
ON DUPLICATE KEY UPDATE key_name = key_name;
