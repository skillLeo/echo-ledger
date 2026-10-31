// Helpers for the Library's "Waiting for your decision" and "Uploads that need you" sections (A2.5, A5.1).
import { formatDuration, sourceAppLabel } from "./format";
import type { PendingItem, UploadItem } from "./ipc";

/** The desktop hint added to a failed transcript whose `error_code` is `no_audio` (docs/API.md, DR18). */
export const NO_AUDIO_HINT = "Check your microphone and the audio device, then try again.";

/** Confirmation before a waiting recording or a failed upload is thrown away. */
export const DISCARD_CONFIRM = "Discard this recording? It can't be undone.";

const recordings = (count: number) => `${count} recording${count === 1 ? "" : "s"}`;

/** Confirmation before every waiting recording is thrown away at once (one item reads like a single Discard). */
export const discardAllConfirm = (count: number): string =>
  count === 1 ? DISCARD_CONFIRM : `Discard all ${recordings(count)}? It can't be undone.`;

/** The notice after Keep all / Discard all ("Kept 3 recordings"). */
export const bulkNotice = (verb: "Kept" | "Discarded", count: number): string => `${verb} ${recordings(count)}`;

const isCall = (item: PendingItem) => item.mode === "call" && item.sourceApp !== "voice_note";

/**
 * The one-line summary of the waiting recordings, as the phone shows it:
 * `4 calls waiting · oldest Oct 30 · 2:18 total`, or `1 call waiting · Oct 30 · 0:36 total` for one.
 * Any voice note turns the noun into "recording(s)". An empty list gives "".
 */
export function waitingSummary(items: readonly PendingItem[], timeZone?: string): string {
  if (items.length === 0) return "";
  const noun = items.every(isCall) ? "call" : "recording";
  const count = `${items.length} ${noun}${items.length === 1 ? "" : "s"} waiting`;
  const oldest = items.reduce((min, i) => {
    const t = Date.parse(i.startedAt);
    return Number.isNaN(t) ? min : Math.min(min, t);
  }, Number.POSITIVE_INFINITY);
  const day = Number.isFinite(oldest) ? new Intl.DateTimeFormat("en-US", { month: "short", day: "numeric", timeZone }).format(oldest) : "";
  const when = day && (items.length === 1 ? day : `oldest ${day}`);
  const totalMs = items.reduce((sum, i) => sum + (i.durationMs > 0 ? i.durationMs : 0), 0);
  const total = `${formatDuration(totalMs) || "0:00"} total`;
  return [count, when, total].filter(Boolean).join(" · ");
}

/** The title when set, else `<Label> call`; voice notes read `Voice note` (same wording as the review card). */
export function recordingTitle(title: string | null | undefined, sourceApp: string, mode: PendingItem["mode"] = "call"): string {
  const own = title?.trim();
  if (own) return own;
  if (mode !== "call" || sourceApp === "voice_note") return "Voice note";
  return `${sourceAppLabel(sourceApp)} call`;
}

export const pendingTitle = (item: PendingItem): string => recordingTitle(item.title, item.sourceApp, item.mode);
export const uploadTitle = (item: UploadItem): string => recordingTitle(item.title, item.sourceApp);

/** Uploads that stopped for good and wait for Retry or Discard, oldest first (the queue order). */
export const failedUploads = (items: readonly UploadItem[]): UploadItem[] => items.filter((i) => i.state === "failed");

/** Uploads whose chunks are all sent and that wait for the server to verify the file. */
export const verifyingUploads = (items: readonly UploadItem[]): UploadItem[] => items.filter((i) => i.state === "verifying");
