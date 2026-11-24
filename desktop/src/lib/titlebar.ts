// The macOS main window's title-bar strip (D-214). The window's title bar is overlaid (tauri.conf.json titleBarStyle
// "Overlay"), so its close, minimize and zoom buttons sit on the page. In a normal window the page leaves them an empty
// strip across the top, in the chrome's colour, that drags the window and zooms it on a double click; the rail, the
// panel and the page start below it. In full screen macOS hides the buttons until the pointer reaches the top edge and
// shows them over the content: there is no strip, and the window looks as it did before the strip (2.0.12).
//
// <html data-titlebar="strip"> says the strip is on; styles key off it (v2/styles/v2.css). Windows has its own title
// bar outside the page, so it never gets one. On a Mac the core sets it before the page's first frame (titlebarStart.js,
// run at document start), so the first paint is right; the answer here then only takes it away in full screen, and is
// kept for the page after a reload (TITLEBAR_KEY).

import { useSyncExternalStore } from "react";

/** The strip on, or not. */
export type TitlebarMode = "strip" | "none";

/** The window's last answer, for titlebarStart.js after a reload ("strip" or "none"; this window's session only). */
export const TITLEBAR_KEY = "echoledger-titlebar";

/** How long macOS's full-screen animation takes, after which the window is asked again (its first answer can be early). */
export const FULLSCREEN_SETTLE_MS = 700;

/** Whether the window's buttons need the strip (pure): on a Mac, in a normal window. */
export function titlebarMode(platform: string | null | undefined, fullscreen: boolean): TitlebarMode {
  return platform === "macos" && !fullscreen ? "strip" : "none";
}

/** Sets or clears <html data-titlebar> for that mode. */
export function applyTitlebar(mode: TitlebarMode, root: HTMLElement = document.documentElement): void {
  if (mode === "strip") root.dataset.titlebar = "strip";
  else delete root.dataset.titlebar;
}

/**
 * Keeps the strip in step with the window, live: it asks the window whether it is full screen at once, and again on
 * every resize (entering and leaving full screen resize the page), now and once the animation is over. Only the newest
 * answer counts. Off a Mac nothing is asked and there is no strip. Returns the function that stops watching.
 */
export function watchTitlebar(
  platform: string | null | undefined,
  isFullscreen: () => Promise<boolean>,
  root: HTMLElement = document.documentElement,
  win: Window = window,
): () => void {
  if (platform !== "macos") {
    // Windows' own title row (below) is not the Mac's strip: it stays.
    if (root.dataset.titlebar !== WIN_TITLEBAR) applyTitlebar("none", root);
    return () => undefined;
  }
  let asked = 0;
  let settle = 0;
  let stopped = false;
  const ask = () => {
    const mine = ++asked;
    isFullscreen().then(
      (fullscreen) => {
        if (stopped || mine !== asked) return;
        const mode = titlebarMode(platform, fullscreen);
        applyTitlebar(mode, root);
        try {
          win.sessionStorage.setItem(TITLEBAR_KEY, mode);
        } catch {
          // Storage off: a reload starts from the normal window's strip.
        }
      },
      () => undefined,
    );
  };
  const resized = () => {
    ask();
    win.clearTimeout(settle);
    settle = win.setTimeout(ask, FULLSCREEN_SETTLE_MS);
  };
  ask();
  win.addEventListener("resize", resized);
  return () => {
    stopped = true;
    win.clearTimeout(settle);
    win.removeEventListener("resize", resized);
  };
}

// Windows' own title row (UX-20, v21_win_titlebar; wintitle.rs). With the flag on the main window has no system title
// bar and the page draws the row with the window's buttons. <html data-titlebar="win"> says so: the core sets it before
// the page's first frame when it builds the window that way (winTitlebarStart.js), tells the page when the flag changes
// while it is open ("window:titlebar"), and answers after a reload (window_titlebar).

/** <html data-titlebar> while the page draws Windows' title row. */
export const WIN_TITLEBAR = "win";
/** Sent on the window when the row comes or goes, for the parts that draw it. */
export const WIN_TITLEBAR_EVENT = "echoledger:win-titlebar";

/** Whether the page draws Windows' title row now. */
export function winTitlebarOn(root: HTMLElement = document.documentElement): boolean {
  return root.dataset.titlebar === WIN_TITLEBAR;
}

/** Sets or clears it, and tells the parts that draw it. */
export function applyWinTitlebar(on: boolean, root: HTMLElement = document.documentElement, win: Window = window): void {
  if (on === winTitlebarOn(root)) return;
  if (on) root.dataset.titlebar = WIN_TITLEBAR;
  else delete root.dataset.titlebar;
  win.dispatchEvent(new Event(WIN_TITLEBAR_EVENT));
}

/**
 * Keeps it as the core says (Windows' main window): its answer now, then every change. Returns the function that stops
 * listening.
 */
export function watchWinTitlebar(
  ask: () => Promise<boolean>,
  listen: (follow: (on: boolean) => void) => Promise<() => void>,
  root: HTMLElement = document.documentElement,
  win: Window = window,
): () => void {
  let stopped = false;
  let unlisten: (() => void) | null = null;
  const follow = (on: boolean) => {
    if (!stopped) applyWinTitlebar(on === true, root, win);
  };
  void listen(follow).then((off) => (stopped ? off() : (unlisten = off)), () => undefined);
  ask().then(follow, () => undefined);
  return () => {
    stopped = true;
    unlisten?.();
  };
}

/** Whether the page draws Windows' title row, followed as it changes. */
export function useWinTitlebar(): boolean {
  return useSyncExternalStore(
    (changed) => {
      window.addEventListener(WIN_TITLEBAR_EVENT, changed);
      return () => window.removeEventListener(WIN_TITLEBAR_EVENT, changed);
    },
    () => winTitlebarOn(),
    () => false,
  );
}
