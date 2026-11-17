// What the after-call window shows in the new look: the card for a call waiting for an answer, and one Toast at a time
// for a call saved by itself or deleted, each with Undo. The core decides what is there (`aftercall:state`); this only
// draws it and sends the answers.
import { useState } from "react";
import { api, errorMessage, type AfterCallState } from "../../lib/ipc";
import { ToastRegion } from "../components";
import { AfterCallCard, DeletedToast, SavedToastV2 } from "./AfterCallCard";
import { keepAudioOnly } from "./commands";

export function AfterCallStack({ state, screens = 0, onGone }: {
  state: AfterCallState;
  /** Screen moments of the call the card is about. */
  screens?: number;
  /** A toast or card the person dismissed: the window drops it before the core's next state. */
  onGone: (part: "saved" | "discarded" | "ask") => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const act = async (fn: () => Promise<unknown>, part?: "ask" | "saved" | "discarded") => {
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
  const toast = state.discarded ?? state.saved;
  return (
    <div className="el-root el-embedded el-aftercall-stack">
      {item && (
        <AfterCallCard key={item.id} item={item} screens={screens} busy={busy} error={error}
          actions={{
            onTranscribe: () => void act(() => api.pendingKeep(item.id), "ask"),
            onSaveAudio: () => void act(() => keepAudioOnly(item.id), "ask"),
            onDelete: () => void act(() => api.afterCallDiscard(item.id)),
            onOpen: () => void act(() => api.pendingOpen(item.id, true)),
          }} />
      )}
      {toast && (
        <div data-hit>
          <ToastRegion inline>
            {state.discarded ? (
              <DeletedToast item={state.discarded} onUndo={() => void act(() => api.afterCallDiscardUndo(state.discarded!.id))} onClose={() => onGone("discarded")} />
            ) : (
              <SavedToastV2 item={toast} onUndo={() => void act(() => api.savedUndo(toast.id), "saved")} onClose={() => onGone("saved")} />
            )}
          </ToastRegion>
        </div>
      )}
      {!item && error && <p className="el-field__error" role="alert">{error}</p>}
    </div>
  );
}
