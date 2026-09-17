#!/usr/bin/env bash
# ============================================================
#  iMplement ERP — restore from a backup
# ------------------------------------------------------------
#  A backup nobody has restored is a hypothesis. This exists so
#  the restore is practised on a quiet Tuesday rather than
#  invented during the emergency.
#
#      deploy/restore.sh --list
#      deploy/restore.sh <dump> --into erp_restore_test   ← rehearsal
#      deploy/restore.sh <dump> --over-live               ← the real thing
#
#  Restoring over the live database is deliberately awkward. It
#  asks for the database name to be typed out, because the one
#  thing worse than losing today's work is losing it to a restore
#  aimed at the wrong place.
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${ERP_BACKUP_DIR:-/var/backups/erp}"

get_env() { grep -E "^$1=" "$ROOT/.env" | tail -1 | cut -d= -f2- | sed 's/^"//;s/"$//'; }
export PGHOST="$(get_env DB_HOST)";  PGHOST="${PGHOST:-localhost}"
export PGPORT="$(get_env DB_PORT)";  PGPORT="${PGPORT:-5432}"
export PGUSER="$(get_env DB_USER)"
export PGPASSWORD="$(get_env DB_PASS)"
LIVE_DB="$(get_env DB_NAME)"

if [ "${1:-}" = "--list" ] || [ $# -eq 0 ]; then
    echo "Backups in $DEST:"
    for tier in daily weekly monthly; do
        [ -d "$DEST/$tier" ] || continue
        find "$DEST/$tier" -name 'erp-*.dump' -printf '  %-8s %10s  %p\n' -exec du -h {} \; 2>/dev/null \
            | paste - - | sed 's/\t/ /' || true
        ls -1sh "$DEST/$tier"/erp-*.dump 2>/dev/null | sed "s|^|  $tier  |" || true
    done
    echo
    echo "Rehearse:  $0 <dump> --into erp_restore_test"
    exit 0
fi

DUMP="$1"; shift
[ -f "$DUMP" ] || { echo "No such dump: $DUMP" >&2; exit 1; }

# The checksum written when it was taken. A dump that no longer
# matches it is not a backup, it is a file.
if [ -f "$DUMP.sha256" ]; then
    echo -n "Checking the dump is intact… "
    if [ "$(sha256sum "$DUMP" | awk '{print $1}')" = "$(cat "$DUMP.sha256")" ]; then
        echo "ok"
    else
        echo "MISMATCH — this file has changed since it was taken. Refusing." >&2
        exit 1
    fi
fi

TARGET=""
MODE=""
while [ $# -gt 0 ]; do
    case "$1" in
        --into)      TARGET="$2"; MODE="rehearsal"; shift 2 ;;
        --over-live) TARGET="$LIVE_DB"; MODE="live"; shift ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done
[ -n "$TARGET" ] || { echo "Say where: --into <db> or --over-live" >&2; exit 1; }

if [ "$MODE" = "live" ]; then
    cat <<EOF

  ┌────────────────────────────────────────────────────────┐
  │  This replaces the LIVE database: $TARGET
  │  Everything recorded since that backup will be gone.
  └────────────────────────────────────────────────────────┘

  Take a backup of the current state first if there is any doubt:
      deploy/backup.sh

EOF
    read -r -p "  Type the database name to continue: " typed
    [ "$typed" = "$TARGET" ] || { echo "  Names did not match. Nothing was changed."; exit 1; }
fi

if [ "$MODE" = "rehearsal" ]; then
    echo "Creating $TARGET…"
    createdb "$TARGET" 2>/dev/null || echo "  (it already exists — restoring into it)"
fi

echo "Restoring $DUMP → $TARGET"
# --clean --if-exists so a rehearsal database can be reused, and
# a single transaction so a failure leaves nothing half-applied.
pg_restore --dbname="$TARGET" --clean --if-exists --no-owner --no-privileges \
           --single-transaction "$DUMP"

echo
echo "Done. Check it before you trust it:"
echo "  psql -d $TARGET -c \"SELECT COUNT(*) AS invoices FROM invoices\""
echo "  psql -d $TARGET -c \"SELECT MAX(created_at) AS newest FROM audit_logs\""
if [ "$MODE" = "rehearsal" ]; then
    echo
    echo "When you are satisfied:  dropdb $TARGET"
fi
