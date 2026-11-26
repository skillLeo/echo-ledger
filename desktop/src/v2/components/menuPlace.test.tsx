// A button's menu is never cut off (S2 on 2.0.8: the Library header's "6 need you ▾" menu opened to the button's end,
// ran past the page into the sidebar and was clipped: "aren't transcribed"). It floats in its own layer above the app
// and is placed from its button (placeMenu): flipped to the button's start edge where the end would run out of the
// page, moved inside the window, and long items wrap inside its width.
import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { ContextMenu, Menu, menuPointFor, placeContextMenu, placeMenu } from "./Menu";
import { SplitButton } from "./SplitButton";
import { InfoIcon, MoreIcon, PhoneIcon, TranscriptIcon } from "./icons";

const v2Css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
let sheet: HTMLStyleElement;
beforeAll(() => {
  sheet = document.createElement("style");
  sheet.textContent = v2Css;
  document.head.appendChild(sheet);
});
afterAll(() => sheet.remove());

/**
 * The Library header's two menus as Chromium draws them (scripts/screens, the fixture's "6 need you" and More, measured):
 * language, window width, the sidebar full or as the rail, which menu, its button's box [left, top, right, bottom],
 * the open menu's size [width, height] (long items wrapped inside its width), and the page beside the sidebar
 * [left, right]. Height 800.
 */
const DRAWN: [string, number, "full" | "rail", "needs" | "more", number[], number[], number[]][] = [
  ["en", 760, "full", "needs", [272, 92, 412.3, 128], [312.3, 82], [240, 760]],
  ["en", 760, "full", "more", [420.3, 92, 456.3, 128], [224, 118], [240, 760]],
  ["en", 760, "rail", "needs", [96, 92, 236.3, 128], [312.3, 82], [64, 760]],
  ["en", 1280, "full", "needs", [683.5, 34, 823.9, 70], [312.3, 82], [240, 1280]],
  ["en", 1280, "full", "more", [1019, 34, 1055, 70], [224, 46], [240, 1280]],
  ["en", 1280, "rail", "needs", [595.5, 34, 735.9, 70], [312.3, 82], [64, 1280]],
  ["en", 1280, "rail", "more", [931, 34, 967, 70], [224, 46], [64, 1280]],
  ["en", 1920, "full", "needs", [1003.5, 34, 1143.9, 70], [312.3, 82], [240, 1920]],
  ["en", 1920, "full", "more", [1339, 34, 1375, 70], [224, 46], [240, 1920]],
  ["en", 1920, "rail", "needs", [915.5, 34, 1055.9, 70], [312.3, 82], [64, 1920]],
  ["en", 1920, "rail", "more", [1251, 34, 1287, 70], [224, 46], [64, 1920]],
  ["ur", 760, "full", "needs", [341.7, 114, 488, 155], [320, 116], [0, 520]],
  ["ur", 760, "full", "more", [297.7, 114, 333.7, 155], [224, 124], [0, 520]],
  ["ur", 760, "rail", "needs", [517.7, 114, 664, 155], [320, 116], [0, 696]],
  ["ur", 760, "rail", "more", [277.7, 114, 313.7, 155], [224, 48], [0, 696]],
  ["ur", 1280, "full", "needs", [380.9, 42.5, 527.2, 83.5], [320, 116], [0, 1040]],
  ["ur", 1280, "full", "more", [236.5, 42.5, 272.5, 83.5], [224, 86], [0, 1040]],
  ["ur", 1280, "rail", "needs", [468.9, 42.5, 615.2, 83.5], [320, 116], [0, 1216]],
  ["ur", 1280, "rail", "more", [324.5, 42.5, 360.5, 83.5], [224, 86], [0, 1216]],
  ["ur", 1920, "full", "needs", [700.9, 42.5, 847.2, 83.5], [320, 116], [0, 1680]],
  ["ur", 1920, "full", "more", [556.5, 42.5, 592.5, 83.5], [224, 86], [0, 1680]],
  ["ur", 1920, "rail", "needs", [788.9, 42.5, 935.2, 83.5], [320, 116], [0, 1856]],
  ["ur", 1920, "rail", "more", [644.5, 42.5, 680.5, 83.5], [224, 86], [0, 1856]],
];
const HEIGHT = 800;
const EDGE = 8;

