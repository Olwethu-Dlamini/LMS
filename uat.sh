#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# RI Leave - local UAT environment control script.
#
#   ./uat.sh start    start MariaDB (port 3307) + PHP web server (port 8000)
#   ./uat.sh stop     stop both
#   ./uat.sh status   show what is running
#   ./uat.sh reset    wipe leave applications/logs, zero balances, keep users
#   ./uat.sh reinstall  drop and re-import schema.sql from scratch
#   ./uat.sh logs     tail the PHP + MariaDB error logs
#
# Why a private MariaDB on 3307: this machine already runs a system MariaDB on
# 3306 whose root password we do not hold, and Docker cannot start containers
# here (runc cannot mask /proc/acpi on kernel 5.4). So UAT gets its own
# instance with its datadir on ext4 (the project lives on an NTFS/fuse mount,
# which InnoDB does not tolerate).
# ---------------------------------------------------------------------------
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
UAT_HOME="$HOME/.ri-leave-uat"
CNF="$UAT_HOME/my.cnf"
DB_PORT=3307
WEB_PORT=8000
MYSQL="mysql --defaults-file=$CNF -u root"

db_up()  { mysqladmin --defaults-file="$CNF" -u root --silent ping >/dev/null 2>&1; }
web_up() { curl -s -o /dev/null --max-time 3 "http://localhost:$WEB_PORT/modules/auth/login.php"; }

start_db() {
  if db_up; then echo "  MariaDB   already running on $DB_PORT"; return; fi
  mkdir -p "$UAT_HOME/run" "$UAT_HOME/log" "$UAT_HOME/tmp"
  nohup /usr/sbin/mysqld --defaults-file="$CNF" >>"$UAT_HOME/log/stdout.log" 2>&1 &
  if mysqladmin --defaults-file="$CNF" -u root --wait=30 --silent ping >/dev/null 2>&1; then
    echo "  MariaDB   started on $DB_PORT"
  else
    echo "  MariaDB   FAILED to start - see $UAT_HOME/log/error.log"; return 1
  fi
}

start_web() {
  if web_up; then echo "  Web       already running on $WEB_PORT"; return; fi
  cd "$APP_DIR" || return 1
  DB_PORT=$DB_PORT PHP_CLI_SERVER_WORKERS=4 nohup php \
    -d display_errors=On -d error_reporting=E_ALL \
    -d date.timezone=Africa/Johannesburg \
    -d upload_max_filesize=10M -d post_max_size=12M \
    -S "localhost:$WEB_PORT" -t "$APP_DIR" >>"$UAT_HOME/log/php-server.log" 2>&1 &
  for _ in $(seq 1 30); do web_up && break; done
  web_up && echo "  Web       started on http://localhost:$WEB_PORT" \
         || { echo "  Web       FAILED - see $UAT_HOME/log/php-server.log"; return 1; }
}

case "${1:-start}" in
  start)
    echo "Starting RI Leave UAT environment..."
    start_db || exit 1
    start_web || exit 1
    echo
    echo "  Open:  http://localhost:$WEB_PORT"
    echo "  Logins (password: password123)"
    echo "    employee@lms.com  manager@lms.com  hr@lms.com  boss@lms.com  admin@lms.com"
    ;;
  stop)
    echo "Stopping..."
    pkill -f "php -d display_errors=On.*localhost:$WEB_PORT" 2>/dev/null \
      && echo "  Web       stopped" || echo "  Web       not running"
    if db_up; then
      mysqladmin --defaults-file="$CNF" -u root shutdown 2>/dev/null \
        && echo "  MariaDB   stopped" || echo "  MariaDB   shutdown failed"
    else
      echo "  MariaDB   not running"
    fi
    ;;
  status)
    db_up  && echo "  MariaDB   UP   (port $DB_PORT)" || echo "  MariaDB   DOWN"
    web_up && echo "  Web       UP   (http://localhost:$WEB_PORT)" || echo "  Web       DOWN"
    if db_up; then
      echo "  Data:"
      $MYSQL lms_db -e "
        SELECT status, COUNT(*) AS applications FROM leave_applications GROUP BY status;" 2>/dev/null \
        | sed 's/^/    /'
    fi
    ;;
  reset)
    db_up || { echo "MariaDB is not running - ./uat.sh start first"; exit 1; }
    $MYSQL lms_db -e "
      SET FOREIGN_KEY_CHECKS=0;
      TRUNCATE TABLE leave_approval_logs;
      TRUNCATE TABLE leave_applications;
      SET FOREIGN_KEY_CHECKS=1;
      UPDATE leave_entitlements SET used_days=0, pending_days=0;"
    rm -f "$APP_DIR"/uploads/attachments/med_*
    echo "  Applications, approval logs, balances and uploads reset. Users kept."
    ;;
  reinstall)
    db_up || { echo "MariaDB is not running - ./uat.sh start first"; exit 1; }
    $MYSQL -e "DROP DATABASE IF EXISTS lms_db;"
    $MYSQL < "$APP_DIR/schema.sql" && echo "  schema.sql re-imported from scratch."
    rm -f "$APP_DIR"/uploads/attachments/med_*
    ;;
  logs)
    tail -n 40 "$UAT_HOME/log/php-server.log" "$UAT_HOME/log/error.log" 2>/dev/null
    ;;
  *)
    echo "usage: ./uat.sh {start|stop|status|reset|reinstall|logs}"; exit 1
    ;;
esac
