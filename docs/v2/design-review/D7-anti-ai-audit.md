# D7: anti-template-look audit of the v2 screens

Owner: EL-Design, 2026-11-21. Scope: every v2 source on v1.1 @ 167e0fd7.
- desktop/src/v2/
- mobile/app/src/main/java/com/echoledger/mobile/ui/v2/
- web/resources/views/filament/v2/, web/resources/css/v2/, web/resources/views/components/el/v2/, web/resources/views/site/v2/

The rules are in docs/v2/DESIGN_SYSTEM.md, "Rules against the template look". This audit has two parts: a code sweep (below) and what today's visual reviews found (section 2).

## 1. Code sweep (automatic, repeatable)
| rule | check | result |
|---|---|---|
| 1 No gradients | `gradient` in all v2 sources | **0** |
| 2 No purple/indigo/violet | names and #8B5CF6 / #6366F1 / #7C3AED | **0** in v2. Teams' own logo colours live in the brand marks (SourceLogos / source-logo partial), which is allowed. The server's default project colour #8B5CF6 is a separate, open decision (DESIGN_SYSTEM "Label colours"). |
| 3 No emoji | U+1F300–1FAFF, U+2600–27BF, U+2B50 in sources and strings_v2_* | **0** |
| 4 No coloured start borders | `border-left` / `border-inline-start` with a colour, Compose start strokes | **0** |
| 5 At most 3 radii | tokens only: tokensOnly.test.ts (desktop), TokensOnlyTest.php (web) | **pass** (CI) |
| 6 Shadows only on floating layers | every `box-shadow` / `shadowElevation` | **pass**. Desktop has 9, all floating: menu, toast, dialog, sheet, recording pill, after-call card, Live Assist panel, command palette, drawer. Phone has 9, all floating: dialog, sheet, toast, recording pill, after-call card, assist card. The others are hairline rings (0 0 0 1px), not shadows. |
| 8 No looping animation except the recording dot | `@keyframes`, `infinite`, `rememberInfiniteTransition` | **pass**. Only `el-pulse` (desktop v2.css:414) and RecordingDot.kt loop, and both stop under Reduce motion. `el-captured` runs twice and stops. |
| 9 No placeholder copy, no "!" | "Lorem", "John Doe", "Welcome back", "!" in strings_v2_*, en.json | **0** |
| 10 No three identical feature cards (website) | repeat(3, …) / feature cards in site/v2 | **0** |
| tokens only | phone: `Color(0x…)`, `Color.Red…`, raw `N.dp` outside tokens/ and theme/ | **0** |

To repeat the sweep, use the `grep` lines above, or the token tests, which already guard rules 1, 2 and 5 and the tokens-only rule in CI.

**One token gap (EL-Design, in D10):** the phone draws shadows with spacing tokens (`shadowElevation = EchoTokens.Space.s8` / `s16`) in Overlays.kt:71,131, Toast.kt:183, V2RecordingPill.kt:73,91,139, V2AfterCallCard.kt:97 and V2AssistCard.kt:161,192. The values look right, but the role is wrong. Fix: emit `EchoTokens.Elevation.level1/2/3` from tokens/global/elevation.json and use those. There is no visual change.

## 2. What the visual reviews found (2026-11-21)
The code sweep can't see these. All of them were found in pictures.

| # | Where | Rule | Status |
|---|---|---|---|
| 1 | Phone Library selection bar: 6 icons, title wrapped | one row, at most 4 main actions | fixed (U1–U4) |
| 2 | Phone: three "not ready" signals on one screen | badges everywhere / noise | fixed (U1–U4) |
| 3 | Phone ⋮ menu: Pin + Unpin, Keep + Allow together | one way to do one thing | fixed (U1–U4) |
| 4 | Phone secondary button with a dark outline | calm by default | fixed in D6 (Button.kt) |
| 5 | Phone chips with a "▾" text glyph | icons from the one set | fixed in D6 (EchoChip dropdown). EL-UX passes `dropdown = true` after D6 merges. |
| 6 | Phone v2 accent following the wallpaper (could turn purple) | rule 2, fixed brand colours | copy fixed (U1–U4). Removing `withWallpaper` from v2 is in D10. |
| 7 | Web Library: two filled buttons, "New voice note" primary | one primary per area | fixed (U7) |
| 8 | Desktop Shared: actions only on hover | status always visible | fixed (U6 + D9 `actionsShown="always"`) |
| 9 | Desktop Shared: "Keep" filled on every waiting call beside "Keep all" | one primary per area | **open, S3 → EL-UX** |
| 10 | Desktop: dates with words drawn in the mono face | calm typography | fixed (U6 + D9 `.el-ltr`) |
| 11 | Web tables: dark-mode badge and chip contrast | rule 11, 4.5:1 | fixed (W-206) |
| 12 | Phone Settings: three identical mic icons | one icon per meaning | **open → EL-Design D3/D10** |
| 13 | Phone at 200 % text: bottom-bar labels and the Activity title cut | no cut-off text | **open → EL-Design D10** |
| 14 | Phone: grey generic source circles instead of real logos | "real logos, never generic glyphs" | web part ready (D2 web). **Phone part open → EL-Design D2.** |

## 3. Result
The v2 code is clean on every rule that can be checked automatically. What's left is 4 open items, all S3:
- #9, owned by EL-UX;
- #12, #13 and #14, mine, in D10 and the phone part of D2;
- the phone elevation tokens (section 1), mine, in D10.

No screen needs a redesign for the template look.