type Rect = { left: number; top: number; right: number; bottom: number };
const domRect = ({ left, top, right, bottom }: Rect) => ({ left, top, right, bottom, width: right - left, height: bottom - top, x: left, y: top, toJSON: () => ({}) }) as DOMRect;
const meets = (a: Rect, b: Rect) => a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;

const NEEDS = [
  { label: "5 recordings aren't transcribed", icon: TranscriptIcon },
  { label: "1 short call was skipped in the last day", icon: PhoneIcon },
];
const MORE = [{ label: "Trash" }, { label: "Sync" }, { label: "Select", shortcut: "Ctrl+A" }];

describe("a button's menu at every width, with the sidebar or the rail, left to right and right to left", () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.documentElement.dir = "";
  });

  for (const [lang, width, side, which, anchor, size, page] of DRAWN) {
    const rtl = lang === "ur";
    it(`${rtl ? "right to left" : "English"}, ${width} px, ${side === "full" ? "the full sidebar" : "the rail"}: ${which === "needs" ? "the needs menu" : "More"} is inside the window, off the sidebar, above the page`, () => {
      vi.spyOn(window, "innerWidth", "get").mockReturnValue(width);
      vi.spyOn(window, "innerHeight", "get").mockReturnValue(HEIGHT);
      if (rtl) document.documentElement.dir = "rtl";
      const sidebarBox = side === "full" ? 240 : 64;
      const sidebar: Rect = rtl ? { left: width - sidebarBox, top: 0, right: width, bottom: HEIGHT } : { left: 0, top: 0, right: sidebarBox, bottom: HEIGHT };
      const main: Rect = { left: page[0], top: 0, right: page[1], bottom: HEIGHT };
      render(
        <div className="el-root el-shell" style={{ direction: rtl ? "rtl" : "ltr" }}>
          <nav className="el-sidebar" />
          <main className="el-shell__main" style={{ overflow: "hidden", direction: rtl ? "rtl" : "ltr" }}>
            <div className="el-page__actions" style={{ direction: rtl ? "rtl" : "ltr" }}>
              {which === "needs"
                ? <Menu label="Needs action" button={{ variant: "quiet", icon: InfoIcon, text: "6 need you" }} items={NEEDS.map((n) => ({ ...n, onPick: () => undefined }))} />
                : <Menu label="More actions" button={{ variant: "quiet", icon: MoreIcon, title: "More" }} items={MORE.map((n) => ({ ...n, onPick: () => undefined }))} />}
            </div>
          </main>
        </div>,
      );
      vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function (this: Element) {
        if (this.classList.contains("el-menu-anchor")) return domRect({ left: anchor[0], top: anchor[1], right: anchor[2], bottom: anchor[3] });
        if (this.classList.contains("el-shell__main")) return domRect(main);
        if (this.classList.contains("el-sidebar")) return domRect(sidebar);
        if (this.getAttribute("role") === "menu") {
          const left = parseFloat((this as HTMLElement).style.left) || 0;
          const top = parseFloat((this as HTMLElement).style.top) || 0;
          return domRect({ left, top, right: left + size[0], bottom: top + size[1] });
        }
        return domRect({ left: 0, top: 0, right: 0, bottom: 0 });
      });
      fireEvent.click(screen.getByRole("button", { name: which === "needs" ? "6 need you" : "More actions" }));
      const menu = screen.getByRole("menu");
      const box = menu.getBoundingClientRect();
      const where = `${JSON.stringify([box.left, box.top, box.right, box.bottom])} in ${width} with the sidebar at ${sidebar.left}-${sidebar.right}`;
      // Inside the window, EDGE from its edges.
      expect(box.left, where).toBeGreaterThanOrEqual(EDGE);
      expect(box.right, where).toBeLessThanOrEqual(width - EDGE);
      expect(box.top, where).toBeGreaterThanOrEqual(EDGE);
      expect(box.bottom, where).toBeLessThanOrEqual(HEIGHT - EDGE);
      // Off the sidebar, and under its button.
      expect(meets(box, sidebar), where).toBe(false);
      expect(Math.abs(box.top - (anchor[3] + 4)), where).toBeLessThanOrEqual(0.5);
      // In its own layer at the end of the page: no panel that clips holds it, and it is above the sidebar.
      const layer = menu.closest(".el-layer")!;
      expect(layer.parentElement).toBe(document.body);
      expect(document.querySelector(".el-shell__main")!.contains(menu)).toBe(false);
      expect(layer).toHaveAttribute("dir", rtl ? "rtl" : "ltr");
      // Every item there, whole.
      expect(within(menu).getAllByRole("menuitem").map((m) => m.textContent?.replace("Ctrl+A", ""))).toEqual((which === "needs" ? NEEDS : MORE).map((n) => n.label));
    });
  }
});

