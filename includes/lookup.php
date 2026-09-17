<?php
/**
 * Pickers that do not send the whole table.
 * ─────────────────────────────────────────────────────────────
 *
 *  Every "choose a customer" box in this application used to be a
 *  <select> holding every customer, filtered in the browser. At two
 *  hundred customers that is a good design: no round trip, no
 *  waiting, works offline. At twenty thousand it is not a design at
 *  all — the invoice list weighed 5 MB, and the cashbook, whose
 *  picker held every invoice, weighed **37 MB**. On the connection
 *  a Nairobi office actually has, that is a page that never arrives.
 *
 *  So the same box now sends a bounded seed — what the user most
 *  likely wants, plus whatever is already selected so the form round
 *  trips — and asks the server for the rest as they type.
 *
 *  The rule this file exists to keep: a list is defined ONCE. The
 *  page that renders the seed and the endpoint that answers the
 *  typing both go through lookup_query(), so they can never drift
 *  into showing different customers for the same picker — which is
 *  the failure mode that makes a search box worse than a long list.
 *
 *  Adding a picker means adding one entry to LOOKUPS. Nothing else.
 */

declare(strict_types=1);

//  For the invoice balance. Borrowed rather than restated: the
//  cashbook already owns the one definition of what an invoice is
//  still owed, and a picker offering a different figure from the
//  receivables report is worse than no picker at all.
require_once __DIR__ . '/cashbook.php';

/** How many rows a page renders before anybody has typed. */
const LOOKUP_SEED = 40;

/** How many rows the endpoint returns for a search. */
const LOOKUP_LIMIT = 40;

/**
 * A list that says nothing about who may read it is read by nobody.
 *
 * Every entry below must carry a `role`, and the value LOOKUP_ANYONE
 * is how a list says "anybody signed in" out loud. A definition that
 * simply omits the key is refused — because the first version of this
 * file let `role` default to null, every list took that default, and
 * a Salesperson who is shown 403 on the supplier and purchase-order
 * pages could read both straight off this endpoint: supplier names,
 * order values, and the cost price of every product the business
 * buys.
 *
 * The rule that prevents it happening again is not "remember to set
 * the role". It is that forgetting fails closed and says so.
 */
const LOOKUP_ANYONE = '*';

/**
 * Every list a picker can be built from.
 *
 *  from/where/id/name/search   the query, in pieces
 *  role      LOOKUP_ANYONE, or the role a caller must hold. REQUIRED.
 *            It must mirror the pages that use the list — see the
 *            note against each one; a list read by a Manager-only
 *            page must not be readable by anybody less.
 *  order     what "most likely wanted" means before anybody types
 *
 *  `:q` is bound to the typed text, already lowercased and wrapped in
 *  wildcards; the kept ids are bound one placeholder each.
 */
