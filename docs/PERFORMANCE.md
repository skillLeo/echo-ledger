# Performance

The budgets the apps and the server are held to, how they were measured, and what was measured for v1.1. To repeat
the device measurements, use [MANUAL_QA.md](MANUAL_QA.md) P-1 (desktop idle) and P-2 (recording with captions).

## Budgets

| Where | Budget |
|---|---|
| Desktop, idle | At most 150 MB private working set and under 1 % CPU, summed over the app and its web views |
| Desktop, recording with live captions | Under 8 % CPU for all app processes together |
| Web console | Under 300 ms server time per page; lists paginated, no N+1 queries |
| Phone | No wake locks; no periodic work beyond the 15-minute activity check |

## Results (v1.1)

- **Desktop idle** (release build `npx tauri build --no-bundle`, fresh unpaired data with `%APPDATA%\com.echoledger.desktop` moved aside and restored unchanged after each run, `--autostart`, 30 s settle + 60 s sample, private working set as in Task Manager, summed over the app and every `msedgewebview2.exe` in its process tree; method: docs/MANUAL_QA.md P-1). Budgets ≤ 150 MB and < 1 % CPU: **met**.
  | State | Processes | Private working set | CPU (of 12 cores) |
  |---|---|---|---|
  | Before C9 (5 windows built at start) | 11 | 310 MB | 0.03 % |
  | After: idle, only the dictation bar kept ready | 7 | **96 MB** | **0.04 %** |
  | After a dictation and opening + closing the main window | 8 | 126 MB | 0.22 % |
  | No window at all (dictation shortcut unbound) | 1 | 3-5 MB | 0.01-0.02 % |
  - What the 96 MB is: WebView2 browser ~35 MB, GPU ~27 MB, the dictation bar's renderer ~15-18 MB, utility ~11 MB, the app 4 MB. The browser grows to ~59 MB once the main window has been open (caches), hence 126 MB.
- **How (C9, `desktop/src-tauri/src/lifecycle.rs`)**: nothing is built in the setup hook. Main window `"create": false`, built from its tauri.conf.json entry when opened (tray, second launch, notification, navigate), shown when its page is ready (fallback 1.5 s), **destroyed on close**; where the user was (page, open recording, scroll of `.content` / `.list` / `.detail`) is kept in the core's memory for this run (`main_view_get` / `main_view_set`, `lib/mainView.ts`). Overlays (captions, pill, review card + ask chip, dictation) are built on first use and **destroyed 30 s after they are hidden** (`HIDE_GRACE`); a rebuilt one reads corner, size, content protection and pill position from the settings. Events for a window still being built are kept (latest of each kind, in order) and sent when the page calls `window_ready` (after its listeners are attached); captions are replayed by `CaptionHub` (status, last 200 final lines, each channel's partial). No-focus rule unchanged: same builder (`focused(false)`, `focusable(false)`, never `show()`); tao-0.37.2 builds such windows with `WS_EX_NOACTIVATE` and `SW_SHOWNOACTIVATE` (window_state.rs 293 / 461), so one built mid-call does not take focus. Device checks: MANUAL_QA V-D8-V-D11.
- **Open latency** (trigger → page ready = rendered and listening, release build, logged by `lifecycle::ready`): cold, no web view running: captions 1104 ms, pill 990 ms, review 1007 ms, dictation 981 ms. With WebView2 already running (dictation bar kept ready): captions 534 ms, pill 593 ms, review 594 ms, main window 456-695 ms. **Dictation**: building it already starts on the key going down, but ~1 s is not instant, so the **dictation bar is the one window kept warm** (`lifecycle::WARM`, while a dictation shortcut is bound): pressing the shortcut shows it with one event. Overlay latencies were measured with a temporary probe build (not committed) that opened each overlay through `lifecycle::show`, because a real recording needs pairing and pairing a test copy would overwrite the real device token in Windows Credential Manager (see docs/HANDOFF.md, "Gotchas learned").
- **Fixed on the way**: a dictation that could not start (not paired, no network) left the bar on screen for good, drawing at ~3.9 % CPU; it now hides 4 s after showing why (`DictationApp` `ENDED_MESSAGE_MS`, regression test). The Phase 6 screenshot review also fixed: quiet-hours time fields full width (desktop settings), wizard "Skip setup" and "Continue" on separate rows, review-card harness deadline in the past ("Keeping in 0 s").
- Fixed in C9: the hidden main window stopped polling (1.5 s status, 2 s microphone scan); the overlay click-through loop idles at 4 Hz (was 30 Hz) and wakes on a new hit region; detection already runs at 1 Hz.
- **Web** (3,005 recordings with transcripts + 30 clients on SQLite, `php artisan serve` with opcache off, median of 5 warm loads, request start to first byte): dashboard 210 ms, sessions list 231 ms, session page with player 90 ms, clients 100 ms, search 127 ms, usage 86 ms, settings 83 ms (budget < 300 ms: met). Lists are paginated (the members table now too); lazy loading throws outside production and found 3 N+1s (bulk zip export, transcript purge, console player) plus a per-row role query on the sessions list; query-budget tests guard the sessions and clients lists and `/api/library`.
- **Phone**: no wake locks; the widget updates on events only (`updatePeriodMillis="0"`); the only periodic job is the 15-minute activity check decided in B18.
- **Recording with captions (< 8 % CPU)**: needs a real call and a provider key; how to measure: docs/MANUAL_QA.md P-2.
- Before / after screenshots (design-review/, git-ignored; every after shot re-taken at the end of Phase 6 from the final code): `before/<name>.png` → `after/<name>.png` for
  - desktop (22): desktop-{library,library-empty,record,settings,onboarding,lock,overlay,pill,review,dictation}-{dark,light}, plus desktop-mica-accent-{dark,light} (after only);
  - web (28): web-{login,dashboard,sessions,player,clients,settings,usage}-{dark,light}[-phone] (the old after "dashboard" shots showed the sign-in page; the new ones are signed in);
  - phone (16): phone-{library,library-empty,session,record,settings,settings-recording,onboarding,lock}-{dark,light} (before: dark only, without the suffix).