describe("placeMenu", () => {
  const view = { width: 800, height: 600 };
  const page = { left: 240, top: 0, right: 800, bottom: 600 };
  const button = (left: number, right: number, top = 100) => ({ left, right, top, bottom: top + 36 });
  const size = { width: 300, height: 100 };

  it("under its button, its end under the button's end, where that fits", () => {
    expect(placeMenu({ anchor: button(600, 740), size, region: page, view, align: "end", rtl: false })).toEqual({ left: 440, top: 140, up: false, room: undefined });
  });
  it("the start edge instead where the end would run into the sidebar (the S2: the needs button at the page's start)", () => {
    expect(placeMenu({ anchor: button(272, 412), size, region: page, view, align: "end", rtl: false }).left).toBe(272);
  });
  it("right to left: the end is the left edge, and flips the same way", () => {
    const rtlPage = { left: 0, top: 0, right: 560, bottom: 600 };
    expect(placeMenu({ anchor: button(100, 240), size, region: rtlPage, view, align: "end", rtl: true }).left).toBe(100);
    expect(placeMenu({ anchor: button(400, 540), size, region: rtlPage, view, align: "end", rtl: true }).left).toBe(240);
  });
  it("moved just inside the page when neither edge fits", () => {
    expect(placeMenu({ anchor: button(250, 290), size: { width: 540, height: 100 }, region: page, view, align: "end", rtl: false }).left).toBe(250);
    expect(placeMenu({ anchor: button(500, 540), size: { width: 540, height: 100 }, region: page, view, align: "end", rtl: false }).left).toBe(240);
  });
  it("wider than the page: inside the window instead, above the sidebar", () => {
    expect(placeMenu({ anchor: button(300, 340), size: { width: 700, height: 100 }, region: page, view, align: "end", rtl: false }).left).toBe(8);
  });
  it("over its button when there is no room below and more above; taller than its room, it scrolls in it", () => {
    expect(placeMenu({ anchor: button(600, 740, 520), size, region: page, view, align: "end", rtl: false })).toEqual({ left: 440, top: 416, up: true, room: undefined });
    expect(placeMenu({ anchor: button(600, 740, 300), size: { width: 300, height: 900 }, region: page, view, align: "end", rtl: false })).toEqual({ left: 440, top: 8, up: true, room: 288 });
  });
});

describe("long items", () => {
  const rule = (selector: string) => [...sheet.sheet!.cssRules].filter((r): r is CSSStyleRule => r instanceof CSSStyleRule && r.selectorText.split(",").map((x) => x.trim()).includes(selector));
  const value = (selector: string, prop: string) => rule(selector).map((r) => r.style.getPropertyValue(prop)).filter(Boolean).pop() ?? "";
  it("wrap inside the menu's width instead of being cut: no nowrap, no clipping, no ellipsis on an item's words", () => {
    expect(value(".el-menu__label", "white-space")).toBe("normal");
    expect(value(".el-menu__label", "overflow-wrap")).toBe("anywhere");
    for (const selector of [".el-menu__label", ".el-menu__option"]) {
      expect(value(selector, "text-overflow"), selector).toBe("");
      expect(value(selector, "overflow"), selector).toBe("");
    }
    expect(value(".el-menu", "max-inline-size")).toBe("calc(var(--el-space-64) * 5)");
    // A floating menu is never wider than the window, less its edges.
    expect(value(".el-menu--floating", "max-inline-size")).toContain("calc(100% - var(--el-space-16))");
  });
});

