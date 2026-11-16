#!/bin/sh
# The Mac half of a release, run in the release shell for each chip (desktop/RELEASE.md). The updater key never goes to
# GitHub: CI builds the self-signed .dmg and EchoLedger.app.tar.gz, and this signs that .app.tar.gz here.
#   1. The app in EchoLedger.app.tar.gz and the app in the .dmg are this version, signed with the hardened runtime, the
#      microphone and Apple events allowed, with the pinned self-signed certificate and a designated requirement that pins
#      it (check-mac-signature.sh --pinned), and they are the same build (one CDHash).
#   2. `tauri signer sign --app-version <version>` signs EchoLedger.app.tar.gz, binding the version into the signature as
#      `tauri build` does (requireSignedVersion), and writes EchoLedger.app.tar.gz.sig beside it.
#   3. It prints the `echoledger:publish-release` command for the server. Nothing is uploaded from here.
#
# Usage: sh scripts/release-mac.sh <folder with the .dmg and EchoLedger.app.tar.gz> <version, e.g. 1.8.1>
# The environment holds the updater key: TAURI_SIGNING_PRIVATE_KEY (the key file's content) or
# TAURI_SIGNING_PRIVATE_KEY_PATH, and TAURI_SIGNING_PRIVATE_KEY_PASSWORD (empty for our key).
set -eu

here=$(cd "$(dirname "$0")" && pwd)
folder=${1:?usage: release-mac.sh <folder with the .dmg and EchoLedger.app.tar.gz> <version>}
version=${2:?usage: release-mac.sh <folder with the .dmg and EchoLedger.app.tar.gz> <version>}

fail() {
  echo "release-mac: $*" >&2
  exit 1
}

[ -n "${TAURI_SIGNING_PRIVATE_KEY:-}${TAURI_SIGNING_PRIVATE_KEY_PATH:-}" ] ||
  fail "set TAURI_SIGNING_PRIVATE_KEY (the updater key file's content) or TAURI_SIGNING_PRIVATE_KEY_PATH first"
# Set, even empty (our key has no password): the signer would otherwise stop and ask for one.
[ "${TAURI_SIGNING_PRIVATE_KEY_PASSWORD+set}" = set ] || fail "set TAURI_SIGNING_PRIVATE_KEY_PASSWORD (empty for our key) first"
case "$version" in
  v*) fail "the version is the app's, without the v: ${version#v}" ;;
esac
folder=$(cd "$folder" && pwd) || fail "no folder $1"

one() {
  found=$(find "$folder" -maxdepth 2 -name "$1" -type f)
  [ -n "$found" ] || fail "no $1 in $folder"
  [ "$(printf '%s\n' "$found" | wc -l | tr -d ' ')" = 1 ] || fail "more than one $1 in $folder: one chip per folder"
  printf '%s' "$found"
}
dmg=$(one '*.dmg')
tarball=$(one 'EchoLedger.app.tar.gz')

work=$(mktemp -d)
mounted=
cleanup() {
  if [ -n "$mounted" ]; then hdiutil detach "$work/dmg" -quiet || true; fi
  rm -rf "$work"
}
trap cleanup EXIT

# The app as the updater will unpack it, and as a new install gets it from the .dmg.
mkdir "$work/package"
tar -xzf "$tarball" -C "$work/package"
packaged="$work/package/EchoLedger.app"
[ -d "$packaged" ] || fail "EchoLedger.app.tar.gz does not hold EchoLedger.app at its top"
mkdir "$work/dmg"
hdiutil attach "$dmg" -nobrowse -readonly -mountpoint "$work/dmg" -quiet
mounted=1
installed="$work/dmg/EchoLedger.app"
[ -d "$installed" ] || fail "$(basename "$dmg") does not hold EchoLedger.app"

for app in "$packaged" "$installed"; do
  found=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleShortVersionString' "$app/Contents/Info.plist")
  [ "$found" = "$version" ] || fail "$app is version $found, not $version"
  sh "$here/check-mac-signature.sh" "$app" --pinned
done

cdhash() {
  codesign -dvvv "$1" 2>&1 | sed -n 's/^CDHash=//p'
}
[ "$(cdhash "$packaged")" = "$(cdhash "$installed")" ] || fail "the .dmg and EchoLedger.app.tar.gz hold different builds"

executable=$(/usr/libexec/PlistBuddy -c 'Print :CFBundleExecutable' "$packaged/Contents/Info.plist")
case "$(lipo -archs "$packaged/Contents/MacOS/$executable")" in
  arm64) platform=macos-arm ;;
  x86_64) platform=macos-intel ;;
  *) fail "one chip per build: $(lipo -archs "$packaged/Contents/MacOS/$executable")" ;;
esac
hdiutil detach "$work/dmg" -quiet
mounted=

# The local Tauri CLI (desktop/node_modules), as `tauri build` would sign it.
rm -f "$tarball.sig"
(cd "$here/.." && npx --no-install tauri signer sign --app-version "$version" "$tarball")
[ -s "$tarball.sig" ] || fail "no signature was written for $tarball"

echo
echo "release-mac: $platform $version checked and signed. On the server:"
echo "  php artisan echoledger:publish-release $(basename "$dmg") --platform=$platform --app-version=$version --updater-file=EchoLedger.app.tar.gz --signature=EchoLedger.app.tar.gz.sig"
