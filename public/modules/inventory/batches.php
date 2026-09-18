<?php

/**
 * ============================================================
 *  Batches — what is on the shelf, and where it went
 * ------------------------------------------------------------
 *  Two views in one file, because they are two questions about
 *  the same thing and splitting them would mean two routes to
 *  keep in step:
 *
 *    batches.php               what have we got, what dies when
 *    batches.php?batch_id=N    where did this one go
 *
 *  The notification scanner has been linking here since batch
 *  warnings were written — `modules/inventory/batches.php?
 *  product_id=…` — and the page did not exist, so every expiry
 *  warning the bell raised led to a 404. That is the reason the
 *  path and the query string are what they are rather than
 *  something tidier.
 *
 *  Every class used here is one the stylesheets actually define.
 *  There is a lot of markup in this application referring to
 *  classes that live only in assets/css/app.css, which nothing
 *  loads, and it all renders as unstyled defaults.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/batches.php';
require_once __DIR__ . '/../../../includes/disposals.php';
require_once __DIR__ . '/../../../includes/pharmacy.php';
require_once __DIR__ . '/../../../includes/icons.php';

/*  Reading stock is open to anyone signed in — the person at the
    counter has to be able to answer "have we got it, and is it
    still good" without fetching a manager.                     */

$batchId = input_int($_GET, 'batch_id');

/* ── Writing stock off ──────────────────────────────────────
 *  Destroying stock is the Manager's to authorise, so the guard
 *  is here on the write rather than on the page: the counter
 *  still needs to read what is on the shelf.
 */
if (is_post() && ($_POST['_action'] ?? '') === 'write_off') {
    require_role(ROLE_MANAGER);
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        flash('error', 'That form had gone stale. Try again.');
        redirect('modules/inventory/batches.php?batch_id=' . (int) ($_POST['batch_id'] ?? 0));
    }

    $result = disposal_write_off(
        (int) ($_POST['batch_id'] ?? 0),
        (float) ($_POST['quantity'] ?? 0),
        (string) ($_POST['reason'] ?? ''),
        trim((string) ($_POST['notes'] ?? '')),
        trim((string) ($_POST['witnessed_by'] ?? ''))
    );

    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('modules/inventory/batches.php?batch_id=' . (int) ($_POST['batch_id'] ?? 0));
}

