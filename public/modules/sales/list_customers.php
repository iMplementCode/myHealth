<?php

/**
 * ============================================================
 *  Customers — List (uniform UI)
 * ------------------------------------------------------------
 *  Paginated, searchable customer list with modal add/edit
 *  (AJAX), activate/deactivate and guarded delete. The sidebar
 *  "Customers" entry lands here.
 * ============================================================
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/export.php';
require_once __DIR__ . '/../../../includes/icons.php';

/* ── AJAX: create / update customer (modal) ───────────────── */
if (is_post() && ($_POST['_action'] ?? '') === 'save') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        json_response(['success' => false, 'message' => 'Security token expired. Reload and try again.'], 403);
    }
    $id      = input_int($_POST, 'customer_id');
    $company = input($_POST, 'company_name');
    $first   = input($_POST, 'first_name');
    $last    = input($_POST, 'last_name');
    $email   = strtolower(input($_POST, 'email'));
    $phone   = input($_POST, 'phone');
    $taxPin  = strtoupper(input($_POST, 'tax_pin'));
    // Where the work happens. For a business that drives to
    // premises and fits cameras to walls, this is the field the
    // job actually needs, and it prints on the delivery note.
    $location = input($_POST, 'location');

    if ($company === '' && $first === '') {
        json_response(['success' => false, 'message' => 'Provide a company name or a first name.']);
    }
    if ($email !== '' && !valid_email($email)) {
        json_response(['success' => false, 'message' => 'Please provide a valid email address.']);
    }
    // Uniqueness (excluding self on update).
    foreach ([['email', $email], ['phone', $phone], ['tax_pin', $taxPin]] as [$col, $val]) {
        if ($val === '') continue;
        $dupe = db_value(
            "SELECT 1 FROM customers WHERE $col = :v" . ($id !== null ? " AND customer_id <> :id" : ""),
            $id !== null ? [':v' => $val, ':id' => $id] : [':v' => $val]
        );
        if ($dupe) {
            json_response(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $col)) . ' "' . $val . '" is already used by another customer.']);
        }
    }

    $params = [
        ':company' => $company !== '' ? $company : null,
        ':first'   => $first !== '' ? $first : null,
        ':last'    => $last !== '' ? $last : null,
        ':email'   => $email !== '' ? $email : null,
        ':phone'   => $phone !== '' ? $phone : null,
        ':pin'     => $taxPin !== '' ? $taxPin : null,
        ':location'=> $location !== '' ? $location : null,
    ];
    try {
        if ($id === null) {
            db_run(
                "INSERT INTO customers (company_name, first_name, last_name, email, phone,
                        tax_pin, location, is_active)
                 VALUES (:company, :first, :last, :email, :phone, :pin, :location, TRUE)",
                $params
            );
            audit_log('customer.create', 'customers', $company ?: $first);
            json_response(['success' => true, 'message' => 'Customer added.']);
        }
        if (!db_value("SELECT 1 FROM customers WHERE customer_id = :id", [':id' => $id])) {
            json_response(['success' => false, 'message' => 'That customer no longer exists.'], 404);
        }
        $params[':id'] = $id;
        db_run(
            "UPDATE customers
             SET company_name = :company, first_name = :first, last_name = :last,
                 email = :email, phone = :phone, tax_pin = :pin,
                 location = :location, updated_at = NOW()
             WHERE customer_id = :id",
            $params
        );
        audit_log('customer.update', 'customers', $id);
        json_response(['success' => true, 'message' => 'Customer updated.']);
    } catch (PDOException $ex) {
        error_log('[CUSTOMERS] save failed: ' . $ex->getMessage());
        json_response(['success' => false, 'message' => $ex->getCode() === '23505'
            ? 'That email, phone or tax PIN is already in use.'
            : db_rule_message($ex, 'Could not save the customer.')], 500);
    }
}

