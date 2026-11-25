// The three levels of navigation (PLAN_NAV_3_LEVELS §1–§3, §6): the rail of main places (72 px, an icon and a short
// name under it, always there; DES-01: 56 px items, the selected one's pill, a tooltip with its shortcut), the place's
// panel of views and collections (⊟ or ⌘\ hides it; in a small window it opens as a flyout from the rail and closes
// once something is chosen), and the page. The pieces are here; Shell puts them together with the app's state.
import { createContext, useContext, useEffect, useId, useMemo, useRef, useState, type ReactNode } from "react";
import { Button, Icon, Icons, Loading, ResizeEdge, SearchField, Skeleton } from "../components";
import type { IconType } from "../components/Button";
import { Menu, type MenuItem } from "../components/Menu";
import { useTip } from "../components/Tip";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";
import { PROJECT_SORTS, projectOrder, useProjectSort } from "./projectSort";
import { FAILED_WORDS, railBadgeLabel } from "../../lib/badge";

/** UX-03: the panel's width, dragged or set from the keyboard, from 200 to 360 px; let go below 160 px it closes. */
export const PANEL_MIN = 200;
export const PANEL_MAX = 360;
export const PANEL_CLOSE_BELOW = 160;
/** The usual width (the CSS's when none is set): 240 px with v21_panel_flyout, 260 px before it. */
export const panelDefault = (flyouts: boolean) => (flyouts ? 240 : 260);

/** Sent by a page to show its place's panel (Settings' home: Show groups): the panel, or in a narrow window its flyout. */
export const SHOW_PANEL_EVENT = "echoledger:show-panel";

/** The rail's places, in their order (⌘1…⌘6); Home is the logo's, Record the round button's. */
export type Place = "library" | "projects" | "live" | "ask" | "activity" | "settings";
export const RAIL_PLACES: readonly Place[] = ["library", "projects", "live", "ask", "activity", "settings"];
export const PLACE_NAMES: Record<Place, string> = {
  library: "Library", projects: "Projects", live: "Live", ask: "Ask", activity: "Activity", settings: "Settings",
};

const MAC = typeof navigator !== "undefined" && /Mac|iPhone|iPad/.test(navigator.platform);
/** ⌘1 on a Mac, Ctrl+1 elsewhere. */
export const placeKey = (place: Place) => `${MAC ? "⌘" : "Ctrl+"}${RAIL_PLACES.indexOf(place) + 1}`;
const placeShortcut = (place: Place) => `Meta+${RAIL_PLACES.indexOf(place) + 1} Control+${RAIL_PLACES.indexOf(place) + 1}`;

/**
 * One place in the rail: its icon (with the selected place's pill behind it), its short name under it, and a number
 * badge (Activity's, the only one) or a dot (Live's red while recording, Setup's when something needs doing). Its
 * tooltip, after 500 ms or on keyboard focus, gives the name and the shortcut ("Library ⌘1").
 */
export function RailItem({ icon, label, current, onClick, count, countTone, dot, dotLabel, dotKind, shortcut, keys, expanded, place }: {
  icon: IconType; label: string; current: boolean; onClick: (e: React.MouseEvent<HTMLButtonElement>) => void; count?: number; dot?: boolean; dotLabel?: string;
  /** The number's colour: red only when something failed (UX-08); the accent otherwise. */
  countTone?: "accent" | "danger";
  /** Live's dot is red (a call is recorded); the others are the accent. */
  dotKind?: "live";
  /** Its flyout is open (a narrow window); left out where there is none. */
  expanded?: boolean;
  place?: Place;
  /** For the tooltip and assistive tools: "⌘1" and "Meta+1 Control+1". */
  shortcut?: string; keys?: string;
}) {
  // Red says something failed: so do the name and the tooltip (UX-08), as colour alone can't.
  const failed = !!count && countTone === "danger";
  const name = count ? `${label}, ${count}${failed ? `, ${FAILED_WORDS}` : ""}` : dot && dotLabel ? `${label}, ${dotLabel}` : label;
  const [tipProps, tip] = useTip(`${shortcut ? `${label} ${shortcut}` : label}${failed ? `, ${FAILED_WORDS}` : ""}`);
  return (
    <>
      <button type="button" className="el-rail__item" aria-current={current ? "page" : undefined} aria-label={name} aria-expanded={expanded}
        data-place={place} aria-keyshortcuts={keys} onClick={onClick} {...tipProps}>
        <span className="el-rail__icon">
          <Icon icon={icon} size="md" />
          {count ? <span className={`el-rail__count el-num${countTone === "danger" ? " el-rail__count--danger" : ""}`} aria-hidden="true">{railBadgeLabel(count)}</span>
            : dot ? <span className={`el-rail__dot${dotKind ? ` el-rail__dot--${dotKind}` : ""}`} aria-hidden="true" /> : null}
        </span>
        <span className="el-rail__label" aria-hidden="true">{label}</span>
      </button>
      {tip}
    </>
  );
}

