// UX-22 (PLAN_V2_1_POLISH 3b): a right-click menu, shown each platform's way. On a Mac it is the system's own menu,
// drawn by the core (native_menu_popup, as Record's menu is): Title Case, ticks, one level of sub-menus, hairlines and
// a greyed title line for several ("3 Recordings"), no icons and no shortcuts. Nothing of it is kept on this side but
// what its picks do. On Windows, in the browser and in the tests the menu is ours in Windows 11's style
// (components/Menu.tsx ContextMenu), which the caller shows when popupNative answers false.
//
// Every system menu's pick comes back as `menu:action` (native_menu.rs: "<id>[@<target>][=<value>][~<n>]", a target's
// or value's own "%", "=", "@" and "~" percent-encoded), heard by the window's one listener (menuState.ts), which hands
// every numbered pick to runPicked (MainApp: setPickedHandler(runPicked)). Each of these menus' picks carries the menu's number last ("~<n>",
// read off first): runPicked claims every such pick, running it through the item it came from (whose pick goes through
// the registry) while its menu is the open one, and dropping it once it is not, so a numbered pick never reaches the
// registry by its bare id. A pick with no number (Record's menu, the menu bar) is not caught here.
import { api, type NativeMenuItem } from "../../lib/ipc";
import type { MenuItem } from "../components/Menu";
import { macTitle, nativeItems, type Platform } from "./registry";
import { parsePick, type MenuPick } from "./menuState";

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

/** A numbered pick's menu number ("recording.pin~5" → 5), read off its end; null for a pick with none. */
export function menuNumberOf(picked: string): number | null {
  return parsePick(picked)?.menu ?? null;
}

/**
 * What an item of menu `n` comes back as, read (menuState.ts parsePick decodes what native_menu.rs encoded): its action,
 * its choice and the menu's number, so a choice holding "=" or ending in "~12" is matched as itself.
 */
const pickOf = (action: string, choice: string | undefined, n: number) => JSON.stringify([action, choice ?? null, n]);
const keyOf = (pick: MenuPick) => JSON.stringify([pick.id, pick.value ?? null, pick.menu ?? null]);

/** Each item's pick, by what it comes back as: its action, its choice, and this menu's number. */
function collectPicks(items: readonly MenuItem[], n: number, into: Map<string, () => void>): void {
  for (const item of items) {
    if (item.items?.length) collectPicks(item.items, n, into);
    else if (item.action && item.onPick) into.set(pickOf(item.action, item.choice, n), item.onPick);
  }
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
  collectPicks(items, n, into);
  picks = into;
  try {
    const lines: NativeMenuItem[] = nativeItems(items, title ? appWords(title, "macos") : undefined);
    await api.nativeMenuPopup(lines, at.x, at.y, n);
    // The pick may come just after the menu closes; a moment later this menu's picks are let go.
    forget = window.setTimeout(() => { picks = new Map(); }, 1000);
    return true;
  } catch {
    forgetPicks();
    return false;
  }
}

/**
 * A pick from a system menu: one of these menus' (it carries "~<n>") is claimed (true), run while its menu is the open
 * one and dropped once it is not (let go, or an earlier menu's); one with no number is another menu's (false).
 */
export function runPicked(picked: string): boolean {
  const pick = parsePick(picked);
  if (!pick || pick.menu === undefined) return false;
  const run = picks.get(keyOf(pick));
  if (run) {
    forgetPicks();
    run();
  }
  return true;
}
