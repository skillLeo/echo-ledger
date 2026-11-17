// What the v2 Library shows of the core's recordings (api.library(), the same list today's Library uses): the saved
// views, the WhatsApp-style row and the day groups. Pure functions, so the rules are tested without a window.
import type { BinItem, LibraryItem } from "../../lib/ipc";
import { TRASH_KEEP_HOURS_FALLBACK, hoursWords } from "../../lib/trash";
import { dayGroup, itemState, recordingName, searchItems, sortItems, type ItemState } from "../../lib/library";
import { formatDuration, formatTime, rowDate, sourceAppLabel } from "../../lib/format";

export type SavedView = "all" | "today" | "untranscribed" | "trash";

const sameDay = (iso: string | undefined, now: Date) => {
  const t = iso ? new Date(iso) : null;
  return !!t && !Number.isNaN(t.getTime()) && t.toDateString() === now.toDateString();
};

/** Untranscribed: no finished transcript yet (waiting, transcribing, failed or nothing heard). */
export function isUntranscribed(item: Pick<LibraryItem, "status" | "transcript">): boolean {
  return itemState(item) !== "ready";
}

/** The recordings of a saved view (not Trash, which has its own list), searched, newest first. */
export function viewItems(items: LibraryItem[], view: Exclude<SavedView, "trash">, query: string, now: Date = new Date()): LibraryItem[] {
  const live = items.filter((i) => !i.deleted);
  const inView = view === "today" ? live.filter((i) => sameDay(i.started_at, now))
    : view === "untranscribed" ? live.filter(isUntranscribed) : live;
  return sortItems(searchItems(inView, query), "newest");
}

export function viewCounts(items: LibraryItem[], now: Date = new Date()): Record<"all" | "today" | "untranscribed", number> {
  const live = items.filter((i) => !i.deleted);
  return {
    all: live.length,
    today: live.filter((i) => sameDay(i.started_at, now)).length,
    untranscribed: live.filter(isUntranscribed).length,
  };
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
};

/** A recording as a WhatsApp-style row: who or what, the first words, when and how long. */
export function rowModel(item: LibraryItem, now: Date = new Date()): RowModel {
  const state = itemState(item);
  // Processing covers both a transcript under way and none asked for yet: only the second is "Untranscribed".
  const waiting = state === "processing" && !item.transcript?.final_status;
  const app = item.source_app ?? (item.source_device === "phone" ? "phone_call" : "other");
  const source = item.source_app_label ?? (item.source_app ? sourceAppLabel(item.source_app) : null);
  const preview = item.transcript?.preview?.trim() || null;
  const meta = state === "ready" ? (preview ?? source ?? "") : waiting ? (source ?? "") : [source, STATE_WORDS[state]].filter(Boolean).join(" · ");
  const badge = state === "failed" ? { tone: "danger" as const, text: "Failed" }
    : state === "no_audio" ? { tone: "neutral" as const, text: "No audio" }
      : waiting ? { tone: "warning" as const, text: "Untranscribed" } : null;
  const at = item.started_at ? new Date(item.started_at) : null;
  const when = !at || Number.isNaN(at.getTime()) ? "" : sameDay(item.started_at, now) ? formatTime(at) : rowDate(item.started_at, now);
  return { title: recordingName(item.title), meta, badge, when, duration: formatDuration(item.duration_ms), app };
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
