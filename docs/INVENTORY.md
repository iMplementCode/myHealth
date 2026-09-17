# Inventory

How stock moves, and how to correct it when the shelf disagrees.

## Not everything sold is stock

The business sells cameras, and it sells the fitting of cameras.
`products.product_type` says which a line is:

| | `goods` | `service` |
|---|---|---|
| Quoted, invoiced, credited | yes | yes |
| Held in stock | yes | **never** |
| Counted, valued, reordered | yes | no |
| Goes on a delivery note | yes | **refused** |
| Bought on a purchase order | yes | no — that is an expense |

**Why a flag and not a separate table.** A service line on a quote
*is* a quote line: a name, a quantity, a unit price, a discount, a
total. Every table that carries a `product_id` —
`quote_items`, `proforma_invoice_items`, `sales_order_items`,
`invoice_items`, `credit_note_items` — would otherwise need a
second nullable foreign key and a `CASE` in every join just to
answer "what was sold?". One column answers it, and every
document, PDF, export and sales report kept working untouched.
The cost is migration 034, which had to tell everything that
assumed a product is physical otherwise.

**A service cannot hold stock.** A trigger zeroes
`stock_quantity` and `low_quantity_threshold` on write, so it does
not matter which code path tries. It also means an existing
product cannot be switched to a service while it still holds
stock — the form refuses it, because the trigger would silently
zero the quantity and nothing would say where the goods went.
Count it out or write it off first.

**A service never holds a document open.** Delivery status asks
whether anything is still owed *in goods*. Labour is not owed in
goods, and can never be sent on a note, so a service line that
counted towards delivery would keep an invoice "not delivered"
for ever — the exact fault migrations 030 and 031 were written to
fix. So the status is computed over goods lines only, and a
document whose lines are *all* services settles at
`fully_delivered`: there is nothing left to send.

That reuses an existing status rather than adding a fourth. About
a dozen places in the application ask
`delivery_status <> 'fully_delivered'` to mean "still needs
delivering", and a new status would have read as outstanding in
every one of them.

**Cost price still matters on a service.** It is what providing
it costs — the fitter's day, the fuel — so the margin on an
installation is real and the Sales Analysis reports it beside the
hardware.

## Every movement is recorded

`products.stock_quantity` is the balance. It is never typed — it is
the result of movements, and every movement writes a row to
`inventory_transactions` saying what moved, how much, where, why,
under which document, and what the balance became.

| Movement | Written by | Type |
|---|---|---|
| Goods arrive from a supplier | GRN posted | `receipt` |
| Goods go out to a customer | delivery note posted (trigger, migration 010) | `delivery` |
| A delivery note is cancelled | same trigger | `delivery_reversal` |
| Goods come back from a customer | goods return received | `return` |
| Goods go back to a supplier | debit note raised | `return` (negative) |
| A debit note is cancelled | debit note status set to cancelled | `return_reversal` |
| **The shelf disagrees with the books** | **stock count posted** | **`adjustment`** |

Cancelling a debit note puts the goods back because raising it
took them off. It has to, and not only for the stock: what is
owed to the supplier is `received − returned − paid`, so a
cancellation that restored the debt without restoring the goods
would leave the business paying for stock it does not have. The
two move together or neither moves. A cancelled note is then
final — reviving it would take the stock off a second time,
against quantities that may have moved on since — so raise a
fresh note instead.

A negative balance is refused by the database itself
(`trg_products_no_negative_stock`), so no route can quietly take
stock below zero.

### A receipt proves the goods landed before it commits

The balance and the ledger are one fact written twice, and the
worst failure in this whole module is a goods received note that
says stock came in when it did not. Nothing about the note shows
it — the note looks perfect; only the shelf disagrees.

So receiving no longer trusts its own writes. Before committing it
checks two things, which fail differently:

| Check | Against | Catches |
|---|---|---|
| **landed** | each balance vs. the figure read *before* anything moved, plus what arrived | a balance that did not change |
| **moved** | the stock ledger vs. what the note says arrived | a movement that was never written |

Both have to pass or the whole receipt is rolled back and the
message names the product and the two figures. A refusal is
visible; a silent divergence is not.

The ledger write used to be wrapped in a try/catch that swallowed
failures. That is now gone from both paths — the ordered lines and
the unordered extras. Stock that moved with nothing explaining why
is a discrepancy nobody can diagnose six months later, so a ledger
that cannot be written stops the receipt instead.