describe("Tab and Shift+Tab out of a menu", () => {
  // The list floats in its own layer at the end of the page, so leaving it must put focus back by hand: on the button
  // that opened it (Tab then moves on from there, the browser's own step; Shift+Tab stays on the button).
  const press = (target: Element, shiftKey: boolean) => fireEvent.keyDown(target, { key: "Tab", shiftKey });

  for (const shiftKey of [false, true]) {
    const key = shiftKey ? "Shift+Tab" : "Tab";
    it(`${key} from a Menu closes it and focus is on its button${shiftKey ? ", where it stays" : ", for the browser to move on from"}`, () => {
      render(<Menu label="Needs action" button={{ text: "6 need you" }} items={NEEDS.map((n) => ({ ...n, onPick: () => undefined }))} />);
      const button = screen.getByRole("button", { name: "6 need you" });
      fireEvent.click(button);
      const item = screen.getAllByRole("menuitem")[0];
      item.focus();
      const moved = press(item, shiftKey);
      expect(screen.queryByRole("menu")).toBeNull();
      expect(button).toHaveFocus();
      // fireEvent's answer is false when the key's default was prevented.
      expect(moved).toBe(!shiftKey);
    });

    it(`${key} from a SplitButton's menu closes it and focus is on its chevron${shiftKey ? ", where it stays" : ""}`, () => {
      render(<SplitButton label="Copy" onClick={() => undefined} menuLabel="More copy options"
        items={[{ label: "Copy text", onPick: () => undefined }, { label: "Copy for AI", onPick: () => undefined }]} />);
      const chevron = screen.getByRole("button", { name: "More copy options" });
      fireEvent.click(chevron);
      const item = screen.getAllByRole("menuitem")[1];
      item.focus();
      const moved = press(item, shiftKey);
      expect(screen.queryByRole("menu")).toBeNull();
      expect(chevron).toHaveFocus();
      expect(moved).toBe(!shiftKey);
    });

    it(`${key} from a right-click menu closes it and focus goes back to the row it opened on`, () => {
      const onClose = vi.fn();
      const { rerender } = render(<button type="button">Row</button>);
      const row = screen.getByRole("button", { name: "Row" });
      row.focus();
      rerender(<><button type="button">Row</button><ContextMenu label="Recording actions" items={[{ label: "Open", onPick: () => undefined }]} at={{ x: 10, y: 10 }} onClose={onClose} /></>);
      const item = screen.getByRole("menuitem", { name: "Open" });
      item.focus();
      const moved = press(item, shiftKey);
      expect(onClose).toHaveBeenCalled();
      expect(row).toHaveFocus();
      expect(moved).toBe(!shiftKey);
    });
  }
});

describe("an item's words in a right-to-left window", () => {
  afterEach(() => {
    document.documentElement.dir = "";
  });
  it("keep their own order, set apart in a <bdi>, while the label follows the menu's side (no direction of its own)", () => {
    document.documentElement.dir = "rtl";
    render(<Menu label="Needs action" button={{ text: "6 need you" }} items={NEEDS.map((n) => ({ ...n, onPick: () => undefined }))} />);
    fireEvent.click(screen.getByRole("button", { name: "6 need you" }));
    const labels = [...screen.getByRole("menu").querySelectorAll(".el-menu__label")];
    expect(labels.map((l) => l.textContent)).toEqual(NEEDS.map((n) => n.label));
    for (const l of labels) {
      expect(l).not.toHaveAttribute("dir");
      expect(l.firstElementChild?.tagName).toBe("BDI");
      expect(l.firstElementChild?.textContent).toBe(l.textContent);
    }
    // The menu's layer takes the window's direction, so its labels sit on its start side, by their icons.
    expect(screen.getByRole("menu").closest(".el-layer")).toHaveAttribute("dir", "rtl");
  });
});

