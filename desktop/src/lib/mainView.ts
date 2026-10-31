// C9: closing the main window destroys it, so the core keeps where the user was (in memory, for this run) and the
// next window opens on the same page, with the same recording open and the same scroll positions.
import { api } from "./ipc";

export type MainTab = "library" | "clients" | "record" | "activity" | "settings";

/** The scroll areas whose position is kept (class names in styles.css). */
export const SCROLL_AREAS = ["content", "list", "detail"] as const;
type ScrollArea = (typeof SCROLL_AREAS)[number];

export interface MainView {
  tab: MainTab;
  recordingId: string | null;
  scroll: Partial<Record<ScrollArea, number>>;
}

const TABS: MainTab[] = ["library", "clients", "record", "activity", "settings"];
const SAVE_DELAY_MS = 250;
const RESTORE_FOR_MS = 2000;

/** The saved view, or null when there is none or it is not one of ours. */
export function parseMainView(text: string | null | undefined): MainView | null {
  if (!text) return null;
  try {
    const value = JSON.parse(text) as Partial<MainView>;
    if (!value || !TABS.includes(value.tab as MainTab)) return null;
    const scroll: MainView["scroll"] = {};
    for (const area of SCROLL_AREAS) {
      const top = value.scroll?.[area];
      if (typeof top === "number" && Number.isFinite(top) && top > 0) scroll[area] = Math.round(top);
    }
    return { tab: value.tab as MainTab, recordingId: typeof value.recordingId === "string" ? value.recordingId : null, scroll };
  } catch {
    return null;
  }
}

let current: MainView = { tab: "library", recordingId: null, scroll: {} };
let timer: ReturnType<typeof setTimeout> | undefined;

/** Merges a change into the kept view and hands it to the core shortly after (scrolling sends many). */
export function rememberMainView(patch: Partial<MainView>): void {
  const tabChanged = patch.tab !== undefined && patch.tab !== current.tab;
  current = { ...current, ...patch, scroll: { ...(tabChanged ? {} : current.scroll), ...patch.scroll } };
  if (timer !== undefined) clearTimeout(timer);
  timer = setTimeout(() => {
    timer = undefined;
    api.saveMainView(JSON.stringify(current)).catch(() => undefined);
  }, SAVE_DELAY_MS);
}

/** The view kept from the last main window of this run (null for the first window). */
export async function loadMainView(): Promise<MainView | null> {
  const view = parseMainView(await api.mainView().catch(() => null));
  if (view) current = view;
  return view;
}

/** Remembers the scroll position of the kept areas as the user scrolls. Returns the remover. */
export function trackScroll(): () => void {
  const onScroll = (e: Event) => {
    const el = e.target;
    if (!(el instanceof HTMLElement)) return;
    const area = SCROLL_AREAS.find((a) => el.classList.contains(a));
    if (area) rememberMainView({ scroll: { [area]: Math.round(el.scrollTop) } });
  };
  document.addEventListener("scroll", onScroll, true);
  return () => document.removeEventListener("scroll", onScroll, true);
}

/**
 * Scrolls the kept areas back once their content has loaded (tried each frame for up to two seconds); stops early
 * when the user scrolls, clicks or types. Returns the canceller.
 */
export function restoreScroll(scroll: MainView["scroll"], now: () => number = () => performance.now()): () => void {
  const wanted = Object.entries(scroll) as [ScrollArea, number][];
  if (wanted.length === 0) return () => undefined;
  const until = now() + RESTORE_FOR_MS;
  let frame = 0;
  let stopped = false;
  const stop = () => {
    stopped = true;
    cancelAnimationFrame(frame);
    for (const type of ["wheel", "pointerdown", "keydown"]) window.removeEventListener(type, stop, true);
  };
  for (const type of ["wheel", "pointerdown", "keydown"]) window.addEventListener(type, stop, true);
  const step = () => {
    if (stopped) return;
    let pending = false;
    for (const [area, top] of wanted) {
      const el = document.querySelector<HTMLElement>(`.${area}`);
      if (el && Math.abs(el.scrollTop - top) > 1) el.scrollTop = top;
      if (!el || Math.abs(el.scrollTop - top) > 1) pending = true;
    }
    if (pending && now() < until) frame = requestAnimationFrame(step);
    else stop();
  };
  frame = requestAnimationFrame(step);
  return stop;
}
