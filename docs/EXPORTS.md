# Exports

Every list and report in the system downloads two ways: **Excel**
and **PDF**. Both are built from one definition, so a column added
to one appears in the other or in neither.

They answer different questions. A spreadsheet is for working with
— sorting, filtering, adding a column of your own. A PDF is for
sending to somebody who will not edit it: a bank, an auditor, a
landlord asking what the business turned over. Neither is a
substitute for the other, which is why both are offered everywhere
rather than one being chosen for you.

## A real workbook, not a renamed CSV

`includes/export.php` writes a genuine Excel file — a zip of XML
parts, which is all an `.xlsx` is. No new dependency: PHP already
has `ZipArchive`, and the parts a workbook needs are few enough to
write by hand.

The distinction matters. A CSV of `KES 12,000.00` is text. Excel
cannot add it up, cannot sort it as a number, and cannot filter a
date range. Here every cell carries its type:

| Declared type | In Excel |
|---|---|
| `date` | A real date, formatted `dd mmm yyyy` |
| `money` / `number` | A number, formatted `#,##0.00` |
| `integer` | A number, formatted `#,##0` |
| `percent` | A number **already in percent points** |
| `text` | Text |

### `percent` is percent points, not a fraction

A seven per cent margin arrives as `7.0`, not `0.07`, because that
is what reads correctly under a column headed "Margin %" and what
the report has always shown on screen. Excel's own percent format
is deliberately not used — it would multiply by a hundred again.
Anything formatting one of these must not either: the PDF once did,
and turned a 7% margin into 700%.

A percent column is also **never summed**, whether or not it asks
(`XLSX_TOTAL_TYPES`). Adding 7% to 22% gives 29%, which is not the
margin on anything.

It can still have a total — just not that one. The overall margin
is a real and wanted figure; it is total margin over total revenue,
so the column says where to get it from:

```php
['label' => 'Margin %', 'key' => 'pc', 'type' => 'percent',
 'total' => ['of' => 'margin', 'over' => 'revenue', 'scale' => 100]],
```

Both writers produce it, and neither by summing: the workbook as
`=IF(D11=0,"",F11/D11*100)` over the two total cells, so it stays
right when a row is deleted, and the PDF as the figure itself. Any
ratio of two totals works the same way — a fill rate, an effective
VAT rate — and the operand columns are added up even when they keep
their own totals off the page.

## When a format cannot be produced

Each writer depends on something that is not part of PHP itself,
and when it is absent the failure is a **fatal error** — which a
production server, with `display_errors` off, serves as a blank
page. The button simply appears not to work.

| Format | Needs | Symptom when missing |
|---|---|---|
| Excel | `ext-zip` (an `.xlsx` **is** a zip) | Excel does nothing, PDF still works |
| PDF | `vendor/` (dompdf), `ext-dom`, `ext-mbstring` | PDF does nothing, Excel still works |

`export_missing_requirement()` checks before either writer starts,
and the request is turned back to the page it came from with the
missing piece named and the command to install it. `php
deploy/check-db.php` reports the same two lines under **Downloads**,
for diagnosing it from a terminal.

That one format failing while the other works is the giveaway. It
is a server that is missing an extension, not a broken report.

The products page already had an export labelled "Excel" that wrote
a CSV. Its stock and price columns arrived as text and would not
sum. That link still works and now produces a real workbook.

## What every file carries

- **A title**, so a printed sheet says what it is
- **The filters that produced it**, spelled out — a spreadsheet gets
  mailed on and argued over, and six months later nobody remembers
  which filters were set
- **Who produced it and when**, because that is the first question
  asked of a figure somebody disputes
- **A frozen header row and filter dropdowns**, so a long export is
  usable the moment it opens
- **Column widths** sized to the data, not the header — amounts
  showing as `####` is the first thing anyone notices about a bad
  export
- **A totals row written as `=SUM()`**, not a computed figure, so it
  stays right when a row is deleted in Excel

## The same sheets, as a PDF

`?export=pdf` reads the identical `$sheets` structure and renders it
through dompdf. Nothing is defined twice.

What the PDF adds over the workbook:

- **The company letterhead** — name in the brand colour, location,
  mobile, email, and the date it was produced
- **Landscape** the moment a table passes seven columns, because a
  report nobody can read is not a report
- **Repeating column headings** on every page, so page four still
  says what its columns are
- **Page numbers**, `Page 2 of 5`
- **Totals computed here**, not written as `=SUM()` — a PDF has no
  spreadsheet underneath it to recalculate. They are the same
  figures the workbook's formulas produce, and a test asserts that
  on every sheet in the system.

Figures never break across two lines. On a cramped sheet dompdf once
put `KES -` above `4,000.00`, which reads as a different number
entirely; numeric cells are `white-space: nowrap` for that reason.

## One header, on every document

Invoices, proformas, credit notes, goods returns, delivery notes,
quotes, sales orders, purchase orders, goods received notes and
debit notes — ten documents, and every one of them opens with the
same header:

