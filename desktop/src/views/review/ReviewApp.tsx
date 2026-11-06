import { useCallback, useEffect, useRef, useState, type CSSProperties } from "react";
import { announceReady, api, errorMessage, listenTo, type AfterCallState, type AskAnswer, type AskPayload, type PendingItem, type SavedToast } from "../../lib/ipc";
import { useHitRegions } from "../../lib/hitRegions";
import { afterCallTitle, alwaysIgnoreLabel, askSeconds, askSubject, reviewDuration, secondsLeft } from "../../lib/controls";
import { CloseIcon, MicIcon, SourceAppIcon } from "../../components/Icons";
import { CheckIcon } from "../../components/ControlIcons";
import { SuccessCheck } from "../../components/SuccessCheck";
import { usePresence } from "../../lib/motion";
import "../controls.css";

/** How long a failed action's message replaces the title. */
const ERROR_MS = 4000;
/** The chip's full time (pending::CHIP_SECONDS): its timer line starts from the part that is left. */
export const CHIP_SECONDS = 10;
/** How long a kept chip shows its check before it leaves (since v1.5). */
export const KEPT_MS = 900;

const EMPTY: AfterCallState = { saved: null, ask: null };

/**
 * The `review` window (360 x 168, bottom-right, near the tray): what shows after a call (v1.5) and the ask chip for an
 * unrecognised app. "Keep automatically" shows a one-line toast, "Saved · Call with Omar · 4:12 · Undo", for 6 s; "Ask
 * me" shows a pill-size chip with the title, ✓ and ✕ that keeps the recording by itself after 10 s (a thin line counts
 * down), and its title opens the recording in the main window to rename it or pick a project. The core decides what is
 * shown (`aftercall:state`); the window never takes focus, so everything works on click and only what is visible is
 * reported as a hit region. The ask chip is time-critical (a call is starting), so it shows in front of the others.
 * Since v1.5 the toast and chip rise in (300 ms) and leave (150 ms), and a kept chip shows a check that pops in before it
 * goes; the core keeps the window a moment after it empties so they can (overlay::show_after_call).
 */
export default function ReviewApp() {
  const [state, setState] = useState<AfterCallState>(EMPTY);
  const [ask, setAsk] = useState<AskPayload | null>(null);

  useEffect(() => {
    let alive = true;
    let heardEvent = false;
    api.afterCallState().then((s) => {
      if (alive && !heardEvent && s) setState(s);
    }).catch(() => undefined);
    const subscriptions = [
      listenTo("aftercall:state", (s) => {
        heardEvent = true;
        setState(s ?? EMPTY);
      }),
      listenTo("ask:show", setAsk),
      listenTo("ask:hide", () => setAsk(null)),
    ];
    announceReady(subscriptions, () => alive);
    return () => {
      alive = false;
      subscriptions.forEach((p) => p.then((unlisten) => unlisten()).catch(() => undefined));
    };
  }, []);

  const closeAsk = useCallback((id: string) => setAsk((current) => (current?.id === id ? null : current)), []);
  const dropSaved = useCallback((id: string) => setState((s) => (s.saved?.id === id ? { ...s, saved: null } : s)), []);
  const dropChip = useCallback((id: string) => setState((s) => (s.ask?.id === id ? { ...s, ask: null } : s)), []);
  // A chip just kept shows its check for a moment, whatever the core sends meanwhile; then it leaves.
  const [kept, setKept] = useState<PendingItem | null>(null);
  // The last chip kept, so it still says Kept while it leaves.
  const lastKept = useRef<string | null>(null);
  const keep = useCallback((item: PendingItem) => {
    lastKept.current = item.id;
    setKept(item);
    dropChip(item.id);
  }, [dropChip]);
  useEffect(() => {
    if (!kept) return;
    const timer = window.setTimeout(() => setKept(null), KEPT_MS);
    return () => window.clearTimeout(timer);
  }, [kept]);
  // A new chip ends the kept one's moment: whatever leaves after it is not the kept one. A chip the core takes away once
  // its time is up was kept by the core, so it gets the check too.
  const shownAsk = useRef<PendingItem | null>(null);
  useEffect(() => {
    const was = shownAsk.current;
    shownAsk.current = state.ask;
    if (state.ask) lastKept.current = null;
    else if (was && lastKept.current !== was.id && secondsLeft(was.deadlineAt, Date.now()) === 0) keep(was);
  }, [state.ask, keep]);
  const saved = usePresence(state.saved);
  const chip = usePresence(state.ask ?? kept);
  const chipKept = !state.ask && !!kept && chip.item?.id === kept.id;

  useHitRegions([ask?.id ?? null, state.saved?.id ?? null, state.ask?.id ?? null, chipKept]);

  return (
    <div className="review-root">
      {ask ? (
        <AskChip key={ask.id} ask={ask} onClose={closeAsk} />
      ) : (
        <div className="after-stack">
          {saved.item && <SavedPill key={saved.item.id} saved={saved.item} leaving={saved.leaving} onGone={dropSaved} />}
          {chip.item && <KeepChip key={chip.item.id} item={chip.item} kept={chipKept || (chip.leaving && chip.item.id === lastKept.current)} leaving={chip.leaving} onGone={dropChip} onKept={keep} />}
        </div>
      )}
    </div>
  );
}

