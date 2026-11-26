// The one title row (PLAN_V2_1_POLISH UX-20): the window's toolbar, as in Finder, Mail and VS Code. In the content
// column, start to end: ⊟ while the panel is off the screen (right after the window's buttons), ← →, the page's title
// with its count, empty space, and the search field at the row's end (the owner's request for 2.0.15, as Mail, Notes and
// Finder have it), then the status items. The title never gives its words to the search: as the window narrows the
// empty space goes first, then the field shrinks to its least width, then it becomes a ⌕ button at the end, which opens
// the field across the row (the title stepping aside only while it is open). Beside the ⌕ button the title wraps to two
// lines, or takes a line of its own under the controls, rather than lose a word: it is never cut, at any width. Under
// the app's least width (720 CSS px: zoomed in a small window, lead decision 18's narrow case) the button says "Search"
// (⌘F). The count always shows. Every empty part of the row, and the title, moves the window
// (data-tauri-drag-region="deep": anything in it that can't be clicked); a double click does what the Mac's setting says
// (lib/titleClick.ts). Buttons and the field never drag. A hairline shows under it only once the page has scrolled. The
// page's part (title, count, search) comes from pageTitle.tsx. Its own words are the window's language (D-255), the
// keys left to right in every language.
import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { Button, Icon, Icons } from "../components";
import type { PageTitle } from "../pageTitle";
import type { Place } from "./NavRail";
import { isolate, isRtl, lineDir, t } from "../../i18n";

const MAC = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform);

/** The field's placeholder names the place; elsewhere it searches everything (English: the catalogue's keys). */
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
/** A shortcut inside words: left to right in every language (⌘K, never K⌘ after Urdu). */
const keysIn = (keys: string) => (isRtl() ? isolate(keys) : keys);
/** "Search recordings (⌘K)": words and their keys. */
const withKeys = (words: string, keys: string) => `${words} (${keysIn(keys)})`;
const narrowNow = () => typeof window !== "undefined" && window.innerWidth < NARROW_BELOW;
/** Between the narrow row's parts: the row's gaps (2 × 8), the title's group's (2 × 4), the title's margin (4) and the
 * gap before its count (8). */
const NARROW_GAPS = 36;

/**
 * Beside the ⌕ button: whether the title takes the line under the controls (measured, never in a test without layout):
 * beside them it needs its longest word, and its words on at most two lines. The other parts' widths don't change with it.
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

/**
 * The width ⊟, ← → and the title with its count take on one line, its words whole, whatever the row draws now (the title
 * wrapped, on a line of its own, or aside while the field is open across the row): laid out so for a moment.
 */
let measurer: CanvasRenderingContext2D | null | undefined;
/**
 * Whether `words` in the field's own font are wider than the room inside it: beside the scope's chip that room is less
 * than the box's (Urdu's "ریکارڈنگز میں تلاش کریں" was cut to "ریکارڈنگز میں ت"). Never without a layout (a test).
 */
function wordsCut(input: HTMLInputElement, words: string): boolean {
  const room = input.clientWidth;
  if (!room || !words) return false;
  measurer ??= document.createElement("canvas").getContext("2d");
  if (!measurer) return false;
  const style = getComputedStyle(input);
  measurer.font = `${style.fontWeight} ${style.fontSize} ${style.fontFamily}`;
  return measurer.measureText(words).width > room;
}

function oneLine(start: HTMLElement): number {
  const title = start.querySelector<HTMLElement>(".el-titlerow__title");
  const name = start.querySelector<HTMLElement>(".el-titlerow__name");
  const parts = [start, title, name].filter((el): el is HTMLElement => !!el);
  const kept = parts.map((el) => el.style.cssText);
  Object.assign(start.style, { display: "flex", flex: "none", inlineSize: "max-content" });
  if (title) Object.assign(title.style, { display: "flex", flex: "none", inlineSize: "max-content", order: "0" });
  if (name) Object.assign(name.style, { whiteSpace: "nowrap", flex: "none", inlineSize: "max-content" });
  const width = start.getBoundingClientRect().width;
  parts.forEach((el, i) => {
    el.style.cssText = kept[i];
  });
  return width;
}

