// The v2 app shell (ui_v2.shell): five modules in the sidebar, the status line at its foot, and the page beside it.
// Modules not rebuilt yet show today's page inside the new frame; Live and Ask, which have no page of their own on
// the desktop yet, say where to find them meanwhile.
import { useState, type ReactNode } from "react";
import { Avatar, Badge, Button, EmptyState, Icons, NavItem, Sidebar, Status } from "../components";
import { initials } from "../settings/initials";
import type { MainTab } from "../../lib/mainView";

export type Module = "home" | "library" | "live" | "ask" | "settings";

/** The module a page of today's app belongs to. */
export function moduleOf(tab: MainTab): Module {
  switch (tab) {
    case "record":
    case "activity": return "home";
    case "library":
    case "projects":
    case "shared": return "library";
    case "settings": return "settings";
  }
}

const MODULE_TAB: Record<Exclude<Module, "live" | "ask">, MainTab> = { home: "record", library: "library", settings: "settings" };

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
  /** The new shell's own notices above every page (the After a call notice). */
  notices?: ReactNode;
  /** The banners above every page (one at a time). */
  banners?: ReactNode;
  /** Today's page for the current tab. */
  children: ReactNode;
};

export function Shell({ tab, onGo, status, me, live, notices, banners, children }: ShellProps) {
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
  const item = (m: Module, label: string, icon: Parameters<typeof NavItem>[0]["icon"]) => (
    <NavItem key={m} icon={icon} label={label} current={current === m} onClick={() => go(m)} />
  );
  return (
    <div className="el-root el-shell" data-shell="v2">
      <Sidebar label="Main" brand={<span>EchoLedger</span>}
        footer={
          <div className="el-shell__foot">
            {me && (
              <button type="button" className="el-shell__me" onClick={() => go("settings")} title={me.email}
                aria-label={`${me.name}${me.plan ? `, ${me.plan} plan` : ""}. Opens Settings.`}>
                <Avatar initials={initials(me.name, me.email)} size="sm" />
                <span className="el-shell__me-text">
                  <span className="el-shell__me-name">{me.name}</span>
                  {me.plan && <Badge>{me.plan}</Badge>}
                </span>
              </button>
            )}
            <div className="el-shell__status el-caption"><Status kind={status.kind} live>{status.text}</Status></div>
          </div>
        }>
        {item("home", "Home", Icons.HomeIcon)}
        {item("library", "Library", Icons.LibraryIcon)}
        {item("live", "Live", Icons.RecordIcon)}
        {item("ask", "Ask", Icons.AskIcon)}
        {item("settings", "Settings", Icons.SettingsIcon)}
      </Sidebar>
      <main className="el-shell__main" aria-label={own === "live" ? "Live" : own === "ask" ? "Ask" : undefined}>
        {notices}
        {banners}
        {own === "live" && live ? (
          <section className="content page-live">{live}</section>
        ) : own === "live" ? (
          <EmptyState icon={Icons.RecordIcon} title="Live is on its way"
            body="Live Assist, Live Translate and Dictation will have their place here. Until then, start them from Home or the tray."
            action={<Button onClick={() => go("home")}>Open Home</Button>} />
        ) : own === "ask" ? (
          <EmptyState icon={Icons.AskIcon} title="Ask is on its way"
            body="Questions across all your recordings will have their place here. Until then, ask inside a project, on its Notes tab."
            action={<Button onClick={() => { setOwn(null); onGo("projects"); }}>Open Projects</Button>} />
        ) : children}
      </main>
    </div>
  );
}
