# Tap audit: the 15 most common tasks

The plan's goal (v1.5 Phase 1C G, v1.6 P6): the top 10 tasks take 2 taps or clicks or fewer. Typing (a search word, a
new title) is not counted; choosing between things that cannot be guessed (which project, text or voice) is.

**Where each count starts:** the phone's Library, the desktop's main window, the console's Library page. A task that is
about one recording includes opening it.

## Phone (counted 2026-11-09, v1.6)

| # | Task | Before | After | How |
|---|---|---|---|---|
| 1 | Record a voice note now | 3 | **2** | Centre button → **Record now** (new at the top of the New sheet). A long press on the centre button starts at once (1), and so do the notification, the Quick Settings tile, the app shortcut and the widget (1 each). |
| 2 | Stop a recording | 1 | 1 | The centre button, the notification, the tile or the widget. |
| 3 | Open the latest recording | 1 | 1 | Tap its row. |
| 4 | Read it in Roman Urdu or English | 2 | 2 | Open → the view's chip. The console's default view opens by itself (1). |
| 5 | Copy the transcript | 2 | 2 | Open → **Copy** in the top bar. |
| 6 | Send it to WhatsApp | 3 | 3 | Open → **Send to WhatsApp** → Text only, Text and voice, or Link. The choice is needed; from the recording page, where it is read first, it is 2. |
| 7 | Find a recording by a word in it | 2 | 2 | Search → (type) → the result. |
| 8 | Keep or discard after a call (Ask me) | 1 | 1 | The chip's ✓ or ✕; Keep automatically (the default) needs none. |
| 9 | Retry a failed transcription | 1 | 1 | Tap the row ("Transcription failed · Tap to retry"). |
| 10 | Fix "Not ready" | 2 | 2 | The chip → the problem's one fix (Start, Allow, Turn on). From the widget or the alert: 1 + the fix. |
| 11 | Rename a recording | 3 | 3 | Open → the title → (type) → Save. |
| 12 | Move a recording to a project | 3 | 3 | Long press → Move → the project. |
| 13 | Delete a recording | 1 | 1 | Swipe it away (Undo for 5 s). |
| 14 | Play from a line | 3 | 3 | Open → the line (moves the player there) → Play. |
| 15 | Group or sort the Library | — | 2 | The View button → Group by, Sort by or Density (new in v1.6). |

Top 10 at 2 or fewer: 9 of 10. The exception is **Send to WhatsApp** (3 from the Library, 2 from the recording): the
three ways of sending are different things, so the app asks rather than guesses.

## Desktop (counted 2026-11-09, v1.6 app shell)

Before: the v1.6 desktop before the shell (`c697c2f`: sidebar Library · Projects · Record · Activity · Settings). After:
the shell (`v16-desktop-shell`: Home · Library · Projects · Activity · Shared · Upload, the top bar, the Library's
column). Clicks only; a key that does the same is named in How.