/** How long the title row's field takes the page's query, if it is one the field sent, for the page catching up. */
const LATE_ANSWER_MS = 2000;

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
  // A narrow field says "Search" (its full words stay its name and tooltip).
  const [short, setShort] = useState(false);
  // Narrow (under the least width): the ⌕ button says "Search".
  const [narrow, setNarrow] = useState(narrowNow);
  // Beside the ⌕ button (no room for the field beside the whole title, or narrow), the field opens across the row from
  // it, and closes back to it.
  const [open, setOpen] = useState(false);
  const findButton = useRef<HTMLButtonElement>(null);
  const backToButton = useRef(false);
  // Beside the ⌕ button, and the title's words don't fit beside the controls even on two lines: it takes the line under them.
  const [below, setBelow] = useState(false);
  // The field becomes a button when the row has no room for it beside the whole title (measured; in a test there is
  // no layout). The title's group is measured on one line, its words whole, however it is drawn now.
  useLayoutEffect(() => {
    const el = row.current;
    if (!el || typeof ResizeObserver === "undefined") return;
    const measure = () => {
      const style = getComputedStyle(el);
      const inner = el.clientWidth - (parseFloat(style.paddingInlineStart) || 0) - (parseFloat(style.paddingInlineEnd) || 0);
      const roomy = searchFits(inner, start.current ? oneLine(start.current) : 0, tail.current?.getBoundingClientRect().width ?? 0);
      setFits(roomy);
      const name = el.querySelector(".el-titlerow__name") as HTMLElement | null;
      const middle = el.querySelector(".el-titlerow__middle") as HTMLElement | null;
      setShort(!!middle && middle.clientWidth > 0 && middle.clientWidth < SHORT_FIELD);
      setBelow((narrowNow() || !roomy) && !!name && titleBelow(el, name, inner));
    };
    const watch = new ResizeObserver(measure);
    watch.observe(el);
    measure();
    return () => watch.disconnect();
  }, [title?.title, title?.count, !!toggle, end, narrow, open]);
  const search = title?.search ?? null;
  // The field's own words: what is typed shows at once. The page's query comes back a moment later, through the title
  // slot, and a key pressed meanwhile was lost ("invoice" typed fast read "invie"); an answer that is one of the field's
  // own, late, is let go. A change from the page itself (the search cleared, another view) is taken.
  const [draft, setDraft] = useState(search?.query ?? "");
  /** What the field sent the page, and when: an answer that is one of these, lately, is the page catching up. */
  const sent = useRef<{ value: string; at: number }[]>([]);
  // Another place or view searches its own words: what the field sent before is no answer of its.
  const owner = `${place ?? ""}|${search?.placeholder ?? ""}|${search?.scope ?? ""}`;
  const ownerSeen = useRef(owner);
  useEffect(() => {
    const q = search?.query ?? "";
    const now = Date.now();
    if (ownerSeen.current !== owner) {
      ownerSeen.current = owner;
      sent.current = [];
    }
    sent.current = sent.current.filter((s) => now - s.at < LATE_ANSWER_MS);
    if (q === draft || sent.current.some((s) => s.value === q)) return;
    setDraft(q);
    // Only the page's answer (or another page's) moves it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search?.query, owner]);
  const type = (value: string) => {
    sent.current = [...sent.current.slice(-30), { value, at: Date.now() }];
    setDraft(value);
    search?.setQuery(value);
  };
  // Searching this view: the scope chip shows while the field has focus or a query.
  const [scoped, setScoped] = useState(false);
  const shownScope = !!search && (scoped || !!draft);
  useEffect(() => {
    const follow = () => setNarrow(narrowNow());
    window.addEventListener("resize", follow);
    return () => window.removeEventListener("resize", follow);
  }, []);
  // Beside the ⌕ button (the title on its own line or wrapped, never cut), and the field open across the row.
  const tight = narrow || !fits;
  useEffect(() => {
    if (!tight) setOpen(false);
  }, [tight]);
  useLayoutEffect(() => {
    if (open) return field.current?.focus();
    if (!backToButton.current) return;
    backToButton.current = false;
    findButton.current?.focus();
  }, [open]);
  // Words typed stay in sight: the field stays while it has them, even when the window narrows meanwhile.
  const fieldShown = !tight || (!!search && (open || !!draft));
  // With the chip beside it, the field's placeholder is the short word when its full words would be cut (its name keeps
  // them), as a short field's is; measured again as the field's room changes.
  const [crowded, setCrowded] = useState(false);
  const fullWords = search?.placeholder ?? "";
  useLayoutEffect(() => {
    const input = field.current;
    if (!input || !shownScope || typeof ResizeObserver === "undefined") return setCrowded(false);
    const measure = () => setCrowded(wordsCut(input, fullWords));
    const watch = new ResizeObserver(measure);
    watch.observe(input);
    measure();
    return () => watch.disconnect();
  }, [shownScope, fullWords, fieldShown]);
  useEffect(() => {
    if (!findSeq) return;
    if (search) {
      setScoped(true);
      if (tight) setOpen(true);
      field.current?.focus();
      field.current?.select();
    } else onEverything("");
    // Only a new ⌘F press opens it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [findSeq]);
  const placeWords = place ? SEARCH_WORDS[place] : undefined;
  const words = search?.placeholder ?? (placeWords ? t(placeWords) : t(EVERYTHING));
  const shortWords = t(SHORT_WORDS);
  const everyKeys = MAC ? "⌘K" : "Ctrl+K";
  // "In: Clients": the scope the field searches, its name a value of the line (dir: Urdu's, or auto as before).
  const scopeWords = search ? t("In: :scope", { scope: search.scope }) : "";
  const scopeDir = lineDir(scopeWords);
  const everything = (typed: string) => {
    if (search && typed) type("");
    setScoped(false);
    onEverything(typed);
  };
  return (
    <header ref={row} className={`el-titlerow${scrolled ? " el-titlerow--scrolled" : ""}${narrow ? " el-titlerow--narrow" : fits ? "" : " el-titlerow--compact"}${tight && fieldShown ? " el-titlerow--searching" : ""}${tight && below ? " el-titlerow--below" : ""}`}
      data-tauri-drag-region="deep" data-testid="title-row">
      <div ref={start} className="el-titlerow__start">
        {toggle && (
          <Button size="sm" variant="quiet" icon={Icons.SidebarIcon} className="el-titlerow__toggle" aria-label={toggle.label} aria-expanded={toggle.expanded}
            title={withKeys(toggle.label, MAC ? "⌃⌘S" : "Ctrl+\\")} aria-keyshortcuts={MAC ? "Control+Meta+S Meta+\\" : "Control+\\"}
            onClick={(e) => toggle.onToggle(e.currentTarget)} />
        )}
        <span className="el-titlerow__history" role="group" aria-label={t("History")}>
          <Button size="sm" variant="quiet" icon={Icons.ChevronStartIcon} directional aria-label={t("Back")} title={t("Back (:keys)", { keys: MAC ? "⌘[" : "Alt+←" })}
            aria-keyshortcuts={MAC ? "Meta+[" : "Alt+ArrowLeft Control+["} disabled={!back} onClick={() => back?.()} />
          <Button size="sm" variant="quiet" icon={Icons.ChevronEndIcon} directional aria-label={t("Forward")} title={t("Forward (:keys)", { keys: MAC ? "⌘]" : "Alt+→" })}
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
        {!fieldShown && narrow ? (
          // Narrow: "Search" (⌘F) opens the field across the row; without a search of the page's own, everything's (⌘K).
          <Button ref={findButton} size="sm" variant="quiet" icon={Icons.SearchIcon} aria-label={shortWords} title={withKeys(shortWords, FIND_KEYS)}
            aria-keyshortcuts="Meta+F Control+F" onClick={() => (search ? setOpen(true) : everything(""))} />
        ) : !fieldShown ? (
          // No room beside the whole title: the ⌕ button opens the page's search across the row (⌘F), else everything's (⌘K).
          <Button ref={findButton} size="sm" variant="quiet" icon={Icons.SearchIcon} aria-label={words} title={withKeys(words, search ? FIND_KEYS : everyKeys)}
            aria-keyshortcuts={search ? "Meta+F Control+F" : "Meta+K Control+K"} onClick={() => (search ? setOpen(true) : everything(""))} />
        ) : search ? (
          <div className="el-titlerow__search" role="search" data-tauri-drag-region="false">
            <Icon icon={Icons.SearchIcon} />
            {shownScope && (
              <span className="el-titlerow__scope">
                <span dir={scopeDir}>{scopeWords}</span>
                <button type="button" className="el-titlerow__unscope" aria-label={t("Search everything instead")} title={t("Search everything (:keys)", { keys: everyKeys })}
                  onMouseDown={(e) => e.preventDefault()} onClick={() => everything(draft)}>
                  <Icon icon={Icons.CloseIcon} />
                </button>
              </span>
            )}
            {/* Empty, the field reads as the window does, so its placeholder starts right after the chip (in Urdu too: on
                its own an empty field's "auto" is left to right); typed, as what was typed. */}
            <input ref={field} type="search" className="el-titlerow__field" aria-label={search.placeholder} placeholder={short || crowded ? shortWords : search.placeholder} value={draft}
              dir={draft || !isRtl() ? "auto" : "rtl"}
              aria-keyshortcuts="Meta+F Control+F"
              onChange={(e) => {
                type(e.target.value);
                // Shown beside the ⌕ button for its words: being typed in, it stays open when they are deleted.
                if (tight) setOpen(true);
              }}
              onFocus={() => {
                setScoped(true);
                if (tight) setOpen(true);
              }}
              onBlur={() => {
                setScoped(false);
                // Opened from the ⌕ button and left empty: the field closes back to it (focus stays where it went).
                if (tight && !draft) setOpen(false);
              }}
              onKeyDown={(e) => {
                if (e.key !== "Escape") return;
                e.preventDefault();
                e.stopPropagation();
                if (draft) {
                  type("");
                  // Opened across the row: it stays open, empty, for the next Esc.
                  if (tight) setOpen(true);
                } else if (tight) {
                  backToButton.current = true;
                  setOpen(false);
                } else field.current?.blur();
              }} />
            <kbd className="el-titlerow__keys" dir="ltr" data-keys={everyKeys} aria-hidden="true" />
          </div>
        ) : (
          <button type="button" className="el-titlerow__search el-titlerow__search--button" data-tauri-drag-region="false" aria-keyshortcuts="Meta+K Control+K" aria-label={words}
            title={withKeys(words, everyKeys)} onClick={() => everything("")}>
            <Icon icon={Icons.SearchIcon} />
            <span className="el-titlerow__placeholder">{short ? shortWords : words}</span>
            <kbd className="el-titlerow__keys" dir="ltr" data-keys={everyKeys} aria-hidden="true" />
          </button>
        )}
      </div>
      <div ref={tail} className="el-titlerow__end">{end}</div>
    </header>
  );
}
