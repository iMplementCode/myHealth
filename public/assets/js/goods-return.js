/* ============================================================
 *  Goods-return receiving form
 * ------------------------------------------------------------
 *  Two ways to receive, because two things really happen:
 *
 *    all     — everything came back as expected. Nothing to
 *              count, so nothing is asked.
 *    counted — the lines are listed with their expected
 *              quantities, pre-filled, so the difference is a
 *              correction rather than data entry from scratch.
 *
 *  The line inputs are disabled while hidden, so an untouched
 *  count from a previous open cannot be submitted by accident.
 * ============================================================ */
(function () {
    'use strict';

    var LINES = {};
    try {
        var el = document.getElementById('return-lines');
        if (el) LINES = JSON.parse(el.textContent) || {};
    } catch (e) { LINES = {}; }

    var HELP = {
        all: 'Every expected quantity is received in full and goes back into stock.',
        counted: 'Enter what actually arrived on each line. Short quantities count as kept by the customer.',
    };

    function fmt(n) {
        return (Math.round(n * 1000) / 1000).toString();
    }

    function apply(form) {
        var mode = form.querySelector('[data-return-mode]');
        var wrap = form.querySelector('[data-return-lines]');
        if (!mode || !wrap) return;

        var counted = mode.value === 'counted';
        wrap.hidden = !counted;
        wrap.querySelectorAll('input, select').forEach(function (f) { f.disabled = !counted; });

        var help = form.querySelector('[data-return-mode-help]');
        if (help) help.textContent = HELP[mode.value] || '';

        if (counted) render(form);
    }

    function render(form) {
        var body = form.querySelector('[data-return-lines-body]');
        var idField = form.querySelector('[name="grn_return_id"]');
        if (!body || !idField) return;

        // Rebuilding on every switch would discard a half-typed
        // count, so the rows are built once per return note.
        if (body.dataset.builtFor === idField.value) return;
        body.dataset.builtFor = idField.value;
        body.innerHTML = '';

        var lines = LINES[idField.value] || [];
        if (!lines.length) {
            body.innerHTML = '<p class="form-hint">No lines on this return note.</p>';
            return;
        }

        lines.forEach(function (l) {
            var row = document.createElement('div');
            row.className = 'cn-line cn-line--return';

            var name = document.createElement('div');
            name.className = 'cn-line-name';
            name.innerHTML = '<strong></strong><span class="cell-muted"></span>';
            name.querySelector('strong').textContent = l.name;
            name.querySelector('span').textContent = l.sku || '';

            var expected = document.createElement('div');
            expected.className = 'cn-line-expected';
            expected.textContent = fmt(l.expected);

            var id = document.createElement('input');
            id.type = 'hidden';
            id.name = 'return_item_id[]';
            id.value = l.return_item_id;

            var qty = document.createElement('input');
            qty.type = 'number';
            qty.name = 'quantity_received[]';
            qty.className = 'form-control';
            qty.min = '0';
            qty.max = String(l.expected);
            qty.step = '0.001';
            qty.value = fmt(l.expected);

            var cond = document.createElement('select');
            cond.name = 'condition[]';
            cond.className = 'form-control';
            [['good', 'Good'], ['damaged', 'Damaged']].forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o[0];
                opt.textContent = o[1];
                cond.appendChild(opt);
            });

            row.appendChild(name);
            row.appendChild(expected);
            row.appendChild(id);
            row.appendChild(qty);
            row.appendChild(cond);
            body.appendChild(row);
        });
    }

    function init(form) {
        if (form.dataset.returnReady) return;
        form.dataset.returnReady = '1';
        form.querySelector('[data-return-mode]')
            ?.addEventListener('change', function () { apply(form); });
        apply(form);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('form[data-goods-return]').forEach(init);
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
