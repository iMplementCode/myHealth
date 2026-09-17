<?php

/**
 * ============================================================
 *  Notes — Read one
 * ------------------------------------------------------------
 *  The note as written, with the buttons that act on it. What
 *  may be seen and what may be changed are both asked of
 *  includes/notes.php.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/notes.php';
require_login();
require_tables('notes');

$id   = input_int($_GET, 'id');
$note = $id !== null ? note_get($id) : null;
if (!$note) {
    flash('error', 'That note could not be found.');
    redirect('notes/index.php');
}

$canEdit = note_may_edit($note);

$pageTitle    = $note['title'];
$pageSubtitle = note_category_label($note['category'])
              . ' · ' . ($note['visibility'] === 'shared' ? 'shared with the team' : 'private');
$pageStyles   = ['forms.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [
    ['label' => 'Notes', 'href' => 'notes/index.php'],
    ['label' => $note['title']],
];

require __DIR__ . '/../../includes/header.php';

$prefill = json_encode([
    'note_id'    => $note['note_id'],
    'title'      => $note['title'],
    'body'       => $note['body'],
    'category'   => $note['category'],
    'visibility' => $note['visibility'],
    'is_pinned'  => $note['is_pinned'] ? '1' : '',
], JSON_HEX_APOS | JSON_HEX_QUOT);
?>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">
            <?php if ($note['is_pinned']): ?><span class="note-pin" title="Pinned">●</span> <?php endif; ?>
            <?= e($note['title']) ?>
        </h2>
        <div class="row-actions">
            <a class="btn btn-ghost btn--sm" target="_blank" rel="noopener"
               href="<?= e(url('notes/pdf.php?id=' . (int) $note['note_id'])) ?>"><?= icon('file') ?> Print</a>
            <a class="btn btn-ghost btn--sm"
               href="<?= e(url('notes/pdf.php?id=' . (int) $note['note_id'] . '&dl=1')) ?>"><?= icon('download') ?> Download</a>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-primary btn--sm"
                        data-modal-open="tplNoteForm" data-modal-title="Edit note"
                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?> Edit</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="u-pad">
        <div class="note-facts">
            <span><strong>Written by</strong> <?= e($note['author_name'] ?: '—') ?></span>
            <span><strong>Created</strong> <?= e(fmt_date($note['created_at'], 'd M Y H:i')) ?></span>
            <?php if ($note['updated_at'] !== $note['created_at']): ?>
                <span><strong>Updated</strong> <?= e(fmt_date($note['updated_at'], 'd M Y H:i')) ?></span>
            <?php endif; ?>
        </div>

        <!-- The note as typed. nl2br over an escaped body keeps the
             line breaks without letting the note style the page. -->
        <div class="note-body"><?= nl2br(e($note['body'])) ?></div>
    </div>
</div>

<?php if ($canEdit): ?>
<template id="tplNoteForm">
    <form method="POST" action="<?= e(url('notes/save.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="note_id" value="">

        <div class="form-group">
            <label class="form-label">Title <span class="req">*</span></label>
            <input type="text" name="title" class="form-control" required maxlength="200">
        </div>

        <div class="form-group">
            <label class="form-label">Note <span class="req">*</span></label>
            <textarea name="body" class="form-control" rows="12" required></textarea>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-control">
                    <?php foreach (note_categories() as $slug => $label): ?>
                        <option value="<?= e($slug) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Who can see it</label>
                <select name="visibility" class="form-control">
                    <option value="private">Private — me and administrators</option>
                    <option value="shared">Shared — anyone signed in</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="checkbox"><input type="checkbox" name="is_pinned" value="1"> <span>Pin to the top of the list</span></label>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save note</button>
        </div>
    </form>
</template>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
