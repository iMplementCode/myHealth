# Notes

Two different things share the word "note" in this system, and they
are worth telling apart before anything else.

**A note** is a piece of writing kept in its own right — a site
survey, what was agreed in a meeting, the thing somebody keeps
re-explaining. It lives under **Notes** in the sidebar, it has a
title and a body, and it can be printed on the company letterhead.

**A line note** is one sentence attached to one line of a quote. It
is not a document; it prints under the item it belongs to.

---

## 1. Notes

### What a note holds

| Field | Why |
|---|---|
| Title | What it is about. Required — a note nobody can find again is lost. |
| Body | The note itself. Line breaks are kept as typed. |
| Category | General, Meeting, Site survey, Installation, Customer, Supplier, Internal. For filtering. |
| Pinned | The handful that should stay at the top of the list. |
| Visibility | Private or Shared. |

### Who can see what

- **Shared** — anybody signed in.
- **Private** — the author, and administrators.
- Editing and deleting — the author, or an administrator.

"Private" means the author and an administrator, and the form says so
in those words rather than just "Private". An administrator can read
the database directly, so a checkbox promising more than that would be
lying to the person who ticked it.

A note that is not yours and a note that does not exist give the same
answer — "that note could not be found" — on the page, on the print
endpoint and on save. Otherwise the id becomes a way of counting how
many notes other people have.

All of that lives in `includes/notes.php`, in `note_visible_sql()` and
`note_may_edit()`. The pages ask; they do not decide. This is
deliberate: the last authorisation bug in this system came from two
pieces of code answering the same question differently, and a rule
written once cannot disagree with itself.

### Printing

`notes/pdf.php` streams the note through `stream_note_pdf()`, which
lays it out as a memo: letterhead, reference (`NOTE-00021`), date,
category, author, then the body as typed.

The letterhead comes from `company_letterhead()` — the same call every
other printed document makes. That is why the logo is right: a note
cannot print an old logo, or none, because it does not know where the
logo comes from. It asks the one function that does.

`?dl=1` downloads instead of opening in the browser.

### Deliberately not attached to anything

A note does not belong to a customer, an invoice or a job. Attaching
it would mean deciding now which of those it hangs from, and the note
somebody most needs to write is usually the one that does not fit a
form yet. When a real pattern shows up in what people actually write,
that is the moment to add the link — with evidence for where it goes.

---

## 2. A note on one line of a quote

A quote already had two blocks of prose, Notes and Terms, and both sit
at the bottom under everything. That works for "prices hold for 30
days". It does not work for the sentence that belongs to one line:

> 4 × Dome camera 5MP — *two of these go on the rear wall, cabling
> through the existing conduit*
>
> 1 × NVR 8-channel — *customer supplies the hard drive*

At the foot of a nine-line quote the reader has to work out which line
each remark is about, and they will get it wrong.

### On the form

Under each product picker there is a small **+ Note**. Click it and a
field opens across the row; type, and the toggle shows what you wrote
so you can see at a glance which lines carry one. A line that already
has a note opens showing it.

The row keeps its six columns and its height. Nothing moves.

### Why only quotes

The line editor (`docbuilder.js`) is shared by quotes, sales orders,
purchase orders and invoices. Turning this on for everybody would
change four screens to answer a question about one. The quote form
opts in with a single attribute:

```html
<form data-docbuilder data-db-notes>
```

Every other builder is untouched, and the tests check that by looking
for the attribute on pages that should not have it. To offer line
notes on another document later, add the attribute — the server side
and the printing are already generic.

### Where the note goes

The column is on **all four line tables**, not just `quote_items`:

```
quote_items            -> proforma_invoice_items
proforma_invoice_items -> invoice_items
sales_order_items      -> invoice_items
```

A quote becomes a proforma and a proforma becomes an invoice. Put the
column only on quotes and the note survives exactly as long as the
quote does — the moment somebody converts it, the sentence explaining
the line is gone, and the customer gets an invoice saying less than
the quote they agreed to. That is not a feature with a limitation, it
is a trap, and the person who falls into it is the one who trusted the
field enough to use it.

`sales_order_items` gets the column even though the sales-order form
has not opted in, because the statement that copies its lines into an
invoice is written to carry a note. A column missing on one side of a
copy is how the note would be lost the day somebody does opt that form
in.

### On the printed page

The note is set under the item in italic, indented behind a rule, at
10.5px against the item's 12px — so it reads as a remark on the
product above it rather than as a second product, which is exactly
what it looked like set at the same size.

`stream_document_pdf()` takes an optional `note` on each item row, so
a caller that passes none gets exactly the row it got before. Quotes,
proformas and invoices pass it.

---

## 3. Which file does what

| File | |
|---|---|
| `includes/notes.php` | Categories, visibility rule, edit rule, excerpt |
| `public/notes/index.php` | List, filters, add/edit modal, export |
| `public/notes/view.php` | One note |
| `public/notes/save.php` | Create/update (AJAX, JSON) |
| `public/notes/delete.php` | Delete |
| `public/notes/pdf.php` | Print |
| `includes/document_pdf.php` | `stream_note_pdf()`, and line notes in `stream_document_pdf()` |
| `public/assets/js/docbuilder.js` | The `data-db-notes` line field |
| Migration 055 | `notes` |
| Migration 056 | `note` on the four line tables |
