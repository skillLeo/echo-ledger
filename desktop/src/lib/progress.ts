// Clear progress (Phase 1C, C): a recording goes through three steps, each with real progress: Upload (% of bytes, from
// the upload queue), Transcribing (parts done and time left, `progress` / `final_progress`) and Improving (the views'
// lines done, `views.progress`). The Library row, the recording page and Activity say it the same way. Pure; tested.
import type { LibraryItem, TranscriptProgress, TranscriptView, UploadItem } from "./ipc";

export type Step = "upload" | "transcribe" | "improve";
export type StepState = "done" | "active" | "waiting" | "failed";

export const VIEW_NAMES: Record<TranscriptView, string> = { roman: "Roman Urdu", english: "English", urdu: "Urdu" };

/** "about 20 s left", "about 3 min left"; null without a measure. */
export function etaText(seconds: number | null | undefined): string | null {
  if (seconds === null || seconds === undefined || !Number.isFinite(seconds) || seconds < 0) return null;
  if (seconds < 60) return `about ${Math.max(5, Math.round(seconds / 5) * 5)} s left`;
  return `about ${Math.round(seconds / 60)} min left`;
}

/** "Transcribing… part 2 of 3 · about 20 s left", "Transcribing… about 20 s left" or "Transcribing…". */
export function transcribingLine(progress: TranscriptProgress | null | undefined): string {
  const parts = progress && progress.total >= 2 ? `part ${Math.min(progress.total, progress.done + 1)} of ${progress.total}` : null;
  const eta = etaText(progress?.eta_seconds);
  return ["Transcribing…", [parts, eta].filter(Boolean).join(" · ")].filter(Boolean).join(" ");
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

/** "Improving Roman Urdu… 60%", or without a percent when the server does not say. */
export function improvingText(view: TranscriptView | null, percent: number | null): string {
  const name = view ? VIEW_NAMES[view] : "the views";
  return percent === null ? `Improving ${name}…` : `Improving ${name}… ${percent}%`;
}

/** "Uploading… 42%" from the upload queue's item; "Waiting to upload" while queued. */
export function uploadLine(upload: Pick<UploadItem, "state" | "progress"> | null | undefined): string {
  if (!upload) return "Uploading…";
  if (upload.state === "queued") return "Waiting to upload";
  if (upload.state === "verifying") return "Uploading… checking";
  if (upload.state === "failed") return "Upload stopped";
  return upload.progress === null ? "Uploading…" : `Uploading… ${Math.round(upload.progress)}%`;
}

const TRANSCRIBING = new Set(["pending", "submitted", "processing"]);

/** Which step a recording is at, and what it says (the Library row, Activity and the recording page). */
export function stepOf(item: Pick<LibraryItem, "status" | "transcript">, upload?: Pick<UploadItem, "state" | "progress"> | null): { step: Step | null; line: string | null } {
  if (upload || item.status === "uploading") return { step: "upload", line: uploadLine(upload) };
  const final = item.transcript?.final_status ?? null;
  if (final === "failed" || item.status === "failed") return { step: null, line: null };
  if (TRANSCRIBING.has(final ?? "") || item.status === "processing" || (!final && item.status !== "completed")) {
    return { step: "transcribe", line: transcribingLine(item.transcript?.final_progress) };
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
