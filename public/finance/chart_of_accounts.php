<?php

/**
 * ============================================================
 *  Chart of accounts
 * ------------------------------------------------------------
 *  The list every figure in the books eventually lands on.
 *  Seeded with a chart shaped like a pharmacy — expired stock,
 *  licence fees, input and output VAT kept apart because they
 *  are two different obligations — so the first month's figures
 *  mean something without somebody having to design a chart
 *  before they can take any money.
 *
 *  Named chart_of_accounts.php and not accounts.php, which is
 *  already the cash and bank accounts screen. Two different
 *  things, and the older one has the shorter name.
 *
 *  Headings total their children and cannot be posted to. The
 *  database enforces that, not this page: a rule living in one
 *  form is a rule until somebody writes the second form.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/accounting.php';
require_once __DIR__ . '/../../includes/icons.php';

require_role(ROLE_MANAGER);

$types = [
    'asset'     => 'Asset',
    'liability' => 'Liability',
    'equity'    => 'Equity',
    'income'    => 'Income',
    'expense'   => 'Expense',
];

/* ── Create or edit ─────────────────────────────────────────── */
if (is_post()) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'That form had gone stale. Try again.');
        redirect('finance/chart_of_accounts.php');
    }

    $id      = input_int($_POST, 'account_id');
    $code    = trim((string) input($_POST, 'code'));
    $name    = trim((string) input($_POST, 'name'));
    $type    = (string) input($_POST, 'type');
    $subtype = trim((string) input($_POST, 'subtype'));
    $header  = isset($_POST['is_header']);
    $active  = isset($_POST['is_active']);

    $errors = [];
    if ($code === '')          { $errors[] = 'An account needs a code.'; }
    if ($name === '')          { $errors[] = 'An account needs a name.'; }
    if (!isset($types[$type])) { $errors[] = 'Choose what kind of account it is.'; }

    /*  An account holding postings cannot become a heading. Its
        balance would drop out of every report that totals
        postable accounts, with nothing anywhere to say where it
        went — and the trial balance would stop balancing for a
        reason nobody could find.                               */
    if (!$errors && $id && $header) {
        if (db_value('SELECT 1 FROM journal_lines WHERE account_id = :id LIMIT 1', [':id' => $id])) {
            $errors[] = 'That account already has postings, so it cannot become a heading.';
        }
    }

    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect('finance/chart_of_accounts.php' . ($id ? '?edit=' . $id : ''));
    }

    try {
        if ($id) {
            db()->prepare(
                'UPDATE accounts
                    SET code = :c, name = :n, type = :t, subtype = :s,
                        is_header = :h, is_active = :a, updated_at = NOW()
                  WHERE account_id = :id'
            )->execute([
                ':c' => $code, ':n' => $name, ':t' => $type,
                ':s' => $subtype !== '' ? $subtype : null,
                ':h' => $header ? 't' : 'f', ':a' => $active ? 't' : 'f', ':id' => $id,
            ]);
            flash('success', $code . ' ' . $name . ' saved.');
        } else {
            db()->prepare(
                'INSERT INTO accounts (code, name, type, subtype, is_header, is_active)
                 VALUES (:c, :n, :t, :s, :h, :a)'
            )->execute([
                ':c' => $code, ':n' => $name, ':t' => $type,
                ':s' => $subtype !== '' ? $subtype : null,
                ':h' => $header ? 't' : 'f', ':a' => $active ? 't' : 'f',
            ]);
            flash('success', $code . ' ' . $name . ' added.');
        }
    } catch (Throwable $e) {
        error_log('[ACCOUNTS] ' . $e->getMessage());
        flash('error', str_contains($e->getMessage(), 'accounts_code_key')
            ? 'Code ' . $code . ' is already in use.'
            : 'That account could not be saved.');
    }

    redirect('finance/chart_of_accounts.php');
}

$accounts = accounts_all();
$editId   = input_int($_GET, 'edit');
$editing  = null;
foreach ($accounts as $a) {
    if ((int) $a['account_id'] === $editId) {
        $editing = $a;
        break;
    }
}

