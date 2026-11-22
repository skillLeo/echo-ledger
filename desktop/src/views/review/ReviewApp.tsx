import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { announceReady, api, errorMessage, listenTo, type AfterCallState, type AfterEachCall, type AskAnswer, type AskPayload, type CallChoice, type CallDone, type CallGroup, type PendingItem } from "../../lib/ipc";
import { useHitRegions } from "../../lib/hitRegions";
import { useConcealed } from "../../lib/privacy";
import { alwaysIgnoreLabel, askSeconds, askSubject } from "../../lib/controls";
import { CALL_WORDS, alwaysOffer, cardMeta, cardName, doneText, groupHeadline, moreRows, progressStyle, useEscape, useSwipeAway, visibleRows } from "../../lib/callChoice";
import { hasSourceLogo, SourceLogo } from "../../components/SourceLogos";
import { CloseIcon, MicIcon, SourceAppIcon } from "../../components/Icons";
import "../controls.css";
import { useRisingCount, useUiFlags } from "../../v2/flags";
import { useWindowShown } from "../../lib/visibility";

// The after-call card in the new look (ui_v2.after_call), loaded only when its flag is on.
const AfterCallStackV2 = lazy(() => import("../../v2/controls/AfterCallStack").then((m) => ({ default: m.AfterCallStack })));

/** The card's full time (pending::CHIP_SECONDS, the call choice: 8 s). */
export { CARD_SECONDS as CHIP_SECONDS } from "../../lib/callChoice";

const EMPTY: AfterCallState = { ask: null, hint: false, held: [], done: null, group: null };

/**
 * The `review` window (360 x 240, next to the recording pill): the call choice after a call (docs/v2/copy/call-choice.md)
 * and the ask chip for an unrecognised app. One thing at a time, as the core sends it (`aftercall:state`):
 * - the card (Ask me): the app's logo, the name or "Call" and "0:44 · just now"; Delete as a quiet text button and
 *   Transcribe as the one filled button; an 8 s line, then "Close to keep audio only". The ×, Esc, a sideways drag or 8 s
 *   with no choice save the audio only. The first card ever adds where to change it. The name opens the recording to
 *   rename it or pick a project.
 * - one confirmation with Undo for 5 s: "Transcribing…", "Saved as audio · Transcribe", "Deleted"; the core applies the
 *   choice only then, so Undo brings the card back with nothing lost. Once, "Always do this?" [Yes].
 * - back-to-back calls: "3 calls saved as audio · Review", each call with what happens to it and its Undo.
 * A solid surface with radius 16 in the app's font, light and dark; it slides in over 200 ms, the line is its only
 * animation, and both stop under Reduce motion. No sound. The window never takes focus, so everything works on click
 * and only what is visible is reported as a hit region. The ask chip is time-critical (a call is starting), so it shows
 * in front of the others.
 */