**For receipts from before that rule**, Purchasing → *Receipts that
do not add up* lists every line where the note says goods arrived
and no stock movement says so. The system cannot tell whether the
goods reached the shelf and only the record is missing, or whether
they never arrived on the books at all — guessing would either
double-count or leave it short — so it asks:

- **Take into stock** — adds the quantity and records the movement.
- **Already on the shelf** — records the movement alone.

Both write a note on the ledger row saying which was chosen.

### Receiving goods also records the VAT the supplier charged

`grns.total_amount` is **net** — what the goods cost, and what
stock is valued at. Alongside it, migration 029 added `tax_rate`
and `tax_amount`: the VAT on the supplier's invoice, entered at
receipt, because that is the moment their invoice is in somebody's
hand.

It is the **only** source of input VAT on the return. It used to
be apportioned off the purchase order, which is our own document
and whose 16% is a form default — see
[REPORTS.md](REPORTS.md#definitions-that-matter). Two rules keep
it honest:

- VAT cannot be recorded without the supplier's invoice number.
  Both the receive form and the edit modal refuse it.
- The rate is suggested from the **supplier**, not the order: a
  tax PIN on file → the order's rate, no PIN → No VAT, because a
  supplier who is not registered cannot issue a tax invoice.

The edit modal is the correction path for receipts entered before
their invoice arrived, and the VAT summary lists every receipt it
is *not* claiming so nothing goes missing quietly.

---

### Finding a receipt again

Goods Received filters on a **date range** as well as status, by
`receipt_date` — the date on the note, which is what somebody
reconciling a month or chasing a delivery is working to. Both ends
are optional, and the range travels with paging and the exports.

The search box covers everything that might be written on the paper
in front of you: our GRN number, the supplier, **their** invoice
number, the purchase order, the note, and **the products that were
in the box** — so a receipt can be found by what arrived when
nobody remembers its number.

## The product card (migration 049)

`inventory_transactions` has recorded every movement since migration
010, `grn_items` every receipt and `invoice_items` every sale. All of
it could only be read one document at a time, which for a question
like *who has one of these* is the same as not having it.

**Inventory → Products → any product name** (or the book icon on the
row) opens `modules/inventory/product_card.php`: one item's whole
life on one page.

| Panel | Answers |
|---|---|
| Header | what is on the shelf, what it is worth, units sold, profit |
| Where it came from, where it went | the last receipt — who, how many, what each cost — and the last sale — who, how many, what price |
| What it has made | revenue, cost of sales, gross profit, margin, average bought at, average sold at |
| Received | every goods received note: date, supplier, their invoice number, quantity, unit cost |
| Sold | every invoice line: date, customer, quantity, price, the cost at the time, and the margin |
| Every movement | the stock ledger, with the document linked and the counterparty named |
| Units | serial numbers, who holds them, and until when |

The three tables page independently (`p_page`, `s_page`, `m_page`) so
paging the sales list does not lose your place in the movements below
it. The money panel takes an optional date range; all time is the
default, because "has this line ever made money" is the question and
a period on it is the follow-up.

### Cost is captured at the moment of sale

This is the part that changes an existing number rather than adding a
new one. Margin used to be worked out as

```
quantity sold × products.cost_price     -- cost_price as it stands TODAY
```

so buying the next batch 15% cheaper silently improved last year's
profit, and buying it dearer turned a good month into a bad one.
Nothing on the invoice changed; the report just said something else.
`reports/sales.php` had been carrying a footnote admitting it.

Migration 049 adds `invoice_items.unit_cost` and stamps it from the
product's cost price **at the moment the line is written**. The
figure never moves again. `credit_note_items` has had a `unit_cost`
column since it was written — invoices were the gap, and that
column is what made the gap obvious.

**A trigger, not four edits.** Invoice lines are written from the
quote conversion, the proforma conversion, a goods return and the
contract biller, and a fifth path will be added by somebody who has
never read this file. A DEFAULT cannot help — it cannot see which
product the row is for — so `trg_invoice_items_stamp_cost` does it
and every path gets it, including the ones not yet written. An
explicit cost still wins, for a correction or an import that knows
better.

**Lines older than 049 stay NULL, on purpose.** The weighted average
of every receipt since would be a number nobody chose, sitting in a
column that claims to say what was actually paid. They fall back to
today's cost price exactly as before, and every figure built on them
counts how many lines needed the fallback:

- the product card says how many and what price it used;
- the sales report says how many are left in the period;
- the profit and loss switches its caveat from *this drifts* to
  *this is settled* once a period has none.

`ledger_unit_cost_sql()` is the single definition, used by the card,
the sales report and the profit and loss. Two versions of "what did
this cost" would disagree the first time either was touched, and
`t_ledger.py` cross-checks that the card and the report return the
same number.

### Ranking by profit, not just revenue

**Reports → Sales Analysis** sorts the by-product table by revenue,
profit, worst-first, margin or units. Revenue answers *what sells*;
profit answers *what is worth selling*, and they are not the same
list — a line can turn over a fortune at a margin that does not
cover handling it. Sorted in SQL so a capped list gets the right end
of it.

The same table has a **search** on product name and SKU, for the
question "what did this one line make last quarter" without reading
down forty rows. Three rules keep it honest:

- **It narrows the product table and nothing else.** The four cards
  above are the period's totals and do not move. A screenshot
  reading "net revenue 40,000" while a filter was quietly on would
  be a lie the page told, so the page says out loud that the cards
  are still the whole period.
- **The table's footer totals the table.** While a search is on it
  reads *Matching products* and sums the matched rows — quoting
  every product's revenue underneath one filtered row reads as that
  row's total at a glance.
- **A search that matches nothing says so**, rather than "nothing
  was invoiced in this period", which sends somebody to check their
  dates when the fault is their spelling.

Filtered in PHP over the rows already fetched, because the whole
period is read uncapped by design and a second query would be a
second definition of the same list. The search travels with the
sort links, the pager, the period bar and the export — and the
export names the filter in its meta, so a spreadsheet holding three
products is not filed as the month's sales.

## Every unit has a serial

A camera is not a quantity. It is a specific box with a number
stamped on it, fitted to a specific wall, covered until a specific
date. `products.warranty_months` says how long a *model* is covered;
until migration 038 nothing recorded which unit went where, so the
question the counter is actually asked —

> this DVR has failed, is it still under warranty?

— was settled by going through paper delivery notes.

**Inventory → Serial Register** (`modules/inventory/serials.php`).
The search box takes a serial number and nothing else is needed to
use the page, because that is what somebody holding the box types.

### Not every product

Cable, connectors and screws are a quantity and nothing more. Making
somebody type a serial for 100 m of coax would get the whole feature
switched off, so tracking is opt-in per product and off by default:
**Track each unit by serial number** on the product form
(`products.tracks_serials`). A service can never be tracked — there
is no box — and the database refuses it rather than trusting the form.

### The warranty clock starts on delivery

A unit on the shelf is not under warranty to anybody; the customer's
cover starts the day it is fitted. So the months are **copied onto
the unit at delivery** and the expiry derived from that date:

```
warranty_expires_at = delivered_at + warranty_months   (generated)
```

Copied, not looked up. A model's cover can be renegotiated with the
supplier next year, and the unit already on a wall in Thika keeps
what it was sold with. A warranty that changes retroactively is not a
warranty. There is a test for exactly that: the product's months are
changed after a delivery and the unit's expiry must not move.

### One unit is in one place

The rules live in the database (migration 038), not in the page:

| Rule | Why |
|---|---|
| A delivered unit cannot be delivered again | It means a mistype, or it came back and nobody said so. Either way the answer is to stop, not to overwrite where it was |
| A unit at a customer knows which customer and since when | Otherwise the register fills with "delivered" rows that cannot answer the only question it exists for |
| Coming back clears the customer, the note and the date | So the register never shows one box in two places |
| Only a tracked, non-service product can take a serial | A half-complete register stops being believed |

## The warranty note (migrations 046–048)

The register above answers *our* question — is this unit still
covered. It does nothing for the customer, who has an invoice
proving what they bought and a memory of somebody saying "one year"
across a counter. Eleven months later they remember two years, and
there is no paper either way.

**Invoice → Warranty Note**, or the shield on a proforma row.
`warranties/create.php` reads the document, drops the service lines,
fills in each period, and issues a numbered note (`WTY-2026-0001`) on
the company letterhead.

### The period comes from the product

`products.warranty_months` is already the right answer for that
product, so it is used as-is. The template's default only fills in
for products that carry no period of their own. A camera at 24
months and a power supply at 6 go onto one note and each line keeps
its own expiry — the alternative is one period for the note and an
argument about which item it applied to. Every line's period can
still be overridden before issue; the screen says which came from
the product and which is the template default, because comparing
the numbers cannot tell them apart when a product happens to be set
to exactly the default.

### Services are left off

A warranty covers equipment that can fail. "Installation" is labour
already performed, and putting it on the note invites a claim to
redo the job for free in year two. If workmanship is guaranteed,
that belongs in the template's wording where it can be said
properly.

### The terms are a list of sections, not three fields

Migration 046 gave the note three blocks of text — covered, not
covered, how to claim. That was a guess at the shape of the
document. The real one is seventeen numbered clauses with lettered
sub-headings, an equipment schedule, a sign-off and a summary box,
so 047 turned the terms into rows: `warranty_template_sections`.

**Settings → Warranty Templates** edits them one at a time. Each
section has a heading, a level and a layout:

| Level | Prints as |
|---|---|
| 1 | `1. WARRANTY COVERAGE` — numbered |
| 2 | `A. Physical Damage or Misuse` — lettered, restarting under each clause |
| 3 | a plain heading, unnumbered |
| 0 | no heading, for the opening paragraphs |

| Layout | Holds |
|---|---|
| `prose` | paragraphs and bullets |
| `schedule` | the equipment table, then the body under it |
| `signature` | the two-column sign-off |
| `summary` | `Label: text` lines as a bordered box |

**The numbers are derived from the order, never typed in.** Insert a
clause at position four and everything below renumbers. Typing them
by hand means the document starts lying about its own structure the
first time somebody inserts one.

The body markup is deliberately tiny, because somebody editing terms
and conditions in a textarea should not also be learning a syntax: a
blank line starts a paragraph, a line beginning `- ` is a bullet.

### The words are copied, not referenced

Every note stores the **whole section list as it stood the day it
was issued**, in `warranty_notes.terms_json`. Edit a clause next year
and a note handed over today still says what the customer was
actually promised. A warranty whose terms can be rewritten after the
fact is not a warranty, and that is the only reason the text is
duplicated onto the note.

`t_warranty.py` earns that claim: it edits a section after issuing,
reprints the note in both formats, and fails if the new wording
appears. Sabotaging either exporter to read the live template makes
exactly those checks fail. Note the ordering — the template is put
back **after** the Word check, not before. Restoring first made that
check unfalsifiable, because an exporter reading the live template
would have printed the right words by accident.

The same rule explains one smaller decision: `expires_on` is stored
per item rather than computed, so the date on the paper in the
customer's hand cannot move when a setting changes.

### One live note per document (migration 048)

Two warranty notes standing against one invoice is not a generous
mistake, it is an unanswerable one. The customer holds two pieces of
paper; one says 24 months from March, the other 12 from June.
Whichever the business honours, it is arguing with its own document.

It happened by accident: raise a note, fail to find it in the list,
raise another. Migration 046 warned about this on the create screen
and let it through — but a warning is a rule everybody is allowed to
break.

Two partial unique indexes, not a trigger and not a PHP check:

```sql
CREATE UNIQUE INDEX uq_warranty_note_live_invoice
    ON warranty_notes (invoice_id)
    WHERE status = 'issued' AND invoice_id IS NOT NULL;
```

The whole fact — which document, and whether the note still stands —
lives on `warranty_notes`, so the database enforces it directly. It
is also the only version that survives two people pressing Issue at
the same moment: a check-then-insert cannot see an uncommitted row,
however carefully it is written. The application still checks first,
so the refusal can name the note in the way; the index is what makes
the rule true.

The create screen renders **no form at all** when a note already
stands, and offers Edit / View / Cancel instead. Rendering the form
under a warning invites somebody to fill the whole thing in and be
refused at the end.

### A note is cancelled, never deleted — and cancelling says why

The customer may still be holding a copy, so the row stays and every
reprint is marked cancelled. A second note is allowed once the first
is cancelled, which is how a note raised against the wrong invoice
gets corrected.

Cancelling **requires a reason**, enforced by a CHECK constraint as
well as the form. "Cancelled" on its own answers nothing six months
later: wrong invoice, goods returned, re-issued with the right
serials — each is a different answer to "so what is this customer
actually owed", and the person pressing the button is the one who
knows. The reason prints on the note, under the cancelled banner.

The constraint is `NOT VALID` on purpose. Notes cancelled before the
migration have no reason recorded, and inventing one for them would
be a lie in the audit trail; the rule binds every cancellation from
here on and the old rows stay visibly empty.

### Amending an issued note

**Serial numbers are why `warranties/edit.php` exists.** They are
read off boxes on a site, often after the paperwork, and a note
carrying the wrong one is worse than a note carrying none — it names
somebody else's camera. Without an edit the only fix is
cancel-and-reissue, which burns a number and leaves a cancelled note
in the file that reads as though something went wrong.

Editable: the dates, the note text, and every line — brand/model,
serial, period, per-line start — plus adding a line from the source
document or dropping one. Every expiry is worked out again from the
dates on save.

Not editable, and each for a reason:

| | Why |
|---|---|
| The number | It is on the customer's copy |
| The terms | The copied-terms guarantee is the point of the feature |
| The source document | A note against the wrong invoice is not an edit — cancel it and raise the right one |
| A cancelled note | It is a record of something withdrawn, not a draft |

"Frozen" has to mean frozen against accident rather than against
correction, so a note issued under the wrong template can be
re-stamped — but only by ticking a box that is off by default and
spelled out, and the audit log records that it happened.

The items are replaced wholesale on save rather than matched up by
id. Matching means three code paths — update, insert, delete — and a
bug in any of them leaves the note saying something nobody chose.
What arrives from the form is the complete list of what the note
covers; that is what it becomes.

An amended note carries `amended_at`, and the PDF and Word file both
print it, so a reprint can be told from the copy already in the
customer's file.

### PDF and Word

`warranties/pdf.php` and `warranties/docx.php` draw the same section
list; only the rendering differs. The Word file exists because a
customer may want to countersign or a lawyer to comment, and neither
is comfortable in a PDF.

`includes/docx_writer.php` builds the `.docx` directly — it is a ZIP
of a few XML files, and `ZipArchive` is already in PHP. PHPWord would
be a dependency to install and patch on the VPS for one document
shape. Two things it cost to learn, both silent failures where Word
says only "the file cannot be opened":

  - **Element order inside `w:pPr` and `w:rPr` is schema-enforced.**
    `pBdr` before `spacing`; `color` before `sz`. Nothing warns you.
  - **A table must be followed by a paragraph.** Two adjacent tables
    with nothing between them merge into one on open.

The cover start follows section 1 of the terms — installation date
where we installed it, delivery date otherwise — and the create
screen works that out rather than asking somebody to.

### Receiving is bulk

Serials are typed off a stack of boxes or pasted from a packing list,
so the form takes a block and splits on new lines, spaces, commas and
semicolons — people paste from a spreadsheet, a WhatsApp message and
a printed list on the same day.

Each serial is inserted inside its own `SAVEPOINT`. In PostgreSQL one
failed statement poisons the whole transaction, so without that a
single duplicate in a list of twenty would throw away the other
nineteen. A serial that could not be taken is **named** in the reply,
not silently dropped: "1 unit registered. 1 skipped: SN-0042 (already
registered)".

