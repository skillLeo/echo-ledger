// The Library's sync, and when it last worked (the sidebar's "Synced 2 min ago" line, which syncs again when pressed:
// it took Sync's place in the Library's header). One sync at a time for the window; every caller hears how it went.
import { useEffect, useState, useSyncExternalStore } from "react";
import { api, errorMessage, type LibraryItem } from "../../lib/ipc";
import { t, useLanguage } from "../../i18n";

type Clock = { at: number | null; syncing: boolean; error: string | null };
let clock: Clock = { at: null, syncing: false, error: null };
let running: Promise<LibraryItem[]> | null = null;
const listeners = new Set<() => void>();
const set = (next: Partial<Clock>) => {
  clock = { ...clock, ...next };
  listeners.forEach((l) => l());
};

/** Syncs the Library with the server (the core's library_refresh): one at a time; a second call shares the first. */
export function syncLibrary(): Promise<LibraryItem[]> {
  if (running) return running;
  set({ syncing: true });
  running = api.refreshLibrary().then((items) => {
    set({ at: Date.now(), syncing: false, error: null });
    return items;
  }, (e) => {
    set({ syncing: false, error: errorMessage(e) });
    throw e;
  }).finally(() => {
    running = null;
  });
  return running;
}

/** For tests: forget the last sync. */
export function resetSyncClock() {
  clock = { at: null, syncing: false, error: null };
  running = null;
  listeners.forEach((l) => l());
}

const subscribe = (l: () => void) => {
  listeners.add(l);
  return () => listeners.delete(l);
};
const snapshot = () => clock;

/** The line's words: "Syncing…", "Synced just now", "Synced 5 min ago", "Synced at 9:40", "Not synced". */
export function syncWords(state: Clock, now: number): string {
  if (state.syncing) return t("Syncing…");
  if (state.error && state.at === null) return t("Not synced");
  if (state.at === null) return t("Not synced yet");
  const minutes = Math.floor((now - state.at) / 60_000);
  if (minutes < 1) return state.error ? t("Last synced just now") : t("Synced just now");
  if (minutes < 60) return state.error ? t("Last synced :minutes min ago", { minutes }) : t("Synced :minutes min ago", { minutes });
  const time = new Date(state.at).toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
  return state.error ? t("Last synced at :time", { time }) : t("Synced at :time", { time });
}

/** The sync's state and its words, the minutes kept up to date. */
export function useSyncClock(): Clock & { words: string } {
  // Its words follow the window's language.
  useLanguage();
  const state = useSyncExternalStore(subscribe, snapshot, snapshot);
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(timer);
  }, []);
  return { ...state, words: syncWords(state, Math.max(now, state.at ?? 0)) };
}