```
  [logo]                          DOCUMENT TITLE
  Company Name                    Number:      INV-2026-0007
  Street, town                    Issue date:  16 Aug 2026
  Email: …                        Due date:    30 Aug 2026
  Mobile: …
```

**The right half mirrors the left.** A title, then details beneath
it, everything sharing one left edge. Labels in the first column,
values in the second, and the meta table is `width: auto` so it
shrinks to its own content and the two columns sit together.

**And the pair is pushed against the right margin.** The title and
the meta table are wrapped in a `width: auto; margin-left: auto`
table, so the block shrinks to whichever of the two is wider and
that edge lands on the same margin the items table below uses.
Left-aligned inside the wrapper, right-aligned as a unit: the
title stays over its own details, and the header uses the width of
the page instead of stopping halfway across it.

The mistake worth not repeating: stretching that table to the full
width of the header cell, labels pinned left and values pinned
right. It does line the values up on the page margin — but it
opens a hand-span of white between `Number:` and the number, and a
label separated from its value by half the header stops reading as
one fact. The company block on the left never had that problem,
which is the whole argument for making the right block match it.

**Most of it now lives in one file.** `includes/document_pdf.php`
holds two renderers — the general one and the delivery note — and
eight of the ten documents go through them. Only `print_quote.php`
and `print_sales_order.php` still carry their own copy of the CSS.

The three purchasing documents used to as well, and that duplication
cost more than a stylesheet: each opened its own PDO connection from
`$_ENV` and ran its queries with no error handling, so one renamed
column (`suppliers.address` → `location`, migration 036) made every
purchasing document answer a blank HTTP 500 at once. They are on the
shared renderer now, and the two survivors are the remaining work. `t_pdfalign.py` measures all ten rendered PDFs —
title and labels on one x, values on one x, no more than 60pt
between the widest label and the value column, and no more than
12pt between the block and the right margin. It names the ten
documents it expects rather than measuring whatever it finds: a
stale login jar once left one PDF on disk and nine HTML error
pages, and the run passed off that one file.

The two families still differ in one respect, deliberately: the
`document_pdf.php` documents have a single full-width info block
under the header, while the two remaining legacy pages put two info
columns side by side, each with its own heading and rule. The test
knows which is which rather than forcing them together.

### Who the document is addressed to

**The company leads; the contact follows it as `Attn:`.** Mitchelle
did not buy the cameras — Multline Autosystems did, Multline is on
the LPO, Multline pays the invoice, and Multline is who the debt is
chased from. So the bold line under *Billed to* is the company
name, and the person is a line beneath it. A walk-in with no
company still shows as a person, and no `Attn:` line is printed
under their own name.

`customer_name_sql($alias)` is the SQL form and
`customer_display_name($row)` / `customer_contact_name($row)` the
PHP one, in `includes/functions.php`. **Use them rather than
writing the choice out again** — forty-seven hand-written copies of
it are how the invoice and the delivery note for one sale ended up
disagreeing about whose sale it was.

`customer_contact_name()` needs `company_name` on the row as well
as the name columns. Three documents selected the names but not the
company, which made the person look like the whole identity and
dropped the contact off the page silently; the helper now treats a
*missing* key as "cannot tell" and shows the contact, so the same
mistake shows up as a duplicate line rather than a vanished one.
`t_billto.py` renders one of each of the seven customer-facing
documents for a company with a named contact and reads the order
off the PDF.

### The letterhead

The company block on every document comes from **Settings → Company
Details**, through `includes/company.php`. Two things there were
broken in ways that looked like nothing was happening:

**The logo.** The settings page defined its own `UPLOAD_DIR` as
`__DIR__ . '/assets/img/'` — which resolves to
`public/modules/settings/assets/img/`, a folder nothing serves and
nothing reads — and re-defined `UPLOAD_URL`, which `bootstrap.php`
had already set. PHP keeps the first definition and warns, so the
stored path came out as `/uploadscompany_logo.jpg`, the two halves
glued together. The upload reported success every time and the
documents kept the old logo.

Fixed at both ends: uploads go to `public/uploads/company/` under a
timestamped filename (replacing one fixed name is how a changed logo
looks unchanged in a browser cache), and `company_logo_fs_path()`
finds the file by **looking for it** rather than trusting the
recorded path — including in the old stranded folder, so a logo
already lost starts working without anyone moving a file.

**The emails.** `company_settings.email` held one address, and a
business has several: sales@ for quotes, accounts@ for invoices,
info@ on the website. Printing one of them on everything sends a
payment query to whoever wrote the quote. `extra_emails` (migration
041) holds the rest, one per line; `company_emails()` returns the
whole list, main address first, dropping anything that is not an
address and any duplicate. Both document renderers print the list.

**Two documents were not asking.** Fixing the helper fixed the
documents that used it, and quotes and sales orders did not. Each
resolved the logo itself, with

