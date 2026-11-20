#!/usr/bin/env bash
# Rehearses a web release's migrations on a copy of production, on the Mac, before the deploy (web/DEPLOY.md):
# a throwaway MariaDB is started, the production dump is imported, and then
#   migrate --pretend  ->  migrate  ->  checks  ->  rollback  ->  checks  ->  migrate  ->  checks
# run against it from the release's code. Any failure stops with a non-zero exit. Nothing here connects to
# production, and production's keys are never needed: the copy runs with a throwaway APP_KEY.
#
#   deploy/rehearse-migrations.sh --dump ~/echoledger-backups/pre-<release>/db.sql.gz --web /path/to/release/web \
#       --expect 1 [--checks deploy/checks/2.0.0-i.sql] [--keep]
#
#   --dump    the step 1 backup (.sql or .sql.gz)
#   --web     the web/ folder of the release being deployed (its vendor/ installed)
#   --expect  how many migrations the release adds (required; the rehearsal stops if another number is pending)
#   --checks  SQL lines that must each answer 1 after migrate (lines starting with "-- after-rollback:" must answer 1
#             after the rollback instead); see deploy/checks/
#   --keep    leave the throwaway database running at the end (it prints how to reach it)
#
# Safety: it refuses a release folder with a cached config (bootstrap/cache/config.php names its own database and would
# win over everything set here); every database setting is set here, so none comes from the release's .env; and before
# changing anything it proves artisan reads the throwaway copy. The copy listens on a private socket only, never on the
# network, with passwords for root and the app user.
set -euo pipefail

dump="" web="" expect="" checks="" keep=0
while [ $# -gt 0 ]; do
  case "$1" in
    --dump) dump="$2"; shift 2 ;;
    --web) web="$2"; shift 2 ;;
    --expect) expect="$2"; shift 2 ;;
    --checks) checks="$2"; shift 2 ;;
    --keep) keep=1; shift ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done
[ -f "$dump" ] || { echo "No dump: --dump <file.sql.gz>" >&2; exit 2; }
[ -f "$web/artisan" ] || { echo "No release: --web <release>/web (with artisan)" >&2; exit 2; }
[[ "$expect" =~ ^[1-9][0-9]*$ ]] || { echo "Say how many migrations the release adds: --expect <n> (a whole number, 1 or more)" >&2; exit 2; }
[ -z "$checks" ] || [ -f "$checks" ] || { echo "No checks file: $checks" >&2; exit 2; }
if [ -e "$web/bootstrap/cache/config.php" ]; then
  echo "Refusing: $web/bootstrap/cache/config.php exists, and Laravel would use the database it names." >&2
  echo "Rehearse from a clean checkout of the release (or delete that file there, never on the server)." >&2
  exit 2
fi

bin=""
for dir in /opt/homebrew/opt/mariadb@11.8/bin /opt/homebrew/opt/mariadb/bin /usr/local/opt/mariadb/bin; do
  [ -x "$dir/mariadbd" ] && { bin="$dir"; break; }
done
[ -n "$bin" ] || { echo "MariaDB is not installed (brew install mariadb@11.8)" >&2; exit 2; }

work=$(mktemp -d "${TMPDIR:-/tmp}/el-rehearsal.XXXXXX")
chmod 700 "$work"
sock="$HOME/.el-rehearsal-$$.sock"   # a short path: Unix sockets allow about 100 characters
db=rehearsal
password=$(openssl rand -hex 16)
root_password=$(openssl rand -hex 16)
marker=$(openssl rand -hex 12)
pid=""

stop() {
  if [ "$keep" = 1 ] && [ -n "$pid" ]; then
    echo "Kept: $bin/mariadb --defaults-extra-file=$work/root.cnf $db   (stop: kill $pid; rm -rf $work $sock)"
    return
  fi
  [ -n "$pid" ] && kill "$pid" 2>/dev/null && wait "$pid" 2>/dev/null || true
  rm -rf "$work" "$sock"
}
trap stop EXIT

step() { printf '\n== %s\n' "$*"; }
fail() { echo "REHEARSAL FAILED: $*" >&2; exit 1; }

step "Throwaway MariaDB on a private socket (no network)"
"$bin/mariadb-install-db" --no-defaults --datadir="$work/data" --auth-root-authentication-method=normal --skip-test-db >"$work/install.log" 2>&1
"$bin/mariadbd" --no-defaults --datadir="$work/data" --socket="$sock" --skip-networking \
  --log-error="$work/error.log" --max-allowed-packet=64M --innodb-buffer-pool-size=256M &
