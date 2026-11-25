// The v2 Settings pages and their sub-pages, opened from the v2 Settings list (or a link elsewhere) by section. A
// page's way back goes to the page it sits on, and from a top page to the list.
// In two levels (UX-11, `grouped`: the panel has the search and the groups) a top page is drawn in its group's page:
// the group's title, then each of its pages as a titled section (the start-up switches and the way to Help among them),
// scrolled to the section or setting asked for. A sub-page (Call sites) has its own page, its way back going to the
// group's page, scrolled to the row that opened it. There is no "‹ Settings" and no inner list.
import { useEffect, useRef, useState, type ReactNode } from "react";
import { findSetting, setting, type SettingEntry } from "../../../lib/settingsRegistry";
import { Group, LinkSetting, NoPlans, PageLoading, SectionLevel, SettingsPage, partId, useSettings, type PageTarget, type Run } from "./rows";
import { V2_SETTINGS_PAGES, sectionTarget } from "./route";
import { entryOf, groupOf, type GoalGroup } from "../groups";
import { STARTUP_IDS, StartupRowV2 } from "./StartupV2";
import { HistoryPageV2, ReplacementsPageV2, SnippetsPageV2, VoicePageV2 } from "./VoiceV2";
import { CaptionStylePageV2, CaptionsPageV2 } from "./CaptionsV2";
import { CallSitesPageV2, CallsPageV2 } from "./CallsV2";
import { KeyPageV2, LanguagePageV2, WordsPageV2 } from "./TranscriptionV2";
import { useEntitlements } from "../../../lib/entitlements";
import type { AppStatus, Settings } from "../../../lib/ipc";
import { roleOf, useAccount } from "../../../views/main/AccountSettings";
import { ProfilePageV2 } from "./ProfileV2";
import { PlanPageV2 } from "./PlanV2";
import { DevicesPageV2 } from "./DevicesV2";
import { AboutPageV2, TechnicalPageV2 } from "./AboutV2";
import { MacCheckPageV2 } from "./MacCheckV2";
import { ConsentPageV2, PrivacyPageV2 } from "./PrivacyV2";
import { LockPageV2 } from "./LockV2";
import { SecurityPageV2, SignInActivityPageV2 } from "./SecurityV2";
import { SharePrivacyPageV2, ShareCheckPageV2 } from "./SharePrivacyV2";
import { NotificationsPageV2 } from "./NotificationsV2";
import { ExcludedAppsPageV2, MicrophonePageV2, ScreenMomentsPageV2 } from "./RecordingInputV2";
import { StoragePageV2 } from "./StorageV2";
import { AppearancePageV2, ShortcutsPageV2, useThemeChoice } from "./AppV2";
import type { ThemeChoice } from "../../../lib/theme";

/** Where a section asks the page to go: the setting (highlighted), else in a group's page its section (or its top). */
function targetOf(t: { page: string; setting: string | null }, seq: number, grouped: boolean): PageTarget | null {
  if (t.setting) return { id: t.setting, seq };
  if (!grouped || setting(t.page).page !== null) return null;
  const group = groupOf(t.page);
  return { id: t.page, seq, how: group && entryOf(group) === t.page ? "top" : "part" };
}

/** The start-up switches with a title of their own on a group's page (Shortcuts & start-up). */
const STARTUP_TITLE = "Start-up";
/** The Mac's menu bar icon, its timer and the Dock's number, under their own title. */
const ICON_IDS: readonly string[] = ["app.menu_bar_icon", "app.menu_bar_timer", "app.dock_badge"];

