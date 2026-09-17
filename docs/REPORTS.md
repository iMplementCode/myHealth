# Reports

The daily position answers *what is true today*. These answer
*how have we done*, which is a different question and needs a
different shape: a start date, an end date, and totals that add
up across it.

## The rule they all follow

Nothing is stored. Every report reads the documents themselves
each time it is opened, so a correction made to last month's
invoice shows up the next time last month is looked at, and no
report can drift away from the records that produced it.

Where a figure is an approximation, the function says so in its
comment and the page says so on the screen. A report that
quietly rounds off the truth is worse than no report.

## What each one answers

| Report | Question |
|---|---|
| Profit and Loss | What did we actually make in this period? |
| Aged Receivables | Who owes us, how much, and for how long? |
| Customer Statement | What does *this* customer owe, movement by movement? |
| Aged Payables | What do we owe suppliers for goods already received? |
| Supplier Statement | What does *this* supplier's account look like, movement by movement? |
| Sales Analysis | Which products and customers did the revenue come from? |
| Stock and Reorder | What is on the shelf, what is it worth, what needs buying? |
| Stock Value | What is the stock we can actually sell worth? |
| VAT Summary | What is owed to the revenue authority for this period? |

## Definitions that matter

**Revenue** is invoiced, not received. A sale is made when the
goods go and the invoice is raised, not when the customer
eventually pays — what is still owed is a receivable and has its
own report. Credit notes are subtracted: a sale reversed is not
revenue.

```
revenue  =  invoiced ex VAT  −  credited ex VAT
```

Both sides are net of VAT, and that matters. The tax on a sale
was never the company's money — it is collected for the revenue
authority and settled on the VAT return. So it is no more revenue
when it is invoiced than it is a loss when it is credited back,
and taking a gross credit note off a net invoice total would
understate the month by the tax on every return.

**Cost of sales** values each line at the product's cost price
*as it stands today*, because no historical cost is kept against
an invoice line. While buying prices are steady this is exact.
After a price rise it restates older sales at the newer cost, so
a past month's gross margin will drift as costs are updated. Both
the Profit and Loss and the Sales Analysis say so on the page.

**Buying stock is not a cost** until the stock is sold — it is
one asset turning into another. Purchases therefore sit in their
own block on the Profit and Loss, not among the expenses, which
is why a month of heavy buying does not show as a loss.

**Expenses are dated when incurred**, not when paid. Rent
settled late still belongs to the month it covers. The cash book
records the payment separately, on the day the money left.

**Ageing runs from the due date** for receivables — an invoice on
30-day terms is not overdue until day 31 — and from the delivery
date for payables, because that is when the debt was incurred.
An order placed but not yet delivered is a commitment, not a
liability, and does not appear.

**What is owed to a supplier is net of what went back to them:**

```
owed  =  received  −  returned  −  paid
```

A debit note comes off the moment it is raised, because that is
when the stock leaves the shelf. Aged Payables shows a
**Returned** column whenever there is anything in it, and
`docs/FINANCE.md` has the full rule, including what cancelling a
debit note does.

**Input VAT is what the supplier charged**, recorded on the goods
received note against their tax invoice. Expenses are excluded
entirely — the table has no tax field, so there is nothing to
claim from, and the page says so rather than letting the figure
look complete.

It used to be apportioned off the purchase order, and that was
wrong in a way that only ever went one direction. A purchase
order is *our* document. Its VAT is what we expected to be
billed, typed on a form that opens on 16%. So a receipt from a
supplier who charges no VAT still produced input tax on the
return — a claim for money never paid, with nothing on the page
to say where it came from.

```
input tax  =  SUM(grns.tax_amount)      -- what suppliers charged
           ≠  PO VAT × share received   -- what we assumed they would
```

Three things follow, and all three are on the page:

- **VAT cannot be entered without the supplier's invoice
  number.** The receipt form refuses it, in both the receive and
  the edit modal. Input tax with no tax invoice behind it is the
  thing an audit assesses.
- **Every claimed shilling is listed**, receipt by receipt, with
  the supplier's invoice number beside it — the same treatment
  output tax already had.
- **Every unclaimed shilling is listed too**, where the order
  expected VAT and the receipt recorded none, with the supplier's
  tax PIN status shown. No PIN means no tax invoice is possible
  and the order was simply left on the default. A PIN and no
  invoice number means a real claim may be going begging, and
  one edit moves it into the claim.

The receive form suggests a rate from the supplier rather than
from the order: their PIN on file → the order's rate, no PIN →
No VAT. A suggestion only — the person holding the invoice
decides, and the server still refuses VAT with no invoice number.

