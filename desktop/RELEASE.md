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
- Hardened runtime entitlements: `src-tauri/Entitlements.plist` (`com.apple.security.device.audio-input`).
- `Info.plist` adds `NSMicrophoneUsageDescription` + `NSAudioCaptureUsageDescription` (system audio tap).

## Signed auto-updates (since v1.5)
The app looks for updates on the server it is signed in to: `<server>/downloads/desktop.json` (Tauri's static
manifest, served by the web app from the builds `echoledger:publish-release` stored). It checks every 12 hours and on
Settings → About → Check for updates, downloads a newer version through the server's pinned TLS (only from that same
server), verifies its minisign signature against the public key in `tauri.conf.json → plugins.updater.pubkey`, and
installs it the next time EchoLedger starts, or at once on "Restart now" (never while recording).

- `requireSignedVersion: true`: each signature names the version it was made for (`tauri build` records it), so an
  old signed package cannot be offered under a newer version number.
- The private key (`desktop-updater.key`, made with `npx tauri signer generate --ci`) never enters the repo. The release
  shell sets `TAURI_SIGNING_PRIVATE_KEY` to the key file's content and `TAURI_SIGNING_PRIVATE_KEY_PASSWORD` to an empty
  string, then builds with the release config, which turns the updater artifacts on:
  ```bash
  npm run tauri build -- --config src-tauri/tauri.release.conf.json            # Windows: the NSIS installer + .sig
  npm run tauri build -- --bundles app,dmg --config src-tauri/tauri.release.conf.json   # macOS: .app.tar.gz + .sig
  ```
  A build without that config (local tries, CI without the key) makes no `.sig` and is never offered as an update.
- Publish: `php artisan echoledger:publish-release <installer> --platform=windows --app-version=1.5.1
  --signature=<installer>.sig`; on the Mac `--platform=macos-arm|macos-intel` with the `.dmg` as the file,
  `--updater-file=<EchoLedger.app.tar.gz>` and `--signature=<EchoLedger.app.tar.gz.sig>`.
- Losing the private key means no more updates for installed apps (they only trust that key): keep the offline backup.

## Privacy checklist per release
- No telemetry/analytics crates (`cargo tree | findstr /i "sentry analytics telemetry"` must be empty).
- Provider keys never in the binary: `strings` the installer for `sk_`, `xi-api-key`, AssemblyAI key formats.
- Device token lives only in the OS keychain (Windows Credential Manager / macOS Keychain).
