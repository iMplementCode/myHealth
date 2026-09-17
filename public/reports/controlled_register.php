<?php

/**
 * ============================================================
 *  Controlled Drugs Register
 * ------------------------------------------------------------
 *  A register per controlled drug: opening balance, every
 *  receipt and every issue in date order, balance carried down.
 *  This is what an inspector asks to see, and until now the only
 *  honest answer was that the system recorded the flag and not
 *  the register.
 *
 *  It is a report rather than a ledger of its own, because every
 *  entry it shows is already recorded elsewhere — in a batch or
 *  an allocation — and a second copy that could disagree with
 *  the first would be worse than none. What it cannot do is
 *  stated on the page instead of being left to be discovered.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/reports.php';
require_once __DIR__ . '/../../includes/controlled.php';
require_once __DIR__ . '/../../includes/pharmacy.php';
require_once __DIR__ . '/../../includes/export.php';
require_once __DIR__ . '/../../includes/icons.php';

//  Controlled stock is the Manager's responsibility, and the
//  register names patients. Same guard as every other report.
require_role(ROLE_MANAGER);

['from' => $from, 'to' => $to] = report_period($_GET);

$drugs     = controlled_drugs();
$productId = input_int($_GET, 'product_id');

//  With one controlled drug on the shelf, showing a chooser and
//  an empty page is a click that tells nobody anything.
if (!$productId && count($drugs) === 1) {
    $productId = (int) $drugs[0]['product_id'];
}

$drug     = null;
$register = null;
foreach ($drugs as $d) {
    if ((int) $d['product_id'] === $productId) {
        $drug = $d;
        break;
    }
}
if ($drug) {
    $register = controlled_register($productId, $from, $to);
}

if ($drug && wants_export()) {
    export_deliver(export_filename('controlled-register-' . slugify((string) $drug['name'])), [
        [
            'name'  => 'Register',
            'title' => 'Controlled drugs register — ' . drug_label($drug),
            'meta'  => export_meta([
                'Drug'            => drug_label($drug),
                'Generic'         => (string) ($drug['generic_name'] ?: '—'),
                'Schedule'        => CONTROLLED_SCHEDULES[$drug['controlled_schedule']]
                                     ?? (string) $drug['controlled_schedule'],
                'Period'          => report_period_label($from, $to),
                'Opening balance' => num($register['opening'], 2),
                'Received'        => num($register['in'], 2),
                'Issued'          => num($register['out'], 2),
                'Closing balance' => num($register['closing'], 2),
                'Basis'           => 'Derived from recorded batches and dispensing. '
                                   . 'Stock moved by a stock take does not appear.',
            ]),
            'columns' => [
                ['label' => 'Date',       'key' => 'date'],
                ['label' => 'Entry',      'key' => 'entry'],
                ['label' => 'Batch',      'key' => 'batch'],
                ['label' => 'Reference',  'key' => 'reference'],
                ['label' => 'From / to',  'key' => 'party'],
                ['label' => 'Prescriber', 'key' => 'prescriber'],
                ['label' => 'Dispensed by', 'key' => 'person'],
                ['label' => 'In',         'key' => 'qty_in',  'type' => 'number'],
                ['label' => 'Out',        'key' => 'qty_out', 'type' => 'number'],
                ['label' => 'Balance',    'key' => 'balance', 'type' => 'number'],
            ],
            'rows' => array_map(static function (array $e): array {
                return [
                    'date'       => fmt_date($e['date']),
                    'entry'      => ['in' => 'Received', 'out' => 'Issued', 'return' => 'Returned'][$e['direction']],
                    'batch'      => $e['batch'],
                    'reference'  => $e['reference'],
                    'party'      => $e['party'],
                    'prescriber' => $e['prescriber'],
                    'person'     => $e['person'],
                    'qty_in'     => $e['direction'] === 'in' ? $e['quantity'] : null,
                    'qty_out'    => $e['direction'] === 'in' ? null : $e['quantity'],
                    'balance'    => $e['balance'],
                ];
            }, $register['entries']),
        ],
    ]);
}

$pageTitle    = 'Controlled Drugs Register';
$pageSubtitle = report_period_label($from, $to);
$pageStyles   = ['forms.css', 'dashboard.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Reports', 'href' => 'reports/index.php'], ['label' => 'Controlled Drugs Register']];

require __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_period_bar.php';
?>

<?php if (!$drugs): ?>
    <div class="alert alert--info" role="status">
        <span>
            No drug in the catalogue is marked as controlled, so there is nothing to
            register. The schedule is set on the product, under Inventory &rarr; Products.
        </span>
    </div>
<?php else: ?>

    <div class="toolbar">
        <div class="u-flex u-gap">
            <?php foreach ($drugs as $d): ?>
                <?php $q = ['product_id' => (int) $d['product_id'], 'from' => $from, 'to' => $to]; ?>
                <a class="chip<?= (int) $d['product_id'] === $productId ? ' chip--on' : '' ?>"
                   href="<?= e(url('reports/controlled_register.php?' . http_build_query($q))) ?>">
                    <?= e(drug_label($d)) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php if ($drug): ?>
            <?= export_button('reports/controlled_register.php',
                              ['product_id' => $productId, 'from' => $from, 'to' => $to]) ?>
        <?php endif; ?>
    </div>

    <?php if (!$drug): ?>
        <div class="alert alert--info" role="status">
            <span>Choose a drug above to see its register.</span>
        </div>
    <?php else: ?>

        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title"><?= e(drug_label($drug)) ?></h2>
                <span class="badge badge--warn">
                    <?= e(CONTROLLED_SCHEDULES[$drug['controlled_schedule']] ?? 'Controlled') ?>
                </span>
            </div>
            <div class="u-pad">
                <div class="form-grid-2">
                    <div>
                        <div class="form-label">Opening balance</div>
                        <p class="u-nomargin"><?= e(num($register['opening'], 2)) ?> <?= e($drug['uom_abbr'] ?: '') ?></p>
                    </div>
                    <div>
                        <div class="form-label">Closing balance</div>
                        <p class="u-nomargin"><?= e(num($register['closing'], 2)) ?> <?= e($drug['uom_abbr'] ?: '') ?></p>
                    </div>
                    <div>
                        <div class="form-label">Received in period</div>
                        <p class="u-nomargin"><?= e(num($register['in'], 2)) ?></p>
                    </div>
                    <div>
                        <div class="form-label">Issued in period</div>
                        <p class="u-nomargin"><?= e(num($register['out'], 2)) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <?php
        /*  The register's closing balance is built from movements.
            The shelf figure is maintained by other code — the GRN,
            the stock take. When the two disagree, one of them is
            wrong about a controlled drug, which is the single
            thing on this page nobody may be left to discover on
            their own.                                            */
        $shelf = (float) $drug['batch_balance'];
        $isToday = $to >= date('Y-m-d');
        ?>
        <?php if ($isToday && abs($shelf - (float) $register['closing']) > 0.0005): ?>
            <div class="alert alert--error" role="status">
                <span>
                    This register closes at <strong><?= e(num($register['closing'], 2)) ?></strong>
                    but the batches hold <strong><?= e(num($shelf, 2)) ?></strong>.
                    A controlled drug has moved without a recorded receipt or issue —
                    a stock take adjustment will do this. Reconcile it before anybody
                    is asked to sign for the balance.
                </span>
            </div>
        <?php endif; ?>

        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Movements</h2>
            </div>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Entry</th>
                            <th>Batch</th>
                            <th>Reference</th>
                            <th>From / to</th>
                            <th>Prescriber</th>
                            <th>Dispensed by</th>
                            <th class="ta-right">In</th>
                            <th class="ta-right">Out</th>
                            <th class="ta-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="9"><em>Balance brought forward</em></td>
                            <td class="ta-right"><strong><?= e(num($register['opening'], 2)) ?></strong></td>
                        </tr>
                        <?php if (!$register['entries']): ?>
                            <tr><td colspan="10" class="table-empty">
                                Nothing received or issued in this period.
                            </td></tr>
                        <?php endif; ?>
                        <?php foreach ($register['entries'] as $entry): ?>
                            <tr>
                                <td><?= e(fmt_date($entry['date'])) ?></td>
                                <td>
                                    <?php if ($entry['direction'] === 'in'): ?>
                                        <span class="badge badge--received">Received</span>
                                    <?php elseif ($entry['direction'] === 'return'): ?>
                                        <span class="badge badge--inactive">Returned</span>
                                    <?php else: ?>
                                        <span class="badge badge--info">Issued</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($entry['batch'] ?: '—') ?></td>
                                <td><?= e($entry['reference'] ?: '—') ?></td>
                                <td>
                                    <?= $entry['party'] !== ''
                                        ? e($entry['party'])
                                        : '<span class="hint">Not recorded</span>' ?>
                                </td>
                                <td><?= e($entry['prescriber'] ?: '—') ?></td>
                                <td><?= e($entry['person'] ?: '—') ?></td>
                                <td class="ta-right">
                                    <?= $entry['direction'] === 'in' ? e(num($entry['quantity'], 2)) : '' ?>
                                </td>
                                <td class="ta-right">
                                    <?= $entry['direction'] !== 'in' ? e(num($entry['quantity'], 2)) : '' ?>
                                </td>
                                <td class="ta-right"><strong><?= e(num($entry['balance'], 2)) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php /*  Said on the page, not only in the documentation.
                  Whoever prints this is answering to somebody, and
                  needs to know what it is before they hand it over. */ ?>
        <div class="alert alert--info" role="status">
            <span>
                <strong>What this register is.</strong>
                It is built from the batches recorded on goods received notes and the
                medicines dispensed at the counter. Stock moved any other way — a stock
                take adjustment, or a drug whose batches were never entered — does not
                appear as a movement. Entries are not individually signed or witnessed,
                so this does not replace a bound register where one is required by law.
            </span>
        </div>

    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
