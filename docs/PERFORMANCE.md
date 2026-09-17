# What it does under load, and how that was found out

Everything below was measured against a database seeded to four
years of trading — 20,000 customers, 5,000 products, 120,000
invoices, 300,000 invoice lines, 400,000 audit rows — served through
nginx and PHP-FPM, because the built-in server is single-process and
would have turned every measurement into a queue length.

Nothing here was reasoned about and then written down. Several of
the things that looked obviously true turned out to be false when
timed, and those are the entries worth reading.

---

## 1. Where it started

| page | before | after |
|---|---:|---:|
| cash book | **37 MB** | 118 KB |
| financial position | 1048ms | 70ms |
| dashboard | 896ms | 129ms |
| reports hub | 780ms | 179ms |
| receivables | 683ms | 282ms |
| credit notes | 680ms | 115ms |
| invoice list | 527ms | 117ms |
| products list | 121ms | 42ms |
| customers list | 107ms | 29ms |

And with twenty-four people using it at once, each clicking every
three to seven seconds: **p95 1073ms → 606ms**, median 125ms, every
request answered.

---

## 2. The application was not slow. It was enormous.

Ten pages sent a `<select>` containing an entire table. The cash
book's invoice picker held 80,238 options and the page weighed
**37 MB** — on the connection a Nairobi office actually has, that is
a page that never arrives. The customer picker was 20,020 options
and 5 MB, on seven different pages.

The design was right for the size it was written at. The comment in
`app.js` says *"a picker with two hundred customers in it is a
scroll, not a choice"*, and at two hundred, rendering them all and
filtering in the browser is the better trade — no round trip, works
offline, nothing to wait for. It stops being the better trade
somewhere around a thousand.

`includes/lookup.php` now defines each list once, `public/lookup.php`
answers searches against it, and a picker sends forty rows and asks
for the rest as somebody types.

**The part that needed care** was not the speed, it was what a
bounded list breaks. Editing a two-year-old quote hands the
`<select>` a `customer_id` it no longer holds; the field comes up
blank and saving moves the document to whoever is first in the list.
`App.ensureOption()` puts it back from a label the server sends
alongside. A receipt posted against an invoice since paid in full
must still name that invoice, so `keep` ids are fetched *without*
the list's own WHERE. Both are covered by `t_pickers.py`.

---

## 3. A notification per overdue invoice is a report, not an alert

78,310 open notifications. The bell sorted all of them on every page
load — **82ms added to every single request in the application** —
for rows nobody has ever scrolled to.

Each rule is capped at 25 now. The worst are named individually and
the rest become one row: *"78,275 invoices are overdue, KES 21.7M
outstanding — the aged receivables report lists them."*

The cap also uncovered a bug that had been running in the dark. The
resolve step named every surviving key in a `NOT IN` list; with
78,310 of them that is more placeholders than PostgreSQL's protocol
allows, so the statement threw, the rule's own try/catch swallowed
it, and **nothing of that kind ever resolved itself** — paying an
invoice left its alert standing forever. One line in a log, a page
that rendered fine, and a feature that had quietly stopped working.
Resolution is by timestamp now: one parameter whether a rule returns
two rows or two million.

---

## 4. One question at a time

`dashboard_summary()` asked twenty-six separate aggregates, nine of
them of `invoices` — nine sequential scans of the same 120,000 rows
to produce nine numbers. Grouped by the table they read, with
`FILTER` splitting each pass into its parts.

**But not all of them.** Today's, this week's and this month's sales
are deliberately left out of that grouping: with an index they read
only the period (0.7ms against 24ms), and folding them into the
whole-table scan would have thrown that away. Grouping queries is
not always the cheaper shape; it is cheaper when the alternative is
scanning the same rows twice.

Two more of the same family:

- `reports/receivables.php` read all 80,241 unpaid invoices into PHP
  and added the ageing buckets up in a `foreach`; the reports hub did
  the same for **one headline figure**. Both aggregate in SQL now.
- `credit_notes/index.php` called `invoice_creditable_lines()` once
  per invoice, two hundred times.

---

## 5. Eight indexes tried, two kept

An index is not free: every INSERT and UPDATE maintains it, for the
life of the system, whether or not any query uses it. So each one was
built against the seeded database and timed either side.

**Kept:**

