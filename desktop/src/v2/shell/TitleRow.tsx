// The one title row (PLAN_V2_1_POLISH UX-20): the window's toolbar, as in Finder, Mail and VS Code. In the content
// column: ⊟ while the panel is off the screen (right after the window's buttons), ← →, the page's title with its count,
// one search field centred in the column, and the status items at the end. Every empty part of it, and the title, moves
// the window (data-tauri-drag-region="deep": anything in it that can't be clicked); a double click does what the Mac's
// setting says (lib/titleClick.ts). Buttons and the field never drag. A hairline shows under it only once the page has
// scrolled. The page's part (title, count, search) comes from pageTitle.tsx.
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

/** The field's least width and the drag room it keeps on each side (tokens desktop.search.*). */
const FIELD_LEAST = 200;
const DRAG_ROOM = 64;
/**
 * Whether the field fits between the row's two ends (pure): 200 px for it and 64 px of free drag space on each side;
 * else it becomes a ⌕ button that opens the same search.
 */
export function searchFits(row: number, start: number, end: number): boolean {
  // The field is centred in the row, so its room is twice the room on the wider side's end.
  const half = row / 2 - Math.max(start, end);
  return half * 2 >= FIELD_LEAST + DRAG_ROOM * 2;
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
  // The field shrinks to a button when the row has no room for it (measured; in a test there is no layout).
  useLayoutEffect(() => {
    const el = row.current;
    if (!el || typeof ResizeObserver === "undefined") return;
    const measure = () => {
      const natural = (box: HTMLElement | null) => (box ? [...box.children].reduce((sum, c) => sum + (c as HTMLElement).scrollWidth, 0) : 0);
      setFits(searchFits(el.clientWidth, natural(start.current), natural(tail.current)));
    };
    const watch = new ResizeObserver(measure);
    watch.observe(el);
    measure();
    return () => watch.disconnect();
  }, [title?.title, title?.count, !!toggle, end]);
  const search = title?.search ?? null;
  // Searching this view: the scope chip shows while the field has focus or a query.
  const [scoped, setScoped] = useState(false);
  const shownScope = !!search && (scoped || !!search.query);
  useEffect(() => {
    if (!findSeq) return;
    if (search) {
      setScoped(true);
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
    <header ref={row} className={`el-titlerow${scrolled ? " el-titlerow--scrolled" : ""}${fits ? "" : " el-titlerow--compact"}`} data-tauri-drag-region="deep" data-testid="title-row">
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
            <span className="el-titlerow__name" dir="auto">{title.title}</span>
            {title.count !== null && <span className="el-titlerow__count el-num">{title.count}</span>}
          </h1>
        )}
      </div>
      <div className="el-titlerow__middle">
        {!fits ? (
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
            <input ref={field} type="search" className="el-titlerow__field" aria-label={search.placeholder} placeholder={search.placeholder} value={search.query} dir="auto"
              aria-keyshortcuts="Meta+F Control+F" onChange={(e) => search.setQuery(e.target.value)} onFocus={() => setScoped(true)}
              onBlur={() => setScoped(false)}
              onKeyDown={(e) => {
                if (e.key !== "Escape") return;
                e.preventDefault();
                e.stopPropagation();
                if (search.query) search.setQuery("");
                else field.current?.blur();
              }} />
            <kbd className="el-titlerow__keys" dir="ltr" aria-hidden="true">{everyKeys}</kbd>
          </div>
        ) : (
          <button type="button" className="el-titlerow__search el-titlerow__search--button" data-tauri-drag-region="false" aria-keyshortcuts="Meta+K Control+K" onClick={() => everything("")}>
            <Icon icon={Icons.SearchIcon} />
            <span className="el-titlerow__placeholder">{words}</span>
            <kbd className="el-titlerow__keys" dir="ltr" aria-hidden="true">{everyKeys}</kbd>
          </button>
        )}
      </div>
      <div ref={tail} className="el-titlerow__end">{end}</div>
    </header>
  );
}
