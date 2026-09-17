<?php

/**
 * ============================================================
 *  Navigation Map
 * ------------------------------------------------------------
 *  Single source of truth for the sidebar / menu structure.
 *  Each item may declare `roles` — the list of roles allowed
 *  to see it. Omitting `roles` means "any authenticated user".
 *  Administrators always see everything.
 *
 *  This data-driven approach means adding a future module is a
 *  one-line change here rather than editing markup in N files.
 * ============================================================
 */

declare(strict_types=1);

// Not a page: refuse to run when requested directly over HTTP.
// CLI tools (migrations, cron) legitimately include this file, so the
// guard only applies to web requests.
if (PHP_SAPI !== 'cli' && !defined('APP_BOOTSTRAPPED')) {
    http_response_code(404);
    exit;
}


/** Return the navigation tree, filtered to the current user's roles. */
function nav_items(): array
{
    $all = [
        [
            'label' => 'Dashboard',
            'icon'  => 'grid',
            'href'  => 'dashboard/index.php',
        ],
        //  Second, and never buried in a submenu. It is the screen
        //  this shop opens in the morning and closes at night.
        [
            'label' => 'Counter',
            'icon'  => 'cart',
            'href'  => 'counter/index.php',
        ],
        [
            'label' => 'Patients',
            'icon'  => 'users',
            'href'  => 'patients/index.php',
        ],
        [
            'label' => 'Notifications',
            'icon'  => 'bell',
            'href'  => 'notifications/index.php',
        ],
        [
            'label' => 'Notes',
            'icon'  => 'edit',
            'href'  => 'notes/index.php',
        ],
        [
            'label' => 'Inventory',
            'icon'  => 'box',
            'children' => [
                ['label' => 'Products', 'icon' => 'box',     'href' => 'modules/inventory/view_products.php'],
                ['label' => 'Add Product', 'icon' => 'plus',  'href' => 'modules/inventory/view_products.php?new=1'],
                //  Where expiry actually gets dealt with. The batch
                //  warnings the bell raises have always linked here.
                ['label' => 'Batches & Expiry', 'icon' => 'clock', 'href' => 'modules/inventory/batches.php'],
                ['label' => 'Stock Take', 'icon' => 'clipboard',   'href' => 'modules/inventory/stock_take.php',
                 'roles' => [ROLE_ADMIN, ROLE_MANAGER]],
                ['label' => 'Categories', 'icon' => 'layers',   'href' => 'modules/inventory/view_categories.php'],
                ['label' => 'Brands', 'icon' => 'tag',       'href' => 'modules/inventory/view_brands.php'],
                ['label' => 'Units (UoM)', 'icon' => 'ruler',  'href' => 'modules/inventory/view_uom.php'],
            ],
        ],
        [
            'label' => 'Sales',
            'icon'  => 'cart',
            'children' => [
                ['label' => 'Customers', 'icon' => 'users',    'href' => 'modules/sales/list_customers.php'],
                ['label' => 'Quotes', 'icon' => 'file',       'href' => 'modules/sales/manage_quotes.php'],
                ['label' => 'Sales Orders', 'icon' => 'cart', 'href' => 'modules/sales/list_sales_orders.php'],
                ['label' => 'Proformas', 'icon' => 'report',    'href' => 'proformas/index.php'],
                ['label' => 'Invoices', 'icon' => 'invoice',     'href' => 'invoices/index.php'],
                ['label' => 'Delivery Notes', 'icon' => 'truck', 'href' => 'delivery_notes/index.php'],
                // Both of these pages are require_role(ROLE_MANAGER)
                // and neither said so here, so they were offered to a
                // salesperson and answered 403 when clicked.
                ['label' => 'Credit Notes', 'icon' => 'undo',   'href' => 'credit_notes/index.php',
                 'roles' => [ROLE_ADMIN, ROLE_MANAGER]],
                ['label' => 'Goods Returns', 'icon' => 'in',  'href' => 'returns/index.php',
                 'roles' => [ROLE_ADMIN, ROLE_MANAGER]],
            ],
        ],
        [
            'label' => 'Finance',
            'icon'  => 'money',
            'roles' => [ROLE_ADMIN, ROLE_MANAGER],
            'children' => [
                ['label' => 'Daily Position', 'icon' => 'calendar',     'href' => 'finance/position.php'],
                ['label' => 'Cash Book', 'icon' => 'book',          'href' => 'finance/cashbook.php'],
                ['label' => 'Cash Count', 'icon' => 'calc',         'href' => 'finance/cash_count.php'],
                ['label' => 'Cash & Bank Accounts', 'icon' => 'wallet', 'href' => 'finance/accounts.php'],
                ['label' => 'Invoice Payments', 'icon' => 'money',   'href' => 'invoices/payments.php'],
                ['label' => 'Customer Advances', 'icon' => 'in',  'href' => 'finance/advances.php'],
                ['label' => 'Staff Loans', 'icon' => 'user',        'href' => 'finance/loans.php'],
                ['label' => 'Investor Capital', 'icon' => 'trend',   'href' => 'finance/investors.php'],
                ['label' => 'Fixed Assets', 'icon' => 'building',   'href' => 'finance/assets.php'],
                ['label' => 'Expenses', 'icon' => 'out',           'href' => 'expenses/index.php'],
            ],
        ],
        [
            'label' => 'Reports',
            'icon'  => 'trend',
            'roles' => [ROLE_ADMIN, ROLE_MANAGER],
            'children' => [
                ['label' => 'All Reports', 'icon' => 'grid',       'href' => 'reports/index.php'],
                ['label' => 'Profit and Loss', 'icon' => 'trend',   'href' => 'reports/profit_loss.php'],
                ['label' => 'Aged Receivables', 'icon' => 'clock',  'href' => 'reports/receivables.php'],
                ['label' => 'Customer Statement', 'icon' => 'file', 'href' => 'reports/statement.php'],
                ['label' => 'Aged Payables', 'icon' => 'clock',     'href' => 'reports/payables.php'],
                ['label' => 'Supplier Statement', 'icon' => 'file', 'href' => 'reports/supplier_statement.php'],
                ['label' => 'Sales Analysis', 'icon' => 'chart',    'href' => 'reports/sales.php'],
                ['label' => 'Stock and Reorder', 'icon' => 'box', 'href' => 'reports/stock.php'],
                ['label' => 'Stock Value', 'icon' => 'wallet',      'href' => 'reports/stock_value.php'],
                ['label' => 'VAT Summary', 'icon' => 'percent',       'href' => 'reports/vat.php'],
            ],
        ],
        [
            'label' => 'Purchasing',
            'icon'  => 'truck',
            'roles' => [ROLE_ADMIN, ROLE_MANAGER],
            'children' => [
                ['label' => 'Suppliers', 'icon' => 'building',        'href' => 'modules/purchasing/view_suppliers.php'],
                ['label' => 'Purchase Orders', 'icon' => 'cart',  'href' => 'modules/purchasing/manage_pos.php'],
                ['label' => 'Goods Received', 'icon' => 'in',   'href' => 'modules/purchasing/manage_grns.php'],
                ['label' => 'Reconcile Receipts', 'icon' => 'compare', 'href' => 'modules/purchasing/reconcile_grns.php',
                 'roles' => [ROLE_ADMIN]],
                ['label' => 'Supplier Payments', 'icon' => 'money','href' => 'modules/purchasing/payments.php'],
                ['label' => 'Debit Notes', 'icon' => 'undo',      'href' => 'modules/purchasing/list_debit_notes.php'],
            ],
        ],
        [
            'label' => 'Users',
            'icon'  => 'users',
            'roles' => [ROLE_ADMIN],
            'children' => [
                ['label' => 'Manage Users', 'icon' => 'users', 'href' => 'users/index.php'],
                ['label' => 'Add User', 'icon' => 'user-plus',     'href' => 'users/index.php?new=1'],
                ['label' => 'Permissions', 'icon' => 'key',  'href' => 'modules/users/view_permissions.php'],
                ['label' => 'Audit Trail', 'icon' => 'book', 'href' => 'users/audit.php'],
            ],
        ],
        [
            'label' => 'Settings',
            'icon'  => 'cog',
            'roles' => [ROLE_ADMIN, ROLE_MANAGER],
            'children' => [
                ['label' => 'Company Details', 'icon' => 'building', 'href' => 'modules/settings/company_detail.php'],
                ['label' => 'Currencies', 'icon' => 'money',      'href' => 'modules/settings/manage_currency.php'],
                /*  Administrators only, and last in the list on purpose.
                 *  It deletes the entire shop, so it does not sit next to
                 *  the settings somebody edits on a Tuesday afternoon. */
                ['label' => 'Start Again', 'icon' => 'trash', 'href' => 'modules/settings/reset_shop.php',
                 'roles' => [ROLE_ADMIN]],
            ],
        ],
    ];

    /*  Filter the parents AND their children.
     *
     *  Only the parents were filtered, and children have carried
     *  their own `roles` since this file was written — so those
     *  declarations did nothing at all. A salesperson was shown
     *  Stock Take, Serial Register, Service Contracts, Credit Notes
     *  and Goods Returns, and every one of them answered 403 when
     *  clicked. The menu said yes and the page said no.
     *
     *  A parent left with no visible children is dropped too: an
     *  expandable group that opens onto nothing is worse than an
     *  absent one, because it looks like something is broken. */
    $visible = [];
    foreach (array_filter($all, 'nav_can_see') as $item) {
        if (isset($item['children'])) {
            $item['children'] = array_values(array_filter($item['children'], 'nav_can_see'));
            if (!$item['children'] && empty($item['href'])) {
                continue;
            }
        }
        $visible[] = $item;
    }
    return $visible;
}

