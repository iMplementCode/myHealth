<?php

/**
 * ============================================================
 *  Cash Count
 * ------------------------------------------------------------
 *  The books say one thing and the drawer says another. This is
 *  where that gets settled — the cash equivalent of a stock
 *  take, and settled the same way.
 *
 *  The balance is never typed over. You count what is actually
 *  there, per account, and the system works out the difference
 *  and posts it as an `adjustment` in the cash book, carrying
 *  the reason. Three things follow from doing it that way:
 *
 *    * the cash book still adds up — the closing balance is
 *      still opening plus every movement, because the
 *      correction *is* a movement
 *    * the difference is visible for ever, with a date, a
 *      person and a reason, instead of a number quietly
 *      changing
 *    * "we were 48,000 short in August" is a question somebody
 *      can still ask in December
 *
 *  A shortfall of any size is worth a reason. Money does not
 *  evaporate: it was banked and not recorded, spent and not
 *  receipted, or it is genuinely missing — and those are three
 *  quite different problems.
 *
 *  Administrators and Managers.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_role(ROLE_MANAGER);
require_tables('cash_accounts', 'cash_transactions');

/**
 * Why a counted balance differs from the book.
 *
 * Named rather than free text because the reason decides what
 * you do next, and "miscount" and "missing" are the same figure
 * and quite different problems.
 */
const CASH_COUNT_REASONS = [
    'banked_unrecorded'  => 'Banked or moved, not recorded',
    'spent_unrecorded'   => 'Spent, not recorded',
    'received_unrecorded'=> 'Received, not recorded',
    'miscount'           => 'Earlier miscount or entry error',
    'shortage'           => 'Short — unexplained',
    'overage'            => 'Over — unexplained',
    'opening_balance'    => 'Setting a true opening balance',
    'other'              => 'Other (say so in the note)',
];

/* ── POST: post the count ───────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'post') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $date = input($_POST, 'count_date') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        json_response(['success' => false, 'message' => 'Please provide a valid date.']);
    }
    if ($date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'You cannot count money on a day that has not happened.']);
    }

    $counted = (array) ($_POST['counted'] ?? []);
    $reasons = (array) ($_POST['reason'] ?? []);
    $notes   = (array) ($_POST['note'] ?? []);

    $accounts = [];
    foreach (cash_liquid_accounts() as $a) {
        $accounts[(int) $a['cash_account_id']] = $a;
    }
    $balances = cash_balances_asof($date);

    // Work out every line first, so a bad one stops the lot rather
    // than posting half a count.
    $lines = [];
    foreach ($counted as $id => $value) {
        $id    = (int) $id;
        $value = trim((string) $value);
        if ($value === '' || !isset($accounts[$id])) {
            continue;   // not counted is not the same as counted zero
        }
        if (!is_numeric($value)) {
            json_response(['success' => false, 'message' =>
                'The count for ' . $accounts[$id]['name'] . ' is not a number.']);
        }
        $count = round((float) $value, 2);
        if ($count < 0) {
            json_response(['success' => false, 'message' =>
                'You cannot count less than nothing in ' . $accounts[$id]['name'] . '.']);
        }

        $book = round($balances[$id] ?? 0.0, 2);
        $diff = round($count - $book, 2);
        if (abs($diff) < 0.005) {
            continue;   // agrees with the book: nothing to post
        }

        $reason = (string) ($reasons[$id] ?? '');
        if (!isset(CASH_COUNT_REASONS[$reason])) {
            json_response(['success' => false, 'message' =>
                'Say why ' . $accounts[$id]['name'] . ' differs by '
                . money(abs($diff)) . '. Money does not evaporate.']);
        }

        $lines[] = [
            'id'     => $id,
            'name'   => $accounts[$id]['name'],
            'book'   => $book,
            'count'  => $count,
            'diff'   => $diff,
            'reason' => $reason,
            'note'   => trim((string) ($notes[$id] ?? '')),
        ];
    }

    if (!$lines) {
        json_response(['success' => false, 'message' =>
            'Nothing to post — every account you counted agrees with the book.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach ($lines as $l) {
            $description = 'Cash count ' . fmt_date($l['date'] ?? $date) . ' — '
                . CASH_COUNT_REASONS[$l['reason']]
                . '. Book ' . money($l['book']) . ', counted ' . money($l['count']) . '.'
                . ($l['note'] !== '' ? ' ' . $l['note'] : '');

            cash_post([
                'date'        => $date,
                'account_id'  => $l['id'],
                // A shortfall takes money out of the account, a
                // surplus puts it in. The sign of the difference
                // says which; nobody has to choose a direction.
                'direction'   => $l['diff'] < 0 ? 'out' : 'in',
                'amount'      => abs($l['diff']),
                'category'    => 'adjustment',
                'party'       => current_user()['name'] ?? 'Cash count',
                'description' => $description,
                'created_by'  => current_user()['id'],
            ]);

            audit_log('cash_count.post', 'cash_accounts', $l['id'], [
                'account' => $l['name'], 'book' => $l['book'],
                'counted' => $l['count'], 'difference' => $l['diff'],
                'reason'  => $l['reason'],
            ]);
        }
        $pdo->commit();
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        json_response(['success' => false, 'message' =>
            db_rule_message($ex, 'Could not post the count.')]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[CASHCOUNT] ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not post the count.']);
    }

    $net = array_sum(array_map(fn($l) => $l['diff'], $lines));
    $n   = count($lines);
    json_response(['success' => true, 'message' =>
        num($n) . ' ' . ($n === 1 ? 'account' : plural('account')) . ' adjusted, '
        . ($net < 0 ? money(abs($net)) . ' short' : money($net) . ' over')
        . '. The cash book shows every correction.']);
}

/* ── The sheet ──────────────────────────────────────────────── */
$date = input($_GET, 'date');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
    $date = date('Y-m-d');
}