/** Shows `error` for a few seconds, then clears it. */
function useError(): [string | null, (e: string | null) => void] {
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (!error) return;
    const timer = window.setTimeout(() => setError(null), ERROR_MS);
    return () => window.clearTimeout(timer);
  }, [error]);
  return [error, setError];
}

/** "Saved · <title> · 4:12 · Undo": gone at `until` (the core says so too); Undo takes the recording back. */
function SavedPill({ saved, leaving, onGone }: { saved: SavedToast; leaving: boolean; onGone: (id: string) => void }) {
  const [error, setError] = useError();
  const busy = useRef(false);

  useEffect(() => {
    const left = secondsLeft(saved.until, Date.now());
    if (left === null) return;
    const timer = window.setTimeout(() => onGone(saved.id), left * 1000);
    return () => window.clearTimeout(timer);
  }, [saved.id, saved.until, onGone]);

  const undo = async () => {
    if (busy.current) return;
    busy.current = true;
    setError(null);
    try {
      await api.savedUndo(saved.id);
      onGone(saved.id);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      busy.current = false;
    }
  };

  const title = afterCallTitle(saved);
  return (
    <section className={`after-pill saved ${leaving ? "leaving" : ""}`} data-hit={leaving ? undefined : true} data-testid="saved-toast" role="status" aria-label={`Saved: ${title}`}
      aria-hidden={leaving || undefined}>
      <span className="after-icon ok" aria-hidden="true"><SuccessCheck size={14} /></span>
      {error ? (
        <span className="after-text error" role="alert" title={error}>{error}</span>
      ) : (
        <span className="after-text">
          <b className="after-word">Saved</b>
          <span className="after-sep" aria-hidden="true">·</span>
          <span className="after-title" dir="auto" title={title}>{title}</span>
          <span className="after-sep" aria-hidden="true">·</span>
          <span className="after-duration">{reviewDuration(saved.durationMs)}</span>
        </span>
      )}
      <button type="button" className="rec-text-btn after-undo" onClick={() => void undo()}>Undo</button>
    </section>
  );
}

/** "Ask me": the title (opens the recording to rename it or pick a project), ✓ Keep and ✕ Discard; kept by the core at
 * `deadlineAt` when nobody answers. */
