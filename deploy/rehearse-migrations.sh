#!/usr/bin/env bash
# Rehearses a web release's migrations on a copy of production, on the Mac, before the deploy (web/DEPLOY.md):
# a throwaway MariaDB is started, the production dump is imported, and then
#   migrate --pretend  ->  migrate  ->  checks  ->  rollback  ->  checks  ->  migrate  ->  checks
# run against it from the release's code. Any failure stops with a non-zero exit. Nothing here connects to
# production, and production's keys are never needed: the copy runs with a throwaway APP_KEY.
#
#   deploy/rehearse-migrations.sh --dump ~/echoledger-backups/pre-<release>/db.sql.gz --web /path/to/release/web \
#       [--expect 1] [--checks deploy/checks/2.0.0-i.sql] [--keep]
#
#   --dump    the step 1 backup (.sql or .sql.gz)
#   --web     the web/ folder of the release being deployed (its vendor/ installed)
#   --expect  how many migrations the release adds (the rehearsal stops if --pretend lists another number)
#   --checks  SQL lines that must each answer 1 after migrate (lines starting with "-- after-rollback:" must answer 1
#             after the rollback instead); see deploy/checks/
#   --keep    leave the throwaway database running at the end (it prints how to reach it)
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
[ -z "$checks" ] || [ -f "$checks" ] || { echo "No checks file: $checks" >&2; exit 2; }

bin=""
for dir in /opt/homebrew/opt/mariadb@11.8/bin /opt/homebrew/opt/mariadb/bin /usr/local/opt/mariadb/bin; do
  [ -x "$dir/mariadbd" ] && { bin="$dir"; break; }
done
[ -n "$bin" ] || { echo "MariaDB is not installed (brew install mariadb@11.8)" >&2; exit 2; }

port=33$(( RANDOM % 80 + 20 ))
work=$(mktemp -d "${TMPDIR:-/tmp}/el-rehearsal.XXXXXX")
sock="$HOME/.el-rehearsal-$port.sock"   # a short path: Unix sockets allow ~100 characters
db=rehearsal
password=$(openssl rand -hex 16)
pid=""

stop() {
  if [ "$keep" = 1 ] && [ -n "$pid" ]; then
    echo "Kept: mariadb -h 127.0.0.1 -P $port -u rehearsal -p$password $db  (stop: kill $pid; then rm -rf $work)"
    return
  fi
  [ -n "$pid" ] && kill "$pid" 2>/dev/null && wait "$pid" 2>/dev/null || true
  rm -rf "$work" "$sock"
}
trap stop EXIT

step() { printf '\n== %s\n' "$*"; }
fail() { echo "REHEARSAL FAILED: $*" >&2; exit 1; }

step "Throwaway MariaDB on port $port"
"$bin/mariadb-install-db" --no-defaults --datadir="$work/data" --auth-root-authentication-method=normal --skip-test-db >"$work/install.log" 2>&1
"$bin/mariadbd" --no-defaults --datadir="$work/data" --socket="$sock" --port="$port" --bind-address=127.0.0.1 \
  --log-error="$work/error.log" --max-allowed-packet=64M --innodb-buffer-pool-size=256M &
pid=$!
for _ in $(seq 1 60); do [ -S "$sock" ] && break; sleep 0.5; done
[ -S "$sock" ] || fail "MariaDB did not start (see $work/error.log)"
root() { "$bin/mariadb" --no-defaults -S "$sock" -u root "$@"; }
root -e "create database $db character set utf8mb4 collate utf8mb4_unicode_ci;
         create user 'rehearsal'@'127.0.0.1' identified by '$password'; grant all on $db.* to 'rehearsal'@'127.0.0.1';"

step "Importing $(basename "$dump")"
if [[ "$dump" == *.gz ]]; then gunzip -c "$dump"; else cat "$dump"; fi | root "$db"

sql() { root -N -B "$db" -e "$1"; }
counts() {
  sql "select concat_ws(' ', (select count(*) from users), (select count(*) from capture_sessions),
       (select count(*) from transcripts), (select count(*) from session_labels), (select count(*) from clients))"
}

# The release's code against the copy: local environment, throwaway key, no recordings key, nothing sent out.
export APP_ENV=local APP_DEBUG=false APP_KEY="base64:$(openssl rand -base64 32)" APP_URL=https://rehearsal.invalid \
  DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT="$port" DB_DATABASE="$db" DB_USERNAME=rehearsal DB_PASSWORD="$password" \
  CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array LOG_CHANNEL=null
art() { (cd "$web" && php artisan "$@"); }

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

step "migrate --pretend"
pending=$(art migrate:status | grep -c Pending || true)
echo "  $pending pending"
art migrate --pretend --force | grep -E '^\s+[0-9]{4}_' || true
[ -z "$expect" ] || [ "$pending" = "$expect" ] || fail "expected $expect new migrations, found $pending"
[ "$pending" -gt 0 ] || fail "nothing to migrate: is --web the new release?"

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
