/* ============================================================
 *  Stock count sheet
 * ------------------------------------------------------------
 *  Counting is done standing at a shelf with a phone or a
 *  laptop, so the sheet does its arithmetic as the numbers go
 *  in: the difference and what it is worth appear on the line
 *  the moment it is typed, and the running total at the bottom
 *  keeps up.
 *
 *  The server recomputes all of it on save. Nothing here is
 *  trusted — it is there so the person counting can see a
 *  mistake while they are still in front of the shelf, rather
 *  than after they have walked away.
 * ============================================================ */
(function () {
    'use strict';

    var money = function (n) {
        return 'KES ' + n.toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    };

    function recalcRow(row) {
        var input  = row.querySelector('[data-stk-counted]');
        var sysCel = row.querySelector('[data-stk-system]');
        var varCel = row.querySelector('[data-stk-variance]');
        var valCel = row.querySelector('[data-stk-value]');
        if (!input || !sysCel) return null;

        var raw = input.value.trim();
        if (raw === '') {
            // Blank is "not counted yet", not "counted zero", and the
            // line says so rather than showing a difference of minus
            // everything.
            if (varCel) { varCel.textContent = '—'; varCel.className = 'ta-right'; }
            if (valCel) { valCel.textContent = '—'; valCel.className = 'ta-right'; }
            row.classList.remove('stk-row--over', 'stk-row--short', 'stk-row--agreed');
            return null;
        }

        var counted  = parseFloat(raw) || 0;
        var system   = parseFloat(sysCel.dataset.stkSystem) || 0;
        var cost     = parseFloat(input.dataset.cost) || 0;
        var variance = Math.round((counted - system) * 1000) / 1000;
        var value    = Math.round(variance * cost * 100) / 100;

        if (varCel) {
            varCel.textContent = variance.toLocaleString('en-KE', { maximumFractionDigits: 2 });
            varCel.className = 'ta-right ' + (Math.abs(variance) < 0.0005 ? 'cell-muted'
                                              : variance > 0 ? 'tone-in' : 'tone-out');
        }
        if (valCel) {
            valCel.textContent = money(value);
            valCel.className = 'ta-right ' + (Math.abs(value) < 0.005 ? 'cell-muted'
                                              : value > 0 ? 'tone-in' : 'tone-out');
        }

        row.classList.remove('stk-row--over', 'stk-row--short', 'stk-row--agreed');
        row.classList.add(Math.abs(variance) < 0.0005 ? 'stk-row--agreed'
                          : variance > 0 ? 'stk-row--over' : 'stk-row--short');

        return { variance: variance, value: value };
    }

    function recalcAll(form) {
        var counted = 0, agreed = 0, over = 0, short = 0, net = 0, lines = 0;

        form.querySelectorAll('[data-stk-row]').forEach(function (row) {
            lines++;
            var r = recalcRow(row);
            if (!r) return;
            counted++;
            net += r.value;
            if (Math.abs(r.variance) < 0.0005) agreed++;
            else if (r.variance > 0) over++;
            else short++;
        });

        var out = form.querySelector('[data-stk-summary]');
        if (!out) return;

        if (counted === 0) {
            out.textContent = 'Enter what is on the shelf. A blank line has not been counted yet — '
                            + 'which is not the same as counting zero.';
            return;
        }
        out.textContent = counted + ' of ' + lines + ' counted · '
                        + agreed + ' agreed, ' + short + ' short, ' + over + ' over · '
                        + 'net ' + money(net) + ' at cost'
                        + (counted < lines ? ' · ' + (lines - counted) + ' still to count' : '');
    }

    /** Narrow a long sheet to what was typed, without losing a line. */
    function filterRows(box) {
        var needle = box.value.trim().toLowerCase();
        var table  = document.querySelector('.stk-table');
        if (!table) return;

        table.querySelectorAll('[data-stk-row]').forEach(function (row) {
            var hit = !needle || (row.dataset.search || '').indexOf(needle) !== -1;
            // Hidden, never removed: the inputs still post, so a
            // search does not quietly drop half the sheet on save.
            row.hidden = !hit;
        });
        // A category heading with nothing under it is noise.
        table.querySelectorAll('tr.cashbook-daybreak').forEach(function (head) {
            var any = false;
            for (var n = head.nextElementSibling; n && !n.classList.contains('cashbook-daybreak'); n = n.nextElementSibling) {
                if (!n.hidden) { any = true; break; }
            }
            head.hidden = !any;
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('form[data-stk-sheet]');
        if (form) {
            form.addEventListener('input', function (e) {
                if (e.target.matches('[data-stk-counted]')) {
                    recalcRow(e.target.closest('[data-stk-row]'));
                    recalcAll(form);
                }
            });
            // Enter moves to the next line rather than submitting the
            // sheet: counting is a column of numbers, and a
            // half-finished save on every row is not what anyone means.
            form.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' || !e.target.matches('[data-stk-counted]')) return;
                e.preventDefault();
                var all = Array.prototype.filter.call(
                    form.querySelectorAll('[data-stk-counted]'),
                    function (el) { return !el.closest('[data-stk-row]').hidden; }
                );
                var next = all[all.indexOf(e.target) + 1];
                if (next) { next.focus(); next.select(); }
            });
            recalcAll(form);
        }

        var box = document.querySelector('[data-stk-filter]');
        if (box) {
            box.addEventListener('input', function () { filterRows(box); });
            box.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') e.preventDefault();
            });
        }
    });
})();
