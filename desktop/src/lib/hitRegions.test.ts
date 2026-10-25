import { afterEach, describe, expect, it, vi } from "vitest";
import { renderHook } from "@testing-library/react";
import { collectRegions, useHitRegions } from "./hitRegions";
import { callsOf, resetTauriMock, respond } from "../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);

const box = (left: number, top: number, width: number, height: number) =>
  ({ left, top, width, height, right: left + width, bottom: top + height, x: left, y: top, toJSON: () => ({}) }) as DOMRect;
const settle = () => new Promise((resolve) => setTimeout(resolve, 80));

describe("collectRegions", () => {
  afterEach(() => {
    document.body.innerHTML = "";
  });

  it("reports [data-hit] rects scaled by the device pixel ratio", () => {
    const el = document.createElement("div");
    el.setAttribute("data-hit", "");
    el.getBoundingClientRect = () => ({ left: 10, top: 20, width: 30, height: 40, right: 40, bottom: 60, x: 10, y: 20, toJSON: () => ({}) });
    document.body.appendChild(el);

    expect(collectRegions(document, 2)).toEqual([{ x: 20, y: 40, w: 60, h: 80 }]);
  });

  it("ignores zero-size regions and elements without the marker", () => {
    const hidden = document.createElement("div");
    hidden.setAttribute("data-hit", "");
    hidden.getBoundingClientRect = () => ({ left: 0, top: 0, width: 0, height: 0, right: 0, bottom: 0, x: 0, y: 0, toJSON: () => ({}) });
    const plain = document.createElement("div");
    plain.getBoundingClientRect = () => ({ left: 1, top: 1, width: 5, height: 5, right: 6, bottom: 6, x: 1, y: 1, toJSON: () => ({}) });
    document.body.append(hidden, plain);

    expect(collectRegions(document, 1)).toEqual([]);
  });
});

describe("useHitRegions", () => {
  afterEach(() => {
    document.body.innerHTML = "";
    vi.unstubAllGlobals();
  });

  it("re-measures within a frame after any scroll area scrolls, and stops after unmount", async () => {
    resetTauriMock();
    respond("overlay_set_hit_regions", undefined);
    vi.stubGlobal("ResizeObserver", class { observe() {} disconnect() {} });
    vi.stubGlobal("devicePixelRatio", 1);
    let rect = box(10, 10, 20, 20);
    const button = document.createElement("div");
    button.setAttribute("data-hit", "");
    button.getBoundingClientRect = () => rect;
    const scroller = document.createElement("div");
    document.body.append(button, scroller);

    const { unmount } = renderHook(() => useHitRegions([]));
    expect(callsOf("overlay_set_hit_regions")).toHaveLength(1);

    rect = box(10, 40, 20, 20);
    await settle();
    expect(callsOf("overlay_set_hit_regions")).toHaveLength(1);

    // `scroll` from an element does not bubble; the hook must still see it.
    scroller.dispatchEvent(new Event("scroll", { bubbles: false }));
    await settle();
    expect(callsOf("overlay_set_hit_regions")).toHaveLength(2);
    expect(callsOf("overlay_set_hit_regions")[1]).toEqual({ regions: [{ x: 4, y: 34, w: 32, h: 32 }] });

    unmount();
    rect = box(50, 50, 20, 20);
    scroller.dispatchEvent(new Event("scroll", { bubbles: false }));
    await settle();
    expect(callsOf("overlay_set_hit_regions")).toHaveLength(2);
  });
});