$accounts = cash_liquid_accounts();
$balances = cash_balances_asof($date);
$bookTotal = 0.0;
foreach ($accounts as $a) {
    $bookTotal += $balances[(int) $a['cash_account_id']] ?? 0.0;
}

// Corrections already made, so a second count on the same day does
// not quietly repeat the first.
$already = db_all(
    "SELECT t.cash_account_id, a.name, t.direction, t.amount, t.description
       FROM cash_transactions t
       JOIN cash_accounts a ON a.cash_account_id = t.cash_account_id
      WHERE t.category = 'adjustment' AND t.txn_date = :d
      ORDER BY t.cash_txn_id DESC",
    [':d' => $date]
);

$pageTitle    = 'Cash Count';
$pageSubtitle = 'Count what is actually there, and let the system post the difference';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js', 'cashcount.js'];
$breadcrumbs  = [['label' => 'Finance'], ['label' => 'Cash Count']];

require __DIR__ . '/../../includes/header.php';
?>

<section class="mini-grid">
    <div class="mini-card">
        <span class="mini-label">The book says</span>
        <span class="mini-value" data-book-total><?= e(money($bookTotal)) ?></span>
    </div>
    <div class="mini-card">
        <span class="mini-label">You counted</span>
        <span class="mini-value" data-count-total>—</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Difference</span>
        <span class="mini-value" data-diff-total>—</span>
    </div>
    <div class="mini-card">
        <span class="mini-label">Accounts</span>
        <span class="mini-value"><?= e(num(count($accounts))) ?></span>
    </div>
</section>

<form class="toolbar" method="GET" action="<?= e(url('finance/cash_count.php')) ?>">
    <div class="toolbar-search">
        <label class="toolbar-inline-label">Counted on</label>
        <input type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>"
               class="form-control toolbar-select" data-autosubmit>
        <?= toolbar_apply('finance/cash_count.php') ?>
    </div>
    <div class="toolbar-actions">
        <a class="btn btn-ghost" href="<?= e(url('finance/cashbook.php')) ?>"><?= icon('file') ?> Cash Book</a>
        <button type="button" class="btn btn-ghost" data-print><?= icon('report') ?> Print to count on</button>
    </div>
