#!/bin/bash
# ============================================================
#  Put the application where cPanel cannot serve it
# ------------------------------------------------------------
#  cPanel serves ~/public_html and, for the primary domain, that
#  cannot be moved. So the application goes beside it rather than
#  inside it:
#
#      ~/erp/              the application — never served
#        includes/ vendor/ database_structure/ config.php .env
#        public/           the files that belong on the web
#      ~/public_html/      the document root cPanel serves
#
#  and public_html is made to *be* ~/erp/public — by symlink where
#  the host allows it, by copy where it does not.
#
#  Run it from the project root on the server:
#      bash deploy/cpanel-layout.sh
#
#  It changes nothing without saying so first, and it never
#  deletes an existing public_html — it moves it aside.
# ============================================================

set -euo pipefail

APP_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
HOME_DIR="${HOME:-$(cd ~ && pwd)}"
DOC_ROOT="${1:-$HOME_DIR/public_html}"

say()  { printf '  %s\n' "$*"; }
fail() { printf '\n  ERROR: %s\n\n' "$*" >&2; exit 1; }

printf '\niMplement ERP — cPanel layout\n\n'
say "application : $APP_ROOT"
say "document root: $DOC_ROOT"
printf '\n'

[ -d "$APP_ROOT/public" ]   || fail "$APP_ROOT/public is missing. Is this the project root?"
[ -f "$APP_ROOT/config.php" ] || fail "$APP_ROOT/config.php is missing. Is this the project root?"

# ── 1. The application must not be inside the document root ──
case "$APP_ROOT/" in
    "$DOC_ROOT"/*)
        fail "The application is inside the document root.
         Move it out — for example to $HOME_DIR/erp — and run this again.
         Left where it is, .env is a URL away from anybody who asks." ;;
esac
say "ok   the application is outside the document root"

# ── 2. public_html becomes the public directory ─────────────
if [ -L "$DOC_ROOT" ]; then
    current="$(readlink -f "$DOC_ROOT" || true)"
    if [ "$current" = "$APP_ROOT/public" ]; then
        say "ok   public_html already points at $APP_ROOT/public"
    else
        fail "public_html is a symlink to $current, not to $APP_ROOT/public.
         Remove it and run this again if that is not deliberate."
    fi
elif [ -d "$DOC_ROOT" ] && [ -n "$(ls -A "$DOC_ROOT" 2>/dev/null)" ]; then
    stamp="$DOC_ROOT.before-erp.$(date +%Y%m%d%H%M%S)"
    say "note public_html exists and is not empty — moving it to:"
    say "     $stamp"
    mv "$DOC_ROOT" "$stamp"
    ln -s "$APP_ROOT/public" "$DOC_ROOT"
    say "ok   public_html now points at $APP_ROOT/public"
else
    rmdir "$DOC_ROOT" 2>/dev/null || true
    ln -s "$APP_ROOT/public" "$DOC_ROOT"
    say "ok   public_html now points at $APP_ROOT/public"
fi

# Some shared hosts refuse to follow a symlinked document root
# (FollowSymLinks off). Say so plainly rather than leaving a site
# that returns 403 with no explanation.
if [ ! -e "$DOC_ROOT/index.php" ]; then
    fail "public_html does not resolve to the application.
         This host may not follow a symlinked document root.
         Copy instead, and tell the application where it went:

             cp -r $APP_ROOT/public/. $DOC_ROOT/
             echo \"PUBLIC_PATH=$DOC_ROOT\" >> $APP_ROOT/.env

         and re-copy public/ on every deployment."
fi

# ── 3. Permissions ──────────────────────────────────────────
# .env holds the database password. On shared hosting the web
# server runs as the account owner, so 600 is readable by the
# application and by nobody else on the box.
if [ -f "$APP_ROOT/.env" ]; then
    chmod 600 "$APP_ROOT/.env"
    say "ok   .env is 600 (owner only)"
else
    say "note no .env yet — copy .env.example and fill it in"
fi

chmod 755 "$APP_ROOT" "$APP_ROOT/public"
find "$APP_ROOT/public" -type d -exec chmod 755 {} +
find "$APP_ROOT/public" -type f -exec chmod 644 {} +
[ -d "$APP_ROOT/public/uploads" ] && chmod -R 755 "$APP_ROOT/public/uploads"
say "ok   directories 755, files 644, uploads writable"

# ── 4. What the world can see ───────────────────────────────
printf '\n'
say "Served from $DOC_ROOT:"
ls -1 "$APP_ROOT/public" | sed 's/^/       /'
printf '\n'
say "Not served, and not reachable by any URL:"
ls -1 "$APP_ROOT" | grep -v '^public$' | sed 's/^/       /'

printf '\nNext:\n'
printf '   1. php database_structure/migrate.php\n'
printf '   2. php deploy/check-db.php\n'
printf '   3. open the site, sign in, and change the admin password\n\n'
