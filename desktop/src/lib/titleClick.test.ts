// UX-20: a double click on the title row's empty parts does what the Mac's own setting says (zoom, minimize or
// nothing): the core reads it. Tauri's drag script would always zoom, so this goes first.
import { afterEach, describe, expect, it, vi } from "vitest";
import { isDragRegion, watchTitleDoubleClick } from "./titleClick";

const make = (html: string) => {
  document.body.innerHTML = html;
  return (selector: string) => document.querySelector<HTMLElement>(selector)!;
};
/** The path a press at `el` travels, as composedPath() gives it. */
const path = (el: Element): EventTarget[] => {
  const out: EventTarget[] = [];
  for (let at: Element | null = el; at; at = at.parentElement) out.push(at);
  return [...out, document, window];
};
const press = (el: Element, type: "mousedown" | "mouseup", detail: number, at = { x: 10, y: 10 }) => {
  const e = new MouseEvent(type, { bubbles: true, cancelable: true, button: 0, detail, clientX: at.x, clientY: at.y });
  el.dispatchEvent(e);
  return e;
};

describe("a double click on the title row (UX-20)", () => {
  afterEach(() => {
    document.body.innerHTML = "";
  });

  it("finds a drag region as Tauri's drag script does: deep, false, and clickable things in between", () => {
    const $ = make(`
      <header data-tauri-drag-region="deep" id="row">
        <h1 id="title"><span id="name">All recordings</span></h1>
        <div data-tauri-drag-region="false" id="field"><span id="icon">⌕</span></div>
        <button id="back"><span id="glyph">‹</span></button>
      </header>
      <div data-tauri-drag-region id="self"><span id="child">x</span></div>`);
    expect(isDragRegion(path($("#row")))).toBe(true);
    expect(isDragRegion(path($("#name")))).toBe(true);
    expect(isDragRegion(path($("#icon")))).toBe(false);
    expect(isDragRegion(path($("#glyph")))).toBe(false);
    // A bare attribute is the element itself only.
    expect(isDragRegion(path($("#self")))).toBe(true);
    expect(isDragRegion(path($("#child")))).toBe(false);
  });

  it("on a Mac: asks the core (once, on the second click's release, if the pointer stayed), and Tauri's zoom never runs", () => {
    const $ = make(`<header data-tauri-drag-region="deep"><h1 id="title">All recordings</h1><button id="back">‹</button></header>`);
    const run = vi.fn(() => Promise.resolve());
    const tauri = vi.fn();
    document.addEventListener("mouseup", tauri);
    const stop = watchTitleDoubleClick("macos", run);
    press($("#title"), "mousedown", 1);
    press($("#title"), "mouseup", 1);
    press($("#title"), "mousedown", 2);
    press($("#title"), "mouseup", 2);
    expect(run).toHaveBeenCalledTimes(1);
    expect(tauri).toHaveBeenCalledTimes(1);
    // The pointer moved between the second press and its release: nothing.
    press($("#title"), "mousedown", 2, { x: 10, y: 10 });
    press($("#title"), "mouseup", 2, { x: 40, y: 10 });
    expect(run).toHaveBeenCalledTimes(1);
    // On a button: nothing of ours.
    press($("#back"), "mousedown", 2);
    press($("#back"), "mouseup", 2);
    expect(run).toHaveBeenCalledTimes(1);
    stop();
    document.removeEventListener("mouseup", tauri);
  });

  it("off a Mac it leaves the double click to Tauri (Windows maximizes)", () => {
    const $ = make(`<header data-tauri-drag-region="deep"><h1 id="title">All recordings</h1></header>`);
    const run = vi.fn(() => Promise.resolve());
    const stop = watchTitleDoubleClick("windows", run);
    press($("#title"), "mousedown", 2);
    press($("#title"), "mouseup", 2);
    expect(run).not.toHaveBeenCalled();
    stop();
  });
});
