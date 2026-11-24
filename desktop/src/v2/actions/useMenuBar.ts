// The Mac menu bar, kept in step with the app (FIX-05): drawn from the action list and the menus' state whenever either
// changes (a command registered or taken back, the sidebar hidden, a recording started, a field focused). Only with the
// new design's shell (ui_v2.shell): classic keeps the core's own bar (app_menu.rs build). A leaf of its own
// (<MenuBar />, rendering nothing), so a focus change or a command registered redraws it and nothing else. The picks
// are heard by the window's one listener (menuState.ts), whatever draws the bar. Windows has no menu bar.
import { useEffect, useSyncExternalStore } from "react";
import { api } from "../../lib/ipc";
import { handlersVersion, watchHandlers } from "./registry";
import { menuBarModel } from "./menuBar";
import { setMenuPart, useMenuSnapshot } from "./menuState";

/** A field being typed in: Space, ⌘← and ⌘→ keep their text meaning (greyed in the menu). */
const typingIn = (el: Element | null) =>
  el instanceof HTMLElement && (el.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(el.tagName));

/** How long the menu bar waits for the state to settle before it is drawn again. */
export const MENU_BAR_SETTLE_MS = 50;

/** Classic's bar comes back a moment after the last <MenuBar /> goes, so moving between screens never flickers it. */
let backToClassic: number | undefined;

export function useMenuBar(platform: string | null | undefined) {
  const mac = platform === "macos";
  useEffect(() => {
    if (!mac) return;
    let typing: boolean | null = null;
    const follow = () => {
      const now = typingIn(document.activeElement);
      if (now === typing) return;
      typing = now;
      setMenuPart("typing", { state: { typing: now } });
    };
    const later = () => window.setTimeout(follow, 0);
    follow();
    document.addEventListener("focusin", follow);
    document.addEventListener("focusout", later);
    return () => {
      document.removeEventListener("focusin", follow);
      document.removeEventListener("focusout", later);
      setMenuPart("typing", null);
    };
  }, [mac]);
  // A command registered or taken back changes what is greyed: read again then.
  useSyncExternalStore(watchHandlers, handlersVersion, handlersVersion);
  const now = useMenuSnapshot();
  // Greyed and run as the focused page's selection, of its kind (the Library's Pin, Labels, Move… are a recording's).
  const bar = mac ? JSON.stringify(menuBarModel({ platform: "macos", state: now.state, targets: now.targets }, now.choices, now.kinds)) : null;
  useEffect(() => {
    if (!bar) return;
    window.clearTimeout(backToClassic);
    // The core updates the bar in place when only words, greying or ticks changed (app_menu.rs).
    const timer = window.setTimeout(() => void api.appMenuSet(JSON.parse(bar)).catch(() => undefined), MENU_BAR_SETTLE_MS);
    return () => window.clearTimeout(timer);
  }, [bar]);
  useEffect(() => {
    if (!mac) return;
    window.clearTimeout(backToClassic);
    return () => {
      backToClassic = window.setTimeout(() => void api.appMenuSet(null).catch(() => undefined), MENU_BAR_SETTLE_MS);
    };
  }, [mac]);
}

/** The menu bar's leaf: rendered by MainApp on a Mac with the new design's shell, in every screen; it draws nothing. */
export function MenuBar({ platform }: { platform: string | null | undefined }): null {
  useMenuBar(platform);
  return null;
}
