// The goal groups of Settings (docs/v2/IA.md 5.1–5.9): one home per feature. In two levels (UX-11) the panel lists the
// groups and a group's page has its pages as titled sections, so a section is found by its group.
import { SETTINGS, type SettingEntry } from "../../lib/settingsRegistry";
// The icons alone (not the components' index): the app's first chunk reads these groups through pages/route.ts.
import * as Icons from "../components/icons";
import type { IconType } from "../components/Button";

export type GoalGroup = { title: string; icon: IconType; description: string; ids: readonly string[] };

/**
 * The goal groups and the root entries each holds (one home per feature), each with its icon and a one-line
 * description of what is inside (PLAN_V2_PARITY 5).
 */
export const GOAL_GROUPS: readonly GoalGroup[] = [
  { title: "Recording", icon: Icons.RecordIcon, description: "Which calls are recorded, and the microphone, screen and voice used.", ids: ["calls", "mic", "screen", "voice"] },
  { title: "Transcription & language", icon: Icons.TranscriptIcon, description: "The languages your calls are in, your own words, and your transcription key.", ids: ["transcription.language", "words", "key"] },
  { title: "Live & overlays", icon: Icons.CaptionsIcon, description: "Captions during calls, and what others see when you share your screen.", ids: ["captions", "share_privacy"] },
  { title: "Privacy & data", icon: Icons.PrivacyIcon, description: "What is kept, for how long, and the app lock.", ids: ["privacy", "storage", "lock"] },
  { title: "Notifications", icon: Icons.BellIcon, description: "What EchoLedger tells you, and when.", ids: ["notifications"] },
  { title: "Appearance", icon: Icons.AppearanceIcon, description: "Theme, sidebar, density, times and the design.", ids: ["appearance"] },
  { title: "Account & plan", icon: Icons.UserIcon, description: "Your profile, plan, sign-in security and devices.", ids: ["profile", "plan", "security", "devices"] },
  { title: "Setup & health", icon: Icons.ReadyIcon, description: "Check that recording works on this computer.", ids: ["setup_health"] },
  { title: "Shortcuts & start-up", icon: Icons.KeyboardIcon, description: "Keys that start things, opening EchoLedger when you sign in, and its icons.",
    ids: ["shortcuts", "app.autostart", "app.on_close", "app.menu_bar_icon", "app.menu_bar_timer", "app.dock_badge"] },
  { title: "About & help", icon: Icons.HelpIcon, description: "Help and shortcuts, what's new, and the version.", ids: ["help", "about"] },
];

/** Every root entry of the registry sits in exactly one goal group (sign out has its own row). */
export function ungrouped(): string[] {
  const grouped = new Set(GOAL_GROUPS.flatMap((g) => g.ids));
  return SETTINGS.filter((e) => e.page === null && e.kind !== "help" && e.id !== "signout" && !grouped.has(e.id)).map((e) => e.id);
}

/** What opening a search result asks Settings for: the page, and the setting to scroll to. */
export function sectionFor(entry: SettingEntry): string {
  return entry.kind === "page" ? entry.id : `${entry.page ?? entry.id}#${entry.id}`;
}

/** The goal group a section is in (a page or one of its settings: "calls", "calls#calls.after"). */
export function groupOf(sectionId: string): GoalGroup | null {
  const id = sectionId.split("#")[0].split(".")[0];
  return GOAL_GROUPS.find((g) => g.ids.some((x) => x === sectionId.split("#")[0] || x.split(".")[0] === id)) ?? null;
}

/**
 * The section that opens a group's page at its top: its first page of settings (Help opens its own page, and the
 * start-up switches have none).
 */
export function entryOf(group: GoalGroup): string {
  return group.ids.find((id) => id !== "help" && !id.startsWith("app.")) ?? group.ids[0];
}
