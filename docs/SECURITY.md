# Security

How the application keeps people out of what is not theirs, and what
the layers around it still have to do.

This covers the code. The edge — TLS termination, WAF, DDoS
scrubbing, CDN, load balancing — is in [DEPLOYMENT.md](DEPLOYMENT.md),
because none of it lives in PHP.

---

## 1. Default deny

Authentication used to depend on every page remembering to call
`require_login()`. One forgotten call left a page open to anyone who
knew its URL.

`includes/bootstrap.php` enforces it centrally:

```php
if (!defined('APP_PUBLIC_PAGE')) {
    require_login();
}
```

Every page that includes the bootstrap is protected. A page is public
only if it says so **before** including:

```php
define('APP_PUBLIC_PAGE', true);
require_once __DIR__ . '/includes/bootstrap.php';
```

Only the sign-in flow, the storefront API and `health.php` do. A page
added later is closed by default — the failure mode is a locked door,
not an open one.

`modules/bootstrap.php` also calls `require_login()`, so every legacy
module page is covered twice.

### Authentication is not authorisation

Default-deny gets you *signed in*. It says nothing about what a
signed-in salesperson may reach, and that gap was real:
`modules/users/insert_users.php` had no role guard and no CSRF token,
so **any signed-in user could create an account with any role,
including Administrator**. It was not linked from anywhere, which is
exactly why nobody noticed. It and its sibling `view_users.php` have
been removed — `users/` is the real module and has always been
admin-guarded.

The lesson is in the audit now: an unlinked page is not a private
page.

---

## 2. Roles and least privilege

Three roles, from `users.role_id`. Administrators pass every check.

| Area | Minimum role |
|---|---|
| Dashboard, quotes, sales orders, customers, products | Salesperson |
| Purchasing (orders, GRNs, debit notes, **and their print views**) | Manager |
| Finance (cash book, expenses, loans, advances, investors) | Manager |
| Reports | Manager |
| Company settings | Manager |
| Users, permissions, audit log | Administrator |
| Deleting anything financial | Administrator |

Enforced by `require_role(...)` at the top of the page, **not** by
hiding the link. The navigation filters by role as a courtesy; the
guard is what actually stops the request. Verified by signing in as a
salesperson and asking for each privileged URL directly — every one
answers 403.

`require_role()` answers a `fetch` with 403 JSON and a browser with
the 403 page, so an AJAX caller is told what happened instead of being
handed a login form it cannot parse.

### The gap that was in the module pages

Pages under `public/modules/` include `modules/bootstrap.php`, which
calls `require_login()`. That is not nothing — none of them were open
to the world — but several then carried **no role check at all**, so
every signed-in user reached their write handlers. Signing in as an
ordinary salesperson and posting to them showed it was not theoretical:

| Was possible | Now |
|---|---|
| Delete any product | Manager |
| Delete any category | Manager |
| Delete any unit of measure | Manager |
| Add or edit any product | Manager |
| Delete any customer | Manager |

The guard sits **inside each write handler**, after `csrf_check()`,
following the pattern `view_brands.php` already used — not at the top
of the file. Reading the product list stays open to anyone signed in,
because a salesperson looking a product up is not a privilege, and
locking the whole page would have broken the job the role exists to do.

Editing and deactivating a *customer* deliberately stays with the
salesperson: "manage customers" is what the role is for. Only deleting
one — which takes the record away for good — moved to Manager.

The buttons moved with the rules. A Delete button that only ever
answers 403 is its own kind of bug, so each list renders its write
controls behind the same test the handler applies.

### Four orphaned pages, deleted

`insert_quote.php`, `insert_quotes.php`, `insert_sales_order.php` and
`insert_customer.php` — 2,091 lines under `public/modules/sales/` —
were unreachable: nothing in the application, the navigation or the
docs linked to them. They also predated the shared layer, and carried

* no role check of any kind,
* `$currentUserId = 1; // Simulated logged-in user`, so anything
  raised through them was credited to whoever user 1 happens to be,
* `<?= $flash['message'] ?>` unescaped — safe only because every
  message that reached it happened to be escaped at the other end,
  which nothing enforced.

They duplicated `manage_quotes.php`, `list_sales_orders.php` and
`list_customers.php`, all of which are guarded and current. Dead code
that can still be reached by typing its URL is attack surface, so they
were removed rather than repaired.

### Changes take effect immediately

| Change | What happens |
|---|---|
| Password changed | every *other* session ends, all remembered devices are revoked |
| Account deactivated | every session ends, on every node |
| Role changed | that user's sessions end so the new role is loaded |

