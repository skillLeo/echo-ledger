// The app shell (v1.6, P4): the sidebar's width, style and open groups, the Preferences kept on this computer, "Find a
// page", and the keys that move through a list. Kept in this computer's storage like the theme (never sent anywhere).
// Pure apart from storage; unit-tested.
import { setClock24 } from "./format";

export const SIDEBAR_MIN = 200;
export const SIDEBAR_MAX = 320;
export const SIDEBAR_DEFAULT = 240;
/** The icon rail's width when the sidebar is collapsed. */
export const SIDEBAR_RAIL = 64;

/** Under this page width (window minus sidebar) the Library's list and recording stack and the page scrolls. */
export const NARROW_CONTENT = 798;
/** Under this page width the Library's and Projects' nested column is left out (the sidebar lists the same). */
export const COLUMN_CONTENT = 1040;

const WIDTH_KEY = "echoledger-sidebar-width";
const COLLAPSED_KEY = "echoledger-sidebar";
const GROUPS_KEY = "echoledger-sidebar-open";
const PREFS_KEY = "echoledger-preferences";

function read(key: string): string | null {
  try {
    return window.localStorage.getItem(key);
  } catch {
    return null;
  }
}
function write(key: string, value: string): void {
  try {
    window.localStorage.setItem(key, value);
  } catch {
    // Storage can be unavailable; the choice then lasts until the app restarts.
  }
}

/** A width the sidebar may take: 200 to 320 px, whole pixels; anything else is the default 240. */
export function clampSidebar(width: number): number {
  if (!Number.isFinite(width)) return SIDEBAR_DEFAULT;
  return Math.round(Math.min(SIDEBAR_MAX, Math.max(SIDEBAR_MIN, width)));
}
export function loadSidebarWidth(): number {
  const saved = Number(read(WIDTH_KEY));
  return read(WIDTH_KEY) === null || !Number.isFinite(saved) ? SIDEBAR_DEFAULT : clampSidebar(saved);
}
export function saveSidebarWidth(width: number): void {
  write(WIDTH_KEY, String(clampSidebar(width)));
}
export function loadCollapsed(): boolean {
  return read(COLLAPSED_KEY) === "collapsed";
}
export function saveCollapsed(collapsed: boolean): void {
  write(COLLAPSED_KEY, collapsed ? "collapsed" : "expanded");
}

/** The sidebar groups that show their pages (Library's smart folders, pinned projects, Shared): Projects and Shared open at first. */
export type SidebarGroup = "library" | "projects" | "shared";
const DEFAULT_OPEN: readonly SidebarGroup[] = ["projects", "shared"];
export function loadOpenGroups(): Set<SidebarGroup> {
  try {
    const saved = JSON.parse(read(GROUPS_KEY) ?? "null");
    if (Array.isArray(saved)) return new Set(saved.filter((g): g is SidebarGroup => g === "library" || g === "projects" || g === "shared"));
  } catch {
    // Not ours: the default.
  }
  return new Set(DEFAULT_OPEN);
}
export function saveOpenGroups(open: ReadonlySet<SidebarGroup>): void {
  write(GROUPS_KEY, JSON.stringify([...open]));
}

/** The sidebar's look (Account → Preferences): its own colour whatever the theme, like the reference's three options. */
export type SidebarStyle = "light" | "dark" | "grey";
export const SIDEBAR_STYLES: readonly (readonly [SidebarStyle, string])[] = [["light", "Light"], ["dark", "Dark"], ["grey", "Soft grey"]];
export type Density = "comfortable" | "compact";
export const DENSITIES: readonly (readonly [Density, string])[] = [["comfortable", "Comfortable"], ["compact", "Compact"]];
export type Clock = "12" | "24";
export const CLOCKS: readonly (readonly [Clock, string])[] = [["12", "12-hour (2:30 PM)"], ["24", "24-hour (14:30)"]];

export interface Preferences {
  sidebarStyle: SidebarStyle;
  density: Density;
  clock: Clock;
}
export const DEFAULT_PREFERENCES: Preferences = { sidebarStyle: "light", density: "comfortable", clock: "12" };

