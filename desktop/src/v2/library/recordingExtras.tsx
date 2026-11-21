// Parts of the v2 recording page that classic's recording view has (docs/v2/audit/parity.csv DT-REC-033, DT-REC-034,
// DT-REC-037, DT-REC-049, DT-REC-104, DT-REC-105): the three steps while a recording is on its way with "Notify me when
// ready", the backup engine's line with Re-transcribe, the "Filed by" line, and the offers after an edit (always write
// a word this way for the project, add new words to the vocabulary). Same commands as classic.
import { useEffect, useState } from "react";
import { api, errorMessage, type FilingRule, type LearnOffer, type LibraryItem } from "../../lib/ipc";
import { stepOf, stepStates, type Step } from "../../lib/progress";
import { itemState } from "../../lib/library";
import { filedByRuleText } from "../../lib/sharing";
import { Button, Icons } from "../components";

/** The steps' words: what each is called before, while and after it runs. */
const STEP_WORDS: Record<Step, { waiting: string; active: string; done: string }> = {
  upload: { waiting: "Upload", active: "Uploading", done: "Uploaded" },
  transcribe: { waiting: "Transcribe", active: "Transcribing", done: "Transcribed" },
  improve: { waiting: "Improve", active: "Improving", done: "Improved" },
};
const STEPS: Step[] = ["upload", "transcribe", "improve"];
export const NOTIFY_ASKED = "You'll get a notification when it's ready.";

/**
 * Upload · Transcribe · Improve while a recording is on its way (nothing once it is ready), the one running marked for
 * screen readers too, and "Notify me when ready" while it uploads or is transcribed.
 */
export function RecordingStepsV2({ item, improving, say }: {
  item: Pick<LibraryItem, "id" | "status" | "transcript">;
  /** The views are being written (the converting line says how far). */
  improving: boolean;
  say: (message: string) => void;
}) {
  const [asked, setAsked] = useState(false);
  const [asking, setAsking] = useState(false);
  useEffect(() => setAsked(false), [item.id]);
  const { step, line } = stepOf(item);
  const failed = itemState(item) === "failed";
  if (!step && !improving) return null;
  const states = stepStates(step, improving, failed);
  const words = (s: Step) => (s === "upload" && states[s] === "active" && line ? line : STEP_WORDS[s][states[s] === "failed" ? "waiting" : states[s]]);
  const ask = async () => {
    setAsking(true);
    try {
      await api.notifyWhenReady(item.id);
      setAsked(true);
    } catch (e) {
      say(errorMessage(e));
    } finally {
      setAsking(false);
    }
  };
  return (
    <div className="el-steps-row" data-testid="recording-steps">
      <ol className="el-steps" aria-label="Progress">
        {STEPS.map((s) => (
          <li key={s} className={`el-step el-step--${states[s]}`} data-step={s} aria-current={states[s] === "active" ? "step" : undefined}>
            <span className="el-step__dot" aria-hidden="true" />
            <span className="el-caption">{words(s)}</span>
            {states[s] === "done" && <span className="el-visually-hidden"> (done)</span>}
          </li>
        ))}
      </ol>
      {step && (asked
        ? <span className="el-caption el-secondary" role="status">{NOTIFY_ASKED}</span>
        : <Button size="sm" variant="quiet" icon={Icons.BellIcon} loading={asking} loadingLabel="Asking…" onClick={() => void ask()}>Notify me when ready</Button>)}
    </div>
  );
}

/** "Backup engine": the preferred engine was busy, so another one wrote it. Re-transcribe tries the preferred one again. */
export function BackupEngineLine({ busy, onRetranscribe }: { busy: boolean; onRetranscribe: () => void }) {
  return (
    <p className="el-caption el-secondary el-recording__note" role="status" data-testid="backup-engine">
      <span>Transcribed with the backup engine. Re-transcribe?</span>
      <Button size="sm" variant="quiet" loading={busy} loadingLabel="Starting…" title="Tries the preferred engine again, in the same language" onClick={onRetranscribe}>Re-transcribe</Button>
    </p>
  );
}

/**
 * Why the recording is in its project, when it wasn't moved there by hand: "Filed by contact" (its caller is one of the
 * project's contacts) or "Filed by rule: title contains Acme" (the project's filing rules are read to say which).
 */
export function FiledByLineV2({ item }: { item: Pick<LibraryItem, "id" | "title" | "source_app" | "filed_by" | "client"> }) {
  const projectId = item.client?.id ?? null;
  const byRule = item.filed_by === "rule" && !!projectId;
  const [rules, setRules] = useState<FilingRule[] | null>(null);
  useEffect(() => {
    setRules(null);
    if (!byRule || !projectId) return;
    let alive = true;
    api.clientRules(projectId).then((r) => alive && setRules(r?.data ?? []), () => alive && setRules([]));
    return () => {
      alive = false;
    };
  }, [byRule, projectId, item.id]);
  if (item.filed_by === "contact" && item.client) {
    return <p className="el-caption el-secondary" data-testid="filed-by" dir="auto">Filed by contact: the caller is one of {item.client.name}'s contacts</p>;
  }
  if (!byRule) return null;
  return <p className="el-caption el-secondary" data-testid="filed-by" dir="auto">{filedByRuleText(item, rules)}</p>;
}

/** After an edit in a project's recording: "Always write X as Y for Acme?", one offer at a time. */
export function LearnOfferV2({ offer, onAnswer }: { offer: LearnOffer; onAnswer: (yes: boolean) => void }) {
  return (
    <div className="el-recording__offer" role="group" aria-label="Always write it this way">
      <span dir="auto">Always write <b>{offer.from}</b> as <b>{offer.to}</b> for {offer.client_name}?</span>
      <Button size="sm" variant="primary" onClick={() => onAnswer(true)}>Yes</Button>
      <Button size="sm" variant="quiet" onClick={() => onAnswer(false)}>No</Button>
    </div>
  );
}

/** After an edit that typed new names or numbers: add them to the vocabulary, so the engine gets them right next time. */
export function VocabularyOfferV2({ terms, projectName, onAdd, onDismiss }: {
  terms: readonly string[];
  projectName: string | null;
  onAdd: (term: string) => void;
  onDismiss: () => void;
}) {
  if (!terms.length) return null;
  return (
    <div className="el-recording__offer" role="group" aria-label="Add to the vocabulary">
      <span dir="auto">Add to {projectName ? `${projectName}'s` : "the"} vocabulary so the engine gets it right next time:</span>
      {terms.map((t) => <Button key={t} size="sm" icon={Icons.PlusIcon} aria-label={`Add ${t} to the vocabulary`} onClick={() => onAdd(t)}><span dir="auto">{t}</span></Button>)}
      <Button size="sm" variant="quiet" onClick={onDismiss}>Not now</Button>
    </div>
  );
}
