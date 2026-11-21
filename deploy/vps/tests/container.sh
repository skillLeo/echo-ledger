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

echo "== the firewall as services/assist-relay/DEPLOY.md leaves it, plus a hand-made 8787 rule, all while ufw is off"
inside 'ufw allow OpenSSH >/dev/null; ufw allow "Nginx Full" >/dev/null; ufw allow 8787/tcp >/dev/null; ufw allow 18787/tcp >/dev/null'

echo "== harden.sh"
inside 'SSH_CONNECTION="203.0.113.5 50000 172.17.0.2 22" bash /root/vps/harden.sh' >/tmp/el-harden-1.log 2>&1; code=$?
[ "$code" = 0 ] && ok "harden.sh runs (exit 0)" || { no "harden.sh exit $code"; tail -30 /tmp/el-harden-1.log; }
expect "firewall on, incoming denied, SSH limited, 80/443 open" 'ufw status verbose | grep -q "Status: active" && ufw status | grep -Eq "^22/tcp +LIMIT" && ufw status | grep -q "Nginx Full"'
expect "no ALLOW for SSH left to skip the limit (v4 and v6)" '! ufw status | grep -Eq "^(22(/tcp)?|OpenSSH)( \(v6\))? +ALLOW"'
expect "the hand-made 8787 rule is gone, 18787 stays" '! ufw show added | grep -Eq "(^| )8787(/tcp)?( |$)" && ufw show added | grep -q 18787'
expect "security updates on, no automatic reboot, Ubuntu's origins cleared" 'grep -q "Automatic-Reboot \"false\"" /etc/apt/apt.conf.d/52echoledger-unattended && grep -q "^#clear Unattended-Upgrade::Allowed-Origins;" /etc/apt/apt.conf.d/52echoledger-unattended'
expect "needrestart leaves the relay and nginx running" 'grep -q "assist-relay" /etc/needrestart/conf.d/echoledger.conf && grep -q "nginx" /etc/needrestart/conf.d/echoledger.conf'
expect "upgrades move to 22:30 UTC" 'systemctl cat apt-daily-upgrade.timer | grep -q "OnCalendar=\*-\*-\* 22:30:00 UTC"'
expect "no copy of a replaced file is left in apt.conf.d" '! ls /etc/apt/apt.conf.d | grep -q before-echoledger'
expect "fail2ban watches the SSH port" 'grep -q "^port = 22" /etc/fail2ban/jail.d/echoledger-sshd.conf'
expect "and lets no outside address past it for good" 'grep -qx "ignoreip = 127.0.0.1/8 ::1" /etc/fail2ban/jail.d/echoledger-sshd.conf'
expect "the watchdog timer fires on the minute" 'grep -q "AccuracySec=1s" /etc/systemd/system/echoledger-relay-watchdog.timer'
expect "fail2ban guards SSH" 'fail2ban-client status sshd'
expect "journal capped" 'grep -q SystemMaxUse=1G /etc/systemd/journald.conf.d/relay.conf'
expect "three ops timers on" '[ "$(systemctl is-active echoledger-relay-watchdog.timer echoledger-resources.timer echoledger-backup.timer | grep -c ^active)" = 3 ]'
expect "alert.env is root's only" '[ "$(stat -c %a /etc/echoledger-ops/alert.env)" = 600 ]'
expect "a first backup was made, with no secret" 'f=$(ls /var/backups/echoledger-relay/*.tar.gz | head -1) && tar -tzf "$f" | grep -q assist-relay.service && ! tar -tzf "$f" | grep -Eq "alert.env|credstore|\.key$"'

echo "== harden.sh again"
inside 'SSH_CONNECTION="203.0.113.5 50000 172.17.0.2 22" bash /root/vps/harden.sh' >/tmp/el-harden-2.log 2>&1; code=$?
[ "$code" = 0 ] && ok "second run exit 0" || no "second run exit $code"
grep -q "  wrote " /tmp/el-harden-2.log && { no "second run rewrote files:"; grep "  wrote " /tmp/el-harden-2.log; } || ok "second run changes no file"

