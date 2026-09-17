<?php

/**
 * ============================================================
 *  Product card — one item's whole life
 * ------------------------------------------------------------
 *  Everything already recorded about one product, in the order
 *  somebody standing over a box actually asks for it:
 *
 *      Where did this last come from, and what did it cost?
 *      Who bought one, and when?
 *      Has this line ever made us any money?
 *      Show me every movement, in and out.
 *
 *  None of this is new data. It is in grn_items, invoice_items
 *  and inventory_transactions, and until now it could only be
 *  read one document at a time — which for a question like "who
 *  has one of these" is the same as not having it.
 *
 *  Three tables page independently (p_page, s_page, m_page) so
 *  paging the sales list does not lose your place in the
 *  movements below it.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/icons.php';
require_once __DIR__ . '/../../../includes/workflow.php';   // status_label / status_tone
require_once __DIR__ . '/../../../includes/product_ledger.php';
require_login();
require_tables('products', 'invoice_items');

$id      = input_int($_GET, 'id') ?? 0;
$product = $id > 0 ? ledger_product($id) : null;

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product';
    require __DIR__ . '/../../../includes/header.php';
    echo '<div class="state-block"><h1 class="state-title">No such product</h1>'
       . '<p class="state-text">It may have been deleted, or the link is wrong.</p>'
       . '<a href="' . e(url('modules/inventory/view_products.php')) . '" class="btn btn-primary">'
       . 'All products</a></div>';
    require __DIR__ . '/../../../includes/footer.php';
    exit;
}

/*  Money can be read over a window or over everything. Everything is
 *  the default: "has this line ever made money" is the question, and
 *  a period on it is the follow-up. */
$from = input($_GET, 'from');
$to   = input($_GET, 'to');
$isDate = static fn(string $d): bool =>
    (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = $isDate($from) ? $from : '';
$to   = $isDate($to) ? $to : '';
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}
$windowed = $from !== '' && $to !== '';

$money     = ledger_money($id, $windowed ? $from : null, $windowed ? $to : null);
$headlines = ledger_headlines($id);

$perPage = per_page();
$pgP = paginate(ledger_purchase_count($id), $perPage, (int) ($_GET['p_page'] ?? 1));
$pgS = paginate(ledger_sale_count($id),     $perPage, (int) ($_GET['s_page'] ?? 1));
$pgM = paginate(ledger_movement_count($id), $perPage, (int) ($_GET['m_page'] ?? 1));

$purchases = ledger_purchases($id, $pgP['per_page'], $pgP['offset']);
$sales     = ledger_sales($id,     $pgS['per_page'], $pgS['offset']);
$movements = ledger_movements($id, $pgM['per_page'], $pgM['offset']);

$stockValue = (float) $product['stock_quantity'] * (float) $product['cost_price'];
$isService  = ($product['product_type'] ?? 'goods') === 'service';

$pageTitle    = $product['name'];
$pageSubtitle = trim(($product['sku'] ? $product['sku'] . ' · ' : '')
    . ($product['category_name'] ?: 'Uncategorised'));
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Products', 'href' => 'modules/inventory/view_products.php'],
                 ['label' => $product['name']]];

require __DIR__ . '/../../../includes/header.php';

$baseQuery = array_filter([
    'id' => $id, 'from' => $from, 'to' => $to,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], static fn($v) => $v !== null && $v !== '');
?>

<div class="toolbar">
    <a href="<?= e(url('modules/inventory/view_products.php')) ?>" class="btn btn-ghost">&larr; All products</a>
    <form method="GET" class="u-flex u-gap" action="<?= e(url('modules/inventory/product_card.php')) ?>">
        <input type="hidden" name="id" value="<?= (int) $id ?>">
        <input type="date" name="from" value="<?= e($from) ?>" class="form-control" aria-label="Money from">
        <input type="date" name="to" value="<?= e($to) ?>" class="form-control" aria-label="Money to">
        <button type="submit" class="btn btn-primary"><?= icon('search') ?> Period</button>
        <?php if ($windowed): ?>
            <a href="<?= e(url('modules/inventory/product_card.php?id=' . $id)) ?>"
               class="btn btn-ghost"><?= icon('x') ?> All time</a>
        <?php endif; ?>
    </form>
</div>

