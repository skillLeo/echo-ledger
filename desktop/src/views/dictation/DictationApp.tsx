import { useEffect, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { announceReady, listenTo, subscribeCaptions } from "../../lib/ipc";
import { meterLevel } from "../../lib/controls";
import { playTone } from "../../lib/sounds";

/**
 * How long the box stays when a dictation ended while it was still shown (it could not start: not paired, no
 * microphone): long enough to read why, then it hides, because a box left on screen keeps drawing (C9).
 */
export const ENDED_MESSAGE_MS = 4000;

/**
 * Tiny unfocusable dictation box with a flow bar (B14): a level meter and what you say; the text is typed into your
 * app when you finish. Plays the start and stop sounds when they are on.
 */
export default function DictationApp() {
  const [visible, setVisible] = useState(false);
  const [finals, setFinals] = useState<string[]>([]);
  const [partial, setPartial] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [level, setLevel] = useState(0);
  const [holding, setHolding] = useState(false);
  const [ended, setEnded] = useState(false);
  const textRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    let alive = true;
    const subscribed = subscribeCaptions((e) => {
      if (e.kind === "clear") {
        setFinals([]);
        setPartial("");
        setMessage(null);
      } else if (e.kind === "partial") setPartial(e.text);
      else if (e.kind === "final") {
        setFinals((f) => [...f, e.text]);
        setPartial("");
      } else if (e.kind === "status") setMessage(e.message);
    }).catch(() => undefined);
    const subscriptions = [
      listen<boolean>("overlay:visibility", (e) => {
        setVisible(e.payload);
        if (e.payload) setEnded(false);
      }),
      listenTo("dictation:level", (l) => setLevel(typeof l === "number" ? l : 0)),
      listenTo("dictation:state", (s) => {
        if (!s) return;
        setHolding(s.mode === "push_to_talk");
        if (s.sounds) playTone(s.active ? "start" : "stop");
        setEnded(!s.active);
        if (!s.active) setLevel(0);
      }),
    ];
    announceReady([subscribed, ...subscriptions], () => alive);
    return () => {
      alive = false;
      subscriptions.forEach((p) => p.then((f) => f()).catch(() => undefined));
    };
  }, []);

  // A finished dictation hides the box before it ends; one that could not start leaves it shown with the reason.
  useEffect(() => {
    if (!visible || !ended) return;
    const timer = window.setTimeout(() => setVisible(false), ENDED_MESSAGE_MS);
    return () => window.clearTimeout(timer);
  }, [visible, ended]);

  // Long dictation wraps inside the small box and scrolls; the newest words stay in view (A6.DU8).
  useEffect(() => {
    const el = textRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [finals, partial]);

  return (
    <div className={`overlay-root ${visible ? "" : "overlay-hidden"}`} style={{ alignItems: "flex-start" }}>
      <div className="overlay-card dictation-card">
        <div className="overlay-head">
          <span className="listening"><span /><span /><span /></span>
          <span>{holding ? "Dictating — let go of the shortcut to insert" : "Dictating — press the shortcut again to insert"}</span>
        </div>
        <div className="flow-bar" role="meter" aria-label="Microphone level" aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(meterLevel(level) * 100)}>
          <span style={{ width: `${Math.round(meterLevel(level) * 100)}%` }} />
        </div>
        {message && <div className="caption-line small caption-message">{message}</div>}
        <div ref={textRef} className="caption-line dictation-text">{finals.join(" ")} <span className="partial" style={{ opacity: 0.7 }}>{partial}</span></div>
      </div>
    </div>
  );
}
