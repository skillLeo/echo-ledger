// C9: the views that poll stop while the main window is off screen. Closing the window destroys it (an idle app runs
// no web view), and a new one is built hidden and shown as soon as its page is ready, so the page starts as shown;
// the core announces "main:visibility" when that changes (WebView2 is not told, so timers would keep running).
import { useEffect, useState } from "react";
import { listen } from "@tauri-apps/api/event";

export const VISIBILITY_EVENT = "main:visibility";

/** Whether the main window is on screen (true until the core says otherwise, also outside Tauri). */
export function useWindowShown(): boolean {
  const [shown, setShown] = useState(true);
  useEffect(() => {
    const un = listen<boolean>(VISIBILITY_EVENT, (e) => setShown(e.payload !== false));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return shown;
}
