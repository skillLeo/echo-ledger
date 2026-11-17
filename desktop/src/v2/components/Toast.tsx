import { useEffect, useRef, useState, type ReactNode } from "react";
import { Button } from "./Button";
import { CloseIcon } from "./icons";

/** The toast's time on screen from the token (--el-component-toast-duration), in milliseconds. */
export function toastDuration(): number {
  const fallback = 6000;
  if (typeof window === "undefined" || typeof getComputedStyle !== "function") return fallback;
  const raw = getComputedStyle(document.documentElement).getPropertyValue("--el-component-toast-duration").trim();
  const ms = raw.endsWith("ms") ? parseFloat(raw) : raw.endsWith("s") ? parseFloat(raw) * 1000 : NaN;
  return Number.isFinite(ms) && ms > 0 ? ms : fallback;
}

/** Where toasts appear: a polite live region, present before any toast so each one is announced. */
export function ToastRegion({ children, inline, label = "Notifications" }: { children?: ReactNode; inline?: boolean; label?: string }) {
  return (
    <div className={`el-toast-region${inline ? " el-toast-region--inline" : ""}`} role="status" aria-live="polite" aria-label={label}>
      {children}
    </div>
  );
}

export type ToastProps = {
  message: ReactNode;
  /** Undo (or another single action); pressing it runs onAction and closes the toast. */
  actionLabel?: string;
  onAction?: () => void;
  onClose: () => void;
  closeLabel?: string;
  /** Milliseconds on screen; the token's value when not given; 0 keeps it until closed. */
  duration?: number;
};

/**
 * A short message with at most one action. It closes by itself after the token's duration, but never while the
 * pointer or keyboard focus is on it; Esc closes it when focus is inside.
 */
export function Toast({ message, actionLabel = "Undo", onAction, onClose, closeLabel = "Dismiss", duration }: ToastProps) {
  const [held, setHeld] = useState(false);
  const close = useRef(onClose);
  close.current = onClose;
  const time = duration ?? toastDuration();

  useEffect(() => {
    if (held || time <= 0) return;
    const timer = window.setTimeout(() => close.current(), time);
    return () => window.clearTimeout(timer);
  }, [held, time]);

  return (
    <div className="el-toast" onPointerEnter={() => setHeld(true)} onPointerLeave={() => setHeld(false)}
      onFocus={() => setHeld(true)} onBlur={() => setHeld(false)}
      onKeyDown={(e) => { if (e.key === "Escape") { e.stopPropagation(); onClose(); } }}>
      <span className="el-toast__message" dir="auto">{message}</span>
      {onAction && (
        <Button variant="quiet" size="sm" className="el-toast__action" onClick={() => { onAction(); onClose(); }}>{actionLabel}</Button>
      )}
      <Button variant="quiet" size="sm" icon={CloseIcon} aria-label={closeLabel} onClick={onClose} />
    </div>
  );
}
