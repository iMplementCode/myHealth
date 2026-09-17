<?php

/**
 * ============================================================
 *  Product Categories — List (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page into the shared
 *  layout. Add and edit both use the shared modal + AJAX
 *  pattern, so creating a category never leaves this page.
 *
 *  Preserves every legacy feature: parent/child nesting, slug
 *  auto-generation with uniqueness handling, the active flag,
 *  and a delete guarded against categories that still hold
 *  products or sub-categories.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';

/** Build a URL-safe slug, falling back to the name when blank. */
function category_slug(string $raw, string $name): string
{
    $base = $raw !== '' ? $raw : $name;
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $base), '-'));
    return $slug !== '' ? substr($slug, 0, 100) : 'category';
}

/* ── AJAX: create or update a category ────────────────────── */
// One handler serves both, so the same form and validation back
// the "Add Category" and "Edit Category" modals.
if (is_post() && in_array($_POST['_action'] ?? '', ['create', 'update'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    // Inventory is the Manager's to change. Reading the list stays
    // open to anyone signed in — a salesperson has to be able to
    // look a product up — but adding, editing and deleting do not.
    require_role(ROLE_MANAGER);

    $isCreate = $_POST['_action'] === 'create';
    $id       = input_int($_POST, 'category_id');
    $name     = input($_POST, 'name');
    $parentId = input_int($_POST, 'parent_id');

    if ($name === '') {
        json_response(['success' => false, 'message' => 'Category name is required.']);
    }
    if (!$isCreate && ($id === null || !db_value("SELECT 1 FROM categories WHERE category_id = :id", [':id' => $id]))) {
        json_response(['success' => false, 'message' => 'That category no longer exists.']);
    }
    if (!$isCreate && $parentId !== null && $parentId === $id) {
        json_response(['success' => false, 'message' => 'A category cannot be its own parent.']);
    }
    if ($parentId !== null && !db_value("SELECT 1 FROM categories WHERE category_id = :id", [':id' => $parentId])) {
        json_response(['success' => false, 'message' => 'The selected parent category no longer exists.']);
    }

    $slug   = category_slug(input($_POST, 'slug'), $name);
    $desc   = input($_POST, 'description') ?: null;
    $active = isset($_POST['is_active']) ? 't' : 'f';

    // Slug must stay unique — suffix a counter rather than failing.
    $candidate = $slug;
    $n = 2;
    while (db_value(
        "SELECT 1 FROM categories WHERE slug = :s AND (:self::int IS NULL OR category_id <> :self)",
        [':s' => $candidate, ':self' => $isCreate ? null : $id]
    )) {
        $candidate = substr($slug, 0, 95) . '-' . $n++;
    }
    $slug = $candidate;

    try {
        if ($isCreate) {
            $id = (int) db_value(
                "INSERT INTO categories (parent_id, name, slug, description, is_active)
                 VALUES (:parent, :name, :slug, :desc, :active)
                 RETURNING category_id",
                [':parent' => $parentId, ':name' => $name, ':slug' => $slug, ':desc' => $desc, ':active' => $active]
            );
        } else {
            db_run(
                "UPDATE categories
                    SET parent_id = :parent, name = :name, slug = :slug,
                        description = :desc, is_active = :active, updated_at = NOW()
                  WHERE category_id = :id",
                [':parent' => $parentId, ':name' => $name, ':slug' => $slug,
                 ':desc' => $desc, ':active' => $active, ':id' => $id]
            );
        }
        audit_log($isCreate ? 'category.create' : 'category.update', 'categories', $id, ['name' => $name]);
        json_response(['success' => true, 'message' => $isCreate ? 'Category added.' : 'Category updated.']);
    } catch (PDOException $ex) {
        error_log('[CATEGORIES] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => $ex->getCode() === '23505'
            ? 'A category with that name already exists.'
            : db_rule_message($ex, 'Could not save the category. Please try again.')], 500);
    }
}

/* ── POST: delete a category ──────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    require_role(ROLE_MANAGER);
    $id  = input_int($_POST, 'category_id');
    $cat = $id !== null ? db_one("SELECT name FROM categories WHERE category_id = :id", [':id' => $id]) : null;
    if (!$cat) {
        flash('error', 'That category no longer exists.');
    } else {
        $products = (int) db_value("SELECT COUNT(*) FROM products WHERE category_id = :id", [':id' => $id]);
        $children = (int) db_value("SELECT COUNT(*) FROM categories WHERE parent_id = :id", [':id' => $id]);
        if ($products > 0 || $children > 0) {
            flash('error', sprintf(
                '"%s" still has %d product%s and %d sub-categor%s. Move them first, or deactivate the category instead.',
                $cat['name'], $products, $products === 1 ? '' : 's', $children, $children === 1 ? 'y' : 'ies'
            ));
        } else {
            try {
                db_run("DELETE FROM categories WHERE category_id = :id", [':id' => $id]);
                audit_log('category.delete', 'categories', $id, ['name' => $cat['name']]);
                flash('success', 'Category "' . $cat['name'] . '" deleted.');
            } catch (PDOException $ex) {
                error_log('[CATEGORIES] delete failed: ' . $ex->getMessage());
                flash('error', 'Could not delete that category.');
            }
        }
    }
    redirect('modules/inventory/view_categories.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search  = input($_GET, 'q');
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(c.name) LIKE :q OR LOWER(COALESCE(c.description,'')) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM categories c $whereSql", $params);
$pg    = paginate($total, $perPage);

$categories = db_all(
    "SELECT c.*, p.name AS parent_name,
            (SELECT COUNT(*) FROM products WHERE category_id = c.category_id) AS product_count
     FROM categories c
     LEFT JOIN categories p ON p.category_id = c.parent_id
     $whereSql
     ORDER BY COALESCE(p.name, c.name), c.name
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Every category may act as a parent for another.
$parents = db_all("SELECT category_id, name FROM categories ORDER BY name");

// Who may change the catalogue. The list stays open to anyone signed
// in; the buttons that write are the Manager's, and showing a button
// that answers 403 is its own kind of bug.
$canManage = is_admin() || user_has_role(ROLE_MANAGER);

$pageTitle    = 'Categories';
$pageSubtitle = 'Product categories and sub-categories';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Inventory'], ['label' => 'Categories']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter(['q' => $search], fn($v) => $v !== '');
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/inventory/view_categories.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search categories…">
    
        <?= toolbar_apply('modules/inventory/view_categories.php') ?>
    </form>
    <?php if ($canManage): ?>
    <button type="button" class="btn btn-primary"
            data-modal-open="tplCategoryForm" data-modal-title="Add Category" data-modal-auto
            data-prefill='<?= e(json_encode(['_action' => 'create', 'category_id' => '', 'is_active' => 1], JSON_UNESCAPED_SLASHES)) ?>'>
        <?= icon('plus') ?> Add Category
    </button>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Parent</th>
                    <th>Slug</th>
                    <th class="ta-right">Products</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$categories): ?>
                    <tr><td colspan="6" class="table-empty">No categories yet. Add one to start organising products.</td></tr>
                <?php endif; ?>
                <?php foreach ($categories as $c): ?>
                    <?php
                    $prefill = json_encode([
                        'category_id' => $c['category_id'],
                        'name'        => $c['name'],
                        'slug'        => $c['slug'],
                        'parent_id'   => $c['parent_id'] ?? '',
                        'description' => $c['description'] ?? '',
                        'is_active'   => $c['is_active'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                    ?>
                    <tr>
                        <td><span class="cell-title"><?= e($c['name']) ?></span></td>
                        <td class="cell-muted"><?= e($c['parent_name'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e($c['slug']) ?></td>
                        <td class="ta-right"><?= e(num($c['product_count'], 0)) ?></td>
                        <td>
                            <span class="badge badge--<?= $c['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if ($canManage): ?>
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplCategoryForm" data-modal-title="Edit Category"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <form method="POST" action="<?= e(url('modules/inventory/view_categories.php')) ?>" class="inline-form"
                                      data-confirm="Delete <?= e($c['name']) ?>? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="category_id" value="<?= (int) $c['category_id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                </form>
                                <?php else: ?>
                                    <span class="cell-muted">&mdash;</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'category'); ?>
</div>

<template id="tplCategoryForm">
    <form method="POST" action="<?= e(url('modules/inventory/view_categories.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="category_id" value="">

        <div class="form-group">
            <label class="form-label">Category name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="100">
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Parent category</label>
                <select name="parent_id" class="form-control">
                    <option value="">— Top level —</option>
                    <?php foreach ($parents as $p): ?>
                        <option value="<?= (int) $p['category_id'] ?>"><?= e($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Slug <span class="opt">(auto if blank)</span></label>
                <input type="text" name="slug" class="form-control" maxlength="100" placeholder="cctv-cameras">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea name="description" class="form-control" rows="3"></textarea>
        </div>

        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="is_active">
                <span>Active</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save category</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
