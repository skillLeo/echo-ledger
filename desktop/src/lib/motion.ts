// Motion (since v1.5, the phone's rules: docs/DESIGN_SYSTEM.md "Motion and haptics", mobile ui/Tokens.kt `Motion`): a page
// switch fades in from the side its page sits on (200 ms), a row opens its recording with its title and app tile moving
// into place while the rest fades (300 ms), the after-call toast and chip rise (300 ms) and leave (150 ms). Only
// transform and opacity move. With the OS asking for reduced motion nothing moves and states change at once; where
// the Web Animations API is missing (an old webview, the tests) nothing moves either. An animation never holds an
// element: it fills nothing, so a cancelled or finished one leaves every element exactly as it is without motion.
import { useEffect, useRef, useState } from "react";

/** Durations in ms: press, exit, state change, enter (the phone's Motion.PRESS, FAST, BASE, SLOW). */
export const MOTION = { press: 120, exit: 150, base: 200, enter: 300 } as const;
/** Standard for state changes, decelerate for what comes in, accelerate for what goes (the phone's curves). */
export const EASE = { standard: "cubic-bezier(0.2, 0, 0, 1)", decelerate: "cubic-bezier(0, 0, 0, 1)", accelerate: "cubic-bezier(0.3, 0, 1, 1)" } as const;
/** How far a page moves while it fades in, in CSS pixels. */
export const VIEW_SHIFT = 12;

/** The OS asks for reduced motion (Windows "Animation effects" off, macOS "Reduce motion"). */
export function reducedMotion(): boolean {
  try {
    return typeof window !== "undefined" && typeof window.matchMedia === "function" && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  } catch {
    return false;
  }
}

function animatable(el: Element | null | undefined): el is HTMLElement {
  return !!el && typeof (el as HTMLElement).animate === "function";
}

/**
 * The running animations of one place (the page, the recording page). Starting new ones stops the old ones first, so a
 * click during a transition never leaves anything half-faded: cancelling puts each element back as it is at rest.
 */
export class MotionGroup {
  private running: Animation[] = [];

  /** Plays `keyframes` on `el` unless motion is off; null when nothing was started. */
  play(el: Element | null | undefined, keyframes: Keyframe[], options: KeyframeAnimationOptions): Animation | null {
    if (!animatable(el) || reducedMotion()) return null;
    try {
      const animation = el.animate(keyframes, { fill: "none", ...options });
      this.running.push(animation);
      const forget = () => {
        this.running = this.running.filter((a) => a !== animation);
      };
      animation.addEventListener?.("finish", forget);
      animation.addEventListener?.("cancel", forget);
      return animation;
    } catch {
      return null;
    }
  }

  /** Ends every running animation at once: the elements are as they are without motion. */
  stop(): void {
    const running = this.running;
    this.running = [];
    for (const animation of running) {
      try {
        animation.cancel();
      } catch {
        // Already gone with its element.
      }
    }
  }

  get size(): number {
    return this.running.length;
  }
}

/** Which way a page switch moves: 1 when the new page sits below the old one in the sidebar, -1 above. */
export function viewDirection(order: readonly string[], from: string, to: string): 1 | -1 {
  const target = order.indexOf(to);
  return target >= 0 && target < order.indexOf(from) ? -1 : 1;
}

/** A page coming in: it fades in, moving a short way up from below (or down from above, `direction` -1). */
export function enterView(group: MotionGroup, elements: Element[], direction: 1 | -1): void {
  group.stop();
  for (const el of elements) {
    group.play(el, [{ opacity: 0, transform: `translateY(${direction * VIEW_SHIFT}px)` }, { opacity: 1, transform: "none" }], { duration: MOTION.base, easing: EASE.decelerate });
  }
}

/** Where a row's title and app tile were when it was clicked, for the recording page to move them from. */
export type RowSnapshot = { id: string; title: DOMRect | null; titleSize: number; tile: DOMRect | null };