echo "== a public listener stops it"
inside 'nohup python3 -m http.server 8080 --bind 0.0.0.0 >/dev/null 2>&1 &' ; sleep 1
inside 'SSH_CONNECTION="203.0.113.5 50000 172.17.0.2 22" bash /root/vps/harden.sh' >/tmp/el-harden-3.log 2>&1; code=$?
[ "$code" = 3 ] && grep -q ":8080" /tmp/el-harden-3.log && ok "stops (exit 3) and names :8080" || no "did not stop for :8080 (exit $code)"
inside 'pkill -f "http.server 8080"'

echo "== check.sh"
inside 'bash /root/vps/check.sh' >/tmp/el-check.log 2>&1; code=$?
grep -E "^(FAIL|WARN)" /tmp/el-check.log | sed 's/^/      /'
# In a container: no NTP sync and no unattended-upgrades run yet; those may warn or fail here and nowhere else.
real=$(grep "^FAIL" /tmp/el-check.log | grep -v -e "clock in sync" -e "updates ran" | wc -l | tr -d ' ')
[ "$real" = 0 ] && ok "check.sh passes (bar the clock, which a container cannot sync)" || no "check.sh: $real failure(s)"
expect "check.sh warns while root can still sign in" 'bash /root/vps/check.sh | grep -q "WARN  root can sign in"'
inside 'chmod 644 /etc/echoledger-ops/alert.env'
expect "check.sh fails an alert.env others can read" 'bash /root/vps/check.sh | grep -q "FAIL  alert.env"'
inside 'SSH_CONNECTION="203.0.113.5 50000 172.17.0.2 22" bash /root/vps/harden.sh' >/tmp/el-harden-4.log 2>&1 || tail -5 /tmp/el-harden-4.log
expect "and harden.sh puts it back to root:root 0600" '[ "$(stat -c "%U:%G %a" /etc/echoledger-ops/alert.env)" = "root:root 600" ]'
inside 'ufw allow OpenSSH >/dev/null'
expect "check.sh fails an ALLOW that skips the SSH limit" 'bash /root/vps/check.sh | grep -q "FAIL  an ALLOW rule for SSH"'
inside 'SSH_CONNECTION="203.0.113.5 50000 172.17.0.2 22" bash /root/vps/harden.sh' >/tmp/el-harden-4.log 2>&1 || tail -5 /tmp/el-harden-4.log
expect "and the next harden.sh run takes that ALLOW away again" '! ufw show added | grep -Eq "^ufw allow (OpenSSH|22|22/tcp)( |$)"'
echo "== run with sudo after the lockdown (no SSH_CONNECTION)"
inside 'env -u SSH_CONNECTION bash /root/vps/harden.sh' >/tmp/el-harden-5.log 2>&1; code=$?
[ "$code" = 0 ] && ok "harden.sh runs without SSH_CONNECTION (exit 0)" || { no "harden.sh without SSH_CONNECTION exit $code"; tail -5 /tmp/el-harden-5.log; }
expect "SSH still rate limited, and the jail unchanged" 'ufw status | grep -Eq "^22/tcp +LIMIT" && grep -qx "ignoreip = 127.0.0.1/8 ::1" /etc/fail2ban/jail.d/echoledger-sshd.conf'
expect "needrestart reads our settings without an error" 'needrestart -b -r l >/tmp/nr.txt 2>&1 && ! grep -qi error /tmp/nr.txt'

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

echo "== check.sh finds what leaks"
# The token first, then more than 64 KiB: a check that stops reading at its first match must still say FAIL.
inside 'mkdir -p /var/log/nginx; echo "1.2.3.4 - - [x] \"GET /v1/assist?t=eyJhbGciOiJIUzI1NiJ9.eyJzaWQiOiJ4In0.sig HTTP/1.1\" 101" >> /var/log/nginx/access.log; for i in $(seq 1 1500); do echo "1.2.3.4 - - [x] \"GET /healthz HTTP/1.1\" 200 filler-filler-filler-filler"; done >> /var/log/nginx/access.log'
expect "a token in nginx's access log fails" 'bash /root/vps/check.sh | grep -q "FAIL  a token appears in nginx"'
inside ': > /var/log/nginx/access.log'
inside 'cd /tmp && mkdir -p leak/etc/credstore.encrypted && echo s > leak/etc/credstore.encrypted/relay_secret && tar -C leak -czf /var/backups/echoledger-relay/relay-config-29990101T000000Z.tar.gz etc && touch /var/backups/echoledger-relay/relay-config-29990101T000000Z.tar.gz'
expect "an archive holding the encrypted relay secret fails" 'bash /root/vps/check.sh | grep -q "FAIL  the backup holds a secret"'
inside 'rm -f /var/backups/echoledger-relay/relay-config-29990101T000000Z.tar.gz'

