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

// ---- Meeting assets (v1.8 P4.2) ---------------------------------------------------------------------------------
// The server names each screen and says what was said around it (`file_name`, `said`). Until a server sends them the
// app works them out the same way from what it has, so the screens read the same either way.

/** What was said this long before and after a screen. */
export const SAID_WINDOW_MS = 15_000;
/** At most this many characters of it. */
export const SAID_CHARS = 600;
const SLUG_CHARS = 40;

/** "orders-page": the description in lowercase Latin letters and digits joined by dashes, cut at a word. */
export function screenSlug(description: string | null | undefined): string {
  let slug = (description ?? "").slice(0, 120).normalize("NFKD").replace(/[\u0300-\u036f]/g, "").toLowerCase()
    .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
  if (slug.length > SLUG_CHARS) slug = slug.slice(0, SLUG_CHARS + 1).replace(/-[^-]*$/, "").replace(/-+$/, "");
  return slug || "screen";
}

/** "S07_14-32_orders-page.webp": its number (as many digits as the last one needs, at least two), moment and slug. */
export function screenFileName(screen: Pick<ScreenMoment, "index" | "at_ms" | "description" | "file_name">, count: number): string {
  if (screen.file_name) return screen.file_name;
  const digits = Math.max(2, String(Math.max(count, screen.index)).length);
  return `S${String(screen.index).padStart(digits, "0")}_${timestamp(screen.at_ms).replace(/:/g, "-")}_${screenSlug(screen.description)}.webp`;
}

/** The lines said within 15 s of a screen, as "Speaker: words" lines, at most 600 characters; null when none. */
export function saidAround(atMs: number, lines: readonly { speaker: string; start_ms: number; end_ms: number; text: string }[]): string | null {
  const said = lines
    .filter((l) => l.end_ms >= atMs - SAID_WINDOW_MS && l.start_ms <= atMs + SAID_WINDOW_MS)
    .map((l) => `${l.speaker.trim() ? `${l.speaker.trim()}: ` : ""}${l.text.trim()}`);
  if (said.length === 0) return null;
  const text = said.join("\n");
  return text.length > SAID_CHARS ? `${text.slice(0, SAID_CHARS - 1).trimEnd()}…` : text;
}

/** What was said around a screen: the server's when it sends it (null = nothing), else from the transcript at hand. */
export function screenSaid(screen: Pick<ScreenMoment, "at_ms" | "said">, lines: Parameters<typeof saidAround>[1]): string | null {
  return screen.said !== undefined ? screen.said : saidAround(screen.at_ms, lines);
}

/** "Me: Here is the price." → { speaker: "Me", text: "Here is the price." }, one per line. */
export function saidLines(said: string | null): { speaker: string | null; text: string }[] {
  if (!said) return [];
  return said.split("\n").filter((l) => l.trim()).map((l) => {
    const m = /^([^:]{1,60}):\s(.*)$/.exec(l);
    return m ? { speaker: m[1], text: m[2] } : { speaker: null, text: l };
  });
}
