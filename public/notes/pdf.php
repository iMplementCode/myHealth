<?php

/**
 * ============================================================
 *  Notes — Print
 * ------------------------------------------------------------
 *  The note on the company letterhead, logo and all. Signed-in
 *  users only, and only notes they may see: note_get() answers
 *  both questions, so this endpoint cannot become a way to read
 *  somebody else's private note by guessing an id.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/notes.php';
require_once __DIR__ . '/../../includes/company.php';
require_once __DIR__ . '/../../includes/document_pdf.php';
require_login();
require_tables('notes');

$id   = input_int($_GET, 'id');
$note = $id !== null ? note_get($id) : null;
if (!$note) {
    flash('error', 'That note could not be found.');
    redirect('notes/index.php');
}

// Its own reference, derived from the row. A note has no number of
// the kind a document carries, but a printed page with nothing to
// quote back is a page nobody can refer to on the phone.
$reference = 'NOTE-' . str_pad((string) $note['note_id'], 5, '0', STR_PAD_LEFT);

$company = company_settings()['company_name'] ?? APP_NAME;

stream_note_pdf([
    'title'    => 'Note',
    'heading'  => $note['title'],
    'filename' => pdf_document_filename('Note', $reference),
    'download' => !empty($_GET['dl']),

    'meta' => [
        ['Reference', $reference],
        ['Date',      fmt_date($note['created_at'], 'd M Y')],
        ['Category',  note_category_label($note['category'])],
    ],

    'facts' => [
        ['Written by', $note['author_name'] ?: ''],
        // Only when it says something the created date does not.
        ['Last updated', $note['updated_at'] !== $note['created_at']
            ? fmt_date($note['updated_at'], 'd M Y H:i') : ''],
    ],

    'body' => $note['body'],

    'footer' => 'Internal note recorded by ' . $company . ' on '
        . fmt_date($note['created_at'], 'd M Y') . '.',
]);
