// FIX-10 (PLAN_V2_1_POLISH): the window is an app, not a web page. In a release build the web view's true leftovers go:
// its right-click menu (Reload, Back, Inspect, Print…) outside text fields and selected text, and its keys for
// reloading, printing the page and the developer tools. Two of those mean something here instead: F5 and ⌘R (Ctrl+R)
// sync now, and ⌘P (Ctrl+P) prints the open transcript (the recording page takes it first). Nothing else is claimed:
// ⌘F and find-next are the app's search, ⌥/Alt and the arrows move words and walk Back and Forward, every ⌘⌃
// combination is the app's (⌃⌘S the sidebar), and on a Mac only ⌘ counts (Ctrl+P, Ctrl+F, Ctrl+H edit text there).
// Answered after every listener of the page and the shell, wherever they listen and whenever they were added: whatever
// they took is left alone. Windows also turns WebView2's own browser keys off at the source (native_feel.rs), with its
// autofill and password saving.

/** What a browser would do with a key press, or null when it isn't one of the browser's keys. */
export type BrowserKey = "reload" | "print" | "devtools" | "navigate";

const isMacPlatform = () => typeof navigator !== "undefined" && /mac/i.test(navigator.platform || navigator.userAgent);

/**
 * The browser's own key for this press (pure). The browser's modifier is ⌘ on a Mac and Ctrl elsewhere; a press with
 * both (⌃⌘S) is never the browser's.
 */
export function browserKey(e: Pick<KeyboardEvent, "key" | "metaKey" | "ctrlKey" | "altKey" | "shiftKey">, mac: boolean = isMacPlatform()): BrowserKey | null {
  const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
  if (e.metaKey && e.ctrlKey) return null;
  const mod = mac ? e.metaKey : e.ctrlKey;
  const other = mac ? e.ctrlKey : e.metaKey;
  if (other) return null;
  if (key === "F5" && !mod && !e.altKey) return "reload";
  if (mod && !e.altKey && key === "r") return "reload";
  if (mod && !e.altKey && !e.shiftKey && key === "p") return "print";
  if (!mac && key === "F12") return "devtools";
  if (mac ? mod && e.altKey && ["i", "j", "c"].includes(key) : mod && e.shiftKey && !e.altKey && ["i", "j", "c"].includes(key)) return "devtools";
  // Windows' own Back and Forward keys (a keyboard's or a mouse's); never Alt and an arrow (the app's Back, a word move).
  if (!mac && (key === "BrowserBack" || key === "BrowserForward")) return "navigate";
  return null;
}

/** What F5 and ⌘R ask of the main window (MainApp syncs the library, as the search's "Sync the library" does). */
export const SYNC_NOW_EVENT = "echoledger:sync-now";

/** A field the person types in: the system's Cut, Copy and Paste menu stays there. */
const editable = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || !!target.closest("input, textarea, select, [contenteditable='true']"));
/** A press on selected text (a transcript's words): the system's menu stays (Look Up, Translate, Copy, Share, Speech). */
function onSelection(target: EventTarget | null, win: Window): boolean {
  const selection = win.getSelection?.();
  if (!selection || selection.isCollapsed || selection.rangeCount === 0 || !(target instanceof Node)) return false;
  for (let i = 0; i < selection.rangeCount; i++) {
    if (selection.getRangeAt(i).intersectsNode(target)) return true;
  }
  return false;
}

/**
 * Puts the app's manners on the window. `release`: a built app (import.meta.env.PROD); a development build keeps the
 * browser's keys and menu (reload, Inspect). `onSyncNow`: what F5 and ⌘R do. Returns what takes it off again.
 */
export function installNativeFeel({ release, onSyncNow, mac = isMacPlatform(), win = window }: {
  release: boolean; onSyncNow: () => void; mac?: boolean; win?: Window;
}): () => void {
  if (!release) return () => undefined;
  // Last of all: the page's and the shell's own listeners (⌘F's search, Back and Forward on ⌘[ ⌘] and Alt+← →) answer
  // first, wherever they listen and whenever they were added (this is installed before the app). A browser key heard
  // in the capture phase gets a listener added at the end of the window's own, for that one press only.
  let waiting: { event: KeyboardEvent; listener: (e: KeyboardEvent) => void } | null = null;
  const settle = () => {
    if (waiting) win.removeEventListener("keydown", waiting.listener);
    waiting = null;
  };
  const onKey = (e: KeyboardEvent) => {
    settle();
    // A text field keeps every key it is given (the title row's search, a note, a rename): never taken from there.
    if (editable(e.target) || !browserKey(e, mac)) return;
    const listener = (last: KeyboardEvent) => {
      if (last !== e) return;
      settle();
      if (last.defaultPrevented) return;
      last.preventDefault();
      if (browserKey(last, mac) === "reload" && !last.shiftKey) onSyncNow();
    };
    waiting = { event: e, listener };
    win.addEventListener("keydown", listener);
  };
  const onMenu = (e: MouseEvent) => {
    if (e.defaultPrevented || editable(e.target) || onSelection(e.target, win)) return;
    e.preventDefault();
  };
  win.addEventListener("keydown", onKey, true);
  win.addEventListener("contextmenu", onMenu);
  return () => {
    settle();
    win.removeEventListener("keydown", onKey, true);
    win.removeEventListener("contextmenu", onMenu);
  };
}