export function railItemProps(place: Place) {
  return { label: PLACE_NAMES[place], shortcut: placeKey(place), keys: placeShortcut(place) };
}

/**
 * How the rail shows `total` places in `room` px of `each` px items (a high zoom, UX-07): all of them when they fit;
 * else one fewer than fit, the last slot being "⋯ More" (`more`); and when not even More fits whole (200% in the least
 * window, sooner under Windows' own title row), none, the places then going into the avatar's menu as Help and Setup do.
 * Nothing measured yet (or no layout): all.
 */
export function railFit(total: number, room: number, each: number): { keep: number; more: boolean } {
  if (!(room > 0) || !(each > 0)) return { keep: total, more: false };
  const fit = Math.floor((room + 0.5) / each);
  if (fit >= total) return { keep: total, more: false };
  return fit < 1 ? { keep: 0, more: false } : { keep: fit - 1, more: true };
}

/** The places' names as one phrase ("Library, Projects and Activity"). */
export function placeList(places: readonly Place[]): string {
  const names = places.map((p) => PLACE_NAMES[p]);
  return names.length < 2 ? names.join("") : `${names.slice(0, -1).join(", ")} and ${names[names.length - 1]}`;
}

/** The avatar's tooltip: the account, and the places it holds when not even More fits (UX-07). */
export function accountTip(held: readonly Place[]): string {
  return held.length ? `${placeList(held)}; account, theme and settings` : "Account, theme and settings";
}

/** The places as menu items (More's, or the avatar's when not even More fits), each with its icon, ⌘ key and Activity's
 * count; the page among them is ticked (no tick column otherwise). */
export function placeMenuItems(places: readonly Place[], current: Place | null, icons: Record<Place, IconType>, activityCount: number | undefined,
  onPick: (place: Place) => void): MenuItem[] {
  const here = !!current && places.includes(current);
  return places.map((p) => ({
    label: PLACE_NAMES[p], icon: icons[p], shortcut: placeKey(p), count: p === "activity" ? activityCount || undefined : undefined,
    ...(here ? { checked: current === p, radio: true } : {}), onPick: () => onPick(p),
  }));
}

/**
 * "⋯ More" (UX-07): the places that don't fit whole in the rail (a high zoom), as a menu of them, each with its icon,
 * shortcut and Activity's count. It carries Activity's badge and Live's dot when they are in it, and is the selected
 * item (its menu ticking the page) while the page is one of its places.
 */
export function RailMore({ places, current, icons, activityCount, liveDot, liveDotWords, onPick }: {
  places: readonly Place[]; current: Place | null; icons: Record<Place, IconType>; activityCount?: number;
  liveDot?: boolean; liveDotWords?: string; onPick: (place: Place) => void;
}) {
  const count = places.includes("activity") && activityCount ? activityCount : undefined;
  const dot = !count && places.includes("live") && !!liveDot;
  const here = !!current && places.includes(current);
  const [tipProps, tip] = useTip("More");
  const spoken = ["More", count ? `${PLACE_NAMES.activity} ${count}` : null, places.includes("live") && liveDot && liveDotWords ? `${PLACE_NAMES.live} ${liveDotWords}` : null]
    .filter(Boolean).join(", ");
  const items = placeMenuItems(places, current, icons, activityCount, onPick);
  return (
    <div className="el-rail__more" data-testid="rail-more">
      <Menu label="More places" items={items} align="start"
        button={{ variant: "quiet", className: "el-rail__item", "aria-label": spoken, "aria-current": here ? "page" : undefined, ...tipProps,
          text: <><span className="el-rail__icon"><Icon icon={Icons.MoreIcon} size="md" />
            {count ? <span className="el-rail__count el-num" aria-hidden="true">{count > 99 ? "99+" : count}</span>
              : dot ? <span className="el-rail__dot el-rail__dot--live" aria-hidden="true" /> : null}</span>
            <span className="el-rail__label" aria-hidden="true">More</span></> }} />
      {tip}
    </div>
  );
}

