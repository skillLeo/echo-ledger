// The recording page's transcript tools in the new design (PLAN_V2_PARITY 4; classic's views/main/TranscriptTools.tsx
// and CallNotes.tsx): Find with every match marked and "N of M", Find and replace, Speakers, History with Restore
// original, a line edited in place, and the call notes of a recording filed under a project. Same commands as classic.
import { useEffect, useRef, useState, type KeyboardEvent } from "react";
import {
  api, errorMessage, type AnswerLanguage, type CallNotes, type TranscriptDetail, type TranscriptRevision,
} from "../../lib/ipc";
import { formatWhen, timestamp } from "../../lib/format";
import { confirmAction } from "../../lib/confirm";
import { usePoll } from "../../lib/usePoll";
import { notesWorking } from "../../views/main/CallNotes";
import { Badge, Button, Checkbox, Dialog, EmptyState, Icons, Loading, SearchField, Segmented, Skeleton, TextField } from "../components";

/** Find in this transcript: the field, "N of M", previous and next; Enter, Shift+Enter and Esc as classic. */
export function FindBar({ query, onQuery, count, current, onStep, onClose }: {
  query: string;
  onQuery: (q: string) => void;
  count: number;
  current: number;
  onStep: (delta: 1 | -1) => void;
  onClose: () => void;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    ref.current?.querySelector<HTMLInputElement>("input")?.focus();
  }, []);
  const key = (e: KeyboardEvent<HTMLInputElement>) => {
    if (e.key === "Enter") {
      e.preventDefault();
      onStep(e.shiftKey ? -1 : 1);
    } else if (e.key === "Escape" && !query) {
      e.preventDefault();
      e.stopPropagation();
      onClose();
    }
  };
  return (
    <div className="el-findbar" role="search" aria-label="Find in this transcript" ref={ref}>
      <SearchField label="Find in this transcript" value={query} onChange={onQuery} onKeyDown={key} />
      <span className="el-caption el-secondary el-num" role="status">{query.trim() ? (count ? `${current + 1} of ${count}` : "No matches") : ""}</span>
      <Button size="sm" variant="quiet" icon={Icons.ChevronStartIcon} aria-label="Previous match" disabled={!count} onClick={() => onStep(-1)} />
      <Button size="sm" variant="quiet" icon={Icons.ChevronEndIcon} aria-label="Next match" disabled={!count} onClick={() => onStep(1)} />
      <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Close find" onClick={onClose} />
    </div>
  );
}

/** A line being edited: saves on leaving it or Ctrl/Cmd+Enter, Esc puts it back. */
export function LineEditor({ text, onSave, onCancel }: { text: string; onSave: (text: string) => void; onCancel: () => void }) {
  const [value, setValue] = useState(text);
  const done = useRef(false);
  const finish = (save: boolean) => {
    if (done.current) return;
    done.current = true;
    if (save && value.trim() && value.trim() !== text.trim()) onSave(value.trim());
    else onCancel();
  };
  return (
    <textarea className="el-line-editor" aria-label="Edit line" value={value} dir="auto" rows={Math.min(6, Math.max(2, Math.ceil(value.length / 70)))} autoFocus
      onChange={(e) => setValue(e.target.value)} onBlur={() => finish(true)}
      onKeyDown={(e) => {
        if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) { e.preventDefault(); finish(true); }
        if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); finish(false); }
      }} />
  );
}