function lookup_lists(): array
{
    $cname  = customer_name_sql('c');
    $csearch = "TRIM(CONCAT_WS(' ', c.company_name, c.first_name, c.last_name, c.phone, c.email, c.location))";

    // The cashbook's balance, not a second one. `:d` bounds every
    // part of it by date and is bound below.
    $bal = invoice_balance_parts();

    return [
        /* Everybody on the books. */
        'customers' => [
            'from'   => "FROM customers c",
            'where'  => "TRUE",
            'id'     => 'c.customer_id',
            'name'   => $cname,
            'search' => $csearch,
            'order'  => "c.created_at DESC NULLS LAST, c.customer_id DESC",
            // Read by customer advances, Manager-only.
            'role'   => ROLE_MANAGER,
        ],

        /* Only the ones still trading — what a new document offers. */
        'active_customers' => [
            'from'   => "FROM customers c",
            'where'  => "c.is_active = TRUE",
            'id'     => 'c.customer_id',
            'name'   => $cname,
            'search' => $csearch,
            'order'  => "c.created_at DESC NULLS LAST, c.customer_id DESC",
            // Read by quotes, proformas and sales orders, which a salesperson raises.
            'role'   => LOOKUP_ANYONE,
        ],

        /* Customers that actually have an invoice: a filter option
           that returns nothing is a filter option that should not be
           in the list. Same reasoning for the three below it. */
        'invoiced_customers' => [
            'from'   => "FROM customers c",
            'where'  => "EXISTS (SELECT 1 FROM invoices i WHERE i.customer_id = c.customer_id)",
            'id'     => 'c.customer_id',
            'name'   => $cname,
            'search' => $csearch,
            'order'  => "c.customer_id DESC",
            // Read by the invoice list, which any signed-in user may open.
            'role'   => LOOKUP_ANYONE,
        ],
        'paying_customers' => [
            'from'   => "FROM customers c",
            'where'  => "EXISTS (SELECT 1 FROM invoices i
                                  JOIN invoice_payments p ON p.invoice_id = i.invoice_id
                                 WHERE i.customer_id = c.customer_id)",
            'id'     => 'c.customer_id',
            'name'   => $cname,
            'search' => $csearch,
            'order'  => "c.customer_id DESC",
            // Read by invoice payments, Manager-only.
            'role'   => ROLE_MANAGER,
        ],
        'delivery_customers' => [
            'from'   => "FROM customers c",
            'where'  => "EXISTS (SELECT 1 FROM delivery_notes d WHERE d.customer_id = c.customer_id)",
            'id'     => 'c.customer_id',
            'name'   => $cname,
            'search' => $csearch,
            'order'  => "c.customer_id DESC",
            // Read by the delivery-note list, open to any signed-in user.
            'role'   => LOOKUP_ANYONE,
        ],

        /* Suppliers. Small today, and bounded anyway — a picker that
           only works while the business is small is a picker that
           breaks on the day the business grows. */
        'suppliers' => [
            'from'   => "FROM suppliers s",
            'where'  => "TRUE",
            'id'     => 's.supplier_id',
            'name'   => "s.name",
            'search' => "TRIM(CONCAT_WS(' ', s.name, s.contact_name, s.phone, s.email, s.location))",
            'order'  => "s.name",
            // Read by fixed assets and the supplier statement, both Manager-only.
            'role'   => ROLE_MANAGER,
        ],
        'active_suppliers' => [
            'from'   => "FROM suppliers s",
            'where'  => "s.is_active = TRUE",
            'id'     => 's.supplier_id',
            'name'   => "s.name",
            'search' => "TRIM(CONCAT_WS(' ', s.name, s.contact_name, s.phone, s.email, s.location))",
            'order'  => "s.name",
            // Read by supplier data: purchasing is Manager-only throughout.
            'role'   => ROLE_MANAGER,
        ],

        /* Invoices still owing something — what a receipt is posted
           against. This is the list that weighed 37 MB.

           The three `extra` fields are what the cashbook form fills
           in from the chosen invoice: the amount to offer, the party
           to name, and the sentence under the box. They travel with
           the row rather than being fetched again, because a picker
           whose summary arrives a moment after the choice reads as
           a bug. */
        'open_invoices' => [
            'from'   => "FROM invoices i
                         LEFT JOIN customers c ON c.customer_id = i.customer_id
                         {$bal['joins']}",
            'where'  => "i.status NOT IN ('cancelled', 'draft') AND ({$bal['expr']}) > 0.005",
            'id'     => 'i.invoice_id',
            'name'   => "i.invoice_number || ' · ' || " . $cname
                        . " || ' · ' || TO_CHAR({$bal['expr']}, 'FM999,999,990.00') || ' owed'",
            'search' => "TRIM(CONCAT_WS(' ', i.invoice_number, c.company_name, c.first_name, c.last_name))",
            'order'  => "i.due_date NULLS LAST, i.issue_date, i.invoice_number",
            // Read by the cash book, Manager-only — it carries balances.
            'role'   => ROLE_MANAGER,
            'params' => [':d' => null],   // filled with today at query time
            'extra'  => [
                'balance' => "TO_CHAR({$bal['expr']}, 'FM9999999990.00')",
                'party'   => $cname,
                'summary' => "'Invoiced ' || TO_CHAR(i.total_amount, 'FM999,999,990.00')
                              || ' · settled ' || TO_CHAR(i.total_amount - ({$bal['expr']}), 'FM999,999,990.00')
                              || ' · ' || TO_CHAR({$bal['expr']}, 'FM999,999,990.00') || ' still owed'
                              || COALESCE(' · due ' || TO_CHAR(i.due_date, 'DD Mon YYYY'), '')",
            ],
        ],

        /* Purchase orders still owing something. */
        'open_purchase_orders' => [
            'from'   => "FROM purchase_orders po LEFT JOIN suppliers s ON s.supplier_id = po.supplier_id",
            'where'  => "po.status NOT IN ('draft', 'cancelled', 'rejected')
                         AND COALESCE(po.total_amount, 0) - COALESCE(po.amount_paid, 0) > 0.005",
            'id'     => 'po.po_id',
            'name'   => "po.po_number || ' · ' || COALESCE(s.name, 'Supplier')
                         || ' · ' || TO_CHAR(COALESCE(po.total_amount,0) - COALESCE(po.amount_paid,0), 'FM999,999,990.00')
                         || ' to pay'",
            'search' => "TRIM(CONCAT_WS(' ', po.po_number, s.name))",
            'order'  => "po.issue_date, po.po_number",
            // Read by the cash book, Manager-only — it carries order values.
            'role'   => ROLE_MANAGER,
            'extra'  => [
                'balance' => "TO_CHAR(COALESCE(po.total_amount,0) - COALESCE(po.amount_paid,0), 'FM9999999990.00')",
                'party'   => "COALESCE(s.name, '')",
                'summary' => "'Ordered ' || TO_CHAR(COALESCE(po.total_amount,0), 'FM999,999,990.00')
                              || ' · paid ' || TO_CHAR(COALESCE(po.amount_paid,0), 'FM999,999,990.00')
                              || ' · ' || TO_CHAR(COALESCE(po.total_amount,0) - COALESCE(po.amount_paid,0), 'FM999,999,990.00')
                              || ' still to pay'",
            ],
        ],

        /* ── The catalogue, for document builders ─────────────
           Three lists rather than one, because what a line of a
           document needs to know differs by which side of the
           business it is on: a quote seeds the selling price, a
           purchase order seeds the cost, and a goods receipt has no
           business offering a service at all. Rendering all of them
           into every line of every document was half a megabyte a
           page and a <select> per line holding five thousand rows. */
        'sale_products' => [
            'from'   => "FROM products p",
            'where'  => "p.is_active = TRUE",
            'id'     => 'p.product_id',
            'name'   => "p.name || COALESCE(' (' || NULLIF(p.sku, '') || ')', '')",
            'search' => "TRIM(CONCAT_WS(' ', p.name, p.sku, p.barcode))",
            'order'  => "p.name",
            // Read by quotes, proformas and sales orders.
            'role'   => LOOKUP_ANYONE,
            'extra'  => [
                'price' => "TO_CHAR(p.selling_price, 'FM9999999990.00')",
                'cost'  => "TO_CHAR(p.cost_price, 'FM9999999990.00')",
            ],
        ],
        'purchase_products' => [
            'from'   => "FROM products p",
            'where'  => "p.is_active = TRUE",
            'id'     => 'p.product_id',
            // A service is marked in the label itself: ordering one
            // by mistake and then waiting for a delivery is exactly
            // the confusion worth spending five characters to avoid.
            'name'   => "p.name || COALESCE(' (' || NULLIF(p.sku, '') || ')', '')"
                        . (product_types_available()
                            ? " || CASE WHEN p.product_type = 'service' THEN '  (service)' ELSE '' END"
                            : ''),
            'search' => "TRIM(CONCAT_WS(' ', p.name, p.sku, p.barcode))",
            'order'  => "p.name",
            // Read by purchase orders, Manager-only — it carries cost price.
            'role'   => ROLE_MANAGER,
            'extra'  => [
                // Purchase orders buy at cost, so the picker seeds cost.
                'price' => "TO_CHAR(p.cost_price, 'FM9999999990.00')",
                'cost'  => "TO_CHAR(p.cost_price, 'FM9999999990.00')",
            ],
        ],
        'stock_products' => [
            'from'   => "FROM products p",
            'where'  => "p.is_active = TRUE AND " . goods_only_sql('p'),
            'id'     => 'p.product_id',
            'name'   => "p.name || COALESCE(' (' || NULLIF(p.sku, '') || ')', '')",
            'search' => "TRIM(CONCAT_WS(' ', p.name, p.sku, p.barcode))",
            'order'  => "p.name",
            // Read by goods received, Manager-only — it carries cost price.
            'role'   => ROLE_MANAGER,
            'extra'  => [
                'price' => "TO_CHAR(p.cost_price, 'FM9999999990.00')",
                'cost'  => "TO_CHAR(p.cost_price, 'FM9999999990.00')",
            ],
        ],
    ];
}

