# Desktop UI sweep: what to fix now (2026-11-16)

Triage of docs/UI_SWEEP_DESKTOP.md and the HANDOFF list "UI BUG LIST from Hassam's Mac screenshots" into
[bugs.csv](bugs.csv) (D-001 … D-125), using the severity scale in PLAN_V2_0.md and the Stage A rule in ROADMAP.md.
Line numbers are the sweep's (v1.1 before the batch in review). In files that batch touched they may have moved a few
lines.

## (a) Counts

| Severity | Rows | Fix now, open | Fixed in review | v2 backlog |
|---|---|---|---|---|
| S0 Blocker | 1 | 1 | 0 | 0 |
| S1 Critical | 2 | 1 | 1 | 0 |
| S2 Major | 35 | 27 | 6 | 2 |
| S3 Minor | 74 | 0 | 3 | 71 |
| S4 Polish | 13 | 0 | 0 | 13 |
| **Total** | **125** | **29** | **10** | **86** |

- Fix now: 39 rows. 29 are open, and 10 are already in the batch in review (9 in c2cd2044, 1 in 661df909).
- v2 backlog: 86 rows (fix_now = no). Two of them are S2 (D-108, D-114), and PLAN_V2_0 says they are fixed before
  their module ships.
- Where the rows come from: the sweep's 98 findings (2 blockers, 43 majors, 47 minors, the untagged menu bar item and
  the 5 "already known" items). 21 combined findings became 45 rows. One remark was merged (dark mode → D-008). Two
  rows cover places that only a rule names (D-122, D-123). Of Hassam's 6 items, 4 were merged into sweep rows
  (D-003, D-086, D-113, D-119) and 2 are new (D-124, D-125).
- By module: Library 19, Recording page 19, Settings 14, Shell 13, Home 10, Floating windows 9, Cross-cutting 8,
  Projects 8, Shared 6, Activity 5, Help 5, Plan 5, Sign-in 3, Upload 1.
- By platform: 123 desktop, 2 desktop-mac (D-113, D-120).

## (b) Fix now: 29 open rows in 6 builder batches

Do Batch 1 first. D-105 is S0, and PLAN_V2_0 says an S0 is fixed before any release, so before v1.8.1 ships.
Batches 1–4 apply the cross-cutting rules. Batches 5–6 go page by page.

Keep each fix minimal, because v2.0 rebuilds these screens. The only new component is the shared confirm dialog in
Batch 1. For every screen it touches, each batch adds screenshots at 860×560 and at full size, in light and dark, for
the review.

Paths are relative to `desktop/`.

### Batch 1: destructive actions get a confirm or an Undo (rule 2), 7 rows
Build one shared confirm dialog (a short question, one line of what happens, a danger button) and use it everywhere
below.
- D-105 (S0) — The after-call chip's Discard is an "×" that people read as close, but one click deletes the recording's files at once, with no confirm and no Undo — `src/views/review/ReviewApp.tsx:238`; `src-tauri/src/pending.rs:421-432` — Use a trash icon labelled "Discard" and an Undo toast. Delete the files only after the Undo window, as Keep's "Saved · Undo" already does.
- D-072 (S1) — Revoke on share links and request links acts at once, with no confirm and no Undo. The link stops working for everyone who has it — `src/views/main/SharedView.tsx:55-72` — Ask in the app dialog ("Revoke this link? It stops working for everyone who has it."), or show an Undo toast.
- D-045 — Delete on the recording page uses the browser's `window.confirm`, with the wrong look and title — `src/views/main/LibraryView.tsx:984` — Use the app's confirm dialog.
- D-085 — Sign out from the avatar menu uses `window.confirm`, while Sign out in Settings uses the app dialog — `src/views/main/MainApp.tsx:626`; `src/views/main/SettingsView.tsx:417,541` — Use the app dialog in both places.
- D-093 — "Reset {page} to defaults?" uses `window.confirm` — `src/views/main/SettingsControls.tsx:240` — Use the app's confirm dialog.
- D-101 — The upgrade flow's "Close without sending?" and "Remove your Groq key?" use `window.confirm` — `src/views/main/UpgradeFlow.tsx:100`; `src/views/main/Upgrade.tsx:520` — Use the app's confirm dialog.
- D-122 — `window.confirm` is used in 12 more places: erase from bin, discard on Home, discard waiting recordings (4), remove a project file, rewrite a brief, sign out a device, clear dictations, delete a screen, restore the original transcript — in `src/views/main/`: `LibraryOrganise.tsx:385`; `RecordView.tsx:113`; `LibraryQueue.tsx:168,193,257,263`; `ClientWorkspace.tsx:409,727`; `SecuritySettings.tsx:226`; `DictationSettings.tsx:136`; `LibraryView.tsx:682`; `TranscriptTools.tsx:253` — Use the app dialog everywhere. Done when no `window.confirm` is left in `src/`.

