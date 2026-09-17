# Architecture

This document explains how the refactored iMplement ERP is put together and
how to extend it.

## Design goals
1. **One source of truth** for cross-cutting concerns (DB, auth, layout, helpers).
2. **Separation of concerns** — business logic separated from presentation.
3. **Security by default** — every page authenticated, every query prepared,
   every output escaped, every state change CSRF-protected.
4. **Extensibility** — adding a module or a nav entry is a small, local change.

## Request lifecycle

Every page follows the same bootstrap pattern:

```php
require_once __DIR__ . '/../includes/bootstrap.php';  // wiring
require_login();                                       // guard (+ require_role(...) if needed)
// ... controller logic (fetch/validate/mutate) ...
require __DIR__ . '/../includes/header.php';           // chrome open
// ... view markup ...
require __DIR__ . '/../includes/footer.php';           // chrome close + JS
```

`includes/bootstrap.php` is the single entry point. It:
1. loads `config.php` (env + constants),
2. loads the database layer, helper functions and auth,
3. starts a hardened session (`auth_start_session()`).

Modules under `/modules/` include `modules/bootstrap.php`, a thin shim that
calls the shared bootstrap, enforces `require_login()`, and provides a central
`getDBConnection()` for backwards compatibility with the legacy module code.

## The shared foundation (`includes/`)

| File              | Responsibility |
|-------------------|----------------|
| `bootstrap.php`   | Wires everything together; starts the session. |
| `database.php`    | `Database::pdo()` singleton + `db()`, `db_all()`, `db_one()`, `db_value()`, `db_run()` helpers. **The only place a PDO connection is created.** |
| `functions.php`   | `e()` escaping, CSRF (`csrf_token/field/verify/check`), flash messages, `redirect()`/`url()`, input helpers, formatting (`money`, `num`, `fmt_date`), `slugify`, `paginate`, `json_response`. |
| `auth.php`        | `auth_attempt()`, `auth_logout()`, secure session handling, login throttling, remember-me tokens, `current_user()`, `require_login()`, `require_role()`, `is_admin()`, `user_has_role()`. |
| `navigation.php`  | Data-driven menu (`nav_items()`), role-filtered. |
| `header/navbar/sidebar/footer.php` | Reusable layout partials. |
| `icons.php`       | Inline SVG icon set (no icon-font CDN). |
| `reporting.php`   | Read-only aggregate queries for the dashboard (reusable by future Reports/API). |
| `403.php`         | Forbidden page for failed role checks. |

### Why a central DB layer
The original codebase defined `getDBConnection()` **~25 times** — once per
module, each opening its own connection. These are now replaced by a single
pooled connection (`Database::pdo()`), removing the duplication and ensuring a
consistent, secure PDO configuration (exceptions on, emulated prepares off).

## Authentication & sessions
- Cookies are `HttpOnly` + `SameSite=Lax`, and `Secure` when HTTPS is detected.
- **Idle timeout** (`SESSION_LIFETIME`) logs users out after inactivity.
- **Session id rotation** every `SESSION_REGENERATE` seconds and on login
  (`session_regenerate_id(true)`) to prevent fixation.
- **Login throttling**: after `LOGIN_MAX_ATTEMPTS` failures the session is
  locked for `LOGIN_LOCKOUT_TIME`.
- **Remember me**: a DB-backed selector/validator token (`remember_tokens`),
  the validator stored only as a SHA-256 hash. Optional and fail-safe.
- Users can sign in with **email or username**.

## Authorization (RBAC)
Guards live in `auth.php`:
- `require_login()` — any authenticated user.
- `require_role(ROLE_MANAGER, ...)` — one of the listed roles; **admins always
  pass**. Renders `403.php` otherwise.

The navigation and dashboard shortcuts are filtered by the same role checks, so
users only see what they can use. Roles are defined as constants
(`ROLE_ADMIN`, `ROLE_MANAGER`, `ROLE_SALES`) in `config.php` and seeded in the
database by migration `003`.

## Front-end

CSS and JS are **fully externalised** — no inline `<style>`/`<script>` logic in
the new code.

**CSS** (`assets/css/`)
- `style.css` — design tokens (CSS variables preserving the original dark /
  cyan / gold / violet branding), reset, app shell, sidebar, top bar, buttons,
  tables, panels, badges, toasts, modal, utilities, responsive rules.
- `dashboard.css` — stat cards, quick actions, charts, activity feed.
- `forms.css` — inputs, selects, switches, the auth (login) screen.

**JS** (`assets/js/`) — dependency-free, wired via `data-*` attributes:
- `app.js` — `App.notify()` toasts, `App.confirm()` modal, `App.ajax()`,
  sidebar toggle, collapsible nav, dropdowns, alert dismissal, password
  show/hide, **live table search/filtering**, confirm-before-submit.
- `validation.js` — progressive client-side form validation (server remains
  authoritative).
- `dashboard.js` — renders charts with Chart.js (loaded via CDN, degrades
  gracefully when unavailable); reads data from a JSON `<script>` block so no
  logic is inlined in PHP.