/**
 * The seed a JS picker starts from, plus where to ask for more.
 *
 * Rendered as a JSON block rather than a <select> because the
 * document builder creates a picker per line, and it needs the rows
 * before it has a row to put them in.
 */
function lookup_seed_block(string $list, string $id): void
{
    $rows = array_map(
        static fn(array $r) => ['id' => $r['id'], 'name' => $r['name'], 'search' => $r['search']]
                               + ($r['data'] ?? []),
        lookup_query($list, '', LOOKUP_SEED)
    );
    ?>
    <script type="application/json" id="<?= e($id) ?>"
            data-lookup="<?= e($list) ?>"
            data-lookup-url="<?= e(url('lookup.php')) ?>"><?=
        json_encode($rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    ?></script>
    <?php
}

/**
 * Whether a named list exists and this user may read it.
 *
 * Fails closed twice over: an unknown list is refused, and so is a
 * known one whose definition forgot to say who may read it. The
 * second is the one that matters — a list added next year with no
 * `role` key must not quietly inherit "everybody".
 */
function lookup_allowed(string $list): bool
{
    $def = lookup_lists()[$list] ?? null;
    if (!$def) {
        return false;
    }
    if (!array_key_exists('role', $def)) {
        // A programming error, not a user error, so it is logged
        // loudly and refused quietly.
        error_log("[LOOKUP] list '$list' declares no role and is refused. "
                . "Give it 'role' => LOOKUP_ANYONE or a role constant.");
        return false;
    }
    if ($def['role'] === LOOKUP_ANYONE) {
        return true;
    }
    return is_admin() || user_has_role((string) $def['role']);
}

/**
 * Rows for a picker.
 *
 * `$keep` are ids that must appear whatever the search text is — the
 * option currently selected. Without that a filtered page would
 * silently lose its own filter the moment it re-rendered, which is
 * the sort of bug that gets blamed on the database.
 */
function lookup_query(string $list, string $q = '', int $limit = LOOKUP_LIMIT, array $keep = []): array
{
    $def = lookup_lists()[$list] ?? null;
    if (!$def) {
        return [];
    }

    /*  The permission check lives HERE, not only in the endpoint.
     *
     *  Every reader goes through this function — the JSON endpoint,
     *  the <select> a page seeds, the JSON block a document builder
     *  reads. Checking in the endpoint alone would leave the other
     *  two open, and it is the seed that renders into the HTML of
     *  whatever page thought to ask for it.
     *
     *  A page that asks for a list its own viewer may not read is a
     *  mistake in that page. It gets an empty picker and a line in
     *  the log, rather than data. */
    if (!lookup_allowed($list)) {
        error_log('[LOOKUP] refused ' . $list . ' for user '
                . (int) (current_user()['id'] ?? 0));
        return [];
    }

    $keep = array_values(array_unique(array_filter(array_map('intval', $keep))));
    $q    = trim($q);

    // Anything the picker needs to know about a row beyond its label
    // — the balance to offer, the party to name. Keys are checked
    // against a strict pattern because they become data-* attribute
    // names on the way out.
    $extra  = $def['extra'] ?? [];
    $select = "{$def['id']} AS id, {$def['name']} AS name, {$def['search']} AS search";
    foreach ($extra as $key => $sqlExpr) {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', (string) $key)) {
            continue;
        }
        $select .= ", $sqlExpr AS x_$key";
    }

    // Parameters the list's own SQL needs, whatever the search is.
    // A null placeholder means "today" — the date every balance is
    // taken as at. Kept separate from the search parameter below,
    // because the pinned query further down uses these and NOT that
    // one, and PDO refuses a statement handed a parameter it has no
    // placeholder for.
    $listParams = [];
    foreach (($def['params'] ?? []) as $k => $v) {
        $listParams[$k] = $v ?? date('Y-m-d');
    }
    $params = $listParams;
    $where  = [$def['where']];

    if ($q !== '') {
        // Matched against the label and the hidden search text
        // together, so a customer is found by the person who answers
        // the phone as readily as by the company on the letterhead.
        $where[] = "(LOWER({$def['name']}) LIKE :q OR LOWER({$def['search']}) LIKE :q)";
        $params[':q'] = '%' . mb_strtolower($q) . '%';
    }

    $sql = "SELECT $select
            {$def['from']}
            WHERE " . implode(' AND ', $where) . "
            ORDER BY {$def['order']}
            LIMIT " . max(1, min(200, $limit));

    // The kept ids come as their own statement rather than an OR in
    // the one above: an OR would let them compete for the LIMIT and
    // the selected customer could be pushed out by the search
    // results — losing exactly the row that had to survive.
    //
    // Note it does NOT repeat the list's own WHERE. A receipt posted
    // against an invoice that has since been paid in full must still
    // render with that invoice named, or editing it would silently
    // move the money.
    if ($keep) {
        // Named placeholders throughout, not positional: the list's
        // own SQL may already carry named ones (:d for the balance
        // date) and PDO refuses a statement that mixes the two.
        $names  = [];
        $pinArg = $listParams;
        foreach (array_values($keep) as $n => $id) {
            $names[]           = ":k$n";
            $pinArg[":k$n"]    = $id;
        }
        $pinned = db_all(
            "SELECT $select {$def['from']} WHERE {$def['id']} IN (" . implode(',', $names) . ")",
            $pinArg
        );
    } else {
        $pinned = [];
    }

    $rows = db_all($sql, $params);

    // Pinned first, then the search, with no id twice.
    $seen = [];
    $out  = [];
    foreach (array_merge($pinned, $rows) as $row) {
        $id = (int) $row['id'];
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;

        $item = ['id' => $id, 'name' => (string) $row['name'], 'search' => (string) ($row['search'] ?? '')];
        foreach ($row as $col => $val) {
            if (str_starts_with((string) $col, 'x_')) {
                $item['data'][substr((string) $col, 2)] = (string) $val;
            }
        }
        $out[] = $item;
    }
    return $out;
}

