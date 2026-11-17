// The `assist` window (the core opens it per L4: top-centre under the webcam, hidden from screen sharing, click-through
// outside the card). Shown only while live_assist and ui_v2.live are on for this person. In a development build
// `?assistfixture` plays the fixture session instead of listening to the core.
import { useCallback, useEffect, useReducer, useRef, useState } from "react";
import { useHitRegions } from "../../lib/hitRegions";
import { useConcealed } from "../../lib/privacy";
import { PrivacyBadge } from "../../components/PrivacyBadge";
import { announceReady, errorMessage } from "../../lib/ipc";
import { useUiFlags } from "../flags";
import { assist, type AssistMessage, type AssistMode, type HintLevel, type SendKind } from "./adapter";
import { AssistCard, CONSENT_LINE } from "./AssistCard";
import { EMPTY_CARD, cardReducer, isSessionActive, sessionActiveOf, shown, type CardState } from "./card";
import type { SessionActive } from "./adapter";
import "../components";

type Action =
  | AssistMessage
  | { type: "local_error"; text: string }
  | { type: "reset"; messages: AssistMessage[] }
  | { type: "starting"; prepId: string | null; clientRef: string | null }
  | { type: "session_active_refused"; active: SessionActive }
  | { type: "offer_dismissed" }
  | { type: "session_active_dismissed" };

export function reduce(state: CardState, a: Action): CardState {
  switch (a.type) {
    case "reset": return a.messages.reduce(cardReducer, EMPTY_CARD);
    case "local_error": return { ...state, error: { text: a.text, fatal: false, suggestionId: null } };
    case "starting": return { ...state, pendingStart: { prepId: a.prepId, clientRef: a.clientRef }, offer: null, error: null };
    case "session_active_refused": return { ...state, sessionActive: a.active };
    case "offer_dismissed": return { ...state, offer: null };
    case "session_active_dismissed": return { ...state, sessionActive: null, pendingStart: null };
    default: return cardReducer(state, a);
  }
}

export function useAssistCard(fixture: boolean): [CardState, (a: Action) => void, { hint: HintLevel; setHint: (h: HintLevel) => void; mutedUntil: number; setMutedUntil: (t: number) => void }] {
  const [state, dispatch] = useReducer(reduce, EMPTY_CARD);
  const [hint, setHint] = useState<HintLevel>("outline");
  const [mutedUntil, setMutedUntil] = useState(0);
  useEffect(() => {
    if (import.meta.env.DEV && fixture) {
      // Development builds only: a release build drops this branch and never loads the fixture.
      let stop: (() => void) | null = null;
      let alive = true;
      void import("./fixture").then((m) => {
        if (alive) stop = m.fixtureStream(dispatch);
      });
      return () => {
        alive = false;
        stop?.();
      };
    }
    let alive = true;
    assist.state().then((snap) => {
      if (!alive || !snap) return;
      dispatch({ type: "reset", messages: snap.messages });
      setHint(snap.hint_level);
      if (snap.muted_until) setMutedUntil(Date.parse(snap.muted_until));
    }).catch(() => undefined);
    const un = assist.listen((m) => alive && dispatch(m));
    // The core builds this window like the others and waits for it to say it listens (C9), whatever the flags: until
    // it does, the window is never let go after a session, and the smoke test waits for it.
    announceReady([un], () => alive);
    return () => {
      alive = false;
      void un.then((f) => f()).catch(() => undefined);
    };
  }, [fixture]);
  return [state, dispatch, { hint, setHint, mutedUntil, setMutedUntil }];
}

export default function AssistApp() {
  const fixture = import.meta.env.DEV && new URLSearchParams(location.search).has("assistfixture");
  const [ui, , readFor] = useUiFlags(true, "assist", false);
  const [state, dispatch, local] = useAssistCard(fixture);
  const [now, setNow] = useState(() => Date.now());
  const concealed = useConcealed();
  const on = fixture || (ui.flags.live_assist && ui.flags["ui_v2.live"]);
  useHitRegions([on, state.shownId, state.captions.length, state.modeNote, concealed]);
  useEffect(() => {
    if (local.mutedUntil <= now) return;
    const t = window.setTimeout(() => setNow(Date.now()), local.mutedUntil - now);
    return () => window.clearTimeout(t);
  }, [local.mutedUntil, now]);

  // 1.9.0-n's "shown" report: when a suggestion's first text is drawn, the time since its suggestion_start.
  const startedAt = useRef(new Map<string, number>());
  const reported = useRef(new Set<string>());
  useEffect(() => {
    for (const sug of state.suggestions) if (!startedAt.current.has(sug.id)) startedAt.current.set(sug.id, performance.now());
  }, [state.suggestions]);
  const onCard = shown(state);
  useEffect(() => {
    if (fixture || !onCard?.text || reported.current.has(onCard.id)) return;
    reported.current.add(onCard.id);
    const since = startedAt.current.get(onCard.id);
    const renderMs = since === undefined ? 0 : Math.max(0, Math.round(performance.now() - since));
    assist.shown(onCard.id, renderMs).catch(() => undefined);
  }, [fixture, onCard?.id, onCard?.text]);

  const run = useCallback((fn: () => Promise<unknown>) => {
    fn().catch((e) => dispatch(isSessionActive(e) ? { type: "session_active_refused", active: sessionActiveOf(e) } : { type: "local_error", text: errorMessage(e) }));
  }, [dispatch]);
  const start = (prepId: string | null, clientRef: string | null) => {
    dispatch({ type: "starting", prepId, clientRef });
    run(() => assist.start(prepId, null, clientRef));
  };

  if (concealed || (!fixture && readFor === null) || !on || (state.ended && !state.offer)) return <div className="el-root el-embedded el-assist-root" />;
  return (
    <div className="el-root el-embedded el-assist-root">
      <AssistCard state={state} hint={local.hint} muted={local.mutedUntil > now} badge={<PrivacyBadge compact />}
        actions={{
          onSend: (kind: SendKind) => run(() => assist.send(kind)),
          onMode: (mode: AssistMode) => run(() => assist.set({ mode })),
          onHint: (level: HintLevel) => { local.setHint(level); run(() => assist.set({ hint_level: level })); },
          onMute: () => {
            const until = Date.now() + 60_000;
            local.setMutedUntil(until);
            setNow(Date.now());
            run(() => assist.set({ muted_seconds: 60 }));
          },
          onHide: () => {
            // Closing the card while it asks about a running session keeps that session.
            if (state.sessionActive && state.pendingStart) {
              dispatch({ type: "session_active_dismissed" });
              run(() => assist.keepRunning());
            }
            run(() => assist.hideCard());
          },
          onCopyConsent: () => run(() => navigator.clipboard.writeText(CONSENT_LINE)),
          onStartOffer: () => state.offer && start(state.offer.prepId, state.offer.clientRef),
          onDismissOffer: () => dispatch({ type: "offer_dismissed" }),
          onEndAndStart: () => {
            const again = state.pendingStart;
            const running = state.sessionActive;
            dispatch({ type: "session_active_dismissed" });
            dispatch({ type: "starting", prepId: again?.prepId ?? null, clientRef: again?.clientRef ?? null });
            run(() => assist.startReplacing(running?.session_id ?? null, again?.prepId ?? null, null, false, again?.clientRef ?? null));
          },
          onKeepRunning: () => {
            dispatch({ type: "session_active_dismissed" });
            run(() => assist.keepRunning());
          },
        }} />
    </div>
  );
}
