// Settings in the window's search (the palette): each setting that matches and each Settings page, said in the
// window's language as Settings says them (D3b), and ranked by the English title the person may have typed, so in Urdu
// "storage" still makes Storage the row Enter opens, and a page is never listed twice in two languages.
import type { PaletteSetting } from "../../views/main/CommandPalette";
import { settingsPages } from "../../views/main/SettingsView";
import type { PageEntry } from "../../lib/shell";
import { searchSettings } from "../../lib/settingsIndex";
import { titleOf, type SettingEntry } from "../../lib/settingsRegistry";
import { t } from "../../i18n";

/** The settings that match what was typed (six at most); `open` gets the section to open ("storage", "lock#lock.after"). */
export function paletteSettings(query: string, available: (entry: SettingEntry) => boolean, open: (section: string) => void): PaletteSetting[] {
  return searchSettings(query, available).settings.slice(0, 6).map((r) => ({
    id: r.entry.id, title: titleOf(r.entry.id), name: r.entry.title, breadcrumb: r.breadcrumb,
    open: () => open(r.entry.kind === "page" ? r.entry.id : `${r.entry.page ?? r.entry.id}#${r.entry.id}`),
  }));
}

/** Settings' pages as the palette's pages ("Go to Storage · Settings › App"); `open` gets the page's id. */
export function palettePages(available: (entry: SettingEntry) => boolean, open: (page: string) => void): (PageEntry & { open: () => void })[] {
  return settingsPages(available).map((p) => ({
    id: p.id, label: titleOf(p.page), name: p.label, group: p.group.split(" › ").map((words) => t(words)).join(" › "), keywords: p.keywords,
    open: () => open(p.page),
  }));
}
