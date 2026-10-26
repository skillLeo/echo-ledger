// C9: the main window is hidden, not closed, when the user closes it (it lives in the tray). WebView2 is not told,
// so timers keep running; the core announces "main:visibility" and the polling views stop while it is hidden.
import { useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { getCurrentWindow } from "@tauri-apps/api/window";

export const VISIBILITY_EVENT = "main:visibility";

/** Whether the main window is on screen (true outside Tauri, so the views behave as before in a browser). */
export function useWindowShown(): boolean {
  const [shown, setShown] = useState(true);
  useEffect(() => {
    let alive = true;
    try {
      getCurrentWindow()
        .isVisible()
        .then((v) => alive && setShown(v))
        .catch(() => undefined);
    } catch {
      // plain browser or tests: no window to ask
    }
    const un = listen<boolean>(VISIBILITY_EVENT, (e) => setShown(e.payload !== false));
    return () => {
      alive = false;
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return shown;
}