---

## Stock take

**Inventory → Stock Take.** Administrators and Managers.

Counting the shelf is the one movement with no other document
behind it. Until it existed the only options were to leave a wrong
figure alone, or to edit the product and leave no trace of who
changed what or why.

### Raising a sheet

Choose what to count:

| Scope | Why |
|---|---|
| **Only what the system thinks is low** | Where being wrong costs a sale. The one to count most often. |
| **Only what has moved in 90 days** | A part nobody has touched since last year rarely walks off on its own. |
| **One category** | Cameras this week, cabling next. |
| **Everything active** | Year end. |

Counting everything at once is how a stock take never gets
finished. A short sheet counted often beats a long one counted
never.

The sheet captures, per line, **what the books said at that moment**
and **the cost price at that moment**. Neither is read again at
posting time — a price rise next month must not restate what last
month's shrinkage was worth.

### Counting

The difference and what it is worth appear on the line as the
number is typed, and the running total keeps up. **Enter** moves to
the next line rather than submitting, because counting is a column
of figures.

A blank line has **not been counted yet**, which is not the same as
counting zero. Only lines with a figure in them are posted.

Print the sheet to count on paper: the printed version leaves the
counted column blank and drops everything that is not needed at a
shelf.

### Reasons

The reason is not decoration. "Twelve missing" and "twelve missing
because a pallet was rained on" are the same number and quite
different problems, and only one of them is worth putting a camera
on.