export default function ReviewApp() {
  const [state, setState] = useState<AfterCallState>(EMPTY);
  const [ask, setAsk] = useState<AskPayload | null>(null);

  useEffect(() => {
    let alive = true;
    let heardEvent = false;
    api.afterCallState().then((s) => {
      if (alive && !heardEvent && s) setState({ ...EMPTY, ...s });
    }).catch(() => undefined);
    const subscriptions = [
      listenTo("aftercall:state", (s) => {
        heardEvent = true;
        setState(s ? { ...EMPTY, ...s } : EMPTY);
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
  const drop = useCallback((part: "ask" | "done" | "group") => setState((s) => ({ ...s, [part]: null })), []);

  // The interface flags are read again each time the window shows something, before it is drawn (v2/flags.ts).
  const windowShown = useWindowShown();
  const showKey = `show-${useRisingCount(windowShown && !!(ask || state.ask || state.done || state.group))}`;
  const [ui, , flagsFor] = useUiFlags(true, showKey, false);

  const concealed = useConcealed();
  useHitRegions([ask?.id ?? null, state.ask?.id ?? null, state.ask?.deadlineAt ?? null, state.done?.id ?? null, state.done?.kind ?? null, !!state.done?.offerAlways, state.group?.count ?? null, concealed]);

  if (concealed || flagsFor !== showKey) return <div className="review-root" />;
  return (
    <div className="review-root">
      {ask ? (
        <AskChip key={ask.id} ask={ask} onClose={closeAsk} />
      ) : ui.flags["ui_v2.after_call"] ? (
        <Suspense fallback={null}>
          <AfterCallStackV2 state={state} onGone={drop} />
        </Suspense>
      ) : (
        <CallChoiceStack state={state} onGone={drop} />
      )}
    </div>
  );
}

/** Classic's card, confirmation or grouped card: one at a time. */
function CallChoiceStack({ state, onGone }: { state: AfterCallState; onGone: (part: "ask" | "done" | "group") => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const act = async (fn: () => Promise<unknown>, part?: "ask" | "done" | "group") => {
    if (busy) return;
    setBusy(true);
    setError(null);
    try {
      await fn();
      if (part) onGone(part);
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };
  const item = state.ask;
  const done = state.done;
  if (item) {
    return <CallCard key={item.id} item={item} hint={state.hint} held={state.held ?? []} busy={busy} error={error} onAnswer={(choice) => void act(() => api.callCardAnswer(item.id, choice))}
      onOpen={() => void act(() => api.pendingOpen(item.id, true), "ask")}
      onRowUndo={(id) => void act(() => api.callUndo(id))} onRowTranscribe={(id) => void act(() => api.callDoneTranscribe(id))} />;
  }
  if (state.group) {
    return <CallGroupLine group={state.group} busy={busy} error={error} onReview={() => void act(() => api.callGroupClose(true), "group")}
      onClose={() => void act(() => api.callGroupClose(false), "group")} onUndo={(id) => void act(() => api.callUndo(id))}
      onTranscribe={(id) => void act(() => api.callDoneTranscribe(id))} />;
  }
  if (done) {
    return <CallDoneLine key={`${done.id}-${done.kind}`} done={done} busy={busy} error={error} onUndo={() => void act(() => api.callUndo(done.id))}
      onTranscribe={() => void act(() => api.callDoneTranscribe(done.id))} onAlways={(value) => void act(() => api.callAlways(done.id, value))} />;
  }
  return error ? <p className="call-error" role="alert">{error}</p> : null;
}

/** The app's own logo, as in the Library (the neutral glyph for an app without one). */
function AppLogo({ app }: { app: string }) {
  return (
    <span className={`call-card-logo${hasSourceLogo(app) ? " logo" : ""}`} aria-hidden="true">
      {hasSourceLogo(app) ? <SourceLogo app={app} size={36} /> : <SourceAppIcon app={app} size={18} />}
    </span>
  );
}

/** The card: Delete (quiet), Transcribe (the one filled button), the 8 s line and "Close to keep audio only". */
function CallCard({ item, hint, held, busy, error, onAnswer, onOpen, onRowUndo, onRowTranscribe }: {
  item: PendingItem;
  hint: boolean;
  /** The rest of a grouped card, kept under the card with their own Undo until it is answered. */
  held: CallDone[];
  onRowUndo: (id: string) => void;
  onRowTranscribe: (id: string) => void;
  busy: boolean;
  error: string | null;
  onAnswer: (choice: CallChoice) => void;
  onOpen: () => void;
}) {
  const name = cardName(item);
  const meta = cardMeta(item.durationMs);
  const saveAudio = () => onAnswer("audio");
  useEscape(!busy, saveAudio);
  const swipe = useSwipeAway(saveAudio);
  // Worked out once per deadline: the line runs on by itself from there (a new deadline, after Undo, starts it again).
  const line = useMemo(() => progressStyle(item.deadlineAt, Date.now()), [item.deadlineAt]);
  return (
    <section className="call-card" lang="en" data-hit data-testid="keep-chip" aria-label={name} aria-describedby={`call-close-${item.id}`} style={swipe.style} {...swipe.handlers}>
      <div className="call-card-head">
        <AppLogo app={item.sourceApp} />
        <button type="button" className="call-card-name" title="Rename or choose a project" aria-label={`${name}, ${meta}. Rename or choose a project`} onClick={onOpen}>
          <span className="call-card-title" dir="auto">{name}</span>
          <span className="call-card-meta" dir="auto">{meta}</span>
        </button>
        <button type="button" className="rec-btn call-card-close" aria-label={CALL_WORDS.save_audio_only} title={CALL_WORDS.save_audio_only} disabled={busy} onClick={saveAudio}>
          <CloseIcon size={15} />
        </button>
      </div>
      {error && <p className="call-error" role="alert">{error}</p>}
      <div className="call-card-actions">
        <button type="button" className="call-text-btn" disabled={busy} onClick={() => onAnswer("delete")}>{CALL_WORDS.delete}</button>
        <button type="button" className="ctl-btn primary" disabled={busy} onClick={() => onAnswer("transcribe")}>{CALL_WORDS.transcribe}</button>
      </div>
      <span className="call-card-line" aria-hidden="true"><span key={item.deadlineAt ?? ""} style={line} /></span>
      <p className="call-card-note" id={`call-close-${item.id}`} dir="auto">{CALL_WORDS.card_close_desktop}</p>
      {hint && <p className="call-card-note" data-testid="call-hint" dir="auto">{CALL_WORDS.card_first_hint}</p>}
      {held.length > 0 && <div className="call-held" data-testid="call-held"><CallRows calls={held} busy={busy} onUndo={onRowUndo} onTranscribe={onRowTranscribe} /></div>}
    </section>
  );
}

/** A grouped list's rows: every Delete with its Undo, always, and the newest others; the rest as "+N more". */
function CallRows({ calls, busy, onUndo, onTranscribe }: { calls: CallDone[]; busy: boolean; onUndo: (id: string) => void; onTranscribe: (id: string) => void }) {
  const { shown, more } = visibleRows(calls);
  return (
    <>
      {shown.map((c) => (
        <DoneRow key={c.id} done={c} busy={busy} testId="call-group-row" named onUndo={() => onUndo(c.id)} onTranscribe={() => onTranscribe(c.id)} />
      ))}
      {more > 0 && <p className="call-card-note" data-testid="call-more" dir="auto">{moreRows(more)}</p>}
    </>
  );
}

/** What was done to one call, its name on a line of its own, and its Transcribe (audio only) and Undo. */
function DoneRow({ done, busy, onUndo, onTranscribe, testId, named = false }: { done: CallDone; busy: boolean; onUndo: () => void; onTranscribe: () => void; testId?: string; named?: boolean }) {
  return (
    <div className="call-done-row" data-testid={testId}>
      <span className="call-done-text">
        <b dir="auto">{doneText(done)}</b>
        <span className="call-card-meta" dir="auto">{cardName(done)}</span>
      </span>
      {done.kind === "audio" && <button type="button" className="call-text-btn" disabled={busy} onClick={onTranscribe}>{CALL_WORDS.transcribe}</button>}
      <button type="button" className="call-text-btn" disabled={busy} onClick={onUndo} aria-label={named ? `${CALL_WORDS.undo}: ${cardName(done)}` : undefined}>{CALL_WORDS.undo}</button>
    </div>
  );
}

/** One confirmation with Undo for 5 s; once, "Always do this? Transcribe every call" [Yes] under it. */
function CallDoneLine({ done, busy, error, onUndo, onTranscribe, onAlways }: {
  done: CallDone;
  busy: boolean;
  error: string | null;
  onUndo: () => void;
  onTranscribe: () => void;
  onAlways: (value: AfterEachCall) => void;
}) {
  const offer = done.offerAlways;
  return (
    <section className="call-card call-done" lang="en" data-hit role="status" aria-label={`${doneText(done)} ${cardName(done)}`} data-testid="call-done">
      <DoneRow done={done} busy={busy} onUndo={onUndo} onTranscribe={onTranscribe} />
      {offer && (
        <div className="call-done-row" data-testid="call-always">
          <span className="call-card-meta call-done-text" dir="auto">{alwaysOffer(offer)}</span>
          <button type="button" className="call-text-btn" disabled={busy} onClick={() => onAlways(offer)}>{CALL_WORDS.card_always_yes}</button>
        </div>
      )}
      {error && <p className="call-error" role="alert">{error}</p>}
    </section>
  );
}

/** Back-to-back calls in one card: "3 calls saved as audio · Review", and each call (the newest three) with its Undo. */
function CallGroupLine({ group, busy, error, onReview, onClose, onUndo, onTranscribe }: {
  group: CallGroup;
  busy: boolean;
  error: string | null;
  onReview: () => void;
  onClose: () => void;
  onUndo: (id: string) => void;
  onTranscribe: (id: string) => void;
}) {
  const calls = group.calls ?? [];
  const headline = groupHeadline(calls);
  return (
    <section className="call-card call-done" lang="en" data-hit role="status" aria-label={headline} data-testid="call-group">
      <div className="call-done-row">
        <b className="call-done-text call-group-headline" dir="auto">{headline}</b>
        <button type="button" className="call-text-btn" disabled={busy} onClick={onReview}>{CALL_WORDS.grouped_review}</button>
        <button type="button" className="rec-btn" aria-label="Close" title="Close" disabled={busy} onClick={onClose}><CloseIcon size={15} /></button>
      </div>
      <CallRows calls={calls} busy={busy} onUndo={onUndo} onTranscribe={onTranscribe} />
      {error && <p className="call-error" role="alert">{error}</p>}
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
