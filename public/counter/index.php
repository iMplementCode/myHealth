<?php

/**
 * ============================================================
 *  The counter
 * ------------------------------------------------------------
 *  Search, add, take the money, print. One screen.
 *
 *  Everything that decides whether the sale is legal or possible
 *  is in includes/counter.php. This page collects and displays;
 *  it does not judge. The search is a small JSON endpoint on the
 *  same file so that typing a drug name does not reload the page
 *  and lose the basket.
 * ============================================================
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/counter.php';
require_once __DIR__ . '/../../includes/icons.php';

require_role(ROLE_SALES);

/* ── Search, as JSON, for the type-ahead ──────────────────── */
if (($_GET['find'] ?? '') !== '') {
    json_response([
        'success' => true,
        'results' => array_map(static function (array $r): array {
            return [
                'id'        => (int) $r['product_id'],
                'label'     => drug_label($r),
                'generic'   => drug_generic_note($r),
                'sku'       => $r['sku'],
                'price'     => (float) $r['selling_price'],
                'available' => (float) $r['available'],
                'expired'   => (float) $r['expired'],
                'tracked'   => (bool) $r['tracked'],
                'rx'        => (bool) $r['requires_rx'],
                'controlled' => $r['controlled_schedule'],
                'uom'       => $r['uom_abbr'] ?: '',
                'storage'   => $r['storage'],
            ];
        }, counter_search((string) $_GET['find'])),
    ]);
}

