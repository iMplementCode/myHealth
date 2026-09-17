# myHealth

A pharmacy management system, built so it can grow into hospital
management without a rewrite.

Plain PHP 8 (PDO) and PostgreSQL. No framework, no build step, no
JavaScript bundler — a page is a file, and what the browser runs is
what is in the repository.

---

## Where this code came from

This is not greenfield, and pretending otherwise would mislead
whoever reads it next.

myHealth started as a copy of `iMplementCode/implement_er`, an ERP
written for a CCTV and security business. That sounds like an odd
parent for a pharmacy, and the reason it is a good one is narrow but
real: **both trades keep stock, buy it from suppliers, sell it, and
have to account for it.** Everything in that intersection was already
written, already attacked in a penetration pass, and already running
in production for paying customers:

| Carried over | |
|---|---|
| Sign-in | two-factor by email, password reset, database-backed sessions, a lockout that survives a discarded cookie |
| Access | roles, per-page guards, an audit log |
| Buying | suppliers, purchase orders, goods received notes, debit notes, supplier payments |
| Money | cash book, expenses, payments, credit notes, P&L, VAT, receivables and payables |
| Documents | PDF printing, and thermal till receipts for a counter printer |
| Operations | one database per site, migrations that run themselves on deploy, backups, notifications |

What did **not** carry over is in
`database_structure/migrations/060_this_shop_sells_medicine.sql`:
serial numbers, warranty notes and service contracts. A recorder is a
specific box with a number on the back; a paracetamol tablet is not.
Medicines are identified by **batch** — which the parent already
tracked, with expiry dates, because camera stock had batch costs.

### The cost of the shortcut, stated plainly

A fork does not receive its parent's bug fixes. A security fix made
in `implement_er` has to be made here too, by hand, by somebody who
remembers both exist. The shared files most likely to matter are
`includes/auth.php`, `includes/security.php`, `includes/mailer.php`
and `includes/password_reset.php`.

---

## What it does

Pharmacy first:

- a drug catalogue that knows about generic name, strength and form
- stock received into batches and dispensed oldest-expiry-first
- a counter screen — find the drug, take the money, print the slip
- expiry warnings while the stock can still be moved, not after

Designed for but deliberately not yet built: wards, appointments, lab
orders. The patient record is shaped to carry them.

---

## Quick start

### Requirements
- PHP **8.1+** (developed on 8.4) with `pdo_pgsql`
- PostgreSQL **13+**
- Composer

### Set it up

```sh
composer install              # vendor/ is not tracked
cp .env.example .env          # then fill in the database block
createdb myhealth
psql -d myhealth -f database_structure/schema.sql
php database_structure/migrate.php
php -S localhost:8000 -t public public/router.php
```

`.env` holds database credentials and is **never** committed.

### Deploying

`docs/DEPLOYMENT.md` for one pharmacy, `docs/MULTI_TENANT.md` for
several. Migrations run themselves on every deploy, so a schema
change reaches every site without anybody logging in to a server.

---

## Reading the code

| | |
|---|---|
| `docs/ARCHITECTURE.md` | how a request is served |
| `docs/DATABASE.md` | the tables, and why they are shaped that way |
| `docs/SECURITY.md` | every control, and the passes that tried to break them |
| `docs/PASSWORD_RESET.md` | switching reset-by-email on for a site |
| `docs/MULTI_TENANT.md` | running more than one pharmacy |

Start with `includes/bootstrap.php`. Every page includes it, and it is
what makes a page private by default: a new file is closed unless it
says `define('APP_PUBLIC_PAGE', true)` before including.
