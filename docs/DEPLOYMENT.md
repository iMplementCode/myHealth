# Deployment and edge security

What this application cannot do for itself, and how to put the
rest around it.

The code enforces authentication, authorisation, CSRF, rate
limits, a content security policy and a request firewall. Those
are the last layer, not the only one. TLS termination, a web
application firewall, DDoS scrubbing, a CDN and a load balancer
all live **in front of** the origin, and no amount of PHP can
substitute for them.

This document is the operator's half of the job.

---

## Somewhere to put an uploaded file

A logo, a product photo, a proof of payment: all three fail the same
way, and the folder is nearly always the reason.

**Upload folders are not shipped in git, on purpose.** A folder that
arrives from a checkout belongs to whoever ran the pull; on a shared
host that is not the web server, and every upload into it fails with
`move_uploaded_file(): Unable to move ...`. The application creates
each one on first use instead, so it belongs to the process that has
to write to it.

Only `public/uploads/` itself has to exist. `products/`, `company/`
and `payments/` are made on demand.

If an upload does fail, the message now says which folder, who owns
it, and the command that fixes it — rather than "Failed to save logo
to server", which is true and useless. `upload_dir_problem()` in
`includes/uploads.php` tells the cases apart:

| What is wrong | What it says |
|---|---|
| The folder is missing and the parent is read-only | names the parent, because that is where the fix goes |
| The folder exists but belongs to another user | names the owner and gives the `chmod` |
| The disk is full | says so, rather than blaming permissions |

It tries one `chmod` before complaining — where the web server owns
the parent or shares its group, that quietly fixes it and nobody has
to be told anything.

```sh
php deploy/check-db.php     # has an "Uploads" section that checks all four
```

## When the database is behind the code

Every deployment has two halves: the files and the schema. Pulling
the files is the half people remember.

Four bug reports in one week had a single cause — migrations that
had not been run:

| What was seen | What it was |
|---|---|
| "Could not save changes" adding a supplier | `suppliers.location` (036) |
| "This page isn't working" on every purchasing document | the same column |
| A changed logo that would not change | `company_settings.extra_emails` (041) |

None of them said so. The application knew exactly which files were
outstanding and kept it to itself, so the time went on looking for a
fault in the form, the browser and the logo file.

**Three things now make that impossible to miss.**

1. **A banner on every page, for administrators**, naming how many
   updates are outstanding, the exact command, and the first few
   filenames. `pending_migrations()` compares the migrations folder
   against `schema_migrations`; it costs one directory listing and
   one indexed read, cached per request, and returns nothing at all
   on a database that cannot answer — a broken check must not become
   a broken page.

2. **An undefined column or table now explains itself.**
   `db_rule_message()` turns SQLSTATE 42703 and 42P01 into *"This
   needs a database update that has not been applied yet (location).
   Run the outstanding migrations — nothing you entered is wrong."*
   The last clause matters: the old message sent people looking at
   their own input for a fault that was not there.

3. **Writes are guarded the way reads already were.** Reading a
   column that might not exist has gone through `column_exists()`
   for a long time. Writing one had not, which is how adding a
   supplier and saving the company settings both failed outright.
   Both now write to whichever column is actually there, and the
   settings form hides the field a missing migration would make
   pointless rather than silently discarding what is typed into it.

### Running them

```sh
php database_structure/migrate.php
```

Safe at any time. It applies only what is missing, in order, and
records each file as it goes. Run it after every `git pull`.

### Running them automatically

On a container host — Dokploy, or anything else that builds from
`nixpacks.toml` — you do not run it by hand at all. The start command
runs it before the web server begins listening:

```toml
[start]
cmd = "php database_structure/migrate.php || true; PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:3000 -t public public/router.php"
```

Four things about that line are deliberate, and each one is there
because the obvious version of it is wrong.

**`|| true`.** A migration that fails must not stop the web server
from starting. The application is written to survive a database one
migration behind, so an old schema is a shop that can still invoice;
a container that refuses to start is a shop that cannot trade at all.
The failure is not hidden — it is printed above, in the deploy log,
which is where it is looked for.

**It waits for the database, briefly.** On a fresh deploy the
application container is frequently up before the database container
is. Connecting once and giving up would skip the migrations on
exactly the deploy that needed them — and *nothing would look
broken*, because the application degrades quietly when the schema is
behind. That is how the two-factor table came to be missing while
every page still rendered. `MIGRATE_DB_WAIT` (default 20s) is the
budget; it costs nothing when the database is already there.

