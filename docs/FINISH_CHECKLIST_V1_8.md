# v1.8 finish checklist — trust, call flow, native design, launch

Single source of truth for v1.8 (plan: docs/PLAN_V1_8.md, local only). Each item is ticked only when its acceptance
test passes; the evidence (commit, test, device log) goes on the item. v1.9 Phase S (private screen share,
docs/FINISH_CHECKLIST_V1_9.md) runs inside v1.8 and ships as a v1.8.x release; Phase L starts after the v1.8.0 tag.

## Needs Hassam

| # | What | Why it matters | What to do |
|---|---|---|---|
| H1 | **The GitHub repo `skillLeo/echo-ledger` is public** — decided 2026-11-16 (D1): make it private; Hassam switches it. Checked: the updater, website and apps don't depend on it (updates come from `<server>/downloads/desktop.json`; the only GitHub links are font licences). After the switch, Mac builds run on a self-hosted runner on the development Mac (never on a public repo) | Anyone can download the whole product without logging in (checked 2026-11-16). No keys are in it, but the code, docs and server layout are | Say "make it private". Cost: private repos get 2,000 free GitHub Actions minutes a month and Mac builds count ×10, so the desktop builds would then run only on tags and on request (or GitHub Pro, $4/month, 3,000 minutes) |
| H2 | Phone windows on the S25 (~30 min each) | 0.2 (read the capture journals), 0.3 (SIM calls), P9 (final check) | Say "phone free" |
| H3 | Screen-share windows on the Windows laptop and the Mac, with a second device watching | 0.1 SM-W1…W6 and SM-M1…M10; v1.9 S0/S7 | Say "laptop free" / "Mac free" and have a phone ready to join the meeting |
| H4 | The product name (Baatly recommended, Mukalma backup) and the final domain — D2: not final; docs/NAME_CHECK.md (local) has the checks (2026-11-16: baatly.com is taken and listed for sale; baatly.app/.ai and mukalma.app/.ai are free; mukalma.com is a live site; no app or trademark with either exact name); no Play production release before Hassam confirms | P6.1 website, P7 store listings; the Android applicationId can never change after the first Play release | Check baatly.com / .app / .ai, the @baatly handles and trademarks (IPO-Pakistan, WIPO, USPTO, EUIPO), then decide |
| H5 | Pick 1 of the 3 brand directions | P5.2 | Look at the screenshots in docs/design/brand/ when they are ready |
| H6 | Google Play company account ($25 once) + a free D-U-N-S number for SkillLeo SMC Pvt Ltd — D6: organization accounts, Hassam creates them from docs/store/ACCOUNT_SETUP.md | P7.1 | play.google.com/console → create a developer account as an organization |
| H7 | The Play demo videos (foreground services) and the reviewer test account | P7.1 | The list of videos and the account steps will be in docs/LAUNCH.md |
| H8 | Microsoft Partner Center account (free for individuals) | P7.3 MSIX in the Microsoft Store | partner.microsoft.com → enroll |
| H9 | Apple Developer Program as an organization ($99/year) | P7.4 notarization; P8 iPhone ("Apple account ready") | developer.apple.com/programs/enroll as SkillLeo SMC Pvt Ltd |
| H10 | Android developer verification for the website APK | P7.2 | Steps in docs/LAUNCH.md |
| H11 | The desktop updater's private key (v1.6 V1) | Installed desktops only take updates signed with it | From the Windows laptop into GitHub secrets, or say "key lost" |
| H12 | Approve the title backfill list (dry run) | P2.1 | Read the list I send and say "apply" |
| H13 | A support mailbox (contact@…) | P6.4 Contact support | Create it on the final domain |
| H14 | A short legal look at the consent wording (US/EU) | P1, v1.9 L | Before the public launch |

## Replaced or carried over

- **Replaced by v1.8:** v1.6 P5.1/P5.4/P5.5 (Library and sharing) → P2.1/P2.2; v1.6 P6 (flow audit) → P5; v1.6 V11
  (downloads page) → P6.1/P7; v1.6 P1.4 leftovers (upgrade sheet) → done in v1.7, Play side → P7.1; v1.6 P1.1 profile
  leftovers → P2.6; v1.6 P7 S25 Free-user journey → passed 2026-11-09 (closed), final phone check → P9.
