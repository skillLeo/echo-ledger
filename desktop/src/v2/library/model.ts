// What the v2 Library shows of the core's recordings (api.library(), the same list today's Library uses): the saved
// views, the WhatsApp-style row and the day groups. Pure functions, so the rules are tested without a window.
import type { BinItem, LibraryItem, ViewsSummary } from "../../lib/ipc";
import { rowViewsLine } from "../../lib/progress";
import { TRASH_KEEP_HOURS_FALLBACK } from "../../lib/trash";
import { inSmartView } from "../../lib/organise";
import { dayGroup, itemState, recordingName, searchItems, sortItems } from "../../lib/library";
import { formatTime, rowDate } from "../../lib/format";
import { partsText } from "../../lib/languages";
import { facetsOf, kindOfItem, sourceOf, statusOf, type Kind } from "./facets";
import { t, tc } from "../../i18n";

/** The saved views (classic's smart views and the new design's Untranscribed), and Trash. */
export type SavedView = "all" | "today" | "this_week" | "untranscribed" | "waiting" | "pinned" | "bookmarked" | "failed" | "trash";
export type ListView = Exclude<SavedView, "trash">;
/** The Views ▾ menu: names in classic's words, in its order (Untranscribed after the dates), in the window's language. */
export const VIEW_NAMES: Record<ListView, string> = {
  get all() { return t("All recordings"); },
  get today() { return t("Today"); },
  get this_week() { return t("This week"); },
  get untranscribed() { return t("Untranscribed"); },
  get waiting() { return t("Waiting for decision"); },
  get pinned() { return t("Pinned"); },
  get bookmarked() { return t("Bookmarked"); },
  get failed() { return t("Failed"); },
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

/**
 * A row's state, in the glossary's words (docs/PLAN_V2_1_POLISH.md section 1; contract 2.0.0-r `facets.state`): Audio
 * only, Transcribing…, Failed · Retry (the only red on a row), Waiting to upload, Waiting (a call waiting for the person's
 * choice after it), and No audio, grey too (nothing was heard: Retry would not help). A transcribed recording has none: its row
 * shows its first words.
 */
export type RowStateKey = "audio_only" | "transcribing" | "failed" | "no_audio" | "waiting_to_upload" | "waiting";
export type RowState = { key: RowStateKey; text: string; tone: "quiet" | "danger" };
/** Each state's words, in the window's language. */
export const STATE_WORDS: Record<RowStateKey, string> = {
  get audio_only() { return t("Audio only"); },
  get transcribing() { return t("Transcribing…"); },
  get failed() { return t("Failed · Retry"); },
  get no_audio() { return t("No audio"); },
  get waiting_to_upload() { return t("Waiting to upload"); },
  get waiting() { return t("Waiting"); },
};
const SERVER_STATES = new Set(["audio_only", "transcribing", "failed", "waiting_to_upload", "waiting"]);

/** A recording's state (null: transcribed): the server's `facets.state` when it sent one, else from its one Status. */
export function rowState(item: LibraryItem): RowState | null {
  const state = (key: RowStateKey): RowState => ({ key, text: STATE_WORDS[key], tone: key === "failed" ? "danger" : "quiet" });
  if (item.transcript?.final_error_code === "no_audio") return state("no_audio");
  const sent = facetsOf(item)?.state;
  if (sent === "transcribed") return null;
  if (sent && SERVER_STATES.has(sent)) return state(sent as RowStateKey);
  // A call still waiting for the person's choice (the session is `recording`): the contract's Status is processing, so
  // its state is Waiting, whatever Status this computer works out (it counts the call in no Status).
  if (item.status === "recording") return state("waiting");
  switch (statusOf(item)) {
    case "failed": return state("failed");
    case "transcribed": return null;
    case "not_transcribed": return state("audio_only");
    default: return state(item.status === "recording" ? "waiting" : item.status === "uploading" ? "waiting_to_upload" : "transcribing");
  }
}

/**
 * A length as a row says it (UX-06): "44s" under a minute, "2m 38s" under an hour, "1h 04m" from an hour; never a clock
 * ("2:38") that reads like the time beside it.
 */
export function rowDuration(ms?: number | null): string {
  if (ms == null || ms <= 0) return "";
  const total = Math.max(1, Math.round(ms / 1000));
  if (total < 60) return `${total}s`;
  if (total < 3600) return `${Math.floor(total / 60)}m ${String(total % 60).padStart(2, "0")}s`;
  return `${Math.floor(total / 3600)}h ${String(Math.floor((total % 3600) / 60)).padStart(2, "0")}m`;
}

/**
 * When a row's recording happened (UX-06): in a date group (Today, Yesterday, This week) only the time; elsewhere
 * (Pinned, other groupings, search) today the time, yesterday "Yesterday", earlier "12 Nov", another year "12 Nov 2025".
 */
export function rowWhen(iso: string | null | undefined, now: Date = new Date(), timeOnly = false): string {
  const at = iso ? new Date(iso) : null;
  if (!at || Number.isNaN(at.getTime())) return "";
  if (timeOnly || at.toDateString() === now.toDateString()) return formatTime(at);
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
  return at.toDateString() === yesterday.toDateString() ? t("Yesterday") : rowDate(iso, now);
}

/** The date groups whose rows show only the time (their heading says the day). */
export const TIME_ONLY_GROUPS: ReadonlySet<string> = new Set(["date:Today", "date:Yesterday", "date:This week"]);

/** A row's mark: a voice note's mic, a meeting's calendar, a caller's initials (else a person), a file for the rest. */
export type RowAvatar = { initials: string | null; icon: "mic" | "calendar" | "person" | "file"; tint: number };
/** How many tints a row's initials circle can have (the label colours, red left for failures). */
export const AVATAR_TINTS = 7;

/** "Hammad Bhai Uol" → "HU", "Omar" → "O"; a number or nothing → null (the row shows a person). */
export function initialsOf(name: string): string | null {
  const words = name.split(/\s+/).map((w) => w.replace(/[^\p{L}]/gu, "")).filter(Boolean);
  if (!words.length) return null;
  const first = [...words[0]][0];
  const last = words.length > 1 ? [...words[words.length - 1]][0] : "";
  return (first + last).toLocaleUpperCase();
}

const tintOf = (name: string) => [...name].reduce((h, c) => (h * 31 + (c.codePointAt(0) ?? 0)) >>> 0, 7) % AVATAR_TINTS;

/** A call's title without "Call with" (UX-06): the name or the number; an untitled call is an unknown caller. */
export function rowTitle(item: Pick<LibraryItem, "title" | "transcript">, kind: Kind): string {
  return titleOf(item, kind).title;
}

/**
 * rowTitle, and whether it is the app's own word for no title ("Voice note", "Unknown caller", in the window's
 * language): then the row's initials and tint don't come from it, so they never change with the language.
 */
function titleOf(item: Pick<LibraryItem, "title" | "transcript">, kind: Kind): { title: string; fallback: boolean } {
  const raw = (item.title ?? "").trim();
  const name = raw.replace(/^call with\s+/i, "").trim();
  if (kind === "voice_note" && (!raw || /^voice note$/i.test(raw))) {
    // A voice note is titled with its first words when it has a transcript (UX-06), else "Voice note".
    const words = (item.transcript?.preview ?? "").trim().split(/\s+/).filter(Boolean);
    return words.length ? { title: words.slice(0, 6).join(" ") + (words.length > 6 ? "…" : ""), fallback: false } : { title: t("Voice note"), fallback: true };
  }
  if (kind === "call" && !name) return { title: t("Unknown caller"), fallback: true };
  return { title: name || recordingName(raw), fallback: false };
}

export type RowModel = {
  /** Line 1: who or what (UX-06: no "Call with"). */
  title: string;
  /** Line 2: the first words of the transcript, or the state in the glossary's words. */
  meta: string;
  /** The glossary's state (null: transcribed, line 2 is its words). */
  state: RowState | null;
  /** Beside line 1: when (rowWhen). */
  when: string;
  /** Beside line 2: how long ("2m 38s"). */
  duration: string;
  app: string;
  kind: Kind;
  avatar: RowAvatar;
  /** Its screen moments, for the screens mark (0: none, or a server without them). */
  screens: number;
  /** Its default view (Roman Urdu for an Urdu call) is being written, with a spinner, or could not be (U19). */
  converting: "making" | "failed" | null;
};

/** A recording as a list row (UX-06 + DES-04): who or what and when, then the first words or the state and how long. */
export function rowModel(item: LibraryItem, now: Date = new Date(), views: ViewsSummary | null | undefined = item.transcript?.views, timeOnly = false): RowModel {
  const app = item.source_app ?? (item.source_device === "phone" ? "phone_call" : "other");
  const kind = kindOfItem(item, sourceOf(item).value);
  const state = rowState(item);
  const preview = item.transcript?.preview?.trim() || null;
  // A ready transcript whose default view is still written says "Converting to Roman Urdu… 60%" in place of its first
  // words (they come in Urdu script until then); one that could not be written says so before them.
  const converting = state === null ? rowViewsLine(views) : null;
  // Transcribing says which part it is on (classic's step line).
  const parts = state?.key === "transcribing" ? partsText(item.transcript?.final_progress) : null;
  const meta = converting?.state === "making" ? converting.text
    : state === null ? [converting?.text, preview ?? ""].filter(Boolean).join(" · ")
      : parts ? `${state.text} ${parts}` : state.text;
  const { title, fallback } = titleOf(item, kind);
  // From the caller's name, never from the app's words for none: an unknown caller shows the person, in any language.
  const initials = kind === "call" && !fallback ? initialsOf(title) : null;
  const avatar: RowAvatar = {
    initials,
    icon: kind === "voice_note" ? "mic" : kind === "meeting" ? "calendar" : kind === "call" ? "person" : "file",
    tint: tintOf(fallback ? item.id : title),
  };
  return {
    title, meta, state, when: rowWhen(item.started_at, now, timeOnly), duration: rowDuration(item.duration_ms), app, kind, avatar,
    screens: item.screen_count ?? 0, converting: converting?.state ?? null,
  };
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
  return t("Deleted recordings stay here for :time, then their audio and transcripts are erased.", { time: trashTime(hours) });
}

/** When a recording in Trash is erased: "erased in 5 hours", "erased in 1 hour", "erased soon" (classic's eraseIn). */
export function eraseInWords(eraseAt: string, now: number = Date.now()): string {
  const hours = Math.floor((Date.parse(eraseAt) - now) / 3_600_000);
  return hours >= 1 ? tc("erased in :count hour|erased in :count hours", hours) : t("erased soon");
}

/**
 * How long Trash keeps a recording, in the window's language: "48 hours", "1 hour", whole days above 48 hours ("30 days"),
 * as lib/trash.ts hoursWords says it (which stays English for the pages not in Urdu yet).
 */
export function trashTime(hours: number | null | undefined): string {
  const h = hours ?? TRASH_KEEP_HOURS_FALLBACK;
  if (h > 48 && h % 24 === 0) return t(":days days", { days: h / 24 });
  return h === 1 ? t("1 hour") : t(":hours hours", { hours: h });
}

/**
 * A group's heading (lib/libraryGroups' English) in the window's language when it is the app's own word (a day, a state,
 * Pinned, Recordings, the fallbacks); a project's, a source's or a device's name stays as it is.
 */
const OWN_GROUP = /^(date|status):|^(pinned|none|project:No project|device:Unknown device|language:Automatic)$/;
/** Group by Source: lib/libraryGroups' own words for voice notes and for no source at all, not a source's name. */
const ownSource = (g: { key: string; items?: readonly Pick<LibraryItem, "source_app" | "source_app_label">[] }): boolean => {
  if (!g.items?.length) return false;
  if (g.key === "app:Voice notes") return g.items.every((i) => i.source_app === "voice_note");
  if (g.key === "app:Other") return g.items.every((i) => !i.source_app_label && !i.source_app);
  return false;
};
export const groupTitle = (g: { key: string; label: string; items?: readonly Pick<LibraryItem, "source_app" | "source_app_label">[] }): string =>
  (OWN_GROUP.test(g.key) || ownSource(g) ? t(g.label) : g.label);

/** "1 recording", "3 recordings": a count of recordings in the window's language. */
export const recordings = (n: number): string => tc(":count recording|:count recordings", n);
