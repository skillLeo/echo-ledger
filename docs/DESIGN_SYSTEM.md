# EchoLedger Android design system (v1.3)

Code: `mobile/app/src/main/java/com/echoledger/mobile/ui/Tokens.kt` (colour, space, radius, motion),
`ui/Theme.kt` (theme, shapes), `ui/design/` (type and components). Screens use these and nothing else.

## Principles
1. Content first: transcripts and recordings are the hero; chrome is quiet.
2. One accent: indigo marks only the primary action, the active tab and selection.
3. One primary action per screen, within thumb reach.
4. Status only when it needs the person (no "everything is fine" badges).
5. Same thing, same look, everywhere: one component per job.
6. Fast and calm: subtle motion, no surprises.

## Type
- **Inter** (bundled, variable, OFL) for all UI text; never the phone's font family unless Appearance → *Use phone
  font* is on. Sizes are `sp`, so the phone's font size setting always applies.
- Transcripts: Inter 17/28; Urdu **Noto Nastaliq Urdu** 19/38; Arabic **Noto Naskh Arabic** 18/30.
- Times and durations use tabular digits (`TextStyle.tabular()`).
- At most three sizes on a screen.

| Token | sp / line | Weight | Material role |
|---|---|---|---|
| display | 28/34 | 600 | headlineLarge (onboarding only) |
| titleL | 24/30, -0.3 | 600 | headlineSmall |
| titleM | 20/26 | 600 | titleLarge |
| titleS | 17/22 | 600 | titleMedium |
| bodyL | 16/22 | 500 | titleSmall (row titles) |
| body | 16/22 | 400 | bodyLarge (inputs) |
| bodyM | 15/22 | 400 | bodyMedium |
| labelL | 14/20 | 600 | labelLarge (buttons) |
| labelM | 13/18 | 500 | labelMedium (chips, meta) |
| caption | 12/16 | 400 | bodySmall |
| micro | 11/14 | 500 | labelSmall (nav labels) |

## Colour (Radix slate + indigo; both themes designed)
| Role | Light | Dark |
|---|---|---|
| background | #F9F9FB | #111113 |
| surface | #FFFFFF | #18191B |
| surface-muted | #F0F0F3 | #212225 |
| pressed / divider | #E8E8EC | #272A2D |
| border | #D9D9E0 | #363A3F |
| text | #1C2024 | #EDEEF0 |
| secondary | #60646C | #B0B4BA |
| tertiary | #6F727B | #80848E |
| brand | #3E63DD (pressed #3358D4) | #3E63DD (pressed #5472E4) |
| brand text | #3A5BC7 | #9EB1FF |
| brand subtle | #EDF2FE | #182449 |
| danger text / fill | #CE2C31 / #CE2C31 | #FF9592 / #CE2C31 |
| danger subtle | #FEEBEC | #3B1219 |
| warning text / fill | #AB6400 / #FFC53D | #FFCA16 / #FFC53D |
| success (rare) | #218358 | #3DD68C |

