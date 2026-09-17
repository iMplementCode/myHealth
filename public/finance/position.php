<?php

/**
 * ============================================================
 *  Daily Position
 * ------------------------------------------------------------
 *  The end-of-day summary the business has kept by hand: what
 *  the company is worth, how the cash reconciles, and who is
 *  holding it. One block per day, newest first.
 *
 *  Every cash figure is derived from the cash book, so the
 *  previous balance is always the day before's closing balance —
 *  the carry-forward can no longer be mistyped.
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/export.php';
require_role(ROLE_MANAGER);
require_tables('cash_accounts', 'cash_transactions', 'daily_positions');

/* ── AJAX: close a day ──────────────────────────────────────
 *  Only inventory and trade payables are captured. Trade
 *  receivables are reconstructible from dated invoices and
 *  receipts, so they are computed rather than typed — and stored
 *  alongside only as a record of what the figure was.
 * ─────────────────────────────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'close') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $date = input($_POST, 'position_date');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid date.']);
    }
    if ($date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'That day has not happened yet.']);
    }

    foreach (['stock_value', 'creditors'] as $f) {
        if ((float) ($_POST[$f] ?? 0) < 0) {
            json_response(['success' => false, 'message' => 'Figures cannot be negative.']);
        }
    }

    db_run(
        "INSERT INTO daily_positions (position_date, stock_value, creditors, debtors, notes, closed_by, closed_at)
         VALUES (:d, :stock, :cred, :debt, :notes, :by, NOW())
         ON CONFLICT (position_date) DO UPDATE
            SET stock_value = EXCLUDED.stock_value,
                creditors   = EXCLUDED.creditors,
                debtors     = EXCLUDED.debtors,
                notes       = EXCLUDED.notes,
                closed_by   = EXCLUDED.closed_by,
                closed_at   = NOW()",
        [
            ':d'     => $date,
            ':stock' => (float) ($_POST['stock_value'] ?? 0),
            ':cred'  => (float) ($_POST['creditors'] ?? 0),
            ':debt'  => finance_trade_receivables($date),
            ':notes' => input($_POST, 'notes') ?: null,
            ':by'    => current_user()['id'],
        ]
    );
    audit_log('daily_position.close', 'daily_positions', 0, ['date' => $date]);
    json_response(['success' => true, 'message' => 'Position for ' . fmt_date($date) . ' saved.']);
}

/* ── POST: reopen a day (admins only) ───────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'reopen') {
    csrf_check();
    require_role(ROLE_ADMIN);
    $date = input($_POST, 'position_date');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        db_run("DELETE FROM daily_positions WHERE position_date = :d", [':d' => $date]);
        audit_log('daily_position.reopen', 'daily_positions', 0, ['date' => $date]);
        flash('success', fmt_date($date) . ' reopened — its figures are live again.');
    }
    redirect('finance/position.php');
}

/* ── Which days to show ─────────────────────────────────────── */
$today = date('Y-m-d');
$to    = input($_GET, 'to');
$from  = input($_GET, 'from');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = $today;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-d', strtotime($to . ' -2 days'));
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}

// Each day is a handful of small queries. Capping the range keeps
// a careless date pick from turning into hundreds of them.
const POSITION_MAX_DAYS = 31;
$span = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
$capped = false;
if ($span > POSITION_MAX_DAYS) {
    $from   = date('Y-m-d', strtotime($to . ' -' . (POSITION_MAX_DAYS - 1) . ' days'));
    $span   = POSITION_MAX_DAYS;
    $capped = true;
}

$days = [];
for ($i = 0; $i < $span; $i++) {
    $d = date('Y-m-d', strtotime($to . " -$i days"));
    $days[] = cash_position($d);
}

/* ── The same statement, as a file ──────────────────────────
 *  A day's position is what gets sent to a bank, an accountant
 *  or a partner who is not going to log in. One sheet per day,
 *  in the running order the page uses — from cash_position_lines(),
 *  so the file and the screen cannot describe different days.
 * ─────────────────────────────────────────────────────────── */
