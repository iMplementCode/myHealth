<?php

/**
 * ============================================================
 *  Notes — Save (AJAX endpoint)
 * ------------------------------------------------------------
 *  Creates or updates a note. POST-only, CSRF-protected,
 *  validated server-side, audited. Returns JSON consumed by the
 *  shared data-ajax form handler.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/notes.php';
require_login();
require_tables('notes');

if (!is_post()) {
    json_response(['success' => false, 'message' => 'Invalid request method.'], 405);
}
if (!csrf_verify($_POST['csrf_token'] ?? null)) {
    json_response(['success' => false, 'message' => 'Security token expired. Reload the page and try again.'], 403);
}

$id         = input_int($_POST, 'note_id');
$title      = trim(input($_POST, 'title'));
$body       = trim((string) ($_POST['body'] ?? ''));
$category   = input($_POST, 'category');
$visibility = input($_POST, 'visibility');
// 't' / 'f' rather than a PHP bool: PDO sends false to Postgres as
// an empty string, which is not a boolean, and the insert fails.
// This is the same idiom the product form uses.
$pinned     = !empty($_POST['is_pinned']) ? 't' : 'f';

// ── Validation ──────────────────────────────────────────────
$errors = [];
if ($title === '') {
    $errors[] = 'A note needs a title, or nobody will find it again.';
} elseif (mb_strlen($title) > 200) {
    $errors[] = 'The title is longer than 200 characters.';
}
if ($body === '') {
    $errors[] = 'The note itself is empty.';
}
if ($category === '' || !note_category_valid($category)) {
    $category = 'general';
}
if (!in_array($visibility, ['private', 'shared'], true)) {
    $visibility = 'private';
}
if ($errors) {
    json_response(['success' => false, 'message' => implode(' ', $errors)]);
}

$me = (int) (current_user()['id'] ?? 0);

try {
    if ($id === null) {
        $newId = db_value(
            "INSERT INTO notes (title, body, category, visibility, is_pinned, created_by)
             VALUES (:t, :b, :c, :v, :p, :me) RETURNING note_id",
            [':t' => $title, ':b' => $body, ':c' => $category,
             ':v' => $visibility, ':p' => $pinned, ':me' => $me]
        );
        audit_log('note.create', 'notes', (int) $newId, ['title' => $title, 'visibility' => $visibility]);
        json_response(['success' => true, 'message' => 'Note saved.']);
    }

    /*  An edit has to prove two things, and they are different
     *  questions: that the note is one this user may SEE, and that
     *  it is one they may CHANGE. note_get() answers the first —
     *  returning null for "not yours" as well as "not there", so a
     *  stranger cannot count through the ids and learn which exist.
     *  note_may_edit() answers the second. */
    $existing = note_get($id);
    if (!$existing) {
        json_response(['success' => false, 'message' => 'That note no longer exists.'], 404);
    }
    if (!note_may_edit($existing)) {
        json_response(['success' => false, 'message' => 'That note belongs to somebody else.'], 403);
    }

    db_run(
        "UPDATE notes SET title = :t, body = :b, category = :c, visibility = :v,
                is_pinned = :p, updated_at = NOW()
          WHERE note_id = :id",
        [':t' => $title, ':b' => $body, ':c' => $category,
         ':v' => $visibility, ':p' => $pinned, ':id' => $id]
    );
    audit_log('note.update', 'notes', $id, ['title' => $title, 'visibility' => $visibility]);
    json_response(['success' => true, 'message' => 'Note updated.']);
} catch (Throwable $ex) {
    error_log('[NOTES] save failed: ' . $ex->getMessage());
    json_response(['success' => false, 'message' => 'Could not save the note. Please try again.'], 500);
}
