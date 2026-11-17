// Find in the v2 Library against classic (PLAN_V2_PARITY 3; docs/v2/audit/parity.csv DT-LIB-001..059): Views ▾ with
// classic's smart views, the filter chips (ticks add up inside one, filters narrow each other), Clear all, Sort and
// group remembered per view, sections with counts that fold, a header box that picks a section, Sync, "/" and the count.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { applyFilters, chipLabel, filterOptions, sections, toggleFilter } from "./filters";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 18, 15, 0); // a Wednesday
const at = (day: number, hour: number) => new Date(2026, 10, day, hour).toISOString();
const done = (preview: string) => ({ final_status: "completed", final_transcript_id: `tr-${preview}`, live_available: false, preview });
const none = { final_status: null, final_transcript_id: null, live_available: false, preview: null };
const ACME = { id: "c1", name: "Acme" };

const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Ali", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", started_at: at(18, 11), duration_ms: 60_000, client: ACME, language_chip: "UR", labels: ["Q4"], transcript: done("one") }),
  makeItem("b", { title: "Bina", source_app: "zoom", source_app_label: "Zoom", source_device: "desktop", started_at: at(18, 9), duration_ms: 600_000, language_chip: "EN", transcript: done("two") }),
  makeItem("c", { title: "Chand", source_app: "whatsapp", source_app_label: "WhatsApp", source_device: "phone", started_at: at(17, 9), duration_ms: 30_000, client: ACME, language_chip: "UR", pinned: true, status: "uploaded", transcript: none }),
  makeItem("d", { title: "Dawood", source_app: "voice_note", source_app_label: "Voice note", source_device: "desktop", started_at: at(10, 9), duration_ms: 120_000, marker_count: 2, tags: ["Price"], status: "failed", transcript: { ...none, final_status: "failed" } }),
];

const rows = () => [...screen.getByRole("list", { name: "Recordings" }).querySelectorAll<HTMLButtonElement>("button[data-row-id]")].map((b) => b.dataset.rowId);
const chip = (name: RegExp) => screen.getByRole("button", { name });
const tick = (chipName: RegExp, item: RegExp) => {
  fireEvent.click(chip(chipName));
  fireEvent.click(screen.getByRole("menuitemcheckbox", { name: item }));
};

describe("the filter rules", () => {
  it("ticks inside one filter add up; filters narrow each other", () => {
    expect(applyFilters(ITEMS, { source: ["whatsapp", "zoom"] }).map((i) => i.id)).toEqual(["a", "b", "c"]);
    expect(applyFilters(ITEMS, { source: ["whatsapp", "zoom"], project: ["none"] }).map((i) => i.id)).toEqual(["b"]);
    // Classic's status groups: a recording not transcribed yet counts as Transcribing; failed ones need you.
    expect(applyFilters(ITEMS, { status: ["Needs you"] }).map((i) => i.id)).toEqual(["d"]);
    expect(applyFilters(ITEMS, { status: ["Needs you", "Transcribing"] }).map((i) => i.id)).toEqual(["c", "d"]);
    expect(applyFilters(ITEMS, { label: ["Q4"] }).map((i) => i.id)).toEqual(["a"]);
    expect(applyFilters(ITEMS, { tag: ["Price"], device: ["desktop"] }).map((i) => i.id)).toEqual(["d"]);
  });

  it("what each filter offers, with counts, the no-project kind last; a chip's words", () => {
    expect(filterOptions(ITEMS, "source")).toEqual([
      { value: "voice_note", label: "Voice notes", count: 1 }, { value: "whatsapp", label: "WhatsApp", count: 2 }, { value: "zoom", label: "Zoom", count: 1 },
    ]);
    expect(filterOptions(ITEMS, "project").map((o) => o.label)).toEqual(["Acme", "No project"]);
    expect(filterOptions(ITEMS, "status").map((o) => o.label)).toEqual(["Needs you", "Transcribing", "Ready"]);
    expect(filterOptions(ITEMS, "device").map((o) => [o.label, o.count])).toEqual([["Desktop", 2], ["Phone", 2]]);
    let f = toggleFilter({}, "source", "zoom");
    expect(chipLabel(ITEMS, f, "source")).toBe("Source: Zoom");
    f = toggleFilter(f, "source", "whatsapp");
    expect(chipLabel(ITEMS, f, "source")).toBe("Source · 2");
    expect(chipLabel(ITEMS, toggleFilter(f, "source", "zoom"), "source")).toBe("Source: WhatsApp");
  });

  it("sections: pinned first when grouped by date, then the days; any other group or sort", () => {
    expect(sections(ITEMS, "date", "newest", {}, true, NOW).map((g) => [g.label, g.items.map((i) => i.id)])).toEqual([
      ["Pinned", ["c"]], ["Today", ["a", "b"]], ["Earlier", ["d"]],
    ]);
    expect(sections(ITEMS, "date", "newest", {}, false, NOW).map((g) => g.label)).toEqual(["Today", "Yesterday", "Earlier"]);
    expect(sections(ITEMS, "app", "longest", {}, true, NOW).map((g) => [g.label, g.items.map((i) => i.id)])).toEqual([
      ["Voice notes", ["d"]], ["WhatsApp", ["a", "c"]], ["Zoom", ["b"]],
    ]);
    expect(sections(ITEMS, "none", "title", {}, true, NOW)).toEqual([{ key: "none", label: "", items: [ITEMS[0], ITEMS[1], ITEMS[2], ITEMS[3]] }]);
    expect(sections(ITEMS, "none", "opened", { d: 5, b: 9 }, true, NOW)[0].items.map((i) => i.id)).toEqual(["b", "d", "a", "c"]);
  });
});