This is only possible because sessions are in the database (§4).
Before, a deactivated user stayed signed in until they closed their
browser.

---

## 3. Sign-in

| Control | Where | Effect |
|---|---|---|
| Uniform failure message | `auth_attempt()` | never reveals whether an account exists |
| **Constant-time miss** | `auth_attempt()` | a wrong password and a missing user take the same time |
| **Lockout in the database** | `auth_is_locked_out()` | 5 failures per account, 20 per address, 15-minute window |
| Rate limit on the form | `login.php` | 30 posts per 5 minutes per address |
| CSRF token | `csrf_check()` | on every state-changing form |
| Password policy | `password_policy_check()` | 12 characters minimum, denylist, no own name |
| Current password required | `users/edit.php` | to change your own |

### The lockout used to be worthless

It was counted in `$_SESSION` — that is, in a cookie the attacker
holds. Discarding the cookie between attempts reset the counter, so
the lockout stopped nobody and only ever inconvenienced someone who
had genuinely forgotten their password.

Attempts are now rows in `login_attempts`, counted two ways because
there are two attacks:

- **per account** — many passwords against one name
- **per address** — one password against many names, which is what
  credential stuffing actually looks like

The per-address limit is deliberately looser (20), because a whole
office shares one address and locking out the building because one
person fat-fingered their password would be its own kind of outage.

It **fails open** if the database cannot answer, falling back to the
session counter. A business must not be locked out of its own books
because a table is missing.

### Password policy

Twelve characters minimum, a denylist of what gets tried first, and a
check against the person's own name and email. No forced expiry and no
"must contain a symbol" — both push people towards `Passw0rd!1`,
`Passw0rd!2`, written on a sticky note, which is why NIST now advises
against them.

### Forgotten passwords

A reset flow is the softest way into an account — it mints a
credential and mails it — so the controls are in
`includes/password_reset.php` and nowhere else. Setting it up is
`docs/PASSWORD_RESET.md`.

| Control | Effect |
|---|---|
| One answer, always | The reply is identical for a real address, an unknown one, a deactivated account and a rate-limited request. The form is not a staff directory. |
| **Link built from configuration** | `APP_PUBLIC_URL`, or a `Host` that `APP_HOSTS` lists. With neither set it **refuses to send**. |
| Split token | Selector stored plainly and indexed; verifier stored only as a hash. The table is not a set of keys. |
| One hour, one use | `consumed_at` is set in the same transaction as the password change, so the two cannot come apart. |
| Earlier links die | Requesting a new one consumes the old, so an attacker's link does not survive the owner asking for theirs. |
| Two rate limits | 3 per address and 10 per source per 15 minutes, **checked before the account is looked up** so a refusal says nothing about it. |
| Everything else signed out | Sessions and remember-me tokens for that user are destroyed. A reset made because somebody else has your password has to push them out. |
| A notice to the account | "Your password was changed" — the warning, if it was not them. |
| Not a way past 2FA | The reset sets a password; it does not sign anybody in. With `TWO_FACTOR_ENABLED` the code is still required. |

The Host rule earned its wording. `security_safe_host()` returns
the raw header when `APP_HOSTS` is empty — right for its other
caller, which bounces a request back to the host it arrived on,
and wrong here, where that host goes into an email sent to
somebody else. So this reads the list itself and gives no answer
when there is none.

### Remember me

The cookie is a thirty-day credential sitting on a laptop, so it is
treated as one:

- **rotated on every use.** A copy taken yesterday stops working the
  moment the real owner opens the app.
- **reuse is detected.** A known selector with a stale validator means
  exactly one thing — the cookie has been copied. Every token that
  user holds is destroyed, both parties have to sign in again, and
  `auth.remember_token_reuse` goes in the audit log. The thief does
  not have the password; the owner does.
- **Secure flag set from `request_is_https()`**, which understands
  proxies. Reading `$_SERVER['HTTPS']` directly meant that behind a
  load balancer — where the hop to PHP is plain HTTP — a thirty-day
  credential was sent without the Secure flag.

---

## 4. Sessions

