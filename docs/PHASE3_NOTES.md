# Phase 3 — Sales, Document Workflow & Inventory Management

Implements the ERP document flow, delivery notes, partial deliveries
and inventory movement. Everything below is enforced at the database
level as well as in the UI, so no code path — the app, the API, the
future customer portal, or manual SQL — can bypass the rules.

## 1. The document flow

```
Quote
 ├── Accepted Quote
 │    ├── Sales Order ──── Invoice
 │    │              └──── Delivery Note(s)
 │    ├── Proforma Invoice ── Invoice
 │    └── Delivery Note(s)
```

**A sale is recognised only on an Invoice.** Quotes, sales orders and
proformas are commitments, not revenue. Every sales figure — turnover,
revenue, gross profit, today/week/month, the 12-month chart, top
products and the Latest Sales feed — derives from a single definition,
`sales_cte()` in `includes/reporting.php`:

```sql
SELECT issue_date, total_amount, customer_id FROM invoices
 WHERE status NOT IN ('cancelled','draft')
```

Because every figure reads that one CTE, the dashboard cards can never
disagree with each other or with the invoice list.

## 2. Sales Order → Invoice

`modules/sales/convert_to_invoice.php` — one action, one transaction.
Preserves customer, product lines, quantities, pricing, taxes,
discounts, payment terms and notes; records the originating order, the
converting user and the timestamp.

Duplicate conversions are prevented three ways:

1. row lock + status re-check inside the transaction
2. unique index `invoices(sales_order_id)`
3. `converted_invoice_id` back-reference on the order

Delivery notes already raised against the order are re-pointed at the
new invoice, and notes raised *later* are linked automatically by
trigger — so the Invoice ↔ Delivery Note relationship always holds.

## 3. Delivery notes and partial deliveries

A delivery note may only be raised from an **accepted quote** or a
**confirmed sales order**. Draft, sent, rejected and expired quotes are
refused by `trg_dn_validate_source()`.

Quantities default to the outstanding balance and are capped at it, so
partial delivery is the natural default. The brief's example works
exactly as specified:

| Step | Delivered | Running total | Progress |
|------|-----------|---------------|----------|
| DN 1 | 2 | 2 / 24 | Partially Delivered |
| DN 2 | 10 | 12 / 24 | Partially Delivered |
| DN 3 | 12 | 24 / 24 | Fully Delivered |

Over-delivery is refused (`trg_dn_check_over_delivery`), as is any
quantity that would drive stock negative.

## 4. Inventory movement

**Stock moves only when a delivery note is posted.** Creating a quote,
sales order, proforma or invoice never touches stock.

Posting writes one `inventory_transactions` row per line: product,
warehouse, quantity (negative = out), movement type, reference document,
running balance, user and timestamp. Cancelling a posted note reverses
every movement symmetrically as `delivery_reversal`, restores stock and
recomputes delivery progress.

`warehouses` exists with a seeded "Main Store" so multi-location stock
can be added later without reshaping movement records.

## 5. Document statuses

| Document | Statuses |
|---|---|
| Quote | draft, sent, viewed, accepted, rejected, expired, converted |
| Sales Order | draft, confirmed, partially_delivered, fully_delivered, invoiced, closed, cancelled |
| Proforma | draft, issued, cancelled, converted |
| Invoice | draft, issued, partially_delivered, fully_delivered, paid, partially_paid, overdue, cancelled |
| Delivery Note | draft, posted, cancelled |

Valid transitions live in `workflow_transitions()` in
`includes/workflow.php`; `can_transition()` gates every status change.
Dropdowns are built from `allowed_transitions()`, so users are only
offered moves that will actually succeed.

Delivery statuses are **derived**, never set by hand — they are
recomputed from the posted delivery notes by trigger.

### Legacy status mapping

Migration 009 maps existing rows onto the new vocabulary in place:

| Old | New |
|---|---|
| sales order `pending` / `processing` | `confirmed` |
| sales order `shipped` | `partially_delivered` |
| sales order `delivered` | `fully_delivered` |
| sales order `completed` | `closed` |
| invoice `unpaid` | `issued` |
| invoice `partial` | `partially_paid` |
| proforma `sent` / `accepted` | `issued` |

