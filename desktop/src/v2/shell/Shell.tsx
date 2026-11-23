// The v2 app shell (ui_v2.shell), laid out as PLAN_V2_PARITY 2 decides for the desktop: "● Record" at the top, then
// Library, Projects, Live and Ask; at the foot Activity (with how many need you), Settings, the account and the status
// line. Record opens the page where recording starts (today's Home and its Live Assist card). Modules not rebuilt yet
// show today's page inside the new frame; Live and Ask, without their page yet, say where to find them meanwhile.
import { useEffect, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type PointerEvent as ReactPointerEvent, type ReactNode } from "react";
import { Avatar, Badge, Button, EmptyState, Icon, Icons, NavItem, Sidebar, Status } from "../components";
import { Menu, type MenuItem } from "../components/Menu";
import { initials } from "../settings/initials";
import type { MainTab } from "../../lib/mainView";
import { isConfirmOpen } from "../../lib/confirm";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";
import { clampSidebar, SIDEBAR_DEFAULT, SIDEBAR_MAX, SIDEBAR_MIN } from "../../lib/shell";
import { NavShell } from "./NavShell";
import { NoProjectDrop, ProjectsPanel, type Place } from "./NavRail";

/** How long Record ignores presses when the status never changes (the core said no, or was slow to say). */
const PRESS_HOLD_MS = 3000;
/** Below this window height the foot packs tighter, so the sidebar's middle (the Library's sections) keeps its room. */
export const SHORT_BELOW = 820;

/** A key typed into a field, a select, editable text or an open dialog or menu is theirs, never app search's. */
const typing = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName)
    || !!target.closest('[role="dialog"], [role="alertdialog"], [role="menu"], [role="listbox"]'));

/** Search's keys in the sidebar tooltip: ⌘F on a Mac, Ctrl+F on Windows (⌘K / Ctrl+K open it too). */
export function searchHint(paletteKeys?: string): { title: string; keys: string } {
  const mac = paletteKeys?.startsWith("⌘") ?? false;
  return { title: `Search · ${mac ? "⌘F" : "Ctrl+F"}`, keys: mac ? "Meta+F Meta+K" : "Control+F Control+K" };
}

export type Module = "record" | "library" | "projects" | "live" | "ask" | "activity" | "settings";

/** The place in the sidebar a page of today's app belongs to. */
export function moduleOf(tab: MainTab): Module {
  switch (tab) {
    case "record": return "record";
    case "activity": return "activity";
    case "projects": return "projects";
    case "library":
    case "shared": return "library";
    case "settings": return "settings";
  }
}

const MODULE_TAB: Record<Exclude<Module, "live" | "ask">, MainTab> = {
  record: "record", library: "library", projects: "projects", activity: "activity", settings: "settings",
};

export { shellStatus, type ShellStatus } from "./status";
import type { ShellStatus } from "./status";