| # | Task | Before | After | How |
|---|---|---|---|---|
| 1 | Record a voice note now | 2 | **2** | **+** → **Record a voice note**, or Home → **Record a voice note** (before: Record → Record a voice note). **N** anywhere in the window and the global Record now shortcut start one with no click. |
| 2 | Stop a recording | 1 | 1 | The pill's stop. The top bar now shows the recording and its time; **N** stops a voice note. |
| 3 | Open the latest recording | 1 | 1 | Its row in the Library, or under **Recent recordings** on Home (new). |
| 4 | Read it in Roman Urdu or English | 2 | 2 | Open → the view in **Show as**. The console's default view opens by itself (1). |
| 5 | Copy the transcript | 2 | 2 | Open → **Copy text**. |
| 6 | Send it to WhatsApp | 4 | **3** | The row's **⋯** → **Send to WhatsApp…** → Text only, Text and voice, or Link (7 days); or open → the WhatsApp button → the choice. From the open recording it is 2. Before (a share link): open → **Share** → **Make link** → **Copy link**. |
| 7 | Find a recording by a word in it | 2 | 2 | The Library's search (**/** focuses it) or the top bar's search (**Ctrl/⌘+K**) → (type) → the match, which plays from that moment. |
| 8 | Keep or discard after a call (Ask me) | 1 | 1 | The after-call chip's **Keep** or **Discard**; Keep automatically (the default) needs none. |
| 9 | Retry a failed transcription | 2 | 2 | Open → **Try again** in the failed notice (new; before: the Re-transcribe button in the header, 2, easy to miss). |
| 10 | Fix "Not ready" | 2 | 2 | **Not ready — Fix** in the top bar (was the sidebar) → the problem's one fix on Home. |
| 11 | Rename a recording | 2 | 2 | Open → the title → (type) → Enter; or the row's **⋯** → **Rename**. |
| 12 | Move a recording to a project | 1 | 1 | Drag the row onto the project: in the Library's column (every project) or a pinned project in the sidebar (before: the sidebar's tree). **⋯** → **Move…** → the project → **Move** is 4. When the window is too narrow for the column, only pinned projects and the Inbox (in the sidebar's Library) take drops. |
| 13 | Delete a recording | 3 | 3 | The row's **⋯** → **Delete** → confirm (Recently deleted keeps it 48 hours). |
| 14 | Play from a line | 2 | 2 | Open → the line (it plays from there). |
| 15 | Group or sort the Library | 2 | 2 | **View** → the choice (Group by, Sort by and Density in one menu, the phone's names; before: the Group by or Sort by menu). |

Top 10 at 2 or fewer: 9 of 10, as on the phone. The exception is **Send to WhatsApp**: 3 from the Library, 2 from the
open recording. The three ways of sending are different things, so the app asks rather than guesses.

Other paths the shell shortened: **Plan & usage** is 2 from any page (the avatar menu; before Settings → Account and
scrolling), **Devices** is one search away (**Ctrl/⌘+K** "devices", or **Find a page**) or 2 (Account → Devices; before
Settings → Account and scrolling), **Shared with me** is 1 (the sidebar's Shared → With me), a request link and a new
project are 2 from any page (**+**), and **Help & shortcuts** is 1 (**?**).

## Console (counted 2026-11-09, v1.6 P5, before the console shell)

Before: the console at `fe9a1a3` (three rows of chips, the Bulk actions menu, no WhatsApp). After: branch
`v16-web-library` (P5.2–P5.5: one Library toolbar, the selection bar, Failed uploads explained, Send to WhatsApp). The
console's app shell (sidebar, top bar) comes in a later pass and is not counted here. Clicks from the Library page;
choosing a file in the browser's file dialog is not counted.

| # | Task | Before | After | How |
|---|---|---|---|---|
| 1 | Record a voice note now | — | — | The console does not record: the phone or the computer does. Its nearest is **Upload** (1, then the file); an empty Library offers **Upload a voice note** too (new). |
| 2 | Stop a recording | — | — | On the device that records. |
| 3 | Open the latest recording | 1 | 1 | Its row (on a wide screen it shows beside the list; **Open** there is 2). |
| 4 | Read it in Roman Urdu or English | 1 | 1 | Open: the default view opens by itself. Another view: the player's **Show as** (2). |
| 5 | Copy the transcript | 2 | 2 | Open → **Copy text** (or **Copy for AI**). |
| 6 | Send it to WhatsApp | 4 | **3** | The row's **More** → **Send to WhatsApp…** → Text only, Text and voice, or Link (7 days); from the open recording, **WhatsApp** → the choice (2). Before (a share link): open → **Share** → **Make the link** → copy it from the notice, then open WhatsApp yourself. |
| 7 | Find a recording by a word in it | 2 | 2 | **Search** → (type) → the result; **Ctrl/⌘+K** → (type) → the result is 1. The Library's own search box looks at titles and projects. |
| 8 | Keep or discard after a call (Ask me) | — | — | The phone or computer that recorded it decides; the Library's "2 calls waiting · Review" shows them. |
| 9 | Retry a failed transcription | 4 | **2** | **Failed uploads (2) · See why** → **Retry** (new). Before: open → **⋮** → **Transcribe again** → confirm. |
| 10 | Fix "Not ready" | — | — | On the device (its Ready check). |
| 11 | Rename a recording | 3 | 3 | Open → the pencil by the title → (type) → **Save**; or the row's **More** → **Rename** → **Save**. |
| 12 | Move a recording to a project | 5 | **4** | Its box → **Move** in the selection bar → the project → **Move** (before: its box → **Bulk actions** → **Move to project** → the project → confirm). On the recording page **File under** is 4 too. |
| 13 | Delete a recording | 3 | 3 | The row's **More** → **Delete** → confirm (Recently deleted keeps it 48 hours); in Failed uploads **Delete** → confirm (2). |
| 14 | Play from a line | 2 | 2 | Open → the line (it plays from there). |
| 15 | Group or sort the Library | 2 | 2 | **Group by** or **Sort by** in the toolbar → the choice (the phone's and the desktop's names). |

Of the top 10, the console does 6 itself (the others happen on the device that records), and 5 of those take 2 clicks or
fewer. The exception is **Send to WhatsApp** (3 from the Library, 2 from the open recording), as on the phone and the
desktop: the three ways of sending are different things, so the console asks rather than guesses.

What the declutter cost: a smart view or folder is now 2 clicks (the **All recordings** or **All folders** menu → the
choice) where its chip was 1. In return the list starts three rows higher, the toolbar is one line on a phone, and
**Failed uploads** says itself above the list when something failed (1 click to see why).

## Console (counted 2026-11-09, v1.6 app shell)

Before: the console of the P5 pass above (`c686ade`: the one Library toolbar, the selection bar, no shell). After: branch
`v16-web-shell` (the sidebar with Find a page, the top bar with + and Ctrl/⌘+K, the Library's column, the drawer on
the right, Account in tabs, the phone's bottom tabs). Clicks from the Library page on a wide screen (1440 px) unless
said; a key that does the same is named in How.

| # | Task | Before | After | How |
|---|---|---|---|---|
| 1 | Record a voice note now | — | **2** (phone) | On a phone's browser: **Record** (bottom tab) → **Record a voice note**: the phone's recorder opens and the note uploads when it stops. On a computer the console still does not record (**Ctrl/⌘+K** "record" opens the apps' page); **Upload** is 1 + the file. |
| 2 | Stop a recording | — | — | The phone's recorder, or the device that records. |
| 3 | Open the latest recording | 1 | 1 | Its row: it opens in the drawer on the right (**Enter** after **J**/**K**); **Open** there is the full page (2). |
| 4 | Read it in Roman Urdu or English | 1 | 1 | The drawer shows the start of the transcript in the view it opens in (Roman Urdu for Urdu), and says which. Another view: **Open** → **Show as** (3). |
| 5 | Copy the transcript | 2 | 2 | Row → **Copy text** in the drawer (the whole transcript in that view). |
| 6 | Send it to WhatsApp | 3 | 3 | Row → **Send to WhatsApp…** in the drawer → Text only, Text and voice, or Link; or the row's **More** → **Send to WhatsApp…** → the choice. |
| 7 | Find a recording by a word in it | 2 | 2 | The top bar's search (always there now) → (type) → the result, which plays from that moment; **Ctrl/⌘+K** → (type) → the result is 1. |
| 8 | Keep or discard after a call (Ask me) | — | — | The device decides; **Waiting** in the column lists them (1). |
| 9 | Retry a failed transcription | 2 | 2 | **Failed uploads** in the column (or **Failed uploads (2) · See why**) → **Retry**. |
| 10 | Fix "Not ready" | — | — | On the device (its Ready check). |
| 11 | Rename a recording | 3 | 3 | The row's **More** → **Rename** → **Save** (or open → the pencil → **Save**). |
| 12 | Move a recording to a project | 4 | **1** | Drag the row onto the project in the Library's column (or onto a pinned project in the sidebar); several: their boxes, then drag one of them. The selection bar's **Move** → the project → **Move** stays (4 with the box). |
| 13 | Delete a recording | 3 | 3 | The row's **More** → **Delete** → confirm (Recently deleted keeps it 48 hours). |
| 14 | Play from a line | 2 | 2 | Open → the line. |
| 15 | Group or sort the Library | 2 | 2 | **Group by** or **Sort by** → the choice; **List** / **Table** is 1. |

Of the top 10 the console now does 7 itself (Record from a phone's browser joins), and 6 of them take 2 clicks or
fewer. The exception is still **Send to WhatsApp** (3), as on the phone and the desktop: the three ways of sending are
different things, so the console asks rather than guesses.

Other paths the shell shortened: a smart folder or view is 1 click again (the column; it was 2 through the toolbar's
menus after the P5 declutter), **Plan & usage** is 2 from any page (the avatar menu; before: Profile and scrolling),
**Devices** is 2 (**Account → Devices**) or one search (**Find a page** or **Ctrl/⌘+K** "devices"), **Shared with
me** is 1 (the sidebar's Shared → With me), a new project or a request link is 2 from any page (**+**), the
**Preferences** are 2 (**Account → Preferences**) and **Help & shortcuts** is 1 (**?**). In the admin panel a customer's
numbers are 1 (a row opens the quick look; **Open** in it is the full page).