| Reason | Counts as a loss? |
|---|---|
| Miscount / booking error | No — a correction, not a loss |
| **Shrinkage — missing, unexplained** | **Yes** |
| Damaged, written off | Yes |
| Expired | Yes |
| Found — more than expected | No |
| Opening balance | No |
| Other | No |

The shrinkage figure on the Stock Take page is the year's
`shrinkage + damage + expiry` at cost. Miscounts are excluded
deliberately.

### Posting

Posting applies **the difference the counter found** — counted less
what the books said when the sheet was raised — not the counted
figure itself.

That distinction matters. A sheet is written at nine and posted at
five, and stock moves in between. Setting the balance to the
counted figure would silently undo every delivery made during the
count. Applying the difference leaves them alone.

Each adjusted line writes an `adjustment` row to the stock ledger,
carrying the reason and the note, so a figure can always be traced
back to the count that changed it and the person who counted.

**A posted count cannot be reopened.** It is the evidence for an
adjustment already made; the database refuses both a status change
and any edit to its lines. To correct one, raise another count.

---

## What the stock is worth

Two pages answer this, and they answer different questions.

**Stock and Reorder** is operational: what is low, what is out,
what to buy. It values the whole active catalogue.

**Stock Value** (`reports/stock_value.php`) is the money question,
and it counts only what the business can actually sell — **active,
and carrying a selling price**. A product with no selling price is
a consumable or a fitting, not stock for sale, and counting it
inflates the answer to *if we sold what is on the shelf, what would
it come to*.

