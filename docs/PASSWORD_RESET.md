# Forgotten passwords

Somebody forgets their password, types their email address, gets a
link, sets a new one. This is how to switch that on for a shop and
how to tell whether it is really working.

---

## What it does

`login.php` has a **Forgot your password?** link. It leads to
`forgot_password.php`, which takes an email address and — if that
address belongs to an active account here — emails a link to
`reset_password.php`. The link works **once** and lasts **one
hour**.

Setting a new password through it also:

- signs out every other session that account had, and forgets its
  "remember me" tokens
- cancels any half-finished sign-in code
- clears the failed-sign-in lockout on that address
- emails the account a *"your password was changed"* notice, which
  is the warning if it was not them

Nothing here lets anybody reset anybody else's password. The link
goes to the address on the account and nowhere else.

---

## Switching it on

Three things, in this order.

### 1. Run the migration

Adds the `password_resets` table (migration
`059_letting_somebody_back_in.sql`).

**On Dokploy you do not have to do anything** — the start command
runs `database_structure/migrate.php` on every deploy, so the
table appears on the release that carries this change.

By hand, for one shop:

```sh
php database_structure/migrate.php \
    --host=<db host> --database=mama_electronics --user=mama_electronics \
    --password-from-env=THAT_SHOPS_PASSWORD
```

Until the table exists the form says the feature is not switched
on rather than failing in front of a customer.

### 2. Configure mail

The same SMTP settings the sign-in codes use. If two-factor
sign-in already works on this shop, this step is already done.

```
SMTP_HOST=smtp.yourprovider.co.ke
SMTP_PORT=587
SMTP_USER=no-reply@mamaelectronics.co.ke
SMTP_PASS=<the mailbox password>
SMTP_SECURE=tls
MAIL_FROM=no-reply@mamaelectronics.co.ke
MAIL_FROM_NAME=Mama Electronics
```

In a hosting panel's environment editor paste values **plain, with
no quotes**. Quotes are a `.env` file rule, not an environment
variable rule; a panel will hand the application the quote marks
as part of the password.

Then prove it, against a real inbox:

```sh
php deploy/check-mail.php you@yourdomain.co.ke
```

It sends a real message and prints what the mail server actually
said. Do not skip this. "The settings look right" has never
delivered an email.

### 3. Set the public URL

```
APP_PUBLIC_URL=https://mamaelectronics.example.com
```

**This is the one that matters.** No trailing slash, and the
scheme the customer really browses with (`https://`, in
production).

The link in the email is built from this. It is *not* built from
the `Host` header of the request, and that is deliberate:

> The `Host` header is whatever the caller typed. If the link were
> built from it, anybody could ask for a reset on somebody else's
> address while claiming to be `evil.example.com`, and the real
> owner would receive a genuine email from their own shop
> containing a link that hands the token to the attacker. That is
> a complete account takeover with nothing for the victim to
> notice.

If `APP_PUBLIC_URL` is empty the application falls back to
`APP_HOSTS` — the allow-list — and with *neither* set it **refuses
to send at all** and says so in the error log. It will not guess.

So either set `APP_PUBLIC_URL`, or set:

```
APP_HOSTS=mamaelectronics.example.com
```

Setting both is better. `APP_HOSTS` also protects redirects
elsewhere in the application.

---

## Checking it, on the server

Five minutes, once per shop, with a real account you control.

1. Open `/forgot_password.php`. If it says *"Password reset by
   email is not switched on"*, mail is not configured or the
   migration has not run — go back to step 1 or 2.
2. Type the address of an account and submit. **Read the email.**
   Hover the link and check the hostname is the shop's own
   domain, not a bare IP and not something else.
3. Follow it, set a password, sign in with it.
4. Open the link a second time. It must say expired or used.
5. Type an address that has *no* account. The page must say
   exactly the same words as it did in step 2.

Step 5 is not a formality. If the two answers differ, the form
becomes a way for anybody on the internet to find out who has an
account — which for a business is a staff list.

---

## The settings, in one place

| Variable | Needed | What it does |
|---|---|---|
| `SMTP_HOST`, `MAIL_FROM` | **yes** | Without both, the feature switches itself off and says so on the page. |
| `SMTP_PORT`, `SMTP_USER`, `SMTP_PASS`, `SMTP_SECURE` | usually | Whatever your provider gives you. |
| `APP_PUBLIC_URL` | **yes** | The base of the emailed link. Set it or set `APP_HOSTS`. |
| `APP_HOSTS` | strongly | The hostnames this shop answers to. The fallback for the link, and a forged-`Host` guard everywhere else. |

There is no on/off switch, unlike two-factor sign-in. A reset link
that cannot be delivered inconveniences one person; a sign-in code
that cannot be delivered locks out the whole business, which is
why that one has `TWO_FACTOR_ENABLED` and this one does not.

---

## When somebody says it did not arrive

In order of how often it is actually the cause:

1. **Spam folder.** Most of the time, on a new domain.
2. **The address is not on the account**, or the account is
   deactivated. Both produce the same reassuring message on the
   page, by design. Check the user under **Users → Manage
   Users**.
3. **They asked more than three times in fifteen minutes.**
   Further requests are silently dropped — again, the same
   message. Wait it out.
4. **Mail is broken.** `php deploy/check-mail.php <address>`, and
   read the container log for lines starting `[RESET]`.

While it is being sorted out, an administrator can set a password
directly under **Users → Manage Users**. That path never depended
on email.

---

## The way in, if you are curious

- `includes/password_reset.php` — every decision; the page files
  only display what it returns
- `public/forgot_password.php`, `public/reset_password.php` — the
  two pages
- `database_structure/migrations/059_letting_somebody_back_in.sql`
  — the table

The token in the link is two halves: a **selector**, stored in
plain text and indexed, and a **verifier**, stored only as a hash.
Somebody who reads the table — a stolen backup, a SQL injection
somewhere else — gets no usable link out of it, and the lookup is
still a single indexed row rather than a scan of every hash in the
table. `consumed_at` is set in the same transaction as the
password change, so the two cannot come apart.