/* ── POST: toggle active / delete ─────────────────────────── */
if (is_post() && in_array($_POST['_action'] ?? '', ['toggle', 'delete'], true)) {
    csrf_check();
    // A salesperson manages customers — that is the role's job, and
    // it covers adding, editing and deactivating them. Deleting one
    // outright is not managing it: the record goes for good, so that
    // much is the Manager's.
    if (($_POST['_action'] ?? '') === 'delete') {
        require_role(ROLE_MANAGER);
    }
    $id   = input_int($_POST, 'customer_id');
    $cust = $id !== null
        ? db_one("SELECT " . customer_name_sql('') . " AS name, is_active FROM customers WHERE customer_id = :id", [':id' => $id])
        : null;
    if (!$cust) {
        flash('error', 'That customer no longer exists.');
    } elseif (($_POST['_action'] ?? '') === 'toggle') {
        db_run("UPDATE customers SET is_active = :a, updated_at = NOW() WHERE customer_id = :id",
            [':a' => $cust['is_active'] ? 'f' : 't', ':id' => $id]);
        audit_log($cust['is_active'] ? 'customer.deactivate' : 'customer.activate', 'customers', $id, ['name' => $cust['name']]);
        flash('success', $cust['name'] . ($cust['is_active'] ? ' deactivated.' : ' activated.'));
    } else {
        try {
            db_run("DELETE FROM customers WHERE customer_id = :id", [':id' => $id]);
            audit_log('customer.delete', 'customers', $id, ['name' => $cust['name']]);
            flash('success', 'Customer "' . $cust['name'] . '" deleted.');
        } catch (PDOException $ex) {
            flash('error', $ex->getCode() === '23503'
                ? 'This customer has quotes, orders or invoices and cannot be deleted. Deactivate them instead.'
                : 'Could not delete the customer.');
        }
    }
    redirect('modules/sales/list_customers.php');
}

/* ── Filters + data ───────────────────────────────────────── */
$search  = input($_GET, 'q');
$filter  = input($_GET, 'filter');
$perPage = per_page();

$where  = [];
$params = [];
if ($search !== '') {
    $where[] = "(LOWER(COALESCE(c.company_name,'')) LIKE :q
                 OR LOWER(TRIM(CONCAT(c.first_name,' ',c.last_name))) LIKE :q
                 OR LOWER(COALESCE(c.email,'')) LIKE :q OR COALESCE(c.phone,'') LIKE :q
                 OR LOWER(COALESCE(c.location,'')) LIKE :q)";
    $params[':q'] = '%' . strtolower($search) . '%';
}
if ($filter === 'active')   $where[] = "c.is_active = TRUE";
elseif ($filter === 'inactive') $where[] = "c.is_active = FALSE";
else $filter = '';
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

if (wants_export()) {
    export_deliver(export_filename('customers'), [[
        'name'  => 'Customers',
        'title' => 'Customers',
        'meta'  => export_meta(['Search' => $search]),
        'columns' => [
            ['label' => 'Customer', 'key' => 'display_name',
             'value' => fn($r) => customer_display_name($r)],
            ['label' => 'Contact',  'key' => 'contact_name',
             'value' => fn($r) => customer_contact_name($r)],
            ['label' => 'Email',   'key' => 'email'],
            ['label' => 'Phone',    'key' => 'phone'],
            ['label' => 'Location', 'key' => 'location'],
            ['label' => 'Tax PIN',  'key' => 'tax_pin'],
            ['label' => 'Status',  'key' => 'is_active', 'value' => fn($r) => $r['is_active'] ? 'Active' : 'Inactive'],
        ],
        'rows' => db_all("SELECT c.* FROM customers c $whereSql ORDER BY c.customer_id DESC", $params),
    ]]);
}

$total = (int) db_value("SELECT COUNT(*) FROM customers c $whereSql", $params);
$pg    = paginate($total, $perPage);

$customers = db_all(
    "SELECT c.*,
            (SELECT COUNT(*) FROM quotes q WHERE q.customer_id = c.customer_id) AS quote_count,
            (SELECT COUNT(*) FROM sales_orders so WHERE so.customer_id = c.customer_id) AS order_count
     FROM customers c $whereSql
     ORDER BY c.created_at DESC
     LIMIT {$pg['per_page']} OFFSET {$pg['offset']}",
    $params
);

// Adding, editing and deactivating a customer is the salesperson's
// job. Deleting one takes the record away for good, so the button
// only appears for the role that is allowed to press it.
$canDelete = is_admin() || user_has_role(ROLE_MANAGER);

$pageTitle    = 'Customers';
$pageSubtitle = num($total) . ' customer' . ($total === 1 ? '' : 's');
$pageStyles   = ['forms.css'];
$breadcrumbs  = [['label' => 'Sales'], ['label' => 'Customers']];

require __DIR__ . '/../../../includes/header.php';
$baseQuery = array_filter(['q' => $search, 'filter' => $filter], fn($v) => $v !== '');
?>

<div class="toolbar">
    <form class="toolbar-search" method="GET" action="<?= e(url('modules/sales/list_customers.php')) ?>">
        <?= icon('search', 'toolbar-search-ic') ?>
        <input type="search" name="q" value="<?= e($search) ?>" class="form-control" placeholder="Search name, company, email, phone or location…">
        <select name="filter" class="form-control toolbar-select" data-autosubmit>
            <option value="">All customers</option>
            <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    
        <?= toolbar_apply('modules/sales/list_customers.php') ?>
    </form>
    <div class="toolbar-actions">
        <?= export_button('modules/sales/list_customers.php', $baseQuery) ?>
    <button type="button" class="btn btn-primary" data-modal-open="tplCustomerForm" data-modal-title="Add Customer" data-modal-auto>

        <?= icon('plus') ?> Add Customer
    </button>
    </div>
