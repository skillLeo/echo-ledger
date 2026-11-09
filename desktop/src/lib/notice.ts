// Notices (v1.6.1, docs/DESIGN_SYSTEM.md "Notices"): every toast and banner behaves the same way. This file holds the
// pure parts: where a notice goes when clicked, its timer (paused while the pointer, a finger or the keyboard is on
// it), and the swipe that closes it (a finger, a mouse drag or a trackpad; past a third of its width or a quick flick
// it leaves, otherwise it springs back, and a drag is never a click). Unit-tested.
import type { MainTab } from "./mainView";
import type { LibraryView } from "./folders";
import type { ServerPage, SmartFolder } from "./ipc";

/** Where a notice goes when it is clicked: the recording, the page or setting, the fix. */
export type NoticeTarget =
  | { kind: "recording"; id: string; ms?: number }
  | { kind: "page"; page: MainTab }
  | { kind: "account"; section: string }
  | { kind: "project"; id: string | null }
  | { kind: "library"; folder?: SmartFolder | null; view?: LibraryView | null }
  | { kind: "shared"; tab: "with-me" | "links" | "requests" }
  | { kind: "server"; page: ServerPage }
  /** A fix done in place (Update, Try again): run when the notice is clicked. */
  | { kind: "action"; run: () => void };

/** Where a notice from the core goes (the same targets, without the in-place fixes). */
export type CoreTarget = Exclude<NoticeTarget, { kind: "action" }>;
/** What the core sends as `app:toast` and `app:error`: the words, or the words and where they go (since v1.6.1). */
export type NoticePayload = string | { text: string; target?: CoreTarget | null };
/** The words and the target of a core notice, whichever shape it came in. */
export function noticeOf(payload: NoticePayload): { text: string; target?: NoticeTarget } {
  if (typeof payload === "string") return { text: payload };
  return { text: String(payload?.text ?? ""), target: payload?.target ?? undefined };
}

/** A notice about nothing in particular: written out at the call site so every notice says whether it has a target. */
export const NO_TARGET = undefined;

/** How the window's `notify` is called: the words, whether it is an error, and where it goes when clicked. */
export type Notify = (text: string, error?: boolean, target?: NoticeTarget) => void;

/** A message hides by itself after 6 s, an error after 10 s; a notice about something still true never does. */
export const MESSAGE_MS = 6000;
export const ERROR_MS = 10_000;

/**
 * A countdown that can pause (pointer, finger, keyboard focus, a drag) and resume where it left off. Times are in ms
 * from any clock; the window passes `performance.now()`.
 */
export class Countdown {
  private left: number;
  private since: number | null;

  constructor(readonly duration: number, now: number) {
    this.left = duration;
    this.since = now;
  }

  get paused(): boolean {
    return this.since === null;
  }

  remaining(now: number): number {
    return Math.max(0, this.since === null ? this.left : this.left - (now - this.since));
  }

  pause(now: number): void {
    if (this.since === null) return;
    this.left = this.remaining(now);
    this.since = null;
  }

  resume(now: number): void {
    if (this.since !== null) return;
    this.since = now;
  }

  expired(now: number): boolean {
    return this.remaining(now) <= 0;
  }
}

/** A pointer moves this far, mostly sideways, before a press becomes a drag (and then it is never a click). */
export const DRAG_START_PX = 6;
/** Past this share of its width the notice leaves; short of it, it springs back unless flicked. */
export const LEAVE_SHARE = 1 / 3;
/** A flick: at least this fast at the end (px per ms) and this far, it leaves whatever the distance. */
export const FLICK_SPEED = 0.6;
export const FLICK_MIN_PX = 24;
/** A trackpad swipe has ended when no sideways scroll came for this long. */
export const WHEEL_END_MS = 140;
/** The movement of the last this-many ms decides the speed. */
const SPEED_WINDOW_MS = 100;

export type SwipeEnd = "leave-left" | "leave-right" | "spring" | "tap";