Data is passed from PHP to JS via a single JSON `<script id="app-data">` block
(`$inlineData` in `footer.php`) — data only, never executable logic.

### Type-to-search over a long `<select>`

A picker with two hundred customers in it is a scroll, not a
choice. `data-search-select` on a text input filters the options of
the `<select>` beside it — the same interaction the document
builder's product picker already used, now available anywhere:

```html
<div class="select-search">
    <input type="search" class="form-control select-search-input" data-search-select>
    <select name="customer_id" required>
        <option value="">— Select customer —</option>
        <option value="7" data-search="Acme Ltd 0722000000 ops@acme.co.ke">Grace Wambua</option>
    </select>
    <span class="select-search-empty" data-search-empty hidden>No customer matches that.</span>
</div>
```

Matching runs over the option's text **plus its `data-search`**, so
a customer is found by phone or email without those cluttering the
label. Typing jumps the selection to the first match — but only
when the current choice is empty or has just been filtered out of
sight, never overruling a selection still on screen.

On the quote, proforma and sales-order builders, and on customer
advances.

### The toolbar wraps, and the search icon is a flex item

Two bugs from the same habit of guessing at a layout.

Adding a status picker and two date fields to the goods-received
toolbar pushed the row past its width, and the left half printed on
top of the Excel and Clear buttons. `.toolbar-search` now carries
`flex-wrap: wrap` at every width, not only under a phone media
query, so a filter row that outgrows its line takes a second one.

The magnifier used to be `position: absolute` inside
`.toolbar-search` — which frames it against the whole toolbar, not
against the input. Every change to the row moved it: a taller
button, a wrap onto a second line, or simply an input that renders
37px where the rule had guessed 38. It is a flex item now, in the
same flex line as the box and pulled over it by a negative margin
(icon 18 + gap 10 + the 12px inset it should sit at = 40), with
`align-self: center` doing the centring. No height to know.

The regression test checks **both axes**. Measuring only the
vertical had passed an icon sitting ten pixels off the left edge.

### No inline handlers, and the CSP is why