### Batch 2: no internal, debug or vendor text (rule 5), 7 rows
- D-013 — Debug text shows while recording: "Windows process loopback (EchoLedger excluded)", "macOS Core Audio tap", "Simulated source" — `src/views/main/RecordView.tsx:93-98,152` — Remove the line.
- D-014 — Home lists the processes using the microphone ("Holds mic ✓ · Playing audio ✗ …") and shows a second auto-record switch with a different name from Settings — `src/views/main/RecordView.tsx:36-64` — Remove the card from Home. The setting lives only in Settings.
- D-052 — Raw status text shows, e.g. "Transcript not_started" — `src/views/main/LibraryView.tsx:1569` — Map each status to plain words.
- D-040 — "اردو view by claude-sonnet" shows a model name — `src/views/main/LibraryView.tsx:1485` — Drop "by {model}".
- D-041 — "AssemblyAI (universal-2)" is shown twice, and the second wraps so a lone "2" sits on its own line — `src/views/main/LibraryView.tsx:1361,1447`; `src/styles.css:707` — Show one line, "Transcribed in English", with no provider or model.
- D-044 — Upsell chips name vendors: "ElevenLabs · Gemini", "Ask Claude or ChatGPT" — `src/views/main/LibraryView.tsx:1390,1460-1462,1478-1479` — Name the feature, not the vendor. The Groq key setup on Free stays, as the rule's one exception.
- D-062 — Project copy says "Claude writes call notes…" and "Claude reads them for the brief…" — `src/views/main/ClientWorkspace.tsx:325,740` — Say "EchoLedger".

