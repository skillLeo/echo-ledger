// The Live Assist card (v1.9 L4): the question in small grey, the first line big and bold, up to three bullets, an
// optional "Say next" and the language line; the captions strip under it (Them / Me, last two lines); the in-call
// controls on its top bar. Rendered token by token from the card state (card.ts). The window is the core's: hidden from
// screen sharing like the other panels, with the privacy badge; [data-hit] marks what stays clickable.
import { useState, type ReactNode } from "react";
import { Button, Icons, Progress } from "../components";
import type { AssistMode, HintLevel, SendKind } from "./adapter";
import { boldParts, modeLabel, offerQuestion, parseSuggestion, shown, type CardState } from "./card";

/** The consent line of the prompt pack (1.0.1), for boss, client and general meetings. */
export const CONSENT_LINE = "Just so you know: I record this call and use an AI assistant that takes notes and suggests points to me. Is that okay with you?";
export const INTERVIEW_NOTE = "Check whether this interview allows AI help.";

const HINT_WORDS: Record<HintLevel, string> = { keywords: "Keywords", outline: "Outline", full: "Full answer" };
const NEXT_HINT: Record<HintLevel, HintLevel> = { keywords: "outline", outline: "full", full: "keywords" };

export type AssistCardActions = {
  onSend: (kind: SendKind) => void;
  onMode: (mode: AssistMode) => void;
  onHint: (level: HintLevel) => void;
  onMute: () => void;
  onHide: () => void;
  onCopyConsent: () => void;
  /** The core's offer: Start (with the call's client_ref) or Not now. */
  onStartOffer: () => void;
  onDismissOffer: () => void;
  /** 409 session_active: end the running session and start this one; or keep it running (closing the card too). */
  onEndAndStart: () => void;
  onKeepRunning: () => void;
};

function Bold({ text }: { text: string }) {
  return <>{boldParts(text).map((p, i) => (p.bold ? <strong key={i}>{p.text}</strong> : <span key={i}>{p.text}</span>))}</>;
}

