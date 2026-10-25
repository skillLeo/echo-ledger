// Helpers for the Library's "Waiting for your decision" and "Uploads that need you" sections (A2.5, A5.1).
import { sourceAppLabel } from "./format";
import type { PendingItem, UploadItem } from "./ipc";

/** The desktop hint added to a failed transcript whose `error_code` is `no_audio` (docs/API.md, DR18). */
export const NO_AUDIO_HINT = "Check your microphone and the audio device, then try again.";

/** Confirmation before a waiting recording or a failed upload is thrown away. */
export const DISCARD_CONFIRM = "Discard this recording? It can't be undone.";

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
