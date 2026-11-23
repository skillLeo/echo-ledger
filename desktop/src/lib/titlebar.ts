// The macOS main window's title-bar strip (D-214). The window's title bar is overlaid (tauri.conf.json titleBarStyle
// "Overlay"), so its close, minimize and zoom buttons sit on the page. In a normal window the page leaves them an empty
// strip across the top, in the chrome's colour, that drags the window and zooms it on a double click; the rail, the
// panel and the page start below it. In full screen macOS hides the buttons until the pointer reaches the top edge and
// shows them over the content: there is no strip, and the window looks as it did before the strip (2.0.12).
//
// <html data-titlebar="strip"> says the strip is on; styles key off it (v2/styles/v2.css). Windows has its own title
// bar outside the page, so it never gets one.

/** The strip on, or not. */
export type TitlebarMode = "strip" | "none";

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
    applyTitlebar("none", root);
    return () => undefined;
  }
  let asked = 0;
  let settle = 0;
  let stopped = false;
  const ask = () => {
    const mine = ++asked;
    isFullscreen().then(
      (fullscreen) => { if (!stopped && mine === asked) applyTitlebar(titlebarMode(platform, fullscreen), root); },
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
