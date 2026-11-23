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
import { Button, Card, Chip, EmptyState, Icon, Icons, List, ListRow, ListSection, SearchField, Toggle, type IconType } from "../components";
import { AccountCard } from "./AccountCard";

/**
 * The goal groups of IA 5 and the root entries each holds (one home per feature), each with its icon and a one-line
 * description of what is inside (PLAN_V2_PARITY 5).
 */
export const GOAL_GROUPS: readonly { title: string; icon: IconType; description: string; ids: readonly string[] }[] = [
  { title: "Recording", icon: Icons.RecordIcon, description: "Which calls are recorded, and the microphone, screen and voice used.", ids: ["calls", "mic", "screen", "voice"] },
  { title: "Transcription & language", icon: Icons.TranscriptIcon, description: "The languages your calls are in, your own words, and your transcription key.", ids: ["transcription.language", "words", "key"] },
  { title: "Live & overlays", icon: Icons.CaptionsIcon, description: "Captions during calls, and what others see when you share your screen.", ids: ["captions", "share_privacy"] },
  { title: "Privacy & data", icon: Icons.PrivacyIcon, description: "What is kept, for how long, and the app lock.", ids: ["privacy", "storage", "lock"] },
  { title: "Notifications", icon: Icons.BellIcon, description: "What EchoLedger tells you, and when.", ids: ["notifications"] },
  { title: "Appearance", icon: Icons.AppearanceIcon, description: "Theme, sidebar, density, times and the design.", ids: ["appearance"] },
  { title: "Account & plan", icon: Icons.UserIcon, description: "Your profile, plan, sign-in security and devices.", ids: ["profile", "plan", "security", "devices"] },
  { title: "Setup & health", icon: Icons.ReadyIcon, description: "Check that recording works on this computer.", ids: ["setup_health"] },
  { title: "Shortcuts & start-up", icon: Icons.KeyboardIcon, description: "Keys that start things, and opening EchoLedger when you sign in.", ids: ["shortcuts", "app.autostart"] },
  { title: "About & help", icon: Icons.HelpIcon, description: "Help and shortcuts, what's new, and the version.", ids: ["help", "about"] },
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

const slug = (t: string) => t.toLowerCase().replace(/[^a-z]+/g, "-");
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
  /**
   * The three levels (PLAN_NAV_3_LEVELS §3, §4): the groups and the search are the panel's, so the home shows the
   * profile card and Sign out only, and a page has its group's sections in an inner list beside it.
   */
  inPanel?: boolean;
};

/** The goal group a section is in (a page or one of its settings: "calls", "calls#calls.after"). */
export function groupOf(sectionId: string) {
  const id = sectionId.split("#")[0].split(".")[0];
  return GOAL_GROUPS.find((g) => g.ids.some((x) => x === sectionId.split("#")[0] || x.split(".")[0] === id)) ?? null;
}

export function SettingsV2({ available, section = null, renderPage, onSignOut, setupHealth = false, footer, inPanel = false }: SettingsV2Props) {
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
  if (opened && inPanel) {
    // The group's sections beside the page (Level 3: an inner list, as a site's settings have).
    const group = groupOf(opened.id);
    const ids = (group?.ids ?? []).filter((id) => id === "setup_health" ? setupHealth : !!SETTINGS.find((e) => e.id === id && available(e)));
    const here = opened.id.split("#")[0];
    return (
      <div className="el-settings-split">
        {group && ids.length > 1 && (
          <nav className="el-settings-split__list" aria-label={`${group.title} sections`}>
            <h2 className="el-caption el-body-strong el-settings-split__title">{group.title}</h2>
            <ul className="el-nav-list">
              {ids.map((id) => {
                const entry = SETTINGS.find((e) => e.id === id);
                const name = id === "setup_health" ? "Setup & health" : entry ? titleV2(entry) : id;
                return (
                  <li key={id}>
                    <button type="button" className="el-nav-item el-nav-item--row" aria-current={here === id || here.startsWith(`${id}.`) ? "page" : undefined}
                      onClick={() => open(id)}><span className="el-nav-item__label">{name}</span></button>
                  </li>
                );
              })}
            </ul>
          </nav>
        )}
        <div className="el-settings-page">{renderPage(opened, () => setOpened(null))}</div>
      </div>
    );
  }
  if (opened) return <div className="el-settings-page">{renderPage(opened, () => setOpened(null))}</div>;
  if (inPanel) {
    return (
      <div className="el-page el-page--narrow" data-v2="settings">
        <header className="el-page__head"><h1 className="el-title-l">Settings</h1></header>
        <AccountCard />
        <p className="el-secondary">Choose a group in the panel, or search there for any setting.</p>
        {onSignOut && (
          <Card>
            <List label="Sign out" className="el-group__rows">
              <ListRow compact icon={Icons.SignOutIcon} iconStyle="plain" title="Sign out" onClick={onSignOut} />
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
              <section key={g.title} className="el-group" aria-label={g.title} aria-describedby={`group-${slug(g.title)}`}>
                <div className="el-group__label">
                  <Icon icon={g.icon} />
                  <h2 className="el-caption el-body-strong el-group__title">{g.title}</h2>
                </div>
                <p className="el-caption el-secondary el-group__desc" id={`group-${slug(g.title)}`}>{g.description}</p>
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
