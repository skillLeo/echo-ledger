// One notice, as docs/DESIGN_SYSTEM.md "Notices" says (v1.6.1): a click goes where it is about (and closes it); ×
// closes it; a message hides by itself with a thin bar showing the time left, paused while the pointer, a finger or the
// keyboard is on it; a swipe left or right (finger, mouse drag or trackpad) closes it, following the drag and springing
// back when it was not far or quick enough; a drag is never a click; Esc on it closes it. Toasts and banners both use it.
import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import { Countdown, dragOpacity, SwipeTracker, WHEEL_END_MS, type SwipeEnd } from "../lib/notice";
import { reducedMotion } from "../lib/motion";
import { CloseIcon } from "./Icons";

type PauseReason = "hover" | "focus" | "touch" | "drag";

/** The timer of a notice: pauses for any reason given, resumes when none is left, calls `onEnd` when it runs out. */
function useNoticeTimer(ms: number | null, onEnd: () => void): { paused: boolean; pause: (r: PauseReason) => void; resume: (r: PauseReason) => void } {
  const countdown = useRef<Countdown | null>(null);
  const reasons = useRef(new Set<PauseReason>());
  const timer = useRef(0);
  const end = useRef(onEnd);
  end.current = onEnd;
  const [paused, setPaused] = useState(false);
  const schedule = useCallback(() => {
    window.clearTimeout(timer.current);
    const c = countdown.current;
    if (!c || c.paused) return;
    timer.current = window.setTimeout(() => end.current(), c.remaining(performance.now()));
  }, []);
  useEffect(() => {
    if (ms === null) return;
    countdown.current = new Countdown(ms, performance.now());
    reasons.current.clear();
    setPaused(false);
    schedule();
    return () => window.clearTimeout(timer.current);
  }, [ms, schedule]);
  const pause = useCallback((reason: PauseReason) => {
    const c = countdown.current;
    reasons.current.add(reason);
    if (!c || c.paused) return;
    c.pause(performance.now());
    window.clearTimeout(timer.current);
    setPaused(true);
  }, []);
  const resume = useCallback((reason: PauseReason) => {
    const c = countdown.current;
    reasons.current.delete(reason);
    if (!c || !c.paused || reasons.current.size > 0) return;
    c.resume(performance.now());
    setPaused(false);
    schedule();
  }, [schedule]);
  return { paused, pause, resume };
}

