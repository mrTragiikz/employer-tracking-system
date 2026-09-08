<?php
/**
 * admin/02-employees/api/save-notes.php  -  save the admin notes on an Employee.
 *
 * POST: id, notes (free text, up to ~2000 chars)
 */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/api.php';
$me = require_admin();
$me = require_super_admin($me); // editing Employee notes is Super Admin only (part of "manage employee information")
api_method('POST');
require dirname(__DIR__) . '/_repo.php';

$id    = (int) ($_POST['id'] ?? 0);
$notes = trim((string) ($_POST['notes'] ?? ''));
$notes = mb_substr($notes, 0, 2000);

$employee = $id ? employee_find($pdo, $id) : null;
if (!$employee) {
    redirect(APP_URL . '/admin/02-employees/');
}

$pdo->beginTransaction();
try {
    $pdo->prepare("UPDATE users SET notes = ? WHERE id = ? AND role = 'employee'")
        ->execute([$notes !== '' ? $notes : null, $id]);

    employee_audit($pdo, $me['id'], 'Employee.notes', $id,
        ['notes' => $employee['notes']], ['notes' => $notes]);

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&err=notes');
}

redirect(APP_URL . '/admin/02-employees/view.php?id=' . $id . '&ok=notes');
