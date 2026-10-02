<?php

/**
 * ============================================================
 *  The journal
 * ------------------------------------------------------------
 *  Every balanced entry in the books, and the form for the ones
 *  no document will ever produce: depreciation, an accrual, an
 *  owner's drawing, the opening balances on the day the shop
 *  started using this.
 *
 *  Entries are never edited or deleted. A wrong entry is
 *  corrected by another entry that reverses it, which is the
 *  entire point of keeping a journal rather than a spreadsheet:
 *  the record of what was thought at the time survives being
 *  wrong.
 *
 *  The entry form uses no JavaScript. Six blank lines are
 *  rendered and the unused ones are ignored. A dynamic "add
 *  line" button would be nicer and would also be the second
 *  screen in this application whose JavaScript silently did not
 *  run; six lines covers every entry a pharmacy types by hand,
 *  and the seventh is a second entry.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/accounting.php';
require_once __DIR__ . '/../../includes/icons.php';

require_role(ROLE_MANAGER);

const JOURNAL_FORM_LINES = 6;

/* ── Posting a manual entry ─────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'post_entry') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'That form had gone stale. Try again.');
        redirect('finance/journal.php');
    }

    $lines = [];
    foreach ((array) ($_POST['line'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = [
            'account_id' => $row['account_id'] ?? null,
            'debit'      => (float) ($row['debit']  ?? 0),
            'credit'     => (float) ($row['credit'] ?? 0),
            'memo'       => trim((string) ($row['memo'] ?? '')) ?: null,
        ];
    }

    $result = journal_post(
        [
            'date' => (string) input($_POST, 'entry_date') ?: date('Y-m-d'),
            'memo' => trim((string) input($_POST, 'memo')),
        ],
        $lines
    );

    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('finance/journal.php' . ($result['ok'] ? '?entry_id=' . (int) $result['entry_id'] : ''));
}

/* ── Reversing one ──────────────────────────────────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'reverse') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'That form had gone stale. Try again.');
        redirect('finance/journal.php');
    }

    $entryId = input_int($_POST, 'entry_id') ?? 0;
    $original = db_one('SELECT * FROM journal_entries WHERE entry_id = :id', [':id' => $entryId]);

    if (!$original) {
        flash('error', 'That entry no longer exists.');
        redirect('finance/journal.php');
    }

    //  Every line, the other way round. Nothing is edited and
    //  nothing is removed: the books show both the mistake and
    //  the correction, which is what an auditor asks to see.
    $lines = [];
    foreach (db_all('SELECT * FROM journal_lines WHERE entry_id = :id ORDER BY line_no', [':id' => $entryId]) as $l) {
        $lines[] = [
            'account_id' => (int) $l['account_id'],
            'debit'      => (float) $l['credit'],
            'credit'     => (float) $l['debit'],
            'memo'       => $l['memo'],
        ];
    }

    $result = journal_post(
        [
            'date'        => date('Y-m-d'),
            'memo'        => 'Reversal of ' . $original['entry_number']
                             . ($original['memo'] ? ' — ' . $original['memo'] : ''),
            'reverses_id' => $entryId,
        ],
        $lines
    );

    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('finance/journal.php' . ($result['ok'] ? '?entry_id=' . (int) $result['entry_id'] : ''));
}

$postable = accounts_postable();
$entryId  = input_int($_GET, 'entry_id');

/* ═══ One entry ═════════════════════════════════════════════ */
if ($entryId) {
    $entry = db_one(
        'SELECT je.*, TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS created_by_name,
                r.entry_number AS reverses_number
           FROM journal_entries je
      LEFT JOIN users u ON u.user_id = je.created_by
      LEFT JOIN journal_entries r ON r.entry_id = je.reverses_id
          WHERE je.entry_id = :id',
        [':id' => $entryId]
    );

    if (!$entry) {
        http_response_code(404);
        $pageTitle   = 'Entry not found';
        $pageStyles  = ['forms.css'];
        $breadcrumbs = [['label' => 'Finance'], ['label' => 'Journal', 'href' => 'finance/journal.php']];
        require __DIR__ . '/../../includes/header.php';
        echo '<div class="alert alert--error" role="status"><span>That entry no longer exists.</span></div>';
        require __DIR__ . '/../../includes/footer.php';
        exit;
    }

    $lines = db_all(
        'SELECT jl.*, a.code, a.name
           FROM journal_lines jl
           JOIN accounts a ON a.account_id = jl.account_id
          WHERE jl.entry_id = :id
          ORDER BY jl.line_no, jl.line_id',
        [':id' => $entryId]
    );

    $reversedBy = db_one(
        'SELECT entry_id, entry_number FROM journal_entries WHERE reverses_id = :id',
        [':id' => $entryId]
    );

    $pageTitle   = $entry['entry_number'];
    $pageStyles  = ['forms.css', 'finance.css'];
    $breadcrumbs = [
        ['label' => 'Finance'],
        ['label' => 'Journal', 'href' => 'finance/journal.php'],
        ['label' => (string) $entry['entry_number']],
    ];
    require __DIR__ . '/../../includes/header.php';
    ?>

    <div class="toolbar">
        <a class="btn btn-ghost" href="<?= e(url('finance/journal.php')) ?>">&larr; All entries</a>
        <?php if (!$reversedBy && !$entry['reverses_id']): ?>
            <form method="POST" action="<?= e(url('finance/journal.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="reverse">
                <input type="hidden" name="entry_id" value="<?= (int) $entry['entry_id'] ?>">
                <button type="submit" class="btn btn-ghost">Reverse this entry</button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($reversedBy): ?>
        <div class="alert alert--warning" role="status">
            <span>
                This entry was reversed by
                <a href="<?= e(url('finance/journal.php?entry_id=' . (int) $reversedBy['entry_id'])) ?>">
                    <?= e($reversedBy['entry_number']) ?></a>.
                Both remain in the books.
            </span>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title"><?= e($entry['entry_number']) ?></h2>
            <span class="hint"><?= e(fmt_date($entry['entry_date'])) ?></span>
        </div>
        <div class="u-pad">
            <div class="form-grid-2">
                <div>
                    <div class="form-label">Narration</div>
                    <p class="u-nomargin"><?= e($entry['memo'] ?: '—') ?></p>
                </div>
                <div>
                    <div class="form-label">Source</div>
                    <p class="u-nomargin">
                        <?= $entry['source_table']
                            ? e($entry['source_table'] . ' #' . $entry['source_id'])
                            : 'Typed by hand' ?>
                        <?php if ($entry['reverses_number']): ?>
                            <span class="hint">&mdash; reverses <?= e($entry['reverses_number']) ?></span>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <div class="form-label">Entered by</div>
                    <p class="u-nomargin"><?= e($entry['created_by_name'] ?: 'System') ?></p>
                </div>
                <div>
                    <div class="form-label">Entered at</div>
                    <p class="u-nomargin"><?= e(fmt_date($entry['created_at'])) ?></p>
                </div>
            </div>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Narration</th>
                        <th class="ta-right">Debit</th>
                        <th class="ta-right">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $dr = $cr = 0.0; ?>
                    <?php foreach ($lines as $l): ?>
                        <?php $dr += (float) $l['debit']; $cr += (float) $l['credit']; ?>
                        <tr>
                            <td>
                                <a href="<?= e(url('reports/trial_balance.php?account_id=' . (int) $l['account_id'])) ?>">
                                    <?= e($l['code'] . ' ' . $l['name']) ?>
                                </a>
                            </td>
                            <td><span class="hint"><?= e($l['memo'] ?: '—') ?></span></td>
                            <td class="ta-right"><?= (float) $l['debit']  > 0 ? e(money($l['debit']))  : '' ?></td>
                            <td class="ta-right"><?= (float) $l['credit'] > 0 ? e(money($l['credit'])) : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2" class="ta-right">Total</th>
                        <th class="ta-right"><?= e(money($dr)) ?></th>
                        <th class="ta-right"><?= e(money($cr)) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <?php
    require __DIR__ . '/../../includes/footer.php';
    exit;
}

