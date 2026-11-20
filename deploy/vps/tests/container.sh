#!/usr/bin/env bash
# The relay VPS scripts on a throwaway Ubuntu 24.04 with systemd (Docker): harden.sh twice (the second run changes
# nothing), check.sh, the watchdog restarting a relay that hangs, the backup, and both steps of ssh-lockdown.sh.
#   bash deploy/vps/tests/container.sh          (needs Docker; removes its container at the end)
set -uo pipefail
here=$(cd "$(dirname "$0")" && pwd)
vps=$(cd "$here/.." && pwd)
name="el-vps-test-$$"
failures=0
ok() { printf 'ok    %s\n' "$*"; }
no() { printf 'FAIL  %s\n' "$*"; failures=$((failures + 1)); }
inside() { docker exec "$name" bash -c "$1"; }
expect() { if inside "$2" >/dev/null 2>&1; then ok "$1"; else no "$1"; fi; }

docker build -q -t el-vps-test "$here" >/dev/null || { echo "docker build failed" >&2; exit 2; }
docker run -d --name "$name" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /run --tmpfs /run/lock el-vps-test >/dev/null
trap 'docker rm -f "$name" >/dev/null 2>&1' EXIT
for _ in $(seq 1 30); do inside 'systemctl is-system-running 2>/dev/null | grep -Eq "running|degraded"' && break; sleep 1; done
docker cp "$vps" "$name:/root/vps" >/dev/null
inside 'touch /run/relay-healthy; systemctl restart assist-relay; echo "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAITestKeyOnlyForTheContainerTest test@mac" > /root/.ssh/authorized_keys'

echo "== harden.sh"
inside 'bash /root/vps/harden.sh' >/tmp/el-harden-1.log 2>&1; code=$?
[ "$code" = 0 ] && ok "harden.sh runs (exit 0)" || { no "harden.sh exit $code"; tail -30 /tmp/el-harden-1.log; }
expect "firewall on, incoming denied, SSH limited, 80/443 open" 'ufw status verbose | grep -q "Status: active" && ufw status | grep -Eq "^22/tcp +LIMIT" && ufw status | grep -q "Nginx Full"'
expect "security updates on, no automatic reboot" 'grep -q "Automatic-Reboot \"false\"" /etc/apt/apt.conf.d/52echoledger-unattended'
expect "fail2ban guards SSH" 'fail2ban-client status sshd'
expect "journal capped" 'grep -q SystemMaxUse=1G /etc/systemd/journald.conf.d/relay.conf'
expect "three ops timers on" '[ "$(systemctl is-active echoledger-relay-watchdog.timer echoledger-resources.timer echoledger-backup.timer | grep -c ^active)" = 3 ]'
expect "alert.env is root's only" '[ "$(stat -c %a /etc/echoledger-ops/alert.env)" = 600 ]'
expect "a first backup was made, with no secret" 'f=$(ls /var/backups/echoledger-relay/*.tar.gz | head -1) && tar -tzf "$f" | grep -q assist-relay.service && ! tar -tzf "$f" | grep -Eq "alert.env|credstore|\.key$"'

echo "== harden.sh again"
inside 'bash /root/vps/harden.sh' >/tmp/el-harden-2.log 2>&1; code=$?
[ "$code" = 0 ] && ok "second run exit 0" || no "second run exit $code"
grep -q "  wrote " /tmp/el-harden-2.log && { no "second run rewrote files:"; grep "  wrote " /tmp/el-harden-2.log; } || ok "second run changes no file"

echo "== a public listener stops it"
inside 'nohup python3 -m http.server 8080 --bind 0.0.0.0 >/dev/null 2>&1 &' ; sleep 1
inside 'bash /root/vps/harden.sh' >/tmp/el-harden-3.log 2>&1; code=$?
[ "$code" = 3 ] && grep -q ":8080" /tmp/el-harden-3.log && ok "stops (exit 3) and names :8080" || no "did not stop for :8080 (exit $code)"
inside 'pkill -f "http.server 8080"'

echo "== check.sh"
inside 'bash /root/vps/check.sh' >/tmp/el-check.log 2>&1; code=$?
grep -E "^(FAIL|WARN)" /tmp/el-check.log | sed 's/^/      /'
# In a container: no NTP sync and no unattended-upgrades run yet; those may warn or fail here and nowhere else.
real=$(grep "^FAIL" /tmp/el-check.log | grep -v -e "clock in sync" -e "updates ran" | wc -l | tr -d ' ')
[ "$real" = 0 ] && ok "check.sh passes (bar the clock, which a container cannot sync)" || no "check.sh: $real failure(s)"
expect "check.sh warns while root can still sign in" 'bash /root/vps/check.sh | grep -q "WARN  root can sign in"'

