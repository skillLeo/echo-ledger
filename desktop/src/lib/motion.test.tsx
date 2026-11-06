import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, renderHook, screen } from "@testing-library/react";
import { fakeAnimations, prefersReducedMotion } from "../test/motion";
import { aroundOf, EASE, enterView, fillHeight, fillWidth, isConfirmation, MOTION, MotionGroup, openFromRow, reducedMotion, snapshotRow, usePresence, viewDirection } from "./motion";

/** Only transform and opacity move (and the transform's origin, which does not move anything). */
const ALLOWED = new Set(["transform", "opacity", "transformOrigin", "offset", "easing", "composite"]);
const onlyTransformAndOpacity = (keyframes: Keyframe[]) => keyframes.every((k) => Object.keys(k).every((p) => ALLOWED.has(p)));

describe("motion", () => {
  let anim: ReturnType<typeof fakeAnimations>;
  beforeEach(() => {
    anim = fakeAnimations();
    prefersReducedMotion(false);
  });
  afterEach(() => {
    anim.restore();
    vi.unstubAllGlobals();
    document.body.innerHTML = "";
  });

  it("uses the phone's durations and curves", () => {
    expect(MOTION).toEqual({ press: 120, exit: 150, base: 200, enter: 300 });
    expect(EASE.decelerate).toBe("cubic-bezier(0, 0, 0, 1)");
    expect(EASE.standard).toBe("cubic-bezier(0.2, 0, 0, 1)");
    expect(EASE.accelerate).toBe("cubic-bezier(0.3, 0, 1, 1)");
  });

  it("follows the OS setting for reduced motion, and treats a webview without matchMedia as motion on", () => {
    expect(reducedMotion()).toBe(false);
    prefersReducedMotion(true);
    expect(reducedMotion()).toBe(true);
    vi.stubGlobal("matchMedia", undefined);
    expect(reducedMotion()).toBe(false);
  });

  it("moves nothing with reduced motion, and nothing where the Web Animations API is missing", () => {
    const el = document.createElement("div");
    const group = new MotionGroup();
    prefersReducedMotion(true);
    expect(group.play(el, [{ opacity: 0 }, { opacity: 1 }], { duration: 200 })).toBeNull();
    prefersReducedMotion(false);
    anim.restore();
    expect(group.play(el, [{ opacity: 0 }, { opacity: 1 }], { duration: 200 })).toBeNull();
    expect(group.size).toBe(0);
  });

  it("never holds an element: animations fill nothing, and stopping cancels each one", () => {
    const group = new MotionGroup();
    const a = document.createElement("div");
    const b = document.createElement("div");
    group.play(a, [{ opacity: 0 }, { opacity: 1 }], { duration: 200 });
    group.play(b, [{ opacity: 0 }, { opacity: 1 }], { duration: 200 });
    expect(anim.calls.map((c) => c.options.fill)).toEqual(["none", "none"]);
    group.stop();
    expect(anim.calls.every((c) => c.cancel.mock.calls.length === 1)).toBe(true);
    expect(group.size).toBe(0);
  });

  it("moves a page switch towards where the new page sits in the sidebar", () => {
    const order = ["library", "projects", "record", "activity", "settings"];
    expect(viewDirection(order, "library", "settings")).toBe(1);
    expect(viewDirection(order, "settings", "projects")).toBe(-1);
    expect(viewDirection(order, "record", "unknown")).toBe(1);
  });

  it("fades a page in over 200 ms from 12 px below, decelerating, with transform and opacity only", () => {
    const group = new MotionGroup();
    const parts = [document.createElement("h1"), document.createElement("div")];
    enterView(group, parts, 1);
    expect(anim.calls).toHaveLength(2);
    for (const call of anim.calls) {
      expect(call.keyframes[0]).toEqual({ opacity: 0, transform: "translateY(12px)" });
      expect(call.keyframes[1]).toEqual({ opacity: 1, transform: "none" });
      expect(call.options).toMatchObject({ duration: 200, easing: EASE.decelerate });
    }
    enterView(group, [parts[0]], -1);
    expect(anim.calls[2].keyframes[0]).toEqual({ opacity: 0, transform: "translateY(-12px)" });
  });

  it("ends a page switch at once when another click starts the next one, so nothing stays half-faded", () => {
    const group = new MotionGroup();
    enterView(group, [document.createElement("div")], 1);
    const first = anim.calls[0];
    enterView(group, [document.createElement("div")], -1);
    expect(first.cancel).toHaveBeenCalledTimes(1);
    expect(anim.calls[1].cancel).not.toHaveBeenCalled();
  });

  it("opens a recording from its row: title and tile move into place, the rest fades, 300 ms", () => {
    document.body.innerHTML = `
      <button id="row"><span data-motion="tile"></span><span data-motion="title" style="font-size: 15px">Kick-off</span></button>
      <div id="page"><div class="head"><span class="badge"><svg data-motion="tile"></svg><span>Teams</span></span>
        <h2 data-motion="title" style="font-size: 24px">Kick-off</h2><div class="meta">Nov 1</div></div><div class="body">Lines</div></div>`;
    const rect = (left: number, top: number, w: number, h: number) => ({ left, top, width: w, height: h, right: left + w, bottom: top + h, x: left, y: top, toJSON() {} }) as DOMRect;
    const at = new Map<string, DOMRect>([["row title", rect(330, 280, 200, 20)], ["row tile", rect(285, 290, 22, 22)], ["page title", rect(700, 250, 500, 32)], ["page tile", rect(694, 218, 14, 14)]]);
    const row = document.getElementById("row")!;
    const page = document.getElementById("page")!;
    for (const [where, el] of [["row", row], ["page", page]] as const) {
      (el.querySelector("[data-motion=title]") as HTMLElement).getBoundingClientRect = () => at.get(`${where} title`)!;
      (el.querySelector("[data-motion=tile]") as Element).getBoundingClientRect = () => at.get(`${where} tile`)!;
    }
    const from = snapshotRow("r5", row);
    expect(from).toMatchObject({ id: "r5", titleSize: 15 });
    const group = new MotionGroup();
    openFromRow(group, page, from);

    const title = anim.calls.find((c) => c.el.matches("h2"))!;
    expect(title.keyframes[0].transform).toBe(`translate(-370px, 30px) scale(${15 / 24})`);
    expect(title.keyframes[1].transform).toBe("none");
    const tile = anim.calls.find((c) => c.el.matches("svg"))!;
    expect(tile.keyframes[0].transform).toBe(`translate(-409px, 72px) scale(${22 / 14})`);
    const faded = anim.calls.filter((c) => c.keyframes[0].opacity === 0).map((c) => c.el.className || c.el.tagName);
    expect(faded.sort()).toEqual(["SPAN", "body", "meta"]);
    for (const call of anim.calls) {
      expect(onlyTransformAndOpacity(call.keyframes)).toBe(true);
      expect(call.options).toMatchObject({ duration: 300, easing: EASE.decelerate, fill: "none" });
    }
  });

  it("does not open a recording with motion when the OS asks for reduced motion", () => {
    document.body.innerHTML = `<div id="page"><h2 data-motion="title">A</h2><p>B</p></div>`;
    prefersReducedMotion(true);
    openFromRow(new MotionGroup(), document.getElementById("page")!, { id: "x", title: null, titleSize: 15, tile: null });
    expect(anim.animate).not.toHaveBeenCalled();
  });

  it("fades what is around the moving parts, going down only into the parts that hold them", () => {
    document.body.innerHTML = `<div id="root"><div id="a"><i id="keep"></i><b id="b"></b></div><p id="c"></p></div>`;
    const root = document.getElementById("root")!;
    expect(aroundOf(root, [document.getElementById("keep")!]).map((e) => e.id)).toEqual(["b", "c"]);
  });

  it("keeps what went away for its 150 ms exit, marked leaving, and lets it go at once with reduced motion", () => {
    vi.useFakeTimers();
    try {
      const { result, rerender } = renderHook(({ item }) => usePresence(item), { initialProps: { item: { id: "a" } as { id: string } | null } });
      expect(result.current).toEqual({ item: { id: "a" }, leaving: false });
      rerender({ item: null });
      expect(result.current).toEqual({ item: { id: "a" }, leaving: true });
      act(() => void vi.advanceTimersByTime(150));
      expect(result.current).toEqual({ item: null, leaving: false });
      // A new one shows at once.
      rerender({ item: { id: "b" } });
      rerender({ item: null });
      rerender({ item: { id: "c" } });
      expect(result.current).toEqual({ item: { id: "c" }, leaving: false });
      prefersReducedMotion(true);
      rerender({ item: null });
      expect(result.current).toEqual({ item: null, leaving: false });
    } finally {
      vi.useRealTimers();
    }
  });

  it("keeps what leaves in the page: the same element goes on, never removed and added back", () => {
    function Shown({ item }: { item: { id: string } | null }) {
      const shown = usePresence(item);
      return shown.item ? <section data-testid="shown" className={shown.leaving ? "leaving" : ""} /> : null;
    }
    const { container, rerender } = render(<Shown item={{ id: "a" }} />);
    const element = screen.getByTestId("shown");
    const changes = new MutationObserver(() => undefined);
    changes.observe(container, { childList: true });
    rerender(<Shown item={null} />);
    expect(changes.takeRecords()).toEqual([]);
    changes.disconnect();
    expect(screen.getByTestId("shown")).toBe(element);
    expect(element).toHaveClass("leaving");
  });

  it("fills a bar by scaling it, between empty and full, whatever the level says", () => {
    expect(fillWidth(0.4)).toEqual({ transform: "scaleX(0.4)" });
    expect(fillHeight(0.125)).toEqual({ transform: "scaleY(0.125)" });
    expect(fillWidth(1.5)).toEqual({ transform: "scaleX(1)" });
    expect(fillWidth(-1)).toEqual({ transform: "scaleX(0)" });
    expect(fillWidth(Number.NaN)).toEqual({ transform: "scaleX(0)" });
    expect(fillWidth(1 / 3)).toEqual({ transform: "scaleX(0.333)" });
  });

  it("gives the check to messages that confirm something, as the phone does", () => {
    for (const m of ["Saved to your Library", "Copied the last 2 minutes", "Link copied", "Voice note saved", "Moved 2 recordings to Acme", "Sent to sara@example.com", "Kept forever"]) {
      expect(isConfirmation(m), m).toBe(true);
    }
    for (const m of ["Recording a voice note. Press N again to stop.", "Opened in your browser: …", "Could not copy"]) {
      expect(isConfirmation(m), m).toBe(false);
    }
  });
});
