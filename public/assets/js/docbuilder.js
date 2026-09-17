/* ============================================================
   iMplement ERP — Document builder (docbuilder.js)
   ------------------------------------------------------------
   Line-item editor used inside modals for quotes and sales
   orders: add/remove product rows with type-to-search, price
   autofill, per-line discounts and live totals.

   Usage: a form marked `data-docbuilder` containing
     <div data-db-rows></div>          rows mount point
     <input name="discount_amount">    global discount (optional)
     <select name="tax_rate">          tax rate (optional)
     [data-db-subtotal] [data-db-tax] [data-db-total]  outputs
     <input type="hidden" name="items_json">           prefill

   The product catalogue is read from a JSON block:
     <script type="application/json" id="db-products">
       [{ "id": 1, "name": "…", "price": 100 }, …]
     </script>

   Rows serialize as product_id[], quantity[], unit_price[],
   item_discount[] so the server reads them like the legacy
   forms did. Works with forms cloned from <template> (modals)
   via a MutationObserver.
   ============================================================ */
(function () {
    'use strict';

    let products = null;

    //  The seed only. The catalogue used to be rendered whole into
    //  this block and then into a <select> per line — five thousand
    //  products became half a megabyte of JSON and twenty-five
    //  thousand <option> elements on a five-line quote. The block
    //  now carries a page of rows and the address to ask for the
    //  rest; app.js's shared search box does the asking, because the
    //  filter input below is marked data-search-select.
    function catalogue() {
        if (products) return products;
        const el = document.getElementById('db-products');
        try { products = el ? (JSON.parse(el.textContent) || []) : []; }
        catch (e) { products = []; }
        return products;
    }

    /** Where the picker asks for products it was not seeded with. */
    function lookupAttrs(el) {
        const src = document.getElementById('db-products');
        if (!src || !src.dataset.lookup) return;
        el.dataset.searchSelect = '';
        el.dataset.lookup = src.dataset.lookup;
        el.dataset.lookupUrl = src.dataset.lookupUrl || 'lookup.php';
    }

    function fmt(n) {
        return (Math.round(n * 100) / 100).toLocaleString('en-KE', {
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        });
    }

    function buildRow(form, item) {
        const row = document.createElement('div');
        row.className = 'db-row';

        // Product picker: filter box + select. `select-search` is
        // the class app.js's shared handler looks for, so this box
        // behaves exactly like every other picker in the app.
        const pickWrap = document.createElement('div');
        pickWrap.className = 'db-pick select-search';
        const filter = document.createElement('input');
        filter.type = 'search';
        filter.className = 'form-control db-filter select-search-input';
        filter.placeholder = 'Type to search product…';
        filter.autocomplete = 'off';
        lookupAttrs(filter);
        const select = document.createElement('select');
        select.className = 'form-control';
        select.name = 'product_id[]';
        select.required = true;
        select.innerHTML = '<option value="">— Product —</option>' +
            catalogue().map((p) =>
                '<option value="' + p.id + '" data-price="' + p.price +
                '" data-cost="' + (p.cost ?? 0) + '">' +
                String(p.name).replace(/</g, '&lt;') + '</option>').join('');
        // Select first, search box second — the label rule again.
        // A <label> with no `for` labels the first labelable element
        // inside it, and when that was the search box a tap on the
        // field opened the keyboard instead of the product list.
        // CSS (.select-search-input { order: -1 }) puts the box back
        // on top.
        pickWrap.appendChild(select);
        pickWrap.appendChild(filter);

        const qty = numInput('quantity[]', '0.001', 'Qty');
        const price = numInput('unit_price[]', '0.01', 'Unit price');
        const disc = numInput('item_discount[]', '0.01', 'Discount');
        disc.required = false;
        disc.value = '0';

        // Line total, with the margin on this line underneath it.
        const lineCell = document.createElement('div');
        lineCell.className = 'db-line-cell';
        const line = document.createElement('span');
        line.className = 'db-line-total';
        line.textContent = '0.00';
        const marginBadge = document.createElement('span');
        marginBadge.className = 'db-line-margin';
        lineCell.appendChild(line);
        lineCell.appendChild(marginBadge);

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'icon-btn icon-btn--danger db-del';
        del.title = 'Remove line';
        del.innerHTML = '&times;';

        row.appendChild(pickWrap);
        row.appendChild(qty);
        row.appendChild(price);
        row.appendChild(disc);
        row.appendChild(lineCell);
        row.appendChild(del);

        /*  A note about this one line, on the forms that asked for
         *  one. This builder draws the lines on quotes, sales orders,
         *  purchase orders and invoices, so switching it on for
         *  everybody would change four screens to answer a question
         *  about one. The form opts in with data-db-notes, and every
         *  other document is byte-for-byte what it was.
         *
         *  Collapsed until it is wanted: the row keeps its height and
         *  its six columns, and the toggle is one small line of text
         *  under the product. A line that already has a note opens
         *  showing it, because a note nobody can see is worse than
         *  no note.                                                  */
        if (form.dataset.dbNotes !== undefined) {
            const noteWrap = document.createElement('div');
            noteWrap.className = 'db-note';

            const note = document.createElement('input');
            note.type = 'text';
            note.name = 'item_note[]';
            note.className = 'form-control db-note-input';
            note.maxLength = 500;
            note.placeholder = 'Note for this line — printed under it on the document';
            note.value = (item && item.note) || '';

            const toggle = document.createElement('button');
            toggle.type = 'button';
            toggle.className = 'db-note-toggle';

            const paint = () => {
                const open = !noteWrap.hidden;
                toggle.textContent = open
                    ? '− Note'
                    : (note.value ? '✎ Note: ' + note.value.slice(0, 40)
                                    + (note.value.length > 40 ? '…' : '')
                                  : '+ Note');
                toggle.title = open ? 'Hide the note' : 'Add a note to this line';
            };

            noteWrap.hidden = !note.value;
            noteWrap.appendChild(note);
            toggle.addEventListener('click', () => {
                noteWrap.hidden = !noteWrap.hidden;
                paint();
                if (!noteWrap.hidden) note.focus();
            });
            note.addEventListener('input', paint);
            paint();

            //  The toggle goes in the product cell, under the picker,
            //  and the field spans the whole row beneath. Both are
            //  children of the same grid, so nothing above them moves.
            pickWrap.appendChild(toggle);
            row.appendChild(noteWrap);

            /*  An empty row still has to post its note, or the notes
             *  arrive shorter than the lines and every one after a
             *  blank is attached to the wrong item. The input is
             *  always in the form — hidden, not absent. */
        }

        // Behaviour. The filter box has no listener of its own: it
        // is marked data-search-select, and app.js filters or
        // fetches for it and fires `change` on the select either
        // way, which is what the handler below is waiting for.
        select.addEventListener('change', () => {
            const opt = select.selectedOptions[0];
            if (opt && opt.dataset.price !== undefined && price.value === '') {
                price.value = opt.dataset.price;
            } else if (opt && opt.dataset.price !== undefined && !price.dataset.touched) {
                price.value = opt.dataset.price;
            }
            recalc(form);
        });
        price.addEventListener('input', () => { price.dataset.touched = '1'; recalc(form); });
        qty.addEventListener('input', () => recalc(form));
        disc.addEventListener('input', () => recalc(form));
        del.addEventListener('click', () => { row.remove(); recalc(form); });

        // Prefill for edit. The picker holds a page of products, not
        // all of them, so a line's own product may not be among the
        // options — it is put back from the name the server sent
        // with the line. Without this an edit would quietly change
        // what was sold.
        if (item) {
            if (window.App && App.ensureOption) {
                App.ensureOption(select, item.product_id, item.name);
                const opt = select.querySelector('option[value="' + CSS.escape(String(item.product_id)) + '"]');
                if (opt && opt.dataset.price === undefined) {
                    opt.dataset.price = item.unit_price;
                    opt.dataset.cost = item.cost ?? 0;
                }
            }
            select.value = String(item.product_id);
            qty.value = item.quantity;
            price.value = item.unit_price;
            price.dataset.touched = '1';
            disc.value = item.discount || 0;
        }
        return row;
    }

    function numInput(name, step, placeholder) {
        const i = document.createElement('input');
        i.type = 'number';
        i.name = name;
        i.className = 'form-control';
        i.min = '0';
        i.step = step;
        i.placeholder = placeholder;
        i.required = name !== 'item_discount[]';
        return i;
    }

    function recalc(form) {
        let subtotal = 0;
        let cogs = 0;          // cost of the goods on the document
        form.querySelectorAll('.db-row').forEach((row) => {
            const q = parseFloat(row.querySelector('[name="quantity[]"]').value) || 0;
            const p = parseFloat(row.querySelector('[name="unit_price[]"]').value) || 0;
            const d = parseFloat(row.querySelector('[name="item_discount[]"]').value) || 0;
            const line = Math.max(0, q * p - d);
            row.querySelector('.db-line-total').textContent = fmt(line);
            subtotal += line;

            // Margin on this line, from the selected product's cost.
            const sel = row.querySelector('[name="product_id[]"]');
            const cost = parseFloat(sel?.selectedOptions?.[0]?.dataset.cost) || 0;
            const lineCost = cost * q;
            cogs += lineCost;

            const badge = row.querySelector('.db-line-margin');
            if (badge) {
                if (!sel?.value || line <= 0) {
                    badge.textContent = '';
                    badge.className = 'db-line-margin';
                } else {
                    const m = line - lineCost;
                    const pct = line > 0 ? (m / line) * 100 : 0;
                    badge.textContent = fmt(m) + ' · ' + fmt(pct) + '%';
                    badge.className = 'db-line-margin' + (m < 0 ? ' is-loss' : '');
                }
            }
        });
        const gDisc = parseFloat(form.querySelector('[name="discount_amount"]')?.value) || 0;
        const rate = parseFloat(form.querySelector('[name="tax_rate"]')?.value) || 0;
        const afterDisc = Math.max(0, subtotal - gDisc);
        const tax = afterDisc * rate;
        const total = afterDisc + tax;

        setOut(form, '[data-db-subtotal]', subtotal);
        setOut(form, '[data-db-tax]', tax);
        setOut(form, '[data-db-total]', total);

        // Document profit is measured against what the customer pays
        // before tax — tax is collected, not earned.
        const profit = afterDisc - cogs;
        const profitPct = afterDisc > 0 ? (profit / afterDisc) * 100 : 0;
        setOut(form, '[data-db-profit]', profit);
        const pctEl = form.querySelector('[data-db-profit-percent]');
        if (pctEl) pctEl.textContent = fmt(profitPct) + '%';
        const profitRow = form.querySelector('[data-db-profit-row]');
        if (profitRow) profitRow.classList.toggle('is-loss', profit < 0);
    }

    function setOut(form, sel, val) {
        const el = form.querySelector(sel);
        if (el) el.textContent = fmt(val);
    }

    function init(form) {
        if (form.dataset.dbReady) return;
        form.dataset.dbReady = '1';
        const mount = form.querySelector('[data-db-rows]');
        if (!mount) return;

        // Prefill items (edit mode) from the hidden items_json field.
        let items = [];
        const src = form.querySelector('[name="items_json"]');
        if (src && src.value) {
            try { items = JSON.parse(src.value) || []; } catch (e) { items = []; }
        }
        if (items.length) {
            items.forEach((it) => mount.appendChild(buildRow(form, it)));
        } else {
            mount.appendChild(buildRow(form, null));
        }

        form.querySelector('[data-db-add]')?.addEventListener('click', () => {
            mount.appendChild(buildRow(form, null));
        });
        form.querySelector('[name="discount_amount"]')?.addEventListener('input', () => recalc(form));
        form.querySelector('[name="tax_rate"]')?.addEventListener('change', () => recalc(form));
        recalc(form);
    }

    function scan(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('form[data-docbuilder]').forEach(init);
    }

    document.addEventListener('DOMContentLoaded', () => {
        scan(document);
        new MutationObserver((muts) => {
            muts.forEach((m) => {
                Array.prototype.forEach.call(m.addedNodes, (node) => {
                    if (node.nodeType === 1) scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    });
})();