export type ShellProps = {
  /** Today's page; its module is highlighted. */
  tab: MainTab;
  onGo: (tab: MainTab) => void;
  status: ShellStatus;
  /** Who is signed in: the profile at the sidebar's foot, which opens Settings. */
  me?: { name: string; email: string; plan: string | null } | null;
  /** The Live module's page (ui_v2.live); without it Live says where to find its features meanwhile. */
  live?: ReactNode;
  /** The Ask module's page (ui_v2.ask); without it Ask says where to ask meanwhile. */
  ask?: ReactNode;
  /** The new shell's own notices above every page (the After a call notice). */
  notices?: ReactNode;
  /** The banners above every page (one at a time). */
  banners?: ReactNode;
  /** How many things need the person (Activity's badge); none or 0 shows no badge. */
  activity?: number;
  /** A recording is under way: Record says so and stops it. */
  recording?: boolean;
  /** Record now (or stop); without it (a viewer) Record opens the Record page. Its promise ends when the core answered. */
  onRecord?: () => unknown;
  /** "Needs setup" in the status line opens Setup & health. */
  onSetup?: () => void;
  onVoiceNote?: () => void;
  /** Upload files (the Record menu), Search (the palette, with its keys) and Help & shortcuts, as classic's sidebar. */
  onUpload?: () => void;
  /** New project and Request link (classic's + menu), in Record's menu. */
  onNewProject?: () => void;
  onRequestLink?: () => void;
  onSearch?: () => void;
  searchKeys?: string;
  onHelp?: () => void;
  /** The super admin's Admin panel on the website (classic's avatar menu); none for everyone else. */
  onAdmin?: () => void;
  /**
   * The Library's places under Library (classic's sidebar): Waiting, Pinned and Failed uploads, then the smart folders
   * that hold something (the Inbox always: it takes dropped recordings), each with its count.
   */
  places?: { places: readonly SidebarPlace[]; current: string | null; open: (key: string) => void };
  /**
   * Every project as a tree under Projects (classic's column): pinned ones first, subfolders under their project, each a
   * target for dropped recordings. Without it the pinned projects are listed alone.
   */
  projectTree?: readonly SidebarProject[];
  /** The sub-lists, as classic's sidebar: pinned projects under Projects, Shared's places under Library. */
  pinnedProjects?: readonly { id: string; name: string }[];
  projectId?: string | null;
  onProject?: (id: string) => void;
  shared?: { tabs: readonly (readonly [string, string])[]; current: string | null; open: (tab: string) => void };
  /**
   * The sidebar as an icon rail (the person's choice, "[" or its Collapse button, or a narrow window) and its toggle;
   * left out, the window's width decides alone.
   */
  rail?: boolean;
  onRail?: () => void;
  /** The sidebar's width (200 to 320 px) and its change while its edge is dragged (`done` once let go). */
  width?: number;
  onWidth?: (width: number, done: boolean) => void;
  /** "Uploading 2 recordings…" while something is on its way; `uploadsWait` while the server holds them. */
  uploading?: string | null;
  uploadsWait?: boolean;
  /** The workspace this computer works in, the person's others, and where to invite or switch (classic's top bar). */
  workspace?: ShellWorkspace | null;
  /**
   * The Library's own part of the sidebar (PLAN_LIBRARY_FILTERS: LIBRARY, MY VIEWS, LABELS; LibraryNav.tsx), in place of
   * the Library item with its places and Shared's; Projects is then a section that folds. In the icon rail only the
   * Library's icon shows.
   */
  library?: ReactNode;
  /** At the top of the sidebar's foot: the Library's Trash and when it last synced (LibraryFoot). */
  footStart?: ReactNode;
  /** Needs you's count: a dot on the Library's icon in the rail. */
  libraryAttention?: boolean;
  /**
   * The three levels' own parts (with `library`): the other places' panels and feet, the place chosen in the rail (the
   * window brings it back as it was left; `again`: start it over), the badges and dots, call recording, the avatar's menu.
   */
  nav?: {
    panels?: Partial<Record<Place, ReactNode>>;
    feet?: Partial<Record<Place, ReactNode>>;
    onPlace?: (place: Place, again: boolean) => void;
    libraryCount?: number;
    liveDot?: boolean;
    liveDotWords?: string;
    setupAttention?: boolean;
    callRecording?: { on: boolean; toggle: () => void };
    account?: MenuItem[];
  };
  /** Today's page for the current tab. */
  children: ReactNode;
};

/** The sidebar's workspace menu: this computer's workspace and role, the others (said, not offered: this computer can't
 * switch), Invite people (owners and admins; with the plan's name when the plan leaves it out) and the browser. */
export type ShellWorkspace = {
  name: string;
  role: string | null;
  others: readonly { key: string; name: string; role: string | null }[];
  /** Invite people: shown to an owner or admin, or locked (it opens the upgrade sheet then). */
  invite?: { locked: string | null; onPick: () => void } | null;
  onManage: () => void;
};

function WorkspaceMenu({ workspace: w }: { workspace: ShellWorkspace }) {
  const items: MenuItem[] = [
    ...w.others.map((o) => ({ label: o.role ? `${o.name} · ${o.role}` : o.name, group: "Your other workspaces", disabled: true })),
    ...(w.invite ? [{ label: w.invite.locked ? `Invite people (${w.invite.locked})` : "Invite people", icon: Icons.UsersIcon, group: `This computer works in ${w.name}`, onPick: w.invite.onPick }] : []),
    { label: "Switch or manage in the browser", icon: Icons.WebIcon, group: `This computer works in ${w.name}`, onPick: w.onManage },
  ];
  return (
    <div className="el-shell__workspace">
      <Menu label={`Workspace: ${w.name}${w.role ? `, ${w.role}` : ""}`} items={items}
        button={{ variant: "quiet", icon: Icons.UsersIcon, iconEnd: Icons.ChevronDownIcon, text: w.role ? `${w.name} · ${w.role}` : w.name, title: "Workspace" }} />
    </div>
  );
}