| Control | Where | Effect |
|---|---|---|
| **Stored in PostgreSQL** | `includes/session_store.php` | any node serves any request; revocation is instant |
| HttpOnly, SameSite=Lax, Secure on HTTPS | `auth_start_session()` | unreadable by JS, not sent cross-site |
| Rotation every 15 min | `SESSION_REGENERATE` | limits fixation |
| Rotation on login | `auth_establish_session()` | a pre-login id never becomes a logged-in one |
| **Strict mode** | `session.use_strict_mode` | an id the *browser* invented is thrown away, not adopted |
| Cookie only, never a URL | `use_only_cookies`, `use_trans_sid=0` | the id cannot leak through Referer, history or an access log |
| Idle timeout, 30 min | `SESSION_LIFETIME` | abandoned sessions die |
| Idle sign-out in the browser | `assets/js/idle.js` | an unattended *screen* clears, not only the session |
| Absolute lifetime, 12 hours | `SESSION_ABSOLUTE_LIFETIME` | a session cannot be kept alive forever by staying busy |
| Client binding | `auth_fingerprint()` | a cookie lifted from one browser fails in another |

The fingerprint hashes the user agent with the network prefix (IPv4
/24, IPv6 /48) rather than the full IP, so a phone moving between cell
towers stays signed in while replay from an unrelated network is
refused. On mismatch the session is destroyed — the legitimate user is
signed out too, which is the safe direction.

Strict mode is the setting that makes the rest of that list mean
something. Without it PHP accepts whatever id it is handed and creates
a session under it, so an attacker can *choose* the id — plant it in a
victim's browser through a link, a shared machine or an XSS, wait for
them to sign in, and then use the same id. Rotation on login already
closed most of that door; strict mode removes the hinge. It has to be
set before `session_start()`, which is why it lives at the top of
`auth_start_session()` rather than in `config.php`.

The database handler degrades rather than breaks: with the `sessions`
table missing (a fresh clone, mid-migration) PHP's own file handler
stays in charge and the application still runs. `/health.php?deep=1`
reports which is in use, so a node quietly keeping sessions to itself
is visible rather than mysterious.

### Idle sign-out

The server expires an idle session, but that check only runs when a
request arrives. A screen left open keeps showing customers, prices
and cash balances until somebody touches it, and on a counter that is
the whole of the exposure. So the browser keeps its own clock, set
from the same constant.

Three details that matter more than they look:

- **Once the warning is up, mouse movement no longer counts.** A nudge
  from someone walking past must not extend a session; only the button
  does.
- **The clock lives in `localStorage`**, so it is shared across every
  tab. Working in one keeps the others alive; one sign-out ends them
  all.
- **Nothing polls.** A timed ping would refresh the session forever
  and there would be no idle timeout at all.

---

## 5. Every request, before it is answered

`security_firewall()` in `includes/security.php` runs first, and is
deliberately **not** a pattern-matching WAF.

An ERP is full of text that looks like an attack — a product called
`1/2" UNION`, a note reading *"SELECT the blue one"*, a JSON blob of
invoice lines. A filter that guesses will eventually refuse a real
invoice, and people then turn it off. Signature matching belongs at
the edge where it can be tuned and watched:
`deploy/modsecurity-erp.conf` does that job and ships with the
exclusions this application needs.

What is enforced here is only what is never legitimate:

| Refused | Why |
|---|---|
| Methods other than GET/HEAD/POST/OPTIONS | nothing implements them |
| A `Host` header not in `APP_HOSTS` | cache poisoning, poisoned reset links |
| Null bytes anywhere in input | exist only to truncate a path |
| `../` in a parameter that names a file | traversal |
| Headers over 32 KB, bodies over 1 MB (non-upload) | resource exhaustion |

Everything else is left to the layers that can judge it: bound
parameters for SQL, escaping on output for HTML. Every block is
recorded as `security.blocked` with the path and the real client IP.

---

## 6. Response headers

Set by `security_headers()` on every response:

```
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-…';
  style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
  font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:;
  connect-src 'self'; frame-ancestors 'none'; form-action 'self';
  base-uri 'self'; object-src 'none'
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-origin
Strict-Transport-Security: max-age=31536000; includeSubDomains   (HTTPS only)
Cache-Control: no-store, no-cache, must-revalidate, private
```

**Strict about scripts, relaxed about styles.** Every inline
`<script>` in the application carries a per-response nonce; an
injected one cannot guess it. Inline *styles* are allowed because the
legacy pages are full of them and an injected style cannot read a
session or call an API.

### A nonce does not cover `onclick=`

`script-src` has no `'unsafe-inline'`, and that bans **inline event
handler attributes** as well as inline `<script>` blocks. A nonce
cannot be attached to an attribute, so there is no way to keep one.

This was missed when the policy shipped, and it broke real work
rather than anything cosmetic. Roughly forty controls still carried
`onchange="this.form.submit()"` or similar, including **every
document status picker** — quotes, invoices, proformas, credit
notes, returns, sales orders, debit notes, goods received. Choosing
*Accepted* on a quote did nothing at all: the browser refused the
handler and the user saw no error, because a refusal is a console
message and not a page.

