// FIX-10 (PLAN_V2_1_POLISH): the window is an app, not a web page. In a release build the web view's own leftovers go:
// its right-click menu (Reload, Back, Inspect, Save as, Print, Search with…) outside text fields, and its keys for
// reload, printing the page, the find bar, saving, the source, downloads, history, page navigation and the developer
// tools. Three of those keys mean something here instead: F5 and ⌘R (Ctrl+R) sync now, ⌘P (Ctrl+P) prints the open
// transcript (the recording page takes it first), and ⌘F (Ctrl+F) is the app's own search (the shell and the Library
// take it first). Listened for on the window, after the page's own listeners: whatever a page took is left alone.
// Windows also turns WebView2's browser keys, autofill and password saving off at the source (native_feel.rs).

/** What a browser would do with a key press, or null when it isn't one of the browser's keys. */
export type BrowserKey = "reload" | "print" | "find" | "save" | "source" | "downloads" | "history" | "devtools" | "navigate";

/** The browser's own key for this press (pure): ⌘ on a Mac and Ctrl elsewhere both count, as both reach the web view. */
export function browserKey(e: Pick<KeyboardEvent, "key" | "metaKey" | "ctrlKey" | "altKey" | "shiftKey">): BrowserKey | null {
  const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
  const mod = e.metaKey || e.ctrlKey;
  if (key === "F5" || (mod && !e.altKey && key === "r")) return "reload";
  if (key === "F12" || (mod && e.shiftKey && ["i", "j", "c"].includes(key)) || (e.metaKey && e.altKey && ["i", "j", "c"].includes(key))) return "devtools";
  if (key === "F3" || (mod && !e.altKey && (key === "f" || key === "g"))) return "find";
  if (mod && !e.altKey && !e.shiftKey && key === "p") return "print";
  if (mod && !e.altKey && !e.shiftKey && key === "s") return "save";
  if (mod && !e.altKey && !e.shiftKey && key === "u") return "source";
  if (e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey && key === "j") return "downloads";
  if (e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey && key === "h") return "history";
  if (key === "BrowserBack" || key === "BrowserForward" || (e.altKey && !mod && (key === "ArrowLeft" || key === "ArrowRight"))) return "navigate";
  return null;
}

/** What F5 and ⌘R ask of the main window (MainApp syncs the library, as the search's "Sync the library" does). */
export const SYNC_NOW_EVENT = "echoledger:sync-now";

/** A field the person types in: the system's Cut, Copy and Paste menu stays there. */
const editable = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || !!target.closest("input, textarea, select, [contenteditable='true']"));

/**
 * Puts the app's manners on the window. `release`: a built app (import.meta.env.PROD); a development build keeps the
 * browser's keys and menu (reload, Inspect). `onSyncNow`: what F5 and ⌘R do. Returns what takes it off again.
 */
export function installNativeFeel({ release, onSyncNow, win = window }: { release: boolean; onSyncNow: () => void; win?: Window }): () => void {
  if (!release) return () => undefined;
  const onKey = (e: KeyboardEvent) => {
    if (e.defaultPrevented) return;
    const what = browserKey(e);
    if (!what) return;
    e.preventDefault();
    if (what === "reload" && !e.shiftKey) onSyncNow();
  };
  const onMenu = (e: MouseEvent) => {
    if (e.defaultPrevented || editable(e.target)) return;
    e.preventDefault();
  };
  win.addEventListener("keydown", onKey);
  win.addEventListener("contextmenu", onMenu);
  return () => {
    win.removeEventListener("keydown", onKey);
    win.removeEventListener("contextmenu", onMenu);
  };
}