describe("Find in the Library", () => {
  beforeEach(() => {
    resetTauriMock();
    window.localStorage.clear();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
  });
  const show = async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
  };

  it("sections with counts that fold and unfold; the count of recordings under the title", async () => {
    await show();
    expect(screen.getByText("4 recordings from your phone, this computer and the web.")).toBeInTheDocument();
    const list = screen.getByRole("list", { name: "Recordings" });
    expect([...list.querySelectorAll(".el-list-section")].map((h) => h.textContent)).toEqual(["Pinned · 1", "Today · 2", "Earlier · 1"]);
    const today = within(list).getByRole("button", { name: "Today · 2" });
    expect(today).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(today);
    expect(rows()).toEqual(["c", "d"]);
    fireEvent.click(within(list).getByRole("button", { name: "Today · 2" }));
    expect(rows()).toEqual(["c", "a", "b", "d"]);
  });

  it("Views ▾ has classic's smart views with counts; a filled chip and Clear all", async () => {
    await show();
    fireEvent.click(chip(/^Views$/));
    const menu = screen.getByRole("menu", { name: "Views" });
    expect(within(menu).getAllByRole("menuitemradio").map((m) => m.textContent)).toEqual([
      "All recordings4", "Today2", "This week3", "Untranscribed2", "Waiting for decision0", "Pinned1", "Bookmarked1", "Failed1",
    ]);
    expect(within(menu).getByRole("menuitemradio", { name: /^All recordings/ })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(within(menu).getByRole("menuitemradio", { name: /^Bookmarked/ }));
    expect(rows()).toEqual(["d"]);
    expect(chip(/^Bookmarked$/)).toHaveClass("el-chip--on");
    fireEvent.click(screen.getByRole("button", { name: "Clear all" }));
    expect(rows()).toHaveLength(4);
    expect(screen.queryByRole("button", { name: "Clear all" })).toBeNull();
  });

  it("filter chips: several ticks stay open in one menu, filters narrow each other, × clears one", async () => {
    await show();
    fireEvent.click(chip(/^Source$/));
    fireEvent.click(screen.getByRole("menuitemcheckbox", { name: /^WhatsApp/ }));
    // The menu stays open for the next tick.
    fireEvent.click(screen.getByRole("menuitemcheckbox", { name: /^Zoom/ }));
    expect(screen.getByRole("menuitemcheckbox", { name: /^Zoom/ })).toHaveAttribute("aria-checked", "true");
    fireEvent.keyDown(screen.getByRole("menu", { name: "Source" }), { key: "Escape" });
    expect(rows()).toEqual(["c", "a", "b"]);
    expect(chip(/^Source · 2$/)).toHaveClass("el-chip--on");
    tick(/^Project$/, /^No project/);
    expect(rows()).toEqual(["b"]);
    expect(screen.getByText("1 recording shown")).toBeInTheDocument();
    tick(/^Recorded on$/, /^Phone/);
    expect(screen.getByRole("region", { name: "No recordings match" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Clear Recorded on" }));
    expect(rows()).toEqual(["b"]);
    tick(/^Label$/, /^Q4/);
    expect(screen.getByRole("region", { name: "No recordings match" })).toBeInTheDocument();
    fireEvent.click(within(screen.getByRole("region", { name: "No recordings match" })).getByRole("button", { name: "Clear filters" }));
    expect(rows()).toHaveLength(4);
    tick(/^Status$/, /^Needs you/);
    expect(rows()).toEqual(["d"]);
    fireEvent.keyDown(document.body, { key: "Escape" });
    tick(/^Bookmark tag$/, /^Price/);
    expect(rows()).toEqual(["d"]);
    tick(/^Language$/, /^Automatic/);
    expect(rows()).toEqual(["d"]);
  });

  it("Sort and group: classic's sorts and groups, remembered per view on this computer", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    const menu = screen.getByRole("menu", { name: "Sort and group" });
    expect(within(menu).getAllByRole("menuitemradio").map((m) => m.textContent)).toEqual([
      "Newest", "Oldest", "Longest", "Shortest", "Name A–Z", "Recently opened", "Date", "Source", "Project", "Language", "Status", "Device", "None",
    ]);
    fireEvent.click(within(menu).getByRole("menuitemradio", { name: "Longest" }));
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "None" }));
    expect(rows()).toEqual(["b", "d", "a", "c"]);
    expect(screen.getByRole("list", { name: "Recordings" }).querySelectorAll(".el-list-section")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Project" }));
    expect([...document.querySelectorAll(".el-list-section")].map((h) => h.textContent)).toEqual(["Acme · 2", "No project · 2"]);
    // Another view has its own; coming back finds this one.
    fireEvent.click(chip(/^Views$/));
    fireEvent.click(screen.getByRole("menuitemradio", { name: /^This week/ }));
    expect([...document.querySelectorAll(".el-list-section")].map((h) => h.textContent)).toEqual(["Pinned · 1", "Today · 2"]);
    fireEvent.click(chip(/^This week$/));
    fireEvent.click(screen.getByRole("menuitemradio", { name: /^All recordings/ }));
    expect([...document.querySelectorAll(".el-list-section")].map((h) => h.textContent)).toEqual(["Acme · 2", "No project · 2"]);
    expect(JSON.parse(window.localStorage.getItem("echoledger-library-view") ?? "{}").all).toEqual({ group: "project", sort: "longest" });
  });

  it("in selection mode a section's box picks it, all or some, and clears it", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Select" }));
    const box = screen.getByRole("checkbox", { name: "Select all in Today" });
    fireEvent.click(box);
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("2 selected");
    fireEvent.click(screen.getByRole("checkbox", { name: "Clear Today" }));
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("Select recordings");
    fireEvent.click(screen.getByRole("button", { name: "Ali" }));
    expect((screen.getByRole("checkbox", { name: "Select all in Today" }) as HTMLInputElement).indeterminate).toBe(true);
  });

  it("Sync reads the list from the server again; \"/\" puts the cursor in search", async () => {
    await show();
    const before = callsOf("library_refresh").length;
    respond("library_refresh", ITEMS.slice(0, 2));
    fireEvent.click(screen.getByRole("button", { name: "Sync" }));
    await act(flushAsync);
    expect(callsOf("library_refresh").length).toBe(before + 1);
    expect(rows()).toEqual(["a", "b"]);
    fireEvent.keyDown(document.body, { key: "/" });
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveFocus();
  });

  it("Recently opened puts the recordings opened here first", async () => {
    respond("session_detail", { playback_url: null });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Dawood" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Library" }));
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Recently opened" }));
    fireEvent.click(screen.getByRole("button", { name: "Sort and group" }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: "None" }));
    expect(rows()[0]).toBe("d");
  });
});