- **Carried over unchanged:** v1.6 V1/P3.2 (updater key) → H11; v1.6 P2.6 (usage report PDF); v1.6 P3.1 "Sign in again"
  on the phone; v1.7 leftovers (renewal window, Raast QR, payment reminders and auto-close, 6-year payment records,
  search Recent/Suggested, full-size receipt viewer, notification sounds page, one 12/24-hour time formatter
  everywhere, a native speaker's review of the Roman Urdu / Urdu search words).

## Step 0
- [x] Plan saved locally (docs/PLAN_V1_8.md, in .git/info/exclude); this checklist committed; v1.9 plan, checklist and
  research doc saved the same way (2026-11-16).

## P0 — Trust bugs
- [ ] 0.1 Screen moments: the exact cause found on Windows and the Mac (plan gating, the switch and project default, the
  Mac permission after updates, meeting detection, thresholds and cap, upload, server). **Acceptance:** a cause report
  in HANDOFF with evidence for each of a–f; a regression test per cause.
  - 2026-11-16 production evidence (read-only, counts only): Hassam's workspace is on the business (Ultra) plan with
    screen moments allowed (a: not the cause); 14 desktop recordings in 30 days and **0 screens ever** on the server;
    the server's PHP has GD with WebP (f: not the cause). So no screen ever left the computers: the Mac's Screen
    Recording permission lost with each unsigned update (c), silent capture failures and the end-of-call race (d, e) —
    fixed on b-desktop (`31eb96ed`), in review fixes; Mac signing in CI follows.
- [ ] 0.1b The pill shows a live "Screens N"; at 0 after 60 s of sharing it says why with a fix button; the after-call
  card and the Library row show the count. **Acceptance:** component tests for the count, the 60 s reason (each reason
  string) and the row icon.
- [ ] 0.1c "Capture screen now" in the pill and a global shortcut (default Ctrl/⌘+Alt+S, changeable, conflict-checked)
  with a confirming flash. **Acceptance:** shortcut registration and conflict tests; a capture lands in the recording.
- [ ] 0.1d SM-W1…W6 (Windows) and SM-M1…M10 (Mac) run with Hassam; everything found fixed. **Acceptance:** results in
  MANUAL_QA with dates.
- [ ] 0.2 Missed-recording detector (phone: telephony state, call log in full, call notifications; desktop: meeting
  detection) → an Activity item "Missed recording" with the reason (helper not armed, permission, detection, mic busy,
  crash) and a fix button; the admin panel counts missed recordings per device. **Acceptance:** unit tests per reason;
  API + isolation tests; the admin count.
  - Server part merged 2026-11-16 (`d71195cb`, reviewed twice; contract 1.8.0-c; full pest 5,712): the endpoint, the
    "Missed recording" activity item (needs action), Admin → Diagnostics → Missed recordings, 90-day retention with the
    item. Waiting: the phone detector (b-phone) and the desktop detector.
- [ ] 0.2b A capture journal per call (detected → armed → started → both channels have audio → saved), kept 7 days,
  in "Copy diagnostics". **Acceptance:** journal tests (rotation at 7 days, the stages in order).
- [ ] 0.2c The real causes from 7 days of journals on the S25 and the laptop (phone window), each fixed with a test.
  **Acceptance:** the causes listed in HANDOFF with their fixes. (Already found and fixed 2026-11-12: a WhatsApp call
  right after a WhatsApp Business call was taken for Business and dropped as unanswered, `c13a3dc7`.)
- [ ] 0.2d Desktop: unrecognised meetings in a browser or app are never skipped silently: after 20 s of two-way audio a
  "Meeting detected — Record?" chip (Record / Not now / Never for this app). **Acceptance:** detection tests with
  localized Meet titles, a PWA and Teams web.
- [ ] 0.3 SIM calls on the S25: diagnosed (VOICE_CALL / uplink / downlink through the helper on One UI 8.5, Android
  16); if blocked, Samsung's own call recording imported right after each call (MediaStore watch), named from the
  contact or number, with the after-call card; nothing in Samsung's folder deleted unless chosen; other brands' folders
  where found. **Acceptance:** incoming, outgoing, an unsaved number, a short call and two calls back to back on the S25.

- [ ] 0.4 Mac: opening EchoLedger from Finder, Launchpad or the Dock while it is already running shows nothing (only the
  menu bar icon opens it). `lib.rs` handles `RunEvent::Opened` and `ExitRequested` but not `RunEvent::Reopen`, which
  macOS sends to the running copy (found by Hassam 2026-11-16 on the installed v1.6.0). **Acceptance:** Reopen with no
  visible window shows and focuses the main window; a test for the handler; checked on the Mac.
  - Code merged 2026-11-16 (`10c7a743`, reviewed; cargo 1,312 passed): the decision uses whether the MAIN window is on
    screen (macOS counts the always-built dictation box as visible). Waiting: MAC-31 on a build with it (Mac batch).

## P1 — Before, during, after a call
- [ ] 1.1 Calls always recorded as audio when Ready; transcribed only with permission. One after-call card (phone:
  bottom, ~64 dp, never over the end-call screen; desktop: a toast by the tray/menu bar): avatar or app icon, "Name ·
  4:12" (+ "3 screens" on the desktop), Transcribe (primary) · Save audio · Delete, the name opens rename/project, a
  thin time-left line; no answer in 12 s → Save audio (not transcribed); Delete → the one undo snackbar. Setting "After a
  call": Ask me (default) · Always transcribe · Always save audio only; rules "Always transcribe calls with this contact
  / in this project"; the v1.5 "Keep automatically / Ask me" migrated. **Acceptance:** card tests (each action, the
  12 s default, rules), settings migration test, API contract for audio-only uploads.
