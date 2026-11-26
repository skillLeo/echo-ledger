# U9b: the new Library's first render at 2,000+ recordings (WEB-LIB-033)

The parity world (`web/tests/Feature/V2/Support/parity_world.php`) with 2,000 more recordings of its person (2,005 in
the Library), new frame on, grouped by date (the default), on a local server with the world in a scratch SQLite file.
A browser at 1280 px opened `/app/sessions` 10 times after a warm-up. Each figure is the median. "Before" is
ui/U9d-sharing-labels @ b35cb534 and "after" is this change; the two ran back to back, on the same server and world.

| | Before (pages, 10 a page) | After (one list, 50 then Load more) |
|---|---|---|
| Server, request to first byte | 1,207 ms | **538 ms** |
| DOM ready | 1,305 ms | **728 ms** |
| Load | 1,312 ms | **751 ms** |
| HTML | 629 KB | 1,318 KB |
| Rows drawn | 10 | 50 |
| DOM nodes | 2,108 | 4,840 |

What changed:
- **Grouping.** The date groups' counts (each group's recordings, for its header count and its select box) are now
  worked out from the rows' raw columns. Before, each of the 2,005 recordings got a model and a pass through
  Filament's closure evaluation, which made up most of the time. The keys are the same: `LibrarySourceLogoTest`
  compares them for every group. That is why the page is faster while drawing five times as many rows.
- **One growing list.** The Library shows its first 50 rows with the whole count ("Showing 1 to 50 of 2,004 results").
  "Show 50 more" (or the button scrolling into view) adds the next 50. A new search, filter, order or grouping starts
  over at 50. There are no page links and no page size. Classic keeps its pages.

The machine was shared with other work while measuring, so single runs varied by up to 2×. The medians above come from
one sitting.

## After the U9 chain review: 2,004 recordings, and the list grown to 500

Measured again on the review's fixes, in the same parity world with 2,004 recordings in the Library, on a local server
with a scratch SQLite file, a browser at 1280 px. The machine was heavily shared (load average 80 to 120), so single
runs varied by up to 6×. Each figure is the median of the runs given.

| | 2,004 recordings, first render (8 runs) | Grown to 500 rows (3 runs) |
|---|---|---|
| Server, request to first byte | 309 ms | |
| DOM ready | 553 ms | |
| Rows drawn | 50 | 500 (the most it grows to) |
| DOM nodes | 4,894 | 36,623 |
| HTML | 1,327 KB | |
| Load more, 50 → 100 | | 633 ms |
| Load more, 450 → 500 | | 3,024 ms, 9.5 MB of update |
| The poll while a row on screen moves, nothing changed | | 3 KB a poll, no redraw |

What changed:
- **A cap.** The list grows to 500 rows. Past that, "Refine your search to see more" takes the place of Show more. How
  many rows show is kept on the server's side (`#[Locked]`): the browser can't ask for more.
- **Lighter rows.** Each row reads only a few columns of its final transcript: its status, the first 600 characters of its
  text (for the snippet) and, only while a Show as view is being made, its meta. Never its lines.
- **A quiet poll.** While a row on screen is uploading or being transcribed, the new Library asks every 5 seconds how
  those rows stand (a few columns of those rows only). It draws the list again only when one of them changed. Before,
  each poll drew the whole list again: about 9.5 MB at 500 rows.

Still heavy: each Load more sends the whole list again, as Livewire draws a table. That is about 19 KB a row, so 9.5 MB
by row 500. That is why the list stops at 500.
