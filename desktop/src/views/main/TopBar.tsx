// The top bar (v1.6, P4.3): the workspace, search and commands (Ctrl/⌘+K), whether calls will be recorded, "+" to make
// something, notifications, help and the avatar menu (v1.7: who you are with the plan's chip, Upgrade or Plan & usage,
// Settings, the admin panel for the super admin, Appearance, Sign out).
import type { Account, AppStatus } from "../../lib/ipc";
import type { ThemeChoice } from "../../lib/theme";
import { initials, paletteKeys } from "../../lib/shell";
import { badgeLabel, badgeSpoken } from "../../lib/badge";
import { ROLE_LABEL } from "../../lib/plan";
import { BellIcon, ExternalIcon, FolderIcon, HelpIcon, ImportIcon, LucideCreditCardIcon, LucideSettingsIcon, LucideUserIcon, LucideUserPlusIcon, MicIcon, PlusIcon, RecordIcon, RequestFilesIcon, SearchIcon, SignOutIcon } from "../../components/Icons";
import { ChevronDownIcon } from "../../components/ControlIcons";
import { Menu } from "../../components/Menu";
import { ReadyChip } from "./ReadyCheck";
import { BadgeMark, canUpgrade, nextPlan } from "./Upgrade";
import { Avatar, PlanChip } from "./ProfileCard";
import { featureWords, isLocked, lowestPlan, openRequest, openUpgradeSheet, planName, useEntitlements } from "../../lib/entitlements";
import { formatDuration, sourceAppLabel } from "../../lib/format";

/** The workspaces the switcher lists: the server's list when it sends one, else the one this computer works in. */
export function workspacesOf(account: Account | null | undefined, status: Pick<AppStatus, "plan">): { key: string; name: string; role: string | null; current: boolean }[] {
  const current = account?.workspace?.name || status.plan.workspace || "Personal";
  const list = Array.isArray(account?.workspaces) ? account!.workspaces! : [];
  if (list.length === 0) return [{ key: "current", name: current, role: account?.workspace?.role ?? status.plan.role ?? null, current: true }];
  return list.map((w, i) => ({
    key: String(w.id ?? i),
    name: w.name || "Workspace",
    role: w.role ?? null,
    current: w.current === true || (w.current == null && (String(w.id) === String(account?.workspace?.id) || w.name === current)),
  }));
}

const roleText = (role: string | null | undefined) => (role && role in ROLE_LABEL ? ROLE_LABEL[role as keyof typeof ROLE_LABEL] : null);

const THEMES: readonly (readonly [ThemeChoice, string])[] = [["system", "System"], ["light", "Light"], ["dark", "Dark"]];