/**
 * How many rows a list holds.
 *
 * For the page that needs to say "there is nothing to record a
 * receipt against" without fetching the whole list to find out.
 * Same definition as the picker, so the two can never disagree
 * about whether the list is empty.
 */
function lookup_count(string $list): int
{
    $def = lookup_lists()[$list] ?? null;
    // Same gate as lookup_query(). A count is a small disclosure but
    // it is still one — "how many suppliers does this business have"
    // is not a question a salesperson gets to ask here.
    if (!$def || !lookup_allowed($list)) {
        return 0;
    }
    $params = [];
    foreach (($def['params'] ?? []) as $k => $v) {
        $params[$k] = $v ?? date('Y-m-d');
    }
    try {
        return (int) db_value(
            "SELECT COUNT(*) {$def['from']} WHERE {$def['where']}",
            $params
        );
    } catch (Throwable $e) {
        // A list whose table a migration has not added yet is empty,
        // not fatal — the same rule the rest of the app follows.
        error_log('[LOOKUP] count ' . $list . ': ' . $e->getMessage());
        return 0;
    }
}

/**
 * Render a picker: a search box, a bounded <select>, and the wiring
 * that tells the browser where to ask for more.
 *
 * $opts: prompt, label, required, class, id, onchange, empty.
 */