export function AssistCard({ state, hint, muted, badge, actions }: {
  state: CardState;
  hint: HintLevel;
  muted: boolean;
  /** The privacy badge (the window's last share check). */
  badge?: ReactNode;
  actions: AssistCardActions;
}) {
  const [noteSeen, setNoteSeen] = useState(false);
  const s = shown(state);
  const parsed = s?.text ? parseSuggestion(s.text) : null;
  const question = s?.turnId ? state.turns[s.turnId] : null;
  const captionsOnly = state.mode === "captions_only";
  const modes = state.allowedModes;
  const nextMode = modes.length > 1 && !captionsOnly ? modes[(modes.indexOf(state.mode as AssistMode) + 1) % modes.length] : null;
  const reminder = noteSeen ? null
    : state.meetingType === "interview" ? INTERVIEW_NOTE
      : state.meetingType ? CONSENT_LINE : null;
  const rtl = parsed?.language && parsed.language.label !== "Roman Urdu";

  // Only for a start made from this card (its offer): the question needs something to start again.
  const sessionActive = state.sessionActive && state.pendingStart && (
    <div className="el-assist__note" role="alert">
      <span>{state.sessionActive?.text}</span>
      <Button size="sm" variant="primary" onClick={actions.onEndAndStart}>{state.sessionActive?.action}</Button>
      <Button size="sm" variant="quiet" onClick={actions.onKeepRunning}>Keep running</Button>
    </div>
  );
  // Before a session: the core's offer for a prepared meeting's call (it never starts by itself).
  if (!state.sessionId) {
    if (!state.offer && !sessionActive && !state.error) return null;
    return (
      <section className="el-assist el-assist--offer" data-hit aria-label="Live Assist" data-testid="assist-card">
        <div className="el-assist__bar">
          <span className="el-assist__budget" />
          <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Hide the card" onClick={actions.onHide} />
        </div>
        {state.offer && (
          <div className="el-assist__offer">
            <p className="el-assist__first" dir="auto">{offerQuestion(state.offer)}</p>
            <div className="el-assist__actions">
              <Button size="sm" variant="primary" onClick={actions.onStartOffer}>Start</Button>
              <Button size="sm" variant="quiet" onClick={actions.onDismissOffer}>Not now</Button>
            </div>
          </div>
        )}
        {sessionActive}
        {state.error && <p className="el-field__error" role="alert">{state.error.text}</p>}
      </section>
    );
  }

  return (
    <section className="el-assist" data-hit aria-label="Live Assist" data-testid="assist-card">
      <div className="el-assist__bar" data-tauri-drag-region>
        {badge}
        <Button size="sm" variant="quiet" disabled={!nextMode} aria-label={`Mode: ${modeLabel(state.mode)}${nextMode ? `. Change to ${modeLabel(nextMode)}` : ""}`}
          onClick={() => nextMode && actions.onMode(nextMode)}>{modeLabel(state.mode)}</Button>
        <Button size="sm" variant="quiet" disabled={captionsOnly} aria-label={`Hints: ${HINT_WORDS[hint]}. Change to ${HINT_WORDS[NEXT_HINT[hint]]}`}
          onClick={() => actions.onHint(NEXT_HINT[hint])}>{HINT_WORDS[hint]}</Button>
        <Button size="sm" variant="quiet" aria-pressed={muted} disabled={captionsOnly} onClick={actions.onMute}>{muted ? "Muted for 60 s" : "Mute 60 s"}</Button>
        {state.budget.left != null && state.budget.total ? (
          <span className="el-assist__budget">
            <Progress label="Assist time left" value={state.budget.left} max={state.budget.total} valueText={`${Math.round(state.budget.left / 60)} minutes of assist left this month`} />
          </span>
        ) : <span className="el-assist__budget" />}
        <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label="Hide the card" onClick={actions.onHide} />
      </div>

      {reminder && (
        <div className="el-assist__note" role="note">
          <span>{reminder}</span>
          {reminder === CONSENT_LINE && <Button size="sm" variant="quiet" onClick={actions.onCopyConsent}>Copy</Button>}
          <Button size="sm" variant="quiet" onClick={() => setNoteSeen(true)}>OK</Button>
        </div>
      )}
      {state.modeNote && <p className="el-assist__mode el-caption" role="status">{state.modeNote}</p>}
      {sessionActive}

      <div className="el-assist__body" aria-live="polite" aria-busy={s?.status === "streaming"}>
        {question && <p className="el-assist__question el-caption" dir="auto">{question}</p>}
        {captionsOnly ? null : parsed && parsed.first ? (
          <>
            <p className="el-assist__first" dir="auto"><Bold text={parsed.first} /></p>
            {parsed.bullets.length > 0 && (
              <ul className="el-assist__bullets">{parsed.bullets.map((b, i) => <li key={i} dir="auto"><Bold text={b} /></li>)}</ul>
            )}
            {parsed.sayNext && <p className="el-assist__next" dir="auto"><span className="el-caption">Say next</span> {parsed.sayNext}</p>}
            {parsed.language && (
              <p className="el-assist__language" lang={parsed.language.label === "Arabic" ? "ar" : parsed.language.label === "Urdu" ? "ur" : "en"} dir={rtl ? "rtl" : "ltr"}>
                <span className="el-caption" dir="ltr">{parsed.language.label}</span> {parsed.language.text}
              </p>
            )}
          </>
        ) : s?.status === "streaming" ? (
          <p className="el-caption">Writing a suggestion</p>
        ) : state.skipped ? (
          <p className="el-caption">No reply needed</p>
        ) : !state.error ? (
          <p className="el-caption">Suggestions appear here when they ask you something.</p>
        ) : null}
        {state.error && <p className="el-field__error" role="alert">{state.error.text}</p>}
      </div>

      {!captionsOnly && (
        <div className="el-assist__actions">
          <Button size="sm" variant="primary" onClick={() => actions.onSend("help_now")}>Help me now</Button>
          <Button size="sm" disabled={!s} onClick={() => actions.onSend("regenerate")}>Regenerate</Button>
          <Button size="sm" disabled={!s} onClick={() => actions.onSend("shorter")}>Shorter</Button>
          <Button size="sm" disabled={!s} onClick={() => actions.onSend("formal")}>More formal</Button>
          <Button size="sm" disabled={!s} onClick={() => actions.onSend("translate")}>Explain in Urdu</Button>
        </div>
      )}

      {state.captions.length > 0 && (
        <ol className="el-assist__captions" aria-label="Captions">
          {state.captions.map((c) => (
            <li key={c.turnId} className={c.final ? undefined : "el-assist__partial"}>
              <span className="el-assist__who">{c.channel === "other" ? "Them" : "Me"}</span> <span dir="auto">{c.text}</span>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
