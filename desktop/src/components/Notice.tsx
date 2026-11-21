// One notice, as docs/DESIGN_SYSTEM.md "Notices" says (v1.6.1): a click goes where it is about (and closes it); ×
// closes it; a message hides by itself with a thin bar showing the time left, paused while the pointer, a finger or the
// keyboard is on it; a swipe left or right (finger, mouse drag or trackpad) closes it, following the drag and springing
// back when it was not far or quick enough; a drag is never a click; Esc on it closes it. Toasts and banners both use it.
import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { Countdown, dragOpacity, SwipeTracker, WHEEL_END_MS, type SwipeEnd } from "../lib/notice";
import { reducedMotion } from "../lib/motion";
import { CloseIcon } from "./Icons";

type PauseReason = "hover" | "focus" | "touch" | "drag";

/** The timer of a notice: pauses for any reason given, resumes when none is left, calls `onEnd` when it runs out. */
function useNoticeTimer(ms: number | null, onEnd: () => void, restart?: unknown): { paused: boolean; pause: (r: PauseReason) => void; resume: (r: PauseReason) => void } {
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
  }, [ms, schedule, restart]);
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

/**
 * How the main window's banners are drawn: classic's, or the new design's (inside its shell). Only the look changes:
 * the words, Open, the buttons, ×, Esc and the one-at-a-time slot (BannerSlot) are the same.
 */
export const NoticeLook = createContext<"classic" | "v2">("classic");

const V2_TONE: Record<string, "info" | "warning" | "danger"> = { warn: "warning", danger: "danger", error: "danger" };
/** Classic's look-only classes (its banner box and tone colours): the new look leaves them out and keeps the rest. */
const CLASSIC_LOOK = new Set(["banner", "warn", "info", "danger", "error", "quiet", "accent", "neutral"]);
const ownClasses = (className: string) => className.split(/\s+/).filter((c) => c && !CLASSIC_LOOK.has(c)).join(" ");

/**
 * A banner in the new design's look (its .el-banner, with the classic banner's parts): the same timer (paused while
 * the pointer or the keyboard is on it, started again by a new `timerKey`), classes, Open, buttons, × and Esc. Its words
 * are read out by the live region around the banners (MainApp's .el-app-banners, always in the page); a danger banner
 * is an alert of its own.
 */
function BannerV2({ tone, icon, children, onOpen, openLabel, onDismiss, actions, details, role, testId, timeoutMs = null, className = "", timerKey }: {
  tone: string; icon?: ReactNode; children: ReactNode; onOpen?: () => void; openLabel?: string; onDismiss: () => void;
  actions?: ReactNode; details?: ReactNode; role?: "status" | "alert"; testId?: string; timeoutMs?: number | null; className?: string; timerKey?: unknown;
}) {
  const v2Tone = V2_TONE[tone] ?? "info";
  const timer = useNoticeTimer(timeoutMs, onDismiss, timerKey);
  return (
    <div className={`el-banner el-banner--${v2Tone} el-banner--app ${ownClasses(className)}`.trim()} role={role === "alert" || v2Tone === "danger" ? "alert" : undefined} data-testid={testId}
      onMouseEnter={() => timer.pause("hover")} onMouseLeave={() => timer.resume("hover")}
      onFocus={() => timer.pause("focus")}
      onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node | null)) timer.resume("focus"); }}
      onKeyDown={(e) => { if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); onDismiss(); } }}>
      {icon && <span className="el-banner__icon" aria-hidden="true">{icon}</span>}
      <span className="el-banner__text" dir="auto">
        {onOpen ? <button type="button" className="el-banner__open" aria-label={openLabel} onClick={() => { onOpen(); onDismiss(); }}>{children}</button> : children}
        {details && <span className="el-banner__details">{details}</span>}
      </span>
      {actions && <span className="el-banner__actions">{actions}</span>}
      <button type="button" className="el-button el-button--quiet el-button--sm el-button--icon" aria-label="Dismiss" title="Dismiss (Esc)" onClick={onDismiss}>
        {/* Sized by the new design's icon token (.el-icon), not a number. */}
        <span className="el-icon" aria-hidden="true"><CloseIcon /></span>
      </button>
    </div>
  );
}

export function Notice(props: Parameters<typeof NoticeClassic>[0]) {
  const look = useContext(NoticeLook);
  if (look === "v2" && props.kind === "banner") return <BannerV2 {...props} tone={props.tone ?? "neutral"} />;
  return <NoticeClassic {...props} />;
}

function NoticeClassic({ kind, tone = "neutral", icon, children, onOpen, openLabel, onDismiss, timeoutMs = null, actions, details, role, className = "", testId, timerKey }: {
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
  /** What the one sentence leaves out ("Learn more", components/LearnMore.tsx): beside it, never inside its button. */
  details?: ReactNode;
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
    if ((e.target as HTMLElement).closest(".notice-close, .notice-actions, .notice-details")) return;
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
      <span className="notice-main">
        {onOpen ? (
          <button type="button" className="notice-open" onClick={open} aria-label={openLabel}>{children}</button>
        ) : (
          <span className="notice-text">{children}</span>
        )}
        {details && <span className="notice-details">{details}</span>}
      </span>
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
