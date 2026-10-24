# PHP2Shell — CVE-2026-109618 and CVE-2026-109619

On **21 October 2026** PHP released 8.5.12, 8.4.27, 8.3.36 and 8.2.35
to fix two flaws in PHP itself. Both allow **remote code execution
without authentication**, both are scored 9.2, and both were being
exploited before the patch was published — a honeypot recorded an
attempt on 17 October, four days before disclosure.

**This is not a flaw in myHealth, and nothing in myHealth can close
it.** One of the two paths runs inside PHP's own upload handling,
before a single line of application code. There is no login to get
past: any page that accepts a POST is a door, and `login.php` is a
door. Every defence in this repository sits downstream of it.

So the only fix is the PHP version. This page is how to get there and
how to prove you did.

### Affected versions

AWS's security bulletin states the ranges as:

| Branch | Affected | Fixed in |
|---|---|---|
| 8.5 | 8.5.0 – 8.5.11 | **8.5.12** |
| 8.4 | 8.4.0 – 8.4.26 | **8.4.27** |
| 8.3 | 8.3.0 – 8.3.35 | **8.3.36** |
| 8.2 | — (not listed in that bulletin) | **8.2.35** |

Treat the ranges as indicative rather than final. Published accounts
disagree about how far back this reaches — some say 5.4 onward, one
lists everything before 7.4 — and php.net's own advisory is the only
thing worth settling it with. What nobody disagrees about is the
fixed versions, which is the number you actually need.

Both are use-after-free bugs (CWE-416), per the Canadian Centre for
Cyber Security.

---

## First: which PHP is actually serving the site?

This is the step people skip, and skipping it is how a server gets
"upgraded" and stays vulnerable. On this deployment there can be
**two different PHPs on the same machine**, and upgrading one does
nothing for the other:

| Where | What runs there | Patched by |
|---|---|---|
| **Inside the app container** | the PHP that serves myHealth | rebuilding and redeploying the app |
| **On the VPS host** | PHP for anything else you host, hPanel tooling | `apt` / `dnf` on the host |

If myHealth is deployed through **Dokploy** — which it is — then the
PHP that answers your customers is the one *inside the container*.
`apt upgrade php` on the host will not touch it.

Find out in one command, on the host:

```bash
docker ps --format '{{.Names}}'          # find the myHealth container
docker exec -it <container-name> php -v  # the version that serves the site
php -v                                   # the host's own PHP, if any
```

---

## The check

Run this wherever you want an answer. It needs nothing — no database,
no `.env`, no composer:

```bash
php deploy/php-version-check.php
```

Inside the container:

```bash
docker exec -it <container-name> php /app/deploy/php-version-check.php
```

It prints the running version, the patched baseline for that branch,
and whether `session.upload_progress` is on. **Exit code 0 means
patched**, so it can go in a deploy script.

### One thing it cannot tell you

If PHP came from Ubuntu, Debian or Red Hat rather than from php.net,
the version number alone does not settle it. **Distributions backport
security fixes and leave the upstream version unchanged.** On Ubuntu
24.04 the constant reads `8.3.6` whether the package is the original
or a patched `8.3.6-0ubuntu0.24.04.11`.

The script detects that case and says so instead of guessing. When it
does, the package version is what matters:

```bash
dpkg-query -W -f='${Version}\n' php8.3-cli      # Debian / Ubuntu
rpm -q php-cli                                  # RHEL / Alma / Rocky
```

and the authority is your vendor's notice, not php.net's:
<https://ubuntu.com/security/CVE-2026-109618>

---

## Path A — the app container (this is the one that matters)

myHealth runs from a container image built by Dokploy. The fix is to
rebuild it so it picks up a patched PHP, then redeploy.

**1. Rebuild without the cache.** This is the important flag. A cached
layer is exactly how a rebuild hands you the same unpatched PHP and
reports success.

In the Dokploy UI: open the myHealth application → **Deployments** →
**Redeploy**, and make sure any "use cache" / "rebuild from cache"
option is **off**. If your version of Dokploy exposes it, "Force
rebuild" or "Clean build" is the one you want.

**2. Verify, in the container, before you believe it:**

```bash
docker exec -it <container-name> php /app/deploy/php-version-check.php
```

Do not accept "the deploy went green" as the answer. The deploy going
green is what it does when it rebuilds from cache.

**3. If the rebuild still gives you an old PHP**, the base image is
stale on that host. Pull it fresh and rebuild:

```bash
docker pull php:8.3-fpm-alpine     # if you have moved to deploy/Dockerfile
docker image prune -f              # drop stale intermediate layers
```

With **nixpacks** — which is what `nixpacks.toml` currently drives —
the PHP comes from the nixpacks base image rather than a tag you
control. If a no-cache rebuild does not move the version, that is the
moment to switch the Dokploy build type to **Dockerfile** with the
path `deploy/Dockerfile`. That file pins nothing dangerous, sets the
mitigation in `php.ini`, and **refuses to build at all** on a PHP that
still has these flaws — so a stale base image becomes a failed build
instead of a quiet one.

---

## Path B — the VPS host

Only needed if you run anything else on the box with PHP. It will not
fix the container.

**Ubuntu / Debian:**

```bash
sudo apt-get update
sudo apt-get install --only-upgrade php8.3-cli php8.3-fpm php8.3-common
php -v
sudo systemctl restart php8.3-fpm
```

If you installed from `ppa:ondrej/php` (as `docs/HOSTINGER_VPS.md`
suggests for newer branches), that PPA ships the upstream numbering,
so after upgrading you should see `8.3.36` or above and the check
script will say PATCHED outright.

If you are on Ubuntu's own packages and `apt` says there is nothing to
upgrade, check <https://ubuntu.com/security/CVE-2026-109618> for
whether a fix has shipped for your release. **If it has not, you are
unpatched, whatever the version number looks like** — add
`ppa:ondrej/php` and move to a build that carries the fix.

**AlmaLinux / Rocky / CloudLinux:**

```bash
sudo dnf update php-cli php-fpm php-common
php -v
sudo systemctl restart php-fpm
```

CloudLinux shipped patched `alt-php` and `ea-php` packages; if your
VPS template uses those, update through their channel.

**PHP 8.1 or older:** do not assume either way — check php.net
before you act. Those branches were end of life when this was
disclosed, and the regular releases above do not cover them. But at
least one vendor write-up (Fastly) reports that the PHP security team
provided **source backports for 7.4, 8.0 and 8.1**, and other write-ups
say the opposite. Sources genuinely disagree, so this page will not
guess on your behalf: read the php.net advisory, and failing that your
distribution's. If no fix exists for your branch, moving to a
supported one is the answer.

---

## The stop-gap, for the hours in between

If you cannot redeploy immediately, this closes **one** of the two
paths (CVE-2026-109619). Nothing in myHealth uses the feature, so
turning it off costs you nothing.

```ini
session.upload_progress.enabled = 0
```

Put it in `php.ini`, or in the php-fpm pool, and restart PHP.

Be clear about what this is and is not:

* It does **not** fix CVE-2026-109618.
* PHP's maintainers said there is no configuration change that fully
  mitigates these. Upgrading is the only complete fix.
* `deploy/Dockerfile` already sets it, so a container built from that
  file has it. `public/.user.ini` sets it too, but **only for the CGI
  and FastCGI SAPIs** — PHP's built-in server, which is what
  `nixpacks.toml` starts, does not read `.user.ini` at all.

Which is why the check script reports the setting *actually in force*
rather than the one that was intended.

---

## Afterwards

```bash
php deploy/check-hardening.php
```

PHP2Shell is the first thing it reports. While you are there, the rest
of that output is worth clearing too — it covers `TRUSTED_PROXIES`,
`APP_HOSTS`, the database account and the default administrator
password.

---

## Sources

* <https://www.php.net/> — the authoritative advisory and the releases
* <https://ubuntu.com/security/CVE-2026-109618> — Ubuntu's status per release
* <https://www.helpnetsecurity.com/2026/10/21/php2shell-php-vulnerabilities-patched-cve-2026-109618-cve-2026-109619/>
* <https://watchtowr.com/intelligence/php-cve-2026-109618-faq/> — exploitation in the wild
* <https://aws.amazon.com/security/security-bulletins/2026-143-aws/> — the version ranges above
* <https://cyber.gc.ca/en/alerts-advisories/al26-026-critical-vulnerabilities-impacting-php-cve-2026-109618-cve-2026-109619> — Canadian Centre for Cyber Security
* <https://www.fastly.com/blog/critical-rce-vulnerabilities-discovered-core-php-cve-2026-109618-cve-2026-109619> — reports source backports for 7.4, 8.0 and 8.1
