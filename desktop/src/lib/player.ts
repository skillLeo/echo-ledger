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
/** A word below this confidence (0-1) is shown as uncertain: dotted underline, a click plays it (the server's threshold). */
export const UNCERTAIN_BELOW = 0.5;

export function uncertainWord(word: { confidence?: number | null }): boolean {
  return typeof word.confidence === "number" && word.confidence < UNCERTAIN_BELOW;
}

/** Whether a line has a word the engine was not sure of. */
export function hasUncertainWords(segment: Pick<Segment, "words">): boolean {
  return (segment.words ?? []).some(uncertainWord);
}

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

/** One bar of the docked player's waveform: its height (0-1) and the side that talks most in it. */
export interface WaveBar {
  level: number;
  channel: 1 | 2;
}

/**
 * The waveform drawn from the transcript, not the audio (decoding a long recording in the page would be slow): each bar
 * is how much of its slice someone speaks, a little higher for fast speech, and coloured by the side that talks. Bars
 * of silence stay low.
 */
export function waveformBars(segments: Pick<Segment, "start_ms" | "end_ms" | "channel" | "text" | "words">[], durationMs: number, count = 96): WaveBar[] {
  if (!(durationMs > 0) || count <= 0) return [];
  const step = durationMs / count;
  const bars = Array.from({ length: count }, () => ({ talk: [0, 0], rate: 0 }));
  for (const s of segments) {
    const from = Math.max(0, s.start_ms);
    const to = Math.min(durationMs, s.end_ms);
    if (!(to > from)) continue;
    const side = s.channel === 2 ? 1 : 0;
    const words = s.words?.length ?? s.text.split(/\s+/).filter(Boolean).length;
    const rate = words / Math.max(1, (to - from) / 1000);
    for (let i = Math.floor(from / step); i < count && i * step < to; i++) {
      const overlap = Math.min(to, (i + 1) * step) - Math.max(from, i * step);
      if (overlap <= 0) continue;
      bars[i].talk[side] += overlap / step;
      bars[i].rate = Math.max(bars[i].rate, rate);
    }
  }
  return bars.map((b, i) => {
    const talk = Math.min(1, b.talk[0] + b.talk[1]);
    // Some variation from the speech rate and the bar's place, so a long turn does not draw as one flat block.
    const wobble = 0.72 + 0.28 * Math.abs(Math.sin(i * 12.9898 + b.rate * 3.7));
    const level = talk === 0 ? 0.1 : Math.min(1, 0.22 + 0.78 * talk * wobble);
    return { level: Math.round(level * 100) / 100, channel: b.talk[1] > b.talk[0] ? 2 : 1 };
  });
}

/** One match of Find in a transcript: segment, character offset and length. */
export interface FindMatch {
  seg: number;
  start: number;
  length: number;
}

/** Every literal, case-insensitive match of `query` in the lines shown, in reading order. */
export function findMatches(lines: string[], query: string): FindMatch[] {
  const q = query.trim().toLowerCase();
  if (!q) return [];
  const out: FindMatch[] = [];
  lines.forEach((line, seg) => {
    const text = line.toLowerCase();
    for (let at = text.indexOf(q); at >= 0; at = text.indexOf(q, at + q.length)) out.push({ seg, start: at, length: q.length });
  });
  return out;
}

/** A line split around the given matches (`current` is the index into `matches` of the one to show strongest). */
export function markParts(line: string, matches: (FindMatch & { index: number })[]): { text: string; match: number | null }[] {
  const parts: { text: string; match: number | null }[] = [];
  let pos = 0;
  for (const m of matches) {
    if (m.start < pos) continue;
    if (m.start > pos) parts.push({ text: line.slice(pos, m.start), match: null });
    parts.push({ text: line.slice(m.start, m.start + m.length), match: m.index });
    pos = m.start + m.length;
  }
  if (pos < line.length) parts.push({ text: line.slice(pos), match: null });
  return parts;
}

/** Where a suggested fix's words sit in its line (case-insensitive, first occurrence), or null when they are gone. */
export function suggestionSpan(line: string, from: string): [number, number] | null {
  const at = from ? line.toLowerCase().indexOf(from.toLowerCase()) : -1;
  return at < 0 ? null : [at, at + from.length];
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
