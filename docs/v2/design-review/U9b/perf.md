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