Everything is now wired from `app.js` or a nonce'd block:

| Was | Now |
|---|---|
| `onchange="this.form.submit()"` | `data-autosubmit` — delegated `change` listener |
| `onclick="window.print()"` | `data-print` — delegated `click` listener |
| `onclick="addRow()"` and friends on the legacy builders | delegated listeners inside each page's nonce'd block |
| `onsubmit="return validateForm()"` | a `submit` listener on the form |

`data-autosubmit` re-submits with `requestSubmit()`, not `submit()`,
so a form that is also `data-ajax` or `data-confirm` still gets its
turn.

**Testing this needs the markup, not the console.** Chromium reports
the refusal when the handler *fires*, so loading a page whose every
control is dead produces no violation at all — a console-watching
test called all 41 pages clean while the bug was still in place. The
regression test therefore scans the rendered HTML of every page for
`on*=` attributes (ignoring comments, since several files now explain
in prose why they are not used) and fails on any hit.

`frame-ancestors 'none'` is the header browsers actually consult now;
`X-Frame-Options` is kept for anything old enough to need it.

`Cache-Control: no-store` on every authenticated page is not
housekeeping — an invoice cached at a CDN edge node is an invoice
served to the next person through that node.

The one external script host is the CDN copy of Chart.js. Run
`bash deploy/vendor-assets.sh` and the host disappears from the policy
entirely; until then, `CHART_JS_SRI` in `.env` pins the file by hash.

---

## 7. Rate limiting

`rate_limit()` is a fixed-window counter in PostgreSQL, so the limit
is the limit however many nodes are running.

| Bucket | Limit |
|---|---|
| Sign-in form, per address | 30 per 5 min |
| `api/orders.php`, per address | 60 per min |
| Wrong API key, per address | 10 per min |
| `api/products.php`, per address | 240 per min |

It **fails open** when the database is unreachable, and logs loudly. A
limiter is not worth stopping the business for.

Behind a CDN this is only meaningful once `TRUSTED_PROXIES` is set —
otherwise every visitor shares the CDN's address and the limit applies
to the whole internet at once. See DEPLOYMENT.md §3.

### Which address is the client's

Every limit above, the account lockout, and every line in the audit
log key off `client_ip()`. It is a small function and it was wrong.

`X-Forwarded-For` reads `client, proxy1, proxy2` — each hop
**appends**. So the right-hand end was written by our own proxy and
can be believed; the left-hand end is whatever the request arrived
carrying, and a client can put anything there. `client_ip()` read the
chain **left to right**, which meant a request sent as

    X-Forwarded-For: 1.2.3.4

became `1.2.3.4` the moment the real proxy appended the real address.
Three consequences, in order of how much they cost:

1. **Unlimited password guessing.** Change the header each attempt and
   every attempt lands in a fresh bucket. Thirty tries per five
   minutes becomes thirty tries per header value.
2. **Lockout as a weapon.** Send somebody else's address and fail
   deliberately; they are locked out and you are not.
3. **An audit log that records a fiction.** The address in
   `security.blocked` is the one the attacker typed.

It now walks the chain from the right and returns the last hop that is
not one of ours, stopping at the first entry that is not a valid IP —
past a value we cannot parse, nothing further left can be trusted
either. When every hop is ours, or the header is absent, the answer is
the peer address.

The account lockout in `login_attempts` is keyed by *email* as well as
by address, so guessing one account stayed bounded even while this was
broken. Nothing else did.

---

## 8. Data handling

- **SQL** — every query is a prepared statement with bound
  parameters. The few places that interpolate (`LIMIT`, `ORDER BY`
  direction) interpolate values the code produced, clamped to a range
  or matched against a fixed list, never a request parameter.
- **Output** — `e()` on everything rendered, which is
  `htmlspecialchars` with `ENT_QUOTES` and an explicit charset.
- **Uploads** — typed by `finfo` from the file's own bytes, not the
  name it claims; stored under a generated filename so a caller can
  never influence the path; 5 MB ceiling; PHP execution disabled in
  `uploads/` by both `.htaccess` and the server configs.
