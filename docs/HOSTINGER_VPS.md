# Hostinger VPS

A VPS is a bare Ubuntu machine with root SSH. Nothing is installed
and nothing is decided for you, which is the difficulty and also the
point: it is the first kind of hosting where the web server can be
denied permission to rewrite the application it is serving.

This is the walkthrough from a fresh VPS to a working site. It
assumes the plain Ubuntu template — no control panel — with nginx,
PHP-FPM and PostgreSQL. If you chose a template with CyberPanel,
CloudPanel or Plesk, see [With a control panel](#with-a-control-panel)
at the end; the layout is the same, the paths are not.

---

## Where the files go

This is the part that cannot be fixed later with a setting, and the
repository is already arranged for it. Nothing needs rearranging —
what changes on a VPS is **ownership**.

```
  /var/www/erp/              the application — no URL can name it
  ├── config.php                 where the two roots are defined
  ├── .env                       the database password    ← 640 root:www-data
  ├── includes/                  every library file
  ├── vendor/                    composer dependencies
  ├── database_structure/        schema and migrations
  ├── deploy/                    server configs and scripts
  ├── docs/                      this file
  └── public/                ← nginx roots here, and only here
      ├── index.php login.php dashboard/ invoices/ modules/ …
      ├── assets/                css, js, images
      └── uploads/           ← the only directory PHP may write to
```

**Two roots, and the gap between them is the whole defence.** A
request can only name something under `public/`. `.env` is not under
`public/`, so there is no rule to get wrong and no `.htaccess` for a
server to ignore — nginx does not read them at all.

**And on a VPS, one more line.** PHP-FPM runs as `www-data`, not as
you. So the code can be readable by it and writable by nobody:

| Path | Owner | Mode | The web server can |
|---|---|---|---|
| the application | `root:www-data` | `750` / `640` | read it |
| `.env` | `root:www-data` | `640` | read it |
| `public/uploads/**` | `www-data:www-data` | `2775` | **write** it |

That table is the difference between a file-upload bug being a
nuisance and being a way to replace `login.php`. This application
can afford it because `public/uploads` is the only path in the tree
it ever writes to — sessions are in PostgreSQL, the cache is in
APCu, spreadsheet exports go to `/tmp`, and errors go to the PHP-FPM
log.

`bash deploy/vps-layout.sh` sets all of it, and
`--dry-run` shows what it would do without doing it.

---

## 0. Before you install anything

**Get a terminal on the machine.** hPanel → VPS → your server shows
the IP address and the root password it generated. From Windows,
PowerShell has SSH built in — there is no need for PuTTY:

```powershell
ssh root@203.0.113.10
```

Everything from here runs on the server, not on your own machine.

**Point the domain at the VPS.** An `A` record for `erp.example.com`
at the server's IPv4 address, and `AAAA` at its IPv6 if it has one.
Do this first — the TLS certificate in step 8 cannot be issued until
DNS resolves.

**Make a non-root login and stop using the root password.** Hostinger
hands you root with a password, which is the single most attacked
account on the internet.

```bash
adduser deploy && usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy    # if you set up keys
```

Then in `/etc/ssh/sshd_config` set `PermitRootLogin prohibit-password`
and `PasswordAuthentication no`, and `systemctl restart ssh`. Keep
your current session open until you have proved the new login works
in a second terminal.

**Two firewalls, and both are real.** Hostinger has one in hPanel
(VPS → Firewall) that runs in front of the machine, and Ubuntu has
`ufw` on it. A rule added to one is not added to the other, and a
port closed in either is closed.

```bash
ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable
```

Leave PostgreSQL's 5432 closed. It is on the same machine as PHP and
should be reachable over localhost only.

---

## 1. Packages

```bash
apt update && apt upgrade -y
apt install -y nginx postgresql postgresql-contrib \
               php-fpm php-pgsql php-mbstring php-xml php-gd php-zip php-curl \
               composer git certbot python3-certbot-nginx
```

The application needs **PHP 8.1 or newer**; Ubuntu 24.04 ships 8.3
and 22.04 ships 8.1, so the distro package is fine either way. For
8.4, add `ppa:ondrej/php` first and install `php8.4-*` instead.

Five of those extensions are not optional, and
`php deploy/check-db.php` will tell you if one is missing:

| Extension | Without it |
|---|---|
| `pdo_pgsql` | nothing connects to the database |
| `mbstring`, `dom` | no PDF renders at all |
| `gd` | PDFs render with the logo missing |
| `fileinfo` | no upload passes its MIME check (built in on Ubuntu) |
| `zip` | spreadsheet export fails |

Check the PHP-FPM socket name — you need it in step 7:

```bash
ls /run/php/*.sock
```

---

## 2. The code

```bash
mkdir -p /var/www
git clone https://github.com/iMplementCode/implement_er.git /var/www/erp
cd /var/www/erp
composer install --no-dev --optimize-autoloader
```

**The repository is private**, so that clone will ask for
credentials and a GitHub password will not work. Either create a
read-only *deploy key* (`ssh-keygen -t ed25519 -C erp-vps`, add the
`.pub` to the repo under Settings → Deploy keys, then clone the
`git@github.com:…` URL), or use a personal access token with `repo`
scope in place of the password. A deploy key is better: it grants
one repository, not your whole account.

`vendor/` is not in git, so `composer install` is not optional.

Clone to `/var/www/erp`, **not** `/var/www/html`. `/var/www/html` is
a document root: the application inside it means `.env` has a URL.
`vps-layout.sh` refuses to run in that situation rather than
arranging a leak neatly.

---

## 3. The database — the role and the database only

```bash
sudo -u postgres createuser --pwprompt erp
sudo -u postgres createdb --owner=erp erp
```

Note the password you set. Nothing is loaded into the database yet —
that is step 5, and it cannot happen until step 4 exists.

---

## 4. `.env`

**This comes before the migrations, not after.** `migrate.php` reads
the database credentials through `config.php`, which reads `.env`.
Run it first and it will try `localhost:5432` with no password and
fail with a connection error that looks like a broken PostgreSQL.

```bash
cd /var/www/erp
cp .env.example .env
nano .env
```

At minimum:

```ini
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=erp
DB_USER=erp
DB_PASS=the password you set in step 3

APP_ENV=production
APP_DEBUG=false                 # never true on a public server
APP_PUBLIC_URL=https://erp.example.com
APP_HOSTS=erp.example.com
```

`APP_DEBUG=true` prints the real error, path and stack trace to
whoever triggered it. On a public server it should be `false`, and
errors then go to the PHP-FPM log with a reference number that
appears on the page — enough to find it without publishing it.

---

## 5. Schema and migrations

```bash
cd /var/www/erp
psql -U erp -d erp -h 127.0.0.1 -f database_structure/schema.sql
php database_structure/migrate.php
```

The migration runner is additive and idempotent — it never drops
data, and running it twice is harmless. It is also how you apply
updates later, and the application will tell an administrator on
every page when it is behind.

---

## 6. Set the layout

```bash
cd /var/www/erp
bash deploy/vps-layout.sh --dry-run     # read what it intends to do
bash deploy/vps-layout.sh               # then do it
```

It works out which user the web server runs as rather than assuming
`www-data`, refuses to run if the application is inside a document
root, sets the ownership in the table above, creates the four upload
directories owned by the web server, and prints two lists: what is
served, and what no URL can name.

**Those upload directories are deliberately not in git.** A folder
that arrives from a clone belongs to whoever ran the clone — root —
and root's directories are not writable by `www-data`. That is
exactly how *Failed to save logo to server* happens. Created here,
they belong to the process that has to write into them.

---

## 7. The certificate, then nginx

**In that order.** nginx reads `ssl_certificate` at startup and refuses
to start when the file is missing, so installing the site config before
the certificate exists leaves you with a dead web server and an error
that blames TLS rather than the order you did things in.

While the stock default site is still enabled and answering on port 80:

```bash
certbot certonly --webroot -w /var/www/html -d erp.example.com
```

Now `deploy/nginx.conf` will find what it names. Copy it and change
three lines:

```bash
cp deploy/nginx.conf /etc/nginx/sites-available/erp.conf
```

```nginx
server_name  erp.example.com;                    # ← your hostname
root         /var/www/erp/public;                # ← what the world may see
fastcgi_pass unix:/run/php/php8.3-fpm.sock;      # ← from step 1
```

`vps-layout.sh` prints the last two back to you with the real values
already filled in.

The file also carries a Cloudflare block — `set_real_ip_from` and
`real_ip_header CF-Connecting-IP`. **If nothing sits in front of this
server, delete it.** Left in place with the domain pointed straight
at the VPS it is inert rather than harmful, but the rate limits below
it are counting per client IP and you want to be sure that is what
they are counting.

The `limit_req_zone` lines at the top of the file can stay where they
are. Debian and Ubuntu include `sites-enabled/*` from inside the
`http { }` block, so they land in the right context already — an
earlier version of this guide said to move them to `conf.d`, which was
wrong and simply made extra work.

**If your VPS has no IPv6**, comment out both `listen [::]` lines.
nginx refuses to start with *Address family not supported by protocol*
rather than skipping them.

```bash
ln -s /etc/nginx/sites-available/erp.conf /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

---

## 8. Check the renewal

The certificate itself came in step 7. What is worth proving now
rather than in ninety days is that it will renew without you:

```bash
certbot renew --dry-run
```

If you would rather certbot wrote the TLS block for you, run
`certbot --nginx -d erp.example.com` — but do it with the two
`ssl_certificate` lines commented out, or nginx will not start for
certbot to edit.

---

## 9. Check it

```bash
cd /var/www/erp && php deploy/check-db.php
```

It reports the database connection, how many migrations are applied
and which was last, every PHP extension above with what breaks
without it, and — since the logo trouble — the four upload
directories with their owner and the exact command to fix one that
is wrong.

Whether any migration is still *outstanding* is answered by the
application itself: an administrator gets a banner on every page
naming the ones that have not run.

Then open the site, sign in, and **change the default administrator
password immediately**.

### One thing to know before real customer data goes in

Payment proofs, refund slips, investor agreements and advance
receipts are stored under `public/uploads/` and linked as direct
URLs. nginx serves those files without checking for a session, so
anyone holding the link can open the document without signing in,
and the link never expires.

Guessing one is hard — the filenames are generated — but a link that
leaks in a forwarded email, a WhatsApp message or a shared browser
history stays valid forever. If the business will be storing customer
bank slips, this is worth closing first: deny those subfolders at the
web server and stream them through a PHP page that checks the
session. Nothing else in the upload path is affected.

---

## 10. Backups

`deploy/backup.sh` dumps **the database**. It writes to a temporary
name and renames on success, records a SHA-256 beside each dump,
reads the dump back before keeping it, and rotates 14 dailies, 8
weeklies and 12 monthlies. From root's crontab:

```cron
30 2 * * * /var/www/erp/deploy/backup.sh >> /var/log/erp-backup.log 2>&1
```

**It does not copy `public/uploads`.** Logos, product photographs and
payment attachments are files on the disk, not rows in the database,
and nothing in this repository backs them up yet. Until that changes,
add them yourself:

```cron
0 3 * * * tar -czf /var/backups/erp/uploads-$(date +\%F).tar.gz \
              -C /var/www/erp/public uploads
```

Hostinger's own snapshots in hPanel are worth having as well, but
they are a different thing: a snapshot restores the whole machine to
a moment, a dump restores the data into a machine you still have.
You want both, and a backup nobody has ever restored is a hope
rather than a backup — `deploy/restore.sh` exists to be rehearsed.

---

## Updating later

```bash
cd /var/www/erp
git pull
composer install --no-dev --optimize-autoloader
php database_structure/migrate.php
bash deploy/vps-layout.sh          # ← do not skip this
systemctl reload php8.3-fpm
```

**The last-but-one line is the one people skip.** Files arriving from
`git pull` belong to whoever ran it and carry that user's modes, so
every pull quietly undoes the ownership from step 6. Re-running the
script puts it back; it is idempotent and safe to run any time.

---

## When something is wrong

**403 on every page.** nginx cannot traverse to the files. Check
`root` points at `/var/www/erp/public` and re-run
`bash deploy/vps-layout.sh`.

**502 Bad Gateway.** nginx is talking to a PHP-FPM socket that is not
there. `ls /run/php/*.sock` and make `fastcgi_pass` match, then
`systemctl status php8.3-fpm`.

**"Failed to save logo to server."** The upload directory is not
writable by the web server. The message now names the directory and
the command; `php deploy/check-db.php` reports all four. Usually the
cure is `bash deploy/vps-layout.sh` after a `git pull`.

**A page says the database needs updating.** Run
`php database_structure/migrate.php`. The banner is shown to
administrators on every page precisely so this is never a mystery.

**Mail does not send.** Outbound port 25 is commonly blocked on a
VPS, Hostinger's included, and a mail server on a fresh IP with no
reputation gets filtered even when it is open. Send through an
authenticated SMTP relay on port 587 rather than fighting this.

---

## With a control panel

If you picked a template with CyberPanel, CloudPanel or Plesk, the
panel owns the web server configuration and creates a document root
per site — typically `/home/<domain>/public_html`. The two-root rule
does not change; only the paths do.

Put the application **beside** that document root, not inside it:

```
  /home/erp.example.com/erp/            the application
  /home/erp.example.com/public_html ->  /home/erp.example.com/erp/public
```

`deploy/cpanel-layout.sh` does exactly this, and it is not
cPanel-specific despite the name — pass the document root as its
argument:

```bash
bash deploy/cpanel-layout.sh /home/erp.example.com/public_html
```

Then set the site's PHP version and extensions in the panel, and use
the panel's own TLS button rather than certbot, which would fight it.
Where the panel lets you set the document root directly — most do,
for a subdomain — point it at `…/erp/public` and skip the symlink.
