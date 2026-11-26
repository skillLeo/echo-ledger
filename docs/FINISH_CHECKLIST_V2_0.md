# Finish checklist — v2.0 Clarity Redesign + Bug Bash

The plan is docs/PLAN_V2_0.md (local only); the order is docs/ROADMAP.md (local only): v2.0 starts when v1.9.0 is
tagged. Before that, only the docs-only V0 groundwork lane runs (when a build slot is free), and every bug from the
v1.8/v1.9 sweeps that isn't fixed now is logged in [v2/audit/bugs.csv](v2/audit/bugs.csv). Each item is ticked only with
its proof (test name, screenshot path or log), a review approval and a HANDOFF line.

> **Status 2026-11-18 03:52 PKT (paused):**
> - The parity sprint (PLAN_V2_PARITY) comes before every other v2.0 item. Desktop is 62% (372/603), phone 36% (103/285), and the web audit has just started.
> - Classic stays the default for everyone, with v2 Opt-in on production.
> - Full state and next steps: [RESUME_HERE.md](RESUME_HERE.md).

## Needs Hassam

| # | What | When | Action |
|---|---|---|---|
| G1 | Gate 1: approve the audit | After V0 | Read the 10-line summary and its links; say "approve" or what to change |
| G2 | Gate 2: approve the feature tree and the copy deck | After V1 | Read docs/v2/IA.md and docs/v2/copy/ |
| G3 | Gate 3: choose the visual direction | V2.1 | Look at the design values and 3 sample screens (Library list, recording page, Settings) on phone and desktop, light and dark, English and Urdu |
| T | Tree-test and usability-test participants (Urdu-first users in Pakistan, US/UK users), or waive the tests | V1.3, V4.3 | Run them with the scripts, or say "waive" |
| F | A web font licence, if the chosen face needs one | V2.1 | Buy or approve |
| S | Approve the key screenshots before each module's rollout | V3 | Per module |
| N | The name decision (Baatly / Mukalma) before v2.1 | Before v2.1 | Decide |

## Step 0
- [x] S0.1 docs/PLAN_V2_0.md and docs/ROADMAP.md saved, local only (2026-11-16).
- [x] S0.2 This checklist and docs/v2/ (IA.md, DESIGN_SYSTEM.md, audit/, copy/, research/, bugbash/, reviews/) created.
- [ ] S0.3 Feature flags in place: ui_v2.shell, .home, .library, .recording_controls, .after_call, .live, .ask,
  .settings, .setup_health, .admin, .website — web through Laravel Pennant (or the existing flag system), apps through
  server-controlled flags, off by default on the device; a "Switch back to classic" control. **Done when:** a flag test
  per app shows each flag off by default and switchable from the server.
- [ ] S0.4 release/v1.9 cut at the v1.9.0 tag (hotfixes only, cherry-picked forward). **Done when:** the branch exists
  and HANDOFF records the cherry-pick rule.

## V0 — Audit (no visible change)
- [ ] 0.1 Feature inventory → v2/audit/features.csv (feature, platform, entry points, on/off control, settings location,
  status). **Done when:** every feature in the tree has all four platforms filled ("n/a" with a reason allowed).
- [ ] 0.2 Screen inventory → v2/audit/screens.csv + screenshots per platform, light and dark. **Done when:** the screen
  count per platform is recorded and every screen has both screenshots.
- [ ] 0.3 Component inventory → v2/audit/components.md (duplicate components; distinct colours, font sizes, radii,
  shadows, spacing values). **Done when:** the totals and the top 20 duplicates are listed.
