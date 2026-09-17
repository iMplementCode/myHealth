# Finance

The business kept its money records in a spreadsheet: a transaction
ledger with a running balance, and a once-a-day summary of what the
company was worth. Both are now pages with the same shape — but the
arithmetic is derived rather than typed, and the vocabulary is the
one an accountant, a bank or an investor already understands.

## 1. The vocabulary

The spreadsheet's quantities were the right ones to track. Two of
its names were not what those words mean in accounting, so they
have been corrected:

| Spreadsheet | Report | Why |
|---|---|---|
| Cash at hand | **Cash and bank balances** | Money is banked now, not only held in a till |
| Debtors | **Trade receivables** | What customers owe on issued invoices |
| Creditors | **Trade payables** | What the business owes suppliers |
| Operating revenue | **Total current assets** | Revenue is what you *earn from sales*. Cash plus what you are owed is an asset total, not revenue |
| Company valuation | **Net asset value** | A valuation is what someone would pay for the business. Assets less liabilities is its net worth |

The daily report is now a small statement of financial position:

```
ASSETS          inventory (at cost)
              + cash and bank balances
              + trade receivables          ← unpaid invoices
              + staff loans receivable
              + other receivables          ← credit given with no invoice
              + supplier prepayments       ← paid before the goods arrived
              = total current assets       ← all of it cash within a year

              + fixed assets               ← equipment, at cost less depreciation
              = total assets

LIABILITIES     trade payables              ← goods received, not yet paid
              + customer advances          ← paid before invoicing
              + investor loans
              + refunds owed to customers  ← returned goods already paid for
              = total liabilities

                total assets − total liabilities = NET ASSET VALUE
                                         of which investor capital
```

The third column of the report lists every account and then totals
**held and owed to us** — cash and bank plus every receivable. That
is what the business has, other than stock: the money it is holding
and the money it is owed, in one figure.

Alongside it, the day's **cash movement** (opening balance,
receipts, payments, transfers, closing balance) and **revenue** for
the day.

### Downloading a day

**Excel** and **PDF** beside Print. The day's position is what gets
sent to a bank, an accountant or a partner who is not going to log
in, so it leaves as a file rather than only as a screenshot of one.

One section per day in the range, in the same running order as the
page, plus a **Where the money sits** sheet per day listing the
account balances — whose total is the cash and bank line above it,
which makes the file self-checking.

Every figure comes from the same `cash_position()` array the page
renders from, so the file and the screen cannot disagree about a
number. The running order and the wording live in
`cash_position_lines()`; a test reads the figures back out of the
PDF and compares them against the page's own HTML.

The header carries whether the day is **closed or open**, whether
the inventory figure is live or as it stood at close, and whether
the day **reconciles** — because a statement that quietly does not
add up is worse than one that says so.

The file is named for the day it describes —
`daily-position-2026-08-12.pdf`, or `-2026-08-09-to-2026-08-11` for
a range — not for the day it was downloaded. A folder of them
sorts into the order the days happened.

## 2. Where money sits

Five account types, because they behave differently:

| Type | Holds real money? | Counts as |
|---|---|---|
| **Cash / till** | yes | Cash and bank balances |
| **Bank account** | yes | Cash and bank balances |
| **Mobile money** | yes | Cash and bank balances |
| **Staff loan** | no | An asset — owed back by an employee |
| **Informal credit** | no | An asset — goods released with no invoice raised |

Migration 016 creates placeholder banking accounts — *Main Current
Account*, *Secondary Current Account*, *Savings Account*, *Mobile
Money Wallet*, *Petty Cash*. **Rename them to the real bank and
account when those are known.** A name is a label; renaming one
changes no history and no balance.

Sales, purchases, expenses, receipts, advances and investments can
only be recorded against an account that holds real money. The
database refuses the rest, so a receipt cannot be banked into a
staff-loan account by mistake.

## 3. Invoice payments and proof of payment

*Sales → Invoices → Record payment*, or **Finance → Invoice
Payments** for the full history.

An invoice's paid amount is no longer typed by anyone. It is the
sum of its receipts, kept in step by a database trigger, and the
status follows from what is **outstanding** — which is receipts and
credit notes and refunds together, not receipts alone:

| Outstanding | Status |
|---|---|
| the whole invoice, nothing received | `issued` (or `overdue`) |
| something, and something received | `partially_paid` |
| nothing | `paid` — meaning **settled** |

