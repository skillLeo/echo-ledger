// Clear progress (Phase 1C, C): a recording goes through three steps, each with real progress: Upload (% of bytes, from
// the upload queue), Transcribing (parts done and time left, `progress` / `final_progress`) and Improve (the views'
// lines done, `views.progress`: an Urdu call opens in Roman Urdu, written after the Urdu transcript; the line says
// "Converting to Roman Urdu… 60%"). The Library row, the recording page and Activity say it the same way. Pure; tested.
// The words are read when they are asked for, in the account's language, or in English when the caller says `lang` "en"
// (a window still in English: classic's).
import type { ExportView, LibraryItem, TranscriptProgress, TranscriptView, UploadItem, ViewsSummary } from "./ipc";
import { wordsIn, type Lang } from "../i18n/locale.ts";

export type Step = "upload" | "transcribe" | "improve";
export type StepState = "done" | "active" | "waiting" | "failed";

/** A view's name, in `lang`'s words (the window's by default). */
export function viewName(view: TranscriptView, lang?: Lang): string {
  const { t } = wordsIn(lang);
  return view === "roman" ? t("Roman Urdu") : view === "english" ? t("English") : t("Urdu");
}

/** The views' names, in the window's language whenever one is read. */
export const VIEW_NAMES: Readonly<Record<TranscriptView, string>> = {
  get roman() {
    return viewName("roman");
  },
  get english() {
    return viewName("english");
  },
  get urdu() {
    return viewName("urdu");
  },
};

/** The units of the time left: Latin in every language. */
const UNIT = { seconds: "s", minutes: "min" } as const;

/** The time left as a count and its amount ("20 s", "3 min"), the amount one value in a line; null without one. */
function timeLeft(seconds: number | null | undefined): { n: number; amount: string } | null {
  if (seconds === null || seconds === undefined || !Number.isFinite(seconds) || seconds < 0) return null;
  if (seconds < 60) {
    const s = Math.max(5, Math.round(seconds / 5) * 5);
    return { n: s, amount: `${s} ${UNIT.seconds}` };
  }
  const min = Math.round(seconds / 60);
  return { n: min, amount: `${min} ${UNIT.minutes}` };
}

/** "about 20 s left", "about 3 min left"; null without a measure. */
export function etaText(seconds: number | null | undefined, lang?: Lang): string | null {
  const left = timeLeft(seconds);
  return left ? wordsIn(lang).tc("about :amount left", left.n, { amount: left.amount }) : null;
}

/**
 * "Transcribing… part 2 of 3 · about 20 s left", "Transcribing… about 20 s left" or "Transcribing…": each a whole
 * phrase (the parts and the time left, two, after a dot).
 */
export function transcribingLine(progress: TranscriptProgress | null | undefined, lang?: Lang): string {
  const { t, tc } = wordsIn(lang);
  if (progress && progress.total >= 2) {
    const doing = tc("Transcribing… part :n of :total", progress.total, { n: Math.min(progress.total, progress.done + 1), total: progress.total });
    const eta = etaText(progress.eta_seconds, lang);
    return eta ? `${doing} · ${eta}` : doing;
  }
  const left = timeLeft(progress?.eta_seconds);
  return left ? tc("Transcribing… about :amount left", left.n, { amount: left.amount }) : t("Transcribing…");
}

/** Percent of a view's lines done (`views.progress`), or null when the server does not say. */
export function viewPercent(progress: Partial<Record<TranscriptView, { done: number; total: number }>> | null | undefined, view: TranscriptView): number | null {
  const p = progress?.[view];
  if (!p || !(p.total > 0)) return null;
  return Math.max(0, Math.min(100, Math.round((p.done / p.total) * 100)));
}

/** The view the slim bar talks about: the one shown, else the default, else the first being made. */
export function improvingView(
  progress: Partial<Record<TranscriptView, { done: number; total: number }>> | null | undefined,
  shown: "original" | TranscriptView,
  preferred: "original" | TranscriptView | undefined,
): TranscriptView | null {
  if (shown !== "original") return shown;
  if (preferred && preferred !== "original") return preferred;
  const first = Object.keys(progress ?? {})[0] as TranscriptView | undefined;
  return first ?? null;
}