```php
$projectRoot . $settings['logo_path']    // no separator between them
```

— `/…/public` glued onto `uploads/company/logo.png`, giving a path
that never exists. `file_exists()` said no, the ternary fell back to
the logo that ships with the software, and the quote printed it.
Nothing failed: a silent fallback is indistinguishable from success,
which is why this outlived two rounds of fixing "the logo".

So the letterhead is now built in exactly one place —
`company_letterhead()` returns the logo `<img>`, the company name and
the contact block, ready to drop into a document — and all four
renderers call it. `company_logo_img_tag()` holds the size, because
that had been written out five times.

The guard against a sixth copy is `t_logodocs.py`. It uploads a logo
of a size nothing else in the tree uses, renders all ten documents,
and reads the image back out of each PDF: a document carrying any
other image is carrying the wrong logo. It also asserts the printed
height, so a logo can no longer be *present* and unreadably small.

**The size.** `LOGO_MAX_HEIGHT_PX` / `LOGO_MAX_WIDTH_PX` in
`includes/company.php`, 100×300 CSS pixels — dompdf renders a CSS
pixel as 0.75pt, so that is 75×225pt, about 26mm tall on A4. Both
bounds are set on purpose: whichever one the uploaded shape meets
first holds it, so a tall crest is held by the height and a wide
banner by the width, and neither can grow sideways into the document
title sharing its row.

**The colour.** Quotes drew the title in amber and sales orders in
green, both hardcoded, while every other document used
`BRAND_COLOR`. A customer who gets a quote and then an invoice was
looking at two different companies. All three now read the constant.

---

### The size limit

dompdf lays out every cell itself and the cost climbs faster than
the row count — 500 rows in under four seconds, 2,000 in
twenty-five, 5,000 never finishing. So a PDF is capped at
**`PDF_MAX_ROWS` (1,200)** rows across all its sheets. Over that,
the request is turned back to the page it came from with a message
pointing at the Excel export, which has no such limit.

It refuses rather than truncating on purpose. A report whose Total
does not add up its own visible rows is worse than no report,
because somebody will quote the figure.

## The export is what you are looking at

Each page handles `?export=xlsx` and `?export=pdf` itself, reusing
the `WHERE` it has already built. The download therefore matches the
filters exactly — and drops the page limit, because an export of
page 3 alone is not what anybody means by "export".

`export_url()` strips `page`, `c_page`, `po_page` and `per_page` for
that reason.

## Adding one to a new page

Immediately after the `WHERE` is built and before any HTML:

```php
if (wants_export()) {
    export_deliver(export_filename('things'), [[
        'name'    => 'Things',
        'title'   => 'Things',
        'meta'    => export_meta(['Search' => $search]),
        'columns' => [
            ['label' => 'Date',   'key' => 'when',   'type' => 'date'],
            ['label' => 'Amount', 'key' => 'amount', 'type' => 'money'],
        ],
        'rows'    => db_all("SELECT ... $whereSql ORDER BY ...", $params),
        'total'   => true,
    ]]);
}
```

`export_deliver()` picks the writer from `?export=`, so a page never
chooses a format itself and a new format is one change in
`includes/export.php` rather than one per page.

Then `<?= export_button('path/to/page.php', $baseQuery) ?>` in the
toolbar — it emits both buttons. A column may compute its value:

```php
['label' => 'Balance', 'key' => 'bal', 'type' => 'money',
 'value' => fn($r) => (float) $r['total'] - (float) $r['paid']],
```

and opt out of the total with `'total' => false` — a column of unit
prices or day counts has no meaningful sum.

## Multiple sheets

Pass more than one sheet where a second view answers the obvious
follow-up question. Expenses ships a **By category** sheet, aged
receivables a **By customer** and a **By invoice**, sales analysis
**By product** and **By customer**.

## Where they are

| Page | Sheets (both formats) |
|---|---|
| Expenses | Expenses, By category |
| Invoices | Invoices |
| Invoice Payments | Receipts |
| Purchase Orders | Purchase orders |
| Supplier Payments | Payments |
| Goods Received | Goods received |
| Cash Book | Cash book |
| Products | Products |
| Stock Count | Count sheet |
| Customers · Suppliers | one each |
| Profit and Loss | Profit and loss |
| Aged Receivables | By customer, By invoice |
| Aged Payables | By supplier, By order |
| Sales Analysis | By product, By customer |
| Stock and Reorder | Stock |
| VAT Summary | Summary, Invoices with VAT |
| Customer Statement | Statement |

## One trap

Both writers discard any output buffered before them. A stray notice
printed ahead of the zip or the PDF would corrupt the file, and the
browser would offer a download that cannot be opened — the same
failure that once made three PDFs unopenable. This is also why the
export block must sit **before any HTML**.

## The cash book leaves out its running balance

That column is the balance of the whole book. In a filtered slice it
would be a number that means nothing, so the export gives the
movements and their totals instead.
