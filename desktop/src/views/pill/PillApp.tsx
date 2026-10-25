import { useCallback, useEffect, useRef, useState, type MouseEvent, type ReactNode } from "react";
import { getCurrentWebviewWindow } from "@tauri-apps/api/webviewWindow";
import { api, errorMessage, listenTo, type FarSidePayload, type MarkPayload, type RecordingState } from "../../lib/ipc";
import { useHitRegions } from "../../lib/hitRegions";
import { elapsedAt, formatTimer, isTwoChannel, meterLevel, nextAnchor, sourceLabel, type TimerAnchor } from "../../lib/controls";
import { CaptionsIcon, SourceAppIcon, TrashIcon } from "../../components/Icons";
import { AlertIcon, BookmarkIcon, MinimizeIcon, PauseIcon, PlayIcon, StopIcon } from "../../components/ControlIcons";
import "../controls.css";

/** How long a failed action's message replaces the source label. */
const ERROR_MS = 4000;
/** The drag runs in the OS move loop; the position is read this long after it started (contract 4.8). */
const DRAG_SAVE_MS = 400;
/** How long the bookmark tray stays after the last bookmark or tag. */
export const MARK_TRAY_MS = 6000;

type Action = "pause" | "stop" | "cancel" | "captions" | "hide" | "mark";

/**
 * Loom-style recording controls in the 308 x 84 `pill` window: REC + timer, source, Me/Client levels, Pause/Resume,
 * Stop (the review card follows), Cancel with an inline confirm, Mark, Captions on/off and Hide. Under the pill, a
 * bookmark (from here or the shortcut) shows its tag presets and Undo for a few seconds, and a call that has not
 * heard the other side for 30 s shows a warning. The window never takes
 * focus: every control works on click, and only the pill itself is reported as a hit region.
 */