## 6. One product per document

A unique index on `(document_id, product_id)` covers quote, sales
order, invoice, proforma and delivery-note lines. Existing duplicates
are **folded** before the index is built — the surviving line takes the
group total, so no quantity is lost on upgrade.

## 7. Traceability

`document_links` is a generic edge table (`from_type`, `from_id`,
`to_type`, `to_id`, `relation`), so new document types need no schema
change. `related_documents()` resolves the graph in both directions
into numbers, statuses and URLs; the invoice and delivery-note views
render it as a clickable trail.

## 8. Dashboard (operational visibility)

Six widgets, each linking to the list that explains it: Outstanding
Deliveries, Invoices Not Delivered, Partially Delivered, Fully
Delivered, Delivered Today, Unposted Delivery Notes. A Recent
Deliveries feed sits alongside.

## 9. Triggers (migration 010)

| Trigger | Enforces |
|---|---|
| `trg_dn_validate_source` | delivery source must be deliverable; auto-links the invoice |
| `trg_dn_guard_posted` | a posted note cannot be re-pointed; a cancelled note cannot reopen |
| `trg_dn_items_guard_posted` | lines are frozen once posted |
| `trg_dn_check_over_delivery` | delivered ≤ ordered |
| `trg_dn_apply_stock` | posting moves stock and writes the ledger; cancelling reverses it |
| `trg_refresh_delivery_progress` | recomputes delivered quantities and document statuses |
| `trg_products_no_negative_stock` | stock floor on every write path |
| `trg_touch_updated_at` | maintains `updated_at` |

All functions are `CREATE OR REPLACE` and triggers are dropped-then-created,
so migration 010 is safely re-runnable.

## 10. Transactions

Every conversion and every inventory update runs inside a single
transaction. If any part fails the whole thing rolls back — a failed
post leaves stock, progress and the ledger untouched.

## 11. Validation on both sides

`delivery_notes/create.php` validates the outstanding balance, stock on
hand and product membership server-side before writing; the triggers
then enforce the same rules again at commit. Trigger messages are
written for humans and surfaced to the user, so "Over-delivery of 4MP
Dome Camera: ordered 24, already delivered 24, this note adds 1"
reaches the screen rather than a generic failure.

## 12. Refinements (migration 011)

**Delivery progress follows the whole chain.** Posting a delivery note
against a quote now updates that quote, every proforma raised from it,
and every invoice raised from those — not just the sales-order branch.
Proformas carry their own `delivery_status` for this. So a customer
document shows what has actually shipped no matter which route was
used to bill it.

**An invoiced delivery note is final.** Cancelling a posted note
reverses stock, which would silently contradict an invoice the
customer has already been billed. Once a live invoice covers the goods
— attached to the note directly, or reached through the sales order or
the quote → proforma chain — cancellation is refused by
`trg_dn_guard_posted()` and the button disappears from the UI.
Reversing invoiced goods belongs on a **credit note**, which is a
later phase. A delivery note that has not been invoiced can still be
cancelled normally, and stock returns.

**Administrator status override.** The everyday lifecycle makes some
statuses terminal — a paid invoice should not casually go back to
unpaid. Administrators may override this
(`admin_override_transitions()`), because payments do get allocated to
the wrong invoice. Reverting away from Paid also resets `amount_paid`,
so the balance due is not left reading zero. Overrides are audited as
`invoice.status_override`, distinct from routine changes, and
delivery-derived statuses are never offered because they would only be
recomputed away.

**Goods receipts and the ledger.** Confirming a GRN increases stock and
writes a `receipt` movement; cancelling one reverses the stock and
writes the matching `adjustment`, so a receipt and its reversal both
appear in the trail rather than the stock silently changing.

## 13. Delivery-note linking, SKUs and profit (migration 012)

**Every delivery note finds its invoice.** Linking previously only
followed the sales-order route, so notes raised from a quote showed no
invoice at all. `trg_link_delivery_notes_to_invoice()` now attaches
notes whichever way the invoice reaches them — sales order, quote, or
the quote behind a proforma — and it fires both when the invoice
appears and when a note is created afterwards. Existing rows are
backfilled.