/**
 * A place's panel: its name, its ⋯ menu and ⊟ (hide; ⌘\ too), what it lists, and its foot. As a flyout (a narrow
 * window) it floats over the page and closes once something in it is chosen, on Esc, or on a press outside.
 */
/** Why a flyout closed: something chosen in it (focus goes to the page), else Esc, a press outside or ⊟ (back to its opener). */
export type FlyoutClose = "chose" | "esc" | "outside" | "hide";

/**
 * The page header's panel toggle (UX-02): while the place has a panel that is not on the screen, its button at the start
 * of the page header ([PanelToggle]); none otherwise. `onToggle` gets the button, the flyout's opener.
 */
export const PanelToggleContext = createContext<{ label: string; expanded?: boolean; onToggle: (opener: HTMLElement) => void } | null>(null);

/** The panel's toggle at the start of a page header ([▯]): nothing while the panel is shown or the page has none. */
/**
 * A page's own steps back and forward, for the title row's ← → (UX-20), ⌘[ ⌘] (Alt+← → on Windows) and the mouse's
 * side buttons. The shell takes a page's step first, then walks the places visited.
 *
 *   const inShell = usePageHistory({ back: canGoBack ? goBack : null, forward: canGoForward ? goForward : null });
 *
 * - Give a step only while it can be taken: null (or left out) greys that arrow, unless the places have somewhere to go.
 *   Pass null for none at all. Every render publishes what is given now; the newest functions are the ones called.
 * - Several pages at once (a page inside a page): the one mounted last is asked first, then the next.
 * - It returns true inside the shell (the three levels): the page then leaves ⌘[ ⌘] and the side buttons to it. False
 *   anywhere else (classic, a test without the shell): the page answers them itself, as before.
 */
export type PageHistory = { back?: (() => void) | null; forward?: (() => void) | null };
export const PageHistoryContext = createContext<{ set: (id: string, steps: PageHistory | null) => void; withdraw: (id: string) => void } | null>(null);
export function usePageHistory(steps: PageHistory | null): boolean {
  const slot = useContext(PageHistoryContext);
  const id = useId();
  useEffect(() => {
    slot?.set(id, steps);
  });
  useEffect(() => () => slot?.withdraw(id), [slot, id]);
  return !!slot;
}

export function PanelToggle() {
  const toggle = useContext(PanelToggleContext);
  if (!toggle) return null;
  return (
    <span className="el-page__toggle">
      <Button size="sm" variant="quiet" icon={Icons.SidebarIcon} aria-label={toggle.label} aria-expanded={toggle.expanded} title={`${toggle.label} (${MAC ? "⌃⌘S" : "Ctrl+\\"})`}
        aria-keyshortcuts={MAC ? "Control+Meta+S Meta+\\" : "Control+\\"} onClick={(e) => toggle.onToggle(e.currentTarget)} />
    </span>
  );
}

