// The v2 app shell (ui_v2.shell), laid out as PLAN_V2_PARITY 2 decides for the desktop: "● Record" at the top, then
// Library, Projects, Live and Ask; at the foot Activity (with how many need you), Settings, the account and the status
// line. Record opens the page where recording starts (today's Home and its Live Assist card). Modules not rebuilt yet
// show today's page inside the new frame; Live and Ask, without their page yet, say where to find them meanwhile.
import { useEffect, useState, type ReactNode } from "react";

/** How long Record ignores presses when the status never changes (the core said no, or was slow to say). */
const PRESS_HOLD_MS = 3000;
import { Avatar, Badge, Button, EmptyState, Icon, Icons, NavItem, Sidebar, Status } from "../components";
import { Menu } from "../components/Menu";
import { initials } from "../settings/initials";
import type { MainTab } from "../../lib/mainView";

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
  onSearch?: () => void;
  searchKeys?: string;
  onHelp?: () => void;
  /** Today's page for the current tab. */
  children: ReactNode;
};

export function Shell({ tab, onGo, status, me, live, ask, notices, banners, activity, recording, onRecord, onSetup, onVoiceNote, onUpload, onSearch, searchKeys, onHelp, children }: ShellProps) {
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
      <Sidebar label="Main" brand={<span>EchoLedger</span>}
        footer={
          <div className="el-shell__foot">
            {item("activity", "Activity", Icons.BellIcon, activity ? activity : undefined)}
            {item("settings", "Settings", Icons.SettingsIcon)}
            {onHelp && <NavItem icon={Icons.HelpIcon} label="Help & shortcuts" onClick={onHelp} />}
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
            { label: "Record page", icon: Icons.HomeIcon, onPick: () => go("record") },
          ]} />
        </div>
        {onSearch && (
          <button type="button" className="el-nav-item el-shell__search" onClick={onSearch} aria-keyshortcuts={searchKeys === "⌘K" ? "Meta+K" : "Control+K"}>
            <Icon icon={Icons.SearchIcon} size="md" />
            <span className="el-nav-item__label">Search</span>
            {searchKeys && <kbd className="el-caption el-shell__keys" aria-hidden="true">{searchKeys}</kbd>}
          </button>
        )}
        {item("library", "Library", Icons.LibraryIcon)}
        {item("projects", "Projects", Icons.FolderIcon)}
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
