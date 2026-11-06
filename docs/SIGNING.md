# Signing the desktop app

Three different signatures matter for EchoLedger on Windows and the Mac:

| Signature | Who checks it | Without it |
|---|---|---|
| Windows code signing (Authenticode) | Windows, SmartScreen | "Windows protected your PC" and "Unknown publisher" on the installer |
| Apple Developer ID + notarization | macOS Gatekeeper | "EchoLedger cannot be opened" the first time, and on macOS 15 and later right-click → Open no longer gets past it: System Settings → Privacy & Security → Open Anyway. macOS may also forget the app's permissions after each update |
| The updater key (minisign) | EchoLedger's own updater | Nothing is installed: the app only installs packages signed with this key |

The first two are bought; the updater key is ours (made 2026-11-04, see `desktop/RELEASE.md`). The GitHub Actions
workflows build signed apps when the secrets below exist and unsigned ones otherwise, so nothing breaks while no
certificate is bought.

## What the workflows do

| Workflow | Secrets present | Result |
|---|---|---|
| Desktop (Windows) | none | Unsigned NSIS installer (the run's summary says so) |
| Desktop (Windows) | `WINDOWS_CERTIFICATE`, `WINDOWS_CERTIFICATE_PASSWORD` | The certificate is imported into the runner's user store for the build; the Tauri bundler signs the app, the installer and the uninstaller with signtool by its thumbprint (SHA-256, timestamped); the step after the build fails unless Windows reports the installer as validly signed |
| Desktop (macOS) | none | Unsigned `.dmg` for Apple Silicon and Intel, each built, tested and smoke-tested on a real runner of its chip; on a v* tag both are attached to the tag's GitHub Release |
| Desktop (macOS) | `APPLE_CERTIFICATE`, `APPLE_CERTIFICATE_PASSWORD`, `APPLE_ID`, `APPLE_PASSWORD`, `APPLE_TEAM_ID` (all five) | Signed with the Developer ID certificate and the hardened runtime, notarized by Apple and stapled; `codesign`, `spctl` and `stapler` check it after the build |
| Both | `TAURI_SIGNING_PRIVATE_KEY` (and `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`, empty for our key) | Also the updater package and its `.sig` (Windows: the installer; Mac: `EchoLedger.app.tar.gz`), uploaded with the run's artifacts |

Secrets go in GitHub → the repository → Settings → Secrets and variables → Actions. They are never printed, and runs
of pull requests from forks do not get them. A secret that is missing or empty counts as not there.

### Windows secrets
- `WINDOWS_CERTIFICATE`: the certificate with its private key as a `.pfx` file, base64. PowerShell:
  `[Convert]::ToBase64String([IO.File]::ReadAllBytes("echoledger.pfx")) | Set-Clipboard`; macOS:
  `base64 -i echoledger.pfx | pbcopy`.
- `WINDOWS_CERTIFICATE_PASSWORD`: the `.pfx` file's password.
- Optional repository variable (not a secret) `WINDOWS_TIMESTAMP_URL`: the certificate authority's timestamp server;
  DigiCert's `http://timestamp.digicert.com` otherwise. The timestamp keeps signatures valid after the certificate
  expires.

A `.pfx` file only exists for certificates whose key may leave the hardware: since June 2023 certificate authorities
issue new OV and EV code-signing keys only on a hardware token or in a cloud HSM (CA/Browser Forum rule). For those,
the bundler signs through a command instead of the thumbprint (`bundle.windows.signCommand`, where `%1` is the file),
for example with Azure Trusted Signing:

```json
{ "bundle": { "windows": { "signCommand": "trusted-signing-cli -e https://eus.codesigning.azure.net -a <account> -c <profile> -d EchoLedger %1" } } }
```

The import step in `.github/workflows/desktop-windows.yml` then writes this config instead of the thumbprint one
(the service's own secrets, such as `AZURE_CLIENT_ID`, `AZURE_CLIENT_SECRET` and `AZURE_TENANT_ID`, go in the step's
`env`). SSL.com eSigner (CodeSignTool), DigiCert KeyLocker (smctl) and Certum SimplySign work the same way with their
own tools. A USB token cannot be used from GitHub's runners: with one, releases are signed on the Windows laptop
(`npm run tauri build` with `certificateThumbprint` set to the token's certificate).

### Mac secrets
- `APPLE_CERTIFICATE`: the "Developer ID Application" certificate with its private key, exported from Keychain
  Access as a `.p12`, base64 (`base64 -i developer-id.p12 | pbcopy`).
- `APPLE_CERTIFICATE_PASSWORD`: the `.p12` file's password.
- `APPLE_ID`: the Apple Account email of the developer account.
- `APPLE_PASSWORD`: an app-specific password for it (appleid.apple.com → Sign-In and Security → App-Specific
  Passwords), never the account's own password.
- `APPLE_TEAM_ID`: the 10-character team id (developer.apple.com → Account → Membership details).
- Optional `APPLE_SIGNING_IDENTITY`: the certificate's name, e.g. `Developer ID Application: <Name> (<TEAM ID>)`;
  the bundler reads it from the certificate when it is not set.

### The updater key
The workflows can make the updater's packages only with the private key in `TAURI_SIGNING_PRIVATE_KEY`. Today the key
stays outside GitHub (and outside the Mac): releases are built and signed in the release shell (`desktop/RELEASE.md`)
and the CI builds carry no `.sig`. Putting the key in a secret is the lead's call: it lets every tagged build be
published as an update, but whoever can read the repository's secrets (or change a workflow on a branch that runs
with them) can then sign an update every installed app accepts. If it ever leaks, make a new key pair, ship one
release signed with the old key that carries the new public key, and only then retire the old one.

## Windows certificate options and costs

Approximate yearly prices, mid-2025, before tax; check the vendor's page before buying.

| Option | Who it is for | Cost per year | CI signing | Notes |
|---|---|---|---|---|
| OV code signing, cloud key (SSL.com eSigner, Certum SimplySign, DigiCert KeyLocker, GlobalSign) | A person or a company, identity checked | about $130–$500 for the certificate, plus the cloud signing plan where it is separate (SSL.com eSigner: about $20–$100 a month by volume) | Yes, through the vendor's signing tool (`signCommand`) | The usual choice for a solo developer. Shows the publisher name; SmartScreen warnings fade as the installer gains reputation with downloads |
| OV code signing on a USB token (Sectigo resellers, Certum) | Same | about $100–$300, token often included or about $50–$100 | No (sign on the Windows laptop) | Cheapest; releases must be built where the token is plugged in |
| EV code signing (token or cloud) | A registered company only | about $300–$700 | Cloud: yes | Since 2024 Microsoft no longer gives EV certificates instant SmartScreen reputation, so EV mostly buys stricter vetting, not fewer warnings |
| Azure Trusted Signing | Organisations with 3 years of history in the US, Canada, the EU or the UK, and individuals in the US and Canada (2025 terms) | $9.99 a month (Basic, 5,000 signatures) | Yes (`trusted-signing-cli`) | The cheapest managed option where it is offered; check whether it accepts us before planning on it |
| Self-signed certificate | Tests only | free | Yes | Windows treats it like no signature |

What we recommend: an OV certificate for "SkillLeo" (or Hassam as an individual) from a vendor with cloud signing, so
the "Desktop (Windows)" workflow can sign every tagged build; the token option if cost matters more than CI. The
publisher name in `tauri.conf.json` (`bundle.publisher`, now "SkillLeo") should be the certificate's name.

## Apple Developer ID and notarization

| Item | Cost | Notes |
|---|---|---|
| Apple Developer Program (individual or organisation) | $99 a year | Needed for a Developer ID certificate. An organisation needs a D-U-N-S number; an individual signs as their own name |
| Developer ID Application certificate | included | Made by the Account Holder in Xcode → Settings → Accounts, or on developer.apple.com → Certificates; up to five per team |
| Notarization | included | Apple's notary service scans the app (usually a few minutes) and the ticket is stapled to it |

Without it the app works, with two costs, and the $99 a year fixes both:

1. **First launch.** Every person has to allow it once by hand: on macOS 15 and later right-click → Open no longer works,
   so it is System Settings → Privacy & Security → Open Anyway (docs/USER_GUIDE.md).
2. **Permissions after an update.** macOS ties a permission to the app's signature, and an unsigned app's changes with
   every build, so it may forget the microphone, system audio and Screen Recording after each update. The app re-checks
   them at every start and, when one that was granted is gone, says so once with the guided steps; a person still has to
   allow them again.

The signing and notarization workflow already exists (`.github/workflows/desktop-mac.yml`, "What the workflows do"
above): put the five `APPLE_*` secrets in place and the next build is signed and notarized, with nothing else to change.

## Checks after a signed build
- Windows: `Get-AuthenticodeSignature .\EchoLedger_*_x64-setup.exe` (Status `Valid`) or `signtool verify /pa /v
  <installer>`; on a clean Windows 11 machine the installer shows the publisher name.
- Mac: `codesign --verify --deep --strict --verbose=2 EchoLedger.app`, `spctl --assess --type execute --verbose=4
  EchoLedger.app` ("source=Notarized Developer ID"), `xcrun stapler validate EchoLedger.app`; a download opened on
  another Mac starts without the warning.
- Updates: the `.sig` next to each package goes to `echoledger:publish-release --signature=<file.sig>`; an installed
  app finds the update under Settings → About → Check for updates.
