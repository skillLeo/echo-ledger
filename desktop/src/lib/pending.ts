// Helpers for the Library's waiting line ("2 calls waiting · Review") and "Uploads that need you" (A2.5, A5.1, v1.5).
import { formatDuration, formatWhen, sourceAppLabel } from "./format";
import type { PendingItem, SkipReason, SkippedItem, UploadItem } from "./ipc";

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

/** Recordings that wait for the person: one whose after-call chip still counts down keeps itself and is not listed. */
export const waitingItems = (items: readonly PendingItem[]): PendingItem[] => items.filter((i) => !i.deadlineAt);

/** The Library's slim line, "3 calls waiting" ("recordings" once a voice note is among them); "" for none. */
export function waitingLine(items: readonly PendingItem[]): string {
  if (items.length === 0) return "";
  const noun = items.every(isCall) ? "call" : "recording";
  return `${items.length} ${noun}${items.length === 1 ? "" : "s"} waiting`;
}

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

/** The title when set, else "Call with <name>" when the meeting app named the other side, else `<Label> call`; voice
 * notes read `Voice note`. */
export function recordingTitle(title: string | null | undefined, sourceApp: string, mode: PendingItem["mode"] = "call", callerName?: string | null): string {
  const own = title?.trim();
  if (own) return own;
  if (mode !== "call" || sourceApp === "voice_note") return "Voice note";
  const caller = callerName?.trim();
  if (caller) return `Call with ${caller}`;
  return `${sourceAppLabel(sourceApp)} call`;
}

export const pendingTitle = (item: PendingItem): string => recordingTitle(item.title, item.sourceApp, item.mode, item.callerName);
export const uploadTitle = (item: UploadItem): string => recordingTitle(item.title, item.sourceApp);

const ours = (items: readonly UploadItem[]): UploadItem[] => items.filter((i) => !i.heldFor);

/** Uploads that stopped for good and wait for Retry or Discard, oldest first (the queue order). */
export const failedUploads = (items: readonly UploadItem[]): UploadItem[] => ours(items).filter((i) => i.state === "failed");

/** Uploads whose chunks are all sent and that wait for the server to verify the file. */
export const verifyingUploads = (items: readonly UploadItem[]): UploadItem[] => ours(items).filter((i) => i.state === "verifying");

/** Imported files on their way up (recordings made here upload quietly; the sidebar says "Uploading…"). */
export const importingUploads = (items: readonly UploadItem[]): UploadItem[] =>
  ours(items).filter((i) => i.imported && (i.state === "queued" || i.state === "uploading"));

/** Recordings made while another account was signed in here (v1.5), by the account they wait for, oldest first. */
export function heldUploads(items: readonly UploadItem[]): [string, UploadItem[]][] {
  const byAccount = new Map<string, UploadItem[]>();
  for (const item of items) {
    if (!item.heldFor) continue;
    byAccount.set(item.heldFor, [...(byAccount.get(item.heldFor) ?? []), item]);
  }
  return [...byAccount.entries()];
}

/** "2 recordings made while signed in as sara@example.com wait for that account. …" */
export function heldLine(account: string, count: number): string {
  const made = count === 1 ? "1 recording made" : `${count} recordings made`;
  const them = count === 1 ? "it" : "them";
  return `${made} while signed in as ${account} ${count === 1 ? "waits" : "wait"} for that account. Sign out and sign in as ${account} to upload ${them}, or discard ${them}.`;
}

/** Where one import is: waiting its turn, or how much of it the server has. */
export function importProgressText(item: UploadItem): string {
  if (item.state === "queued") return "Waiting to upload";
  return item.progress != null ? `Uploading ${item.progress}%` : "Uploading…";
}

/** "Skip calls shorter than" (v1.5): the choices in seconds, 0 = never; 10 by default (same as the phone). */
export const SKIP_SHORTER_THAN: readonly (readonly [number, string])[] = [[0, "Never"], [5, "5 s"], [10, "10 s (default)"], [20, "20 s"], [30, "30 s"]];

export const SKIP_REASON_LABELS: Record<SkipReason, string> = { too_short: "Too short", no_speech: "No speech" };

/** Activity's quiet line: "Skipped 3 short calls" / "Skipped 1 short call" (the phone's wording). */
export const skippedLine = (count: number): string => (count === 1 ? "Skipped 1 short call" : `Skipped ${count} short calls`);

/** One skipped call: "WhatsApp · Today · 9:12 AM · 0:06 · Too short" (with the caller or the title first when known). */
export function skippedMeta(item: SkippedItem, now?: Date): string {
  const who = item.title?.trim() || (item.callerName?.trim() ? `Call with ${item.callerName.trim()}` : sourceAppLabel(item.sourceApp));
  const reason = SKIP_REASON_LABELS[item.reason] ?? "Skipped";
  return [who, formatWhen(item.startedAt, now), formatDuration(item.durationMs) || "0:00", reason].filter(Boolean).join(" · ");
}
