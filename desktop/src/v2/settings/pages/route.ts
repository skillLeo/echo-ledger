// Which Settings pages have their v2 page (the rest still open today's page inside the v2 frame), and the page and
// setting a section asks for ("voice", "voice#voice.cleanup", a setting's own id, or a v1.6 name).
import { findSetting, pageFor } from "../../../lib/settingsRegistry";
import { LEGACY_SECTIONS } from "../../../views/main/SettingsView";

export const V2_SETTINGS_PAGES: ReadonlySet<string> = new Set([
  "voice", "voice.replacements", "voice.snippets", "voice.history",
  "captions", "captions.style",
]);

/** The page a section opens and the setting to highlight there; null for an unknown section. */
export function sectionTarget(sectionId: string): { page: string; setting: string | null } | null {
  const [where, id] = (LEGACY_SECTIONS[sectionId] ?? sectionId).split("#");
  const entry = findSetting(where);
  if (!entry) return null;
  const page = pageFor(entry);
  if (!page) return null;
  return { page, setting: id ?? (entry.kind === "page" ? null : entry.id) };
}

/** Whether a section opens a v2 page. */
export function hasV2Page(sectionId: string): boolean {
  const t = sectionTarget(sectionId);
  return !!t && V2_SETTINGS_PAGES.has(t.page);
}