- **Errors** — `db_rule_message()` passes through the messages the
  database triggers were written to show a person, and nothing else.
  A raw `SQLSTATE` never reaches a client; ModSecurity rule 1022 is a
  second net under that.

  Nothing caught what those two missed. There was no exception
  handler and no shutdown handler anywhere in the application, so an
  uncaught throwable produced PHP's default: an empty HTTP 500, which
  a browser renders as *"This page isn't working"*. The visitor
  learned nothing, and the only record was a line in a log they could
  not reach. Three purchasing documents were "broken" for days that
  way; the cause was one renamed column.

  `includes/errors.php` now catches both. Every failure is logged in
  full with a short reference, and the visitor gets a readable page —
  or JSON, for a `fetch` — carrying that same reference, so a report
  can be matched to a log line. The real message, file and line are
  shown **only** when `APP_DEBUG` is on: a database error names
  tables and columns and a stack trace names paths, and both are
  reconnaissance. Output buffers are discarded first, so an error can
  never be stapled to the front of a half-written PDF.
- **Audit log** — sign-ins, lock-outs, token reuse, role changes,
  password changes, blocked requests, rate limits, and every
  financial document. With the real client IP once `TRUSTED_PROXIES`
  is set.

### Reading it back

Forty-nine files wrote to `audit_logs` and nothing read it. The answer
to "who deleted that supplier?" was in the database with no way to ask,
which makes it storage rather than a trail.

**Users → Audit Trail** (`public/users/audit.php`) answers the three
questions it exists for — what happened to *this record*, what has
*this person* done, what happened *that day* — and exports.

It is read-only on purpose: no edit, no delete, and the page writes
nothing to the table it reads. A trail somebody can tidy up is worth
nothing in the argument it exists to settle. Administrators only,
since it records everybody.

The filter lists are built from `SELECT DISTINCT` over the table
rather than a hard-coded list, so an action added by a new module
appears without anyone remembering to come back and add it. Labels
are derived from the `entity.verb` convention the action names already
follow (`credit_note.approve` → "Credit note · approve"), and the
colour comes off the verb, so destructive entries are the ones the eye
finds first.

---

## 9. Secrets

`.env` holds the database password. It is gitignored,
`.env.example` is the template, and on a server it should be
`640 root:www-data` — or `600` on shared hosting, where the web
server runs as the account owner.

**It is above the document root, and that is the whole defence.**
The application lives in the project root and only `public/` is
served, so no URL names `.env` at all. See `docs/DEPLOYMENT.md`
§0 for the layout and `deploy/cpanel-layout.sh` for cPanel.

The `.htaccess` rules are still there and still worth having, but
they are the second line now, not the only one. They had to carry
the whole weight when the project root *was* the document root,
and they are the wrong thing to rely on: **nginx does not read
`.htaccess` at all**, a host may ship `AllowOverride None`, and a
rule only fires for the requests it matches. Under the old layout
any one of those served the database password as plain text.
Under this one there is nothing to misconfigure.

Supplying the credentials as real environment variables instead
of a file is better still, and `config.php` already prefers them.

`t_docroot.py` proves the layout: it asks the server for every
private file by its old URL and by eight traversal spellings and
requires that none return the file's contents. After deploying,
fetch `https://your-domain/.env` yourself — a 403 or 404 is
right, and anything showing `DB_PASS` means the application was
put inside the document root.

---

## 9b. Private documents

Payment slips, refund proofs, investor agreements and staff advance
receipts are uploaded into `public/uploads/`, and until this was
written they were linked as plain URLs and served straight off the
disk. The web server never asked who was asking, so every one of
those links was an **unauthenticated, permanent key to a customer's
bank details** — no attack needed, just a link forwarded once.

Confirmed against real nginx and real PHP-FPM before the fix:

```
GET /uploads/payments/doc_probe.pdf   →  200, contents and all
```

The filenames are generated, which makes guessing one hard. That is
not protection: hard-to-guess is not a session check, and URLs leak
through forwarded mail, shared browser history and proxy logs without
anybody attacking anything.

Now: those four folders are denied at the web server, and
`public/documents/view.php` is the only way in. It asks in order —
signed in, folder on the allow-list, extension on the allow-list,
`realpath()` still inside `UPLOAD_PATH`, and the file's real MIME type
matching its extension — and refuses on the first no.

**The nginx rule is four prefix blocks, not one regex, and that is
load-bearing.** `location ^~ /uploads/` carries the `^~` modifier,
which tells nginx to stop and never consider regex locations. A regex
deny would never have been reached. The first version of this was a
regex, and its test passed — because the folders were empty. A rule
that is never reached and a file that is not there give the same
answer.

Product images and the company logo deliberately stay public: they
belong on pages and documents that are themselves public, and routing
them through PHP would cost a process per image for privacy nobody
wants.