**It takes an advisory lock.** A rolling deploy briefly runs two
containers, both reading `schema_migrations`, both seeing the same
file unapplied, both running it. `pg_try_advisory_lock` settles who
goes first; the other waits, then finds there is nothing to do.
Failing to get the lock is not an error — the other container is
doing the work — so it exits 0 and lets the server start.

**It fails fast when the database is genuinely down.** See
`DB_CONNECT_TIMEOUT` below. Without a connect timeout libpq waits on
the operating system's TCP timeout, which is minutes, and the deploy
is marked failed long before the server ever listens.

If you would rather run migrations by hand on a container host,
Dokploy's **Terminal** tab on the application opens a shell in the
running container; `php database_structure/migrate.php` there does
the same thing, and the lock means it is safe to do so even while a
deploy is in flight.


## 0. Where the files go

Everything below depends on this, and it is the one thing that
cannot be fixed later with a config setting.

```
  erp/                    ← the application. NOT served. Ever.
  ├── config.php              constants, and where the two roots are defined
  ├── .env                    the database password              ← chmod 600
  ├── includes/               every library file
  ├── vendor/                 composer dependencies
  ├── database_structure/     migrations and the schema
  ├── deploy/                 server configs, backup and check scripts
  ├── docs/                   this file
  └── public/             ← the document root. This, and only this.
      ├── index.php login.php logout.php …
      ├── dashboard/ invoices/ reports/ finance/ modules/ …
      ├── assets/             css, js, images
      ├── uploads/            what people upload      ← PHP execution off
      └── .htaccess           the second line of defence
```

**Two roots, and the gap between them is the point.** `BASE_PATH`
is the application; `PUBLIC_PATH` is the document root. A request
can only ever name something under `PUBLIC_PATH`. There is no
rule to get wrong, no `.htaccess` to be ignored, no directive a
host might have disabled: `.env` is not under the document root,
so no URL addresses it.

It was one root until this was written, with the whole project
inside the web root and `.htaccess` standing between the internet
and the database password. Those rules are still there and still
useful, but they are now the *second* line. Any of these and a
single root leaks the password: a host shipping `AllowOverride
None`, a move from Apache to nginx (which ignores `.htaccess`
entirely), a rule that does not fire because the request was
routed differently. Two roots have nothing to disable.

### On a VPS

A VPS already has the layout above — clone the project to
`/var/www/erp` and root the web server at `/var/www/erp/public`.
Nothing needs rearranging.

What a VPS adds is that PHP-FPM runs as its own user, so the code
can be readable by the web server and writable by nobody. That is
worth taking: `public/uploads` is the only path in the tree this
application ever writes to, so everything else can be closed.

```bash
cd /var/www/erp
bash deploy/vps-layout.sh --dry-run    # what it would do
bash deploy/vps-layout.sh              # do it
```

It works out which user the web server runs as, refuses if the
application is inside a document root, sets the code to
`root:www-data` 750/640, `.env` to 640, and the four upload
directories to `www-data` 2775.

Re-run it after every `git pull` — files arriving from a pull belong
to whoever ran it, so a pull quietly undoes this. The script is
idempotent.

[`HOSTINGER_VPS.md`](HOSTINGER_VPS.md) is the full walkthrough from a
fresh Ubuntu box to a running site: packages, PostgreSQL, PHP-FPM,
the nginx site, TLS, firewalls and backups.

### On cPanel

cPanel serves `~/public_html`, and for the primary domain that
cannot be moved. So put the application *beside* it:

```
  ~/erp/              the application (upload the whole project here)
  ~/public_html  ->   ~/erp/public          a symlink
```

`bash deploy/cpanel-layout.sh` does exactly that, and refuses to
run if the application is inside the document root. It moves an
existing `public_html` aside rather than deleting it, sets `.env`
to 600, and prints the two lists that matter: what is served and
what is not.

**If the host will not follow a symlinked document root** — some
shared hosts run with `FollowSymLinks` off, and the script says so
rather than leaving you a 403 with no explanation — copy instead
and tell the application where the copy went:

```bash
cp -r ~/erp/public/. ~/public_html/
echo "PUBLIC_PATH=$HOME/public_html" >> ~/erp/.env
```

That trades a re-copy on every deployment for the symlink. The
application is still outside the document root either way, which
is the part that matters.

**For an addon domain or subdomain** none of this applies: cPanel
lets you set the document root when you create it. Point it
straight at `~/erp/public` and there is nothing else to do.