/** The sidebar's edge: drag it (or the arrow keys, Home and End on it) for 200 to 320 px; a double press for the usual width. */
function ResizeEdge({ width, onWidth }: { width: number; onWidth: (width: number, done: boolean) => void }) {
  // Right to left the sidebar is on the right: dragging left widens it, and so does the left arrow.
  const rtl = () => document.documentElement.dir === "rtl" || getComputedStyle(document.documentElement).direction === "rtl";
  // While dragging, the width is set on the shell directly (no redraw of the app on every move); it is kept once let go.
  // The pointer is captured, so the edge hears the moves and the end even off the window; a cancelled drag puts the
  // width back.
  const start = (e: ReactPointerEvent<HTMLDivElement>) => {
    if (e.button !== 0) return;
    e.preventDefault();
    const edge = e.currentTarget;
    const shell = edge.closest<HTMLElement>(".el-shell");
    const from = e.clientX;
    const sign = rtl() ? -1 : 1;
    let last = width;
    const show = (w: number) => {
      shell?.style.setProperty("--el-sidebar-width", `${w}px`);
      edge.setAttribute("aria-valuenow", String(w));
    };
    try {
      edge.setPointerCapture?.(e.pointerId);
    } catch {
      // A pointer that is gone already can't be captured.
    }
    const move = (m: PointerEvent) => {
      last = clampSidebar(width + sign * (m.clientX - from));
      show(last);
    };
    const end = (keep: boolean) => {
      edge.removeEventListener("pointermove", move);
      edge.removeEventListener("pointerup", up);
      edge.removeEventListener("pointercancel", cancel);
      document.body.classList.remove("resizing-sidebar");
      if (keep) onWidth(last, true);
      else show(width);
    };
    const up = () => end(true);
    const cancel = () => end(false);
    document.body.classList.add("resizing-sidebar");
    edge.addEventListener("pointermove", move);
    edge.addEventListener("pointerup", up);
    edge.addEventListener("pointercancel", cancel);
  };
  const key = (e: ReactKeyboardEvent) => {
    const step = e.shiftKey ? 40 : 16;
    const wider = rtl() ? "ArrowLeft" : "ArrowRight";
    const narrower = rtl() ? "ArrowRight" : "ArrowLeft";
    if (e.key === narrower) onWidth(clampSidebar(width - step), true);
    else if (e.key === wider) onWidth(clampSidebar(width + step), true);
    else if (e.key === "Home") onWidth(SIDEBAR_MIN, true);
    else if (e.key === "End") onWidth(SIDEBAR_MAX, true);
    else return;
    e.preventDefault();
  };
  return (
    <div className="el-shell__edge" role="separator" aria-orientation="vertical" aria-label="Resize the sidebar" tabIndex={0}
      aria-valuemin={SIDEBAR_MIN} aria-valuemax={SIDEBAR_MAX} aria-valuenow={width} title="Drag to resize. Double-click for the usual width."
      onPointerDown={start} onDoubleClick={() => onWidth(SIDEBAR_DEFAULT, true)} onKeyDown={key} />
  );
}

/** A project in the sidebar's tree: its folder (`parent_id`, null at the top) and whether it is pinned. */
export type SidebarProject = { id: string; name: string; parent_id?: string | null; pinned?: boolean };

/** Which projects' subfolders are folded in the sidebar, kept on this computer (classic's: a group stays as left). */
export const FOLDS_KEY = "echoledger-v2-sidebar-folds";
function loadFolds(): Set<string> {
  try {
    const saved = JSON.parse(localStorage.getItem(FOLDS_KEY) ?? "[]");
    return new Set(Array.isArray(saved) ? saved.filter((v): v is string => typeof v === "string") : []);
  } catch {
    return new Set();
  }
}
function saveFolds(folds: ReadonlySet<string>) {
  try {
    localStorage.setItem(FOLDS_KEY, JSON.stringify([...folds]));
  } catch {
    // Kept for this session only.
  }
}

