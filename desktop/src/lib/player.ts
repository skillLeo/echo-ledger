// The library's transcript player (plan B6): which line and word are being played, skipping silences, speaker
// names and grouping, the keys, and search snippets. Pure; the views only wire them to the audio element.
import type { Segment } from "./ipc";

export const SPEEDS = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2] as const;
/** Silences shorter than this are not skipped. */
export const SKIP_SILENCE_MS = 1500;
/** Where playback lands before the next line after a skip. */
export const SKIP_LEAD_MS = 200;

/** The line being played at `ms`: the last one that started, or -1 before the first. */
export function activeIndex(segments: Pick<Segment, "start_ms">[], ms: number): number {
  let lo = 0;
  let hi = segments.length - 1;
  let found = -1;
  while (lo <= hi) {
    const mid = (lo + hi) >> 1;
    if (segments[mid].start_ms <= ms) {
      found = mid;
      lo = mid + 1;
    } else {
      hi = mid - 1;
    }
  }
  return found;
}

/** The word being spoken within a line at `ms`, or -1. */
export function activeWord(words: { start_ms: number }[] | undefined, ms: number): number {
  if (!words) return -1;
  let found = -1;
  for (let i = 0; i < words.length && words[i].start_ms <= ms; i++) found = i;
  return found;
}

/** With "Skip silence": where to jump when `ms` sits in a silence longer than 1.5 s before the next line, else null. */
export function skipTarget(segments: Pick<Segment, "start_ms" | "end_ms">[], ms: number): number | null {
  if (segments.some((s) => s.start_ms <= ms && s.end_ms >= ms)) return null;
  const next = segments.find((s) => s.start_ms > ms);
  if (!next || next.start_ms - ms <= SKIP_SILENCE_MS) return null;
  return next.start_ms - SKIP_LEAD_MS;
}

/** The name to show for a speaker label: the renamed one when set. */
export function speakerName(names: Record<string, string> | undefined, label: string): string {
  return names?.[label]?.trim() || label;
}

/** True when a line continues the previous speaker's turn (the label is shown once per turn). */
export function continuesTurn(segments: Pick<Segment, "speaker">[], index: number): boolean {
  return index > 0 && segments[index - 1].speaker === segments[index].speaker;
}

/** The speaker labels in the transcript, in order of first appearance. */
export function speakerLabels(segments: Pick<Segment, "speaker">[]): string[] {
  return [...new Set(segments.map((s) => s.speaker).filter((s) => s !== ""))];
}

export type PlayerKey = "toggle" | "back10" | "forward10" | "back5" | "forward5";

/**
 * What a key does in the player: Space and K play or pause, J and L jump 10 s, the arrows 5 s. Keys typed into a
 * field are never taken, and Space and the arrows keep their meaning on buttons and the audio element.
 */
export function playerKey(key: string, targetTag: string, editable = false): PlayerKey | null {
  const tag = targetTag.toUpperCase();
  if (editable || ["INPUT", "TEXTAREA", "SELECT"].includes(tag)) return null;
  const onControl = ["BUTTON", "AUDIO", "A"].includes(tag);
  const k = key.length === 1 ? key.toLowerCase() : key;
  if (k === "k" || (k === " " && !onControl)) return "toggle";
  if (k === "j") return "back10";
  if (k === "l") return "forward10";
  if (k === "ArrowLeft" && !onControl) return "back5";
  if (k === "ArrowRight" && !onControl) return "forward5";
  return null;
}

export const KEY_SECONDS: Record<Exclude<PlayerKey, "toggle">, number> = { back10: -10, forward10: 10, back5: -5, forward5: 5 };

/** Position on the timeline in percent, clamped to 0-100. */
export function timelinePercent(ms: number, durationMs: number): number {
  if (!Number.isFinite(ms) || durationMs <= 0) return 0;
  return Math.min(100, Math.max(0, (ms / durationMs) * 100));
}

/** A search snippet split into plain and highlighted parts (the server sends character ranges, never HTML). */
export function snippetParts(snippet: string, highlights: [number, number][]): { text: string; hit: boolean }[] {
  const chars = [...snippet];
  const parts: { text: string; hit: boolean }[] = [];
  let pos = 0;
  for (const [start, length] of [...highlights].sort((a, b) => a[0] - b[0])) {
    if (start < pos || start >= chars.length) continue;
    if (start > pos) parts.push({ text: chars.slice(pos, start).join(""), hit: false });
    parts.push({ text: chars.slice(start, start + length).join(""), hit: true });
    pos = start + length;
  }
  if (pos < chars.length) parts.push({ text: chars.slice(pos).join(""), hit: false });
  return parts;
}

/**
 * Words an edit added that were not in the line before (candidates for "Add to vocabulary"): capitalised or
 * containing a digit, at most three, punctuation stripped.
 */
export function newTerms(before: string, after: string): string[] {
  const clean = (w: string) => w.replace(/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/gu, "");
  const old = new Set(before.split(/\s+/).map((w) => clean(w).toLowerCase()));
  const terms: string[] = [];
  for (const word of after.split(/\s+/).map(clean)) {
    if (word.length < 2 || old.has(word.toLowerCase()) || terms.includes(word)) continue;
    if (/^\p{Lu}/u.test(word) || /\d/.test(word)) terms.push(word);
  }
  return terms.slice(0, 3);
}