| index | effect |
|---|---|
| `invoices (issue_date)` | month's sales 24.2ms → 0.7ms; a 30-day report 15.2ms → 3.7ms |
| `invoice_items (invoice_id) WHERE quantity - delivered - credited > 0.0005` | with the LATERAL rewrite below, 84ms → 0.8ms |

**Rejected, which is the more useful half of the record:**

| index | why not |
|---|---|
| `invoices (delivery_status)` | 18.6ms → 18.4ms. 40,035 of 120,105 rows match, so a sequential scan **is** the right plan — the planner said so by ignoring it |
| `audit_logs (action)` | 49.6ms → 38.2ms on a filter dropdown, paid for on every audited action in the application |
| `audit_logs (entity)` | not used at all; still a scan |
| `quote_items (quote_id)` | redundant — `ux_quote_items_product` is `(quote_id, product_id)` |
| `user_roles (role_id)` | nothing filters on it |

The 49 unindexed foreign keys elsewhere in the schema were left alone
on the same reasoning: an unindexed foreign key is only a problem
when something queries or deletes by it, and nothing measured here
does.

---

## 6. Three things that looked obviously right and were not

**`invoices.balance_due` instead of the three aggregate joins.** It
is a generated column holding exactly the receivables arithmetic and
it measures four times faster — 108ms against 26ms. It also gives a
**different answer**, by 59 million shillings, because `balance_due`
counts every payment ever recorded while the report counts payments
dated on or before the date asked for. A post-dated cheque is money
still owed today. A receivables report that cannot be run as at the
end of last month is not a receivables report.

**`EXISTS` for "is anything still to deliver".** PostgreSQL plans a
correlated `EXISTS` before it knows a `LIMIT 10` is coming, decides
hashing the subquery beats looping, and sequentially scans all
300,250 invoice lines to draw ten rows on a screen. Written as a
`LATERAL` — which says *loop* — it probes ten times:

```
EXISTS, no index        166ms
EXISTS, partial index    84ms
LATERAL, no index        54ms
LATERAL, partial index  0.8ms
```

**More PHP-FPM workers.** Raising `pm.max_children` from 5 to 30
moved p95 from 932ms to 838ms — almost nothing. The workers were not
the bottleneck. (Sizing still matters on a bigger machine; see
DEPLOYMENT.md §10.)

---

## 7. A cache that made the tail worse

`includes/cache.php` had three layers, a documented policy on what
may be cached, and had never been called from anywhere. Wiring it up
to the whole-history figures helped — and then made p95 *worse* at
the one moment it mattered.

`app_cache` had one clock. The instant a value expired, every request
that wanted it computed it: twenty-four people all running the same
400ms query at once, on four cores. That is a stampede, and it is
worst exactly when the most people are using the system.

Two clocks now. Past `fresh_until` a value is stale but still served,
and exactly one caller wins the right to recompute — an atomic
`UPDATE` that pushes `fresh_until` forward, the same trick
`notifications_scan_if_due()` uses. One statement, one winner,
nothing left locked if the process dies. **Nobody ever waits for a
refresh.**

### What is cached, and what deliberately is not

Cached, 60 seconds: profit since the beginning, best-selling
products, trade receivables and payables, the ageing summary. These
answer *"what has this business made"* and move by a rounding error
when one more sale lands.

Never cached: today's sales, this week's, this month's; every list;
every document. These answer *"did the sale I just recorded go in"*,
which is the question somebody refreshes a page for.

---

## 8. An office is one address

`limit_req_zone` keys on `$binary_remote_addr`, and a Kenyan business
runs on one connection behind one NAT gateway — so the whole building
arrives as one visitor.

Counted with a real browser rather than guessed: **a page load is
five requests on a cold cache** (two PHP, three static) and one once
the browser has the assets. Twenty people clicking every five seconds
is about 20 requests a second cold.

Against the old `rate=20r/s` that is an office sitting exactly on its
limit on the first morning after a deployment. Three of those five
requests were a stylesheet or an icon nginx answers off disk without
waking PHP, each spending the same allowance as the page itself — so
most of the limit was being used by the cheapest thing the server
does. Static files have their own lane now, the general rate is
doubled, and `limit_conn 25` (twenty-five requests in flight from the
entire office) is 300.

---

## 9. How to measure it again

The suites live outside the repository, in the scratchpad, because
they need a seeded database and a running nginx. What each one is
for:

