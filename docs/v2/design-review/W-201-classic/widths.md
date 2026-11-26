# W-201 classic: the wide tables fit the page

The classic console's four wide tables scrolled sideways at 1024 and 1280 px and cut off their row actions. Classic now
fits them: the row's actions are in one ⋮ menu (as in the new frame, W-201), long titles wrap, dates keep
classic's "Nov 17, 2026" with the hour on a second line, and the wide columns show only on a wide window (from 1280 px).
Classic has no Columns menu: Filament draws that menu's labels with x-html, which the classic pages never contain
(AdminXssTest, AdminTranscriptPlayerTest). Classic keeps its English words.

| Table | Shown from 1280 px only |
|---|---|
| Recording › Share links | Downloads, Made by |
| My links | Downloads, Last opened, Made |
| Devices | App, Signed in |
| Request links | Made |

## Widths

Measured with `web/tests/Feature/V2/Support/table-widths.mjs` (the screens' script) on a local server with the parity world
(`web/tests/Feature/V2/Support/parity_world.php`) in a scratch SQLite file, classic frame, light and dark (the same
widths). "Needs" is the table's narrowest width (`width: min-content`); slack is the box's width minus that. Before is
The classic measurement (docs/v2/design-review/U7/layout-check.md, C2–C5).

| Table | Needs before | Needs at 1024 | Slack at 1024 | Needs at 1280 (wide columns shown) | Slack at 1280 | Scrolls sideways |
|---|---|---|---|---|---|---|
| Recording › Share links | 1,128 | 634 | **84** | 831 | 143 | no |
| My links | 1,213 | 545 | 173 | 866 | **108** | no |
| Devices | 1,046 | 596 | 122 | 758 | 216 | no |
| Request links | 951 | 599 | 119 | 697 | 277 | no |

## Pictures

`<table>-<width>-<theme>-en.png` for each table at 1024 and 1280 px, light and dark, and
`<table>-1024-light-en-menu.png` with the row's ⋮ menu open (its actions, the red ones last).
