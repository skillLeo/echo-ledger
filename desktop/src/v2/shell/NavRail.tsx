// The three levels of navigation (PLAN_NAV_3_LEVELS §1–§3, §6): the rail of main places (72 px, an icon and a short
// name under it, always there; DES-01: 56 px items, the selected one's pill, a tooltip with its shortcut), the place's
// panel of views and collections (⊟ or ⌘\ hides it; in a small window it opens as a flyout from the rail and closes
// once something is chosen), and the page. The pieces are here; Shell puts them together with the app's state.
import { createContext, useContext, useEffect, useId, useRef, useState, type ReactNode } from "react";
import { Button, Icon, Icons, SearchField } from "../components";
import type { IconType } from "../components/Button";
import { Menu, type MenuItem } from "../components/Menu";
import { useTip } from "../components/Tip";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";
import { recentProjects } from "./recentProjects";
import { railBadgeLabel } from "../../lib/badge";

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
  const name = count ? `${label}, ${count}` : dot && dotLabel ? `${label}, ${dotLabel}` : label;
  const [tipProps, tip] = useTip(shortcut ? `${label} ${shortcut}` : label);
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

export function PlacePanel({ place, menu, onHide, flyout, onClose, children, foot, pin }: {
  place: Place; menu?: MenuItem[]; onHide: () => void; flyout: boolean; onClose: (why: FlyoutClose) => void; children: ReactNode; foot?: ReactNode;
  /** A small window (UX-02): the pin that docks the panel there, or takes it back to a flyout. */
  pin?: { pinned: boolean; onPin: () => void };
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
    </aside>
  );
}

/** A project in the Projects panel: its folder (`parent_id`, null at the top) and whether it is pinned. */
export type PanelProject = { id: string; name: string; parent_id?: string | null; pinned?: boolean };
/** From this many projects the Projects panel has a search box. */
export const PROJECT_SEARCH_FROM = 11;

/**
 * The Projects panel: All projects (the page), then which projects the list below shows (every one as a tree,
 * Pinned, or Recent: opened here lately), + New project, and the list, with a search box from 11 projects.
 */
export function ProjectsPanel({ projects, current, onAll, allCurrent, onOpen, onNew, onRequest, tree }: {
  projects: readonly PanelProject[]; current: string | null; onAll: () => void; allCurrent: boolean; onOpen: (id: string) => void; onNew?: () => void;
  /** Request files: a link anyone can use to send voice notes and files (into no project). */
  onRequest?: () => void;
  /** The tree as the sidebar drew it (folds kept), the current project marked. */
  tree: (projects: readonly PanelProject[], current: string | null, onOpen: (id: string) => void) => ReactNode;
}) {
  const [find, setFind] = useState("");
  const [which, setWhich] = useState<"all" | "pinned" | "recent">("all");
  const recent = recentProjects();
  const q = find.trim().toLowerCase();
  const picked = which === "pinned" ? projects.filter((p) => p.pinned)
    : which === "recent" ? recent.map((id) => projects.find((p) => p.id === id)).filter((p): p is PanelProject => !!p) : projects;
  // Pinned, Recent and a search list their projects flat; All keeps the tree.
  const flat = which !== "all" || !!q;
  const shown = (q ? picked.filter((p) => p.name.toLowerCase().includes(q)) : picked).map((p) => (flat ? { ...p, parent_id: null } : p));
  const view = (key: typeof which, label: string, icon: IconType, count: number) => (
    <li>
      <button type="button" className="el-nav-item el-nav-item--row" aria-pressed={which === key} onClick={() => setWhich(key)}>
        <Icon icon={icon} /><span className="el-nav-item__label">{label}</span>
        <span className="el-nav-item__count el-caption el-num el-nav-item__count--quiet">{count}</span>
      </button>
    </li>
  );
  return (
    <div className="el-panel__group">
      <ul className="el-nav-list" aria-label="Projects views">
        <li>
          <button type="button" className="el-nav-item el-nav-item--row" aria-current={allCurrent ? "page" : undefined} onClick={() => { setWhich("all"); onAll(); }}>
            <Icon icon={Icons.FolderIcon} /><span className="el-nav-item__label">All projects</span>
            <span className="el-nav-item__count el-caption el-num el-nav-item__count--quiet">{projects.length}</span>
          </button>
        </li>
        {projects.some((p) => p.pinned) && view("pinned", "Pinned", Icons.PinIcon, projects.filter((p) => p.pinned).length)}
        {recent.some((id) => projects.some((p) => p.id === id)) && view("recent", "Recent", Icons.ClockIcon, recent.filter((id) => projects.some((p) => p.id === id)).length)}
        {onNew && (
          <li>
            <button type="button" className="el-nav-item el-nav-item--row el-nav-item--add" onClick={onNew}>
              <Icon icon={Icons.PlusIcon} /><span className="el-nav-item__label">New project</span>
            </button>
          </li>
        )}
        {onRequest && (
          <li>
            <button type="button" className="el-nav-item el-nav-item--row" onClick={onRequest} title="A link anyone can use to send you voice notes and files">
              <Icon icon={Icons.RequestFilesIcon} /><span className="el-nav-item__label">Request files</span>
            </button>
          </li>
        )}
      </ul>
      {projects.length >= PROJECT_SEARCH_FROM && <SearchField label="Search projects" value={find} onChange={setFind} className="el-panel__search" />}
      {q && shown.length === 0 && <p className="el-caption el-secondary el-panel__none">No project matches</p>}
      {shown.length > 0 && tree(shown, current, onOpen)}
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
