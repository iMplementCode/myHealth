<?php

/**
 * ============================================================
 *  Credit Note — detail
 * ------------------------------------------------------------
 *  One credit note, its lines, the goods return it opened, and
 *  the money position of the invoice behind it.
 *
 *  The money panel is the point of the page. After a credit note
 *  the invoice is in one of three states, and the page says which
 *  in plain words:
 *
 *    still owed to us  — the customer had not paid enough
 *    settled           — nothing owed either way
 *    owed back to them — they had paid for goods now returned
 *
 *  Only the third has an action: disburse the refund.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/returns.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/uploads.php';
require_role(ROLE_MANAGER);
require_tables('credit_notes', 'credit_note_items', 'customer_refunds');

/* ── AJAX: disburse a refund ────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'refund') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $cn = credit_note_row(input_int($_POST, 'credit_note_id'));
    if (!$cn) {
        json_response(['success' => false, 'message' => 'That credit note no longer exists.']);
    }
    if ($cn['status'] !== 'approved') {
        json_response(['success' => false, 'message' =>
            'Approve ' . $cn['cn_number'] . ' before refunding against it.']);
    }

    $money = invoice_money((int) $cn['invoice_id']);
    if ($money['refund_due'] < 0.005) {
        json_response(['success' => false, 'message' =>
            'Nothing is owed back on ' . $cn['invoice_number'] . '.']);
    }

    $amount    = (float) ($_POST['amount'] ?? 0);
    $date      = input($_POST, 'refund_date') ?: date('Y-m-d');
    $method    = input($_POST, 'method');
    $accountId = input_int($_POST, 'cash_account_id');
    $account   = $accountId !== null
        ? db_one("SELECT * FROM cash_accounts WHERE cash_account_id = :id", [':id' => $accountId])
        : null;

    if ($amount <= 0) {
        json_response(['success' => false, 'message' => 'The refund must be more than zero.']);
    }
    if ($amount > $money['refund_due'] + 0.005) {
        json_response(['success' => false, 'message' => sprintf(
            'Only %s is owed back on %s.', money($money['refund_due']), $cn['invoice_number']
        )]);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'Please provide a valid date, not in the future.']);
    }
    if (!isset(PAYMENT_METHODS[$method]) || $method === 'salary_deduction') {
        json_response(['success' => false, 'message' => 'Choose how the refund is being paid.']);
    }
    if (!$account || !cash_type_is_liquid($account['account_type'])) {
        json_response(['success' => false, 'message' => 'Choose the account the refund is paid from.']);
    }

    // Refuse to pay out of an account that does not hold it.
    $balances  = cash_balances_asof($date);
    $available = $balances[$accountId] ?? (float) $account['opening_balance'];
    if ($available + 0.005 < $amount) {
        json_response(['success' => false, 'message' => sprintf(
            '%s held %s on %s, which is less than the %s being refunded.',
            $account['name'], money($available), fmt_date($date), money($amount)
        )]);
    }

    $proof = ['file' => null, 'error' => null];
    if (!empty($_FILES['proof']['name'])) {
        $proof = store_uploaded_document($_FILES['proof'], 'refunds');
        if ($proof['error']) {
            json_response(['success' => false, 'message' => $proof['error']]);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $number = next_document_number('customer_refunds', 'refund_number', 'REF');
        $stmt = $pdo->prepare(
            "INSERT INTO customer_refunds
                (refund_number, credit_note_id, invoice_id, customer_id, refund_date,
                 amount, method, cash_account_id, reference, proof_url, proof_filename, notes, paid_by)
             VALUES (:num, :cn, :inv, :cust, :d, :amt, :m, :acct, :ref, :purl, :pname, :notes, :by)
             RETURNING refund_id"
        );
        $stmt->execute([
            ':num' => $number, ':cn' => $cn['credit_note_id'], ':inv' => $cn['invoice_id'],
            ':cust' => $cn['customer_id'], ':d' => $date, ':amt' => $amount, ':m' => $method,
            ':acct' => $accountId,
            ':ref' => input($_POST, 'reference') ?: null,
            ':purl' => $proof['file']['url'] ?? null,
            ':pname' => $proof['file']['filename'] ?? null,
            ':notes' => input($_POST, 'notes') ?: null,
            ':by' => current_user()['id'],
        ]);
        $refundId = (int) $stmt->fetchColumn();

        cash_post([
            'date'        => $date,
            'account_id'  => $accountId,
            'direction'   => 'out',
            'amount'      => $amount,
            'category'    => 'refund',
            'party'       => $cn['customer_name'],
            'description' => 'Refund ' . $number . ' against ' . $cn['cn_number']
                             . ' (' . $cn['invoice_number'] . ')',
            'customer_id' => $cn['customer_id'],
            'source_type' => 'customer_refund',
            'source_id'   => $refundId,
        ]);

        $pdo->commit();

        $after = invoice_money((int) $cn['invoice_id']);
        audit_log('customer_refund.pay', 'customer_refunds', $refundId, [
            'number' => $number, 'invoice' => $cn['invoice_number'], 'amount' => $amount,
        ]);
        json_response(['success' => true, 'message' => sprintf(
            '%s refunded to %s from %s. %s',
            money($amount), $cn['customer_name'], $account['name'],
            $after['refund_due'] > 0.005
                ? money($after['refund_due']) . ' still owed back.'
                : 'Nothing further is owed to the customer.'
        )]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[REF] refund failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not record the refund.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[REF] refund failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not record the refund.'], 500);
    }
}

/* ── Data ───────────────────────────────────────────────────── */
$cn = credit_note_row(input_int($_GET, 'id'));
if (!$cn) {
    flash('error', 'That credit note no longer exists.');
    redirect('credit_notes/index.php');
}