echo "== ssh-lockdown.sh"
inside 'SSH_CONNECTION="203.0.113.5 50000 172.17.0.2 22" bash /root/vps/ssh-lockdown.sh --admin elops' >/tmp/el-lock-1.log 2>&1
expect "step 1 lets the tester's address through fail2ban while testing, in its running state only" \
  'fail2ban-client get sshd ignoreip | grep -q 203.0.113.5 && ! grep -q 203.0.113.5 /etc/fail2ban/jail.d/echoledger-sshd.conf'
expect "for two hours at most, and remembers which address" 'systemctl list-timers --all | grep -q echoledger-f2b-unexempt && grep -qx 203.0.113.5 /run/echoledger-f2b-tester'
expect "step 1 makes elops with root's key and sudo, and changes no SSH setting" \
  'id elops && grep -q TestKeyOnly /home/elops/.ssh/authorized_keys && sudo -u elops sudo -n true && [ ! -f /etc/ssh/sshd_config.d/00-echoledger.conf ]'
expect "the admin account has no password" '[ "$(passwd -S elops | cut -d" " -f2)" = L ] || [ "$(passwd -S elops | cut -d" " -f2)" = NP ]'
inside 'bash /root/vps/ssh-lockdown.sh --admin root' >/dev/null 2>&1 && no "refuses root as the admin" || ok "refuses root as the admin"
inside 'echo "elops ALL=(ALL) !ALL" > /etc/sudoers.d/99-test-deny; chmod 440 /etc/sudoers.d/99-test-deny'
inside 'bash /root/vps/ssh-lockdown.sh --admin elops --i-tested-key-login' >/tmp/el-lock-0.log 2>&1; code=$?
inside 'rm -f /etc/sudoers.d/99-test-deny'
[ "$code" = 3 ] && grep -q "cannot use sudo" /tmp/el-lock-0.log && ok "step 2 stops before any SSH change when the admin cannot sudo" || no "step 2 went on without the admin's sudo (exit $code)"
expect "and wrote nothing" '[ ! -f /etc/ssh/sshd_config.d/00-echoledger.conf ]'
expect "a step 2 that stops early still takes the tester's exemption back (from the file, not the session)" '! fail2ban-client get sshd ignoreip | grep -q 203.0.113.5 && [ ! -f /run/echoledger-f2b-tester ] && ! systemctl list-timers --all | grep -q echoledger-f2b-unexempt'
inside 'SSH_CONNECTION="203.0.113.5 50000 172.17.0.2 22" bash /root/vps/ssh-lockdown.sh --admin elops' >/dev/null 2>&1
inside 'touch /etc/ssh/sshd_config.d/00-echoledger.conf.prev'
inside 'bash /root/vps/ssh-lockdown.sh --admin elops --i-tested-key-login' >/dev/null 2>&1; code=$?
[ "$code" = 3 ] && ok "a stale .prev from a run stopped half way stops step 2" || no "a stale .prev went unnoticed (exit $code)"
inside 'rm -f /etc/ssh/sshd_config.d/00-echoledger.conf.prev'
inside 'SSH_CONNECTION="203.0.113.5 50000 172.17.0.2 22" bash /root/vps/ssh-lockdown.sh --admin elops --i-tested-key-login' >/tmp/el-lock-2.log 2>&1; code=$?
[ "$code" = 0 ] && ok "step 2 runs" || { no "step 2 exit $code"; cat /tmp/el-lock-2.log; }
expect "and takes the tester's address back" '! fail2ban-client get sshd ignoreip | grep -q 203.0.113.5'
expect "root sign-in and passwords off, as sshd itself reads it" 'sshd -T | grep -qx "permitrootlogin no" && sshd -T | grep -qx "passwordauthentication no" && sshd -T | grep -qx "kbdinteractiveauthentication no"'
expect "check.sh --expect-lockdown passes the SSH lines" '! bash /root/vps/check.sh --expect-lockdown | grep -Eq "FAIL  (root|passwords)"'
inside 'printf "PasswordAuthentication yes\n" > /etc/ssh/sshd_config.d/00-aaa.conf; bash /root/vps/ssh-lockdown.sh --admin elops --i-tested-key-login' >/tmp/el-lock-3.log 2>&1; code=$?
[ "$code" = 4 ] && ok "a setting that would win over it is found (exit 4)" || no "an overriding setting went unnoticed (exit $code)"
expect "and the earlier lockdown file is put back as it was" 'grep -qx "PermitRootLogin no" /etc/ssh/sshd_config.d/00-echoledger.conf && [ ! -f /etc/ssh/sshd_config.d/00-echoledger.conf.prev ]'
inside 'rm -f /etc/ssh/sshd_config.d/00-aaa.conf; cp /etc/ssh/sshd_config /tmp/sshd_config.orig; printf "\nMatch User root\n    PermitRootLogin yes\n" >> /etc/ssh/sshd_config'
inside 'bash /root/vps/ssh-lockdown.sh --admin elops --i-tested-key-login' >/tmp/el-lock-4.log 2>&1; code=$?
[ "$code" = 4 ] && grep -q "Match block" /tmp/el-lock-4.log && ok "a Match block that lets root in is found (exit 4)" || no "a Match block for root went unnoticed (exit $code)"
inside 'cp /tmp/sshd_config.orig /etc/ssh/sshd_config'
inside 'rm -f /etc/ssh/sshd_config.d/00-echoledger.conf; printf "#!/bin/sh\n[ \"\$1\" = reload ] && exit 1\nexec /usr/bin/systemctl \"\$@\"\n" > /usr/local/sbin/systemctl; chmod +x /usr/local/sbin/systemctl'
inside 'bash /root/vps/ssh-lockdown.sh --admin elops --i-tested-key-login' >/tmp/el-lock-5.log 2>&1; code=$?
[ "$code" = 4 ] && grep -q "did not reload" /tmp/el-lock-5.log && ok "a failed reload is reported (exit 4)" || no "a failed reload went unreported (exit $code)"
expect "and its file is taken back" '[ ! -f /etc/ssh/sshd_config.d/00-echoledger.conf ]'
inside 'rm -f /usr/local/sbin/systemctl'

