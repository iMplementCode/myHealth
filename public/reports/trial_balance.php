<?php

/**
 * ============================================================
 *  Trial balance, and the ledger behind any line of it
 * ------------------------------------------------------------
 *    trial_balance.php                 every account, both sides
 *    trial_balance.php?account_id=N    that account, movement by
 *                                      movement, balance carried
 *
 *  Two views of one thing, same as the batches screen, because
 *  a trial balance whose figures cannot be opened is a number
 *  somebody has to take on trust.
 *
 *  The reconciliation at the top is the point of the page. The
 *  profit and loss is built from documents; this is built from
 *  the journal. They are deliberately computed from different
 *  sources, so agreement is evidence rather than tautology — and
 *  disagreement is a finding, stated in figures, rather than
 *  something the shop discovers from its auditor.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/accounting.php';
require_once __DIR__ . '/../../includes/export.php';
require_once __DIR__ . '/../../includes/icons.php';

require_role(ROLE_MANAGER);

['from' => $from, 'to' => $to] = report_period($_GET);
$accountId = input_int($_GET, 'account_id');

/* ═══ One account's ledger ══════════════════════════════════ */
if ($accountId) {
    //  Sized before it is fetched: this page used to render every
    //  line the account had ever carried.
    $probe   = account_ledger($accountId, $from, $to, 1, 0);
    $pg      = paginate((int) $probe['total'], PER_PAGE_DEFAULT);
    $ledger  = account_ledger($accountId, $from, $to, $pg['per_page'], $pg['offset']);
    $account = $ledger['account'];

    if (!$account) {
        http_response_code(404);
        $pageTitle   = 'Account not found';
        $pageStyles  = ['forms.css'];
        $breadcrumbs = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Trial Balance']];
        require __DIR__ . '/../../includes/header.php';
        echo '<div class="alert alert--error" role="status"><span>That account no longer exists.</span></div>';
        require __DIR__ . '/../../includes/footer.php';
        exit;
    }

    $pageTitle    = $account['code'] . ' ' . $account['name'];
    $pageSubtitle = report_period_label($from, $to);
    $pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
    $breadcrumbs  = [
        ['label' => 'Reports', 'href' => 'reports/index.php'],
        ['label' => 'Trial Balance', 'href' => 'reports/trial_balance.php'],
        ['label' => (string) $account['code']],
    ];

    require __DIR__ . '/../../includes/header.php';
    require __DIR__ . '/_period_bar.php';
    ?>

    <div class="toolbar">
        <a class="btn btn-ghost" href="<?= e(url('reports/trial_balance.php?from=' . e($from) . '&to=' . e($to))) ?>">
            &larr; Trial balance
        </a>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title"><?= e($account['code'] . ' ' . $account['name']) ?></h2>
            <span class="hint">
                <?= e(ucfirst((string) $account['type'])) ?>,
                <?= account_is_debit_normal((string) $account['type']) ? 'debit' : 'credit' ?> balance
            </span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Entry</th>
                        <th>Narration</th>
                        <th class="ta-right">Debit</th>
                        <th class="ta-right">Credit</th>
                        <th class="ta-right">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="5">
                            <em>Balance brought forward</em>
                            <?php if ($pg['offset'] > 0): ?>
                                <span class="hint">&mdash; at the start of this period</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><strong><?= e(money($ledger['opening'])) ?></strong></td>
                    </tr>
                    <?php if (!$ledger['rows']): ?>
                        <tr><td colspan="6" class="table-empty">Nothing posted to this account in this period.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($ledger['rows'] as $row): ?>
                        <tr>
                            <td><?= e(fmt_date($row['entry_date'])) ?></td>
                            <td>
                                <a href="<?= e(url('finance/journal.php?entry_id=' . (int) $row['entry_id'])) ?>">
                                    <?= e($row['entry_number']) ?>
                                </a>
                            </td>
                            <td>
                                <?= e($row['line_memo'] ?: $row['entry_memo'] ?: '—') ?>
                                <?php if ($row['source_table']): ?>
                                    <span class="hint">&mdash; <?= e($row['source_table']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="ta-right"><?= (float) $row['debit']  > 0 ? e(money($row['debit']))  : '' ?></td>
                            <td class="ta-right"><?= (float) $row['credit'] > 0 ? e(money($row['credit'])) : '' ?></td>
                            <td class="ta-right"><strong><?= e(money($row['balance'])) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="5" class="ta-right">Balance carried forward</th>
                        <th class="ta-right"><?= e(money($ledger['closing'])) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php pagination_nav($pg, ['account_id' => $accountId, 'from' => $from, 'to' => $to], 'movement'); ?>
    </div>

    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit;
}

/* ═══ The trial balance ═════════════════════════════════════ */

$tb = trial_balance($from, $to);

//  The same period, reckoned from documents instead.
$documents = report_profit_and_loss($from, $to);
$journal   = journal_profit_and_loss($from, $to);

$revenueGap = (float) $documents['revenue']['net'] - $journal['income'];
$profitGap  = (float) $documents['net_profit']     - $journal['net_profit'];
$posted     = (int) db_value('SELECT COUNT(*) FROM journal_entries');

if (wants_export()) {
    export_deliver(export_filename('trial-balance'), [[
        'name'  => 'Trial balance',
        'title' => 'Trial balance — ' . report_period_label($from, $to),
        'meta'  => export_meta([
            'Period'  => report_period_label($from, $to),
            'Debits'  => money($tb['debits']),
            'Credits' => money($tb['credits']),
            'Status'  => $tb['balanced'] ? 'Balanced' : 'OUT BY ' . money($tb['out_by']),
        ]),
        'columns' => [
            ['label' => 'Code',    'key' => 'code'],
            ['label' => 'Account', 'key' => 'name'],
            ['label' => 'Kind',    'key' => 'type'],
            ['label' => 'Debit',   'key' => 'debits',  'type' => 'money'],
            ['label' => 'Credit',  'key' => 'credits', 'type' => 'money'],
        ],
        'rows' => $tb['rows'],
    ]]);
}

$pageTitle    = 'Trial Balance';
$pageSubtitle = report_period_label($from, $to);
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Trial Balance']];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_period_bar.php';
?>

<div class="toolbar">
    <a class="btn btn-ghost" href="<?= e(url('finance/journal.php')) ?>">Journal</a>
    <a class="btn btn-ghost" href="<?= e(url('finance/chart_of_accounts.php')) ?>">Chart of accounts</a>
    <?= export_button('reports/trial_balance.php', ['from' => $from, 'to' => $to]) ?>
</div>

<?php if ($posted === 0): ?>
    <div class="alert alert--info" role="status">
        <span>
            Nothing has been posted to the journal yet, so there is nothing to balance.
            The chart of accounts is ready; entries appear here as they are posted.
        </span>
    </div>
<?php endif; ?>

<?php if (!$tb['balanced']): ?>
    <div class="alert alert--error" role="status">
        <span>
            <strong>The books do not balance.</strong>
            Debits <?= e(money($tb['debits'])) ?>, credits <?= e(money($tb['credits'])) ?>,
            out by <?= e(money(abs($tb['out_by']))) ?>. Every entry is checked as it is
            written, so this should be impossible &mdash; which makes it worth
            investigating rather than correcting.
        </span>
    </div>
<?php endif; ?>

<?php
/*  Documents against journal. Only worth showing once something
    has been posted: before that the gap is the entire turnover
    and says nothing except that posting has not been run.     */
?>
<?php if ($posted > 0 && (abs($revenueGap) > 0.005 || abs($profitGap) > 0.005)): ?>
    <div class="alert alert--warning" role="status">
        <span>
            <strong>The journal and the documents disagree for this period.</strong>
            Revenue is <?= e(money($documents['revenue']['net'])) ?> by the invoices and
            <?= e(money($journal['income'])) ?> in the journal
            (<?= e(money(abs($revenueGap))) ?> apart).
            Net profit is <?= e(money($documents['net_profit'])) ?> against
            <?= e(money($journal['net_profit'])) ?>.
            That is what it looks like when some documents have not been posted to
            the journal &mdash; not in itself an error, but the two cannot both be
            reported as the result.
        </span>
    </div>
<?php elseif ($posted > 0): ?>
    <div class="alert alert--success" role="status">
        <span>
            The journal agrees with the documents for this period: revenue
            <?= e(money($journal['income'])) ?>, net profit
            <?= e(money($journal['net_profit'])) ?>. Two different sources, same answer.
        </span>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Trial balance</h2>
        <span class="badge badge--<?= $tb['balanced'] ? 'active' : 'warn' ?>">
            <?= $tb['balanced'] ? 'Balanced' : 'Out by ' . e(money(abs($tb['out_by']))) ?>
        </span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th>Kind</th>
                    <th class="ta-right">Debit</th>
                    <th class="ta-right">Credit</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tb['rows']): ?>
                    <tr><td colspan="5" class="table-empty">No account was posted to in this period.</td></tr>
                <?php endif; ?>
                <?php foreach ($tb['rows'] as $row): ?>
                    <tr>
                        <td><strong><?= e($row['code']) ?></strong></td>
                        <td>
                            <a href="<?= e(url('reports/trial_balance.php?account_id=' . (int) $row['account_id']
                                               . '&from=' . $from . '&to=' . $to)) ?>">
                                <?= e($row['name']) ?>
                            </a>
                        </td>
                        <td><span class="hint"><?= e(ucfirst((string) $row['type'])) ?></span></td>
                        <td class="ta-right"><?= $row['debits']  > 0 ? e(money($row['debits']))  : '' ?></td>
                        <td class="ta-right"><?= $row['credits'] > 0 ? e(money($row['credits'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="3" class="ta-right">Total</th>
                    <th class="ta-right"><?= e(money($tb['debits'])) ?></th>
                    <th class="ta-right"><?= e(money($tb['credits'])) ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
