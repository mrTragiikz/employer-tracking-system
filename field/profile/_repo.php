<?php
/**
 * field/profile/_repo.php
 *
 * Data access for the Employee's own profile page (bio data + change-PIN).
 * Pure functions over $pdo, scoped to "myself" - no output. Self-contained
 * by design, no requiring admin files.
 */

declare(strict_types=1);

/** The logged-in employee's full profile row, or null if somehow missing. */
function profile_find(PDO $pdo, int $userId): ?array
{
    $st = $pdo->prepare(
        "SELECT id, name, phone, email, code, area, region, dob, gender,
                id_number, document_type, id_photo_front, id_photo_back, emergency_name,
                emergency_contact, address, photo_path, vehicle_type,
                device_id, device_bound_at, is_active, last_login_at, created_at
           FROM users
          WHERE id = ? AND role = 'employee'
          LIMIT 1"
    );
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Human label for a users.document_type value, or a generic fallback. */
function profile_document_label(?string $type): string
{
    return match ($type) {
        'citizenship'      => 'Nagrita (Citizenship)',
        'driving_license'  => 'Driving License',
        'passport'         => 'Passport',
        'national_id'      => 'NID Card',
        default            => 'ID Document',
    };
}

/**
 * Validate a change-PIN request: current PIN must verify against the
 * stored hash, the two new-PIN entries must match, and the new PIN must be
 * exactly 4 digits (same shape as login) and different from the current one.
 *
 * @return array{ok:bool, errors:array<string,string>}
 */
function profile_pin_validate(string $currentHash, string $currentPin, string $newPin, string $newPinConfirm): array
{
    $errors = [];

    if ($currentPin === '' || !password_verify($currentPin, $currentHash)) {
        $errors['current_pin'] = 'Your current PIN is incorrect.';
    }

    if (!preg_match('/^\d{4}$/', $newPin)) {
        $errors['new_pin'] = 'New PIN must be exactly 4 digits.';
    } elseif ($newPin !== $newPinConfirm) {
        $errors['new_pin_confirm'] = 'The two PINs do not match.';
    } elseif ($currentPin !== '' && $newPin === $currentPin) {
        $errors['new_pin'] = 'New PIN must be different from your current PIN.';
    }

    return ['ok' => empty($errors), 'errors' => $errors];
}
