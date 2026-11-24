// UX-22 (PLAN_V2_1_POLISH 3b): a right-click menu, shown each platform's way. On a Mac it is the system's own menu,
// drawn by the core (native_menu_popup, as Record's menu is): Title Case, ticks, one level of sub-menus, hairlines and
// a greyed title line for several ("3 Recordings"), no icons and no shortcuts. Nothing of it is kept on this side but
// what its picks do. On Windows, in the browser and in the tests the menu is ours in Windows 11's style
// (components/Menu.tsx ContextMenu), which the caller shows when popupNative answers false.
//
// Every system menu's pick comes back as `menu:action` (native_menu.rs: "<id>", or "<id>=<value>" for a choice), heard by
// the window's one listener, which offers it to runPicked first. One of these menus' picks carries the menu's number in
// its value ("<id>=<value>#<n>") and runs through the item it came from (whose pick goes through the registry); any other
// (Record's menu, the menu bar, or an earlier menu's) is not caught here.
import { api, type NativeMenuItem } from "../../lib/ipc";
import type { MenuItem } from "../components/Menu";
import { macTitle, nativeItems, type Platform } from "./registry";

/** The platform a menu is for: a Mac's system menus, else Windows's. */
export function currentPlatform(): Platform {
  return typeof navigator !== "undefined" && /Mac/i.test(navigator.platform) ? "macos" : "windows";
}

/** An app's own words in a menu (a title line, "No project", a sort order, a format), in the platform's case. */
export const appWords = (words: string, platform: Platform = currentPlatform()) => (platform === "macos" ? macTitle(words) : words);

/** What the open system menu's picks do (one menu at a time), by what each comes back as. */
let picks = new Map<string, () => void>();
let menuNumber = 0;
let forget = 0;
/** Lets this side's menu go (its picks are no longer this menu's). */
export function forgetPicks(): void {
  window.clearTimeout(forget);
  picks = new Map();
}

/** Each item's pick, by what it comes back as: its action, its choice, and this menu's number. */
function withNumber(items: readonly MenuItem[], n: number, into: Map<string, () => void>): MenuItem[] {
  return items.map((item) => {
    if (item.items?.length) return { ...item, items: withNumber(item.items, n, into) };
    if (!item.action || !item.onPick) return item;
    const value = `${item.choice ?? ""}#${n}`;
    into.set(`${item.action}=${value}`, item.onPick);
    return { ...item, choice: value };
  });
}

/**
 * Shows the system's menu at a point of the window (a Mac). False when it can't (not a Mac, not in the app, or the
 * system said no): the caller shows ours instead.
 */
export async function popupNative(items: readonly MenuItem[], at: { x: number; y: number }, title?: string): Promise<boolean> {
  if (currentPlatform() !== "macos" || !items.length) return false;
  forgetPicks();
  const n = ++menuNumber;
  const into = new Map<string, () => void>();
  const numbered = withNumber(items, n, into);
  picks = into;
  try {
    const lines: NativeMenuItem[] = nativeItems(numbered, title ? appWords(title, "macos") : undefined);
    await api.nativeMenuPopup(lines, at.x, at.y);
    // The pick may come just after the menu closes; a moment later this menu's picks are let go.
    forget = window.setTimeout(() => { picks = new Map(); }, 1000);
    return true;
  } catch {
    forgetPicks();
    return false;
  }
}

/** A pick from a system menu one of these showed: runs it (true), else false (another menu's, or let go). */
export function runPicked(picked: string): boolean {
  const run = picks.get(picked);
  if (!run) return false;
  forgetPicks();
  run();
  return true;
}
