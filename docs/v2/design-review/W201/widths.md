# W-201: the wide tables fit the page in the new frame

Measured on a local server with the parity world (`web/tests/Feature/V2/Support/parity_world.php`), new frame on, light,
English and Urdu, at 1024 and 1280 px. "Available" is the table's box. "Needs" is the table's narrowest width
(`width: min-content`), past which it would scroll sideways. "Natural" is its width with nothing wrapped. Slack is
available minus needs. Before is v1.1 @ c1b87ef2; after is this change.

| Table | Width | Lang | Available | Needs before | Slack before | Needs after | Natural after | Slack after |
|---|---|---|---|---|---|---|---|---|
| Recording › Share links | 1024 | EN | 734 | 1121 | -387 | 627 | 661 | **107** |
| Recording › Share links | 1024 | UR | 734 | 1112 | -378 | 618 | 653 | **116** |
| Recording › Share links | 1280 | EN | 990 | 1121 | -131 | 627 | 661 | **363** |
| Recording › Share links | 1280 | UR | 990 | 1112 | -122 | 618 | 653 | **372** |
| My links | 1024 | EN | 734 | 1195 | -461 | 542 | 762 | **192** |
| My links | 1024 | UR | 734 | 1177 | -443 | 538 | 758 | **196** |
| My links | 1280 | EN | 990 | 1195 | -205 | 542 | 762 | **448** |
| My links | 1280 | UR | 990 | 1177 | -187 | 538 | 758 | **452** |
| Devices | 1024 | EN | 734 | 1038 | -304 | 590 | 590 | **144** |
| Devices | 1024 | UR | 734 | 1022 | -288 | 576 | 576 | **158** |
| Devices | 1280 | EN | 990 | 1038 | -48 | 590 | 590 | **400** |
| Devices | 1280 | UR | 990 | 1022 | -32 | 576 | 576 | **414** |
| Request links | 1024 | EN | 734 | 931 | -197 | 585 | 714 | **149** |
| Request links | 1024 | UR | 734 | 907 | -173 | 580 | 710 | **154** |
| Request links | 1280 | EN | 990 | 931 | 59 | 585 | 714 | **405** |
| Request links | 1280 | UR | 990 | 907 | 83 | 580 | 710 | **410** |

Request links at 1280 already fitted before (59 px of slack in English). Every other table scrolled sideways at both
widths.

## What changed, in the new frame only

| Table | Row actions | Hidden at first (under Columns) | Other |
|---|---|---|---|
| Recording › Share links | ⋮ Actions: Access log, Revoke (red, asks) | Downloads, Made by | Made and Expires show the date; the time is in the tooltip |
| My links | ⋮ Actions: Access log, Revoke (red, asks) | Downloads, Last opened, Made (still sorts when shown) | What was shared wraps |
| Devices | ⋮ Actions: Rename, Sign in again (on a signed-out device of yours), Sign out (red, asks), Remove from list (red, asks) | App, Signed in | |
| Request links | ⋮ Actions: Revoke (red, asks) | Made | The title wraps; Open until shows the date, with the time in the tooltip |

No column or action is gone. Each one hidden at first is in the table's Columns menu, and each action keeps its
classic label, colour, confirmation and order. Classic is byte for byte as it was (`ClassicTablesGoldenTest` and
`ClassicGoldenTest`). `TablesFitTest` holds both shapes.

## Pictures

`<table>-<width>-light-<en|ur>.png` for each table at 1024 and 1280, and `my-links-1024-light-en-menu.png` /
`devices-1024-light-en-menu.png` with the ⋮ menu open.
