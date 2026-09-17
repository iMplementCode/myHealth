#!/bin/bash
# ============================================================
#  Put the application on a VPS, and decide who owns what
# ------------------------------------------------------------
#  Hostinger, DigitalOcean, Linode, a bare Ubuntu box — any
#  machine where you are root and there is no control panel
#  deciding the layout for you.
#
#  The folder layout is the same one the project already has and
#  the same one cpanel-layout.sh arranges. What changes on a VPS
#  is ownership, and that is the whole point of this script.
#
#      /var/www/erp/            the application — never served
#        config.php .env includes/ vendor/ database_structure/
#        public/                ← the only directory nginx roots at
#          uploads/             ← the only directory PHP may write to
#
#  On shared hosting the web server runs *as you*, so it can
#  rewrite any file it can read and there is nothing to be done
#  about it. On a VPS PHP-FPM runs as its own user, so the code
#  can be readable and not writable. A bug that lets somebody
#  upload a file then cannot let them replace login.php with it.
#
#  This application makes that easy: sessions are in PostgreSQL,
#  the cache is in APCu, exports go to /tmp, and errors go to the
#  PHP-FPM log. public/uploads is the only path in the tree it
#  ever writes to.
#
#  Run it as root from the project root, on the server:
#
#      bash deploy/vps-layout.sh                  # do it
#      bash deploy/vps-layout.sh --dry-run        # say what it would do
#      bash deploy/vps-layout.sh --web-user nginx # RHEL/Alma
#
#  It changes nothing without printing it first, and it refuses
#  rather than guessing when something looks wrong.
# ============================================================

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_USER=""
DRY_RUN=0

while [ $# -gt 0 ]; do
    case "$1" in
        --dry-run)  DRY_RUN=1 ;;
        --web-user) WEB_USER="${2:-}"; shift ;;
        -h|--help)
            sed -n '2,40p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) printf 'Unknown option: %s (try --help)\n' "$1" >&2; exit 2 ;;
    esac
    shift
done

say()  { printf '  %s\n' "$*"; }
fail() { printf '\n  ERROR: %s\n\n' "$*" >&2; exit 1; }
run()  {
    if [ "$DRY_RUN" = 1 ]; then
        printf '       would run: %s\n' "$*"
    else
        "$@"
    fi
}

printf '\niMplement ERP — VPS layout\n\n'

# ── 0. Is this the project, and are we root? ─────────────────
[ -d "$APP_ROOT/public" ]     || fail "$APP_ROOT/public is missing. Is this the project root?"
[ -f "$APP_ROOT/config.php" ] || fail "$APP_ROOT/config.php is missing. Is this the project root?"

if [ "$(id -u)" != "0" ] && [ "$DRY_RUN" = 0 ]; then
    fail "Run this as root — it sets ownership across the tree.
         sudo bash deploy/vps-layout.sh
         Or add --dry-run to see what it would do."
fi

# ── 1. Who does the web server run as? ───────────────────────
# Debian/Ubuntu say www-data, RHEL and its family say nginx or
# apache. Guessing wrong makes the site 403 on every page, so it
# is checked against /etc/passwd rather than assumed.
if [ -z "$WEB_USER" ]; then
    for candidate in www-data nginx apache http; do
        if id "$candidate" >/dev/null 2>&1; then WEB_USER="$candidate"; break; fi
    done
fi
[ -n "$WEB_USER" ] || fail "Could not work out which user the web server runs as.
         Install nginx (or apache) first, or pass it yourself:
             bash deploy/vps-layout.sh --web-user www-data"
id "$WEB_USER" >/dev/null 2>&1 || fail "There is no user called '$WEB_USER' on this machine."

# The account that owns the code. Deliberately NOT the web user:
# that is the whole distinction this script exists to draw.
OWNER="root"

say "application  : $APP_ROOT"
say "document root: $APP_ROOT/public"
say "code owned by: $OWNER"
say "web server   : $WEB_USER  (reads the code, writes only uploads)"
[ "$DRY_RUN" = 1 ] && say "MODE         : dry run — nothing will be changed"
printf '\n'

