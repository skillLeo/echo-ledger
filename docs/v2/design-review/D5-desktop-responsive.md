# D5 — Desktop responsive rules (classic and v2)

Owner: EL-Design. For: EL-Fix (classic desktop bugs, the width check) and EL-UX (v2 desktop screens).
Status: rules v1, 2026-11-21. They are based on F1 (b-desktop-ux @ 4c69e2fe), which already does most of this for the Library. Every other screen follows the same rules.

## 1. What decides the layout
- **Page width** decides the layout. The page width is the window width minus the sidebar or rail, and it is measured where the page is drawn (MainApp `pageWidth`). The window width alone cannot decide, because the sidebar can be anywhere from 64 to 320 px.
- **Window width** decides one thing only: whether the sidebar shows as the rail.
- Use container queries or a measured class (`.narrow`, `.no-column`). Do not use `@media (width…)` for page layout. Media queries are fine only for `prefers-*` and `max-height`.
- Every number lives in one place: lib/shell.ts for classic, tokens `--el-breakpoint-*` for v2. A test keeps the two in sync.

## 2. Sizes
| name | rule | value | what changes |
|---|---|---|---|
| min window | tauri.conf.json minWidth | **720** | Nothing may be cut off, overlap, or need sideways scrolling at 720. |
| rail | window < `AUTO_RAIL_BELOW` | **780** | The sidebar becomes the 64 px icon rail. Names stay as tooltips and for screen readers. The person can still open the full sidebar there for the moment. |
| narrow page | page < `NARROW_CONTENT` | **798** | One column. A list page shows only the list, and opening an item replaces the list (section 3). The page itself scrolls. |
| no nested column | page < `COLUMN_CONTENT` | **1120** | The Library/Projects nested column (Views, Sources, Projects) is left out, because the sidebar lists the same things. Two columns: list + detail. |
| full | page ≥ 1120 | — | Three columns: nested column, list, detail. |
| reading width | any | text blocks max **72ch**; Settings max **704 px** (11 × 64) | On wide windows the text measure stays readable. The extra space goes to the list, not to longer lines. |

Check list widths: **720, 756, 800, 1024, 1280, 1728**, each in light/dark, EN/UR, at text 100 % and 200 %.

Known gap: the v2 `--el-breakpoint-compact` token is 720 (desktop/src/v2/styles/v2.css:802). The window can no longer be narrower than 720, so v2's rail rule never fires. **EL-Design will move the token to 780** so it matches classic. EL-Fix then updates tokensOnly.test.ts, which owns that check.

## 3. Narrow page (< 798)
1. **List → item.** Opening an item hides the list and its toolbar. The item fills the full width.
2. **Back.** Back sits first in the item, at the start edge, with its icon mirrored in RTL.
   - Focus moves to Back when the item opens. Esc goes back to the list and puts focus on the same row.
   - J/K do nothing while the list is hidden.
3. **Sticky item bar (new, applies to all item pages).** Once the item's header scrolls off, a compact bar sticks under the top bar. It is 40 px tall plus the text growth, holds Back + the title on one line with an ellipsis + the ⋮ menu, uses `--surface` and a hairline bottom border, and has no shadow. This covers recording-scrolled-720, where Back currently scrolls away.
4. **List pages.** Section headers stick to the page's top edge (inside its padding). Nothing shows through them: they are solid `--surface`.
5. **Two-column pairs stack.** Form rows ("Project ▾", "Language ▾ + Re-transcribe", "Labels + Pin + Keep forever") wrap whole controls onto the next line. A control never shrinks below its label + value. A select's label never sits over its value.
6. **Top bar collapse order.** Drop items in this order as space runs out:
   1. the workspace role;
   2. the search text (keep the icon + Ctrl+K);
   3. the Ctrl+K hint;
   4. "Calls: Ready" becomes the dot alone, with the full text as a tooltip and accessible name.

   Never drop: the workspace switcher, the bell, the avatar.