<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">In stock</span>
        <span class="mini-value <?= (float) $product['stock_quantity'] <= 0 ? 'tone-out' : '' ?>">
            <?= $isService ? '—' : e(num($product['stock_quantity'], 2)) ?>
        </span>
        <span class="mini-note">
            <?php if ($isService): ?>
                a service is not held in stock
            <?php else: ?>
                reorder at <?= e(num($product['low_quantity_threshold'], 2)) ?>
            <?php endif; ?>
        </span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Stock value</span>
        <span class="mini-value"><?= $isService ? '—' : e(money($stockValue)) ?></span>
        <span class="mini-note">at <?= e(money($product['cost_price'])) ?> cost</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Units sold</span>
        <span class="mini-value"><?= e(num($money['units_sold'], 2)) ?></span>
        <span class="mini-note">
            to <?= e(num($money['customers'])) ?> customer<?= $money['customers'] === 1 ? '' : 's' ?>
        </span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Profit<?= $windowed ? ' in period' : '' ?></span>
        <span class="mini-value <?= $money['profit'] >= 0 ? 'tone-in' : 'tone-out' ?>">
            <?= e(money($money['profit'])) ?>
        </span>
        <span class="mini-note">
            <?= $money['margin_pct'] === null ? 'no revenue yet'
                : e(num($money['margin_pct'], 1)) . '% margin' ?>
        </span>
    </div>
</section>

<?php /* The two questions the page exists for, answered before any
         table: where did the last one come from, and where did the
         last one go. */ ?>
<div class="panel">
    <div class="panel-head"><h2 class="panel-title">Where it came from, where it went</h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <tbody>
                <tr>
                    <td style="width:150px;"><span class="cell-title">Last received</span></td>
                    <td>
                        <?php if ($r = $headlines['last_receipt']): ?>
                            <strong><?= e(num($r['quantity_received'], 2)) ?></strong>
                            <?= e($product['uom'] ?: 'units') ?>
                            from
                            <?php if ($r['supplier_id']): ?>
                                <a class="panel-link"
                                   href="<?= e(url('modules/purchasing/view_suppliers.php?q=' . urlencode((string) $r['supplier_name']))) ?>"><?= e($r['supplier_name']) ?></a>
                            <?php else: ?>
                                <?= e($r['supplier_name'] ?: 'an unnamed supplier') ?>
                            <?php endif; ?>
                            at <strong><?= e(money($r['unit_cost'])) ?></strong> each
                            &mdash; <?= e(money($r['subtotal'])) ?> the lot
                            <span class="cell-sub">
                                <?= e(fmt_date($r['receipt_date'])) ?> on <?= e($r['grn_number']) ?>
                                <?= $r['supplier_invoice_no']
                                    ? ' · their invoice ' . e($r['supplier_invoice_no']) : '' ?>
                            </span>
                        <?php else: ?>
                            <span class="cell-muted">Never received through a goods received note.</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td><span class="cell-title">Last sold</span></td>
                    <td>
                        <?php if ($s = $headlines['last_sale']): ?>
                            <strong><?= e(num($s['quantity'], 2)) ?></strong>
                            <?= e($product['uom'] ?: 'units') ?>
                            to
                            <a class="panel-link"
                               href="<?= e(url('modules/sales/list_customers.php?q=' . urlencode((string) $s['customer_name']))) ?>"><?= e($s['customer_name']) ?></a>
                            at <strong><?= e(money($s['unit_price'])) ?></strong> each
                            &mdash; <?= e(money($s['subtotal'])) ?> the lot
                            <span class="cell-sub">
                                <?= e(fmt_date($s['issue_date'])) ?> on
                                <a class="panel-link" href="<?= e(url('invoices/view.php?id=' . (int) $s['invoice_id'])) ?>"><?= e($s['invoice_number']) ?></a>
                            </span>
                        <?php else: ?>
                            <span class="cell-muted">Never invoiced.</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($product['supplier_name']): ?>
                    <tr>
                        <td><span class="cell-title">Usual supplier</span></td>
                        <td><?= e($product['supplier_name']) ?>
                            <span class="cell-sub">on the product record</span></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">What it has made<?= $windowed ? ' in this period' : '' ?></h2>
            <p class="panel-sub">
                <?= $windowed
                    ? e(fmt_date($from)) . ' to ' . e(fmt_date($to))
                    : 'every invoice ever raised for it' ?>
            </p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <tbody>
                <tr>
                    <td style="width:220px;">Revenue <span class="cell-sub">net of what came back, ex VAT</span></td>
                    <td class="ta-right"><strong><?= e(money($money['revenue'])) ?></strong></td>
                    <td style="width:220px;">Bought in <span class="cell-sub">received, not cancelled</span></td>
                    <td class="ta-right"><?= e(num($money['units_bought'], 2)) ?>
                        <span class="cell-sub"><?= e(money($money['spent'])) ?> spent</span></td>
                </tr>
                <tr>
                    <td>Cost of what was sold</td>
                    <td class="ta-right cell-muted"><?= e(money($money['cost'])) ?></td>
                    <td>Average bought at</td>
                    <td class="ta-right"><?= $money['avg_buy'] === null ? '—' : e(money($money['avg_buy'])) ?></td>
                </tr>
                <tr>
                    <td><strong>Gross profit</strong></td>
                    <td class="ta-right <?= $money['profit'] >= 0 ? 'tone-in' : 'tone-out' ?>">
                        <strong><?= e(money($money['profit'])) ?></strong>
                    </td>
                    <td>Average sold at</td>
                    <td class="ta-right"><?= $money['avg_sell'] === null ? '—' : e(money($money['avg_sell'])) ?></td>
                </tr>
                <tr>
                    <td>Margin</td>
                    <td class="ta-right"><?= $money['margin_pct'] === null
                        ? '<span class="cell-muted">—</span>'
                        : e(num($money['margin_pct'], 1)) . '%' ?></td>
                    <td>On</td>
                    <td class="ta-right"><?= e(num($money['invoices'])) ?>
                        invoice<?= $money['invoices'] === 1 ? '' : 's' ?></td>
                </tr>
            </tbody>
        </table>
    </div>

    <?php /* Said plainly rather than hidden in a footnote. A margin
             that mixes recorded cost with today's guess is worth
             less than one that admits which is which. */ ?>
    <?php if ($money['estimated_lines'] > 0): ?>
        <p class="form-hint u-pad">
            <?= e(num($money['estimated_lines'])) ?> of these sales were invoiced before
            the system began recording what goods cost on the day they sold, so their
            cost is taken from today's cost price
            (<?= e(money($product['cost_price'])) ?>) and moves when that is edited.
            Everything invoiced from now on carries its own cost and will not move.
        </p>
    <?php endif; ?>