### Running it locally on XAMPP or WAMP

XAMPP serves `C:\xampp\htdocs`, so the project usually ends up
*inside* the web root — the layout this whole section says to
avoid. That is fine for a machine nobody else can reach, and the
`.htaccess` pair handles it: the application root denies
everything, `public/` grants itself back.

The URL is the one thing that changes. With the project at
`C:\xampp\htdocs\implement_er`:

```
  http://localhost/implement_er/public/          ← the application
  http://localhost/implement_er/                 ← 403, correctly
```

`/implement_er/` answering **403 Forbidden** is the deny-all
doing its job, not a fault. Add `/public/`.

Better, and closer to production, is to give it a virtual host so
the document root is `public/` and the URL has nothing extra in
it. In `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName erp.local
    DocumentRoot "C:/xampp/htdocs/implement_er/public"
    <Directory "C:/xampp/htdocs/implement_er/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

then add `127.0.0.1  erp.local` to
`C:\Windows\System32\drivers\etc\hosts`, restart Apache, and open
<http://erp.local/>. Note this needs the project **outside**
`htdocs` to be a true rehearsal of production — but even inside,
the document root is now `public/` and nothing above it is
reachable.

### Checking it

Two tests, because there are two things to get wrong.

`t_docroot.py` proves the **layout**: it asks the running server
for every private file by its old URL and by eight traversal
spellings, and requires that none of them ever return the file's
contents. Put `.env` back in the document root and it fails with
`HTTP 200` beside it.

`t_htaccess.py` proves the **rules**, under a real Apache, in both
deployments at once — document root at `public/`, and the whole
project sitting inside the web root. The second is why it exists:
`Require all denied` at the application root is inherited into
`public/`, and the first version of this shipped without the
matching `Require all granted`. Every page answered 403 with an
Apache error page, secrets safe and application unreachable
alongside them. Remove that one line and the test fails on the
three pages that stop being served.

Neither can prove *your* production Apache is configured
correctly. After deploying, fetch `https://your-domain/.env`
yourself: 403 or 404 is right, and anything showing `DB_PASS`
means the application is inside the document root — go back to
the top of this section.

---

## 1. The shape of it

```
                    ┌──────────────────────────────┐
   visitors ──────► │  CDN + WAF + DDoS scrubbing  │   Cloudflare (or equivalent)
                    │  TLS terminates here         │
                    └───────────────┬──────────────┘
                                    │  TLS again, origin certificate
                    ┌───────────────▼──────────────┐
                    │  Load balancer               │   optional, for more than one node
                    └───────────────┬──────────────┘
                       ┌────────────┼────────────┐
                    ┌──▼──┐      ┌──▼──┐      ┌──▼──┐
                    │ app │      │ app │      │ app │   nginx + PHP-FPM, stateless
                    └──┬──┘      └──┬──┘      └──┬──┘
                       └────────────┼────────────┘
                    ┌───────────────▼──────────────┐
                    │  PostgreSQL (primary)        │   sessions, cache, data
                    │       └─ read replica        │   optional
                    └──────────────────────────────┘
```

The application tier is **stateless**: sessions, rate-limit
counters and the shared cache all live in PostgreSQL, so any node
can serve any request. Nodes can be added, restarted or lost
without signing anyone out, and the load balancer does not need
sticky sessions.

---

## 2. TLS

**At the edge.** Cloudflare (or your CDN) terminates TLS for
visitors. Set the SSL mode to **Full (strict)** — anything less
lets the edge talk to your origin unencrypted or with an
unverified certificate, which is most of the point thrown away.

**At the origin.** A real certificate, not a self-signed one:

```bash
apt install certbot python3-certbot-nginx
certbot --nginx -d erp.example.com
```

`deploy/nginx.conf` and `deploy/apache-vhost.conf` set TLS 1.2 as
the floor, disable session tickets, and enable OCSP stapling.

**In the application.** `FORCE_HTTPS=true` (the default) redirects
any plain request and sends HSTS. Localhost and private addresses
are exempt, so development still works.

Turn HSTS preloading on only when you are sure — `HSTS_PRELOAD=true`
is a commitment browsers cache for months and it applies to every
subdomain.

---

## 3. Trusted proxies — do this first

Behind a CDN, `REMOTE_ADDR` is the CDN. Every visitor in the world
then shares one address, and every rate limit either locks out
everybody at once or nobody at all. Audit entries record the CDN
too, which makes them nearly useless.

