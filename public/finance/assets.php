<?php

/**
 * ============================================================
 *  Fixed Asset Register
 * ------------------------------------------------------------
 *  What the business owns and uses: desks, laptops, ladders,
 *  the van. Not stock — stock is bought to sell, and it has its
 *  own valuation. These are bought to work with.
 *
 *  Every other asset on the daily position was a current one,
 *  turning into cash within a year. These do not, and leaving
 *  them out understated the net asset value by whatever had
 *  been spent equipping the place.
 *
 *  ── Cost is not value ───────────────────────────────────────
 *  Each line is carried at cost less straight-line depreciation
 *  over its useful life, floored at whatever it is reckoned to
 *  be worth at the end of that life. So a register put in once
 *  goes on telling the truth without anybody maintaining it,
 *  and the position reads right on any date it is asked about.
 *
 *  Nothing here is stored twice: the depreciation is computed
 *  on the way past from cost, date, life and residual.
 *
 *  Managers and administrators. It moves the net asset value.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('fixed_assets');

/* ── AJAX: add or amend an asset ──────────────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['create', 'update'], true)) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $name     = input($_POST, 'name');
    $category = input($_POST, 'category');
    $bought   = input($_POST, 'purchase_date');
    $cost     = (float) ($_POST['cost'] ?? 0);
    $residual = (float) ($_POST['residual_value'] ?? 0);
    $life     = (int) ($_POST['useful_life_months'] ?? 0);

    if ($name === '') {
        json_response(['success' => false, 'message' => 'Give the item a name — "Reception desk", not "asset".']);
    }
    if (!fixed_asset_category_valid($category)) {
        json_response(['success' => false, 'message' => 'Choose what kind of thing it is.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bought)) {
        json_response(['success' => false, 'message' => 'When was it bought?']);
    }
    if ($bought > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'It cannot have been bought in the future.']);
    }
    if ($cost <= 0) {
        json_response(['success' => false, 'message' => 'What did it cost?']);
    }
    // Depreciating upwards is not a thing.
    if ($residual < 0 || $residual > $cost) {
        json_response(['success' => false, 'message' =>
            'What it will be worth at the end cannot be more than what it cost.']);
    }
    if ($life < 0 || $life > 1200) {
        json_response(['success' => false, 'message' => 'Give a working life between 0 and 100 years.']);
    }

    $fields = [
        ':name'     => $name,
        ':cat'      => $category,
        ':tag'      => input($_POST, 'asset_tag') ?: null,
        ':bought'   => $bought,
        ':cost'     => $cost,
        ':life'     => $life,
        ':residual' => $residual,
        ':serial'   => input($_POST, 'serial_number') ?: null,
        ':loc'      => input($_POST, 'location') ?: null,
        ':sup'      => input_int($_POST, 'supplier_id'),
        ':notes'    => input($_POST, 'notes') ?: null,
    ];

    try {
        if (($_POST['_action'] ?? '') === 'update') {
            $id = input_int($_POST, 'asset_id');
            if ($id === null || !db_value("SELECT 1 FROM fixed_assets WHERE asset_id = :id", [':id' => $id])) {
                json_response(['success' => false, 'message' => 'That asset no longer exists.']);
            }
            db_run(
                "UPDATE fixed_assets
                    SET name = :name, category = :cat, asset_tag = :tag,
                        purchase_date = :bought, cost = :cost,
                        useful_life_months = :life, residual_value = :residual,
                        serial_number = :serial, location = :loc,
                        supplier_id = :sup, notes = :notes
                  WHERE asset_id = :id",
                $fields + [':id' => $id]
            );
            audit_log('fixed_asset.update', 'fixed_assets', $id, ['name' => $name]);
            json_response(['success' => true, 'message' => $name . ' updated.']);
        }

        $id = (int) db_value(
            "INSERT INTO fixed_assets
                (name, category, asset_tag, purchase_date, cost,
                 useful_life_months, residual_value, serial_number, location,
                 supplier_id, notes, created_by)
             VALUES (:name, :cat, :tag, :bought, :cost,
                     :life, :residual, :serial, :loc, :sup, :notes, :by)
             RETURNING asset_id",
            $fields + [':by' => current_user()['id']]
        );
        audit_log('fixed_asset.create', 'fixed_assets', $id, ['name' => $name, 'cost' => $cost]);
        json_response(['success' => true, 'message' =>
            $name . ' added at ' . money($cost) . '.']);
    } catch (PDOException $ex) {
        json_response(['success' => false, 'message' =>
            db_rule_message($ex, 'Could not save that asset.')]);
    }
}

/* ── AJAX: it has gone ────────────────────────────────────── */
//  Sold, scrapped or stolen. The row stays: what the business
//  owned last March is a question somebody will ask.
if (is_post() && ($_POST['_action'] ?? '') === 'dispose') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $id  = input_int($_POST, 'asset_id');
    $row = $id !== null
        ? db_one("SELECT name, purchase_date, disposed_on FROM fixed_assets WHERE asset_id = :id", [':id' => $id])
        : null;
    if (!$row) {
        json_response(['success' => false, 'message' => 'That asset no longer exists.']);
    }

    $on = input($_POST, 'disposed_on') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) {
        json_response(['success' => false, 'message' => 'When did it go?']);
    }
    if ($on < $row['purchase_date']) {
        json_response(['success' => false, 'message' =>
            'It cannot have gone before it was bought (' . fmt_date($row['purchase_date']) . ').']);
    }
    if (input($_POST, 'disposal_reason') === '') {
        json_response(['success' => false, 'message' =>
            'Say what happened to it — sold, scrapped, stolen. An asset that just vanishes from a register is the thing an auditor asks about.']);
    }

    try {
        db_run(
            "UPDATE fixed_assets
                SET disposed_on = :on, disposal_amount = :amt, disposal_reason = :why
              WHERE asset_id = :id",
            [':on' => $on, ':amt' => (float) ($_POST['disposal_amount'] ?? 0),
             ':why' => input($_POST, 'disposal_reason'), ':id' => $id]
        );
        audit_log('fixed_asset.dispose', 'fixed_assets', $id,
                  ['name' => $row['name'], 'on' => $on]);
        json_response(['success' => true, 'message' =>
            $row['name'] . ' left the register on ' . fmt_date($on) . '.']);
    } catch (PDOException $ex) {
        json_response(['success' => false, 'message' =>
            db_rule_message($ex, 'Could not record that disposal.')]);
    }
}