</div>

<?php if (!$isService): ?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">Received</h2>
            <p class="panel-sub">every delivery in, and what it cost</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Note</th>
                    <th>Supplier</th>
                    <th class="ta-right">Quantity</th>
                    <th class="ta-right">Unit cost</th>
                    <th class="ta-right">Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$purchases): ?>
                    <tr><td colspan="6" class="table-empty">
                        Nothing has been received against this product yet.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($purchases as $r): ?>
                    <tr>
                        <td class="cell-muted"><?= e(fmt_date($r['receipt_date'])) ?></td>
                        <td>
                            <a class="cell-title panel-link"
                               href="<?= e(url('modules/purchasing/manage_grns.php?q=' . urlencode((string) $r['grn_number']))) ?>"><?= e($r['grn_number']) ?></a>
                            <?php if ($r['po_number']): ?>
                                <span class="cell-sub">on <?= e($r['po_number']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($r['supplier_name'] ?: '—') ?>
                            <?php if ($r['supplier_invoice_no']): ?>
                                <span class="cell-sub">invoice <?= e($r['supplier_invoice_no']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <?= e(num($r['quantity_received'], 2)) ?>
                            <?php if ((float) $r['quantity_rejected'] > 0.0005): ?>
                                <span class="cell-sub tone-out"><?= e(num($r['quantity_rejected'], 2)) ?> rejected</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><?= e(money($r['unit_cost'])) ?></td>
                        <td class="ta-right"><strong><?= e(money($r['subtotal'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php pagination_nav($pgP, $baseQuery, 'receipt', 'p_page'); ?>
</div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">Sold</h2>
            <p class="panel-sub">every invoice line, and who it went to</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th class="ta-right">Quantity</th>
                    <th class="ta-right">Price</th>
                    <th class="ta-right">Value</th>
                    <th class="ta-right">Margin</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$sales): ?>
                    <tr><td colspan="7" class="table-empty">
                        This product has never been invoiced.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($sales as $s): ?>
                    <tr>
                        <td class="cell-muted"><?= e(fmt_date($s['issue_date'])) ?></td>
                        <td>
                            <a class="cell-title panel-link"
                               href="<?= e(url('invoices/view.php?id=' . (int) $s['invoice_id'])) ?>"><?= e($s['invoice_number']) ?></a>
                            <span class="cell-sub"><?= e(status_label($s['status'])) ?></span>
                        </td>
                        <td><?= e($s['customer_name']) ?></td>
                        <td class="ta-right">
                            <?= e(num($s['quantity'], 2)) ?>
                            <?php if ((float) $s['credited_quantity'] > 0.0005): ?>
                                <span class="cell-sub tone-out"><?= e(num($s['credited_quantity'], 2)) ?> credited back</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <?= e(money($s['unit_price'])) ?>
                            <span class="cell-sub">cost <?= e(money($s['unit_cost'])) ?><?=
                                $s['cost_estimated'] === true || $s['cost_estimated'] === 't' ? '*' : '' ?></span>
                        </td>
                        <td class="ta-right"><strong><?= e(money($s['subtotal'])) ?></strong></td>
                        <td class="ta-right <?= (float) $s['margin'] >= 0 ? 'tone-in' : 'tone-out' ?>">
                            <?= e(money($s['margin'])) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($money['estimated_lines'] > 0): ?>
        <p class="form-hint u-pad">* cost taken from today's price, not recorded at the time.</p>
    <?php endif; ?>
    <?php pagination_nav($pgS, $baseQuery, 'sale', 's_page'); ?>
</div>

<?php if (!$isService): ?>
<div class="panel">
    <div class="panel-head">
        <div>
            <h2 class="panel-title">Every movement</h2>
            <p class="panel-sub">the stock ledger, newest first &mdash; what moved, why, and the balance after</p>
        </div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>What happened</th>
                    <th>Document</th>
                    <th>Who</th>
                    <th class="ta-right">Change</th>
                    <th class="ta-right">Balance after</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$movements): ?>
                    <tr><td colspan="6" class="table-empty">
                        No stock movement has been recorded for this product.
                        <?php if ((float) $product['stock_quantity'] > 0): ?>
                            The balance of <?= e(num($product['stock_quantity'], 2)) ?> was set
                            directly rather than moved in.
                        <?php endif; ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($movements as $m):
                    [$label, $dir] = ledger_movement_label((string) $m['movement_type']);
                    $qty = (float) $m['quantity'];
                    $url = ledger_movement_url($m);
                ?>
                    <tr>
                        <td class="cell-muted"><?= e(fmt_date($m['created_at'], 'd M Y H:i')) ?></td>
                        <td>
                            <span class="cell-title"><?= e($label) ?></span>
                            <?php if ($m['notes']): ?>
                                <span class="cell-sub"><?= e(mb_strimwidth((string) $m['notes'], 0, 70, '…')) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['reference_number'] && $url): ?>
                                <a class="panel-link" href="<?= e(url($url)) ?>"><?= e($m['reference_number']) ?></a>
                            <?php elseif ($m['reference_number']): ?>
                                <?= e($m['reference_number']) ?>
                            <?php else: ?>
                                <span class="cell-muted">&mdash;</span>
                            <?php endif; ?>
                            <?php if ($m['party']): ?>
                                <span class="cell-sub"><?= e($m['party']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="cell-muted"><?= e($m['by_name'] ?: '—') ?></td>
                        <td class="ta-right <?= $qty > 0 ? 'tone-in' : ($qty < 0 ? 'tone-out' : '') ?>">
                            <?= $qty > 0 ? '+' : '' ?><?= e(num($qty, 2)) ?>
                        </td>
                        <td class="ta-right">
                            <?= $m['balance_after'] === null
                                ? '<span class="cell-muted">—</span>'
                                : e(num($m['balance_after'], 2)) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php pagination_nav($pgM, $baseQuery, 'movement', 'm_page'); ?>
</div>
<?php endif; ?>


<?php require __DIR__ . '/../../../includes/footer.php'; ?>
