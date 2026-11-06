import { processName } from "./format";
// Pure helpers behind the recording pill, the after-call toast and chip, and the ask chip (unit-tested in
// controls.test.ts).
import { SOURCE_APP_LABELS, formatDuration, timestamp } from "./format";
import type { AskPayload, MissingPermission, PendingItem, RecordingState } from "./ipc";

/** Display label of a `source_app` value; unknown values read as words instead of a raw id. */
export function sourceLabel(app: string | null | undefined): string {
  const key = app || "other";
  const known = SOURCE_APP_LABELS[key];
  if (known) return known;
  const words = key.split("_").filter(Boolean).join(" ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

/** Voice notes capture one side only (the microphone or the audio playing), so they get no Me/Client meters. */
export function isTwoChannel(mode: RecordingState["mode"]): boolean {
  return mode !== "voice_note" && mode !== "mic_note";
}

/** The pill timer: `m:ss` under an hour, `h:mm:ss` from an hour, always with seconds, `0:00` at the start. */
export function formatTimer(ms: number): string {
  return timestamp(Number.isFinite(ms) ? ms : 0);
}

/** Level bar fill (0-1) for an RMS level; same scale as the Record view meters (RMS 0.25 fills the bar). */
export function meterLevel(rms: number): number {
  if (!Number.isFinite(rms) || rms <= 0) return 0;
  return Math.min(1, rms * 4);
}

/**
 * The pill timer between the core's once-a-second `recording:state` events: the last known elapsed time and the
 * local clock reading it belongs to. Paused anchors stand still.
 */
export interface TimerAnchor {
  elapsedMs: number;
  at: number;
  running: boolean;
}

export function elapsedAt(anchor: TimerAnchor | null, now: number): number {
  if (!anchor) return 0;
  return anchor.running ? anchor.elapsedMs + Math.max(0, now - anchor.at) : anchor.elapsedMs;
}

/**
 * The anchor after a new state arrives. Events land a little after the core measured them, so a running timer
 * that is already ahead by less than a second keeps its own value instead of stepping back; anything else
 * (start, pause, resume, a new recording, a large correction) takes the core's value.
 */
export function nextAnchor(prev: TimerAnchor | null, state: RecordingState, now: number): TimerAnchor {
  const running = state.active && !state.paused;
  if (!state.active) return { elapsedMs: 0, at: now, running: false };
  if (!running || !prev || !prev.running) return { elapsedMs: state.elapsedMs, at: now, running };
  const shown = elapsedAt(prev, now);
  const behind = shown - state.elapsedMs;
  return { elapsedMs: behind > 0 && behind < 1000 ? shown : state.elapsedMs, at: now, running: true };
}

/**
 * What the after-call toast and chip call a recording (v1.5): its own title, else "Call with <name>" when the meeting
 * app named the other side, else the app ("WhatsApp"); voice notes read "Voice note", an unknown app "Call".
 */
export function afterCallTitle(item: { title?: string | null; callerName?: string | null; sourceApp: string; mode: PendingItem["mode"] }): string {
  const title = item.title?.trim();
  if (title) return title;
  if (item.mode !== "call") return "Voice note";
  const caller = item.callerName?.trim();
  if (caller) return `Call with ${caller}`;
  return !item.sourceApp || item.sourceApp === "other" ? "Call" : sourceLabel(item.sourceApp);
}

/** A recording's length on the after-call toast and chip (`4:12`); a zero-length one still reads `0:00`. */
export function reviewDuration(ms: number): string {
  return formatDuration(ms) || "0:00";
}

/** Seconds (with fractions) until `deadlineAt`, never below zero; null without a valid deadline. */
export function secondsLeft(deadlineAt: string | null, now: number): number | null {
  if (!deadlineAt) return null;
  const deadline = Date.parse(deadlineAt);
  if (Number.isNaN(deadline)) return null;
  return Math.max(0, (deadline - now) / 1000);
}

/** Who the ask chip names: the browser site when the core knows it (browsers only), else the process name. */
export function askSubject(ask: AskPayload): string {
  return ask.site?.trim() || processName(ask.name);
}

/** The core sends `site` only for browsers, so a site means "this site" and its absence "this app". */
export function alwaysIgnoreLabel(ask: AskPayload): string {
  return ask.site ? "Always ignore this site" : "Always ignore this app";
}

/** How long the ask chip stays: the payload's `seconds`, 8 when missing or invalid. */
export function askSeconds(ask: AskPayload): number {
  return Number.isFinite(ask.seconds) && ask.seconds > 0 ? ask.seconds : 8;
}

/** What the pill and the captions say when the system does not let a recording use something (one short line). */
export function missingShort(missing: MissingPermission[] | undefined): string | null {
  const kinds = (missing ?? []).map((m) => m.kind);
  if (kinds.includes("microphone") && kinds.includes("system_audio")) return "Nothing is being recorded: no permission";
  if (kinds.includes("microphone")) return "No microphone: recording the other side";
  if (kinds.includes("system_audio")) return "No system audio: recording your voice only";
  return null;
}
