// The one title row (PLAN_V2_1_POLISH UX-20): the window's toolbar, as in Finder, Mail and VS Code. In the content
// column, start to end: ⊟ while the panel is off the screen (right after the window's buttons), ← →, the page's title
// with its count, empty space, and the search field at the row's end (the owner's request for 2.0.15, as Mail, Notes and
// Finder have it), then the status items. The title never gives its words to the search: as the window narrows the
// empty space goes first, then the field shrinks to its least width, then it becomes a ⌕ button at the end. Under the
// app's least width (720 CSS px: zoomed in a small window, lead decision 18's narrow case) the ⌕ button ("Search", ⌘F)
// opens the field across the row, the title stepping aside only while it is open, and the title wraps to two lines
// rather than lose a word: it is never cut. The count always shows. Every empty part of the row, and the title, moves the window
// (data-tauri-drag-region="deep": anything in it that can't be clicked); a double click does what the Mac's setting says
// (lib/titleClick.ts). Buttons and the field never drag. A hairline shows under it only once the page has scrolled. The
// page's part (title, count, search) comes from pageTitle.tsx.
import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { Button, Icon, Icons } from "../components";
import type { PageTitle } from "../pageTitle";
import type { Place } from "./NavRail";

const MAC = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform);

/** The field's placeholder names the place; elsewhere it searches everything. */
export const SEARCH_WORDS: Partial<Record<Place, string>> = {
  library: "Search recordings", projects: "Search projects", settings: "Search settings",
};
export const EVERYTHING = "Search everything";
/** Below this width the field's placeholder is the short word: its full words (and the scope's chip) stay its name. */
export const SHORT_FIELD = 220;
export const SHORT_WORDS = "Search";
/** Under this page width (CSS px: the window's least width) the row is narrow (lead decision 18's narrow case). */
export const NARROW_BELOW = 720;
const FIND_KEYS = MAC ? "⌘F" : "Ctrl+F";
const narrowNow = () => typeof window !== "undefined" && window.innerWidth < NARROW_BELOW;
/** Between the narrow row's parts: the row's gaps (2 × 8), the title's group's (2 × 4), the title's margin (4) and the
 * gap before its count (8). */
const NARROW_GAPS = 36;

/**
 * Narrow: whether the title takes the line under the controls (measured, never in a test without layout): beside them
 * it needs its longest word, and its words on at most two lines. The other parts' widths don't change with it.
 */
function titleBelow(row: HTMLElement, name: HTMLElement, inner: number): boolean {
  const width = (selector: string) => (row.querySelector(selector) as HTMLElement | null)?.getBoundingClientRect().width ?? 0;
  const room = inner - NARROW_GAPS - [".el-titlerow__toggle", ".el-titlerow__history", ".el-titlerow__count", ".el-titlerow__middle", ".el-titlerow__end"]
    .reduce((sum, selector) => sum + width(selector), 0);
  // The title's whole width on one line and its longest word's, measured on the name itself for a moment.
  const kept = name.style.cssText;
  name.style.flex = "none";
  name.style.inlineSize = "max-content";
  const whole = name.getBoundingClientRect().width;
  name.style.inlineSize = "min-content";
  const longest = name.getBoundingClientRect().width;
  name.style.cssText = kept;
  if (!(whole > 0)) return false;
  return room < longest || whole > room * 2;
}

/** The field's least width and the least drag room left between the title and the field (tokens desktop.search.*). */
export const FIELD_LEAST = 180;
export const DRAG_ROOM = 16;
/** The gaps between the row's parts: the title's group, the field, the status items (--el-space-8 each). */
const GAPS = 2 * 8;
/**
 * Whether the field fits at the row's end beside the title (pure): `row` is the row's inner width, `start` the whole
 * width of ⊟, ← → and the title with its count, `end` the status items'. The field needs its least width and the least
 * drag room; else it becomes a ⌕ button, and the title keeps its words.
 */
export function searchFits(row: number, start: number, end: number): boolean {
  return row - start - end - GAPS >= FIELD_LEAST + DRAG_ROOM;
}

export type TitleRowProps = {
  place: Place | null;
  /** What the page shows: its title and count, and the search the field runs for this view. */
  title: PageTitle | null;
  /** The panel's toggle while the panel is off the screen (or a small window's flyout is open over the page). */
  toggle: { label: string; expanded?: boolean; onToggle: (opener: HTMLElement) => void } | null;
  /** Back and Forward; null where there is nowhere to go (greyed). */
  back: (() => void) | null;
  forward: (() => void) | null;
  /** Search everything (⌘K), starting from what was typed. */
  onEverything: (typed: string) => void;
  /** ⌘F was pressed: the field searches this view (each press adds one). */
  findSeq?: number;
  /** The page under the row has scrolled: a hairline under the row. */
  scrolled?: boolean;
  /** At the end: the page's own actions, then status items that show only when needed. */
  end?: ReactNode;
};