`t_privatedocs.py` runs against nginx rather than the PHP dev server,
because the deny rule only exists in nginx and the dev server would
pass a test that a real deployment failed. It checks the direct URLs
are shut with and without a session, the endpoint refuses anonymous
requests, ten traversal attempts (`../.env`, `..%2f.env`,
`payments/../../../etc/passwd`, a file whose contents disagree with
its name) leak nothing, the response carries `nosniff` and
`no-store`, and a product image still serves.

---

## 10. What is still open

- **`forgot_password.php`** — inert. Planned as a one-time code by
  email or WhatsApp. Today an administrator sets passwords from
  *Users → Edit*.
- **`signup.php`** — inert, and will stay that way. There is no public
  sign-up for internal business software.
- **Two-factor authentication** — the session model already supports
  it: `auth_establish_session()` is the single place a session becomes
  authenticated, so a "password accepted, awaiting code" state slots
  in ahead of it without touching any page.
- **Uploads on more than one node** — `uploads/` is local disk. Before
  scaling past one node it needs shared or object storage. Nothing
  else in the application holds state on disk.
- **Field-level encryption** — the database is not encrypted at rest by
  the application. Use the volume or the database's own encryption.
- **Dependencies** — `composer audit` is clean as of this writing
  (dompdf 3.1.6; 3.1.5 carried six advisories, all low, including a
  chroot validation bypass). It is not a thing you do once: run
  `composer audit` on every deployment, and `composer update` when it
  reports something.
- **The finance module** has had the least review of anything in the
  application, and it is where money moves. Authorization on the
  expenses, advances and investor pages has not been walked
  endpoint-by-endpoint the way `modules/` was in `t_privesc.py`.
- **Session fixation on privilege change** — the session id is not
  known to be regenerated when a user's role changes mid-session.
  Not tested either way.

---

## 11. Verified

Everything in this document was exercised against a live database, not
reasoned about:

- brute force with a **fresh cookie jar on every attempt** — locked out
  at the 6th, the correct password refused while locked
- a salesperson requesting each privileged URL directly — 403 on every
  one, 200 only on their own pages
- a salesperson POSTing to every write handler under `modules/` —
  product, category and unit deletes refused and the rows verified
  still present afterwards; product edit refused; customer delete
  refused; and, in the same run, customer *edit* and *deactivate*
  confirmed still working, because a fix that takes away the role's
  own job has broken the application rather than secured it
- an administrator deleting a category in the same run — still
  allowed, and still shown the button (`t_privesc.py`)
- the audit trail as a salesperson — 403
- the removed user-management endpoints — 404
- CSRF omitted from the legacy forms — 403
- null byte, traversal, unimplemented method, oversized header — 400,
  400, 405, 431, each audited
- a remember-me cookie replayed after rotation — whole token family
  destroyed, both parties signed out, `auth.remember_token_reuse`
  logged
- a password change — refused without the current password; with it,
  every other session ended and the current one kept
- rate limiters — allow exactly the limit, then 429 with `Retry-After`
- a forged `X-Forwarded-For` from an untrusted hop — ignored
- fifteen pages under the content security policy — zero violations,
  inline scripts still running

### The pass after the pickers

New code deserves its own engagement, and the picker work added a
lot: a JSON endpoint, a list registry, a cache with a new claim, and
several rewritten report queries. None of it had been attacked.

**One finding, and it was the serious kind.**

`/lookup.php` answers "give me the rows for this list" and every list
in the registry was declared `'role' => null` — the default, taken by
all thirteen because nobody had to think about it. But the pages that
use those lists are not open to everybody: the whole purchasing
module and all of finance are `require_role(ROLE_MANAGER)`.

So a Salesperson who is shown **403 on the supplier list and 403 on
purchase orders** could read both straight off the endpoint. Proven
against a live salesperson session:

    modules/purchasing/view_suppliers.php   HTTP 403
    lookup.php?list=suppliers               HTTP 200, 40 rows

    modules/purchasing/manage_pos.php       HTTP 403
    lookup.php?list=purchase_products       HTTP 200, 40 rows
                                            cost=… what the business pays

That is supplier names, open order values, and the cost price of
every product bought — an API not enforcing what its pages enforce,
which is the most ordinary way access control is lost.

Three things changed, and only the third is the fix:

1. Every list now carries the role of the least restrictive page
   that uses it. A salesperson keeps the five they need to raise a
   quote and loses the eight they never had.
2. The check moved into `lookup_query()`, which is the one function
   every reader goes through — the endpoint, the `<select>` a page
   seeds, and the JSON block a document builder reads. Guarding the
   endpoint alone would have left the other two open, and it is the
   seed that renders into a page's HTML.
