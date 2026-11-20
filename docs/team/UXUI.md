# EL-UX: v2 screens and flows, to 100% parity

**Mission:** build the new design's screens and flows on phone, desktop and web until every feature classic has is there, reachable in at most 2 taps, organised calmly, tested and shown side by side. You are the biggest builder. Work in parallel across the three platforms.

## Read first
docs/team/README.md, PROTOCOL.md, this file, docs/PLAN_V2_PARITY.md, docs/PLAN_V2_0.md (tree, navigation, components), docs/v2/IA.md, docs/v2/audit/parity.csv, docs/API_CONTRACT*, ~/EchoLedgerTeam/BOARD.md, your status file.

## You own (write)
v2 screen and flow code on phone, desktop (React views) and web front end (paths on the board), plus the parity.csv rows you close.

## You never touch
- Shared components and tokens. Ask EL-Design: if you need a new component, send EL-Design a request and use a temporary local version marked TODO-DS.
- Server code and migrations (EL-Backend). Work from the API contract, and ask EL-Backend for missing fields.
- Classic screens (EL-Fix).
- Shared core code without a lock from EL-Lead.

## Your helper agents (up to 3 at once)
- **ux-phone:** Kotlin/Compose screens.
- **ux-desktop:** React views in the Tauri app.
- **ux-web:** Laravel/Filament front-end views and JS.
Brief each one with the task IDs, the parity rows, the files it may edit, and its "done when".

## How to build each screen
1. **Start from parity.csv:** list every classic row for the screen, and read the classic code. Never work from memory.
2. **Use the patterns:** long-press = right-click = the same menu. Show the 3–4 most used actions; put the rest in ⋮ with group headers (Organise, Export, Danger). Export is a sub-menu, Delete is last and red.
3. **Use only EL-Design components and tokens,** with no hard-coded colours or sizes. Use real copy (en/ur/ar) from the copy deck.
4. **Bring the tests:** an end-to-end test for every parity row, and screenshot tests (light/dark, EN/UR, the sizes in PROTOCOL.md §6).
5. **Write side-by-side screenshots** (classic | v2) to docs/v2/design-review/<id>/, then send READY to EL-Lead and EL-Design.
6. **Mark parity rows "done"** only with a passing test name.

## Starting backlog (order = priority; EL-Lead gives the IDs)
**Phone (about 150 features left, the biggest gap):**
- **U1** Finish the Library review fixes:
  - Undo after deleting many restores only what was deleted;
  - Undo appears only after the delete succeeds;
  - the design choice is handled correctly on a server error;
  - one name for Trash;
  - tests and screenshots.
- **U2** The recording page's last 4 buttons: capture test, check your key, open at a search match, back to the source app.
- **U3** The bottom bar: Library · Projects · ● Record · Live · Ask.
  - Tap Record = voice note; press and hold = call recording, Live Assist, Dictation, Live Translate.
  - Library header: "Calls: Ready" chip, bell with badge (Activity), avatar (Settings).
  - Home's checklist becomes a card at the top of the Library.
- **U4** Settings: every classic setting in 9 groups with icons, descriptions and search. Appearance: theme, wallpaper colours, phone font (from EL-Design D1), text size, language, design switch.
- **U5** The Activity screen, then every remaining phone parity row (rare options last).
**Desktop (128 left, after EL-Fix's urgent bug fixes land):**
- **U6** The rest of the Library and recording page, Record, Activity, Projects, the top bar, onboarding and import, then rare options.
**Web (42 left, front-end parts):**
- **U7** Library: group by, List/Table with columns, the This week / Waiting / Failed uploads views, device/status/pin on rows.
- **U8** Keyboard and sidebar: J/K/Enter/X/Esc keys, ● Record / Upload at the top, the + create menu, breadcrumbs, sidebar resize, My links / Request links.
- **U9** Selection: Transcribe all, Copy for AI, Mark important (needs EL-Backend's B1 fields).
**At the end:**
- **U10** With EL-Design: the final side-by-side pack of every screen.

## Done when (each task)
Every parity row for the screen is "done", with a test. The screenshots are approved by EL-Design (VISUAL-OK). The reviewer gives REVIEW-OK. CI is green. It also works on a small phone, at large text, in split screen and in Urdu.
