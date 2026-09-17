<?php

/**
 * ============================================================
 *  Credit Notes
 * ------------------------------------------------------------
 *  A credit note cancels part or all of an invoice. It is the
 *  money side of undoing a sale, and it is deliberately separate
 *  from the goods coming back:
 *
 *    approved → what the customer owes falls
 *             → if they had already paid, we now owe them
 *             → a goods return note opens, if goods are expected
 *
 *  Nothing happens on a draft. Approval is the financial event,
 *  which is why only an administrator can do it.
 *
 *  Administrators and Managers only.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/cashbook.php';
require_once __DIR__ . '/../../includes/returns.php';
require_once __DIR__ . '/../../includes/documents.php';
require_once __DIR__ . '/../../includes/workflow.php';
require_role(ROLE_MANAGER);
require_tables('credit_notes', 'credit_note_items', 'invoices');

/* ── AJAX: raise a credit note against an invoice ───────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'create') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }

    $invoiceId = input_int($_POST, 'invoice_id');
    $money     = $invoiceId !== null ? invoice_money($invoiceId) : [];
    if (!$money) {
        json_response(['success' => false, 'message' => 'That invoice no longer exists.']);
    }
    if ($money['status'] === 'draft') {
        json_response(['success' => false, 'message' => 'A draft invoice has not been issued, so there is nothing to credit.']);
    }
    if ($money['creditable'] < 0.005) {
        json_response(['success' => false, 'message' =>
            $money['invoice_number'] . ' has already been credited in full.']);
    }

    $scope = input($_POST, 'return_scope');
    if (!isset(CREDIT_RETURN_SCOPES[$scope])) {
        json_response(['success' => false, 'message' => 'Say what is coming back.']);
    }
    $date = input($_POST, 'issue_date') ?: date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
        json_response(['success' => false, 'message' => 'Please provide a valid date, not in the future.']);
    }

    // Lines. A full credit takes everything not yet credited; a
    // partial one takes what the form says.
    $available = [];
    foreach (invoice_creditable_lines($invoiceId) as $line) {
        $left = (float) $line['quantity'] - (float) $line['credited_quantity'];
        if ($left > 0.0005) {
            $available[(int) $line['product_id']] = $line + ['remaining' => $left];
        }
    }

    $lines = [];
    if ($scope === 'full') {
        foreach ($available as $pid => $line) {
            $lines[$pid] = ['qty' => $line['remaining'], 'line' => $line];
        }
    } elseif ($scope === 'partial') {
        foreach ((array) ($_POST['product_id'] ?? []) as $i => $rawPid) {
            $pid = (int) $rawPid;
            $qty = (float) ($_POST['quantity'][$i] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            if (!isset($available[$pid])) {
                json_response(['success' => false, 'message' => 'A chosen product is not on this invoice, or is already credited.']);
            }
            if (isset($lines[$pid])) {
                json_response(['success' => false, 'message' => 'Each product may appear only once — combine the quantities.']);
            }
            if ($qty > $available[$pid]['remaining'] + 0.0005) {
                json_response(['success' => false, 'message' => sprintf(
                    '%s: only %s is left to credit on this invoice.',
                    $available[$pid]['product_name'], num($available[$pid]['remaining'], 2)
                )]);
            }
            $lines[$pid] = ['qty' => $qty, 'line' => $available[$pid]];
        }
        if (!$lines) {
            json_response(['success' => false, 'message' => 'Choose at least one product and quantity to credit.']);
        }
    }

    // A money-only credit note carries a value rather than lines.
    $moneyOnly = (float) ($_POST['credit_amount'] ?? 0);
    if ($scope === 'none') {
        if ($moneyOnly <= 0) {
            json_response(['success' => false, 'message' => 'Enter the amount to credit.']);
        }
        if ($moneyOnly > $money['creditable'] + 0.005) {
            json_response(['success' => false, 'message' => sprintf(
                'Only %s is left to credit on %s.',
                money($money['creditable']), $money['invoice_number']
            )]);
        }
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $number = next_document_number('credit_notes', 'cn_number', 'CN');
        $dnId   = db_value(
            "SELECT dn_id FROM delivery_notes
              WHERE invoice_id = :id AND status = 'posted'
              ORDER BY dn_id LIMIT 1",
            [':id' => $invoiceId]
        );

        $stmt = $pdo->prepare(
            "INSERT INTO credit_notes
                (cn_number, invoice_id, customer_id, dn_id, issue_date, reason,
                 return_scope, subtotal, tax_amount, total_amount, notes, created_by)
             VALUES (:num, :inv, :cust, :dn, :d, :reason, :scope, 0, 0, 0, :notes, :by)
             RETURNING credit_note_id"
        );
        $stmt->execute([
            ':num' => $number, ':inv' => $invoiceId, ':cust' => $money['customer_id'],
            ':dn' => $dnId ?: null, ':d' => $date,
            ':reason' => input($_POST, 'reason') ?: null,
            ':scope' => $scope,
            ':notes' => input($_POST, 'notes') ?: null,
            ':by' => current_user()['id'],
        ]);
        $creditNoteId = (int) $stmt->fetchColumn();

        if ($scope === 'none') {
            $pdo->prepare(
                "UPDATE credit_notes SET subtotal = :v, total_amount = :v WHERE credit_note_id = :id"
            )->execute([':v' => $moneyOnly, ':id' => $creditNoteId]);
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO credit_note_items
                    (credit_note_id, product_id, quantity, unit_price, discount, unit_cost)
                 VALUES (:cn, :p, :qty, :price, :disc, :cost)"
            );
            foreach ($lines as $pid => $l) {
                // Credit the line at what it was billed at, discount
                // included. Crediting a discounted line at its full
                // price hands back money that was never charged —
                // and, on a full credit, adds up to more than the
                // invoice, which the database rightly refuses.
                $invoiced = (float) $l['line']['quantity'];
                $share    = $invoiced > 0 ? (float) $l['qty'] / $invoiced : 0.0;
                $ins->execute([
                    ':cn' => $creditNoteId, ':p' => $pid, ':qty' => $l['qty'],
                    ':price' => $l['line']['unit_price'],
                    ':disc'  => round((float) ($l['line']['discount'] ?? 0) * $share, 2),
                    ':cost'  => $l['line']['unit_cost'],
                ]);
            }
            // Credit tax at the rate the invoice charged it at, so
            // crediting everything cancels the invoice exactly
            // rather than leaving the tax behind.
            // The invoice carries the rate it was raised at
            // (migration 026). Working it back out of the money is
            // the fallback for rows raised before that.
            $inv  = db_one(
                "SELECT subtotal, tax_amount, tax_rate FROM invoices WHERE invoice_id = :id",
                [':id' => $invoiceId]
            );
            $rate = (float) ($inv['tax_rate'] ?? 0) > 0
                ? (float) $inv['tax_rate']
                : ((float) $inv['subtotal'] > 0
                    ? round((float) $inv['tax_amount'] / (float) $inv['subtotal'], 4)
                    : 0.0);

            if ($scope === 'full') {
                // The whole invoice is being cancelled, so the note
                // is worth whatever is still owing on it. See
                // credit_note_credit_in_full() for why the lines are
                // not trusted to add up to that on their own.
                credit_note_credit_in_full($creditNoteId, $money['creditable'], $rate);
            } else {
                credit_note_recalculate($creditNoteId, $rate);
                $raised = (float) db_value(
                    "SELECT total_amount FROM credit_notes WHERE credit_note_id = :id",
                    [':id' => $creditNoteId]
                );
                // Say so here, in the customer's terms, rather than
                // letting the database refuse it on approval a day
                // later with nothing the user can act on.
                if ($raised > $money['creditable'] + 0.005) {
                    throw new DomainException(sprintf(
                        'Those quantities come to %s, but only %s is left to credit on %s.',
                        money($raised), money($money['creditable']), $money['invoice_number']
                    ));
                }
            }
        }

        link_documents('invoice', $invoiceId, 'credit_note', $creditNoteId);

        $pdo->commit();
        audit_log('credit_note.create', 'credit_notes', $creditNoteId, [
            'number' => $number, 'invoice' => $money['invoice_number'], 'scope' => $scope,
        ]);
        json_response(['success' => true, 'message' =>
            "$number raised against {$money['invoice_number']} as a draft. "
            . 'Approve it to credit the customer and open the goods return.',
            'redirect' => url('credit_notes/view.php?id=' . $creditNoteId),
        ]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CN] create failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not raise the credit note.')], 500);
    } catch (DomainException $ex) {
        // Our own refusal, written for the person at the screen.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response(['success' => false, 'message' => $ex->getMessage()]);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CN] create failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not raise the credit note.'], 500);
    }
}

/* ── AJAX: approve — the financial event ────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'approve') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    if (!is_admin()) {
        json_response(['success' => false, 'message' => 'Only an administrator can approve a credit note.'], 403);
    }

    $cn = credit_note_row(input_int($_POST, 'credit_note_id'));
    if (!$cn) {
        json_response(['success' => false, 'message' => 'That credit note no longer exists.']);
    }
    if ($cn['status'] !== 'draft') {
        json_response(['success' => false, 'message' =>
            "{$cn['cn_number']} is already {$cn['status']}."]);
    }
    if ((float) $cn['total_amount'] <= 0) {
        json_response(['success' => false, 'message' => 'A credit note with no value cannot be approved.']);
    }

    $pdo = db();
    try {
        $pdo->beginTransaction();

        db_run(
            "UPDATE credit_notes
                SET status = 'approved', approved_by = :by, approved_at = NOW(), updated_at = NOW()
              WHERE credit_note_id = :id",
            [':by' => current_user()['id'], ':id' => $cn['credit_note_id']]
        );

        // Goods coming back get a return note straight away, so the
        // warehouse knows to expect them.
        $returnId = credit_note_open_return($cn);

        $pdo->commit();

        $money = invoice_money((int) $cn['invoice_id']);
        audit_log('credit_note.approve', 'credit_notes', (int) $cn['credit_note_id'], [
            'number' => $cn['cn_number'], 'amount' => $cn['total_amount'],
        ]);

        $parts = [money($cn['total_amount']) . ' credited against ' . $cn['invoice_number'] . '.'];
        if ($money['refund_due'] > 0.005) {
            $parts[] = money($money['refund_due']) . ' is now owed back to the customer.';
        } elseif ($money['receivable'] > 0.005) {
            $parts[] = money($money['receivable']) . ' remains outstanding.';
        } else {
            $parts[] = 'Nothing is owed either way.';
        }
        if ($returnId) {
            $parts[] = 'A goods return note has been opened.';
        }
        json_response(['success' => true, 'message' => implode(' ', $parts)]);
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CN] approve failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => db_rule_message($ex, 'Could not approve the credit note.')], 500);
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[CN] approve failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => 'Could not approve the credit note.'], 500);
    }
}

/* ── POST: cancel a draft ───────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'cancel') {
    csrf_check();
    $cn = credit_note_row(input_int($_POST, 'credit_note_id'));
    if (!$cn) {
        flash('error', 'That credit note no longer exists.');
    } elseif ($cn['status'] !== 'draft') {
        // An approved credit note has already moved money; voiding
        // it silently would leave the invoice wrong.
        flash('error', $cn['cn_number'] . ' has been approved and can no longer be cancelled.');
    } else {
        db_run("UPDATE credit_notes SET status = 'cancelled', updated_at = NOW() WHERE credit_note_id = :id",
            [':id' => $cn['credit_note_id']]);
        audit_log('credit_note.cancel', 'credit_notes', (int) $cn['credit_note_id'], ['number' => $cn['cn_number']]);
        flash('success', $cn['cn_number'] . ' cancelled.');
    }
    redirect('credit_notes/index.php');
}

/* ── Data ───────────────────────────────────────────────────── */
$statusFilter = input($_GET, 'status');
$search       = input($_GET, 'q');
$perPage      = per_page();

