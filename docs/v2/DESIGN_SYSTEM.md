# Echo DS — the v2.0 design system

**Status: proposal for Gate 3.** The values below are the starting proposal from the v2.0 plan; Phase V2.1 turns them
into tokens/ and three sample screens, and Gate 3 chooses. Until then, docs/DESIGN_SYSTEM.md (v1.8) stays in force for
today's screens.

## Principles
1. Calm by default: neutral surfaces; colour only when it means something.
2. One way to do one thing: one component per job, one settings home per feature.
3. Status is always visible: recording, ready and needs-setup are never unclear.
4. Same product, native manners: the tree, names, icons, copy and states are the same everywhere; navigation, fonts and
   controls follow each platform.
5. Real content: real names, durations and Urdu/Arabic text in every screen and every test.
6. Bilingual from day one: every component works left-to-right and right-to-left.

## Rules against the template look (lint what can be linted; review the rest)
1. No gradients on UI surfaces or text (the website hero: at most one subtle tonal gradient, approved by Hassam).
2. No purple, indigo or violet as the brand or accent colour.
3. No emoji in labels, buttons, headings or badges.
4. No cards with a coloured left border.
5. At most 3 corner radii in use (4, 8 and 12; "full" is the pill shape of chips, avatars and the recording pill and
   does not count). The rule holds per platform: desktop and web use 4/8/12; the phone uses 4/8/16, its dialogs taking
   the sheet radius 16.
6. Shadows only on floating layers (menus, dialogs, overlays).
7. At most one badge per row; no "New" badges older than 30 days.
8. No looping animation except the recording dot.
9. No placeholder copy ("Lorem", "John Doe", "Welcome back!"), no exclamation marks in UI copy.
10. No grid of three identical feature cards on the website.
11. All text, secondary included, at least 4.5:1 contrast.
12. Every screen has a designed empty, loading and error state.
13. Untouched framework defaults (stock shadcn/Tailwind palette, Inter + indigo) count as findings.

## Tokens
One source in tokens/ (W3C design-token JSON): global values → semantic roles → component tokens. Components use only
semantic roles. Style Dictionary builds CSS variables + a Tailwind theme (web, desktop webview), Kotlin EchoTheme
(Compose) and Swift EchoTokens. CI fails when a component hard-codes a colour, size or spacing value.

### Colour roles (proposal; the accent is deliberately not purple)
| Role | Light | Dark | Use |
|---|---|---|---|
| bg.canvas | #FAFAF9 | #111113 | App background |
| bg.surface | #FFFFFF | #18181B | Panels |
| bg.subtle | #F4F4F2 | #1F1F23 | Hover rows, sidebar |
| border.default | #E4E4E0 | #2C2C31 | Hairlines |
| text.primary | #1A1A1A | #EDEDED | Body |
| text.secondary | #5C5C58 | #A1A1A6 | Meta (must pass 4.5:1) |
| accent | #0F6E6A | #3FB8AF | Primary buttons, links, focus |
| record | #D92D20 | #F97066 | Recording state only |
| success | #12805C | #4ADE80 | Ready, saved |
| warning | #B54708 | #FDB022 | Needs setup |
| danger | #B42318 | #F97066 | Destructive actions, errors |

Red is only for recording and destructive actions.

Wallpaper colours (Android dynamic colour) are not used by the redesigned screens: the accent stays teal and every
surface stays neutral, so the app looks the same on every phone and the record button never changes colour.

### Type (web and desktop; native apps map to Material 3 roles and iOS text styles)
| Token | Size / line | Weight | Use |
|---|---|---|---|
| display | 32/40 | 600 | Website and empty-state titles only |
| title.l | 24/32 | 600 | Page titles |
| title.m | 20/28 | 600 | Section titles |
| title.s | 16/24 | 600 | Card and dialog titles |
| body | 14/20 | 400 | Default text |
| body.strong | 14/20 | 600 | Labels, emphasis |
| caption | 12/16 | 400 | Meta, timestamps |
| mono | 13/20 | 400 | Durations, ADB commands |
| urdu.body | 16/30 | 400 | Noto Nastaliq Urdu |
| arabic.body | 15/26 | 400 | Noto Naskh Arabic or IBM Plex Sans Arabic |

