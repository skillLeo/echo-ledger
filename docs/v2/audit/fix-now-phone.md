# Phone UI sweep: what to fix now (2026-11-17)

I swept the Android app page by page. Sources: the 102 rendered screenshots in `docs/design/screens/phone-*.png` (light and dark, plus 200 % and 360 dp where they exist; 14 of them were re-taken or added at 02:28, and I viewed those too), and the UI code in `ui/`, `ui/design/`, `overlay/`, `translate/`, `notify/` and `res/values*/`. I triaged everything with the severity scale in PLAN_V2_0.md and the Stage A rule in ROADMAP.md, the same way as `docs/v2/audit/fix-now-desktop.md`. The result is 74 rows, P-002 … P-075, in `phone-bugs.csv`, ready to append to `docs/v2/audit/bugs.csv`. P-001 already exists, so there is no new vendor-name row.

Paths below are relative to `mobile/app/src/main/java/com/echoledger/mobile/` unless they start with `mobile/` or `docs/`. Line numbers are as of 02:30. The P-001 change landed in the working tree at 02:28, mid-sweep, so `Screens.kt` (after line 1133) and `MainViewModel.kt` moved a few lines. I re-checked every location after that. No git was used.

Gaps in the evidence:
- 200 % text: screenshots exist only for the Library, the Settings root and the recording page's actions.
- 360 dp: a screenshot exists only for the recording page's actions.
- No screenshots of the bottom bar or record button, Sign-in, Account, a project page, the share sheet, request links, or anything right to left. Rows that come from the code alone say "From the code", and their fix adds the missing screenshot.
- `phone-record-light.png` is byte-for-byte the dark file (26,184 bytes each). `ScreenshotsTest.record()` turns `autoAdvance` off, so the light capture runs before the theme change lands. This is a test bug, not an app bug. The idle Voice note page in light was seen only in code.

## (a) Counts

| Severity | Rows | Fix now, open | Fixed (merged) | v2 backlog |
|---|---|---|---|---|
| S0 Blocker | 2 | 2 | 0 | 0 |
| S1 Critical | 2 | 2 | 0 | 0 |
| S2 Major | 22 | 21 | 1 | 0 |
| S3 Minor | 34 | 0 | 0 | 34 |
| S4 Polish | 14 | 0 | 0 | 14 |
| **Total** | **74** | **25** | **1** | **48** |

- **Fix now: 26 rows.** 25 are open, in 6 fix batches below. One is already fixed and merged: P-027, the "Send to WhatsApp" button squeezed into a one-letter column.
- **v2 backlog: 48 rows** (fix_now = no). No S2 is left in the backlog.
- **By module:** Settings 24, Cross-cutting 13, Library 11, Recording page 10, Record 5, Floating windows 4, Projects 2, Plan 2, Notifications 1, Shell 1, Activity 1.
- **By category:** Copy 24, Component state 10, Backend/Flow 9, Navigation/IA 8, Layout/Resize 6, Typography 4, Accessibility 4, Colour/Theme 3, RTL 3, Overlay/Window 2, Icon 1.

## (b) Fix now: 25 open rows in 6 fix batches

Do Batch 1 first: an S0 is fixed before any release. Keep each fix small, because v2.0 rebuilds these screens.

The only new shared piece is a 5 s "… · Undo" pattern for discards and deletes. The recording controls' Discard now already works this way (`recording/PillMachine.kt`: pause, Undo for 5 s, then delete). Everything else uses `ConfirmDialog` (`ui/Screens.kt:944`). Each batch adds light and dark screenshots of the screens it touches, and the 200 % ones that are missing.

### Batch 1: recordings and links lost in one tap (RC-02), 4 rows
- P-002 (S0) — Holding an Uploading row ("Hold to cancel", "hold to remove") deletes the recording at once. It is the only copy and was never uploaded. There is no confirm and no Undo, and the message says "Upload cancelled". On every other row a long-press selects — `ui/LibraryScreen.kt:423-424,642-644`; `ui/MainViewModel.kt:1108-1116` — Long-press selects. Removing goes in a menu, with a confirm or a 5 s Undo before the file is deleted.
- P-003 (S0) — The after-call card's "×" reads as close, but one tap deletes the call's recording file at once. This is the desktop's D-105 defect — `overlay/DecisionPopup.kt:125,187-190,274`; `calls/SaveDecisionReceiver.kt:56-61` — A trash icon labelled "Discard", then "Discarded · Undo" for 5 s. Delete the file only after that.
- P-004 (S1) — On Record, a failed upload offers Retry and Discard. Discard reads like dismissing the error, but it deletes the only copy in one tap — `ui/RecordScreen.kt:384-399` — Confirm or a 5 s Undo, and call the button Delete.
- P-005 (S1) — Revoke (share link), Turn off (request link) and Stop sharing (teammate) act at once with no way back. The link dies for everyone who has it. This matches the desktop's D-072 — `ui/Projects.kt:754,767,1009` — ConfirmDialog. Also delete the unused `ShareLinkDialog` (`ui/TranscriptTools.kt:208-322`), which has the same one-tap Revoke.

