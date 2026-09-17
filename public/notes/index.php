<?php

/**
 * ============================================================
 *  Notes — List
 * ------------------------------------------------------------
 *  Searchable, filterable, paginated list of notes with a
 *  modal add/edit form. Any signed-in user may keep notes;
 *  what they can see is decided by note_visible_sql(), not
 *  here.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/notes.php';
require_once __DIR__ . '/../../includes/export.php';
require_login();
require_tables('notes');

$categories = note_categories();

// ── Filters ─────────────────────────────────────────────────
$search   = input($_GET, 'q');
$category = input($_GET, 'category');
$mine     = input($_GET, 'mine') === '1';
$perPage  = per_page();

[$visibleSql, $params] = note_visible_sql('n');
$where = [$visibleSql];

if ($search !== '') {
    $where[] = "(LOWER(n.title) LIKE :q OR LOWER(n.body) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($category !== '' && note_category_valid($category)) {
    $where[] = "n.category = :cat";
    $params[':cat'] = $category;
}
if ($mine) {
    $where[] = "n.created_by = :author";
    $params[':author'] = (int) (current_user()['id'] ?? 0);
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// Pinned first, then most recently touched — the same order the
// index is built for.
$orderSql = 'ORDER BY n.is_pinned DESC, n.updated_at DESC, n.note_id DESC';

// ── Export ──────────────────────────────────────────────────
// The same WHERE without the page limit: what comes down is what
// the filters describe, not the rows on screen.
if (wants_export()) {
    $rows = db_all(
        "SELECT n.title, n.category, n.visibility, n.is_pinned, n.body, n.created_at, n.updated_at,
                TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name
           FROM notes n LEFT JOIN users u ON u.user_id = n.created_by
           $whereSql $orderSql",
        $params
    );
    export_deliver(export_filename('notes'), [[
        'name'  => 'Notes',
        'title' => 'Notes',
        'meta'  => export_meta([
            'Category' => $category !== '' ? note_category_label($category) : 'all',
            'Scope'    => $mine ? 'only mine' : 'everything I can see',
            'Search'   => $search,
        ]),
        'columns' => [
            ['label' => 'Title',      'key' => 'title'],
            ['label' => 'Category',   'key' => 'category', 'value' => fn($r) => note_category_label($r['category'])],
            ['label' => 'Visibility', 'key' => 'visibility', 'value' => fn($r) => ucfirst((string) $r['visibility'])],
            ['label' => 'Pinned',     'key' => 'is_pinned', 'value' => fn($r) => $r['is_pinned'] ? 'Yes' : ''],
            ['label' => 'Note',       'key' => 'body'],
            ['label' => 'Written by', 'key' => 'author_name'],
            ['label' => 'Created',    'key' => 'created_at', 'type' => 'date'],
            ['label' => 'Updated',    'key' => 'updated_at', 'type' => 'date'],
        ],
        'rows' => $rows,
    ]]);
}

// ── Pagination + data ───────────────────────────────────────
$total = (int) db_value("SELECT COUNT(*) FROM notes n $whereSql", $params);
$pg    = paginate($total, $perPage);

$notes = db_all(
    "SELECT n.*, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name
       FROM notes n LEFT JOIN users u ON u.user_id = n.created_by
       $whereSql $orderSql
       LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// ── Summary ─────────────────────────────────────────────────
[$vSql, $vParams] = note_visible_sql('n');
$myCount     = (int) db_value("SELECT COUNT(*) FROM notes n WHERE n.created_by = :me",
                              [':me' => (int) (current_user()['id'] ?? 0)]);
$pinnedCount = (int) db_value("SELECT COUNT(*) FROM notes n WHERE $vSql AND n.is_pinned", $vParams);
$sharedCount = (int) db_value("SELECT COUNT(*) FROM notes n WHERE $vSql AND n.visibility = 'shared'", $vParams);

$pageTitle    = 'Notes';
$pageSubtitle = 'Write things down and keep them where the business can find them';
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Notes']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter([
    'q' => $search, 'category' => $category, 'mine' => $mine ? '1' : null,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Notes I can see</span><span class="mini-value"><?= e(num($total)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Written by me</span><span class="mini-value"><?= e(num($myCount)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Pinned</span><span class="mini-value"><?= e(num($pinnedCount)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Shared with the team</span><span class="mini-value"><?= e(num($sharedCount)) ?></span></div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('notes/index.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Search titles and note text…">
        <select name="category" class="form-control toolbar-select" data-autosubmit>
            <option value="">All categories</option>
            <?php foreach ($categories as $slug => $label): ?>
                <option value="<?= e($slug) ?>" <?= $category === $slug ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="mine" class="form-control toolbar-select" data-autosubmit>
            <option value="">Everything I can see</option>
            <option value="1" <?= $mine ? 'selected' : '' ?>>Only mine</option>
        </select>
        <?= toolbar_apply('notes/index.php') ?>
    </div>
    <div class="toolbar-actions">
        <?= export_button('notes/index.php', $baseQuery) ?>
        <button type="button" class="btn btn-primary" data-modal-open="tplNoteForm" data-modal-title="New note" data-modal-auto>
            <?= icon('plus') ?> New note
        </button>
    </div>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table" id="notesTable">
            <thead>
                <tr>
                    <th>Note</th>
                    <th>Category</th>
                    <th>Visibility</th>
                    <th>Written by</th>
                    <th>Updated</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$notes): ?>
                    <tr><td colspan="6" class="table-empty">
                        <?= $search !== '' || $category !== '' || $mine
                            ? 'No notes match these filters.'
                            : 'No notes yet. The first one is a good place for whatever you keep re-explaining.' ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($notes as $n):
                    $canEdit = note_may_edit($n);
                    $prefill = json_encode([
                        'note_id'    => $n['note_id'],
                        'title'      => $n['title'],
                        'body'       => $n['body'],
                        'category'   => $n['category'],
                        'visibility' => $n['visibility'],
                        'is_pinned'  => $n['is_pinned'] ? '1' : '',
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td>
                            <span class="cell-title">
                                <?php if ($n['is_pinned']): ?><span class="note-pin" title="Pinned">●</span> <?php endif; ?>
                                <a href="<?= e(url('notes/view.php?id=' . (int) $n['note_id'])) ?>"><?= e($n['title']) ?></a>
                            </span>
                            <?php if ($excerpt = note_excerpt($n['body'])): ?>
                                <span class="cell-sub"><?= e($excerpt) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="role-pill"><?= e(note_category_label($n['category'])) ?></span></td>
                        <td>
                            <span class="badge badge--<?= $n['visibility'] === 'shared' ? 'active' : 'pending' ?>">
                                <?= $n['visibility'] === 'shared' ? 'Shared' : 'Private' ?>
                            </span>
                        </td>
                        <td class="cell-muted"><?= e($n['author_name'] ?: '—') ?></td>
                        <td class="cell-muted"><?= e(fmt_date($n['updated_at'], 'd M Y H:i')) ?></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a class="icon-btn" title="Print" target="_blank" rel="noopener"
                                   href="<?= e(url('notes/pdf.php?id=' . (int) $n['note_id'])) ?>"><?= icon('file') ?></a>
                                <?php if ($canEdit): ?>
                                    <button type="button" class="icon-btn" title="Edit"
                                            data-modal-open="tplNoteForm" data-modal-title="Edit note"
                                            data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                    <form method="POST" action="<?= e(url('notes/delete.php')) ?>" class="inline-form"
                                          data-confirm="Delete &ldquo;<?= e($n['title']) ?>&rdquo;? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $n['note_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'note'); ?>
</div>

<template id="tplNoteForm">
    <form method="POST" action="<?= e(url('notes/save.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="note_id" value="">

        <div class="form-group">
            <label class="form-label">Title <span class="req">*</span></label>
            <input type="text" name="title" class="form-control" required maxlength="200"
                   placeholder="What is this note about?">
        </div>

        <div class="form-group">
            <label class="form-label">Note <span class="req">*</span></label>
            <textarea name="body" class="form-control" rows="10" required
                      placeholder="Write it as you would say it. Line breaks are kept."></textarea>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category" class="form-control">
                    <?php foreach ($categories as $slug => $label): ?>
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

<?php require __DIR__ . '/../../includes/footer.php'; ?>