export function PlacePanel({ place, menu, onHide, flyout, onClose, children, foot, pin, width = null, resize }: {
  place: Place; menu?: MenuItem[]; onHide: () => void; flyout: boolean; onClose: (why: FlyoutClose) => void; children: ReactNode; foot?: ReactNode;
  /** A small window (UX-02): the pin that docks the panel there, or takes it back to a flyout. */
  pin?: { pinned: boolean; onPin: () => void };
  /** UX-03: the width kept for this place (the CSS's usual width when null). */
  width?: number | null;
  /** UX-03: its edge, which sets the width; let go below 160 px the panel closes. */
  resize?: { width: number; onWidth: (w: number) => void; onReset: () => void; onClose: () => void };
}) {
  const box = useRef<HTMLElement>(null);
  useEffect(() => {
    if (!flyout) return;
    const away = (e: PointerEvent) => {
      if (!box.current?.contains(e.target as Node) && !(e.target as HTMLElement).closest?.(".el-rail")) onClose("outside");
    };
    // Esc closes the flyout and nothing else: the page's own Esc (an open recording, filters, a selection) listens on
    // the document and must not hear it. From inside the flyout the panel takes it (onKeyDown below, after its own
    // menus); from outside it (the rail, or nothing focused), this listener does, before anyone else, unless a menu or
    // a dialog there has it.
    const esc = (e: KeyboardEvent) => {
      if (e.key !== "Escape" || e.defaultPrevented) return;
      const target = e.target instanceof Element ? e.target : null;
      if (target && (box.current?.contains(target) || target.closest('[role="menu"], [role="listbox"], [role="dialog"], [role="alertdialog"]'))) return;
      e.preventDefault();
      e.stopPropagation();
      onClose("esc");
    };
    document.addEventListener("pointerdown", away);
    document.addEventListener("keydown", esc, true);
    box.current?.querySelector<HTMLElement>("button, input")?.focus();
    return () => {
      document.removeEventListener("pointerdown", away);
      document.removeEventListener("keydown", esc, true);
    };
  }, [flyout, onClose]);
  // A flyout closes once something is chosen: a press on a button that isn't a fold, a menu or a field.
  const chose = (e: React.MouseEvent) => {
    if (!flyout) return;
    const button = (e.target as HTMLElement).closest("button");
    if (!button || button.hasAttribute("aria-expanded") || button.hasAttribute("aria-haspopup") || button.closest(".el-panel__head")) return;
    window.setTimeout(() => onClose("chose"), 0);
  };
  return (
    <aside ref={box} className={`el-panel${flyout ? " el-panel--flyout" : ""}`} aria-label={`${PLACE_NAMES[place]} panel`} onClickCapture={chose}
      style={width ? { ["--el-panel-width" as string]: `${width}px` } : undefined}
      onKeyDown={(e) => {
        if (!flyout || e.key !== "Escape" || e.defaultPrevented) return;
        e.preventDefault();
        e.stopPropagation();
        onClose("esc");
      }}
      data-testid="nav-panel">
      {/* The panel's top cell of the title row: its empty part and its name move the window (UX-20). */}
      <div className="el-panel__head" data-tauri-drag-region="deep">
        <h2 className="el-panel__title">{PLACE_NAMES[place]}</h2>
        {menu && menu.length > 0 && <Menu label={`More for ${PLACE_NAMES[place]}`} items={menu} button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }} />}
        {pin && <Button size="sm" variant="quiet" icon={Icons.PinIcon} aria-label={pin.pinned ? "Unpin the panel" : "Pin the panel"} aria-pressed={pin.pinned}
          title={pin.pinned ? "Unpin: the panel opens over the page in a small window" : "Pin: keep the panel beside the page in a small window"} onClick={pin.onPin} />}
        <Button size="sm" variant="quiet" icon={Icons.SidebarIcon} aria-label={flyout ? "Close panel" : "Hide panel"} title={`${flyout ? "Close" : "Hide"} panel (${MAC ? "⌘" : "Ctrl+"}\\)`}
          aria-keyshortcuts="Meta+\ Control+\" onClick={flyout ? () => onClose("hide") : onHide} />
      </div>
      <div className="el-panel__body">{children}</div>
      {foot && <div className="el-panel__foot">{foot}</div>}
      {resize && (
        <ResizeEdge label={`Resize the ${PLACE_NAMES[place]} panel`} side="end" cssVar="--el-panel-width" width={resize.width}
          min={PANEL_MIN} max={PANEL_MAX} closeBelow={PANEL_CLOSE_BELOW} onWidth={resize.onWidth} onReset={resize.onReset} onClose={resize.onClose} />
      )}
    </aside>
  );
}

/**
 * A project in the Projects panel: its folder (`parent_id`, null at the top), whether it is pinned, and whether it is
 * archived (listed last; no server says so yet, PLAN_V2_1_POLISH UX-09 asks for the place).
 */