/** What a released drag does: leaves past a third of the width or on a flick, else springs back. Pure. */
export function swipeOutcome(dx: number, width: number, speed: number): Exclude<SwipeEnd, "tap"> {
  const far = Math.abs(dx) >= Math.max(1, width) * LEAVE_SHARE;
  const flick = Math.abs(speed) >= FLICK_SPEED && Math.abs(dx) >= FLICK_MIN_PX && Math.sign(speed) === Math.sign(dx);
  if (far || flick) return dx < 0 ? "leave-left" : "leave-right";
  return "spring";
}

/** How faded a notice is while dragged (1 at rest, down to 0.35 at the leaving distance). */
export function dragOpacity(dx: number, width: number): number {
  const share = Math.min(1, Math.abs(dx) / Math.max(1, width * LEAVE_SHARE));
  return 1 - 0.65 * share;
}

/**
 * Follows one press (mouse, pen or finger) or one trackpad swipe. `move` says how far to draw the notice; `up` (or
 * `wheelEnd`) what to do with it. A press that never became a drag is a tap; a drag is never a click.
 */
export class SwipeTracker {
  private startX = 0;
  private startY = 0;
  private dx = 0;
  private dragging = false;
  private samples: { x: number; t: number }[] = [];
  private wheeling = false;

  constructor(private readonly width: number) {}

  /** Whether the gesture became a drag (the click that follows it must not count). */
  get isDrag(): boolean {
    return this.dragging;
  }

  get offset(): number {
    return this.dragging ? this.dx : 0;
  }

  down(x: number, y: number, t: number): void {
    this.startX = x;
    this.startY = y;
    this.dx = 0;
    this.dragging = false;
    this.samples = [{ x: 0, t }];
  }

  /** The offset to draw: 0 until the press is a sideways drag (a mostly vertical one is the page's scroll). */
  move(x: number, y: number, t: number): number {
    const dx = x - this.startX;
    const dy = y - this.startY;
    if (!this.dragging && Math.abs(dx) >= DRAG_START_PX && Math.abs(dx) > Math.abs(dy)) this.dragging = true;
    this.dx = dx;
    this.sample(dx, t);
    return this.offset;
  }

  up(t: number): SwipeEnd {
    if (!this.dragging) return "tap";
    return swipeOutcome(this.dx, this.width, this.speed(t));
  }

  /** A trackpad's sideways scroll (`deltaX`); a mostly vertical one is left to the page. Returns the offset to draw. */
  wheel(deltaX: number, deltaY: number, t: number): number {
    if (Math.abs(deltaX) <= Math.abs(deltaY)) return this.offset;
    if (!this.wheeling) {
      this.wheeling = true;
      this.dx = 0;
      this.samples = [{ x: 0, t }];
    }
    // Scrolling right (positive deltaX) moves the content left, as a finger would.
    this.dx -= deltaX;
    this.dragging = Math.abs(this.dx) >= DRAG_START_PX;
    this.sample(this.dx, t);
    return this.offset;
  }

  /** The trackpad swipe ended (no sideways scroll for WHEEL_END_MS). */
  wheelEnd(t: number): Exclude<SwipeEnd, "tap"> {
    this.wheeling = false;
    const outcome = swipeOutcome(this.dx, this.width, this.speed(t));
    this.dx = 0;
    this.dragging = false;
    return outcome;
  }

  private sample(x: number, t: number): void {
    this.samples.push({ x, t });
    while (this.samples.length > 2 && t - this.samples[0].t > SPEED_WINDOW_MS) this.samples.shift();
  }

  /** px per ms over the last 100 ms (0 when the pointer stood still before letting go). */
  private speed(t: number): number {
    const first = this.samples[0];
    const last = this.samples[this.samples.length - 1];
    if (!first || !last || t - last.t > SPEED_WINDOW_MS || last.t === first.t) return 0;
    return (last.x - first.x) / (last.t - first.t);
  }
}