$items    = credit_note_items((int) $cn['credit_note_id']);
$money    = invoice_money((int) $cn['invoice_id']);
$refunds  = db_all(
    "SELECT r.*, a.name AS account_name,
            TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS paid_by_name
       FROM customer_refunds r
       LEFT JOIN cash_accounts a ON a.cash_account_id = r.cash_account_id
       LEFT JOIN users u ON u.user_id = r.paid_by
      WHERE r.credit_note_id = :id
      ORDER BY r.refund_date DESC, r.refund_id DESC",
    [':id' => $cn['credit_note_id']]
);
$accounts = cash_liquid_accounts();

$refundFill = json_encode([
    'credit_note_id' => $cn['credit_note_id'],
    'refund_date'    => date('Y-m-d'),
    'amount'         => number_format($money['refund_due'], 2, '.', ''),
], JSON_HEX_APOS | JSON_HEX_QUOT);

$pageTitle    = $cn['cn_number'];
$pageSubtitle = 'Credit note against ' . $cn['invoice_number'];
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js'];
$breadcrumbs  = [
    ['label' => 'Sales'],
    ['label' => 'Credit Notes', 'href' => 'credit_notes/index.php'],
    ['label' => $cn['cn_number']],
];

require __DIR__ . '/../../includes/header.php';
?>

<?php /*
    Both documents a return produces, in the one place somebody
    stands when a customer is returning goods.

    The credit note is the customer's copy — what was credited and
    for how much. The goods return note is the warehouse's — what
    is coming back and how many, and no money at all, because a
    value on a counting sheet only starts an argument about a price
    nobody is settling there.
*/ ?>
<div class="toolbar">
    <div class="toolbar-actions">
        <a class="btn btn-ghost" target="_blank" rel="noopener"
           href="<?= e(url('credit_notes/pdf.php?id=' . (int) $cn['credit_note_id'])) ?>">
            <?= icon('report') ?> Print credit note
        </a>
        <a class="btn btn-ghost"
           href="<?= e(url('credit_notes/pdf.php?id=' . (int) $cn['credit_note_id'] . '&dl=1')) ?>">
            <?= icon('download') ?> Download
        </a>
        <?php if ($cn['grn_return_id']): ?>
            <a class="btn btn-ghost" target="_blank" rel="noopener"
               href="<?= e(url('returns/pdf.php?id=' . (int) $cn['grn_return_id'])) ?>">
                <?= icon('truck') ?> Print goods return note
            </a>
            <a class="btn btn-ghost"
               href="<?= e(url('returns/pdf.php?id=' . (int) $cn['grn_return_id'] . '&dl=1')) ?>">
                <?= icon('download') ?> Download
            </a>
        <?php endif; ?>
    </div>
</div>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Credit note value</span><span class="mini-value"><?= e(money($cn['total_amount'])) ?></span></div>
    <div class="mini-card"><span class="mini-label">Invoice total</span><span class="mini-value"><?= e(money($money['total_amount'])) ?></span></div>
    <div class="mini-card"><span class="mini-label">Received from customer</span><span class="mini-value tone-in"><?= e(money($money['amount_paid'])) ?></span></div>
    <div class="mini-card">
        <span class="mini-label"><?= $money['refund_due'] > 0.005 ? 'Owed back to customer' : 'Still owed to us' ?></span>
        <span class="mini-value <?= $money['refund_due'] > 0.005 ? 'tone-out' : '' ?>">
            <?= e(money($money['refund_due'] > 0.005 ? $money['refund_due'] : $money['receivable'])) ?>
        </span>
    </div>