## 4. Sticky layers and safe areas
- **One stack, top to bottom:** top bar → page sticky bar (item bar or find bar) → section header. Each one's top is the sum of the drawn heights above it. Measure them at runtime the way F1 does (`useStickyHeight` → `--topbar-h`, `--item-bar-h`, `--group-head-h`). Never hard-code 40 px, because headers grow at 200 % text.
- **Player dock.** It is solid `--surface` with a hairline top border, sticks to the bottom of the scrolling area, and its height is published as `--player-dock-h`.
- **Scroll padding.** Every scroller with sticky layers sets `scroll-padding-block-start` to the total height of the sticky layers above and `scroll-padding-block-end` to `--player-dock-h + --space-2`. Then J/K, Follow, search hits and `scrollIntoView` never land under a bar.
- **Short window** (`max-height: 480px`, for example 200 % text in a small window): the player dock goes static, and only the top bar stays sticky.
- **Layering:** sticky layers sit below the player dock, which sits below menus, which sit below dialogs. Menus and dialogs always sit above the player. There are no z-index tokens yet. EL-Design adds them (`z.sticky` < `z.dock` < `z.menu` < `z.dialog`, in tokens/global) with D6. Until then, keep today's values and don't add new ad-hoc ones.

## 5. Columns at ≥ 798
- **List column:** min 320 px, default 360, max 480. The person can resize it, and the choice is kept on this computer.
- **Detail column:** min 360 px and takes the rest.
- If both minimums plus padding (64 + 320 + 16 + 360 = 760) plus a scrollbar don't fit, the page is narrow (section 3). No in-between layout squeezes either column.
- The table view (as-table) keeps its own drawer. The drawer is 420 px, and below 1120 it becomes full-width with Back, like section 3.

## 6. Text size 130 % / 200 %
- Rows grow in height. They never get clipped and never truncate a title to fewer than about 12 characters. At 200 % the row's meta line may wrap onto a second line.
- Buttons keep a 32 px minimum height and grow with their text. Icon-only buttons keep a 32 px hit area with a tooltip.
- The narrow and short rules above are what make 200 % text work at 720 × 600.

## 7. RTL at every width
- Layout mirrors: the rail and sidebar move to the right, Back and the chevrons flip, and the order of row content flips.
- **These do not mirror:** the player transport (skip back / play / skip forward), the waveform and its progress, times and durations ("0:00 / 2:15", "8:12 AM"), speeds ("1×"), phone numbers, and keyboard hints. Wrap them in `<bdi dir="ltr">` or an element with `direction: ltr; unicode-bidi: isolate`.
- Free text (titles, notes, banners, transcript lines) uses `dir="auto"`. Titles truncate at their own logical end, which F1 already does.
- Full rules: D4 (Urdu/Arabic typography). Open bugs from F1-visual go to EL-Fix with D-163.

## 8. What each screen must pass (width check + eye)
1. No words drawn over words, and no control over another's value (widthcheck.mjs).
2. No sideways scroll at any check width.
3. After J/K, Follow and search, the target row is fully visible (not under a sticky layer).
4. Back is reachable without scrolling up on any item page under 798.
5. The primary action of each area is visible without scrolling at 720 × 900.
6. Light/dark, EN/UR and 100/200 % pictures go in docs/qa/desktop-widths/ (classic) or docs/v2/design-review/<id>/ (v2).

## 9. Hand-off
- **EL-Fix (classic):** sections 3.3 (sticky item bar), 3.6 (the remaining top bar steps), 4 (stack variables beyond the Library) and 7 (bidi isolates) are not done yet. Apply them to Home, Projects, Project, Shared, Activity and Settings, then extend the width check to the item-page Back reachability and to every screen in the matrix.
- **EL-UX (v2):** use the same sizes through `--el-breakpoint-*`. Sections 3 and 4 apply to every v2 list/detail page. EL-Design will ship the sticky item bar as a shared component (D6).
- **EL-Design:** in tokens/global/size.json, move `breakpoint.compact` from 720 to 780, and add `breakpoint.narrow` 798 and `breakpoint.column` 1120. Today's `medium` (1024) and `wide` stay for web. Add the sticky item bar and the z-index tokens to D6.
