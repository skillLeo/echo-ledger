// Tools around a recording's transcript in the library (plan B6, B7, B10, B11): player controls, the activity
// timeline, find and replace, speaker names, history, export, share links and "Add to vocabulary".
import { useEffect, useId, useMemo, useState } from "react";
import { api, errorMessage, type ExportFormat, type LearnOffer, type Segment, type ShareLink, type TranscriptRevision, type TranscriptSuggestion } from "../../lib/ipc";
import { formatWhen, timestamp } from "../../lib/format";
import { SPEEDS, timelinePercent, waveformBars } from "../../lib/player";
import type { Bookmark } from "../../lib/library";
import { ChevronRightIcon, CloseIcon, SearchIcon, SkipIcon } from "../../components/Icons";
import { ChevronLeftIcon, PauseIcon, PlayIcon } from "../../components/ControlIcons";

/** Speed, skip silence and follow. */
export function PlayerControls({ rate, onRate, skipSilence, onSkipSilence, follow, onFollow }: {
  rate: number;
  onRate: (rate: number) => void;
  skipSilence: boolean;
  onSkipSilence: (on: boolean) => void;
  follow: boolean;
  onFollow: (on: boolean) => void;
}) {
  const speedId = useId();
  return (
    <div className="row small player-controls">
      <label htmlFor={speedId} className="muted">Speed</label>
      <select id={speedId} className="select compact" value={rate} onChange={(e) => onRate(Number(e.target.value))}>
        {SPEEDS.map((s) => <option key={s} value={s}>{s}×</option>)}
      </select>
      <label className="check"><input type="checkbox" checked={skipSilence} onChange={(e) => onSkipSilence(e.target.checked)} />Skip silence</label>
      <label className="check"><input type="checkbox" checked={follow} onChange={(e) => onFollow(e.target.checked)} />Follow</label>
      <span className="muted keys-hint">Space or K plays · J / L jump 10 s · ← / → 5 s</span>
    </div>
  );
}

/** How many bars the docked player's waveform draws. */
export const WAVE_BARS = 96;

/**
 * The waveform of the docked player: who speaks when (bars coloured by side), the bookmarks and how far it has played.
 * Clicking seeks; the arrow keys and J / L move through the recording (LibraryView's player keys).
 */
export function Waveform({ segments, bookmarks, durationMs, nowMs, onSeek }: {
  segments: Segment[];
  bookmarks: Bookmark[];
  durationMs: number;
  nowMs: number;
  onSeek: (ms: number) => void;
}) {
  const bars = useMemo(() => waveformBars(segments, durationMs, WAVE_BARS), [segments, durationMs]);
  if (durationMs <= 0 || (segments.length === 0 && bookmarks.length === 0)) return null;
  const seekAt = (e: React.MouseEvent<HTMLDivElement>) => {
    const box = e.currentTarget.getBoundingClientRect();
    if (box.width > 0) onSeek(Math.max(0, ((e.clientX - box.left) / box.width) * durationMs));
  };
  const played = timelinePercent(nowMs, durationMs);
  return (
    <div className="waveform" data-testid="waveform" role="slider" tabIndex={0} aria-label="Position in the recording"
      aria-valuemin={0} aria-valuemax={Math.round(durationMs / 1000)} aria-valuenow={Math.round(nowMs / 1000)}
      aria-valuetext={`${timestamp(nowMs)} of ${timestamp(durationMs)}`} onClick={seekAt}>
      {bars.map((b, i) => (
        <span key={i} className={`wf-bar ch-${b.channel} ${((i + 0.5) / bars.length) * 100 <= played ? "played" : ""}`} style={{ height: `${Math.round(b.level * 100)}%` }} />
      ))}
      {bookmarks.map((b) => <span key={b.id} className="wf-mark" style={{ left: `${timelinePercent(b.at_ms, durationMs)}%` }} />)}
    </div>
  );
}

/** The player docked at the bottom of a recording: ±10 s, play / pause, the time and the waveform. */
export function PlayerDock({ playing, nowMs, durationMs, onToggle, onSkip, children, below }: {
  playing: boolean;
  nowMs: number;
  durationMs: number;
  onToggle: () => void;
  onSkip: (seconds: number) => void;
  /** The audio element and the waveform, on the player's line. */
  children: React.ReactNode;
  /** The speed and follow controls, under it. */
  below?: React.ReactNode;
}) {
  return (
    <div className="player-dock" role="region" aria-label="Player">
      <div className="player-row">
        <button type="button" className="skip-btn" aria-label="Back 10 seconds" title="Back 10 s (J)" onClick={() => onSkip(-10)}><SkipIcon back size={22} /></button>
        <button type="button" className="play-btn" aria-label={playing ? "Pause" : "Play"} title={playing ? "Pause (Space)" : "Play (Space)"} onClick={onToggle}>
          {playing ? <PauseIcon size={18} /> : <PlayIcon size={18} />}
        </button>
        <button type="button" className="skip-btn" aria-label="Forward 10 seconds" title="Forward 10 s (L)" onClick={() => onSkip(10)}><SkipIcon size={22} /></button>
        <span className="player-time">{timestamp(nowMs)} / {timestamp(durationMs)}</span>
        {children}
      </div>
      {below}
    </div>
  );
}

