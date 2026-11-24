import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { act, render } from "@testing-library/react";
import { useRef } from "react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { fitRow, useFitRow } from "./fitRow";

// The Library's row 2 never cuts a label (the owner's request, Mac 2.0.15): the end's labels go first, then the tabs
// scroll sideways. 8 px always sits between the tabs and the end's controls.
describe("the Library's toolbar row keeps every label whole", () => {
  // Tabs 420 px wide; [Filters] [Sort & display] 230 px with their labels, 72 px as icons.
  const tabs = 420;
  const labelled = 230;
  const icons = 72;

  it("with room for everything, the tabs and both labels show and nothing scrolls", () => {
    expect(fitRow(1000, tabs, labelled, icons)).toEqual({ labels: true, scrolls: false });
    expect(fitRow(tabs + 8 + labelled, tabs, labelled, icons)).toEqual({ labels: true, scrolls: false });
  });

  it("a pixel short, the end's labels go (their icons stay) before any tab moves", () => {
    expect(fitRow(tabs + 8 + labelled - 1, tabs, labelled, icons)).toEqual({ labels: false, scrolls: false });
    expect(fitRow(tabs + 8 + icons, tabs, labelled, icons)).toEqual({ labels: false, scrolls: false });
  });

  it("with no room even for the icons, the tabs scroll sideways; their words never wrap or get cut", () => {
    expect(fitRow(tabs + 8 + icons - 1, tabs, labelled, icons)).toEqual({ labels: false, scrolls: true });
    expect(fitRow(300, tabs, labelled, icons)).toEqual({ labels: false, scrolls: true });
  });

  it("the tabs' and the row's styles: one line each, never squeezed; only a row with no room scrolls, with no fade", () => {
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
    const rule = (selector: string) => {
      const at = css.indexOf(`\n${selector} {`);
      expect(at, selector).toBeGreaterThan(-1);
      return css.slice(at, css.indexOf("}", at));
    };
    expect(rule(".el-lib-tabs__tab")).toMatch(/white-space: nowrap/);
    expect(rule(".el-lib-tabs__tab")).toMatch(/flex: none/);
    expect(rule(".el-lib-tabs")).not.toMatch(/overflow/);
    expect(rule(".el-lib-tabs--scrolls")).toMatch(/overflow-x: auto/);
    expect(rule(".el-lib-tabs--scrolls")).not.toMatch(/gradient|mask/);
    expect(rule(".el-lib-row2__end")).toMatch(/flex: none/);
  });
});

// The row as drawn, with a pretend layout: a tab is 10 px a character, the end 230 px with its labels or 72 px as icons,
// the row 600 px. The observer reports only what it was asked to watch, as a browser's does.
describe("the Library's toolbar row follows its parts' sizes, not only the window's", () => {
  type Watch = { report: () => void; elements: Set<Element> };
  let watches: Watch[] = [];
  const Real = globalThis.ResizeObserver;
  const descriptors = {
    clientWidth: Object.getOwnPropertyDescriptor(HTMLElement.prototype, "clientWidth"),
    scrollWidth: Object.getOwnPropertyDescriptor(HTMLElement.prototype, "scrollWidth"),
  };
  const rect = Element.prototype.getBoundingClientRect;
  /** The browser's report that `el` changed size: to each observer watching it. */
  const resized = (el: Element) => act(() => watches.filter((w) => w.elements.has(el)).forEach((w) => w.report()));

  beforeEach(() => {
    watches = [];
    globalThis.ResizeObserver = class {
      watch: Watch;
      constructor(report: () => void) {
        this.watch = { report, elements: new Set() };
        watches.push(this.watch);
      }
      observe(el: Element) { this.watch.elements.add(el); }
      disconnect() { watches = watches.filter((w) => w !== this.watch); }
    } as unknown as typeof ResizeObserver;
    Object.defineProperty(HTMLElement.prototype, "clientWidth", { configurable: true, get() { return this.dataset?.part === "row" ? 600 : 0; } });
    Object.defineProperty(HTMLElement.prototype, "scrollWidth", { configurable: true, get() {
      return this.dataset?.part === "tabs" ? [...this.children].reduce((sum: number, tab: Element) => sum + (tab.textContent ?? "").length * 10, 0) : 0;
    } });
    Element.prototype.getBoundingClientRect = function (this: Element) {
      const end = (this as HTMLElement).dataset?.part === "end";
      return { width: end ? ((this.textContent ?? "").includes("Sort & display") ? 230 : 72) : 0 } as DOMRect;
    };
  });
  afterEach(() => {
    globalThis.ResizeObserver = Real;
    Element.prototype.getBoundingClientRect = rect;
    for (const [name, d] of Object.entries(descriptors)) if (d) Object.defineProperty(HTMLElement.prototype, name, d);
  });

  function Row({ count, shown = true }: { count: number; shown?: boolean }) {
    const row = useRef<HTMLDivElement>(null);
    const tabs = useRef<HTMLDivElement>(null);
    const end = useRef<HTMLDivElement>(null);
    const fit = useFitRow(row, tabs, end);
    if (!shown) return <div>3 selected</div>;
    return (
      <div ref={row} data-part="row">
        <div ref={tabs} data-part="tabs" data-scrolls={fit.scrolls}>
          {["All", "Calls", "Meetings", "Voice notes"].map((name) => <button key={name} type="button">{name} {count}</button>)}
        </div>
        <div ref={end} data-part="end">
          <button type="button" aria-label={fit.labels ? undefined : "Filters"}>{fit.labels && "Filters"}</button>
          <button type="button" aria-label={fit.labels ? undefined : "Sort & display"}>{fit.labels && "Sort & display"}</button>
        </div>
      </div>
    );
  }
  const part = (name: string) => document.querySelector(`[data-part="${name}"]`)!;
  const labelled = () => (part("end").textContent ?? "").includes("Sort & display");

  it("the counts grow (9 → 10) with no resize of the window: the row collapses to icons before a tab runs under them", () => {
    // 9 each: tabs 350 px + 8 + 230 = 588 px, in 600: the labels show.
    const view = render(<Row count={9} />);
    resized(part("row"));
    expect(labelled()).toBe(true);
    // 10 each: tabs 390 px + 8 + 230 = 628 px. Only the tabs changed size; the row did not.
    view.rerender(<Row count={10} />);
    for (const tab of part("tabs").children) resized(tab);
    expect(labelled()).toBe(false);
    expect(part("end").querySelectorAll("button[aria-label]")).toHaveLength(2);
    expect(part("tabs")).toHaveAttribute("data-scrolls", "false");
    // Back to 9 (a recording trashed): the labels come back.
    view.rerender(<Row count={9} />);
    for (const tab of part("tabs").children) resized(tab);
    expect(labelled()).toBe(true);
  });

  it("a row put back after a selection is new: it is watched again", () => {
    const view = render(<Row count={9} />);
    resized(part("row"));
    view.rerender(<Row count={9} shown={false} />);
    view.rerender(<Row count={10} />);
    // The new row's first report (a browser sends one for each element it starts watching).
    resized(part("row"));
    expect(labelled()).toBe(false);
  });
});