**Delivery notes carry their own fulfilment state.**
`delivery_notes.delivery_status` says what the source document looked
like once that note was posted, so the list shows at a glance whether
a shipment completed the order or only part of it. It is *derived*
alongside the source document's progress, never typed in — a manual
flag would immediately contradict the quantities on the note. The list
filters on it.

**Auto-generated SKUs.** `generate_sku()` builds a readable code from
one category token plus two from the product name, with a sequence
that guarantees uniqueness — `CCTV-4MP-DOME-0001`. The product form
has an "Auto" button, and a SKU left blank on save is generated
server-side, so no product is ever left unidentifiable.

**Profit while you work.** The product form shows profit per unit in
value and percent, against the discount price when one is set, turning
red on a loss. The quote, sales-order and proforma builders show
margin per line and for the whole document, measured against the
pre-tax amount the customer pays — tax is collected, not earned.
Nothing is stored; margin is always computed from current cost.

## 14. Marking fulfilment by hand (migration 013)

The computed fulfilment state follows the posted quantities, which is
right most of the time but cannot know about the real world: a
customer who accepts a short shipment as complete, a driver who brings
part of a load back, goods signed for that never arrived.

**Delivery Notes → open a note → Fulfilment panel.** Managers and
administrators get a "Mark as" dropdown with Not / Partially / Fully
Delivered plus an optional reason. The panel shows the computed value
and the effective one side by side, so an override is always visible
as an override rather than quietly replacing the truth. Choosing
"Automatic" clears the mark and hands control back to the quantities.

`delivery_status` keeps tracking the quantities underneath;
`delivery_status_override` holds the mark. Everything the user sees —
the list column, the fulfilment filter, the document — reads
`COALESCE(override, computed)`. Marks are audited as
`delivery_note.fulfilment` with both values recorded, and the list
tags a marked row so it can be told apart at a glance.

## 15. Delivering from an invoice or proforma (migration 014)

A delivery note could only hang off a quote or a sales order, so an
invoice or proforma raised without one had no route to delivery at
all. Both are now sources in their own right, and every document that
can be delivered carries a **Create delivery note** button:

| Document | Where |
|---|---|
| Sales order | truck icon on the sales-order list |
| Proforma | list row action, and the proforma view toolbar |
| Invoice | list row action, and the Deliveries panel on the invoice |

**The request is resolved to the origin.** An invoice raised from a
sales order, and a proforma raised from a quote, are billing views of
goods already ordered upstream — delivering against both ends would
count one deal twice. `resolve_delivery_source()` walks up: invoice →
sales order → quote → proforma, and only when nothing sits above it
does the invoice or proforma become the source itself. Clicking
"Create delivery note" on an invoice backed by SO-001 opens
"Delivering against SO-001", and the note is tracked against the
order.

`invoice_items` and `proforma_invoice_items` gained
`delivered_quantity`, and the over-delivery guard, progress refresh
and source validation all handle the two new origins. A cancelled
invoice or proforma is refused, as is a draft invoice — issue it
first.

The picker on `delivery_notes/create.php` lists only proformas and
invoices with nothing upstream, since anything else is delivered from
its origin instead.

## 16. Future-proofing

The architecture accommodates, without further refactoring:

- **Customer portal** — `viewed`/`accepted`/`rejected` already exist on
  quotes with `accepted_at`/`accepted_by`; a portal only needs to write
  those transitions.
- **Multi-location inventory** — `warehouses` and
  `inventory_transactions.warehouse_id` are already in place.
- **Batch/serial tracking** — `product_batches` exists; delivery lines
  can gain a batch reference.
- **GRNs / purchase orders** — `inventory_transactions.movement_type`
  already includes `receipt`.
- **E-commerce sync** — order intake already exists (`api/`); the
  document graph absorbs web orders as sales orders.

## Files

