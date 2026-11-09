# EchoLedger v1.6 — finish checklist

Plan: docs/PLAN_V1_6.md (local only). Design reference: docs/design/reference/ (another client's design, local only,
patterns only). One continuous run; after each item: HANDOFF + this file, related tests, commit, push; full suites per
phase. Tenant isolation: for every endpoint a test where user B asks for user A's resource and gets 404.

## Needs Hassam

| # | What | Why | Exact steps |
|---|---|---|---|
| V1 | The desktop updater's private key (N14) | Installed desktops (the laptop shows 1.1.0) take only updates signed with the key they were built with; it is only on the Windows laptop | From the laptop, the key file and its password, into GitHub → Settings → Secrets: `TAURI_SIGNING_PRIVATE_KEY` and `TAURI_SIGNING_PRIVATE_KEY_PASSWORD`. If the key is lost: say so; a new key is made and the laptop's 1.1.0 is replaced by hand once, then it updates itself. |
| V2 | Google sign-in (N7) | "Continue with Google" on web, phone and desktop | console.cloud.google.com → APIs & Services → Credentials → OAuth client ID → Web application; redirect `https://<server>/auth/google/callback`; paste the ID and secret in Admin → API Credentials → Google sign-in (ADMIN_GUIDE has the steps). |
| V3 | Cloudflare Turnstile (free) | The anti-bot check on web sign-up | dash.cloudflare.com → Turnstile → Add widget for the console's domain → paste the site key and secret in Admin → API Credentials → Turnstile. |
| V4 | Plans: prices and hours | The Pro and Business display prices and Pro's platform hours a month | A line each (they stay editable in the admin panel). |
| V5 | Payment instructions | Shown after "Request upgrade" | The bank / JazzCash text to show (account name, number, what to write in the reference). |
| V6 | Terms and Privacy (N10) | Before anyone else signs up | Read the drafts and send changes. |
| V7 | ElevenLabs key, Soniox credit, Gemini key (from v1.5) | Arabic on Scribe; the short-note check's second engine; the tie-breaker | API Credentials; soniox.com → Billing. |
| V8 | A phone window for P7 | A new Free user signs up on the S25 with an email code | Say "phone free". |

Not needed any more: the laptop's Android `debug.keystore` (the S25 runs a build signed on this Mac since 2026-11-09).

## Step 0
- [x] Plan saved locally; the design reference copied to docs/design/reference/ and excluded locally (and guarded by tools/repo-policy). Phase 1C items that overlap (progress, group by, sign-in, notifications screen) are redone here; widgets, Quick Settings tile and the tap-count audit continue after P5.

## P1 — Sign-up, Free plan, entitlements
- [ ] P1.1 One sign-in flow (email → code for a new email → name → Terms → account on the default plan; password or "Email me a code" → 2FA; Google; no server address, Advanced → another server; QR a small link; plain words) on web, phone and desktop. **Phone done** 2026-11-09 (SignInFlow, SignInScreen; the server from the build: `echoledger.defaultServer` in the user's gradle.properties, never in the repo; install_id on every sign-in; PairingScreenTest 15, SignInFlowTest). The instrumented e2e tests still use the old labels: to update before the next phone run.
- [ ] P1.2 Entitlements engine: features registry, plans with features and limits, per-user overrides and plan expiry, every API checks, `GET /api/me/entitlements`; provider keys never leave the server.
- [ ] P1.3 Default plans Free / Pro / Business; the super admin on Business unlimited; Free transcribes only with the user's own Groq key (encrypted per user) or on-device.
- [ ] P1.4 One badge style, the upgrade sheet, "Request upgrade", payment instructions.
- [ ] P1.5 Abuse protection: Turnstile, disposable-email blocklist, sign-ups per IP a day, device limits, unusual-usage alert, suspend.

## P2 — Admin panel
- [ ] P2.1 /app and /admin apart; platform items move to /admin.
- [ ] P2.2 Customers table and drawer (usage, devices, plan, overrides, notes, audit, support actions); metadata only unless support access is granted.
- [ ] P2.3 Plans & features (matrix).
- [ ] P2.4 Upgrade requests inbox.
- [ ] P2.5 Staff roles and permissions matrix; 2FA for staff; workspace roles on the same matrix.
- [ ] P2.6 Usage & costs reports.
- [ ] P2.7 System: sign-up settings, app versions, announcement, maintenance, audit log.

## P3 — Devices
- [ ] P3.1 Stable per-install identity, one row per device (duplicates merged), readable names, statuses, rename / sign out / sign in again / remove, live QR status, device limit, new sign-in alert.
- [ ] P3.2 Installed desktops update themselves (needs V1).

## P4 — App shell (web + desktop)
- [ ] P4.1 Sidebar · P4.2 nested column · P4.3 top bar · P4.4 page pattern · P4.5 Account tabs · P4.6 phone web · P4.7 screenshot review.

## P5 — Library, progress, sharing
- [ ] P5.1 Group header: checkbox only selects, chevron/label only folds (web, desktop, phone).
- [ ] P5.2 Web Library decluttered.
- [ ] P5.3 Auto titles backfilled; failed uploads explained.
- [ ] P5.4 Progress everywhere, Continue in background, Notify me when ready.
- [ ] P5.5 Share to WhatsApp: text, text + voice, link.

## P6 — Flow audit
- [ ] P6 Every listed item fixed, more searched for; the 15 common tasks counted on web, desktop and phone.

## P7 — Release and verify
- [ ] P7 Migrations (existing data → Hassam's workspace, super admin on Business unlimited), deploy, installers, phone build, the end-to-end Free-user test, guides, tag v1.6.0.
