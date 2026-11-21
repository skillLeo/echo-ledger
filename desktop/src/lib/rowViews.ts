// The Library rows' "Converting to Roman Urdu… 60%" (U19). A row's views come with the recording, but writing them
// changes only the transcript, so the Library's sync would not bring the next percent: while a row's default view is
// being made the Library asks for its views every few seconds, and stops once they are ready or failed. What the
// recording page reads about the same transcript (a poll, Try again) is noted here too, so the row and the page agree.
import { useCallback, useMemo, useState } from "react";
import { api, type LibraryItem, type TranscriptViewsState, type ViewsSummary } from "./ipc";
import { defaultViewOf } from "./progress";
import { usePoll } from "./usePoll";

/** How often the Library asks about the rows whose views are being made. */
export const ROW_VIEWS_POLL_MS = 5_000;
/** At most this many rows are asked about per round (the newest first, as the list shows them). */
const MAX_ROWS = 6;

/**
 * A read: its summary, when it was made here, the recording's `updated_at` then (no clocks compared), and whether the
 * recording page made it (it keeps asking while open) or the Library did.
 */
type Seen = { summary: ViewsSummary; at: number; basis: string; page?: boolean };

/** The final transcript a row's views belong to. */
const transcriptOf = (item: LibraryItem): string | null => item.transcript?.final_transcript_id ?? null;

/** The summary a views answer gives (the lines themselves stay with the page that shows them). */
export function summaryOf(state: Pick<TranscriptViewsState, "status" | "default" | "progress">): ViewsSummary {
  return { status: state.status, default: state.default, progress: state.progress ?? {} };
}

/**
 * The row's views as known last: what was read here while the recording stayed as it was, else what came with the
 * recording. A recording that changed on the server since the read (a new title, a run started elsewhere) is trusted again.
 */
export function rowSummary(item: LibraryItem, seen: Readonly<Record<string, Seen>>): ViewsSummary | null {
  const own = item.transcript?.views ?? null;
  const id = transcriptOf(item);
  const read = id ? seen[id] : undefined;
  if (!read) return own;
  if (own && item.updated_at !== read.basis) return own;
  return own?.default && !read.summary.default ? { ...read.summary, default: own.default } : read.summary;
}

/**
 * The rows' views for the Library: `summaryFor(item)` for each row, `note` for what the recording page read, and a
 * poll while any row's default view is being made. `onSettled` runs once a view a row was waiting for is ready or
 * failed (the Library syncs then, for the title the server may have written in Roman Urdu).
 */
export function useRowViews(items: readonly LibraryItem[] | null, onSettled?: () => void): {
  summaryFor: (item: LibraryItem) => ViewsSummary | null;
  note: (item: Pick<LibraryItem, "updated_at" | "transcript">, state: Pick<TranscriptViewsState, "status" | "default" | "progress">) => void;
} {
  const [seen, setSeen] = useState<Record<string, Seen>>({});
  const note = useCallback((item: Pick<LibraryItem, "updated_at" | "transcript">, state: Pick<TranscriptViewsState, "status" | "default" | "progress">) => {
    const id = item.transcript?.final_transcript_id;
    if (id) setSeen((s) => ({ ...s, [id]: { summary: summaryOf(state), at: Date.now(), basis: item.updated_at, page: true } }));
  }, []);
  const summaryFor = useCallback((item: LibraryItem) => rowSummary(item, seen), [seen]);
  const making = useMemo(() => (items ?? [])
    .filter((i) => !i.deleted && transcriptOf(i) && defaultViewOf(rowSummary(i, seen)) && rowSummary(i, seen)?.status === "making")
    .slice(0, MAX_ROWS), [items, seen]);
  usePoll(making.length > 0, async () => {
    const answers = await Promise.all(making.map(async (item) => {
      const id = transcriptOf(item)!;
      // The recording page open on it asks every few seconds itself: no second question for the same answer.
      if (seen[id]?.page && Date.now() - seen[id].at < 2 * ROW_VIEWS_POLL_MS) return null;
      try {
        return { id, basis: item.updated_at, state: await api.transcriptViews(id) };
      } catch {
        return null;
      }
    }));
    const got = answers.filter((a): a is { id: string; basis: string; state: TranscriptViewsState } => a !== null);
    if (!got.length) return;
    const at = Date.now();
    setSeen((s) => ({ ...s, ...Object.fromEntries(got.map((a) => [a.id, { summary: summaryOf(a.state), at, basis: a.basis }])) }));
    if (got.some((a) => a.state.status === "ready" || a.state.status === "failed")) onSettled?.();
  }, ROW_VIEWS_POLL_MS);
  return { summaryFor, note };
}