### The two statements

A statement is a ledger for one party: every movement in date
order with a running balance, opening with whatever was owed
before the period started so consecutive statements join up.
There is one for each side.

| | Increases the balance | Decreases it |
|---|---|---|
| **Customer** | invoice | receipt, credit note |
| **Supplier** | goods received | debit note, payment |

Both are net of VAT on the supplier side and gross on the
customer side, for the same reason in both cases: the customer
statement is what a customer is asked to pay, and they pay the
tax; the supplier statement is worked from `grns.total_amount`,
which is the cost of the goods with the tax recorded beside it,
because input tax is claimed on the VAT return rather than
settled through the account.

**Both pages ask rather than bounce.** Arriving with no party
chosen shows a searchable picker, most recently traded with
first. The customer statement used to redirect to Aged
Receivables instead, which is why it could not be listed on the
reports hub or in the menu at all — every link to it landed
somewhere else. Both are now first-class reports with their own
menu entries.

**Same-day movements are ordered by what they are**, not by the
name of what they are: the charge first, then whatever came off
it. Sorting on the kind alphabetically put a credit note ahead of
the invoice it credits and a debit note ahead of its own receipt,
and the running balance dipped negative in the middle of a
statement that ends in the black.

**A supplier statement will not always equal Aged Payables for
the same supplier, and both are right.** The statement is a
ledger, so it nets the whole account. Aged Payables works order
by order and never lets one order's credit balance cancel
another's debt — money paid ahead of delivery is a prepayment, an
asset the supplier is holding, and it is reported as one rather
than quietly reducing what is owed. Where the two differ the
statement page says so, with the figures, rather than leaving it
to be discovered.

### Credit notes on the Sales Analysis

Money credited is money handed back, so the Sales Analysis shows
what was invoiced, what was credited and what is left, rather than
a single net figure with the reversal buried inside it. Both
tables carry **Invoiced**, **Credited** and **Revenue** columns,
and both columns appear only when there are credits in the period
— a clean month is not made to carry an empty column.

**A credit note reverses the sale it belongs to, not the period it
falls in.** It is counted in the period it was *issued*, which is
where the money left. A March invoice credited in May reduces May,
not March, and March's figures do not move after the fact.

**Only approved credit notes count.** A draft is a proposal; the
customer has not been given anything back and nothing on the
report may move because somebody started typing.

**Goods returned and money returned are different things**, and
the product table treats them differently:

| `return_scope` | Money | Quantity | Cost of sales |
|---|---|---|---|
| `partial` / `full` | comes off | comes off | comes off |
| `none` | comes off | stays | stays |

A price correction, an overcharge, a goodwill credit — `none` —
takes the money without taking the goods back. The customer still
has the item, so the quantity sold and the cost of selling it are
both unchanged, and only the revenue moves. A physical return
puts stock back on the shelf, so its cost is no longer a cost of
selling anything.

This last point had a visible consequence: before it, a full
return left the sale's cost behind with no revenue against it, and
the report showed a loss the exact size of the cost of goods that
were sitting back in the store room.

**The two tables are counted on different bases**, and will not
always tie to each other:

- **By customer** and the summary cards read invoice *headers*
  (`total_amount − tax_amount`).
- **By product** reads invoice *lines*, because that is the only
  place a product is named.

They agree whenever every invoice in the period has lines and
carries no header-level discount. An invoice raised as a header
total with no lines behind it — a service, a one-off, anything
typed straight onto the document — contributes to the cards and
to the customer table but can never appear in the product table,
because there is nothing on it to say what was sold.

### Saleable, and why Stock Value is not Stock and Reorder

Stock and Reorder is an operational page — what is low, what is
out, what to buy this week. Stock Value asks a money question, the
one a year end, a bank or an insurer asks: *what is the stock on
the shelf worth?* They read the same table and are deliberately
not the same report.

**Saleable** means **active, and carrying a selling price**:

```sql
p.is_active AND p.selling_price > 0
```

**Services are on neither list.** Installation and transport are
active and priced, so they would pass that test — but they are
not stock, and a valuation that listed "Installation" under
"counted but not valued" would be answering a question nobody
asked. `product_type = 'goods'` is applied outside the saleable
test, so a service is absent from the valuation *and* from the
excluded list, rather than being explained away on one of them.
Same for Stock and Reorder: a service holds no stock, so every
one of them would report as out of stock and bury the products
that genuinely need buying. See `docs/INVENTORY.md`.

There is no `is_saleable` column, and inventing one would only
give somebody a second place to forget to tick. A price is the
better test, because it is what the business already maintains
for the things it sells. A line with no selling price is not
something the business sells — a consumable, a fitting used up on
a job, a product set up and never priced — and counting it inflates
a figure that is supposed to answer *if we sold what is on the
shelf, what would it come to*.

