// Screen moments in the apps (docs/API.md "Screen moments", since v1.5), the same rules as the phone's
// ScreenMoments.kt: each screen sits after the last transcript line that started at or before its moment (a screen
// before the first line goes with it), and reads "Screen 7 · 14:32" on the page and "[Screen 7 at 14:32: The invoice
// page]" in Copy for AI.
import { api, type ProjectScreen, type ScreenMoment } from "./ipc";
import { timestamp } from "./format";

type Timed = Pick<ScreenMoment, "index" | "at_ms" | "description">;

/** Line index → the screens shown after that line, in time order. */
export function screensByLine<T extends Pick<ScreenMoment, "at_ms">>(segments: { start_ms: number }[], screens: T[]): Map<number, T[]> {
  const out = new Map<number, T[]>();
  if (segments.length === 0 || screens.length === 0) return out;
  for (const screen of [...screens].sort((a, b) => a.at_ms - b.at_ms)) {
    let line = 0;
    segments.forEach((s, i) => { if (s.start_ms <= screen.at_ms) line = i; });
    out.set(line, [...(out.get(line) ?? []), screen]);
  }
  return out;
}

/** "Screen 7 · 14:32" */
export function screenLabel(screen: Timed): string {
  return `Screen ${screen.index} · ${timestamp(screen.at_ms)}`;
}

/** "[Screen 7 at 14:32: The invoice page]", or "[Screen 7 at 14:32]" without a description. */
export function screenMarker(screen: Timed): string {
  const about = screen.description?.trim() ? `: ${screen.description.trim()}` : "";
  return `[Screen ${screen.index} at ${timestamp(screen.at_ms)}${about}]`;
}

/** What a screen shows, for its picture's alternative text: the description, else its label. */
export function screenAlt(screen: Timed): string {
  return screen.description?.trim() || screenLabel(screen);
}

/** A project's screens grouped by their recording, in the order the server lists them (newest recording first). */
export function screensBySession(screens: ProjectScreen[]): { sessionId: string; title: string | null; startedAt: string | null; screens: ProjectScreen[] }[] {
  const groups: { sessionId: string; title: string | null; startedAt: string | null; screens: ProjectScreen[] }[] = [];
  for (const s of screens) {
    const last = groups[groups.length - 1];
    if (last && last.sessionId === s.session_id) last.screens.push(s);
    else groups.push({ sessionId: s.session_id, title: s.session_title, startedAt: s.session_started_at, screens: [s] });
  }
  return groups;
}

/** Pictures already fetched this run, by their signed link (each is fetched once; the oldest go first). */
const pictures = new Map<string, Promise<string>>();
export const PICTURES_KEPT = 120;

/** A screen's picture as a data: URL, fetched through the core (pinned to the server) and kept for this run. */
export function screenPicture(url: string): Promise<string> {
  const known = pictures.get(url);
  if (known) return known;
  const loading = api.screenImage(url);
  pictures.set(url, loading);
  // A failed fetch is forgotten, so opening the screen again tries again.
  loading.catch(() => pictures.delete(url));
  while (pictures.size > PICTURES_KEPT) pictures.delete(pictures.keys().next().value as string);
  return loading;
}

/** Forgets every fetched picture (tests). */
export function forgetPictures(): void {
  pictures.clear();
}