Contrast: every text colour keeps 4.5:1 on background and surface. Radix slate 9 (#8B8D98 / #696E77, the plan's
tertiary) is about 3.3:1, so tertiary text uses one step darker in light (#6F727B) and lighter in dark (#80848E). Red
text uses red 11 (#CE2C31 / #FF9592); white text sits on #CE2C31 (5.2:1), not on red 9 (#E5484D, 3.9:1). Wallpaper
colours stay off by default.

## Space, radius, elevation
- 4-pt scale: 2, 4, 8, 12, 16, 20, 24, 32, 40, 48 (`Space.s0` … `Space.s7`). Screen margin 16. Content max 640 on
  large screens.
- Radius: tags 6 · chips 10 · inputs and row tiles 12 · cards 14 · sheets and dialogs 20 · buttons and pills full.
- Lists are flat. One shadow for sheets, the record button and floating controls (light: y4 blur16 10% + y1 blur2 6%);
  dark uses surface steps and a 1 px border instead.

## Motion and haptics
Since v1.5: a tab switch fades in and moves a short way from the side the tab sits on (200 ms); a row opens its recording
with the app tile and title moving into place (300 ms); the record button changes colour and swaps mic and stop (200 ms)
and gives under a press; the after-call chip rises (300 ms) and a confirmation carries a check that pops in
(`SuccessCheck`, also in messages that confirm something). With Remove animations on, all of it is simply there.
Press 120 ms · state 200 ms (standard easing) · enter 300 ms (decelerate) · exit 150 ms (accelerate) · springs for
drag and the pill. Press feedback scales to 0.96. "Remove animations" turns motion off. Haptics on long-press,
selection change, record start/stop and destructive confirm.

## Icons
One family (since v1.5): line glyphs with round ends wherever an icon is an action (back, close, search, stop, more,
chevrons: `Icons.Rounded`, the same glyph on every screen), outlined when a thing is at rest (a list row, an inactive
tab: `Icons.Outlined`), filled or on a tinted tile when it is active or selected (`Icons.Rounded`). The desktop and the
console draw the same metaphors in line icons of the same weight. `MotionAndIconsTest` keeps the other Material sets out.
16 meta, 20 rows and chips, 24 bars, 28 record. Source apps show the installed app's own icon (PackageManager) on a 12% tint; no bundled third-party
logos.

## Notices (since v1.6.1, Hassam's rule, the same on web, desktop and phone)
Every in-app notice — a toast ("Saved", "Link copied", "Turn on two-step sign-in"), an inline banner, an in-app
alert — behaves the same way:
1. **Click or tap it → it goes where it is about** (the setting, the recording, the fix), when it is about something;
   the notice closes as it goes. A notice about nothing in particular has no target and is not clickable.
2. **× closes it** (labelled "Dismiss" for screen readers), on every notice.
3. **A timer:** a message hides by itself (6 s; an error 10 s), with a thin bar along its bottom edge showing the time
   left. The timer pauses while the pointer is over it, a finger is on it or it has keyboard focus, and starts again
   when that ends. Undo keeps its own time (5 s). **A notice about something still true** ("Calls are not being
   recorded", offline, "Please update", maintenance, a failed upload) **has no timer**: hiding a problem by itself would
   let it be missed; it still has × and swipe, and stays closed until the state changes or the next visit.
4. **Swipe left or right to close**, with a finger on a phone or a touch screen, and with a trackpad or a mouse drag on
   a laptop: the notice follows the drag and fades; past a third of its width (or a quick flick) it leaves, otherwise
   it springs back. A short drag never counts as a click.
5. Esc closes the newest toast. Notices are announced politely (`status`), errors assertively (`alert`). With Remove
   animations / reduced motion they appear and go without sliding, and the timer bar does not move (the time still
   runs).
Operating-system notifications (Android's shade, Windows and macOS notification centres) already behave this way and
are not changed.

## Controls
Switches for on/off; segmented controls for 2-4 choices; bottom sheets for long lists.

## Components (`ui/design/`)
EchoTopBar · SearchPill · FilterChip · SectionHeader · RecordingRow · SelectionBar · EchoBottomBar + RecordButton ·
BottomSheet · Buttons (primary, secondary, text, destructive) · TextField · SegmentedControl · SettingsRow ·
EmptyState · SkeletonRow · InlineBanner · UndoSnackbar · Avatar · Player · FloatingPill. Each has default, pressed,
disabled, focused, selected, loading and error states where they apply. A debug-only Design gallery shows them all in
light and dark.

## Screenshots
Laptop: Roborazzi on Robolectric (native graphics). `./gradlew recordRoborazziFullDebug` writes the PNGs to
`docs/design/screens/`, `verifyRoborazziFullDebug` fails on any change, `compareRoborazziFullDebug` writes diffs. Before
pictures of the old design: `docs/design/before/`. Real-phone screenshots are taken when the phone is free.
