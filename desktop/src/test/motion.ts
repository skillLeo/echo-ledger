// Test helpers for motion (lib/motion.ts): the Web Animations API, which jsdom lacks, and the reduced-motion setting.
import { vi } from "vitest";

/** A stand-in for the Web Animations API: every call is kept, each animation can be cancelled. */
export function fakeAnimations() {
  const calls: { el: Element; keyframes: Keyframe[]; options: KeyframeAnimationOptions; cancel: ReturnType<typeof vi.fn> }[] = [];
  const animate = vi.fn(function (this: Element, keyframes: Keyframe[], options: KeyframeAnimationOptions) {
    const cancel = vi.fn();
    calls.push({ el: this, keyframes, options, cancel });
    return { cancel, addEventListener: vi.fn() } as unknown as Animation;
  });
  Object.defineProperty(Element.prototype, "animate", { value: animate, configurable: true, writable: true });
  return { calls, animate, restore: () => delete (Element.prototype as { animate?: unknown }).animate };
}

/** The OS setting: reduced motion on or off. */
export function prefersReducedMotion(on: boolean) {
  vi.stubGlobal("matchMedia", (query: string) => ({ matches: on && query.includes("reduced-motion"), media: query, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} }));
}