function KeepChip({ item, kept, leaving, onGone, onKept }: {
  item: PendingItem;
  /** Kept: the check pops in where the app's icon was and the chip says so, then it leaves. */
  kept: boolean;
  leaving: boolean;
  onGone: (id: string) => void;
  onKept: (item: PendingItem) => void;
}) {
  const [error, setError] = useError();
  const busy = useRef(false);
  // Read once: a window built mid-countdown starts the line where the time is.
  const [left] = useState(() => secondsLeft(item.deadlineAt, Date.now()) ?? CHIP_SECONDS);

  const act = async (command: () => Promise<unknown>, done: () => void = () => onGone(item.id)) => {
    if (busy.current) return;
    busy.current = true;
    setError(null);
    try {
      await command();
      done();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      busy.current = false;
    }
  };

  const title = afterCallTitle(item);
  if (kept) {
    return (
      <section className={`after-pill chip kept ${leaving ? "leaving" : ""}`} data-testid="keep-chip" role="status" aria-label={`Kept: ${title}`}
        aria-hidden={leaving || undefined}>
        <span className="after-icon ok" aria-hidden="true"><SuccessCheck size={14} /></span>
        <span className="after-text">
          <b className="after-word">Kept</b>
          <span className="after-sep" aria-hidden="true">·</span>
          <span className="after-title" dir="auto" title={title}>{title}</span>
        </span>
      </section>
    );
  }
  return (
    <section className={`after-pill chip ${leaving ? "leaving" : ""}`} data-hit={leaving ? undefined : true} data-testid="keep-chip" aria-label={`Keep ${title}?`}
      aria-hidden={leaving || undefined}>
      <span className="after-icon source" aria-hidden="true"><SourceAppIcon app={item.sourceApp} size={14} /></span>
      {error ? (
        <span className="after-text error" role="alert" title={error}>{error}</span>
      ) : (
        <button type="button" className="after-title-btn" title="Rename or choose a project" aria-label={`${title}: rename or choose a project`}
          onClick={() => void act(() => api.pendingOpen(item.id, true))}>
          <span className="after-title" dir="auto">{title}</span>
          <span className="after-sep" aria-hidden="true">·</span>
          <span className="after-duration">{reviewDuration(item.durationMs)}</span>
        </button>
      )}
      <span className="after-actions">
        <button type="button" className="rec-btn after-keep" aria-label="Keep" title="Keep" onClick={() => void act(() => api.pendingKeep(item.id), () => onKept(item))}><CheckIcon size={15} /></button>
        <button type="button" className="rec-btn after-discard" aria-label="Discard" title="Discard" onClick={() => void act(() => api.pendingDiscard(item.id))}><CloseIcon size={14} /></button>
      </span>
      <span className="after-progress" aria-hidden="true">
        <span style={{ animationDuration: `${left}s`, "--from": String(Math.min(1, left / CHIP_SECONDS)) } as CSSProperties} />
      </span>
    </section>
  );
}

/** The unknown-app chip (A1.5): Record / Ignore / Always ignore, gone after `seconds` (8) without an answer. */
function AskChip({ ask, onClose }: { ask: AskPayload; onClose: (id: string) => void }) {
  const seconds = askSeconds(ask);
  const [error, setError] = useState<string | null>(null);
  const busy = useRef(false);

  useEffect(() => {
    const timer = window.setTimeout(() => onClose(ask.id), seconds * 1000);
    return () => window.clearTimeout(timer);
  }, [ask.id, seconds, onClose]);

  const answer = async (value: AskAnswer) => {
    if (busy.current) return;
    busy.current = true;
    setError(null);
    try {
      await api.askAnswer(ask.id, value);
      onClose(ask.id);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      busy.current = false;
    }
  };

  const subject = askSubject(ask);
  return (
    <section className="ask-chip" data-hit data-testid="ask-chip" aria-label="Microphone in use">
      <div className="ask-head">
        <span className="ask-icon"><MicIcon size={15} /></span>
        <div className="ask-text" data-testid="ask-text" title={`${subject} is using the microphone`}>
          <b>{subject}</b> is using the microphone
        </div>
      </div>
      {error && <div className="ask-error" role="alert">{error}</div>}
      <div className="ask-actions">
        <button type="button" className="ctl-btn primary" onClick={() => answer("record")}><span className="ask-rec-dot" aria-hidden="true" />Record</button>
        <button type="button" className="ctl-btn" onClick={() => answer("ignore")}>Ignore</button>
        <button type="button" className="ctl-btn" onClick={() => answer("always_ignore")}>{alwaysIgnoreLabel(ask)}</button>
      </div>
      <div className="ask-progress" aria-hidden="true"><span style={{ animationDuration: `${seconds}s` }} /></div>
    </section>
  );
}
