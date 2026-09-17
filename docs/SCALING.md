# Growing this into more than one shop, and into a product

Two directions, and they are not the same problem.

**More than one shop** is one business with several places. One set
of books, one customer list, one catalogue — and stock, tills and
documents that belong to a location.

**Selling it as a subscription** is many businesses that must never
see each other's data. Nothing is shared.

Doing the first does not get you the second, and the second is much
easier if the first is done first — because a shop is a smaller,
safer rehearsal for the same idea.

---

## Part 1 — More than one shop

### What already supports it

More than you would expect, because the schema was drawn with it in
mind even though nothing used it:

| Already there | Where |
|---|---|
| `warehouses` — a place stock lives | migration 003 |
| `warehouse_id` on delivery notes, goods return notes | migrations 003, 017 |
| `inventory_transactions` — every movement, with a place | migration 003 |
| `movement_type = 'transfer'` in the ledger's list | migration 003 |
| Stock counts, per location | migration 024 |
| Stateless sessions, so more than one server can serve one shop | migration 022 |

**The stock ledger is the important one.** Every movement of stock
since the system was installed is a row in
`inventory_transactions` carrying a product, a quantity and a
place. That means per-location balances can be *reconstructed*
rather than guessed at when the time comes — which is the
difference between a migration and a fresh start.

### What has to change

Three things, in this order.

**1. Documents record where they were raised.** A `warehouse_id`
(or `branch_id` — same thing, different word) on invoices, quotes,
proformas, purchase orders, GRNs, expenses and cash accounts.
Nullable, backfilled to the default location, so nothing breaks on
the day it lands.

Cash accounts matter more than they look: a till belongs to a shop,
and *until it does, the cash book cannot tell you which shop is
short.*

**2. Stock is held per location.**

```sql
CREATE TABLE product_stock (
    product_id   INTEGER REFERENCES products(product_id),
    warehouse_id INTEGER REFERENCES warehouses(warehouse_id),
    quantity     NUMERIC(12,3) NOT NULL DEFAULT 0,
    PRIMARY KEY (product_id, warehouse_id)
);
```

`products.stock_quantity` becomes the **sum**, maintained by a
trigger on `product_stock`. Keeping it means the catalogue API, the
low-stock report and the delivery-note check all keep working
untouched, which is most of the risk removed. Seed it from
`inventory_transactions`, replaying the ledger per location.

Then each of the four places that move stock says which location:
`manage_grns.php`, the delivery trigger in migration 010,
`includes/returns.php`, and `list_debit_notes.php`. Each already
knows — GRNs have a receiving store, delivery notes carry
`warehouse_id` — they simply do not use it yet.

**3. People belong to a shop, and see their shop.** A
`branch_id` on `users`, a current branch in the session, and a
branch predicate on the list queries. Administrators get a switcher;
everyone else is pinned. The pattern is already there in
`require_role()` — one guard, called at the top of the page.

Then **transfers between shops** — the ledger's `transfer` type,
two rows, one out and one in, exactly as `cash_post_transfer()`
already does for money.

### What stays company-wide

Customers, suppliers, the product catalogue, users, categories,
brands, currencies, the chart of expense categories. A customer who
buys at the Westlands shop is the same customer at the Mombasa
Road shop, and their statement should say so.

---

## Part 2 — Selling it as a subscription

### Pick the isolation model first

Everything else follows from it.

| Model | Isolation | Cost |
|---|---|---|
| **One database per tenant** | The connection string is the boundary. A query cannot reach another tenant because the data is not there. | Migrations run N times. Cross-tenant reporting needs its own pipeline. A few hundred tenants per server. |
| One schema per tenant | `search_path` is the boundary. | Same migration cost; one bad `search_path` and the boundary is gone. |
| Shared tables, `tenant_id` column | A `WHERE tenant_id = ?` on every query, forever. | Cheapest to run, and the way almost every SaaS data leak has happened. |

**For this application: one database per tenant.**

Not because it is fashionable, but because of what the code
actually is. There are roughly two hundred queries here, hand
written, none carrying a tenant predicate. Retrofitting one to
every query — and to every future query, in every pull request,
forever — makes isolation a matter of code review. One forgotten
`WHERE` shows another business their books.

