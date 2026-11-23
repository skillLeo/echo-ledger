// What the v2 Library shows of the core's recordings (api.library(), the same list today's Library uses): the saved
// views, the WhatsApp-style row and the day groups. Pure functions, so the rules are tested without a window.
import type { BinItem, LibraryItem, ViewsSummary } from "../../lib/ipc";
import { rowViewsLine } from "../../lib/progress";
import { TRASH_KEEP_HOURS_FALLBACK, hoursWords } from "../../lib/trash";
import { inSmartView } from "../../lib/organise";
import { dayGroup, itemState, recordingName, searchItems, sortItems, type ItemState } from "../../lib/library";
import { formatDuration, formatTime, rowDate, sourceAppLabel } from "../../lib/format";
import { partsText } from "../../lib/languages";

/** The saved views (classic's smart views and the new design's Untranscribed), and Trash. */
export type SavedView = "all" | "today" | "this_week" | "untranscribed" | "waiting" | "pinned" | "bookmarked" | "failed" | "trash";
export type ListView = Exclude<SavedView, "trash">;
/** The Views ▾ menu: names in classic's words, in its order (Untranscribed after the dates). */
export const VIEW_NAMES: Record<ListView, string> = {
  all: "All recordings", today: "Today", this_week: "This week", untranscribed: "Untranscribed", waiting: "Waiting for decision",
  pinned: "Pinned", bookmarked: "Bookmarked", failed: "Failed",
};
export const LIST_VIEWS = Object.keys(VIEW_NAMES) as ListView[];

/** Whether a recording is in a view (classic's smart view rules; Untranscribed is the new design's). */
export function inView(item: LibraryItem, view: ListView, now: Date = new Date()): boolean {
  if (item.deleted) return false;
  if (view === "today") return sameDay(item.started_at, now);
  if (view === "untranscribed") return isUntranscribed(item);
  return inSmartView(item, view, now);
}

const sameDay = (iso: string | undefined, now: Date) => {
  const t = iso ? new Date(iso) : null;
  return !!t && !Number.isNaN(t.getTime()) && t.toDateString() === now.toDateString();
};

/** Untranscribed: no finished transcript yet (waiting, transcribing, failed or nothing heard). */
export function isUntranscribed(item: Pick<LibraryItem, "status" | "transcript">): boolean {
  return itemState(item) !== "ready";
}

/** The recordings of a saved view (not Trash, which has its own list), searched, newest first. */
export function viewItems(items: LibraryItem[], view: ListView, query: string, now: Date = new Date()): LibraryItem[] {
  return sortItems(searchItems(items.filter((i) => inView(i, view, now)), query), "newest");
}

export function viewCounts(items: LibraryItem[], now: Date = new Date()): Record<ListView, number> {
  return Object.fromEntries(LIST_VIEWS.map((v) => [v, items.filter((i) => inView(i, v, now)).length])) as Record<ListView, number>;
}

/** Recordings under day headings: Today, Yesterday, This week, Earlier. */
export function dayGroups(items: LibraryItem[], now: Date = new Date()): { label: string; items: LibraryItem[] }[] {
  const groups: { label: string; items: LibraryItem[] }[] = [];
  for (const item of items) {
    const label = dayGroup(item.started_at, now);
    const last = groups[groups.length - 1];
    if (last?.label === label) last.items.push(item);
    else groups.push({ label, items: [item] });
  }
  return groups;
}

const STATE_WORDS: Record<Exclude<ItemState, "ready">, string> = {
  processing: "Transcribing…",
  failed: "Transcription failed",
  no_audio: "Nothing was heard",
};

export type RowModel = {
  title: string;
  /** Line 2: the first words of the transcript, or what is happening to it. */
  meta: string;
  /** The one badge a row may have: why it has no transcript. */
  badge: { tone: "warning" | "danger" | "neutral"; text: string } | null;
  /** The end column: the time today, the date before; the length under it. */
  when: string;
  duration: string;
  app: string;
  /** Its screen moments, for the screens mark (0: none, or a server without them). */
  screens: number;
  /** Its default view (Roman Urdu for an Urdu call) is being written, with a spinner, or could not be (U19). */
  converting: "making" | "failed" | null;
};

/** A recording as a WhatsApp-style row: who or what, the first words, when and how long. */
export function rowModel(item: LibraryItem, now: Date = new Date(), views: ViewsSummary | null | undefined = item.transcript?.views): RowModel {
  const state = itemState(item);
  // Processing covers both a transcript under way and none asked for yet: only the second is "Untranscribed".
  // A call still waiting for Keep or Discard on the server (session `recording`): said so, not "Untranscribed".
  const deciding = item.status === "recording";
  const waiting = !deciding && state === "processing" && !item.transcript?.final_status;
  const app = item.source_app ?? (item.source_device === "phone" ? "phone_call" : "other");
  const source = item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app) : null);
  const preview = item.transcript?.preview?.trim() || null;
  // A ready transcript whose default view is still written says "Converting to Roman Urdu… 60%" in place of its first
  // words (they come in Urdu script until then); one that could not be written says so before them.
  const converting = state === "ready" ? rowViewsLine(views) : null;
  // Transcribing says which part it is on (classic's step line).
  const parts = state === "processing" ? partsText(item.transcript?.final_progress) : null;
  const words = parts ? `Transcribing… ${parts}` : STATE_WORDS[state as Exclude<ItemState, "ready">];
  const base = converting?.state === "making" ? converting.text
    : state === "ready" ? [converting?.text, preview ?? source ?? ""].filter(Boolean).join(" · ")
      : waiting ? (source ?? "") : [source, words].filter(Boolean).join(" · ");
  // Classic's sub-line after it: the project and how many bookmarks (the length is at the row's end).
  const marks = item.marker_count ?? 0;
  const meta = [base, item.client?.name, marks > 0 ? (marks === 1 ? "1 bookmark" : `${marks} bookmarks`) : null].filter(Boolean).join(" · ");
  const badge = deciding ? { tone: "warning" as const, text: "Waiting for decision" }
    : state === "failed" ? { tone: "danger" as const, text: "Failed" }
    : state === "no_audio" ? { tone: "neutral" as const, text: "No audio" }
      : waiting ? { tone: "warning" as const, text: "Untranscribed" } : null;
  const at = item.started_at ? new Date(item.started_at) : null;
  const when = !at || Number.isNaN(at.getTime()) ? "" : sameDay(item.started_at, now) ? formatTime(at) : rowDate(item.started_at, now);
  return { title: recordingName(item.title), meta, badge, when, duration: formatDuration(item.duration_ms), app, screens: item.screen_count ?? 0, converting: converting?.state ?? null };
}

/** The server's default for how long a deleted recording is kept (lib/trash.ts has the fallback). */
export const BIN_HOURS = TRASH_KEEP_HOURS_FALLBACK;

/**
 * The bin's length: the server's setting (trash_keep_hours) when the core says it; else what the items show
 * (erase_at less deleted_at); else the server's 48 hours.
 */
export function binHours(items: readonly Pick<BinItem, "deleted_at" | "erase_at">[], serverHours: number | null = null): number {
  if (serverHours !== null && serverHours > 0) return serverHours;
  for (const b of items) {
    const hours = Math.round((Date.parse(b.erase_at) - Date.parse(b.deleted_at)) / 3_600_000);
    if (Number.isFinite(hours) && hours > 0) return hours;
  }
  return BIN_HOURS;
}

/** The Trash sentence, in the classic Library's words. */
export function binSentence(hours: number): string {
  return `Deleted recordings stay here for ${hoursWords(hours)}, then their audio and transcripts are erased.`;
}
