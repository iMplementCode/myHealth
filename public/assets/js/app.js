/* ============================================================
   iMplement ERP — Core JavaScript (app.js)
   ------------------------------------------------------------
   Reusable, dependency-free UI modules wired up via data-*
   attributes. No inline JS lives in the PHP pages.

   Public API (window.App):
     App.notify(message, type)      → toast notification
     App.confirm(opts) → Promise    → confirmation modal
     App.ajax(url, opts) → Promise  → JSON fetch helper
   ============================================================ */
(function () {
    'use strict';

    const App = {};

    /* ── Toast notifications ─────────────────────────────── */
    App.notify = function (message, type = 'info', timeout = 4000) {
        const stack = document.getElementById('toastStack');
        if (!stack) return;
        const toast = document.createElement('div');
        toast.className = 'toast toast--' + type;
        toast.setAttribute('role', 'status');
        toast.textContent = message;
        stack.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(20px)';
            setTimeout(() => toast.remove(), 200);
        }, timeout);
    };

    /* ── Confirmation modal (returns a Promise<boolean>) ──── */
    App.confirm = function (opts = {}) {
        const {
            title = 'Are you sure?',
            text = 'This action cannot be undone.',
            confirmLabel = 'Confirm',
            cancelLabel = 'Cancel',
            danger = true,
        } = opts;

        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'modal-overlay';
            overlay.innerHTML =
                '<div class="modal" role="dialog" aria-modal="true">' +
                    '<div class="modal-body">' +
                        '<h3 class="modal-title"></h3>' +
                        '<p class="modal-text"></p>' +
                    '</div>' +
                    '<div class="modal-actions">' +
                        '<button type="button" class="btn btn-ghost" data-cancel></button>' +
                        '<button type="button" class="btn" data-ok></button>' +
                    '</div>' +
                '</div>';
            overlay.querySelector('.modal-title').textContent = title;
            overlay.querySelector('.modal-text').textContent = text;
            const okBtn = overlay.querySelector('[data-ok]');
            const cancelBtn = overlay.querySelector('[data-cancel]');
            okBtn.textContent = confirmLabel;
            cancelBtn.textContent = cancelLabel;
            okBtn.classList.add(danger ? 'btn-danger' : 'btn-primary');

            document.body.appendChild(overlay);
            requestAnimationFrame(() => overlay.classList.add('is-open'));

            const close = (result) => {
                overlay.classList.remove('is-open');
                setTimeout(() => overlay.remove(), 180);
                resolve(result);
            };
            okBtn.addEventListener('click', () => close(true));
            cancelBtn.addEventListener('click', () => close(false));
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(false); });
            document.addEventListener('keydown', function esc(e) {
                if (e.key === 'Escape') { close(false); document.removeEventListener('keydown', esc); }
            });
            okBtn.focus();
        });
    };

    /* ── AJAX helper (JSON) ──────────────────────────────── */
    App.ajax = function (url, opts = {}) {
        const config = Object.assign({
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        }, opts);
        if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
            config.headers['Content-Type'] = 'application/json';
            config.body = JSON.stringify(config.body);
        }
        return fetch(url, config).then((res) => {
            const ct = res.headers.get('content-type') || '';
            const data = ct.includes('application/json') ? res.json() : res.text();
            return data.then((body) => (res.ok ? body : Promise.reject(body)));
        });
    };

    /* ── Sidebar toggle (mobile) ─────────────────────────── */
    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;
        document.querySelectorAll('[data-sidebar-toggle]').forEach((btn) =>
            btn.addEventListener('click', () => sidebar.classList.toggle('is-open')));
        document.querySelectorAll('[data-sidebar-close]').forEach((el) =>
            el.addEventListener('click', () => sidebar.classList.remove('is-open')));
    }

    /* ── Collapsible nav groups ──────────────────────────── */
    function initNavGroups() {
        document.querySelectorAll('[data-nav-toggle]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const group = btn.closest('[data-nav-group]');
                if (group) group.classList.toggle('is-open');
            });
        });
    }

    /* ── Dropdown menus ──────────────────────────────────── */
    function initDropdowns() {
        document.querySelectorAll('[data-dropdown]').forEach((dd) => {
            const toggle = dd.querySelector('[data-dropdown-toggle]');
            if (!toggle) return;
            toggle.addEventListener('click', (e) => {
                e.stopPropagation();
                document.querySelectorAll('[data-dropdown].is-open').forEach((o) => {
                    if (o !== dd) o.classList.remove('is-open');
                });
                dd.classList.toggle('is-open');
            });
        });
        document.addEventListener('click', () =>
            document.querySelectorAll('[data-dropdown].is-open').forEach((o) => o.classList.remove('is-open')));
    }

    /* ── Dismissible alerts ──────────────────────────────── */
    function initAlerts() {
        document.querySelectorAll('[data-alert-close]').forEach((btn) =>
            btn.addEventListener('click', () => btn.closest('.alert')?.remove()));
    }

    /* ── Password show / hide ────────────────────────────── */
    function initPasswordToggles() {
        document.querySelectorAll('[data-toggle-password]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const field = document.getElementById(btn.getAttribute('data-toggle-password'));
                if (!field) return;
                const show = field.type === 'password';
                field.type = show ? 'text' : 'password';
                btn.textContent = show ? 'Hide' : 'Show';
            });
        });
    }

    /* ── Live table search / filtering ───────────────────── */
    function initTableSearch() {
        document.querySelectorAll('[data-table-search]').forEach((input) => {
            const table = document.getElementById(input.getAttribute('data-table-search'));
            if (!table) return;
            input.addEventListener('input', () => {
                const q = input.value.trim().toLowerCase();
                table.querySelectorAll('tbody tr').forEach((row) => {
                    if (row.querySelector('.table-empty')) return;
                    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
                });
            });
        });
    }

    /* ── Keeping a saved value in a bounded picker ───────── */
    //  Every long <select> in this application now ships a page of
    //  rows rather than the whole table, so a form being edited can
    //  carry a value the picker does not hold — a customer from two
    //  years ago, a product long past the first forty by name.
    //
    //  Assigning that value to the select would set it to nothing,
    //  the field would look empty, and saving would move the
    //  document to whatever was selected instead. The value is not
    //  wrong; the list is short. So put it back in the list.
    //
    //  Returns false only when there is nothing to put back —
    //  a value with no label, which is a caller that forgot to send
    //  one rather than a state to paper over.
    App.ensureOption = function (select, value, label) {
        if (!select || value === null || value === undefined || value === '') return true;
        const v = String(value);
        if (select.querySelector('option[value="' + CSS.escape(v) + '"]')) return true;
        if (!label) return false;

        const opt = document.createElement('option');
        opt.value = v;
        opt.textContent = label;
        // Straight after the prompt, so it reads as the current
        // answer rather than the last thing on a list.
        const first = select.options[0];
        if (first && first.value === '') first.after(opt);
        else select.prepend(opt);
        return true;
    };

    /* ── Reusable modal component ────────────────────────── */
    // Programmatic API: App.openModal({title, content, wide}) →
    // {overlay, close}. `content` is an HTML string or a Node.
    App.openModal = function (opts = {}) {
        const { title = '', content = '', wide = false, xl = false } = opts;

        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        const modal = document.createElement('div');
        modal.className = 'modal' + (xl ? ' modal--xl' : (wide ? ' modal--wide' : ''));
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');

        const head = document.createElement('div');
        head.className = 'modal-head';
        const h = document.createElement('h3');
        h.className = 'modal-title';
        h.textContent = title;
        const x = document.createElement('button');
        x.type = 'button';
        x.className = 'modal-x';
        x.innerHTML = '&times;';
        x.setAttribute('aria-label', 'Close');
        head.appendChild(h);
        head.appendChild(x);

        const body = document.createElement('div');
        body.className = 'modal-body';
        if (content instanceof Node) body.appendChild(content);
        else body.innerHTML = content;

        modal.appendChild(head);
        modal.appendChild(body);
        overlay.appendChild(modal);
        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('is-open'));

        const close = () => {
            overlay.classList.remove('is-open');
            setTimeout(() => overlay.remove(), 180);
            document.removeEventListener('keydown', onEsc);
        };
        const onEsc = (e) => { if (e.key === 'Escape') close(); };
        x.addEventListener('click', close);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
        overlay.addEventListener('click', (e) => {
            if (e.target.closest('[data-modal-close]')) close();
        });
        document.addEventListener('keydown', onEsc);

        const focusable = modal.querySelector('input, select, textarea, button:not(.modal-x)');
        focusable?.focus();

        return { overlay, close };
    };

    // Declarative triggers: <button data-modal-open="tplId"
    //   data-modal-title="…" data-prefill='{"field":"value"}'>
    // clones <template id="tplId"> into a modal and pre-fills any
    // form fields whose name matches a key in data-prefill.
    function initModalTriggers() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-modal-open]');
            if (!btn) return;
            const tpl = document.getElementById(btn.getAttribute('data-modal-open'));
            if (!tpl || tpl.tagName !== 'TEMPLATE') return;

            const content = tpl.content.cloneNode(true);
            const { overlay } = App.openModal({
                title: btn.getAttribute('data-modal-title') || '',
                content: content,
                wide: btn.hasAttribute('data-modal-wide'),
                xl: btn.hasAttribute('data-modal-xl'),
            });

            // Labels for values a bounded picker may not be holding.
            // A <select> now ships a page of rows, not the whole
            // table, so editing last year's quote can hand it a
            // customer_id with no matching <option> — the field
            // would silently come up blank and the save would move
            // the document to whoever was first in the list. The
            // server sends the label so the option can be recreated.
            let labels = {};
            try { labels = JSON.parse(btn.getAttribute('data-prefill-labels') || '{}'); }
            catch (err) { labels = {}; }

            const prefillRaw = btn.getAttribute('data-prefill');
            if (prefillRaw) {
                try {
                    const data = JSON.parse(prefillRaw);
                    Object.entries(data).forEach(([key, value]) => {
                        const fields = overlay.querySelectorAll('[name="' + key + '"]');
                        if (!fields.length) return;

                        // A radio group is several elements sharing one
                        // name, and the answer is which of them is
                        // checked. Taking the first and assigning to its
                        // .value would rewrite that button's value and
                        // leave the wrong one selected.
                        if (fields[0].type === 'radio') {
                            fields.forEach((f) => { f.checked = f.value === String(value ?? ''); });
                            return;
                        }

                        const field = fields[0];
                        if (field.type === 'checkbox') { field.checked = !!value && value !== 'f'; return; }

                        if (field.tagName === 'SELECT') {
                            App.ensureOption(field, value, labels[key]);
                        }
                        field.value = value ?? '';
                    });
                } catch (err) { /* malformed prefill: open empty */ }
            }
        });

        // Deep-link support: a sidebar entry or dashboard shortcut can
        // point at a list page with ?new=1 and have its "add" modal
        // open straight away. That keeps every create flow inside one
        // window instead of navigating to a separate form page.
        const wants = new URLSearchParams(window.location.search).get('new');
        if (wants) {
            const trigger = document.querySelector(
                wants === '1'
                    ? '[data-modal-open][data-modal-auto]'
                    : '[data-modal-open][data-modal-auto="' + CSS.escape(wants) + '"]'
            );
            if (trigger) {
                trigger.click();
                // Drop the flag so a refresh or a back-navigation does
                // not pop the form open again.
                const url = new URL(window.location.href);
                url.searchParams.delete('new');
                window.history.replaceState({}, '', url);
            }
        }
    }

    /* ── AJAX form submission (works inside modals) ──────── */
    // <form data-ajax> posts via fetch and expects JSON:
    //   { success: bool, message: string, redirect?: url }
    // On success: toast, close the modal, then reload (or follow
    // redirect) so the affected table reflects the change.
    function initAjaxForms() {
        document.addEventListener('submit', (e) => {
            const form = e.target.closest('form[data-ajax]');
            if (!form) return;
            e.preventDefault();

            const submitBtn = form.querySelector('[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            // Read these off the attributes, never off the form
            // object. A named control shadows the property of the
            // same name, so a form carrying <select name="method">
            // — every payment form does — returns that element from
            // form.method instead of the string "post". fetch() then
            // gets an object where a verb belongs, rejects, and the
            // user is told the request failed for no visible reason.
            const action = form.getAttribute('action') || window.location.href;
            const method = (form.getAttribute('method') || 'POST').toUpperCase();

            fetch(action, {
                method: method,
                body: new FormData(form),
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                // A handler that answers with a page instead of JSON —
                // a 403, a session that expired into the sign-in form, a
                // PHP error — used to surface as "Request failed", which
                // tells the user nothing. Read the body first and say
                // what actually came back.
                .then((res) => res.text().then((text) => parseReply(res, text)))
                .then((data) => {
                    App.notify(data.message || (data.success ? 'Saved.' : 'Something went wrong.'),
                        data.success ? 'success' : 'error');
                    if (data.success) {
                        form.closest('.modal-overlay')?.querySelector('.modal-x')?.click();
                        setTimeout(() => {
                            if (data.redirect) window.location.href = data.redirect;
                            else window.location.reload();
                        }, 600);
                    } else if (submitBtn) {
                        submitBtn.disabled = false;
                    }
                })
                .catch(() => {
                    // Nothing came back at all — the network, not the app.
                    App.notify('Could not reach the server. Check your connection and try again.', 'error');
                    if (submitBtn) submitBtn.disabled = false;
                });
        });
    }

    /**
     * Make sense of whatever came back.
     *
     * The happy path is plain JSON. Everything else is a symptom:
     * a 403 page, a session that timed out into the sign-in form, a
     * PHP notice printed ahead of the JSON. Each used to surface as
     * "Request failed", which tells the user nothing at all.
     */
    function parseReply(res, text) {
        try {
            return JSON.parse(text);
        } catch (err) { /* not plain JSON — work out why below */ }

        // A stray warning or notice printed before the body does not
        // undo the work the server did. Recover the JSON so the user
        // is told what actually happened, and complain in the console
        // so the notice still gets fixed.
        const embedded = text.match(/\{[\s\S]*\}\s*$/);
        if (embedded) {
            try {
                const data = JSON.parse(embedded[0]);
                console.warn('Server printed output before its JSON reply:',
                    text.slice(0, embedded.index).trim());
                return data;
            } catch (err) { /* not that either */ }
        }

        console.error('Expected JSON, got:', res.status, text.slice(0, 2000));
        return { success: false, message: describeNonJson(res, text) };
    }

    /**
     * Turn a non-JSON response into a sentence worth reading.
     *
     * The body is a page, so it is no use to the user directly, but
     * the status and a couple of markers in it say plainly enough
     * what went wrong.
     */
    function describeNonJson(res, text) {
        if (res.status === 401 || /name="password"/i.test(text)) {
            return 'Your session has expired. Reload the page and sign in again.';
        }
        if (res.status === 403 || /Access Denied/i.test(text)) {
            return 'You do not have permission to do that. Ask an administrator to give you the right role.';
        }
        if (res.status === 404) {
            return 'That page is missing. It may have been moved or renamed.';
        }
        if (res.status === 413) {
            return 'That file is too large to upload.';
        }
        // A PHP fatal or warning. Tags are stripped first: the message
        // is usually wrapped in <b>…</b>, which would otherwise cut it
        // off after two words.
        const plain = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ');
        const phpError = plain.match(/(Fatal error|Parse error|Warning|Notice|Deprecated|Uncaught)[^{]{0,180}/i);
        if (phpError) {
            return 'The server reported an error: ' + phpError[0].trim();
        }
        return 'The server sent an unexpected reply (HTTP ' + res.status
            + '). Check the browser console for details.';
    }

    /* ── Confirm-before-submit forms ─────────────────────── */
    //  Delegated, and on the CAPTURE phase, because initAjaxForms
    //  listens for submit on the document too. A form-level listener
    //  runs first but the event still bubbles on, so a form carrying
    //  both data-confirm and data-ajax used to post immediately —
    //  before the question was answered — and then post a second time
    //  when it was. Every "are you sure?" on an AJAX form was applying
    //  its action twice.
    //
    //  Capture runs ahead of every bubble listener, so stopping the
    //  event here really does stop it. Once confirmed the form is
    //  submitted with requestSubmit(), which fires a real submit event
    //  the delegated AJAX handler can still see — form.submit() does
    //  not fire one at all, which is why it bypassed AJAX entirely.
    //
    //  Delegation also means a form cloned out of a <template> into a
    //  modal is covered, which a querySelectorAll at load time was not.
    function initConfirmForms() {
        document.addEventListener('submit', (e) => {
            const form = e.target.closest('form[data-confirm]');
            if (!form) return;

            // Second pass, after the question was answered: let it through.
            if (form.dataset.confirmed === '1') {
                delete form.dataset.confirmed;
                return;
            }
            e.preventDefault();
            e.stopImmediatePropagation();

            App.confirm({ title: 'Please confirm', text: form.getAttribute('data-confirm') })
                .then((ok) => {
                    if (!ok) return;
                    form.dataset.confirmed = '1';
                    form.requestSubmit();
                });
        }, true);
    }

    /* ── Controls that submit their own form ─────────────── */
    //  A filter dropdown, or the status picker on a document row:
    //  choosing an option is the whole interaction, so there is no
    //  Apply button to press.
    //
    //  These used to carry onchange="this.form.submit()". The
    //  Content-Security-Policy sets script-src to 'self' plus a
    //  nonce and deliberately does NOT allow 'unsafe-inline', so
    //  the browser refused to run every one of them — silently, as
    //  far as the user is concerned. Picking "Accepted" on a quote
    //  did nothing at all.
    //
    //  Delegated, so it also covers rows and modals rendered later.
    function initAutoSubmit() {
        document.addEventListener('change', (e) => {
            const el = e.target.closest('[data-autosubmit]');
            if (!el || !el.form) return;
            // requestSubmit(), not submit(): it fires a real submit
            // event, so a data-confirm or data-ajax form still gets
            // its turn. submit() bypasses both.
            if (typeof el.form.requestSubmit === 'function') {
                el.form.requestSubmit();
            } else {
                el.form.submit();
            }
        });
    }

    /* ── Type-to-search over a long <select> ─────────────── */
    //  A picker with two hundred customers in it is a scroll, not a
    //  choice. This filters the options of the select that follows
    //  the box, the same way the document builder's product picker
    //  already works — one interaction to learn, not two.
    //
    //  Matching runs over the option's text plus its data-search,
    //  so a customer can be found by phone or email without those
    //  having to clutter the label.
    //
    //  Two modes, one interaction:
    //
    //    LOCAL   — the box has no data-lookup. Every option is in
    //              the page and we hide the ones that miss. This is
    //              right for a list that is short by nature, like
    //              cash accounts or currencies.
    //
    //    REMOTE  — the box carries data-lookup="<list>". The page
    //              only shipped a seed, so typing asks the server
    //              and replaces the options with what comes back.
    //              This exists because the customer picker was
    //              twenty thousand options and 5 MB of HTML, and the
    //              cashbook's invoice picker was 37 MB. See
    //              includes/lookup.php.
    //
    //  Delegated, because every one of these lives in a form cloned
    //  out of a <template> when a modal opens.
    function initSelectSearch() {
        document.addEventListener('input', (e) => {
            const box = e.target.closest('[data-search-select]');
            if (!box) return;

            const wrap = box.closest('.select-search') || box.parentElement;
            const select = wrap && wrap.querySelector('select');
            if (!select) return;

            if (box.dataset.lookup) {
                remoteSearch(box, wrap, select);
            } else {
                localFilter(box, wrap, select);
            }
        });

        // Enter in a search box would submit the form around it,
        // half filled. The box is a way to find a row, never a way
        // to say "done".
        document.addEventListener('keydown', (e) => {
            const box = e.target.closest('[data-search-select]');
            if (box && e.key === 'Enter') e.preventDefault();
        });
    }

    function localFilter(box, wrap, select) {
        const q = box.value.trim().toLowerCase();
        let shown = 0;
        let first = null;

        Array.prototype.forEach.call(select.options, (opt) => {
            // The prompt option stays: it is how you clear a choice.
            if (opt.value === '') return;
            const hay = (opt.textContent + ' ' + (opt.dataset.search || '')).toLowerCase();
            const hit = q === '' || hay.includes(q);
            opt.hidden = !hit;
            if (hit) { shown++; if (!first) first = opt; }
        });

        // Jump to the first match, so typing enough to be unique is
        // the whole interaction. Only when the current choice is
        // nothing, or has just been filtered out of sight — never
        // overrule a selection the user can still see.
        //
        // And never on a filter that submits its form on change:
        // there the jump would reload the page on the first letter
        // typed, and you would never reach the second.
        const cur = select.selectedOptions[0];
        if (q !== '' && first && (!cur || cur.value === '' || cur.hidden)) {
            select.value = first.value;
            if (!select.matches('[data-autosubmit]')) {
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        const note = wrap.querySelector('[data-search-empty]');
        if (note) note.hidden = !(q !== '' && shown === 0);
    }

    /* ── Any long dropdown can be searched ───────────────── */
    //  A page marks a list that GROWS WITH THE BUSINESS:
    //
    //      <select name="supplier_id" data-searchable="Search suppliers">
    //
    //  and this puts the shared search box on it. The page states
    //  the intent; the browser decides whether the list is actually
    //  long enough to need help, so a business with four suppliers
    //  gets a plain dropdown and the same page grows into a search
    //  box on its own.
    //
    //  Built here rather than written into thirty-odd templates
    //  because the wrapper has to put the <select> FIRST and the box
    //  second — a <label> labels the first control inside it, and
    //  getting that backwards is what made every dropdown close
    //  before you could choose (see includes/lookup.php). One place
    //  to get it right.
    //  How long a list has to be before a search box earns its place,
    //  and it is not one number, because the cost is not the same in
    //  both places.
    //
    //  In a form the picker has a row and a label to itself, so a box
    //  above it costs nothing and eight options is enough to want one.
    //
    //  In a TOOLBAR it costs a great deal. The row is already full of
    //  controls, and the page nearly always has its own search box a
    //  few pixels away — "Search name, SKU or barcode" next to "Type
    //  to search…" is two boxes and no way to tell which does what.
    //  So there the bar is what a native dropdown can show at once:
    //  if the whole list fits in the popup, opening it IS the search.
    const SEARCH_WORTH_IT = 8;
    const SEARCH_WORTH_IT_TOOLBAR = 20;

    function makeSearchable(select) {
        if (!select || select.dataset.searchReady) return;
        // Already inside a picker that brought its own box.
        if (select.closest('.select-search, .db-pick')) return;

        const inToolbar = !!select.closest('.toolbar, .toolbar-search');
        const floor = inToolbar ? SEARCH_WORTH_IT_TOOLBAR : SEARCH_WORTH_IT;
        if (select.options.length < floor) return;

        select.dataset.searchReady = '1';

        const wrap = document.createElement('span');
        wrap.className = 'select-search';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);            // the select comes first

        const box = document.createElement('input');
        box.type = 'search';
        box.className = 'form-control select-search-input';
        box.autocomplete = 'off';
        //  The placeholder says WHAT it searches — "Search suppliers",
        //  not "Type to search…". A page usually has a search box of
        //  its own, and two boxes both saying "type to search" leave
        //  no way to tell which one narrows the list you are looking
        //  at. The page already had to name the list to mark it.
        const what = select.dataset.searchable || 'Search';
        box.placeholder = what + '…';
        box.setAttribute('aria-label', what);
        box.setAttribute('data-search-select', '');
        wrap.appendChild(box);

        const note = document.createElement('span');
        note.className = 'select-search-empty';
        note.setAttribute('data-search-empty', '');
        note.hidden = true;
        note.textContent = 'Nothing matches that.';
        wrap.appendChild(note);
    }

    function initSearchableSelects(root) {
        (root || document).querySelectorAll('select[data-searchable]').forEach(makeSearchable);

        // Modals are cloned out of <template> when they open, and
        // the document builder adds rows as you type. Watching for
        // them beats remembering to call this from each one.
        if (root) return;
        new MutationObserver((records) => {
            records.forEach((r) => {
                r.addedNodes.forEach((n) => {
                    if (n.nodeType !== 1) return;
                    if (n.matches('select[data-searchable]')) makeSearchable(n);
                    n.querySelectorAll('select[data-searchable]').forEach(makeSearchable);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    //  Debounced, because a request per keystroke is a request per
    //  keystroke times every person in the office. 220ms is under
    //  the pause between words and over the pause between letters.
    const LOOKUP_WAIT = 220;

    function remoteSearch(box, wrap, select) {
        clearTimeout(box._lookupTimer);
        box._lookupTimer = setTimeout(() => runLookup(box, wrap, select), LOOKUP_WAIT);
    }

    function runLookup(box, wrap, select) {
        const q = box.value.trim();

        // Whatever is chosen must come back in the reply, or the
        // form would post a customer_id the <select> no longer
        // holds and quietly lose the filter it was showing.
        const keep = select.value ? select.value : '';

        // A later keystroke wins. Without this, a slow reply for
        // "ac" can land after the reply for "acme" and put the wrong
        // list on screen — the classic out-of-order autocomplete.
        const seq = (box._lookupSeq = (box._lookupSeq || 0) + 1);

        if (box._lookupAbort) box._lookupAbort.abort();
        const ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        box._lookupAbort = ctl;

        const url = (box.dataset.lookupUrl || 'lookup.php') +
                    '?list=' + encodeURIComponent(box.dataset.lookup) +
                    '&q=' + encodeURIComponent(q) + '&keep=' + encodeURIComponent(keep);

        wrap.classList.add('is-looking');

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: ctl ? ctl.signal : undefined,
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(r.status))))
            .then((data) => {
                if (seq !== box._lookupSeq) return;      // superseded
                fillOptions(select, data.rows || [], keep);
                const note = wrap.querySelector('[data-search-empty]');
                if (note) note.hidden = !(q !== '' && (data.rows || []).length === 0);
            })
            .catch((err) => {
                if (err && err.name === 'AbortError') return;
                if (seq !== box._lookupSeq) return;
                // Leave the options alone. A search that could not
                // run must not empty a picker that was working.
                const note = wrap.querySelector('[data-search-empty]');
                if (note) { note.hidden = false; note.textContent = 'Search unavailable — check the connection.'; }
            })
            .finally(() => {
                if (seq === box._lookupSeq) wrap.classList.remove('is-looking');
            });
    }

    function fillOptions(select, rows, keep) {
        const prompt = select.options[0] && select.options[0].value === '' ? select.options[0] : null;
        const chosen = select.value;

        // The prompt is how a filter is cleared, so it survives.
        select.textContent = '';
        if (prompt) select.appendChild(prompt);

        rows.forEach((row) => {
            const opt = document.createElement('option');
            opt.value = String(row.id);
            opt.textContent = row.name;
            if (row.search) opt.dataset.search = row.search;
            // Whatever else the list carries — the balance to offer,
            // the party to name — as the same data-* attributes the
            // server would have rendered, so the code that reads
            // them cannot tell where the option came from.
            if (row.data) {
                Object.keys(row.data).forEach((k) => { opt.dataset[k] = row.data[k]; });
            }
            select.appendChild(opt);
        });

        // Keep the chosen row selected if it came back; otherwise
        // select the first match, which is what makes typing enough
        // of a name the whole interaction.
        if (chosen && select.querySelector('option[value="' + CSS.escape(chosen) + '"]')) {
            select.value = chosen;
        } else if (rows.length && (!chosen || chosen === keep)) {
            select.value = String(rows[0].id);
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    /* ── Print this page ─────────────────────────────────── */
    function initPrintButtons() {
        document.addEventListener('click', (e) => {
            if (e.target.closest('[data-print]')) {
                e.preventDefault();
                window.print();
            }
        });
    }

    /* ── A header checkbox that ticks its column ─────────────
       Used on the warranty note screen, where every line is
       covered by default and the exceptions are unticked. The
       header box also reflects the column: untick one line and it
       stops claiming everything is selected. */
    function initCheckAll() {
        document.querySelectorAll('[data-check-all]').forEach((master) => {
            const table = master.closest('table');
            if (!table) return;

            const boxes = () => Array.from(
                table.querySelectorAll('tbody input[type="checkbox"]'));

            master.addEventListener('change', () => {
                boxes().forEach((b) => { b.checked = master.checked; });
            });

            table.addEventListener('change', (e) => {
                if (!e.target.matches('tbody input[type="checkbox"]')) return;
                const all = boxes();
                const on = all.filter((b) => b.checked).length;
                master.checked = on === all.length;
                master.indeterminate = on > 0 && on < all.length;
            });
        });
    }

    /* ── Cover starts on installation, else delivery ─────────
       Section 1 of the warranty terms says exactly this, so the
       form works it out rather than asking somebody to. It stops
       following the moment the field is edited by hand: an
       override that gets silently undone is worse than no help
       at all. */
    function initCoverStart() {
        const start = document.querySelector('[data-cover-start]');
        const sources = document.querySelectorAll('[data-cover-source]');
        if (!start || !sources.length) return;

        let overridden = false;
        start.addEventListener('input', () => { overridden = true; });

        sources.forEach((src) => {
            src.addEventListener('change', () => {
                if (overridden) return;
                const vals = Array.from(sources).map((s) => s.value).filter(Boolean);
                // Installation wins where there is one; it is last in
                // the DOM, so the last non-empty value is the answer.
                if (vals.length) start.value = vals[vals.length - 1];
            });
        });
    }

    /* ── Theme (dark / light) toggle ─────────────────────── */
    function initThemeToggle() {
        document.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const root = document.documentElement;
                const next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
                if (next === 'light') root.setAttribute('data-theme', 'light');
                else root.removeAttribute('data-theme');
                try { localStorage.setItem('erp-theme', next); } catch (e) { /* private mode */ }
            });
        });
    }

    /* ── One press, one submission ───────────────────────── */
    //  A form that posts a whole page has no spinner and no visible
    //  change until the reply comes back, so a slow save looks like
    //  a button that did not work — and the honest response to a
    //  button that did not work is to press it again.
    //
    //  Every one of those presses is another POST. Recording a
    //  payment twice is not a cosmetic problem: the database now
    //  refuses the second one (migration 054), and document numbers
    //  no longer collide (053), but both of those are the last line
    //  of defence. This is the first: after the first submission the
    //  button says what is happening and stops taking presses.
    //
    //  data-ajax forms already do this in initAjaxForms(). This is
    //  for the ordinary ones — delete, approve, convert, post, and
    //  every plain <form method="POST"> in the application.
    //
    //  Deliberately NOT disabled: a disabled control is not
    //  submitted, and disabling a submit button that carries a name
    //  and a value would drop it from the POST. aria-disabled plus a
    //  guard on the event says the same thing to a screen reader
    //  without changing what is sent.
    function initSubmitOnce() {
        function release(form) {
            delete form.dataset.submitting;
            const btn = form.querySelector('[type="submit"]');
            if (!btn) return;
            btn.removeAttribute('aria-disabled');
            btn.classList.remove('is-working');
            if (btn.dataset.wasLabel) {
                btn.textContent = btn.dataset.wasLabel;
                delete btn.dataset.wasLabel;
            }
        }

        function showWorking(form) {
            const btn = form.querySelector('[type="submit"]');
            if (!btn) return;
            btn.setAttribute('aria-disabled', 'true');
            btn.classList.add('is-working');
            if (btn.tagName === 'BUTTON' && btn.textContent.trim()) {
                btn.dataset.wasLabel = btn.textContent;
                btn.textContent = btn.dataset.workingLabel || 'Working…';
            }
        }

        /*  On the CAPTURE phase, and that matters twice.
         *
         *  Bubbling would put this after any handler the form has of
         *  its own, and a form whose handler calls preventDefault —
         *  validation, a custom widget — would hide the event from
         *  the guard entirely. Capture sees it first, so the second
         *  press is blocked whatever else is listening.
         *
         *  And initConfirmForms() also captures on document, and is
         *  registered before this one, so it still gets to ask its
         *  question first. */
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!form || form.matches('[data-ajax]') || form.matches('[data-submit-many]')) {
                return;
            }

            if (form.dataset.submitting === '1') {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            // Set synchronously: two clicks in the same tick must not
            // both get through while a timer decides.
            form.dataset.submitting = '1';

            /*  Whether this submission is really happening is not
             *  known yet — the confirm prompt cancels it to ask a
             *  question, and a validation handler cancels it for
             *  good. Both have run by the end of the dispatch, so
             *  the decision waits until then.
             *
             *  Cancelled: let go, or the confirm dialog's own
             *  re-submit would be blocked and Delete would never work.
             *  Going ahead: say so on the button. */
            setTimeout(() => {
                if (e.defaultPrevented) {
                    release(form);
                    return;
                }
                showWorking(form);

                /*  And let go again if the page is somehow still
                 *  here much later. A submission can end without a
                 *  navigation — a download, a cancelled unload, the
                 *  back button — and a permanently dead Save button
                 *  is a worse bug than the double press. */
                setTimeout(() => release(form), 8000);
            }, 0);
        }, true);
    }

    /* ── Wide tables on a narrow screen ──────────────────────
     *
     *  A list table is eight to eleven columns wide. On a desktop
     *  that is the point of it; on a phone it is between 870 and
     *  1400 pixels of table inside a 390 pixel window, and
     *  .table-wrap turns that into a sideways scroll strip. Measured
     *  on the invoice list: 1409px of table, of which 390 are
     *  visible. You could see the number or the amount, never both,
     *  and the Actions column was four swipes away.
     *
     *  Below 640px the CSS turns each row into a card, one line per
     *  column. For that the cell has to carry its own heading,
     *  because CSS cannot reach across the table to the <thead>. So
     *  this copies the header text onto every cell as data-label,
     *  once, at load.
     *
     *  Doing it here rather than in the markup is deliberate: there
     *  are 51 pages with a .data-table on them, and a rule that has
     *  to be remembered on page 52 is a rule that will be forgotten.
     *  It is also why this degrades honestly — if the script does
     *  not run, the table stays exactly the scrolling table it is
     *  today rather than collapsing into an unreadable stack.
     *
     *  Two kinds of table are left alone. Anything inside a
     *  .doc-sheet — the line items on an invoice or a quote as it
     *  will be printed — keeps the shape of the document it is
     *  showing; a customer asking "what does my invoice say" should
     *  see the invoice, not a summary of it. And any table can opt
     *  out with data-no-stack.                                   */
    function initStackedTables() {
        document.querySelectorAll('table.data-table').forEach((table) => {
            if (table.hasAttribute('data-no-stack')) return;
            if (table.closest('.doc-sheet')) return;

            // The last header row is the one that names the columns;
            // an upper row, where there is one, spans groups.
            const headRows = table.querySelectorAll('thead tr');
            if (!headRows.length) return;
            const heads = Array.from(headRows[headRows.length - 1].children)
                .map((th) => th.textContent.trim());
            if (!heads.length) return;

            table.querySelectorAll('tbody tr').forEach((tr) => {
                const cells = Array.from(tr.children);

                /*  A row that spans the whole table is not a record —
                 *  it is "No products match these filters", or a
                 *  totals banner. Cards are for records. Marking it
                 *  lets the CSS leave it alone.                    */
                if (cells.length === 1 && cells[0].colSpan > 1) {
                    tr.setAttribute('data-dt-full', '');
                    return;
                }

                let col = 0;
                cells.forEach((td) => {
                    if (!td.hasAttribute('data-label') && heads[col] !== undefined) {
                        td.setAttribute('data-label', heads[col]);
                    }
                    col += td.colSpan || 1;
                });
            });

            table.setAttribute('data-dt-stack', '');
        });
    }

    /* ── Filters that do not eat the whole first screen ──────
     *
     *  Every list page carries a filter form: dates, a customer
     *  picker, two or three selects, a row of quick-range chips.
     *  Stacked one per line on a phone that is the entire viewport —
     *  measured on the invoice list, you scroll past six full-width
     *  fields before the first invoice appears. The filters are
     *  occasionally useful; the invoices are why you opened the page.
     *
     *  So on a narrow screen they fold behind one button, and the
     *  search box — the filter people actually use — stays out.
     *
     *  Two things this must not do. It must not hide a filter that
     *  is switched on, because a list that is quietly filtered and
     *  does not say so is how somebody concludes a customer has no
     *  invoices. So the button carries a count, and the panel opens
     *  by itself when anything is set. And it must not touch the
     *  desktop layout at all: the button is display:none above the
     *  breakpoint and the collapsing class does nothing there.   */
    const NARROW = '(max-width: 640px)';

    function filtersInUse(form) {
        let n = 0;
        form.querySelectorAll('.filter-row--fields [name]').forEach((el) => {
            if (el.disabled || el.type === 'submit' || el.type === 'hidden') return;
            const v = (el.value || '').trim();
            if (v !== '') n++;
        });
        // A chip that is switched on is a date range, already counted
        // by the from/to fields it sets. Counting it again would read
        // "2 filters" for one choice.
        return n;
    }

    function initFilterDisclosure() {
        document.querySelectorAll('form.filter-bar').forEach((form) => {
            const foldable = form.querySelectorAll(
                '.filter-row--fields, .filter-row--presets'
            );
            if (!foldable.length) return;

            const active = filtersInUse(form);

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'filter-toggle';
            const label = document.createElement('span');
            label.className = 'filter-toggle-label';
            label.textContent = 'Filters';
            btn.appendChild(label);
            if (active > 0) {
                const count = document.createElement('span');
                count.className = 'filter-toggle-count';
                count.textContent = String(active);
                btn.appendChild(count);
            }

            // Anything already filtering stays visible. Only a form
            // with nothing set starts folded away.
            let open = active > 0;
            const apply = () => {
                form.classList.toggle('filters-folded', !open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            };
            btn.setAttribute('aria-controls', '');
            apply();

            btn.addEventListener('click', () => { open = !open; apply(); });

            // Straight after the search row, so it reads as "search,
            // or narrow it down".
            const first = form.querySelector('.filter-row');
            if (first && first.nextSibling) {
                form.insertBefore(btn, first.nextSibling);
            } else {
                form.insertBefore(btn, form.firstChild);
            }

            /*  Coming back to a wide window must not leave the fields
             *  hidden — the class is inert there, but the open flag
             *  would be wrong the next time the window narrows.    */
            if (window.matchMedia) {
                const mq = window.matchMedia(NARROW);
                const onChange = (e) => { if (!e.matches) { open = active > 0; apply(); } };
                if (mq.addEventListener) mq.addEventListener('change', onChange);
            }
        });
    }

    /* ── Choosing a customer on a phone ──────────────────────
     *
     *  Every picker in the application is a <select> with a search
     *  box above it. On a desktop that works: you type, the options
     *  filter, you open the list and the short list is there.
     *
     *  On a phone it does not, because tapping a <select> does not
     *  open the list — it hands the whole screen to the operating
     *  system's own picker, which knows nothing about the search box
     *  above it. What you get is every customer in the business as
     *  one scrolling column of radio buttons, unsearchable, with the
     *  form you were filling in hidden behind it. With forty
     *  customers that is annoying. With four hundred it is the
     *  reason somebody stops using the application on their phone.
     *
     *  So below 640px the native list is taken out of the loop. The
     *  search box becomes the control, and the matches appear under
     *  it as an ordinary list that this page draws and can therefore
     *  filter. The <select> stays exactly where it was and keeps the
     *  value: it is what the form posts, what `required` is checked
     *  against, and what docbuilder.js reads the price off. Nothing
     *  server-side knows this happened.
     *
     *  It is CLIPPED rather than display:none. A required <select>
     *  that is display:none cannot be focused, and the browser
     *  refuses to submit the form with "an invalid form control is
     *  not focusable" — no message, no indication of which field, on
     *  a screen where the field is not visible anyway.           */
    function initMobilePickers() {
        if (!window.matchMedia) return;
        const mq = window.matchMedia(NARROW);

        function labelFor(select) {
            const opt = select.selectedOptions && select.selectedOptions[0];
            return opt && opt.value !== '' ? opt.textContent.trim() : '';
        }

        function build(wrap) {
            if (wrap.dataset.pickerReady !== undefined) return;
            const select = wrap.querySelector('select');
            const box = wrap.querySelector('[data-search-select]');
            if (!select || !box) return;
            wrap.dataset.pickerReady = '';

            const list = document.createElement('div');
            list.className = 'picker-list';
            list.setAttribute('role', 'listbox');
            list.hidden = true;
            wrap.appendChild(list);

            const close = () => { list.hidden = true; wrap.classList.remove('is-picking'); };

            /*  Drawn from the <select>'s own options, and only the
             *  ones still showing — localFilter() and remoteSearch()
             *  have already decided which those are by setting
             *  opt.hidden, so there is one filtering rule in this
             *  file and not two that can disagree. */
            function render() {
                list.textContent = '';
                let n = 0;
                Array.prototype.forEach.call(select.options, (opt) => {
                    if (opt.hidden) return;
                    if (opt.value === '' && select.required) return;
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'picker-option';
                    b.setAttribute('role', 'option');
                    b.dataset.value = opt.value;
                    b.textContent = opt.textContent.trim();
                    if (opt.value === select.value) {
                        b.classList.add('is-chosen');
                        b.setAttribute('aria-selected', 'true');
                    }
                    list.appendChild(b);
                    n++;
                });
                list.hidden = n === 0;
            }

            function open() {
                if (!mq.matches) return;
                wrap.classList.add('is-picking');
                render();
            }

            box.addEventListener('focus', () => {
                // Typing should replace the name that is already
                // there rather than be appended to it.
                box.select();
                open();
            });

            // After initSelectSearch's own handler, which is what
            // sets opt.hidden. Same event, later frame.
            box.addEventListener('input', () => {
                if (!mq.matches) return;
                requestAnimationFrame(() => { if (wrap.classList.contains('is-picking')) render(); });
            });

            /*  A remote lookup replaces the options asynchronously,
             *  long after the keystroke that asked for them. */
            new MutationObserver(() => {
                if (wrap.classList.contains('is-picking')) render();
            }).observe(select, { childList: true });

            list.addEventListener('click', (e) => {
                const opt = e.target.closest('.picker-option');
                if (!opt) return;
                select.value = opt.dataset.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                box.value = labelFor(select);
                close();
                box.blur();
            });

            // Something else set the value — an edit form filling
            // itself in, or the desktop <select> on a wide screen.
            select.addEventListener('change', () => {
                const name = labelFor(select);
                if (name) box.value = name;
            });

            document.addEventListener('click', (e) => {
                if (!wrap.contains(e.target)) close();
            });
            box.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

            const chosen = labelFor(select);
            if (chosen) box.value = chosen;
        }

        function sweep() {
            if (!mq.matches) return;
            document.querySelectorAll('.select-search').forEach(build);
        }

        sweep();
        if (mq.addEventListener) mq.addEventListener('change', sweep);

        /*  Rows the document builder adds after this ran, and
         *  pickers inside a modal that is filled in when it opens. */
        new MutationObserver((records) => {
            if (!mq.matches) return;
            for (const r of records) {
                for (const node of r.addedNodes) {
                    if (node.nodeType !== 1) continue;
                    if (node.matches && node.matches('.select-search')) build(node);
                    if (node.querySelectorAll) node.querySelectorAll('.select-search').forEach(build);
                }
            }
        }).observe(document.body, { childList: true, subtree: true });
    }

    /* ── Boot ────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        initStackedTables();
        initFilterDisclosure();
        initSidebar();
        initNavGroups();
        initDropdowns();
        initAlerts();
        initPasswordToggles();
        initTableSearch();
        initConfirmForms();
        initModalTriggers();
        initAjaxForms();
        // After initConfirmForms: that one listens on the capture
        // phase and cancels the first submit while it asks the
        // question, and this one steps aside for a cancelled event.
        initSubmitOnce();
        initAutoSubmit();
        initSelectSearch();
        initSearchableSelects();
        // After initSelectSearch: that one decides which options are
        // still showing, and this one draws them.
        initMobilePickers();
        initPrintButtons();
        initThemeToggle();
        initCheckAll();
        initCoverStart();
    });

    window.App = App;
})();
