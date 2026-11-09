// The sidebar (v1.6, P4.1): "Find a page" at the top, then Home · Library (its smart folders) · Projects (the pinned
// ones) · Activity · Shared (with me, my links, request links) · Upload, and at the bottom Help & shortcuts, Account and
// Collapse sidebar. 240 px by default, 200–320 by dragging its edge (kept on this computer), or a 64 px icon rail ("[").
import { useEffect, useId, useRef, useState, type ReactNode } from "react";
import type { ClientFolder, SmartFolder } from "../../lib/ipc";
import type { LibraryPlace, LibraryView } from "../../lib/folders";
import type { MainTab } from "../../lib/mainView";
import { clampSidebar, findPages, loadOpenGroups, saveOpenGroups, SIDEBAR_DEFAULT, SIDEBAR_MAX, SIDEBAR_MIN, type PageEntry, type SidebarGroup } from "../../lib/shell";
import { badgeLabel, badgeSpoken } from "../../lib/badge";
import { BellIcon, ChevronRightIcon, CloseIcon, FolderIcon, HelpIcon, HomeIcon, ImportIcon, LibraryIcon, LinkIcon, RequestFilesIcon, SearchIcon, ShareIcon, SidebarIcon, UserIcon, UsersIcon } from "../../components/Icons";
import { BrandMark } from "../../components/BrandMark";
import { PlaceList } from "./SecondaryColumn";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";

/** The Shared page's tabs (v1.6). */
export type SharedTab = "with-me" | "links" | "requests";
export const SHARED_TABS: readonly (readonly [SharedTab, string])[] = [["with-me", "With me"], ["links", "My links"], ["requests", "Request links"]];

/** A page "Find a page" opens. */
export interface ShellPage extends PageEntry {
  open: () => void;
}

const FIND_LIMIT = 8;