echo "== watchdog"
# The installed timer runs the same check every minute: pause it, so only the test counts the misses.
inside 'systemctl stop echoledger-relay-watchdog.timer; echo 0 > /var/lib/echoledger-ops/relay-misses'
inside 'rm -f /run/relay-healthy; for i in 1 2; do /usr/local/lib/echoledger-ops/relay-watchdog.sh; done'
expect "two misses: no restart yet" '[ "$(cat /var/lib/echoledger-ops/relay-misses)" = 2 ]'
inside 'before=$(systemctl show -p InvocationID --value assist-relay); echo "$before" > /tmp/inv; touch /run/relay-healthy-after; /usr/local/lib/echoledger-ops/relay-watchdog.sh'
expect "the third miss restarts the relay" '[ "$(systemctl show -p InvocationID --value assist-relay)" != "$(cat /tmp/inv)" ]'
expect "and says so in the journal" 'journalctl -t echoledger-ops -o cat | grep -q "ALERT relay"'
inside 'touch /run/relay-healthy; sleep 1; /usr/local/lib/echoledger-ops/relay-watchdog.sh'
expect "health back: count reset, recovery logged" '[ "$(cat /var/lib/echoledger-ops/relay-misses)" = 0 ] && journalctl -t echoledger-ops -o cat | grep -q "RECOVERED relay"'
inside 'systemctl stop assist-relay; rm -f /run/relay-healthy; for i in 1 2 3 4; do /usr/local/lib/echoledger-ops/relay-watchdog.sh; done'
expect "a relay stopped on purpose stays stopped" '[ "$(systemctl is-active assist-relay)" = inactive ]'
inside 'touch /run/relay-healthy; systemctl start assist-relay; systemctl start echoledger-relay-watchdog.timer'

echo "== ssh-lockdown.sh"
inside 'bash /root/vps/ssh-lockdown.sh --admin elops' >/tmp/el-lock-1.log 2>&1
expect "step 1 makes elops with root's key and sudo, and changes no SSH setting" \
  'id elops && grep -q TestKeyOnly /home/elops/.ssh/authorized_keys && sudo -u elops sudo -n true && [ ! -f /etc/ssh/sshd_config.d/00-echoledger.conf ]'
expect "the admin account has no password" '[ "$(passwd -S elops | cut -d" " -f2)" = L ] || [ "$(passwd -S elops | cut -d" " -f2)" = NP ]'
inside 'bash /root/vps/ssh-lockdown.sh --admin root' >/dev/null 2>&1 && no "refuses root as the admin" || ok "refuses root as the admin"
inside 'bash /root/vps/ssh-lockdown.sh --admin elops --i-tested-key-login' >/tmp/el-lock-2.log 2>&1; code=$?
[ "$code" = 0 ] && ok "step 2 runs" || { no "step 2 exit $code"; cat /tmp/el-lock-2.log; }
expect "root sign-in and passwords off, as sshd itself reads it" 'sshd -T | grep -qx "permitrootlogin no" && sshd -T | grep -qx "passwordauthentication no" && sshd -T | grep -qx "kbdinteractiveauthentication no"'
expect "check.sh --expect-lockdown passes the SSH lines" '! bash /root/vps/check.sh --expect-lockdown | grep -Eq "FAIL  (root|passwords)"'
inside 'printf "PasswordAuthentication yes\n" > /etc/ssh/sshd_config.d/00-aaa.conf; bash /root/vps/ssh-lockdown.sh --admin elops --i-tested-key-login' >/tmp/el-lock-3.log 2>&1; code=$?
[ "$code" = 4 ] && ok "a setting that would win over it is found (exit 4)" || no "an overriding setting went unnoticed (exit $code)"
expect "and the earlier lockdown file is put back as it was" 'grep -qx "PermitRootLogin no" /etc/ssh/sshd_config.d/00-echoledger.conf && [ ! -f /etc/ssh/sshd_config.d/00-echoledger.conf.prev ]'

echo
[ "$failures" -eq 0 ] && echo "ALL CONTAINER TESTS PASSED" || echo "$failures CONTAINER TEST(S) FAILED"
[ "$failures" -eq 0 ]
