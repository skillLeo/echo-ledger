// The three levels of navigation (PLAN_NAV_3_LEVELS §1–§3, §6): the rail of main places (72 px, an icon and a short
// name under it, always there), the place's panel of views and collections (260 px; ⊟ or ⌘\ hides it; under 1100 px
// it opens as a flyout from the rail and closes once something is chosen), and the page. The pieces are here; Shell
// puts them together with the app's state.
import { useEffect, useRef, useState, type ReactNode } from "react";
import { Button, Icon, Icons, SearchField } from "../components";
import type { IconType } from "../components/Button";
import { Menu, type MenuItem } from "../components/Menu";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";
import { recentProjects } from "./recentProjects";

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

/** One place in the rail: its icon, its short name under it, a count (Library's Needs you, Activity's) or a dot. */
export function RailItem({ icon, label, current, onClick, count, dot, dotLabel, shortcut, keys }: {
  icon: IconType; label: string; current: boolean; onClick: () => void; count?: number; dot?: boolean; dotLabel?: string;
  /** For the tooltip and assistive tools: "⌘1" and "Meta+1 Control+1". */
  shortcut?: string; keys?: string;
}) {
  const name = count ? `${label}, ${count}` : dot && dotLabel ? `${label}, ${dotLabel}` : label;
  return (
    <button type="button" className="el-rail__item" aria-current={current ? "page" : undefined} aria-label={name}
      title={shortcut ? `${label} (${shortcut})` : label} aria-keyshortcuts={keys} onClick={onClick}>
      <span className="el-rail__icon">
        <Icon icon={icon} size="md" />
        {count ? <span className="el-rail__count el-num" aria-hidden="true">{count > 99 ? "99+" : count}</span>
          : dot ? <span className="el-rail__dot" aria-hidden="true" /> : null}
      </span>
      <span className="el-rail__label" aria-hidden="true">{label}</span>
    </button>
  );
}

export function railItemProps(place: Place) {
  return { label: PLACE_NAMES[place], shortcut: placeKey(place), keys: placeShortcut(place) };
}

/**
 * A place's panel: its name, its ⋯ menu and ⊟ (hide; ⌘\ too), what it lists, and its foot. As a flyout (a narrow
 * window) it floats over the page and closes once something in it is chosen, on Esc, or on a press outside.
 */
export function PlacePanel({ place, menu, onHide, flyout, onClose, children, foot }: {
  place: Place; menu?: MenuItem[]; onHide: () => void; flyout: boolean; onClose: () => void; children: ReactNode; foot?: ReactNode;
}) {
  const box = useRef<HTMLElement>(null);
  useEffect(() => {
    if (!flyout) return;
    const away = (e: PointerEvent) => {
      if (!box.current?.contains(e.target as Node) && !(e.target as HTMLElement).closest?.(".el-rail")) onClose();
    };
    const esc = (e: KeyboardEvent) => {
      if (e.key === "Escape" && !e.defaultPrevented) onClose();
    };
    document.addEventListener("pointerdown", away);
    document.addEventListener("keydown", esc);
    box.current?.querySelector<HTMLElement>("button, input")?.focus();
    return () => {
      document.removeEventListener("pointerdown", away);
      document.removeEventListener("keydown", esc);
    };
  }, [flyout, onClose]);
  // A flyout closes once something is chosen: a press on a button that isn't a fold, a menu or a field.
  const chose = (e: React.MouseEvent) => {
    if (!flyout) return;
    const button = (e.target as HTMLElement).closest("button");
    if (!button || button.hasAttribute("aria-expanded") || button.hasAttribute("aria-haspopup") || button.closest(".el-panel__head")) return;
    window.setTimeout(onClose, 0);
  };
  return (
    <aside ref={box} className={`el-panel${flyout ? " el-panel--flyout" : ""}`} aria-label={`${PLACE_NAMES[place]} panel`} onClickCapture={chose}
      data-testid="nav-panel">
      <div className="el-panel__head">
        <h2 className="el-panel__title">{PLACE_NAMES[place]}</h2>
        {menu && menu.length > 0 && <Menu label={`More for ${PLACE_NAMES[place]}`} items={menu} button={{ variant: "quiet", size: "sm", icon: Icons.MoreIcon }} />}
        <Button size="sm" variant="quiet" icon={Icons.SidebarIcon} aria-label={flyout ? "Close panel" : "Hide panel"} title={`${flyout ? "Close" : "Hide"} panel (${MAC ? "⌘" : "Ctrl+"}\\)`}
          aria-keyshortcuts="Meta+\ Control+\" onClick={flyout ? onClose : onHide} />
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
export function ProjectsPanel({ projects, current, onAll, allCurrent, onOpen, onNew, tree }: {
  projects: readonly PanelProject[]; current: string | null; onAll: () => void; allCurrent: boolean; onOpen: (id: string) => void; onNew?: () => void;
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
