// The start-up switches of Settings › Shortcuts & start-up: opening EchoLedger at sign-in, what ✕ does (Windows), and
// the Mac's menu bar icon, its timer and the Dock's number. They have no page of their own; the group's page has them
// (UX-11), and so does the list of Settings without the panel. Each saves at once; a refused start-up says why under it.
import { useState } from "react";
import { api, errorMessage, type Settings } from "../../../lib/ipc";
import { setting } from "../../../lib/settingsRegistry";
import { ListRow, Select, Toggle, type IconType } from "../../components";
import { t } from "../../../i18n";

/** The switches, in their order on the page. */
export const STARTUP_IDS = ["app.autostart", "app.on_close", "app.menu_bar_icon", "app.menu_bar_timer", "app.dock_badge"] as const;

// FIX-11: the Mac's menu bar icon, its timer and the Dock's number.
const ICON_SWITCHES: Record<string, "menuBarIcon" | "menuBarTimer" | "dockBadge"> = {
  "app.menu_bar_icon": "menuBarIcon", "app.menu_bar_timer": "menuBarTimer", "app.dock_badge": "dockBadge",
};

/** One of them as a row; its control waits for the settings. `icon`: the list of Settings draws its rows with one. */
export function StartupRowV2({ id, settings, onSettings, icon }: {
  id: string;
  settings: Settings | null;
  onSettings: (s: Settings) => void;
  icon?: IconType;
}) {
  const [error, setError] = useState<string | null>(null);
  const entry = setting(id);
  const look = icon ? { icon, iconStyle: "plain" as const } : {};
  if (id === "app.autostart") {
    return (
      <ListRow compact anchor={id} {...look} title={entry.title}
        meta={error ? <span className="el-row__error" role="alert">{error}</span> : undefined}
        control={settings ? <Toggle label={entry.title} hideLabel checked={settings.autostart}
          onChange={(on) => {
            setError(null);
            void api.updateSettings({ autostart: on }).then(onSettings, (e) => setError(errorMessage(e)));
          }} /> : undefined} />
    );
  }
  // FIX-12 (Windows): what ✕ does.
  if (id === "app.on_close") {
    return (
      <ListRow compact anchor={id} {...look} title={entry.title}
        control={settings ? <Select label={entry.title} hideLabel value={settings.closeQuits ? "quit" : "keep"}
          options={[{ value: "keep", label: t("Keep running in the background (recommended)") }, { value: "quit", label: t("Quit") }]}
          onChange={(v) => void api.updateSettings({ closeQuits: v === "quit" }).then(onSettings, () => undefined)} /> : undefined} />
    );
  }
  const key = ICON_SWITCHES[id];
  if (!key) return null;
  return (
    <ListRow compact anchor={id} {...look} title={entry.title} meta={entry.description}
      control={settings ? <Toggle label={entry.title} hideLabel checked={settings[key] !== false}
        onChange={(on) => void api.updateSettings({ [key]: on }).then(onSettings, () => undefined)} /> : undefined} />
  );
}