- [ ] 1.2 "Not transcribed": a smart folder and a filter; rows "Audio only · Transcribe"; the recording page's
  Transcribe with the estimate and what's left in the plan; bulk "Transcribe selected" with the total; audio-only counts
  for storage, not minutes; its screens still upload. **Acceptance:** API (transcribe later, estimate) + isolation
  tests; row and bulk tests on every app.
- [ ] 1.3 During the call: the pill "● 02:14 · Omar"; tap → Captions, Pause, Stop, Screens on/off + Capture now
  (desktop), Language, Discard; captions off by default. **Acceptance:** pill tests on phone and desktop.
- [ ] 1.4 "How recording works" (Before / During / After) from the Calls: Ready chip, once in onboarding and always in
  Help. **Acceptance:** screen tests; copy review.

## P2 — Library and recording page like WhatsApp
- [ ] 2.1 Rows: 48 dp avatar (contact photo or initials) with an app badge; line 1 the contact's name / meeting title
  / topic (a typed title wins) + time; line 2 type icon + duration + first words (default view, RTL-safe) or the state;
  right side unread dot, pin, screens count. Backfill of content-generated call titles (dry run first, H12).
  **Acceptance:** row tests on phone, desktop and web; backfill dry-run list in HANDOFF.
- [ ] 2.2 Recording page: top bar (back, title, search, Share, ⋮); one Share sheet (WhatsApp text / text + voice, Copy
  text, Copy for AI, Meeting pack PDF/ZIP, Share link, Export); the pill row removed; one sticky player; Re-transcribe
  in ⋮; the repeated last lines ("Have a good time." twice) found (bleed or chunk overlap) and the dedupe fixed.
  **Acceptance:** page tests; a dedupe regression test on the real transcript shape.
- [ ] 2.3 Fonts: the phone's own font by default; system fonts on desktop and web; Noto Nastaliq / Naskh only for Urdu
  and Arabic; "EchoLedger font" (Inter) as an Appearance option; every hard-coded family fixed. **Acceptance:** a
  font-family scan test per app.
