# Nightly tests

One command runs every test suite this Mac can run, one after another, and writes a short report.

```sh
sh docs/qa/nightly.sh            # everything, about 40 minutes
sh docs/qa/nightly.sh --quick    # the fast suites only, about 10 minutes
sh docs/qa/nightly.sh --only=vitest,web      # some suites (ids in the table below)
sh docs/qa/nightly.sh --skip=widths          # all but some
```

Run it from any folder of the repository (a worktree is fine). A failing suite never stops the others. It exits 0 when
nothing failed and 1 otherwise. Missing dependencies are installed first (the npm packages in `desktop/` and
`tokens/` from their lockfiles, `composer install` in `web/`); a tool that is not on the Mac at all (cargo, a JDK, the Android SDK) makes that suite
"skipped" with the reason, not a failure.

## Suites

| id | What | Time | In --quick |
|---|---|---|---|
| `policy` | `node tools/repo-policy.test.mjs` | 10 s | yes |
| `tokens` | `tokens/test/*.test.mjs` | 5 s | yes |
| `vitest` | desktop unit tests, `npx vitest run` | 2-3 min | yes |
| `tsc` | desktop types, `npx tsc --noEmit -p .` | 20-70 s | yes |
| `cargo` | desktop core, `cargo test --no-fail-fast` in `desktop/src-tauri` | 9 min (first build 5 min more) | no |
| `widths` | desktop window widths, `desktop/scripts/widthcheck.mjs` (16 screens, 720 to 1728 px, light/dark, English/Urdu) | 20 min | no |
| `scroll` | desktop first paint and scrolling, `desktop/scripts/scrollcheck.mjs` (WebKit and Chromium) | 2 min | no |
| `web` | web server and console, `php artisan test --parallel` | 5 min | yes |
| `webjs` | web JavaScript rules, `web/tests/js/*.test.mjs` | 5 minjs | yes |
| `phone` | phone unit tests and screens, `./gradlew verifyRoborazziFullDebug testPlayDebugUnitTest` | 15-17 min | no |
| `webshots` | web screenshots 390/1440 light/dark: listed as not automated yet (see below) | | no |

`--quick` skips the Rust core, both picture checks and the phone (the slow ones).

Times are from the first run (2026-11-21) on a busy Mac (load average near 190); a quiet machine is faster. The report
shows the load at the start.

Notes:

- **Re-runs.** Each failed test is run once more (vitest by file, Pest by file, node by file, cargo by name, Gradle by
  class). A test that passes the second time is listed under "Flaky", not as a failure.
- **Rust.** `nightly.sh` puts Homebrew's rustup first on `PATH`. The build folder is kept in
  `~/EchoLedgerTeam/cache/cargo-target` so later nights only rebuild what changed (the first build is the slow one).
- **Phone.** The JDK is found in `JAVA_HOME`, Homebrew's `openjdk@17`/`@21` or Android Studio; the SDK in
  `ANDROID_HOME` or `~/Library/Android/sdk`. Old results in `mobile/app/build/test-results` are removed first so
  Gradle runs the tests again. `verifyRoborazziFullDebug` fails a screen that no longer matches
  `docs/design/screens`; the report counts those separately. The report also lists how many test files cover large
  text, Urdu, dark and split screen, from the existing Robolectric qualifiers (nothing new is added for it).
- **Widths.** Runs only when `desktop/scripts/widthcheck.mjs` is on the branch; otherwise "skipped".
- **Web screenshots** (`web/scripts/screenshots.mjs`) are not automated yet: they need `web/node_modules` and a vite
  build, a seeded local SQLite database (`db:seed` and `ConsoleTourSeeder`, `APP_ENV=local`), `php artisan serve`
  and Playwright; the admin pages also need the local account's TOTP secret.

## Known gaps

- `widths` is skipped until `widthcheck.mjs` is merged into v1.1 (it is on the desktop fix branches).
- `scroll` aims the wheel at 60% of the window. On classic Settings the only part that scrolls is the settings list
  on the left, so the check reports "scrolls nothing" there although the page itself may be fine; look at the picture.
- `webshots` is not automated (above).
- The phone matrix uses only the qualifiers the tests already have: no small-text (font scale below 1) case exists.

## Output

All outside git, in `~/EchoLedgerTeam/reports/` (`NIGHTLY_REPORTS` changes it):

- `<date>.md`: overall pass/fail, time, branch and commit, the suite table, new failures (with the first error line),
  flaky tests, the picture problems grouped by screen and kind, and what changed since the previous report.
- `<date>.json`: the same, for scripts; the next night compares itself with it.
- `<date>-desktop-widths/`, `<date>-desktop-scroll/`: the pictures and each check's `report.json`.
- `<date>-run/`: every suite's full log and JUnit file.

`NIGHTLY_DATE=YYYY-MM-DD` names the report for another day (to re-run a night).
