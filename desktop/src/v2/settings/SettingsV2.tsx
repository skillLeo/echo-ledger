// Settings in the new look (ui_v2.settings): one searchable list grouped by goal (docs/v2/IA.md 5.1–5.9), the profile
// card with the plan and Upgrade on top. Search finds every individual setting by the words the classic search knows
// (lib/settingsIndex.ts) and jumps to it; a page opens today's page for that feature (its one home), scrolled to the
// setting and highlighted.
import { useEffect, useRef, useState, type ReactNode } from "react";
import { api, errorMessage, type Settings, type StorageInfo } from "../../lib/ipc";
import { fileSize } from "../../lib/format";
import { searchSettings } from "../../lib/settingsIndex";
import { SETTINGS, breadcrumbOf, titleV2, type SettingEntry } from "../../lib/settingsRegistry";
import { ROOT_ICONS } from "../../components/Icons";
import { getThemeChoice } from "../../lib/theme";
import { useEntitlements } from "../../lib/entitlements";
import { Button, Card, Chip, EmptyState, Icons, List, ListRow, ListSection, SearchField, Toggle, type IconType } from "../components";
import { AccountCard } from "./AccountCard";

/** The goal groups of IA 5 and the root entries each holds (one home per feature). */
export const GOAL_GROUPS: readonly { title: string; ids: readonly string[] }[] = [
  { title: "Recording", ids: ["calls", "mic", "screen", "voice"] },
  { title: "Transcription & language", ids: ["transcription.language", "words", "key"] },
  { title: "Live & overlays", ids: ["captions", "share_privacy"] },
  { title: "Privacy & data", ids: ["privacy", "storage", "lock"] },
  { title: "Notifications", ids: ["notifications"] },
  { title: "Appearance", ids: ["appearance"] },
  { title: "Account & plan", ids: ["profile", "plan", "security", "devices"] },
  { title: "Setup & health", ids: ["setup_health"] },
  { title: "Shortcuts & start-up", ids: ["shortcuts", "app.autostart"] },
  { title: "About & help", ids: ["help", "about"] },
];

/** Every root entry of the registry sits in exactly one goal group (sign out has its own row). */
export function ungrouped(): string[] {
  const grouped = new Set(GOAL_GROUPS.flatMap((g) => g.ids));
  return SETTINGS.filter((e) => e.page === null && e.kind !== "help" && e.id !== "signout" && !grouped.has(e.id)).map((e) => e.id);
}

/** What opening a search result asks today's Settings for: the page, and the setting to scroll to. */
export function sectionFor(entry: SettingEntry): string {
  return entry.kind === "page" ? entry.id : `${entry.page ?? entry.id}#${entry.id}`;
}

const THEME_WORDS = { system: "System", light: "Light", dark: "Dark" } as const;
const iconOf = (id: string): IconType => (ROOT_ICONS[id] as IconType | undefined) ?? Icons.SettingsIcon;

export type SettingsV2Props = {
  /** What this computer, account and plan offer (SettingsView's settingsAvailability). */
  available: (entry: SettingEntry) => boolean;
  /** A page or setting asked for from elsewhere (a notice, the palette), with a fresh seq. */
  section?: { id: string; seq: number } | null;
  /** Today's page for a section; `onHome` brings this list back. */
  renderPage: (section: { id: string; seq: number }, onHome: () => void) => ReactNode;
  onSignOut?: () => void;
  /** Setup & health in the new look (ui_v2.setup_health): its row opens the section "setup_health". */
  setupHealth?: boolean;
  /** Shown under the groups ("Switch back to classic"). */
  footer?: ReactNode;
};