/** True when the current user may see a nav item (by role). */
function nav_can_see(array $item): bool
{
    if (empty($item['roles']) || is_admin()) {
        return true;
    }
    return user_has_role(...$item['roles']);
}

/** Detect whether a nav href matches the current request path. */
function nav_is_active(string $href): bool
{
    return $href !== '' && $href === nav_active_href();
}

/**
 * Which single nav item is the page you are on.
 *
 * It compares whole paths. It used to compare basenames, and ten
 * items in this menu end in "index.php" — dashboard, notifications,
 * proformas, invoices, delivery notes, credit notes, returns,
 * expenses, reports, users. Opening any one of them lit up all ten,
 * so "the highlighted link" meant nothing and you could not tell
 * where you were from the sidebar.
 *
 * Two items can still share a path and differ only by query string
 * (Products and Add Product are both view_products.php). So the
 * winner is the most specific match — the one that agrees with the
 * request on the most parameters — and the answer is computed once
 * for the whole menu rather than per item. That is what guarantees
 * exactly one link is ever highlighted, which is the entire point
 * of highlighting one.
 */
function nav_active_href(): ?string
{
    static $answer = false;          // false = not worked out yet
    if ($answer !== false) {
        return $answer;
    }

    $reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $reqPath = rtrim(urldecode($reqPath), '/');
    parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY), $reqQuery);

    $best      = null;
    $bestScore = -1;

    foreach (nav_all_hrefs() as $href) {
        $path = rtrim((string) parse_url(url($href), PHP_URL_PATH), '/');
        if ($path !== $reqPath) {
            continue;
        }

        // Every parameter the menu item names must match the request,
        // or it is not this item — "Add Product" is view_products.php
        // with new=1, and the plain products list is not it.
        parse_str((string) parse_url($href, PHP_URL_QUERY), $navQuery);
        $matched = 0;
        foreach ($navQuery as $k => $v) {
            if (!array_key_exists($k, $reqQuery) || (string) $reqQuery[$k] !== (string) $v) {
                continue 2;
            }
            $matched++;
        }

        if ($matched > $bestScore) {
            $bestScore = $matched;
            $best      = $href;
        }
    }

    return $answer = $best;
}