export type PanelProject = { id: string; name: string; parent_id?: string | null; pinned?: boolean; archived?: boolean };
/** From this many projects (more than 10) the Projects panel has a search box. */
export const PROJECT_SEARCH_FROM = 11;

/** How a panel's list of projects is drawn: its name, and the order of its top level (folders keep theirs). */
export type ProjectList = { label: string; order: (a: PanelProject, b: PanelProject) => number };

/** `roots` at the top of their list (as if in no folder), each with its folders under it (their links kept). */
export function withFolders(roots: readonly PanelProject[], all: readonly PanelProject[]): PanelProject[] {
  const out: PanelProject[] = roots.map((p) => ({ ...p, parent_id: null }));
  const seen = new Set(out.map((p) => p.id));
  for (let i = 0; i < out.length; i++) {
    for (const child of all) {
      if (child.parent_id === out[i].id && !seen.has(child.id)) {
        seen.add(child.id);
        out.push(child);
      }
    }
  }
  return out;
}

/**
 * The keys in the Projects panel's lists: ↑ ↓ (Home, End) go from project to project across Pinned, Projects and
 * Archived; → shows a project's folders, ← folds them (mirrored right to left).
 */
function treeKeys(e: React.KeyboardEvent<HTMLElement>) {
  const target = e.target as HTMLElement;
  if (!target.classList?.contains("el-nav-tree__name")) return;
  const rows = [...e.currentTarget.querySelectorAll<HTMLButtonElement>(".el-nav-tree__name")];
  const at = rows.indexOf(target as HTMLButtonElement);
  const go = (i: number) => {
    e.preventDefault();
    rows[Math.max(0, Math.min(rows.length - 1, i))]?.focus();
  };
  if (e.key === "ArrowDown") return go(at + 1);
  if (e.key === "ArrowUp") return go(at - 1);
  if (e.key === "Home") return go(0);
  if (e.key === "End") return go(rows.length - 1);
  if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") return;
  const rtl = getComputedStyle(target).direction === "rtl";
  const open = (e.key === "ArrowRight") !== rtl;
  const fold = target.closest(".el-nav-tree__row")?.querySelector<HTMLButtonElement>(".el-nav-tree__fold[aria-expanded]");
  if (!fold || (fold.getAttribute("aria-expanded") === "true") === open) return;
  e.preventDefault();
  fold.click();
}

/**
 * The Projects panel (UX-09): the project list, as a chat app's channels. Pinned first, then the rest under Projects
 * (its title opens All projects, the page; its ends sort the list, Name or Recent, and make a New project), then
 * Archived, folded, at the bottom; a search box from 11 projects (Enter opens the first match, ↓ goes into them). A
 * project is in one list only. No rows of views: All projects is the page's title. While the projects are read, shapes
 * of rows; with no project at all the shell shows no panel (the page says how to start).
 */