Nothing is hidden by that rule. Excluded stock is listed at the
foot of the page with the reason against each line, and the page
does the reconciliation to the daily position out loud:

```
saleable at cost  +  active but unpriced  =  daily position inventory
```

Stock held against *inactive* products appears in neither, so it is
called out on its own — it is usually either a discontinued line
worth writing off or a product switched off by mistake.

Both values are shown: **at cost** (the balance-sheet figure) and
**at retail**. The gap is margin *not yet earned* — nothing has
been sold, so it is not profit. At cost means what was actually
paid; see below.

## What is not there yet

- **Stock is one figure per product, not per location.** The
  `warehouses` table and the ledger's `warehouse_id` are in place
  and stock counts record where the counting happened, but the
  balance itself is company-wide. See [SCALING.md](SCALING.md) for
  what that change looks like.
- **No transfers between locations.** The ledger has the movement
  type; nothing writes it. It needs per-location stock first.
- **Cost of sales values a sale at today's cost, not at the cost
  of the units that went out.** Stock on hand is costed correctly
  (below), but a sale made last month is still valued at the
  product's cost price as it stands now. Getting that exact means
  recording which layer each sale consumed, at the moment it
  consumed it — a bigger change, and a smaller error than the one
  fixed in migration 035.

---

## What stock is worth