export function TitleRow({ place, title, toggle, back, forward, onEverything, findSeq = 0, scrolled = false, end }: TitleRowProps) {
  const row = useRef<HTMLElement>(null);
  const start = useRef<HTMLDivElement>(null);
  const tail = useRef<HTMLDivElement>(null);
  const field = useRef<HTMLInputElement>(null);
  const [fits, setFits] = useState(true);
  // The title's words cut (only in a window under the least width, next to the ⌕ button): its tooltip says them.
  const [cut, setCut] = useState(false);
  // A narrow field says "Search" (its full words stay its name and tooltip).
  const [short, setShort] = useState(false);
  // Narrow (under the least width): the field opens across the row from its ⌕ button, and closes back to it.
  const [narrow, setNarrow] = useState(narrowNow);
  const [open, setOpen] = useState(false);
  const findButton = useRef<HTMLButtonElement>(null);
  const backToButton = useRef(false);
  // Narrow, and the title's words don't fit beside the controls even on two lines: it takes the line under them.
  const [below, setBelow] = useState(false);
  // The field becomes a button when the row has no room for it beside the whole title (measured; in a test there is
  // no layout). The title's group is measured at its full width, its words uncut.
  useLayoutEffect(() => {
    const el = row.current;
    if (!el || typeof ResizeObserver === "undefined") return;
    const measure = () => {
      // A group's width with the title's words whole (what a cut title hides added back).
      const whole = (box: HTMLElement | null) => {
        if (!box) return 0;
        const name = box.querySelector(".el-titlerow__name") as HTMLElement | null;
        return box.getBoundingClientRect().width + (name ? Math.max(0, name.scrollWidth - name.clientWidth) : 0);
      };
      const style = getComputedStyle(el);
      const inner = el.clientWidth - (parseFloat(style.paddingInlineStart) || 0) - (parseFloat(style.paddingInlineEnd) || 0);
      setFits(searchFits(inner, whole(start.current), whole(tail.current)));
      const name = el.querySelector(".el-titlerow__name") as HTMLElement | null;
      setCut(!!name && name.scrollWidth > name.clientWidth + 0.5);
      const middle = el.querySelector(".el-titlerow__middle") as HTMLElement | null;
      setShort(!!middle && middle.clientWidth > 0 && middle.clientWidth < SHORT_FIELD);
      setBelow(narrowNow() && !!name && titleBelow(el, name, inner));
    };
    const watch = new ResizeObserver(measure);
    watch.observe(el);
    measure();
    return () => watch.disconnect();
  }, [title?.title, title?.count, !!toggle, end, narrow, open]);
  const search = title?.search ?? null;
  // Searching this view: the scope chip shows while the field has focus or a query.
  const [scoped, setScoped] = useState(false);
  const shownScope = !!search && (scoped || !!search.query);
  useEffect(() => {
    const follow = () => setNarrow(narrowNow());
    window.addEventListener("resize", follow);
    return () => window.removeEventListener("resize", follow);
  }, []);
  useEffect(() => {
    if (!narrow) setOpen(false);
  }, [narrow]);
  useLayoutEffect(() => {
    if (open) return field.current?.focus();
    if (!backToButton.current) return;
    backToButton.current = false;
    findButton.current?.focus();
  }, [open]);
  const fieldShown = !narrow || (open && !!search);
  useEffect(() => {
    if (!findSeq) return;
    if (search) {
      setScoped(true);
      if (narrow) setOpen(true);
      field.current?.focus();
      field.current?.select();
    } else onEverything("");
    // Only a new ⌘F press opens it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [findSeq]);
  const words = search?.placeholder ?? (place ? SEARCH_WORDS[place] : undefined) ?? EVERYTHING;
  const everyKeys = MAC ? "⌘K" : "Ctrl+K";
  const everything = (typed: string) => {
    if (search && typed) search.setQuery("");
    setScoped(false);
    onEverything(typed);
  };
  return (
    <header ref={row} className={`el-titlerow${scrolled ? " el-titlerow--scrolled" : ""}${narrow ? " el-titlerow--narrow" : fits ? "" : " el-titlerow--compact"}${narrow && fieldShown ? " el-titlerow--searching" : ""}${narrow && below ? " el-titlerow--below" : ""}`}
      data-tauri-drag-region="deep" data-testid="title-row">
      <div ref={start} className="el-titlerow__start">
        {toggle && (
          <Button size="sm" variant="quiet" icon={Icons.SidebarIcon} className="el-titlerow__toggle" aria-label={toggle.label} aria-expanded={toggle.expanded}
            title={`${toggle.label} (${MAC ? "⌃⌘S" : "Ctrl+\\"})`} aria-keyshortcuts={MAC ? "Control+Meta+S Meta+\\" : "Control+\\"}
            onClick={(e) => toggle.onToggle(e.currentTarget)} />
        )}
        <span className="el-titlerow__history" role="group" aria-label="History">
          <Button size="sm" variant="quiet" icon={Icons.ChevronStartIcon} directional aria-label="Back" title={`Back (${MAC ? "⌘[" : "Alt+←"})`}
            aria-keyshortcuts={MAC ? "Meta+[" : "Alt+ArrowLeft Control+["} disabled={!back} onClick={() => back?.()} />
          <Button size="sm" variant="quiet" icon={Icons.ChevronEndIcon} directional aria-label="Forward" title={`Forward (${MAC ? "⌘]" : "Alt+→"})`}
            aria-keyshortcuts={MAC ? "Meta+]" : "Alt+ArrowRight Control+]"} disabled={!forward} onClick={() => forward?.()} />
        </span>
        {title && (
          <h1 className="el-titlerow__title">
            <span className="el-titlerow__name" dir="auto" title={cut && !narrow ? title.title : undefined}>{title.title}</span>
            {title.count !== null && <span className="el-titlerow__count el-num">{title.count}</span>}
          </h1>
        )}
      </div>
      <div className="el-titlerow__middle">
        {!fieldShown ? (
          // Narrow: "Search" (⌘F) opens the field across the row; without a search of the page's own, everything's (⌘K).
          <Button ref={findButton} size="sm" variant="quiet" icon={Icons.SearchIcon} aria-label={SHORT_WORDS} title={`${SHORT_WORDS} (${FIND_KEYS})`}
            aria-keyshortcuts="Meta+F Control+F" onClick={() => (search ? setOpen(true) : everything(""))} />
        ) : !fits && !narrow ? (
          <Button size="sm" variant="quiet" icon={Icons.SearchIcon} aria-label={words} title={`${words} (${everyKeys})`}
            aria-keyshortcuts="Meta+K Control+K" onClick={() => (search ? (setScoped(true), setFits(true)) : everything(""))} />
        ) : search ? (
          <div className="el-titlerow__search" role="search" data-tauri-drag-region="false">
            <Icon icon={Icons.SearchIcon} />
            {shownScope && (
              <span className="el-titlerow__scope">
                <span dir="auto">In: {search.scope}</span>
                <button type="button" className="el-titlerow__unscope" aria-label="Search everything instead" title={`Search everything (${everyKeys})`}
                  onMouseDown={(e) => e.preventDefault()} onClick={() => everything(search.query)}>
                  <Icon icon={Icons.CloseIcon} />
                </button>
              </span>
            )}
            <input ref={field} type="search" className="el-titlerow__field" aria-label={search.placeholder} placeholder={short ? SHORT_WORDS : search.placeholder} value={search.query} dir="auto"
              aria-keyshortcuts="Meta+F Control+F" onChange={(e) => search.setQuery(e.target.value)} onFocus={() => setScoped(true)}
              onBlur={() => {
                setScoped(false);
                // Narrow, left empty: the field closes back to its button (focus stays where it went).
                if (narrow && !search.query) setOpen(false);
              }}
              onKeyDown={(e) => {
                if (e.key !== "Escape") return;
                e.preventDefault();
                e.stopPropagation();
                if (search.query) search.setQuery("");
                else if (narrow) {
                  backToButton.current = true;
                  setOpen(false);
                } else field.current?.blur();
              }} />
            <kbd className="el-titlerow__keys" dir="ltr" aria-hidden="true">{everyKeys}</kbd>
          </div>
        ) : (
          <button type="button" className="el-titlerow__search el-titlerow__search--button" data-tauri-drag-region="false" aria-keyshortcuts="Meta+K Control+K" aria-label={words}
            title={`${words} (${everyKeys})`} onClick={() => everything("")}>
            <Icon icon={Icons.SearchIcon} />
            <span className="el-titlerow__placeholder">{short ? SHORT_WORDS : words}</span>
            <kbd className="el-titlerow__keys" dir="ltr" aria-hidden="true">{everyKeys}</kbd>
          </button>
        )}
      </div>
      <div ref={tail} className="el-titlerow__end">{end}</div>
    </header>
  );
}
