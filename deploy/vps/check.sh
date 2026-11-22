#!/usr/bin/env bash
# Read-only check of the relay VPS against deploy/vps/README.md: changes nothing.
#   sudo bash check.sh [--expect-lockdown]
# PASS / WARN / FAIL per line; exit 1 when anything FAILs. --expect-lockdown turns the SSH warnings into failures (once
# ssh-lockdown.sh has run).
# No pipefail here: `… | grep -q` stops reading at its first match, and under pipefail the writer's broken pipe would
# turn a match into a failure, so a leak would read as "none found". The leak checks count their matches instead.
set -u
lockdown=0
[ "${1:-}" = --expect-lockdown ] && lockdown=1
fails=0
pass() { printf 'PASS  %s\n' "$*"; }
warn() { printf 'WARN  %s\n' "$*"; }
fail() { printf 'FAIL  %s\n' "$*"; fails=$((fails + 1)); }
check() { if eval "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }
sshwarn() { if [ "$lockdown" = 1 ]; then fail "$1"; else warn "$1 (until ssh-lockdown.sh)"; fi; }

. /etc/os-release
echo "$(hostname) · ${PRETTY_NAME:-?} · up $(uptime -p 2>/dev/null | sed 's/^up //')"

# Updates
check "automatic security updates on" "grep -q 'Unattended-Upgrade \"1\"' /etc/apt/apt.conf.d/20auto-upgrades"
check "security origins only, no automatic reboot" "grep -q 'Automatic-Reboot \"false\"' /etc/apt/apt.conf.d/52echoledger-unattended"
last=$(ls -t /var/log/unattended-upgrades/unattended-upgrades.log* 2>/dev/null | head -1)
if [ -n "$last" ] && [ "$(find "$last" -mtime -2 | wc -l)" -gt 0 ]; then pass "updates ran in the last 2 days"; else warn "no update run seen in the last 2 days"; fi
if [ -f /var/run/reboot-required ]; then warn "a reboot is waiting (security updates)"; else pass "no reboot waiting"; fi

# Firewall and what listens
status=$(ufw status verbose 2>/dev/null)
grep -q "Status: active" <<<"$status" && pass "firewall on" || fail "firewall on"
grep -q "Default: deny (incoming)" <<<"$status" && pass "incoming denied by default" || fail "incoming denied by default"
rules=$(ufw show added 2>/dev/null)
grep -Eq '(^| )8787(/(tcp|udp))?( |$)|port 8787( |$)' <<<"$rules" && fail "a firewall rule opens the relay's port 8787" || pass "8787 closed in the firewall"
sshd_conf=$(sshd -T 2>/dev/null)
ssh_ports=$(awk '$1 == "port" { print $2 }' <<<"$sshd_conf")
[ -n "$ssh_ports" ] || fail "sshd -T names no port (sshd missing or its settings invalid): SSH not checked"
for port in $ssh_ports; do
  grep -Eq "^${port}/tcp +LIMIT" <<<"$status" && pass "SSH ($port) rate limited" || fail "SSH ($port) not rate limited"
  # An ALLOW for the same port comes before the LIMIT and lets every attempt through (DEPLOY.md's `ufw allow OpenSSH`).
  # Read from the rules as added: the same whether the firewall is on or off, and without status's display names.
  grep -Eq "^ufw allow (OpenSSH|${port}|${port}/tcp)( comment .*)?$" <<<"$rules" && fail "an ALLOW rule for SSH ($port) skips the rate limit" || pass "no ALLOW rule skips the SSH rate limit ($port)"
done
public=$(ss -H -tln 2>/dev/null | awk -v ports=" $ssh_ports " '{ split($4, a, ":"); port = a[length(a)]; addr = substr($4, 1, length($4) - length(port) - 1)
  if (addr !~ /^(127\.|\[::1\]|::1)/ && index(ports, " " port " ") == 0 && port != 80 && port != 443) print $4 }' | tr '\n' ' ')
[ -z "$public" ] && pass "only SSH, 80 and 443 listen publicly" || fail "also listening publicly: $public"
ss -H -tln | awk '{print $4}' | grep -Eq '^(0\.0\.0\.0|\*|\[::\]):8787$' && fail "relay listens on every address" || pass "relay on loopback only"
check "fail2ban guards SSH" "fail2ban-client status sshd"
check "clock in sync" "timedatectl show -p NTPSynchronized --value | grep -qx yes"

# SSH
# As sshd decides for root signing in from somewhere else (a Match block can change it per user or address).
eff=$(sshd -T -C user=root,host=check.invalid,addr=203.0.113.9 2>/dev/null)
grep -qx "permitrootlogin no" <<<"$eff" && pass "root cannot sign in" || sshwarn "root can sign in over SSH"
grep -qx "passwordauthentication no" <<<"$eff" && pass "no password sign-in" || sshwarn "passwords accepted over SSH"

# Relay
check "relay service running" "systemctl is-active --quiet assist-relay"
check "relay healthy" "curl -fsS -m 5 http://127.0.0.1:8787/healthz | grep -q '\"status\":\"ok\"'"
check "nginx running" "systemctl is-active --quiet nginx"
# Logs with no content: the apps send tokens in a header, so a token in a logged URL means something is wrong.
# Counted (grep -c reads to the end), never grep -q in a pipe: see the top of this file.
leak='token=|Bearer |eyJ[A-Za-z0-9_-]{10,}\.|sk-[A-Za-z0-9_-]{10}'
n=$(cat /var/log/nginx/access.log /var/log/nginx/access.log.1 2>/dev/null | grep -Ec "$leak")
[ "${n:-0}" -eq 0 ] && pass "no tokens in nginx's access log" || fail "a token appears in nginx's access log ($n lines)"
n=$(journalctl -u assist-relay --since "-24h" -o cat 2>/dev/null | grep -Ec "$leak"'|"text":')
[ "${n:-0}" -eq 0 ] && pass "relay journal: no tokens, keys or text in 24 h" || fail "the relay's journal holds a token, key or text ($n lines)"
check "journal capped" "grep -q SystemMaxUse /etc/systemd/journald.conf.d/relay.conf"

# Ops
for t in echoledger-relay-watchdog echoledger-resources echoledger-backup; do
  check "$t timer on" "systemctl is-active --quiet $t.timer"
  result=$(systemctl show -p Result --value "$t.service" 2>/dev/null)
  [ "$result" = success ] || [ -z "$result" ] && pass "$t last run ok" || fail "$t last run: $result"
done
newest=$(ls -t /var/backups/echoledger-relay/relay-config-*.tar.gz 2>/dev/null | head -1)
if [ -n "$newest" ] && [ "$(find "$newest" -mmin -1560 | wc -l)" -gt 0 ]; then
  pass "settings backup from the last 26 h ($(basename "$newest"))"
  n=$(tar -tzf "$newest" 2>/dev/null | grep -Ec '(\.key|\.pem)$|alert\.env|credstore|credential\.secret|/\.ssh/')
  [ "${n:-0}" -eq 0 ] && pass "the backup holds no secret" || fail "the backup holds a secret ($n entries)"
else
  fail "no settings backup in the last 26 h"
fi
[ "$(stat -c '%U:%G %a' /etc/echoledger-ops/alert.env 2>/dev/null)" = "root:root 600" ] && pass "alert.env is root's only" || fail "alert.env is not root:root 0600"
if grep -Eq '^ALERT_URL=.+' /etc/echoledger-ops/alert.env 2>/dev/null; then pass "alerts go out (ALERT_URL set)"; else warn "alerts only in the journal: set ALERT_URL (README, Alerts)"; fi
check "needrestart leaves the relay and nginx alone" "grep -q 'assist-relay' /etc/needrestart/conf.d/echoledger.conf"
check "upgrades run at a quiet hour" "systemctl cat apt-daily-upgrade.timer | grep -q 'echoledger'"

# Now
cores=$(nproc)
printf 'INFO  load %s on %s core(s) · memory %s%% used · disk / %s used\n' "$(cut -d' ' -f1-3 /proc/loadavg)" "$cores" \
  "$(awk '/^MemTotal:/ {t=$2} /^MemAvailable:/ {a=$2} END { printf "%d", (t - a) * 100 / t }' /proc/meminfo)" "$(df -P / | awk 'NR == 2 { print $5 }')"

echo
[ "$fails" -eq 0 ] && echo "ALL CHECKS PASSED" || echo "$fails CHECK(S) FAILED"
[ "$fails" -eq 0 ]
