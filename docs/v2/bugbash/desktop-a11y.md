# Desktop v2 accessibility pass, Day 5 (2026-11-17)

Every v2 screen on the desktop, in light and dark: an automated axe-core check, a check that every control has a name a
screen reader can read, and keyboard-only walks of the five most common tasks. Run again with
`node src/v2/gallery/a11y.mjs --out=DIR` from `desktop/` (`--only=NAME` for one screen). The results of this run are in
`desktop-a11y/report.json`, with a screenshot at the end of each walk.

## Screens

20 screens, each in light and dark: 40 pages at 1280 × 800.

- **Gallery** (Gate 3 samples): Library in English and in Urdu (right to left), its loading and error states, the
  recording page and its empty state, Settings, and the Live Assist card.
- **App** (fixture mode, every v2 flag on): Library, a recording, Trash, Home, Live, the Live Assist prep, Ask, a
  project's Recordings and its Memory & instructions, Settings, Setup & health, and the recording pill with the after-call
  card.

On the controls screen only the v2 pill and after-call card are checked. Today's caption overlay shares that window and
is not part of v2, so its findings are listed apart.

## Automated check (axe-core 4.14.0)

Rules: WCAG 2.0, 2.1 and 2.2 A and AA, and axe's best practices.

| | Pages | Critical | Serious | Moderate | Minor |
|---|---|---|---|---|---|
| Before the fixes | 40 | 0 | 2 rules | 1 rule | 0 |
| **After the fixes** | **40** | **0** | **0** | **0** | **0** |

Before the fixes there were 2 serious rules:

- `list`: a list's section headings were `<li role="presentation">`, so the list held something other than items. This
  was on every page with a grouped list.
- `label-content-name-mismatch`: the sidebar's profile button showed "Ayesha Siddiqui, Pro" but was named "…. Opens
  Settings.", so voice control users couldn't say what they saw.

There was 1 moderate rule:

- `page-has-heading-one`: the Live Assist card's window had no heading.

Today's caption overlay, which is not v2 and is shown here only for the record, has one serious finding:
`color-contrast` on the speaker name of a partial caption in light (`.partial > .spk-2.who`, 3.49:1). It is logged as
D-149.

## Screen-reader names

Every button, link, field, switch, checkbox, tab, menu item and select in the v2 screens was checked for a name a
screen reader reads: its accessible name from text, a label, `aria-label` or `aria-labelledby`. **0 unnamed controls on
all 40 pages.** Icon-only buttons (for example a file's Remove button, Copy answer and Hide the card) have names that say
what they act on.

## Keyboard-only walks

Each walk starts at the top of the page and uses only Tab, Shift+Tab, Enter, Space and the arrow keys. At each stop the
walk checks that focus is visible: a focus ring on the control, or on the field's frame for text fields. It then checks
that the app was asked to do the task (the command sent to the core). All 5 walks pass, plus a sixth for Undo from the keyboard (D-150).

| Task | Keys | Result |
|---|---|---|
| Record | Tab ×1 to Home, Enter; Tab ×6 to Record now, Enter | Recording starts (`record_now`) |
| Find a recording | Tab ×9 to Search recordings, type "contract"; Tab ×6 to "Contract review", Enter | The recording opens |
| Transcribe | Tab ×12 to the Untranscribed filter, Enter; Tab ×3 to the call, Enter; Tab ×9 to Transcribe, Enter | Transcription starts (`session_retranscribe`) |
| Delete with Undo | Tab ×14 to "Weekly sync with Northwind", Enter; Tab ×4 to Delete, Enter; Tab ×8 to Undo, Enter | Deleted, then restored (`delete_session`, then `session_restore`). Focus returns to the restored recording |
| Undo from the keyboard | After Delete: F6 to the toast, F6 back to the next recording; then Cmd/Ctrl+Z | F6 moves focus to Undo and back; Cmd/Ctrl+Z restores the recording (`session_restore`) without moving focus |
| Change a setting | Tab ×7 to Search settings, type "captions"; Tab ×2 to the result, Enter; Tab ×1 to the switch, Space | The setting is saved (`update_settings`) |

Screenshots: `desktop-a11y/keys-record.png`, `keys-find.png`, `keys-transcribe.png`, `keys-delete-undo.png`,
`keys-undo-keys.png`, `keys-setting.png`.

The first run of the delete walk found that focus was lost after Delete and after Undo. The deleted row and then the
toast left the page, so focus fell back to the top of the window, and reaching Undo took 16 Tabs. Now focus moves to the
next recording after Delete, or to the search field when the list is empty, and back to the restored recording after
Undo. The toast waits while the pointer or focus is on it.

## Findings

| Id | Sev | Where | What | Status |
|---|---|---|---|---|
| D-145 | S2 | Grouped lists (Library, Trash, Settings, projects) | Section headings broke the list structure; screen readers announced the wrong number of items | Fixed in f408f2a6 |
| D-146 | S2 | Sidebar profile button | Its spoken name didn't start with the name it shows (voice control) | Fixed in f408f2a6 |
| D-147 | S2 | Library, Trash | Keyboard focus was lost after Delete and after Undo | Fixed in f408f2a6 |
| D-148 | S4 | Live Assist card window | No page heading for screen-reader navigation | Fixed in f408f2a6 |
| D-149 | S3 | Caption overlay (today's, not v2) | The speaker name on a partial caption is 3.49:1 in light; it should be 4.5:1 | Logged |
| D-150 | S3 | Toast with Undo | From the next row, Undo was 8 Tabs away (the toast is last in the page), with no shortcut | Fixed in 8e7c39c0: Cmd+Z (Ctrl+Z on Windows) undoes while the toast shows; F6 moves focus to the toast and back |

No S0 or S1. The tests now cover focus after Delete and after Undo (`v2/library/library.test.tsx`) and the profile
button's name and description (`v2/shell/shell.test.tsx`).
