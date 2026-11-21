#!/usr/bin/env bash
# Tests of the relay VPS ops checks (deploy/vps/ops) with fixtures, on any machine with bash, awk and tar.
#   bash deploy/vps/tests/test-ops.sh
# The full install runs in a throwaway Ubuntu 24.04 with systemd: deploy/vps/tests/container.sh.
set -uo pipefail
ops=$(cd "$(dirname "$0")/../ops" && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
failures=0
ok() { printf 'ok    %s\n' "$*"; }
no() { printf 'FAIL  %s\n' "$*"; failures=$((failures + 1)); }
expect() { if eval "$2"; then ok "$1"; else no "$1"; fi; }

export OPS_ETC="$tmp/etc" OPS_STATE="$tmp/state" OPS_NO_JOURNAL=1
mkdir -p "$OPS_ETC" "$tmp/proc"
printf 'ALERT_URL=https://alerts.invalid/topic\nALERT_KIND=ntfy\n' >"$OPS_ETC/alert.env"
sent="$tmp/sent"
export ALERT_CMD="tee -a $sent" REBOOT_FLAG="$tmp/no-reboot"
: >"$sent"
fixture() { # load5 cores mem_total mem_available
  printf '0.10 %s 0.20 1/100 1234\n' "$1" >"$tmp/proc/loadavg"
  printf 'MemTotal:       %s kB\nMemFree:          1000 kB\nMemAvailable:   %s kB\n' "$3" "$4" >"$tmp/proc/meminfo"
  export CORES="$2"
}
resources() { PROC="$tmp/proc" DISK_PATHS="/" DISK_PCT="${DISK_PCT:-101}" bash "$ops/resource-check.sh" 2>/dev/null >/dev/null; }
count() { grep -c "$1" "$sent" 2>/dev/null || true; }

# Resources: quiet while fine, one alert when a limit is crossed, none while it stays crossed, one when it clears.
fixture 0.5 2 4000000 3000000
resources
expect "no alert while everything is under its limit" '[ ! -s "$sent" ]'
fixture 1.8 2 4000000 3000000
resources
expect "CPU over 80 % of the cores alerts once" '[ "$(count "ALERT: CPU at 90%")" = 1 ]'
resources; resources
expect "the same CPU alert is not repeated" '[ "$(count "ALERT: CPU")" = 1 ]'
fixture 0.4 2 4000000 3000000
resources
expect "CPU back under the limit says so once" '[ "$(count "OK again: CPU")" = 1 ]'
fixture 0.4 2 4000000 400000
resources
expect "memory at 90 % used (available, not free) alerts" '[ "$(count "ALERT: memory at 90% used")" = 1 ]'
fixture 0.4 2 4000000 2000000
DISK_PCT=0 resources
expect "a disk over its limit alerts with its path" '[ "$(count "ALERT: disk / at")" = 1 ]'
touch "$tmp/reboot"; REBOOT_FLAG="$tmp/reboot" resources
expect "a waiting reboot alerts" '[ "$(count "ALERT: security updates wait for a reboot")" = 1 ]'
expect "messages carry the host name" 'grep -q "^\[" "$sent"'

# Hysteresis: memory fires at 80, stays firing down to 75, clears under 75.
: >"$sent"; rm -f "$OPS_STATE/memory"
fixture 0.4 2 1000000 180000; resources   # 82 %
fixture 0.4 2 1000000 230000; resources   # 77 %
expect "a value just under the limit keeps the alert (no flapping)" '[ "$(count "ALERT: memory")" = 1 ] && [ "$(count "OK again: memory")" = 0 ]'
fixture 0.4 2 1000000 260000; resources   # 74 %
expect "it clears 5 points under the limit" '[ "$(count "OK again: memory")" = 1 ]'

# needrestart holding back the relay or nginx after an update alerts; anything else does not.
: >"$sent"
printf 'NEEDRESTART-VER: 3.6\nNEEDRESTART-SVC: assist-relay.service\nNEEDRESTART-SVC: cron.service\n' >"$tmp/nr"
NEEDRESTART_OUTPUT="$tmp/nr" resources
expect "the relay waiting for a restart alerts, naming it" '[ "$(count "ALERT: updated libraries wait for a restart of assist-relay.service")" = 1 ]'
printf 'NEEDRESTART-SVC: cron.service\n' >"$tmp/nr"
NEEDRESTART_OUTPUT="$tmp/nr" resources
expect "and recovers once nothing of ours waits" '[ "$(count "OK again: no service waits")" = 1 ]'

# Slack and Discord bodies are JSON.
: >"$sent"
printf 'ALERT_URL=https://alerts.invalid/x\nALERT_KIND=slack\n' >"$OPS_ETC/alert.env"
rm -f "$OPS_STATE/cpu"; fixture 1.9 2 4000000 3000000; resources
expect "a Slack webhook gets {\"text\": …}" 'grep -q "^{\"text\":\"\[" "$sent"'
: >"$sent"
printf 'ALERT_URL=\n' >"$OPS_ETC/alert.env"
rm -f "$OPS_STATE/cpu"; resources
expect "without ALERT_URL nothing is sent" '[ ! -s "$sent" ]'
printf 'ALERT_URL=https://alerts.invalid/topic\nALERT_KIND=ntfy\n' >"$OPS_ETC/alert.env"

# Watchdog: three misses in a row restart the relay once; a healthy answer resets the count; a clean stop is left alone.
: >"$sent"; rm -rf "$OPS_STATE"; mkdir -p "$OPS_STATE"
cat >"$tmp/systemctl" <<EOF
#!/usr/bin/env bash
echo "\$@" >>"$tmp/systemctl.log"
case "\$1" in
  is-enabled) exit 0 ;;
  is-active) cat "$tmp/active" ;;
  show) cat "$tmp/result" ;;
  restart) exit 0 ;;
