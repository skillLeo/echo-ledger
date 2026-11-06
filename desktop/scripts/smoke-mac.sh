#!/bin/sh
# Runs the built Mac app's smoke test (src-tauri/src/smoke.rs): the app starts against a temporary folder with no
# account, opens its windows, waits until they are ready, quits and exits 0. The test fails on a non-zero exit, on a
# hang past the limit, and on a new EchoLedger crash report in ~/Library/Logs/DiagnosticReports.
#   sh desktop/scripts/smoke-mac.sh path/to/EchoLedger.app [seconds]
set -u
APP=${1:?usage: smoke-mac.sh path/to/EchoLedger.app [seconds]}
LIMIT=${2:-180}
REPORTS="$HOME/Library/Logs/DiagnosticReports"
BINARY="$APP/Contents/MacOS/$(/usr/libexec/PlistBuddy -c 'Print :CFBundleExecutable' "$APP/Contents/Info.plist")"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$REPORTS"
ls -1 "$REPORTS" | sort > "$WORK/before"

"$BINARY" --smoke-test > "$WORK/log" 2>&1 &
PID=$!
WAITED=0
while kill -0 "$PID" 2>/dev/null; do
  if [ "$WAITED" -ge "$LIMIT" ]; then
    kill -9 "$PID" 2>/dev/null
    wait "$PID" 2>/dev/null
    cat "$WORK/log"
    echo "Smoke test failed: the app was still running after $LIMIT s."
    exit 1
  fi
  sleep 1
  WAITED=$((WAITED + 1))
done
wait "$PID"
CODE=$?
cat "$WORK/log"

# The crash reporter writes a moment after the process died.
sleep 5
ls -1 "$REPORTS" | sort > "$WORK/after"
CRASHES=$(comm -13 "$WORK/before" "$WORK/after" | grep -iE 'echoledger' || true)
if [ -n "$CRASHES" ]; then
  echo "Smoke test failed: new crash reports:"
  echo "$CRASHES"
  echo "$CRASHES" | while read -r report; do sed -n '1,60p' "$REPORTS/$report"; done
  exit 1
fi
if [ "$CODE" -ne 0 ]; then
  echo "Smoke test failed: the app exited with $CODE."
  exit 1
fi
# Exit 0 alone could be something else ending the process early: the test says it finished.
if ! grep -q 'smoke test: ok' "$WORK/log"; then
  echo "Smoke test failed: the app ended without saying its windows were ready."
  exit 1
fi
echo "Smoke test passed in about $WAITED s."