| Path | Purpose |
|---|---|
| `database_structure/migrations/009_delivery_notes_inventory.sql` | schema: warehouses, delivery notes, inventory transactions, document links, statuses, dedupe |
| `database_structure/migrations/010_delivery_triggers.sql` | business-rule triggers |
| `database_structure/migrations/011_delivery_propagation.sql` | delivery status along the quote chain; invoiced notes locked |
| `includes/workflow.php` | statuses, transitions, delivery rules, traceability |
| `delivery_notes/` | list, create, view, post/cancel, PDF |
| `modules/sales/convert_to_invoice.php` | Sales Order → Invoice |
| `includes/reporting.php` | invoice-only sales metrics + delivery summary |
| `modules/purchasing/manage_pos.php` | purchase orders: create, edit lines (receipt-aware), cancel |

## Verified

Tested against live PostgreSQL, both on a fresh database and on a
legacy database carrying old-style statuses and duplicate product
lines (all migrated without data loss). Covered: the 24 → 2/10/12
partial-delivery example, over-delivery refusal, negative-stock
refusal, delivery from a draft quote refused, posted-note immutability,
cancelled-note reopen refused, duplicate-product refusal, duplicate
conversion refusal, cancellation stock reversal, and automatic invoice
linking. Zero PHP warnings.

## 17. Editing a purchase order

Editing a PO used to reach only the delivery date, address, notes and
terms. The product lines — the part of the order most likely to be
wrong — could not be touched at all: a mistyped quantity meant
cancelling the order and raising a new one.

The edit modal is now the same document builder as the create modal,
opened with the order's lines already in it. Adding, removing and
repricing lines all work, and subtotal, tax and total are recomputed
from the lines on the server; the browser's numbers are never trusted.

What the edit may **not** do is contradict goods that have already
arrived. `purchase_order_items.quantity_fulfilled` is the record of
what a GRN receipted, so:

| Attempt | Result |
|---|---|
| Reduce a line below what was received | refused — *"Test Dome Camera: 8.00 already received, so the order cannot be reduced to 5.00."* |
| Remove a line that has receipts | refused — *"… has 8.00 already received and cannot be removed from the order."* |
| Reduce a line to exactly what was received | allowed — closes the line off |
| Add a line, or raise a quantity | allowed, at any status short of closed |
| Edit a `received` or `cancelled` order | refused, and the edit button is hidden |

Lines are **updated in place** rather than deleted and reinserted.
That matters twice over: `quantity_fulfilled` survives the edit, and
`grn_items.po_item_id` keeps pointing at a row that still exists, so
receipt history is not orphaned. Only lines the user removed *and*
which have no receipts are deleted. The whole write runs in one
transaction behind `SELECT … FOR UPDATE` on the order, so a receipt
posted concurrently cannot slip between the check and the write.

---

## 18. Where the customer is (migration 036)

A customer record held a name, an email, a phone number and a tax
PIN, and nothing about where they are. For a business whose work
is driving to premises and fitting cameras to walls, that is the
field the job actually needs — and it was not on the form because
it was not in the table.

**One word for it.** The system already called this *location*:
`company_settings.location` is what prints under the company name
on every document. Suppliers called the same thing `address`. Two
words for one idea, on two sides of the same transaction, is how
a field ends up filled in on one form and left blank on the
other. So `suppliers.address` was **renamed** rather than
duplicated — the data is kept exactly as it was — and every party
now has a `location`.

**One free-text field, not town/street/postcode.** Kenyan
addresses are written as a road and a landmark — "Gaberone Road,
Montana Mall 2nd Floor, Shop M206" — and forcing that into boxes
designed for a different postal system produces empty fields and
a worse address than the one somebody would have typed.

Where it shows up:

| | |
|---|---|
| Customer and supplier forms | a Location box, add and edit |
| Both list pages | a Location column |
| Both searches | searchable, so "the customer in Thika" finds them |
| Both Excel/PDF exports | its own column |
| Customer picker on quotes, proformas and sales orders | part of what the type-ahead matches |
| Invoice and proforma PDFs | under the customer name, in the billed-to block |
| Delivery note PDF | as a **fallback** — see below |
| Purchase order, GRN and debit note PDFs | the supplier block, as before the rename |

**The delivery note keeps its own address.** A delivery can go
somewhere other than the customer's usual place, so
`delivery_notes.delivery_address` still wins when it is filled
in. The customer's location stands in when it is not, so a driver
is never handed a note with no address on it at all.