export function ProjectsPanel({ projects, current, onAll, allCurrent, onOpen, onNew, tree, loading = false, error = null }: {
  projects: readonly PanelProject[]; current: string | null; onAll: () => void; allCurrent: boolean; onOpen: (id: string) => void; onNew?: () => void;
  /** The tree as the sidebar draws it (folds kept), the current project marked. */
  tree: (projects: readonly PanelProject[], current: string | null, onOpen: (id: string) => void, list: ProjectList) => ReactNode;
  /** The projects are being read. */
  loading?: boolean;
  /** They couldn't be read (offline). */
  error?: string | null;
}) {
  const [find, setFind] = useState("");
  const [archivedOpen, setArchivedOpen] = useState(false);
  const [sort, setSort] = useProjectSort();
  // Built once a render that changes it: the sort, or the project opened (Recent moves).
  const order = useMemo(() => projectOrder(sort), [sort, current]);
  const box = useRef<HTMLDivElement>(null);
  const q = find.trim().toLowerCase();
  const live = projects.filter((p) => !p.archived);
  const ids = new Set(live.map((p) => p.id));
  const top = live.filter((p) => !p.parent_id || !ids.has(p.parent_id));
  const pinned = live.filter((p) => p.pinned);
  // A pinned project (a folder too) is under Pinned only, never in the Projects list as well.
  const rest = top.filter((p) => !p.pinned);
  const unpinned = live.filter((p) => !p.pinned);
  const archived = projects.filter((p) => p.archived);
  const matches = q ? projects.filter((p) => p.name.toLowerCase().includes(q)).map((p) => ({ ...p, parent_id: null })).sort(order) : [];
  const sortLabel = PROJECT_SORTS.find((s) => s.value === sort)!.label;
  const head = useId();
  const searchKeys = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter" && matches.length) {
      e.preventDefault();
      onOpen(matches[0].id);
    } else if (e.key === "ArrowDown" && matches.length) {
      e.preventDefault();
      box.current?.querySelector<HTMLButtonElement>(".el-nav-tree__name")?.focus();
    }
  };
  return (
    <div className="el-panel__group el-projects-panel" ref={box} onKeyDown={treeKeys}>
      {projects.length >= PROJECT_SEARCH_FROM && <SearchField label="Search projects" value={find} onChange={setFind} onKeyDown={searchKeys} className="el-panel__search" />}
      {q ? (
        matches.length ? tree(matches, current, onOpen, { label: "Matches", order })
          : <p className="el-caption el-secondary el-panel__none">No project matches</p>
      ) : (
        <>
          {pinned.length > 0 && (
            <section className="el-nav-section" aria-labelledby={`${head}-pinned`}>
              <div className="el-nav-section__head"><h3 className="el-nav-section__title el-caption" id={`${head}-pinned`}>Pinned</h3></div>
              {tree(withFolders(pinned, live), current, onOpen, { label: "Pinned", order })}
            </section>
          )}
          <section className="el-nav-section" aria-labelledby={`${head}-all`}>
            <div className="el-nav-section__head">
              <h3 className="el-nav-section__heading" id={`${head}-all`}>
                <button type="button" className="el-nav-section__link el-caption" aria-current={allCurrent ? "page" : undefined} aria-label="All projects" title="All projects"
                  onClick={onAll}>Projects</button>
              </h3>
              <Menu label="Sort projects" align="start"
                items={PROJECT_SORTS.map((s) => ({ label: s.label, radio: true, checked: s.value === sort, onPick: () => setSort(s.value) }))}
                button={{ variant: "quiet", size: "sm", icon: Icons.SortIcon, "aria-label": `Sort projects: ${sortLabel}`, title: `Sort projects: ${sortLabel}` }} />
              {onNew && <Button size="sm" variant="quiet" icon={Icons.PlusIcon} aria-label="New project" title="New project" onClick={onNew} />}
            </div>
            {loading ? (
              <Loading label="Loading projects" className="el-panel__loading">
                {[0, 1, 2].map((n) => <span key={n} className="el-panel__skeleton"><Skeleton width={n === 1 ? "medium" : "long"} /></span>)}
              </Loading>
            ) : error && projects.length === 0 ? (
              <p className="el-caption el-secondary el-panel__none" role="status">Couldn't load the projects.</p>
            ) : rest.length > 0 && tree(withFolders(rest, unpinned), current, onOpen, { label: "Projects", order })}
          </section>
          {archived.length > 0 && (
            <section className="el-nav-section" aria-labelledby={`${head}-archived`}>
              <div className="el-nav-section__head">
                <h3 className="el-nav-section__heading" id={`${head}-archived`}>
                  <button type="button" className="el-nav-section__title el-caption" aria-expanded={archivedOpen} onClick={() => setArchivedOpen((o) => !o)}>
                    <Icon icon={archivedOpen ? Icons.ChevronDownIcon : Icons.ChevronEndIcon} directional />Archived{" "}
                    <span className="el-nav-item__count el-caption el-num el-nav-item__count--quiet">{archived.length}</span>
                  </button>
                </h3>
              </div>
              {archivedOpen && tree(withFolders(archived.filter((p) => !p.parent_id || !archived.some((a) => a.id === p.parent_id)), archived), current, onOpen,
                { label: "Archived", order })}
            </section>
          )}
        </>
      )}
    </div>
  );
}

/** While recordings are dragged: No project takes them out of their project (shown only then). */
export function NoProjectDrop() {
  return (
    <div className="el-nav-drop" aria-hidden="true" {...{ [DROP_ATTRIBUTE]: "" }}>
      <Icon icon={Icons.InboxIcon} /><span>No project</span>
    </div>
  );
}
