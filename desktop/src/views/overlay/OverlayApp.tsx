import { useEffect, useReducer, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { getCurrentWebviewWindow } from "@tauri-apps/api/webviewWindow";
import { api, subscribeCaptions, type CaptionEvent } from "../../lib/ipc";
import { useHitRegions } from "../../lib/hitRegions";
import { CloseIcon, CopyIcon, GripIcon } from "../../components/Icons";

export interface CaptionState {
  lines: { channel: number; speaker: string; text: string }[];
  partial: Record<number, string>;
  live: boolean;
  message: string | null;
}

export const initialCaptions: CaptionState = { lines: [], partial: {}, live: false, message: null };

/** Pure reducer (unit-tested): keeps the last few final lines plus the current partial per speaker. */
export function captionsReducer(state: CaptionState, event: CaptionEvent): CaptionState {
  switch (event.kind) {
    case "partial":
      return { ...state, partial: { ...state.partial, [event.channel]: event.text } };
    case "final": {
      const lines = [...state.lines, { channel: event.channel, speaker: event.speaker, text: event.text }].slice(-3);
      const partial = { ...state.partial };
      delete partial[event.channel];
      return { ...state, lines, partial };
    }
    case "status":
      return { ...state, live: event.live, message: event.message };
    case "clear":
      return initialCaptions;
  }
}

/** Translucent caption overlay: never focused; click-through except the small toolbar. */
export default function OverlayApp() {
  const [state, dispatch] = useReducer(captionsReducer, initialCaptions);
  const [visible, setVisible] = useState(false);

  useEffect(() => {
    subscribeCaptions((e) => {
      dispatch(e);
      if (e.kind === "status" && e.live) setVisible(true);
    }).catch(() => undefined);
    const un = listen<boolean>("overlay:visibility", (e) => setVisible(e.payload));
    return () => {
      un.then((f) => f()).catch(() => undefined);
    };
  }, []);

  useHitRegions([visible, state.lines.length]);

  const startDrag = async () => {
    await getCurrentWebviewWindow().startDragging().catch(() => undefined);
    window.setTimeout(() => api.snapOverlay().catch(() => undefined), 400);
  };

  const partials = Object.entries(state.partial);
  return (
    <div className={`overlay-root ${visible ? "" : "overlay-hidden"}`}>
      <div className="overlay-card" data-testid="caption-card">
        <div className="overlay-head">
          <span className={`status-dot ${state.live ? "live" : ""}`} />
          <span>{state.live ? "Live" : "Captions"}</span>
          <div className="overlay-tools" data-hit>
            <button className="icon-btn grip" title="Drag" onMouseDown={startDrag}><GripIcon size={14} /></button>
            <button className="icon-btn" title="Copy last 2 minutes" onClick={() => api.copyLastMinutes(2).catch(() => undefined)}><CopyIcon size={13} /></button>
            <button className="icon-btn" title="Hide captions" aria-label="Hide captions" onClick={() => api.hideCaptions().catch(() => undefined)}><CloseIcon size={14} /></button>
          </div>
        </div>
        {state.message && <div className="caption-line small" style={{ color: "#fda4af" }}>{state.message}</div>}
        {state.lines.length === 0 && partials.length === 0 && !state.message && <div className="caption-line partial">Listening…</div>}
        {state.lines.map((line, i) => (
          <div className="caption-line" key={i}><span className={`who spk-${line.channel === 2 ? 2 : 1}`}>{line.speaker}</span>{line.text}</div>
        ))}
        {partials.map(([channel, text]) => (
          <div className="caption-line partial" key={`p${channel}`}><span className={`who spk-${channel === "2" ? 2 : 1}`}>{channel === "2" ? "Client" : "Me"}</span>{text}</div>
        ))}
      </div>
    </div>
  );
}
