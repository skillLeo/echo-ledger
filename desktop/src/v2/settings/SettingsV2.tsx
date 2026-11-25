// Settings in the new look (ui_v2.settings): one searchable list grouped by goal (docs/v2/IA.md 5.1–5.9), the profile
// card with the plan and Upgrade on top. Search finds every individual setting by the words the classic search knows
// (lib/settingsIndex.ts) and jumps to it; a page opens today's page for that feature (its one home), scrolled to the
// setting and highlighted. In two levels (UX-11, with the panel) the panel has the search and the groups, and a page is
// a group's page (SettingsPagesV2): no inner list.
import { PanelToggle } from "../shell/NavRail";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { api, type Settings, type StorageInfo } from "../../lib/ipc";
import { fileSize } from "../../lib/format";
import { searchSettings } from "../../lib/settingsIndex";
import { SETTINGS, breadcrumbOf, titleV2, type SettingEntry } from "../../lib/settingsRegistry";
import { GOAL_GROUPS, sectionFor } from "./groups";
import { StartupRowV2, STARTUP_IDS } from "./pages/StartupV2";
import { ROOT_ICONS } from "../../components/Icons";
import { getThemeChoice } from "../../lib/theme";
import { useEntitlements } from "../../lib/entitlements";
import { Button, Card, Chip, EmptyState, Icon, Icons, List, ListRow, ListSection, SearchField, type IconType } from "../components";
import { AccountCard } from "./AccountCard";
import { SHOW_PANEL_EVENT } from "../shell/NavRail";
import { t } from "../../i18n";

export { GOAL_GROUPS, entryOf, groupOf, sectionFor, ungrouped } from "./groups";

const slug = (s: string) => s.toLowerCase().replace(/[^a-z]+/g, "-");
/** The theme's words, in the window's language. */
const THEME_WORDS = {
  get system() { return t("System"); },
  get light() { return t("Light"); },
  get dark() { return t("Dark"); },
};
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
  /**
   * Two levels (UX-11): the groups and the search are the panel's, so the home shows the profile card and Sign out only,
   * and a page is its group's whole page (no inner list).
   */
  inPanel?: boolean;
};

export function SettingsV2({ available, section = null, renderPage, onSignOut, setupHealth = false, footer, inPanel = false }: SettingsV2Props) {
  const [opened, setOpened] = useState<{ id: string; seq: number } | null>(null);
  const [query, setQuery] = useState("");
  const [settings, setSettings] = useState<Settings | null>(null);
  const [storage, setStorage] = useState<StorageInfo | null>(null);
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
  if (inPanel) {
    return (
      <div className="el-page el-page--narrow" data-v2="settings">
        <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">{t("Settings")}</h1></header>
        <AccountCard />
        <div className="el-settings-home__groups">
          <p className="el-secondary">{t("Choose a group in the panel, or search there for any setting.")}</p>
          <Button onClick={() => window.dispatchEvent(new Event(SHOW_PANEL_EVENT))}>{t("Show groups")}</Button>
        </div>
        {onSignOut && (
          <Card>
            <List label={t("Sign out")} className="el-group__rows">
              <ListRow compact icon={Icons.SignOutIcon} iconStyle="plain" title={t("Sign out")} onClick={onSignOut} />
            </List>
          </Card>
        )}
        {footer}
      </div>
    );
  }

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
        ? <ListRow key={id} compact icon={Icons.ReadyIcon} iconStyle="plain" title={t("Setup & health")} chevron onClick={() => open("setup_health")} />
        : null;
    }
    const entry = SETTINGS.find((e) => e.id === id);
    if (!entry || !available(entry)) return null;
    if ((STARTUP_IDS as readonly string[]).includes(id)) {
      return <StartupRowV2 key={id} id={id} settings={settings} onSettings={setSettings} icon={iconOf(id)} />;
    }
    return <ListRow key={id} compact icon={iconOf(id)} iconStyle="plain" title={entry.title} value={value(id)} chevron onClick={() => open(sectionFor(entry))} />;
  };

  return (
    <div className="el-page el-page--narrow" data-v2="settings">
      <header className="el-page__head"><PanelToggle /><h1 className="el-title-l">{t("Settings")}</h1></header>
      <SearchField label={t("Search settings")} value={query} onChange={setQuery} />
      {answer ? (
        answer.settings.length + answer.help.length > 0 ? (
          <Card>
            <List label={t("Results")} className="el-group__rows">
              {answer.settings.map((r) => (
                <ListRow key={r.entry.id} compact title={titleV2(r.entry)} meta={r.breadcrumb || undefined} chevron onClick={() => go(r.entry)} />
              ))}
              {answer.help.length > 0 && <ListSection title={t("Help")} />}
              {answer.help.map((r) => (
                <ListRow key={r.entry.id} compact icon={Icons.HelpIcon} iconStyle="plain" title={r.entry.title} meta={breadcrumbOf(r.entry) || undefined} chevron onClick={() => go(r.entry)} />
              ))}
            </List>
          </Card>
        ) : (
          <EmptyState icon={Icons.SearchIcon} title={t("No settings match")} body={t("Try another word, such as language, storage or captions.")}
            action={answer.closest.length > 0
              ? <div className="el-filterbar" role="group" aria-label={t("Closest matches")}>{answer.closest.map((e) => <Chip key={e.id} label={titleV2(e)} onToggle={() => go(e)} />)}</div>
              : <Button onClick={() => setQuery("")}>{t("Clear search")}</Button>} />
        )
      ) : (
        <>
          <AccountCard />
          {GOAL_GROUPS.map((g) => {
            const rows = g.ids.map(row).filter(Boolean);
            if (rows.length === 0) return null;
            return (
              // The group's English title is its id (the key, the description's id); its words are said in the window's language.
              <section key={g.title} className="el-group" aria-label={t(g.title)} aria-describedby={`group-${slug(g.title)}`}>
                <div className="el-group__label">
                  <Icon icon={g.icon} />
                  <h2 className="el-caption el-body-strong el-group__title">{t(g.title)}</h2>
                </div>
                <p className="el-caption el-secondary el-group__desc" id={`group-${slug(g.title)}`}>{t(g.description)}</p>
                <Card><List label={t(g.title)} className="el-group__rows">{rows}</List></Card>
              </section>
            );
          })}
          {onSignOut && (
            <Card>
              <List label={t("Sign out")} className="el-group__rows">
                <ListRow compact icon={Icons.SignOutIcon} iconStyle="plain" title={t("Sign out")} onClick={onSignOut} />
              </List>
            </Card>
          )}
          {footer}
        </>
      )}
    </div>
  );
}
