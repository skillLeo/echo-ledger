# Releasing EchoLedger Desktop

## Build
```bash
npm ci
npm run tauri build            # Windows: NSIS installer (tauri.windows.conf.json); macOS: .app + .dmg
```
Prerequisites: Rust stable (MSVC on Windows) + Visual Studio Build Tools "Desktop development with C++";
WebView2 (preinstalled on Windows 10 1803+). macOS: Xcode Command Line Tools; build on macOS 14.4+ hardware.

## Smoke test
`<the app> --smoke-test` (or `ECHOLEDGER_SMOKE_TEST=1`) starts the built app against a temporary folder with no account,
opens its windows, waits until they are ready, quits and exits 0 (`src-tauri/src/smoke.rs`; no network, capture, keychain
or permission prompt, and a running EchoLedger is left alone). Both workflows run it on the built app with
`scripts/smoke-mac.sh` and `scripts/smoke-windows.ps1`, on every runner GitHub offers: a crash, a hang or a new crash report
fails the job. On a `v*` tag "Desktop (macOS)" attaches both DMGs to the tag's GitHub Release.

## Code signing and notarization
See `docs/SIGNING.md`: the certificate options and costs, the GitHub secrets the "Desktop (Windows)" and
"Desktop (macOS)" workflows sign and notarize with when they exist (unsigned builds otherwise), and the checks.
- Hardened runtime entitlements: `src-tauri/Entitlements.plist`: `com.apple.security.device.audio-input` (the
  microphone), `com.apple.security.automation.apple-events` (the Apple events osascript sends for "Read browser tab
  addresses"; without it the hardened runtime refuses them and the switch silently reads nothing) and
  `com.apple.security.network.client`. A signed build is checked by `scripts/check-mac-signature.sh`: `codesign -dv`
  shows the runtime flag and `codesign -d --entitlements - --xml` allows the microphone and Apple events.
- `Info.plist` adds `NSMicrophoneUsageDescription` + `NSAudioCaptureUsageDescription` (system audio tap) +
  `NSAppleEventsUsageDescription` (the browser's tab address).

## Signed auto-updates (since v1.5)
The app looks for updates on the server it is signed in to: `<server>/downloads/desktop.json` (Tauri's static
manifest, served by the web app from the builds `echoledger:publish-release` stored). It checks soon after it starts
and every 6 hours (since v1.6), and on Settings → About → Check for updates, downloads a newer version through the
server's pinned TLS (only from that same server), verifies its minisign signature against the public key in
`tauri.conf.json → plugins.updater.pubkey`, shows "Update ready — Restart", and installs it the next time EchoLedger
starts, or at once on "Restart" (never while recording).

What an installed app needs to update itself (v1.6, P3.2):
- A build that has the updater: 1.5.0 and later. **1.1.0 has none** (the updater came in v1.5), so a computer on 1.1.0
  is updated once by hand: download the Windows installer from `<server>/downloads` and run it over the old one (the
  per-user install keeps the settings and the sign-in). From then on it updates itself.
- Signed in to the https server whose `/downloads/desktop.json` lists the new version (the app reads the manifest of
  the server it is signed in to, and only takes a package from that server).
- A version above the installed one in `tauri.conf.json` and `Cargo.toml` (semver: `1.6.0-beta.1` is below `1.6.0`).
- The `.sig` beside the package: made only when `TAURI_SIGNING_PRIVATE_KEY` (and `_PASSWORD`, empty for our key) is
  set, in the release shell or as the repository secrets; without them CI skips "Turn on the signed updater package" and
  makes no `.sig`. The key must be the private half of the public key in `tauri.conf.json` (key id `FBF4156A0A9B5B35`).
- Publishing: nothing in CI uploads to the server. Run `echoledger:publish-release` on the server with the installer
  and its `.sig` (below); only then does `/downloads/desktop.json` offer it.

- `requireSignedVersion: true`: each signature names the version it was made for (`tauri build` records it), so an
  old signed package cannot be offered under a newer version number.
- The private key (`desktop-updater.key`, made with `npx tauri signer generate --ci`) never enters the repo or GitHub.
  The release shell sets `TAURI_SIGNING_PRIVATE_KEY` to the key file's content and `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`
  to an empty string. Windows is built there with the release config, which turns the updater artifacts on:
  ```bash
  npm run tauri build -- --config src-tauri/tauri.release.conf.json            # Windows: the NSIS installer + .sig
  ```
  A build without that config (local tries, CI without the key) makes no `.sig` and is never offered as an update.
- Publish: `php artisan echoledger:publish-release <installer> --platform=windows --app-version=1.5.1
  --signature=<installer>.sig`. The Mac is released from CI's self-signed build (below).
- Losing the private key means no more updates for installed apps (they only trust that key): keep the offline backup.

## Releasing the Mac build (since v1.8.1)
Decided for v1.8.1 (docs/SIGNING.md): CI builds the self-signed `.dmg` and `EchoLedger.app.tar.gz`, the release shell
signs that `.app.tar.gz` with `tauri signer sign` (keeping the version binding; the updater key never goes to GitHub),
and before `echoledger:publish-release` it checks that `codesign -d -r-` on the packaged app pins the self-signed
certificate. A Mac build from a branch other than main or v1.1 is ad hoc and is never released.

1. Tag the release on main or v1.1 (`git tag v1.8.1 && git push origin v1.8.1`) and wait for "Desktop (macOS)" to pass
   on both chips. Its "Check the signature" step must say "Signed with the self-signed identity".
2. In the release shell, download both chips' artifacts of that run, one folder each:
   ```bash
   run=$(gh run list --workflow desktop-mac.yml --branch v1.8.1 --status success --limit 1 --json databaseId --jq '.[0].databaseId')
   gh run download "$run" -n EchoLedger-mac-apple-silicon -D release/mac-arm
   gh run download "$run" -n EchoLedger-mac-intel -D release/mac-intel
   ```
3. With the updater key in the environment (`TAURI_SIGNING_PRIVATE_KEY` = the key file's content,
   `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` = empty), check and sign each chip from `desktop/`:
   ```bash
   sh scripts/release-mac.sh ../release/mac-arm 1.8.1
   sh scripts/release-mac.sh ../release/mac-intel 1.8.1
   ```
   For each, `scripts/release-mac.sh` unpacks `EchoLedger.app.tar.gz` and mounts the `.dmg` read-only, and stops unless
   both apps are this version, pass `scripts/check-mac-signature.sh --pinned` and are the same build (one CDHash). The
   check means: valid and strict (`codesign --verify --deep --strict`), the hardened runtime (`codesign -dv`), the
   microphone and Apple events allowed (`codesign -d --entitlements - --xml`), signed with the certificate whose SHA-256
   is the one in docs/SIGNING.md, and `codesign -d -r-` reads
   `designated => identifier "com.echoledger.desktop" and certificate leaf = H"<that certificate's SHA-1>"`. Only then
   does it run `npx tauri signer sign --app-version 1.8.1 EchoLedger.app.tar.gz` (the version is bound into the
   signature, as `tauri build` does for `requireSignedVersion`), which writes `EchoLedger.app.tar.gz.sig`.
4. Copy each folder's `.dmg`, `EchoLedger.app.tar.gz` and `EchoLedger.app.tar.gz.sig` to the server and run the
   command the script printed, once per chip:
   ```bash
   php artisan echoledger:publish-release EchoLedger_1.8.1_aarch64.dmg --platform=macos-arm --app-version=1.8.1 \
     --updater-file=EchoLedger.app.tar.gz --signature=EchoLedger.app.tar.gz.sig
   ```
   (`--platform=macos-intel` with the Intel folder's files.) Only then does `/downloads/desktop.json` offer it.
5. On a Mac with the previous self-signed version: Settings → About → Check for updates, restart, and the microphone,
   system audio and Screen Recording are still allowed (the first self-signed build asks once for all three).

## Privacy checklist per release
- No telemetry/analytics crates (`cargo tree | findstr /i "sentry analytics telemetry"` must be empty).
- Provider keys never in the binary: `strings` the installer for `sk_`, `xi-api-key`, AssemblyAI key formats.
- Device token lives only in the OS keychain (Windows Credential Manager / macOS Keychain).