/* ── Completing a sale ────────────────────────────────────── */
if (is_post()) {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'That form had gone stale. Reload and try again.'], 403);
    }

    $lines = [];
    foreach ((array) ($_POST['line'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lines[] = [
            'product_id' => (int) ($row['product_id'] ?? 0),
            'quantity'   => (float) ($row['quantity'] ?? 0),
            'unit_price' => $row['unit_price'] ?? '',
        ];
    }

    $result = counter_complete($lines, [
        'method'           => (string) ($_POST['method'] ?? ''),
        'cash_account_id'  => (int) ($_POST['cash_account_id'] ?? 0),
        'customer_id'      => (int) ($_POST['customer_id'] ?? 0),
        'patient_id'       => (int) ($_POST['patient_id'] ?? 0),
        'prescriber'       => (string) ($_POST['prescriber'] ?? ''),
        'prescription_ref' => (string) ($_POST['prescription_ref'] ?? ''),
    ]);

    json_response([
        'success'  => $result['ok'],
        'message'  => $result['message'],
        'invoice'  => $result['invoice_id'],
        'number'   => $result['invoice_number'],
        'receipt'  => $result['invoice_id']
            ? url('invoices/receipt.php?id=' . $result['invoice_id'])
            : null,
    ], $result['ok'] ? 200 : 422);
}

$tills = db_all(
    'SELECT cash_account_id, name FROM cash_accounts
      WHERE is_active ORDER BY sort_order NULLS LAST, name'
);
$walkIn = counter_walk_in_customer();

$pageTitle    = 'Counter';
/*  forms.css styles every control on this page — .form-control,
    .form-label, .hint, .form-grid-2 — and finance.css carries the
    .u-pad the panels use. Without them the browser's own widgets
    show through, which is what this screen shipped doing.        */
$pageStyles   = ['forms.css', 'finance.css'];
$breadcrumbs  = [['label' => 'Counter']];
require __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1 class="page-title"><?= icon('cart') ?> Counter</h1>
        <p class="page-sub">Find it, add it, take the money.</p>
    </div>
</div>

<?php if (!$walkIn): ?>
    <div class="alert alert--error" role="status">
        <span>This shop has no walk-in customer record, so a sale cannot be
        invoiced. Ask an administrator to restore it.</span>
    </div>
<?php endif; ?>

<div class="counter-grid">

    <?php /* ── Left: find and add ─────────────────────────── */ ?>
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">What are they buying?</h2>
        </div>
        <div class="u-pad">
            <div class="form-group">
                <label class="form-label" for="find">Search by brand, generic or code</label>
                <input type="search" id="find" class="form-control" autocomplete="off"
                       placeholder="panadol, paracetamol, PAN500&hellip;" autofocus>
                <span class="hint">Somebody asking for Panadol and somebody asking for
                    Hedex want the same thing &mdash; either finds it.</span>
            </div>
            <div id="results" class="counter-results" role="listbox"></div>
        </div>
    </div>

    <?php /* ── Right: the basket ──────────────────────────── */ ?>
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">This sale</h2>
        </div>

        <form id="sale" method="POST" autocomplete="off">
            <?= csrf_field() ?>

            <div class="u-pad" style="padding-bottom:0;">
                <div class="form-group">
                    <label class="form-label" for="patient">Patient <span class="opt">(optional)</span></label>
                    <input type="search" id="patient" class="form-control" autocomplete="off"
                           placeholder="Name, number or phone&hellip;">
                    <input type="hidden" name="patient_id" id="patient_id" value="">
                    <span class="hint">Leave empty for somebody buying off the shelf.</span>
                    <div id="patient-hits" class="counter-results"></div>
                    <div id="patient-chosen" hidden></div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table counter-basket" id="basket">
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th class="ta-right">Qty</th>
                            <th class="ta-right">Price</th>
                            <th class="ta-right">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr data-empty>
                            <td colspan="5" class="table-empty">Nothing on this sale yet.</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="3" class="ta-right">Total</th>
                            <th class="ta-right" id="total">0.00</th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="u-pad">
                <?php /*  Shown only when something on the sale needs it.
                          A prescriber box on every sale is a box that
                          gets filled with a full stop. */ ?>
                <div class="form-grid-2" id="rx-block" hidden>
                    <div class="form-group">
                        <label class="form-label" for="prescriber">Prescribed by <span class="req">*</span></label>
                        <input type="text" id="prescriber" name="prescriber" class="form-control"
                               maxlength="160" placeholder="Dr. …">
                        <span class="hint">Required: this sale includes a
                            prescription-only medicine.</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="prescription_ref">Prescription no.</label>
                        <input type="text" id="prescription_ref" name="prescription_ref"
                               class="form-control" maxlength="60">
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label class="form-label" for="method">Paying by</label>
                        <select id="method" name="method" class="form-control">
                            <?php foreach (COUNTER_METHODS as $k => $label): ?>
                                <option value="<?= e($k) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="cash_account_id">Into</label>
                        <select id="cash_account_id" name="cash_account_id" class="form-control">
                            <?php foreach ($tills as $t): ?>
                                <option value="<?= (int) $t['cash_account_id'] ?>"><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div id="sale-message"></div>

                <button type="submit" class="btn btn-primary btn-block" id="take-money" disabled>
                    <?= icon('money') ?> Take payment
                </button>
            </div>
        </form>
    </div>
</div>

<script nonce="<?= csp_nonce() ?>">
(function () {
    'use strict';

    var find    = document.getElementById('find');
    var results = document.getElementById('results');
    var body    = document.querySelector('#basket tbody');
    var totalEl = document.getElementById('total');
    var rxBlock = document.getElementById('rx-block');
    var button  = document.getElementById('take-money');
    var form    = document.getElementById('sale');
    var msg     = document.getElementById('sale-message');

    var basket = [];   // {id, label, price, qty, available, rx, uom}

    function money(n) {
        return Number(n).toLocaleString(undefined, {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    }

    function alertBox(kind, text) {
        msg.innerHTML = '';
        if (!text) { return; }
        var d = document.createElement('div');
        d.className = 'alert alert--' + kind;
        d.setAttribute('role', 'status');
        var s = document.createElement('span');
        s.textContent = text;          // never innerHTML: this carries drug names
        d.appendChild(s);
        msg.appendChild(d);
    }

    function render() {
        body.innerHTML = '';
        var total = 0;
        var needsRx = false;

        if (!basket.length) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-empty', '');
            var td = document.createElement('td');
            td.colSpan = 5;
            td.className = 'table-empty';
            td.textContent = 'Nothing on this sale yet.';
            tr.appendChild(td);
            body.appendChild(tr);
        }

        basket.forEach(function (item, i) {
            total += item.qty * item.price;
            if (item.rx) { needsRx = true; }

            var tr = document.createElement('tr');

            var name = document.createElement('td');
            var strong = document.createElement('span');
            strong.className = 'cell-title';
            strong.textContent = item.label;
            name.appendChild(strong);
            if (item.rx) {
                var tag = document.createElement('span');
                tag.className = 'badge badge--pending';
                tag.textContent = 'Rx';
                tag.style.marginLeft = '6px';
                name.appendChild(tag);
            }
            //  The warning that matters: they have asked for more
            //  than can be handed over. Said here rather than only
            //  on submit, while there is still time to offer
            //  something else.
            if (item.qty > item.available) {
                var warn = document.createElement('span');
                warn.className = 'cell-sub';
                warn.style.color = 'var(--danger, #e5484d)';
                warn.textContent = 'only ' + item.available + ' ' + (item.uom || '') + ' can be dispensed';
                name.appendChild(warn);
            }
            tr.appendChild(name);

            var qtd = document.createElement('td');
            qtd.className = 'ta-right';
            var q = document.createElement('input');
            q.type = 'number'; q.min = '0.001'; q.step = 'any';
            q.className = 'form-control counter-num counter-qty';
            q.value = item.qty;
            q.setAttribute('aria-label', 'Quantity of ' + item.label);
            q.addEventListener('input', function () {
                basket[i].qty = parseFloat(q.value) || 0;
                render();
            });
            qtd.appendChild(q);
            tr.appendChild(qtd);

            var ptd = document.createElement('td');
            ptd.className = 'ta-right';
            var p = document.createElement('input');
            p.type = 'number'; p.min = '0'; p.step = '0.01';
            p.className = 'form-control counter-num counter-price';
            p.value = Number(item.price).toFixed(2);
            p.setAttribute('aria-label', 'Unit price of ' + item.label);
            p.addEventListener('input', function () {
                basket[i].price = parseFloat(p.value) || 0;
                render();
            });
            ptd.appendChild(p);
            tr.appendChild(ptd);

            var atd = document.createElement('td');
            atd.className = 'ta-right';
            atd.textContent = money(item.qty * item.price);
            tr.appendChild(atd);

            var xtd = document.createElement('td');
            xtd.className = 'ta-right';
            var x = document.createElement('button');
            x.type = 'button';
            /*  An icon, not the word: "Remove" spelled out cost about
                sixty pixels per row, which was enough to push this
                column past the edge of the panel and leave the
                button reachable only by scrolling sideways. The
                label stays for a screen reader. */
            x.className = 'btn btn-ghost btn-sm counter-remove';
            x.textContent = '\u00d7';
            x.title = 'Remove';
            x.setAttribute('aria-label', 'Remove ' + item.label + ' from this sale');
            x.addEventListener('click', function () {
                basket.splice(i, 1);
                render();
            });
            xtd.appendChild(x);
            tr.appendChild(xtd);

            body.appendChild(tr);
        });

        totalEl.textContent = money(total);
        rxBlock.hidden = !needsRx;
        button.disabled = basket.length === 0;

        //  Hidden inputs, rebuilt each render, so the form posts
        //  exactly what is on screen.
        Array.prototype.slice.call(form.querySelectorAll('[data-line]')).forEach(function (el) {
            el.parentNode.removeChild(el);
        });
        basket.forEach(function (item, i) {
            [['product_id', item.id], ['quantity', item.qty], ['unit_price', item.price]]
                .forEach(function (pair) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.setAttribute('data-line', '');
                    h.name = 'line[' + i + '][' + pair[0] + ']';
                    h.value = pair[1];
                    form.appendChild(h);
                });
        });
    }

    function add(r) {
        var existing = null;
        basket.forEach(function (b) { if (b.id === r.id) { existing = b; } });
        if (existing) {
            existing.qty += 1;
        } else {
            basket.push({
                id: r.id, label: r.label, price: r.price, qty: 1,
                available: r.available, rx: r.rx, uom: r.uom
            });
        }
        find.value = '';
        results.innerHTML = '';
        find.focus();
        render();
    }

    var timer = null;
    find.addEventListener('input', function () {
        clearTimeout(timer);
        var term = find.value.trim();
        if (term.length < 2) { results.innerHTML = ''; return; }
        timer = setTimeout(function () {
            fetch(window.location.pathname + '?find=' + encodeURIComponent(term), {
                headers: { 'Accept': 'application/json' }
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                results.innerHTML = '';
                (data.results || []).forEach(function (r) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'counter-hit';
                    b.disabled = r.available <= 0;

                    var top = document.createElement('span');
                    top.className = 'counter-hit-name';
                    top.textContent = r.label;
                    b.appendChild(top);

                    var sub = document.createElement('span');
                    sub.className = 'counter-hit-sub';
                    var bits = [];
                    if (r.generic) { bits.push(r.generic); }
                    bits.push(money(r.price));
                    if (r.available > 0) {
                        bits.push(r.available + ' ' + (r.uom || '') + ' available');
                    } else {
                        bits.push('out of stock');
                    }
                    if (r.expired > 0) { bits.push(r.expired + ' expired'); }
                    if (r.rx) { bits.push('prescription only'); }
                    if (r.controlled) { bits.push('controlled'); }
                    sub.textContent = bits.join(' · ');
                    b.appendChild(sub);

                    b.addEventListener('click', function () { add(r); });
                    results.appendChild(b);
                });
                if (!(data.results || []).length) {
                    var none = document.createElement('p');
                    none.className = 'table-empty';
                    none.textContent = 'Nothing matches that.';
                    results.appendChild(none);
                }
            })
            .catch(function () { alertBox('error', 'The search could not be reached.'); });
        }, 180);
    });

    /* ── Who it is for ──────────────────────────────────────
       Optional, and the reason it is here at all is the allergy
       line: the person dispensing has to see it before they hand
       anything over. Nothing is matched automatically — see
       includes/patients.php. */
    var patientBox    = document.getElementById('patient');
    var patientHits   = document.getElementById('patient-hits');
    var patientId     = document.getElementById('patient_id');
    var patientChosen = document.getElementById('patient-chosen');

    function clearPatient() {
        patientId.value = '';
        patientChosen.hidden = true;
        patientChosen.innerHTML = '';
        patientBox.hidden = false;
    }

    function choosePatient(p) {
        patientId.value = p.id;
        patientHits.innerHTML = '';
        patientBox.value = '';
        patientBox.hidden = true;
        patientChosen.hidden = false;
        patientChosen.innerHTML = '';

        var line = document.createElement('div');
        line.className = 'alert alert--info';
        line.setAttribute('role', 'status');
        var who = document.createElement('span');
        who.textContent = p.name + ' · ' + p.number + (p.age ? ' · ' + p.age : '');
        line.appendChild(who);
        var drop = document.createElement('button');
        drop.type = 'button';
        drop.className = 'btn btn-ghost btn-sm';
        drop.textContent = 'Change';
        drop.addEventListener('click', clearPatient);
        line.appendChild(drop);
        patientChosen.appendChild(line);

        if (p.allergies) {
            var warn = document.createElement('div');
            warn.className = 'alert alert--error';
            warn.setAttribute('role', 'alert');
            var t = document.createElement('span');
            t.textContent = 'Allergies: ' + p.allergies;
            warn.appendChild(t);
            patientChosen.appendChild(warn);
        }
    }

    var ptimer = null;
    patientBox.addEventListener('input', function () {
        clearTimeout(ptimer);
        var term = patientBox.value.trim();
        if (term.length < 2) { patientHits.innerHTML = ''; return; }
        ptimer = setTimeout(function () {
            fetch('<?= e(url('patients/index.php')) ?>?find=' + encodeURIComponent(term),
                  { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                patientHits.innerHTML = '';
                (data.results || []).forEach(function (p) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'counter-hit';
                    var n = document.createElement('span');
                    n.className = 'counter-hit-name';
                    n.textContent = p.name;
                    b.appendChild(n);
                    var sub = document.createElement('span');
                    sub.className = 'counter-hit-sub';
                    var bits = [p.number];
                    if (p.age)   { bits.push(p.age); }
                    if (p.phone) { bits.push(p.phone); }
                    if (p.allergies) { bits.push('allergies recorded'); }
                    sub.textContent = bits.join(' · ');
                    b.appendChild(sub);
                    b.addEventListener('click', function () { choosePatient(p); });
                    patientHits.appendChild(b);
                });
            })
            .catch(function () { /* the sale does not depend on this */ });
        }, 180);
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        button.disabled = true;
        alertBox('', '');

        fetch(window.location.pathname, { method: 'POST', body: new FormData(form) })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    alertBox('error', data.message || 'The sale was refused.');
                    button.disabled = false;
                    return;
                }
                alertBox('success', data.message + ' ' + (data.number || ''));
                basket = [];
                clearPatient();
                render();
                if (data.receipt) { window.open(data.receipt, '_blank', 'noopener'); }
            })
            .catch(function () {
                alertBox('error', 'The sale could not be sent. Check the connection and try again.');
                button.disabled = false;
            });
    });

    render();
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
