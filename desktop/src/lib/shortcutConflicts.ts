// Shortcut conflict detection (plan B19): global shortcuts that many browsers or the system already use. A global
// shortcut wins over them, so the app or browser loses that key; EchoLedger warns before that surprises anyone.

type Platform = "windows" | "macos" | string;

const COMMON: Record<string, string> = {
  "Ctrl+Shift+C": "opens the element picker (DevTools) in browsers",
  "Ctrl+Shift+I": "opens DevTools in browsers",
  "Ctrl+Shift+J": "opens the browser console",
  "Ctrl+Shift+R": "hard-reloads the page in browsers",
  "Ctrl+Shift+N": "opens a private window in browsers",
  "Ctrl+Shift+T": "reopens the last closed tab in browsers",
  "Ctrl+Shift+W": "closes the window in browsers",
  "Ctrl+Shift+V": "pastes without formatting in many apps",
  "Ctrl+Shift+S": "saves as in many apps",
  "Ctrl+Shift+Delete": "clears browsing data in browsers",
};
const WINDOWS: Record<string, string> = {
  "Ctrl+Shift+Escape": "opens Task Manager",
  "Ctrl+Alt+Delete": "opens the Windows security screen",
  "Alt+F4": "closes the active window",
  "Alt+Tab": "switches windows",
  "Ctrl+Escape": "opens the Start menu",
};
const MACOS: Record<string, string> = {
  "Cmd+Space": "opens Spotlight",
  "Cmd+Tab": "switches apps",
  "Cmd+Q": "quits the active app",
  "Cmd+W": "closes the window",
  "Cmd+Shift+3": "takes a screenshot",
  "Cmd+Shift+4": "takes a screenshot of a selection",
  "Cmd+Shift+5": "opens the screenshot tools",
  "Ctrl+Space": "switches the input source",
  "Cmd+Shift+C": "opens the element picker (DevTools) in browsers",
  "Cmd+Shift+R": "hard-reloads the page in browsers",
  "Cmd+Shift+N": "opens a private window in browsers",
  "Cmd+Shift+T": "reopens the last closed tab in browsers",
};

/** "CommandOrControl+Shift+c" → "Ctrl+Shift+C" (or Cmd on macOS), in a fixed modifier order. */
export function normaliseAccelerator(accelerator: string, platform: Platform): string {
  const parts = accelerator.split("+").map((p) => p.trim()).filter(Boolean);
  const mods = new Set<string>();
  let key = "";
  for (const part of parts) {
    const p = part.toLowerCase();
    if (p === "commandorcontrol" || p === "cmdorctrl") mods.add(platform === "macos" ? "Cmd" : "Ctrl");
    else if (p === "control" || p === "ctrl") mods.add("Ctrl");
    else if (p === "command" || p === "cmd" || p === "super" || p === "meta") mods.add("Cmd");
    else if (p === "alt" || p === "option") mods.add("Alt");
    else if (p === "shift") mods.add("Shift");
    else key = p === "esc" ? "Escape" : p.length === 1 ? p.toUpperCase() : part.charAt(0).toUpperCase() + part.slice(1);
  }
  return [...["Cmd", "Ctrl", "Alt", "Shift"].filter((m) => mods.has(m)), key].filter(Boolean).join("+");
}

/** What a shortcut already does elsewhere, or null when it is not a known one. */
export function conflictFor(accelerator: string | undefined, platform: Platform): string | null {
  if (!accelerator) return null;
  const key = normaliseAccelerator(accelerator, platform);
  const table = { ...COMMON, ...(platform === "macos" ? MACOS : WINDOWS) };
  return table[key] ?? null;
}