export function TopBar({ status, account, needsYou, theme, onTheme, onPalette, onActivity, onAccount, onPlan, onAdmin, onWorkspaces, onReady, onRecord, onVoiceNote,
  onUpload, onNewProject, onRequestLink, onHelp, onSignOut, canRecord }: {
  status: AppStatus;
  account: Account | null | undefined;
  needsYou: number;
  theme: ThemeChoice;
  onTheme: (theme: ThemeChoice) => void;
  onPalette: () => void;
  onActivity: () => void;
  onAccount: () => void;
  onPlan: () => void;
  /** The console's admin panel in the browser (the super admin only). */
  onAdmin: () => void;
  onWorkspaces: () => void;
  onReady: () => void;
  onRecord: () => void;
  onVoiceNote: () => void;
  onUpload: () => void;
  onNewProject: () => void;
  onRequestLink: () => void;
  onHelp: () => void;
  onSignOut: () => void;
  /** A viewer records and uploads nothing. */
  canRecord: boolean;
}) {
  const entitlements = useEntitlements();
  const teamLocked = isLocked(entitlements, "team");
  const workspaces = workspacesOf(account, status);
  const here = workspaces.find((w) => w.current) ?? workspaces[0];
  // The account's name, never this computer's (a person glyph until it is known).
  const accountName = account ? account.name?.trim() || account.email.split("@")[0] : null;
  const name = accountName || "You";
  const plan = entitlements?.plan ? planName(entitlements.plan) : null;
  const upgradeOffered = !!entitlements && canUpgrade(here.role) && !openRequest(entitlements) && !!nextPlan(entitlements);
  const recording = status.recording;
  const what = recording ? (recording.mode === "mic_note" ? "Voice note" : sourceAppLabel(recording.sourceApp)) : "";
  return (
    <header className="topbar" data-tauri-drag-region>
      <Menu label={`Workspace: ${here.name}`} title="Workspace" className="workspace-switch" buttonClass="workspace-btn" align="left"
        button={<>
          <span className="workspace-avatar" aria-hidden="true">{initials(here.name)}</span>
          <span className="workspace-text"><span className="workspace-name">{here.name}</span>{roleText(here.role) && <span className="workspace-role">{roleText(here.role)}</span>}</span>
          <ChevronDownIcon size={14} />
        </>}
        header={<span className="small muted">This computer works in {here.name}.</span>}
        items={[
          ...workspaces.map((w) => ({ id: `ws-${w.key}`, label: <>{w.name}{roleText(w.role) && <span className="small muted"> · {roleText(w.role)}</span>}</>, name: w.name, checked: w.current,
            onPick: () => (w.current ? undefined : onWorkspaces()) })),
          "separator",
          // Team workspaces (v1.6.2): a plan without them still shows Invite, with its badge, and opens the upgrade sheet.
          (teamLocked || here.role === "owner" || here.role === "admin") && {
            id: "ws-invite", label: <>Invite people<BadgeMark feature="team" /></>, icon: <LucideUserPlusIcon size={16} />,
            name: teamLocked ? `Invite people, ${featureWords(entitlements, "team").name} is in ${lowestPlan(entitlements, "team")?.name ?? "a paid plan"}` : "Invite people",
            onPick: teamLocked ? () => openUpgradeSheet("team") : onWorkspaces,
          },
          { id: "ws-manage", label: "Switch or manage workspaces in the browser", icon: <ExternalIcon size={16} />, onPick: onWorkspaces },
        ]} />
      <button type="button" className="topbar-search" onClick={onPalette} aria-label={`Search and commands (${paletteKeys(status.platform)})`}>
        <SearchIcon size={16} />
        <span className="topbar-search-text">Search recordings, settings, pages… or type a command</span>
        <span className="kbd" aria-hidden="true">{paletteKeys(status.platform)}</span>
      </button>
      <span className="spacer" />
      {recording ? (
        <button type="button" className="ready-chip recording" data-testid="recording-chip" onClick={onReady} title="Open Home to pause or stop it">
          <span className={`status-dot ${status.paused ? "paused" : "live"}`} aria-hidden="true" />
          <span className="ready-chip-text">{status.paused ? "Paused" : "Recording"} · {what} · {formatDuration(status.elapsedMs) || "0:00"}</span>
        </button>
      ) : canRecord && <ReadyChip status={status} onOpen={onReady} />}
      {canRecord && (
        <Menu label="Create" title="Create (record, upload, a project, a request link)" buttonClass="btn icon-only topbar-icon create-btn" button={<PlusIcon size={18} />}
          items={[
            { id: "record", label: recording ? "Stop recording" : "Record now", icon: <RecordIcon size={16} />, onPick: onRecord },
            !recording && { id: "voice", label: "Record a voice note", icon: <MicIcon size={16} />, hint: "N", onPick: onVoiceNote },
            { id: "upload", label: "Upload files", icon: <ImportIcon size={16} />, onPick: onUpload },
            "separator",
            { id: "project", label: "New project", icon: <FolderIcon size={16} />, onPick: onNewProject },
            { id: "request", label: "Request link", icon: <RequestFilesIcon size={16} />, onPick: onRequestLink },
          ]} />
      )}
      <button type="button" className="btn icon-only topbar-icon bell" onClick={onActivity} title="Notifications"
        aria-label={needsYou > 0 ? `Notifications, ${badgeSpoken(needsYou)}` : "Notifications"}>
        <BellIcon size={18} />
        {needsYou > 0 && <span className="bell-count" aria-hidden="true">{badgeLabel(needsYou)}</span>}
      </button>
      <button type="button" className="btn icon-only topbar-icon" onClick={onHelp} aria-label="Help & shortcuts" title="Help & shortcuts (?)"><HelpIcon size={18} /></button>
      <Menu label={`Account menu: ${name}`} title={name} buttonClass="avatar-btn"
        button={<span className="avatar-initials" aria-hidden="true">{accountName ? initials(accountName) : <LucideUserIcon size={16} />}</span>}
        header={
          <button type="button" className="menu-who as-button" data-menu-close onClick={onAccount} aria-label={`${name}${account?.email ? `, ${account.email}` : ""}${plan ? `, ${plan} plan` : ""}. Opens Settings`}>
            <Avatar name={accountName} size={36} />
            <span className="menu-who-text"><b>{name}</b>{account?.email && <span className="small muted">{account.email}</span>}{plan && <PlanChip name={plan} />}</span>
          </button>
        }
        footer={<>
          <div className="menu-theme">
            <span className="small" id="menu-theme-label">Appearance</span>
            <span className="segmented" role="radiogroup" aria-labelledby="menu-theme-label">
              {THEMES.map(([value, label]) => (
                <button key={value} type="button" role="radio" aria-checked={theme === value} onClick={() => onTheme(value)}>{label}</button>
              ))}
            </span>
          </div>
          <div className="menu-sep" role="separator" />
          <button type="button" role="menuitem" className="btn ghost small menu-item danger" data-menu-close onClick={onSignOut}>
            <span className="menu-icon" aria-hidden="true"><SignOutIcon size={16} /></span><span className="menu-label">Sign out</span>
          </button>
          </>
        }
        items={[
          upgradeOffered
            ? { id: "upgrade", label: "Upgrade", icon: <LucideCreditCardIcon size={16} />, onPick: () => openUpgradeSheet(null, { plan: nextPlan(entitlements)?.key ?? null }) }
            : entitlements && { id: "plan", label: "Plan & usage", icon: <LucideCreditCardIcon size={16} />, onPick: onPlan },
          { id: "account", label: "Settings", icon: <LucideSettingsIcon size={16} />, onPick: onAccount },
          account?.super_admin === true && { id: "admin", label: "Admin panel", icon: <ExternalIcon size={16} />, onPick: onAdmin },
        ]} />
    </header>
  );
}