3. **It fails closed.** `role` is now required, and a list that omits
   it is refused and logged. The rule is not "remember to set the
   role" — a rule everybody is allowed to forget is not a rule.

The test that should have caught this said, in a comment: *"every
list here is readable by any signed-in user today"*. Nobody had
asked. It now asks the page what the user is allowed and the endpoint
the same question, for all thirteen lists, and the endpoint may never
be the more generous of the two.

**What was attacked and held:** a stored `<script>` in a customer name
through the new render path — escaped in the page's `<option>` and
inert in the JSON; the `keep` parameter, which deliberately bypasses
a list's WHERE so a settled invoice stays nameable, and which does
not get a salesperson past the role check; LIKE metacharacters,
backslashes and quoted payloads through `q`; cross-origin reads (no
`Access-Control-Allow-Origin`, `private, no-store`); and the shared
cache, which holds only whole-business aggregates keyed on a
server-clock or regex-validated date — never a row a permission check
applies to, which is the policy `includes/cache.php` set for itself
before anything used it.

---

### The penetration pass

A second, adversarial run (`t_pentest.py`, 50 checks) attacked the
running application rather than reading it — six sections:
authentication, authorisation, injection, CSRF, files, rate limits.
Each check names the leak it is looking for rather than a status code,
because the status code lies: on the built-in server a request for
`.env` answers `302 → login` with an empty body, which looks like a
finding and is not one. The same probes are then repeated against
nginx, because production is nginx and the two disagree.

What it confirmed:

- a salesperson POSTing a role change for their own account — refused,
  and the role re-read from the database afterwards to prove it
- session id replaced on sign-in; an id the client invented never
  adopted
- `' OR 1=1 --`, `'; DROP TABLE`, `UNION SELECT` through search, login
  and id parameters — no error text, no extra rows, tables still there
- a stored `<script>` in a customer name — escaped on all three pages
  that render it
- a POST with no token, a stale token, and another user's token — 403
  each, and the row unchanged
- `.env`, `config.php`, `includes/database.php`, `.git/config`,
  `database_structure/migrate.php` under nginx — 404, and the response
  body checked for the password string rather than trusted to the code
- **rotating `X-Forwarded-For` does not buy a fresh allowance** — the
  check that would have caught the bug above, and did not exist before

What it found, and what was done:

| Finding | Fix |
|---|---|
| `client_ip()` believed the client-supplied end of `X-Forwarded-For` | §7, walk the chain from the right |
| Company-details flash rendered unescaped | `e()` — the message can carry a PostgreSQL error quoting a typed value |
| `session.use_strict_mode` never set | §4, set before `session_start()` |

None of the three needed a clever exploit; all three were one line.
That is the usual shape of it.

### The pass over the two API endpoints

`public/api/` holds the only two doors that are not a signed-in page:
`products.php`, which the storefront reads the catalogue from, and
`orders.php`, which it posts baskets to. They were read the way the
picker endpoint was read — by asking what each one accepts that the
rest of the application would refuse.

What held up:

- **Prices are never taken from the request.** `orders.php` looks each
  line up in `products` and uses the catalogue price. A basket that
  says the camera costs 10 shillings is priced at what the shop
  charges.
- **`catalog_get_product()` selects `p.*` but returns a hand-built
  shape.** Cost price, supplier, reorder level and every other
  internal column are read and then not returned. The whitelist is
  the return statement, which is the right place for it.
- **No `Access-Control-Allow-Credentials`,** so the `*` origin on the
  catalogue cannot be used to read anything as a signed-in user.
- **A wrong API key is rate limited before it is compared,** and the
  comparison is `hash_equals`.

What it found:

| Finding | Fix |
|---|---|
| Order intake accepted products the catalogue deliberately hides | `is_published = TRUE` on both lookups, matching `catalog_*` |
| `quantity` had no ceiling and no finiteness check | `ORDER_INTAKE_MAX_QUANTITY`, and `is_finite()` |
| Two orders from one new customer raced into a unique violation | `ON CONFLICT (email) DO NOTHING`, then read the winner's row |

The first is the one worth explaining. `api/products.php` only ever
lists products that are `is_active AND is_published` — "Publish to
website" is a box somebody ticks on the product form, and it is off
until they do. Intake checked only `is_active`. So a product held back
from the website — withdrawn, priced internally, not ready — could
still be ordered by anyone who guessed its SKU, at whatever price the
row happened to carry. That is the same shape as the picker bug: two
pieces of code answering the same question differently, and the
looser one reachable from outside.

