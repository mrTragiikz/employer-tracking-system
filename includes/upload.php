<?php
/**
 * Secure photo upload (Anti-Fraud rule 3 + Security requirements).
 *
 * - real MIME verified with finfo (not the client-declared type)
 * - file renamed to a random name, extension derived from verified MIME
 * - stored under uploads/YYYY/MM/
 * - size enforced
 * - PHP execution in uploads/ is blocked by uploads/.htaccess
 *
 * The "live camera only" requirement is enforced on the client with
 * <input type="file" accept="image/*" capture="environment">
 * and there is no reliable server signal for "camera vs gallery"; we record
 * captured_via='camera' by contract and rely on the capture attribute + the
 * GPS/accuracy fraud checks to catch stale gallery images.
 */

declare(strict_types=1);

/** Max size for an employee profile photo (admin upload, not a live capture). */
if (!defined('EMPLOYEE_PHOTO_MAX_BYTES')) {
    define('EMPLOYEE_PHOTO_MAX_BYTES', 200 * 1024); // 200 KB
}

/**
 * Verified image MIME for an uploaded temp file, or '' if it can't be
 * determined. Prefers the fileinfo extension; on shared hosts where that
 * extension is disabled, falls back to getimagesize() (a core function, no
 * extension needed) so an upload NEVER hard-fails with "Class finfo not found".
 */
function verified_image_mime(string $tmpPath): string
{
    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $m  = $fi->file($tmpPath);
        if (is_string($m) && $m !== '') {
            return $m;
        }
    }
    if (function_exists('mime_content_type')) {
        $m = @mime_content_type($tmpPath);
        if (is_string($m) && $m !== '') {
            return $m;
        }
    }
    $info = @getimagesize($tmpPath);
    if ($info !== false && !empty($info['mime'])) {
        return (string) $info['mime'];
    }
    return '';
}

/**
 * Store an admin-uploaded employee/admin photo (profile photo, ID card front/back).
 * Same pipeline as visit photos (verified MIME, random name, uploads/YYYY/MM/)
 * but capped at 200 KB.
 *
 * @param array  $file    one entry from $_FILES (may be an empty/no-file entry)
 * @param string $prefix  filename prefix, e.g. 'employee' or 'employee_id'
 * @return array{ok:bool, skipped?:bool, error?:string, stored_path?:string,
 *               mime?:string, bytes?:int, width?:?int, height?:?int}
 */
function save_employee_photo(array $file, string $prefix = 'employee'): array
{
    // No file chosen is fine - the field is optional.
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'skipped' => true];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The photo upload failed. Try again.'];
    }

    $bytes = (int) ($file['size'] ?? 0);
    if ($bytes <= 0) {
        return ['ok' => false, 'error' => 'The photo is empty.'];
    }
    if ($bytes > EMPLOYEE_PHOTO_MAX_BYTES) {
        return ['ok' => false, 'error' => 'Photo must be 200 KB or smaller (this one is '
            . number_format($bytes / 1024, 0) . ' KB).'];
    }

    $mime  = verified_image_mime($file['tmp_name']) ?: 'application/octet-stream';
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Only JPEG, PNG or WebP images are accepted.'];
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['ok' => false, 'error' => 'That file is not a valid image.'];
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'bin',
    };

    $subDir = date('Y/m');
    $absDir = rtrim(UPLOAD_DIR, '/\\') . '/' . $subDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
        return ['ok' => false, 'error' => 'Cannot create the upload directory.'];
    }

    $slug    = preg_replace('/[^a-z0-9_]/', '', strtolower($prefix)) ?: 'employee';
    $name    = $slug . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $absPath = $absDir . '/' . $name;
    $relPath = $subDir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        return ['ok' => false, 'error' => 'Could not store the photo.'];
    }
    @chmod($absPath, 0644);

    return [
        'ok'          => true,
        'stored_path' => $relPath,
        'mime'        => $mime,
        'bytes'       => $bytes,
        'width'       => $info[0] ?? null,
        'height'      => $info[1] ?? null,
    ];
}

/** Delete a previously stored upload (relative path). Best-effort. */
function delete_upload(?string $relPath): void
{
    if (!$relPath) return;
    $relPath = ltrim(str_replace('\\', '/', $relPath), '/');
    if (str_contains($relPath, '..')) return;
    $abs = rtrim(UPLOAD_DIR, '/\\') . '/' . $relPath;
    if (is_file($abs)) @unlink($abs);
}

/**
 * @param array $file one entry from $_FILES
 * @return array{ok:bool, error?:string, stored_path?:string, mime?:string, bytes?:int, sha256?:string}
 */
function save_camera_photo(array $file, int $visitId): array
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'No file received or upload failed.'];
    }

    $bytes = (int) ($file['size'] ?? 0);
    if ($bytes <= 0 || $bytes > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'error' => 'File too large or empty.'];
    }

    // Verified MIME - ignore $file['type'].
    $mime = verified_image_mime($file['tmp_name']) ?: 'application/octet-stream';

    $allowed = explode(',', UPLOAD_ALLOWED_MIME);
    if (!in_array($mime, $allowed, true)) {
        return ['ok' => false, 'error' => 'Only JPEG, PNG or WebP images are accepted.'];
    }

    // Confirm it really decodes as an image and grab dimensions.
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return ['ok' => false, 'error' => 'File is not a valid image.'];
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => 'bin',
    };

    $subDir = date('Y/m');
    $absDir = rtrim(UPLOAD_DIR, '/\\') . '/' . $subDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
        return ['ok' => false, 'error' => 'Cannot create upload directory.'];
    }

    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $absPath = $absDir . '/' . $name;
    $relPath = $subDir . '/' . $name;

    if (!move_uploaded_file($file['tmp_name'], $absPath)) {
        return ['ok' => false, 'error' => 'Could not store the file.'];
    }
    @chmod($absPath, 0644);

    return [
        'ok' => true,
        'stored_path' => $relPath,
        'mime' => $mime,
        'bytes' => $bytes,
        'width' => $info[0] ?? null,
        'height' => $info[1] ?? null,
        'sha256' => hash_file('sha256', $absPath),
    ];
}
