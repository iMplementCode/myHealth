# Phase 2 notes

What the Phase 2 brief asked for, what was delivered in this increment, and
what remains as incremental follow-up. Everything below was verified at
runtime against a live PostgreSQL (login → workflows → RBAC → audit).

## Delivered

### Expense Management module (`expenses/`)
- List with **server-side pagination**, text search, category / status / date
  filters, and summary cards (today, this month, filtered total, count).
- **Add/Edit in a reusable modal** with client + server validation, submitted
  via **AJAX** (JSON responses, toast notifications, auto-refresh).
- Delete (admin-only) with confirmation dialog; all writes audited.
- Categories seeded by migration: Rent, Salaries, Fuel, Utilities, Transport,
  Maintenance, Internet, Office Supplies, Marketing, Miscellaneous.
- Access: Administrators and Managers (`require_role`).

### Proforma → Invoice workflow (`proformas/`, `invoices/`)
- **Quote → Proforma** in one click (modal picker of eligible quotes): copies
  customer, items, quantities, pricing, discounts, taxes, notes and terms in a
  transaction; the source quote is marked `converted`.
- **Proforma → Invoice** in one click: preserves every commercial field,
  generates a sequential `INV-YYYY-NNNN` number, updates the proforma's
  status, and records the conversion **date**, **user** and invoice reference.
- **Duplicate conversions prevented three ways**: row lock + status check in
  the transaction, a unique index on `invoices(pi_id)`, and one on
  `proforma_invoices(quote_id)`.
- Invoice list (paginated, searchable, status-filtered) and a **printable
  invoice document** (company header, bill-to, items, totals, terms) using the
  print stylesheet.

### Dashboard v2 (`dashboard/`)
- New widgets: Weekly Sales, Gross Profit, **Net Profit (gross − expenses)**,
  Pending Quotes, Converted Quotes, Suppliers, Today's/Monthly/Total Expenses.
- **Every card is clickable** and opens its filtered view — e.g. Low Stock →
  `view_products.php?filter=low`, Monthly Expenses → the expense list
  pre-filtered to this month. The dashboard is a navigation hub.
- The monthly chart now plots **Sales vs Expenses**; a Recent Expenses feed
  appears for managers/admins.
- All values are computed live from PostgreSQL — nothing hardcoded.

### Uniform UI — products list rebuilt (`modules/inventory/view_products.php`)
The flagship legacy rebuild, establishing the pattern for the rest:
- Shared layout (sidebar/topbar/breadcrumbs), brand palette, standard tables.
- Server-side pagination, search (name/SKU/barcode), category filter, stock
  filters (`low`/`out`/`active`/`inactive`), sorting.
- **Edit in a modal via AJAX**; CSRF-protected delete with confirmation;
  CSV export preserved and now filter-aware.

### Shared infrastructure
- **Reusable modal component** (`App.openModal`, `data-modal-open` +
  `<template>`, `data-prefill`) and **AJAX form handler** (`data-ajax`) in
  `assets/js/app.js` — no inline JS.
- **Audit logging** (`includes/audit.php` + `audit_logs` table): logins,
  logouts, user admin actions, expense CRUD, product edit/delete, proforma
  creation and conversion.
- **HTTP security headers** on every page (nosniff, SAMEORIGIN,
  Referrer-Policy, Permissions-Policy).
- Migration `006` (additive, idempotent, shape-defensive like 003/005):
  expense tables + seeds, proforma/invoice tables, conversion tracking,
  audit_logs, and nullable `created_by`/`updated_by` audit columns on
  products/customers/suppliers.

## Remaining follow-ups (incremental, pattern established)
1. **Rebuild the remaining legacy pages** into the shared layout the same way
   `view_products.php` was done (suppliers, categories, UoM, quotes, users
   legacy pages, settings). Each is mechanical: port the queries, reuse the
   toolbar/table/pagination/modal patterns.
2. **Modal add/edit for suppliers/customers/users legacy forms** — the modal +
   AJAX components are ready; each form needs porting.
3. **CSRF on remaining legacy POST handlers** (unchanged Phase 1 follow-up).
4. Stock adjustments and inventory-history views (brief §3/§5 mentions) —
   needs a stock_movements table; recommend designing it together with the
   e-commerce sync so movements have a single ledger.