export function SettingsPagesV2({ section, onHome, onSection, run, status, onUnpaired, theme, onTheme, threeLevels = false, zoom = false, grouped = false, available = () => true }: {
  section: { id: string; seq: number };
  /** The rail · panel · page layout (its keys on the shortcuts page). */
  threeLevels?: boolean;
  /** Zoom is on (UX-07, v21_zoom): Appearance has its slider. */
  zoom?: boolean;
  /** Two levels (UX-11): a top page is drawn in its group's page; a sub-page's way back is to the group's page. */
  grouped?: boolean;
  /** What this computer, account and plan offer: a group's page draws only those pages and switches. */
  available?: (entry: SettingEntry) => boolean;
  onHome: () => void;
  /** Opens a section outside these pages (Help, from the Mac check); without it the way back is Settings. */
  onSection?: (id: string) => void;
  run: Run;
  status: AppStatus;
  /** The app's status after signing out (Sign out everywhere). */
  onUnpaired: (s: AppStatus) => void;
  /** The app's theme and how to change it, so the top bar's toggle and Appearance agree (else the page keeps its own). */
  theme?: ThemeChoice;
  onTheme?: (t: ThemeChoice) => void;
}) {
  const start = sectionTarget(section.id, grouped);
  const [page, setPage] = useState(start?.page ?? "voice");
  const [target, setTarget] = useState<PageTarget | null>(start ? targetOf(start, section.seq, grouped) : null);
  const backs = useRef(section.seq * 1000);
  /** The group whose page was drawn last (kept while one of its sub-pages shows). */
  const shownGroup = useRef<string | null>(null);
  const { settings, patch, setSettings } = useSettings(run);
  const [themeChoice, chooseTheme] = useThemeChoice(theme, onTheme);
  const entitlements = useEntitlements();
  const [account, setAccount] = useAccount();
  const platform = status.platform;
  useEffect(() => {
    const t = sectionTarget(section.id, grouped);
    if (!t) return;
    setPage(t.page);
    setTarget(targetOf(t, section.seq, grouped));
  }, [section]); // eslint-disable-line react-hooks/exhaustive-deps

  const open = (next: string) => {
    // In two levels a page of another group opens through the app, so the panel shows its group.
    if (grouped && onSection && groupOf(next) !== groupOf(page)) return onSection(next);
    // A sub-page opens at its top (the group's page above it may have been scrolled).
    setTarget(grouped ? { id: next, seq: ++backs.current, how: "top" } : null);
    setPage(next);
  };
  const parent = setting(page).page;
  const bodyFor = (page: string): ReactNode => !settings ? <PageLoading /> : (() => {
    switch (page) {
      case "voice": return <VoicePageV2 settings={settings} patch={patch} onOpen={open} />;
      case "voice.replacements": return <ReplacementsPageV2 settings={settings} patch={patch} />;
      case "voice.snippets": return <SnippetsPageV2 settings={settings} patch={patch} />;
      case "voice.history": return <HistoryPageV2 />;
      case "captions": return <CaptionsPageV2 settings={settings} patch={patch} onStyle={() => open("captions.style")} />;
      case "captions.style": return <CaptionStylePageV2 settings={settings} patch={patch} />;
      case "calls": return <CallsPageV2 settings={settings} patch={patch} platform={platform} onSites={() => open("calls.sites")} onConsent={() => open("privacy.consent")} />;
      case "transcription.language": return <LanguagePageV2 settings={settings} patch={patch} />;
      case "words": return <WordsPageV2 settings={settings} patch={patch} />;
      case "key": return entitlements === undefined ? <PageLoading /> : entitlements ? <KeyPageV2 e={entitlements} /> : <NoPlans />;
      case "privacy": return account === undefined ? <PageLoading /> : <PrivacyPageV2 account={account} settings={settings} onAccount={setAccount} onConsent={() => open("privacy.consent")} />;
      case "privacy.consent": return <ConsentPageV2 status={status} settings={settings} patch={patch} />;
      case "lock": return <LockPageV2 settings={settings} patch={patch} run={run} />;
      case "security": return account ? <SecurityPageV2 account={account} run={run} onSignedOut={onUnpaired} onActivity={() => open("security.activity")} /> : <PageLoading />;
      case "security.activity": return <SignInActivityPageV2 />;
      case "share_privacy": return <SharePrivacyPageV2 settings={settings} patch={patch} onCheck={() => open("share_privacy.check")} />;
      case "share_privacy.check": return <ShareCheckPageV2 />;
      case "notifications": return <NotificationsPageV2 settings={settings} patch={patch} run={run} />;
      case "mic": return <MicrophonePageV2 settings={settings} patch={patch} disabled={!!status.recording || status.dictationActive} systemAudioNote={status.systemAudioNote} />;
      case "screen": return <ScreenMomentsPageV2 settings={settings} patch={patch} onExcluded={() => open("screen.excluded")} />;
      case "screen.excluded": return <ExcludedAppsPageV2 settings={settings} patch={patch} platform={platform} />;
      case "storage": return <StoragePageV2 limitBytes={entitlements?.limits.storage_bytes?.limit ?? status.plan.storageBytes} />;
      case "appearance": return <AppearancePageV2 theme={themeChoice} onTheme={chooseTheme} rail={threeLevels} zoom={zoom} />;
      case "shortcuts": return <ShortcutsPageV2 settings={settings} status={status} run={run} onSettings={setSettings} threeLevels={threeLevels} />;
      case "profile": return account === undefined ? <PageLoading /> : account ? <ProfilePageV2 account={account} status={status} run={run} /> : null;
      case "plan": return entitlements === undefined ? <PageLoading /> : entitlements
        ? <PlanPageV2 e={entitlements} role={roleOf(account, status)} workspace={account?.workspace?.name || status.plan.workspace || null} onKey={() => open("key")} />
        : <NoPlans />;
      case "devices": return <DevicesPageV2 run={run} />;
      case "about": return <AboutPageV2 status={status} run={run} onImported={setSettings} onDevices={() => open("devices")} onTechnical={() => open("about.technical")} />;
      case "about.technical": return <TechnicalPageV2 status={status} />;
      // On a Mac only, as today (the registry asks for macOS).
      case "help.mac_check": return platform === "macos" ? <MacCheckPageV2 status={status} /> : null;
      case "calls.sites": return <CallSitesPageV2 rules={settings.siteRules} onSave={(siteRules) => patch({ siteRules })} />;
      default: return null;
    }
  })();

  if (grouped && (parent || groupOf(page))) {
    // A sub-page goes back to its group's page, at the row that opened it (or to the page it sits on elsewhere: Help).
    const home = parent ? (V2_SETTINGS_PAGES.has(parent) ? groupOf(parent) : null) : groupOf(page);
    if (!parent && home) shownGroup.current = home.title;
    // The group's page stays (hidden) while a sub-page opened from it shows: back is at once, nothing read again.
    const kept = home && (!parent || shownGroup.current === home.title) ? home : null;
    const back = () => {
      if (!parent) return;
      if (!home) return onSection ? onSection(parent) : onHome();
      setPage(parent);
      setTarget({ id: page, seq: ++backs.current, how: "row" });
    };
    return (
      <>
        <div className="el-settings-kept" hidden={!!parent}>
          {kept && (
            <SettingsPage key={kept.title} kind="group" title={kept.title} target={parent ? null : target}>
              <GroupParts group={kept} available={available} body={bodyFor} settings={settings} onSettings={setSettings}
                onHelp={onSection ? () => onSection("help") : undefined} />
            </SettingsPage>
          )}
        </div>
        {parent && <SettingsPage key={page} title={setting(page).title} back={home ? home.title : setting(parent).title} target={target} onBack={back}>{bodyFor(page)}</SettingsPage>}
      </>
    );
  }
  return (
    <SettingsPage key={page} title={setting(page).title} back={parent ? setting(parent).title : "Settings"} target={target}
      onBack={() => (!parent ? onHome() : V2_SETTINGS_PAGES.has(parent) ? open(parent) : onSection ? onSection(parent) : onHome())}>
      {bodyFor(page)}
    </SettingsPage>
  );
}

