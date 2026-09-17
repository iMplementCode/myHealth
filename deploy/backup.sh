#!/usr/bin/env bash
# ============================================================
#  iMplement ERP — database backup
# ------------------------------------------------------------
#  The books of a business live in this database. There is no
#  other copy of them.
#
#  Run it from cron, nightly:
#
#      15 1 * * *  /var/www/erp/deploy/backup.sh >> /var/log/erp-backup.log 2>&1
#
#  It reads .env, so it needs no credentials of its own.
#
#  ── What it does that a bare pg_dump does not ──────────────
#    * writes to a temporary name and renames on success, so a
#      backup interrupted half way is never mistaken for a good
#      one
#    * records a SHA-256 next to each dump, so a silently
#      corrupted file can be told from a sound one
#    * verifies the dump can be read back before keeping it
#    * keeps the last 14 dailies, 8 weeklies and 12 monthlies —
#      because the usual disaster is not a dead disk, it is
#      noticing on the 20th that something went wrong on the 3rd
#
#  A backup nobody has restored is a hypothesis. Test one:
#      deploy/restore.sh --list
#      deploy/restore.sh /var/backups/erp/daily/erp-2026-08-05.dump --into erp_restore_test
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${ERP_BACKUP_DIR:-/var/backups/erp}"

KEEP_DAILY=${ERP_KEEP_DAILY:-14}
KEEP_WEEKLY=${ERP_KEEP_WEEKLY:-8}
KEEP_MONTHLY=${ERP_KEEP_MONTHLY:-12}

# ── Credentials, from the same .env the application reads ───
if [ ! -f "$ROOT/.env" ]; then
    echo "No .env at $ROOT — cannot find the database." >&2
    exit 1
fi
# Only the keys we need, and nothing is executed from the file.
get_env() { grep -E "^$1=" "$ROOT/.env" | tail -1 | cut -d= -f2- | sed 's/^"//;s/"$//'; }

export PGHOST="$(get_env DB_HOST)";     PGHOST="${PGHOST:-localhost}"
export PGPORT="$(get_env DB_PORT)";     PGPORT="${PGPORT:-5432}"
export PGDATABASE="$(get_env DB_NAME)"
export PGUSER="$(get_env DB_USER)"
export PGPASSWORD="$(get_env DB_PASS)"

if [ -z "${PGDATABASE:-}" ]; then
    echo "DB_NAME is not set in .env." >&2
    exit 1
fi

STAMP="$(date +%Y-%m-%d_%H%M)"
DAY="$(date +%Y-%m-%d)"
mkdir -p "$DEST"/{daily,weekly,monthly}
chmod 700 "$DEST" "$DEST"/{daily,weekly,monthly}

FINAL="$DEST/daily/erp-$DAY.dump"
TMP="$FINAL.part-$STAMP"

echo "[$(date -Is)] dumping $PGDATABASE → $FINAL"

# -Fc is the custom format: compressed, and restorable table by
# table, which is what you want at three in the morning when only
# one table is wrong.
pg_dump --format=custom --compress=6 --no-owner --no-privileges --file="$TMP"

# A dump that cannot be listed cannot be restored. Better to find
# that out now than during the emergency.
if ! pg_restore --list "$TMP" > /dev/null 2>&1; then
    echo "  the dump could not be read back — keeping nothing." >&2
    rm -f "$TMP"
    exit 1
fi

mv "$TMP" "$FINAL"
chmod 600 "$FINAL"
sha256sum "$FINAL" | awk '{print $1}' > "$FINAL.sha256"

SIZE=$(du -h "$FINAL" | cut -f1)
echo "  ok — $SIZE"

# ── Promote to weekly and monthly ───────────────────────────
#  Hard links, so a month of retention does not cost a month of
#  disk. They are separate directory entries to the same file;
#  deleting the daily leaves the weekly intact.
[ "$(date +%u)" = "7" ] && ln -f "$FINAL" "$DEST/weekly/erp-$DAY.dump"  && echo "  kept as this week's"
[ "$(date +%d)" = "01" ] && ln -f "$FINAL" "$DEST/monthly/erp-$DAY.dump" && echo "  kept as this month's"

# ── Retention ───────────────────────────────────────────────
prune() {
    local dir="$1" keep="$2"
    local n
    n=$(find "$dir" -maxdepth 1 -name 'erp-*.dump' | wc -l)
    if [ "$n" -gt "$keep" ]; then
        find "$dir" -maxdepth 1 -name 'erp-*.dump' -printf '%T@ %p\n' \
            | sort -n | head -n $((n - keep)) | cut -d' ' -f2- \
            | while read -r f; do rm -f "$f" "$f.sha256"; echo "  pruned $(basename "$f")"; done
    fi
}
prune "$DEST/daily"   "$KEEP_DAILY"
prune "$DEST/weekly"  "$KEEP_WEEKLY"
prune "$DEST/monthly" "$KEEP_MONTHLY"

# ── Off the machine ─────────────────────────────────────────
#  A backup on the same disk as the database survives a mistake
#  and nothing else. Set ERP_BACKUP_REMOTE to somewhere rclone or
#  rsync can reach, and it leaves the building.
if [ -n "${ERP_BACKUP_REMOTE:-}" ]; then
    if command -v rclone > /dev/null; then
        rclone copy "$FINAL" "$ERP_BACKUP_REMOTE" && echo "  copied to $ERP_BACKUP_REMOTE"
    else
        rsync -a "$FINAL" "$FINAL.sha256" "$ERP_BACKUP_REMOTE" && echo "  copied to $ERP_BACKUP_REMOTE"
    fi
else
    echo "  NOTE: ERP_BACKUP_REMOTE is not set, so this copy never leaves the server."
fi

echo "[$(date -Is)] done"