/** Find in the transcript on screen: every match marked, Enter / Shift+Enter or the arrows step through them. */
export function FindBar({ query, onQuery, count, current, onStep, onClose }: {
  query: string;
  onQuery: (q: string) => void;
  count: number;
  current: number;
  onStep: (delta: 1 | -1) => void;
  onClose: () => void;
}) {
  return (
    <div className="find-bar" role="search" aria-label="Find in transcript">
      <label className="searchbox compact">
        <SearchIcon size={16} />
        <input value={query} onChange={(e) => onQuery(e.target.value)} placeholder="Find in this transcript" aria-label="Find in transcript" autoFocus
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              onStep(e.shiftKey ? -1 : 1);
            }
            if (e.key === "Escape") onClose();
          }} />
      </label>
      <span className="find-count" role="status">{query.trim() ? (count === 0 ? "No matches" : `${current + 1} of ${count}`) : ""}</span>
      <button type="button" className="btn ghost compact icon-only" aria-label="Previous match" disabled={count === 0} onClick={() => onStep(-1)}><ChevronLeftIcon size={16} /></button>
      <button type="button" className="btn ghost compact icon-only" aria-label="Next match" disabled={count === 0} onClick={() => onStep(1)}><ChevronRightIcon size={16} /></button>
      <button type="button" className="btn ghost compact icon-only" aria-label="Close find" onClick={onClose}><CloseIcon size={16} /></button>
    </div>
  );
}

/** "Always write *la ravel* as *Laravel* for Acme?" after an edit: Yes saves a replacement rule for that client. */
export function LearnOfferBar({ offer, onAnswer }: { offer: LearnOffer | null; onAnswer: (yes: boolean) => void }) {
  if (!offer) return null;
  return (
    <div className="learn-offer" role="group" aria-label="Always write it this way">
      <span>Always write <em>{offer.from}</em> as <em>{offer.to}</em> for {offer.client_name}?</span>
      <button type="button" className="btn primary compact" onClick={() => onAnswer(true)}>Yes</button>
      <button type="button" className="btn ghost compact" onClick={() => onAnswer(false)}>No</button>
    </div>
  );
}

/** A suggested fix opened from its mark in the text: Accept changes the words (an edit), Dismiss leaves them. */
export function SuggestionPop({ suggestion, busy, onDecide, onClose }: {
  suggestion: TranscriptSuggestion;
  busy: boolean;
  onDecide: (accept: boolean) => void;
  onClose: () => void;
}) {
  return (
    <div className="suggest-pop" role="group" aria-label="Suggested fix">
      <span>Change <b>{suggestion.from}</b> to <b>{suggestion.to}</b>?{suggestion.reason ? <span className="muted"> ({suggestion.reason})</span> : null}</span>
      <span className="spacer" />
      <button type="button" className="btn primary compact" disabled={busy} onClick={() => onDecide(true)}>Accept</button>
      <button type="button" className="btn ghost compact" disabled={busy} onClick={() => onDecide(false)}>Dismiss</button>
      <button type="button" className="btn ghost compact icon-only" aria-label="Close" onClick={onClose}><CloseIcon size={14} /></button>
    </div>
  );
}

/** Find and replace in the whole transcript. */
export function ReplaceBar({ onReplace, onClose }: {
  onReplace: (find: string, replace: string, matchCase: boolean, wholeWord: boolean) => Promise<number | null>;
  onClose: () => void;
}) {
  const [find, setFind] = useState("");
  const [replace, setReplace] = useState("");
  const [matchCase, setMatchCase] = useState(false);
  const [wholeWord, setWholeWord] = useState(true);
  const [result, setResult] = useState<string | null>(null);
  const findId = useId();
  const replaceId = useId();
  const submit = async () => {
    const count = await onReplace(find, replace, matchCase, wholeWord);
    if (count !== null) setResult(count === 0 ? "Nothing matched." : count === 1 ? "Replaced 1 time." : `Replaced ${count} times.`);
  };
  return (
    <form className="tool-panel" role="search" aria-label="Find and replace" onSubmit={(e) => { e.preventDefault(); void submit(); }}>
      <label htmlFor={findId} className="sr-only">Find</label>
      <input id={findId} className="input" placeholder="Find" value={find} maxLength={200} onChange={(e) => setFind(e.target.value)} autoFocus />
      <label htmlFor={replaceId} className="sr-only">Replace with</label>
      <input id={replaceId} className="input" placeholder="Replace with" value={replace} maxLength={200} onChange={(e) => setReplace(e.target.value)} />
      <label className="check"><input type="checkbox" checked={matchCase} onChange={(e) => setMatchCase(e.target.checked)} />Match case</label>
      <label className="check"><input type="checkbox" checked={wholeWord} onChange={(e) => setWholeWord(e.target.checked)} />Whole words</label>
      <button type="submit" className="btn" disabled={!find.trim()}>Replace all</button>
      <button type="button" className="btn ghost" onClick={onClose}>Close</button>
      <span className="small muted" role="status">{result}</span>
    </form>
  );
}