export default function PillApp() {
  const [rec, setRec] = useState<RecordingState | null>(null);
  const [visible, setVisible] = useState(false);
  const [anchor, setAnchor] = useState<TimerAnchor | null>(null);
  const [now, setNow] = useState(() => Date.now());
  const [confirming, setConfirming] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [mark, setMark] = useState<MarkPayload | null>(null);
  const [markAt, setMarkAt] = useState(0);
  const [tags, setTags] = useState<string[]>([]);
  const [farSide, setFarSide] = useState<FarSidePayload | null>(null);
  const busy = useRef<Action | null>(null);
  /** Set while Stop, Cancel or Hide runs, so a late once-a-second event cannot bring the pill back. */
  const closing = useRef(false);
  const dragStartedAt = useRef(0);

  const apply = useCallback((state: RecordingState) => {
    const t = Date.now();
    setRec(state);
    setAnchor((prev) => nextAnchor(prev, state, t));
    setNow(t);
    const show = state.active && !state.hidden;
    const keep = closing.current;
    setVisible((was) => (show && keep ? was : show));
    if (!state.active) {
      setConfirming(false);
      setMark(null);
      setFarSide(null);
    }
  }, []);

  const refresh = useCallback(() => api.recordingState().then(apply).catch(() => undefined), [apply]);

  useEffect(() => {
    let alive = true;
    let heardEvent = false;
    api.recordingState().then((state) => {
      if (alive && !heardEvent) apply(state);
    }).catch(() => undefined);
    const subscriptions = [
      listenTo("recording:state", (state) => {
        heardEvent = true;
        apply(state);
      }),
      listenTo("recording:marked", (payload) => {
        setMark(payload);
        setMarkAt(Date.now());
        api.markerTags().then(setTags).catch(() => undefined);
      }),
      listenTo("capture:far_side", (payload) => setFarSide(payload.silent ? payload : null)),
      listenTo("pill:visibility", (show) => {
        setVisible(show);
        if (show) void refresh();
        else setConfirming(false);
      }),
    ];
    return () => {
      alive = false;
      subscriptions.forEach((p) => p.then((unlisten) => unlisten()).catch(() => undefined));
    };
  }, [apply, refresh]);

  const live = rec?.active ? rec : null;
  const shown = visible && live !== null;
  const running = shown && !live.paused;

  // Interpolates the timer between the core's 1 s events; a paused timer shows the frozen value.
  useEffect(() => {
    if (!running) return;
    const timer = window.setInterval(() => setNow(Date.now()), 250);
    return () => window.clearInterval(timer);
  }, [running]);

  useEffect(() => {
    if (!error) return;
    const timer = window.setTimeout(() => setError(null), ERROR_MS);
    return () => window.clearTimeout(timer);
  }, [error]);

  // The window cannot take focus, so Escape only reaches it in a plain browser; it still means "No".
  useEffect(() => {
    if (!confirming) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") setConfirming(false);
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [confirming]);

  // The bookmark tray closes by itself; tagging keeps it open a little longer.
  useEffect(() => {
    if (!mark) return;
    const timer = window.setTimeout(() => setMark(null), MARK_TRAY_MS);
    return () => window.clearTimeout(timer);
  }, [mark, markAt]);

  const tray = mark ? "mark" : farSide && running ? "warn" : null;
  useHitRegions([shown, confirming, tray]);

  /** Runs one command at a time; on failure shows the message and resyncs with the core. */
  const run = async (action: Action, command: () => Promise<unknown>) => {
    if (busy.current) return;
    busy.current = action;
    try {
      await command();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      busy.current = null;
      closing.current = false;
      await refresh();
    }
  };

  /** Optimistic local change, so the click answers at once; the core's state follows. */
  const patch = (change: Partial<RecordingState>) => {
    if (!rec) return;
    apply({ ...rec, elapsedMs: elapsedAt(anchor, Date.now()), ...change });
  };

  const togglePause = () => {
    if (!rec || busy.current) return;
    const paused = rec.paused;
    patch({ paused: !paused });
    void run("pause", paused ? api.resumeRecording : api.pauseRecording);
  };

  const stop = () => {
    if (busy.current) return;
    closing.current = true;
    setVisible(false);
    void run("stop", api.stopRecording);
  };

  const confirmCancel = () => {
    if (busy.current) return;
    closing.current = true;
    setConfirming(false);
    setVisible(false);
    void run("cancel", api.cancelRecording);
  };

  const toggleCaptions = () => {
    if (!rec || busy.current) return;
    patch({ captions: !rec.captions });
    void run("captions", api.toggleCaptions);
  };

  const markNow = () => void run("mark", api.markMoment);

  const tagMark = (tag: string) => {
    if (!mark) return;
    const next = mark.tag === tag ? null : tag;
    setMark({ ...mark, tag: next });
    setMarkAt(Date.now());
    api.tagLastMark(next, mark.note).catch((e) => setError(errorMessage(e)));
  };

  const undoMark = () => {
    setMark(null);
    api.undoMark().catch((e) => setError(errorMessage(e)));
  };

  const hide = () => {
    if (busy.current) return;
    closing.current = true;
    setVisible(false);
    void run("hide", () => api.setPillHidden(true));
  };

  const saveDrag = () => api.pillMoved().catch(() => undefined);

  const startDrag = async (e: MouseEvent<HTMLDivElement>) => {
    if (e.button !== 0 || (e.target as Element).closest("button")) return;
    e.preventDefault();
    dragStartedAt.current = Date.now();
    await getCurrentWebviewWindow().startDragging().catch(() => undefined);
    window.setTimeout(saveDrag, DRAG_SAVE_MS);
  };

  // A drag that outlasts the timer is saved again on the first pointer movement after the OS move loop ends.
  const afterDrag = () => {
    if (!dragStartedAt.current || Date.now() - dragStartedAt.current < DRAG_SAVE_MS) return;
    dragStartedAt.current = 0;
    void saveDrag();
  };

  if (!shown || !live) return <div className="pill-root" />;

  const paused = live.paused;
  const label = sourceLabel(live.sourceApp);
  const elapsed = formatTimer(elapsedAt(anchor, now));
  const [me, client] = live.levels;

  return (
    <div className="pill-root">
      <div className={`rec-pill${paused ? " paused" : ""}`} data-hit data-testid="recording-pill" role="toolbar"
        aria-label="Recording controls" onMouseDown={startDrag} onMouseMove={afterDrag}>
        <span className="rec-status">
          <span className="rec-dot" aria-hidden="true" />
          <span className="rec-state">{paused ? "Paused" : "REC"}</span>
        </span>
        <span className="rec-timer" data-testid="pill-timer">{elapsed}</span>
        {error ? (
          <span className="rec-source rec-error" role="alert" title={error}><span className="rec-label">{error}</span></span>
        ) : (
          <span className="rec-source" title={label}>
            <SourceAppIcon app={live.sourceApp ?? "other"} size={14} />
            <span className="rec-label">{label}</span>
          </span>
        )}
        {isTwoChannel(live.mode) && !confirming && (
          <span className="rec-meters" data-testid="pill-meters">
            <span className="rec-meter me" title="Me" aria-hidden="true"><span style={{ height: `${Math.round(meterLevel(me) * 100)}%` }} /></span>
            <span className="rec-meter client" title="Client" aria-hidden="true"><span style={{ height: `${Math.round(meterLevel(client) * 100)}%` }} /></span>
          </span>
        )}
        {confirming ? (
          <span className="rec-confirm" role="group" aria-label="Discard this recording?">
            <span className="rec-confirm-q">Discard?</span>
            <button type="button" className="rec-text-btn danger" onClick={confirmCancel}>Yes</button>
            <button type="button" className="rec-text-btn" onClick={() => setConfirming(false)}>No</button>
          </span>
        ) : (
          <span className="rec-actions">
            <PillButton label={paused ? "Resume recording" : "Pause recording"} onClick={togglePause}>
              {paused ? <PlayIcon size={14} /> : <PauseIcon size={14} />}
            </PillButton>
            <PillButton label="Stop and review" className="stop" onClick={stop}><StopIcon size={14} /></PillButton>
            <PillButton label="Cancel recording" onClick={() => setConfirming(true)}><TrashIcon size={15} /></PillButton>
            <PillButton label="Bookmark this moment" onClick={markNow}><BookmarkIcon size={15} /></PillButton>
            <PillButton label={live.captions ? "Turn captions off" : "Turn captions on"} pressed={live.captions} onClick={toggleCaptions}>
              <CaptionsIcon size={15} />
            </PillButton>
            <PillButton label="Hide controls" onClick={hide}><MinimizeIcon size={15} /></PillButton>
          </span>
        )}
      </div>
      {tray === "mark" && mark && (
        <div className="mark-tray" data-hit data-testid="mark-tray" role="group" aria-label="Bookmark">
          <span className="mark-done">Bookmarked {formatTimer(mark.atMs)}</span>
          <span className="mark-tags">
            {tags.map((tag) => (
              <button type="button" key={tag} className="mark-tag" aria-pressed={mark.tag === tag} onClick={() => tagMark(tag)}>{tag}</button>
            ))}
          </span>
          <button type="button" className="rec-text-btn" onClick={undoMark}>Undo</button>
        </div>
      )}
      {tray === "warn" && farSide && (
        <div className="mark-tray warn" data-hit data-testid="far-side-warning" role="alert">
          <AlertIcon size={14} />
          <span className="mark-text">Not hearing the other side for {farSide.seconds} s. Check the call's speaker or device.</span>
          <button type="button" className="rec-btn" aria-label="Dismiss warning" title="Dismiss" onClick={() => setFarSide(null)}><MinimizeIcon size={14} /></button>
        </div>
      )}
    </div>
  );
}

function PillButton({ label, onClick, pressed, className, children }: {
  label: string;
  onClick: () => void;
  pressed?: boolean;
  className?: string;
  children: ReactNode;
}) {
  return (
    <button type="button" className={`rec-btn${className ? ` ${className}` : ""}`} aria-label={label} title={label}
      aria-pressed={pressed} onClick={onClick}>
      {children}
    </button>
  );
}