# ── 2. Refuse the layouts that leak the password ─────────────
# A document root at or above the application means .env has a
# URL. On a VPS this is usually somebody who dropped the whole
# project into /var/www/html.
for served in /var/www/html /usr/share/nginx/html; do
    case "$APP_ROOT/" in
        "$served"/*|"$served"/)
            fail "The application is inside $served, which is a document root.
         Move it out — /var/www/erp is the usual place — and point
         the web server at /var/www/erp/public instead:

             mv $APP_ROOT /var/www/erp
             cd /var/www/erp && bash deploy/vps-layout.sh

         Left where it is, .env is a URL away from anybody who asks." ;;
    esac
done
say "ok   the application is not inside a stock document root"

# A .env inside public/ is the same failure by a different route.
if [ -e "$APP_ROOT/public/.env" ]; then
    fail "There is a .env inside public/. That directory is served.
         Delete it — the real one belongs at $APP_ROOT/.env"
fi

# ── 3. The code: readable by the web server, writable by nobody ──
# Group-owned by the web user and 750/640, so PHP-FPM can read
# every file it needs and write none of them, while other logins
# on the box see nothing at all.
say "ok   setting code ownership to $OWNER:$WEB_USER"
run chown -R "$OWNER:$WEB_USER" "$APP_ROOT"
run find "$APP_ROOT" -type d -exec chmod 750 {} +
run find "$APP_ROOT" -type f -exec chmod 640 {} +

# The scripts in deploy/ are meant to be run.
run find "$APP_ROOT/deploy" -type f -name '*.sh' -exec chmod 750 {} + 2>/dev/null || true

# ── 4. .env ──────────────────────────────────────────────────
# 640 root:www-data — PHP-FPM reads it through the group, and no
# other account on the machine can. Not 600: that would lock out
# the web server along with everybody else.
if [ -f "$APP_ROOT/.env" ]; then
    run chown "$OWNER:$WEB_USER" "$APP_ROOT/.env"
    run chmod 640 "$APP_ROOT/.env"
    say "ok   .env is 640 $OWNER:$WEB_USER — readable by PHP, by nothing else"
else
    say "note there is no .env yet:"
    say "       cp $APP_ROOT/.env.example $APP_ROOT/.env  &&  edit it"
fi

# ── 5. Uploads: the one writable tree ────────────────────────
# Owned by the web user, and setgid so a file arriving tomorrow
# inherits the group instead of the uploader's own.
#
# These directories are deliberately absent from git. A folder
# that arrives from a clone belongs to whoever ran the clone,
# which on this machine is root — and root's directories are not
# writable by www-data. That is exactly how "Failed to save logo
# to server" happened. Created here, they belong to the process
# that has to write into them.
say "ok   uploads owned by $WEB_USER — the only writable path in the tree"
for dir in uploads uploads/products uploads/company uploads/payments; do
    run mkdir -p "$APP_ROOT/public/$dir"
    run chown "$WEB_USER:$WEB_USER" "$APP_ROOT/public/$dir"
    run chmod 2775 "$APP_ROOT/public/$dir"
done

# ── 6. Say what that produced ────────────────────────────────
printf '\n'
say "Served — everything under public/ is reachable by URL:"
ls -1 "$APP_ROOT/public" | sed 's/^/       /'
printf '\n'
say "Not served, and no URL can name it:"
ls -1 "$APP_ROOT" | grep -v '^public$' | sed 's/^/       /'

# ── 7. What the web server still needs told ──────────────────
PHP_SOCK="$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1 || true)"
printf '\n  Point the web server at it:\n\n'
printf '       root         %s;\n' "$APP_ROOT/public"
if [ -n "$PHP_SOCK" ]; then
    printf '       fastcgi_pass unix:%s;\n' "$PHP_SOCK"
else
    printf '       fastcgi_pass unix:/run/php/phpX.Y-fpm.sock;   (PHP-FPM is not running yet)\n'
fi
printf '\n     deploy/nginx.conf is the site file — those are the two lines to edit.\n'

printf '\n  Then:\n'
printf '       1. php database_structure/migrate.php\n'
printf '       2. php deploy/check-db.php\n'
printf '       3. sudo -u %s php deploy/check-permissions.php\n' "$WEB_USER"
printf '       4. open the site, sign in, change the admin password\n\n'
printf '     Step 3 asks the question this script cannot: not "did I set\n'
printf '     the modes", but "can the web server actually open these files".\n'
printf '     A file it cannot read is a 500 with nothing on the page.\n\n'

if [ "$DRY_RUN" = 1 ]; then
    printf '  Nothing was changed. Re-run without --dry-run to apply.\n\n'
fi
