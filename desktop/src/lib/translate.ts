import { api } from "./ipc";

/** Live Translate on the desktop (v1.3, plan B6): one app's sound translated in a click-through overlay. */
export type TranslateMode = "game" | "watch";
export type TranslateSpeed = "fastest" | "balanced" | "accurate";

export interface TranslateApp {
  pid: number;
  name: string;
}

/** What the overlay shows; `status` is listening, live, quiet, reconnecting or stopped. */
export interface TranslateFeed {
  mode: TranslateMode | null;
  app: string;
  text: string;
  partial: string;
  original: string;
  status: string;
  notice: string | null;
  locked: boolean;
  hidden: boolean;
}

export const TRANSLATE_FEED = "translate-feed";

export const TRANSLATE_TARGETS: [string, string][] = [
  ["en", "English"],
  ["ur", "Urdu"],
  ["ar", "Arabic"],
  ["fa", "Persian"],
  ["tr", "Turkish"],
  ["ru", "Russian"],
  ["es", "Spanish"],
  ["fr", "French"],
  ["de", "German"],
];

export const RTL_TARGETS = new Set(["ur", "ar", "fa"]);

/** `translate_start`: which app, Game or Watch, the language to write in, the languages spoken (empty = any), the speed. */
export interface TranslateStartRequest {
  pid: number;
  name: string;
  mode: TranslateMode;
  target: string;
  hints: string[];
  speed: TranslateSpeed;
}

/** The Live Translate commands (wrapped in ipc.ts with the others, where the contract test checks them). */
export const translateApi = {
  apps: () => api.translateApps(),
  status: () => api.translateStatus(),
  start: (app: TranslateApp, mode: TranslateMode, target: string, hints: string[], speed: TranslateSpeed) =>
    api.translateStart({ pid: app.pid, name: app.name, mode, target, hints, speed }),
  stop: () => api.translateStop(),
  toggleLock: () => api.translateToggleLock(),
};

/** The newest `lines` lines of the text, roughly: the overlay has a fixed size and shows the end of the flow. */
export function tailText(text: string, maxChars: number): string {
  const t = text.trim();
  if (t.length <= maxChars) return t;
  const cut = t.slice(t.length - maxChars);
  const space = cut.indexOf(" ");
  return space > 0 && space < 20 ? cut.slice(space + 1) : cut;
}