Set the ranges you actually sit behind:

```
TRUSTED_PROXIES=173.245.48.0/20,103.21.244.0/22,103.22.200.0/22,...
```

Current list: <https://www.cloudflare.com/ips/>. The application
believes `CF-Connecting-IP` and `X-Forwarded-For` **only** from
these addresses; from anywhere else the headers are ignored, which
is what stops a client forging its own IP. With no list set,
neither header is trusted at all — the right default for a server
exposed directly.

Do the same for the web server (`set_real_ip_from` in nginx,
`RemoteIPTrustedProxy` in Apache) so its rate limits and logs agree
with the application's.

---

## 4. Web application firewall

Two places, and both are worth having.

**At the edge.** Cloudflare's Managed Ruleset, on. It never sees
your origin's load because it blocks before the request leaves the
edge. Turn on the OWASP Core Ruleset at paranoia level 1 and leave
the rest at defaults.

**At the origin.** `deploy/modsecurity-erp.conf`. This is the
layer that still applies when somebody finds the origin's IP
address and goes around the CDN — and they will, because the
address is in DNS history, in old certificates, and in the headers
of any email the server has ever sent.

```bash
apt install libnginx-mod-http-modsecurity modsecurity-crs
# include deploy/modsecurity-erp.conf after the CRS setup include
```

**Start in `DetectionOnly` and read the log for a week.** An ERP
is full of text that looks like an attack — a product called
`1/2" UNION`, a note reading *"SELECT the blue one"*, a JSON blob
of invoice lines. A rule that blocks a real invoice gets the whole
firewall switched off by someone with a deadline, which is worse
than never installing it. The tuning exclusions in that file cover
the fields this application is known to trip on.

**Lock the origin down** so the edge cannot be bypassed:

```
# Only the CDN may reach the origin at all.
ufw default deny incoming
ufw allow from <cloudflare-range> to any port 443 proto tcp   # repeat per range
ufw allow <your-admin-ip> to any port 22 proto tcp
ufw enable
```

Cloudflare Tunnel does the same thing without exposing a public
port at all, and is the better answer if you can use it.

---

## 5. DDoS mitigation

Volumetric attacks are absorbed at the edge; nothing you run on
one server will out-bandwidth a botnet. What the origin does is
survive whatever gets through.

**At the edge:** Cloudflare's DDoS protection is on by default.
Add rate-limiting rules for the paths that cost the most:

| Path | Suggested limit | Why |
|---|---|---|
| `/login.php` | 10 per minute per IP | password guessing |
| `/api/orders.php` | 60 per minute per IP | writes to the database |
| `/api/products.php` | 240 per minute per IP | the scrapeable one |
| everything else | 300 per minute per IP | generous for a person |

Turn on **Under Attack mode** when something is actually
happening; it puts an interstitial in front of every request.

**At the origin:** `deploy/nginx.conf` sets `limit_req`,
`limit_conn` and short header/body timeouts. The timeouts are what
stop Slowloris — an attack that needs almost no bandwidth and kills
a server by opening connections and never finishing them.

**In the application:** the rate limiter in `includes/security.php`
is shared across nodes through the database, so the limit is the
limit however many servers are running. It fails *open* if the
database is unreachable, on the grounds that a business must not
stop trading because a counter table is busy.

---

## 6. Caching, in layers

| Layer | What it holds | Where |
|---|---|---|
| CDN edge | CSS, JS, fonts, images | Cloudflare |
| Browser | the same, `immutable` for a year | client |
| APCu | computed report figures | per node |
| `app_cache` | the same, shared between nodes | PostgreSQL |
| per-request | anything asked for twice on one page | PHP array |

Static assets are safe to cache hard because every URL is stamped
with the file's modification time (`asset()` in
`includes/functions.php`): change the file, change the URL. The
configs set `max-age=31536000, immutable`.

**Pages are never cached.** Every authenticated response carries
`Cache-Control: no-store`. This matters more than it sounds:
an invoice cached at an edge node is an invoice served to the next
person through that node. Cloudflare's default is not to cache
HTML, and there is no reason to change it.

Application caching is for **aggregates, never documents** —
`cache_remember()` should hold last month's sales total, never a
row that a permission check applies to.

---

## 7. Load balancing and a stateless tier

Sessions are in PostgreSQL (migration 022), so:

- no sticky sessions needed — configure round-robin or least-connections
- a node can be replaced mid-conversation without signing anyone out
- deactivating a user ends their sessions on every node at once