Cost is **what was actually paid**, taken from the goods received
notes, on a **first-in first-out** basis. The oldest stock is
treated as sold first, so what is on the shelf is the most recent
receipts:

```
  23 in at 2,600 · 13 sold · 15 in at 3,400   →   25 on hand

      15 × 3,400  =  51,000     the new lot
      10 × 2,600  =  26,000     what is left of the old one
                     ───────
                     77,000     and 3,080 each
```

Until migration 035 that figure was 65,000. `products.cost_price`
was typed in once when the product was created and **no receipt
ever changed it** — buy at a new price and the books never heard.
Twelve thousand shillings missing on one product, on the balance
sheet, at a year end, on an insurance claim.

**`cost_price` now means something precise:** the average unit
cost of the stock on hand, derived from the receipts. It is kept
on the row rather than computed in each report because the
valuation, the daily position, cost of sales, the Sales Analysis
margin and the dashboard all read it already — give it the right
value and every one of them is right, with no second definition
to drift. Triggers recompute it on a receipt, an edit to a
receipt, a cancellation, goods returned to a supplier, and any
movement of stock, because selling the oldest units leaves a
dearer mix behind.

**No consumption tracking, and none needed.** Under FIFO the
stock remaining *is* the newest receipts, so the layers are
derived from the receipt history and the quantity on hand. There
is a `product_batches` table with a `quantity_remaining` column,
added early and never written to — using it would mean
maintaining a running balance per batch on every sale, return,
count and cancellation, which is a great deal of machinery to
keep in step for an answer that can be derived. It stays unused.

**Stock with no purchase behind it keeps its typed cost.**
Opening balances entered by hand at go-live have no receipt to
read, so what was typed stands. `product_stock_cost()` reports
how much of the quantity receipts actually cover, and the Stock
Value report marks the uncovered part, so the gap is visible
rather than assumed away.

`product_cost_layers()` returns the working — "15 × 3,400 +
10 × 2,600" — which is what the **avg** badge on the Stock Value
report shows when you hover it.

---

## Delivery: the note is the authority

A delivery note carries two facts, and confusing them is what made
an invoice read "Delivered" while the note it was reading said
"Not delivered".

| Field | Values | Means |
|---|---|---|
| `status` | draft, posted, cancelled | the document's own life. **Posting is what releases stock.** |
| `delivery_status` | not / partially / fully delivered | whether the goods reached the customer |
| `delivery_status_override` | the same three, or empty | a human saying otherwise |

