// The Library header with the needs button (headerFold.ts), there whenever something needs action: the least-used
// actions fold into More (Select, then Sync, then Trash) so the header keeps its lines and its actions stay on one row,
// at every window width from 720 to 1920 px,
// in English and right to left, with the full sidebar or the rail. jsdom draws nothing, so each action is given the
// width Chromium draws it at (scripts/screens, measured), and the header the width the window leaves it.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeStatus } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { foldCount, headerLines, rowWidth } from "./headerFold";
import type { Account } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const account: Account = { name: "Ayesha Khan", email: "ayesha@example.com", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null };
const ITEMS = [
  makeItem("a", { title: "Ali", status: "uploaded", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } }),
  makeItem("b", { title: "Bina" }),
];

/** Each action's width as Chromium draws it, and a button's height (More is as wide as it is tall). The needs button
 * leads with its attention dot (8 px where an icon was 16). */
const DRAWN = {
  en: { widths: { "3 need you": 140.3, Trash: 87.7, Sync: 83.5, Select: 92.6, Import: 71, Record: 97.9 } as Record<string, number>, height: 36 },
  rtl: { widths: { "3 need you": 146.3, Trash: 92.4, Sync: 87.7, Select: 97.8, Import: 76.6, Record: 103.9 } as Record<string, number>, height: 40 },
};
/** The titles' flex basis, the header's gap and the actions' gap (v2.css .el-page__head). */
const BASIS = 384, HEAD_GAP = 16, GAP = 8;
/** The header's width in a window: the sidebar (240 full, 64 as the rail) and the page's padding off, at most 960. */
const headWidth = (window: number, sidebar: "full" | "rail") => Math.min(960, window - (sidebar === "full" ? 240 : 64) - 64);
const BASE_ORDER = ["Trash", "Sync", "Select", "Import", "Record"];

const SWEEP = [...new Set([
  ...Array.from({ length: (1920 - 720) / 10 + 1 }, (_, i) => 720 + i * 10),
  // Where the needs button with its words used to put the header on another line (the first build): 780–855 with the
  // full sidebar, 1164–1236 in English, 1280 right to left.
  ...Array.from({ length: (856 - 780) / 2 }, (_, i) => 780 + i * 2),
  ...Array.from({ length: (1238 - 1164) / 2 }, (_, i) => 1164 + i * 2),
  1276, 1278, 1280, 1282, 1284,
])].sort((a, b) => a - b);

