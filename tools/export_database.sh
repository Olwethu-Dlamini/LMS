#!/usr/bin/env bash
#
# Apply pending migrations to the local lms_db and export a deployable dump.
#
# Usage:
#   ./tools/export_database.sh                 # migrate, then dump
#   ./tools/export_database.sh --dump-only     # dump without migrating
#   ./tools/export_database.sh --schema-only   # structure, no rows
#
# The dump lands in ~/ri-leave-exports/ and is never written into the repo, so a
# database containing real staff records cannot be committed by accident.

set -euo pipefail

DB_NAME="${DB_NAME:-lms_db}"
OUT_DIR="${OUT_DIR:-$HOME/ri-leave-exports}"
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAMP="$(date +%Y%m%d-%H%M%S)"

DUMP_ONLY=0
SCHEMA_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --dump-only)   DUMP_ONLY=1 ;;
        --schema-only) SCHEMA_ONLY=1 ;;
        *) echo "Unknown option: $arg" >&2; exit 2 ;;
    esac
done

# Connection, in order of preference:
#   1. The UAT instance from uat.sh (its own MariaDB on 3307) - this is the
#      database the app actually runs against on this machine.
#   2. MYSQL_USER/MYSQL_PWD over TCP, for a server or a different instance.
#   3. The system MariaDB on 3306 via sudo socket authentication.
UAT_CNF="$HOME/.ri-leave-uat/my.cnf"
if [[ -f "$UAT_CNF" ]] && mysqladmin --defaults-file="$UAT_CNF" -u root --silent ping >/dev/null 2>&1; then
    echo "==> Source: UAT MariaDB instance ($UAT_CNF)"
    MYSQL=(mysql "--defaults-file=$UAT_CNF" -u root)
    MYSQLDUMP=(mysqldump "--defaults-file=$UAT_CNF" -u root)
elif [[ -n "${MYSQL_USER:-}" ]]; then
    echo "==> Source: TCP connection as $MYSQL_USER"
    MYSQL=(mysql -u "$MYSQL_USER")
    MYSQLDUMP=(mysqldump -u "$MYSQL_USER")
else
    echo "==> Source: system MariaDB via sudo"
    MYSQL=(sudo mysql)
    MYSQLDUMP=(sudo mysqldump)
fi

q() { "${MYSQL[@]}" -N -B -e "$1" "$DB_NAME"; }

echo "==> Database: $DB_NAME"
if ! "${MYSQL[@]}" -e "USE \`$DB_NAME\`" 2>/dev/null; then
    echo "Cannot reach database '$DB_NAME'." >&2
    exit 1
fi

echo "==> Before"
q "SELECT CONCAT('    users: ', COUNT(*)) FROM users"
q "SELECT CONCAT('    applications: ', COUNT(*)) FROM leave_applications"
q "SELECT CONCAT('    in flight: ', COUNT(*)) FROM leave_applications
   WHERE status IN ('pending_manager','pending_hr','pending_executive')"

if [[ "$DUMP_ONLY" -eq 0 ]]; then
    shopt -s nullglob
    migrations=("$REPO_DIR"/migrations/*.sql)
    shopt -u nullglob
    if [[ ${#migrations[@]} -eq 0 ]]; then
        echo "==> No migrations found, skipping"
    else
        for m in "${migrations[@]}"; do
            echo "==> Applying $(basename "$m")"
            "${MYSQL[@]}" "$DB_NAME" < "$m"
        done
    fi

    echo "==> Verifying no application waits on its own applicant"
    stranded="$(q "SELECT COUNT(*) FROM leave_applications a
        JOIN users u ON u.id = a.user_id
        JOIN roles r ON r.id = u.role_id
        WHERE (a.status = 'pending_manager'   AND r.name IN ('manager','hr','executive','admin'))
           OR (a.status = 'pending_hr'        AND r.name = 'hr')
           OR (a.status = 'pending_executive' AND r.name = 'executive')")"
    if [[ "$stranded" != "0" ]]; then
        echo "    FAILED: $stranded application(s) still stranded. Not exporting." >&2
        exit 1
    fi
    echo "    OK: 0 stranded applications"
fi

mkdir -p "$OUT_DIR"
if [[ "$SCHEMA_ONLY" -eq 1 ]]; then
    OUT_FILE="$OUT_DIR/${DB_NAME}-schema-${STAMP}.sql"
    DUMP_ARGS=(--no-data --routines --events)
else
    OUT_FILE="$OUT_DIR/${DB_NAME}-${STAMP}.sql"
    DUMP_ARGS=(--single-transaction --routines --events)
fi

echo "==> Exporting to $OUT_FILE"
"${MYSQLDUMP[@]}" \
    "${DUMP_ARGS[@]}" \
    --add-drop-table \
    --default-character-set=utf8mb4 \
    --databases "$DB_NAME" > "$OUT_FILE"

# A truncated dump restores as a silently incomplete database, so confirm
# mysqldump wrote its own end marker before calling this a success.
if ! tail -5 "$OUT_FILE" | grep -q "Dump completed"; then
    echo "    FAILED: dump is incomplete (no end marker). Do not deploy it." >&2
    exit 1
fi

echo "==> Done"
echo "    file:  $OUT_FILE"
echo "    size:  $(du -h "$OUT_FILE" | cut -f1)"
echo "    lines: $(wc -l < "$OUT_FILE")"
echo
echo "Restore on the server with:"
echo "    mysql -u root -p < $(basename "$OUT_FILE")"
