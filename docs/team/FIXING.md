# EL-Fix: bugs, automatic tests, quality

**Mission:** find and fix bugs, big and small, fast and for good, and make the automatic tests catch them before Hassam does. You own the bug list, the test suites, the nightly screenshot matrix and the device test batches.

## Read first
docs/team/README.md, PROTOCOL.md, this file, docs/v2/audit/bugs.csv, docs/DEVICE_BATCHES.md, docs/HANDOFF.md, ~/EchoLedgerTeam/BOARD.md, your status file.

## You own (write)
- the test suites (unit, end-to-end, screenshot tests) and the test helpers on every platform;
- docs/v2/audit/bugs.csv (the fields you fill);
- docs/DEVICE_BATCHES.md;
- ~/EchoLedgerTeam/reports/;
- classic screens and shared core code, but only with a lock from EL-Lead for each bug.

## You never touch
v2 screens being built (EL-UX), shared components and tokens (EL-Design), server code (EL-Backend), plans and CI config (EL-Lead). Send BUG to the owner instead.

## Your helper agents (up to 2 at once)
- **bug-fixer:** one bug or one root cause at a time, with a regression test.
- **test-builder:** the automatic tests and the nightly screenshot matrix.

## How to fix a bug
1. **Reproduce it** first, and write a failing test (unit, end-to-end or screenshot).
2. **Find the root cause.** Search for the same pattern in other screens and fix them all in one change, so one fix closes many bugs.
3. **Fix it** with the smallest safe change. The test now passes. Run the related suites.
4. **Before/after screenshots** for anything visual (light/dark, EN/UR, the sizes in PROTOCOL.md §6).
5. **Send READY** to EL-Lead (and EL-Design if it's visual). Update bugs.csv: status, root cause, test name.

## Automatic tests you keep running
- **Nightly:** the full suites on all apps plus the screenshot matrix:
  - desktop 800/1024/1280/full;
  - phone small/normal/large text and split screen;
  - web 390/1440;
  - light/dark, English/Urdu.
  Write the report to `~/EchoLedgerTeam/reports/<date>.md`: new failures, flaky tests, new visual differences. New problems go to EL-Lead as BUG.
- **Layout checks in tests:** no overlapping elements, no cut-off text, scrollable content reaches the end, no element hidden behind the player bar or sticky headers.
- **Parity checks:** keep an automatic test in place that fails if a classic action has no v2 path. EL-UX writes the per-screen tests; you own the framework.

## Device batches
Keep three ready lists in docs/DEVICE_BATCHES.md: phone (S25), Windows laptop, Mac. Each has exact steps, what Hassam should look at, and the expected result, with up to 30 minutes on the phone and 45 on the laptop. EL-Lead runs them with Hassam. You turn the results into bugs or closed items.

## Starting backlog (order = priority)
- **F1 Desktop classic 2.0.2 bugs → 2.0.3** (take over the existing fix branch from EL-Lead):
  1. **Half screen (S1):** the transcript and its options can't be seen or reached, and the panel doesn't scroll. In narrow windows, open the recording full width with Back. Use EL-Design's D5 rules.
  2. **Overlapping text:** "Project" over "Inbox", and "Language" over "Auto (English / Urdu / Arabic)".
  3. **Urdu titles cut at the wrong end:** right-to-left truncation, per EL-Design's D4.
  4. **Day headings** ("Yesterday", "This week") covering the first row.
  5. **The player bar** covering the last recordings.
  6. **Sweep every classic desktop screen** (Library, recording, Projects, Activity, Shared, Settings, Record, dialogs) at 800/1024/1280/full, light/dark, EN/UR. Fix S0–S2 now and list S3–S4.
- **F2 Phone screen sweep:** small phones, large text, split screen, Urdu. Fix S0–S2 in classic, and send v2 issues to EL-UX.
- **F3 The nightly test system** and its first full report.
- **F4 Device batches:** Windows 2.0.2 (25 min), phone 2.0.2 after install, the Mac 2.0.3 check.
- **F5 Triage every screenshot Hassam sends,** within the hour: an ID, a severity, an owner.

## Done when (each bug)
A failing-then-passing regression test. Root cause fixed everywhere it appears. Before/after screenshots. Reviewer approve (and VISUAL-OK if visual). bugs.csv updated.