`paid` therefore covers an invoice paid in full, and one settled by
goods coming back, and one that was half paid and half returned.
`credit_status` says which. See [the one formula](#the-one-formula)
for why a payment cannot be pinned to a particular line, and what
that means when a customer returns one of two items.

**Whatever is left outstanding is a trade receivable**, so a
partly-paid invoice leaves only its balance there — which is
precisely what the daily position shows.

Each receipt carries its **proof of payment**: the M-Pesa message,
bank slip or statement page, as an image or a PDF up to 5 MB. The
file's type is taken from the file itself rather than the name it
claims, and it is stored under a generated filename in
`uploads/payments/`, where `.htaccess` disables PHP execution.

The database refuses an over-payment, naming the figures:

> Invoice INV-2026-0002 is for 34,800.00, and 10,000.00 is already
> received. A further 30,000.00 would over-pay it.

Administrators can reverse a receipt. The ledger entry goes with
it, and the invoice status is recalculated — no orphaned cash.

### The same receipt, recorded from the cash book

A receipt can also be entered where the money is first noticed —
**Finance → Cash Book → Record Entry**, type *Invoice payment*. It
is the same event and it is recorded the same way, because both
pages call the same function: `cash_record_invoice_receipt()` in
`includes/cashbook.php`. There is one set of rules about what may
be received and how much, not two that can drift apart.

The entry asks **which invoice**, and it is not optional. Money
received with no invoice behind it is not an invoice payment; it is
money on account, which this system already has a name for —
a **customer advance**. The form says so when the invoice is left
blank.

Only invoices that can still take a receipt are listed:

* issued (or overdue, or partly paid) — never `draft`, never
  `cancelled`
* with something still owed on them

A settled invoice is deliberately absent. Applying money to it
would over-pay it, and the database refuses that anyway; leaving it
out of the list stops the mistake being made at all.

Choosing an invoice fills the amount with its balance and the name
with the customer's, both of which can be typed over — a part
payment is simply a smaller figure. The hint under the picker
becomes a statement of the account:

> Invoiced KES 12,500.00 · settled KES 3,125.00 · KES 9,375.00
> still owed

**The balance offered is the true one**, credit notes and refunds
included: `invoiced − received − credited + refunded`, the same
expression the receivables report and the customer statement use
(`invoice_balance_parts()`). An invoice half credited is not owed
in full any more, and the picker says so. A receipt larger than
that balance is refused by name:

> Only KES 200.00 is still owed on INV-2026-0007. Record the
> difference as a customer advance instead.

The cash-book row this writes is **stamped with where it came
from** (`source_type = 'invoice_payment'`). It therefore cannot be
edited or deleted in the cash book — it is reversed on the invoice,
and the ledger entry goes with it. The row carries a *from invoice
payment* badge to say so.

#### Every posted line says where it came from, and goes there

A stamped row is not editable in the cash book, so its Actions
column used to be empty — which reads as a page that is broken
rather than a rule being kept. It now carries the one action that
line actually wants: **open the document behind it**. Clicking it
lands on that expense, that receipt, that investment, filtered to
the single row, with **Clear** to get back to the full list.

That is a cash book's most-used move. The first question anybody
asks of a figure is "what was that?", and the answer is never in
the cash book itself.

The link is built by `cash_source_url()` in `includes/cashbook.php`
from `source_type` and `source_id`, and there are two shapes:

| Source | Lands on |
|---|---|
| expense · invoice payment · supplier payment · investment · staff loan · customer advance | that one row (`?focus=<id>`) |
| investor repayment · loan repayment · customer refund | the list, unfiltered |

The second group is not laziness. Those rows stamp the id of the
**repayment**, which is a child row with no list of its own;
sending it as `focus` would pinpoint whichever unrelated parent
happens to share that number, which is worse than not pinpointing
at all.

`?focus=<id>` is now a convention across the six list pages: it
filters to one row, counts as an active filter (so **Clear**
appears), and rides along with pagination and the exports so the
download matches what is on screen. Every target requires
`ROLE_MANAGER`, exactly as the cash book does, so a link is never
offered to somebody who cannot follow it.

A row entered by hand keeps **Edit**, and Delete for an
administrator, because nothing else owns it.

One thing does work in the other direction: an entry recorded
loosely before, with no invoice attached, can be **edited and given
one**. The money never moved — only the record of it — so the plain
row is dropped and written again through the ledger, in a single
transaction.

The same picker appears for a **Purchase**, offering purchase
orders with a balance to pay, and running through
`cash_record_supplier_payment()`. There it is *optional*: stock
bought over a counter is a genuine purchase with no order behind
it. Name the order and the payment reduces what is owed to that
supplier; leave it blank and the entry stays a plain cash purchase.

## 4. Staff loans

**Finance → Staff Loans.**

```
requested → approved → disbursed → settled
                ↘ rejected
```

Nothing reaches the books on approval. Approving is a decision;
**disbursement** is what moves money, so that is when the cash book
is written: cash out of the paying account, into a receivable
account carrying that employee's balance. The loan account is
created automatically on first disbursement.

The effect on the position is the point:

| | Cash and bank | Staff loans | Total assets |
|---|---|---|---|
| Before | 568,000 | 0 | 672,800 |
| After lending 20,000 | 548,000 | 20,000 | **672,800** |

Lending does not change what the business is worth — only where
the money sits. That is why a loan cannot be an expense.

Managers may request a loan; **only an administrator can approve or
reject one**. Disbursement is refused if the paying account did not
hold enough on that date, and a loan cannot be over-repaid. A loan
repaid in full settles itself.

A repayment by **salary deduction** takes no account: no money
arrives, the employee's debt simply shrinks. Note the limitation —
there is no payroll module, so the matching wages cost is not
recorded anywhere. Deduct the same amount from the salary actually
paid, or the cost is counted twice.

## 5. Customer advances

**Finance → Customer Advances.** Money a customer pays before the
invoice exists.

This is **not income and not a sale**. The cash is real and is
banked like any receipt, but the business now owes goods or
services for it, so it sits under **liabilities** until an invoice
draws it down. Recording it as revenue on the day it arrives
overstates both sales and net asset value.

Applying an advance to an invoice **moves no cash** — the money
arrived when the advance did. The receipt is written with no
account attached, so the cash book is untouched:

| | Cash | Receivables | Advances (liability) | Net asset value |
|---|---|---|---|---|
| Advance of 40,000 | +40,000 | — | +40,000 | unchanged |
| Applied 24,800 to an invoice | unchanged | −24,800 | −24,800 | unchanged |

An advance can only settle **its own customer's** invoices, and
cannot be drawn past its balance; both are enforced by the
database. An unused balance can be refunded, which does move cash.

## 6. Investor capital

**Finance → Investor Capital.** Two kinds, and which one it is
decides where it lands:

- **Equity** — bought into the business, never repaid. It is
  capital: it raises cash and raises net asset value. It is not
  income and never appears in sales. Optionally records the share
  of the business agreed.
- **Investor loan** — borrowed. It raises cash but the business
  owes it back, so it sits under liabilities and **leaves net asset
  value unchanged** on the day it arrives. Records interest rate
  and due date, and can be repaid.

Only a loan can be repaid. Returning money to an equity investor is
a drawing or a dividend, and the database says so rather than
letting the two be confused. A signed agreement can be attached to
either.

## 7. Undoing a sale: credit notes, returns and refunds

Cancelling an invoice does not undo anything by itself. The goods
are with the customer and, if they paid, so is the money. Three
documents put it right, and they are separate because the three
things happen at separate moments:

| Document | What it moves |
|---|---|
| **Credit note** | The money. What the customer owes falls; if they had paid, we now owe them. |
| **Goods return note** | The stock. Only what physically arrives goes back on the shelf. |
| **Customer refund** | The cash. Paying the customer clears the liability. |

### The one formula

Everything derives from a single per-invoice balance:

```
balance = total − paid − credited + refunded

  positive → a trade receivable  (they owe us)
  negative → a refund liability  (we owe them)
```

One expression covers every case — unpaid and credited in full,
paid and credited in full, part-paid, partial credits — and it is
shared by the daily position, the invoice list and the credit-note
page, so the three cannot disagree.

#### It is a column, not an expression anyone retypes

`invoices.balance_due` is **generated** by the database
(migration 028):

```sql
balance_due  GENERATED ALWAYS AS
    (total_amount - amount_paid - amount_credited + amount_refunded) STORED
```

It cannot be set, cannot go stale, and cannot be got wrong: every
page that selects `i.*` has the right number without joining
anything. `amount_credited` and `amount_refunded` are maintained
beside `amount_paid` by the one refresh function, so all four are
derived and none is typed.

That is not tidiness. Migration 027 fixed the invoice's *status*
and the over-payment guard, and **seven other places went on
working the balance out for themselves** as `total_amount −
amount_paid` — blind to credit notes. So an invoice with an item
returned still showed its full balance on the payments list, on the
invoice list, on the invoice detail, on **the printed invoice the
customer receives**, and in the Excel export; the payment filters
still called it outstanding; and the outstanding total still added
it up. Patching seven call sites would have left the eighth to be
written next month.

**Never write `total_amount - amount_paid`.** It is right only for
an invoice nobody has returned anything on, which is not a
condition any page can assume.

#### A payment is against the invoice, not against a line

This is the part that surprises people, and it is what makes the
formula work. Nothing in this system records *which item* a
payment was for, and nothing should.

Take the case: an invoice for two items at KES 1,000. The customer
pays 1,000, then returns one item and is credited 1,000. Which item
did the money buy — the one he kept, or the one he sent back?

It does not matter, and it must not, because the answer is a story
rather than a fact. Allocate the payment to the returned item and
the customer is owed 1,000 while still owing 1,000 for the other.
Allocate it to the kept item and everything is square. Same money,
same goods, two different answers — which is how a system starts
arguing with itself. The balance settles it without asking:

```
2,000 invoiced − 1,000 received − 1,000 credited = 0
```

Nothing is owed by anybody. The invoice is **settled**.

#### What that means for the invoice's own status

Until migration 027 two places had their own idea, and both were
wrong in exactly this case:

- **The status** came from `paid` against `total_amount`, blind to
  credit notes, so the invoice above read *Partially paid* for ever
  — chased for 1,000 the customer did not owe, and eventually
  called overdue.
- **The over-payment guard** capped receipts at `total_amount`, also
  blind to credit notes, so the same invoice would have **accepted
  another 1,000**. That is the serious one: a wrong label wastes a
  phone call, wrongly-taken money has to be given back.

Both now read the one balance, and so does a refund — which changes
it as surely as a receipt does and previously had nothing watching
it at all. All three go through `invoice_refresh_money_state()`, so
they cannot drift.

| Invoiced | Received | Credited | Status | Balance |
|---|---|---|---|---|
| 2,000 | 1,000 | 1,000 | **Paid** | 0 — the case above |
| 2,000 | 2,000 | 0 | Paid | 0 |
| 2,000 | 0 | 2,000 | **Paid** | 0 — returned in full, never paid |
| 2,000 | 1,000 | 0 | Partially paid | 1,000 |
| 2,000 | 500 | 500 | Partially paid | 1,000 |
| 2,000 | 2,000 | 1,000 | Paid | −1,000 — we owe them |

**`paid` here means settled**, which is what every other ledger
means by it, and `credit_status` says whether it was money or a
return that settled it. The invoice list shows both, because *Paid*
on its own does not distinguish an invoice that was paid from one
whose goods came back:

> `Paid` · `Part credited`  — the case above
> `Paid` · `Credited`       — returned in full, nobody paid anything

The last row is settled as a receivable but leaves a **refund
liability**: the customer paid for goods they no longer have. That
is the credit note's *Disburse refund* action, and the daily
position carries it under refunds owed until it is paid.

### The flow

1. **Cancel a delivered invoice.** The system does not pretend
   this is enough. It marks the invoice `awaiting_credit`, warns
   that the goods are already out, and — if money was received —
   names the amount that will have to go back. The dashboard grows
   a queue: *Cancelled, Awaiting Credit Note*.

2. **Raise a credit note.** Against all of the invoice, some
   lines, or as money only (a price correction, where nothing
   returns to stock). Tax is credited at the rate the invoice
   charged, so crediting everything cancels it exactly. It cannot
   credit more than was sold.

   **A full credit is worth what the invoice is worth**, not what
   its line items add back up to. Those are not the same number as
   often as you would hope: a line can carry a discount, a
   converted quote can leave the header rounded away from the sum
   of its lines, and an invoice raised for a lump sum has no lines
   at all. Rebuilding the money from the lines in any of those
   cases credits the wrong amount — too much and the database
   refuses the note, so it can never be approved and no goods
   return ever opens; too little and the invoice sits at *Part
   credited* for ever, showing a balance nobody owes. So the lines
   stay the record of which goods are coming back, and the money
   comes from the invoice. A **partial** credit is still worth its
   lines, discount included, because that is what a partial credit
   means.

3. **Approve it** — an administrator only, because this is the
   financial event. The receivable falls; where the customer had
   paid, the difference becomes **Refunds owed to customers** under
   liabilities. A goods return note opens automatically unless the
   credit was money-only.

4. **Receive the goods**, two ways:
   - *Everything, as per the credit note* — one click.
   - *Count each line* — pre-filled with the expected quantities,
     with a condition per line. **Damaged goods are credited but
     not restocked**: they are not sellable, so adding them to
     inventory would overstate it.

5. **Bill what was kept.** Goods that did not come back were not
   returned, so crediting them would be a gift. The page raises an
   invoice for exactly the shortfall, at the original prices and
   the original tax rate, linked back to the return note. Create a
   delivery note from it to record that the goods are already with
   the customer.

6. **Disburse the refund** from the credit note. Refused if it
   exceeds what is owed, or if the paying account did not hold the
   money on that date. Proof of payment attaches as with any
   receipt.

### The two documents on paper

A return happens at a counter, and both parties need something to
hold. **Credit Notes → open one** offers both, print or download:

| Document | Carries | Does not carry |
|---|---|---|
| **Credit note** | product, SKU, quantity, unit price, discount, line total, subtotal, VAT at the rate the invoice charged, total credited, and which invoice it reverses | — |
| **Goods return note** | product, SKU, expected quantity, received quantity, and what is short | **any money at all** |

That the return note has no value on it is the point, not an
omission. It is a receiving document: what it is asked to settle is
whether the right number of boxes came back. A price on it only
starts an argument at the counter about a figure nobody is settling
there — the value of the return is the credit note's business, and
the credit note is the document the customer files.

The return note is printable **before** the goods arrive as well as
after. While it is awaiting them the Received column is blank on
purpose — a column of zeros reads as *none of it came back* rather
than *not counted yet* — so it prints as a sheet to count against,
footed "Count the goods against the expected column and record what
arrived". Once received it shows what actually came and flags each
line *2.00 short* or *Complete*.

Both are also on the Credit Notes and Goods Returns lists, and both
download under their own number — `CreditNote_CN-2026-0001.pdf`,
`GoodsReturn_GRT-2026-0001.pdf`.

A **draft** credit note prints too. The customer standing at the
counter needs paper before anybody gets round to approving it, and
the status line says *Draft* so it cannot be mistaken for the
final one.

### Worked example, end to end

An invoice for KES 116,000 (10 cameras, 4 NVRs), delivered and
paid in full, then cancelled. The customer returns 8 cameras and
all 4 NVRs, keeping 2 cameras.

| Step | Effect |
|---|---|
| Cancel | `awaiting_credit`; nothing else moves |
| Credit note approved, 116,000 | receivable → 0; **116,000 owed back** |
| Goods received (12 of 14 units) | stock +68,000 at cost |
| Shortfall billed | new invoice INV‑2026‑0004 for 13,920 |
| Refund 116,000 paid | cash −116,000; liability → 0 |

Net asset value rose by exactly **81,920** across steps 3–5 —
68,000 of stock back on the shelf plus 13,920 billed for what the
customer kept. The original invoice ends at a balance of zero with
nothing owed either way, and the only thing outstanding is the new
invoice for the two cameras.

### Rules the database enforces

| Rule | Why |
|---|---|
| A credit note cannot exceed the invoice | Crediting more than was sold manufactures a refund |
| An approved credit note's lines are frozen | It is a financial fact, not a draft |
| Only an administrator approves | Approval is the money event |
| More cannot come back than went out | |
| A refund cannot exceed what is owed back | |
| A refund cannot be paid from an account without the funds | |
| A shortfall can only be billed once | |
| A line discount cannot exceed the line it is on | The discount is an amount, not a percentage — see below |

### The discount is an amount, not a percentage

Every document line stores `subtotal = quantity × unit_price −
discount` as a generated column, and `discount` is a figure in
shillings. Nothing stopped that figure being larger than the line.

A quote for one item at 1,000 with a discount of 999,999 was accepted:
the line stored a subtotal of **−998,999** while the header stored
**0.00**, because the page floors each line at zero as it adds them up
(`max(0, $qty * $price - $disc)`). The document disagreed with itself.
Convert that quote onward and the line travels with it — and since a
credit note now takes its discount from the invoice line, the nonsense
would reach the customer's refund too.

Migration **040** caps the lines already stored that way (the intent
of "discount larger than the line" is unambiguously "this line is
free") and adds a CHECK to all five line tables. The three pages that
capture a discount — quotes, sales orders, proformas — now also refuse
it in words, because a constraint violation is a poor way to learn you
typed 20 meaning 20%. A discount *equal* to the line stays legal: that
is an item given away, priced so the customer can see what it is worth.

The first of those used to fire on ordinary invoices, because a
full credit note was reconstructed from the lines with the discount
dropped. Migration **037** repairs the notes left behind by that:
drafts marked *full* are set to what is left creditable, and an
approved *full* note that is the only one on its invoice and
credits less than the invoice is set to the invoice total. Approved
**partial** notes are left exactly as they are — their value is a
figure somebody agreed with a customer, and quietly moving approved
money is worse than leaving it visible.

## 7b. Income that repeats: service contracts

Every invoice described so far is a one-off: somebody quoted a job,
fitted it, and billed for it once. That is not how a security firm
actually earns. The steady money is the **maintenance contract** and
the **monitoring subscription** — the same amount, from the same
customer, every month or quarter, for as long as the agreement runs.
The system had no idea those existed, so they were billed by somebody
remembering, and the month nobody remembered was simply lost.

**Sales → Service Contracts** (`modules/sales/contracts.php`).

### A contract is not an invoice

It is the *agreement* to invoice: who, how much, how often, from
when, and how far it has been billed. What it produces is an ordinary
invoice — it ages, it is paid, it is credited, it lands on the VAT
return like any other. Deliberately: nothing downstream should have
to know a contract was involved.

`service_contracts.next_due_date` is the contract's whole memory of
where it has got to. Everything before it has been invoiced. It is
moved by the billing run and never by hand — retyping it would either
bill a period twice or skip one silently — so the edit form does not
offer it.

### The one rule the feature rests on

**A billing run must never charge the same period twice.**

It *will* be run twice: a cron retry, a slow page somebody clicked
again, two managers on the first of the month. So the period is
written onto the invoice and a unique index makes the second attempt
impossible rather than merely unlikely:

```sql
CREATE UNIQUE INDEX ux_invoice_contract_period
    ON invoices (contract_id, period_start)
    WHERE contract_id IS NOT NULL AND status <> 'cancelled';
```

Idempotence enforced by the database, not by hoping. The run catches
that refusal and reports it as *already billed*, because it is not an
error — it is the guard doing its job. The contract row is also locked
`FOR UPDATE` for the length of the run, so two runs starting in the
same second cannot both read the same `next_due_date`.

Cancelled invoices are excluded from the index on purpose: cancelling
January's invoice because it was wrong has to leave January billable
again.

### Catching up, and stopping

A contract left unbilled for three months owes **three invoices, not
one**, so each is billed forward until it catches up — capped per
contract, so a typo in a start date cannot turn into two hundred
invoices. A contract that ends mid-period is billed to its end date
and not beyond it, then marks itself `ended` rather than staying due
for ever.

| State | Effect on the run |
|---|---|
| `active` | billed when a period falls due |
| `paused` | skipped; invoices already raised are untouched |
| `ended` | term is over, nothing further |
| `cancelled` | called off |

Verified by running the billing four times in a row and confirming the
invoice count does not move, then cancelling one generated invoice and
confirming that period becomes billable again (`t_contracts.py`).

---

## 8. What is derived, and what is captured

| Figure | Where it comes from |
|---|---|
| Cash and bank balances | Summed from the cash book |
| Trade receivables | Issued invoices less receipts, less credit notes, plus refunds — all dated |
| Staff loans | The loan accounts the module maintains |
| Customer advances | Advances less what invoices drew down |
| Investor loans / capital | The investments table |
| Refunds owed to customers | Invoices credited past what was paid, less refunds disbursed |
| Trade payables | Goods received on GRNs, less supplier payments — all dated |
| Supplier prepayments | Supplier payments past what has been received |
| **Inventory** | **Captured on Close day** |

Everything reconstructible from dated rows is computed on every
read — including for a day closed weeks ago — so it cannot drift.
Only inventory cannot be reconstructed for a past day, so that is
the one figure captured when the day is closed. Until a day is
closed, the report shows today's inventory and labels it `live`
rather than pretending it stood that way.

Trade payables used to be captured too. Since migration 020 there
is a purchase ledger to derive them from, so they are computed like
everything else; a day closed before that migration keeps the
figure that was typed, because there is nothing else to go on.

This is what the old sheet could not do. Its 29/07 opened with a
previous balance of 164,196 when 28/07 had closed at 162,996; the
1,200 spent on the 28th was recorded in the ledger and lost in the
carry-forward, and every figure from that day on was overstated.
Here the opening balance *is* the day before's closing balance.

## 9. Rules the database enforces

| Rule | Why |
|---|---|
| Sales, purchases, expenses, receipts, advances and investments must sit on a cash, bank or mobile-money account | These reconcile cash |
| A credit sale must sit on a credit account | No cash moved, so it must not reduce a till |
| An invoice cannot be paid beyond what is **collectible** — total less credits, plus refunds | Crediting goods back reduces what may be asked for; the guard used to cap at the invoice total and would take money nobody owed |
| A cancelled invoice cannot take a payment | |
| A purchase order cannot be over-paid | |
| A cancelled, rejected or draft purchase order cannot take a payment | |
| An expense with an account must have a payment date, and vice versa | The books must be able to say when the money moved |
| `amount_paid`, status and credit status follow the receipts, the credit notes **and the refunds** | None is typed, and all three come from one function, so they cannot disagree |
| An advance cannot be over-drawn, or applied to another customer | |
| A loan cannot be repaid before it is disbursed, or over-repaid | |
| Equity cannot be "repaid" | It is capital, not a debt |
| One ERP document posts to the cash book once (unique index) | Double-posting was the easiest way to corrupt the sheet |
| A transfer writes both legs or neither, and deleting one deletes both | A half-recorded transfer unbalances the book |

Trigger messages are written for the person reading them and are
passed to the screen by `db_rule_message()`. Any other database
error is logged and replaced with a generic message — a constraint
name on screen helps an attacker more than the user.

Note for future work: `PDOException extends RuntimeException`. A
handler that raises `RuntimeException` for its own messages must
catch `PDOException` **first**, or raw SQL reaches the browser.

## 10. Verified

Against live PostgreSQL, on a fresh database, with migration 016
re-run to confirm it is a no-op:

- A full receipt takes an invoice to `paid`; a part receipt to
  `partially_paid` with the balance appearing under trade
  receivables (58,000 and 10,000 of 34,800 → 24,800 outstanding).
- Over-payment, future-dating, a non-cash account, and payment
  against a draft or cancelled invoice are all refused.
- Proof of payment stored for a PNG and a PDF; a PHP file renamed
  `.png` refused on its real type.
- Reversing a receipt removes its ledger entry and recalculates the
  invoice.
- The loan lifecycle end to end, including: disburse-before-approve
  refused, repay-before-disburse refused, approving twice refused,
  disbursing from an account without the funds refused,
  over-repayment refused, settlement automatic, and total assets
  unchanged across the disbursement.
- An advance raises cash and liabilities together; applying it to
  an invoice moves no cash; applying it to another customer's
  invoice is refused; the unused balance refunds.
- Equity raises net asset value, an investor loan does not, and
  repaying equity is refused.
- Managers may request a loan but not approve one; salespeople get
  403 on every finance page and see no Record-payment button;
  signed-out visitors are redirected.
- The full reversal cycle on a paid, delivered invoice of 116,000:
  cancelling flags it and queues it on the dashboard; a manager
  cannot approve the credit note but an administrator can;
  approving credits 116,000 and leaves that owed back; receiving 12
  of 14 units puts 68,000 of stock back and flags the shortfall;
  the 2 units kept are billed at 13,920 with the original tax rate;
  and two refunds totalling 116,000 close the invoice at a balance
  of zero. Net asset value moved by exactly 81,920 — the stock
  returned plus the shortfall billed.
- Refusals covered: receiving more than went out, refunding more
  than is owed, refunding from an account without the funds,
  billing the same shortfall twice, and refunding once nothing is
  owed.
- No PHP warnings, no console errors.

## 11. Not yet wired up

- **Payroll.** Salary-deduction repayments reduce the loan but the
  matching wages cost is not recorded (see §4).
- **Posting from documents.** `cash_transactions.source_type` and
  `source_id` exist with a unique index, and invoice payments,
  advances, investments, loans, supplier payments and expenses
  all use them.

## 12. VAT: two rates, and the document remembers which

Kenya charges VAT at **16%**, the general rate, and **8%** on
petroleum products. Both are offered wherever a rate is chosen —
quotes, sales orders, proformas, purchase orders — along with **No
VAT**, which covers zero-rated and exempt supplies alike. They
differ in how they are declared on the return, not in what they add
to the document.

Every picker is built from one list, `VAT_RATES` in
`includes/functions.php`, so the day a Finance Act moves a rate it
moves in one place rather than in eight.

### The rate is stored, not inferred

Before migration 026 a document kept only `tax_amount` — the money
— and threw the rate away as soon as it had multiplied by it. With
one rate in use that was survivable. With two it is not:

- **Nothing could tell an 8% document from a 16% one afterwards.**
  The sales-order screen had to guess — *"tax above zero, so assume
  16%"* — which turned an 8% quote into a 16% order and overcharged
  the customer eight points. The purchase-order editor did the same
  in reverse, reopening an 8% order as a 16% one.
- **A VAT return declares output tax by rate.** Derived from the
  money alone that is arithmetic on a rounded figure, and it fails
  the moment a document carries a rounding difference.
- **An old invoice could not say what rate it was raised at**, which
  is the first thing anybody asks of one.

`tax_rate NUMERIC(6,4)` now sits on quotes, sales orders,
proformas, invoices, purchase orders and credit notes, and follows
the sale through every conversion — quote → proforma → invoice,
sales order → invoice, invoice → credit note.

Existing rows were **derived, not assumed**: `tax_amount` over the
base each document actually taxed. Every one came out at exactly
0.1600, because 16% is all the system had ever offered — which is
the point. The figure was read out of the data.

There is deliberately **no CHECK pinning the rate to today's 16 and
8**. Rates are set by a Finance Act, not by this schema, and a
document raised under an old rate has to stay readable after a new
one arrives. The only constraint is that a rate is a fraction
between nothing and everything.

### Output tax is ours to declare; input tax is theirs to prove

The two halves of a return are not symmetrical, and treating them
the same is what went wrong.

**Output tax** comes off our own invoices, and rightly so — we
raised them, we charged the VAT, we owe it.

**Input tax** was also being taken off our own document: the
purchase order, apportioned by the share received. But a purchase
order is a *commitment we wrote*, and its 16% is what the form
opened on. It is not evidence that any supplier billed us for VAT.
A business buying from unregistered suppliers was therefore
claiming input tax on every receipt — money never paid, on a
return, with nothing on the page to trace it to.

Migration 029 moved input tax onto the goods received note, where
the supplier's invoice is:

| | Source | Evidence |
|---|---|---|
| Output tax | `invoices.tax_amount` | our own invoice |
| Input tax | `grns.tax_amount` | the supplier's tax invoice, named on the receipt |

VAT cannot be recorded on a receipt without the supplier's invoice
number, and the rate is suggested from whether that supplier holds
a tax PIN rather than from the order. The VAT summary lists both
what it claims **and what it is not claiming**, so a figure left
out is a decision somebody can see, not an omission somebody
discovers in an audit.

## 13. Tax notes and untaxed documents

A price quoted without VAT has to say so, in words, next to the
figures. Quotes, proformas and invoices carry a **tax note** — free
text printed directly under the totals, where the figures it
describes are read.

The field offers the four sentences most often wanted (exclusive of
VAT, inclusive of VAT, VAT charged at invoicing, zero-rated) but
accepts anything, so an exemption reference or a zero-rating reason
fits just as well.

It follows the sale: a note on a proforma or a sales order is
carried onto the invoice at conversion, because the statement
belongs to the sale rather than to whichever document happened to
make it first.

**A document with no tax carries no tax row.** A printed line
reading *Tax 0.00* only invites the question of whether tax was
forgotten, so quotes, sales orders, proformas and invoices leave it
out entirely when nothing was charged — as they do a zero discount.
When tax *is* charged the row is there as before.

## 14. Currency symbols are not unique

`currencies.symbol` was declared `UNIQUE`, which is not how
currency symbols work: ¥ is both the yen and the yuan, and $ is the
US, Canadian, Australian, Singapore and Hong Kong dollar. Adding
CNY therefore failed against JPY's ¥ — and because the message said
"code or symbol", it read as though the code were taken, sending
the user searching for a duplicate that was not there.

Migration 018 drops that constraint. The **code** remains unique,
and now case-insensitively so — `cny` and `CNY` are one currency
however they are typed — and a clash names the currency holding it:

> CNY is already used by "Chinese Yuan".

## 15. The purchase side

### Two questions, two columns

A purchase order answers two independent questions, and the ledger
keeps them apart:

| Column | Question | Set by |
|---|---|---|
| `status` | What did the supplier say, and what has arrived? | A person, then the GRNs |
| `payment_status` | What have we paid? | The payments, by trigger |

An order can be received and unpaid, or paid and undelivered.
Collapsing the two into one column is how a purchase ledger stops
being able to answer either.

### The order lifecycle

```
draft ──► sent ──► accepted ──────► partially_received ──► received ──► closed
                └► rejected                                        
  (cancelled at any point before goods arrive or money is paid)
```

`partially_received` and `received` are set by the goods received
notes and are not offered as manual choices — the alternative is a
button that lets someone contradict the GRNs. Everything else is a
person's decision, recorded with who made it, when, and a note.
A rejection **requires** the note: it is the one everybody asks
about a month later.

An order that has taken delivery or a payment cannot be cancelled
or rejected. Raise a debit note for the goods, or reverse the
payment first.

### Supplier payments

`purchase_payments` mirrors `invoice_payments`: amount, date,
method, reference (the proof-of-payment code), the account the
money left, and an optional attached slip. Recording one writes a
cash-book entry in the same transaction; reversing one takes that
entry with it, so the cash book never shows money that is no longer
recorded as spent.

`purchase_orders.amount_paid` and `payment_status` follow by
trigger and are never typed. The balance still to pay is
`total_amount − amount_paid`. An order cannot be over-paid, and
editing an order's total re-decides whether it counts as paid.

### Purchases on the daily position

Two different figures, and the difference between them is the point:

| Figure | Source | Where it appears |
|---|---|---|
| **Purchases** — what was bought | GRN totals for the day | Trading |
| **Paid to suppliers** — what left the bank | Cash book | Cash movement |

The Purchases line used to read the cash book, and nothing ever
wrote a purchase into it, so it was always zero. It now comes from
the goods received notes, because stock taken on credit arrives
without a shilling moving and would otherwise never be counted at
all. It sits under Trading rather than in the cash column for the
same reason: putting it there would break the day's reconciliation,
which is the report's only self-check.

Where the two differ, the gap is stated outright as *of which not
yet paid for*.

### Buying a service

The business buys services as well as selling them: logo design,
subcontracted cabling, a hired vehicle. The supplier bills for it and
it gets paid like anything else — but nothing arrives on a shelf, so
there is no goods received note and there never will be.

Purchasing refused to admit that. Every product picker and every line
validation filtered on `goods_only_sql()`, which migration 034
introduced for the **selling** side and which had been applied to
buying too. Selling a service and buying one are the same kind of
thing; only stock says otherwise.

**The part that actually needed a column** is the money. Payables are
not what was ordered, they are what was received:

```
owed = Σ(GRN totals) − Σ(debit notes) − Σ(payments)
```

Right for goods, useless for a service whose GRN never comes. Left
alone, a service line would sit on an order for ever, owed to nobody
and unpayable.

So a service line records **the day the work was accepted**
(`purchase_order_items.accepted_at`, migration 042), and that date
does for a service exactly what a receipt date does for goods: from
then on it is owed, and it is owed *as of that date*, so an aged
payables report run for last month is not moved by work accepted this
month. A date rather than a flag, because every other money figure
here is dated and every report that reads them is.

Accepted work is counted **inclusive of tax**, because the goods side
of the same sum is a GRN's `total_amount`, which is tax-inclusive.
Leaving it out made every service under-owed by exactly its VAT —
caught by the test, which found the order still showing 7,200
outstanding after being paid in full.

| Rule | Enforced by |
|---|---|
| A service can never be received into stock | `trg_grn_items_no_services` — the mirror of `trg_dni_no_services` |
| Goods cannot be "accepted"; they are received | `trg_po_item_accepted_sanely` |
| Work cannot be accepted before the order was raised | the same trigger |
| A services-only order completes without a receipt | accepting sets `quantity_fulfilled`, so the order does not wait for goods that never come |

`po_owed_for_sql()` is the one place that says what an order is owed
for, so the daily position, the payables report and the prepayments
figure cannot drift apart on the question.

---

### Trade payables, derived

Per order, and clamped at zero on its own:

```
payable = goods received − goods returned − paid
```

You owe for what has arrived, not for what is on order — an order
placed and undelivered is a commitment, not a liability. And you
do not owe for what went back: **a debit note comes off what is
owed the moment it is raised**, because raising it is what takes
the stock off the shelf, and a debt for goods the business
demonstrably no longer holds is not a debt.

That last clause was missing until migration 033. Forty thousand
of goods arrived, eight thousand went straight back damaged, the
stock dropped that afternoon — and the daily position went on
carrying the full forty thousand as a liability. It is the
purchasing side of the sentence migration 031 wrote for sales; a
credit note cancels a customer's obligation, a debit note cancels
ours, and they are the same document pointing the other way.

**Every note that is not cancelled counts, draft included.** The
stock leaves when the note is raised, not when somebody remembers
to mark it sent, and counting only sent notes would leave a
window — often a permanent one, since nothing forces the status
on — where the goods are gone and the debt is not.

**Cancelling a debit note puts the goods back on the shelf**, with
a `return_reversal` movement in the stock ledger, so the debt and
the stock return together. A cancelled note cannot then be
revived: the goods are back, and taking them off again against
quantities that have since moved on is not something a status
dropdown should be able to do. Raise a fresh note instead.

Clamping each order separately keeps one supplier's credit balance
from cancelling another's debt; the excess is reported as
**supplier prepayments** under assets, which is what it is. Goods
sent back move that line the same way — they stop counting as
delivered, so money paid against them is once again money the
supplier is holding.

## 16. Expenses reach the cash book

An expense used to be a note in a list. It recorded that money
had gone but never which account it left, so nothing connected
it to the books: the **Operating expenses** line on the daily
position read the cash book, and no expense ever wrote to it.
The figure was always zero unless somebody typed the movement in
a second time by hand — the same hole that made Purchases read
zero before migration 020.

Recording and paying are now separate events:

| | |
|---|---|
| No account chosen | Recorded, real, not yet a cash movement. Counts in the Profit and Loss on its own date. |
| Account chosen | Also written to the cash book on the payment date, under Operating expenses. |

A bill from the landlord can be entered on the day it arrives and
settled on the Friday, and each date does its own job — the
Profit and Loss charges it to the month it covers, the cash book
to the day the money left.

The cash book follows the expense in both directions. Clearing
the account on an edit removes the entry; deleting the expense
removes it; marking it rejected removes it. There is no way to
leave a ledger entry behind for an expense that no longer exists.

## 17. When the books and the drawer disagree

### Cash: count it, do not type over it

**Finance → Cash Count.** Administrators and Managers.

The balance is never edited. You count what is actually in each
account, and the system works out the difference and posts it to
the cash book as an **adjustment**, carrying the reason. Three
things follow from doing it that way rather than overwriting a
number:

- **the cash book still adds up** — closing is still opening plus
  every movement, because the correction *is* a movement
- **the difference survives** — with a date, a person and a
  reason, instead of a figure quietly changing
- *"we were 48,000 short in August"* is a question somebody can
  still answer in December

A blank box means **not counted yet**, which is not the same as
counting zero, so a half-finished count posts only the lines that
were filled in. A line that agrees asks for nothing. A line that
differs **must** carry a reason before it will post — the browser
asks and the server insists — because money does not evaporate:

| Reason | What it actually means |
|---|---|
| Banked or moved, not recorded | The money is safe, the entry is missing |
| Spent, not recorded | A purchase or expense never got entered |
| Received, not recorded | A receipt never got entered |
| Earlier miscount or entry error | A correction, not a loss |
| Short / Over — unexplained | The one worth investigating |
| Setting a true opening balance | Starting from what is really there |

To find one afterwards, filter the cash book by **Adjustment**.

### First, check you are comparing the same thing

**Cash and bank balances** on the daily position is the total of
*every* liquid account — tills, bank accounts and mobile-money
wallets together. A till holding 29,000 against a position reading
77,000 usually means the other 48,000 is in the bank or the M-Pesa
wallet, not that anything is missing. The **Where the money sits**
column on the daily position breaks it down account by account,
and that is the figure to count against.

### Supplier prepayments: how it is worked out

**Dashboard → Paid For, Goods Not Yet In**, which opens the list;
also linked from the figure on the daily position, and reachable at
**Reports → Aged Payables**, where it sits below the ageing.

The dashboard carries four cards, because the figure alone does not
say what to do about it:

| Card | What it is for |
|---|---|
| **Paid For, Goods Not Yet In** | the money — the same figure as the daily position |
| **Orders Waiting on Goods** | how many orders it is spread across |
| **Paid, Nothing Received At All** | the ones to look at first: not one goods received note against them, which is usually a note nobody raised rather than a delivery still in transit |
| **Owed to Suppliers** | the other side, for contrast |

All of them open the same list. They are drawn only for Managers and
Administrators — the list is Manager and up, and a card somebody
cannot follow is worse than no card.

```
prepaid = paid − goods received, per order, where that is positive
```

"Goods received" is **the value of the goods received notes against
the order**, not the order's own total. Pay 100,000 against a
100,000 order and you are still prepaid 60,000 until 100,000 of
goods have been booked in.

Which is the answer to why the figure looks too big. **An order
showing "nothing received" while the stock is on your shelf means
the goods received note was never raised.** The payables report
proper cannot show you this — it joins to the GRNs, so an order
with no GRN at all appears nowhere in it — which is why this list
exists and why each row links straight to *Book the goods in*.
Raising the note moves the money from prepayments into stock, where
it belongs.

## 18. When more arrives than was ordered

A supplier puts a third item in the box against an order for two.
You take it in, and now the order says 15,000 while the receipt
says 21,000.

**You owe for what you received and kept, not for what you
ordered.** A purchase order is a commitment; the liability arises
on delivery. The payables figure has always worked that way —
`owed = received − paid` per order — so 21,000 received against
15,000 paid is **6,000 still owed**, and it is right.

What was wrong is that a purchase order had no way to represent
goods that were never ordered. The receiving screen refused the
line outright, the order's fulfilment could never complete, and the
daily position read *paid for and not received* while the stock sat
on the shelf.

### Receiving it

**Purchasing → Goods Received → Receive**, then *Item not on this
order*. Two things are now allowed, both deliberately:

| | |
|---|---|
| **More of an ordered item than was ordered** | the quantity box has no ceiling |
| **An item that is not on the order at all** | added on its own line, at the price the supplier charged |

Accepting goods you did not order means **the order changed**, so
the order is amended in the same transaction: the extra line is
added, any over-received quantity is raised to what came, and the
order's subtotal, tax and total are recomputed from its lines.
Afterwards the order, the receipt and what is owed all agree, and
the order can close.

It will not record an over-delivery **without a note**. The
business now owes money it did not plan to, and that has to be
explainable six months later.

There is no ordered price to fall back on for an unordered item, so
one must be typed. The last cost price is offered as a starting
point, not an answer — what goes on the order is what the supplier
charged.

### Fixing one that is already wrong

**Purchasing → Reconcile Receipts.** Administrators.

Lists every order whose receipts say something its own lines do
not, with what is wrong spelled out — *"1 item(s) received that
were never ordered"*, *"2 line(s) received over the quantity
ordered"*. **Reconcile** applies the same amendment after the fact.

Mostly these are records that predate the purchasing rebuild
(migration 020), which were never checked against their order at
all — the current screen would refuse to create one.

**It never touches the receipt.** What arrived is a fact; the order
is the document that was wrong about it. And it only ever raises a
quantity, never lowers one: an order part-delivered is not an order
that shrank.

---

## 19. Fixed assets

Everything the daily position called an asset was a **current**
asset — stock, cash, money owed to us. All of it turns into cash
within a year, which is what *current* means.

The desks, the laptops, the ladders, the van: owned, used to run
the business, not for sale, and worth real money. None of it
appeared anywhere, so the net asset value was understated by
whatever the business had spent equipping itself.

**Finance → Fixed Assets.** Managers and administrators.

### The term

**Fixed assets.** Under IFRS the heading is *property, plant and
equipment*; every bank, landlord and auditor in Kenya will also
answer to fixed assets, and that is what the page is called. The
opposite of current: held for **use**, not for resale.

Stock is not a fixed asset even when it is the same object. A
camera on the shelf is stock — bought to sell, valued by
[Stock Value](REPORTS.md). The same camera screwed to our own wall
is a fixed asset.

### Cost is not value

A laptop bought for 120,000 in 2022 is not worth 120,000 today, and
a register that says so makes the net asset value a slowly growing
lie. Each item is carried at

```
net book value = cost − accumulated depreciation
```

written down in a **straight line** over its working life and
floored at whatever it will be worth at the end (its residual).
Straight line because it is the method somebody can check by hand:
cost less residual, divided by the months of life, times the months
owned.

| | |
|---|---|
| 120,000 laptop, 3-year life, 18 months old | 60,000 |
| Same laptop at 36 months, and for ever after | nil — never negative |
| 950,000 van, 150,000 trade-in value, 5-year life, 30 months old | 550,000 |
| Land, any age | what it cost — never written down |

**Nothing is stored but the facts** — cost, date, life, residual.
The depreciation is computed on the way past, like every other
figure in this system, so the register is right on whatever date it
is asked about, including a past one, and cannot drift.

`fixed_asset_nbv()` lives in the database so SQL and PHP cannot
disagree about it.

### Depreciation is not a payment

It never touches the cash book. The money left when the thing was
bought; depreciation is the cost of using it up, spread over the
years that use it. Nothing is posted anywhere — it is recomputed
from the dates every time a page asks.

### Categories set a starting life, not a rule

| Category | Default life |
|---|---|
| Computers and IT | 3 years |
| Office equipment · Tools · Security · Motor vehicles | 5 years |
| Furniture and fittings | 8 years |
| Buildings and improvements | 25 years |
| **Land** | **never depreciated** |

The picker prefills from the category and every asset keeps its
own, because a laptop that lives on a workbench and one that lives
in a bag do not last the same time. Type over it and it stops
following the category.

### Disposal

Sold, scrapped or stolen: it stops counting towards assets from
that date, and **the row stays**. What the business owned last
March is a question somebody will ask, and an asset that simply
vanishes from a register is the thing an auditor asks about — which
is why a reason is required.

Any money received is recorded in the cash book separately. The
register is a record of what is owned, not a second cash book.

### On the daily position

```
current assets  (inventory + cash + receivables)
+ fixed assets  (book value)
= total assets
```

Both subtotals are shown, because they answer different questions:
*what could we turn into cash this year* and *what have we got*.
The fixed figure links to the register as at the same date.
