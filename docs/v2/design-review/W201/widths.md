# W-201: the wide tables fit the page in the new frame

Measured by `web/tests/Feature/V2/Support/table-widths.mjs` on a local server with the parity world
(`web/tests/Feature/V2/Support/parity_world.php`), new frame on, light and dark (the same widths), English and Urdu, at 1024
and 1280 px. The script fails when a table has less than 60 px to spare. "Available" is the table's box. "Needs" is the
table's narrowest width (`width: min-content`), past which it would scroll sideways. "Natural" is its width with nothing
wrapped. Slack is available minus needs. Before is v1.1 @ c1b87ef2. After is this change: the dates follow one rule,
with the time on a second line, which adds height but no width, and the words are in Urdu.

| Table | Width | Lang | Available | Needs before | Slack before | Needs after | Natural after | Slack after |
|---|---|---|---|---|---|---|---|---|
| Recording › Share links | 1024 | EN | 734 | 1121 | -387 | 627 | 661 | **107** |
| Recording › Share links | 1024 | UR | 734 | 1112 | -378 | 585 | 585 | **149** |
| Recording › Share links | 1280 | EN | 990 | 1121 | -131 | 627 | 661 | **363** |
| Recording › Share links | 1280 | UR | 990 | 1112 | -122 | 585 | 585 | **405** |
| My links | 1024 | EN | 734 | 1195 | -461 | 538 | 759 | **196** |
| My links | 1024 | UR | 734 | 1177 | -443 | 491 | 683 | **243** |
| My links | 1280 | EN | 990 | 1195 | -205 | 538 | 759 | **452** |
| My links | 1280 | UR | 990 | 1177 | -187 | 491 | 683 | **499** |
| Devices | 1024 | EN | 734 | 1038 | -304 | 590 | 590 | **144** |
| Devices | 1024 | UR | 734 | 1022 | -288 | 549 | 549 | **185** |
| Devices | 1280 | EN | 990 | 1038 | -48 | 590 | 590 | **400** |
| Devices | 1280 | UR | 990 | 1022 | -32 | 549 | 549 | **441** |
| Request links | 1024 | EN | 734 | 931 | -197 | 585 | 714 | **149** |
| Request links | 1024 | UR | 734 | 907 | -173 | 563 | 693 | **171** |
| Request links | 1280 | EN | 990 | 931 | 59 | 585 | 714 | **405** |
| Request links | 1280 | UR | 990 | 907 | 83 | 563 | 693 | **427** |

Request links at 1280 already fitted before (59 px of slack in English). Every other table scrolled sideways at both
widths. The least room left at 1024 is 107 px (Share links, English).

## What changed, in the new frame only

| Table | Row actions (Filament's own ⋮ "Actions" / "کاروائیاں") | Hidden at first (under Columns) | Other |
|---|---|---|---|
| Recording › Share links | Access log, Revoke (red, asks) | Downloads, Made by | Made and Expires: date, with the time on a second line |
| My links | Access log, Revoke (red, asks) | Downloads, Last opened, Made (still sorts when shown) | What was shared wraps; Expires: date, with the time on a second line; page title My links |
| Devices | Rename, Sign in again (on a signed-out device of yours), Sign out (red, asks), Remove from list (red, asks) | App, Signed in (date) | |
| Request links | Revoke (red, asks) | Made | The title wraps; Open until: date, with the time on a second line |

One date rule (`App\Filament\Support\V2Tables::date`): "24 Nov 2026" / "24 نومبر 2026" through translatedFormat, with the time
in the console's clock ("11:42 AM" / "11:42 صبح") on a second line, readable without hovering. Headers, values, the
filter chip and the actions are in `web/lang/ur.json`.

No column or action is gone. Each one hidden at first is in the table's Columns menu, and each action keeps its
classic label, colour, confirmation and order. Classic is byte for byte as it was (`ClassicTablesGoldenTest`,
`ClassicGoldenTest`). `TablesShapeTest` covers both shapes in English and in Urdu, the date rule, the page title, and
who sees which device actions (the owner and an admin manage every device; a member has the small console and no
Devices page).

## Pictures

`<table>-<width>-<light|dark>-<en|ur>.png` for each table at 1024 and 1280, and `<table>-1024-<theme>-<lang>-menu.png`
with the row's ⋮ menu open (the red actions last). The script above takes them.
