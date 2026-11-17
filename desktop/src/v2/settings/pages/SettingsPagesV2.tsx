// The v2 Settings pages and their sub-pages, opened from the v2 Settings list (or a link elsewhere) by section. A
// page's way back goes to the page it sits on, and from a top page to the list.
import { useEffect, useState } from "react";
import { setting } from "../../../lib/settingsRegistry";
import { PageLoading, SettingsPage, useSettings, type Run } from "./rows";
import { sectionTarget } from "./route";
import { HistoryPageV2, ReplacementsPageV2, SnippetsPageV2, VoicePageV2 } from "./VoiceV2";
import { CaptionStylePageV2, CaptionsPageV2 } from "./CaptionsV2";

export function SettingsPagesV2({ section, onHome, run }: { section: { id: string; seq: number }; onHome: () => void; run: Run }) {
  const start = sectionTarget(section.id);
  const [page, setPage] = useState(start?.page ?? "voice");
  const [target, setTarget] = useState<{ id: string; seq: number } | null>(start?.setting ? { id: start.setting, seq: section.seq } : null);
  const { settings, patch } = useSettings(run);
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
