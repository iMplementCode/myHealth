/* ============================================================
 *  Staff-loan forms
 * ------------------------------------------------------------
 *  Two small behaviours:
 *
 *  1. Approve and Reject are one form with two submit buttons.
 *     Whichever was clicked sets the action, so the decision is
 *     unambiguous rather than inferred.
 *
 *  2. A salary deduction reduces what an employee owes without
 *     any money arriving, so the "paid into" account is hidden
 *     and disabled — a disabled field posts nothing, which is
 *     exactly what the server expects for that method.
 * ============================================================ */
(function () {
    'use strict';

    function initDecision(form) {
        var action = form.querySelector('[data-loan-decision]');
        if (!action) return;
        form.querySelectorAll('[type="submit"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                action.value = btn.hasAttribute('data-loan-reject') ? 'reject' : 'approve';
            });
        });
    }

    function applyRepay(form) {
        var method = form.querySelector('[data-repay-method]');
        var wrap = form.querySelector('[data-repay-account]');
        if (!method || !wrap) return;

        var needsAccount = method.value !== 'salary_deduction';
        wrap.hidden = !needsAccount;
        var select = wrap.querySelector('select');
        if (select) {
            select.disabled = !needsAccount;
            select.required = needsAccount;
            if (!needsAccount) select.value = '';
        }
    }

    function init(form) {
        if (form.dataset.loanReady) return;
        form.dataset.loanReady = '1';

        initDecision(form);

        if (form.hasAttribute('data-loan-repay')) {
            form.querySelector('[data-repay-method]')
                ?.addEventListener('change', function () { applyRepay(form); });
            applyRepay(form);
        }
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('form[data-loan-repay]').forEach(init);
        // The decision form is found through its marker field rather
        // than with :has(), which an unsupporting browser would throw
        // a SyntaxError on — taking the whole scan down with it.
        scope.querySelectorAll('[data-loan-decision]').forEach(function (el) {
            if (el.form) init(el.form);
        });
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
