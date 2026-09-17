<?php

/**
 * ============================================================
 *  Products — List (uniform UI)
 * ------------------------------------------------------------
 *  Rebuilt from the legacy standalone page into the shared
 *  layout. Preserves every legacy feature — CSV export, edit,
 *  delete — and adds server-side pagination, search, sorting
 *  and stock filters (?filter=low|out) used by the dashboard.
 *
 *  Edit uses the shared modal + AJAX pattern; delete is a
 *  CSRF-protected POST with confirmation.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/export.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/uploads.php';
require_once __DIR__ . '/../../../includes/documents.php';
require_once __DIR__ . '/../../../includes/pharmacy.php';

/* ── AJAX: suggest a SKU for the product form ─────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'suggest_sku') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired.'], 403);
    }
    $name = input($_POST, 'name');
    if ($name === '') {
        json_response(['success' => false, 'message' => 'Enter a product name first.']);
    }
    json_response(['success' => true, 'sku' => generate_sku($name, input_int($_POST, 'category_id'))]);
}

/* ── AJAX: create or update a product (from the modal) ────── */
// One handler serves both so the same form, validation and field
// mapping back the "Add Product" and "Edit Product" modals.
if (is_post() && in_array($_POST['_action'] ?? '', ['create', 'update'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    // Inventory is the Manager's to change. Reading the list stays
    // open to anyone signed in — a salesperson has to be able to
    // look a product up — but adding, editing and deleting do not.
    require_role(ROLE_MANAGER);

    $isCreate = $_POST['_action'] === 'create';
    $id = input_int($_POST, 'product_id');
    $name = input($_POST, 'name');
    $cost = input($_POST, 'cost_price');
    $sell = input($_POST, 'selling_price');
    $qty  = input($_POST, 'stock_quantity');
    $low  = input($_POST, 'low_quantity_threshold');

    // Installation, transport, a site survey: sold and invoiced like
    // anything else, but they hold no stock, so the form hides the
    // stock boxes and the server ignores whatever was in them.
    $type = input($_POST, 'product_type') ?: 'goods';
    if (!array_key_exists($type, PRODUCT_TYPES)) {
        json_response(['success' => false, 'message' => 'Choose whether this is a product or a service.']);
    }
    if ($type === 'service') {
        $qty = '0';
        $low = '0';
    }

    $errors = [];
    if (!$isCreate && ($id === null || !db_value("SELECT 1 FROM products WHERE product_id = :id", [':id' => $id]))) {
        $errors[] = 'That product no longer exists.';
    }
    if ($name === '') $errors[] = ($type === 'service' ? 'Service' : 'Product') . ' name is required.';
    foreach ([['cost price', $cost], ['selling price', $sell], ['stock quantity', $qty], ['low-stock threshold', $low]] as [$label, $v]) {
        if (!is_numeric($v) || (float) $v < 0) $errors[] = "Enter a valid {$label} (0 or more).";
    }

    // Turning stock into labour would leave the stock unaccounted for:
    // the trigger zeroes the quantity, and nothing says where it went.
    // Count it out first, or write it off, and the books still balance.
    if (!$isCreate && $type === 'service') {
        $held = (float) db_value(
            "SELECT p.stock_quantity FROM products p WHERE p.product_id = :id AND " . goods_only_sql(),
            [':id' => $id]
        );
        if ($held > 0.0005) {
            $errors[] = 'This product still holds ' . num($held, 2) . ' in stock, so it cannot become a service. '
                      . 'Count it out or write it off first.';
        }
    }
    if ($errors) {
        json_response(['success' => false, 'message' => implode(' ', $errors)]);
    }

    // Optional discount price (drives the storefront "on sale" flag).
    $disc = input($_POST, 'discount_price');
    if ($disc !== '' && (!is_numeric($disc) || (float) $disc < 0)) {
        json_response(['success' => false, 'message' => 'Enter a valid discount price (0 or more).']);
    }
    if ($disc !== '' && is_numeric($sell) && (float) $disc >= (float) $sell) {
        json_response(['success' => false, 'message' => 'Discount price must be less than the selling price.']);
    }

    $published = isset($_POST['is_published']);
    $pdo = db();

    // Every writable column, shared by both paths.
    // A blank SKU is filled in rather than left null, so every product
    // is identifiable on a picking list or a supplier order.
    $sku = input($_POST, 'sku') !== ''
        ? strtoupper(input($_POST, 'sku'))
        : generate_sku($name, input_int($_POST, 'category_id'));

    // The column arrives in migration 034. On a database that has not
    // had it yet the form still saves — it just cannot record that a
    // line is a service, which is the honest outcome rather than a
    // failure to save the product at all.
    $hasTypes   = product_types_available();
    $typeColumn = $hasTypes ? 'product_type,' : '';
    $typeValue  = $hasTypes ? ':ptype,'       : '';
    $typeSet    = $hasTypes ? 'product_type = :ptype,' : '';

    /*  Validated against the lists in includes/pharmacy.php, which
     *  are the same lists the selects were built from. Anything
     *  else becomes NULL rather than reaching the CHECK constraint
     *  — the constraint is the backstop, not the error message. */
    $fields = [
        ':generic'  => input($_POST, 'generic_name') ?: null,
        ':strength' => input($_POST, 'strength') ?: null,
        ':form'     => pharmacy_valid(DOSAGE_FORMS, $_POST['dosage_form'] ?? ''),
        ':route'    => pharmacy_valid(ADMIN_ROUTES, $_POST['route'] ?? ''),
        ':storage'  => pharmacy_valid(STORAGE_CONDITIONS, $_POST['storage'] ?? ''),
        ':sched'    => pharmacy_valid(CONTROLLED_SCHEDULES, $_POST['controlled_schedule'] ?? ''),
        ':ppb'      => input($_POST, 'ppb_registration_no') ?: null,
        ':rx'       => isset($_POST['requires_rx']) ? 't' : 'f',
        ':name' => $name,
        ':sku' => $sku,
        ':barcode' => input($_POST, 'barcode') !== '' ? input($_POST, 'barcode') : null,
        ':cost' => $cost, ':sell' => $sell,
        ':disc' => $disc !== '' ? $disc : null,
        ':qty' => $qty, ':low' => $low,
        ':active' => isset($_POST['is_active']) ? 't' : 'f',
        ':brand' => input_int($_POST, 'brand_id'),
        ':short' => input($_POST, 'short_description') ?: null,
        ':published' => $published ? 't' : 'f',
        ':featured' => isset($_POST['is_featured']) ? 't' : 'f',
        ':weight' => input($_POST, 'weight_kg') !== '' ? input($_POST, 'weight_kg') : null,
        ':mtitle' => input($_POST, 'meta_title') ?: null,
        ':mdesc' => input($_POST, 'meta_description') ?: null,
        ':category' => input_int($_POST, 'category_id'),
        ':supplier' => input_int($_POST, 'supplier_id'),
        ':uom' => input_int($_POST, 'uom_id'),
        ':desc_long' => input($_POST, 'description') ?: null,
    ];

    try {
        $pdo->beginTransaction();

        if ($isCreate) {
            $stmt = $pdo->prepare(
                "INSERT INTO products
                    (name, sku, barcode, cost_price, selling_price, discount_price,
                     stock_quantity, low_quantity_threshold, $typeColumn is_active, brand_id,
                     generic_name, strength, dosage_form, route, storage,
                     controlled_schedule, ppb_registration_no, requires_rx,
                     category_id, supplier_id, uom_id, description,
                     short_description, is_published, is_featured, weight_kg,
                     meta_title, meta_description, published_at, created_by)
                 VALUES
                    (:name, :sku, :barcode, :cost, :sell, :disc,
                     :qty, :low, $typeValue :active, :brand,
                     :generic, :strength, :form, :route, :storage,
                     :sched, :ppb, :rx,
                     :category, :supplier, :uom, :desc_long,
                     :short, :published, :featured, :weight,
                     :mtitle, :mdesc,
                     CASE WHEN :published2 = 't' THEN NOW() ELSE NULL END, :by)
                 RETURNING product_id"
            );
            $stmt->execute($fields + ($hasTypes ? [':ptype' => $type] : [])
                           + [':published2' => $published ? 't' : 'f', ':by' => current_user()['id']]);
            $id = (int) $stmt->fetchColumn();
        } else {
        db_run(
            "UPDATE products
             SET name = :name, sku = :sku, barcode = :barcode,
                 cost_price = :cost, selling_price = :sell, discount_price = :disc,
                 stock_quantity = :qty, low_quantity_threshold = :low,
                 $typeSet is_active = :active, brand_id = :brand, short_description = :short,
                 generic_name = :generic, strength = :strength, dosage_form = :form,
                 route = :route, storage = :storage, controlled_schedule = :sched,
                 ppb_registration_no = :ppb, requires_rx = :rx,
                 category_id = :category, supplier_id = :supplier, uom_id = :uom,
                 description = :desc_long,
                 is_published = :published, is_featured = :featured,
                 weight_kg = :weight,
                 meta_title = :mtitle, meta_description = :mdesc,
                 published_at = CASE WHEN :published2 = 't' AND published_at IS NULL THEN NOW() ELSE published_at END,
                 updated_at = NOW()
             WHERE product_id = :id",
            $fields + ($hasTypes ? [':ptype' => $type] : [])
                    + [':published2' => $published ? 't' : 'f', ':id' => $id]
        );
        }

        // Images: validated and stored, then linked to the product.
        // The first image becomes primary when none is set yet.
        if (!empty($_FILES['images']['name'][0] ?? '')) {
            $up = store_uploaded_images($_FILES['images'], 'products');
            if ($up['errors']) {
                throw new RuntimeException(implode(' ', $up['errors']));
            }
            attach_product_images($id, $up['files']);
        }

        // Rewrite specifications from the "Name: Value" (one per line) editor.
        $pdo->prepare("DELETE FROM product_specifications WHERE product_id = :id")->execute([':id' => $id]);
        $specLines = preg_split('/\r\n|\r|\n/', (string) input($_POST, 'specifications'));
        $specStmt = $pdo->prepare(
            "INSERT INTO product_specifications (product_id, spec_name, spec_value, sort_order)
             VALUES (:id, :name, :value, :ord)"
        );
        $ord = 0;
        foreach ($specLines as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) continue;
            [$specName, $specVal] = array_map('trim', explode(':', $line, 2));
            if ($specName === '' || $specVal === '') continue;
            $specStmt->execute([':id' => $id, ':name' => mb_substr($specName, 0, 150), ':value' => $specVal, ':ord' => $ord++]);
        }

        $pdo->commit();
        audit_log($isCreate ? 'product.create' : 'product.update', 'products', $id,
            ['name' => $name, 'published' => $published]);
        json_response(['success' => true, 'message' => $isCreate ? 'Product added.' : 'Product updated.']);
    } catch (RuntimeException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[PRODUCTS] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => $ex->getCode() === '23505'
            ? 'That SKU or barcode is already in use.'
            : db_rule_message($ex, 'Could not save changes. Please try again.')], 500);
    }
}