function lookup_select(string $list, string $name, $selected = null, array $opts = []): void
{
    $selected = ($selected === null || $selected === '') ? null : (int) $selected;
    $rows     = lookup_query($list, '', LOOKUP_SEED, $selected !== null ? [$selected] : []);

    $prompt   = $opts['prompt']   ?? '';
    $label    = $opts['label']    ?? 'Search';
    $required = !empty($opts['required']);
    $class    = trim('form-control ' . ($opts['class'] ?? ''));
    $empty    = $opts['empty']    ?? 'Nothing matches that.';
    $id       = $opts['id']       ?? null;

    $attrs = '';
    foreach (($opts['attrs'] ?? []) as $k => $v) {
        $attrs .= ' ' . e($k) . '="' . e((string) $v) . '"';
    }

    /*  A <span>, always.
     *
     *  Nearly every one of these sits inside a <label class="filter-field">,
     *  and a <label> may only contain phrasing content — a <div>
     *  there is invalid HTML, which browsers forgive inconsistently
     *  and mobile browsers least of all. A span with display:flex
     *  lays out identically and is valid wherever it is put. */
    ?>
    <span class="select-search">
        <?php /*  ── The select comes FIRST, and this is load-bearing ──
                 *
                 *  A <label> with no `for` labels the first labelable
                 *  element inside it. With the search box first, that
                 *  was the search box — so tapping the label put the
                 *  cursor in the search field and opened the keyboard,
                 *  and the dropdown the user was opening got dismissed
                 *  before they could choose anything. The workaround
                 *  people found was to press and hold, which is how
                 *  this was reported.
                 *
                 *  Select first, so the label labels the select and a
                 *  tap anywhere on the field opens the list. CSS puts
                 *  the search box back on top visually — see the
                 *  `order` rule on .select-search-input in style.css,
                 *  which is the other half of this fix and must not be
                 *  removed on its own. */ ?>
        <select name="<?= e($name) ?>" class="<?= e($class) ?>"
                <?= $id ? 'id="' . e($id) . '"' : '' ?>
                <?= $required ? 'required' : '' ?><?= $attrs ?>>
            <?php if ($prompt !== '' || !$required): ?>
                <option value=""><?= e($prompt !== '' ? $prompt : '— none —') ?></option>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <option value="<?= (int) $row['id'] ?>" data-search="<?= e($row['search']) ?>"
                        <?php foreach (($row['data'] ?? []) as $k => $v): ?>
                            data-<?= e($k) ?>="<?= e($v) ?>"
                        <?php endforeach; ?>
                        <?= $selected === (int) $row['id'] ? 'selected' : '' ?>>
                    <?= e($row['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php /* The endpoint travels with the box rather than through
                 a global, so the picker keeps working when the
                 application is installed in a subdirectory — the
                 same reason url() exists at all. */ ?>
        <input type="search" class="form-control select-search-input" data-search-select
               data-lookup="<?= e($list) ?>"
               data-lookup-url="<?= e(url('lookup.php')) ?>"
               placeholder="Type to search…" aria-label="<?= e($label) ?>" autocomplete="off">
        <span class="select-search-empty" data-search-empty hidden><?= e($empty) ?></span>
    </span>
    <?php
}
