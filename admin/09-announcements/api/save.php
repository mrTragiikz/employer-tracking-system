<?php
/**
 * admin/09-announcements/api/save.php - create or update an announcement.
 *
 * POST (form-encoded, from form.php):
 *   id?        int     present => update, absent => create
 *   title      string  3..120
 *   body       string  3..2000
 *   audience   "all" | "selected"
 *   targets[]  int      employee ids (only used when audience = "selected")
 *
 * Every announcement goes LIVE on save (no drafts). On success => redirect to
 * the list with ?ok=posted|updated. On error => stash errors + old input in
 * the session, redirect back to the form.
 *
 * Super Admin only. CSRF enforced by includes/api.php.
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php'; // bootstrap + auth gate + CSRF
$me = require_admin();
$me = require_super_admin($me);
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$editingId = isset($_POST['id']) && $_POST['id'] !== '' ? (int) $_POST['id'] : null;
$formUrl   = APP_URL . '/admin/09-announcements/form.php' . ($editingId !== null ? '?id=' . $editingId : '');
$listUrl   = APP_URL . '/admin/09-announcements/';

if ($editingId !== null && announcement_find($pdo, $editingId) === null) {
    $_SESSION['announcement_form_error'] = ['_' => 'That announcement no longer exists.'];
    redirect($listUrl);
}

[$errors, $clean] = announcement_validate($pdo, $_POST);

if ($errors) {
    $_SESSION['announcement_form_error'] = $errors;
    $_SESSION['announcement_form_old']   = [
        'title'    => $clean['title'],
        'body'     => $clean['body'],
        'audience' => $clean['audience'],
        // keep whatever the admin had ticked, verbatim, so the picker
        // re-renders with their selection (not the filtered list)
        'targets'  => array_map('intval', (array) ($_POST['targets'] ?? [])),
    ];
    redirect($formUrl);
}

try {
    announcement_save(
        $pdo, (int) $me['id'], $editingId,
        $clean['title'], $clean['body'],
        $clean['audience'], $clean['targets']
    );
} catch (Throwable $e) {
    redirect($listUrl . '?err=save');
}

redirect($listUrl . '?ok=' . ($editingId === null ? 'posted' : 'updated'));