export function snapshotRow(id: string, row: Element): RowSnapshot {
  const title = row.querySelector("[data-motion=title]");
  const tile = row.querySelector("[data-motion=tile]");
  return {
    id,
    title: title?.getBoundingClientRect() ?? null,
    titleSize: title ? Number.parseFloat(getComputedStyle(title).fontSize) || 0 : 0,
    tile: tile?.getBoundingClientRect() ?? null,
  };
}

/** The parts of `root` to fade: everything except `keep`, going down only into the elements that hold one of them. */
export function aroundOf(root: Element, keep: Element[]): Element[] {
  const out: Element[] = [];
  for (const child of Array.from(root.children)) {
    if (keep.includes(child)) continue;
    if (keep.some((k) => child.contains(k))) out.push(...aroundOf(child, keep));
    else out.push(child);
  }
  return out;
}

/**
 * The recording page opening from its row (the phone's shared title and tile): the page's title and app icon start
 * where the row's were and move into place, the rest of the page fades in; 300 ms, decelerating.
 */
export function openFromRow(group: MotionGroup, page: Element, from: RowSnapshot): void {
  group.stop();
  if (!animatable(page) || reducedMotion()) return;
  const title = page.querySelector("[data-motion=title]");
  const tile = page.querySelector("[data-motion=tile]");
  const timing = { duration: MOTION.enter, easing: EASE.decelerate };
  const move = (el: Element | null, rect: DOMRect | null, scale: number) => {
    if (!el || !rect) return;
    const to = el.getBoundingClientRect();
    if (to.width === 0 && to.height === 0) return;
    const at = `translate(${rect.left - to.left}px, ${rect.top - to.top}px) scale(${Number.isFinite(scale) && scale > 0 ? scale : 1})`;
    group.play(el, [{ transformOrigin: "0 0", transform: at }, { transformOrigin: "0 0", transform: "none" }], timing);
  };
  const titleSize = title ? Number.parseFloat(getComputedStyle(title).fontSize) : 0;
  move(title, from.title, titleSize > 0 && from.titleSize > 0 ? from.titleSize / titleSize : 1);
  const tileTo = tile?.getBoundingClientRect();
  move(tile, from.tile, tileTo && tileTo.height > 0 && from.tile ? from.tile.height / tileTo.height : 1);
  for (const el of aroundOf(page, [title, tile].filter((e): e is Element => !!e))) {
    group.play(el, [{ opacity: 0 }, { opacity: 1 }], timing);
  }
}

/**
 * Keeps what just went away on screen for its exit (150 ms), marked `leaving`, so it can slide out; with reduced
 * motion it goes at once. A new item shows at once and ends the exit of the old one.
 */
export function usePresence<T extends { id: string }>(item: T | null, ms: number = MOTION.exit): { item: T | null; leaving: boolean } {
  const [gone, setGone] = useState<T | null>(null);
  const last = useRef<T | null>(item);
  useEffect(() => {
    if (item) {
      last.current = item;
      setGone(null);
      return;
    }
    const previous = last.current;
    last.current = null;
    if (!previous || reducedMotion()) {
      setGone(null);
      return;
    }
    setGone(previous);
    const timer = window.setTimeout(() => setGone(null), ms);
    return () => window.clearTimeout(timer);
  }, [item, ms]);
  return item ? { item, leaving: false } : { item: gone, leaving: gone !== null };
}

/** Messages that confirm something worked get the check that pops in (the phone's `isConfirmation`, and the desktop's own). */
const CONFIRMATIONS = ["Saved", "Copied", "Restored", "Renamed", "Added", "Moved", "Signed in", "Shared", "Sent", "Kept", "Pinned", "Imported", "Link copied", "Settings saved", "Settings imported", "Voice note saved"];
export function isConfirmation(message: string): boolean {
  return CONFIRMATIONS.some((start) => message.startsWith(start));
}
