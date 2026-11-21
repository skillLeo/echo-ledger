// The v2 app shell (ui_v2.shell), laid out as PLAN_V2_PARITY 2 decides for the desktop: "● Record" at the top, then
// Library, Projects, Live and Ask; at the foot Activity (with how many need you), Settings, the account and the status
// line. Record opens the page where recording starts (today's Home and its Live Assist card). Modules not rebuilt yet
// show today's page inside the new frame; Live and Ask, without their page yet, say where to find them meanwhile.
import { useEffect, useRef, useState, type ReactNode } from "react";
import { Avatar, Badge, Button, EmptyState, Icon, Icons, NavItem, Sidebar, Status } from "../components";
import { Menu } from "../components/Menu";
import { initials } from "../settings/initials";
import type { MainTab } from "../../lib/mainView";
import { isConfirmOpen } from "../../lib/confirm";
import { DROP_ATTRIBUTE } from "../../lib/dragMove";

/** How long Record ignores presses when the status never changes (the core said no, or was slow to say). */
const PRESS_HOLD_MS = 3000;

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
  /** The sub-lists, as classic's sidebar: pinned projects under Projects, Shared's places under Library. */
  pinnedProjects?: readonly { id: string; name: string }[];
  projectId?: string | null;
  onProject?: (id: string) => void;
  shared?: { tabs: readonly (readonly [string, string])[]; current: string | null; open: (tab: string) => void };
  /** Today's page for the current tab. */
  children: ReactNode;
};

export function Shell({ tab, onGo, status, me, live, ask, notices, banners, activity, recording, onRecord, onSetup, onVoiceNote, onUpload, onNewProject, onRequestLink, onSearch, searchKeys, onHelp, onAdmin, pinnedProjects = [], projectId = null, onProject, shared, children }: ShellProps) {
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
  return (
    <div className="el-root el-shell" data-shell="v2">
      {/* The name, and Search at its end (the start in RTL); in the rail Search stays at the top, the name goes. */}
      <Sidebar label="Main" brand={<>EchoLedger</>} brandEnd={search}
        footer={
          <div className="el-shell__foot">
            {item("activity", "Activity", Icons.BellIcon, activity ? activity : undefined)}
            {item("settings", "Settings", Icons.SettingsIcon)}
            {onHelp && <NavItem icon={Icons.HelpIcon} label="Help & shortcuts" onClick={onHelp} />}
            {onAdmin && <NavItem icon={Icons.LinkIcon} label="Admin panel" onClick={onAdmin} />}
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
            <div className="el-shell__status el-caption">
              {status.kind === "setup" && onSetup ? (
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
            ...(onVoiceNote ? [{ label: "Voice note", icon: Icons.MicIcon, onPick: onVoiceNote }] : []),
            ...(onUpload ? [{ label: "Upload files…", icon: Icons.ShareIcon, onPick: onUpload }] : []),
            ...(onNewProject ? [{ label: "New project…", icon: Icons.FolderIcon, group: "Make", onPick: onNewProject }] : []),
            ...(onRequestLink ? [{ label: "Request link…", icon: Icons.LinkIcon, group: "Make", onPick: onRequestLink }] : []),
            { label: "Record page", icon: Icons.HomeIcon, onPick: () => go("record") },
          ]} />
        </div>
        {item("library", "Library", Icons.LibraryIcon)}
        {shared && shared.tabs.length > 0 && (
          <ul className="el-nav-sub" aria-label="Shared">
            {shared.tabs.map(([tab, label]) => (
              <li key={tab}><button type="button" className="el-nav-item el-nav-item--sub" aria-current={shared.current === tab ? "page" : undefined}
                onClick={() => { setOwn(null); shared.open(tab); }}>{label === "With me" ? "Shared with me" : label}</button></li>
            ))}
          </ul>
        )}
        {item("projects", "Projects", Icons.FolderIcon)}
        {/* While recordings are dragged (lib/dragMove.ts): the Inbox takes them out of their project. Pinned projects
            below take them too. Hidden otherwise; Move to project… is the way for the keyboard. */}
        <div className="el-nav-drop" aria-hidden="true" {...{ [DROP_ATTRIBUTE]: "" }}>
          <Icon icon={Icons.InboxIcon} /><span>Inbox (no project)</span>
        </div>
        {onProject && pinnedProjects.length > 0 && (
          <ul className="el-nav-sub" aria-label="Pinned projects">
            {pinnedProjects.map((p) => (
              <li key={p.id} {...{ [DROP_ATTRIBUTE]: p.id }}><button type="button" className="el-nav-item el-nav-item--sub" dir="auto" aria-current={current === "projects" && projectId === p.id ? "page" : undefined}
                onClick={() => { setOwn(null); onProject(p.id); }}>{p.name}</button></li>
            ))}
          </ul>
        )}
        {item("live", "Live", Icons.WaveIcon)}
        {item("ask", "Ask", Icons.AskIcon)}
      </Sidebar>
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