if (wants_export()) {
    $sheets = [];
    foreach ($days as $p) {
        $lines = cash_position_lines($p);
        $sheets[] = [
            // Excel caps a sheet name at 31 characters, and a date
            // is nowhere near that.
            'name'  => fmt_date($p['date'], 'd M Y'),
            'title' => 'Daily position — ' . fmt_date($p['date'], 'l, d F Y'),
            'meta'  => export_meta([
                'Day'       => $p['closed'] ? 'Closed' : 'Open',
                'Inventory' => $p['stock_live']
                    ? 'live figure, this day is not closed'
                    : 'as it stood at close',
                'Reconciles' => cash_position_reconciles($p)
                    ? 'yes — opening + receipts − payments + transfers = closing'
                    : 'NO — check the cash book for this day',
            ]),
            'columns' => [
                ['label' => 'Section', 'key' => 'section'],
                ['label' => 'Item',    'key' => 'item'],
                // Never summed: a column holding both an opening
                // balance and a total of it has no meaningful total.
                ['label' => 'Amount',  'key' => 'amount', 'type' => 'money', 'total' => false],
            ],
            'rows'  => $lines,
        ];

        // Who is holding the money, which is the question the
        // statement always draws next.
        if ($p['accounts']) {
            $sheets[] = [
                'name'  => fmt_date($p['date'], 'd M Y') . ' accounts',
                'title' => 'Where the money sits — ' . fmt_date($p['date'], 'd F Y'),
                'columns' => [
                    ['label' => 'Account', 'key' => 'name'],
                    ['label' => 'Type',    'key' => 'type',
                     'value' => fn($r) => ucwords(str_replace('_', ' ', (string) $r['type']))],
                    ['label' => 'Holds money', 'key' => 'liquid',
                     'value' => fn($r) => $r['liquid'] ? 'yes' : 'no — owed to us'],
                    ['label' => 'Balance', 'key' => 'balance', 'type' => 'money'],
                ],
                'rows'  => $p['accounts'],
                'total' => true,
            ];
        }
    }
    // Named for the day it describes, not for today: export_filename()
    // stamps today's date, which on a position produces
    // "daily-position-2026-08-12-2026-08-12" and tells nobody which
    // day is inside.
    export_deliver(
        'daily-position-' . ($from === $to ? $to : $from . '-to-' . $to),
        $sheets
    );
}

$liveStock    = finance_live_stock_value();
$liveDebtors  = finance_trade_receivables(date('Y-m-d'));
$livePayables = finance_trade_payables(date('Y-m-d'));

$pageTitle    = 'Daily Position';
$pageSubtitle = 'What the business owns, what it owes, and how the cash moved';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [['label' => 'Finance'], ['label' => 'Daily Position']];

require __DIR__ . '/../../includes/header.php';
?>

<form class="toolbar" method="GET" action="<?= e(url('finance/position.php')) ?>">
    <div class="toolbar-search">
        <label class="toolbar-inline-label">From</label>
        <input type="date" name="from" value="<?= e($from) ?>" class="form-control toolbar-select" data-autosubmit>
        <label class="toolbar-inline-label">To</label>
        <input type="date" name="to" value="<?= e($to) ?>" class="form-control toolbar-select" data-autosubmit>
        <?= toolbar_apply('finance/position.php') ?>
    </div>
    <div class="toolbar-actions">
        <?= export_button('finance/position.php', ['from' => $from, 'to' => $to]) ?>
        <a class="btn btn-ghost" href="<?= e(url('finance/cashbook.php')) ?>"><?= icon('file') ?> Cash Book</a>
        <button type="button" class="btn btn-ghost" data-print><?= icon('report') ?> Print</button>
    </div>
</form>

<?php if ($capped): ?>
    <p class="form-hint u-pad">Showing the most recent <?= POSITION_MAX_DAYS ?> days of the range you chose.</p>
<?php endif; ?>