/* ═══ The list, and the entry form ══════════════════════════ */

$total   = (int) db_value('SELECT COUNT(*) FROM journal_entries');
$pg      = paginate($total, PER_PAGE_DEFAULT);
$entries = db_all(
    'SELECT je.entry_id, je.entry_number, je.entry_date, je.memo,
            je.source_table, je.source_id,
            COALESCE(SUM(jl.debit), 0) AS total_debit,
            COUNT(jl.line_id) AS lines
       FROM journal_entries je
  LEFT JOIN journal_lines jl ON jl.entry_id = je.entry_id
      GROUP BY je.entry_id, je.entry_number, je.entry_date, je.memo,
               je.source_table, je.source_id
      ORDER BY je.entry_date DESC, je.entry_id DESC
      LIMIT ' . $pg['per_page'] . ' OFFSET ' . $pg['offset']
);

$pageTitle   = 'Journal';
$pageStyles  = ['forms.css', 'finance.css'];
$breadcrumbs = [['label' => 'Finance'], ['label' => 'Journal']];

require __DIR__ . '/../../includes/header.php';
?>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">New entry</h2>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('reports/trial_balance.php')) ?>">Trial balance</a>
    </div>
    <form method="POST" action="<?= e(url('finance/journal.php')) ?>" class="u-pad">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="post_entry">

        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label" for="entry_date">Date <span class="req">*</span></label>
                <input type="date" id="entry_date" name="entry_date" class="form-control"
                       value="<?= e(date('Y-m-d')) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="memo">Narration</label>
                <input type="text" id="memo" name="memo" class="form-control" maxlength="255"
                       placeholder="What this entry is for">
            </div>
        </div>

        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Narration</th>
                        <th class="ta-right">Debit</th>
                        <th class="ta-right">Credit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($i = 0; $i < JOURNAL_FORM_LINES; $i++): ?>
                        <tr>
                            <td>
                                <select name="line[<?= $i ?>][account_id]" class="form-control">
                                    <option value="">&mdash;</option>
                                    <?php foreach ($postable as $a): ?>
                                        <option value="<?= (int) $a['account_id'] ?>">
                                            <?= e($a['code'] . ' ' . $a['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="line[<?= $i ?>][memo]" class="form-control" maxlength="160">
                            </td>
                            <td class="ta-right">
                                <input type="number" name="line[<?= $i ?>][debit]" class="form-control counter-num"
                                       step="0.01" min="0" placeholder="0.00">
                            </td>
                            <td class="ta-right">
                                <input type="number" name="line[<?= $i ?>][credit]" class="form-control counter-num"
                                       step="0.01" min="0" placeholder="0.00">
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>

        <p class="hint">
            Debits must equal credits. Blank lines are ignored. The entry is refused
            whole if it does not balance &mdash; nothing is half-written.
        </p>

        <button type="submit" class="btn btn-primary">Post entry</button>
    </form>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Entries</h2>
        <span class="hint"><?= (int) $total ?> in the books</span>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Number</th>
                    <th>Narration</th>
                    <th>Source</th>
                    <th class="ta-right">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$entries): ?>
                    <tr><td colspan="5" class="table-empty">
                        Nothing posted yet. Entries appear here as documents are posted,
                        and when somebody types one above.
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($entries as $en): ?>
                    <tr>
                        <td><?= e(fmt_date($en['entry_date'])) ?></td>
                        <td>
                            <a href="<?= e(url('finance/journal.php?entry_id=' . (int) $en['entry_id'])) ?>">
                                <?= e($en['entry_number']) ?>
                            </a>
                        </td>
                        <td><?= e($en['memo'] ?: '—') ?></td>
                        <td>
                            <?php if ($en['source_table']): ?>
                                <span class="badge badge--info"><?= e($en['source_table']) ?></span>
                            <?php else: ?>
                                <span class="hint">By hand</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right"><?= e(money($en['total_debit'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php pagination_nav($pg, [], 'entry'); ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
