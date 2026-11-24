// UX-25 (PLAN_V2_1_POLISH decision 13): the Library keeps your place, and a Back to top button. Back from a recording
// (‹ Library, Esc, ⌘[), the list is where it was, with the same view, tab, filters and search; the recording you came
// from keeps a quiet tint ("last opened", not the selection's colour) and keyboard focus until another row is opened
// or clicked, the view, tab or filters change, or 60 s pass. Back to top shows after one screen, and its click, Home
// and ⌘↑ (Ctrl+Home) all reach the top with focus on the first row.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { emitEvent, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { filterBySource } from "../../test/library";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 24, 15, 0);
const done = (preview: string) => ({ final_status: "completed", final_transcript_id: null, live_available: false, preview });
/** 60 recordings, one a minute, newest first: row 40 is "Call 40". */
const ITEMS: LibraryItem[] = Array.from({ length: 60 }, (_, i) => makeItem(`r${i + 1}`, {
  title: `Call ${i + 1}`, source_app: i % 2 ? "zoom" : "whatsapp", started_at: new Date(2026, 10, 24, 14, 59 - i).toISOString(), duration_ms: 60_000, transcript: done(`Words ${i + 1}`),
}));
const SCREEN = 600;

/** The Library inside a scrolling box, as the shell's content area is: one screen is 600 px. */
async function show() {
  const view = render(<div data-testid="scroller" style={{ overflowY: "auto" }}><LibraryV2 /></div>);
  const scroller = screen.getByTestId("scroller");
  Object.defineProperty(scroller, "clientHeight", { configurable: true, value: SCREEN });
  await act(flushAsync);
  return { view, scroller };
}
const scrollTo = (scroller: HTMLElement, top: number) => {
  scroller.scrollTop = top;
  fireEvent.scroll(scroller);
};
const row = (title: string) => within(screen.getByRole("list", { name: "Recordings" })).getByRole("button", { name: title });
const lastOpened = () => [...document.querySelectorAll(".el-rec--last button[data-row-id]")].map((b) => b.getAttribute("data-row-id"));
const backToLibrary = async () => {
  fireEvent.click(screen.getByRole("button", { name: "Library" }));
  await act(flushAsync);
};

describe("the Library keeps your place (UX-25)", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
    vi.useFakeTimers({ toFake: ["Date", "setTimeout", "clearTimeout"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
    respond("session_detail", { playback_url: null });
  });
  afterEach(() => vi.useRealTimers());

  it("back from row 40: the list is where it was, row 40 tinted and focused; the tint stays, and moves to the next one opened", async () => {
    const { scroller } = await show();
    scrollTo(scroller, 2200);
    fireEvent.click(row("Call 40"));
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Call 40" })).toBeInTheDocument();
    // The recording page starts at its own top.
    scroller.scrollTop = 0;
    await backToLibrary();
    expect(scroller.scrollTop).toBe(2200);
    expect(row("Call 40")).toHaveFocus();
    expect(lastOpened()).toEqual(["r40"]);
    // A quiet tint, never the selection's: nothing is picked.
    expect(row("Call 40").closest("li")).not.toHaveClass("el-rec--selected");
    expect(row("Call 40")).not.toHaveAttribute("aria-pressed");
    // It stays: 3 s later it is still there.
    act(() => vi.advanceTimersByTime(3_000));
    expect(lastOpened()).toEqual(["r40"]);
    // Another row opened: the tint moves to it.
    fireEvent.click(row("Call 41"));
    await act(flushAsync);
    await backToLibrary();
    expect(lastOpened()).toEqual(["r41"]);
    expect(row("Call 41")).toHaveFocus();
  });

  it("the tint goes after 60 s, or when the view's filters change; Esc and ⌘[ come back to the same place too", async () => {
    const { scroller } = await show();
    scrollTo(scroller, 1800);
    fireEvent.click(row("Call 33"));
    await act(flushAsync);
    scroller.scrollTop = 0;
    fireEvent.keyDown(document.body, { key: "Escape" });
    await act(flushAsync);
    expect(scroller.scrollTop).toBe(1800);
    expect(lastOpened()).toEqual(["r33"]);
    act(() => vi.advanceTimersByTime(60_000));
    expect(lastOpened()).toEqual([]);

    fireEvent.click(row("Call 33"));
    await act(flushAsync);
    scroller.scrollTop = 0;
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await act(flushAsync);
    expect(scroller.scrollTop).toBe(1800);
    expect(lastOpened()).toEqual(["r33"]);
    vi.useRealTimers();
    await filterBySource("Zoom");
    expect(lastOpened()).toEqual([]);
  });

  it("a recording gone while it was open (deleted on another device): the list comes back at the same place, focus on the row now there", async () => {
    const { scroller } = await show();
    scrollTo(scroller, 2200);
    fireEvent.click(row("Call 40"));
    await act(flushAsync);
    scroller.scrollTop = 0;
    respond("library_list", ITEMS.filter((i) => i.id !== "r40"));
    await emitEvent("library:updated", null);
    await act(flushAsync);
    expect(screen.queryByRole("heading", { level: 1, name: "Call 40" })).toBeNull();
    expect(scroller.scrollTop).toBe(2200);
    // The nearest row: the one now where Call 40 was. No tint, as it isn't the one you opened.
    expect(row("Call 41")).toHaveFocus();
    expect(lastOpened()).toEqual([]);
  });
});

describe("Back to top (UX-25)", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
  });

  it("shows after one screen, hides at the top; its click, Home, ⌘↑ and Ctrl+Home reach the top with focus on the first row", async () => {
    const { scroller } = await show();
    const button = () => screen.queryByRole("button", { name: "Back to top" });
    expect(button()).toBeNull();
    scrollTo(scroller, SCREEN - 1);
    expect(button()).toBeNull();
    scrollTo(scroller, SCREEN + 1);
    expect(button()).toHaveAttribute("title", "Back to top");
    fireEvent.click(button()!);
    expect(scroller.scrollTop).toBe(0);
    expect(row("Call 1")).toHaveFocus();
    fireEvent.scroll(scroller);
    expect(button()).toBeNull();
    for (const key of [{ key: "Home" }, { key: "ArrowUp", metaKey: true }, { key: "Home", ctrlKey: true }]) {
      scrollTo(scroller, 3000);
      act(() => row("Call 50").focus());
      fireEvent.keyDown(row("Call 50"), key);
      expect(scroller.scrollTop, JSON.stringify(key)).toBe(0);
      expect(row("Call 1")).toHaveFocus();
    }
    // Typing in the search, Home is the field's.
    scrollTo(scroller, 3000);
    fireEvent.keyDown(screen.getByRole("searchbox", { name: "Search recordings" }), { key: "Home" });
    expect(scroller.scrollTop).toBe(3000);
  });
});