- [ ] 0.4 Heuristic evaluation per module (Nielsen's 10) → v2/audit/heuristics.md. **Done when:** every module is
  scored and every finding has the heuristic number, a severity and a screenshot.
- [ ] 0.5 Bug triage → v2/audit/bugs.csv + v2/audit/root-causes.md. **Done when:** every bug has platform, module,
  category, severity and root-cause ID, and the root causes are sorted by how many bugs one fix closes.
- [ ] 0.6 AI-look audit against the plan's 13 rules → v2/audit/ai-look.md with file:line. **Done when:** every app
  is scanned and each finding has a location.
- [ ] **Gate 1** (G1).

## V1 — Information architecture
- [ ] 1.1 v2/IA.md complete: the tree, the level rules, the full feature → settings home → control table, navigation
  per platform. **Done when:** every feature of features.csv has exactly one settings home in the table.
- [ ] 1.2 Copy deck v2/copy/en.json, ur.json, ar.json (navigation, settings, empty states, errors, setup steps).
  **Done when:** the deck covers every string the tree names, in sentence case, one action name per flow.
- [ ] 1.3 Tree-test plan v2/research/tree-test.md (10 top tasks, Urdu and English users). **Done when:** results
  recorded (target ≥ 80 % on the top tasks) or waived (T).
- [ ] **Gate 2** (G2).

## V2 — Design values, components, test harness
- [ ] 2.1 tokens/ (W3C design-token JSON: global → semantic → component) and the Style Dictionary build (CSS variables
  + Tailwind theme, Kotlin EchoTheme, Swift EchoTokens); the 3 sample screens with the real values. **Done when:** one
  command regenerates every output and CI fails on drift. **Gate 3** (G3).
- [ ] 2.2 Lint rules against raw colours, sizes and spacing in components (stylelint/ESLint, detekt/ktlint, SwiftLint).
  **Done when:** they run in CI and the starting count is recorded.
- [ ] 2.3 Component libraries per platform (Button, TextField, Select, SearchField, FilterBar, Chip, ListRow, Card,
  Table, Tabs, Segmented, Dialog/Sheet, Toast with Undo, Banner, Badge/Status, Toggle/Checkbox/Radio, Menu, EmptyState,
  Skeleton/Progress, RecordingPill, CaptionsWindow, LiveAssistPanel, AfterCallCard, SetupStep, HealthCheckRow,
  TrayPopover). **Done when:** every component has stories (Storybook / @Preview / #Preview) for every state × light/dark
  × LTR/RTL × text 100/130/200 %, and all screenshot tests pass.
- [ ] 2.4 Visual regression + accessibility in CI (Playwright toHaveScreenshot + axe-core on Storybook; Paparazzi and
  Roborazzi + accessibility checks on Android; swift-snapshot-testing + audits on iOS). **Done when:** the suites run on
  every merge request and keep their diffs as artifacts.

## V3 — Rebuild module by module (one flag each)
For each module: built only from library components and copy-deck strings; screen screenshot tests (light/dark, LTR/RTL,
compact/regular, large text); end-to-end tests for its top 3 tasks; review approve; flag on for Hassam; bug bash;
staged rollout. **Done when (each):** the top tasks pass on every platform, no open S0/S1, at most 3 S2, screenshots
approved (S), accessibility green.
- [ ] 3.1 App shell and navigation (ui_v2.shell).
- [ ] 3.2 Recording controls, pill and after-call card (ui_v2.recording_controls, ui_v2.after_call).
- [ ] 3.3 Library and the recording page: filters, saved views, trash, undo (ui_v2.library).
- [ ] 3.4 Setup & health with the Android and desktop wizards (ui_v2.setup_health). **Also done when:** a new user on the
  S25 finishes the wireless setup in ≤ 8 minutes without help, 3 of 3 runs, and every check is right after a reboot.
- [ ] 3.5 Settings, searchable and goal-grouped (ui_v2.settings).
- [ ] 3.6 Ask and Workspaces (ui_v2.ask).
- [ ] 3.7 Live: Record, Live Assist, Live Translate, Dictation, Captions (ui_v2.live).
- [ ] 3.8 Home (ui_v2.home).
- [ ] 3.9 Admin, Filament themed with the design values (ui_v2.admin).
- [ ] 3.10 Website (ui_v2.website), including the items moved from v1.8: P6.1's look and P6.2's first-run screens.

## V4 — Bug bash and quality gates
- [ ] 4.1 90-minute bug bashes per module per platform with charters (template in v2/bugbash/). **Done when:** every
  module × platform has a logged session in bugs.csv.
- [ ] 4.2 Matrix tests (desktop: minimum size, resize, 100–200 % scaling, two monitors, sleep/wake, 60 min background
  recording; Android: rotation, split screen, battery saver, reboot; iPhone: largest text; web 320–1920 px). **Done
  when:** each row has a result in HANDOFF.
- [ ] 4.3 Usability tests (5 Urdu-first users in Pakistan, 5 US/UK users, 8 tasks, task success + SUS). **Done when:**
  task success ≥ 85 % and SUS ≥ 75 against the V0 baseline, or waived (T).
- [ ] 4.4 Release gates: 0 S0, 0 S1, ≤ 10 S2, accessibility green, visual diffs approved, crash-free sessions ≥ 99.5 %,
  0 AI-look findings.

## V5 — Release and clean-up
- [ ] 5.1 Staged rollout per module (internal → 5 % → 25 % → 100 %) with "Switch back to classic" for 4 weeks.
- [ ] 5.2 Old UI code and its flags deleted.
- [ ] 5.3 Store screenshots and website images retaken from the new UI.
- [ ] 5.4 v2.0.0 tagged.
- [ ] 5.5 Final report: what changed, before/after screenshots, metrics against the baseline, what needs Hassam.

## Moved here from v1.8 (ROADMAP, Stage C)
- v1.8 P5 (looks professional, not generated: 5.1–5.4) → V0.6, V2, V3.
- v1.8 P3.3 (appearance and themes) → the design values (V2.1) and Settings › Appearance (V3.5).
- The look of v1.8 P6.1 (website) and P6.2 (first-run screens) → V3.10 and the first-run flow in V3.4. v1.8 keeps only
  what the stores need: plain privacy, terms, support and download pages.
- v1.8 P8 (iPhone) → starts after V2, built in the new system (unless Hassam says "iPhone now").