/** The projects as a tree: pinned first, then by name, each project's subfolders under it (foldable, remembered). */
function ProjectTreeNav({ projects, current, onOpen }: { projects: readonly SidebarProject[]; current: string | null; onOpen: (id: string) => void }) {
  const [folds, setFolds] = useState<Set<string>>(loadFolds);
  const ids = new Set(projects.map((p) => p.id));
  const children = (parent: string | null) => projects
    .filter((p) => (p.parent_id && ids.has(p.parent_id) ? p.parent_id : null) === parent)
    .sort((a, b) => Number(!!b.pinned) - Number(!!a.pinned) || a.name.localeCompare(b.name));
  const fold = (id: string) => setFolds((now) => {
    const next = new Set(now);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    saveFolds(next);
    return next;
  });
  const branch = (parent: string | null, depth: number): ReactNode => {
    const rows = children(parent);
    if (!rows.length) return null;
    return (
      <ul className="el-nav-sub el-nav-tree" aria-label={parent === null ? "Projects" : undefined} role={parent === null ? undefined : "group"}>
        {rows.map((p) => {
          const kids = children(p.id).length > 0;
          const open = kids && !folds.has(p.id);
          return (
            <li key={p.id} className="el-nav-tree__item" style={{ ["--el-tree-depth" as string]: String(depth) }}>
              <div className="el-nav-tree__row" {...{ [DROP_ATTRIBUTE]: p.id }}>
                {kids ? (
                  <button type="button" className="el-nav-tree__fold" aria-expanded={open} aria-label={`${open ? "Fold" : "Show"} ${p.name}'s folders`} onClick={() => fold(p.id)}>
                    <Icon icon={open ? Icons.ChevronDownIcon : Icons.ChevronEndIcon} directional />
                  </button>
                ) : <span className="el-nav-tree__fold" aria-hidden="true" />}
                <button type="button" className="el-nav-item el-nav-item--sub el-nav-tree__name" dir="auto" aria-current={current === p.id ? "page" : undefined}
                  onClick={() => onOpen(p.id)}>{p.name}</button>
              </div>
              {open && branch(p.id, depth + 1)}
            </li>
          );
        })}
      </ul>
    );
  };
  return <>{branch(null, 0)}</>;
}

/** A place of the Library in the sidebar: its key, words and count; `drop` takes dropped recordings (the Inbox). */
export type SidebarPlace = { key: string; label: string; count: number; drop?: boolean };

/**
 * The shell: with the Library's sections (`library`), the three levels of PLAN_NAV_3_LEVELS (rail · panel · page,
 * NavShell); without, the sidebar of PLAN_V2_PARITY 2.
 */
export function Shell(props: ShellProps) {
  return props.library ? <ShellNav {...props} /> : <ShellSidebar {...props} />;
}

/** The three levels: Live and Ask are the shell's own pages, as in the sidebar; the rest are MainApp's tabs. */
function ShellNav({ tab, onGo, status, me, live, ask, notices, banners, activity, recording, onRecord, onSetup, onVoiceNote, onUpload, onNewProject, onSearch,
  searchKeys, onHelp, projectTree, projectId = null, onProject, uploading, library, footStart, nav, children }: ShellProps) {
  const [own, setOwn] = useState<"live" | "ask" | null>(null);
  const [lastTab, setLastTab] = useState(tab);
  if (tab !== lastTab) {
    setLastTab(tab);
    setOwn(null);
  }
  const place: Place | null = own ?? (tab === "record" ? null : moduleOf(tab) as Place);
  const onPlace = (p: Place, again: boolean) => {
    if (p === "live" || p === "ask") {
      setOwn(p);
      nav?.onPlace?.(p, again);
      return;
    }
    setOwn(null);
    if (nav?.onPlace) nav.onPlace(p, again);
    else onGo(MODULE_TAB[p]);
  };
  // While recordings are dragged (lib/dragMove.ts), the Library's panel lists where they can go: No project (out of
  // their project) and every project. Hidden otherwise; Move to project… is the way for the keyboard.
  const drops = (
    <div className="el-panel-drops" aria-hidden="true">
      <p className="el-caption el-secondary el-panel-drops__title">Drop on a project</p>
      <NoProjectDrop />
      {onProject && projectTree && projectTree.length > 0 && <ProjectTreeNav projects={projectTree} current={null} onOpen={() => undefined} />}
    </div>
  );
  const projectsPanel = onProject && (
    <ProjectsPanel projects={projectTree ?? []} current={tab === "projects" ? projectId : null} allCurrent={tab === "projects" && !projectId}
      onAll={() => { setOwn(null); onGo("projects"); }} onOpen={(id) => { setOwn(null); onProject(id); }} onNew={onNewProject}
      tree={(list, current, open) => <ProjectTreeNav projects={list} current={current} onOpen={open} />} />
  );
  return (
    <NavShell place={place} onPlace={onPlace} onHome={() => { setOwn(null); onGo("record"); }} status={status}
      panels={{ library: library && <>{library}{drops}</>, projects: projectsPanel || undefined, ...nav?.panels }} feet={{ library: footStart, ...nav?.feet }}
      libraryCount={nav?.libraryCount} activityCount={activity} liveDot={nav?.liveDot ?? !!recording} liveDotWords={nav?.liveDotWords ?? (recording ? "recording" : undefined)}
      setupAttention={nav?.setupAttention ?? status.kind === "setup"} onSetupHealth={onSetup ?? (() => onGo("settings"))} onHelp={onHelp}
      recording={recording} onRecord={onRecord} onUpload={onUpload} onVoiceNote={onVoiceNote} callRecording={nav?.callRecording}
      onSearch={onSearch} searchKeys={searchKeys} me={me} account={nav?.account} uploading={uploading} notices={notices} banners={banners}>
      {own === "live" && live ? <section className="content page-live">{live}</section>
        : own === "ask" && ask ? <section className="content page-ask">{ask}</section>
          : own ? <EmptyState icon={own === "live" ? Icons.RecordIcon : Icons.AskIcon} title={own === "live" ? "Live is on its way" : "Ask is on its way"}
            body="Its place is here soon." /> : children}
    </NavShell>
  );
}

