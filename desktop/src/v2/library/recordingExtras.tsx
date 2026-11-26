// Parts of the v2 recording page that classic's recording view has (docs/v2/audit/parity.csv DT-REC-033, DT-REC-034,
// DT-REC-037, DT-REC-049, DT-REC-104, DT-REC-105): the three steps while a recording is on its way with "Notify me when
// ready", the backup engine's line with Re-transcribe, the "Filed by" line, and the offers after an edit (always write
// a word this way for the project, add new words to the vocabulary). Same commands as classic. In the account's language.
import { useEffect, useState } from "react";
import { api, errorMessage, type FilingRule, type LearnOffer, type LibraryItem } from "../../lib/ipc";
import { stepOf, stepStates, type Step } from "../../lib/progress";
import { itemState } from "../../lib/library";
import { filedByRuleText } from "../../lib/sharing";
import { Button, Icons } from "../components";
import { sayProblem, type Say } from "./organise";
import { lineDir, useT, wordsIn, withParts, type Lang } from "../../i18n";

/** The steps' words: what each is called before, while and after it runs. */
function stepWords(step: Step, state: "waiting" | "active" | "done", t: (text: string) => string): string {
  switch (step) {
    case "upload":
      return state === "waiting" ? t("Upload") : state === "active" ? t("Uploading") : t("Uploaded");
    case "transcribe":
      return state === "waiting" ? t("Transcribe") : state === "active" ? t("Transcribing") : t("Transcribed");
    default:
      return state === "waiting" ? t("Improve") : state === "active" ? t("Improving") : t("Improved");
  }
}
const STEPS: Step[] = ["upload", "transcribe", "improve"];
/** What the page says once "Notify me when ready" is asked. */
export function notifyAsked(lang?: Lang): string {
  return wordsIn(lang).t("You'll get a notification when it's ready.");
}
/** The same, in English (as the tests read it). */
export const NOTIFY_ASKED = notifyAsked("en");

/**
 * Upload · Transcribe · Improve while a recording is on its way (nothing once it is ready), the one running marked for
 * screen readers too, and "Notify me when ready" while it uploads or is transcribed.
 */
export function RecordingStepsV2({ item, improving, say }: {
  item: Pick<LibraryItem, "id" | "status" | "transcript">;
  /** The views are being written (the converting line says how far). */
  improving: boolean;
  say: Say;
}) {
  const { t } = useT();
  const [asked, setAsked] = useState(false);
  const [asking, setAsking] = useState(false);
  useEffect(() => setAsked(false), [item.id]);
  const { step, line } = stepOf(item);
  const failed = itemState(item) === "failed";
  if (!step && !improving) return null;
  const states = stepStates(step, improving, failed);
  const words = (s: Step) => {
    const state = states[s];
    return s === "upload" && state === "active" && line ? line : stepWords(s, state === "failed" ? "waiting" : state, t);
  };
  const ask = async () => {
    setAsking(true);
    try {
      await api.notifyWhenReady(item.id);
      setAsked(true);
    } catch (e) {
      sayProblem(say, errorMessage(e));
    } finally {
      setAsking(false);
    }
  };
  return (
    <div className="el-rec-steps-row" data-testid="recording-steps">
      <ol className="el-rec-steps" aria-label={t("Progress")}>
        {STEPS.map((s) => (
          <li key={s} className={`el-rec-step el-rec-step--${states[s]}`} data-step={s} aria-current={states[s] === "active" ? "step" : undefined}>
            <span className="el-rec-step__dot" aria-hidden="true" />
            <span className="el-caption">{words(s)}</span>
            {states[s] === "done" && <span className="el-visually-hidden"> {t("(done)")}</span>}
          </li>
        ))}
      </ol>
      {step && (asked
        ? <span className="el-caption el-secondary" role="status">{notifyAsked()}</span>
        : <Button size="sm" variant="quiet" icon={Icons.BellIcon} loading={asking} loadingLabel={t("Asking…")} onClick={() => void ask()}>{t("Notify me when ready")}</Button>)}
    </div>
  );
}

/** "Backup engine": the preferred engine was busy, so another one wrote it. Re-transcribe tries the preferred one again. */
export function BackupEngineLine({ busy, onRetranscribe }: { busy: boolean; onRetranscribe: () => void }) {
  const { t } = useT();
  return (
    <p className="el-caption el-secondary el-recording__note" data-testid="backup-engine">
      <span role="status">{t("Transcribed with the backup engine. Re-transcribe?")}</span>
      <Button size="sm" variant="quiet" loading={busy} loadingLabel={t("Starting…")} title={t("Tries the preferred engine again, in the same language")} onClick={onRetranscribe}>{t("Re-transcribe")}</Button>
    </p>
  );
}

/**
 * Why the recording is in its project, when it wasn't moved there by hand: "Filed by contact" (its caller is one of the
 * project's contacts) or "Filed by rule: title contains Acme" (the project's filing rules are read to say which).
 */
export function FiledByLineV2({ item }: { item: Pick<LibraryItem, "id" | "title" | "source_app" | "filed_by" | "client"> }) {
  const { t } = useT();
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
    const line = t("Filed by contact: the caller is one of :project's contacts", { project: item.client.name });
    return <p className="el-caption el-secondary" data-testid="filed-by" dir={lineDir(line)}>{line}</p>;
  }
  if (!byRule) return null;
  const line = filedByRuleText(item, rules);
  return <p className="el-caption el-secondary" data-testid="filed-by" dir={lineDir(line)}>{line}</p>;
}

/** After an edit in a project's recording: "Always write X as Y for Acme?", one offer at a time. */
export function LearnOfferV2({ offer, onAnswer }: { offer: LearnOffer; onAnswer: (yes: boolean) => void }) {
  const { t, rtl } = useT();
  return (
    <div className="el-recording__offer" role="group" aria-label={t("Always write it this way")}>
      <span dir={rtl ? "rtl" : "auto"}>{withParts(t("Always write :from as :to for :project?"), { from: <b>{offer.from}</b>, to: <b>{offer.to}</b>, project: offer.client_name })}</span>
      <Button size="sm" variant="primary" onClick={() => onAnswer(true)}>{t("Yes")}</Button>
      <Button size="sm" variant="quiet" onClick={() => onAnswer(false)}>{t("No")}</Button>
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
  const { t } = useT();
  if (!terms.length) return null;
  const line = projectName ? t("Add to :project's vocabulary so the engine gets it right next time:", { project: projectName })
    : t("Add to the vocabulary so the engine gets it right next time:");
  return (
    <div className="el-recording__offer" role="group" aria-label={t("Add to the vocabulary")}>
      <span dir={lineDir(line)}>{line}</span>
      {terms.map((term) => <Button key={term} size="sm" icon={Icons.PlusIcon} aria-label={t("Add :term to the vocabulary", { term })} onClick={() => onAdd(term)}><span dir="auto">{term}</span></Button>)}
      <Button size="sm" variant="quiet" onClick={onDismiss}>{t("Not now")}</Button>
    </div>
  );
}