// D-256: in right to left a right-click menu opened to the pointer's right while its sub-menus opened to the left.
describe("a right-click menu at the pointer", () => {
  const view = { width: 1280, height: 800 };
  const size = { width: 224, height: 160 };
  it("English: its left corner at the pointer, so it opens to the pointer's right (unchanged)", () => {
    expect(placeContextMenu({ at: { x: 500, y: 300 }, size, view, rtl: false })).toEqual({ x: 500, y: 300 });
  });
  it("right to left: its right corner at the pointer, so it opens to the pointer's left, the side its sub-menus open on", () => {
    expect(placeContextMenu({ at: { x: 500, y: 300 }, size, view, rtl: true })).toEqual({ x: 276, y: 300 });
  });
  it("English: near the window's right edge it moves left just enough to stay inside", () => {
    expect(placeContextMenu({ at: { x: 1270, y: 300 }, size, view, rtl: false }).x).toBe(1280 - 224);
  });
  it("right to left: near the window's left edge it moves right just enough to stay inside", () => {
    expect(placeContextMenu({ at: { x: 10, y: 300 }, size, view, rtl: true }).x).toBe(0);
  });
  it("either way it never starts off the window's other edge either (a pointer at or past it)", () => {
    expect(placeContextMenu({ at: { x: -4, y: 300 }, size, view, rtl: false }).x).toBe(0);
    expect(placeContextMenu({ at: { x: 1300, y: 300 }, size, view, rtl: true }).x).toBe(1280 - 224);
  });
  for (const rtl of [false, true]) {
    it(`${rtl ? "right to left" : "English"}: near the bottom it moves up to stay inside, and at the top it stays at 0`, () => {
      expect(placeContextMenu({ at: { x: 500, y: 790 }, size, view, rtl }).y).toBe(800 - 160);
      expect(placeContextMenu({ at: { x: 500, y: -2 }, size, view, rtl }).y).toBe(0);
    });
  }

  describe("drawn", () => {
    let rect: ReturnType<typeof vi.spyOn<HTMLElement, "getBoundingClientRect">>;
    beforeEach(() => {
      rect = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect");
    });
    afterEach(() => {
      document.documentElement.dir = "";
      rect.mockRestore();
    });
    for (const rtl of [false, true]) {
      it(`${rtl ? "right to left" : "English"}: the list is placed ${rtl ? "to the left of" : "to the right of"} the pointer`, () => {
        if (rtl) document.documentElement.dir = "rtl";
        rect.mockImplementation(function (this: HTMLElement) {
          return domRect(this.getAttribute("role") === "menu" ? { left: 0, top: 0, right: 224, bottom: 160 } : { left: 0, top: 0, right: 0, bottom: 0 });
        });
        render(<ContextMenu label="Recording actions" items={[{ label: "Open", onPick: () => undefined }]} at={{ x: 500, y: 300 }} onClose={() => undefined} />);
        const menu = screen.getByRole("menu");
        expect(menu.style.left).toBe(rtl ? "276px" : "500px");
        expect(menu.style.top).toBe("300px");
      });
    }
    it("from the keyboard it opens under the focused row's start: a quarter in from its left, or its right in right to left", () => {
      const row = document.createElement("div");
      document.body.appendChild(row);
      rect.mockImplementation(() => domRect({ left: 100, top: 40, right: 500, bottom: 80 }));
      expect(menuPointFor(row)).toEqual({ x: 200, y: 80 });
      document.documentElement.dir = "rtl";
      expect(menuPointFor(row)).toEqual({ x: 400, y: 80 });
      row.remove();
    });
  });
});
