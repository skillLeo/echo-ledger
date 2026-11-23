#!/usr/bin/env bash
# Whether both of the server's PHPs have every extension EchoLedger needs and can encrypt a recording (W-INC-1124: a
# hosting change took sodium out of PHP 8.4, and uploads failed until someone looked). Run it on the server, in the app
# folder, after each deploy and after any change to the hosting's PHP. Read-only: it changes nothing.
#
#   bash deploy/check-php.sh --php /opt/alt/php84/usr/bin/php --url https://<console host> [--php <another CLI PHP>]
#
#   --php   a CLI PHP to check (repeat it for each one: the one cron runs `schedule:run` with, and the one you run
#           artisan with, when they differ); default: `php` on the PATH
#   --url   the console's address: the web PHP is asked through it, at /health/php (ok or not, nothing more; the
#           admin dashboard's banner names what is missing)
#   --app   the app folder (the one with artisan); default: the current folder
#
# PASS / FAIL per PHP; exit 1 when anything FAILs, so a deploy stops here.
set -u
phps=() url="" app="."
while [ $# -gt 0 ]; do
  case "$1" in
    --php) phps+=("$2"); shift 2 ;;
    --url) url="${2%/}"; shift 2 ;;
    --app) app="$2"; shift 2 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done
[ -n "$url" ] || { echo "Say where the console answers: --url https://<console host>" >&2; exit 2; }
[ -f "$app/artisan" ] || { echo "No artisan in $app: run it in the app folder, or pass --app <folder>" >&2; exit 2; }
[ ${#phps[@]} -gt 0 ] || phps=(php)
fails=0

for php in "${phps[@]}"; do
  out=$(cd "$app" && "$php" artisan echoledger:health 2>&1)
  if [ $? -eq 0 ]; then
    printf 'PASS  CLI PHP %s\n' "$php"
  else
    printf 'FAIL  CLI PHP %s\n' "$php"
    fails=$((fails + 1))
  fi
  printf '%s\n' "$out" | sed 's/^/      /'
done

code=$(curl -s -o /dev/null -w '%{http_code}' -m 15 -H 'Accept: application/json' "$url/health/php")
case "$code" in
  200) printf 'PASS  web PHP (%s/health/php)\n' "$url" ;;
  503) printf 'FAIL  web PHP: it lacks an extension or cannot encrypt. The admin dashboard'"'"'s banner says which.\n'
       fails=$((fails + 1)) ;;
  *)   printf 'FAIL  web PHP: %s/health/php answered %s, not 200 or 503, so the web PHP was not checked\n' "$url" "${code:-nothing}"
       fails=$((fails + 1)) ;;
esac

[ "$fails" -eq 0 ] || exit 1
