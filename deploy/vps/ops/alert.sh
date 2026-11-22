#!/usr/bin/env bash
# Shared by the ops checks on the relay VPS (deploy/vps/README.md). Sourced, not run.
#
# alert <key> <firing|ok> <message>
#   Writes the message to the journal (tag echoledger-ops) and, when /etc/echoledger-ops/alert.env sets ALERT_URL, posts it
#   there. A condition is told once when it starts and once when it clears, never every few minutes in between: its state
#   lives in $OPS_STATE/<key>. Messages hold the host, the check and numbers only: never anything a person said or typed,
#   no tokens, no keys.
#
# alert.env (root only, 0600; never in the repo):
#   ALERT_URL=https://ntfy.sh/<a long random topic>      or a Slack / Discord incoming webhook
#   ALERT_KIND=ntfy | slack | discord                    (ntfy by default: the message as the body)

OPS_ETC=${OPS_ETC:-/etc/echoledger-ops}
OPS_STATE=${OPS_STATE:-/var/lib/echoledger-ops}
ALERT_URL=""
ALERT_KIND=ntfy
# shellcheck disable=SC1091
[ -r "$OPS_ETC/alert.env" ] && . "$OPS_ETC/alert.env"
# shellcheck disable=SC1091
[ -r "$OPS_ETC/ops.env" ] && . "$OPS_ETC/ops.env"

ops_log() {
  if command -v logger >/dev/null 2>&1 && [ -z "${OPS_NO_JOURNAL:-}" ]; then
    logger -t echoledger-ops -- "$*"
  else
    echo "echoledger-ops: $*" >&2
  fi
}

ops_send() {
  local text="$1" body type
  [ -n "${ALERT_URL:-}" ] || return 0
  case "${ALERT_KIND:-ntfy}" in
    slack) type=application/json; body=$(jq -cn --arg t "$text" '{text: $t}') ;;
    discord) type=application/json; body=$(jq -cn --arg t "$text" '{content: $t}') ;;
    *) type=text/plain; body="$text" ;;
  esac
  if [ -n "${ALERT_CMD:-}" ]; then
    # Tests: hand the message to a command instead of the network.
    printf '%s' "$body" | $ALERT_CMD >/dev/null
    return 0
  fi
  curl -fsS -m 10 --retry 2 -H "Content-Type: $type" --data-binary "$body" "$ALERT_URL" >/dev/null 2>&1 \
    || ops_log "alert not delivered (the alert URL did not answer)"
}

alert() {
  local key="$1" state="$2" message="$3" file before host
  file="$OPS_STATE/$key"
  before=$( [ -f "$file" ] && cat "$file" || echo ok )
  host=$(hostname -s 2>/dev/null || hostname)
  mkdir -p "$OPS_STATE"
  if [ "$state" = firing ]; then
    if [ "$before" != firing ]; then
      echo firing >"$file"
      ops_log "ALERT $key: $message"
      ops_send "[$host] ALERT: $message"
    fi
  elif [ "$before" = firing ]; then
    echo ok >"$file"
    ops_log "RECOVERED $key: $message"
    ops_send "[$host] OK again: $message"
  fi
}