function ShellSidebar({ tab, onGo, status, me, live, ask, notices, banners, activity, recording, onRecord, onSetup, onVoiceNote, onUpload, onNewProject, onRequestLink, onSearch, searchKeys, onHelp, onAdmin, places, projectTree, pinnedProjects = [], projectId = null, onProject, shared, rail, onRail, width, onWidth, uploading, uploadsWait = false, workspace, library, footStart, libraryAttention, children }: ShellProps) {
  // One press records or stops: while that is under way (the core answering, then the status saying so) more presses
  // and held-down keys do nothing, so a double click never starts and stops at once.
  const [pressing, setPressing] = useState<{ from: boolean } | null>(null);
  if (pressing && !!recording !== pressing.from) setPressing(null);
  useEffect(() => {
    if (!pressing) return;
    const fallback = window.setTimeout(() => setPressing(null), PRESS_HOLD_MS);
    return () => window.clearTimeout(fallback);
  }, [pressing]);
  const pressRecord = () => {
    if (!onRecord) return go("record");
    if (pressing) return;
    setPressing({ from: !!recording });
    void Promise.resolve(onRecord()).catch(() => setPressing(null));
  };
  const stops = !!onRecord && !!recording;
  // Cmd/Ctrl+F opens search, as Cmd/Ctrl+K does (MainApp). An open recording or shared transcript keeps it for Find in
  // this transcript: they listen on the document, so they have answered (and prevented it) before this, on the window.
  const searchRef = useRef(onSearch);
  searchRef.current = onSearch;
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (!(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey || e.key.toLowerCase() !== "f") return;
      if (e.defaultPrevented || !searchRef.current || typing(e.target)) return;
      e.preventDefault();
      // Nothing opens behind a question, a dialog or a sheet.
      if (isConfirmOpen() || document.querySelector('[role="alertdialog"], [aria-modal="true"]')) return;
      searchRef.current();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, []);
  const hint = searchHint(searchKeys);
  const search = onSearch && <Button size="sm" variant="quiet" icon={Icons.SearchIcon} aria-label="Search" title={hint.title} aria-keyshortcuts={hint.keys} onClick={onSearch} />;
  // Live and Ask live in the shell only until their modules are built.
  const [own, setOwn] = useState<"live" | "ask" | null>(null);
  // With the Library's sections, a short window keeps more of the middle: the foot's rows pack tighter (Activity and
  // Settings beside the tools as icons; the workspace and the avatar on one row), and the middle's edges fade where
  // there is more to scroll to.
  const [short, setShort] = useState(() => typeof window !== "undefined" && window.innerHeight < SHORT_BELOW);
  useEffect(() => {
    const follow = () => setShort(window.innerHeight < SHORT_BELOW);
    window.addEventListener("resize", follow);
    return () => window.removeEventListener("resize", follow);
  }, []);
  const middle = useRef<HTMLDivElement>(null);
  const [edges, setEdges] = useState({ above: false, below: false });
  useEffect(() => {
    const el = middle.current;
    if (!el || !library) return;
    const measure = () => {
      const above = el.scrollTop > 1;
      const below = el.scrollTop + el.clientHeight < el.scrollHeight - 1;
      setEdges((now) => (now.above === above && now.below === below ? now : { above, below }));
    };
    measure();
    el.addEventListener("scroll", measure, { passive: true });
    window.addEventListener("resize", measure);
    const watch = typeof ResizeObserver === "function" ? new ResizeObserver(measure) : null;
    watch?.observe(el);
    for (const child of el.children) watch?.observe(child);
    const changes = typeof MutationObserver === "function" ? new MutationObserver(measure) : null;
    changes?.observe(el, { childList: true, subtree: true });
    return () => {
      el.removeEventListener("scroll", measure);
      window.removeEventListener("resize", measure);
      watch?.disconnect();
      changes?.disconnect();
    };
  }, [library]);
  // Anything that opens a page of the app (a notice, the palette, a link) leaves Live or Ask. Worked out while
  // rendering, not in an effect: a page that suspends and shows again re-runs effects, which would close Live.
  const [lastTab, setLastTab] = useState(tab);
  if (tab !== lastTab) {
    setLastTab(tab);
    setOwn(null);
  }
  const current: Module = own ?? moduleOf(tab);
  const go = (m: Module) => {
    if (m === "live" || m === "ask") {
      setOwn(m);
      return;
    }
    setOwn(null);
    onGo(MODULE_TAB[m]);
  };
  const item = (m: Module, label: string, icon: Parameters<typeof NavItem>[0]["icon"], count?: number) => (
    <NavItem key={m} icon={icon} label={label} current={current === m} onClick={() => go(m)} count={count} />
  );
  // PROJECTS folds (kept on this computer), with the Library's sections.
  const [projectsFolded, setProjectsFolded] = useState(() => {
    try {
      return localStorage.getItem("echoledger-sidebar-projects-folded") === "1";
    } catch {
      return false;
    }
  });
  const foldProjects = () => setProjectsFolded((f) => {
    try {
      localStorage.setItem("echoledger-sidebar-projects-folded", f ? "0" : "1");
    } catch {
      // Folded for this session only.
    }
    return !f;
  });
  return (
    <div className={`el-root el-shell${library ? " el-shell--library" : ""}${library && short && !rail ? " el-shell--short" : ""}`} data-shell="v2" style={width !== undefined && !rail ? { ["--el-sidebar-width" as string]: `${width}px` } : undefined}>
      {/* The name, and Search at its end (the start in RTL); in the rail Search stays at the top, the name goes. */}
      <Sidebar label="Main" brand={<>EchoLedger</>} brandEnd={search} rail={rail}
        footer={
          <div className="el-shell__foot">
            {footStart && <div className="el-shell__foot-start">{footStart}</div>}
            {/* Activity, Settings and the tools: one row of icons in a short window (el-shell--short). */}
            <div className="el-shell__actions">
            {item("activity", "Activity", Icons.BellIcon, activity ? activity : undefined)}
            {item("settings", "Settings", Icons.SettingsIcon)}
            {library ? (
              // With the Library's sections the foot stays on screen: its least-used buttons share one row, as icons.
              <div className="el-shell__tools">
                {onHelp && <Button size="sm" variant="quiet" icon={Icons.HelpIcon} aria-label="Help & shortcuts" title="Help & shortcuts" onClick={onHelp} />}
                {onAdmin && <Button size="sm" variant="quiet" icon={Icons.LinkIcon} aria-label="Admin panel" title="Admin panel" onClick={onAdmin} />}
                {onRail && <Button size="sm" variant="quiet" icon={Icons.SidebarIcon} aria-label={rail ? "Expand sidebar" : "Collapse sidebar"} aria-expanded={!rail}
                  aria-keyshortcuts="[" title={rail ? "Expand the sidebar ([)" : "Collapse the sidebar ([)"} onClick={onRail} />}
              </div>
            ) : (
              <>
                {onHelp && <NavItem icon={Icons.HelpIcon} label="Help & shortcuts" onClick={onHelp} />}
                {onAdmin && <NavItem icon={Icons.LinkIcon} label="Admin panel" onClick={onAdmin} />}
                {onRail && (
                  <button type="button" className="el-nav-item" aria-expanded={!rail} aria-keyshortcuts="[" title={rail ? "Expand the sidebar ([)" : "Collapse the sidebar ([)"} onClick={onRail}>
                    <Icon icon={Icons.SidebarIcon} size="md" />
                    <span className="el-nav-item__label">{rail ? "Expand sidebar" : "Collapse sidebar"}</span>
                  </button>
                )}
              </>
            )}
            </div>
            {/* The workspace and who is signed in: one row in a short window. */}
            <div className="el-shell__who">
            {workspace && <WorkspaceMenu workspace={workspace} />}
            {me && (
              <button type="button" className="el-shell__me" onClick={() => go("settings")} title={me.email} aria-describedby="el-shell-me-hint">
                <Avatar initials={initials(me.name, me.email)} size="sm" />
                <span className="el-shell__me-text">
                  <span className="el-shell__me-name">{me.name}</span>
                  {me.plan && <Badge>{me.plan}</Badge>}
                </span>
                <span className="el-visually-hidden" id="el-shell-me-hint">Opens Settings</span>
              </button>
            )}
            </div>
            {uploading && (
              <p className={`el-shell__uploads el-caption el-secondary${uploadsWait ? " el-shell__uploads--wait" : ""}`} aria-live="polite" data-testid="sidebar-uploads">
                {!uploadsWait && <span className="el-spinner" aria-hidden="true" />}<span dir="auto">{uploading}</span>
              </p>
            )}
            <div className="el-shell__status el-caption">
              {status.kind === "recording" ? (
                // The recording chip: what is recorded, paused or not, and for how long; a press opens the Record page.
                <button type="button" className="el-shell__status-fix" onClick={() => go("record")} aria-describedby="el-shell-chip-hint">
                  {/* Only Recording / Paused and what is said aloud when it changes; the time ticks outside the live region. */}
                  <Status kind={status.kind} live>{status.text}</Status>
                  {status.time && <span className="el-shell__time el-num" dir="ltr">{` · ${status.time}`}</span>}
                  <span className="el-visually-hidden" id="el-shell-chip-hint">Opens the Record page</span>
                </button>
              ) : status.kind === "setup" && onSetup ? (
                <button type="button" className="el-shell__status-fix" onClick={onSetup} aria-describedby="el-shell-setup-hint">
                  <Status kind={status.kind} live>{status.text}</Status>
                  <span className="el-visually-hidden" id="el-shell-setup-hint">Opens Setup & health</span>
                </button>
              ) : <Status kind={status.kind} live>{status.text}</Status>}
            </div>
          </div>
        }>
        {/* ● Record: one press records (or stops); its menu has a voice note and the Record page (calls ready, Live
            Assist), the sheet the phone opens on a long press. */}
        <div className="el-shell__record" role="group" aria-label="Record" aria-current={current === "record" ? "page" : undefined}>
          <Button variant={stops ? "danger" : "primary"} icon={stops ? Icons.StopIcon : Icons.RecordIcon} aria-disabled={pressing ? true : undefined}
            onKeyDown={(e) => { if (e.repeat) e.preventDefault(); }} onClick={pressRecord}>{stops ? "Stop recording" : "Record"}</Button>
          <Menu label="More ways to record" button={{ variant: stops ? "danger" : "primary", icon: Icons.ChevronDownIcon }} items={[
            ...(onRecord ? [{ label: stops ? "Stop recording" : "Record now", icon: stops ? Icons.StopIcon : Icons.RecordIcon, onPick: pressRecord }] : []),
            // Import: files can also be dropped anywhere on the window.
            ...(onUpload ? [{ label: "Import audio files…", icon: Icons.ShareIcon, onPick: onUpload }] : []),
            ...(onVoiceNote ? [{ label: "Voice note", icon: Icons.MicIcon, onPick: onVoiceNote }] : []),
            ...(onNewProject ? [{ label: "New project…", icon: Icons.FolderIcon, group: "Make", onPick: onNewProject }] : []),
            ...(onRequestLink ? [{ label: "Request link…", icon: Icons.LinkIcon, group: "Make", onPick: onRequestLink }] : []),
            { label: "Record page", icon: Icons.HomeIcon, onPick: () => go("record") },
          ]} />
        </div>
        {/* With the Library's sections (PLAN_LIBRARY_FILTERS), Live and Ask stay at the top with Record, the foot stays put, and
            only the middle scrolls: LIBRARY, MY VIEWS, LABELS and PROJECTS. */}
        {library && item("live", "Live", Icons.WaveIcon)}
        {library && item("ask", "Ask", Icons.AskIcon)}
        <div ref={middle} className={library ? "el-sidebar__middle" : "el-sidebar__flow"}
          data-more-above={library && edges.above ? "" : undefined} data-more-below={library && edges.below ? "" : undefined}>
        {library ? (
          <>
            <div className="el-shell__rail-only">
              <NavItem icon={Icons.LibraryIcon} label="Library" current={current === "library"} onClick={() => go("library")} count={libraryAttention ? 1 : undefined} />
            </div>
            <div className="el-shell__full-only" onClickCapture={() => setOwn(null)}>{library}</div>
          </>
        ) : item("library", "Library", Icons.LibraryIcon)}
        {!library && places && places.places.length > 0 && (
          <ul className="el-nav-sub" aria-label="Library places">
            {places.places.map((p) => (
              <li key={p.key} {...(p.drop ? { [DROP_ATTRIBUTE]: "" } : {})}>
                <button type="button" className="el-nav-item el-nav-item--sub el-nav-item--place" aria-label={`${p.label}, ${p.count}`}
                  aria-current={current === "library" && places.current === p.key ? "page" : undefined}
                  onClick={() => { setOwn(null); places.open(p.key); }}>
                  <span className="el-nav-item__label" dir="auto">{p.label}</span>
                  <span className="el-nav-item__count el-caption el-num">{p.count}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
        {!library && shared && shared.tabs.length > 0 && (
          <ul className="el-nav-sub" aria-label="Shared">
            {shared.tabs.map(([tab, label]) => (
              <li key={tab}><button type="button" className="el-nav-item el-nav-item--sub" aria-current={shared.current === tab ? "page" : undefined}
                onClick={() => { setOwn(null); shared.open(tab); }}>{label === "With me" ? "Shared with me" : label}</button></li>
            ))}
          </ul>
        )}
        {library ? (
          <>
            <div className="el-shell__rail-only">{item("projects", "Projects", Icons.FolderIcon)}</div>
            <div className="el-nav-section__head el-shell__full-only">
              <button type="button" className="el-nav-section__fold" aria-expanded={!projectsFolded} aria-label={projectsFolded ? "Show projects" : "Fold projects"}
                onClick={foldProjects}><Icon icon={projectsFolded ? Icons.ChevronEndIcon : Icons.ChevronDownIcon} directional /></button>
              <button type="button" className="el-nav-section__link el-caption" aria-current={current === "projects" && !projectId ? "page" : undefined}
                onClick={() => go("projects")}>Projects</button>
              {onNewProject && <Button size="sm" variant="quiet" icon={Icons.PlusIcon} aria-label="New project" title="New project" onClick={onNewProject} />}
            </div>
          </>
        ) : item("projects", "Projects", Icons.FolderIcon)}
        {/* While recordings are dragged (lib/dragMove.ts): the Inbox takes them out of their project. Pinned projects
            below take them too. Hidden otherwise; Move to project… is the way for the keyboard. */}
        {!places?.places.some((p) => p.drop) && <div className="el-nav-drop" aria-hidden="true" {...{ [DROP_ATTRIBUTE]: "" }}>
          <Icon icon={Icons.InboxIcon} /><span>No project</span>
        </div>}
        {onProject && projectTree && projectTree.length > 0 && !(library && projectsFolded) && (
          <ProjectTreeNav projects={projectTree} current={current === "projects" ? projectId : null} onOpen={(id) => { setOwn(null); onProject(id); }} />
        )}
        {onProject && !projectTree && pinnedProjects.length > 0 && (
          <ul className="el-nav-sub" aria-label="Pinned projects">
            {pinnedProjects.map((p) => (
              <li key={p.id} {...{ [DROP_ATTRIBUTE]: p.id }}><button type="button" className="el-nav-item el-nav-item--sub" dir="auto" aria-current={current === "projects" && projectId === p.id ? "page" : undefined}
                onClick={() => { setOwn(null); onProject(p.id); }}>{p.name}</button></li>
            ))}
          </ul>
        )}
        </div>
        {!library && item("live", "Live", Icons.WaveIcon)}
        {!library && item("ask", "Ask", Icons.AskIcon)}
      </Sidebar>
      {width !== undefined && onWidth && !rail && <ResizeEdge width={width} onWidth={onWidth} />}
      <main className="el-shell__main" aria-label={own === "live" ? "Live" : own === "ask" ? "Ask" : undefined}>
        {notices}
        {banners}
        {own === "live" && live ? (
          <section className="content page-live">{live}</section>
        ) : own === "live" ? (
          <EmptyState icon={Icons.RecordIcon} title="Live is on its way"
            body="Live Assist, Live Translate and Dictation will have their place here. Until then, start them from the Record page or the tray."
            action={<Button onClick={() => go("record")}>Open Record page</Button>} />
        ) : own === "ask" && ask ? (
          <section className="content page-ask">{ask}</section>
        ) : own === "ask" ? (
          <EmptyState icon={Icons.AskIcon} title="Ask is on its way"
            body="Questions across all your recordings will have their place here. Until then, ask inside a project, on its Notes tab."
            action={<Button onClick={() => { setOwn(null); onGo("projects"); }}>Open Projects</Button>} />
        ) : children}
      </main>
    </div>
  );
}