type Part = { key: string; title: string; body: ReactNode; untitled?: boolean };

/**
 * A group's page (UX-11, DES-05): each of its pages this computer, account and plan offer, in the group's order, as a
 * section under the page's title (a level-2 heading; its own sections' titles are level 3 under it). With one page only,
 * or the page named as the group (Privacy & data), the group's title is enough. Setup & health is the rail's own.
 */
function GroupParts({ group, available, body, settings, onSettings, onHelp }: {
  group: GoalGroup;
  available: (entry: SettingEntry) => boolean;
  body: (page: string) => ReactNode;
  settings: Settings | null;
  onSettings: (s: Settings) => void;
  /** Opens Help (its own page). */
  onHelp?: () => void;
}) {
  const offered = (id: string) => {
    const entry = findSetting(id);
    return !!entry && available(entry);
  };
  const parts: Part[] = [];
  const startup = group.ids.filter((id) => (STARTUP_IDS as readonly string[]).includes(id) && offered(id));
  for (const id of group.ids) {
    if (id === "setup_health") continue;
    if (id === "help") {
      if (onHelp && offered(id)) {
        // One row named Help: no title over it.
        parts.push({ key: id, title: setting(id).title, untitled: true, body: <Group><LinkSetting id="help" onOpen={onHelp} /></Group> });
      }
      continue;
    }
    if ((STARTUP_IDS as readonly string[]).includes(id)) {
      if (id !== startup[0]) continue;
      const row = (rowId: string) => <StartupRowV2 key={rowId} id={rowId} settings={settings} onSettings={onSettings} />;
      const own = startup.filter((s) => !ICON_IDS.includes(s));
      const icons = startup.filter((s) => ICON_IDS.includes(s));
      parts.push({ key: "startup", title: STARTUP_TITLE, body: (
        <>
          {own.length > 0 && <Group>{own.map(row)}</Group>}
          {icons.length > 0 && <Group title="Menu bar & Dock">{icons.map(row)}</Group>}
        </>
      ) });
      continue;
    }
    if (!offered(id) || !V2_SETTINGS_PAGES.has(id)) continue;
    const drawn = body(id);
    if (drawn !== null) parts.push({ key: id, title: setting(id).title, body: drawn });
  }
  const titled = parts.length > 1;
  return (
    <>
      {parts.map((p) => {
        const shown = titled && !p.untitled && p.title !== group.title;
        return (
          <section key={p.key} className="el-settings-part" id={partId(p.key)} aria-labelledby={shown ? `${partId(p.key)}-title` : undefined}>
            {shown && <h2 className="el-title-s el-settings-part__title" id={`${partId(p.key)}-title`} tabIndex={-1}>{p.title}</h2>}
            <SectionLevel value={shown ? 3 : 2}>{p.body}</SectionLevel>
          </section>
        );
      })}
    </>
  );
}
