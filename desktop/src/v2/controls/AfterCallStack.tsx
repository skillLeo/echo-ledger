// What the after-call window shows in the new look, one thing at a time (the call choice): the card for a call waiting
// for a choice, one confirmation with Undo, or the grouped card for back-to-back calls. The core decides what is there
// (`aftercall:state`); this only draws it and sends the answers.
import { useState } from "react";
import { api, errorMessage, type AfterCallState, type AfterEachCall, type CallChoice } from "../../lib/ipc";
import { AfterCallCard, CallDoneCard, CallGroupCard } from "./AfterCallCard";

export function AfterCallStack({ state, screens = 0, onGone }: {
  state: AfterCallState;
  /** Screen moments of the call the card is about. */
  screens?: number;
  /** A part the person answered: the window drops it before the core's next state. */
  onGone: (part: "ask" | "done" | "group") => void;
}) {
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
  const answer = (choice: CallChoice) => item && void act(() => api.callCardAnswer(item.id, choice));
  return (
    <div className="el-root el-embedded el-aftercall-stack">
      {item ? (
        <AfterCallCard key={item.id} item={item} hint={state.hint} screens={screens || item.screens || 0} busy={busy} error={error}
          actions={{
            onTranscribe: () => answer("transcribe"),
            onSaveAudio: () => answer("audio"),
            onDelete: () => answer("delete"),
            onOpen: () => void act(() => api.pendingOpen(item.id, true), "ask"),
          }} />
      ) : state.group ? (
        <CallGroupCard group={state.group} busy={busy} error={error} onReview={() => void act(() => api.callGroupClose(true), "group")}
          onClose={() => void act(() => api.callGroupClose(false), "group")} onUndo={(id) => void act(() => api.callUndo(id))}
          onTranscribe={(id) => void act(() => api.callDoneTranscribe(id))} />
      ) : done ? (
        <CallDoneCard key={`${done.id}-${done.kind}`} done={done} busy={busy} error={error}
          onUndo={() => void act(() => api.callUndo(done.id))}
          onTranscribe={() => void act(() => api.callDoneTranscribe(done.id))}
          onAlways={(value: AfterEachCall) => void act(() => api.callAlways(done.id, value))} />
      ) : error ? <p className="el-field__error" role="alert">{error}</p> : null}
    </div>
  );
}
