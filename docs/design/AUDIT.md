# Android design audit (before Phase C)

Rendered on the laptop (Robolectric, 393 × 852 dp, hdpi) from `ScreenshotsTest`; images in `docs/design/before/`
(light and dark). Real-phone screenshots follow when the phone is free: the phone adds its own system font (Samsung's
handwriting-style font today), which these renders do not show.

## Everywhere
- No own typeface: text takes the phone's font family, so hierarchy depends on whatever font is set.
- Accent is violet and used for links, icons, filters and buttons alike: nothing stands out as the one primary action.
- Three radii (8, 12, 16), no shared row, chip, banner or empty-state component; each screen builds its own.
- Bottom bar: three items with the default Material pill indicator; no centre record action.
- No motion beyond defaults; no designed loading or error states.

## Library
- Header: title + subtitle sentence + bell, trash and refresh icons (four things before any content).
- Outlined, square search field; filters and sort are separate controls with a "4 recordings" counter and a checkbox.
- Every row: an always-visible checkbox, a device icon and a green dot (status shown when nothing needs attention).
- Full-width heavy dividers; no date groups; date repeated on every row; generic tinted squares instead of app icons.
- Empty state is a sentence, not a designed state.

## Recording page
- Six actions compete above the transcript (Export, Share link, Re-transcribe as, Copy text, Copy with timestamps,
  Copy for AI), as outlined pills and violet links of different sizes.
- Source shown twice (device chip + app chip); delete in red at the top bar.
- Transcript lines are small with fixed columns (time, speaker, text); no speaker blocks, no player docked at the bottom.
- The "Organise" card (client, labels, keep forever, pin) sits under the transcript as a heavy bordered box.

## Record
- A large violet "REC" circle and two cards on an otherwise empty page; the mode choices (voice note, calls, game,
  watch) are not visible.

## Settings
- Rectangular outlined search; plain violet glyph icons without tiles; one long card. Structure is sound.

## Onboarding and lock
- Plain stacked text and buttons; the lock screen has no app mark and a small unlock control.
