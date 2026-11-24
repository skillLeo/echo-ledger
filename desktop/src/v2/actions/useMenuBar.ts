// The Mac menu bar, kept in step with the app (FIX-05): drawn from the action list and the menus' state whenever either
// changes (a command registered or taken back, the sidebar hidden, a recording started, a field focused), and every
// pick from a menu the system drew runs through the registry. Windows has no menu bar: only the picks are listened for.
import { useEffect, useSyncExternalStore } from "react";
import { api } from "../../lib/ipc";
import { handlersVersion, watchHandlers } from "./registry";
import { menuBarModel } from "./menuBar";
import { listenToMenuPicks, setMenuPart, useMenuSnapshot } from "./menuState";

/** A field being typed in: Space, ⌘← and ⌘→ keep their text meaning (greyed in the menu). */
const typingIn = (el: Element | null) =>
  el instanceof HTMLElement && (el.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(el.tagName));

/** How long the menu bar waits for the state to settle before it is drawn again. */
export const MENU_BAR_SETTLE_MS = 50;

export function useMenuBar(platform: string | null | undefined) {
  const mac = platform === "macos";
  useEffect(() => listenToMenuPicks(mac ? "macos" : "windows"), [mac]);
  useEffect(() => {
    const follow = () => setMenuPart("typing", { state: { typing: typingIn(document.activeElement) } });
    const later = () => window.setTimeout(follow, 0);
    follow();
    document.addEventListener("focusin", follow);
    document.addEventListener("focusout", later);
    return () => {
      document.removeEventListener("focusin", follow);
      document.removeEventListener("focusout", later);
      setMenuPart("typing", null);
    };
  }, []);
  const version = useSyncExternalStore(watchHandlers, handlersVersion, handlersVersion);
  const now = useMenuSnapshot();
  const bar = mac ? JSON.stringify(menuBarModel({ platform: "macos", state: now.state, targets: now.targets }, now.choices)) : null;
  useEffect(() => {
    if (!bar) return;
    const timer = window.setTimeout(() => void api.appMenuSet(JSON.parse(bar)).catch(() => undefined), MENU_BAR_SETTLE_MS);
    return () => window.clearTimeout(timer);
  }, [bar, version]);
}
