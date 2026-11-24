// Zoom, like Chrome (UX-07, PLAN_V2_1_POLISH; behind v21_zoom). The main window's web view zooms in steps (67% to 200%,
// 100% the default after DES-03); the floating windows (the pill, the after-call card, captions, the Live Assist card,
// Live Translate) keep their own sizes. It is kept on this computer, not synced (a laptop and a big monitor want
// different sizes), and applied before the window first shows (main.tsx), so it doesn't jump.
//   ⌘+ (and ⌘=), ⌘−, ⌘0; Ctrl on Windows; the number pad's keys too. Ctrl + the mouse wheel: a step a notch. A
//   touchpad's pinch builds up and steps about every 100 px, so it doesn't jump.
import { useSyncExternalStore } from "react";
import { getCurrentWebview } from "@tauri-apps/api/webview";

/** Chrome's steps, in percent. */
export const ZOOM_STEPS = [67, 75, 80, 90, 100, 110, 125, 150, 175, 200] as const;
export const ZOOM_DEFAULT = 100;
/** Kept on this computer (never synced). */
export const ZOOM_KEY = "echoledger-zoom";
/** Sent on the window when the zoom changes, for the parts that show it. */
export const ZOOM_EVENT = "echoledger:zoom";
/** A pinch (or a wheel's small moves) this far, in pixels, is one step. */
export const PINCH_STEP = 100;

/** The step `dir` steps from `now` (pure): the next one up or down, never past the ends; 0 goes back to 100%. */
export function stepZoom(now: number, dir: 1 | -1 | 0): number {
  if (dir === 0) return ZOOM_DEFAULT;
  const steps = [...ZOOM_STEPS];
  if (dir > 0) return steps.find((s) => s > now) ?? steps[steps.length - 1];
  return [...steps].reverse().find((s) => s < now) ?? steps[0];
}

/** A kept zoom (pure): one of the steps, else 100%. */
export function parseZoom(text: string | null | undefined): number {
  const n = Number(text);
  return (ZOOM_STEPS as readonly number[]).includes(n) ? n : ZOOM_DEFAULT;
}

/** What a key asks of the zoom (pure): in, out, back to 100%, or nothing. The window's modifier: ⌘ on a Mac, Ctrl else. */
export function zoomKey(e: Pick<KeyboardEvent, "key" | "code" | "metaKey" | "ctrlKey" | "altKey">, mac: boolean): 1 | -1 | 0 | null {
  if (e.altKey || (mac ? !e.metaKey || e.ctrlKey : !e.ctrlKey || e.metaKey)) return null;
  if (e.key === "+" || e.key === "=" || e.code === "NumpadAdd") return 1;
  if (e.key === "-" || e.key === "_" || e.key === "−" || e.code === "NumpadSubtract") return -1;
  if (e.key === "0" || e.code === "Numpad0") return 0;
  return null;
}

let current = ZOOM_DEFAULT;

/** The zoom now. */
export const zoomNow = () => current;

/** The kept zoom (100% when none, or when storage is off). */
export function loadZoom(): number {
  try {
    return parseZoom(window.localStorage.getItem(ZOOM_KEY));
  } catch {
    return ZOOM_DEFAULT;
  }
}

/** Sets the main window's zoom: applied to its web view, kept unless `keep` is false, and told to the parts. */
export function setZoom(level: number, keep = true, apply: (factor: number) => Promise<void> = async (f) => getCurrentWebview().setZoom(f)): number {
  const next = parseZoom(String(level));
  if (keep) {
    try {
      if (next === ZOOM_DEFAULT) window.localStorage.removeItem(ZOOM_KEY);
      else window.localStorage.setItem(ZOOM_KEY, String(next));
    } catch {
      // Kept for this session only.
    }
  }
  const changed = next !== current;
  current = next;
  // The page's pixels per point: the Mac's title row counts with it to keep the system's buttons' row (v2.css).
  document.documentElement.style.setProperty("--el-zoom", String(next / 100));
  void apply(next / 100).catch(() => undefined);
  if (changed) window.dispatchEvent(new CustomEvent(ZOOM_EVENT, { detail: next }));
  return next;
}

/** One step in, out, or back to 100% (the menus' commands, the keys, the chip, the avatar's row). */
export const zoomBy = (dir: 1 | -1 | 0) => setZoom(stepZoom(current, dir));

/** The zoom now, followed as it changes. */
export function useZoom(): number {
  return useSyncExternalStore(
    (changed) => {
      window.addEventListener(ZOOM_EVENT, changed);
      return () => window.removeEventListener(ZOOM_EVENT, changed);
    },
    zoomNow,
    () => ZOOM_DEFAULT,
  );
}

/**
 * Ctrl + the wheel (and a touchpad's pinch, which comes as one with ctrlKey): a step for every PINCH_STEP px built up,
 * a mouse's notch being about one. Returns the function that stops listening.
 */
export function watchZoomWheel(win: Window = window, step: (dir: 1 | -1) => void = (dir) => void zoomBy(dir)): () => void {
  let built = 0;
  const wheel = (e: WheelEvent) => {
    if (!e.ctrlKey) {
      built = 0;
      return;
    }
    e.preventDefault();
    // Lines and pages are a notch each (a mouse's wheel in line mode); pixels build up.
    const px = e.deltaMode === 0 ? e.deltaY : Math.sign(e.deltaY) * PINCH_STEP;
    built += px;
    while (Math.abs(built) >= PINCH_STEP) {
      const dir = built < 0 ? 1 : -1;
      built -= -dir * PINCH_STEP;
      step(dir);
    }
  };
  win.addEventListener("wheel", wheel, { passive: false });
  return () => win.removeEventListener("wheel", wheel);
}
