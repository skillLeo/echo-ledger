# U7 layout check: console pages at 720, 1024, 1280 and 1728 px

What was checked: every console page of the parity world (`web/tests/Feature/V2/Support/parity_world.php`, 38 pages:
Home, Library, a recording, Projects and each project tab, Search, Upload, Activity, Live, Shares, Shared with me,
Request links, Recently deleted, Devices, Usage, Workspace, Help, Get the apps, Live Assist preps, sessions and settings,
Settings and each settings page) at 720, 1024, 1280 and 1728 px, plus 390 px for the known phone issue:

- classic: light and dark, English (classic has no Urdu);
- v2: light and dark, English and Urdu.

1,140 page loads in all, on a local server with the parity world in a scratch SQLite file. Each page was checked for
sideways page scroll, boxes that scroll sideways, text cut by its box without an ellipsis, text cut off the screen and
text drawn over text. The pages it flagged were then looked at by eye, as were Home, a recording, Search, Upload, Live,
Settings, Appearance, Projects, a project, Activity and Usage in Urdu at 720 and 1280 px.

The page never scrolls sideways, at any width, in either design. Nothing at 1728 px. Light and dark give the same
results everywhere. The screenshots are in `layout/`. Red outlines mark what was flagged.

## v2 issues

| # | Page | Width | What is wrong | Screenshot | Owner |
|---|------|-------|---------------|------------|-------|
| 1 | Library (List) | 390 (phone), EN and UR | **Resolved.** The row's time and length were pushed off the right edge, and the list scrolled sideways (it showed 358 px and needed 463). The cause was the records grid (`.fi-ta-content`), which sized its one column to the longest title. `console.css` now has `.v2-library .fi-ta-content { grid-template-columns: minmax(0, 1fr); }` (test: `LibraryParityTest`, "keeps the records grid to the page's width…"). The time and length now show, the list no longer scrolls, and the title and meta end in "…". `nav-phone-*.png` were retaken. | `layout/v2-library-390-light-en.png`, `layout/v2-library-390-light-ur.png` | done |
| 1b | Library (List) | 390, UR | Now that the row fits, an English title in the Urdu list is cut at its start ("…terms for the March shipment"). The title's box is right to left, so the ellipsis goes at its left end, and the `<bdi>` keeps the English running left to right inside it. Suggested fix: give `.v2-row-title` `dir="auto"` instead of the inner `<bdi>` (in `library-row.blade.php`), and keep it right-aligned in Urdu with `html[dir='rtl'] .v2-row-title { text-align: right; }`. The second line is cut at its left end, which is correct for right to left. | `layout/v2-library-390-light-ur.png`, `docs/v2/parity/web/nav-phone-dark-ur.png` | EL-Design (needs a rule outside this lock) |
| 2 | Settings › Appearance | 390 | The Language hint ("The console's words and direction. Transcripts keep their own…") is cut at the right edge with no ellipsis. The settings box is 358 px and the line needs 409. | `layout/v2-settings-appearance-390-light-en.png` | EL-Design (settings box styles) |
| 3 | Recording | 390 | The tabs (Transcript, Summary, Ask, Moments, Details) need 7 px more than they get, so "Details" is cut at the edge. They scroll, but nothing shows that more is there. The meta line also wraps with its "·" left at the end of the first line. | `layout/v2-recording-390-light-en.png` | EL-Design (tabs) |
| 4 | Recording › Share links table | 720, 1024, 1280, EN and UR | The table needs 1,121 px. At 1280 it shows 990, so the row's Access action is cut at the right edge. Its dates are also in classic's English format ("Nov 17, 2026 · 11:42 AM") under the v2 frame. | `layout/v2-recording-share-links-1280-light-en.png` | EL-Fix (shared page table) |
| 5 | My links (Shares) | 720, 1024, 1280, EN and UR | The table needs 1,194 px, so it scrolls sideways even at 1280. | `layout/v2-shares-1280-dark-en.png` | EL-Fix (shared page table) |
| 6 | Devices | 720, 1024, 1280, EN and UR | The table needs 1,038 px, so at 1280 the row actions (Rename and the rest) are cut at the right edge. | `layout/v2-devices-1280-light-en.png` | EL-Fix (shared page table) |
| 7 | Request links | 720, 1024, EN and UR | The table needs 931 px and scrolls sideways at 1024. | `layout/v2-request-links-1024-light-ur.png` | EL-Fix (shared page table) |
| 8 | Project › Recordings (and every project tab) | 390, UR | The project pages are still classic's, in English, under the Urdu frame. Their row titles are not isolated, so in right-to-left an English title is cut at its start ("…ms for the March shipment") instead of its end. The Sort select's arrow sits over "Newest first". The tab bar scrolls sideways at 390 (it needs 528 px). | `layout/v2-project-recordings-390-light-ur.png` | EL-Fix (project pages: `<bdi>` / `dir="auto"` on titles, Urdu words) |
| 9 | Usage | 720, UR | The page is in English under the Urdu frame. Its mixed-direction lines come out in the wrong order: "B 68.0" for 68.0 B, "min · no limit 0.0", and the full stop at the wrong end of the note ("…until the limit is raised or the month ends" with "." first). | `layout/v2-usage-720-light-ur.png` | EL-Fix (Usage page: Urdu words, `dir="auto"` / `<bdi>` on values) |
| 10 | Home | 720, UR | The "This month" card wraps "0.0 min · $0.00" so the "·" is left alone at the end of the first line. | `layout/v2-home-720-light-ur.png` | EL-Fix (Home stats widget) |
| 11 | Workspace › Invitations | 390, EN and UR | The members and invitations tables scroll sideways at 390 (they need 630 and 445 px). | `layout/v2-workspace-390-light-en.png` | EL-Fix (shared page tables) |

Seen and left alone as intended: the Library's view chips (Views, All, Today…) run past the edge at 390 and 720 px and
scroll sideways, as a phone chip row does. The empty Live Assist preps and sessions tables need 10 to 70 px more than
they get at 390 px.

Changed in this task (v2 Library, in scope): the phone-width row (issue 1 above), and the Library table's App and Device columns were English under Urdu. They now
read in the console's language, as the group titles, Retry and Delete do (test: `LibraryParityTest`, "reads the group
titles, the table's apps and the Failed uploads row's Retry and Delete in Urdu…").

## Classic issues (not fixed here)

| # | Page | Width | What is wrong | Screenshot | Owner |
|---|------|-------|---------------|------------|-------|
| C1 | Library | 1024 (and 768) | The toolbar needs 749 px and gets 720. List / Table stacks into two lines, and "Sort by: Newest" is cut at the right edge. | `layout/classic-library-1024-light-en.png`, also `library-rows-768-light-en.png` (left half) | EL-Fix |
| C2 | Recording › Share links | 720, 1024, 1280 | The table needs 1,128 px and scrolls sideways at 1280. The Access action is cut. | `layout/classic-recording-share-links-1280-light-en.png` | EL-Fix |
| C3 | My links (Shares) | 720, 1024, 1280 | The table needs 1,213 px and scrolls sideways at 1280. | `layout/classic-shares-1280-dark-en.png` | EL-Fix |
| C4 | Devices | 720, 1024, 1280 | The table needs 1,046 px and scrolls sideways at 1280. | as v2 #6 | EL-Fix |
| C5 | Request links | 720, 1024 | The table needs 951 px and scrolls sideways at 1024. | as v2 #7 | EL-Fix |

Server: nothing found that needs EL-Backend.