/* ── AJAX: bring one back ─────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'undispose') {
    csrf_check();
    $id = input_int($_POST, 'asset_id');
    if ($id !== null) {
        db_run("UPDATE fixed_assets
                   SET disposed_on = NULL, disposal_amount = NULL, disposal_reason = NULL
                 WHERE asset_id = :id", [':id' => $id]);
        audit_log('fixed_asset.undispose', 'fixed_assets', $id);
        flash('success', 'Back on the register.');
    }
    redirect('finance/assets.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$asOf   = input($_GET, 'as_of');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $asOf)) {
    $asOf = date('Y-m-d');
}
$scope  = in_array(input($_GET, 'scope'), ['held', 'disposed', 'all'], true)
        ? input($_GET, 'scope') : 'held';
$search = input($_GET, 'q');

$rows   = fixed_asset_rows($asOf, $scope, $search);
$totals = fixed_asset_totals($rows);
$byCat  = fixed_assets_by_category($asOf);

[$pg, $pageRows] = paginate_rows($rows, per_page());
$baseQuery = array_filter([
    'as_of'    => $asOf !== date('Y-m-d') ? $asOf : null,
    'scope'    => $scope !== 'held' ? $scope : null,
    'q'        => $search,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');

if (wants_export()) {
    export_deliver(export_filename('fixed-assets'), [
        [
            'name'  => 'Register',
            'title' => 'Fixed asset register as at ' . fmt_date($asOf, 'd F Y'),
            'meta'  => export_meta([
                'As at'        => fmt_date($asOf, 'd F Y'),
                'Showing'      => ['held' => 'owned on this date', 'disposed' => 'already disposed of',
                                   'all' => 'everything ever recorded'][$scope],
                'At cost'      => money($totals['cost']),
                'Depreciation' => money($totals['depreciation']),
                'Book value'   => money($totals['book_value']),
                'Basis'        => 'Straight line over each item\'s useful life',
            ]),
            'columns' => [
                ['label' => 'Item',         'key' => 'name'],
                ['label' => 'Tag',          'key' => 'asset_tag'],
                ['label' => 'Category',     'key' => 'category'],
                ['label' => 'Bought',       'key' => 'purchase_date', 'type' => 'date'],
                ['label' => 'Cost',         'key' => 'cost',          'type' => 'money'],
                ['label' => 'Life (months)','key' => 'useful_life_months', 'type' => 'integer', 'total' => false],
                ['label' => 'Depreciation', 'key' => 'depreciation',  'type' => 'money'],
                ['label' => 'Book value',   'key' => 'book_value',    'type' => 'money'],
                ['label' => 'Where',        'key' => 'location'],
                ['label' => 'Serial',       'key' => 'serial_number'],
                ['label' => 'Disposed',     'key' => 'disposed_on',   'type' => 'date'],
            ],
            'rows'  => $rows,
            'total' => true,
        ],
        [
            'name'  => 'By category',
            'columns' => [
                ['label' => 'Category',     'key' => 'category'],
                ['label' => 'Items',        'key' => 'items',        'type' => 'integer'],
                ['label' => 'Cost',         'key' => 'cost',         'type' => 'money'],
                ['label' => 'Depreciation', 'key' => 'depreciation', 'type' => 'money'],
                ['label' => 'Book value',   'key' => 'book_value',   'type' => 'money'],
            ],
            'rows'  => $byCat,
            'total' => true,
        ],
    ]);
}


$pageTitle    = 'Fixed Assets';
$pageSubtitle = 'What the business owns and uses, as at ' . fmt_date($asOf, 'd F Y');
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Finance'], ['label' => 'Fixed Assets']];

require __DIR__ . '/../../includes/header.php';
?>

<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">Book value</span>
        <span class="mini-value"><?= e(money($totals['book_value'])) ?></span>
        <span class="mini-note">what the daily position carries</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Cost</span>
        <span class="mini-value"><?= e(money($totals['cost'])) ?></span>
        <span class="mini-note">what was paid for it all</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Written off so far</span>
        <span class="mini-value tone-out"><?= e(money($totals['depreciation'])) ?></span>
        <span class="mini-note"><?= $totals['cost'] > 0.005
            ? e(num($totals['depreciation'] / $totals['cost'] * 100, 1)) . '% of cost'
            : '—' ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Items</span>
        <span class="mini-value"><?= e(num($totals['items'])) ?></span>
        <span class="mini-note"><?= $scope === 'held' ? 'owned on this date'
            : ($scope === 'disposed' ? 'already gone' : 'ever recorded') ?></span>
    </div>
</section>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('finance/assets.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Search item, tag, serial or where it is…">
        <select name="scope" class="form-control toolbar-select" data-autosubmit>
            <option value="held"     <?= $scope === 'held' ? 'selected' : '' ?>>Owned</option>
            <option value="disposed" <?= $scope === 'disposed' ? 'selected' : '' ?>>Disposed of</option>
            <option value="all"      <?= $scope === 'all' ? 'selected' : '' ?>>Everything</option>
        </select>
        <input type="date" name="as_of" value="<?= e($asOf) ?>" class="form-control toolbar-select"
               data-autosubmit title="Value the register as at this date">
        <?= toolbar_apply('finance/assets.php') ?>
    </form>
    <div class="toolbar-actions">
        <?= export_button('finance/assets.php', $baseQuery) ?>
        <a class="btn btn-ghost" href="<?= e(url('finance/position.php?date=' . $asOf)) ?>"><?= icon('calendar') ?> Daily position</a>
        <button type="button" class="btn btn-primary"
                data-modal-open="tplAssetForm" data-modal-title="Add Fixed Asset" data-modal-auto>
            <?= icon('plus') ?> Add Asset
        </button>
    </div>
</div>

<p class="form-hint u-pad">
    <strong>Fixed assets</strong> are the things the business owns to work with rather
    than to sell — furniture, computers, tools, vehicles. Stock is not here: it is
    bought to sell and has its <a href="<?= e(url('reports/stock_value.php')) ?>">own
    valuation</a>. Each item is carried at <strong>cost less depreciation</strong>,
    written down evenly over the working life you give it, so the register keeps
    telling the truth without anybody maintaining it. The book value is what the
    <a href="<?= e(url('finance/position.php?date=' . $asOf)) ?>">daily position</a>
    adds to total assets.
</p>

<?php if ($byCat): ?>
    <div class="panel">
        <h2 class="panel-title u-pad">By category</h2>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th class="ta-right">Items</th>
                        <th class="ta-right">Cost</th>
                        <th class="ta-right">Written off</th>
                        <th class="ta-right">Book value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($byCat as $c): ?>
                        <tr>
                            <td><span class="cell-title"><?= e($c['category']) ?></span></td>
                            <td class="ta-right cell-muted"><?= e(num($c['items'])) ?></td>
                            <td class="ta-right cell-muted"><?= e(money($c['cost'])) ?></td>
                            <td class="ta-right tone-out"><?= e(money($c['depreciation'])) ?></td>
                            <td class="ta-right"><strong><?= e(money($c['book_value'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="2">All categories</th>
                        <td class="ta-right"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['cost'], $byCat)))) ?></strong></td>
                        <td class="ta-right"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['depreciation'], $byCat)))) ?></strong></td>
                        <td class="ta-right"><strong><?= e(money(array_sum(array_map(fn($c) => (float) $c['book_value'], $byCat)))) ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="panel">
    <h2 class="panel-title u-pad">The register</h2>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Bought</th>
                    <th class="ta-right">Cost</th>
                    <th class="ta-right">Written off</th>
                    <th class="ta-right">Book value</th>
                    <th>Life left</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="8" class="table-empty">
                        Nothing on the register yet. <strong>Add Asset</strong> puts the
                        desks, laptops and tools on the books.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($pageRows as $a):
                    $prefill = json_encode([
                        'asset_id'           => $a['asset_id'],
                        'name'               => $a['name'],
                        'category'           => $a['category'],
                        'asset_tag'          => $a['asset_tag'],
                        'purchase_date'      => $a['purchase_date'],
                        'cost'               => number_format((float) $a['cost'], 2, '.', ''),
                        'useful_life_months' => $a['useful_life_months'],
                        'residual_value'     => number_format((float) $a['residual_value'], 2, '.', ''),
                        'serial_number'      => $a['serial_number'],
                        'location'           => $a['location'],
                        'supplier_id'        => $a['supplier_id'],
                        'notes'              => $a['notes'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td>
                            <span class="cell-title"><?= e($a['name']) ?></span>
                            <?php // Only the parts there are. A leading em-dash
                                  // before a location reads as a missing field. ?>
                            <span class="cell-sub"><?= e(implode(' · ', array_filter([
                                $a['asset_tag'], $a['serial_number'], $a['location'],
                            ])) ?: '—') ?></span>
                        </td>
                        <td class="cell-muted"><?= e($a['category']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($a['purchase_date'])) ?></td>
                        <td class="ta-right cell-muted"><?= e(money($a['cost'])) ?></td>
                        <td class="ta-right tone-out"><?= e(money($a['depreciation'])) ?></td>
                        <td class="ta-right"><strong><?= e(money($a['book_value'])) ?></strong></td>
                        <td class="cell-muted">
                            <?php if ($a['disposed_on']): ?>
                                <span class="badge badge--inactive">Gone <?= e(fmt_date($a['disposed_on'])) ?></span>
                            <?php else: ?>
                                <?= e(fixed_asset_life_left($a, $asOf)) ?>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <?php if (!$a['disposed_on']): ?>
                                    <button type="button" class="icon-btn" title="Edit"
                                            data-modal-open="tplAssetForm"
                                            data-modal-title="Edit <?= e($a['name']) ?>"
                                            data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                    <button type="button" class="icon-btn" title="Sold, scrapped or stolen"
                                            data-modal-open="tplDisposeForm"
                                            data-modal-title="Dispose of <?= e($a['name']) ?>"
                                            data-prefill="<?= e(json_encode(['asset_id' => $a['asset_id']], JSON_HEX_APOS | JSON_HEX_QUOT)) ?>"><?= icon('out') ?></button>
                                <?php else: ?>
                                    <form method="POST" action="<?= e(url('finance/assets.php')) ?>" class="inline-form"
                                          data-confirm="Put <?= e($a['name']) ?> back on the register?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="undispose">
                                        <input type="hidden" name="asset_id" value="<?= (int) $a['asset_id'] ?>">
                                        <button type="submit" class="icon-btn" title="Put it back"><?= icon('undo') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if ($rows): ?>
                <tfoot>
                    <tr class="table-total">
                        <th colspan="3">Total <span class="cell-muted">every page</span></th>
                        <td class="ta-right"><strong><?= e(money($totals['cost'])) ?></strong></td>
                        <td class="ta-right"><strong><?= e(money($totals['depreciation'])) ?></strong></td>
                        <td class="ta-right"><strong><?= e(money($totals['book_value'])) ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
        <?php pagination_nav($pg, $baseQuery, 'asset'); ?>
    </div>
</div>

<p class="form-hint u-pad">
    <strong>Straight line</strong>: cost less what it will be worth at the end,
    divided evenly across the months of its working life. A laptop at
    <?= e(money(120000)) ?> over three years loses <?= e(money(120000 / 36)) ?> a
    month and reaches nil. Nothing is written below its end value, and
    <strong>land is never written down at all</strong>. Depreciation is not a
    payment and never touches the cash book — it is the cost of using a thing up,
    and it is recomputed from the dates every time this page is opened rather
    than being posted anywhere.
</p>

<!-- Add / edit -->
<template id="tplAssetForm">
    <form method="POST" action="<?= e(url('finance/assets.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="create">
        <input type="hidden" name="asset_id" value="">

        <div class="form-group">
            <label class="form-label">What is it <span class="req">*</span></label>
            <input type="text" name="name" class="form-control" required maxlength="200"
                   placeholder="Reception desk · Dell Latitude 5420 · Ladder 3m">
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Kind of thing <span class="req">*</span></label>
                <select name="category" class="form-control" required data-asset-category data-searchable="Search categories">
                    <?php foreach (FIXED_ASSET_CATEGORIES as $label => $c): ?>
                        <option value="<?= e($label) ?>" data-life="<?= (int) $c['months'] ?>"
                                <?= $label === 'Computers and IT' ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="form-hint" data-asset-hint><?= e(FIXED_ASSET_CATEGORIES['Computers and IT']['hint']) ?></span>
            </div>
            <div class="form-group">
                <label class="form-label">Bought on <span class="req">*</span></label>
                <input type="date" name="purchase_date" class="form-control" required
                       max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>">
                <span class="form-hint">Depreciation runs from here, not from today.</span>
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">What it cost <span class="req">*</span></label>
                <input type="number" name="cost" class="form-control ta-right" required
                       min="0.01" step="0.01" placeholder="0.00">
            </div>
            <div class="form-group">
                <label class="form-label">Worth at the end</label>
                <input type="number" name="residual_value" class="form-control ta-right"
                       min="0" step="0.01" value="0.00">
                <span class="form-hint">Scrap or trade-in value. Leave at nil if it will be worth nothing.</span>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Working life <span class="req">*</span></label>
            <div class="form-inline">
                <input type="number" name="useful_life_months" class="form-control ta-right"
                       required min="0" max="1200" step="1" value="36" data-asset-life
                       style="max-width: 140px;">
                <span class="cell-muted">months</span>
            </div>
            <span class="form-hint">
                Prefilled from the kind of thing it is; change it if this one will
                last longer or shorter. <strong>Zero means never written down</strong> —
                land, and nothing else.
            </span>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Asset tag</label>
                <input type="text" name="asset_tag" class="form-control" maxlength="40"
                       placeholder="The sticker on it, if you use them">
            </div>
            <div class="form-group">
                <label class="form-label">Serial number</label>
                <input type="text" name="serial_number" class="form-control" maxlength="120">
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Where it is</label>
                <input type="text" name="location" class="form-control" maxlength="120"
                       placeholder="Front office · Workshop · Van">
            </div>
            <div class="form-group">
                <label class="form-label">Bought from</label>
                <?php lookup_select('suppliers', 'supplier_id', null, [
                    'prompt' => '— Not recorded —',
                    'label'  => 'Search suppliers',
                    'empty'  => 'No supplier matches that.',
                ]); ?>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save asset</button>
        </div>
    </form>
</template>

<!-- Disposal -->
<template id="tplDisposeForm">
    <form method="POST" action="<?= e(url('finance/assets.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="dispose">
        <input type="hidden" name="asset_id" value="">

        <p class="form-hint">
            It stops counting towards the business's assets from this date. The record
            stays — what was owned last March is a question somebody will ask.
        </p>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Gone on <span class="req">*</span></label>
                <input type="date" name="disposed_on" class="form-control" required
                       value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">What it fetched</label>
                <input type="number" name="disposal_amount" class="form-control ta-right"
                       min="0" step="0.01" value="0.00">
                <span class="form-hint">Nil for scrapped or stolen. Record any money received in the cash book too.</span>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">What happened <span class="req">*</span></label>
            <textarea name="disposal_reason" class="form-control" rows="2" required
                      placeholder="Sold to staff · Beyond repair, scrapped · Stolen, reported"></textarea>
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record disposal</button>
        </div>
    </form>
</template>

<script nonce="<?= csp_nonce() ?>">
    // The working life follows the kind of thing, until somebody
    // types over it — at which point it is theirs and we stop
    // moving it. Bound here rather than with onchange=: the CSP
    // allows script from 'self' and this nonce and nothing inline.
    (function () {
        const HINTS = <?= json_encode(array_map(fn($c) => $c['hint'], FIXED_ASSET_CATEGORIES),
                                      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.addEventListener('change', (e) => {
            const cat = e.target.closest('[data-asset-category]');
            if (!cat) return;
            const form = cat.closest('form');
            const life = form.querySelector('[data-asset-life]');
            const hint = form.querySelector('[data-asset-hint]');
            const opt  = cat.selectedOptions[0];
            if (hint) hint.textContent = HINTS[cat.value] || '';
            if (life && opt && !life.dataset.touched) life.value = opt.dataset.life;
        });
        document.addEventListener('input', (e) => {
            const life = e.target.closest('[data-asset-life]');
            if (life) life.dataset.touched = '1';
        });
    })();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