System fonts in the native apps (SF Pro, Segoe UI Variable, Roboto). The web uses one deliberate face, not the Inter
default; chosen at Gate 3.

### Spacing, radius, elevation, motion, density
- Spacing on a 4 px base: 2, 4, 8, 12, 16, 20, 24, 32, 40, 48, 64. Page padding 24 (desktop) / 16 (mobile). Rows 56
  comfortable / 44 compact on desktop, 64–72 on mobile.
- Radius: 4 (inputs, buttons), 8 (cards, menus), 12 (dialogs; 16 for mobile sheets), full (chips, avatars, the
  recording pill only). Native components keep native shapes on iOS and Android.
- Elevation: cards flat with a border; level 1 menus, level 2 dialogs, level 3 floating overlays; soft neutral shadows.
- Motion: 100 ms (hover, press), 200 ms (open, close), 300 ms (page, sheet); ease-out in, ease-in out; springs on
  Android and iOS; Reduce motion respected. Only the recording dot loops (1.5 s), and not under Reduce motion.
- Density: Comfortable (default) and Compact (desktop and admin tables).

### Icons
One outline set for web and desktop (Lucide or Phosphor, 1.5 px stroke, 16/20/24), mapped by name to SF Symbols,
Material Symbols and Fluent icons (record, transcribe, ask, moment, shield-private…). No emoji.

### Settings icons (D3)
One map for every platform: tokens/icons/settings.json. It holds the group and page ids of docs/v2/IA.md 5, each with its Lucide name (desktop and web), its Material Outlined name (the phone) and the console's `<x-el.v2.icon>` name where one exists.
- **One meaning, one glyph.** No two groups share a glyph, and no two pages in a group share one. A group's glyph differs from its pages' glyphs; the only exception is a group with a single page (Notifications, Appearance, Setup & health). tokens/test checks all of this on desktop and on the phone.
- **Where they show.** Group headers use the accent colour; page rows use text-secondary. Both are 20 px on desktop and web, 24 dp on the phone.
- **Right to left.** Two glyphs mirror: Sign out's arrow, and Help's question mark, which reads as ؟ in Urdu and Arabic. Use AutoMirrored on the phone and `directional` on desktop. No other settings glyph mirrors.

What the screens change to match the map (owner EL-UX; the phone icons arrive in EchoIcons.kt with D10):
| where | now | map |
|---|---|---|
| desktop GOAL_GROUPS (settings/SettingsV2.tsx:21–30) | Live & overlays: captions; About & help: circle-help | radio; life-buoy |
| desktop ROOT_ICONS (components/Icons.tsx:435–439) | profile: user; security: shield | user-round-pen; fingerprint |
| phone SettingsGoal (settings/SettingsTree.kt:10–18 on ui/U1-4-visual) | Recording: Mic; Transcription: Notes; Account: AccountCircle; Setup: HealthAndSafety; About: Info | RadioButtonChecked; Description; Person; CheckCircle; Support |
| phone page icons (SettingsTree.kt:49–68) | voice: VoiceNote = Mic (three mics in a row); language: Language (globe); account: AccountCircle; plan: WorkspacePremium | GraphicEq; Translate; ManageAccounts (the map's id is profile); CreditCard |

## Components (same names and options on every platform)
Button · TextField · Select · SearchField · FilterBar · Chip · ListRow (RecordingRow, SettingRow) · Card (mixed items
only) · Table (admin and usage only) · Tabs · Segmented · Dialog/Sheet · Toast with Undo · Banner · Badge/Status ·
Toggle · Checkbox · Radio · Menu · EmptyState · Skeleton/Progress · RecordingPill · CaptionsWindow · LiveAssistPanel ·
AfterCallCard · SetupStep · HealthCheckRow · TrayPopover. Rules per component are in the v2.0 plan and become each
component's story set in V2.3.

## Dark mode and right-to-left
- Both themes from the same roles; raised surfaces get lighter, not shadowed; the system theme by default.
- Logical properties everywhere (inline start/end, Compose start/end, SwiftUI leading/trailing). Mirror directional icons,
  never media controls, clocks, checkmarks or logos. Mixed Urdu + English lines get their direction per segment
  (dir="auto" on the web, BidiFormatter on Android). Numbers, durations and Roman Urdu stay left-to-right. The app
  language and the transcript language are separate settings.
