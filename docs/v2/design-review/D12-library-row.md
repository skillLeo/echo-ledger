# D12: the Library row (desktop, web, phone)

Owner: EL-Design, 2026-11-21. Asked for by Hassam (S2): "the list is too compacted, make it professional; the icons are not perfect".
Before: ~/EchoLedgerTeam/handover/D12/hassam-library-rows-before.png (desktop v2, Pinned + Today).
The components and tokens change in my folders; EL-UX switches the screens to them.

## What was wrong (the before picture)
1. The rows run into each other: no separation, and title, snippet and time all crowd one band.
2. The snippet is the raw transcript: "Client: Hello… Me: the time. Client: …", "Speaker: .", and Urdu with "Speaker:" labels jumbled across it in RTL.
3. The pin glyph sits inside the snippet, so it reads as text.
4. The end column mixes faces and sizes: the date is small, the duration is large monospace ("1:19"), and the times use "5:09 am" in yet another style.
5. The marks: the voice note is a heavy dark-grey circle with a mic, and the device badge is cut into the logo's edge.

## The row
```
| [mark 40] 16 | Title (strong, 1 line, its own direction)        [slot]  12 | date      |
|              | Snippet: first meaningful sentence (1 line, secondary)        | duration  |
```
- **Height and padding:** 72 px comfortable and 56 px compact (desktop and web). The phone keeps 72 dp. Block padding is 12 px. The mark centres on the two lines.
- **Separation:** a hairline divider (border.default) between rows, inset to start at the text (16 + 40 + 16 = 72 px from the start edge). There's no divider after a section's last row.
- **Hover and focus** (desktop and web): the whole row gets bg-subtle on an 8 px radius, with the list inset 8 px on both sides so the radius shows. A selected row gets accent-soft on the same radius.
- **Line 1, the title:** title.s strong (16/24, weight 600, `--el-text-title-s-*`, the same on desktop, web and phone), text-primary, one line with an ellipsis, `dir="auto"`. From 150 % text it may take 2 lines (phone).
- **Line 2, the snippet:** body 14/20 in text-secondary, one line with an ellipsis, `dir="auto"`. See "The snippet" below. If there's no snippet, line 2 shows the source and project ("WhatsApp · Acme Holdings"), and a recording that's still being processed shows its state badge there.
- **The indicator slot:** a fixed area between the text and the end column, always in this order: unread dot, pin, important flag, then up to 3 label dots.
  - Glyphs are 16 px with a 1.75 stroke. Dots are 8 px with a 4 px gap.
  - Pin and flag are drawn in text-secondary; important is warning amber. Nothing in the slot ever goes into the snippet.
  - When the slot is empty it takes no room.
- **The end column:** date on line 1 and duration on line 2, aligned to the end and baseline-aligned with the two lines.
  - Both are caption 12/16 in text-secondary, in the same face as the row (not mono), with `font-variant-numeric: tabular-nums` so columns line up.
  - Times today read "5:09 am", earlier days "15 Nov", other years "15 Nov 2025". The locale formatter uses Latin digits (D4 §5), and both values are LTR isolates.

## The snippet: the first meaningful sentence
One rule, implemented once per platform (`firstSentence(preview)`):
1. Split the preview into utterances at speaker labels. A label is up to 24 letters, digits, spaces or brackets (starting with a letter) followed by ": " at the start or after a sentence end ("Client:", "Me:", "Speaker:", "Sara (Acme):", "مقرر:").
2. Drop the labels. Never show "Speaker:", "Client:" or "Me:" in a row.
3. Skip utterances that are empty, only punctuation, or under 3 words ("the time.", "Hello.", "Yeah").
4. Take the first remaining utterance and cut it at its first sentence end (. ? ! ۔ ؟). If it's longer than the line, the ellipsis does the rest.
5. If nothing is left, show a quiet "No speech" (text-secondary) for a finished transcript, and nothing while it's still transcribing.

Examples from the before picture:
- "Client: Hello, can you understand? Me: the time. Client: Is everything fine there?…" → "Hello, can you understand?"
- "Speaker: ." → "No speech"
- "Speaker: میسج کا آپ کو میں کیا جواب دوں Speaker: …" → "میسج کا آپ کو میں کیا جواب دوں" (RTL, its own direction)

## The marks (icons)
- **Size:** source marks are 40 px on desktop and web and 40 dp on the phone, the app's own logo from the shared set.
- **Device badge:** a 16 px chip at the bottom-end corner, offset 2 px outside the mark. It has a 2 px ring in the row's background colour (`box-shadow: 0 0 0 2px var(--row-bg)` / a border in the surface colour), so the logo's edge is never cut. The glyph is 10–12 px with a 1.75 stroke in text-secondary. A phone recording shows the phone outline (not a handset); a computer shows the laptop; the web shows the globe.
- **Voice note:** a new mark instead of the dark mic circle. It's a 40 px circle in accent-soft with the audio-lines glyph (four bars) in accent-text, 20 px, 1.75 stroke. It's the app's own source, so it uses the app's own colour, calm like the others.
- **Other or unknown source:** a 40 px circle in bg-subtle with a 20 px glyph in text-secondary.
- **Quality bar for every row glyph:**
  - one icon family, 1.75 stroke, round caps;
  - optical sizes 16 (slot) and 20 (in marks);
  - drawn on the 24 grid, crisp at 1× (no half-pixel offsets in the row);
  - no glyph inside text runs.

## Tokens and components
- **Tokens:** `component.list-row.height` stays 72 (comfortable). New tokens: `list-row.gap-indicators` 4, `list-row.divider-inset` 72, and `source-mark.badge` 16.
- **Desktop:** ListRow gets an `indicators` slot (unread, pin, important, labels) and `end` lines with the tabular caption; v2.css adds the dividers, hover radius and inset; SourceLogos/SourceAvatar get the new voice-note mark and the ringed badge; components/text.ts adds `firstSentence()` with tests.
- **Web:** console.css row rules for the same layout; the source-logo partial gets the voice-note mark and the ringed badge; firstSentence in PHP is written by whoever owns the row model (EL-UX). The rule above is the contract.
- **Phone:** EchoListRow gets an `indicators` slot and the end column; EchoSourceLogo gets the voice-note mark and the ringed badge; `firstSentence()` goes in components with a test.
- **Banners (Hassam):** every platform's Banner takes an optional dismiss (×). Desktop already has `onClose`; the phone's EchoBanner and the web banner get one (`onDismiss` / a close button with its label).

## Pictures
Before/after at 1280 and 800, light/dark, EN/UR, using the before picture's sample (WhatsApp, Meet, two Urdu voice notes and an empty one): docs/v2/design-review/D12/.
