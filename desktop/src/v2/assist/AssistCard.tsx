// The Live Assist card (v1.9 L4): the question in small grey, the first line big and bold, up to three bullets, an
// optional "Say next" and the language line; the captions strip under it (Them / Me, last two lines); the in-call
// controls on its top bar. Rendered token by token from the card state (card.ts). The window is the core's: hidden from
// screen sharing like the other panels, with the privacy badge; [data-hit] marks what stays clickable. The app's words
// are in the window's language (D-264); the suggestions, the captions and the consent line (said to the other side,
// English in every language) are shown as they are.
import { useState, type ReactNode } from "react";
import { isRtl, lineDir, t, tc, useLanguage, wordsIn, type Lang } from "../../i18n";
import { Button, Icons, Progress } from "../components";
import { CONSENT_LINE, type AssistMode, type HintLevel, type SendKind } from "./adapter";
import { boldParts, errorText, modeLabel, modeNote, offerQuestion, parseSuggestion, sessionActiveWords, shown, type CardState, type ParsedSuggestion } from "./card";

export { CONSENT_LINE } from "./adapter";

/** The reminder for an interview (in place of the consent line), in `lang`: the window's when left out. */
export function interviewNote(lang?: Lang): string {
  const { t: say } = wordsIn(lang);
  return say("Check whether this interview allows AI help.");
}
export const INTERVIEW_NOTE = interviewNote("en");

/** A hint level's name, in the window's language. */
export function hintWords(level: HintLevel): string {
  switch (level) {
    case "keywords": return t("Keywords");
    case "outline": return t("Outline");
    case "full": return t("Full answer");
  }
}
const NEXT_HINT: Record<HintLevel, HintLevel> = { keywords: "outline", outline: "full", full: "keywords" };