**The rule:**

```
draft or cancelled            → delivers nothing
posted                        → delivers what is on it
posted, but marked otherwise  → whatever the mark says
```

A parent document — sales order, quote, proforma, invoice — counts
a note's lines **only when that note has actually delivered
something**, and can never read "fully delivered" while a note
covering it is only partly delivered. The goods are out there
somewhere, and saying otherwise is how a customer gets chased for
something they never received.

Marking a note by hand (*Delivery Notes → open one → fulfilment*)
propagates immediately, in both directions: mark it not delivered
and the invoice follows it down; clear the mark and it follows back
up.

### What was wrong before migration 025

Three things, which together produced the contradiction:

1. **Nothing maintained a note's own `delivery_status`** unless it
   hung off a quote or a sales order. A note raised against an
   invoice or a proforma — the common case since migration 014 —
   sat at its column default, `not_delivered`, for ever.
2. **The parents never looked at it.** They counted a note's
   quantities on `status = 'posted'` alone, so posting a note made
   its invoice say delivered whatever the note said.
3. **Marking a note by hand changed nothing upstream.** The trigger
   watched `status` and the origin columns, but not
   `delivery_status_override` — the one column the override writes.

### And what was still wrong until migration 030

Delivery status is derived from **two** things: what the note
delivered, and what the document asked for. Migration 025 wired
every recomputation to the first and none to the second.

That works when the note comes last. It fails whenever the
paperwork comes last — which is the ordinary way round for a
customer who takes the goods and is invoiced afterwards:

```
1. INSERT INTO invoices …           header only, no lines yet
2. tg_invoice_link_dns finds the note raised against the quote
   and back-links it
3. that UPDATE fires the note refresh, which computes the
   invoice's status from invoice_items — there are none yet —
   so COUNT(*) = 0 → not_delivered
4. the lines are inserted a moment later, and nothing
   recomputes anything, ever
```

The answer was not wrong when it was worked out. It was worked out
**too early, and then frozen** — so the invoice read *Not
delivered* and went on offering to create a delivery note for goods
that had already gone out. A proforma raised after a delivery had
the same hole and got away with it by luck: raising an invoice from
it later cascaded back and fixed it, but raise a proforma and stop
and it stayed wrong.

Migration 030 puts a trigger on the **lines** of every document in
the chain — `invoice_items`, `proforma_invoice_items`,
`quote_items`, `sales_order_items` — plus the proforma header, so a
document recomputes as soon as it knows what it is for.

It also lets a note reach an invoice through the proforma's quote:

```
dn.quote_id → pi.quote_id → i.pi_id
```

The old match relied on `dn.invoice_id` having been back-filled,
and that back-fill only ever touches a note whose `invoice_id` is
still NULL — so a quote invoiced twice left the second invoice with
no route back to the note at all.

### Credited goods are not owed — migration 031

Two products on an invoice. One went out on a delivery note. The
other was credited: the customer is not having it and is not
paying for it. Nothing is outstanding — and the invoice read
*Partially Delivered* for ever, still offering to raise a delivery
note for goods nobody was ever going to send.

Delivery status compared `delivered_quantity` against the quantity
**invoiced**, and a credit note has nothing to do with the quantity
invoiced. So a credited line stayed outstanding permanently: it can
never be delivered, and it was the only thing holding the invoice
open.

```
outstanding = invoiced − delivered − credited
```

That is the same sentence about goods that migration 027 already
wrote about money:

```
owed        = invoiced − received  − credited + refunded
```

A credit note is both sides agreeing that part of the sale is not
happening. It cancels the obligation to deliver exactly as it
cancels the obligation to pay. `invoice_items.credited_quantity`
holds it, maintained by triggers on `credit_notes` and
`credit_note_items`, so approving a credit note closes the delivery
and cancelling one re-opens it.

**Two things it deliberately does not do.**

It does not touch `delivered_quantity`. Goods delivered and then
returned were still delivered — the return note brings the stock
back and the credit note settles the money, and rewriting history
to say the goods never went would lose a delivery that happened.

It will not call an invoice *fully delivered* when nothing was ever
delivered. An invoice credited away in full has no outstanding
lines, but saying it was delivered is a lie on a document. It stays
*not delivered*, which is true — and the pages stop offering a
delivery note anyway, because that button now asks a different
question:

> **Is anything still outstanding?** — not *does the badge say
> fully delivered?*

