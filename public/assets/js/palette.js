/* ============================================================
   iMplement ERP — Quick jump (palette.js)
   ------------------------------------------------------------
   Ctrl-K (⌘K on a Mac) opens a box, you type two or three
   letters of where you want to be, and Enter takes you there.

   This system has around fifty pages behind seven collapsed
   sidebar groups. Getting from Aged Payables to Stock Value is
   currently: open the group, find the item, click, wait, open
   the other group. Somebody doing that forty times a day knows
   exactly where they are going and should not have to navigate
   to it.

   The list comes from the #nav-index JSON block, which the
   server builds from the same role-filtered navigation as the
   sidebar. So the box never offers a page that would answer
   403, and it gains a new module the day the sidebar does.
   These are links, not permissions — every page still checks
   for itself.

   Also here, because both are about not reaching for the mouse:
     /          focus this page's search box
     Escape     close the box
     ↑ ↓ Enter  move and go
   ============================================================ */
(function () {
    'use strict';

    let items = [];
    let box = null;      // the overlay
    let input = null;
    let list = null;
    let matches = [];
    let cursor = 0;

    function destinations() {
        const el = document.getElementById('nav-index');
        if (!el) return [];
        try { return JSON.parse(el.textContent) || []; }
        catch (e) { return []; }
    }

    /*  Scoring, in the order people expect. A label that starts
     *  with what was typed beats one that merely contains it, and
     *  both beat a match that was only found in the group name —
     *  so typing "in" offers Invoices before Inventory · Brands.
     *  Everything is compared lowercased and trimmed. */
    function score(item, q) {
        const label = item.label.toLowerCase();
        const group = (item.group || '').toLowerCase();
        if (label === q) return 0;
        if (label.startsWith(q)) return 1;
        if (label.includes(q)) return 2;
        if (group.startsWith(q)) return 3;
        if (group.includes(q)) return 4;
        // Initials: "cb" finds Cash Book, "agp" finds Aged Payables.
        const initials = label.split(/\s+/).map((w) => w[0] || '').join('');
        if (initials.startsWith(q)) return 5;
        return -1;
    }

    function filter(q) {
        q = q.trim().toLowerCase();
        if (!q) return items.slice(0, 12);
        return items
            .map((it) => ({ it: it, s: score(it, q) }))
            .filter((r) => r.s >= 0)
            .sort((a, b) => a.s - b.s || a.it.label.localeCompare(b.it.label))
            .slice(0, 12)
            .map((r) => r.it);
    }

    function render() {
        list.textContent = '';
        if (!matches.length) {
            const none = document.createElement('div');
            none.className = 'palette-empty';
            none.textContent = 'Nothing here by that name.';
            list.appendChild(none);
            return;
        }
        matches.forEach((it, i) => {
            const row = document.createElement('a');
            row.className = 'palette-row' + (i === cursor ? ' is-on' : '');
            row.href = it.href;
            if (it.group) {
                const g = document.createElement('span');
                g.className = 'palette-group';
                g.textContent = it.group;
                row.appendChild(g);
            }
            const l = document.createElement('span');
            l.className = 'palette-label';
            l.textContent = it.label;
            row.appendChild(l);
            // Hovering moves the selection, so the mouse and the
            // arrow keys never disagree about what Enter will open.
            row.addEventListener('mousemove', () => {
                if (cursor !== i) { cursor = i; paint(); }
            });
            list.appendChild(row);
        });
    }

    /** Repaint only the selection, so moving does not rebuild the list. */
    function paint() {
        Array.prototype.forEach.call(list.children, (el, i) => {
            el.classList.toggle('is-on', i === cursor);
        });
        const on = list.children[cursor];
        if (on && on.scrollIntoView) on.scrollIntoView({ block: 'nearest' });
    }

    function build() {
        box = document.createElement('div');
        box.className = 'palette';
        box.hidden = true;
        box.setAttribute('role', 'dialog');
        box.setAttribute('aria-modal', 'true');
        box.setAttribute('aria-label', 'Jump to a page');

        const panel = document.createElement('div');
        panel.className = 'palette-panel';

        input = document.createElement('input');
        input.type = 'text';
        input.className = 'palette-input';
        input.placeholder = 'Jump to…';
        input.autocomplete = 'off';
        input.setAttribute('aria-label', 'Jump to a page');

        list = document.createElement('div');
        list.className = 'palette-list';

        const hint = document.createElement('div');
        hint.className = 'palette-hint';
        hint.textContent = '↑ ↓ to move · Enter to open · Esc to close';

        panel.appendChild(input);
        panel.appendChild(list);
        panel.appendChild(hint);
        box.appendChild(panel);
        document.body.appendChild(box);

        // A click on the backdrop closes; a click inside does not.
        box.addEventListener('mousedown', (e) => {
            if (e.target === box) close();
        });
        input.addEventListener('input', () => {
            matches = filter(input.value);
            cursor = 0;
            render();
        });
        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                cursor = Math.min(cursor + 1, matches.length - 1);
                paint();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                cursor = Math.max(cursor - 1, 0);
                paint();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                const target = matches[cursor];
                if (target) window.location.href = target.href;
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close();
            }
        });
    }

    function open() {
        if (!box) build();
        box.hidden = false;
        document.body.classList.add('palette-open');
        input.value = '';
        matches = filter('');
        cursor = 0;
        render();
        input.focus();
    }

    function close() {
        if (!box) return;
        box.hidden = true;
        document.body.classList.remove('palette-open');
    }

    /** Is the person typing into something? Then a bare key is text. */
    function typing(el) {
        if (!el) return false;
        const tag = el.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
    }

    document.addEventListener('DOMContentLoaded', () => {
        items = destinations();
        if (!items.length) return;

        // The topbar button, for anybody who is not going to learn a
        // keyboard shortcut and for phones, which have no Ctrl key.
        document.addEventListener('click', (e) => {
            if (e.target.closest('[data-palette-open]')) {
                e.preventDefault();
                open();
            }
        });

        document.addEventListener('keydown', (e) => {
            // Ctrl-K, or ⌘K on a Mac.
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                if (box && !box.hidden) { close(); } else { open(); }
                return;
            }
            if (e.key === 'Escape' && box && !box.hidden) {
                close();
                return;
            }
            /*  "/" focuses this page's own search box — the one in
             *  the toolbar, not this one. Only when the person is not
             *  already typing, or every slash in a note would jump
             *  the cursor out of the note.                          */
            if (e.key === '/' && !typing(document.activeElement) && (!box || box.hidden)) {
                const search = document.querySelector(
                    '.toolbar-search input[type="search"], .toolbar input[type="search"]'
                );
                if (search) {
                    e.preventDefault();
                    search.focus();
                    search.select();
                }
            }
        });
    });
})();