$used = [];
foreach (db_all('SELECT account_id, COUNT(*) AS n FROM journal_lines GROUP BY account_id') as $row) {
    $used[(int) $row['account_id']] = (int) $row['n'];
}

$pageTitle   = 'Chart of Accounts';
$pageStyles  = ['forms.css', 'finance.css'];
$breadcrumbs = [['label' => 'Finance'], ['label' => 'Chart of Accounts']];

require __DIR__ . '/../../includes/header.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title"><?= $editing ? 'Edit account' : 'Add an account' ?></h2>
        <?php if ($editing): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('finance/chart_of_accounts.php')) ?>">Cancel</a>
        <?php endif; ?>
    </div>
    <form method="POST" action="<?= e(url('finance/chart_of_accounts.php')) ?>" class="u-pad">
        <?= csrf_field() ?>
        <input type="hidden" name="account_id" value="<?= (int) ($editing['account_id'] ?? 0) ?>">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="code">Code <span class="req">*</span></label>
                <input type="text" id="code" name="code" class="form-control" maxlength="20" required
                       value="<?= e($editing['code'] ?? '') ?>">
                <span class="hint">1xxx assets, 2xxx liabilities, 3xxx equity, 4xxx income, 5xxx cost of sales, 6xxx expenses.</span>
            </div>
            <div class="form-group">
                <label class="form-label" for="name">Name <span class="req">*</span></label>
                <input type="text" id="name" name="name" class="form-control" maxlength="160" required
                       value="<?= e($editing['name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label" for="type">Kind <span class="req">*</span></label>
                <select id="type" name="type" class="form-control" required>
                    <?php foreach ($types as $k => $label): ?>
                        <option value="<?= e($k) ?>" <?= ($editing['type'] ?? '') === $k ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="subtype">Grouping</label>
                <input type="text" id="subtype" name="subtype" class="form-control" maxlength="24"
                       value="<?= e($editing['subtype'] ?? '') ?>">
                <span class="hint">
                    <code>cost_of_sales</code> or <code>operating</code> on an expense. The difference
                    between those two is the gross margin, which is the number a pharmacy watches.
                </span>
            </div>
        </div>

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="is_header">
                    <input type="checkbox" id="is_header" name="is_header"
                           <?= !empty($editing['is_header']) ? 'checked' : '' ?>>
                    A heading
                </label>
                <span class="hint">Totals its children. Nothing may post to it.</span>
            </div>
            <div class="form-group">
                <label class="form-label" for="is_active">
                    <input type="checkbox" id="is_active" name="is_active"
                           <?= ($editing === null || !empty($editing['is_active'])) ? 'checked' : '' ?>>
                    In use
                </label>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <?= $editing ? 'Save account' : 'Add account' ?>
        </button>
    </form>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">The chart</h2>
        <span class="hint"><?= count($accounts) ?> accounts</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Account</th>
                    <th>Kind</th>
                    <th>Grouping</th>
                    <th class="ta-right">Postings</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accounts as $a): ?>
                    <?php $isHeader = !empty($a['is_header']); ?>
                    <tr>
                        <td><strong><?= e($a['code']) ?></strong></td>
                        <td>
                            <?= $isHeader ? '<strong>' . e($a['name']) . '</strong>' : e($a['name']) ?>
                            <?php if ($isHeader): ?>
                                <span class="badge badge--info">Heading</span>
                            <?php endif; ?>
                            <?php if (empty($a['is_active'])): ?>
                                <span class="badge badge--inactive">Not in use</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($types[$a['type']] ?? $a['type']) ?></td>
                        <td><span class="hint"><?= e($a['subtype'] ?: '—') ?></span></td>
                        <td class="ta-right">
                            <?php $n = $used[(int) $a['account_id']] ?? 0; ?>
                            <?php if ($n): ?>
                                <a href="<?= e(url('reports/trial_balance.php?account_id=' . (int) $a['account_id'])) ?>">
                                    <?= (int) $n ?>
                                </a>
                            <?php else: ?>
                                <span class="hint">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <a class="btn btn-ghost btn-sm"
                               href="<?= e(url('finance/chart_of_accounts.php?edit=' . (int) $a['account_id'])) ?>">Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