/** "Converting to Roman Urdu… 60%", or without a percent while the server has no lines done yet. */
export function convertingText(view: TranscriptView | null, percent: number | null, lang?: Lang): string {
  const { t } = wordsIn(lang);
  if (view) {
    const name = viewName(view, lang);
    return percent === null ? t("Converting to :view…", { view: name }) : t("Converting to :view… :percent%", { view: name, percent });
  }
  return percent === null ? t("Converting…") : t("Converting… :percent%", { percent });
}

/** "Couldn't convert to Roman Urdu": the view stopped, or went quiet and the server gave up on it. */
export function convertFailedText(view: TranscriptView | null, lang?: Lang): string {
  const { t } = wordsIn(lang);
  return view ? t("Couldn't convert to :view", { view: viewName(view, lang) }) : t("Couldn't convert");
}

/** The view a transcript opens in when it is not its original words (Roman Urdu for an Urdu call), else null. */
export function defaultViewOf(views: Pick<ViewsSummary, "default"> | null | undefined): TranscriptView | null {
  const view = views?.default;
  return view && view !== "original" ? view : null;
}

/**
 * What a Library row says about its default view: converting (with the percent once there is one) from the moment the
 * transcript is done, or that it failed. Null when the row opens in its original words or the view is ready.
 */
export function rowViewsLine(views: ViewsSummary | null | undefined, lang?: Lang): { state: "making" | "failed"; view: TranscriptView; text: string; percent: number | null } | null {
  const view = defaultViewOf(views);
  if (!views || !view) return null;
  if (views.status === "making") {
    const percent = viewPercent(views.progress, view);
    return { state: "making", view, text: convertingText(view, percent, lang), percent };
  }
  if (views.status === "failed") return { state: "failed", view, text: convertFailedText(view, lang), percent: null };
  return null;
}

/** "Uploading… 42%" from the upload queue's item; "Waiting to upload" while queued. */
export function uploadLine(upload: Pick<UploadItem, "state" | "progress"> | null | undefined, lang?: Lang): string {
  const { t } = wordsIn(lang);
  if (!upload) return t("Uploading…");
  if (upload.state === "queued") return t("Waiting to upload");
  if (upload.state === "verifying") return t("Uploading… checking");
  if (upload.state === "failed") return t("Upload stopped");
  return upload.progress === null ? t("Uploading…") : t("Uploading… :percent%", { percent: Math.round(upload.progress) });
}

const TRANSCRIBING = new Set(["pending", "submitted", "processing"]);

/** Which step a recording is at, and what it says (the Library row, Activity and the recording page). */
export function stepOf(item: Pick<LibraryItem, "status" | "transcript">, upload?: Pick<UploadItem, "state" | "progress"> | null, lang?: Lang): { step: Step | null; line: string | null } {
  if (upload || item.status === "uploading") return { step: "upload", line: uploadLine(upload, lang) };
  const final = item.transcript?.final_status ?? null;
  if (final === "failed" || item.status === "failed") return { step: null, line: null };
  if (TRANSCRIBING.has(final ?? "") || item.status === "processing" || (!final && item.status !== "completed")) {
    return { step: "transcribe", line: transcribingLine(item.transcript?.final_progress, lang) };
  }
  return { step: null, line: null };
}

const ORDER: Step[] = ["upload", "transcribe", "improve"];

/** The three steps for the recording page: done, the one running, and the ones after it. */
export function stepStates(step: Step | null, improving: boolean, failed: boolean): Record<Step, StepState> {
  if (failed) return { upload: "done", transcribe: "failed", improve: "waiting" };
  const at = improving ? 2 : step ? ORDER.indexOf(step) : ORDER.length;
  return Object.fromEntries(ORDER.map((s, i) => [s, i < at ? "done" : i === at ? "active" : "waiting"])) as Record<Step, StepState>;
}

/**
 * The view a list shows its recordings in (U19): the default view most of `items` open in (Roman Urdu for Urdu calls),
 * or undefined when they open in their original words. A zip in that view keeps the original words of a recording whose
 * view is not made yet, as the server does.
 */
export function shownExportView(items: readonly Pick<LibraryItem, "transcript">[]): ExportView | undefined {
  const counts = new Map<ExportView, number>();
  for (const item of items) {
    const view = defaultViewOf(item.transcript?.views);
    if (view) counts.set(view, (counts.get(view) ?? 0) + 1);
  }
  let best: ExportView | undefined;
  for (const [view, n] of counts) if (best === undefined || n > (counts.get(best) ?? 0)) best = view;
  return best;
}
