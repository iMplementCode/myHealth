# Running many shops

One customer, one database, one deployment. This is how a new shop
is set up and why it is arranged this way.

---

## The shape of it

| | |
|---|---|
| Code | One repository, one branch. Every shop runs the same commit. |
| Database | **One per shop.** The connection string is the boundary. |
| Deployment | One Dokploy application per shop, differing only in environment variables. |
| Uploads | One persistent volume per shop. |

Nothing in the application knows about tenants, and that is
deliberate. `DB_NAME` decides which shop a container is serving,
and it is read once, at boot.

### Why not one database with a `tenant_id` column

There are roughly two hundred hand-written queries here and none of
them carries a tenant predicate. Adding one to every query — and to
every query anyone writes from now on, forever — makes isolation a
matter of remembering. **One forgotten `WHERE` shows one shop
another shop's books**, and that is the way almost every SaaS data
leak has happened.

With a database each, a query cannot reach another shop's data
because the data is not there. The wall is enforced by PostgreSQL,
not by code review.

The cost is real and worth stating: migrations run N times (they run
themselves, see below), and a report spanning all shops needs its
own pipeline because you cannot join across databases from the app.

---

## Adding a shop

### 1. Provision the database

From the project root, with the PostgreSQL superuser password in
the environment:

```sh
PGPASSWORD='<postgres superuser password>' \
php deploy/new-tenant.php \
    --slug=mama_electronics \
    --name="Mama Electronics Ltd" \
    --admin-email=owner@mamaelectronics.co.ke \
    --admin-host=implenetwebapp-implenetdb-zlsqsl
```

The slug becomes the database name and the role name, so it is
restricted to a letter followed by letters, digits and underscores.
Anything else is refused — it cannot be bound as a query parameter,
so the pattern is the only thing standing between this and an
injected `CREATE`.

What it does:

- creates the role and the database, owned by that role
- **revokes `CONNECT` from `PUBLIC`**, which PostgreSQL grants by
  default — without this every shop's role could open every other
  shop's database
- applies `schema.sql`, then every migration
- **replaces the seeded administrator password.** Migration 005
  seeds `admin@implement.local` with a password that is written in
  the migration file and therefore known to anyone who has read
  this repository. One install can live with that; ten cannot.
- sets the shop's own name so its first invoice is not headed with
  the software's

It prints the environment block and the first-sign-in password
**once**. Nothing is written to a file and nothing is emailed. If
the output is lost, reset the password rather than go looking for
it.

It refuses to run against a database that already exists. That is
the difference between provisioning a new shop and quietly
re-pointing a live one.

### 2. Create the Dokploy application

Same repository, same branch as every other shop. Paste the
environment block it printed, then add:

```
APP_HOSTS=mamaelectronics.example.com
APP_PUBLIC_URL=https://mamaelectronics.example.com
RECEIPT_WIDTH_MM=80
TRUSTED_PROXIES=<your Traefik subnet>
```

`APP_HOSTS` is not decoration — it is what stops a forged `Host`
header being used against this shop. `APP_PUBLIC_URL` is what a
password-reset link is built from; without one of the two, the
application refuses to email a reset link at all rather than build
it from the header. See `docs/PASSWORD_RESET.md`.

### 3. Mount a volume for uploads — before they upload anything

**The container filesystem is ephemeral.** `public/uploads` holds
payment slips, refund proofs, staff advance receipts and investor
agreements, and without a volume **every deploy deletes all of
them**.

> The **logo is the exception** and no longer needs this. It is
> stored in the shop's own database (migration 058), because a logo
> that vanished on every release was the first symptom of this and
> the most visible: the application fell back to the logo shipped
> with it — one real company's — so every shop printed somebody
> else's brand on its invoices until the owner re-uploaded. There
> is no fallback any more. A shop with no logo shows none.
>
> Everything else in `public/uploads` still depends on the volume.

In Dokploy → the application → Volumes, mount a persistent volume
at:

```
/app/public/uploads
```

Do this before the shop uploads its logo, not after.

### 4. Point the domain at it

Dokploy → Domains. Traefik terminates TLS.

### 5. Hand over the credentials

The email address and password the provisioning script printed.
Tell them to change it at first sign-in.

---

## Starting a shop again

A shop is usually set up, practised on for a week, and then has to
open its books clean. Two ways to do that, both leaving a database
identical to one `new-tenant.php` has just made — the schema, the
starter lists a new shop begins with, and exactly one
administrator.

**From the terminal**, for a shop you are handling yourself:

```sh
php deploy/reset-shop.php --database=mama_electronics \
                          --confirm="Mama Electronics Ltd"
```

Run without `--confirm` it tells you which database and which shop
it would clear, and stops. `--confirm` must be that shop's exact
company name.

**From the application**, Settings → Start Again. Administrators
only. It lists what will be deleted with counts, and asks for the
company name typed out and the password re-entered — the first
blocks the wrong tab, the second blocks an unlocked screen.

### What it does, and what it does not

Deleted: every customer, product, invoice, quote, payment, expense,
stock movement, note, delivery, warranty and audit entry; every
other user account; **and every uploaded file** — payment slips,
refund proofs, investor agreements, product images. A reset that
left those on disk would hand the next owner the last one's bank
statements.

Kept: your own account and password, the shop's name, and the
seeded starter lists. Document numbering restarts, so the first
real invoice is number one rather than continuing from the practice
run. Everyone else is signed out.

**There is no undo.** `deploy/backup.sh` first, every time. The
only thing that survives the wipe is one audit entry saying who did
it and when.

---

## Day-to-day

**Deploying a change to everyone.** Push once. Each shop's
application redeploys and runs its own migrations on the way up —
the start command does that, so a schema change reaches ten
databases without anybody running anything by hand.

**Migrating one shop by hand**, if you ever need to:

```sh
php database_structure/migrate.php \
    --host=<db host> --database=mama_electronics --user=mama_electronics \
    --password-from-env=THAT_SHOPS_PASSWORD
```

`--password-from-env` names a variable to read rather than taking
the password on the command line, because anything in `argv` is
visible to every other user on the box through `ps`.

**Backups.** `deploy/backup.sh` per database. A backup nobody has
restored is a hope, not a backup — restore one into a scratch
database and sign into it before you rely on the schedule.

**Suspending a late payer.** Stop the Dokploy application. Never
drop the database: the business that comes back after two months is
worth more than the disk.

---

## Where this stops working

This arrangement is comfortable into the low dozens of shops. Past
that, two things start to hurt:

- **Containers.** Ten PHP processes on one VPS is fine; fifty is
  not. That is the point to move to nginx + php-fpm
  (`deploy/nginx.conf`, already written and tested) rather than a
  development server per shop.
- **Manual onboarding.** Provisioning is one command, but creating
  the Dokploy application and the domain is still by hand. If
  customers start signing themselves up, that is when the
  control-plane design in `docs/SCALING.md` — one deployment
  resolving the tenant from the hostname — earns its complexity.

Neither is a reason to build them today.
