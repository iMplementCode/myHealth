# Notifications

The system already knew all of it. Stock had run out, an invoice had
been overdue since March, a batch of cameras was going out of date in
a fortnight — every one of those facts was sitting in a table, and
none of them ever said anything. You had to think to go and look,
which means you found out about the overdue invoice when the customer
mentioned it.

Now it says so: a bell in the top bar with a count, a panel at the
top of the dashboard, and a page listing everything open.

---

## What it watches

Ten rules, all in `notification_rules()` in `includes/notifications.php`.

| Alert | When | Severity | Who sees it |
|---|---|---|---|
| Invoice overdue | past due, balance outstanding | critical past 30 days, else warning | everyone |
| Invoice due soon | due within 7 days | info | everyone |
| Out of stock | a stocked product at zero | critical | Manager |
| Low stock | at or below its reorder level | warning | Manager |
| Batch expired | past its expiry, stock remaining | critical | Manager |
| Batch expiring | expires within 30 days | warning | Manager |
| Warranty expiring | a delivered serial, within 30 days | info | everyone |
| Contract due | ready to invoice within 3 days | warning if late, else info | everyone |
| Quote expiring | expires within 7 days | info | Salesperson |
| Supplier unpaid | a PO 7+ days old, not fully paid | warning | Manager |

**Audience is not decoration.** A salesperson has no business seeing
the company's payables, and an administrator sees everything.

---

## Three things that make it useful rather than noise

**It does not repeat itself.** The scanner runs every few minutes.
An invoice overdue since March must be one row, not thousands, so
every rule returns a `key` and the write is an upsert on it. The
title is rewritten in place as the figures move; `created_at` stays
at the moment the trouble started, which is the date worth knowing.

**It takes itself back.** Each rule reports the complete current set
of its own keys, and anything of that kind not in the set is stamped
`resolved_at`. Pay the invoice, receive the stock, and the alert goes
away by itself. Nobody tidies up after it — and an alert nobody can
clear is one people learn to ignore, which makes the real one
invisible too. The row is kept, because *this went unpaid for six
weeks* is worth being able to look up.

**Read is per person.** A shared alert one manager dismisses is still
waiting for the other. That is `notification_reads`.

---

## When it runs

**On a page load, at most once every ten minutes.** That is what
makes the bell work with nothing configured — open the site and it is
current. The claim is one atomic statement:

```sql
UPDATE notification_scan_state
   SET last_run_at = CURRENT_TIMESTAMP
 WHERE last_run_at < CURRENT_TIMESTAMP - '600 seconds'::INTERVAL
 RETURNING 1
```

Exactly one request gets a row back and does the work; every other
request gets nothing and carries on rendering. No lock to release,
and nothing left half-done if PHP dies partway.

**From cron, which is better.** On the VPS, add this to root's
crontab and the first person through the door in the morning stops
paying for the scan in page latency:

```cron
*/10 * * * * php /var/www/erp/deploy/notify-scan.php --quiet >> /var/log/erp-notify.log 2>&1
```

It exits non-zero if it could not run, so cron's `MAILTO` says
something useful. A rule that fails — usually a table a later
migration adds — takes itself out and is reported on stderr; it does
not take the scan down with it.

**By hand.** *Check now* on the notifications page, for when you have
just received stock and want the alert gone this minute.

---

## Adding an alert

Add a rule to the array. Everything else — dedupe, resolution, the
bell, the count, the page, the audience filter — already works.

```php
[
    'kind'         => 'grn_unbilled',
    'subject_type' => 'grn',
    'audience'     => ROLE_MANAGER,
    'sql' => "
        SELECT g.grn_id                        AS subject_id,
               'grn_unbilled:' || g.grn_id     AS key,
               'warning'                       AS severity,
               'GRN ' || g.grn_number || ' has no supplier bill' AS title,
               s.name || ' delivered on ' || TO_CHAR(g.received_date, 'DD Mon') AS body,
               'modules/purchasing/view_grn.php?grn_id=' || g.grn_id AS url
          FROM grns g
          JOIN suppliers s ON s.supplier_id = g.supplier_id
         WHERE ...",
],
```

The `key` must be stable for the same condition and unique across
kinds — `kind:subject_id` is the convention and there is no reason to
depart from it.

---

## Email and SMS

Not built. The columns are there and deliberately so:
`notifications.emailed_at` and `notifications.sms_at`, both NULL.

The shape it should take, when you come to it:

1. `deploy/notify-scan.php` already runs on a schedule and already
   knows what is open. Sending belongs there, after the scan, in one
   pass: select open notifications where `emailed_at IS NULL`, send,
   stamp the column. The stamp is what stops the same alert going out
   every ten minutes for a fortnight.
2. **Send a digest, not a message per alert.** Eleven overdue invoices
   is one email with eleven lines. A system that sends eleven emails
   gets filtered, and then the twelfth — the one that mattered — is
   filtered too.
3. **Severity decides the channel.** Critical is worth an SMS;
   everything else is worth a line in the morning email. An SMS at
   9pm about a quote expiring next week teaches people to ignore SMS.
4. **Email:** use an authenticated SMTP relay on port 587. Outbound
   port 25 is commonly blocked on a VPS, Hostinger's included, and a
   mail server on a fresh IP with no reputation gets filtered even
   where it is open.
5. **SMS in Kenya:** Africa's Talking or Safaricom's bulk SMS are the
   usual choices. Both are an HTTP POST with an API key, which
   belongs in `.env` beside the database password — never in the
   repository.
6. Who gets what is the one design decision this schema does not
   already make. `audience` is a role, not a person; a
   `notification_subscriptions` table keyed by user and kind is the
   natural next step, and it is worth having before the first email
   goes out rather than after.

---

## Where things are

| | |
|---|---|
| Rules and engine | `includes/notifications.php` |
| Cron entry point | `deploy/notify-scan.php` |
| The bell | `includes/navbar.php` |
| Dashboard panel | `public/dashboard/index.php` |
| The page | `public/notifications/index.php` |
| Tables | migration `044_the_system_speaks_up.sql` |
| Test | `t_notify.py`, `t_subpath.py` |

The test drives real conditions in and out of existence — puts a
product's stock to zero, restocks it, empties it again — and asks
what the bell says at each step, for an administrator and for a
salesperson. It covers the three properties above directly: five
scans produce one row, a restock resolves it, and a relapse reopens
the same row rather than adding another.

---

## A note for anyone adding a page

`redirect()` runs what it is given through `url()` already. Pass it a
**bare path**:

```php
redirect('notifications/index.php');        // right
redirect(url('notifications/index.php'));   // prefixes APP_URL twice
```

The wrong one is invisible on a server rooted at the application —
`APP_URL` is the empty string there, so twice is the same as once,
and every test passes. Under XAMPP, where the project sits at
`/projects/implement_erp/public`, it produced

```
/projects/implement_erp/public/projects/implement_erp/public/notifications/index.php
```

and Apache answered *Object not found!*. `t_subpath.py` serves the
application from a subdirectory and follows every redirect this
feature can produce, which is the only way that class of mistake gets
caught before somebody hits it.

And never redirect to `HTTP_REFERER`. Besides coming out mangled for
the same reason, a redirect that follows a header the caller controls
is an open redirect — a phishing link wearing this application's
domain.