/** Rename the speakers of this recording, optionally for every recording of its client. */
export function SpeakersPanel({ labels, names, hasClient, onSave, onClose }: {
  labels: string[];
  names: Record<string, string>;
  hasClient: boolean;
  onSave: (names: Record<string, string | null>, remember: boolean) => Promise<boolean>;
  onClose: () => void;
}) {
  const [draft, setDraft] = useState<Record<string, string>>(() => Object.fromEntries(labels.map((l) => [l, names[l] ?? ""])));
  const [remember, setRemember] = useState(false);
  const save = async () => {
    const ok = await onSave(Object.fromEntries(labels.map((l) => [l, draft[l]?.trim() ? draft[l].trim() : null])), remember);
    if (ok) onClose();
  };
  return (
    <form className="tool-panel column" aria-label="Rename speakers" onSubmit={(e) => { e.preventDefault(); void save(); }}>
      <b>Speaker names</b>
      {labels.map((label) => (
        <label key={label} className="row small">
          <span className="speaker-label">{label}</span>
          <input className="input" value={draft[label] ?? ""} placeholder={label} maxLength={60} aria-label={`Name for ${label}`}
            onChange={(e) => setDraft({ ...draft, [label]: e.target.value })} />
        </label>
      ))}
      {hasClient && <label className="check small"><input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} />Use these names for this client’s next recordings</label>}
      <div className="row">
        <button type="submit" className="btn">Save names</button>
        <button type="button" className="btn ghost" onClick={onClose}>Cancel</button>
      </div>
    </form>
  );
}

const REVISION_LABEL: Record<TranscriptRevision["source"], string> = {
  original: "Original from the engine",
  edit: "Edited",
  replace: "Find and replace or vocabulary",
  restore: "Original restored",
};

