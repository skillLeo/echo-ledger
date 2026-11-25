// Helpers for the Library's waiting line ("2 calls waiting · Review") and "Uploads that need you" (A2.5, A5.1, v1.5).
// The words are read when they are asked for, in the account's language (src/i18n), or in English when the caller says
// `lang` "en": a window still in English (classic's) keeps exactly the English it always had.
import { formatDuration, formatWhen, sourceAppLabel, whenPart } from "./format";
import type { PendingItem, SkipReason, SkippedItem, UploadItem } from "./ipc";
import type { ConfirmQuestion } from "./confirm";
import { language, wordsIn, type Lang } from "../i18n";

/** Whether `lang`'s words are English: asked for in English, or the window speaks English. */
const english = (lang?: Lang) => lang === "en" || language() === "en";

/** The desktop hint added to a failed transcript whose `error_code` is `no_audio` (docs/API.md, DR18). */
export function noAudioHint(lang?: Lang): string {
  return wordsIn(lang).t("Check your microphone and the audio device, then try again.");
}
/** The same hint in English, for a window still in English (classic's recording view). */
export const NO_AUDIO_HINT = noAudioHint("en");

/** Confirmation before a waiting recording or a failed upload is thrown away (lib/confirm.ts). */
export function discardQuestion(lang?: Lang): ConfirmQuestion {
  const { t } = wordsIn(lang);
  return { title: t("Discard this recording?"), body: t("It can't be undone."), confirm: t("Discard"), tone: "danger" };
}
/** The question in English, for a window still in English (classic's queue and recorder). */
export const DISCARD_QUESTION = discardQuestion("en");
/** The same for several at once. */
export function discardAllQuestion(count: number, lang?: Lang): ConfirmQuestion {
  if (count === 1) return english(lang) ? DISCARD_QUESTION : discardQuestion(lang);
  const { t, tc } = wordsIn(lang);
  return { title: tc("Discard all :count recordings?", count), body: t("It can't be undone."), confirm: t("Discard all"), tone: "danger" };
}

/** The waiting list's Delete (the call choice's words, docs/v2/copy/call-choice.md): the question before one or several
 * waiting recordings are deleted. Undo stays on the notice after it for a few seconds, so nothing goes at once. */
export function deleteWaitingQuestion(count: number, lang?: Lang): ConfirmQuestion {
  const { t, tc } = wordsIn(lang);
  if (count === 1) return { title: t("Delete this recording?"), body: t("You can undo it for a few seconds."), confirm: t("Delete"), tone: "danger" };
  return { title: tc("Delete all :count recordings?", count), body: t("You can undo it for a few seconds."), confirm: t("Delete all"), tone: "danger" };
}
/** The question for one, in English (classic's queue). */
export const DELETE_WAITING_QUESTION = deleteWaitingQuestion(1, "en");

/** The notice after Delete / Delete all, with its Undo: "Deleted · Undo", "Deleted 3 recordings · Undo". */
export function deletedNotice(count: number, lang?: Lang): string {
  const { t, tc } = wordsIn(lang);
  return count === 1 ? t("Deleted · Undo") : tc("Deleted :count recordings · Undo", count);
}

/** The notice after Transcribe all, Save audio only for all, or a failed upload's Discard all. */
export function bulkNotice(verb: "Transcribing" | "Saved as audio" | "Discarded" | "Deleted", count: number, lang?: Lang): string {
  const { tc } = wordsIn(lang);
  switch (verb) {
    case "Transcribing":
      return tc("Transcribing :count recording|Transcribing :count recordings", count);
    case "Saved as audio":
      return tc("Saved :count recording as audio|Saved :count recordings as audio", count);
    case "Deleted":
      return tc("Deleted :count recording|Deleted :count recordings", count);
    default:
      return tc("Discarded :count recording|Discarded :count recordings", count);
  }
}

const isCall = (item: PendingItem) => item.mode === "call" && item.sourceApp !== "voice_note";

/** Recordings that wait for the person: one whose after-call chip still counts down keeps itself and is not listed. */
export const waitingItems = (items: readonly PendingItem[]): PendingItem[] => items.filter((i) => !i.deadlineAt);

/** "3 calls waiting" ("recordings" once a voice note is among them). */
function waitingCount(items: readonly PendingItem[], lang?: Lang): string {
  const { tc } = wordsIn(lang);
  return items.every(isCall)
    ? tc(":count call waiting|:count calls waiting", items.length)
    : tc(":count recording waiting|:count recordings waiting", items.length);
}

/** The Library's slim line, "3 calls waiting" ("recordings" once a voice note is among them); "" for none. */
export function waitingLine(items: readonly PendingItem[], lang?: Lang): string {
  return items.length === 0 ? "" : waitingCount(items, lang);
}

