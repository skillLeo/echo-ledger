# Releasing EchoLedger Desktop

## Build
```bash
npm ci
npm run tauri build            # Windows: NSIS installer (tauri.windows.conf.json); macOS: .app + .dmg
```
Prerequisites: Rust stable (MSVC on Windows) + Visual Studio Build Tools "Desktop development with C++";
WebView2 (preinstalled on Windows 10 1803+). macOS: Xcode Command Line Tools; build on macOS 14.4+ hardware.

## Windows code signing (Authenticode / SmartScreen)
- Buy an OV/EV code-signing certificate (or use Azure Trusted Signing). Since 2024 EV no longer grants instant
  SmartScreen reputation — reputation builds with downloads; signing still removes "Unknown publisher".
- Configure `bundle.windows.certificateThumbprint`, `digestAlgorithm: "sha256"`, `timestampUrl` in
  `tauri.windows.conf.json`, or a `signCommand` for cloud HSMs (Azure Key Vault / Trusted Signing).
- Verify: `signtool verify /pa /v EchoLedger_*_x64-setup.exe`. Manual QA: install on a clean Windows 11 VM and
  confirm SmartScreen shows the publisher name.

## macOS signing + notarization
- Needs an Apple Developer "Developer ID Application" certificate.
- Env for `tauri build`: `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_SIGNING_IDENTITY`, and either
  `APPLE_ID` + `APPLE_PASSWORD` (app-specific) + `APPLE_TEAM_ID`, or `APPLE_API_ISSUER` + `APPLE_API_KEY` +
  `APPLE_API_KEY_PATH`.
- Hardened runtime entitlements: `src-tauri/Entitlements.plist` (`com.apple.security.device.audio-input`).
- `Info.plist` adds `NSMicrophoneUsageDescription` + `NSAudioCaptureUsageDescription` (system audio tap).
- Verify: `spctl -a -vvv -t install EchoLedger.app` and `xcrun stapler validate EchoLedger.dmg`.

## Signed auto-updates (optional, off by default)
1. `npm run tauri signer generate -- -w ~/.tauri/echoledger.key` (keep the private key + password offline / in CI secrets).
2. Put the **public** key contents in `tauri.conf.json → plugins.updater.pubkey`, set `endpoints` to an HTTPS URL
   on your server (returns `204` when there is no update, or the JSON manifest), set
   `bundle.createUpdaterArtifacts: true`.
3. Build with `TAURI_SIGNING_PRIVATE_KEY` (+ `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`). The `.sig` *contents* go in
   the manifest `signature` field. `requireSignedVersion: true` binds each signature to its version.
4. Signatures are verified on download and install and cannot be disabled. Never install while recording.

## Privacy checklist per release
- No telemetry/analytics crates (`cargo tree | findstr /i "sentry analytics telemetry"` must be empty).
- Provider keys never in the binary: `strings` the installer for `sk_`, `xi-api-key`, AssemblyAI key formats.
- Device token lives only in the OS keychain (Windows Credential Manager / macOS Keychain).