</section>

<div class="position-grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2>Credit note</h2>
            <span class="badge badge--<?= e(returns_status_tone($cn['status'])) ?>"><?= e(ucfirst($cn['status'])) ?></span>
        </div>
        <table class="position-table">
            <tbody>
                <tr><th>Invoice</th><td class="ta-right"><?= e($cn['invoice_number']) ?></td></tr>
                <tr><th>Customer</th><td class="ta-right"><?= e($cn['customer_name']) ?></td></tr>
                <tr><th>Issued</th><td class="ta-right"><?= e(fmt_date($cn['issue_date'])) ?></td></tr>
                <tr><th>Coming back</th><td class="ta-right"><?= e(CREDIT_RETURN_SCOPES[$cn['return_scope']]['label'] ?? '—') ?></td></tr>
                <?php if ($cn['reason']): ?>
                    <tr><th>Reason</th><td class="ta-right"><?= e($cn['reason']) ?></td></tr>
                <?php endif; ?>
                <?php if ($cn['approved_by_name']): ?>
                    <tr><th>Approved by</th><td class="ta-right"><?= e($cn['approved_by_name']) ?> · <?= e(fmt_date($cn['approved_at'])) ?></td></tr>
                <?php endif; ?>
                <tr><th>Goods return</th>
                    <td class="ta-right">
                        <?php if ($cn['return_number']): ?>
                            <a href="<?= e(url('returns/index.php?q=' . urlencode($cn['return_number']))) ?>"><?= e($cn['return_number']) ?></a>
                            <span class="badge badge--<?= e(returns_status_tone($cn['return_status'])) ?>"><?= e(ucfirst($cn['return_status'])) ?></span>
                        <?php elseif ($cn['return_scope'] === 'none'): ?>
                            <span class="cell-muted">Money only — no goods</span>
                        <?php else: ?>
                            <span class="cell-muted">Opens on approval</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="panel">
        <div class="panel-head"><h2>Where the money stands</h2></div>
        <table class="position-table">
            <tbody>
                <tr><th>Invoiced</th><td class="ta-right"><?= e(money($money['total_amount'])) ?></td></tr>
                <tr><th>Less received</th><td class="ta-right">−<?= e(money($money['amount_paid'])) ?></td></tr>
                <tr><th>Less credited</th><td class="ta-right">−<?= e(money($money['credited'])) ?></td></tr>
                <?php if ((float) $money['refunded'] > 0.005): ?>
                    <tr><th>Plus refunded</th><td class="ta-right">+<?= e(money($money['refunded'])) ?></td></tr>
                <?php endif; ?>
                <?php if ($money['refund_due'] > 0.005): ?>
                    <tr class="position-highlight">
                        <th>Owed back to the customer</th>
                        <td class="ta-right"><?= e(money($money['refund_due'])) ?></td>
                    </tr>
                <?php elseif ($money['receivable'] > 0.005): ?>
                    <tr class="position-revenue">
                        <th>Still owed to us</th>
                        <td class="ta-right"><?= e(money($money['receivable'])) ?></td>
                    </tr>
                <?php else: ?>
                    <tr class="position-subtotal">
                        <th>Settled</th>
                        <td class="ta-right">Nothing owed either way</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="panel-actions">
            <?php if ($cn['status'] === 'draft' && is_admin()): ?>
                <form method="POST" action="<?= e(url('credit_notes/index.php')) ?>" data-ajax class="inline-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="approve">
                    <input type="hidden" name="credit_note_id" value="<?= (int) $cn['credit_note_id'] ?>">
                    <button type="submit" class="btn btn-primary"><?= icon('check') ?> Approve credit note</button>
                </form>
            <?php elseif ($cn['status'] === 'draft'): ?>
                <p class="form-hint">Awaiting an administrator's approval. Nothing has moved yet.</p>
            <?php endif; ?>

            <?php if ($cn['status'] === 'approved' && $money['refund_due'] > 0.005): ?>
                <button type="button" class="btn btn-primary"
                        data-modal-open="tplRefundForm"
                        data-modal-title="Refund <?= e($cn['customer_name']) ?>"
                        data-prefill="<?= e($refundFill) ?>"><?= icon('money') ?> Disburse refund</button>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($items): ?>