**Anything excluded is still counted and listed**, at the foot of
the page, with the reason against each line and a total at cost. A
valuation that quietly omits stock is worse than one that says what
it omitted, and the excluded list is usually a to-do list: the
products on it are nearly always ones that should have been priced.

**Two values, and the gap between them.** *At cost* is what the
business paid — the balance-sheet figure, and the one the daily
position carries. *At retail* is what it would fetch at today's
prices. The difference is **margin not yet earned**. It is not
profit, nothing has been sold, and the page says so rather than
leaving somebody to add it to one.

**It reconciles to the daily position on the page.** That page
values every *active* product, priced or not, so:

```
saleable at cost  +  active but unpriced  =  daily position inventory
```

Both sides are shown, with the arithmetic spelled out, and stock
held against *inactive* products is called out separately because
it appears in neither figure — stock on a shelf that no report is
counting is worth a look.

**One scope control drives both panels.** *Items holding stock*
(the default) or *everything saleable, including zeroes* — the
second is for a stocktake, where the zeroes matter. The switch is
a SQL predicate shared by the product query and the category
summary (`REPORT_HELD_SQL`); when only one of them applied it, the
summary card read 5 lines while the category panel below it read
18 for the same 307 units. Two panels on one page under one
control must take that control, or the page is arguing with itself.

## They cannot disagree with the daily position

Each report uses the same arithmetic over the same rows as the
figure it corresponds to, and this is checked:

| Report figure | Equals |
|---|---|
| Aged Receivables total | Daily position → trade receivables |
| Aged Payables total | Daily position → trade payables |
| Stock at cost | Daily position → inventory |
| Stock Value saleable + active-but-unpriced | Daily position → inventory |
| Statement closing balance | That customer's share of receivables |

A customer statement and the aged receivables report are two
views of one number, so a debt chased from one can be reconciled
against the other without arithmetic.

### What a statement's Reference column says

A statement is read by somebody asking one question of every line:
**what was that?** So a payment leads with the document it settled,
and the instrument follows:

```
INV-2026-0007 · Cheque 004512
INV-2026-0003 · Cheque
CN-2026-0001 · Bank transfer FT88231
```

A receipt used to print its own `reference` field and fall back to
the invoice number — except the fallback was `COALESCE`, and the
form posts an **empty string** when the box is left blank, which
`COALESCE` treats as a value. So a payment entered without a cheque
number printed a blank reference, and one entered with a stray note
in that box printed the note instead of anything that identifies
the payment. Neither told the customer what the money was applied
to, which is the whole job of the column.

The invoice number now always leads and cannot be blank; the
instrument is named from `PAYMENT_METHODS`, so a statement cannot
start calling a cheque something the rest of the system does not.
Refunds follow the same rule, leading with the credit note.

## One trap worth recording

A statement must apply the same filter to receipts as it does to
invoices. Counting a payment made against a cancelled invoice,
while leaving the invoice itself out, credits the customer with
money they never had — the statement then disagrees with the
receivables report by exactly the cancelled amount. Both filter
on `status NOT IN ('draft', 'cancelled')`.

## Payment terms and what "overdue" means

Aged receivables ages an invoice from its **due date**, falling back
to the issue date when there is none. That fallback used to matter a
great deal, because two of the three ways an invoice can be raised
never set a due date:

| Route | What it did |
|---|---|
| Sales order → invoice | set it: issue + 14 days |
| **Proforma → invoice** | passed the proforma's **expiry date** — the last day the quoted price stands, which is not the day payment is due, and NULL whenever the proforma had no expiry |
| **Goods not returned → invoice** | set nothing at all |

So most invoices had no due date and were aged from the issue date,
which made them overdue the morning after they were raised. On a day
when everything happened to be invoiced, the overdue figure read
**zero** instead — which is the more confusing failure, because a
business owed thousands sees a report saying nothing is late.

All three routes now call `invoice_due_date()`, which reads
`INVOICE_PAYMENT_TERMS_DAYS` (`.env`, default **14**). Migration 023
gives the same terms to invoices already in the books.

Change the default and new invoices follow; invoices already raised
keep the date they were given, which is right — terms agreed with a
customer do not change retroactively.

### The hub card

*All Reports* shows the total owed as its headline, with the overdue
share underneath. It used to show the overdue figure as the headline,
so a day with nothing yet due read `KES 0.00` above a business owed
thousands and looked like a fault. Aged Payables beside it has always
shown its total, so the two now read the same way.
