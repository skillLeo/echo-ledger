#!/bin/sh
# Checks the signature of a built EchoLedger.app, for "Desktop (macOS)" after the build and for the release shell before
# a release is published (desktop/RELEASE.md):
#   - it is valid on disk and satisfies its designated requirement (codesign --verify --deep --strict);
#   - it was signed with the hardened runtime (codesign -dv shows flags=...(runtime));
#   - its entitlements allow the microphone and Apple events, which the hardened runtime refuses otherwise
#     (com.apple.security.device.audio-input, and com.apple.security.automation.apple-events for "Read browser tab
#     addresses", which asks the browser through osascript).
# With --pinned it also checks the app is signed with the self-signed "EchoLedger Development Signing" certificate
# (docs/SIGNING.md: its SHA-256 fingerprint is below) and that its designated requirement pins exactly that certificate,
# which is what lets macOS keep the microphone, system audio and Screen Recording across updates:
#   identifier "com.echoledger.desktop" and certificate leaf = H"<the certificate's SHA-1>"
# (codesign says "root" instead of "leaf" when the certificate's subject has an organisation; with a chain of this one
# self-signed certificate both pin it).
#
# Usage: sh check-mac-signature.sh <EchoLedger.app> [--pinned]
set -eu

app=${1:?usage: check-mac-signature.sh <EchoLedger.app> [--pinned]}
mode=${2:-}
# EchoLedger Development Signing, SHA-256 fingerprint E4:E5:22:F7:…:39:39:56 (docs/SIGNING.md).
PINNED_SHA256=e4e522f7ca98ac2aca82590ccb7bdf84fe84b7301e6462dba8dae44643393956
IDENTIFIER=com.echoledger.desktop

fail() {
  echo "check-mac-signature: $*" >&2
  exit 1
}

[ -d "$app" ] || fail "$app is not an app"
codesign --verify --deep --strict --verbose=2 "$app" || fail "the signature of $app does not verify"

details=$(codesign -dv "$app" 2>&1) || fail "codesign cannot read the signature of $app"
echo "$details" | grep -Eq '^CodeDirectory .*flags=0x[0-9a-f]+\([^)]*runtime' ||
  fail "not signed with the hardened runtime: $(echo "$details" | grep '^CodeDirectory' || echo 'no code directory')"

entitlements=$(codesign -d --entitlements - --xml "$app" 2>/dev/null) || fail "codesign cannot read the entitlements of $app"
flat=$(printf '%s' "$entitlements" | tr -d ' \t\r\n')
for key in com.apple.security.device.audio-input com.apple.security.automation.apple-events; do
  case "$flat" in
    *"<key>$key</key><true/>"*) ;;
    *) fail "the signature's entitlements do not allow $key" ;;
  esac
done

if [ "$mode" = "--pinned" ]; then
  work=$(mktemp -d)
  trap 'rm -rf "$work"' EXIT
  codesign -d --extract-certificates="$work/cert" "$app" 2>/dev/null || true
  [ -s "$work/cert0" ] || fail "the signature of $app holds no certificate (an ad hoc build?)"
  sha256=$(shasum -a 256 "$work/cert0" | cut -d ' ' -f 1)
  [ "$sha256" = "$PINNED_SHA256" ] ||
    fail "signed with another certificate (SHA-256 $sha256), not EchoLedger Development Signing (docs/SIGNING.md)"
  sha1=$(shasum -a 1 "$work/cert0" | cut -d ' ' -f 1)
  requirement=$(codesign -d -r- "$app" 2>&1 | sed -n 's/^designated => //p')
  case "$requirement" in
    "identifier \"$IDENTIFIER\" and certificate leaf = H\"$sha1\"" | "identifier \"$IDENTIFIER\" and certificate root = H\"$sha1\"") ;;
    *) fail "the designated requirement does not pin the certificate: ${requirement:-none}" ;;
  esac
  echo "check-mac-signature: $app: hardened runtime, microphone and Apple events allowed, signed with EchoLedger Development Signing; requirement: $requirement"
else
  echo "check-mac-signature: $app: hardened runtime, microphone and Apple events allowed"
fi