/* ═══ One batch: the recall trace ═══════════════════════════ */
if ($batchId) {
    $batch = batch_get($batchId);
    if (!$batch) {
        http_response_code(404);
        $pageTitle   = 'Batch not found';
        $pageStyles  = ['forms.css'];
        $breadcrumbs = [['label' => 'Inventory'], ['label' => 'Batches', 'href' => 'modules/inventory/batches.php']];
        require __DIR__ . '/../../../includes/header.php';
        echo '<div class="alert alert--error" role="status"><span>That batch no longer exists.</span></div>';
        require __DIR__ . '/../../../includes/footer.php';
        exit;
    }

    $trace     = batch_trace($batchId);
    $disposals = disposals_for_batch($batchId);
    $canWrite  = is_admin() || user_has_role(ROLE_MANAGER);
    $went      = batch_trace_total($trace);
    $disposed  = array_sum(array_map(static fn(array $d): float => (float) $d['quantity'], $disposals));
    //  Received minus remaining is everything that left. Dispensing
    //  explains part of it and disposal explains the rest; what is
    //  left over after both is the part nobody can account for.
    $accounted = (float) $batch['quantity_received'] - (float) $batch['quantity_remaining'];
    $unexplained = $accounted - $went - $disposed;
    [$stateLabel, $stateTone] = batch_state_label((string) $batch['state']);
    $uom = (string) ($batch['uom_abbr'] ?: '');

    $pageTitle   = 'Batch ' . $batch['batch_number'];
    $pageStyles  = ['forms.css', 'finance.css'];
    $breadcrumbs = [
        ['label' => 'Inventory'],
        ['label' => 'Batches', 'href' => 'modules/inventory/batches.php'],
        ['label' => (string) $batch['batch_number']],
    ];
    require __DIR__ . '/../../../includes/header.php';
    ?>

    <div class="toolbar">
        <a class="btn btn-ghost" href="<?= e(url('modules/inventory/batches.php')) ?>">
            &larr; All batches
        </a>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">
                <?= e(drug_label($batch)) ?>
                &middot; batch <?= e($batch['batch_number']) ?>
            </h2>
            <span class="badge badge--<?= e($stateTone) ?>"><?= e($stateLabel) ?></span>
        </div>
        <div class="u-pad">
            <div class="form-grid-2">
                <div>
                    <div class="form-label">Expires</div>
                    <p class="u-nomargin">
                        <?= $batch['expiry_date'] ? e(fmt_date($batch['expiry_date'])) : 'No expiry recorded' ?>
                        <?php if ($batch['state'] === 'expired' && $batch['days_left'] !== null): ?>
                            <span class="hint">&mdash; <?= abs((int) $batch['days_left']) ?> days ago</span>
                        <?php elseif ($batch['state'] === 'expiring' && $batch['days_left'] !== null): ?>
                            <span class="hint">&mdash; in <?= (int) $batch['days_left'] ?> days</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div>
                    <div class="form-label">Supplier</div>
                    <p class="u-nomargin"><?= e($batch['supplier_name'] ?: 'Not recorded') ?></p>
                </div>
                <div>
                    <div class="form-label">Received</div>
                    <p class="u-nomargin"><?= e(num($batch['quantity_received'], 2)) ?> <?= e($uom) ?></p>
                </div>
                <div>
                    <div class="form-label">Still on the shelf</div>
                    <p class="u-nomargin"><?= e(num($batch['quantity_remaining'], 2)) ?> <?= e($uom) ?></p>
                </div>
            </div>
        </div>
    </div>

    <?php /*  Received minus remaining should equal what the trace
              adds up to. When it does not, something moved this
              batch without writing an allocation — a stock take,
              a hand-edit — and a recall list built from the trace
              is short by exactly that much. Better said out loud
              than quietly wrong.                                */ ?>
    <?php if (abs($unexplained) > 0.0005): ?>
        <div class="alert alert--warning" role="status">
            <span>
                <strong><?= e(num(abs($unexplained), 2)) ?></strong>
                <?= e($uom ?: 'units') ?> of this batch left the shelf without being
                dispensed through the counter and without being written off,
                so they are not in the list below. A stock take or a manual
                adjustment does this. The recall list is short by that much.
            </span>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Who received it</h2>
            <span class="hint"><?= e(num($went, 2)) ?> <?= e($uom) ?> dispensed</span>
        </div>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Sale</th>
                        <th>Patient</th>
                        <th>Paid by</th>
                        <th>Prescriber</th>
                        <th>Served by</th>
                        <th class="ta-right">Quantity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$trace): ?>
                        <tr><td colspan="7" class="table-empty">
                            None of this batch has been dispensed yet.
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($trace as $t): ?>
                        <?php $isReturn = (float) $t['quantity'] < 0; ?>
                        <tr>
                            <td><?= e(fmt_date($t['dispensed_at'])) ?></td>
                            <td>
                                <?php if ($t['invoice_number']): ?>
                                    <?= e($t['invoice_number']) ?>
                                <?php else: ?>
                                    <span class="hint"><?= e($t['reference_table'] ?: '—') ?></span>
                                <?php endif; ?>
                                <?php if ($isReturn): ?>
                                    <span class="badge badge--inactive">Returned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($t['patient_name']): ?>
                                    <a href="<?= e(url('patients/index.php?q=' . urlencode((string) $t['patient_number']))) ?>">
                                        <?= e($t['patient_name']) ?>
                                    </a>
                                    <?php if ($t['patient_phone']): ?>
                                        <div class="hint"><?= e($t['patient_phone']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php /*  No patient attached is the ordinary case for
                                              somebody buying off the shelf. It is also
                                              exactly who a recall cannot reach, so it is
                                              said plainly rather than left blank.       */ ?>
                                    <span class="hint">Not recorded &mdash; cannot be traced</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($t['customer_name'] ?: '—') ?></td>
                            <td><?= e($t['prescriber'] ?: '—') ?></td>
                            <td><?= e($t['dispensed_by_name'] ?: '—') ?></td>
                            <td class="ta-right"><?= e(num($t['quantity'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php /*  Written off already. Shown above the form, because the
              first question somebody opening this page asks is
              whether the shelf was already cleared — and doing it
              twice takes stock that is not there.               */ ?>
    <?php if ($disposals): ?>
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Written off</h2>
                <span class="hint"><?= e(num($disposed, 2)) ?> <?= e($uom) ?> destroyed</span>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Reason</th>
                            <th>Witnessed by</th>
                            <th>Recorded by</th>
                            <th>Note</th>
                            <th class="ta-right">Quantity</th>
                            <th class="ta-right">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($disposals as $d): ?>
                            <tr>
                                <td><?= e(fmt_date($d['disposed_at'])) ?></td>
                                <td><?= e(DISPOSAL_REASONS[$d['reason']] ?? $d['reason']) ?></td>
                                <td><?= e($d['witnessed_by'] ?: '—') ?></td>
                                <td><?= e($d['disposed_by_name'] ?: '—') ?></td>
                                <td><?= e($d['notes'] ?: '—') ?></td>
                                <td class="ta-right"><?= e(num($d['quantity'], 2)) ?></td>
                                <td class="ta-right">
                                    <?= e(money((float) $d['quantity'] * (float) ($d['unit_cost'] ?? 0))) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($canWrite && (float) $batch['quantity_remaining'] > 0): ?>
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Write stock off</h2>
            </div>
            <form method="POST" action="<?= e(url('modules/inventory/batches.php')) ?>" class="u-pad">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="write_off">
                <input type="hidden" name="batch_id" value="<?= (int) $batch['batch_id'] ?>">

                <p class="hint">
                    For stock that has physically left the shelf and cannot be sold.
                    This is not a stock count: it records that <em>this</em> batch was
                    destroyed, on this date, for this reason.
                </p>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="quantity">
                            Quantity <span class="req">*</span>
                        </label>
                        <input type="number" id="quantity" name="quantity" class="form-control"
                               min="0.001" step="any"
                               max="<?= e((string) $batch['quantity_remaining']) ?>"
                               value="<?= e($batch['state'] === 'expired'
                                             ? (string) $batch['quantity_remaining'] : '') ?>"
                               required>
                        <?php /*  An expired batch is almost always destroyed whole,
                                  so it is filled in. Anything else starts empty:
                                  a pre-filled number somebody did not mean to
                                  accept is how the wrong quantity gets written. */ ?>
                        <span class="hint"><?= e(num($batch['quantity_remaining'], 2)) ?> <?= e($uom) ?> available</span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="reason">Reason <span class="req">*</span></label>
                        <select id="reason" name="reason" class="form-control" required>
                            <?php foreach (DISPOSAL_REASONS as $k => $label): ?>
                                <option value="<?= e($k) ?>"
                                    <?= ($batch['state'] === 'expired' && $k === 'expired') ? 'selected' : '' ?>>
                                    <?= e($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="witnessed_by">Witnessed by</label>
                        <input type="text" id="witnessed_by" name="witnessed_by" class="form-control"
                               maxlength="160" placeholder="Name of the second person present">
                        <?php if ($batch['controlled_schedule']): ?>
                            <span class="hint">
                                This is a controlled drug. Destruction is supposed to be
                                witnessed, and this system can only record the name &mdash;
                                it cannot confirm anybody was there.
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="notes">Note</label>
                        <input type="text" id="notes" name="notes" class="form-control"
                               maxlength="255" placeholder="Anything worth recording">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary">Write off</button>
            </form>
        </div>
    <?php endif; ?>

    <?php
    require __DIR__ . '/../../../includes/footer.php';
    exit;
}

/* ═══ The list ══════════════════════════════════════════════ */

$state     = (string) ($_GET['state'] ?? '');
$search    = trim((string) ($_GET['q'] ?? ''));
$productId = input_int($_GET, 'product_id');

$counts  = batch_state_counts();
$filters = ['state' => $state, 'q' => $search, 'product_id' => $productId];

//  Count the filtered set first, then size the page from it, so
//  the navigation counts what is actually on screen.
$probe   = batch_list($filters, 1, 0);
$pg      = paginate($probe['total'], PER_PAGE_DEFAULT);
$batches = batch_list($filters, $pg['per_page'], $pg['offset'])['rows'];

$product = $productId
    ? db_one('SELECT name, generic_name, strength, dosage_form FROM products WHERE product_id = :id',
             [':id' => $productId])
    : null;

$pageTitle   = 'Batches';
$pageStyles  = ['forms.css', 'finance.css'];
$breadcrumbs = [['label' => 'Inventory'], ['label' => 'Batches']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter([
    'q'          => $search,
    'state'      => $state,
    'product_id' => $productId,
], fn($v) => $v !== '' && $v !== null);
?>

<div class="toolbar">
    <form class="toolbar-search filter-grow" method="GET" action="<?= e(url('modules/inventory/batches.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="Drug, generic or batch number&hellip;">
        <?php if ($state !== ''): ?>
            <input type="hidden" name="state" value="<?= e($state) ?>">
        <?php endif; ?>
        <?= toolbar_apply('modules/inventory/batches.php', ['state']) ?>
    </form>
</div>

<?php if ($product): ?>
    <div class="alert alert--info" role="status">
        <span>Showing batches of <strong><?= e(drug_label($product)) ?></strong> only.</span>
        <a href="<?= e(url('modules/inventory/batches.php')) ?>">Show every drug</a>
    </div>
<?php endif; ?>

<?php
/*  The counts ride in the chip labels because the count is the
    decision. "Expired (3)" is read as a job to do; "Expired" is
    read as a filter somebody might try.                        */
$chips = [
    ''         => ['All', $counts['all']],
    'expired'  => ['Expired', $counts['expired']],
    'expiring' => ['Expiring soon', $counts['expiring']],
    'ok'       => ['In date', $counts['ok']],
    'depleted' => ['Sold out', $counts['depleted']],
];
?>
<div class="toolbar">
    <div class="u-flex u-gap">
        <?php foreach ($chips as $key => [$label, $n]): ?>
            <?php $q = array_filter(
                ['q' => $search, 'product_id' => $productId, 'state' => $key],
                fn($v) => $v !== '' && $v !== null
            ); ?>
            <a class="chip<?= $state === $key ? ' chip--on' : '' ?>"
               href="<?= e(url('modules/inventory/batches.php' . ($q ? '?' . http_build_query($q) : ''))) ?>">
                <?= e($label) ?> (<?= (int) $n ?>)
            </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Medicine</th>
                    <th>Batch</th>
                    <th>Expires</th>
                    <th class="ta-right">Remaining</th>
                    <th class="ta-right">Value</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$batches): ?>
                    <tr><td colspan="7" class="table-empty">
                        <?= $counts['all'] === 0
                            ? 'No batches recorded yet. Batch numbers and expiry dates are entered on a goods received note.'
                            : 'No batches match that.' ?>
                    </td></tr>
                <?php endif; ?>
                <?php foreach ($batches as $b): ?>
                    <?php [$label, $tone] = batch_state_label((string) $b['state']); ?>
                    <tr>
                        <td>
                            <a href="<?= e(url('modules/inventory/product_card.php?id=' . (int) $b['product_id'])) ?>">
                                <?= e(drug_label($b)) ?>
                            </a>
                            <?php if ($b['generic_name']): ?>
                                <div class="hint"><?= e($b['generic_name']) ?></div>
                            <?php endif; ?>
                            <?php if ($b['controlled_schedule']): ?>
                                <span class="badge badge--warn">
                                    <?= e(CONTROLLED_SCHEDULES[$b['controlled_schedule']] ?? 'Controlled') ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?= e($b['batch_number']) ?></strong></td>
                        <td>
                            <?php if ($b['expiry_date']): ?>
                                <?= e(fmt_date($b['expiry_date'])) ?>
                                <?php if ($b['state'] === 'expiring'): ?>
                                    <div class="hint"><?= (int) $b['days_left'] ?> days</div>
                                <?php elseif ($b['state'] === 'expired'): ?>
                                    <div class="hint"><?= abs((int) $b['days_left']) ?> days ago</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="hint">&mdash;</span>
                            <?php endif; ?>
                        </td>
                        <td class="ta-right">
                            <?= e(num($b['quantity_remaining'], 2)) ?>
                            <span class="hint"><?= e($b['uom_abbr'] ?: '') ?></span>
                        </td>
                        <td class="ta-right"><?= e(money($b['value_remaining'])) ?></td>
                        <td><span class="badge badge--<?= e($tone) ?>"><?= e($label) ?></span></td>
                        <td class="ta-right">
                            <a class="btn btn-ghost btn-sm"
                               href="<?= e(url('modules/inventory/batches.php?batch_id=' . (int) $b['batch_id'])) ?>">
                                Trace
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php pagination_nav($pg, $baseQuery, 'batch'); ?>
</div>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