| script | question |
|---|---|
| `seed_big.sql` | four years of trading, re-runnable, every foreign key picked from ids that exist |
| `t_load.py` | how long is each page, one at a time |
| `t_pagesize.py` | how big is each page, and which `<select>` is responsible |
| `t_concurrency.py` | what happens with N people on it, each with their own session |
| `t_agree.php` | does the SQL aggregation still agree with the PHP it replaced |
| `t_pickers.py` | do the bounded pickers still find and still hold |

Three rules that earned their place while writing them:

**A test that cannot fail is worse than no test.** `t_pagesize.py`
once reported *"every page under 250K"* because the session had
expired and every page was an empty redirect. It now fails loudly on
anything that does not look like the page it asked for.

**Measure the limiter or the application, not both.** The first
version of `t_concurrency.py` fired every request at once, reported
that 24 users could not use the system at all, and was measuring
nginx refusing 320 page requests a second. That is not an office; it
is a flood, and being refused is the limiter working. It models think
time now, and signs in through the built-in server so the sign-in
lane's ten-a-minute limit does not leave a room full of signed-out
people.

**Check the harness's own floor before believing a number.** At 24
threads the test's own overhead is 28ms median, 56ms p95. That is
what makes an 800ms p95 a finding rather than an artefact.

---

## 10. Two people saving at once

Everything above is about one request being cheaper. This one is about
two requests being correct.

Every document number in the system — invoices, quotes, sales orders,
purchase orders, GRNs, delivery notes, credit notes, debit notes,
refunds, proformas, return notes, warranty notes, contracts, stock
counts, investments, loans, advances: seventeen columns, all of them
`UNIQUE` — came from one function, and that function read
`MAX(number) + 1` and let go of the table:

```sql
SELECT MAX(CAST(SUBSTRING(invoice_number FROM '\d+$') AS INTEGER))
  FROM invoices WHERE invoice_number LIKE 'INV-2026-%'
```

Nothing stands between that read and the `INSERT` that uses it. Two
transactions read 41, both build `INV-2026-0042`, and the unique
constraint lets exactly one of them through. Measured, with workers
released at the same microsecond:

| Concurrent saves | Lost |
|---|---|
| 2, over 8 rounds | 8 of 16 — one every round |
| 12, over 5 rounds | 29 of 60 |
| 24, over 4 rounds | (after the fix) 0 of 96 |

Two is not a stress test. Two is a salesperson and the accounts clerk,
and it lost one of them every single time. What the loser saw was an
error page where their invoice used to be.

The same run through the real stack — nginx, php-fpm, and the order
intake endpoint that a storefront posts to — put ten simultaneous
orders through and lost five of them. After the fix, ten of ten.
(Twenty-five is refused by nginx's `erp_api` lane at burst 20, which
is the limiter working, not the application failing. It is worth
saying because the first version of this measurement did not notice
the difference.)

### The number comes from a locked row now

`document_sequences` holds one row per document type per year, and the
number is produced by incrementing it. Postgres holds that row for the
rest of the transaction, so the second caller does not read a stale
maximum — it waits a moment and is handed the next number.

Not a Postgres `SEQUENCE`, which would never block and never collide,
because `nextval()` does not roll back: a save abandoned after
allocating would burn `INV-2026-0042` permanently, and a hole in the
invoice numbers is a question somebody has to answer to an auditor. A
locked row rolls back with everything else.

### And it is faster, which was not the point

The old read was the most expensive statement in a document save. On
120,127 invoices:

```
Index Only Scan using invoices_invoice_number_key  (actual time=0.037..10.359)
  Filter: invoice_number LIKE 'INV-2026-%'
  Rows Removed by Filter: 120116        Buffers: shared hit=2967
```

Ten milliseconds and 2,967 buffers to find eleven rows, on every
single save — 241ms on a cold cache. The replacement is an indexed
update of one row plus one probe of a unique index: **0.4ms**, and
unlike the scan it does not get slower every year the business trades.

## 11. What is still true

- Per-request cost is what determines how many people a box holds.
  On four cores this handles two dozen concurrent users with a
  sub-second p95, and the work above cut per-request cost by roughly
  five times — which is the same thing as five times the users.
- `reports/stock_value.php` is 257 KB, marginally over the page
  budget. It is a report, and it is a table, not a picker.
- The receivables and cash book pages are the most expensive left, at
  roughly 280ms. Both read a genuine ledger; neither is fetching
  anything it does not display.
- Uploads are still local disk. Before a second node they need shared
  or object storage — see SECURITY.md §10.
