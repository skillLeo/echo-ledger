# Web console accessibility pass, FIX-06 (2026-11-25)

The new console's main pages under an automated axe-core check, and keyboard-only checks of the Library. Run again with
`node scripts/a11y.mjs` from `web/` against a local server with the tour's data (see the script's header; `ONLY=library`
for one page, `KEYS=off` to skip the keyboard checks, `OUT=report.json` to keep the findings). The findings of this run
are in `web-a11y/report.json`.

## Pages

6 pages (the Library, a recording, Activity, Settings, Settings › Profile and the Workspace page), each in English and
Urdu, light and dark, at 1440 and 390 px: 48 pages, as the owner of the tour's data, every v2 flag on.

## Automated check (axe-core 4.14.0, as the desktop's)

Rules: WCAG 2.0, 2.1 and 2.2 A and AA, and axe's best practices.

| | Pages | Critical | Serious | Moderate | Minor |
|---|---|---|---|---|---|
| Before the fixes | 48 | 1 rule | 4 rules | 4 rules | 1 rule |
| **After the fixes** | **48** | **0** | **0** | 4 rules | 1 rule |

Before the fixes:

- `aria-required-children` (critical, every Library page): the grouped list's day headings sat straight in its
  `role="list"` (Filament's grouped table), so the list held a heading and a button, not items.
- `label-content-name-mismatch` (serious, every page in English): a control's name did not hold the words it shows, so a
  voice-control user could not say them. The workspace button showed "P Personal Owner" and was named "Workspace:
  Personal"; the recording's project chip showed "No project" and was named "Project: none. Change"; the profile card in
  Settings left out its plan and its month's use.
- `target-size` (serious): Record's menu in the rail was a 20 px target; the recording's Rename was cut to 22.5 px by
  Previous and Next recording, which draw 44 px past their line.
- `color-contrast` (serious, the Workspace page): the invitations' and invite links' headings were 3.32:1 (70 %
  opacity), and in dark the "Owner" badge was 1.12:1 (Filament writes a dark badge's words in its colour's 100, which the
  new design had set to the accent's soft fill).
- `scrollable-region-focusable` (serious, a recording at 390 px): the empty share links table scrolls sideways with
  nothing in it to focus, so the keyboard could not scroll it.

Left, all moderate or minor: `region` (the Terms and Privacy links are outside every landmark), Settings' own `<main>`
inside the page's (`landmark-main-is-top-level`, `landmark-no-duplicate-main`, `landmark-unique`) and Filament's empty
actions column heading (`empty-table-header`). Both changes touch classic's markup (the Settings page is in
ClassicGoldenTest), so they are logged for later (W-228, W-229).

## Keyboard

| Check | Keys | Result |
|---|---|---|
| The rail is one stop | Tab from the top of the Library into the rail, ArrowDown, then Tab | The arrows move through the rail (Home and End to its ends); Tab leaves it after one stop |
| Tooltips close | Hover Previous recording, Esc | The tooltip closes |
| Focus is visible | Tab through the Library, 26 stops | Every stop shows its focus: a 2 px outline or a ring |

The resize edges of the place's panel and the Library's filters already move by keyboard (UX-03).

## Findings

| Id | Sev | Where | What | Status |
|---|---|---|---|---|
| W-222 | S2 | Library (grouped list) | Day headings were not items of the list | Fixed: the console's help script makes each one an item (`a11y-core.cjs`) |
| W-223 | S2 | Top bar, recording, Settings | Names did not hold the words the controls show | Fixed under the new design; classic keeps its names |
| W-224 | S2 | Rail, recording | Record's menu 20 px; Rename partly covered | Fixed: a 24 px target with the same 20 px circle; the title keeps clear |
| W-225 | S2 | Workspace | Table headings 3.32:1; dark "Owner" badge 1.12:1 | Fixed: whole headings in the secondary colour; dark light shades are the accent's text colour |
| W-226 | S2 | Recording at 390 px | A table box that scrolls could not be reached by keyboard | Fixed: such a box takes the focus while nothing in it can |
| W-227 | S2 | Rail | Every rail item was its own Tab stop; the arrows did nothing (FIX-06) | Fixed: one stop, a roving tabindex, the arrows, Home and End |
| W-228 | S3 | Settings, every page | Settings' `<main>` inside the page's; the footer links outside a landmark | Logged |
| W-229 | S4 | Workspace (People) | Filament's actions column heading is empty | Logged |

No S0 or S1. Tests: `tests/js/a11y-core.test.mjs` (the help script), `tests/Feature/V2/ConsoleA11yTest.php` (the names,
the styles, where the script is installed); ClassicGoldenTest holds classic byte for byte. The parity checks read the
workspace menu's three items under "personal owner" now that the mark's letter is drawn, not written
(`web-parity-checks.json`, WEB-NAV-023 to 025).
