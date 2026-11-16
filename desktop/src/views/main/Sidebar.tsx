// The sidebar (v1.6, P4.1): Search at the top (the window's one search, the palette: recordings, settings, pages and
// commands), then Home · Library (its smart folders) · Projects (the pinned
// ones) · Activity · Shared (with me, my links, request links) · Upload, and at the bottom Help & shortcuts, Settings and
// Collapse sidebar. 240 px by default, 200–320 by dragging its edge (kept on this computer), or a 64 px icon rail ("[").
import { useEffect, useState, type ReactNode } from "react";
import type { ClientFolder, SmartFolder } from "../../lib/ipc";
import type { LibraryPlace, LibraryView } from "../../lib/folders";
import type { MainTab } from "../../lib/mainView";
import { clampSidebar, loadOpenGroups, saveOpenGroups, SIDEBAR_DEFAULT, SIDEBAR_MAX, SIDEBAR_MIN, type PageEntry, type SidebarGroup } from "../../lib/shell";
import { badgeLabel, badgeSpoken } from "../../lib/badge";
import { BellIcon, ChevronRightIcon, FolderIcon, HelpIcon, HomeIcon, ImportIcon, LibraryIcon, LinkIcon, RequestFilesIcon, SearchIcon, ShareIcon, SettingsIcon, SidebarIcon, UsersIcon } from "../../components/Icons";
import { BrandMark } from "../../components/BrandMark";
import { PlaceList } from "./SecondaryColumn";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";

/** The Shared page's tabs (v1.6). */
export type SharedTab = "with-me" | "links" | "requests";
export const SHARED_TABS: readonly (readonly [SharedTab, string])[] = [["with-me", "With me"], ["links", "My links"], ["requests", "Request links"]];

/** A page the search (the palette) opens by name. */
export interface ShellPage extends PageEntry {
  open: () => void;
}

/** The search's name for screen readers and its tooltip: one search for everything, the same as the top bar's. */
export function sidebarSearchLabel(keys: string): string {
  return `Search recordings, settings and pages (${keys})`;
}

