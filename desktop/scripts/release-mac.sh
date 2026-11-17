#!/bin/sh
# The Mac half of a release, run in the release shell for each chip (desktop/RELEASE.md). The updater key never goes to
# GitHub: CI builds the self-signed .dmg and EchoLedger.app.tar.gz, and this signs that .app.tar.gz here.
#   0. The release gate (src/lib/releaseGate.test.ts with ECHOLEDGER_RELEASE=1): nothing the release can open still
#      waits for a core command (src/lib/awaitingCore.ts). It stops here when the gate fails, before anything is signed.
#   1. The downloaded EchoLedger.app.tar.gz holds EchoLedger.app and nothing else (check-mac-archive.sh: no other entry,
#      no .. or absolute path, no path twice, no hard link, no symbolic link out of the app) and is unpacked.
#   2. The unpacked app and the app in the .dmg are this version, signed with the hardened runtime, the microphone and
#      Apple events allowed, with the pinned self-signed certificate and a designated requirement that pins it
#      (check-mac-signature.sh --pinned), and they are the same build (one CDHash).
#   3. A fresh EchoLedger.app.tar.gz is packed from that checked app (sorted names, owner root, no extended attributes),
#      in <folder>/release/; it is checked like the download and unpacks to the same CDHash. The downloaded archive is
#      never signed.
#   4. `tauri signer sign --app-version <version>` signs the fresh archive, binding the version into the signature as
#      `tauri build` does (requireSignedVersion), and writes EchoLedger.app.tar.gz.sig beside it.
#   5. It prints the `echoledger:publish-release` command for the server. Nothing is uploaded from here.
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
  # Not what an earlier run wrote in release/.
  found=$(find "$folder" -maxdepth 2 -path "$folder/release" -prune -o -name "$1" -type f -print)
  [ -n "$found" ] || fail "no $1 in $folder"
  [ "$(printf '%s\n' "$found" | wc -l | tr -d ' ')" = 1 ] || fail "more than one $1 in $folder: one chip per folder"
  printf '%s' "$found"
}
dmg=$(one '*.dmg')
tarball=$(one 'EchoLedger.app.tar.gz')

# The release gate, with this checkout's tests (desktop/node_modules): a screen the release can reach must not wait for
# a command the core lacks.
(cd "$here/.." && ECHOLEDGER_RELEASE=1 npx --no-install vitest run src/lib/releaseGate.test.ts) ||
  fail "the release gate failed (src/lib/releaseGate.test.ts): nothing was signed"

work=$(mktemp -d)
mounted=
cleanup() {
  if [ -n "$mounted" ]; then hdiutil detach "$work/dmg" -quiet || true; fi
  rm -rf "$work"
}
trap cleanup EXIT

# The app as the updater will unpack it (only after the archive is shown to hold it and nothing else), and as a new
# install gets it from the .dmg.
mkdir "$work/package"
sh "$here/check-mac-archive.sh" "$tarball" "$work/package"
packaged="$work/package/EchoLedger.app"
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

# What is signed is packed here from the app that was checked, never the download: sorted names, owner root:wheel, no
# extended attributes or AppleDouble files, no timestamp in the gzip header.
out="$folder/release"
rm -rf "$out"
mkdir "$out"
fresh="$out/EchoLedger.app.tar.gz"
(cd "$work/package" && find EchoLedger.app -print | LC_ALL=C sort) > "$work/names"
(cd "$work/package" && COPYFILE_DISABLE=1 tar --no-mac-metadata --uid 0 --gid 0 --uname root --gname wheel \
  --options gzip:!timestamp -czf "$fresh" -n -T "$work/names")
mkdir "$work/fresh"
sh "$here/check-mac-archive.sh" "$fresh" "$work/fresh"
codesign --verify --deep --strict "$work/fresh/EchoLedger.app" || fail "the fresh archive's app does not verify"
[ "$(cdhash "$work/fresh/EchoLedger.app")" = "$(cdhash "$packaged")" ] || fail "the fresh archive holds another build than the checked one"

# The local Tauri CLI (desktop/node_modules), as `tauri build` would sign it.
(cd "$here/.." && npx --no-install tauri signer sign --app-version "$version" "$fresh")
[ -s "$fresh.sig" ] || fail "no signature was written for $fresh"

echo
echo "release-mac: $platform $version checked and signed. Publish $(basename "$dmg"), release/EchoLedger.app.tar.gz and release/EchoLedger.app.tar.gz.sig from $folder; on the server:"
echo "  php artisan echoledger:publish-release $(basename "$dmg") --platform=$platform --app-version=$version --updater-file=EchoLedger.app.tar.gz --signature=EchoLedger.app.tar.gz.sig"