</form>

<form method="POST" action="<?= e(url('finance/cash_count.php')) ?>" data-ajax data-cash-count>
    <?= csrf_field() ?>
    <input type="hidden" name="_action" value="post">
    <input type="hidden" name="count_date" value="<?= e($date) ?>">

    <div class="panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th class="ta-right">The book says</th>
                        <th class="ta-right">You counted</th>
                        <th class="ta-right">Difference</th>
                        <th>Why</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$accounts): ?>
                        <tr><td colspan="6" class="table-empty">
                            No cash, bank or mobile-money accounts yet. Add them under
                            <a href="<?= e(url('finance/accounts.php')) ?>">Cash Accounts</a>.
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($accounts as $a):
                        $id   = (int) $a['cash_account_id'];
                        $book = (float) ($balances[$id] ?? 0);
                    ?>
                        <tr data-count-row>
                            <td>
                                <span class="cell-title"><?= e($a['name']) ?></span>
                                <span class="cell-sub"><?= e(cash_account_type_label($a['account_type'])) ?></span>
                            </td>
                            <?php // number_format with no separator: the running
                                  // total reads this, and parseFloat("77,059.00")
                                  // is 77. ?>
                            <td class="ta-right" data-book="<?= e(number_format($book, 2, '.', '')) ?>">
                                <?= e(money($book)) ?>
                            </td>
                            <td class="ta-right">
                                <input type="number" step="0.01" min="0" inputmode="decimal"
                                       class="form-control ta-right" style="max-width: 160px;"
                                       name="counted[<?= $id ?>]" data-counted
                                       placeholder="not counted">
                            </td>
                            <td class="ta-right" data-diff><span class="cell-muted">—</span></td>
                            <td>
                                <select name="reason[<?= $id ?>]" class="form-control" data-reason disabled>
                                    <option value="">Why?</option>
                                    <?php foreach (CASH_COUNT_REASONS as $k => $label): ?>
                                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="note[<?= $id ?>]" class="form-control"
                                       maxlength="200" data-note disabled placeholder="what happened">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($accounts): ?>
                    <tfoot>
                        <tr class="table-total">
                            <th>Total</th>
                            <td class="ta-right"><strong><?= e(money($bookTotal)) ?></strong></td>
                            <td class="ta-right"><strong data-count-total>—</strong></td>
                            <td class="ta-right"><strong data-diff-total>—</strong></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <?php if ($accounts): ?>
        <div class="form-actions u-pad">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Post the count</button>
            <span class="form-hint">
                Only accounts you put a figure against are posted. A blank box means
                <strong>not counted yet</strong>, which is not the same as counting nothing.
            </span>
        </div>
    <?php endif; ?>
</form>

<?php if ($already): ?>
    <div class="panel">
        <div class="panel-head"><h2>Already corrected on <?= e(fmt_date($date)) ?></h2></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Account</th><th class="ta-right">Correction</th><th>Why</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($already as $r): ?>
                        <tr>
                            <td><?= e($r['name']) ?></td>
                            <td class="ta-right <?= $r['direction'] === 'out' ? 'tone-out' : 'tone-in' ?>">
                                <?= $r['direction'] === 'out' ? '−' : '+' ?><?= e(money($r['amount'])) ?>
                            </td>
                            <td class="cell-muted"><?= e($r['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<p class="form-hint u-pad">
    A count does not overwrite the balance — it posts the difference to the cash
    book as an adjustment, so the book still adds up and the correction keeps its
    date, its reason and your name. To see one afterwards, filter the cash book by
    <strong>Adjustment</strong>.
</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