echo "== a token followed by more than 64 KiB of the relay's journal"
inside 'touch /etc/fake-relay-leak; systemctl restart assist-relay; sleep 3'
expect "the journal holds the token and over 64 KiB after it" '[ "$(journalctl -u assist-relay -o cat | wc -c)" -gt 140000 ] && journalctl -u assist-relay -o cat | head -c 1000 | grep -q eyJ'
# The line check.sh had before the review: under pipefail, grep -q quits at the token, journalctl gets SIGPIPE, and the
# pipe fails, so the leak read as "none found". It must still miss it here, or this test proves nothing.
inside 'set -o pipefail; journalctl -u assist-relay --since "-24h" -o cat 2>/dev/null | grep -Eq "Bearer |sk-[A-Za-z0-9]{10}|\"text\":|eyJ[A-Za-z0-9_-]{10,}\."' && no "the old check line saw the leak (the test would not catch the regression)" || ok "the old check line misses it (so this test catches the regression)"
expect "check.sh fails it (the leak checks count, they never stop at the first match)" 'bash /root/vps/check.sh | grep -q "FAIL  the relay.s journal holds a token"'

echo
[ "$failures" -eq 0 ] && echo "ALL CONTAINER TESTS PASSED" || echo "$failures CONTAINER TEST(S) FAILED"
[ "$failures" -eq 0 ]
