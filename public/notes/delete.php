<?php

/**
 * ============================================================
 *  Notes — Delete
 * ------------------------------------------------------------
 *  POST-only, CSRF-protected, audited. The author or an
 *  administrator; note_may_edit() decides, not this file.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/notes.php';
require_login();
require_tables('notes');

if (!is_post()) {
    redirect('notes/index.php');
}
csrf_check();

$id = input_int($_POST, 'id');
if ($id === null) {
    flash('error', 'No note specified.');
    redirect('notes/index.php');
}

// Not visible and not there give the same answer, so the id is not
// a way to find out which notes exist.
$note = note_get($id);
if (!$note) {
    flash('error', 'That note no longer exists.');
    redirect('notes/index.php');
}
if (!note_may_edit($note)) {
    flash('error', 'That note belongs to somebody else.');
    redirect('notes/index.php');
}

try {
    db_run("DELETE FROM notes WHERE note_id = :id", [':id' => $id]);
    audit_log('note.delete', 'notes', $id, ['title' => $note['title']]);
    flash('success', 'Note deleted.');
} catch (Throwable $ex) {
    error_log('[NOTES] delete failed: ' . $ex->getMessage());
    flash('error', 'Could not delete the note.');
}

redirect('notes/index.php');
