// UX-22 (PLAN_V2_1_POLISH 3b): a right-click menu, shown each platform's way. On a Mac it is the system's own menu
// (Tauri's menu popup), so it looks and behaves like Finder's: no icons, no shortcuts, ticks and one level of sub-menus,
// hairlines between groups, and a greyed title line when several things are chosen ("3 recordings"). On Windows, in the
// browser and in the tests it is our own menu in Windows 11's style (components/Menu.tsx ContextMenu), which the caller
// shows when this answers false.
import type { MenuItem } from "../components/Menu";
import type { Platform } from "./registry";

/** The platform a menu is for: a Mac's system menus, else Windows's. */
export function currentPlatform(): Platform {
  return typeof navigator !== "undefined" && /Mac/i.test(navigator.platform) ? "macos" : "windows";
}

type NativeItem =
  | { item: "Separator" }
  | { text: string; enabled: boolean; action?: () => void }
  | { text: string; enabled: boolean; checked: boolean; action?: () => void }
  | { text: string; enabled: boolean; items: NativeItem[] };

/**
 * Our items as the system menu's (pure): words, ticks, one level of sub-menus and hairlines (from `separator`, or where
 * one group ends and the next begins); no icons, shortcuts or colours, as a Mac's right-click menus have none. A title
 * goes first, greyed, with a hairline under it.
 */
export function nativeItems(items: readonly MenuItem[], title?: string, depth = 0): NativeItem[] {
  const out: NativeItem[] = [];
  if (title) out.push({ text: title, enabled: false }, { item: "Separator" });
  let group: string | undefined;
  items.forEach((item, at) => {
    const apart = at > 0 && (item.separator || (item.group !== undefined && item.group !== group));
    group = item.group;
    if (apart && out.length > 0 && !("item" in out[out.length - 1])) out.push({ item: "Separator" });
    const enabled = !item.disabled;
    if (item.items?.length) {
      // One level deep (3b): a sub-menu's own sub-menus are left out.
      if (depth === 0) out.push({ text: item.label, enabled, items: nativeItems(item.items, undefined, depth + 1) });
      return;
    }
    const action = item.onPick ? () => item.onPick?.() : undefined;
    out.push(item.checked !== undefined ? { text: item.label, enabled, checked: !!item.checked, action } : { text: item.label, enabled, action });
  });
  return out;
}

/**
 * Shows the system's menu at a point of the window (a Mac). False when it can't (not a Mac, not in the app, or the
 * system said no): the caller shows ours instead.
 */
export async function popupNative(items: readonly MenuItem[], at: { x: number; y: number }, title?: string): Promise<boolean> {
  if (currentPlatform() !== "macos" || !items.length) return false;
  try {
    const [{ Menu }, { LogicalPosition }] = await Promise.all([import("@tauri-apps/api/menu"), import("@tauri-apps/api/dpi")]);
    const menu = await Menu.new({ items: nativeItems(items, title) as never });
    await menu.popup(new LogicalPosition(at.x, at.y));
    return true;
  } catch {
    return false;
  }
}
