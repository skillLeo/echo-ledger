// UX-07: zoom like Chrome: its steps, its keys, Ctrl + the wheel and a pinch, kept on this computer.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { PINCH_STEP, ZOOM_EVENT, ZOOM_KEY, ZOOM_STEPS, loadZoom, parseZoom, setZoom, stepZoom, watchZoomWheel, zoomKey, zoomNow } from "./zoom";

const key = (k: string, mods: { meta?: boolean; ctrl?: boolean; alt?: boolean; code?: string } = {}) =>
  ({ key: k, code: mods.code ?? "", metaKey: !!mods.meta, ctrlKey: !!mods.ctrl, altKey: !!mods.alt });

describe("zoom like Chrome (UX-07)", () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => setZoom(100, false, async () => undefined));

  it("Chrome's steps, 67% to 200%, 100% the default; a step never goes past the ends; 0 is back to 100%", () => {
    expect([...ZOOM_STEPS]).toEqual([67, 75, 80, 90, 100, 110, 125, 150, 175, 200]);
    expect(stepZoom(100, 1)).toBe(110);
    expect(stepZoom(100, -1)).toBe(90);
    expect(stepZoom(200, 1)).toBe(200);
    expect(stepZoom(67, -1)).toBe(67);
    expect(stepZoom(150, 0)).toBe(100);
    expect(parseZoom("125")).toBe(125);
    expect(parseZoom("130")).toBe(100, "never a zoom that isn't a step");
    expect(parseZoom(null)).toBe(100);
  });

  it("the keys: ⌘+ and ⌘= (Ctrl off a Mac), ⌘−, ⌘0, the number pad's too; never with ⌥, never the other modifier", () => {
    expect(zoomKey(key("+", { meta: true }), true)).toBe(1);
    expect(zoomKey(key("=", { meta: true }), true)).toBe(1);
    expect(zoomKey(key("-", { meta: true }), true)).toBe(-1);
    expect(zoomKey(key("0", { meta: true }), true)).toBe(0);
    expect(zoomKey(key("+", { ctrl: true, code: "NumpadAdd" }), false)).toBe(1);
    expect(zoomKey(key("-", { ctrl: true, code: "NumpadSubtract" }), false)).toBe(-1);
    expect(zoomKey(key("0", { ctrl: true, code: "Numpad0" }), false)).toBe(0);
    expect(zoomKey(key("=", { ctrl: true }), true)).toBeNull();
    expect(zoomKey(key("=", { meta: true }), false)).toBeNull();
    expect(zoomKey(key("=", { meta: true, alt: true }), true)).toBeNull();
    expect(zoomKey(key("=", {}), false)).toBeNull();
    expect(zoomKey(key("k", { ctrl: true }), false)).toBeNull();
  });

  it("a change is applied to the web view, kept on this computer (100% keeps nothing), and told once", () => {
    const apply = vi.fn(async () => undefined);
    const told = vi.fn();
    window.addEventListener(ZOOM_EVENT, told);
    try {
      expect(setZoom(125, true, apply)).toBe(125);
      expect(apply).toHaveBeenLastCalledWith(1.25);
      expect(localStorage.getItem(ZOOM_KEY)).toBe("125");
      expect(loadZoom()).toBe(125);
      expect(zoomNow()).toBe(125);
      setZoom(125, true, apply);
      expect(told).toHaveBeenCalledTimes(1);
      setZoom(100, true, apply);
      expect(localStorage.getItem(ZOOM_KEY)).toBeNull();
      // Not kept (the flag off, or the kept one put back at the start).
      setZoom(150, false, apply);
      expect(localStorage.getItem(ZOOM_KEY)).toBeNull();
    } finally {
      window.removeEventListener(ZOOM_EVENT, told);
    }
  });

  it("Ctrl + the wheel: a step a notch; a pinch builds up and steps every 100 px; without Ctrl, nothing", () => {
    const steps: number[] = [];
    const stop = watchZoomWheel(window, (dir) => steps.push(dir));
    const wheel = (deltaY: number, ctrlKey = true, deltaMode = 0) => window.dispatchEvent(new WheelEvent("wheel", { deltaY, ctrlKey, deltaMode, cancelable: true }));
    wheel(-100);
    wheel(100);
    expect(steps).toEqual([1, -1]);
    for (let i = 0; i < 9; i++) wheel(-PINCH_STEP / 10);
    expect(steps).toEqual([1, -1], "not yet a step");
    wheel(-PINCH_STEP / 10);
    expect(steps).toEqual([1, -1, 1]);
    wheel(3, true, 1);
    expect(steps).toEqual([1, -1, 1, -1], "a line's notch is a step");
    wheel(-500, false);
    expect(steps).toHaveLength(4);
    stop();
    wheel(-100);
    expect(steps).toHaveLength(4);
  });
});
