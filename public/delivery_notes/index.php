<?php

/**
 * ============================================================
 *  Delivery Notes — List
 * ------------------------------------------------------------
 *  Every delivery note with its source document, delivery date
 *  and status. Filterable by status, date range, customer and
 *  free text so the dashboard's operational widgets can each
 *  open the list that explains them.
 *
 *  Delivery notes are raised from an accepted quote or a
 *  confirmed sales order — see create.php.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_once __DIR__ . '/../../includes/workflow.php';
require_tables('delivery_notes', 'delivery_note_items');

$search  = input($_GET, 'q');
$status  = input($_GET, 'status');
$from    = input($_GET, 'from');
$to      = input($_GET, 'to');
$custId  = input_int($_GET, 'customer_id');
$fulfil  = input($_GET, 'fulfilment');
$perPage = per_page();

$isDate = static fn(string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from   = $isDate($from) ? $from : '';
$to     = $isDate($to) ? $to : '';
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(dn.dn_number) LIKE :q
                 OR LOWER(COALESCE(c.company_name,'')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q
                 OR LOWER(COALESCE(so.order_number,'')) LIKE :q
                 OR LOWER(COALESCE(q.quote_number,'')) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if (in_array($status, DN_STATUSES, true)) {
    $where[] = "dn.status = :status";
    $params[':status'] = $status;
} else {
    $status = '';
}
if ($from !== '') {
    $where[] = "dn.delivery_date >= :from";
    $params[':from'] = $from;
}
if ($to !== '') {
    $where[] = "dn.delivery_date <= :to";
    $params[':to'] = $to;
}
if ($custId !== null) {
    $where[] = "dn.customer_id = :cust";
    $params[':cust'] = $custId;
}
if (in_array($fulfil, DELIVERY_STATES, true)) {
    // Filter on what the user actually sees: the manual mark when one
    // is set, otherwise the value computed from the quantities.
    $where[] = "COALESCE(dn.delivery_status_override, dn.delivery_status) = :fulfil";
    $params[':fulfil'] = $fulfil;
} else {
    $fulfil = '';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$joins = "FROM delivery_notes dn
          LEFT JOIN customers c    ON c.customer_id = dn.customer_id
          LEFT JOIN sales_orders so ON so.sales_order_id = dn.sales_order_id
          LEFT JOIN quotes q       ON q.quote_id = dn.quote_id
          LEFT JOIN invoices i     ON i.invoice_id = dn.invoice_id";

$total = (int) db_value("SELECT COUNT(*) $joins $whereSql", $params);
$pg    = paginate($total, $perPage);

$notes = db_all(
    "SELECT dn.*, so.order_number, q.quote_number, i.invoice_number,
            " . customer_name_sql('c') . " AS customer,
            (SELECT COALESCE(SUM(quantity),0) FROM delivery_note_items WHERE dn_id = dn.dn_id) AS units,
            (SELECT COUNT(*) FROM delivery_note_items WHERE dn_id = dn.dn_id) AS line_count,
            COALESCE(dn.delivery_status_override, dn.delivery_status) AS fulfilment,
            (dn.delivery_status_override IS NOT NULL) AS fulfilment_marked
     $joins
     $whereSql
     ORDER BY dn.delivery_date DESC, dn.dn_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$filtersActive = $search !== '' || $status !== '' || $fulfil !== ''
    || $from !== '' || $to !== '' || $custId !== null;

$pageTitle    = 'Delivery Notes';
$pageSubtitle = 'Goods dispatched to customers';
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Delivery Notes']];

require __DIR__ . '/../../includes/header.php';
$baseQuery = array_filter([
    'q' => $search, 'status' => $status, 'fulfilment' => $fulfil,
    'from' => $from, 'to' => $to, 'customer_id' => $custId,
], fn($v) => $v !== '' && $v !== null);
?>

<form class="filter-bar" method="GET" action="<?= e(url('delivery_notes/index.php')) ?>">
    <div class="filter-row">
        <div class="toolbar-search filter-grow">
            <?= icon('search', 'toolbar-search-ic') ?>
            <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
                   placeholder="Search note, customer, order or quote…">
        </div>
        <a href="<?= e(url('delivery_notes/create.php')) ?>" class="btn btn-primary"><?= icon('plus') ?> New Delivery Note</a>
    </div>

    <div class="filter-row filter-row--fields">
        <label class="filter-field">
            <span class="filter-label">From</span>
            <input type="date" name="from" value="<?= e($from) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">To</span>
            <input type="date" name="to" value="<?= e($to) ?>" class="form-control">
        </label>
        <label class="filter-field">
            <span class="filter-label">Customer</span>
            <?php lookup_select('delivery_customers', 'customer_id', $custId, [
                'prompt' => 'All customers',
                'label'  => 'Search customers',
                'empty'  => 'No customer matches that.',
            ]); ?>
        </label>
        <label class="filter-field">
            <span class="filter-label">Status</span>
            <select name="status" class="form-control">
                <option value="">Any status</option>
                <?php foreach (DN_STATUSES as $st): ?>
                    <option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= e(status_label($st)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="filter-field">
            <span class="filter-label">Fulfilment</span>
            <select name="fulfilment" class="form-control">
                <option value="">Any fulfilment</option>
                <?php foreach (DELIVERY_STATES as $ds): ?>
                    <option value="<?= e($ds) ?>" <?= $fulfil === $ds ? 'selected' : '' ?>><?= e(status_label($ds)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary"><?= icon('search') ?> Filter</button>
            <?php if ($filtersActive): ?>
                <a href="<?= e(url('delivery_notes/index.php')) ?>" class="btn btn-ghost"><?= icon('x') ?> Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if ($filtersActive): ?>
    <p class="filter-summary"><strong><?= e(num($total)) ?></strong> delivery note<?= $total === 1 ? '' : 's' ?> matched</p>
<?php endif; ?>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Number</th>
                    <th>Customer</th>
                    <th>Source</th>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th class="ta-right">Units</th>
                    <th>Status</th>
                    <th>Fulfilment</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$notes): ?>
                    <tr><td colspan="9" class="table-empty">
                        No delivery notes yet. Raise one from an accepted quote or a confirmed sales order.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($notes as $dn): ?>
                    <tr>
                        <td><span class="cell-title"><?= e($dn['dn_number']) ?></span></td>
                        <td><?= e($dn['customer']) ?></td>
                        <td class="cell-muted">
                            <?php if ($dn['order_number']): ?>
                                <?= e($dn['order_number']) ?>
                            <?php elseif ($dn['quote_number']): ?>
                                <?= e($dn['quote_number']) ?>
                            <?php else: ?>&mdash;<?php endif; ?>
                        </td>
                        <td class="cell-muted">
                            <?php if ($dn['invoice_number']): ?>
                                <a class="panel-link" href="<?= e(url('invoices/view.php?id=' . (int) $dn['invoice_id'])) ?>"><?= e($dn['invoice_number']) ?></a>
                            <?php else: ?>&mdash;<?php endif; ?>
                        </td>
                        <td class="cell-muted"><?= e(fmt_date($dn['delivery_date'])) ?></td>
                        <td class="ta-right"><?= e(num($dn['units'], 0)) ?></td>
                        <td><span class="badge badge--<?= e(status_tone($dn['status'])) ?>"><?= e(status_label($dn['status'])) ?></span></td>
                        <td>
                            <span class="badge badge--<?= e(status_tone($dn['fulfilment'])) ?>"><?= e(status_label($dn['fulfilment'])) ?></span>
                            <?php if ($dn['fulfilment_marked']): ?>
                                <span class="cell-sub" title="Marked by hand rather than computed">marked</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a href="<?= e(url('delivery_notes/view.php?id=' . (int) $dn['dn_id'])) ?>"
                                   class="icon-btn" title="View"><?= icon('file') ?></a>
                                <a href="<?= e(url('delivery_notes/pdf.php?id=' . (int) $dn['dn_id'] . '&dl=1')) ?>"
                                   class="icon-btn" title="Download PDF"><?= icon('download') ?></a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'delivery note'); ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
