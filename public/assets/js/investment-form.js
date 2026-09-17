/* ============================================================
 *  Investment form
 * ------------------------------------------------------------
 *  Equity and a loan need different terms, and the two sets are
 *  mutually exclusive — the database refuses an equity row that
 *  carries loan terms. Hiding the wrong set also disables it, so
 *  a stale value cannot be submitted after switching kind.
 * ============================================================ */
(function () {
    'use strict';

    var HELP = {
        equity: 'Equity is capital: never repaid, and it raises the net asset value.',
        loan: 'A loan raises cash but the business owes it back, so it sits under liabilities.',
    };

    function apply(form) {
        var type = form.querySelector('[data-investment-type]');
        if (!type) return;
        var isLoan = type.value === 'loan';

        toggle(form.querySelector('[data-investment-equity]'), !isLoan);
        toggle(form.querySelector('[data-investment-loan]'), isLoan);

        var help = form.querySelector('[data-investment-help]');
        if (help) help.textContent = HELP[type.value] || '';
    }

    function toggle(wrap, show) {
        if (!wrap) return;
        wrap.hidden = !show;
        wrap.querySelectorAll('input, select').forEach(function (f) {
            f.disabled = !show;
            if (!show) f.value = '';
        });
    }

    function init(form) {
        if (form.dataset.investReady) return;
        form.dataset.investReady = '1';
        form.querySelector('[data-investment-type]')
            ?.addEventListener('change', function () { apply(form); });
        apply(form);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('form[data-investment-form]').forEach(init);
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
