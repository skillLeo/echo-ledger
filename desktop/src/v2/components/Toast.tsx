import { useEffect, useLayoutEffect, useRef, useState, type ReactNode } from "react";
import { Button } from "./Button";
import { CloseIcon } from "./icons";
import { lineDir } from "../../i18n";

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
  /** The action undoes something: Cmd+Z (Ctrl+Z on Windows) runs it while the toast shows. Set when the action is "Undo". */
  undo?: boolean;
  /** A problem: a newer message from elsewhere in the window waits until it closes, instead of taking its place. */
  error?: boolean;
};

/** A key typed into a field or editable text belongs to the field (its own undo), never to the toast. */
const typing = (target: EventTarget | null) =>
  target instanceof HTMLElement && (target.isContentEditable || ["INPUT", "TEXTAREA", "SELECT"].includes(target.tagName));
/**
 * One message at a time in the whole window, whichever part of it shows one (FIX-03). A new toast closes the one on
 * screen and takes its place, but a problem is never replaced by anything but a newer problem: a message that comes
 * while one is up waits (the newest only) and shows once the problem has closed. Only the toast on screen answers F6,
 * Cmd/Ctrl+Z and Esc, and its time runs only while it is on screen.
 */
type Entry = { close: () => void; error: boolean; release: () => void };
let shown: Entry | null = null;
let waiting: Entry | null = null;
/** A toast comes: on screen now (true), or waiting for a problem to close (false). */
function arrive(me: Entry): boolean {
  if (shown?.error && !me.error) {
    const older = waiting;
    waiting = me;
    older?.close();
    return false;
  }
  const before = shown;
  shown = me;
  if (!me.error) {
    const older = waiting;
    waiting = null;
    older?.close();
  }
  before?.close();
  return true;
}
/** A toast goes: the one waiting, if any, comes on screen in its place. */
function leave(me: Entry) {
  if (waiting === me) waiting = null;
  if (shown !== me) return;
  shown = waiting;
  waiting = null;
  shown?.release();
}
/** Something else on screen answers Esc first: a dialog, a sheet, a menu or a list that pops up. */
const takesEsc = () => !!document.querySelector('[role="dialog"], [role="alertdialog"], [aria-modal="true"], [role="menu"], [role="listbox"]');
const isMac = () => typeof navigator !== "undefined" && /mac/i.test(navigator.platform || navigator.userAgent);

/**
 * A short message with at most one action, and its ✕. It closes by itself after the token's duration, but never while
 * the pointer or keyboard focus is on it; Esc closes it, from anywhere in the window, when nothing else there takes Esc
 * (a dialog or a menu open, or the page's own Esc: an open recording, the filters). A new toast takes its place: one at
 * a time. From the keyboard, F6 moves focus to it (and F6 again back to where it was), and an Undo toast's action also
 * runs with Cmd+Z (Ctrl+Z on Windows).
 */
export function Toast({ message, actionLabel = "Undo", onAction, onClose, closeLabel = "Dismiss", duration, undo = actionLabel === "Undo", error = false }: ToastProps) {
  const [held, setHeld] = useState(false);
  // Waiting for a problem elsewhere to close (it is not drawn, and its time doesn't run, until then).
  const [waitingNow, setWaiting] = useState(() => !!shown?.error && !error);
  const entry = useRef<Entry | null>(null);
  const close = useRef(onClose);
  close.current = onClose;
  const act = useRef(onAction);
  act.current = onAction;
  const box = useRef<HTMLDivElement>(null);
  const before = useRef<HTMLElement | null>(null);
  const time = duration ?? toastDuration();
  const canUndo = undo && !!onAction;

  useLayoutEffect(() => {
    const me: Entry = { close: () => close.current(), error, release: () => setWaiting(false) };
    entry.current = me;
    setWaiting(!arrive(me));
    return () => leave(me);
    // A toast is one message (keyed by it): what it is doesn't change while it is mounted.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
  useEffect(() => {
    // On the window, after the page's own listeners (on the document or below): what the page took is left alone.
    const onKey = (e: KeyboardEvent) => {
      const el = box.current;
      if (!el || e.defaultPrevented || !entry.current || shown !== entry.current) return;
      if (e.key === "Escape" && !e.ctrlKey && !e.metaKey && !e.altKey && !el.contains(e.target as Node)) {
        if (takesEsc()) return;
        e.preventDefault();
        close.current();
        return;
      }
      if (e.key === "F6" && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        if (el.contains(document.activeElement)) {
          const back = before.current;
          before.current = null;
          if (back?.isConnected) back.focus();
          else (document.activeElement as HTMLElement | null)?.blur();
        } else {
          before.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
          el.querySelector<HTMLElement>("button")?.focus();
        }
        return;
      }
      const mod = isMac() ? e.metaKey && !e.ctrlKey : e.ctrlKey && !e.metaKey;
      if (canUndo && mod && !e.shiftKey && !e.altKey && e.key.toLowerCase() === "z" && !typing(e.target)) {
        e.preventDefault();
        act.current?.();
        close.current();
      }
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [canUndo]);

  useEffect(() => {
    if (held || waitingNow || time <= 0) return;
    const timer = window.setTimeout(() => close.current(), time);
    return () => window.clearTimeout(timer);
  }, [held, waitingNow, time]);

  if (waitingNow) return null;
  return (
    <div className="el-toast" ref={box} onPointerEnter={() => setHeld(true)} onPointerLeave={() => setHeld(false)}
      onFocus={() => setHeld(true)} onBlur={() => setHeld(false)}
      onKeyDown={(e) => { if (e.key === "Escape") { e.stopPropagation(); onClose(); } }}>
      {/* In Urdu by its own words, not by a Latin name isolated at its start (lineDir); dir="auto" in English. */}
      <span className="el-toast__message" dir={lineDir(message)}>{message}</span>
      {onAction && (
        <Button variant="quiet" size="sm" className="el-toast__action" aria-keyshortcuts={canUndo ? (isMac() ? "Meta+Z" : "Control+Z") : undefined} onClick={() => { onAction(); onClose(); }}>{actionLabel}</Button>
      )}
      <Button variant="quiet" size="sm" icon={CloseIcon} aria-label={closeLabel} onClick={onClose} />
    </div>
  );
}
