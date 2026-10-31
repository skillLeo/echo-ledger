// Call notes (since v1.4): what Claude took from a call filed under a client, in sections (Asked for, Decisions, To
// confirm…). Each line opens the recording at its moment; "verify" marks a line the transcript was unclear about.
import { useEffect, useRef, useState } from "react";
import { api, errorMessage, type AnswerLanguage, type CallNotes, type NoteItem } from "../../lib/ipc";
import { timestamp } from "../../lib/format";
import { usePoll } from "../../lib/usePoll";
import { notifyWhenAway } from "../../lib/notify";
import { AlertIcon, SparkleIcon } from "../../components/Icons";

const CHANGE_KIND: Record<NonNullable<NoteItem["kind"]>, string> = { add: "Add", remove: "Remove", modify: "Change" };

/** Pending notes are written on the server's queue; the page asks again until they are done. */
export const notesWorking = (notes: CallNotes | null | undefined): boolean => notes?.status === "pending";

/** The sections of a set of notes; each line is a button when it has a moment to open. */
export function NotesSections({ notes, onOpen }: { notes: CallNotes; onOpen?: (ms: number) => void }) {
  return (
    <>
      {notes.sections.map((section) => (
        <div key={section.key} className="notes-group">
          <h4>{section.heading}</h4>
          <ul className="notes-list">
            {section.items.map((item, i) => {
              const canOpen = !!onOpen && item.at_ms !== null && item.at_ms !== undefined;
              return (
                <li key={i}>
                  <button type="button" className="note-line" disabled={!canOpen} onClick={() => canOpen && onOpen!(item.at_ms!)}
                    title={canOpen ? `Play from ${timestamp(item.at_ms!)}` : undefined}>
                    <span className="note-time">{item.at_ms !== null && item.at_ms !== undefined ? timestamp(item.at_ms) : ""}</span>
                    {item.kind && <span className="change-kind">{CHANGE_KIND[item.kind]}</span>}
                    <span className="note-text" dir="auto">{item.text}</span>
                    {item.verify && <span className="verify-tag" title="The transcript was unclear here: check it before you rely on it">verify</span>}
                  </button>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </>
  );
}

/** The "Call notes" section of a recording filed under a client. */
export function CallNotesSection({ sessionId, clientName, onSeek, notify }: {
  sessionId: string;
  clientName: string;
  /** Plays the recording from a moment. */
  onSeek: (ms: number) => void;
  notify: (text: string, error?: boolean) => void;
}) {
  // undefined: loading; null: none written yet.
  const [notes, setNotes] = useState<CallNotes | null | undefined>(undefined);
  const [error, setError] = useState<string | null>(null);
  const [language, setLanguage] = useState<AnswerLanguage>("en");
  const [busy, setBusy] = useState(false);
  const wasWorking = useRef(false);

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
    setError(null);
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessionId]);
  useEffect(() => {
    if (notes?.language === "roman_urdu" || notes?.language === "en") setLanguage(notes.language);
  }, [notes?.id, notes?.language]);

  usePoll(notesWorking(notes), load);
  // Notes finished while the person looked elsewhere: a native notification says so.
  useEffect(() => {
    const working = notesWorking(notes);
    if (wasWorking.current && !working && notes?.status === "ready") void notifyWhenAway("Call notes ready", `${clientName}: ${notes.session?.title ?? "the call"}`);
    wasWorking.current = working;
  }, [notes, clientName]);

  const write = async (lang: AnswerLanguage) => {
    setBusy(true);
    try {
      const answer = await api.writeSessionNotes(sessionId, lang);
      setNotes(answer.data);
      setLanguage(lang);
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };

  const languageSwitch = (
    <span className="segmented" role="group" aria-label="Notes language">
      <button type="button" aria-pressed={language === "en"} disabled={busy || notesWorking(notes)} onClick={() => (notes ? void write("en") : setLanguage("en"))}>English</button>
      <button type="button" aria-pressed={language === "roman_urdu"} disabled={busy || notesWorking(notes)} onClick={() => (notes ? void write("roman_urdu") : setLanguage("roman_urdu"))}>Roman Urdu</button>
    </span>
  );

  return (
    <section className="notes-section" aria-labelledby={`notes-${sessionId}`}>
      <div className="notes-head">
        <SparkleIcon size={18} />
        <h3 id={`notes-${sessionId}`}>Call notes</h3>
        <span className="spacer" />
        {notes !== undefined && languageSwitch}
      </div>
      {notes === undefined && !error && (
        <div aria-busy="true">
          <span className="sr-only">Loading the call notes…</span>
          <span className="skeleton" style={{ width: "70%", height: 12, marginTop: 8 }} />
          <span className="skeleton" style={{ width: "52%", height: 12, marginTop: 8 }} />
        </div>
      )}
      {error && (
        <div className="row small muted" role="status">
          <AlertIcon size={14} /><span>Couldn't load the call notes.</span>
          <button type="button" className="btn text compact" aria-label="Retry loading the call notes" onClick={() => void load()}>Retry</button>
        </div>
      )}
      {notes === null && (
        <div className="row small muted" style={{ flexWrap: "wrap" }}>
          <span>No notes for this call yet.</span>
          <button type="button" className="btn compact" disabled={busy} onClick={() => void write(language)}>Write call notes</button>
        </div>
      )}
      {notes && notes.status === "pending" && (
        <div className="row small muted" role="status"><span className="spinner" aria-hidden="true" />Writing the call notes… this takes a minute.</div>
      )}
      {notes && (notes.status === "failed" || notes.status === "skipped") && (
        <div className="notice" role="status">
          {notes.error ?? (notes.status === "skipped" ? "No notes were written for this call." : "The notes could not be written.")}{" "}
          <button type="button" className="btn compact" disabled={busy} onClick={() => void write(language)}>Try again</button>
        </div>
      )}
      {notes && notes.status === "ready" && (
        notes.sections.length === 0
          ? <p className="small muted">Nothing to note from this call.</p>
          : <NotesSections notes={notes} onOpen={onSeek} />
      )}
    </section>
  );
}
