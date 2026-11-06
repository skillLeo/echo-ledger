#!/bin/sh
# Smoke test on real phones in Firebase Test Lab (plan Phase 1B B5). The free plan allows 5 runs on physical devices a
# day, so test a few models a day.
#
#   sh tools/testlab.sh list                                # the phones Test Lab offers now, with their Android versions
#   sh tools/testlab.sh run <apk> <model>:<api> [more ...]  # a Robo test of the APK on those phones
#
# Needs gcloud signed in (gcloud auth login) and a project (gcloud config set project <id>), made once in the Firebase
# console. Robo taps through the app by itself, so it finds crashes and ANRs and shows how the screens fit. It cannot
# place a call. The results are in the Firebase console; gcloud prints their link.
set -eu

command -v gcloud >/dev/null || { echo "gcloud is not installed: brew install --cask google-cloud-sdk"; exit 1; }

case "${1:-}" in
  list)
    gcloud firebase test android models list --filter="form=PHYSICAL" \
      --format="table(id, brand, name, supportedVersionIds.list())"
    ;;
  run)
    apk="${2:?the path of an APK}"
    shift 2
    [ "$#" -gt 0 ] || { echo "Name at least one phone, as <model>:<api>, from: sh tools/testlab.sh list"; exit 1; }
    devices=""
    for phone in "$@"; do
      devices="$devices --device model=${phone%%:*},version=${phone##*:},locale=en,orientation=portrait"
    done
    # shellcheck disable=SC2086
    gcloud firebase test android run --type robo --app "$apk" --timeout 300s $devices
    ;;
  *)
    sed -n '2,10p' "$0"
    ;;
esac