</div>

<div class="panel">
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Contact</th>
                    <th>Location</th>
                    <th>Phone</th>
                    <th>Tax PIN</th>
                    <th class="ta-right">Quotes</th>
                    <th class="ta-right">Orders</th>
                    <th>Status</th>
                    <th class="ta-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$customers): ?>
                    <tr><td colspan="9" class="table-empty">No customers match these filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($customers as $c):
                    $name = customer_display_name($c);
                    $prefill = json_encode([
                        'customer_id'  => $c['customer_id'],
                        'company_name' => $c['company_name'],
                        'first_name'   => $c['first_name'],
                        'last_name'    => $c['last_name'],
                        'email'        => $c['email'],
                        'phone'        => $c['phone'],
                        'tax_pin'      => $c['tax_pin'],
                        'location'     => $c['location'] ?? '',
                    ], JSON_HEX_APOS | JSON_HEX_QUOT);
                ?>
                    <tr>
                        <td>
                            <div class="cell-user">
                                <span class="avatar avatar--sm"><?= e(initials($name)) ?></span>
                                <div>
                                    <span class="cell-title"><?= e($name) ?></span>
                                    <span class="cell-sub"><?= e($c['email'] ?? '—') ?></span>
                                </div>
                            </div>
                        </td>
                        <td class="cell-muted"><?= e(customer_contact_name($c) ?: '—') ?></td>
                        <td class="cell-muted"><?= e($c['location'] ?? '') !== '' ? e($c['location']) : '—' ?></td>
                        <td class="cell-muted"><?= e($c['phone'] ?? '—') ?></td>
                        <td class="cell-muted"><?= e($c['tax_pin'] ?? '—') ?></td>
                        <td class="ta-right"><?= e(num($c['quote_count'])) ?></td>
                        <td class="ta-right"><?= e(num($c['order_count'])) ?></td>
                        <td>
                            <span class="badge <?= $c['is_active'] ? 'badge--active' : 'badge--inactive' ?>">
                                <?= $c['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td class="ta-right">
                            <div class="row-actions">
                                <button type="button" class="icon-btn" title="Edit"
                                        data-modal-open="tplCustomerForm" data-modal-title="Edit Customer"
                                        data-prefill="<?= e($prefill) ?>"><?= icon('edit') ?></button>
                                <form method="POST" action="<?= e(url('modules/sales/list_customers.php')) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="toggle">
                                    <input type="hidden" name="customer_id" value="<?= (int) $c['customer_id'] ?>">
                                    <button type="submit" class="icon-btn" title="<?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <?= icon($c['is_active'] ? 'x' : 'check') ?>
                                    </button>
                                </form>
                                <?php if ($canDelete): ?>
                                <form method="POST" action="<?= e(url('modules/sales/list_customers.php')) ?>" class="inline-form"
                                      data-confirm="Delete <?= e($name) ?>? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="customer_id" value="<?= (int) $c['customer_id'] ?>">
                                    <button type="submit" class="icon-btn icon-btn--danger" title="Delete"><?= icon('trash') ?></button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php pagination_nav($pg, $baseQuery, 'customer'); ?>
</div>

<!-- Add / edit customer modal -->
<template id="tplCustomerForm">
    <form method="POST" action="<?= e(url('modules/sales/list_customers.php')) ?>" data-ajax>
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save">
        <input type="hidden" name="customer_id" value="">

        <div class="form-group">
            <label class="form-label">Company name <span class="opt">(company or person required)</span></label>
            <input type="text" name="company_name" class="form-control" maxlength="255">
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">First name</label>
                <input type="text" name="first_name" class="form-control" maxlength="100">
            </div>
            <div class="form-group">
                <label class="form-label">Last name</label>
                <input type="text" name="last_name" class="form-control" maxlength="100">
            </div>
        </div>
        <div class="form-grid-2">
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" maxlength="255">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" maxlength="20">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Tax PIN</label>
            <input type="text" name="tax_pin" class="form-control" maxlength="50">
        </div>

        <div class="form-group">
            <label class="form-label">Location</label>
            <textarea name="location" class="form-control" rows="2"
                      placeholder="Road, building, floor — where the site is"></textarea>
        </div>
        <div class="form-actions">
            <button type="button" class="btn btn-ghost" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary">Save customer</button>
        </div>
    </form>
</template>

<?php require __DIR__ . '/../../../includes/footer.php'; ?>
