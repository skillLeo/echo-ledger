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

# CPU: the busy share over the last 5 minutes from the load average, against the number of cores, so a short spike
# (a build, an update) does not alert but five busy minutes do.
cores=$(getconf _NPROCESSORS_ONLN 2>/dev/null || nproc 2>/dev/null || echo 1)
cores=${CORES:-$cores}
load5=$(awk '{print $2}' "$PROC/loadavg")
cpu=$(awk -v l="$load5" -v c="$cores" 'BEGIN { printf "%d", (l / c) * 100 }')
if [ "$cpu" -ge "$CPU_PCT" ]; then
  alert cpu firing "CPU at ${cpu}% of ${cores} core(s) over 5 minutes (limit ${CPU_PCT}%)"
else
  alert cpu ok "CPU at ${cpu}% (limit ${CPU_PCT}%)"
fi

# Memory: what the kernel says is still available (page cache counts as free), not "free".
mem=$(awk '/^MemTotal:/ {t=$2} /^MemAvailable:/ {a=$2} END { if (t > 0) printf "%d", (t - a) * 100 / t; else print 0 }' "$PROC/meminfo")
if [ "$mem" -ge "$MEM_PCT" ]; then
  alert memory firing "memory at ${mem}% used (limit ${MEM_PCT}%)"
else
  alert memory ok "memory at ${mem}% used (limit ${MEM_PCT}%)"
fi

# Disk: each watched file system.
for path in $DISK_PATHS; do
  used=$(df -P "$path" 2>/dev/null | awk 'NR == 2 { gsub("%", "", $5); print $5 }')
  [ -n "$used" ] || continue
  key="disk$(echo "$path" | tr '/' '_')"
  if [ "$used" -ge "$DISK_PCT" ]; then
    alert "$key" firing "disk $path at ${used}% (limit ${DISK_PCT}%)"
  else
    alert "$key" ok "disk $path at ${used}% (limit ${DISK_PCT}%)"
  fi
done

# A kernel or library update waits for a reboot (unattended-upgrades does not reboot on its own here).
if [ -f "${REBOOT_FLAG:-/var/run/reboot-required}" ]; then
  alert reboot firing "security updates wait for a reboot (restart outside busy hours: every live call reconnects)"
else
  alert reboot ok "no reboot pending"
fi
exit 0
