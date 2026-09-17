/* ============================================================
 *  Cash-book entry form
 * ------------------------------------------------------------
 *  The entry form asks for different things depending on what
 *  kind of movement is being recorded:
 *
 *    - a transfer needs a second account (and writes both legs)
 *    - most categories imply their direction; only transfers and
 *      adjustments need to be asked
 *    - the account list is narrowed to the types the category is
 *      allowed on, so the server-side rule is never a surprise
 *    - a receipt against an invoice, or a payment against a
 *      purchase order, has to say which one — and once it does,
 *      the amount and the name fill themselves in from it
 *
 *  Hiding a field also disables it, so a hidden control never
 *  posts a value the server would have to second-guess.
 * ============================================================ */
(function () {
    'use strict';

    // Which account types each category may be recorded against.
    // Mirrors the database trigger, as widened in migration 016 —
    // money reaches the business through a bank and a wallet as
    // readily as through a till.
    var LIQUID = ['cash', 'bank', 'mobile_money'];
    var ALLOWED = {
        sale:             LIQUID,
        purchase:         LIQUID,
        expense:          LIQUID,
        invoice_payment:  LIQUID,
        customer_advance: LIQUID,
        investment:       LIQUID,
        refund:           LIQUID,
        credit_sale:      ['credit'],
    };

    function apply(form) {
        var catSel = form.querySelector('[name="category"]');
        var acctSel = form.querySelector('[name="cash_account_id"]');
        if (!catSel || !acctSel) return;

        var opt = catSel.selectedOptions[0];
        var cat = catSel.value;
        var dir = opt ? opt.dataset.dir : 'out';
        var editing = !!form.querySelector('[name="cash_txn_id"]').value;

        // Destination account: transfers only.
        var destWrap = form.querySelector('[data-cash-destination]');
        if (destWrap) {
            // Editing an existing entry cannot re-pair a transfer,
            // so the field stays hidden in edit mode.
            toggle(destWrap, cat === 'transfer' && !editing);
        }

        // Direction: only where the category genuinely allows both.
        var dirWrap = form.querySelector('[data-cash-direction]');
        if (dirWrap) toggle(dirWrap, dir === 'both');
        if (dir !== 'both') {
            var dirSel = form.querySelector('[name="direction"]');
            if (dirSel) dirSel.value = dir;
        }

        // The document this movement is against, if the category
        // names one. Only the block for the current category is
        // live; the others are hidden, disabled, and post nothing.
        var active = null;
        form.querySelectorAll('[data-cash-doc]').forEach(function (wrap) {
            var on = wrap.dataset.cashDoc === cat;
            toggle(wrap, on);
            if (on) active = wrap;
        });

        // Method and reference belong to the payment, so they only
        // appear once there is a payment to attach them to.
        var payWrap = form.querySelector('[data-cash-payinfo]');
        if (payWrap) {
            var sel = active && active.querySelector('[data-doc-select]');
            toggle(payWrap, !!(sel && sel.value));
        }

        // Narrow the account list to the types this category allows.
        var allowed = ALLOWED[cat] || null;
        var current = acctSel.value;
        var stillValid = false;
        Array.prototype.forEach.call(acctSel.options, function (o) {
            if (!o.value) return;
            var ok = !allowed || allowed.indexOf(o.dataset.type) !== -1;
            o.hidden = !ok;
            o.disabled = !ok;
            if (ok && o.value === current) stillValid = true;
        });
        if (!stillValid) acctSel.value = '';

        var help = form.querySelector('[data-cash-help]');
        if (help && opt) help.textContent = opt.dataset.help || '';
    }

    /**
     * A document has been picked: carry what it knows into the
     * fields the user would otherwise retype. Anything they have
     * already typed themselves is left alone.
     */
    function documentChosen(form, sel) {
        var opt = sel.selectedOptions[0];
        var wrap = sel.closest('[data-cash-doc]');
        var summary = wrap && wrap.querySelector('[data-doc-summary]');

        if (summary) {
            summary.textContent = (opt && opt.dataset.summary) || summary.dataset.help || '';
            summary.classList.toggle('form-hint--strong', !!(opt && opt.dataset.summary));
        }
        if (!opt || !opt.value) {
            apply(form);
            return;
        }

        // The balance is what is normally paid, so it is offered —
        // a part payment is simply typed over it.
        var amount = form.querySelector('[name="amount"]');
        if (amount && !amount.dataset.touched && opt.dataset.balance) {
            amount.value = opt.dataset.balance;
        }
        var party = form.querySelector('[name="party"]');
        if (party && !party.dataset.touched && opt.dataset.party) {
            party.value = opt.dataset.party;
        }
        apply(form);
    }

    /** The account already implies how the money moved. */
    function seedMethod(form) {
        var acct = form.querySelector('[name="cash_account_id"]');
        var method = form.querySelector('[name="pay_method"]');
        if (!acct || !method || method.dataset.touched) return;

        var opt = acct.selectedOptions[0];
        var byType = { cash: 'cash', bank: 'bank_transfer', mobile_money: 'mobile_money' };
        var guess = opt ? byType[opt.dataset.type] : null;
        if (guess) method.value = guess;
    }

    //  There used to be a filterOptions() here, narrowing a rendered
    //  list of every open invoice to what was typed. The list is no
    //  longer rendered — it was 37 MB of it — so the search box is
    //  now the shared one in app.js, which asks the server. What
    //  comes back carries the same data-balance / data-party /
    //  data-summary attributes the server used to write, so
    //  documentChosen() below could not tell the difference.

    function toggle(wrap, show) {
        wrap.hidden = !show;
        wrap.querySelectorAll('select, input').forEach(function (f) {
            f.disabled = !show;
            // Required only while visible, or the browser blocks
            // submission on a field the user cannot see.
            if (f.dataset.wasRequired === undefined) {
                f.dataset.wasRequired = f.required ? '1' : '0';
            }
            f.required = show && f.dataset.wasRequired === '1';
        });
    }

    function init(form) {
        if (form.dataset.cashReady) return;
        form.dataset.cashReady = '1';

        // Capture the per-category help text the server rendered.
        var catSel = form.querySelector('[name="category"]');
        if (catSel) {
            catSel.addEventListener('change', function () { apply(form); });
        }
        form.querySelector('[name="cash_account_id"]')
            ?.addEventListener('change', function () { seedMethod(form); apply(form); });

        // The hint under a document list doubles as the summary of
        // whatever is chosen, so its original wording is kept to go
        // back to when the choice is cleared.
        form.querySelectorAll('[data-doc-summary]').forEach(function (p) {
            p.dataset.help = p.textContent.trim();
        });
        form.querySelectorAll('[data-doc-select]').forEach(function (sel) {
            sel.addEventListener('change', function () { documentChosen(form, sel); });
        });

        // Once a figure has been typed by hand, nothing overwrites it.
        // An entry being edited counts as typed already: whatever is
        // in the form is what was recorded, and attaching a document
        // to it must not quietly restate the amount or the name.
        var editing = !!form.querySelector('[name="cash_txn_id"]').value;
        ['amount', 'party', 'pay_method'].forEach(function (name) {
            var f = form.querySelector('[name="' + name + '"]');
            if (!f) return;
            if (editing && f.value) f.dataset.touched = '1';
            f.addEventListener('input', function () { f.dataset.touched = '1'; });
            f.addEventListener('change', function () { f.dataset.touched = '1'; });
        });

        seedMethod(form);
        apply(form);
    }

    function scan(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('form[data-cash-entry]').forEach(init);
    }

    document.addEventListener('DOMContentLoaded', function () {
        scan(document);
        // Modals clone a <template> into the page after load, so the
        // form only exists once it is opened.
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                Array.prototype.forEach.call(m.addedNodes, function (node) {
                    if (node.nodeType === 1) scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
