// Clear progress on the recording page (Phase 1C, C2–C4): the three steps with what each is doing, "Notify me when
// ready", and the slim "Converting to Roman Urdu… 60%" bar over a transcript that is already readable (or, when the
// view could not be written, "Couldn't convert to Roman Urdu" with Try again).
import { useState } from "react";
import { api, errorMessage, type LibraryItem, type TranscriptView } from "../../lib/ipc";
import { convertFailedText, convertingText, stepOf, stepStates, type Step } from "../../lib/progress";
import { itemState } from "../../lib/library";
import { fillWidth } from "../../lib/motion";
import type { Notify } from "../../lib/notice";
import { AlertIcon } from "../../components/Icons";

// The running step says its name; what it is doing (parts, time left, percent) is said once, under the steps.
const STEP_WORDS: Record<Step, { waiting: string; active: string; done: string }> = {
  upload: { waiting: "Upload", active: "Uploading", done: "Uploaded" },
  transcribe: { waiting: "Transcribe", active: "Transcribing", done: "Transcribed" },
  // The step keeps its name on every platform (Upload · Transcribe · Improve); only the line under it says "Converting to …".
  improve: { waiting: "Improve", active: "Improving", done: "Improved" },
};

/** The three steps while a recording is on its way; nothing once it is ready. */
export function RecordingSteps({ item, improving, notify }: {
  item: Pick<LibraryItem, "id" | "status" | "transcript">;
  /** The views are being made (the slim bar says how far). */
  improving: boolean;
  notify?: Notify;
}) {
  const [asked, setAsked] = useState(false);
  const { step, line } = stepOf(item, null, "en");
  // An upload has no other place to say its percent; a transcript's progress is said where the transcript will be.
  const failed = itemState(item) === "failed";
  if (!step && !improving) return null;
  const states = stepStates(step, improving, failed);
  const text = (s: Step): string => (s === "upload" && states[s] === "active" && line ? line : STEP_WORDS[s][states[s] === "failed" ? "waiting" : states[s]]);
  const ask = async () => {
    try {
      await api.notifyWhenReady(item.id);
      setAsked(true);
    } catch (e) {
      notify?.(errorMessage(e), true);
    }
  };
  return (
    <div className="recording-steps" data-testid="recording-steps">
      <ol className="steps" aria-label="Progress">
        {(["upload", "transcribe", "improve"] as Step[]).map((s) => (
          <li key={s} className={`step ${states[s]}`} data-testid={`step-${s}`} aria-current={states[s] === "active" ? "step" : undefined}>
            <span className="step-dot" aria-hidden="true" />{text(s)}
          </li>
        ))}
      </ol>
      {step && (asked
        ? <span className="small muted" role="status">You will get a notification when it is ready.</span>
        : <button type="button" className="btn ghost compact" onClick={() => void ask()}>Notify me when ready</button>)}
    </div>
  );
}

/** The slim bar over a readable transcript while its views are written (C3): a small spinner, the words, the percent. */
export function ConvertingBar({ view, percent }: { view: TranscriptView | null; percent: number | null }) {
  const label = convertingText(view, percent, "en");
  return (
    <div className="improving-bar" data-testid="improving-bar">
      <span className="small muted converting-line" role="status"><span className="spinner" aria-hidden="true" />{label}</span>
      <div className="improving-track" role="progressbar" aria-label={label} aria-valuemin={0} aria-valuemax={100} aria-valuenow={percent ?? undefined}>
        <div className={percent === null ? "indeterminate" : ""} style={percent === null ? undefined : fillWidth(percent / 100)} />
      </div>
    </div>
  );
}

/** The view could not be written (it stopped, or went quiet for too long): say so, and offer to start it again. */
export function ConvertFailed({ view, onRetry, retrying }: { view: TranscriptView | null; onRetry?: () => void; retrying?: boolean }) {
  return (
    <div className="convert-failed" data-testid="convert-failed" role="status">
      <span className="small muted"><AlertIcon size={14} />{convertFailedText(view, "en")}</span>
      {onRetry && <button type="button" className="btn ghost compact" disabled={retrying} onClick={onRetry}>{retrying ? "Starting…" : "Try again"}</button>}
    </div>
  );
}