**Health checks.** Point the load balancer at:

```
GET /health.php            → 200 while PHP is alive
GET /health.php?deep=1     → 200 only when the database, migrations,
                             shared sessions and uploads are all good
```

Use the shallow check for liveness (restart the node) and the deep
one for readiness (take it out of rotation). Using the deep check
for liveness means a database blip restarts every node at once.

Set `HEALTH_TOKEN` in `.env` if the deep check should not be
readable by the internet; send it as `X-Health-Token`.

**Uploads on more than one node.** `uploads/` is local disk, so a
file written on node A is a broken image on node B. Before you
scale past one node, put uploads on shared storage (NFS, EFS) or
object storage (S3, R2). Nothing else in the application holds
state on disk.

---

## 8. The database

- **Least privilege.** The application's role owns nothing:
  ```sql
  CREATE ROLE runai_app LOGIN PASSWORD '…';
  GRANT CONNECT ON DATABASE runai_technologies TO runai_app;
  GRANT USAGE ON SCHEMA public TO runai_app;
  GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO runai_app;
  GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO runai_app;
  -- migrations run as a different, owning role
  ```
  No `CREATE`, no `DROP`, no superuser. An SQL injection that got
  through everything else could then still only read and write the
  rows the application already reads and writes.
- **Never reachable from the internet.** `listen_addresses` on a
  private interface, and a firewall rule per app node.
- **TLS between app and database** if they are on different hosts:
  `sslmode=verify-full` in the connection string.
- **Backups**, tested by restoring them. A backup nobody has
  restored is a hypothesis.
- **Read replica** (optional): reports can be pointed at it. Not
  sessions or rate limits — those need the primary.

---

## 9. Environment

`.env` holds the database password and must never be committed —
it is gitignored, and `.env.example` is the template. On the
server:

```bash
chown root:www-data .env && chmod 640 .env
```

Settings this document refers to:

| Key | Default | What it does |
|---|---|---|
| `FORCE_HTTPS` | `true` | redirect http and send HSTS |
| `HSTS_MAX_AGE` | `31536000` | one year |
| `HSTS_PRELOAD` | `false` | only when you mean it |
| `TRUSTED_PROXIES` | *(empty)* | CIDRs allowed to set the client IP |
| `APP_HOSTS` | *(empty)* | hostnames this app answers to |
| `CSP_REPORT_ONLY` | `false` | roll the policy out without enforcing |
| `CSP_REPORT_URI` | *(empty)* | where violations are reported |
| `SESSION_DRIVER_DB` | `true` | sessions in PostgreSQL |
| `IDLE_TIMEOUT_MINUTES` | `30` | automatic sign-out |
| `LOGIN_MAX_ATTEMPTS_PER_IP` | `20` | per lockout window |
| `PASSWORD_MIN_LENGTH` | `12` | |
| `HEALTH_TOKEN` | *(empty)* | gates the deep health check |
| `CHART_JS_SRI` | *(empty)* | pin the CDN copy of Chart.js |

---

## 10. Before you go live

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `.env` is `640 root:www-data`, and not in git
- [ ] Every migration applied — `php database_structure/migrate.php`
- [ ] The default administrator password has been changed
- [ ] `APP_HOSTS` set to your real hostname
- [ ] `TRUSTED_PROXIES` set to your CDN's ranges
- [ ] TLS certificate valid; `https://` redirect works; HSTS present
- [ ] CDN in Full (strict) mode; origin firewalled to CDN ranges only
- [ ] WAF installed, tuned, and switched from `DetectionOnly` to `On`
- [ ] `bash deploy/vendor-assets.sh` run, so no external script host remains
- [ ] `/health.php?deep=1` returns 200 from every node
- [ ] Load balancer health checks configured (shallow for liveness, deep for readiness)
- [ ] Database role has no DDL rights; database not reachable from the internet
- [ ] `deploy/backup.sh` in cron, `ERP_BACKUP_REMOTE` set, and one backup restored somewhere (§11)
- [ ] `SELECT security_gc();` in cron, hourly
- [ ] `deploy/notify-scan.php` in cron, every ten minutes — see below
- [ ] `pm.max_children` sized to the machine, not left at the packaged 5 — see below
- [ ] Log shipping somewhere the server itself cannot rewrite

### The two the checklist used to leave to chance

