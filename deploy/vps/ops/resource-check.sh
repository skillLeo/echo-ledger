#!/usr/bin/env bash
# Every 5 minutes (echoledger-resources.timer): CPU, memory and disk against their limits (80 % by default, ops.env),
# and whether the machine waits for a reboot after security updates. Each is told once when it starts and once when
# it clears (ops/alert.sh).
set -uo pipefail
here=$(dirname "$(readlink -f "$0" 2>/dev/null || echo "$0")")
# shellcheck source=alert.sh
. "$here/alert.sh"

CPU_PCT=${CPU_PCT:-80}
MEM_PCT=${MEM_PCT:-80}
DISK_PCT=${DISK_PCT:-80}
PROC=${PROC:-/proc}
DISK_PATHS=${DISK_PATHS:-/}

# level <key> <value> <limit> <message>: fires at the limit, and once firing clears only 5 points under it, so a value
# hovering at the limit does not alert and recover every 5 minutes.
level() {
  local key="$1" value="$2" limit="$3" message="$4" clear
  clear=$(( limit - 5 ))
  if [ "$value" -ge "$limit" ] || { [ "$(cat "$OPS_STATE/$key" 2>/dev/null)" = firing ] && [ "$value" -ge "$clear" ]; }; then
    alert "$key" firing "$message (limit ${limit}%)"
  else
    alert "$key" ok "$message (limit ${limit}%)"
  fi
}

# CPU: the busy share over the last 5 minutes from the load average, against the number of cores, so a short spike
# (a build, an update) does not alert but five busy minutes do.
cores=$(getconf _NPROCESSORS_ONLN 2>/dev/null || nproc 2>/dev/null || echo 1)
cores=${CORES:-$cores}
load5=$(awk '{print $2}' "$PROC/loadavg")
cpu=$(awk -v l="$load5" -v c="$cores" 'BEGIN { printf "%d", (l / c) * 100 }')
level cpu "$cpu" "$CPU_PCT" "CPU at ${cpu}% of ${cores} core(s) over 5 minutes"

# Memory: what the kernel says is still available (page cache counts as free), not "free".
mem=$(awk '/^MemTotal:/ {t=$2} /^MemAvailable:/ {a=$2} END { if (t > 0) printf "%d", (t - a) * 100 / t; else print 0 }' "$PROC/meminfo")
level memory "$mem" "$MEM_PCT" "memory at ${mem}% used"

# Disk: each watched file system.
for path in $DISK_PATHS; do
  used=$(df -P "$path" 2>/dev/null | awk 'NR == 2 { gsub("%", "", $5); print $5 }')
  [ -n "$used" ] || continue
  key="disk$(echo "$path" | tr '/' '_')"
  level "$key" "$used" "$DISK_PCT" "disk $path at ${used}%"
done

# A kernel or library update waits for a reboot (unattended-upgrades does not reboot on its own here).
if [ -f "${REBOOT_FLAG:-/var/run/reboot-required}" ]; then
  alert reboot firing "security updates wait for a reboot (restart outside busy hours: every live call reconnects)"
else
  alert reboot ok "no reboot pending"
fi
# The relay or nginx runs an old library after an update (harden.sh tells needrestart to leave them running).
if command -v needrestart >/dev/null 2>&1 || [ -n "${NEEDRESTART_OUTPUT:-}" ]; then
  waiting=$( { if [ -n "${NEEDRESTART_OUTPUT:-}" ]; then cat "$NEEDRESTART_OUTPUT"; else needrestart -b -r l 2>/dev/null; fi; } \
    | awk -F': ' '$1 == "NEEDRESTART-SVC" { print $2 }' | grep -E '^(assist-relay|nginx)\.service$' | tr '\n' ' ')
  if [ -n "$waiting" ]; then
    alert restart firing "updated libraries wait for a restart of ${waiting% } (restart outside busy hours: every live call reconnects)"
  else
    alert restart ok "no service waits for a restart"
  fi
fi
exit 0