<?php foreach ($days as $p):
    $reconciles = cash_position_reconciles($p);
    $closeFill  = json_encode([
        'position_date' => $p['date'],
        'stock_value'   => number_format($p['inventory'], 2, '.', ''),
        'creditors'     => number_format($p['trade_payables'], 2, '.', ''),
    ], JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<section class="position-day">
    <header class="position-daybar">
        <h2><?= e(fmt_date($p['date'], 'l, d F Y')) ?></h2>
        <div class="position-daybar-actions">
            <?php if ($p['closed']): ?>
                <span class="badge badge--active">Closed</span>
                <?php if (is_admin()): ?>
                    <form method="POST" action="<?= e(url('finance/position.php')) ?>" class="inline-form"
                          data-confirm="Reopen <?= e(fmt_date($p['date'])) ?>? Its inventory and trade-payable figures go back to live values.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="_action" value="reopen">
                        <input type="hidden" name="position_date" value="<?= e($p['date']) ?>">
                        <button type="submit" class="btn btn-ghost btn--sm">Reopen</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <span class="badge badge--pending">Open</span>
                <?php if ($p['stale']): ?>
                    <span class="cell-muted position-note">inventory shown live, not as it stood that day</span>
                <?php endif; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-ghost btn--sm"
                    data-modal-open="tplCloseDay"
                    data-modal-title="Close <?= e(fmt_date($p['date'])) ?>"
                    data-prefill="<?= e($closeFill) ?>"><?= icon('check') ?> <?= $p['closed'] ? 'Edit' : 'Close day' ?></button>
        </div>
    </header>

    <?php if (!$reconciles): ?>
        <p class="position-warning">
            <?= icon('alert') ?>
            This day does not reconcile. That normally means the two legs of a
            transfer were dated differently — check the cash book for
            <?= e(fmt_date($p['date'])) ?>.
        </p>
    <?php endif; ?>

    <div class="position-grid">

        <!-- ── Assets: what the business owns ── -->
        <table class="position-table">
            <tbody>
                <tr class="position-head"><th colspan="2">Assets</th></tr>
                <tr>
                    <th>Inventory <span class="cell-muted">at cost</span></th>
                    <td class="ta-right"><?= e(money($p['inventory'])) ?>
                        <?php if ($p['stock_live']): ?><span class="position-live" title="Live figure — this day has not been closed">live</span><?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Cash and bank balances</th>
                    <td class="ta-right"><?= e(money($p['cash_and_bank'])) ?></td>
                </tr>
                <tr>
                    <th>Trade receivables <span class="cell-muted">unpaid invoices</span></th>
                    <td class="ta-right"><?= e(money($p['trade_receivables'])) ?></td>
                </tr>
                <?php if ($p['staff_loans'] > 0.005): ?>
                    <tr>
                        <th>Staff loans receivable</th>
                        <td class="ta-right"><?= e(money($p['staff_loans'])) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($p['informal_credit'] > 0.005): ?>
                    <tr>
                        <th>Other receivables <span class="cell-muted">credit, no invoice</span></th>
                        <td class="ta-right"><?= e(money($p['informal_credit'])) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($p['supplier_prepayments'] > 0.005): ?>
                    <tr>
                        <?php // "How is it working that out?" is the question this
                              // figure always draws, so it links to its working. ?>
                        <th>
                            <a href="<?= e(url('reports/payables.php?view=prepayments&as_of=' . $p['date'])) ?>">Supplier prepayments</a>
                            <span class="cell-muted">paid, goods not yet in</span>
                        </th>
                        <td class="ta-right"><?= e(money($p['supplier_prepayments'])) ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="position-sub">
                    <th>Current assets <span class="cell-muted">cash within a year</span></th>
                    <td class="ta-right"><?= e(money($p['current_assets'])) ?></td>
                </tr>
                <?php // Held to work with rather than to sell, so they are
                      // their own subtotal rather than being buried among
                      // the things that turn into cash. ?>
                <tr>
                    <th>
                        <a href="<?= e(url('finance/assets.php?as_of=' . $p['date'])) ?>">Fixed assets</a>
                        <span class="cell-muted">equipment, at cost less depreciation</span>
                    </th>
                    <td class="ta-right"><?= $p['fixed_assets'] > 0.005 ? e(money($p['fixed_assets'])) : '—' ?></td>
                </tr>
                <tr class="position-subtotal">
                    <th>Total assets</th>
                    <td class="ta-right"><?= e(money($p['total_assets'])) ?></td>
                </tr>

                <tr class="position-head"><th colspan="2">Liabilities</th></tr>
                <tr>
                    <th>Trade payables <span class="cell-muted">goods received, not yet paid</span></th>
                    <td class="ta-right"><?= $p['trade_payables'] > 0.005 ? e(money($p['trade_payables'])) : '—' ?></td>
                </tr>
                <tr>
                    <th>Customer advances <span class="cell-muted">paid before invoicing</span></th>
                    <td class="ta-right"><?= $p['customer_advances'] > 0.005 ? e(money($p['customer_advances'])) : '—' ?></td>
                </tr>
                <tr>
                    <th>Investor loans</th>
                    <td class="ta-right"><?= $p['investor_loans'] > 0.005 ? e(money($p['investor_loans'])) : '—' ?></td>
                </tr>
                <tr>
                    <th>Refunds owed to customers <span class="cell-muted">returned goods already paid for</span></th>
                    <td class="ta-right"><?= $p['refunds_due'] > 0.005 ? e(money($p['refunds_due'])) : '—' ?></td>
                </tr>
                <tr class="position-subtotal">
                    <th>Total liabilities</th>
                    <td class="ta-right"><?= e(money($p['total_liabilities'])) ?></td>
                </tr>

                <tr class="position-valuation">
                    <th>Net asset value</th>
                    <td class="ta-right"><?= e(money($p['net_asset_value'])) ?></td>
                </tr>
                <?php if ($p['invested_capital'] > 0.005): ?>
                    <tr class="position-sub">
                        <th>of which investor capital</th>
                        <td class="ta-right"><?= e(money($p['invested_capital'])) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ── Cash movement for the day ── -->
        <table class="position-table">
            <tbody>
                <tr class="position-head"><th colspan="2">Cash movement</th></tr>
                <tr>
                    <th>Opening balance</th>
                    <td class="ta-right"><?= e(money($p['opening_balance'])) ?></td>
                </tr>
                <?php if ($p['sales_cash'] > 0.005): ?>
                    <tr><th>Cash sales</th><td class="ta-right"><?= e(money($p['sales_cash'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($p['invoice_receipts'] > 0.005): ?>
                    <tr><th>Invoice receipts</th><td class="ta-right"><?= e(money($p['invoice_receipts'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($p['advances_in'] > 0.005): ?>
                    <tr><th>Customer advances</th><td class="ta-right"><?= e(money($p['advances_in'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($p['investment_in'] > 0.005): ?>
                    <tr><th>Investment received</th><td class="ta-right"><?= e(money($p['investment_in'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($p['other_in'] > 0.005): ?>
                    <tr><th>Other income</th><td class="ta-right"><?= e(money($p['other_in'])) ?></td></tr>
                <?php endif; ?>
                <tr class="position-subtotal">
                    <th>Total receipts</th>
                    <td class="ta-right"><?= e(money($p['receipts'])) ?></td>
                </tr>
                <tr>
                    <th>Paid to suppliers <span class="cell-muted">cash out</span></th>
                    <td class="ta-right"><?= e(money($p['purchases'])) ?></td>
                </tr>
                <tr>
                    <th>Operating expenses</th>
                    <td class="ta-right"><?= e(money($p['expenses'])) ?></td>
                </tr>
                <?php if ($p['other_out'] > 0.005): ?>
                    <tr><th>Drawings and refunds</th><td class="ta-right"><?= e(money($p['other_out'])) ?></td></tr>
                <?php endif; ?>
                <tr class="position-subtotal">
                    <th>Total payments</th>
                    <td class="ta-right"><?= e(money($p['payments'])) ?></td>
                </tr>
                <?php if (abs($p['transfers_net']) > 0.005): ?>
                    <tr>
                        <th>Transfers <span class="cell-muted">loans, banking, moves</span></th>
                        <td class="ta-right"><?= ($p['transfers_net'] < 0 ? '−' : '+') . e(money(abs($p['transfers_net']))) ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="position-highlight">
                    <th>Closing balance</th>
                    <td class="ta-right"><?= e(money($p['closing_balance'])) ?></td>
                </tr>
                <?php if ($p['revenue'] > 0.005 || $p['goods_received'] > 0.005): ?>
                    <tr class="position-head"><th colspan="2">Trading</th></tr>
                    <?php if ($p['revenue'] > 0.005): ?>
                        <tr>
                            <th>Revenue <span class="cell-muted">cash, invoiced and on credit</span></th>
                            <td class="ta-right"><?= e(money($p['revenue'])) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php // Purchases in the trading sense — what was bought.
                          // Read from the goods received notes, not the cash
                          // book, because stock taken on credit arrives
                          // without a shilling moving. ?>
                    <tr>
                        <th>Purchases <span class="cell-muted">goods received, at cost</span></th>
                        <td class="ta-right"><?= e(money($p['goods_received'])) ?></td>
                    </tr>
                    <?php if ($p['purchased_on_credit'] > 0.005): ?>
                        <tr class="position-sub">
                            <th>of which not yet paid for</th>
                            <td class="ta-right"><?= e(money($p['purchased_on_credit'])) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- ── Where the money sits ── -->
        <table class="position-table">
            <tbody>
                <tr class="position-head"><th colspan="2">Where the money sits</th></tr>
                <?php if (!$p['accounts']): ?>
                    <tr><td class="table-empty">
                        No accounts yet. Add your tills and bank accounts under
                        <a href="<?= e(url('finance/accounts.php')) ?>">Cash Accounts</a>.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($p['accounts'] as $a): ?>
                    <?php // A receivable account at zero has nothing to say.
                          if (!$a['liquid'] && abs($a['balance']) < 0.005) { continue; } ?>
                    <tr<?= $a['liquid'] ? '' : ' class="position-sub"' ?>>
                        <th>
                            <?= e($a['name']) ?>
                            <?php if (!$a['liquid']): ?>
                                <span class="badge badge--pending"><?= e(cash_account_type_label($a['type'])) ?></span>
                            <?php endif; ?>
                        </th>
                        <td class="ta-right"><?= e(money($a['balance'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($p['accounts']): ?>
                    <tr class="position-highlight">
                        <th>Cash and bank</th>
                        <td class="ta-right"><?= e(money($p['cash_and_bank'])) ?></td>
                    </tr>
                <?php endif; ?>

                <?php // Money the business does not hold but is owed. It
                      // belongs in this total as much as the cash does. ?>
                <?php if ($p['trade_receivables'] > 0.005): ?>
                    <tr class="position-sub">
                        <th>Owed on unpaid invoices</th>
                        <td class="ta-right"><?= e(money($p['trade_receivables'])) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($p['staff_loans'] > 0.005): ?>
                    <tr class="position-sub">
                        <th>Owed by staff</th>
                        <td class="ta-right"><?= e(money($p['staff_loans'])) ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($p['informal_credit'] > 0.005): ?>
                    <tr class="position-sub">
                        <th>Owed on informal credit</th>
                        <td class="ta-right"><?= e(money($p['informal_credit'])) ?></td>
                    </tr>
                <?php endif; ?>

                <?php if ($p['accounts']): ?>
                    <tr class="position-revenue">
                        <th>Held and owed to us <span class="cell-muted">everything but stock</span></th>
                        <td class="ta-right"><?= e(money($p['held_and_owed'])) ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endforeach; ?>

<!-- Close-day modal -->
<template id="tplCloseDay">
    <form method="POST" action="<?= e(url('finance/position.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="close">
        <input type="hidden" name="position_date" value="">

        <p class="form-hint">
            Cash, receivables and liabilities all come from the records that
            produced them. Only inventory cannot be reconstructed for a past
            day, so that is the one figure captured here.
        </p>

        <div class="form-group">
            <label class="form-label">Stock value (at cost) <span class="req">*</span></label>
            <input type="number" name="stock_value" class="form-control" min="0" step="0.01" required>
            <p class="form-hint">Stock is worth <?= e(money($liveStock)) ?> right now.</p>
        </div>

        <div class="form-group">
            <label class="form-label">Trade payables <span class="opt">(owed to suppliers)</span></label>
            <input type="number" name="creditors" class="form-control" min="0" step="0.01">
            <p class="form-hint">
                Now worked out for you — goods received less what has been paid,
                <strong><?= e(money($livePayables)) ?></strong> today. Leave this
                at zero unless you are correcting a day from before the purchase
                ledger existed.
            </p>
        </div>

        <p class="form-hint">
            Trade receivables are not asked for: they come from the invoices
            themselves and stand at <strong><?= e(money($liveDebtors)) ?></strong>
            today. Customer advances and investor loans come from their own
            pages in the same way.
        </p>

        <div class="form-group">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" placeholder="Anything worth remembering about this day">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save position</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
