<?php

/**
 * ============================================================
 *  Expense Management — List
 * ------------------------------------------------------------
 *  Filterable, searchable, server-side-paginated expense list
 *  with summary totals and modal-based add/edit (AJAX).
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('expenses', 'expense_categories');

$categories = db_all("SELECT category_id, name FROM expense_categories WHERE is_active = TRUE ORDER BY name");
$payAccounts = cash_liquid_accounts();
$supplierList = db_all("SELECT supplier_id, name FROM suppliers WHERE is_active = TRUE ORDER BY name");

// ── Filters ─────────────────────────────────────────────────
$search   = input($_GET, 'q');
$catId    = input_int($_GET, 'category');
$status   = input($_GET, 'status');
$dateFrom = input($_GET, 'from');
$dateTo   = input($_GET, 'to');
$focus    = input_int($_GET, 'focus');
$perPage  = per_page();

$where  = [];
$params = [];
// "Show me this one" — how the cash book links back to the expense
// behind a line. Every other filter still applies, and Clear gets
// out of it.
if ($focus !== null) {
    $where[] = "e.expense_id = :focus";
    $params[':focus'] = $focus;
}
if ($search !== '') {
    $where[] = "(LOWER(e.description) LIKE :q OR LOWER(e.receipt_reference) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($catId !== null) {
    $where[] = "e.category_id = :cat";
    $params[':cat'] = $catId;
}
if (in_array($status, ['recorded', 'approved', 'rejected'], true)) {
    $where[] = "e.status = :status";
    $params[':status'] = $status;
}
if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = "e.expense_date >= :from";
    $params[':from'] = $dateFrom;
}
if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = "e.expense_date <= :to";
    $params[':to'] = $dateTo;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Export ──────────────────────────────────────────────────
// Same WHERE as the list, without the page limit: what comes down
// is exactly what the filters describe, not the ten rows on screen.
if (wants_export()) {
    $exportRows = db_all(
        "SELECT e.expense_date, c.name AS category_name, e.description, e.receipt_reference,
                e.status, e.amount, a.name AS account_name, e.paid_on, e.payment_method,
                s.name AS supplier_name,
                TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS created_by_name
           FROM expenses e
           JOIN expense_categories c ON c.category_id = e.category_id
           LEFT JOIN cash_accounts a ON a.cash_account_id = e.cash_account_id
           LEFT JOIN suppliers s ON s.supplier_id = e.supplier_id
           LEFT JOIN users u ON u.user_id = e.created_by
           $whereSql
          ORDER BY e.expense_date DESC, e.expense_id DESC",
        $params
    );

    // A second sheet totalling by category: the question anybody
    // opening a year of expenses in Excel asks first.
    $byCategory = db_all(
        "SELECT c.name AS category_name, COUNT(*) AS entries, COALESCE(SUM(e.amount), 0) AS amount
           FROM expenses e
           JOIN expense_categories c ON c.category_id = e.category_id
           $whereSql
          GROUP BY c.name
          ORDER BY amount DESC",
        $params
    );

    export_deliver(export_filename('expenses'), [
        [
            'name'  => 'Expenses',
            'title' => 'Expenses',
            'meta'  => export_meta([
                'Period'   => ($dateFrom !== '' || $dateTo !== '')
                    ? (($dateFrom ?: 'the beginning') . ' to ' . ($dateTo ?: 'today'))
                    : 'all dates',
                'Category' => $catId !== null
                    ? (string) db_value("SELECT name FROM expense_categories WHERE category_id = :c", [':c' => $catId])
                    : 'all',
                'Status'   => $status !== '' ? ucfirst($status) : 'any',
                'Search'   => $search,
            ]),
            'columns' => [
                ['label' => 'Date',        'key' => 'expense_date',      'type' => 'date'],
                ['label' => 'Category',    'key' => 'category_name'],
                ['label' => 'Description', 'key' => 'description'],
                ['label' => 'Receipt ref', 'key' => 'receipt_reference'],
                ['label' => 'Supplier',    'key' => 'supplier_name'],
                ['label' => 'Status',      'key' => 'status', 'value' => fn($r) => ucfirst((string) $r['status'])],
                ['label' => 'Amount',      'key' => 'amount',            'type' => 'money'],
                ['label' => 'Paid from',   'key' => 'account_name'],
                ['label' => 'Paid on',     'key' => 'paid_on',           'type' => 'date'],
                ['label' => 'Method',      'key' => 'payment_method',
                 'value' => fn($r) => $r['payment_method'] ? payment_method_label($r['payment_method']) : ''],
                ['label' => 'Recorded by', 'key' => 'created_by_name'],
            ],
            'rows'  => $exportRows,
            'total' => true,
        ],
        [
            'name'    => 'By category',
            'columns' => [
                ['label' => 'Category', 'key' => 'category_name'],
                ['label' => 'Entries',  'key' => 'entries', 'type' => 'integer'],
                ['label' => 'Amount',   'key' => 'amount',  'type' => 'money'],
            ],
            'rows'  => $byCategory,
            'total' => true,
        ],
    ]);
}

// ── Pagination + data ───────────────────────────────────────
$total = (int) db_value("SELECT COUNT(*) FROM expenses e $whereSql", $params);
$pg    = paginate($total, $perPage);

$expenses = db_all(
    "SELECT e.*, c.name AS category_name, a.name AS account_name,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS created_by_name
     FROM expenses e
     JOIN expense_categories c ON c.category_id = e.category_id
     LEFT JOIN cash_accounts a ON a.cash_account_id = e.cash_account_id
     LEFT JOIN users u ON u.user_id = e.created_by
     $whereSql
     ORDER BY e.expense_date DESC, e.expense_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// ── Summary totals (respect current filters + fixed windows) ─
$filteredTotal = (float) db_value("SELECT COALESCE(SUM(e.amount),0) FROM expenses e $whereSql", $params);
$todayTotal    = (float) db_value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date = CURRENT_DATE");
$monthTotal    = (float) db_value("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE date_trunc('month', expense_date) = date_trunc('month', CURRENT_DATE)");

$pageTitle    = 'Expenses';
$pageSubtitle = 'Track and manage business expenses';
$pageStyles   = ['forms.css', 'dashboard.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Expenses']];

require __DIR__ . '/../../includes/header.php';

// Query string that preserves filters across pagination links.
$baseQuery = array_filter([
    'q' => $search, 'category' => $catId, 'status' => $status,
    'from' => $dateFrom, 'to' => $dateTo, 'focus' => $focus,
    'per_page' => $perPage !== 15 ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<!-- Summary cards -->
<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Today's Expenses</span><span class="mini-value"><?= e(money($todayTotal)) ?></span></div>
    <div class="mini-card"><span class="mini-label">This Month</span><span class="mini-value"><?= e(money($monthTotal)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Filtered Total</span><span class="mini-value"><?= e(money($filteredTotal)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Records</span><span class="mini-value"><?= e(num($total)) ?></span></div>
</section>

<!-- Toolbar: filters + add -->
<form class="toolbar" method="GET" action="<?= e(url('expenses/index.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Search description or receipt ref…">
        <select name="category" class="form-control toolbar-select" data-autosubmit data-searchable="Search categories">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['category_id'] ?>" <?= $catId === (int) $c['category_id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach (['recorded', 'approved', 'rejected'] as $st): ?>
                <option value="<?= $st ?>" <?= $status === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= e($dateFrom) ?>" class="form-control toolbar-select" data-autosubmit title="From date">
        <input type="date" name="to" value="<?= e($dateTo) ?>" class="form-control toolbar-select" data-autosubmit title="To date">
        <?= toolbar_apply('expenses/index.php') ?>
    </div>
    <div class="toolbar-actions">
        <?= export_button('expenses/index.php', $baseQuery) ?>
        <button type="button" class="btn btn-primary" data-modal-open="tplExpenseForm" data-modal-title="Add Expense" data-modal-auto>
            <?= icon('plus') ?> Add Expense
        </button>
    </div>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table" id="expensesTable">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Receipt Ref</th>
                    <th>Recorded By</th>
                    <th>Status</th>
                    <th>Paid from</th>
                    <th class="ta-right">Amount</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$expenses): ?>
                    <tr><td colspan="9" class="table-empty">No expenses found for these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($expenses as $x):
                    $prefill = json_encode([
                        'expense_id'        => $x['expense_id'],
                        'category_id'       => $x['category_id'],
                        'expense_date'      => $x['expense_date'],
                        'amount'            => $x['amount'],
                        'description'       => $x['description'],
                        'receipt_reference' => $x['receipt_reference'],
                        'status'            => $x['status'],
                        'cash_account_id'   => $x['cash_account_id'],
                        'paid_on'           => $x['paid_on'],
                        'payment_method'    => $x['payment_method'],
                        'supplier_id'       => $x['supplier_id'],
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td class="cell-muted"><?= e(fmt_date($x['expense_date'])) ?></td>
                        <td><span class="role-pill"><?= e($x['category_name']) ?></span></td>
                        <td><?= e($x['description'] ?: '—') ?></td>
                        <td class="cell-muted"><?= e($x['receipt_reference'] ?: '—') ?></td>
                        <td class="cell-muted"><?= e($x['created_by_name'] ?: '—') ?></td>
                        <td><span class="badge badge--<?= $x['status'] === 'approved' ? 'active' : ($x['status'] === 'rejected' ? 'inactive' : 'pending') ?>"><?= e(ucfirst($x['status'])) ?></span></td>
                        <td>
                            <?php if ($x['cash_account_id']): ?>
                                <span class="cell-title"><?= e($x['account_name']) ?></span>
                                <span class="cell-sub"><?= e(fmt_date($x['paid_on'])) ?></span>
                            <?php else: ?>
                                <span class="badge badge--pending">Not yet paid</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><strong><?= e(money($x['amount'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplExpenseForm" data-modal-title="Edit Expense"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <a class="icon-btn" title="Print voucher" target="_blank" rel="noopener"
                                   href="<?= e(url('expenses/pdf.php?id=' . (int) $x['expense_id'])) ?>"><?= icon('file') ?></a>
                                <a class="icon-btn" title="Download voucher"
                                   href="<?= e(url('expenses/pdf.php?id=' . (int) $x['expense_id'] . '&dl=1')) ?>"><?= icon('download') ?></a>
                                <?php if (is_admin()): ?>
                                    <form method="POST" action="<?= e(url('expenses/delete.php')) ?>" class="inline-form"
                                          data-confirm="Delete this expense of <?= e(money($x['amount'])) ?>? This cannot be undone.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $x['expense_id'] ?>">
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

    <?php pagination_nav($pg, $baseQuery, 'expense'); ?>
</div>

<!-- Reusable add/edit modal template -->
<template id="tplExpenseForm">
    <form method="POST" action="<?= e(url('expenses/save.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="expense_id" value="">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Category <span class="req">*</span></label>
                <select name="category_id" class="form-control" required data-searchable="Search categories">
                    <option value="">— Select —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['category_id'] ?>"><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Date <span class="req">*</span></label>
                <input type="date" name="expense_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Amount (KES) <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label class="form-label">Receipt reference <span class="opt">(optional)</span></label>
                <input type="text" name="receipt_reference" class="form-control" maxlength="100" placeholder="e.g. RCT-00123">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Description / notes</label>
            <textarea name="description" class="form-control" rows="3" placeholder="What was this expense for?"></textarea>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control">
                    <option value="recorded">Recorded</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Supplier <span class="opt">(optional)</span></label>
                <select name="supplier_id" class="form-control" data-searchable="Search suppliers">
                    <option value="">— None —</option>
                    <?php foreach ($supplierList as $sp): ?>
                        <option value="<?= (int) $sp['supplier_id'] ?>"><?= e($sp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <?php // Recording an expense and paying it are two different
              // events. Leave the account blank for a bill that is real
              // but not yet settled; fill it in and the cash book moves. ?>
        <fieldset class="form-fieldset">
            <legend class="form-legend">Payment <span class="opt">(leave blank if not yet paid)</span></legend>
            <div class="form-grid-3">
                <div class="form-group">
                    <label class="form-label">Paid from</label>
                    <select name="cash_account_id" class="form-control" data-searchable="Search accounts">
                        <option value="">— Not paid yet —</option>
                        <?php foreach ($payAccounts as $a): ?>
                            <option value="<?= (int) $a['cash_account_id'] ?>"><?= e($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Payment date</label>
                    <input type="date" name="paid_on" class="form-control" max="<?= e(date('Y-m-d')) ?>">
                    <p class="form-hint">Defaults to the expense date.</p>
                </div>
                <div class="form-group">
                    <label class="form-label">Method</label>
                    <select name="payment_method" class="form-control">
                        <?php foreach (PAYMENT_METHODS as $key => $label): ?>
                            <?php if ($key === 'salary_deduction') { continue; } ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p class="form-hint">
                Choosing an account writes the movement straight into the cash
                book, under <strong>Operating expenses</strong> on the daily
                position. Clearing it takes the entry away again.
            </p>
        </fieldset>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save expense</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
