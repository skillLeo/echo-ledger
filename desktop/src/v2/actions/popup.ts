// UX-22 (PLAN_V2_1_POLISH 3b): a right-click menu, shown each platform's way. On a Mac it is the system's own menu,
// drawn by the core (native_menu_popup, as Record's menu is): Title Case, ticks, one level of sub-menus, hairlines and
// a greyed title line for several ("3 Recordings"), no icons and no shortcuts. Nothing of it is kept on this side but
// what its picks do: a pick comes back as `menu:action` ("<id>" or "<id>=<value>"), and runPicked runs it through the
// item it came from (whose pick goes through the registry). On Windows, in the browser and in the tests the menu is ours
// in Windows 11's style (components/Menu.tsx ContextMenu), which the caller shows when popupNative answers false.
import { api } from "../../lib/ipc";
import type { MenuItem } from "../components/Menu";
import { nativeItems, type Platform } from "./registry";

/** The platform a menu is for: a Mac's system menus, else Windows's. */
export function currentPlatform(): Platform {
  return typeof navigator !== "undefined" && /Mac/i.test(navigator.platform) ? "macos" : "windows";
}

/** What the open system menu's picks do: one menu at a time, forgotten after a pick or when the next one opens. */
let picks = new Map<string, () => void>();
let forget = 0;

/** Each item's pick, by what it comes back as: its action, or its action and choice. */
function picksOf(items: readonly MenuItem[], into = new Map<string, () => void>()): Map<string, () => void> {
  for (const item of items) {
    if (item.items?.length) picksOf(item.items, into);
    else if (item.action && item.onPick) into.set(item.choice !== undefined ? `${item.action}=${item.choice}` : item.action, item.onPick);
  }
  return into;
}

/**
 * Shows the system's menu at a point of the window (a Mac). False when it can't (not a Mac, not in the app, or the
 * system said no): the caller shows ours instead.
 */
export async function popupNative(items: readonly MenuItem[], at: { x: number; y: number }, title?: string): Promise<boolean> {
  if (currentPlatform() !== "macos" || !items.length) return false;
  window.clearTimeout(forget);
  picks = picksOf(items);
  try {
    await api.nativeMenuPopup(nativeItems(items, title), at.x, at.y);
    // The pick may come just after the menu closes; a moment later this menu's picks are let go.
    forget = window.setTimeout(() => { picks = new Map(); }, 1000);
    return true;
  } catch {
    picks = new Map();
    return false;
  }
}

/** A pick from a system menu one of these showed: runs it (true), else leaves it to the caller (Record's menu). */
export function runPicked(picked: string): boolean {
  const run = picks.get(picked);
  if (!run) return false;
  picks = new Map();
  run();
  return true;
}
