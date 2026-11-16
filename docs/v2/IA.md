# EchoLedger information architecture (v2.0)

**Status: draft.** This is the feature tree from the v2.0 plan. Phase V1 completes it (the full feature → settings home →
control table for every feature in audit/features.csv) and Gate 2 approves it. Until v2.0's flags turn on, the apps keep
today's navigation.

## Level rules
- Level 1 is primary navigation. Level 2 is sub-navigation (tabs or sidebar sections). Level 3 is a section or panel
  inside a page, never another menu level.
- Plain names: "Recordings", "Ask", "Live".
- Every feature has exactly one settings home. Controls elsewhere link to it with "More settings".
- A feature a platform can't do is shown disabled with the reason ("Phone call recording is available on Android"),
  never hidden.
- Toggles take effect immediately; when a Save button is needed, use a checkbox. A toggle's label names what it controls
  ("Auto-transcribe after calls"), never just "On/Off".

## The tree (the same on every platform)

1 **Home**
- 1.1 Get started checklist until everything is done: capture helper / permissions ready, a test recording,
  transcription language.
- 1.2 Status strip: "Ready to record" / "Needs setup" / "Recording 02:14".
- 1.3 Needs action: untranscribed, failed, Live sessions scheduled today.
- 1.4 Recent recordings (the last 5).
- 1.5 Usage meter (minutes, storage) → Plan. Home is not a dashboard of charts.

2 **Library**
- 2.1 All recordings (default view): rows with source icon · caller or meeting name · duration · transcript snippet ·
  time · status; search + 3 quick filters (Source, Date, Status) + a Filters panel, applied filters as removable chips;
  saved views Today · Untranscribed · Starred · Voice notes; select many → Transcribe · Move to workspace · Delete (undo).
- 2.2 Workspaces (projects with memory): tabs Recordings · Ask · Memory & instructions.
- 2.3 Trash: restore for 30 days.
- The recording page opens from any row: tabs Transcript · Summary · Ask · Moments · Details; actions Play · Share
  (WhatsApp, link, file) · Transcribe · Move · Delete.

3 **Live** (everything during a call or session)
- 3.1 Record: sources Phone & WhatsApp calls (Android) · Meetings & apps (desktop: Meet, Zoom, Teams, Slack, any site) ·
  Voice note · Microphone only; modes Arm before the call · Record now.
- 3.2 Live Assist: Upcoming · New session (prep) · Past reports · Practice.
- 3.3 Live Translate. 3.4 Dictation. 3.5 Captions (also a toggle inside any live session).

4 **Ask**
- 4.1 New question with a scope picker: All recordings · A workspace · One recording.
- 4.2 Ask history. Ask inside a recording or workspace is the same component with the scope set.

5 **Settings** (searchable; the same groups everywhere)
- 5.1 Recording · 5.2 Transcription & language · 5.3 Live & overlays · 5.4 Privacy & data · 5.5 Notifications ·
  5.6 Appearance · 5.7 Account & plan · 5.8 Setup & health · 5.9 Shortcuts (desktop) · About & help.

**Admin** (web only, /admin): Users · Plans & limits · Usage · API credentials (masked, rotate) · Results matrices ·
Feature flags & rollout · Audit log.

## Where each feature lives (key rows; V1.1 completes the table)

| Feature | Settings home | On/off | Also reachable from |
|---|---|---|---|
| WhatsApp / WA Business / SIM recording (Android) | Settings › Recording › Auto-record sources | One switch per source | Live › Record; Home status strip |
| Meet / Zoom / Teams / Slack / any site (desktop) | Settings › Recording › Auto-record sources | One switch per source | Tray / menu bar "Arm"; Live › Record |
| Capture helper (Shizuku/ADB) | Settings › Setup & health | Wizard | Home checklist; banner on Record |
| Live captions | Settings › Live & overlays | Switch + shortcut | "CC" in the live toolbar |
| Screen moments | Settings › Live & overlays | Switch + shortcut | "Mark moment" on the pill |
| Private overlay | Settings › Live & overlays | Switch + Share Check | Badge on each overlay |
| Live Translate, Dictation, Live Assist | Live › … | Start/stop, schedule | Tray / menu bar; Home "Upcoming" |
| After-call card | Settings › Recording › After-call action | A choice | After every call |
| Themes | Settings › Appearance | System / Light / Dark | — |
| Plan, usage, storage | Settings › Account & plan | — | Home usage meter |

## Navigation per platform

| Platform | Primary navigation | Search | Settings |
|---|---|---|---|
| Web console | Left sidebar: Home, Library, Live, Ask, Settings (collapsible) | Top bar + Ctrl/Cmd+K | Settings page, group list + search |
| Windows | The same sidebar; the tray popover is a remote control | Ctrl+K | Same as web |
| macOS | The same sidebar (split view); the menu-bar extra is a remote control; standard app menus | Cmd+K | Settings window (Cmd+,) |
| Android | Bottom bar: Home, Library, Live, Ask; Settings from the avatar | Search field in Library and Ask | Full-screen list + search |
| iPhone | Tab bar: Home, Library, Live, Ask; Settings from the profile | Searchable lists | List + search |

- The tray / menu-bar popover has at most 7 items: status line, Arm/Stop, the last 3 recordings, Open EchoLedger,
  Settings, Quit.
- One recording pill, one captions window, one Live Assist panel: draggable, snapping to edges, position remembered per
  display, covered by the hide-all shortcut.
- The command palette lists every command and setting by name; it is a shortcut, never the only way.
