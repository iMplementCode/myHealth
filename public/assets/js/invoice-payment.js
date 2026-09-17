/* ============================================================
 *  Record-payment form
 * ------------------------------------------------------------
 *  A receipt either brings new money into an account, or draws
 *  down an advance the customer paid earlier. Those need
 *  different fields, and only one set may be filled in — so the
 *  other is hidden *and* disabled, which keeps it out of the
 *  submitted form entirely.
 * ============================================================ */
(function () {
    'use strict';

    function apply(form) {
        var mode = form.querySelector('[data-payment-source]');
        if (!mode) return;
        var advance = mode.value === 'advance';

        toggle(form.querySelector('[data-payment-direct]'), !advance);
        toggle(form.querySelector('[data-payment-advance]'), advance);
    }

    function toggle(wrap, show) {
        if (!wrap) return;
        wrap.hidden = !show;
        wrap.querySelectorAll('select, input').forEach(function (f) {
            f.disabled = !show;
            // Remember whether the server marked this field required,
            // then only enforce it while the field is on screen — a
            // hidden required field blocks submission with no visible
            // explanation.
            if (f.dataset.wasRequired === undefined) {
                f.dataset.wasRequired = f.required ? '1' : '0';
            }
            f.required = show && f.dataset.wasRequired === '1';
        });
        // The account and advance pickers are required in their own
        // mode, but the server, not the markup, is the authority.
        if (show) {
            var picker = wrap.querySelector('[name="cash_account_id"], [name="from_advance_id"]');
            if (picker) picker.required = true;
        }
    }

    function init(form) {
        if (form.dataset.payReady) return;
        form.dataset.payReady = '1';
        form.querySelector('[data-payment-source]')
            ?.addEventListener('change', function () { apply(form); });
        apply(form);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('form[data-invoice-payment]').forEach(init);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);
        // The form lives in a <template> until the modal opens.
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                Array.prototype.forEach.call(m.addedNodes, function (node) {
                    if (node.nodeType === 1) scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