**The notification scan.** The application runs it itself, on a page
load, at most once every ten minutes — so the bell works whether or
not anything is set up. But *somebody* pays for it, in their own page
load, and it was measured at 3.7 seconds on four years of trading.
That is one person every ten minutes waiting on a background job
because it happened to be their turn. Put it in cron and nobody
waits:

```cron
*/10 * * * *  php /var/www/erp/deploy/notify-scan.php >> /var/log/erp-notify.log 2>&1
```

**PHP-FPM's worker count.** Debian and Ubuntu package
`pm.max_children = 5`, and nothing in this repository changes it, so
a freshly built server answers five PHP requests at once however
much machine you paid for. Each worker of this application measures
about 27 MB resident. Leave the operating system and PostgreSQL
their share and divide what is left:

```ini
; /etc/php/8.3/fpm/pool.d/www.conf — a 4 GB VPS, PostgreSQL local
pm = dynamic
pm.max_children      = 40      ; ~1.1 GB at 27 MB each
pm.start_servers     = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 16
```

Do not simply set it high. Workers that cannot fit in RAM are worse
than a queue: the machine swaps, every request slows at once, and
the queue you were avoiding arrives anyway with a disk attached.
Check it under load with `ps -o rss= -C php-fpm8.3`.

---

## 11. Backups

The books of the business live in one database and there is no
other copy of them. This is the largest single risk on the list,
and the cheapest to remove.

```bash
# nightly, from root's crontab
15 1 * * *  /var/www/erp/deploy/backup.sh >> /var/log/erp-backup.log 2>&1
```

The script reads `.env`, so it carries no credentials of its own.
It differs from a bare `pg_dump` in four ways that matter at three
in the morning:

- it writes to a temporary name and renames on success, so an
  interrupted backup is never mistaken for a good one
- it **reads the dump back** before keeping it — a dump that cannot
  be listed cannot be restored, and it is better to find that out
  now
- it records a SHA-256 beside each file, so silent corruption is
  detectable
- it keeps 14 dailies, 8 weeklies and 12 monthlies, because the
  usual disaster is not a dead disk — it is noticing on the 20th
  that something went wrong on the 3rd

Weeklies and monthlies are hard links, so a year of retention costs
almost no extra disk.

**Get them off the machine.** A backup on the same disk as the
database survives a mistake and nothing else:

```bash
ERP_BACKUP_REMOTE=b2:erp-backups   # rclone remote, or an rsync target
```

### Restoring

A backup nobody has restored is a hypothesis. Rehearse it on a
quiet Tuesday:

```bash
deploy/restore.sh --list
deploy/restore.sh /var/backups/erp/daily/erp-2026-08-05.dump --into erp_restore_test
psql -d erp_restore_test -c "SELECT COUNT(*) FROM invoices"
dropdb erp_restore_test
```

Restoring over the live database is deliberately awkward — it makes
you type the database name — because the only thing worse than
losing today's work is losing it to a restore aimed at the wrong
place.

---

## 12. "The service is temporarily unavailable"

This message means one thing: **PHP could not open a connection to
PostgreSQL.** It is `includes/database.php:70`, and it is
deliberately vague — it is shown to whoever is at the browser, and
your host, user and database name are none of their business.

The real reason is written to the PHP error log as a `[DB]` line.
Or ask directly:

```bash
php deploy/check-db.php
```

That walks the same checks the application does, in the same
order, and names the one that failed with the command to fix it.
It never prints the password.

**If the browser fails but the command line works**, run it as the
web server's user — and suspect the extension first, because the
CLI and the web server are usually different PHP binaries with
different `php.ini` files:

```bash
sudo -u www-data php deploy/check-db.php
/opt/lampp/bin/php -m | grep pdo_pgsql     # XAMPP's PHP, not the system one
```

The causes, in the order they actually happen:

| Cause | What the log says |
|---|---|
| PostgreSQL is not running | `Connection refused` |
| No `.env`, so `DB_PASS` is empty | `fe_sendauth: no password supplied` |
| Wrong password | `password authentication failed` |
| Database or role never created | `database "…" does not exist` |
| `pg_hba.conf` refuses the method | `no pg_hba.conf entry for host` |
| Web server user cannot read `.env` | empty settings, then one of the above |
| `pdo_pgsql` missing from *that* PHP | `could not find driver` |
| Connection pool full | `too many clients already` — intermittent, under load |

The last one is the only one that comes and goes. Everything else
is constant until fixed.

A related message, **"Setup required"**, is a different thing: the
database connected fine but a table the page needs is missing.
That is `require_tables()`, and it means a migration has not been
run.