describe("the header's actions with the needs button, at every width", () => {
  let head = 960;
  let drawn = DRAWN.en;
  const nameOf = (el: Element) => (el.querySelector("button") ?? el).getAttribute("aria-label") || el.textContent?.trim() || "";
  beforeEach(() => {
    head = 960;
    drawn = DRAWN.en;
    resetTauriMock();
    window.localStorage.clear();
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("ready_last", { ready: true, checkedAt: "2026-11-17T10:00:00Z", checks: [] });
    respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos" });
    respond("skipped_list", []);
    respond("account_get", account);
    vi.spyOn(HTMLElement.prototype, "clientWidth", "get").mockImplementation(function (this: HTMLElement) {
      return this.classList.contains("el-page__head") ? head : 0;
    });
    vi.spyOn(Element.prototype, "getBoundingClientRect").mockImplementation(function (this: Element) {
      const action = this.parentElement?.classList.contains("el-page__actions");
      const name = action ? nameOf(this) : "";
      const width = !action ? 0 : name === "More actions" ? drawn.height : drawn.widths[name] ?? 0;
      const height = action ? drawn.height : 0;
      return { width, height, top: 0, left: 0, right: width, bottom: height, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    });
    const real = window.getComputedStyle.bind(window);
    vi.spyOn(window, "getComputedStyle").mockImplementation((el: Element, pseudo?: string | null) => {
      const style = real(el, pseudo);
      const own: Record<string, string> = el.classList.contains("el-page__titles") ? { flexBasis: `${BASIS}px` }
        : el.classList.contains("el-page__head") ? { columnGap: `${HEAD_GAP}px` }
        : el.classList.contains("el-page__actions") ? { columnGap: `${GAP}px` } : {};
      if (!Object.keys(own).length) return style;
      return new Proxy(style, {
        get: (target, key) => {
          if (typeof key === "string" && key in own) return own[key];
          const value = Reflect.get(target, key, target);
          return typeof value === "function" ? value.bind(target) : value;
        },
      });
    });
  });
  afterEach(() => {
    document.documentElement.dir = "";
    vi.restoreAllMocks();
  });

  const open = async () => {
    const top = { status: makeStatus({ platform: "macos", pendingDecisions: 2 }), onSetup: vi.fn(), onReadyCheck: vi.fn(), onRecord: vi.fn(), onActivity: vi.fn() };
    render(<LibraryV2 top={top} onImport={vi.fn()} onRecord={vi.fn()} />);
    await act(flushAsync);
    // No banner to dismiss first: the button is there as soon as something needs action.
    expect(screen.queryByTestId("needs-action")).toBeNull();
  };
  const actions = () => document.querySelector(".el-page__head .el-page__actions") as HTMLElement;
  const at = async (width: number) => {
    head = width;
    await act(async () => {
      window.dispatchEvent(new Event("resize"));
    });
  };

  for (const [lang, rtl] of [["English", false], ["right to left", true]] as const) {
    for (const sidebar of ["full", "rail"] as const) {
      it(`${lang}, ${sidebar === "full" ? "the full sidebar" : "the rail"}: one row of actions, no line more than without the button, its words always`, async () => {
        drawn = rtl ? DRAWN.rtl : DRAWN.en;
        if (rtl) document.documentElement.dir = "rtl";
        await open();
        const folds = new Map<string, number[]>();
        for (const window of SWEEP) {
          const room = headWidth(window, sidebar);
          await at(room);
          const names = [...actions().children].map(nameOf);
          const width = (name: string) => (name === "More actions" ? drawn.height : drawn.widths[name]);
          const row = rowWidth(names.map(width), GAP);
          const without = headerLines(rowWidth(BASE_ORDER.map(width), GAP), room + 0.5, BASIS + HEAD_GAP);
          const where = `${window} px (header ${room}): ${names.join(" | ")}`;
          expect(row, where).toBeLessThanOrEqual(room + 0.5);
          expect(headerLines(row, room + 0.5, BASIS + HEAD_GAP), where).toBeLessThanOrEqual(Math.min(without, 2));
          expect(names[0], where).toBe("3 need you");
          expect(names.slice(-2), where).toEqual(["Import", "Record"]);
          // What folded is Select first, then Sync, then Trash.
          const gone = ["Select", "Sync", "Trash"].filter((n) => !names.includes(n));
          expect(gone, where).toEqual(["Select", "Sync", "Trash"].slice(0, gone.length));
          expect(names.includes("More actions"), where).toBe(gone.length > 0);
          folds.set(gone.join("+") || "none", [...(folds.get(gone.join("+") || "none") ?? []), window]);
        }
        // Wide windows keep the title and the actions on one row: Select goes into More, and Sync too where the words
        // are wider (right to left: 583 px of actions with Sync beside the 560 the titles leave; English 556).
        const wide = rtl ? "Select+Sync" : "Select";
        expect(folds.get(wide)).toContain(1920);
        expect(folds.get(wide)).toContain(1280);
      });
    }
  }

  // The header goes while a recording is open (the list opens it as its own page) and is drawn again on Back: it is
  // measured again then, also when the window changed meanwhile. (The fixture starts with a recording open.)
  const names = () => [...actions().children].map(nameOf);
  const back = async () => {
    const buttons = screen.getAllByRole("button", { name: "Library", exact: true });
    fireEvent.click(buttons[buttons.length - 1]);
    await act(flushAsync);
  };
  const startOpen = async (window: number) => {
    head = headWidth(window, "full");
    const top = { status: makeStatus({ platform: "macos", pendingDecisions: 2 }), onSetup: vi.fn(), onReadyCheck: vi.fn(), onRecord: vi.fn(), onActivity: vi.fn() };
    render(<LibraryV2 top={top} onImport={vi.fn()} onRecord={vi.fn()} initialOpen="a" />);
    await act(flushAsync);
    expect(document.querySelector(".el-page__head .el-page__actions [aria-haspopup=menu]")?.textContent ?? "").not.toMatch(/need you/);
  };
  const ROWS = { 1280: ["3 need you", "Trash", "Sync", "More actions", "Import", "Record"], 800: ["3 need you", "Trash", "More actions", "Import", "Record"] } as const;

  for (const window of [1280, 800] as const) {
    it(`a recording opened at start and closed: at ${window} px the header folds as it should (${window === 1280 ? "Select" : "Select and Sync"} in More)`, async () => {
      await startOpen(window);
      await back();
      expect(names()).toEqual(ROWS[window]);
    });
  }

  it("a recording opened from the list and closed, with the window changed meanwhile: measured again at the new width", async () => {
    await open();
    await at(headWidth(1280, "full"));
    expect(names()).toEqual(ROWS[1280]);
    fireEvent.click(document.querySelector("button[data-row-id=a]")!);
    await act(flushAsync);
    expect(document.querySelector("button[data-row-id=a]")).toBeNull();
    head = headWidth(800, "full");
    await back();
    expect(names()).toEqual(ROWS[800]);
    await at(headWidth(1280, "full"));
    expect(names()).toEqual(ROWS[1280]);
  });

  it("More keeps each action's icon, words and shortcut, and does what the button did", async () => {
    await open();
    await at(headWidth(800, "full"));
    const more = within(actions()).getByRole("button", { name: "More actions" });
    expect(more).toHaveAttribute("title", "More");
    fireEvent.click(more);
    const items = screen.getAllByRole("menuitem");
    expect(items.map((i) => i.textContent)).toEqual(["Sync", "SelectCtrl+A"]);
    expect(items.every((i) => i.querySelector(".el-icon svg"))).toBe(true);
    expect(items[1]).toHaveAttribute("aria-keyshortcuts", "Control+A");
    fireEvent.click(screen.getByRole("menuitem", { name: /^Select/ }));
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toBeInTheDocument();
  });

  it("unfolds when the window grows back, and Trash folds last", async () => {
    await open();
    await at(headWidth(720, "full"));
    expect([...actions().children].map(nameOf)).toEqual(["3 need you", "More actions", "Import", "Record"]);
    fireEvent.click(within(actions()).getByRole("button", { name: "More actions" }));
    expect(screen.getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["Trash", "Sync", "SelectCtrl+A"]);
    fireEvent.keyDown(document.activeElement ?? document.body, { key: "Escape" });
    await at(headWidth(1100, "full"));
    expect([...actions().children].map(nameOf)).toEqual(["3 need you", "Trash", "Sync", "Select", "Import", "Record"]);
  });
});

describe("foldCount", () => {
  const base = [["trash", 88], ["sync", 84], ["select", 93], ["import", 71], ["record", 98]] as const;
  it("folds nothing where the row has room, then Select, Sync and Trash in turn", () => {
    const at = (room: number) => foldCount({ room, beside: 400, gap: 8, base, needs: 148, more: 36 });
    expect(at(1400)).toBe(0);
    expect(at(960)).toBe(2);
    expect(at(796)).toBe(0);
    expect(at(592)).toBe(1);
    expect(at(496)).toBe(2);
    expect(at(416)).toBe(3);
    expect(at(300)).toBe(3);
  });
  it("leaves out what isn't shown", () => {
    expect(foldCount({ room: 496, beside: 400, gap: 8, base: base.filter(([k]) => k !== "select"), needs: 148, more: 36 })).toBe(1);
  });
});