/** The name of the language a suggestion's language line is in, in the window's language. */
function languageName(label: NonNullable<ParsedSuggestion["language"]>["label"]): string {
  switch (label) {
    case "Urdu": return t("Urdu");
    case "Roman Urdu": return t("Roman Urdu");
    case "Arabic": return t("Arabic");
  }
}

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
  const lang = useLanguage();
  const rtlWindow = isRtl(lang);
  const [noteSeen, setNoteSeen] = useState(false);
  const s = shown(state);
  const parsed = s?.text ? parseSuggestion(s.text) : null;
  const question = s?.turnId ? state.turns[s.turnId] : null;
  const captionsOnly = state.mode === "captions_only";
  const modes = state.allowedModes;
  const nextMode = modes.length > 1 && !captionsOnly ? modes[(modes.indexOf(state.mode as AssistMode) + 1) % modes.length] : null;
  const reminder = noteSeen ? null
    : state.meetingType === "interview" ? "interview"
      : state.meetingType ? "consent" : null;
  const rtl = parsed?.language && parsed.language.label !== "Roman Urdu";
  // In a right-to-left window the consent line is still English: in the English face, left to right.
  const english = rtlWindow ? { lang: "en", dir: "ltr" as const } : {};
  const offer = state.offer ? offerQuestion(state.offer) : null;

  // Only for a start made from this card (its offer): the question needs something to start again.
  const activeWords = state.sessionActive ? sessionActiveWords(state.sessionActive) : null;
  const note = state.modeChange ? modeNote(state.modeChange.mode, state.modeChange.reason) : null;
  const sessionActive = state.sessionActive && state.pendingStart && (
    <div className="el-assist__note" role="alert">
      <span dir={lineDir(activeWords?.text ?? "")}>{activeWords?.text}</span>
      <Button size="sm" variant="primary" onClick={actions.onEndAndStart}>{activeWords?.action}</Button>
      <Button size="sm" variant="quiet" onClick={actions.onKeepRunning}>{t("Keep running")}</Button>
    </div>
  );
  // What the state keeps (codes, the core's own words) is worded here, at draw time, so it follows a language change.
  const errorLine = state.error ? errorText(state.error) : "";
  const error = state.error && <p className="el-field__error" role="alert" dir={lineDir(errorLine)}>{errorLine}</p>;
  // Before a session: the core's offer for a prepared meeting's call (it never starts by itself).
  if (!state.sessionId) {
    if (!state.offer && !sessionActive && !state.error) return null;
    return (
      <section className="el-assist el-assist--offer" data-hit aria-label={t("Live Assist")} data-testid="assist-card">
        <div className="el-assist__bar">
          <span className="el-assist__budget" />
          <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label={t("Hide the card")} onClick={actions.onHide} />
        </div>
        {offer && (
          <div className="el-assist__offer">
            <p className="el-assist__first" dir={lineDir(offer)}>{offer}</p>
            <div className="el-assist__actions">
              <Button size="sm" variant="primary" onClick={actions.onStartOffer}>{t("Start")}</Button>
              <Button size="sm" variant="quiet" onClick={actions.onDismissOffer}>{t("Not now")}</Button>
            </div>
          </div>
        )}
        {sessionActive}
        {error}
      </section>
    );
  }

  const mode = modeLabel(state.mode);
  const minutesLeft = state.budget.left != null ? Math.round(state.budget.left / 60) : 0;
  return (
    <section className="el-assist" data-hit aria-label={t("Live Assist")} data-testid="assist-card">
      <div className="el-assist__bar" data-tauri-drag-region>
        {badge}
        <Button size="sm" variant="quiet" disabled={!nextMode}
          aria-label={nextMode ? t("Mode: :mode. Change to :next", { mode, next: modeLabel(nextMode) }) : t("Mode: :mode", { mode })}
          onClick={() => nextMode && actions.onMode(nextMode)}>{mode}</Button>
        <Button size="sm" variant="quiet" disabled={captionsOnly} aria-label={t("Hints: :level. Change to :next", { level: hintWords(hint), next: hintWords(NEXT_HINT[hint]) })}
          onClick={() => actions.onHint(NEXT_HINT[hint])}>{hintWords(hint)}</Button>
        <Button size="sm" variant="quiet" aria-pressed={muted} disabled={captionsOnly} onClick={actions.onMute}>{muted ? t("Muted for 60 s") : t("Mute 60 s")}</Button>
        {state.budget.left != null && state.budget.total ? (
          <span className="el-assist__budget">
            <Progress label={t("Assist time left")} value={state.budget.left} max={state.budget.total} valueText={tc(":count minute of assist left this month|:count minutes of assist left this month", minutesLeft)} />
          </span>
        ) : <span className="el-assist__budget" />}
        <Button size="sm" variant="quiet" icon={Icons.CloseIcon} aria-label={t("Hide the card")} onClick={actions.onHide} />
      </div>

      {reminder && (
        <div className="el-assist__note" role="note">
          {reminder === "consent" ? <span {...english}>{CONSENT_LINE}</span> : <span>{interviewNote()}</span>}
          {reminder === "consent" && <Button size="sm" variant="quiet" onClick={actions.onCopyConsent}>{t("Copy")}</Button>}
          <Button size="sm" variant="quiet" onClick={() => setNoteSeen(true)}>{t("OK")}</Button>
        </div>
      )}
      {note && <p className="el-assist__mode el-caption" role="status">{note}</p>}
      {sessionActive}

      <div className="el-assist__body" aria-live="polite" aria-busy={s?.status === "streaming"}>
        {question && <p className="el-assist__question el-caption" dir="auto">{question}</p>}
        {captionsOnly ? null : parsed && parsed.first ? (
          <>
            <p className="el-assist__first" dir="auto"><Bold text={parsed.first} /></p>
            {parsed.bullets.length > 0 && (
              // In a right-to-left window the list takes its bullets' direction, so an English list keeps its markers inside.
              <ul className="el-assist__bullets" dir={rtlWindow ? "auto" : undefined}>{parsed.bullets.map((b, i) => <li key={i} dir="auto"><Bold text={b} /></li>)}</ul>
            )}
            {parsed.sayNext && (
              <p className="el-assist__next" dir="auto"><span className="el-caption">{t("Say next")}</span> {rtlWindow ? <bdi>{parsed.sayNext}</bdi> : parsed.sayNext}</p>
            )}
            {parsed.language && (
              <p className="el-assist__language" lang={parsed.language.label === "Arabic" ? "ar" : parsed.language.label === "Urdu" ? "ur" : "en"} dir={rtl ? "rtl" : "ltr"}>
                <span className="el-caption" {...(rtlWindow ? { lang, dir: "rtl" as const } : { dir: "ltr" as const })}>{languageName(parsed.language.label)}</span> {parsed.language.text}
              </p>
            )}
          </>
        ) : s?.status === "streaming" ? (
          <p className="el-caption">{t("Writing a suggestion")}</p>
        ) : state.skipped ? (
          <p className="el-caption">{t("No reply needed")}</p>
        ) : !state.error ? (
          <p className="el-caption">{t("Suggestions appear here when they ask you something.")}</p>
        ) : null}
        {error}
      </div>

      {!captionsOnly && (
        <div className="el-assist__actions">
          <Button size="sm" variant="primary" onClick={() => actions.onSend("help_now")}>{t("Help me now")}</Button>
          <Button size="sm" disabled={!s} onClick={() => actions.onSend("regenerate")}>{t("Regenerate")}</Button>
          <Button size="sm" disabled={!s} onClick={() => actions.onSend("shorter")}>{t("Shorter")}</Button>
          <Button size="sm" disabled={!s} onClick={() => actions.onSend("formal")}>{t("More formal")}</Button>
          <Button size="sm" disabled={!s} onClick={() => actions.onSend("translate")}>{t("Explain in Urdu")}</Button>
        </div>
      )}

      {state.captions.length > 0 && (
        <ol className="el-assist__captions" aria-label={t("Captions")}>
          {state.captions.map((c) => (
            <li key={c.turnId} className={c.final ? undefined : "el-assist__partial"}>
              <span className="el-assist__who">{c.channel === "other" ? t("Them") : t("Me")}</span> <span dir="auto">{c.text}</span>
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
