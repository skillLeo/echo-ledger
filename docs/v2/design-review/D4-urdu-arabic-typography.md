# D4 — Urdu and Arabic typography, right-to-left and mixed lines

Owner: Design. For: the classic fixes (bugs D-163 and the F1-visual follow-ups) and the v2 screens on all three platforms.
Status: rules v1, 2026-11-21. The tokens are in tokens/global/type.json and are built for web, desktop and Android.

## 1. Fonts
| script | face | where it comes from |
|---|---|---|
| Latin, digits, Roman Urdu | Inter (the app face) | bundled (D1) |
| Urdu | Noto Nastaliq Urdu | bundled, with `unicode-range` limited to Arabic-script letters (desktop/src/styles.css:10) |
| Arabic | Noto Naskh Arabic, then IBM Plex Sans Arabic | bundled, same range |

- **Which language a text is.** Urdu and Arabic share one script, so decide in this order:
  1. UI strings use the app language. A transcript uses its transcript language.
  2. Free text (titles, notes, names, previews) is decided from its letters:
     - **no Arabic-script letter** → Latin: LTR, the app's sans face, even in the Urdu UI (this matches EchoV2Theme `content()`);
     - **Arabic script with an Urdu-only letter** (ٹ ڈ ڑ ں ے ۓ ھ ہ ی ک گ پ چ ژ) → Urdu;
     - **other Arabic-script text** → the app language if it is ur or ar; otherwise Arabic.
- **How each platform picks the face.** Screens never name a family; the shared type styles do it.
  - Desktop classic: `--font-sans` is Inter first (styles.css:103). The Noto faces are `@font-face` rules limited to Arabic-script letters (styles.css:10–11), and `[lang="ur"]` / `[lang="ar"]` rules put Nastaliq or Naskh first for that element. Latin letters inside keep Inter.
  - Desktop/web v2: `.el-root:lang(ur)` and `[lang="ar"]` switch to the `font.family.urdu` / `.arabic` token stacks (v2.css). Those stacks end in the system sans, so Latin inside Urdu currently falls to the system face, not Inter. **Follow-up (Design, D1):** put Inter after the Noto face in both token stacks.
  - Android v2: `EchoV2Type` sets the family explicitly. Urdu UI uses the bundled `noto_nastaliq_urdu`, Latin-only content switches to the bundled Inter (`latin`), and Arabic content must use the bundled `noto_naskh_arabic`. Never rely on `localeList` alone: it lets the system pick its own Arabic-script font.
- The phone's decorative system font is never used for Arabic script, even when "Use phone font" is on. That switch changes the Latin face only (D1).

## 2. Sizes and line heights (tokens)
Nastaliq has tall ascenders, deep descenders and a sloping baseline. At the same pixel size it reads about 2 px smaller than Latin, so it needs about 1.9–2× line height. Naskh needs about 1.7×.

| role | Latin | Urdu (Nastaliq) | Arabic (Naskh) | token |
|---|---|---|---|---|
| title.l | 24/32 600 | 24/44 600 | 24/36 600 | `text.urdu-title-l`, `text.arabic-title-l` |
| title.m | 20/28 600 | 20/40 600 | 20/32 600 | `…-title-m` |
| title.s | 16/24 600 | 16/30 600 | 16/26 600 | `…-title-s` |
| body | 14/20 400 | **16/30** 400 | 15/26 400 | `text.urdu-body`, `text.arabic-body` |
| caption / meta | 12/16 400 | **14/28** 400 | 13/22 400 | `…-caption` |
| transcript, reading | 15/24 | 19/38 | 18/30 | `…-transcript` |

- **Urdu text is never smaller than 14 px.** Nastaliq at 12 px can't be read. Captions, chips, badges and tab labels all use `urdu-caption` at the least.
- **Weight:** 400 and 600 only. Nastaliq at 700 fills in its counters.
- **Never** apply letter-spacing, `text-transform`, italics or synthetic bold to Arabic script. Letter-spacing breaks the joins. Show emphasis with weight 600 or colour, never italics.
- **Phone:** the same values in sp. They scale with the system text size and the app's Text size choice, so line heights scale too. Never pin a line height in dp.

## 3. Containers: no clipping
1. **No fixed height on anything that holds text.** Use `min-height` / `Modifier.heightIn(min = …)` with block padding. A fixed 32 px chip clips Nastaliq descenders, and so does a 40 px tab.
2. A single-line control's height is its token line height plus padding. Example: an Urdu chip is 28 + 2×4 = 36 px; the Latin v2 chip is 28 (v2.css:291–293).
3. Don't use `overflow: hidden` / `clipToBounds` on a text's block axis unless the box is at least one full token line tall. Horizontal clipping for an ellipsis is fine.
4. Icons next to Urdu text centre on the line box, not the baseline. Nastaliq's baseline is low, so baseline alignment drops the icon.
5. Segmented controls and tabs with mixed scripts (for example "Roman Urdu · اردو · Original") take the tallest line height of their labels, so every segment has the same height. On desktop, styles.css:360 sets `line-height: 1` on the Urdu segment. That clips; use the `urdu-caption` line instead.