/** The transcript's revisions and "Restore original". */
export function HistoryPanel({ transcriptId, edited, onRestore, onClose }: {
  transcriptId: string;
  edited: boolean;
  onRestore: () => Promise<void>;
  onClose: () => void;
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
  return (
    <div className="tool-panel column" role="region" aria-label="Transcript history">
      <b>History</b>
      {error && <span className="small error-text">{error}</span>}
      {revisions === null && !error && <span className="small muted">Loading…</span>}
      {revisions?.length === 0 && <span className="small muted">Not edited yet. Edits and corrections appear here, and the original can always be restored.</span>}
      {revisions?.map((r) => (
        <div key={r.id} className="row small"><span>{REVISION_LABEL[r.source] ?? r.source}</span><span className="muted">{formatWhen(r.created_at)}</span>{r.author && <span className="muted">by {r.author}</span>}</div>
      ))}
      <div className="row">
        {edited && <button type="button" className="btn danger ghost" onClick={() => { if (window.confirm("Put the engine’s original text back? Your edits stay in the history.")) void onRestore(); }}>Restore original</button>}
        <button type="button" className="btn ghost" onClick={onClose}>Close</button>
      </div>
    </div>
  );
}

export const EXPORTS: [ExportFormat, string][] = [
  ["txt", "Text (.txt)"],
  ["docx", "Word (.docx)"],
  ["html", "PDF (print)"],
  ["srt", "Subtitles (.srt)"],
  ["json", "JSON (.json)"],
  ["md", "Markdown (.md)"],
  ["vtt", "Web subtitles (.vtt)"],
];

/** What "PDF (print)" does: the desktop has no PDF writer of its own, the browser's print page makes the file. */
export const PDF_HINT = "PDF (print) opens a print page in your browser: choose Save as PDF there.";

/**
 * Export menu: the transcript in the view on screen (`viewLabel`, e.g. "Roman Urdu"; none = the original words). Each
 * file is saved where the person chooses; PDF opens the print page in the browser to save as PDF.
 */
export function ExportMenu({ onExport, viewLabel = null }: { onExport: (format: ExportFormat) => void; viewLabel?: string | null }) {
  const id = useId();
  return (
    <>
      <label htmlFor={id} className="sr-only">Export</label>
      <select id={id} className="select compact" value="" title={PDF_HINT} onChange={(e) => { if (e.target.value) onExport(e.target.value as ExportFormat); e.target.value = ""; }}>
        <option value="">{viewLabel ? `Export as ${viewLabel}…` : "Export…"}</option>
        {EXPORTS.map(([format, label]) => <option key={format} value={format}>{label}</option>)}
      </select>
    </>
  );
}

const LIFETIMES: [number, string][] = [[3_600, "1 hour"], [86_400, "1 day"], [604_800, "7 days"]];

/** Make a view-only link (shown once, with Copy), and see or revoke the recording's links. */
export function SharePanel({ sessionId, hasAudio, notify, onClose }: {
  sessionId: string;
  hasAudio: boolean;
  notify: (t: string, e?: boolean) => void;
  onClose: () => void;
}) {
  const [expiresIn, setExpiresIn] = useState(86_400);
  const [password, setPassword] = useState("");
  const [audio, setAudio] = useState(false);
  const [made, setMade] = useState<string | null>(null);
  const [links, setLinks] = useState<ShareLink[]>([]);
  const [busy, setBusy] = useState(false);
  const expiresId = useId();
  const passwordId = useId();
  const load = () => api.listShares(sessionId).then((r) => setLinks(r.data)).catch(() => undefined);
  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sessionId]);
  const create = async () => {
    setBusy(true);
    try {
      const link = await api.createShare(sessionId, expiresIn, password || null, audio);
      setMade(link.url ?? null);
      setPassword("");
      await load();
    } catch (e) {
      notify(errorMessage(e), true);
    } finally {
      setBusy(false);
    }
  };
  const copy = async () => {
    if (!made) return;
    try {
      await navigator.clipboard.writeText(made);
      notify("Link copied");
    } catch {
      notify("Could not access the clipboard", true);
    }
  };
  const revoke = async (id: string) => {
    try {
      await api.revokeShare(id);
      await load();
    } catch (e) {
      notify(errorMessage(e), true);
    }
  };
  return (
    <div className="tool-panel column" role="region" aria-label="Share">
      <b>Share a view-only link</b>
      <div className="row small">
        <label htmlFor={expiresId} className="muted">Expires after</label>
        <select id={expiresId} className="select compact" value={expiresIn} onChange={(e) => setExpiresIn(Number(e.target.value))}>
          {LIFETIMES.map(([s, label]) => <option key={s} value={s}>{label}</option>)}
        </select>
        <label htmlFor={passwordId} className="muted">Password</label>
        <input id={passwordId} className="input" type="password" value={password} minLength={6} maxLength={100} placeholder="Optional, 6+ characters" onChange={(e) => setPassword(e.target.value)} />
        {hasAudio && <label className="check"><input type="checkbox" checked={audio} onChange={(e) => setAudio(e.target.checked)} />Include the recording</label>}
        <button type="button" className="btn" disabled={busy || (password.length > 0 && password.length < 6)} onClick={() => void create()}>Make link</button>
      </div>
      {made && (
        <div className="row small share-made" role="status">
          <span className="muted">Copy it now — it is shown only once:</span>
          <code className="share-url">{made}</code>
          <button type="button" className="btn ghost" onClick={() => void copy()}>Copy</button>
        </div>
      )}
      {links.length > 0 && (
        <div className="column" role="list" aria-label="Share links">
          {links.map((l) => (
            <div key={l.id} className="row small" role="listitem">
              <span>{l.active ? "Active" : l.revoked_at ? "Revoked" : "Expired"}</span>
              <span className="muted">made {formatWhen(l.created_at)}{l.expires_at ? ` · expires ${formatWhen(l.expires_at)}` : ""}</span>
              <span className="muted">{l.view_count} view{l.view_count === 1 ? "" : "s"}{l.has_password ? " · password" : ""}{l.include_audio ? " · audio" : ""}</span>
              {l.active && <button type="button" className="btn ghost danger" onClick={() => void revoke(l.id)}>Revoke</button>}
            </div>
          ))}
        </div>
      )}
      <div className="row"><button type="button" className="btn ghost" onClick={onClose}>Close</button></div>
    </div>
  );
}

/** After an edit: offer the new names or terms for the vocabulary. */
export function VocabularyHint({ terms, clientName, onAdd, onDismiss }: {
  terms: string[];
  clientName: string | null;
  onAdd: (term: string) => void;
  onDismiss: () => void;
}) {
  if (terms.length === 0) return null;
  return (
    <div className="notice row small" role="status">
      <span>Add to {clientName ? `${clientName}’s` : "the"} vocabulary so the engine gets it right next time:</span>
      {terms.map((t) => <button key={t} type="button" className="chip" onClick={() => onAdd(t)}>{t}</button>)}
      <button type="button" className="btn ghost compact" onClick={onDismiss}>Not now</button>
    </div>
  );
}