export function SettingsV2({ available, section = null, renderPage, onSignOut, setupHealth = false, footer }: SettingsV2Props) {
  const [opened, setOpened] = useState<{ id: string; seq: number } | null>(null);
  const [query, setQuery] = useState("");
  const [settings, setSettings] = useState<Settings | null>(null);
  const [storage, setStorage] = useState<StorageInfo | null>(null);
  const [autostartError, setAutostartError] = useState<string | null>(null);
  const entitlements = useEntitlements();
  const seq = useRef(1000);

  useEffect(() => {
    api.settings().then(setSettings, () => undefined);
    api.storage().then((s) => setStorage(s ?? null), () => undefined);
  }, []);
  useEffect(() => {
    if (section) setOpened(section);
  }, [section]);

  const open = (id: string) => setOpened({ id, seq: ++seq.current });
  if (opened) return <div className="el-settings-page">{renderPage(opened, () => setOpened(null))}</div>;

  const answer = query.trim().length >= 2 ? searchSettings(query, available) : null;
  const go = (entry: SettingEntry) => {
    if (entry.id === "signout") return onSignOut?.();
    open(sectionFor(entry));
  };
  const value = (id: string): string | undefined => {
    if (id === "plan") return entitlements?.plan?.name ?? undefined;
    if (id === "storage") return storage ? fileSize(storage.recordings_bytes) : undefined;
    if (id === "appearance") return THEME_WORDS[getThemeChoice()];
    return undefined;
  };
  const row = (id: string) => {
    if (id === "setup_health") {
      return setupHealth
        ? <ListRow key={id} compact icon={Icons.ReadyIcon} iconStyle="plain" title="Setup & health" chevron onClick={() => open("setup_health")} />
        : null;
    }
    const entry = SETTINGS.find((e) => e.id === id);
    if (!entry || !available(entry)) return null;
    if (id === "app.autostart") {
      return (
        <ListRow key={id} compact icon={iconOf(id)} iconStyle="plain" title={entry.title}
          meta={autostartError ? <span className="el-row__error" role="alert">{autostartError}</span> : undefined}
          control={settings ? <Toggle label={entry.title} hideLabel checked={settings.autostart}
            onChange={(on) => {
              setAutostartError(null);
              void api.updateSettings({ autostart: on }).then(setSettings, (e) => setAutostartError(errorMessage(e)));
            }} /> : undefined} />
      );
    }
    return <ListRow key={id} compact icon={iconOf(id)} iconStyle="plain" title={entry.title} value={value(id)} chevron onClick={() => open(sectionFor(entry))} />;
  };

  return (
    <div className="el-page el-page--narrow" data-v2="settings">
      <header className="el-page__head"><h1 className="el-title-l">Settings</h1></header>
      <SearchField label="Search settings" value={query} onChange={setQuery} />
      {answer ? (
        answer.settings.length + answer.help.length > 0 ? (
          <Card>
            <List label="Results" className="el-group__rows">
              {answer.settings.map((r) => (
                <ListRow key={r.entry.id} compact title={titleV2(r.entry)} meta={r.breadcrumb || undefined} chevron onClick={() => go(r.entry)} />
              ))}
              {answer.help.length > 0 && <ListSection title="Help" />}
              {answer.help.map((r) => (
                <ListRow key={r.entry.id} compact icon={Icons.HelpIcon} iconStyle="plain" title={r.entry.title} meta={breadcrumbOf(r.entry) || undefined} chevron onClick={() => go(r.entry)} />
              ))}
            </List>
          </Card>
        ) : (
          <EmptyState icon={Icons.SearchIcon} title="No settings match" body="Try another word, such as language, storage or captions."
            action={answer.closest.length > 0
              ? <div className="el-filterbar" role="group" aria-label="Closest matches">{answer.closest.map((e) => <Chip key={e.id} label={titleV2(e)} onToggle={() => go(e)} />)}</div>
              : <Button onClick={() => setQuery("")}>Clear search</Button>} />
        )
      ) : (
        <>
          <AccountCard />
          {GOAL_GROUPS.map((g) => {
            const rows = g.ids.map(row).filter(Boolean);
            if (rows.length === 0) return null;
            return (
              <section key={g.title} className="el-group" aria-label={g.title}>
                <h2 className="el-caption el-body-strong el-group__title">{g.title}</h2>
                <Card><List label={g.title} className="el-group__rows">{rows}</List></Card>
              </section>
            );
          })}
          {onSignOut && (
            <Card>
              <List label="Sign out" className="el-group__rows">
                <ListRow compact icon={Icons.SignOutIcon} iconStyle="plain" title="Sign out" onClick={onSignOut} />
              </List>
            </Card>
          )}
          {footer}
        </>
      )}
    </div>
  );
}