esac
EOF
chmod +x "$tmp/systemctl"
echo active >"$tmp/active"; echo success >"$tmp/result"; : >"$tmp/systemctl.log"
watchdog() { SYSTEMCTL="$tmp/systemctl" RELAY_HEALTH_URL="$1" bash "$ops/relay-watchdog.sh" 2>/dev/null; }
down="http://127.0.0.1:9/healthz"
watchdog "$down"; watchdog "$down"
expect "two misses do not restart" '! grep -q restart "$tmp/systemctl.log"'
watchdog "$down"
expect "the third miss in a row restarts the relay" '[ "$(grep -c restart "$tmp/systemctl.log")" = 1 ]'
expect "and alerts once" '[ "$(count "ALERT: relay did not answer its health check 3 times")" = 1 ]'
watchdog "$down"; watchdog "$down"
expect "no restart loop after it" '[ "$(grep -c restart "$tmp/systemctl.log")" = 1 ]'
echo inactive >"$tmp/active"; : >"$tmp/systemctl.log"; echo 0 >"$OPS_STATE/relay-misses"
for _ in 1 2 3 4; do watchdog "$down"; done
expect "a relay stopped on purpose is left alone" '! grep -q restart "$tmp/systemctl.log"'
echo active >"$tmp/active"; echo 2 >"$OPS_STATE/relay-misses"; echo inactive >"$tmp/active"
watchdog "$down"
expect "a clean stop resets the miss count" '[ "$(cat "$OPS_STATE/relay-misses")" = 0 ]'

# Backup: settings in, secrets out, the newest 14 kept.
root="$tmp/root/"
mkdir -p "$root/etc/assist-relay" "$root/etc/nginx/sites-available" "$root/etc/echoledger-ops" "$root/etc/letsencrypt/renewal" \
  "$root/etc/credstore.encrypted" "$root/etc/ssh/sshd_config.d" "$root/opt/assist-relay/releases/abc123"
echo 'RELAY_BIND=127.0.0.1:8787' >"$root/etc/assist-relay/relay.env"
echo 'server {}' >"$root/etc/nginx/sites-available/assist-relay"
echo 'CPU_PCT=80' >"$root/etc/echoledger-ops/ops.env"
echo 'ALERT_URL=https://secret.invalid' >"$root/etc/echoledger-ops/alert.env"
echo 'secret' >"$root/etc/credstore.encrypted/relay_secret"
echo 'key' >"$root/etc/nginx/sites-available/privkey.pem"
echo 'cert = x' >"$root/etc/letsencrypt/renewal/relay.conf"
echo 'binary' >"$root/opt/assist-relay/releases/abc123/assist-relay"
mkdir -p "$root/etc/assist-relay/credstore.encrypted" "$root/etc/assist-relay/.ssh"
echo 'secret' >"$root/etc/assist-relay/credstore.encrypted/relay_secret"
echo 'key' >"$root/etc/assist-relay/.ssh/id_ed25519"
ln -s "$root/opt/assist-relay/releases/abc123" "$root/opt/assist-relay/current"
backup() { BACKUP_ROOT="$root" BACKUP_DIR="$tmp/backups" BACKUP_KEEP=14 bash "$ops/config-backup.sh" >/dev/null 2>&1; }
backup
archive=$(ls "$tmp/backups"/relay-config-*.tar.gz | head -1)
list=$(tar -tzf "$archive")
expect "the backup holds the relay's settings, nginx, ops limits and the current binary" \
  'grep -q etc/assist-relay/relay.env <<<"$list" && grep -q sites-available/assist-relay <<<"$list" && grep -q ops.env <<<"$list" && grep -q releases/abc123 <<<"$list"'
expect "and no secret: no alert.env, credstore, private key" '! grep -Eq "alert.env|credstore|privkey" <<<"$list"'
expect "not even inside a backed-up folder (credstore, .ssh)" '! grep -Eq "credstore|\.ssh|id_ed25519" <<<"$list"'
expect "with its sha256" '(cd "$tmp/backups" && (sha256sum -c "$(basename "$archive").sha256" 2>/dev/null || shasum -a 256 -c "$(basename "$archive").sha256") >/dev/null)'
expect "readable by its owner only" '[ "$(stat -f %Lp "$archive" 2>/dev/null || stat -c %a "$archive")" = 600 ]'
for i in $(seq 1 16); do touch -t "2026010${i:0:1}0000.$(printf %02d "$i")" "$tmp/backups/relay-config-2026010${i}T000000Z.tar.gz" 2>/dev/null || touch "$tmp/backups/relay-config-old$i.tar.gz"; done
backup
expect "only the newest 14 are kept" '[ "$(ls "$tmp/backups"/relay-config-*.tar.gz | wc -l | tr -d " ")" = 14 ]'

echo
[ "$failures" -eq 0 ] && echo "ALL OPS TESTS PASSED" || echo "$failures OPS TEST(S) FAILED"
[ "$failures" -eq 0 ]