export function Sidebar({ tab, collapsed, onCollapse, width, onWidth, needsYou, recording, places, folder, view, onPlace, projects, projectId, onProject,
  sharedTab, onShared, onGo, onUpload, onHelp, pages, uploading, uploadsWait = false, canUpload }: {
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
  pages: readonly ShellPage[];
  /** "Uploading 2 recordings…" while something is on its way. */
  uploading: string | null;
  /** The server holds the uploads (v1.6, P2.7): the line says why, without the spinner. */
  uploadsWait?: boolean;
  canUpload: boolean;
}) {
  const [open, setOpen] = useState<Set<SidebarGroup>>(loadOpenGroups);
  const [query, setQuery] = useState("");
  const [findIndex, setFindIndex] = useState(0);
  const findRef = useRef<HTMLInputElement | null>(null);
  const findList = useId();
  const toggleGroup = (group: SidebarGroup) => setOpen((current) => {
    const next = new Set(current);
    if (next.has(group)) next.delete(group);
    else next.add(group);
    saveOpenGroups(next);
    return next;
  });
  const found = findPages(pages, query).slice(0, FIND_LIMIT);
  const chosen = Math.min(findIndex, Math.max(0, found.length - 1));
  const openPage = (page: ShellPage | undefined) => {
    if (!page) return;
    setQuery("");
    setFindIndex(0);
    page.open();
  };
  // "Find a page" from the rail: the sidebar opens and the field takes the keys.
  const [focusFind, setFocusFind] = useState(false);
  useEffect(() => {
    if (focusFind && !collapsed) {
      findRef.current?.focus();
      setFocusFind(false);
    }
  }, [focusFind, collapsed]);

  const pinned = (projects ?? []).filter((p) => p.pinned);
  const navButton = (id: MainTab, label: string, icon: ReactNode, extra?: ReactNode, current = tab === id) => (
    <button type="button" className={`nav-btn ${current ? "active" : ""}`} aria-current={current ? "page" : undefined}
      title={collapsed ? label : undefined} onClick={() => onGo(id)}>
      {icon}<span className="nav-label">{label}</span>{extra}
    </button>
  );
  const expander = (group: SidebarGroup, label: string) => (collapsed ? null : (
    <button type="button" className={`nav-expand ${open.has(group) ? "open" : ""}`} aria-expanded={open.has(group)}
      aria-label={`${open.has(group) ? "Hide" : "Show"} ${label}`} title={`${open.has(group) ? "Hide" : "Show"} ${label}`} onClick={() => toggleGroup(group)}>
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
        <button type="button" className="nav-btn find-rail" title="Find a page" aria-label="Find a page" onClick={() => { onCollapse(); setFocusFind(true); }}>
          <SearchIcon size={18} />
        </button>
      ) : (
        <div className="find-page">
          <label className="searchbox compact find-box">
            <SearchIcon size={16} />
            <input ref={findRef} value={query} placeholder="Find a page" aria-label="Find a page" role="combobox" aria-expanded={query.trim().length > 0}
              aria-controls={findList} aria-activedescendant={found[chosen] ? `${findList}-${found[chosen].id}` : undefined}
              onChange={(e) => { setQuery(e.target.value); setFindIndex(0); }}
              onKeyDown={(e) => {
                if (e.key === "Escape") {
                  if (query) e.stopPropagation();
                  setQuery("");
                } else if (e.key === "ArrowDown") {
                  e.preventDefault();
                  setFindIndex((chosen + 1) % Math.max(1, found.length));
                } else if (e.key === "ArrowUp") {
                  e.preventDefault();
                  setFindIndex((chosen - 1 + found.length) % Math.max(1, found.length));
                } else if (e.key === "Enter") {
                  e.preventDefault();
                  openPage(found[chosen]);
                }
              }} />
            {query && <button type="button" className="clear" aria-label="Clear" onClick={() => setQuery("")}><CloseIcon size={14} /></button>}
          </label>
        </div>
      )}
      {!collapsed && query.trim() ? (
        <ul id={findList} className="find-results" role="listbox" aria-label="Pages">
          {found.length === 0 && <li className="small muted find-empty" role="presentation">No page matches.</li>}
          {found.map((page, i) => (
            <li key={page.id} id={`${findList}-${page.id}`} role="option" aria-selected={i === chosen} className={`find-result ${i === chosen ? "active" : ""}`}
              onMouseEnter={() => setFindIndex(i)} onMouseDown={(e) => { e.preventDefault(); openPage(page); }}>
              <span className="find-label">{page.label}</span>{page.group && <span className="find-group small muted">{page.group}</span>}
            </li>
          ))}
        </ul>
      ) : (
        <nav className="nav" aria-label="Main">
          <div className="nav-group">
            {navButton("record", "Home", <HomeIcon size={20} filled={tab === "record"} />, recording && <span className="status-dot live" aria-hidden="true" />)}
          </div>
          <div className="nav-group">
            <div className="nav-row">
              {navButton("library", "Library", <LibraryIcon size={20} filled={tab === "library"} />)}
              {expander("library", "Library's smart folders")}
            </div>
            {!collapsed && open.has("library") && <PlaceList places={places} folder={tab === "library" ? folder : null} view={tab === "library" ? view : null} onOpen={onPlace} label="Smart folders in the sidebar" compact />}
          </div>
          <div className="nav-group">
            <div className="nav-row">
              {navButton("projects", "Projects", <FolderIcon size={20} filled={tab === "projects"} />)}
              {pinned.length > 0 && expander("projects", "pinned projects")}
            </div>
            {!collapsed && open.has("projects") && pinned.length > 0 && (
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
              {navButton("shared", "Shared", <ShareIcon size={20} filled={tab === "shared"} />)}
              {expander("shared", "what is shared")}
            </div>
            {!collapsed && open.has("shared") && (
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
      )}
      </div>
      <div className="sidebar-footer">
        {uploading && !collapsed && (
          <div className={`footer-state small muted ${uploadsWait ? "waiting" : ""}`} aria-live="polite">{!uploadsWait && <span className="spinner" aria-hidden="true" />}{uploading}</div>
        )}
        <button type="button" className="nav-btn footer-btn" title={collapsed ? "Help & shortcuts (?)" : undefined} onClick={onHelp}>
          <HelpIcon size={18} /><span className="nav-label">Help &amp; shortcuts</span><span className="kbd nav-kbd" aria-hidden="true">?</span>
        </button>
        <button type="button" className={`nav-btn footer-btn ${tab === "settings" ? "active" : ""}`} aria-current={tab === "settings" ? "page" : undefined}
          title={collapsed ? "Account" : undefined} onClick={() => onGo("settings")}>
          <UserIcon size={18} /><span className="nav-label">Account</span>
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
