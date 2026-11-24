// UX-20: a double click on the title row's empty parts (its drag regions) does what the Mac's own setting says, in
// System Settings › Desktop & Dock › "Double-click a window's title bar to": zoom, minimize or nothing. Tauri's drag
// script (tauri/src/window/scripts/drag.js) always zooms on a Mac, on the second click's release when the pointer did
// not move; this listens first (the capture phase, on the window), does the same checks, stops Tauri's and asks the
// core (api.titleDoubleClick), which reads the setting. Off a Mac, Tauri's own double click stays (Windows maximizes).
import { api } from "./ipc";

const CLICKABLE = new Set(["A", "BUTTON", "INPUT", "SELECT", "TEXTAREA", "LABEL", "SUMMARY"]);
const ROLES = new Set(["button", "link", "menuitem", "tab", "checkbox", "radio", "switch", "option"]);
const clickable = (el: HTMLElement) => CLICKABLE.has(el.tagName)
  || (el.hasAttribute("contenteditable") && el.getAttribute("contenteditable") !== "false")
  || (el.hasAttribute("tabindex") && el.getAttribute("tabindex") !== "-1")
  || ROLES.has(el.getAttribute("role") ?? "");

/** Whether a press on this path drags the window: Tauri's own rule (drag.js isDragRegion), the same values. */
export function isDragRegion(path: readonly EventTarget[]): boolean {
  for (const el of path) {
    if (!(el instanceof HTMLElement)) continue;
    const attr = el.getAttribute("data-tauri-drag-region");
    if (clickable(el) && attr === null) return false;
    if (attr === null) continue;
    if (attr === "false") return false;
    if (attr === "deep") return true;
    if (attr === "" || attr === "true") return el === path[0];
  }
  return false;
}

/** Watches the window's double clicks on its drag regions (a Mac only). Returns what stops watching. */
export function watchTitleDoubleClick(platform: string | null | undefined, run: () => Promise<unknown> = () => api.titleDoubleClick(),
  win: Window = window): () => void {
  if (platform !== "macos") return () => undefined;
  let at: { x: number; y: number } | null = null;
  const down = (e: MouseEvent) => {
    if (e.button !== 0 || e.detail !== 2 || !isDragRegion(e.composedPath())) return;
    at = { x: e.clientX, y: e.clientY };
    e.stopImmediatePropagation();
  };
  const up = (e: MouseEvent) => {
    if (e.button !== 0 || e.detail !== 2 || !at) return;
    const still = e.clientX === at.x && e.clientY === at.y;
    at = null;
    if (!still || !isDragRegion(e.composedPath())) return;
    e.stopImmediatePropagation();
    void run().catch(() => undefined);
  };
  win.addEventListener("mousedown", down, true);
  win.addEventListener("mouseup", up, true);
  return () => {
    win.removeEventListener("mousedown", down, true);
    win.removeEventListener("mouseup", up, true);
  };
}