- [ ] 2.4 One snackbar system (one at a time, message + one action + 5 s line; delete → Undo, 48 h Recently deleted;
  no snackbar for pin/unpin/read; Move → "Moved to Acme · Open"; errors inline with one fix) on phone, desktop and web;
  the old components removed. **Acceptance:** component tests; a scan that no old toast/timer component remains.
- [ ] 2.5 Storage: the account's numbers from the plan (used of limit, by kind, retention) and this device's (free
  space, cache, waiting uploads, Clear cache); the server's disk only in the admin panel. **Acceptance:** API +
  isolation tests; screen tests on every app.
- [ ] 2.6 Profile & account on every app with one API: photo (upload, crop, remove; initials fallback), name, email
  change by code, password, two-step (TOTP + recovery codes), passkeys, Google, language, time zone, devices, export,
  delete. `GET/PATCH /api/me`, `POST/DELETE /api/me/avatar` (256 px WebP, metadata stripped), email change start/verify.
  The avatar in Settings, the top bar, shared items and team lists. **Acceptance:** API + isolation + validation tests;
  screens on every app.

## P3 — Desktop polish
- [ ] 3.1 Captions window: drag from anywhere on the top bar; resize from every edge and corner (min 280×80, max 80%
  of the screen) with reflow; no snap after a drag (only a gentle 12 px edge snap); position and size per monitor,
  double-click resets; A−/A+, opacity, lock (click-through), close; the top bar and handles still respond in
  click-through on Windows and Mac. **Acceptance:** component + Rust tests; MANUAL_QA rows.
- [ ] 3.2 The Mac app: in the Dock (ActivationPolicy Regular by default); Reopen opens or focuses the main window;
  single instance; "Show in Dock" / "Show in menu bar"; a monochrome template menu-bar icon (Open, Record now, Captions,
  Pause, Quit); a proper app menu (About, Settings… ⌘,, Check for updates, Hide ⌘H, Quit ⌘Q; ⌘W keeps recording, said
  once). **Acceptance:** Rust tests for the policy and reopen; MANUAL_QA on the Mac.
- [ ] 3.3 Appearance on all apps: theme, 6 accent colours, text size, density; desktop window style (Solid default /
  Translucent) and sidebar style; phone "Use wallpaper colours" (off); web theme, accent, density; saved per device; a
  quick theme switch in the avatar menu. **Acceptance:** settings tests per app; screenshots.

## P4 — Meeting assets for AI
- [ ] 4.1 WebP ~75, ≤1600 px + 320 px thumbnail, near-duplicates dropped (perceptual hash), ≤ ~120 per hour, typical
  60–150 KB. **Acceptance:** encoder tests; the real average size per meeting hour reported.
- [ ] 4.2 Names `S07_14-32_orders-page.webp`; each screen keeps its time, OCR text, a one-line description and what was
  said ±15 s. **Acceptance:** naming + metadata tests.
- [ ] 4.3 The Library row's screens count; the recording page's Screens strip and inline screens ("Screen 7 · 14:32");
  a viewer with "Play from here"; a gallery per project. **Acceptance:** screen tests on every app.
- [ ] 4.4 Export for AI: Meeting pack PDF (title page, transcript with labelled screens, < ~100 pages, split longer),
  Meeting folder ZIP (transcript.md with markers, screens/, screens.md, optional audio), Copy for AI with markers; the
  connector's get_recording uses the same names. **Acceptance:** export tests; the PDF opened in Claude (P9).

## P5 — Looks professional, not generated
- [ ] 5.1 Audit and remove the tells (all caps, middle-dot strings, "→" on buttons, emoji/sparkles, identical cards,
  gradients, purple defaults, generic copy, "Submit", mixed fonts, wrapped pills, more than one primary button).
  **Acceptance:** a lint/scan per app for the mechanical ones; a screenshot review for the rest.
- [ ] 5.2 Three brand directions as screenshots (Library, recording page, after-call card; light and dark) → H5; the
  chosen one applied everywhere. **Acceptance:** screenshots in docs/design/brand/; the choice applied.