<div class="panel">
    <div class="panel-head"><h2>Lines credited</h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Product</th><th>SKU</th>
                    <th class="ta-right">Quantity</th>
                    <th class="ta-right">Unit price</th>
                    <th class="ta-right">Unit cost</th>
                    <th class="ta-right">Credited</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= e($it['product_name']) ?></td>
                        <td class="cell-muted"><?= e($it['sku'] ?: '—') ?></td>
                        <td class="ta-right"><?= e(num($it['quantity'], 2)) ?></td>
                        <td class="ta-right"><?= e(money($it['unit_price'])) ?></td>
                        <td class="ta-right cell-muted"><?= e(money($it['unit_cost'])) ?></td>
                        <td class="ta-right"><strong><?= e(money($it['subtotal'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="position-subtotal">
                    <td colspan="5">Subtotal</td>
                    <td class="ta-right"><?= e(money($cn['subtotal'])) ?></td>
                </tr>
                <?php if ((float) $cn['tax_amount'] > 0.005): ?>
                    <tr class="position-subtotal">
                        <td colspan="5">Tax</td>
                        <td class="ta-right"><?= e(money($cn['tax_amount'])) ?></td>
                    </tr>
                <?php endif; ?>
                <tr class="position-valuation">
                    <td colspan="5">Total credited</td>
                    <td class="ta-right"><?= e(money($cn['total_amount'])) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($refunds): ?>
<div class="panel">
    <div class="panel-head"><h2>Refunds paid</h2></div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr><th>Refund</th><th>Date</th><th>Method</th><th>Paid from</th><th>Reference</th><th>Proof</th><th class="ta-right">Amount</th></tr>
            </thead>
            <tbody>
                <?php foreach ($refunds as $r): ?>
                    <tr>
                        <td><span class="cell-title"><?= e($r['refund_number']) ?></span></td>
                        <td class="cell-muted"><?= e(fmt_date($r['refund_date'])) ?></td>
                        <td><span class="role-pill"><?= e(payment_method_label($r['method'])) ?></span></td>
                        <td class="cell-muted"><?= e($r['account_name'] ?: '—') ?></td>
                        <td class="cell-muted"><?= e($r['reference'] ?: '—') ?></td>
                        <td>
                            <?php if ($r['proof_url']): ?>
                                <a href="<?= e(private_document_url($r['proof_url'])) ?>" target="_blank" rel="noopener" class="icon-btn" title="View proof"><?= icon('file') ?></a>
                            <?php else: ?><span class="cell-muted">—</span><?php endif; ?>
                        </td>
                        <td class="ta-right tone-out"><strong><?= e(money($r['amount'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<template id="tplRefundForm">
    <form method="POST" action="<?= e(url('credit_notes/view.php?id=' . (int) $cn['credit_note_id'])) ?>"
          data-ajax enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="refund">
        <input type="hidden" name="credit_note_id" value="">

        <p class="form-hint">
            The customer paid for goods they have now returned, so this money is
            no longer the business's. Paying it back clears the liability.
        </p>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Amount (KES) <span class="req">*</span></label>
                <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required
                       max="<?= e(number_format($money['refund_due'], 2, '.', '')) ?>">
                <p class="form-hint"><?= e(money($money['refund_due'])) ?> is owed back.</p>
            </div>
            <div class="form-group">
                <label class="form-label">Date <span class="req">*</span></label>
                <input type="date" name="refund_date" class="form-control" required max="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Method <span class="req">*</span></label>
                <select name="method" class="form-control" required>
                    <?php foreach (PAYMENT_METHODS as $key => $label): ?>
                        <?php if ($key === 'salary_deduction') { continue; } ?>
                        <option value="<?= e($key) ?>" <?= $key === 'bank_transfer' ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Paid from <span class="req">*</span></label>
                <select name="cash_account_id" class="form-control" required data-searchable="Search accounts">
                    <option value="">— Select account —</option>
                    <?php foreach ($accounts as $a): ?>
                        <option value="<?= (int) $a['cash_account_id'] ?>">
                            <?= e($a['name']) ?> · <?= e(cash_account_type_label($a['account_type'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Reference</label>
            <input type="text" name="reference" class="form-control" maxlength="160">
        </div>

        <div class="form-group">
            <label class="form-label">Proof of payment <span class="opt">(image or PDF)</span></label>
            <input type="file" name="proof" class="form-control" accept="image/*,application/pdf">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Record refund</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