/* ── POST: delete product ─────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    require_role(ROLE_MANAGER);
    $id = input_int($_POST, 'product_id');
    $product = $id !== null ? db_one("SELECT name FROM products WHERE product_id = :id", [':id' => $id]) : null;
    if (!$product) {
        flash('error', 'That product no longer exists.');
    } else {
        try {
            db_run("DELETE FROM products WHERE product_id = :id", [':id' => $id]);
            audit_log('product.delete', 'products', $id, ['name' => $product['name']]);
            flash('success', 'Product "' . $product['name'] . '" deleted.');
        } catch (PDOException $ex) {
            error_log('[PRODUCTS] delete failed: ' . $ex->getMessage());
            flash('error', $ex->getCode() === '23503'
                ? 'This product is referenced by quotes, sales or purchases and cannot be deleted. Deactivate it instead.'
                : 'Could not delete the product.');
        }
    }
    redirect('modules/inventory/view_products.php');
}

/* ── Filters, search, sort ────────────────────────────────── */
$search  = input($_GET, 'q');
$filter  = input($_GET, 'filter');            // low | out | active | inactive
$catId   = input_int($_GET, 'category');
$sort    = input($_GET, 'sort');
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(p.name) LIKE :q OR LOWER(COALESCE(p.sku,'')) LIKE :q OR LOWER(COALESCE(p.barcode,'')) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
switch ($filter) {
    // The two stock filters are about goods by definition — a service
    // holds none, so every one of them would answer to "out of stock"
    // and bury the products that genuinely need reordering.
    case 'low':      $where[] = goods_only_sql() . " AND p.stock_quantity > 0 AND p.stock_quantity <= p.low_quantity_threshold"; break;
    case 'out':      $where[] = goods_only_sql() . " AND p.stock_quantity <= 0"; break;
    case 'active':   $where[] = "p.is_active = TRUE";  break;
    case 'inactive': $where[] = "p.is_active = FALSE"; break;
    case 'goods':    $where[] = goods_only_sql(); break;
    case 'services': $where[] = product_types_available() ? "p.product_type = 'service'" : 'FALSE'; break;
    default:         $filter = '';
}
if ($catId !== null) {
    $where[] = "p.category_id = :cat";
    $params[':cat'] = $catId;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$marginSql = margin_percent_sql('p.cost_price', 'p.selling_price');
$sortMap = [
    'name'   => 'p.name ASC',
    'price'  => 'p.selling_price DESC',
    'stock'  => 'p.stock_quantity ASC',
    'newest' => 'p.created_at DESC',
    // Worst first, because that is the question somebody opens this
    // list to ask. Products with no price have no margin and sort
    // last rather than masquerading as the worst.
    'margin'     => "$marginSql ASC NULLS LAST",
    'margin_top' => "$marginSql DESC NULLS LAST",
];
$orderBy = $sortMap[$sort] ?? $sortMap['name'];
if (!isset($sortMap[$sort])) $sort = 'name';

$selectSql =
    "FROM products p
     LEFT JOIN categories c ON c.category_id = p.category_id
     LEFT JOIN suppliers s ON s.supplier_id = p.supplier_id
     LEFT JOIN units_of_measurement u ON u.uom_id = p.uom_id
     $whereSql";

/* ── Excel export (honours current filters) ───────────────── */
// This used to write a CSV under an "excel" label. A CSV cannot
// carry a number as a number, so the stock and price columns
// arrived in Excel as text and would not add up. It is a real
// workbook now; the old ?export=excel link still works.
if (wants_export() || ($_GET['export'] ?? '') === 'excel') {
    export_deliver(export_filename('products'), [[
        'name'  => 'Products',
        'title' => 'Product catalogue',
        'meta'  => export_meta(['Search' => $search]),
        'columns' => [
            ['label' => 'ID',            'key' => 'product_id',   'type' => 'integer', 'total' => false],
            ['label' => 'Product',       'key' => 'name'],
            ['label' => 'SKU',           'key' => 'sku'],
            ['label' => 'Barcode',       'key' => 'barcode'],
            ['label' => 'Type',          'key' => 'product_type',
             'value' => fn($r) => product_type_label($r['product_type'])],
            ['label' => 'Category',      'key' => 'category_name'],
            ['label' => 'Supplier',      'key' => 'supplier_name'],
            ['label' => 'UoM',           'key' => 'uom_abbr'],
            ['label' => 'Cost price',    'key' => 'cost_price',    'type' => 'money', 'total' => false],
            ['label' => 'Selling price', 'key' => 'selling_price', 'type' => 'money', 'total' => false],
            // Same figure as the Margin column on screen, from the same
            // function. Blank where there is no price to take a margin on.
            ['label' => 'Margin %',      'key' => 'margin_pct',    'type' => 'percent', 'total' => false,
             'value' => fn($r) => margin_of((float) $r['cost_price'], (float) $r['selling_price'])['percent']],
            ['label' => 'In stock',      'key' => 'stock_quantity','type' => 'number'],
            ['label' => 'Reorder at',    'key' => 'low_quantity_threshold', 'type' => 'number', 'total' => false],
            ['label' => 'Value at cost', 'key' => 'value', 'type' => 'money',
             'value' => fn($r) => (float) $r['stock_quantity'] * (float) $r['cost_price']],
            ['label' => 'Status',        'key' => 'is_active', 'value' => fn($r) => $r['is_active'] ? 'Active' : 'Inactive'],
        ],
        'rows' => db_all(
            "SELECT p.product_id, p.name, p.sku, p.barcode, p.product_type, c.name AS category_name,
                    s.name AS supplier_name, p.cost_price, p.selling_price, p.stock_quantity,
                    u.abbreviation AS uom_abbr, p.low_quantity_threshold, p.is_active
             $selectSql ORDER BY $orderBy",
            $params
        ),
        'total' => true,
    ]]);
}

/* ── Page data ────────────────────────────────────────────── */
$total = (int) db_value("SELECT COUNT(*) $selectSql", $params);
$pg    = paginate($total, $perPage);

$products = db_all(
    "SELECT p.*, c.name AS category_name, s.name AS supplier_name, u.abbreviation AS uom_abbr,
            (SELECT image_url FROM product_images WHERE product_id = p.product_id AND is_primary = TRUE LIMIT 1) AS primary_image
     $selectSql ORDER BY $orderBy
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$categories = db_all("SELECT category_id, name FROM categories WHERE is_active = TRUE ORDER BY name");
$brands     = db_all("SELECT brand_id, name FROM brands WHERE is_active = TRUE ORDER BY name");
$suppliers  = db_all("SELECT supplier_id, name FROM suppliers WHERE is_active = TRUE ORDER BY name");
$uoms       = db_all("SELECT uom_id, name, abbreviation FROM units_of_measurement ORDER BY name");

// Specifications for the products on this page (for the edit modal),
// flattened to a "Name: Value" text block per product.
$specsByProduct = [];
if ($products) {
    $ids = implode(',', array_map(fn($p) => (int) $p['product_id'], $products));
    foreach (db_all("SELECT product_id, spec_name, spec_value FROM product_specifications WHERE product_id IN ($ids) ORDER BY sort_order, spec_id") as $sp) {
        $specsByProduct[$sp['product_id']][] = $sp['spec_name'] . ': ' . $sp['spec_value'];
    }
}

// Who may change what is on the shelf. The list itself is open to
// anyone signed in — a salesperson looking a product up is not a
// privilege — but the buttons that write are the Manager's, and
// showing a button that answers 403 is its own kind of bug.
$canManage = is_admin() || user_has_role(ROLE_MANAGER);

$pageTitle    = 'Products';
$pageSubtitle = num($total) . ' product' . ($total === 1 ? '' : 's') . ($filter ? ' · ' . ucfirst($filter) . ' stock filter' : '');
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Inventory'], ['label' => 'Products']];

require __DIR__ . '/../../../includes/header.php';

$baseQuery = array_filter([
    'q' => $search, 'filter' => $filter, 'category' => $catId, 'sort' => $sort !== 'name' ? $sort : null,
], fn($v) => $v !== null && $v !== '');
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/inventory/view_products.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search name, SKU or barcode…">
        <select name="filter" class="form-control toolbar-select" data-autosubmit>
            <option value="">All stock</option>
            <option value="low" <?= $filter === 'low' ? 'selected' : '' ?>>Low stock</option>
            <option value="out" <?= $filter === 'out' ? 'selected' : '' ?>>Out of stock</option>
            <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="goods" <?= $filter === 'goods' ? 'selected' : '' ?>>Products only</option>
            <option value="services" <?= $filter === 'services' ? 'selected' : '' ?>>Services only</option>
        </select>
        <select name="category" class="form-control toolbar-select" data-autosubmit data-searchable="Search categories">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['category_id'] ?>" <?= $catId === (int) $c['category_id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="sort" class="form-control toolbar-select" data-autosubmit>
            <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Name A–Z</option>
            <option value="price" <?= $sort === 'price' ? 'selected' : '' ?>>Price high–low</option>
            <option value="stock" <?= $sort === 'stock' ? 'selected' : '' ?>>Stock low–high</option>
            <option value="margin" <?= $sort === 'margin' ? 'selected' : '' ?>>Margin low–high</option>
            <option value="margin_top" <?= $sort === 'margin_top' ? 'selected' : '' ?>>Margin high–low</option>
            <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest first</option>
        </select>
    
        <?= toolbar_apply('modules/inventory/view_products.php') ?>
    </form>
    <div class="u-flex u-gap">
        <?= export_button('modules/inventory/view_products.php', $baseQuery) ?>
        <?php if ($canManage): ?>
        <button type="button" class="btn btn-primary"
                data-modal-open="tplProductForm" data-modal-title="Add Product" data-modal-wide data-modal-auto
                data-prefill='<?= e(json_encode([
                    '_action' => 'create', 'product_id' => '',
                    'is_active' => 1, 'low_quantity_threshold' => 5,
                    'cost_price' => 0, 'selling_price' => 0, 'stock_quantity' => 0,
                ], JSON_UNESCAPED_SLASHES)) ?>'><?= icon('plus') ?> Add Product</button>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Category</th>
                    <th class="ta-right">Cost</th>
                    <th class="ta-right">Price</th>
                    <th class="ta-right">Margin</th>
                    <th class="ta-right">Stock</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$products): ?>
                    <tr><td colspan="9" class="table-empty">No products match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($products as $p):
                    $isSvc = is_service($p);
                    $stockState = $p['stock_quantity'] <= 0 ? 'out'
                        : ($p['stock_quantity'] <= $p['low_quantity_threshold'] ? 'low' : 'ok');
                    $prefill = json_encode([
                        'product_id'             => $p['product_id'],
                        'product_type'           => $p['product_type'] ?? 'goods',
                        'name'                   => $p['name'],
                        'sku'                    => $p['sku'],
                        'barcode'                => $p['barcode'],
                        'cost_price'             => $p['cost_price'],
                        'selling_price'          => $p['selling_price'],
                        'discount_price'         => $p['discount_price'],
                        'stock_quantity'         => $p['stock_quantity'],
                        'low_quantity_threshold' => $p['low_quantity_threshold'],
                        'is_active'              => $p['is_active'],
                        'brand_id'               => $p['brand_id'] ?? '',
                        'category_id'            => $p['category_id'] ?? '',
                        'supplier_id'            => $p['supplier_id'] ?? '',
                        'uom_id'                 => $p['uom_id'] ?? '',
                        'description'            => $p['description'] ?? '',
                        'short_description'      => $p['short_description'] ?? '',
                        'is_published'           => $p['is_published'] ?? false,
                        'is_featured'            => $p['is_featured'] ?? false,
                        'weight_kg'              => $p['weight_kg'] ?? '',
                        'meta_title'             => $p['meta_title'] ?? '',
                        'meta_description'       => $p['meta_description'] ?? '',
                        'generic_name'           => $p['generic_name'] ?? '',
                        'strength'               => $p['strength'] ?? '',
                        'dosage_form'            => $p['dosage_form'] ?? '',
                        'route'                  => $p['route'] ?? '',
                        'storage'                => $p['storage'] ?? '',
                        'controlled_schedule'    => $p['controlled_schedule'] ?? '',
                        'ppb_registration_no'    => $p['ppb_registration_no'] ?? '',
                        'requires_rx'            => $p['requires_rx'] ?? false,
                        'specifications'         => implode("\n", $specsByProduct[$p['product_id']] ?? []),
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td>
                            <div class="cell-user">
                                <?php if ($p['primary_image']): ?>
                                    <img src="<?= e($p['primary_image']) ?>" alt="" class="avatar avatar--sm" style="object-fit:cover;">
                                <?php else: ?>
                                    <span class="avatar avatar--sm"><?= e(initials($p['name'])) ?></span>
                                <?php endif; ?>
                                <div>
                                    <a class="cell-title panel-link"
                                       href="<?= e(url('modules/inventory/product_card.php?id=' . (int) $p['product_id'])) ?>"
                                       title="Where it came from, where it went, and what it made"><?= e($p['name']) ?></a>
                                    <?php if ($isSvc): ?>
                                        <span class="badge badge--info">Service</span>
                                    <?php endif; ?>
                                    <span class="cell-sub"><?= e($p['supplier_name'] ?? '—') ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="cell-muted"><?= e($p['sku'] ?: '—') ?></td>
                        <td class="cell-muted"><?= e($p['category_name'] ?? '—') ?></td>
                        <td class="ta-right cell-muted"><?= e(money($p['cost_price'])) ?></td>
                        <td class="ta-right"><strong><?= e(money($p['selling_price'])) ?></strong></td>
                        <td class="ta-right">
                            <?php
                            /*  Margin on the price in the column beside it, so
                             *  the arithmetic on screen is the arithmetic a
                             *  reader can check: (Price − Cost) ÷ Price.
                             *
                             *  margin_of() is the one definition of that, and
                             *  it returns null rather than infinity when
                             *  nothing is being charged — margin on a zero
                             *  price is not 0%, it is a question with no
                             *  answer, and printing "0%" would put this
                             *  product at the bottom of a sorted list as
                             *  though it were the worst performer.          */
                            $m       = margin_of((float) $p['cost_price'], (float) $p['selling_price']);
                            $pct     = $m['percent'];
                            $noCost  = (float) $p['cost_price'] <= 0 && (float) $p['selling_price'] > 0;
                            ?>
                            <?php if ($pct === null): ?>
                                <span class="cell-muted" title="No selling price, so there is no margin to work out">—</span>
                            <?php elseif ($noCost): ?>
                                <?php /*  Arithmetically this is 100%, and it is
                                          almost always a cost price nobody has
                                          typed in yet. Shown, because hiding it
                                          hides the gap — but not dressed up as
                                          a healthy margin.                    */ ?>
                                <span class="cell-muted" title="No cost price recorded, so this is not a real margin">
                                    <?= e(num($pct, 1)) ?>%
                                </span>
                            <?php else: ?>
                                <span class="margin-figure<?= $pct < 0 ? ' is-loss' : '' ?>"
                                      title="<?= e(money($m['value'])) ?> per unit">
                                    <?= e(num($pct, 1)) ?>%
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <?php if ($isSvc): ?>
                                <?php // Not zero — "0" here reads as "out of stock",
                                      // and a service is never out of anything. ?>
                                <span class="cell-muted" title="A service holds no stock">n/a</span>
                            <?php else: ?>
                                <span class="badge <?= $stockState === 'out' ? 'badge--inactive' : ($stockState === 'low' ? 'badge--pending' : 'badge--active') ?>">
                                    <?= e(num($p['stock_quantity'], 0)) ?> <?= e($p['uom_abbr'] ?: '') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $p['is_active'] ? 'badge--active' : 'badge--inactive' ?>">
                                <?= $p['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                            <?php if (!empty($p['is_published'])): ?>
                                <span class="badge badge--sent" title="Visible on the website">Web</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a href="<?= e(url('modules/inventory/product_card.php?id=' . (int) $p['product_id'])) ?>"
                                   class="icon-btn" title="History and profit"><?= icon('book') ?></a>
                                <?php if ($canManage): ?>
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplProductForm" data-modal-title="Edit Product"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <form method="POST" action="<?= e(url('modules/inventory/view_products.php')) ?>" class="inline-form"
                                      data-confirm="Delete <?= e($p['name']) ?>? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="product_id" value="<?= (int) $p['product_id'] ?>">
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

    <?php pagination_nav($pg, $baseQuery, 'product'); ?>
</div>

<!-- Edit product modal -->
<template id="tplProductForm">
    <form method="POST" action="<?= e(url('modules/inventory/view_products.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="update">
        <input type="hidden" name="product_id" value="">

        <?php // What kind of thing this is decides half the form. A
              // service has no stock, no reorder level, no weight and
              // nothing to put on a delivery note, so those fields are
              // hidden rather than left to be filled in wrongly. ?>
        <div class="form-group">
            <label class="form-label">This is a <span class="req">*</span></label>
            <div class="radio-row">
                <?php foreach (PRODUCT_TYPES as $val => $label): ?>
                    <label class="checkbox">
                        <input type="radio" name="product_type" value="<?= e($val) ?>"
                               data-product-type <?= $val === 'goods' ? 'checked' : '' ?>>
                        <span><?= e($label) ?><span class="opt">
                            <?= $val === 'goods' ? ' — something you stock' : ' — installation, transport, labour' ?>
                        </span></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label"><span data-name-label>Product</span> name <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="255">
        </div>

        <?php /*  The identity of a medicine, kept next to the name
                  because that is what it is. A shelf holds "Amoxil
                  250" and "Amoxil 500" as two different things, and
                  a row that records only "Amoxil" cannot tell them
                  apart when somebody is counting stock. */ ?>
        <div class="form-grid-3" data-goods-only>
            <div class="form-group">
                <label class="form-label">Generic name</label>
                <input type="text" name="generic_name" class="form-control" maxlength="160"
                       placeholder="Paracetamol">
                <span class="form-hint">What it actually is. Somebody asking for Panadol
                    and somebody asking for Hedex want the same thing.</span>
            </div>
            <div class="form-group">
                <label class="form-label">Strength</label>
                <input type="text" name="strength" class="form-control" maxlength="60"
                       placeholder="500 mg">
                <span class="form-hint">As it is written on the pack &mdash; 500 mg,
                    5 mg/ml, 500 mg/125 mg.</span>
            </div>
            <div class="form-group">
                <label class="form-label">Form</label>
                <select name="dosage_form" class="form-control">
                    <option value="">&mdash; Not said &mdash;</option>
                    <?php foreach (DOSAGE_FORMS as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-grid-3" data-goods-only>
            <div class="form-group">
                <label class="form-label">Route</label>
                <select name="route" class="form-control">
                    <option value="">&mdash; Not said &mdash;</option>
                    <?php foreach (ADMIN_ROUTES as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Storage</label>
                <select name="storage" class="form-control">
                    <option value="">&mdash; Not said &mdash;</option>
                    <?php foreach (STORAGE_CONDITIONS as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">Cold-chain stock that spent a night out of the
                    fridge is waste, and nobody can tell by looking.</span>
            </div>
            <div class="form-group">
                <label class="form-label">PPB registration no.</label>
                <input type="text" name="ppb_registration_no" class="form-control" maxlength="40">
                <span class="form-hint">What an inspector asks for.</span>
            </div>
        </div>

        <div class="form-grid-2" data-goods-only>
            <div class="form-group">
                <label class="checkbox">
                    <input type="checkbox" name="requires_rx">
                    <span>Prescription only</span>
                </label>
                <span class="form-hint">The counter will not sell this without a
                    prescription on the sale.</span>
            </div>
            <div class="form-group">
                <label class="form-label">Controlled class</label>
                <select name="controlled_schedule" class="form-control">
                    <option value="">&mdash; Not controlled &mdash;</option>
                    <?php foreach (CONTROLLED_SCHEDULES as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint">Narcotics and psychotropics go in the controlled
                    register, which is a legal obligation rather than a preference.</span>
            </div>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">SKU <span class="opt">(auto if blank)</span></label>
                <div class="input-affix">
                    <input type="text" name="sku" class="form-control" maxlength="100">
                    <button type="button" class="affix-btn" data-sku-generate
                            title="Generate a SKU from the name and category">Auto</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Barcode</label>
                <input type="text" name="barcode" class="form-control" maxlength="50">
            </div>
            <div class="form-group">
                <label class="form-label">Brand</label>
                <select name="brand_id" class="form-control" data-searchable="Search brands">
                    <option value="">— None —</option>
                    <?php foreach ($brands as $b): ?>
                        <option value="<?= (int) $b['brand_id'] ?>"><?= e($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Category</label>
                <select name="category_id" class="form-control" data-searchable="Search categories">
                    <option value="">— None —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['category_id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Supplier</label>
                <select name="supplier_id" class="form-control" data-searchable="Search suppliers">
                    <option value="">— None —</option>
                    <?php foreach ($suppliers as $sp): ?>
                        <option value="<?= (int) $sp['supplier_id'] ?>"><?= e($sp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Unit of measure</label>
                <select name="uom_id" class="form-control">
                    <option value="">— None —</option>
                    <?php foreach ($uoms as $u): ?>
                        <option value="<?= (int) $u['uom_id'] ?>"><?= e($u['name']) ?> (<?= e($u['abbreviation']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Description <span class="opt">(full product detail)</span></label>
            <textarea name="description" class="form-control" rows="3"></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Product images <span class="opt">(JPEG, PNG, WebP or GIF — up to 8, 5 MB each)</span></label>
            <input type="file" name="images[]" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
            <div class="field-note" data-image-note hidden></div>
        </div>

        <div class="form-grid-3">
            <div class="form-group">
                <label class="form-label">Cost price (KES) <span class="req">*</span></label>
                <input type="number" name="cost_price" class="form-control" min="0" step="0.01" required>
                <?php // Typed once to open the product; from the first goods
                      // received note onward it is worked out from what was
                      // actually paid, so an edit here will not survive the
                      // next receipt. Saying so beats somebody typing it
                      // back every month and wondering why it moves. ?>
                <span class="form-hint">Set for opening stock. Once goods are
                    received it follows what you paid.</span>
            </div>
            <div class="form-group">
                <label class="form-label">Selling price (KES) <span class="req">*</span></label>
                <input type="number" name="selling_price" class="form-control" min="0" step="0.01" required>
            </div>
            <div class="form-group">
                <label class="form-label">Discount price <span class="opt">(on sale)</span></label>
                <input type="number" name="discount_price" class="form-control" min="0" step="0.01">
            </div>
        </div>

        <div class="margin-readout" data-margin hidden>
            <span class="margin-label">Profit per unit</span>
            <strong class="margin-value" data-margin-value>—</strong>
            <span class="margin-pill" data-margin-percent>—</span>
        </div>

        <div class="form-grid-2" data-goods-only>
            <div class="form-group">
                <label class="form-label">Stock quantity <span class="req">*</span></label>
                <input type="number" name="stock_quantity" class="form-control" min="0" step="0.001" required>
            </div>
            <div class="form-group">
                <label class="form-label">Low-stock alert at <span class="req">*</span></label>
                <input type="number" name="low_quantity_threshold" class="form-control" min="0" step="0.001" required>
            </div>
        </div>

        <p class="form-hint" data-service-only hidden>
            A service holds no stock and is never counted, valued or put on a
            delivery note. It goes on a quote and an invoice like anything else,
            and the cost price above is what it costs you to provide — the
            fitter's day, the fuel — so the margin on the job is real.
        </p>

        <div class="form-group">
            <label class="checkbox">
                <input type="checkbox" name="is_active">
                <span>Active (visible and sellable internally)</span>
            </label>
        </div>

        <div class="form-divider"><span>Website / e-commerce</span></div>

        <div class="form-group">
            <label class="form-label">Short description <span class="opt">(storefront card / meta)</span></label>
            <textarea name="short_description" class="form-control" rows="2" maxlength="500"></textarea>
        </div>

        <div class="form-grid-3">
            <div class="form-group" data-goods-only>
                <?php // Nothing to weigh, and nothing to ship. ?>
                <label class="form-label">Weight (kg) <span class="opt">(shipping)</span></label>
                <input type="number" name="weight_kg" class="form-control" min="0" step="0.001">
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;gap:16px;">
                <label class="checkbox"><input type="checkbox" name="is_published"> <span>Publish to website</span></label>
                <label class="checkbox"><input type="checkbox" name="is_featured"> <span>Featured</span></label>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Specifications <span class="opt">(one per line, “Name: Value”)</span></label>
            <textarea name="specifications" class="form-control" rows="4"
                      placeholder="Resolution: 1920x1080 @ 30fps&#10;Lens: 2.8mm&#10;Power: PoE"></textarea>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">SEO title <span class="opt">(optional)</span></label>
                <input type="text" name="meta_title" class="form-control" maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label">SEO description <span class="opt">(optional)</span></label>
                <input type="text" name="meta_description" class="form-control" maxlength="500">
            </div>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save product</button>
        </div>
    </form>
</template>

<script nonce="<?= csp_nonce() ?>">
    // Product form helpers: a SKU suggestion and a live profit
    // readout. Both are conveniences — the server recomputes the SKU
    // when the field is left blank, and margin is never stored.
    (function () {
        const fmt = (n) => (Math.round(n * 100) / 100).toLocaleString('en-KE', {
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        });

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-sku-generate]');
            if (!btn) return;
            const form = btn.closest('form');
            const name = form.querySelector('[name="name"]').value.trim();
            if (!name) {
                window.App?.notify('Enter a product name first.', 'error');
                return;
            }
            const body = new FormData();
            body.append('_action', 'suggest_sku');
            body.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
            body.append('name', name);
            body.append('category_id', form.querySelector('[name="category_id"]')?.value || '');
            btn.disabled = true;
            fetch(form.action, { method: 'POST', body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then((r) => r.json())
                .then((d) => {
                    if (d.success) form.querySelector('[name="sku"]').value = d.sku;
                    else window.App?.notify(d.message || 'Could not generate a SKU.', 'error');
                })
                .catch(() => window.App?.notify('Could not generate a SKU.', 'error'))
                .finally(() => { btn.disabled = false; });
        });

        // Profit per unit, from cost against the price actually charged
        // (the discount price when one is set).
        function recalcMargin(form) {
            const box = form.querySelector('[data-margin]');
            if (!box) return;
            const cost = parseFloat(form.querySelector('[name="cost_price"]')?.value) || 0;
            const sell = parseFloat(form.querySelector('[name="selling_price"]')?.value) || 0;
            const disc = parseFloat(form.querySelector('[name="discount_price"]')?.value) || 0;
            const price = disc > 0 ? disc : sell;

            if (price <= 0 && cost <= 0) { box.hidden = true; return; }
            box.hidden = false;

            const value = price - cost;
            const pct = price > 0 ? (value / price) * 100 : null;
            box.querySelector('[data-margin-value]').textContent = 'KES ' + fmt(value)
                + (disc > 0 ? ' (at discount price)' : '');
            const pill = box.querySelector('[data-margin-percent]');
            pill.textContent = pct === null ? '—' : fmt(pct) + '%';
            box.classList.toggle('is-loss', value < 0);
        }

        document.addEventListener('input', (e) => {
            if (!e.target.matches('[name="cost_price"], [name="selling_price"], [name="discount_price"]')) return;
            recalcMargin(e.target.closest('form'));
        });
        // Also run when the modal opens with values already prefilled.
        document.addEventListener('click', (e) => {
            if (!e.target.closest('[data-modal-open="tplProductForm"]')) return;
            setTimeout(() => {
                document.querySelectorAll('.modal-overlay form').forEach((f) => {
                    recalcMargin(f);
                    applyType(f);
                });
            }, 0);
        });

        /* ── Product or service ──────────────────────────────
           A service has no stock, no reorder level and no
           shipping weight. Hiding those is not enough on its
           own: a hidden input still validates, and `required`
           on a hidden empty box blocks the submit with a
           message pointing at a field nobody can see. So the
           required flag comes off with the box. */
        function applyType(form) {
            if (!form) return;
            const picked = form.querySelector('[data-product-type]:checked');
            if (!picked) return;
            const isService = picked.value === 'service';

            form.querySelectorAll('[data-goods-only]').forEach((el) => {
                el.hidden = isService;
                el.querySelectorAll('input, select').forEach((f) => {
                    if (isService) {
                        f.dataset.wasRequired = f.required ? '1' : '';
                        f.required = false;
                        if (f.type === 'number' && f.value === '') f.value = '0';
                    } else if (f.dataset.wasRequired === '1') {
                        f.required = true;
                    }
                });
            });
            form.querySelectorAll('[data-service-only]').forEach((el) => { el.hidden = !isService; });

            const label = form.querySelector('[data-name-label]');
            if (label) label.textContent = isService ? 'Service' : 'Product';
        }

        document.addEventListener('change', (e) => {
            if (!e.target.matches('[data-product-type]')) return;
            applyType(e.target.closest('form'));
        });
    })();
</script>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