- [ ] 5.3 Copy pass: sentence case, plain verbs, one name per action through the flow. **Acceptance:** copy tests per
  app.
- [ ] 5.4 Screenshot review of every screen (phone light/dark, desktop Windows + Mac, web 1440 and 390) against
  WhatsApp, Gmail and Linear. **Acceptance:** the review notes and fixes in HANDOFF.

## P6 — SaaS
- [ ] 6.1 Public website on the main domain (H4): home, pricing from the admin plans, downloads (badges, direct
  files, iPhone "coming soon"), help center, privacy, terms, contact. **Acceptance:** page tests; Lighthouse-style
  accessibility and speed check.
- [ ] 6.2 First run: sign-up → 3 questions → "Get the apps" with QR codes + "Email me the links" → one first success →
  a 5-step checklist on Home. **Acceptance:** flow tests on web and apps.
- [ ] 6.3 Invitations: email or link with role and expiry; seats shown; "Join Acme on EchoLedger" → sign up → apps;
  personal accounts stay private. **Acceptance:** API + isolation tests; the join page.
- [ ] 6.4 In-app Help: "?" with search over the guides, What's new, Contact support (device report only when ticked).
  **Acceptance:** screen tests per app.

## P7 — Launch readiness
- [ ] 7.1 Play flavor: no READ_CALL_LOG / PROCESS_OUTGOING_CALLS, no REQUEST_INSTALL_PACKAGES or self-updater, no
  Accessibility; foreground-service declaration texts + the demo-video list; prominent disclosures before mic,
  contacts and notification access; no prices / Upgrade / payment instructions or links; account deletion verified in
  the app and on the web; Play Console answers (Data safety, privacy URL, rating, 18+, no ads, reviewer account);
  store listing text and 8 screenshots + feature graphic + 512 px icon; AAB with Play App Signing; target API confirmed
  (37); honest review notes. **Acceptance:** a manifest test of the play flavor's permissions; docs/LAUNCH.md complete.
  - 2026-11-16: docs/LAUNCH.md written (accounts, steps, draft Data safety answers). Found: the privacy policy's contact
    line points to a personal mailbox, it does not mention the caller names and numbers the website build sends, and
    there is no support page (`/contact` is 404; Apple requires a support URL) → web work with P6.4 and H13. To confirm
    for the Data safety form: whether production sets `ECHOLEDGER_COUNTRY_HEADER` and whether access logs keep search
    queries.
- [ ] 7.2 Website APK: Android developer verification steps documented (package + signing key). **Acceptance:** the
  steps in docs/LAUNCH.md.
- [ ] 7.3 Windows: an MSIX for the Microsoft Store (H8); the website installer kept; OV signing documented as the
  alternative. **Acceptance:** the MSIX built in CI.
- [ ] 7.4 Mac: notarization ready in CI for when the Apple Developer ID is added (H9); USER_GUIDE says what changes.
  **Acceptance:** the CI job present and skipped cleanly without the secrets.
  - 2026-11-16: the Mac app runs `osascript` to read browser tabs, but Entitlements.plist has no
    `com.apple.security.automation.apple-events`; under the Hardened Runtime that entitlement is needed for Apple events
    to other apps. Add it with the notarization job and test it on the first signed build.

## P8 — iPhone
- [ ] P8 docs/IOS_PLAN.md written; the build starts only on "Apple account ready" (H9). **Acceptance:** the plan file.

## P9 — Release + verify
- [ ] Full suites green; web deployed with the usual safety steps; Windows installer + MSIX; Mac DMG; the phone full
  flavor installed on the S25 keeping data + the play AAB; the phone window (H2) and the Windows + Mac window (H3) run;
  USER_GUIDE, ADMIN_GUIDE, LAUNCH.md and HANDOFF updated; tagged v1.8.0; the final short report (what's live, what
  needs Hassam in order with steps, the costs). **Acceptance:** all of the above recorded in HANDOFF.

Backlog: video clips around screen moments; Play Billing / StoreKit; Apple sign-in; Mac App Store.
