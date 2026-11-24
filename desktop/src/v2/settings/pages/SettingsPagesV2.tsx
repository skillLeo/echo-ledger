// The v2 Settings pages and their sub-pages, opened from the v2 Settings list (or a link elsewhere) by section. A
// page's way back goes to the page it sits on, and from a top page to the list.
import { useEffect, useState } from "react";
import { setting } from "../../../lib/settingsRegistry";
import { NoPlans, PageLoading, SettingsPage, useSettings, type Run } from "./rows";
import { V2_SETTINGS_PAGES, sectionTarget } from "./route";
import { HistoryPageV2, ReplacementsPageV2, SnippetsPageV2, VoicePageV2 } from "./VoiceV2";
import { CaptionStylePageV2, CaptionsPageV2 } from "./CaptionsV2";
import { CallSitesPageV2, CallsPageV2 } from "./CallsV2";
import { KeyPageV2, LanguagePageV2, WordsPageV2 } from "./TranscriptionV2";
import { useEntitlements } from "../../../lib/entitlements";
import type { AppStatus } from "../../../lib/ipc";
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

export function SettingsPagesV2({ section, onHome, onSection, run, status, onUnpaired, theme, onTheme, threeLevels = false }: {
  section: { id: string; seq: number };
  /** The rail · panel · page layout (its keys on the shortcuts page). */
  threeLevels?: boolean;
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
  const start = sectionTarget(section.id);
  const [page, setPage] = useState(start?.page ?? "voice");
  const [target, setTarget] = useState<{ id: string; seq: number } | null>(start?.setting ? { id: start.setting, seq: section.seq } : null);
  const { settings, patch, setSettings } = useSettings(run);
  const [themeChoice, chooseTheme] = useThemeChoice(theme, onTheme);
  const entitlements = useEntitlements();
  const [account, setAccount] = useAccount();
  const platform = status.platform;
  useEffect(() => {
    const t = sectionTarget(section.id);
    if (!t) return;
    setPage(t.page);
    setTarget(t.setting ? { id: t.setting, seq: section.seq } : null);
  }, [section]);

  const open = (next: string) => {
    setTarget(null);
    setPage(next);
  };
  const parent = setting(page).page;
  const body = !settings ? <PageLoading /> : (() => {
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
      case "appearance": return <AppearancePageV2 theme={themeChoice} onTheme={chooseTheme} rail={threeLevels} />;
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
  return (
    <SettingsPage key={page} title={setting(page).title} back={parent ? setting(parent).title : "Settings"} target={target}
      onBack={() => (!parent ? onHome() : V2_SETTINGS_PAGES.has(parent) ? open(parent) : onSection ? onSection(parent) : onHome())}>
      {body}
    </SettingsPage>
  );
}