/** The saved preferences; anything missing or not one of the choices is the default. */
export function parsePreferences(text: string | null | undefined): Preferences {
  let value: Partial<Record<keyof Preferences, unknown>> = {};
  try {
    const parsed = JSON.parse(text ?? "null");
    if (parsed && typeof parsed === "object") value = parsed;
  } catch {
    // Not ours: the defaults.
  }
  const pick = <T extends string>(v: unknown, choices: readonly (readonly [T, string])[], fallback: T): T =>
    choices.some(([c]) => c === v) ? (v as T) : fallback;
  return {
    sidebarStyle: pick(value.sidebarStyle, SIDEBAR_STYLES, DEFAULT_PREFERENCES.sidebarStyle),
    density: pick(value.density, DENSITIES, DEFAULT_PREFERENCES.density),
    clock: pick(value.clock, CLOCKS, DEFAULT_PREFERENCES.clock),
  };
}
export function loadPreferences(): Preferences {
  return parsePreferences(read(PREFS_KEY));
}
/** Saves them and applies them to this window: the sidebar style and density on <html>, the clock to every time shown. */
export function savePreferences(prefs: Preferences): void {
  write(PREFS_KEY, JSON.stringify(prefs));
  applyPreferences(prefs);
}
export function applyPreferences(prefs: Preferences): void {
  const root = document.documentElement;
  root.dataset.sidebar = prefs.sidebarStyle;
  root.dataset.density = prefs.density;
  setClock24(prefs.clock === "24");
}
/** Event the shell sends when Preferences change, so every part follows at once. */
export const PREFERENCES_EVENT = "echoledger:preferences";

/** A page "Find a page" can open: its name, where it sits ("Library", "Account") and the words it answers to. */
export interface PageEntry {
  id: string;
  label: string;
  /** The group it is in, shown after the name. */
  group?: string;
  keywords?: string;
}

/** The pages whose name, group or keywords hold every word typed (capitals ignored), in the order given. */
export function findPages<T extends PageEntry>(pages: readonly T[], query: string): T[] {
  const words = query.toLowerCase().split(/\s+/).filter(Boolean);
  if (words.length === 0) return [];
  return pages.filter((p) => {
    const text = `${p.label} ${p.group ?? ""} ${p.keywords ?? ""}`.toLowerCase();
    return words.every((w) => text.includes(w));
  });
}

/** What a key does in a list (J/K move, Enter opens, X selects, Esc closes), or null when it is not one of them. */
export type ListKey = "next" | "previous" | "open" | "pick" | "close";
export function listKey(e: Pick<KeyboardEvent, "key" | "ctrlKey" | "metaKey" | "altKey" | "shiftKey">): ListKey | null {
  if (e.ctrlKey || e.metaKey || e.altKey) return null;
  switch (e.key) {
    case "j":
    case "J":
      return e.shiftKey ? null : "next";
    case "k":
    case "K":
      return e.shiftKey ? null : "previous";
    case "Enter":
      return "open";
    case "x":
    case "X":
      return e.shiftKey ? null : "pick";
    case "Escape":
      return "close";
    default:
      return null;
  }
}

/** The row a move lands on: the next or previous one, staying at the ends; the first (or last) when none is current. */
export function stepIndex(current: number, length: number, delta: 1 | -1): number {
  if (length <= 0) return -1;
  if (current < 0 || current >= length) return delta > 0 ? 0 : length - 1;
  return Math.min(length - 1, Math.max(0, current + delta));
}

/** "Saved · just now", "Saved · 3 min ago", "Saved · 2 h ago" for a save at `at` (ms). */
export function savedLine(at: number, now: number = Date.now()): string {
  const seconds = Math.max(0, Math.round((now - at) / 1000));
  if (seconds < 45) return "Saved · just now";
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `Saved · ${minutes} min ago`;
  return `Saved · ${Math.round(minutes / 60)} h ago`;
}

/** Initials for the avatar: the first letters of the first two words of a name ("Sara Ahmed" → "SA"), else "?". */
export function initials(name: string | null | undefined): string {
  const words = (name ?? "").trim().split(/\s+/).filter(Boolean);
  const letters = words.slice(0, 2).map((w) => [...w][0]?.toUpperCase() ?? "");
  return letters.join("") || "?";
}

/** "Ctrl+K" on Windows, "⌘K" on a Mac. */
export function paletteKeys(platform: string): string {
  return platform === "macos" ? "⌘K" : "Ctrl+K";
}