/** Every href in the menu, parents and children alike. */
function nav_all_hrefs(): array
{
    static $flat = null;
    if ($flat !== null) {
        return $flat;
    }
    $flat = [];
    foreach (nav_items() as $item) {
        if (!empty($item['href'])) {
            $flat[] = $item['href'];
        }
        foreach ($item['children'] ?? [] as $child) {
            if (!empty($child['href'])) {
                $flat[] = $child['href'];
            }
        }
    }
    return $flat;
}

/** True when any child of a parent item is the active page. */
function nav_group_active(array $item): bool
{
    foreach ($item['children'] ?? [] as $child) {
        if (nav_is_active($child['href'])) {
            return true;
        }
    }
    return false;
}

/**
 * The same navigation, flattened into a list of destinations.
 *
 * One entry per page the signed-in user is allowed to reach, each
 * with the label it carries in the sidebar and the group it sits
 * under, so a quick-jump box can show "Sales · Quotes" and let
 * either word find it.
 *
 * Built from nav_items(), which is already filtered to their roles.
 * That is the point: the list cannot name a page that would answer
 * 403, and a module added to the sidebar appears here the same day
 * without anybody remembering to add it twice.
 *
 * These are links, not permissions. Every page still checks for
 * itself — a URL is not an authorisation, and this file must never
 * become the place that decides.
 */
function nav_flat(): array
{
    $out = [];
    foreach (nav_items() as $item) {
        if (!empty($item['href'])) {
            $out[] = [
                'label' => $item['label'],
                'group' => '',
                'href'  => url($item['href']),
            ];
        }
        foreach ($item['children'] ?? [] as $child) {
            if (empty($child['href'])) {
                continue;
            }
            $out[] = [
                'label' => $child['label'],
                'group' => $item['label'],
                'href'  => url($child['href']),
            ];
        }
    }
    return $out;
}
