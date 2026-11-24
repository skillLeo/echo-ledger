# D14: Lists (desktop, web, phone)

Owner: EL-Design, 2026-11-21. Asked for by Hassam (P1): sort the Library into his own lists the way a chat app sorts chats, kept very simple. It's a familiar pattern drawn in our own look, with no other app's branding.
**A list is an existing label with its per-person colour (contract 2.0.0-i).** There's no new concept and no new server model. EL-UX builds the screens; the shared parts below are mine.

## 1. The chip row (top of the Library, under the search)
`All · Unread · Important · Clients · Home · Family · +`
- **All** (selected by default), **Unread** and **Important** are fixed chips. They can't be renamed, recoloured, moved or deleted.
- **The person's lists** come next, in the order they made them, followed by **+**.
- **A list chip** is its colour dot (8 px, the label's `role.label.<name>.dot`) plus its name. Selected: accent-soft fill with accent-text and a check icon, as the filter chips have it. One chip is selected at a time: a list here is a place to go, not a filter to stack.
- **Layout:** the row scrolls sideways on the phone and below 1280 px, with no wrapping and no fade. From 1280 px it wraps onto a second line when needed. The selected chip scrolls into view.
- **Counts:** none on the chips (no "badges everywhere"). Only Unread shows a count, and only when it's above 0.
- **Empty list:** "Nothing in Clients yet" with one line: "Add recordings from their ⋮ menu: Add to list."
- **RTL:** the order mirrors, and "+" is always last (at the left end in Urdu).

## 2. Making a list: "+" → name + colour → Create (2 steps)
- A small dialog on desktop/web, or a bottom sheet on the phone: **List name** (focused, up to 40 characters), then **Colour** as the 8 label colours in a row (ColourPicker, below), with the next unused colour preselected.
- **Create** is the primary button and stays disabled until a name is entered; Cancel closes.
- A name that already exists → "You already have a list called Clients."
- After Create, the new chip is selected and its empty state shows.

## 3. Adding a recording to lists
- **Entry points:** ⋮ › **Add to list**, long-press on the phone, right-click on desktop/web, and the selection bar's Label action for several recordings at once.
- **The picker:** a menu or sheet of the person's lists, each a checkbox row (colour dot + name), plus **New list…** at the end, which opens §2 and comes back ticked. Ticks apply at once, and a toast says "Added to Clients" with Undo. A recording can be in several lists.
- **On the row:** the recording's lists appear as their colour dots, up to 3, in the D12 marks slot, never in the snippet. Each dot has the list's name as its accessible name and tooltip.

## 4. Managing a list: long-press (phone) or right-click / ⋮ on the chip (desktop/web)
- **The menu:**
  - Rename…
  - Change colour… (the ColourPicker)
  - Move left / Move right (only when there is somewhere to move; in RTL, read as "earlier / later" by direction)
  - a divider, then **Delete list…** (red, last)
- **Delete asks:** "Delete Clients? Its recordings stay in your Library; only the list goes." Delete is red; Cancel.
- Rename and recolour change the label everywhere at once, because it's the same label.

## 5. Rename a recording by tapping its title
On the recording page, tapping or clicking the title turns it into a text field in place, with the text selected. Enter or Done saves, and Esc or tapping away cancels. It's the same action as ⋮ › Rename. Long names wrap; nothing jumps.

## 6. Shared components (EL-Design builds them; same names and options on every platform)
- **ListChip** { name, colour, selected, onClick, onMenu }: on top of LabelChip/EchoChip. Its menu opens on long-press or right-click.
- **ColourPicker** { value, onChange, label }: the 8 colours as a radio group of 32 px dots inside 44 px targets. The selected dot has a 2 px ring in text-primary and a check. Each is named for screen readers ("Teal"), and the arrow keys move between them.
- **AddToListMenu** { lists, checked, onToggle, onNew }: the Menu/Sheet with checkbox rows and "New list…".
- **Reused:** Menu, Dialog/Sheet, Toast with Undo, and the D12 row marks.

## 7. Pictures (for Hassam, then EL-UX builds)
For desktop 1280 and 800, light and dark, EN and UR: the chip row with three lists, a list selected with its rows, the "+" dialog, the Add-to-list menu with two ticks, and the chip menu. Plus phone and web equivalents of the chip row, the sheet and the picker. They go in docs/v2/design-review/D14/.