export function Notice({ kind, tone = "neutral", icon, children, onOpen, openLabel, onDismiss, timeoutMs = null, actions, role, className = "", testId, timerKey }: {
  kind: "toast" | "banner";
  tone?: "neutral" | "error" | "warn" | "danger" | "info" | "quiet" | "accent";
  icon?: ReactNode;
  /** The words (and any small print). */
  children: ReactNode;
  /** Where it goes when clicked (none: about nothing in particular, and not clickable). */
  onOpen?: () => void;
  /** The click's name for screen readers, when the words alone do not say where it goes. */
  openLabel?: string;
  onDismiss: () => void;
  /** Hides by itself after this long (none: a notice about something still true). */
  timeoutMs?: number | null;
  /** Buttons of its own (Update, Restart); a click on them is theirs. */
  actions?: ReactNode;
  role?: "status" | "alert";
  className?: string;
  testId?: string;
  /** A new value restarts the timer (a new toast in the same place). */
  timerKey?: unknown;
}) {
  const shell = useRef<HTMLDivElement | null>(null);
  const tracker = useRef<SwipeTracker | null>(null);
  const pointer = useRef<number | null>(null);
  const suppressClick = useRef(Number.NEGATIVE_INFINITY);
  const wheelEnd = useRef(0);
  const [offset, setOffset] = useState(0);
  const [settling, setSettling] = useState(false);
  const [leaving, setLeaving] = useState<"left" | "right" | null>(null);
  const timer = useNoticeTimer(timeoutMs === null ? null : timeoutMs, onDismiss);
  // A new toast in the same place starts its own time and is drawn at rest.
  useEffect(() => {
    setOffset(0);
    setLeaving(null);
  }, [timerKey]);
  useEffect(() => () => window.clearTimeout(wheelEnd.current), []);

  const width = () => shell.current?.getBoundingClientRect().width || 360;
  const leave = useCallback((side: "left" | "right") => {
    if (reducedMotion()) {
      onDismiss();
      return;
    }
    setLeaving(side);
    window.setTimeout(onDismiss, 160);
  }, [onDismiss]);
  const finish = (end: SwipeEnd) => {
    if (end === "leave-left" || end === "leave-right") {
      leave(end === "leave-left" ? "left" : "right");
      return;
    }
    setSettling(true);
    setOffset(0);
  };

  const onPointerDown = (e: React.PointerEvent) => {
    if (e.button !== 0 || leaving) return;
    // The × and its own buttons are pressed, never dragged.
    if ((e.target as HTMLElement).closest(".notice-close, .notice-actions")) return;
    tracker.current = new SwipeTracker(width());
    tracker.current.down(e.clientX, e.clientY, performance.now());
    pointer.current = e.pointerId;
    if (e.pointerType === "touch") timer.pause("touch");
  };
  const onPointerMove = (e: React.PointerEvent) => {
    const t = tracker.current;
    if (!t || pointer.current !== e.pointerId) return;
    const dx = t.move(e.clientX, e.clientY, performance.now());
    if (t.isDrag) {
      if (dx !== offset) setOffset(dx);
      setSettling(false);
      timer.pause("drag");
      try {
        shell.current?.setPointerCapture?.(e.pointerId);
      } catch {
        // A pointer that is gone already cannot be captured.
      }
    }
  };
  const onPointerUp = (e: React.PointerEvent) => {
    const t = tracker.current;
    if (!t || pointer.current !== e.pointerId) return;
    tracker.current = null;
    pointer.current = null;
    timer.resume("touch");
    timer.resume("drag");
    if (t.isDrag) suppressClick.current = performance.now();
    finish(t.up(performance.now()));
  };
  const onPointerCancel = () => {
    tracker.current = null;
    pointer.current = null;
    timer.resume("touch");
    timer.resume("drag");
    finish("spring");
  };
  const onWheel = (e: React.WheelEvent) => {
    if (leaving) return;
    const t = (tracker.current ??= new SwipeTracker(width()));
    const dx = t.wheel(e.deltaX, e.deltaY, performance.now());
    if (dx === 0 && !t.isDrag) return;
    setSettling(false);
    setOffset(dx);
    window.clearTimeout(wheelEnd.current);
    wheelEnd.current = window.setTimeout(() => {
      const ended = tracker.current;
      tracker.current = null;
      if (ended) finish(ended.wheelEnd(performance.now()));
    }, WHEEL_END_MS);
  };
  const dragged = () => performance.now() - suppressClick.current < 400;
  const onClick = (e: React.MouseEvent) => {
    if (dragged()) {
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    if ((e.target as HTMLElement).closest("button, a, input, select, textarea")) return;
    if (onOpen) {
      onOpen();
      onDismiss();
    }
  };
  const open = (e: React.MouseEvent) => {
    e.stopPropagation();
    if (dragged() || !onOpen) return;
    onOpen();
    onDismiss();
  };

  const style: React.CSSProperties = leaving
    ? { transform: `translateX(${leaving === "left" ? "-" : ""}110%)`, opacity: 0 }
    : offset !== 0 ? { transform: `translateX(${offset}px)`, opacity: dragOpacity(offset, width()) } : {};
  return (
    <div ref={shell} className={`notice-shell ${kind} tone-${tone} ${onOpen ? "has-target" : ""} ${offset !== 0 ? "dragging" : ""} ${settling || leaving ? "settling" : ""} ${className}`.trim()}
      role={role} data-testid={testId} style={style}
      onPointerDown={onPointerDown} onPointerMove={onPointerMove} onPointerUp={onPointerUp} onPointerCancel={onPointerCancel} onWheel={onWheel}
      onMouseEnter={() => timer.pause("hover")} onMouseLeave={() => timer.resume("hover")}
      onFocus={() => timer.pause("focus")}
      onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node | null)) timer.resume("focus"); }}
      onKeyDown={(e) => { if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); onDismiss(); } }}
      onClick={onClick} onTransitionEnd={() => setSettling(false)}>
      {icon && <span className="notice-icon" aria-hidden="true">{icon}</span>}
      {onOpen ? (
        <button type="button" className="notice-open" onClick={open} aria-label={openLabel}>{children}</button>
      ) : (
        <span className="notice-text">{children}</span>
      )}
      {actions && <span className="notice-actions">{actions}</span>}
      <button type="button" className="notice-close" aria-label="Dismiss" title="Dismiss (Esc)" onClick={(e) => { e.stopPropagation(); onDismiss(); }}>
        <CloseIcon size={14} />
      </button>
      {timeoutMs !== null && (
        <span className="notice-timer" aria-hidden="true">
          <span key={String(timerKey)} className={timer.paused ? "paused" : ""} style={{ animationDuration: `${timeoutMs}ms` }} />
        </span>
      )}
    </div>
  );
}