export function Sidebar({ tab, collapsed, onCollapse, width, onWidth, needsYou, recording, places, folder, view, onPlace, projects, projectId, onProject,
  sharedTab, onShared, onGo, onUpload, onHelp, onSearch, searchKeys, uploading, uploadsWait = false, canUpload, libraryColumn = false }: {
  tab: MainTab;
  collapsed: boolean;
  onCollapse: () => void;
  width: number;
  onWidth: (width: number, done: boolean) => void;
  /** The Activity badge: only what needs the person (lib/badge.ts). */
  needsYou: number;
  recording: boolean;
  places: readonly LibraryPlace[];
  folder: SmartFolder | null;
  view: LibraryView | null;
  onPlace: (place: LibraryPlace) => void;
  projects: readonly ClientFolder[] | null;
  projectId: string | null;
  onProject: (id: string | null) => void;
  sharedTab: SharedTab | null;
  onShared: (tab: SharedTab) => void;
  onGo: (tab: MainTab) => void;
  onUpload: () => void;
  onHelp: () => void;
  /** Opens the window's search (the palette, the same as the top bar's and Ctrl/⌘+K). */
  onSearch: () => void;
  /** "⌘K" or "Ctrl+K". */
  searchKeys: string;
  /** "Uploading 2 recordings…" while something is on its way. */
  uploading: string | null;
  /** The server holds the uploads (v1.6, P2.7): the line says why, without the spinner. */
  uploadsWait?: boolean;
  canUpload: boolean;
  /** The Library page shows its folders in a column of its own (wide enough): the sidebar's group would repeat them. */
  libraryColumn?: boolean;
}) {
  // The groups the person opened or closed with their arrow (kept on this computer), and the ones a click on a group's
  // row opened for now (never kept: the arrow is the person's choice).
  const [saved, setSaved] = useState<Set<SidebarGroup>>(loadOpenGroups);
  const [rowOpened, setRowOpened] = useState<ReadonlySet<SidebarGroup>>(() => new Set());
  const isOpen = (group: SidebarGroup) => saved.has(group) || rowOpened.has(group);
  const toggleGroup = (group: SidebarGroup) => {
    const closing = isOpen(group);
    setRowOpened((current) => {
      if (!current.has(group)) return current;
      const next = new Set(current);
      next.delete(group);
      return next;
    });
    setSaved((current) => {
      const next = new Set(current);
      if (closing) next.delete(group);
      else next.add(group);
      saveOpenGroups(next);
      return next;
    });
  };
  const pinned = (projects ?? []).filter((p) => p.pinned);
  // A group's whole row opens it (D-121): its label goes to the page and shows what is under it, for now; the arrow
  // beside it shows or hides that and is remembered. The Library's row leaves its group alone while the Library page
  // shows the same folders in its own column.
  const openGroup = (group: SidebarGroup) => {
    if (group === "library" && libraryColumn) return;
    setRowOpened((current) => (current.has(group) ? current : new Set([...current, group])));
  };
  // Widened until the Library's column shows: the group its row opened for now closes (the arrow's choice stays).
  useEffect(() => {
    if (!libraryColumn) return;
    setRowOpened((current) => {
      if (!current.has("library")) return current;
      const next = new Set(current);
      next.delete("library");
      return next;
    });
  }, [libraryColumn]);
  const navButton = (id: MainTab, label: string, icon: ReactNode, extra?: ReactNode, current = tab === id, group?: SidebarGroup) => (
    <button type="button" className={`nav-btn ${current ? "active" : ""}`} aria-current={current ? "page" : undefined}
      title={collapsed ? label : undefined} onClick={() => { onGo(id); if (group && !collapsed) openGroup(group); }}>
      {icon}<span className="nav-label">{label}</span>{extra}
    </button>
  );
  const expander = (group: SidebarGroup, label: string) => (collapsed ? null : (
    <button type="button" className={`nav-expand ${isOpen(group) ? "open" : ""}`} aria-expanded={isOpen(group)}
      aria-label={`${isOpen(group) ? "Hide" : "Show"} ${label}`} title={`${isOpen(group) ? "Hide" : "Show"} ${label}`} onClick={() => toggleGroup(group)}>
      <ChevronRightIcon size={14} />
    </button>
  ));

  // Dragging the edge: 200–320 px, kept when the button is let go; double-click puts it back to 240.
  const startResize = (e: React.PointerEvent) => {
    if (e.button !== 0) return;
    e.preventDefault();
    const startX = e.clientX;
    const startWidth = width;
    let last = startWidth;
    const move = (m: PointerEvent) => {
      last = clampSidebar(startWidth + m.clientX - startX);
      onWidth(last, false);
    };
    const up = () => {
      window.removeEventListener("pointermove", move);
      window.removeEventListener("pointerup", up);
      document.body.classList.remove("resizing-sidebar");
      onWidth(last, true);
    };
    document.body.classList.add("resizing-sidebar");
    window.addEventListener("pointermove", move);
    window.addEventListener("pointerup", up);
  };
  const resizeKey = (e: React.KeyboardEvent) => {
    const step = e.shiftKey ? 40 : 16;
    if (e.key === "ArrowLeft") onWidth(clampSidebar(width - step), true);
    else if (e.key === "ArrowRight") onWidth(clampSidebar(width + step), true);
    else if (e.key === "Home") onWidth(SIDEBAR_MIN, true);
    else if (e.key === "End") onWidth(SIDEBAR_MAX, true);
    else return;
    e.preventDefault();
  };

  return (
    <aside className="sidebar" aria-label="Sidebar">
      <div className="brand">
        <BrandMark size={24} />
        <span className="brand-name">EchoLedger</span>
      </div>
      <div className="sidebar-main">
      {collapsed ? (
        <button type="button" className="nav-btn find-rail" title={sidebarSearchLabel(searchKeys)} aria-label={sidebarSearchLabel(searchKeys)} onClick={onSearch}>
          <SearchIcon size={18} />
        </button>
      ) : (
        <button type="button" className="sidebar-search" aria-label={sidebarSearchLabel(searchKeys)} title={sidebarSearchLabel(searchKeys)} onClick={onSearch}>
          <SearchIcon size={16} />
          <span className="sidebar-search-text">Search</span>
          <span className="kbd" aria-hidden="true">{searchKeys}</span>
        </button>
      )}
        <nav className="nav" aria-label="Main">
          <div className="nav-group">
            {navButton("record", "Home", <HomeIcon size={20} filled={tab === "record"} />, recording && <span className="status-dot live" aria-hidden="true" />)}
          </div>
          <div className="nav-group">
            <div className="nav-row">
              {navButton("library", "Library", <LibraryIcon size={20} filled={tab === "library"} />, undefined, tab === "library", "library")}
              {expander("library", "Library's smart folders")}
            </div>
            {!collapsed && isOpen("library") && <PlaceList places={places} folder={tab === "library" ? folder : null} view={tab === "library" ? view : null} onOpen={onPlace} label="Smart folders in the sidebar" compact />}
          </div>
          <div className="nav-group">
            <div className="nav-row">
              {navButton("projects", "Projects", <FolderIcon size={20} filled={tab === "projects"} />, undefined, tab === "projects", pinned.length > 0 ? "projects" : undefined)}
              {pinned.length > 0 && expander("projects", "pinned projects")}
            </div>
            {!collapsed && isOpen("projects") && pinned.length > 0 && (
              <ul className="place-list in-sidebar" aria-label="Pinned projects">
                {pinned.map((p) => (
                  <li key={p.id} className={`place-row ${tab === "projects" && projectId === p.id ? "selected" : ""}`} {...{ [DROP_ATTRIBUTE]: p.id }}>
                    <button type="button" className="place-open" aria-current={tab === "projects" && projectId === p.id ? "true" : undefined} onClick={() => onProject(p.id)} title={p.name}>
                      <FolderIcon size={16} /><span className="place-name">{p.name}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
          <div className="nav-group">
            {navButton("activity", "Activity", <BellIcon size={20} filled={tab === "activity"} />,
              needsYou > 0 && <><span className="nav-count" aria-hidden="true">{badgeLabel(needsYou)}</span><span className="sr-only">, {badgeSpoken(needsYou)}</span></>)}
          </div>
          <div className="nav-group">
            <div className="nav-row">
              {navButton("shared", "Shared", <ShareIcon size={20} filled={tab === "shared"} />, undefined, tab === "shared", "shared")}
              {expander("shared", "what is shared")}
            </div>
            {!collapsed && isOpen("shared") && (
              <ul className="place-list in-sidebar" aria-label="Shared">
                {SHARED_TABS.map(([id, label]) => {
                  const here = tab === "shared" && sharedTab === id;
                  const Icon = id === "with-me" ? UsersIcon : id === "links" ? LinkIcon : RequestFilesIcon;
                  return (
                    <li key={id} className={`place-row ${here ? "selected" : ""}`}>
                      <button type="button" className="place-open" aria-current={here ? "true" : undefined} onClick={() => onShared(id)}>
                        <Icon size={16} /><span className="place-name">{label}</span>
                      </button>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
          {canUpload && (
            <div className="nav-group">
              <button type="button" className="nav-btn" title={collapsed ? "Upload" : "Voice notes, audio or video files. You can also drop them anywhere in this window."} onClick={onUpload}>
                <ImportIcon size={20} /><span className="nav-label">Upload</span>
              </button>
            </div>
          )}
        </nav>
      </div>
      <div className="sidebar-footer">
        {uploading && !collapsed && (
          <div className={`footer-state small muted ${uploadsWait ? "waiting" : ""}`} aria-live="polite">{!uploadsWait && <span className="spinner" aria-hidden="true" />}{uploading}</div>
        )}
        <button type="button" className="nav-btn footer-btn" title={collapsed ? "Help & shortcuts (?)" : undefined} onClick={onHelp}>
          <HelpIcon size={18} /><span className="nav-label">Help &amp; shortcuts</span><span className="kbd nav-kbd" aria-hidden="true">?</span>
        </button>
        <button type="button" className={`nav-btn footer-btn ${tab === "settings" ? "active" : ""}`} aria-current={tab === "settings" ? "page" : undefined}
          title={collapsed ? "Settings" : undefined} onClick={() => onGo("settings")}>
          <SettingsIcon size={18} filled={tab === "settings"} /><span className="nav-label">Settings</span>
        </button>
        <button type="button" className="nav-btn footer-btn" aria-label={collapsed ? "Expand the sidebar" : "Collapse the sidebar"} aria-expanded={!collapsed}
          title={collapsed ? "Expand the sidebar ([)" : undefined} onClick={onCollapse}>
          <SidebarIcon size={18} /><span className="nav-label">Collapse sidebar</span><span className="kbd nav-kbd" aria-hidden="true">[</span>
        </button>
      </div>
      {!collapsed && (
        <div className="sidebar-resize" role="separator" aria-orientation="vertical" aria-label="Resize the sidebar" tabIndex={0}
          aria-valuemin={SIDEBAR_MIN} aria-valuemax={SIDEBAR_MAX} aria-valuenow={width} title="Drag to resize. Double-click for the usual width."
          onPointerDown={startResize} onDoubleClick={() => onWidth(SIDEBAR_DEFAULT, true)} onKeyDown={resizeKey} />
      )}
    </aside>
  );
}
