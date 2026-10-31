# EchoLedger v1.4 — finish checklist

The single list of what is left before v1.4.0. Tick an item only when its acceptance test passes. **(phone)** = checked
on the S25 in a phone window; **(mac)** = checked by Hassam on the MacBook from MANUAL_QA.

## Needs Hassam

| # | What | Why | Exact steps |
|---|---|---|---|
| N1 | Phone window, ~10 min (after P0) | Install the P0 fixes keeping data; test voice note, Waiting, bottom nav | Say "phone free"; I install with `pm install -r` and tell you what to tap. |
| N2 | Soniox credit | Live Translate text; "Needs Soniox credit" goes away | soniox.com → Billing → add funds. |
| N3 | macOS version | Call capture on the Mac needs macOS 14.2 or later | Apple menu → About This Mac → paste the version. |
| N4 | Mac checks (P3) | Only a Mac can test the Mac app | Download the .dmg from the GitHub Actions run, right-click → Open, run the Mac section of MANUAL_QA, paste results. |
| N5 | Keystore (optional) | Signed APK; switching needs one uninstall (data wiped) | Path, alias, both passwords — or keep debug builds. |
| N6 | TurboScribe texts | Accuracy Lab (P5) | Paste the corrected text of 3 recordings. |
| N7 | Phone window, ~30 min (P6) | Import, rename, a client call → call notes, Ask | Say "phone free" when ready. |

## P0 — Phone fixes before the meeting

- [ ] 1. In-app voice note: timer runs, a stopped note shows in Library at once (Uploading → Transcribing → ready). *Accept:* `RecordScreenTest` timer + saved-note test; **(phone)** record 10 s, see it in Library.
- [x] 2. Record sheet: compact 2×2 grid, tiles ~112 dp (icon, title, one-line status), height wraps content; Game/Watch say "Needs Soniox credit" when refused. *Accept:* screenshot `phone-new-sheet-*`; `UndecidedKeepWorkerTest` (refusal remembered).
- [ ] 3. Waiting: one summary card ("4 calls waiting · oldest Oct 30 · 2:18 total", Review) → Waiting screen with per-item Keep / Discard / Rename / Client / Language and Keep all / Discard all with undo; setting "Undecided calls: keep automatically after 24 h / 3 days / never" (default 24 h); same card on desktop and web. *Accept:* `WaitingScreenTest`, `UndecidedPolicyTest`; screenshots; **(phone)**.
- [x] 4. Selection bar: "4 selected" on one line; compact icons, labels on long-press. *Accept:* screenshot at 200 % text; test.
- [ ] 5. Bottom nav: any tab tap returns to that tab's main screen (closes sub-screens); a second tap scrolls to top. *Accept:* `MainActivityTest`; **(phone)**.
- [x] 6. Colour settings: swatches (circles with a check), no hex text; no one-character-per-line chip. *Accept:* screenshot; test.
- [x] 7. Messages never over a header: inline banner on Game/Watch or a snackbar above the nav. *Accept:* screenshot; no overlay text without a running session.
- [x] 8. Voice note screen: no "Client" meter, no "Both sides of calls" text. *Accept:* `RecordScreenTest`.
- [x] 9. Library rows: no "EN"/"UR" codes. *Accept:* `LibraryScreenTest`.
- [x] 10. Activity badge: only items that need action; "9+" above 9; informational items read once seen. *Accept:* test.
- [x] 11. "Auto-record calls" with the per-app list under it. *Accept:* screenshot; test.
- [ ] 12. Lag: profile (gfxinfo / JankStats / Perfetto), fix the top 3 causes, janky-frame % before and after. *Accept:* numbers in HANDOFF **(phone)**.
- [x] P0 suites: phone 1075/1075 + ktlint + lint + APK; desktop vitest 1087/1087 + tsc; web 3310 (2 stale error-table counts fixed) + PHPStan + Pint.

## P1 — Import, rename, save, live status

- [ ] Import single/bulk (.opus/.ogg/mp3/m4a/aac/wav, audio from mp4/webm): desktop (Import button + drop), web (Upload + drop zone, chunked resumable), phone (multi-select picker); batch client + language; per-file progress, cancel, retry; duplicates by hash skipped; titled from the file name. *Accept:* tests per app.
- [ ] Rename everywhere (row menu, recording title, Keep card), synced. *Accept:* tests.
- [ ] Save/export: audio + TXT/DOCX/PDF/SRT/JSON in the chosen view with unsure markers; bulk ZIP; desktop save dialog; phone share sheet. *Accept:* tests.
- [ ] Live status: ~5 s refresh only while something is pending; rows and recording page update by themselves; web polls pending items only. *Accept:* tests.

## P2 — One design for desktop and web

- [ ] Tokens (Inter, slate + indigo, radii, spacing, icons, light/dark) on desktop and web; old palette removed.
- [ ] Structure Library · Clients · Record · Activity · You; collapsible sidebar; bottom nav on narrow web.
- [ ] Components and screens: search pill + chips, rows, Waiting card, list + detail, recording page (chips, view switch, speaker blocks, docked player, find, Copy / Copy for AI), Keep card, Clients, Activity, settings panels, empty/loading/error.
- [ ] Desktop extras: Ctrl/⌘+K, N, Space, J/L; tray menu; pill and captions restyled; native notifications.
- [ ] Web: Filament theme on every page; user pages as custom Livewire pages; admin pages themed; responsive.
- [ ] Playwright screenshots (web 1440 + 390 px, desktop; light/dark) compared with the phone; before/after saved.
- [ ] P2 suites: phone, desktop, web.

## P3 — Mac app

- [ ] GitHub Actions: tauri-action on macOS, .dmg for Apple Silicon and Intel on v* tags and on demand.
- [ ] Core Audio process tap for the other side (14.2+, NSAudioCaptureUsageDescription), mic permission, app detection by bundle id, overlays and pill, ⌘ shortcuts, menu-bar icon.
- [ ] USER_GUIDE first open (right-click → Open); MANUAL_QA Mac section. **(mac)**

## P4 — Client workspaces

- [ ] Client tabs Overview · Recordings · Files · Ask; files encrypted, text extracted.
- [ ] Brief (one page, editable, grows from calls).
- [ ] Call notes after each kept recording for a client (only sections with content, timestamps, never invented, "verify").
- [ ] Client memory (scope, dated decisions, open questions, changelog; edit/pin; citations).
- [ ] Ask with citations; English / Roman Urdu output; Copy for AI bundle.
- [ ] Per-client AI on/off, monthly AI budget ($5) with usage; Anthropic data-use note in docs/research/.
- [ ] Same on phone, desktop and web.

## P5 — Accuracy boosters

- [ ] Client vocabulary to every engine (Whisper prompt, AssemblyAI keyterms, Soniox context).
- [ ] "Always write X as Y for this client?" after an edit.
- [ ] Speaker names: You + the contact's name.
- [ ] Suggested fixes (flag only, Accept to apply).
- [ ] Double-check on by default for recordings with a client.
- [ ] Accuracy Lab run (N6).

## P6 — Release

- [ ] Full suites green; lints clean.
- [ ] Web deploy (backup, maintenance, migrate, optimize, up, verify); Windows installer; Mac .dmg from CI; APK installed keeping data.
- [ ] Phone window (N7); Mac checks (N4).
- [ ] USER_GUIDE, MANUAL_QA, HANDOFF; tag v1.4.0; push.