/** Find and replace: Match case, Whole words, Replace all; says how many were replaced. */
export function ReplaceDialog({ transcriptId, onReplaced, onClose, say }: {
  transcriptId: string;
  onReplaced: (t: TranscriptDetail) => void;
  onClose: () => void;
  say: (text: string) => void;
}) {
  const [find, setFind] = useState("");
  const [replace, setReplace] = useState("");
  const [matchCase, setMatchCase] = useState(false);
  const [whole, setWhole] = useState(false);
  const [busy, setBusy] = useState(false);
  const [note, setNote] = useState<string | null>(null);
  const run = async () => {
    setBusy(true);
    try {
      const r = await api.replaceInTranscript(transcriptId, find, replace, matchCase, whole);
      onReplaced(r.transcript);
      if (r.replaced === 0) setNote("Nothing matched.");
      else {
        say(r.replaced === 1 ? "Replaced 1 place" : `Replaced ${r.replaced} places`);
        onClose();
      }
    } catch (e) {
      setNote(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Dialog open title="Find and replace" onClose={onClose} description={note ?? undefined}
      actions={<><Button onClick={onClose}>Close</Button><Button variant="primary" disabled={!find.trim()} loading={busy} loadingLabel="Replacing…" onClick={() => void run()}>Replace all</Button></>}>
      <TextField label="Find" value={find} onChange={(e) => setFind(e.target.value)} autoFocus />
      <TextField label="Replace with" value={replace} onChange={(e) => setReplace(e.target.value)} />
      <Checkbox label="Match case" checked={matchCase} onChange={(e) => setMatchCase(e.target.checked)} />
      <Checkbox label="Whole words" checked={whole} onChange={(e) => setWhole(e.target.checked)} />
    </Dialog>
  );
}

/** Rename the speakers; for a recording in a project, optionally for that project's next recordings too. */
export function SpeakersDialog({ sessionId, labels, names, hasProject, onSaved, onClose, say }: {
  sessionId: string;
  labels: readonly string[];
  names: Record<string, string>;
  hasProject: boolean;
  onSaved: (names: Record<string, string>) => void;
  onClose: () => void;
  say: (text: string) => void;
}) {
  const [draft, setDraft] = useState<Record<string, string>>(() => Object.fromEntries(labels.map((l) => [l, names[l] ?? ""])));
  const [remember, setRemember] = useState(false);
  const [busy, setBusy] = useState(false);
  const save = async () => {
    setBusy(true);
    try {
      const r = await api.renameSpeakers(sessionId, Object.fromEntries(labels.map((l) => [l, draft[l]?.trim() || null])), remember);
      onSaved(r.speaker_names);
      say("Speakers renamed");
      onClose();
    } catch (e) {
      say(errorMessage(e));
      setBusy(false);
    }
  };
  return (
    <Dialog open title="Speakers" description="Names shown instead of the speaker labels, here and in what you copy or export." onClose={onClose}
      actions={<><Button onClick={onClose}>Cancel</Button><Button variant="primary" loading={busy} loadingLabel="Saving…" onClick={() => void save()}>Save</Button></>}>
      {labels.map((l) => (
        <TextField key={l} label={l} placeholder={l} value={draft[l] ?? ""} maxLength={80} onChange={(e) => setDraft((d) => ({ ...d, [l]: e.target.value }))} />
      ))}
      {hasProject && <Checkbox label="Use these names for this project's next recordings" checked={remember} onChange={(e) => setRemember(e.target.checked)} />}
    </Dialog>
  );
}

const REVISION_WORDS: Record<TranscriptRevision["source"], string> = {
  original: "Original from the engine",
  edit: "Edited",
  replace: "Find and replace or vocabulary",
  restore: "Original restored",
};

/** The transcript's history; Restore original asks first (the edits stay in the history). */
export function HistoryDialog({ transcriptId, edited, onRestored, onClose, say }: {
  transcriptId: string;
  edited: boolean;
  onRestored: (t: TranscriptDetail) => void;
  onClose: () => void;
  say: (text: string) => void;
}) {
  const [revisions, setRevisions] = useState<TranscriptRevision[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    let alive = true;
    api.transcriptRevisions(transcriptId).then((r) => alive && setRevisions(r.data)).catch((e) => alive && setError(errorMessage(e)));
    return () => {
      alive = false;
    };
  }, [transcriptId]);
  const restore = async () => {
    if (!(await confirmAction({ title: "Put the original text back?", body: "Your edits stay in the history.", confirm: "Restore" }))) return;
    try {
      onRestored(await api.restoreTranscript(transcriptId));
      say("The original text is back");
      onClose();
    } catch (e) {
      say(errorMessage(e));
    }
  };
  return (
    <Dialog open title="History" onClose={onClose} description={error ?? undefined}
      actions={<>{edited && <Button variant="danger" onClick={() => void restore()}>Restore original</Button>}<Button onClick={onClose}>Close</Button></>}>
      {revisions === null && !error ? <Loading label="Loading the history"><Skeleton /></Loading>
        : revisions?.length === 0 ? <p className="el-secondary">Not edited yet. Edits and corrections appear here, and the original can always be restored.</p>
          : (
            <ul className="el-history" aria-label="Changes">
              {revisions?.map((r) => (
                <li key={r.id}><span>{REVISION_WORDS[r.source] ?? r.source}</span><span className="el-caption el-secondary">{formatWhen(r.created_at)}{r.author ? ` · by ${r.author}` : ""}</span></li>
              ))}
            </ul>
          )}
    </Dialog>
  );
}

/** The call notes of a recording in a project: written on the server, in English or Roman Urdu; a line plays its moment. */
export function CallNotesV2({ sessionId, onSeek, say }: { sessionId: string; onSeek: (ms: number) => void; say: (text: string) => void }) {
  const [notes, setNotes] = useState<CallNotes | null | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [language, setLanguage] = useState<AnswerLanguage>("en");
  const [busy, setBusy] = useState(false);
  const load = async () => {
    try {
      const answer = await api.sessionNotes(sessionId);
      setNotes(answer?.data ?? null);
      setError(null);
    } catch (e) {
      setError(errorMessage(e));
    }
  };
  useEffect(() => {
    setNotes(undefined);
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessionId]);
  useEffect(() => {
    if (notes?.language === "roman_urdu" || notes?.language === "en") setLanguage(notes.language);
  }, [notes?.id, notes?.language]);
  usePoll(notesWorking(notes), load);
  const write = async (lang: AnswerLanguage) => {
    setBusy(true);
    try {
      setNotes((await api.writeSessionNotes(sessionId, lang)).data);
      setLanguage(lang);
    } catch (e) {
      say(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  if (error) {
    return <EmptyState tone="error" icon={Icons.ErrorIcon} title="The call notes didn't load" body={error} action={<Button onClick={() => void load()}>Try again</Button>} />;
  }
  if (notes === undefined) return <Loading label="Loading the call notes"><Skeleton width="long" /><Skeleton width="medium" /></Loading>;
  return (
    <div className="el-notes">
      <div className="el-notes__bar">
        <Segmented label="Notes language" value={language} onChange={(v) => (notes ? void write(v) : setLanguage(v))}
          options={[{ value: "en" as AnswerLanguage, label: "English" }, { value: "roman_urdu" as AnswerLanguage, label: "Roman Urdu" }]} />
      </div>
      {notes === null ? (
        <EmptyState icon={Icons.SparkleIcon} title="No notes for this call yet" body="What was asked for, decided and still to confirm, from the transcript."
          action={<Button variant="primary" loading={busy} loadingLabel="Starting…" onClick={() => void write(language)}>Write call notes</Button>} />
      ) : notes.status === "pending" ? (
        <p className="el-secondary" role="status">Writing the call notes… this takes a minute.</p>
      ) : notes.status === "failed" || notes.status === "skipped" ? (
        <EmptyState tone="error" icon={Icons.WarningIcon} title={notes.status === "skipped" ? "No notes were written for this call" : "The notes could not be written"}
          body={notes.error ?? "Try again in a moment."} action={<Button loading={busy} onClick={() => void write(language)}>Try again</Button>} />
      ) : notes.sections.length === 0 ? (
        <p className="el-secondary">Nothing to note from this call.</p>
      ) : (
        notes.sections.map((section) => (
          <section key={section.key} className="el-group" aria-label={section.heading}>
            <h3 className="el-caption el-body-strong el-group__title">{section.heading}</h3>
            <ul className="el-notes__list">
              {section.items.map((item, i) => {
                const at = item.at_ms ?? null;
                return (
                  <li key={i}>
                    <button type="button" className="el-notes__line" disabled={at === null} onClick={() => at !== null && onSeek(at)}
                      aria-label={at !== null ? `${item.text} · Play from ${timestamp(at)}` : undefined}>
                      <span className="el-num el-caption el-secondary">{at !== null ? timestamp(at) : ""}</span>
                      <span dir="auto">{item.text}</span>
                      {item.verify && <Badge tone="warning">Verify</Badge>}
                    </button>
                  </li>
                );
              })}
            </ul>
          </section>
        ))
      )}
    </div>
  );
}