`quantity` is second-hand input twice over: a stranger types it into a
website, and the website forwards it. JSON can express `1e999`, PHP
decodes that to `INF`, `INF > 0` is true, and it reached `NUMERIC` as
a value the column cannot hold — a 500 and a lost basket instead of
"that is not a quantity".

The third was found by running the concurrency test rather than by
reading, and the first attempt at fixing it made things worse:
`ON CONFLICT DO UPDATE` returns the id in one statement, but it takes
a write lock on the customer row and holds it to commit, while the
order insert needs its own lock on that row for the foreign key. Ten
simultaneous orders deadlocked on that pair and Postgres resolved it
by killing transactions. `DO NOTHING` locks nothing; the loser reads
back the row the winner committed.

### The pass over the money ceilings

Seven database triggers stand between this business and figures that
do not add up. Each reads a parent row, sums what its children already
come to, and refuses anything that would go over:

| Guard | What it protects | Direction |
|---|---|---|
| `invoice_payment_within_total` | an invoice cannot take more than it is for | money in |
| `purchase_payment_within_total` | a supplier cannot be paid past the order | **money out** |
| `customer_refund_within_due` | a refund cannot exceed what is due | **money out** |
| `advance_draw_within_balance` | a draw cannot exceed the advance | **money out** |
| `credit_note_within_invoice` | credit cannot exceed the invoice | money given up |
| `loan_repayment_within_principal` | repayment cannot exceed the principal | — |
| `investment_repayment_valid` | repayment cannot exceed the investment | — |

Putting the check in the database rather than in a page is the right
call and it is why these survived every earlier pass: no route into
the system can go round them. But all seven read the parent **without
locking it**, and a `SUM` is a snapshot. Two transactions arriving
together each look at a table where the other's row is not yet
visible, so each is comfortably under the ceiling, and both are let
through.

Measured. One invoice for 10,000, six payments of 10,000 recorded at
the same microsecond — which is what a double-clicked *Record Payment*
button sends:

```
6 accepted, 0 refused
invoice is for      10,000.00
actually received   60,000.00
invoice status:     paid
```

Not one of the six was refused. The guard never saw a problem, because
no two of them could see each other. With the lock removed again to
check the test could fail, the other two behaved identically: an 8,000
purchase order paid 24,000, and a 5,000 invoice credited 30,000.

The fix is `FOR UPDATE` on the parent read, seven times. The second
transaction waits at that line; when the first commits, read committed
gives the waiting statement a fresh look, its `SUM` includes the
payment that just landed, and the ceiling does what it was written to
do — one accepted, five refused, each with the real business message.

It costs nothing, which sounds too convenient, so: the `AFTER` trigger
on the same insert already runs `invoice_refresh_money_state()`, which
does `UPDATE invoices` on that very row. The exclusive lock was always
being taken. It was simply being taken *after* the decision instead of
before it.

Migration 054 has the deadlock argument — one insert into
`invoice_payments` fires two of these guards and so takes two locks,
and trigger firing order is alphabetical, which fixes the order every
writer takes them in.

### The pass over the reset flow

Thirty-four checks, and the whole point of them was to be capable of
failing. Five properties were sabotaged one at a time in a scratch
copy and the suite re-run:

| Property broken on purpose | Caught |
|---|---|
| leak whether an address has an account | yes |
| build the link from the `Host` header | **no, at first** |
| never mark the token consumed | yes |
| leave other sessions alive | yes |
| accept a used link | yes |

The host check was the one that mattered and it was the one that
did not work. It read `host is None or "evil.example.com" not in
host` — which passes when no email is sent at all, and no email
*was* sent, because the harness fetched the CSRF token under one
`Host` and posted it under another. curl keys its cookie jar by
the `Host` header, so the session cookie never went, the post died
on CSRF, and the check congratulated itself on a request that
never reached the code it was testing.

It now asserts the link's host **equals** the configured one, and
separately that an email arrived at all — so "nothing happened"
fails instead of passing.

Fixing the test then found the real fault. With `APP_PUBLIC_URL`
and `APP_HOSTS` both empty — **the shipped default**, and the
state every live instance was in — the link was built from
`security_safe_host()`, which returns the raw `Host` header when
there is no allow-list to check it against. A forged header
therefore produced a genuine email, from the shop's own address,
carrying a link that handed the token to whoever sent it:

```
── link host in the email ──
http://evil.example.com/reset_password.php?t=bc2db943c856e1c1.bc909…
```

`password_reset_base_url()` now reads the allow-list itself and
returns nothing when it is empty. Re-sabotaged to confirm the new
check fails on the old behaviour: it does.
