import { useEffect, useState } from "react";
import { api, type LiveTranslateAvailability } from "./ipc";

/** Live Translate on the desktop (v1.3, plan B6): one app's sound translated in a click-through overlay. */
export type TranslateMode = "game" | "watch";
export type TranslateSpeed = "fastest" | "balanced" | "accurate";

export interface TranslateApp {
  pid: number;
  name: string;
}

/**
 * What the overlay shows; `status` is listening, live, quiet or reconnecting while it runs, stopped after Stop, and
 * ended when capture ended on its own (the app stopped playing or closed, or an error; core batch 5), with the reason
 * in `notice` when the core gives one.
 */
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

/** Why Live Translate can't run here, in words. */
export const UNAVAILABLE_WORDS: Record<NonNullable<LiveTranslateAvailability["reason"]>, string> = {
  platform: "Live Translate isn't on the Mac yet",
  plan: "Not in your plan",
};

/**
 * Whether it can run here: the core's answer (live_translate_status), else worked out the same way from this system
 * and the plan, for a core that doesn't answer.
 */
export function translateAvailability(answer: LiveTranslateAvailability | null, platform: string, inPlan: boolean): LiveTranslateAvailability {
  if (answer) return answer;
  if (platform === "macos") return { available: false, reason: "platform" };
  return inPlan ? { available: true, reason: null } : { available: false, reason: "plan" };
}

/** The core's answer, read once: null until it comes (or when the core can't say). */
export function useTranslateAvailability(): LiveTranslateAvailability | null {
  const [answer, setAnswer] = useState<LiveTranslateAvailability | null>(null);
  useEffect(() => {
    let live = true;
    api.liveTranslateStatus().then((a) => live && setAnswer(a ?? null), () => undefined);
    return () => {
      live = false;
    };
  }, []);
  return answer;
}

/** The statuses of a session that is running. Anything else, a status this app doesn't know included, is not. */
export const RUNNING_STATUSES: ReadonlySet<string> = new Set(["listening", "live", "quiet", "reconnecting"]);

/**
 * Where a session stands for the panels: running, ended on its own (or a status this app doesn't know: it may have
 * stopped, so it never shows as running, and Close tidies it away), or stopped (none at all).
 */
export function translatePhase(feed: TranslateFeed | null | undefined): "running" | "ended" | "stopped" {
  // No feed, or an answer that isn't one (no status at all): nothing is running and there is nothing to tidy.
  if (!feed || typeof feed.status !== "string" || feed.status === "stopped") return "stopped";
  return RUNNING_STATUSES.has(feed.status) ? "running" : "ended";
}

/** Why an ended session ended, in plain words: the core's reason, else what usually ends one. */
export function endedWords(feed: TranslateFeed): string {
  const reason = typeof feed.notice === "string" ? feed.notice.trim() : "";
  if (reason) return reason;
  const app = (typeof feed.app === "string" ? feed.app.trim() : "") || "The app";
  return `Live Translate ended. ${app} closed or stopped playing sound.`;
}

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