The delivery form shows the credited quantity in its own column and
marks such a line **Credited** rather than *Complete*, because
"complete" against something nobody ever sent reads as a mistake.

---

## Printing and PDF filenames

Every document PDF downloads under its own number —
`Invoice_INV-2026-0001.pdf`, `Quote_Q-2026-0004.pdf`,
`GRN_GRN-2026-0012.pdf` — so a folder of them can be told apart.

Two things have to be true for that, and for the five legacy print
pages only the first was:

1. the response says the name, in `Content-Disposition`
2. it says **`attachment`**. Served inline, the file goes to the
   browser's own PDF viewer, and what that viewer calls the file
   when you press Save is its business — most often the last
   segment of the URL, which is the same `print_quote.php` for
   every quote ever printed.

So each list now has two actions: **Print** opens it for reading
(`?dl=0`, the default), **Download** saves it (`?dl=1`). Both are
built by `pdf_document_filename()`, which also scrubs the document
number — a slash or a newline in a header is a header injection,
and a document number is user-entered text.


### One right margin, and everything on it

A document has two edges. Down the left, the company block, the
section rules, the items table and the footer all start together.
Down the right, the title, the meta block, the table and the totals
all have to end together — and that edge is the one the eye follows
down a page of figures.

The meta block did not respect it. Labels were right-aligned
against left-aligned values, in a table sized to its own content:

```
        Number:  CN-2026-0001
Against invoice:  INV-RET-0001
          Scope:  Full return
    Approved by:  Implement Administrator
```

The colons line up, and every value ends wherever its own words
happen to end — a 59pt ragged edge, measured. It is the other way
round now, labels left and values right across the full width of
the header cell, so the values close on the same line as the title
above them.

Two more faults went with it:

- **The header cells were never told to sit at the top.** A table
  cell defaults to `vertical-align: middle`, so the company block
  floated down the page against the taller meta column beside it.
- **The BILL TO / CREDITED TO box had a permanently empty
  neighbour.** Every document this renders had a hard-coded empty
  second cell, so the rule under that heading stopped half way
  across the page while every other rule ran the full width.

One trap for anyone editing this file: **the CSS lives inside a
single-quoted PHP string**, so an apostrophe in a comment ends the
string. `header's` cost a parse error.

The regression test measures the rendered PDF rather than the HTML,
because what dompdf does with a table is the only thing that
counts. It checks the title, every meta value and the section rules
against the drawn margin, on all five documents. Text bounds are
ink rather than advance width, so a few points of side bearing are
allowed against a rule but not between two pieces of text.

Two versions of that test were wrong before it caught anything:
one measured a heading by its bounding-box height, which matched
nothing at all, and one flagged the items-table column borders and
the signature lines — both of which are meant to be short. A check
that never fires is worse than no check, so it now fails if it
measured no section rule at all.

### The title fits on one line

Eight documents printed their name at five different sizes — 28px,
30px or 32px, with one or two pixels of letter-spacing — in the
right-hand half of the header. The longest of them did not fit:

| Document | Was |
|---|---|
| **GOODS RECEIVED NOTE** | broken over several lines |
| **PURCHASE ORDER** | broken over three |
| **PROFORMA INVOICE** | broken over two |
| **DELIVERY NOTE** | broken over two |

A document's name broken across lines stops reading as its name.

All eight now share one size — **20px, half a pixel of tracking,
`white-space: nowrap`** — chosen to fit the longest title the
system prints inside its half of the header, with the body text
down a point (13px → 12px) and the company name to 19px so it no
longer competes with the title for the same glance.

An amount in the totals block is `nowrap` too, for the reason the
report exports are: on a narrow column dompdf put `KES` above
`40,000.00`, which reads as a different figure.

A test renders all eight and asserts three things — the title on
one line, no text outside the page, and no figure broken across
lines. Before this change four titles failed it.

### Goods coming back

A goods return note is a delivery note facing the other way, so it
is the same renderer — `stream_delivery_pdf()` — with three
options rather than a second copy of 120 lines of markup:

| Option | Delivery note | Goods return note |
|---|---|---|
| `items[]['expected']` | absent | adds an **Expected** column |
| `qty_label` | Quantity | Received |
| `sign_left` / `sign_right` | Delivered by / Received by | Returned by (customer) / Received into stock by |

Every one defaults to what a delivery note already did, so that
document is untouched — a test asserts it still renders with one
quantity column, its own signatures and its own footer sentence.

Neither carries money. One says goods went out, the other says
goods came back; what either is asked to settle is whether the
right number of boxes moved.