$where  = [];
$params = [];
if (in_array($statusFilter, ['draft', 'approved', 'cancelled'], true)) {
    $where[] = "cn.status = :st";
    $params[':st'] = $statusFilter;
}
if ($search !== '') {
    $where[] = "(LOWER(cn.cn_number) LIKE :q OR LOWER(i.invoice_number) LIKE :q
                 OR LOWER(COALESCE(c.company_name,'')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int) db_value(
    "SELECT COUNT(*) FROM credit_notes cn
       JOIN invoices i ON i.invoice_id = cn.invoice_id
       LEFT JOIN customers c ON c.customer_id = cn.customer_id
     $whereSql",
    $params
);
$pg = paginate($total, $perPage);

$notes = db_all(
    "SELECT cn.*, i.invoice_number,
            " . customer_name_sql('c') . " AS customer_name,
            r.return_number, r.status AS return_status
       FROM credit_notes cn
       JOIN invoices i ON i.invoice_id = cn.invoice_id
       LEFT JOIN customers c ON c.customer_id = cn.customer_id
       LEFT JOIN goods_return_notes r ON r.credit_note_id = cn.credit_note_id
     $whereSql
     ORDER BY cn.issue_date DESC, cn.credit_note_id DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

$today        = date('Y-m-d');
$creditedTot  = (float) db_value("SELECT COALESCE(SUM(total_amount), 0) FROM credit_notes WHERE status = 'approved'");
$draftCount   = (int) db_value("SELECT COUNT(*) FROM credit_notes WHERE status = 'draft'");
$refundsDue   = finance_customer_refunds_due($today);
$awaitingCredit = (int) db_value("SELECT COUNT(*) FROM invoices WHERE credit_status = 'awaiting_credit'");

// Invoices a credit note can still be raised against.
$creditableInvoices = db_all(
    "SELECT i.invoice_id, i.invoice_number, i.total_amount, i.amount_paid, i.credit_status,
            " . customer_name_sql('c') . " AS customer_name,
            i.total_amount - COALESCE(cn.credited, 0) AS creditable
       FROM invoices i
       LEFT JOIN customers c ON c.customer_id = i.customer_id
       LEFT JOIN (SELECT invoice_id, SUM(total_amount) AS credited
                    FROM credit_notes WHERE status = 'approved' GROUP BY invoice_id) cn
              ON cn.invoice_id = i.invoice_id
      WHERE i.status <> 'draft'
        AND i.total_amount - COALESCE(cn.credited, 0) > 0.005
      ORDER BY i.credit_status = 'awaiting_credit' DESC, i.issue_date DESC
      LIMIT 200"
);

$pageTitle    = 'Credit Notes';
$pageSubtitle = 'Cancelling an invoice after the goods have gone out';
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$pageScripts  = ['validation.js', 'credit-note.js'];
$breadcrumbs  = [['label' => 'Sales'], ['label' => 'Credit Notes']];

require __DIR__ . '/../../includes/header.php';

$baseQuery = array_filter([
    'status' => $statusFilter, 'q' => $search,
    'per_page' => $perPage !== PER_PAGE_DEFAULT ? $perPage : null,
], fn($v) => $v !== null && $v !== '');
?>

<section class="mini-grid">
    <div class="mini-card"><span class="mini-label">Credited to date</span><span class="mini-value"><?= e(money($creditedTot)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Refunds owed to customers</span><span class="mini-value tone-out"><?= e(money($refundsDue)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Drafts awaiting approval</span><span class="mini-value"><?= e(num($draftCount)) ?></span></div>
    <div class="mini-card"><span class="mini-label">Cancelled, not yet credited</span><span class="mini-value <?= $awaitingCredit ? 'tone-out' : '' ?>"><?= e(num($awaitingCredit)) ?></span></div>
</section>

<?php if ($awaitingCredit > 0): ?>
    <p class="position-warning u-round">
        <?= icon('alert') ?>
        <?= e(num($awaitingCredit)) ?> cancelled invoice<?= $awaitingCredit === 1 ? '' : 's' ?>
        had goods already delivered and <?= $awaitingCredit === 1 ? 'is' : 'are' ?> waiting for a credit note.
        Until one is raised, the sale is still counted and the stock is still out.
    </p>
<?php endif; ?>

<form class="toolbar" method="GET" action="<?= e(url('credit_notes/index.php')) ?>">
    <div class="toolbar-search">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Search credit note, invoice or customer…">
        <select name="status" class="form-control toolbar-select" data-autosubmit>
            <option value="">Any status</option>
            <?php foreach (['draft', 'approved', 'cancelled'] as $st): ?>
                <option value="<?= e($st) ?>" <?= $statusFilter === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
            <?php endforeach; ?>
        </select>
        <?= toolbar_apply('credit_notes/index.php') ?>
    </div>
    <button type="button" class="btn btn-primary" data-modal-open="tplCreditNoteForm"
            data-modal-title="Raise Credit Note" data-modal-wide data-modal-auto>
        <?= icon('plus') ?> Raise Credit Note
    </button>
</form>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Credit note</th>
                    <th>Invoice</th>
                    <th>Customer</th>
                    <th>Issued</th>
                    <th>Coming back</th>
                    <th>Status</th>
                    <th>Goods return</th>
                    <th class="ta-right">Amount</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$notes): ?>
                    <tr><td colspan="9" class="table-empty">
                        No credit notes. One is raised when an invoice has to be undone
                        after the goods have gone out.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($notes as $cn): ?>
                    <tr>
                        <td>
                            <a class="cell-title" href="<?= e(url('credit_notes/view.php?id=' . (int) $cn['credit_note_id'])) ?>">
                                <?= e($cn['cn_number']) ?>
                            </a>
                        </td>
                        <td><?= e($cn['invoice_number']) ?></td>
                        <td><?= e($cn['customer_name']) ?></td>
                        <td class="cell-muted"><?= e(fmt_date($cn['issue_date'])) ?></td>
                        <td class="cell-muted"><?= e(CREDIT_RETURN_SCOPES[$cn['return_scope']]['label'] ?? '—') ?></td>
                        <td><span class="badge badge--<?= e(returns_status_tone($cn['status'])) ?>"><?= e(ucfirst($cn['status'])) ?></span></td>
                        <td>
                            <?php if ($cn['return_number']): ?>
                                <a href="<?= e(url('returns/index.php?q=' . urlencode($cn['return_number']))) ?>">
                                    <?= e($cn['return_number']) ?>
                                </a>
                                <span class="badge badge--<?= e(returns_status_tone($cn['return_status'])) ?>"><?= e(ucfirst($cn['return_status'])) ?></span>
                            <?php else: ?>
                                <span class="cell-muted"><?= $cn['return_scope'] === 'none' ? 'Money only' : '—' ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><strong><?= e(money($cn['total_amount'])) ?></strong></td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <a class="icon-btn" title="Open"
                                   href="<?= e(url('credit_notes/view.php?id=' . (int) $cn['credit_note_id'])) ?>"><?= icon('file') ?></a>
                                <a class="icon-btn" title="Print the credit note" target="_blank" rel="noopener"
                                   href="<?= e(url('credit_notes/pdf.php?id=' . (int) $cn['credit_note_id'])) ?>"><?= icon('report') ?></a>
                                <a class="icon-btn" title="Download the credit note"
                                   href="<?= e(url('credit_notes/pdf.php?id=' . (int) $cn['credit_note_id'] . '&dl=1')) ?>"><?= icon('download') ?></a>
                                <?php if ($cn['status'] === 'draft'): ?>
                                    <form method="POST" action="<?= e(url('credit_notes/index.php')) ?>" class="inline-form"
                                          data-confirm="Cancel <?= e($cn['cn_number']) ?>?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="_action" value="cancel">
                                        <input type="hidden" name="credit_note_id" value="<?= (int) $cn['credit_note_id'] ?>">
                                        <button type="submit" class="icon-btn icon-btn--danger" title="Cancel"><?= icon('x') ?></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'credit note'); ?>
</div>

<template id="tplCreditNoteForm">
    <form method="POST" action="<?= e(url('credit_notes/index.php')) ?>" data-ajax data-credit-note>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="create">

        <div class="form-group">
            <label class="form-label">Invoice to credit <span class="req">*</span></label>
            <select name="invoice_id" class="form-control" required data-cn-invoice data-searchable="Search invoices">
                <option value="">— Select invoice —</option>
                <?php foreach ($creditableInvoices as $inv): ?>
                    <option value="<?= (int) $inv['invoice_id'] ?>"
                            data-creditable="<?= e(number_format((float) $inv['creditable'], 2, '.', '')) ?>"
                            data-paid="<?= e(number_format((float) $inv['amount_paid'], 2, '.', '')) ?>">
                        <?= e($inv['invoice_number']) ?> · <?= e($inv['customer_name']) ?>
                        · <?= e(money($inv['creditable'])) ?> creditable
                        <?= $inv['credit_status'] === 'awaiting_credit' ? ' · CANCELLED, AWAITING CREDIT' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="form-hint" data-cn-paid-note hidden></p>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">What is coming back <span class="req">*</span></label>
                <select name="return_scope" class="form-control" required data-cn-scope>
                    <?php foreach (CREDIT_RETURN_SCOPES as $key => $meta): ?>
                        <option value="<?= e($key) ?>" data-help="<?= e($meta['help']) ?>"><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-hint" data-cn-scope-help><?= e(CREDIT_RETURN_SCOPES['full']['help']) ?></p>
            </div>
            <div class="form-group">
                <label class="form-label">Date <span class="req">*</span></label>
                <input type="date" name="issue_date" class="form-control" required
                       value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
            </div>
        </div>

        <!-- Money-only credit: no goods, just a value -->
        <div class="form-group" data-cn-amount hidden>
            <label class="form-label">Amount to credit (KES) <span class="req">*</span></label>
            <input type="number" name="credit_amount" class="form-control" min="0.01" step="0.01">
            <p class="form-hint">A price correction or agreed discount. Nothing returns to stock.</p>
        </div>

        <!-- Partial credit: pick the lines -->
        <div data-cn-lines hidden>
            <p class="form-hint">
                Choose what the customer is sending back. Leave a quantity at zero
                to keep that line on the invoice.
            </p>
            <div data-cn-lines-body class="cn-lines"></div>
        </div>

        <div class="form-group">
            <label class="form-label">Reason</label>
            <input type="text" name="reason" class="form-control" maxlength="255"
                   placeholder="e.g. Customer cancelled after delivery">
        </div>

        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Raise credit note</button>
        </div>
    </form>
</template>

<?php
/*  One query for all two hundred invoices, not two hundred queries.
    This loop used to call invoice_creditable_lines() per invoice. */
$linesByInvoice = invoice_creditable_lines_bulk(
    array_map(fn($inv) => (int) $inv['invoice_id'], $creditableInvoices)
);
$cnLines = [];
foreach ($linesByInvoice as $invoiceId => $lines) {
    $cnLines[$invoiceId] = [];
    foreach ($lines as $l) {
        $left = (float) $l['quantity'] - (float) $l['credited_quantity'];
        if ($left > 0.0005) {
            $cnLines[$invoiceId][] = [
                'product_id' => (int) $l['product_id'],
                'name'       => $l['product_name'],
                'sku'        => $l['sku'],
                'remaining'  => round($left, 3),
                'unit_price' => (float) $l['unit_price'],
                'delivered'  => (float) $l['delivered_quantity'],
            ];
        }
    }
}
?>
<script type="application/json" id="cn-invoice-lines"><?= json_encode(
    $cnLines, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
) ?></script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
