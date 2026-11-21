#!/usr/bin/env bash
# Every minute (echoledger-relay-watchdog.timer). systemd already restarts the relay when it exits (Restart=on-failure).
# This catches the other case: a relay that is still running but no longer answers. After FAILS_BEFORE_RESTART misses in
# a row (3 by default: three minutes) it restarts the service once, alerts, and waits for health to come back before it
# tries again. A relay stopped on purpose (systemctl stop) is left alone.
set -uo pipefail
here=$(dirname "$(readlink -f "$0" 2>/dev/null || echo "$0")")
# shellcheck source=alert.sh
. "$here/alert.sh"

UNIT=${RELAY_UNIT:-assist-relay}
HEALTH_URL=${RELAY_HEALTH_URL:-http://127.0.0.1:8787/healthz}
FAILS_BEFORE_RESTART=${FAILS_BEFORE_RESTART:-3}
SYSTEMCTL=${SYSTEMCTL:-systemctl}
counter="$OPS_STATE/relay-misses"
mkdir -p "$OPS_STATE"

if ! $SYSTEMCTL is-enabled --quiet "$UNIT" 2>/dev/null; then
  exit 0   # not installed on this machine, or disabled on purpose
fi
if [ "$($SYSTEMCTL is-active "$UNIT" 2>/dev/null)" = inactive ] && [ "$($SYSTEMCTL show -p Result --value "$UNIT" 2>/dev/null)" = success ]; then
  echo 0 >"$counter"   # stopped on purpose: not an outage, and a start later begins counting afresh
  exit 0
fi

if curl -fsS -m 5 "$HEALTH_URL" 2>/dev/null | grep -q '"status":"ok"'; then
  echo 0 >"$counter"
  alert relay ok "relay healthy"
  exit 0
fi

misses=$(( $(cat "$counter" 2>/dev/null || echo 0) + 1 ))
echo "$misses" >"$counter"
ops_log "relay health check missed ($misses in a row)"
if [ "$misses" -eq "$FAILS_BEFORE_RESTART" ]; then
  alert relay firing "relay did not answer its health check $misses times in a row: restarting it"
  $SYSTEMCTL restart "$UNIT" || ops_log "relay restart failed"
elif [ "$misses" -gt "$FAILS_BEFORE_RESTART" ] && [ $(( misses % 30 )) -eq 0 ]; then
  # Still down half an hour later (a restart did not help): one more restart and a log line, no new alert.
  ops_log "relay still not healthy after $misses minutes: restarting again"
  $SYSTEMCTL restart "$UNIT" || ops_log "relay restart failed"
fi
exit 0