/**
 * The one-line summary of the waiting recordings, as the phone shows it:
 * `4 calls waiting · oldest Oct 30 · 2:18 total`, or `1 call waiting · Oct 30 · 0:36 total` for one.
 * Any voice note turns the noun into "recording(s)". An empty list gives "". The day is in `timeZone` (this computer's
 * by default); in Urdu it has whenParts' shape for a date ("30 اکتوبر") and its own isolate (whenPart, or t()'s).
 */
export function waitingSummary(items: readonly PendingItem[], timeZone?: string, lang?: Lang): string {
  if (items.length === 0) return "";
  const { t } = wordsIn(lang);
  const oldest = items.reduce((min, i) => {
    const at = Date.parse(i.startedAt);
    return Number.isNaN(at) ? min : Math.min(min, at);
  }, Number.POSITIVE_INFINITY);
  const day = !Number.isFinite(oldest) ? ""
    : new Intl.DateTimeFormat(english(lang) ? "en-US" : "ur-PK", { month: "short", day: "numeric", timeZone }).format(oldest);
  const when = day && (items.length === 1 ? (english(lang) ? day : whenPart(day)) : t("oldest :day", { day }));
  const totalMs = items.reduce((sum, i) => sum + (i.durationMs > 0 ? i.durationMs : 0), 0);
  const total = t(":time total", { time: formatDuration(totalMs) || "0:00" });
  return [waitingCount(items, lang), when, total].filter(Boolean).join(" · ");
}

/** The title when set, else "Call with <name>" when the meeting app named the other side, else `<Label> call`; voice
 * notes read `Voice note`, and a phone call `Phone call` (never "Phone call call"). */
export function recordingTitle(title: string | null | undefined, sourceApp: string, mode: PendingItem["mode"] = "call", callerName?: string | null, lang?: Lang): string {
  const own = title?.trim();
  if (own) return own;
  const { t } = wordsIn(lang);
  if (mode !== "call" || sourceApp === "voice_note") return t("Voice note");
  const caller = callerName?.trim();
  if (caller) return t("Call with :name", { name: caller });
  // The phone's label is already a call.
  if (sourceApp === "phone_call") return sourceAppLabel(sourceApp, lang);
  return t(":app call", { app: sourceAppLabel(sourceApp, lang) });
}

export const pendingTitle = (item: PendingItem, lang?: Lang): string => recordingTitle(item.title, item.sourceApp, item.mode, item.callerName, lang);
export const uploadTitle = (item: UploadItem, lang?: Lang): string => recordingTitle(item.title, item.sourceApp, "call", null, lang);

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
export function heldLine(account: string, count: number, lang?: Lang): string {
  return wordsIn(lang).tc(
    ":count recording made while signed in as :account waits for that account. Sign out and sign in as :account to upload it, or discard it.|:count recordings made while signed in as :account wait for that account. Sign out and sign in as :account to upload them, or discard them.",
    count, { account },
  );
}

/** Where one import is: waiting its turn, or how much of it the server has. */
export function importProgressText(item: UploadItem, lang?: Lang): string {
  const { t } = wordsIn(lang);
  if (item.state === "queued") return t("Waiting to upload");
  return item.progress != null ? t("Uploading :percent%", { percent: item.progress }) : t("Uploading…");
}

/** "Skip calls shorter than" (v1.5): the choices in seconds, 0 = never; 10 by default (same as the phone). The units
 * stay Latin in every language. */
export function skipShorterThan(lang?: Lang): readonly (readonly [number, string])[] {
  const { t } = wordsIn(lang);
  const seconds = (n: number) => `${n} s`;
  return [[0, t("Never")], [5, seconds(5)], [10, t(":amount (default)", { amount: seconds(10) })], [20, seconds(20)], [30, seconds(30)]];
}

/** Why a call was skipped, in words. */
export function skipReasonText(reason: SkipReason, lang?: Lang): string {
  const { t } = wordsIn(lang);
  return reason === "too_short" ? t("Too short") : reason === "no_speech" ? t("No speech") : t("Skipped");
}

/** Activity's quiet line: "Skipped 3 short calls" / "Skipped 1 short call" (the phone's wording). */
export const skippedLine = (count: number, lang?: Lang): string => wordsIn(lang).tc("Skipped :count short call|Skipped :count short calls", count);

/** One skipped call: "WhatsApp · Today · 9:12 AM · 0:06 · Too short" (with the caller or the title first when known). */
export function skippedMeta(item: SkippedItem, now?: Date, lang?: Lang): string {
  const { t } = wordsIn(lang);
  const caller = item.callerName?.trim();
  const who = item.title?.trim() || (caller ? t("Call with :name", { name: caller }) : sourceAppLabel(item.sourceApp, lang));
  return [who, formatWhen(item.startedAt, now), formatDuration(item.durationMs) || "0:00", skipReasonText(item.reason, lang)].filter(Boolean).join(" · ");
}