### Batch 3: controls do what they show (rule 1), 3 rows
- D-121 — Dropdowns and collapsible sections only open from their small arrow (workspace switcher, Shared, the sidebar's Library arrow, selects) — `src/views/main/Sidebar.tsx:91-96`; `src/views/main/TopBar.tsx` — Make the whole control the click target, with a pointer cursor, a hover state and a visible focus ring.
- D-007 — The workspace switcher lists workspaces you can pick, but picking one only opens the browser — `src/views/main/TopBar.tsx:81-82` — Show the other workspaces as plain text, with one "Switch or manage in the browser" row.
- D-090 — "Show in screen share for 10 min" and "Copy diagnostics" look like plain text — `src/lib/settingsRegistry.ts:212,219`; `src/views/main/PrivacySettings.tsx` — Make them visibly clickable (blue text or a chevron).

### Batch 4: Help and shortcuts tell the truth, 4 rows
- D-047 — The player's tooltips name the wrong keys ("Back 10 s (J)", "Forward 10 s (L)", "Space or K plays"). J and K move through the list, and L does nothing — `src/views/main/TranscriptTools.tsx:30,83,87`; `src/lib/shell.ts:145-150` — Take the tooltips from the shortcut list: Shift+←/→ for 10 s, Space plays.
- D-078 — Help sends people to "Account → Preferences" and to "the console under Help", and neither place exists — `src/views/main/HelpDialog.tsx:54` — Point to Settings › Keyboard shortcuts and link the user guide.
- D-125 — EchoLedger is hidden from screenshots by design, and Help doesn't say how to get a screenshot or help — `src/views/main/HelpDialog.tsx:54` — Add one line: use "Show in screen share for 10 min" before a screenshot, and "Copy diagnostics" for support. This depends on D-090.
- D-079 — The Help dialog's bottom is cut off at the window edge, and it has no close button at the top — `src/views/main/HelpDialog.tsx` — Cap the dialog at the window height with its own scroll, and add × at the top.

### Batch 5: Library and recording page, clipped and overflowing layout, 5 rows
- D-023 — On a short list, the smart-folder column stretches and its rows end up about 130 px apart — `src/styles.css:1297-1303` — Add `align-content: start` on `.subnav` and `.place-list`.
- D-024 — The "Drag recordings onto…" hint is cut mid-line in every Library screenshot — `src/styles.css:1300`; `src/views/main/SecondaryColumn.tsx:78` — Remove or shorten the hint (the inner scroll itself is D-025, in the backlog).
- D-029 — The search placeholder is cut to "…and transc" at normal width — `src/views/main/LibraryView.tsx:1111` — Change the placeholder to "Search".
- D-046 — The Language chip is wider than the 360 px detail panel, so the detail scrolls sideways — `src/styles.css:689,691,693` — Let the chip wrap, or put Re-transcribe on its own row.
- D-031 — Untitled rows show a blank title in the list, while the table shows "Recording" — `src/views/main/LibraryView.tsx:1277`; `src/views/main/LibraryTable.tsx:111` — Use the same fallback title in the list.

### Batch 6: Settings, search and the Mac permission sheet, 3 rows
- D-001 — In Settings › Calls & meetings, the app icons sit on top of the app names, because `set-icon app` picks up the window's `.app` grid — `src/styles.css:186`; `src/views/main/CaptureSettings.tsx:75` — Rename the class to `set-icon app-glyph`.
- D-087 — In ⌘K, "new" ranks "Record a voice note" above "New project", so Enter starts a voice note — `src/views/main/CommandPalette.tsx:87` — Rank name matches first and drop loose keyword hits.
- D-120 — On a Mac, the permission sheet says "Not asked yet" when an older copy of EchoLedger holds the permission — `src/views/main/Permissions.tsx:48-52` — Say so ("An older copy of EchoLedger has this permission: remove it in System Settings, then Allow"), with the link to the right pane.

### Already in the batch in review (10 rows, no new work)
- 661df909: D-113, the menu bar menu over full-screen apps (S1).
- c2cd2044:
  - D-003: the `.searchbox` clipping. Review: the base rule's `min-width: 220px` was still there.
  - D-004: the two searches became one (⌘K).
  - D-011: "Find a page" replaced the sidebar menu.
  - D-124: "Find a page" found only pages.
  - D-082: the Settings column was too narrow.
  - D-086: "Closest matches" showed unrelated rows.
  - D-117: two banners were stacked.
  - D-118: the usage bar was empty on Ultra.
  - D-119: the "Recovered" chip.

## (c) Root causes

The table is sorted by how many bugs one fix closes. "Fix now" counts open rows only.

| ID | Root cause | Bugs | Fix now | Phase |
|---|---|---|---|---|
| RC-13 | Wordy copy: paragraphs on cards, subtitles, filler lines, repeated facts, tooltips written as sentences | 12 | 0 | v2 component (V1 copy deck) |
| RC-10 | Layout not built for real sizes: min-widths wider than their container, nested scroll areas, grid stretch, fixed-width chips, dialogs taller than the window, no automatic rail, no 860×560 check | 11 | 5 | now (clipping), then v2 component |
| RC-12 | Duplicate entry points: the same thing in two or three places (search, Help, projects, shared with me, Re-transcribe, Density, Check for updates) | 10 | 0 (3 in review) | v2 component (V1 IA tree) |
| RC-07 | No shared list row, table or state components (empty, loading, failed, untitled, selection, sort) | 9 | 1 | v2 component |
| RC-14 | One-off and native form controls: a select used as a menu, checkboxes beside switches, two picker styles, loose action rows, one-off forms | 9 | 0 | v2 component |
| RC-02 | `window.confirm` instead of the app's dialog, and no shared confirm or Undo for destructive actions | 7 | 7 | now |
| RC-05 | Fixed px sizes and one-off values, with no token scale (type, control heights, colours, inline styles) | 7 | 0 | v2 token |
| RC-11 | No single name or style for each action and thing (no copy deck) | 7 | 0 | v2 component (V1 copy deck) |
| RC-15 | No shared page frame (width, right edge, breadcrumbs, subtitles, page actions) | 7 | 0 | v2 component |
| RC-01 | No shared dropdown, disclosure or row component: the whole control isn't clickable, and what a control looks like doesn't match what it does | 6 | 3 | now, then v2 component |
| RC-20 | No shared chrome for floating windows (close, move, dismiss, overflow menu, labels) | 6 | 0 | v2 component |
| RC-08 | Vendor and model names in UI strings | 4 | 4 | now |
| RC-17 | No RTL-safe text building blocks: physical CSS properties, no `dir="auto"`, no `<bdi>` | 4 | 0 | v2 token + v2 component |
| RC-18 | Shortcut and help text written by hand instead of coming from the shortcut list | 4 | 3 | now, then v2 component |
| RC-03 | Generic CSS class names (`.app`, `.nav-count`, `.row`, `.nav-row`) collide across components | 3 | 1 | now, then v2 component |
| RC-06 | Mixed icon sets and dropdown arrows | 3 | 0 | v2 component |
| RC-09 | Internal values shown as user text (backend ids, enum states, process names) | 3 | 3 | now |
| RC-19 | Search ranks loose matches above name matches | 3 | 1 (1 in review) | now, then v2 component |
| RC-23 | One-off state and count logic | 3 | 0 (2 in review) | now (in review), then v2 component |
| RC-04 | No single date and time formatter or date grouping | 2 | 0 | v2 component |
| RC-16 | No single feedback system for banners and toasts | 2 | 0 (1 in review) | v2 component |
| RC-22 | macOS integration: full-screen Spaces, and the permission state of older copies | 2 | 1 (1 in review) | now |
| RC-21 | No single pattern for locked (paid) features | 1 | 0 | v2 component |

## (d) Judgment calls

- **The review's two blockers are S2, fixed now.** D-001 (icons over names) and D-023 (folders 130 px apart) are
  broken layouts. Neither is data loss, a privacy leak, a crash or a failed recording.
- **Raised to S0 and S1.** In D-105, the "×" reads as close but deletes the recording folder at once
  (`pending.rs:421-432`). In D-072, Revoke is one click with no way back. Both follow the brief's rule that a one-click
  destructive action is data loss.
- **All 17 `window.confirm` calls are S2, fixed now.** That includes the two the review called minor (D-093, D-101).
  It is one defect, the webview's own dialog. One shared dialog fixes all 17, and a partial fix keeps the mix that
  D-085 complains about.
- **Raised from minor to S2, fixed now.**
  - D-031: a blank row title looks broken, and the fix is a one-line fallback.
  - D-090: the two support actions look like plain text, and D-125 sends people to them.
- **Lowered to S3 backlog: D-061 ("4 recordings" vs "2").** The page explains it: the folder chips show their counts,
  and "Include its folders" sits beside the tab.
- **Kept S2 but in the backlog.**
  - D-108: the dictation box hides after 4 s and says where to go.
  - D-114: 200 % text needs the v2 token system, and nothing is unreadable today. The smallest text is 10–11 px, in
    counts and key chips.
- **Clipping.** Hard-cut text is fixed now (D-003, D-024, D-029). An ellipsis in a closed picker (D-038) goes to the
  backlog.
- **D-087 is S2, fixed now.** After c2cd2044, ⌘K is the app's only search, so Enter on its top hit has to be right.
- **D-088 corrects the sweep.** "Account & security" under App › Notifications is the notification type with that
  name (`settingsRegistry.ts:72`), not a misfiled page. It is now an S3 naming row.
- **Splits and merges.**
  - Findings were split where the severity, fix or root cause differs.
  - Hassam's items 2–5 were merged, and their source shows both.
  - The dark-mode remark was merged into D-008.
  - The Density finding's "no text-size option" was merged into D-114.
  - Rules 1, 3–9, 11 and 12 are fully covered by finding rows. Rule 2 adds D-122 and rule 10 adds D-123, for the
    places that no finding names.
- **Fixed in review.**
  - Commits: 661df909 is the menu bar fix (ROADMAP). Everything else on the list of fixes in review is c2cd2044. These
    commits were not checked with git.
  - D-011 is marked fixed because its fix is the single search.
  - Still open in the same areas: D-083 (the Settings list still scrolls on its own), D-087 and D-088.
  - When the batch merges, replace "fixed-in-review (…)" with the merge commit.
- **Module and screenshot.** Each bug is filed where it shows (D-001 under Settings, not Shell). App-wide items
  (icons, dates, breadcrumbs, text size, RTL, dropdowns) are under Cross-cutting, and ⌘K is under Shell. The
  screenshot column is filled only where the sweep names one.
