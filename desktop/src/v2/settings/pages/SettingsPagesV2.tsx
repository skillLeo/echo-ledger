// The v2 Settings pages and their sub-pages, opened from the v2 Settings list (or a link elsewhere) by section. A
// page's way back goes to the page it sits on, and from a top page to the list.
import { useEffect, useState } from "react";
import { setting } from "../../../lib/settingsRegistry";
import { PageLoading, SettingsPage, useSettings, type Run } from "./rows";
import { sectionTarget } from "./route";
import { HistoryPageV2, ReplacementsPageV2, SnippetsPageV2, VoicePageV2 } from "./VoiceV2";
import { CaptionStylePageV2, CaptionsPageV2 } from "./CaptionsV2";
import { CallSitesPageV2, CallsPageV2 } from "./CallsV2";
import { KeyPageV2, LanguagePageV2, WordsPageV2 } from "./TranscriptionV2";
import { useEntitlements } from "../../../lib/entitlements";
import type { AppStatus } from "../../../lib/ipc";
import { useAccount } from "../../../views/main/AccountSettings";
import { ConsentPageV2, PrivacyPageV2 } from "./PrivacyV2";

export function SettingsPagesV2({ section, onHome, run, status }: {
  section: { id: string; seq: number };
  onHome: () => void;
  run: Run;
  status: AppStatus;
}) {
  const start = sectionTarget(section.id);
  const [page, setPage] = useState(start?.page ?? "voice");
  const [target, setTarget] = useState<{ id: string; seq: number } | null>(start?.setting ? { id: start.setting, seq: section.seq } : null);
  const { settings, patch } = useSettings(run);
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
      case "key": return entitlements ? <KeyPageV2 e={entitlements} /> : <PageLoading />;
      case "privacy": return account === undefined ? <PageLoading /> : <PrivacyPageV2 account={account} settings={settings} onAccount={setAccount} onConsent={() => open("privacy.consent")} />;
      case "privacy.consent": return <ConsentPageV2 status={status} settings={settings} patch={patch} />;
      case "calls.sites": return <CallSitesPageV2 rules={settings.siteRules} onSave={(siteRules) => patch({ siteRules })} />;
      default: return null;
    }
  })();
  return (
    <SettingsPage key={page} title={setting(page).title} back={parent ? setting(parent).title : "Settings"} target={target}
      onBack={() => (parent ? open(parent) : onHome())}>
      {body}
    </SettingsPage>
  );
}