`data-*` attributes are not a style preference here. `script-src`
carries a nonce and no `'unsafe-inline'`, which bans inline event
handler **attributes** too — and an attribute cannot carry a nonce,
so there is no way to keep one. Every `onclick=` in the codebase was
dead, silently, including every document status picker. See
[SECURITY.md](SECURITY.md#a-nonce-does-not-cover-onclick).

Two behaviours in `app.js` cover the common cases:

| Attribute | Behaviour |
|---|---|
| `data-autosubmit` | on `change`, submit the control's own form |
| `data-print` | on `click`, `window.print()` |

Anything else belongs in a `<script nonce="<?= csp_nonce() ?>">`
block or a file under `assets/js/`.

### "Are you sure?" on an AJAX form was doing it twice

Both behaviours listen for `submit`, and they were stepping on
each other. `initAjaxForms` delegates from `document`;
`initConfirmForms` bound a listener to each form. The form's own
listener runs first, calls `preventDefault()` and opens the
dialog — but the event still **bubbles on to `document`**, where
the AJAX handler posts it immediately. Then answering *yes* called
`form.submit()`, which fires no submit event at all, so it went
around AJAX entirely and posted natively a second time.

Every form carrying both attributes therefore applied its action
twice, before the question had been answered. On this codebase
that is *Reconcile* on a purchase order and *Take into stock* on a
receipt — the two places where posting twice means posting the
adjustment twice.

The fix is two changes to `initConfirmForms`:

- **Delegate from `document`, on the capture phase.** Capture runs
  ahead of every bubble listener, so `stopImmediatePropagation()`
  there really does stop the event reaching the AJAX handler.
  Delegation also covers forms cloned out of a `<template>` into a
  modal, which the load-time `querySelectorAll` never saw.
- **Re-submit with `requestSubmit()`, not `submit()`.** It fires a
  real submit event, so the AJAX handler still gets its turn; the
  second pass is waved through by a `data-confirmed` flag that is
  cleared as it goes, so a repeat attempt asks again.

A test counts the POST requests a confirmed AJAX form sends and
requires exactly one.

### Conditional fields and the `hidden` attribute

Forms all over the app show and hide fields with the `hidden` attribute:
the transfer leg on a cash entry, the advance block on an invoice payment,
the line table on a credit note, the document picker on the cash book.

The browser's rule for that attribute — `[hidden] { display: none }` — comes
from the **user-agent stylesheet**, and *any* author declaration beats it
regardless of specificity. `.form-group { display: flex }` in `forms.css` was
therefore quietly overruling it, and every one of those fields was on screen
all the time. `style.css` now restates the rule where it wins:

```css
[hidden] { display: none !important; }
```

Anything relying on `hidden` needed this. If a new component sets its own
`display`, it inherits the fix rather than re-breaking it.

### The sidebar's sub-menu carries icons

The top-level groups always had one; their children were nine or ten
text links in a column, which reads as a wall — you scan it rather
than see it. Every entry now carries a 15px icon from the same
Feather-stroke set (`includes/icons.php`), declared alongside its
label in `includes/navigation.php`:

```php
['label' => 'Cash Count', 'icon' => 'calc', 'href' => 'finance/cash_count.php'],
```

Sixteen icons were added for it — `clipboard`, `ruler`, `invoice`,
`undo`, `book`, `calc`, `wallet`, `clock`, `chart`, `percent`,
`building`, `user-plus`, `compare`, `in`, `out`, `calendar`.

Two details that matter more than they sound:

- **The label still starts where it always did.** `.nav-sub-link`
  was a block with a 41px left pad faking the indent; it is now a
  flex row where the icon occupies that space, so the column of text
  did not move.
- **`flex: 0 0 15px` on the icon.** A flex item's default
  `min-width: auto` lets a long label squeeze its neighbour, and
  *Cash & Bank Accounts* is long enough to do it.

`icon()` falls back to `box` for an unknown name, so a menu entry
added without one still lines up rather than rendering nothing. A
test asserts all 41 links have an icon, none is squashed below 14px,
and every label starts at the same x.

### Table alignment, and why the utilities carry `!important`

The same trap, one layer up. `.ta-right` scores (0,1,0);
`.data-table thead th { text-align: left }` scores (0,1,2) and wins. So
every numeric heading in the system — Amount, Balance, Total, Margin %,
Value at cost — sat left-aligned above a column of right-aligned figures.
It was not one report: **50 of 50** aligned headings measured across
eleven pages were doing the opposite of what their class said, and
`.ta-center` was being used in the cash book without ever having been
defined at all.

```css
.ta-left   { text-align: left   !important; }
.ta-center { text-align: center !important; }
.ta-right  { text-align: right  !important; }
```

Raising specificity instead would mean writing the rule once per table
class — `.data-table`, `.position-table`, `.cashbook-table` — and once
more for the next one anybody adds. A single-declaration utility whose
whole purpose is to override is what `!important` is for.

Two more scoping bugs went with it. `.data-table tbody td` sets the cell
padding, and `tbody` is in that selector, so **`tfoot` cells had none** and
every totals row sat 16px off the figures it was adding up. A `tfoot th`
also falls back to the user agent's centred default, which pushed a row's
label away from the column it labels. Both now match the body.

A regression script measures the right edge of the heading, a body cell and
the totals cell for every right-aligned column on eleven pages, in both
themes, and requires all three to agree within a pixel.

## Extending the app

**Add a page to an existing module**
1. Create `modules/<area>/<page>.php`.
2. First line after `<?php`: `require_once __DIR__ . '/../bootstrap.php';`
3. Use `db_*()` helpers, `e()` for output, `csrf_field()`/`csrf_check()` for forms.
4. Add a link in `includes/navigation.php`.

**Add a whole new module** — create `modules/<newarea>/`, reuse the shim, and
add a top-level nav entry (optionally role-restricted).

## Future integration (designed for, not yet built)
The structure anticipates the roadmap in the brief:
- **REST API** — `reporting.php` and the `db_*` layer are transport-agnostic;
  add an `api/` folder that reuses them and returns `json_response()`.
- **Website orders / inventory sync** — the sales/inventory tables already
  model channels (`sales_channel`) and stock; a sync service can write through
  the same DB layer.
- **Audit logs / notifications / multi-branch** — add tables + a thin service
  layer; the central bootstrap gives every request a known user context.

## Pagination

Ten rows a page, everywhere, with Previous and Next. One function
renders the control:

```php
pagination_nav($pg, $baseQuery, 'product');
```

It used to be written out by hand at the foot of each list, and the
copies had drifted: `users/index.php` never defined `$baseQuery` at
all, and every page printed one link per page — a forty-page list
printed forty links, which is a wall rather than navigation. Now the
first page, the last, and a window either side of the current one are
shown, with gaps marked, and Previous and Next are always present
(greyed at the ends rather than vanishing, so the control does not
change shape as you move through it).

The caption — *Showing 11–18 of 18 products* — appears even on a
single page. It is how someone checks a filter did what they expected.

### Two mechanisms, one rule

| | When |
|---|---|
| `paginate()` + SQL `LIMIT`/`OFFSET` | Rows come straight from a table |
| `paginate_rows()` on a loaded array | Everything must be read first |

The ageing reports and the stock valuation are the second kind: their
bucket totals and summary cards have to cover the whole ledger before
a single row can be shown, so they read everything, total it, then cut
the table to a page. Footers on those tables say **every page**, so a
grand total is never mistaken for a page total.

### Two tables on one page

`pagination_nav()` takes a fourth argument, the query key holding the
page number. Aged Payables and Sales Analysis each show two tables and
give the second its own key (`po_page`, `c_page`) — with one shared
key, paging either would silently page both.

### `$baseQuery`

Every filter the page understands, so paging never drops them. Set it
before `header.php` is required:

```php
$baseQuery = array_filter([
    'q' => $search, 'status' => $status,
    'per_page' => per_page() !== PER_PAGE_DEFAULT ? per_page() : null,
], fn($v) => $v !== null && $v !== '');
```
