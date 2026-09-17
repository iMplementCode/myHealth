/* ============================================================
 *  Credit-note form
 * ------------------------------------------------------------
 *  What a credit note needs depends on what is coming back:
 *
 *    full    — nothing to ask; every uncredited line is taken
 *    partial — the lines of the chosen invoice, with quantities
 *    none    — a single amount, because no goods move
 *
 *  Hidden fields are also disabled, so the form never posts a
 *  value belonging to a scope the user has moved away from.
 * ============================================================ */
(function () {
    'use strict';

    var LINES = {};
    try {
        var el = document.getElementById('cn-invoice-lines');
        if (el) LINES = JSON.parse(el.textContent) || {};
    } catch (e) { LINES = {}; }

    function fmt(n) {
        return (Math.round(n * 100) / 100).toLocaleString(undefined, {
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        });
    }

    function apply(form) {
        var scopeSel = form.querySelector('[data-cn-scope]');
        var invSel = form.querySelector('[data-cn-invoice]');
        if (!scopeSel || !invSel) return;

        var scope = scopeSel.value;
        toggle(form.querySelector('[data-cn-amount]'), scope === 'none');
        toggle(form.querySelector('[data-cn-lines]'), scope === 'partial');

        var opt = scopeSel.selectedOptions[0];
        var help = form.querySelector('[data-cn-scope-help]');
        if (help && opt) help.textContent = opt.dataset.help || '';

        // Warn up front when the customer has already paid: the
        // credit will turn into a refund the business owes.
        var invOpt = invSel.selectedOptions[0];
        var note = form.querySelector('[data-cn-paid-note]');
        if (note) {
            var paid = invOpt ? parseFloat(invOpt.dataset.paid) || 0 : 0;
            note.hidden = paid <= 0;
            if (paid > 0) {
                note.textContent = 'This invoice has KES ' + fmt(paid)
                    + ' already received. Crediting it will leave that money owed back to the customer.';
            }
        }

        if (scope === 'partial') renderLines(form, invSel.value);
    }

    function renderLines(form, invoiceId) {
        var body = form.querySelector('[data-cn-lines-body]');
        if (!body) return;
        body.innerHTML = '';

        var lines = LINES[invoiceId] || [];
        if (!lines.length) {
            body.innerHTML = '<p class="form-hint">Choose an invoice to list its lines.</p>';
            return;
        }

        lines.forEach(function (l) {
            var row = document.createElement('div');
            row.className = 'cn-line';

            var label = document.createElement('div');
            label.className = 'cn-line-name';
            label.innerHTML = '<strong></strong><span class="cell-muted"></span>';
            label.querySelector('strong').textContent = l.name;
            label.querySelector('span').textContent =
                (l.sku ? l.sku + ' · ' : '') + 'up to ' + fmt(l.remaining)
                + (l.delivered ? ' · ' + fmt(l.delivered) + ' delivered' : '');

            var pid = document.createElement('input');
            pid.type = 'hidden';
            pid.name = 'product_id[]';
            pid.value = l.product_id;

            var qty = document.createElement('input');
            qty.type = 'number';
            qty.name = 'quantity[]';
            qty.className = 'form-control';
            qty.min = '0';
            qty.max = String(l.remaining);
            qty.step = '0.001';
            qty.value = '0';

            row.appendChild(label);
            row.appendChild(pid);
            row.appendChild(qty);
            body.appendChild(row);
        });
    }

    function toggle(wrap, show) {
        if (!wrap) return;
        wrap.hidden = !show;
        wrap.querySelectorAll('input, select').forEach(function (f) {
            f.disabled = !show;
            if (f.dataset.wasRequired === undefined) {
                f.dataset.wasRequired = f.required ? '1' : '0';
            }
            f.required = show && f.dataset.wasRequired === '1';
        });
        // The amount is required only while it is the visible way
        // of stating the credit.
        var amount = wrap.querySelector('[name="credit_amount"]');
        if (amount) amount.required = show;
    }

    function init(form) {
        if (form.dataset.cnReady) return;
        form.dataset.cnReady = '1';
        form.querySelector('[data-cn-scope]')?.addEventListener('change', function () { apply(form); });
        form.querySelector('[data-cn-invoice]')?.addEventListener('change', function () { apply(form); });
        apply(form);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('form[data-credit-note]').forEach(init);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                Array.prototype.forEach.call(m.addedNodes, function (node) {
                    if (node.nodeType === 1) scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