## 4. Direction
- **The page direction comes from the app language.** Set `dir="rtl"` on the root on web/desktop and `LayoutDirection.Rtl` on Android. Layout uses logical properties only: start/end, `margin-inline`, `padding-inline`, `inset-inline`.
- **Each piece of free text gets its own direction from its content.** Titles, notes, transcript lines, banner text, contact names, search results and input fields all need this.
  - Web/desktop: `dir="auto"` on the element that holds the text. For a paragraph mixing sentences, `unicode-bidi: plaintext`.
  - Android: `TextDirection.Content` in Compose (`style.copy(textDirection = TextDirection.Content)`), or `BidiFormatter.unicodeWrap` for text joined into a string.
  - This is the fix for D-163. An English sentence in an RTL window currently takes the window's direction, so its full stop jumps to the start: ".console to try the next engine".
- **UI strings in the app language take the page direction.** They need nothing extra, except for the values put into them.
- **Every value put into a translated sentence is isolated** (D11). This covers names, file and project names, months, the search text, counts with units, and links. Use U+2068 … U+2069 around the value, or `<bdi>` in markup.
  - **Why:** the web console sets `unicode-bidi: plaintext` on text elements in RTL, so English lines keep their own order. plaintext takes a line's direction from its first strong letter, and a sentence that starts with a Latin value ("Qureshi Textiles میں تلاش کریں") would turn LTR.
  - **Where to do it:** once, in the translator. When the locale is ur or ar, wrap each `:replacement` before it goes into the sentence. Then no view has to remember. Until then, views isolate their own values, as W-209b does.

## 5. Things that stay left-to-right inside RTL
Wrap each of these in an LTR isolate (LRI U+2066 … PDI U+2069): web/desktop `<bdi dir="ltr">` or the `.el-num` class (`direction: ltr; unicode-bidi: isolate`); Android `BidiFormatter.getInstance(true).unicodeWrap(s, TextDirectionHeuristicsCompat.LTR)` or `⁦…⁩`.
- times and durations (`8:12 AM`, `0:00 / 2:15`, `42:10`), speeds (`1×`), dates written with digits, percentages, file sizes;
- phone numbers (`+92 300 1234567`), emails, URLs, file names, version numbers;
- keyboard shortcuts (`Ctrl + Shift + D`, `Ctrl+K`);
- Roman Urdu and English brand names inside an Urdu sentence (WhatsApp, Microsoft Teams).

**Digits:** use Western digits 0–9 in every language, including the Urdu and Arabic UI. That's how Pakistani users write times and money, and it keeps timestamps, durations and phone numbers identical across languages. Format dates through the locale with Latin digits (`ur-PK-u-nu-latn`, `ar-u-nu-latn`): "20 اکتوبر 2026".

## 5a. Urdu and Arabic punctuation
- In ur/ar strings use the script's own marks: full stop ۔ (Urdu, U+06D4) or . (Arabic), comma ، (U+060C), question mark ؟ (U+061F), semicolon ؛ (U+061B). Never use the Latin ? or , in Urdu copy.
- Inside a Latin isolate (a time, an email), punctuation stays Latin.
- String reviews (the v2 strings_v2_* and the web/desktop ur files) check for this; a Latin "?" in an ur string is a copy bug.

## 6. Truncation
- The ellipsis goes at the **logical end of the text's own direction.** An Urdu title is cut on the left; an English title in an Urdu window is cut on the right. That only works when the element itself has the text's direction (section 4). An element that inherits the page direction cuts at the wrong end, which is the "Urdu titles cut at the wrong end" bug.
  - Web/desktop: `dir="auto"` + `overflow: hidden; text-overflow: ellipsis; white-space: nowrap` on the same element (not on a parent).
  - Android: `maxLines = 1, overflow = TextOverflow.Ellipsis` with `TextDirection.Content`.
- **Mixed titles** (for example "Payment plan یں loan … next month ki installments"): the title's own direction is set by its first strong letter (dir=auto), and the cut follows that. That is correct. Don't force RTL on mixed titles.
- **Phone list rows:** an Urdu title may use 2 lines (`maxLines = 2`) before it gets cut. On a 360 dp phone, a one-line Nastaliq title rarely holds more than 3–4 words.
- Never cut inside a bidi isolate (a time, a number). Isolates go in a fixed, non-shrinking slot (`flex: none`) next to the title.
- Select values and menu items follow the same rule. "…Export" in RTL (F1-visual 6) is fixed by giving the option text `dir="auto"`.

## 7. What mirrors and what doesn't
| mirrors in RTL | never mirrors |
|---|---|
| Back / forward arrows, chevrons, the disclosure "›" in settings rows | media transport (skip back, play, skip forward), the waveform and its progress, the seek bar |
| Sidebar, rail, list row order (icon → text → meta), the toast's Undo position | clocks, timers, the recording dot, checkmarks |
| "Reply"/"Undo"-style curved arrows, the indent of nested items | logos, brand marks, source icons, the phone/laptop device badge |
| Progress bars that mean "steps" | charts' time axes (time runs left to right in every language) |