### Batch 2: other one-tap destructive actions (RC-02), 4 rows
- P-006 — The post-call notification's Discard deletes the recording at once with no Undo — `notify/Notifications.kt:319-334`; `calls/SaveDecisionReceiver.kt:20-29` — Show "Discarded · Undo" in the notification for 5 s, then delete.
- P-007 — The no-audio card's Delete acts in one tap, while the ⋮ menu's Delete asks first — `ui/Screens.kt:450-470` — Use the same ConfirmDialog, or "Recording deleted · Undo".
- P-008 — Clear history erases the dictations kept on this phone in one tap — `ui/DictationScreens.kt:316-321` — Confirm or Undo.
- P-009 — Remove key deletes the Free plan's key in one tap, and transcription stops — `ui/PlanUi.kt:624-625` — A confirm that says transcription stops until a new key is added.

### Batch 3: no diagnostics, engineering or raw error text (RC-P1, RC-P2), 7 rows
This batch runs alongside P-001.
- P-018 — Settings › Help shows everyone "Audio probe", with package names, "uid 10133", "state unknown (none)" and "Copy findings". On the Play build (no capture helper) it and Capture test lead nowhere — `ui/LiveTranslateSettings.kt:531-551`; `ui/AudioProbeSheet.kt:103-125,165-177` — Put the probe behind a support switch or in debug builds only. Keep Copy diagnostics.
- P-019 — Settings › Games has a "Show speed details … for testing" HUD switch — `ui/LiveTranslateSettings.kt:462-464` — Remove it from the page.
- P-020 — Setup shows "Capture helper is running ({source}, uid {n})" and the raw last capture problem — `ui/Screens.kt:852-875` — Show "Call capture is on." and the problem in plain words. The uid goes only into the diagnostics.
- P-021 — About › This phone shows the server address, "Server certificate pinned (2 pins)" and a second red Sign out — `ui/SettingsScreens.kt:842-875` — Show the phone's name and Setup guide only.
- P-022 — Microphone & audio says "Near-end microphone source", "echo-cancelled", "One UI gives … silence" and "kbps per side (Opus, 48 kHz)", and each Change cycles through hidden values — `ui/SettingsScreens.kt:700-733` — Use value rows that open their few choices, with no codec or rate text.
- P-023 — Storage shows the server's disk ("Server disk N% used · X free") next to the plan's storage in Plan & usage — `ui/SecurityScreens.kt:150-191` — Show the plan's storage meter and how long recordings are kept.
- P-024 — Errors show raw exception text:
  - 35 `it.message` / `e.message` messages in the view model (offline, OkHttp's "Unable to resolve host …").
  - "The capture helper did not answer ({exception})".
  - "Did not work: {exception}".
  - "Will retry: {lastError}".

  Locations: `ui/MainViewModel.kt:677,941,1089,1259`; `translate/TranslateAudio.kt:40`; `translate/AudioProbe.kt:102`; `ui/Screens.kt:1201`; `ui/RecordScreen.kt:376-378,393`. Fix: one mapper from exception or error_code to plain words. Raw text appears only in Copy diagnostics.

### Batch 4: large text and fixed sizes (RC-05, RC-P8), 4 rows
- P-010 — At 200 % text, Settings titles break inside words ("Microph / one & …", "Langua / ge"). The value keeps up to 160 dp, and the title gets what is left — `ui/design/Components.kt:506-528` (`phone-settings-font200-*`) — When the title can't keep whole words, show the value under it, as Android's own Settings do.
- P-011 — From the code: at 200 % text the bottom-bar labels "Settings" and "Projects" (and "Activity" at 360 dp) are cut mid-word. They are one line with no ellipsis — `ui/design/Navigation.kt:109-141` — Add an ellipsis and cap the label's text scale at about 1.5×.
- P-012 — From the code: the New sheet's tiles are a fixed 112 dp, so at 200 % text the status line is cut off — `ui/RecordScreen.kt:194-219` — Use `heightIn(min = 112.dp)`.
- P-013 — From the code: Shared voice notes doesn't scroll, so with many notes or large text the lower ones can't be reached — `ui/SharedNotesScreen.kt:96-139` — A scrolling list under EchoTopBar.

### Batch 5: dark-mode contrast and right to left (RC-03, RC-P7), 2 rows
- P-014 — In dark mode, stock text buttons, dialog buttons and focused field labels use #3E63DD. That gives 3.6:1 on the page, 3.4:1 on cards and 3.0:1 in dialogs. Affected: "Re-transcribe as…", "Allow", "Copy diagnostics", Cancel and Import — `ui/Theme.kt:161-165` (88 `TextButton(` calls) — Map `primary` to #9EB1FF with a dark `onPrimary` in the dark scheme. Fills stay on accentStrong.
- P-015 — From the code: with the phone in Urdu or Arabic, the scrubber fills from the right but taps and drags read x from the left, so seeking goes the wrong way — `ui/RecordingPlayer.kt:243-275` — Mirror x in RTL.

### Batch 6: flows and words that lead the wrong way (RC-P4, RC-P3, RC-P8), 4 rows
- P-016 — Settings › Calls & meetings has no Auto-record calls switch. It only says "Auto-record is off", and search for "auto record" lands there. The switch exists only on Record — `ui/CaptureScreens.kt:291-298`; `core/SettingsIndex.kt:28,60-66` — Add the same row to the top of the page, indexed in search.
- P-017 — The Ready check shows its first problem twice: once as the headline and again as the row — `core/Readiness.kt:82-88`; `ui/ReadyCheck.kt:150-152` (`phone-activity-missed-fix-*`) — Use a general headline ("Calls are not being recorded") or none.
- P-025 — The text names places that don't exist: "You → Transcription key", "Settings → Devices" for the helper, "Calls & meetings → Microphone → Set up". It also gives the console path as "Profile → Devices" in some places and "Account → Devices" in others — `ui/Screens.kt:1161`; `ui/OnboardingScreen.kt:188`; `ui/AudioProbeSheet.kt:103`; `translate/LiveTranslateService.kt:211`; `ui/QrScanner.kt:158`; `ui/SignInScreen.kt:406`; `ui/MainViewModel.kt:1072,1634` — Use the real names, or a link that opens the page.
- P-026 — System Back on "Waiting for you" closes the app. Only an open recording and Setup handle Back. The Record, Game translate and Watch translate pages close the app too — `MainActivity.kt:460-463,635`; `ui/WaitingScreen.kt:97-118` — Handle Back like Setup does. The Record pages go back to the previous tab.

### Already fixed and merged
- P-027 (S2) — The action row squeezed "Send to WhatsApp" into a one-letter column at 360 dp and 200 % text — `ui/TranscriptTools.kt:155-206` — The buttons now wrap with their labels. `phone-session-actions-360-*` and `-text200-*` show it.

## (c) Root causes

Sorted by how many rows one fix closes. "Fix now" counts open rows only. Where a desktop root cause from RC-01 … RC-08 fits, I reused it. New phone root causes are RC-P1 … RC-P9.

| ID | Root cause | Rows | Fix now | Phase |
|---|---|---|---|---|
| RC-P3 | Text written inside the Kotlin files, with no copy deck. One action has several names, lines point to places that don't exist, explanations run long, and the text stays English under a mirrored layout (like desktop RC-11 / RC-13) | 13 | 2 | now (P-017, P-025), then the v2 copy deck |
| RC-05 | Fixed dp sizes and raw sp values instead of the tokens (179 raw `fontSize`). Nothing is checked at 200 % text or 360 dp (desktop RC-05 plus RC-10) | 10 | 3 (+1 fixed) | now (clipping), then v2 tokens |
| RC-P1 | Diagnostics, operator and engineering details in user screens: probe, HUD, uid, pinning, codec, server disk, USD provider costs, package names (like desktop RC-09) | 10 | 6 | now |
| RC-02 | No shared confirm or Undo for destructive actions. Discards, deletes and revokes act at once (desktop RC-02, minus its `window.confirm` part) | 9 | 8 | now |
| RC-P8 | No shared page frame or navigation rules: pages draw their own headers, some don't scroll, Back isn't handled, actions are repeated, and status shows when all is fine (like desktop RC-12 / RC-15) | 6 | 2 | now (P-013, P-026), then a v2 component |
| RC-P5 | Stock Material and one-off controls instead of the design components: `TextButton`, `OutlinedButton`, `FilterChip`, `SegmentedButton`, "●/○" typed as text, a typed time (like desktop RC-14) | 5 | 0 | v2 component |
| RC-P4 | Settings pages are hand-built from cards instead of registry rows, and the search index is a hand-kept list that drifts from the pages | 4 | 1 | now (P-016), then the v2 registry |
| RC-P6 | Floating windows build their own controls: 40–44 dp targets, × for discard, a cycling chip, three hide buttons, an unexplained "!" (like desktop RC-20) | 3 | 0 | v2 component |
| RC-P7 | No RTL-safe building blocks: gestures read raw x, and some icons don't mirror (like desktop RC-17) | 2 | 1 | now (P-015), then v2 |
| RC-01 | A control's look or text doesn't match what it does ("Tap to retry" opens the page; filter chips look like toggles) | 2 | 0 | v2 component |
| RC-04 | No single date and time formatter or date grouping | 2 | 0 | v2 component |
| RC-06 | Mixed icon sets, and tinted tiles on every row | 2 | 0 | v2 component |
| RC-07 | No shared empty, loading and error state | 2 | 0 | v2 component |
| RC-P9 | The same value worked out in two places (the title after a rename, the name for an untitled recording) (like desktop RC-23) | 2 | 0 | v2 |
| RC-03 | One style role used for two jobs: `colorScheme.primary` is the button fill and also every stock text button's text colour. This is the phone's version of the desktop's style collisions | 1 | 1 | now |
| RC-P2 | No layer that turns errors into words, so raw exception and server text reach messages and notifications | 1 | 1 | now |
| RC-08 | Vendor and model names in UI strings | P-001 | — | in the working tree since 02:28 |

## (d) Judgment calls

- **S0: P-002 and P-003.** Each deletes the only copy of a recording at once, through something that reads as something else. A long-press selects everywhere else, and "×" reads as close. The desktop's D-105 set this precedent.
- **S1: P-004 and P-005.**
  - P-004's Discard sits beside Retry on an error, so it reads like "dismiss", yet it deletes a recording that was never uploaded.
  - P-005 matches the desktop's D-072 (Revoke with no way back).
- **S2, fixed now: the other one-tap destructive actions (P-006 to P-009).** All four follow the brief's rule for one-click destructive actions:
  - P-007 has a 48-hour bin but still deletes in one click.
  - P-006 answers an explicit "Save this recording?".
- **Kept in the backlog: P-075 (S3).** The smaller removals (a filing rule, a Shared with me item, a dictation snippet, a memory line) can be re-made in seconds. Batch 2's Undo covers them later.
- **Internal text, fixed now:** the probe, the HUD, the uid, certificate pinning, codec details, server disk and raw exceptions. These match the desktop's D-013 and D-052.
- **Internal text, backlog:**
  - USD provider costs (P-057). Who sees spend is a v2 product decision.
  - "API x.y" and sp/dp units (P-058).
  - Setup's developer steps (P-059). Today they are the only way to turn on call capture in the website build.
  - Package-name fields (P-055).
- **P-014 counts as unreadable contrast.** 3.0–3.6:1 is below the design system's own 4.5:1 floor, and the fix is one theme line. Light mode passes at about 5:1. The app's own text buttons (`EchoButton` text kind, #9EB1FF) are fine.
- **Raised to S2, fixed now:**
  - P-017. The Ready check is how people fix call recording, and a repeated sentence there looks broken. The fix is one line. The desktop's D-031 set this precedent.
  - P-016. The product's main setting is missing exactly where Settings and search send you.
  - P-026. Back closes the app from a sub-page.
- **Code-only S2s (P-011, P-012, P-013, P-015).** I worked these out from sizes and code: Inter at 22 sp against a 79 dp slot, 140 dp of content in a 112 dp tile, a Column with no scroll, raw pointer x. Each fix adds the screenshot that proves it. If a screenshot shows one is fine, close it.
- **Kept S3:**
  - Search gaps (P-053). Every page is still reachable, and P-016 covers the switch that matters.
  - Library titles at 200 % (P-028). They are cut with an ellipsis, not broken.
- **P-001.** The vendor-name change has been in the working tree since 02:28 (`core/VendorNames.kt`).
  - I found no vendor or model name it misses in phone strings.
  - The Free plan's Groq key steps remain as the allowed exception.
  - "Roman Urdu is made by Claude." appears only in the debug-only Design gallery (`src/debug`).
  - The session screenshots were re-taken at 02:28 and now show a plain "Final transcript". The new wording "Backup transcription — Re-transcribe?" is in P-067 as an S4 label.
- **Checked and not logged:**
  - The record button stays its own colour when Wallpaper colours is on (`Tokens.kt:85-88`, `Theme.kt:136-158`). It is brand indigo at rest and red only while recording, as DESIGN_SYSTEM.md says. If Hassam means red at rest, that is one token (`recordFill`).
  - Wallpaper colours tint Upgrade. That is by design: the wallpaper replaces only the accent and the neutrals.
  - Live Translate's Urdu line is left-aligned in `phone-translate-hidden-*` because the fixture passes "UR" and `TranslateLanguages.rtl()` expects "ur". Live sessions pass lowercase codes.
  - The edge-to-edge import dialog is a stock `AlertDialog` drawn by Robolectric.
  - Back on a top-level tab closes the app, which is the Android convention.
  - The bottom bar hides during Library selection, which is the standard pattern.
  - Notifications are private on the lock screen.
- **Desktop parity.** The phone has no native confirm dialogs, so only the "no Undo" half of the desktop's RC-02 applies.