A database per tenant makes isolation a property of the
**connection**, which is checked once, at boot, by code that
already exists: `config.php` reads `DB_NAME` from the environment.
That is the whole change.

### What is already in place

| Needed | Status |
|---|---|
| Connection from environment, not hard-coded | `config.php` reads `.env` |
| Migration runner that is safe to re-run | `database_structure/migrate.php`, idempotent |
| Stateless application tier | migration 022 — sessions in the database |
| Health check for a load balancer | `health.php`, shallow and deep |
| Rate limiting, shared across nodes | `includes/security.php` |
| Per-request tenant resolution point | `includes/bootstrap.php`, one file |
| Backup and restore, per database | `deploy/backup.sh`, `deploy/restore.sh` |

### What has to be built

**1. A control-plane database.** Separate from every tenant's, and
the only place that knows they exist:

```
tenants          id, name, subdomain, database_name, status, created_at
plans            id, name, price, interval, limits (users, invoices/month)
subscriptions    tenant_id, plan_id, status, current_period_end, trial_ends_at
tenant_invoices  tenant_id, amount, status, paid_at, provider_reference
tenant_users     the person who signs up and pays — not an ERP user
```

**2. Tenant resolution.** By subdomain — `runai.yourerp.co.ke` —
resolved in `bootstrap.php` before the database connects:

```php
$tenant = control_plane_lookup(subdomain_of(security_safe_host()));
if (!$tenant || $tenant['status'] !== 'active') { show_suspended_page(); }
define('DB_NAME', $tenant['database_name']);
```

`security_safe_host()` already validates the Host header, which is
what stops a forged one selecting somebody else's database. That
function exists and is tested.

**3. Provisioning.** A script that creates the database, runs every
migration, seeds a currency and an administrator, and emails the
credentials. It is `migrate.php` in a loop plus `createdb` — an
afternoon, not a project.

**4. Billing.** In Kenya that means **M-Pesa via the Daraja API**
first, with a card option (Paystack or Flutterwave, both of which
settle to Kenyan accounts) for customers who prefer it. Stripe is
a poor first choice here.

The pattern the ERP already uses for supplier payments applies
exactly: a `tenant_invoices` row, a payment against it, a webhook
that confirms, and a status derived from the sum — never typed.

**5. A subscription gate.** One check in `bootstrap.php`, after
tenant resolution and before `require_login()`: past due beyond the
grace period, the tenant sees a payment page and nothing else.
**Never delete a late payer's data.** Suspend, keep backups, and
restore on payment — the business that comes back after two months
is worth more than the disk.

**6. Per-tenant housekeeping.** `security_gc()` and
`deploy/backup.sh` run per database. Both take the database from
`.env` today; both need a loop over the tenant list.

### Two traps in the current code

- **`cache_key()` namespaces by `APP_NAME` and `BASE_PATH`.** One
  codebase serving many tenants gives every tenant the same
  namespace, and one tenant's cached report would be served to
  another. It must include the tenant. This is in
  `includes/cache.php`, and it is four characters — but it is
  exactly the kind of thing that is obvious now and invisible
  later.
- **`uploads/` is one directory.** Proofs of payment and product
  photographs from every tenant would share it. Per-tenant
  sub-directories, or object storage with a per-tenant prefix.

### A sensible order

1. Multi-shop first, in one business (Part 1). It is the same
   thinking at a scale where a mistake is recoverable.
2. Control plane and tenant resolution, with **two** tenants — your
   own business and a test one. Two is where all the assumptions
   break; ten is just more of the same.
3. Provisioning and self-service sign-up.
4. Billing, and the subscription gate.
5. Per-tenant backups and monitoring, before the first paying
   customer rather than after.

### What to charge for

The limits worth metering in an ERP are users and documents, not
storage. `plans.limits` above holds both. Enforce them where they
are created — `users/create.php` and the document numbering in
`next_document_number()` — not scattered through the pages.

---

## Neither of these is urgent

The application works for one business in one shop today, and
everything above is written down so that the next change does not
have to be undone. The single most useful thing that has already
been done for both futures is the stock ledger: every movement of
stock is recorded with its place and its reason, which means the
per-location figures can be rebuilt from history rather than
started from zero.