Desktop: `[dir=rtl] .icon-directional { transform: scaleX(-1) }`. Only icons marked directional in the icon map flip (D3 adds a `directional: true` flag). Android: `Icons.AutoMirrored.*`, or `Modifier.scale(scaleX = -1f)` for custom icons when `LocalLayoutDirection` is Rtl.

## 8. Inputs
- Text fields: `dir="auto"` and `text-align: start`. The typed text decides the direction as it is written. On Android, `TextDirection.Content`.
- The placeholder is in the app language, so it takes the page direction.
- The search field sits in the Urdu layout and searches both scripts. Highlight hits inside an isolate so the highlight span doesn't reorder the line.

## 9. Transcript lines
- Each line gets its own direction (`dir="auto"` per segment). The speaker name gets its own `dir="auto"` (Urdu names stay RTL), and only the timestamp is an LTR isolate.
- Urdu lines use `urdu-transcript` (19/38), with 8 px between lines of the same speaker and 16 px when the speaker changes.
- Roman Urdu transcripts are Latin text: LTR and the app's sans face, even in the Urdu UI.

## 10. Check list (every screen, EN + UR, light + dark)
1. No clipped ascenders or descenders. Check chips, tabs, segmented controls, buttons, badges and list titles at text 100 % and 200 %.
2. Ellipses are at each text's own logical end.
3. Times, durations, numbers, phone numbers and shortcuts read the same as in EN ("0:00 / 2:15", not "2:15 / 0:00").
4. English sentences in the Urdu UI keep their full stop at the end.
5. The player, the waveform and logos are not mirrored. Back arrows and chevrons are.
6. No Urdu text smaller than 14 px.
7. Use the real sample strings, not lorem: the Urdu and mixed titles in the desktop sample data (F1) and the phone gate3 samples.

Automatic checks (Fixes; widthcheck.mjs exists on b-desktop-ux / F1 and lands with it):
- A text element whose `scrollHeight > clientHeight + 1` with `overflow-y` not visible counts as clipped.
- A time-like string (`\d+:\d{2}`) whose visual order differs from its logical order counts as reversed.
- Any `font-size` under 14 px on a `:lang(ur)` element counts as too small, except LTR isolates (`.el-num`, `bdi[dir=ltr]`, `[dir=ltr]`), which keep the Latin size.

## 11. Hand-off
**Fixes (classic desktop: styles.css and views; lock per bug):**
- D-163: `dir="auto"` on banner text, page descriptions, the Re-transcribe notice, transcript segments and every list title (section 4).
- Isolates for times, durations, speed, phone numbers and the AM/PM time in list rows (section 5). The player's time readout gets `dir="ltr"`.
- Player transport + waveform `dir="ltr"` (section 7). Settings row chevrons flip in RTL.
- styles.css:360: the Urdu segment's `line-height: 1` → `var(--el-text-urdu-caption-line)` (section 3.5). styles.css:356 `height: 30px` on the segmented control → `min-height` (section 3.1). **Visual change.**
- styles.css:12–13: transcript sizes → `--el-text-urdu-transcript-*` (19/38, the same values) and `--el-text-arabic-transcript-*` (18/30, which is 0.6 px tighter than today's 18 × 1.7 = 30.6). **Visual change (Arabic only, tiny).**
- Add the three automatic checks above to widthcheck.mjs once F1 lands.

**Screens (v2, all platforms):**
- Phone list titles: `TextDirection.Content` + `maxLines = 2` for Urdu. Dates through the Latin-digit locale (this also covers U1-U4-visual point 11).
- Use `.el-num` / `<bdi>` for every time and number on desktop/web v2 screens.
- Phone: BidiFormatter for joined strings ("WhatsApp call · 5:12").

**Design (phone theme; after b-phone-parity merges, because that branch rewrites EchoV2Theme.kt):**
- EchoV2Theme.kt `face()` scales every Urdu line by 30/16. That gives title.l 45 and title.m 37.5 instead of the tokens' 44/40, and leaves the Urdu caption at 12 sp. Map them to the tokens instead: titleL/M/S → `urduTitleL/M/S`, caption/captionStrong → `urduCaption` (14/28), meta → 14/28, body → `urduBody`. Add `arabic*` styles that use the bundled Naskh. **Visual change**: Urdu captions grow from 12 to 14 sp, so phone pictures need re-shooting (the screens are shot again).

**Design (this branch):** the Urdu/Arabic tokens for title, caption and transcript; v2.css Urdu titles and captions now read the tokens (same values as before) and gain the Arabic equivalents. v2.css: every element that used the 12 px caption size (small buttons, field hints and errors, badges, segment notes, markdown tables, swatches, the palette, table headers, sub-nav items, row labels) now uses urdu-caption 14/28 under `:lang(ur)`. **Visual change**: re-shoot the gallery in UR. Next with D3: the icon map's `directional` flag. Next with D6: shared components that carry `dir="auto"` and the isolates, so screens don't have to.
