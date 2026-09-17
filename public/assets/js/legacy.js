/* ============================================================
   iMplement ERP — Legacy page enhancements (legacy.js)
   ------------------------------------------------------------
   Loaded by the legacy module pages (which don't use the shared
   layout yet). Adds, without touching their business logic:

   1. Table pagination — any data table with more than 10 body
      rows shows 10 at a time with Prev/Next + page numbers.
      Pagination steps aside automatically while the page's own
      search/filter inputs are in use.

   2. Searchable dropdowns — any <select> with 10+ options
      (product pickers on quotes, sales orders, purchase orders,
      GRNs, debit notes) gains a "type to filter" box above it.
      Works for rows added dynamically by the page's own JS.
   ============================================================ */
(function () {
    'use strict';

    var PAGE_SIZE = 10;

    /* ── Shared styles ───────────────────────────────────── */
    var style = document.createElement('style');
    style.textContent =
        '.ljs-pager{display:flex;gap:6px;align-items:center;flex-wrap:wrap;padding:14px 0;font-family:inherit;}' +
        '.ljs-pager button{min-width:32px;height:32px;padding:0 10px;border:1px solid rgba(122,127,148,.35);' +
        'background:transparent;color:inherit;border-radius:7px;font-size:12.5px;cursor:pointer;font-family:inherit;}' +
        '.ljs-pager button:hover{border-color:#29c5f0;}' +
        '.ljs-pager button.ljs-on{background:#29c5f0;color:#04222c;border-color:#29c5f0;font-weight:700;}' +
        '.ljs-pager button:disabled{opacity:.4;cursor:default;}' +
        '.ljs-pager .ljs-info{font-size:12px;opacity:.65;margin-left:auto;}' +
        '.ljs-filter{display:block;width:100%;margin-bottom:6px;padding:7px 10px;font-size:12.5px;' +
        'background:rgba(122,127,148,.12);border:1px solid rgba(122,127,148,.35);border-radius:7px;' +
        'color:inherit;font-family:inherit;}' +
        '.ljs-filter:focus{outline:none;border-color:#29c5f0;}';
    document.head.appendChild(style);

    /* ── 1. Table pagination ─────────────────────────────── */
    function paginateTable(table) {
        if (table.dataset.ljsPaged) return;
        var tbody = table.tBodies[0];
        if (!tbody) return;
        var rows = Array.prototype.slice.call(tbody.rows);
        if (rows.length <= PAGE_SIZE) return;
        table.dataset.ljsPaged = '1';

        var page = 1;
        var pager = document.createElement('div');
        pager.className = 'ljs-pager';
        table.parentNode.insertBefore(pager, table.nextSibling);

        function totalPages() {
            return Math.max(1, Math.ceil(rows.length / PAGE_SIZE));
        }

        function render() {
            var tp = totalPages();
            if (page > tp) page = tp;
            rows.forEach(function (row, i) {
                row.style.display =
                    (i >= (page - 1) * PAGE_SIZE && i < page * PAGE_SIZE) ? '' : 'none';
            });
            pager.innerHTML = '';
            var prev = btn('« Prev', function () { page--; render(); });
            prev.disabled = page === 1;
            pager.appendChild(prev);
            for (var i = 1; i <= tp; i++) {
                (function (n) {
                    var b = btn(String(n), function () { page = n; render(); });
                    if (n === page) b.classList.add('ljs-on');
                    pager.appendChild(b);
                })(i);
            }
            var next = btn('Next »', function () { page++; render(); });
            next.disabled = page === tp;
            pager.appendChild(next);
            var info = document.createElement('span');
            info.className = 'ljs-info';
            info.textContent = rows.length + ' records · page ' + page + ' of ' + tp;
            pager.appendChild(info);
        }

        function btn(label, onClick) {
            var b = document.createElement('button');
            b.type = 'button';
            b.textContent = label;
            b.addEventListener('click', onClick);
            return b;
        }

        // Let the page's own search boxes take over row visibility
        // while they hold text; restore pagination when cleared.
        function setSuspended(on) {
            if (on) {
                pager.style.display = 'none';
                rows.forEach(function (r) { r.style.display = ''; });
            } else {
                pager.style.display = '';
                page = 1;
                render();
            }
        }
        document.addEventListener('input', function (e) {
            var el = e.target;
            if (!el.matches || el.classList.contains('ljs-filter')) return;
            if (!el.matches('input[type="search"], input[type="text"]')) return;
            if (pager.contains(el)) return;
            setSuspended(el.value.trim() !== '');
        });

        render();
    }

    /* ── 2. Searchable selects ───────────────────────────── */
    function enhanceSelect(sel) {
        if (sel.dataset.ljsSearch || sel.options.length < 10 || sel.multiple) return;
        sel.dataset.ljsSearch = '1';

        var filter = document.createElement('input');
        filter.type = 'text';
        filter.className = 'ljs-filter';
        filter.placeholder = 'Type to search…';
        filter.setAttribute('aria-label', 'Filter options');
        sel.parentNode.insertBefore(filter, sel);

        filter.addEventListener('input', function () {
            var q = filter.value.trim().toLowerCase();
            var firstMatch = null;
            Array.prototype.forEach.call(sel.options, function (opt) {
                if (opt.value === '') { opt.hidden = false; return; } // placeholder
                var match = q === '' || opt.textContent.toLowerCase().indexOf(q) !== -1;
                opt.hidden = !match;
                if (match && !firstMatch && opt.value !== '') firstMatch = opt;
            });
            // Auto-select the first match so Enter/blur picks it fast,
            // and fire change so dependent logic (price fill) runs.
            if (q !== '' && firstMatch && sel.value !== firstMatch.value) {
                sel.value = firstMatch.value;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });

        filter.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); sel.focus(); }
        });
    }

    function scanSelects(root) {
        var scope = root && root.querySelectorAll ? root : document;
        Array.prototype.forEach.call(scope.querySelectorAll('select'), enhanceSelect);
    }

    /* ── Boot + watch for dynamically added rows ─────────── */
    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('table'), paginateTable);
        scanSelects(document);

        new MutationObserver(function (mutations) {
            mutations.forEach(function (m) {
                Array.prototype.forEach.call(m.addedNodes, function (node) {
                    if (node.nodeType === 1) scanSelects(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