pid=$!
for _ in $(seq 1 60); do [ -S "$sock" ] && break; sleep 0.5; done
[ -S "$sock" ] || fail "MariaDB did not start (see $work/error.log)"
"$bin/mariadb" --no-defaults -S "$sock" -u root -e "ALTER USER 'root'@'localhost' IDENTIFIED BY '$root_password';"
printf '[client]\nuser=root\npassword=%s\nsocket=%s\n' "$root_password" "$sock" >"$work/root.cnf"
chmod 600 "$work/root.cnf"
root() { "$bin/mariadb" --defaults-extra-file="$work/root.cnf" "$@"; }
root -e "CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
         CREATE USER 'rehearsal'@'localhost' IDENTIFIED BY '$password'; GRANT ALL ON $db.* TO 'rehearsal'@'localhost';"

step "Importing $(basename "$dump")"
if [[ "$dump" == *.gz ]]; then gunzip -c "$dump"; else cat "$dump"; fi | root "$db"

sql() { root -N -B "$db" -e "$1"; }
counts() {
  sql "select concat_ws(' ', (select count(*) from users), (select count(*) from capture_sessions),
       (select count(*) from transcripts), (select count(*) from session_labels), (select count(*) from clients))"
}

# The release's code against the copy. Exported values win over the release's .env, so every setting the database
# connection reads is set here; the config, route and event caches live in the work folder; a throwaway key, no
# recordings key, and nothing is sent out.
export APP_ENV=rehearsal APP_DEBUG=false APP_KEY="base64:$(openssl rand -base64 32)" APP_URL=https://rehearsal.invalid \
  APP_CONFIG_CACHE="$work/config.php" APP_ROUTES_CACHE="$work/routes.php" APP_EVENTS_CACHE="$work/events.php" \
  DB_CONNECTION=mariadb DB_URL= DB_HOST=localhost DB_PORT=3306 DB_SOCKET="$sock" DB_DATABASE="$db" DB_USERNAME=rehearsal \
  DB_PASSWORD="$password" MYSQL_ATTR_SSL_CA= DB_CHARSET=utf8mb4 DB_COLLATION=utf8mb4_unicode_ci \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array LOG_CHANNEL=null
art() { (cd "$web" && php artisan "$@"); }

step "Is artisan on the throwaway copy?"
# It must read back a value only this copy holds before it may change anything.
sql "CREATE TABLE _rehearsal_marker (v varchar(32)); INSERT INTO _rehearsal_marker VALUES ('$marker');"
seen=$(cd "$web" && php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  try { echo Illuminate\Support\Facades\DB::table("_rehearsal_marker")->value("v"); } catch (Throwable $e) { echo "nothing"; }
' 2>/dev/null || true)
sql "DROP TABLE _rehearsal_marker;"
[ "$seen" = "$marker" ] || fail "artisan does not reach the throwaway copy (it read '${seen:-nothing}'): stopped before any migration"
echo "  yes: it reads the value only the copy holds"

run_checks() {
  local phase="$1" line query answer
  [ -n "$checks" ] || return 0
  while IFS= read -r line || [ -n "$line" ]; do
    case "$phase:$line" in
      after:"-- after-rollback:"*) continue ;;
      rollback:"-- after-rollback:"*) query="${line#-- after-rollback:}" ;;
      *:--*|*:) continue ;;
      rollback:*) continue ;;
      *) query="$line" ;;
    esac
    answer=$(sql "$query" | tail -1)
    [ "$answer" = 1 ] || fail "check ($phase) answered '$answer': $query"
    echo "  ok: $query"
  done <"$checks"
}

step "Before"
before=$(counts)
echo "  users, recordings, transcripts, labels, projects: $before"
art migrate:status | tail -3

step "migrate --pretend (the SQL it would run)"
pending=$(art migrate:status | grep -c Pending || true)
echo "  $pending pending"
art migrate --pretend --force >"$work/pretend.txt" 2>&1 || { sed 's/^/  /' "$work/pretend.txt"; fail "migrate --pretend failed"; }
sed 's/^/  /' "$work/pretend.txt"
[ "$pending" = "$expect" ] || fail "expected $expect new migration(s), found $pending pending"

step "migrate"
art migrate --force
[ "$(counts)" = "$before" ] || fail "counts changed: $before -> $(counts)"
run_checks after

step "rollback --step=$pending"
art migrate:rollback --step="$pending" --force
[ "$(counts)" = "$before" ] || fail "counts changed on rollback: $before -> $(counts)"
[ "$(art migrate:status | grep -c Pending || true)" = "$pending" ] || fail "rollback did not undo all $pending"
run_checks rollback

step "migrate again"
art migrate --force
[ "$(counts)" = "$before" ] || fail "counts changed on the second migrate: $before -> $(counts)"
[ "$(art migrate:status | grep -c Pending || true)" = 0 ] || fail "migrations still pending"
run_checks after

step "REHEARSAL PASSED: $pending migration(s) up, down and up on a copy of production; counts unchanged ($before)"
