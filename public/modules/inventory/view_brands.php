<?php

/**
 * ============================================================
 *  Product Brands — List
 * ------------------------------------------------------------
 *  Brands arrived with migration 007 as a fixed list of six,
 *  seeded once and unreachable afterwards. This page makes them
 *  a managed list: add, rename, deactivate, delete.
 *
 *  A brand is deletable only while nothing uses it. Deactivating
 *  is the answer for a brand that has history but is no longer
 *  stocked — it drops out of the product form without touching
 *  the products that already carry it.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_tables('brands');

/** Build a URL-safe slug, falling back to the name when blank. */
function brand_slug(string $raw, string $name): string
{
    $base = $raw !== '' ? $raw : $name;
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $base), '-'));
    return $slug !== '' ? substr($slug, 0, 100) : 'brand';
}

/* ── AJAX: create or update a brand ───────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    // One form serves both modals. Which one this is follows from
    // whether the prefill carried an id, so no script has to keep a
    // hidden action field in step with the button that opened it.
    $id       = input_int($_POST, 'brand_id');
    $isCreate = $id === null;
    $name     = trim(input($_POST, 'name'));
    $website  = trim(input($_POST, 'website'));

    if ($name === '') {
        json_response(['success' => false, 'message' => 'Brand name is required.']);
    }
    if (!$isCreate && ($id === null || !db_value("SELECT 1 FROM brands WHERE brand_id = :id", [':id' => $id]))) {
        json_response(['success' => false, 'message' => 'That brand no longer exists.']);
    }

    // Names are unique case-insensitively, so say which brand holds
    // the name rather than letting a constraint fail generically.
    $clash = db_one(
        "SELECT name FROM brands WHERE LOWER(name) = LOWER(:n) AND brand_id IS DISTINCT FROM :id",
        [':n' => $name, ':id' => $id]
    );
    if ($clash) {
        json_response(['success' => false, 'message' => '"' . $clash['name'] . '" is already on the list.']);
    }

    if ($website !== '' && !preg_match('#^https?://#i', $website)) {
        $website = 'https://' . $website;      // a bare domain is what people type
    }
    if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
        json_response(['success' => false, 'message' => 'That website address does not look valid.']);
    }

    // The slug must stay unique — suffix a counter rather than fail.
    $slug      = brand_slug(input($_POST, 'slug'), $name);
    $candidate = $slug;
    for ($n = 2; db_value(
        "SELECT 1 FROM brands WHERE LOWER(slug) = LOWER(:s) AND brand_id IS DISTINCT FROM :id",
        [':s' => $candidate, ':id' => $id]
    ); $n++) {
        $candidate = $slug . '-' . $n;
    }

    $active = isset($_POST['is_active']) ? 't' : 'f';
    $order  = input_int($_POST, 'sort_order') ?? 0;

    try {
        if ($isCreate) {
            $id = (int) db_value(
                "INSERT INTO brands (name, slug, website, notes, sort_order, is_active)
                 VALUES (:n, :s, :w, :notes, :ord, :active)
                 RETURNING brand_id",
                [':n' => $name, ':s' => $candidate, ':w' => $website ?: null,
                 ':notes' => input($_POST, 'notes') ?: null, ':ord' => $order, ':active' => $active]
            );
        } else {
            db_run(
                "UPDATE brands
                    SET name = :n, slug = :s, website = :w, notes = :notes,
                        sort_order = :ord, is_active = :active, updated_at = NOW()
                  WHERE brand_id = :id",
                [':n' => $name, ':s' => $candidate, ':w' => $website ?: null,
                 ':notes' => input($_POST, 'notes') ?: null, ':ord' => $order,
                 ':active' => $active, ':id' => $id]
            );
        }
        audit_log($isCreate ? 'brand.create' : 'brand.update', 'brands', $id, ['name' => $name]);
        json_response(['success' => true, 'message' => $isCreate
            ? $name . ' added.'
            : $name . ' updated.']);
    } catch (PDOException $ex) {
        error_log('[BRAND] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not save the brand.')], 500);
    }
}

/* ── POST: delete a brand ─────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    require_role(ROLE_MANAGER);

    $id    = input_int($_POST, 'id');
    $brand = $id !== null ? db_one("SELECT brand_id, name FROM brands WHERE brand_id = :id", [':id' => $id]) : null;

    if (!$brand) {
        flash('error', 'That brand no longer exists.');
    } else {
        $used = (int) db_value("SELECT COUNT(*) FROM products WHERE brand_id = :id", [':id' => $id]);
        if ($used > 0) {
            // Deleting would strip the brand from products that
            // legitimately carry it. Deactivating is the honest way
            // to retire a brand with history.
            flash('error', sprintf(
                '%s is on %d product%s, so it cannot be deleted. Deactivate it instead — '
                . 'it will stop appearing on the product form without changing those products.',
                $brand['name'], $used, $used === 1 ? '' : 's'
            ));
        } else {
            db_run("DELETE FROM brands WHERE brand_id = :id", [':id' => $id]);
            audit_log('brand.delete', 'brands', $id, ['name' => $brand['name']]);
            flash('success', $brand['name'] . ' deleted.');
        }
    }
    redirect('modules/inventory/view_brands.php');
}

/* ── Data ─────────────────────────────────────────────────── */
$search  = input($_GET, 'q');
$status  = input($_GET, 'status');
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(b.name) LIKE :q OR LOWER(b.slug) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($status === 'active' || $status === 'inactive') {
    $where[] = "b.is_active = :active";
    $params[':active'] = $status === 'active' ? 't' : 'f';
} else {
    $status = '';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value("SELECT COUNT(*) FROM brands b $whereSql", $params);
$pg    = paginate($total, $perPage);

$brands = db_all(
    "SELECT b.*,
            (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.brand_id) AS product_count
       FROM brands b
     $whereSql
     ORDER BY b.sort_order, LOWER(b.name)
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$activeCount = (int) db_value("SELECT COUNT(*) FROM brands WHERE is_active");
$unbranded   = (int) db_value("SELECT COUNT(*) FROM products WHERE brand_id IS NULL AND is_active");

$pageTitle    = 'Brands';
$pageSubtitle = 'Manufacturers and makes the catalogue carries';
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Inventory'], ['label' => 'Brands']];

require __DIR__ . '/../../../includes/header.php';

$baseQuery = array_filter([
    'q' => $search, 'status' => $status,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Brands</span><span class="mini-value"><?= e(num($total)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Active</span><span class="mini-value"><?= e(num($activeCount)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Products with no brand</span><span class="mini-value"><?= e(num($unbranded)) ?></span></div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('modules/inventory/view_brands.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search brands…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Active and inactive</option>
            <option value="active"   <?= $status === 'active' ? 'selected' : '' ?>>Active only</option>
            <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
        </select>
        <?= toolbar_apply('modules/inventory/view_brands.php') ?>
    </div>
    <button type="button" class="btn btn-primary" data-modal-open="tplBrandForm" data-modal-title="Add Brand" data-modal-auto>
        <?= icon('plus') ?> Add Brand
    </button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Brand</th>
                    <th>Slug</th>
                    <th>Website</th>
                    <th class="ta-right">Products</th>
                    <th class="ta-right">Order</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$brands): ?>
                    <tr><td colspan="7" class="table-empty">
                        No brands match. Add one with <strong>Add Brand</strong>.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($brands as $b):
                    $prefill = json_encode([
                        'brand_id'   => $b['brand_id'],
                        'name'       => $b['name'],
                        'slug'       => $b['slug'],
                        'website'    => $b['website'],
                        'notes'      => $b['notes'],
                        'sort_order' => $b['sort_order'],
                        'is_active'  => $b['is_active'] ? 1 : '',
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td><span class="cell-title"><?= e($b['name']) ?></span></td>
                        <td class="cell-muted"><?= e($b['slug']) ?></td>
                        <td class="cell-muted">
                            <?php if ($b['website']): ?>
                                <a href="<?= e($b['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($b['website']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <?php if ((int) $b['product_count'] > 0): ?>
                                <a href="<?= e(url('modules/inventory/view_products.php?q=' . urlencode($b['name']))) ?>">
                                    <?= e(num((int) $b['product_count'])) ?>
                                </a>
                            <?php else: ?>
                                <span class="cell-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right cell-muted"><?= e(num((int) $b['sort_order'])) ?></td>
                        <td>
                            <span class="badge badge--<?= $b['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $b['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplBrandForm" data-modal-title="Edit Brand"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <?php if (is_admin() || user_has_role(ROLE_MANAGER)): ?>
                                    <form method="POST" action="<?= e(url('modules/inventory/view_brands.php')) ?>" class="inline-form"
                                          data-confirm="Delete <?= e($b['name']) ?>?<?= (int) $b['product_count'] > 0 ? ' It is used by ' . (int) $b['product_count'] . ' product(s) and will be refused.' : ' This cannot be undone.' ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $b['brand_id'] ?>">
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

    <?php pagination_nav($pg, $baseQuery, 'brand'); ?>
</div>

<template id="tplBrandForm">
    <form method="POST" action="<?= e(url('modules/inventory/view_brands.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="brand_id" value="">

        <div class="form-group">
            <label class="form-label">Brand name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="120"
                   placeholder="e.g. Uniview">
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Slug <span class="opt">(leave blank to generate)</span></label>
                <input type="text" name="slug" class="form-control" maxlength="120" placeholder="uniview">
                <p class="form-hint">Used by the storefront's brand filter.</p>
            </div>
            <div class="form-group">
                <label class="form-label">Sort order</label>
                <input type="number" name="sort_order" class="form-control" step="1" value="0">
                <p class="form-hint">Lower numbers come first; ties fall back to the name.</p>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Website</label>
            <input type="text" name="website" class="form-control" maxlength="255" placeholder="uniview.com">
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Distributor, agency terms, anything worth remembering">
        </div>

        <div class="form-group form-check">
            <label><input type="checkbox" name="is_active" value="1" checked> Active</label>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save brand</button>
        </div>
    </form>
</template>


<?php require __DIR__ . '/../../../includes/footer.php'; ?>
