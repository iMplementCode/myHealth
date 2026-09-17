/* ============================================================
 *  Cash count — live difference per account and in total
 * ------------------------------------------------------------
 *  Counting money is a column of figures. The difference has to
 *  appear as the number is typed, or the person counting has to
 *  do the arithmetic twice.
 *
 *  A blank box means NOT COUNTED, which is not the same as
 *  counting zero — so a blank line contributes nothing to the
 *  totals and asks for no reason.
 * ============================================================ */
(function () {
    'use strict';

    var form = document.querySelector('form[data-cash-count]');
    if (!form) { return; }

    var money = function (n) {
        return 'KES ' + n.toLocaleString('en-KE', {
            minimumFractionDigits: 2, maximumFractionDigits: 2
        });
    };

    function refresh() {
        var counted = 0, book = 0, any = false;

        form.querySelectorAll('[data-count-row]').forEach(function (row) {
            var input  = row.querySelector('[data-counted]');
            var bookEl = row.querySelector('[data-book]');
            var diffEl = row.querySelector('[data-diff]');
            var reason = row.querySelector('[data-reason]');
            var note   = row.querySelector('[data-note]');

            // The attribute carries an unformatted number on purpose:
            // parseFloat('77,059.00') is 77.
            var bookVal = parseFloat(bookEl.getAttribute('data-book')) || 0;
            var raw     = input.value.trim();

            if (raw === '') {
                diffEl.innerHTML = '<span class="cell-muted">—</span>';
                reason.disabled = true;
                note.disabled = true;
                reason.removeAttribute('required');
                return;
            }

            var countVal = parseFloat(raw);
            if (isNaN(countVal)) { return; }

            any = true;
            counted += countVal;
            book    += bookVal;

            var diff = Math.round((countVal - bookVal) * 100) / 100;
            if (Math.abs(diff) < 0.005) {
                diffEl.innerHTML = '<span class="tone-in">agrees</span>';
                reason.disabled = true;
                note.disabled = true;
                reason.removeAttribute('required');
            } else {
                diffEl.innerHTML = '<strong class="' + (diff < 0 ? 'tone-out' : 'tone-in') + '">'
                    + (diff > 0 ? '+' : '−') + money(Math.abs(diff)) + '</strong>';
                // A difference has to be explained before it can be
                // posted; the server insists on this too.
                reason.disabled = false;
                note.disabled = false;
                reason.setAttribute('required', 'required');
            }
        });

        var net = Math.round((counted - book) * 100) / 100;
        form.ownerDocument.querySelectorAll('[data-count-total]').forEach(function (el) {
            el.textContent = any ? money(counted) : '—';
        });
        form.ownerDocument.querySelectorAll('[data-diff-total]').forEach(function (el) {
            el.textContent = any ? (net > 0 ? '+' : net < 0 ? '−' : '') + money(Math.abs(net)) : '—';
            el.className = el.className.replace(/\s*tone-(in|out)/g, '');
            if (any && Math.abs(net) >= 0.005) {
                el.className += net < 0 ? ' tone-out' : ' tone-in';
            }
        });
    }

    form.addEventListener('input', function (e) {
        if (e.target.matches('[data-counted]')) { refresh(); }
    });

    // Enter moves to the next account rather than submitting: this is
    // a column of figures, and submitting halfway through a count is
    // never what was meant.
    form.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.matches('[data-counted]')) { return; }
        e.preventDefault();
        var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-counted]'));
        var next  = boxes[boxes.indexOf(e.target) + 1];
        if (next) { next.focus(); next.select(); }
    });

    refresh();
})();
