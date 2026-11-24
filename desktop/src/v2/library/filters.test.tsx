// Find in the v2 Library (PLAN_LIBRARY_FILTERS; docs/v2/audit/parity.csv DT-LIB-001..059): one toolbar row (Search,
// [Filters n], [Sort & display ▾]); the filters (F, Esc; remembered; from the end side, pushing the list from 1440 px, over it below) with its sections,
// live counts (an option at 0 dimmed, still a choice), a search box in a long section and each section's Clear, the
// list following 150 ms after the last tick; the pills row only while filters are on (edit with "is" or "is not",
// × removes one, Clear all); a row's source logo or label dot as a quick filter; the keys; sections with counts that
// fold; a section's box in selection; Sort, Group by and Density remembered on this computer.
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { sections } from "./filters";
import { DRAWER_BELOW, FILTER_DEBOUNCE_MS, LibraryV2 } from "./LibraryV2";
import { LibraryFoot } from "./LibraryNav";
import { resetSyncClock } from "./syncClock";

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

/** The rows shown (none when nothing matches). */
const rows = () => [...(screen.queryByRole("list", { name: "Recordings" })?.querySelectorAll<HTMLButtonElement>("button[data-row-id]") ?? [])].map((b) => b.dataset.rowId);
const headings = () => [...document.querySelectorAll(".el-list-section")].map((h) => h.textContent);
const panel = () => within(screen.getByTestId("filter-panel"));
const section = (name: string) => within(panel().getByRole("region", { name }));
/** A section's options as shown: "WhatsApp2". */
const options = (name: string) => section(name).queryAllByRole(name === "Date" ? "radio" : "checkbox").map((c) => c.closest("label")!.textContent);
const option = (sectionName: string, label: RegExp) => section(sectionName).getByRole(sectionName === "Date" ? "radio" : "checkbox", { name: label });
const pills = () => within(screen.getByRole("group", { name: "Filters on" }));
const filtersButton = () => screen.getByRole("button", { name: /^Filters/ });
/** The list follows the panel 150 ms after the last tick. */
const followed = () => act(() => new Promise((r) => setTimeout(r, FILTER_DEBOUNCE_MS + 30)));
const display = (name: string) => {
  fireEvent.click(screen.getByRole("button", { name: "Sort & display" }));
  fireEvent.click(screen.getByRole("menuitemradio", { name }));
};
const setWidth = (width: number) => {
  Object.defineProperty(window, "innerWidth", { value: width, configurable: true, writable: true });
  window.dispatchEvent(new Event("resize"));
};

describe("the sections", () => {
  it("Pinned first in every grouping, then the days or the groups; any sort", () => {
    expect(sections(ITEMS, "date", "newest", {}, true, NOW).map((g) => [g.label, g.items.map((i) => i.id)])).toEqual([
      ["Pinned", ["c"]], ["Today", ["a", "b"]], ["Earlier", ["d"]],
    ]);
    // Only pinned ones shown already (Marked is Pinned): no Pinned section of their own.
    expect(sections(ITEMS, "date", "newest", {}, false, NOW).map((g) => g.label)).toEqual(["Today", "Yesterday", "Earlier"]);
    expect(sections(ITEMS, "app", "longest", {}, true, NOW).map((g) => [g.label, g.items.map((i) => i.id)])).toEqual([
      ["Pinned", ["c"]], ["Voice notes", ["d"]], ["WhatsApp", ["a"]], ["Zoom", ["b"]],
    ]);
    // Ungrouped, the rest has a heading of its own under Pinned.
    expect(sections(ITEMS, "none", "title", {}, true, NOW).map((g) => [g.label, g.items.map((i) => i.id)])).toEqual([["Pinned", ["c"]], ["Recordings", ["a", "b", "d"]]]);
    expect(sections(ITEMS, "none", "opened", { d: 5, b: 9 }, false, NOW)[0].items.map((i) => i.id)).toEqual(["b", "d", "a", "c"]);
  });

  it("Name A–Z with Urdu and mixed-script titles: Latin first whatever the case, then Urdu in its own order", () => {
    const names = ["علی", "Bilal", "ali 2", "بلال", "Ali", "Zeeshan", "آصف", "Ahmad علی"].map((title, i) => makeItem(`n${i}`, { title, started_at: at(18, 9) }));
    expect(sections(names, "none", "title", {}, true, NOW)[0].items.map((i) => i.title)).toEqual([
      "Ahmad علی", "Ali", "ali 2", "Bilal", "Zeeshan", "آصف", "بلال", "علی",
    ]);
  });
});

describe("Find in the Library", () => {
  beforeEach(() => {
    resetTauriMock();
    resetSyncClock();
    window.localStorage.clear();
    setWidth(1440);
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("library_folder", []);
    respond("bin_list", { data: [] });
  });
  afterEach(() => {
    vi.useRealTimers();
    setWidth(1024);
  });
  const show = async () => {
    const view = render(<LibraryV2 />);
    await act(flushAsync);
    return view;
  };

  it("one toolbar row: the title with its count, Search, Filters and Sort & display; no pills while nothing is filtered", async () => {
    await show();
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(/^All recordings\s*4$/);
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveAttribute("aria-keyshortcuts", "Meta+F Control+F /");
    // The placeholder names the place, the key at its end (UX-20).
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveAttribute("placeholder", expect.stringMatching(/^Search recordings (⌘|Ctrl\+)F$/));
    expect(filtersButton()).toHaveAttribute("aria-expanded", "false");
    expect(filtersButton()).toHaveAttribute("aria-keyshortcuts", "F");
    expect(screen.getByRole("button", { name: "Sort & display" })).toHaveAttribute("aria-haspopup", "menu");
    expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
    // No Select button and no Sync button: a row's box starts a selection; the sidebar's "Synced" line syncs.
    expect(screen.queryByRole("button", { name: "Select" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Sync" })).toBeNull();
    // Narrower, the search says less (its box is shorter).
    act(() => setWidth(1100));
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveAttribute("placeholder", expect.stringMatching(/^Search recordings (⌘|Ctrl\+)F$/));
    act(() => setWidth(900));
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveAttribute("placeholder", expect.stringMatching(/^Search (⌘|Ctrl\+)F$/));
  });

  it("sections with counts that fold and unfold", async () => {
    await show();
    const list = screen.getByRole("list", { name: "Recordings" });
    expect(headings()).toEqual(["Pinned · 1", "Today · 2", "Earlier · 1"]);
    const today = within(list).getByRole("button", { name: "Today · 2" });
    expect(today).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(today);
    expect(rows()).toEqual(["c", "d"]);
    fireEvent.click(within(list).getByRole("button", { name: "Today · 2" }));
    expect(rows()).toEqual(["c", "a", "b", "d"]);
  });

  it("Sort & display ▾: List or Table, classic's sorts and groups, and Density; remembered on this computer", async () => {
    const { unmount } = await show();
    fireEvent.click(screen.getByRole("button", { name: "Sort & display" }));
    const menu = screen.getByRole("menu", { name: "Sort & display" });
    expect(within(menu).getAllByRole("menuitemradio").map((m) => m.textContent)).toEqual([
      "List", "Table",
      "Newest", "Oldest", "Longest", "Name A–Z", "Shortest", "Recently opened",
      "Date", "Source", "Project", "None", "Language", "Status", "Device",
      // Density, shared with Settings › Appearance (classic's View menu).
      "Comfortable", "Compact",
    ]);
    fireEvent.click(within(menu).getByRole("menuitemradio", { name: "Longest" }));
    display("None");
    expect(rows()).toEqual(["c", "b", "d", "a"]);
    expect(headings()).toEqual(["Pinned · 1", "Recordings · 3"]);
    display("Project");
    expect(headings()).toEqual(["Pinned · 1", "Acme · 1", "No project · 2"]);
    expect(JSON.parse(window.localStorage.getItem("echoledger-library-view") ?? "{}").all).toEqual({ group: "project", sort: "longest" });
    unmount();
    await show();
    expect(headings()).toEqual(["Pinned · 1", "Acme · 1", "No project · 2"]);
  });

  it("Recently opened puts the recordings opened here first", async () => {
    respond("session_detail", { playback_url: null });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Dawood" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Library" }));
    display("Recently opened");
    display("None");
    // Pinned still comes first; then the one opened here.
    expect(rows().slice(0, 2)).toEqual(["c", "d"]);
  });

  it("[Filters] opens the panel and closes it; it stays as it was left", async () => {
    const { unmount } = await show();
    fireEvent.click(filtersButton());
    expect(filtersButton()).toHaveAttribute("aria-expanded", "true");
    expect(panel().getAllByRole("region").map((r) => r.getAttribute("aria-label"))).toEqual(
      ["Date", "Source", "Device", "Project", "Language", "Status", "Labels", "Marked", "Bookmarks"]);
    // A column beside the list, not a dialog over it.
    expect(screen.getByTestId("filter-panel")).not.toHaveAttribute("role", "dialog");
    expect(panel().getByRole("button", { name: "Close filters" })).toHaveAttribute("aria-keyshortcuts", "Escape F");
    unmount();
    await show();
    expect(screen.getByTestId("filter-panel")).toBeInTheDocument();
    fireEvent.click(panel().getByRole("button", { name: "Close filters" }));
    expect(screen.queryByTestId("filter-panel")).toBeNull();
    expect(window.localStorage.getItem("echoledger-library-filters-open")).toBe("0");
  });

  it("each section's options with live counts; a tick narrows the list 150 ms later and the other sections count what it keeps", async () => {
    await show();
    fireEvent.click(filtersButton());
    expect(options("Date")).toEqual(["Any time", "Today2", "Last 7 days3", "This month4", "Custom"]);
    expect(options("Source")).toEqual(["WhatsApp2", "Voice notes1", "Zoom1"]);
    expect(options("Device")).toEqual(["Phone2", "Desktop2"]);
    expect(options("Project")).toEqual(["Acme2", "No project2"]);
    expect(options("Language")).toEqual(["Urdu2", "English1", "Unknown1"]);
    expect(options("Status")).toEqual(["Transcribed2", "Audio only1", "In progress0", "Failed1"]);
    expect(options("Labels")).toEqual(["Q41"]);
    expect(options("Marked")).toEqual(["Pinned1", "Important0", "Keep forever0"]);
    expect(options("Bookmarks")).toEqual(["Any bookmark1", "Price1"]);
    fireEvent.click(option("Source", /^WhatsApp/));
    // The panel shows the tick at once; the list and the counts follow after the debounce.
    expect(option("Source", /^WhatsApp/)).toBeChecked();
    expect(rows()).toHaveLength(4);
    await followed();
    expect(rows()).toEqual(["c", "a"]);
    expect(filtersButton()).toHaveTextContent("Filters1");
    // Source still offers every source with its own count; the others count WhatsApp's two.
    expect(options("Source")).toEqual(["WhatsApp2", "Voice notes1", "Zoom1"]);
    expect(options("Device")).toEqual(["Phone2", "Desktop0"]);
    // Ticks inside a section add up; sections narrow each other.
    fireEvent.click(option("Source", /^Zoom/));
    await followed();
    expect(rows()).toEqual(["c", "a", "b"]);
    fireEvent.click(option("Project", /^No project/));
    await followed();
    expect(rows()).toEqual(["b"]);
    expect(pills().getAllByRole("button", { name: /^Remove / }).map((b) => b.getAttribute("aria-label"))).toEqual(
      ["Remove Source: WhatsApp, Zoom", "Remove Project: No project"]);
  });

  it("the page's tabs (All · Calls · Meetings · Voice notes) by Source; the panel's counts are counted inside the tab, so they match the list", async () => {
    await show();
    const tabs = () => within(screen.getByRole("tablist", { name: "Kinds of recording" }));
    expect(tabs().getAllByRole("tab").map((t) => t.textContent)).toEqual(["All4", "Calls2", "Meetings1", "Voice notes1"]);
    fireEvent.click(tabs().getByRole("tab", { name: /^Calls/ }));
    expect(rows()).toEqual(["c", "a"]);
    fireEvent.click(filtersButton());
    // Only the calls are counted: Zoom (a meeting) and the voice note are 0 here.
    expect(options("Source")).toEqual(["WhatsApp2", "Voice notes0", "Zoom0"]);
    expect(options("Device")).toEqual(["Phone2", "Desktop0"]);
    // An option's count is what the list shows with it.
    expect(options("Status")).toEqual(["Transcribed1", "Audio only1", "In progress0", "Failed0"]);
    fireEvent.click(option("Status", /^Audio only/));
    await followed();
    expect(rows()).toEqual(["c"]);
    expect(tabs().getByRole("tab", { name: /^Calls/ })).toHaveTextContent("Calls1");
    // Back to All: counted among everything again.
    fireEvent.click(tabs().getByRole("tab", { name: /^All/ }));
    expect(options("Source")).toEqual(["WhatsApp1", "Voice notes0", "Zoom0"]);
    fireEvent.click(section("Status").getByRole("button", { name: "Clear Status" }));
    await followed();
    expect(options("Source")).toEqual(["WhatsApp2", "Voice notes1", "Zoom1"]);
  });

  it("row 2: Other (an imported file, an app no kind names) shows only while it has recordings, and the tabs add up to All", async () => {
    const facetime = makeItem("e", { title: "Eman", source_app: "facetime", source_app_label: "FaceTime", source_device: "desktop", started_at: at(18, 8), duration_ms: 90_000, transcript: done("three") });
    respond("library_list", [...ITEMS, facetime]);
    respond("library_refresh", [...ITEMS, facetime]);
    await show();
    const tabs = () => within(screen.getByRole("tablist", { name: "Kinds of recording" }));
    // In row 2 (40 px), on its start side; Filters and Sort & display on its end.
    expect(screen.getByRole("tablist", { name: "Kinds of recording" }).closest(".el-lib-row2")).toContainElement(filtersButton());
    expect(tabs().getAllByRole("tab").map((t) => t.textContent)).toEqual(["All5", "Calls2", "Meetings1", "Voice notes1", "Other1"]);
    fireEvent.click(tabs().getByRole("tab", { name: /^Other/ }));
    expect(rows()).toEqual(["e"]);
    expect(tabs().getByRole("tab", { name: /^Other/ })).toHaveAttribute("aria-selected", "true");
    // A search that leaves Other empty keeps its tab while it is open, at 0.
    fireEvent.change(screen.getByRole("searchbox", { name: "Search recordings" }), { target: { value: "Ali" } });
    await followed();
    expect(tabs().getByRole("tab", { name: /^Other/ })).toHaveTextContent("Other0");
    fireEvent.click(tabs().getByRole("tab", { name: /^All/ }));
    expect(tabs().queryByRole("tab", { name: /^Other/ })).toBeNull();
  });

  it("an option at 0 is dimmed but can be ticked; each section's Clear; nothing matching says so with Clear filters", async () => {
    await show();
    fireEvent.click(filtersButton());
    fireEvent.click(option("Source", /^WhatsApp/));
    await followed();
    const desktop = option("Device", /^Desktop/);
    expect(desktop.closest("label")).toHaveClass("el-filter__option--none");
    expect(desktop).toBeEnabled();
    fireEvent.click(desktop);
    await followed();
    expect(screen.getByRole("region", { name: "No recordings match these filters" })).toBeInTheDocument();
    fireEvent.click(section("Device").getByRole("button", { name: "Clear Device" }));
    await followed();
    expect(rows()).toEqual(["c", "a"]);
    fireEvent.click(option("Labels", /^Q4/));
    fireEvent.click(option("Marked", /^Pinned/));
    await followed();
    fireEvent.click(within(screen.getByRole("region", { name: "No recordings match these filters" })).getByRole("button", { name: "Clear filters" }));
    await followed();
    expect(rows()).toHaveLength(4);
    expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
  });

  it("a long section has a search box of its own", async () => {
    const apps = ["whatsapp", "zoom", "google_meet", "microsoft_teams", "voice_note", "phone_call", "telegram", "signal"];
    const many = apps.map((app, i) => makeItem(`s${i}`, { source_app: app, started_at: at(18, 9) }));
    respond("library_list", many);
    respond("library_refresh", many);
    await show();
    fireEvent.click(filtersButton());
    const find = section("Source").getByRole("searchbox", { name: "Search source" });
    fireEvent.change(find, { target: { value: "te" } });
    // Anywhere in the name, whatever the case.
    expect(options("Source")).toEqual(["Teams1", "Telegram1", "Voice notes1"]);
    fireEvent.change(find, { target: { value: "xyz" } });
    expect(section("Source").getByText("No match")).toBeInTheDocument();
    // Short sections have none.
    expect(section("Device").queryByRole("searchbox")).toBeNull();
  });

  it("Date: one choice, Today, Last 7 days, This month or Custom with its two days; Any time clears it", async () => {
    await show();
    fireEvent.click(filtersButton());
    fireEvent.click(option("Date", /^Today/));
    await followed();
    expect(rows()).toEqual(["a", "b"]);
    fireEvent.click(option("Date", /^Custom/));
    fireEvent.change(section("Date").getByLabelText("From"), { target: { value: "2026-11-09" } });
    fireEvent.change(section("Date").getByLabelText("To"), { target: { value: "2026-11-11" } });
    await followed();
    expect(rows()).toEqual(["d"]);
    expect(pills().getByRole("button", { name: /^Remove Date: / })).toBeInTheDocument();
    fireEvent.click(option("Date", /^Any time/));
    await followed();
    expect(rows()).toHaveLength(4);
  });

  it("the pills: one per section in its words; a pill opens to edit it with is or is not; × removes it; Clear all", async () => {
    await show();
    fireEvent.click(filtersButton());
    fireEvent.click(option("Source", /^WhatsApp/));
    fireEvent.click(option("Status", /^Failed/));
    await followed();
    expect(rows()).toEqual([]);
    const edit = pills().getByRole("button", { name: "Source: WhatsApp" });
    fireEvent.click(edit);
    const editor = within(screen.getByRole("dialog", { name: "Edit Source" }));
    expect(editor.getByRole("radiogroup", { name: "Source is or is not" })).toBeInTheDocument();
    fireEvent.click(editor.getByRole("radio", { name: "is not" }));
    // A pill's change applies at once.
    expect(rows()).toEqual(["d"]);
    expect(pills().getByRole("button", { name: "Source is not: WhatsApp" })).toBeInTheDocument();
    // Its choices with their counts: another tick from the pill.
    fireEvent.click(editor.getByRole("checkbox", { name: /^Voice notes/ }));
    expect(rows()).toEqual([]);
    fireEvent.keyDown(screen.getByRole("dialog", { name: "Edit Source" }), { key: "Escape" });
    expect(screen.queryByRole("dialog", { name: "Edit Source" })).toBeNull();
    expect(pills().getByRole("button", { name: "Source is not: WhatsApp, Voice notes" })).toHaveFocus();
    fireEvent.click(pills().getByRole("button", { name: "Remove Status: Failed" }));
    expect(rows()).toEqual(["b"]);
    fireEvent.click(pills().getByRole("button", { name: "Clear all" }));
    expect(rows()).toHaveLength(4);
    expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
  });

  it("DT-LIB-022: Date › Today and Marked › Pinned narrow each other; ticks inside Marked add up", async () => {
    const list = [
      makeItem("tp", { title: "Today pinned", started_at: at(18, 10), pinned: true }),
      makeItem("t", { title: "Today only", started_at: at(18, 9) }),
      makeItem("p", { title: "Pinned last week", started_at: at(10, 9), pinned: true }),
      makeItem("ti", { title: "Important today", started_at: at(18, 8), important: true }),
    ];
    respond("library_list", list);
    respond("library_refresh", list);
    await show();
    expect(rows()).toEqual(["tp", "p", "t", "ti"]);
    fireEvent.click(filtersButton());
    fireEvent.click(option("Date", /^Today/));
    await followed();
    expect(rows()).toEqual(["tp", "t", "ti"]);
    fireEvent.click(option("Marked", /^Pinned/));
    await followed();
    expect(rows()).toEqual(["tp"]);
    fireEvent.click(option("Marked", /^Important/));
    await followed();
    expect(rows()).toEqual(["tp", "ti"]);
    expect(pills().getByRole("button", { name: "Date: Today" })).toBeInTheDocument();
    expect(pills().getByRole("button", { name: "Marked: Pinned, Important" })).toBeInTheDocument();
    fireEvent.click(section("Marked").getByRole("button", { name: "Clear Marked" }));
    await followed();
    expect(rows()).toEqual(["tp", "t", "ti"]);
  });

  it("the search finds every word among the title, the transcript and the project with the folders above it", async () => {
    respond("list_clients", { data: [{ id: "p0", name: "Holdings" }, { id: "c1", name: "Acme", parent_id: "p0" }] });
    await show();
    const search = screen.getByRole("searchbox", { name: "Search recordings" });
    // Ali and Chand are in Acme, under Holdings.
    fireEvent.change(search, { target: { value: "holdings" } });
    await act(flushAsync);
    expect(rows()).toEqual(["c", "a"]);
    fireEvent.change(search, { target: { value: "acme" } });
    await act(flushAsync);
    expect(rows()).toEqual(["c", "a"]);
    fireEvent.change(search, { target: { value: "two" } });
    await act(flushAsync);
    expect(rows()).toEqual(["b"]);
    fireEvent.change(search, { target: { value: "dawood" } });
    await act(flushAsync);
    expect(rows()).toEqual(["d"]);
    // The words in different places: Holdings (Ali's project's folder) and "one" (Ali's transcript).
    fireEvent.change(search, { target: { value: "holdings one" } });
    await act(flushAsync);
    expect(rows()).toEqual(["a"]);
  });

  it("a row's source logo or label dot filters by it at once", async () => {
    await show();
    fireEvent.click(screen.getAllByRole("button", { name: "Show only WhatsApp" })[0]);
    expect(rows()).toEqual(["c", "a"]);
    expect(pills().getByRole("button", { name: "Source: WhatsApp" })).toBeInTheDocument();
    // Pressed again, it stays.
    fireEvent.click(screen.getAllByRole("button", { name: "Show only WhatsApp" })[0]);
    expect(rows()).toEqual(["c", "a"]);
    fireEvent.click(screen.getByRole("button", { name: "Show only label Q4" }));
    expect(rows()).toEqual(["a"]);
    expect(pills().getByRole("button", { name: "Labels: Q4" })).toBeInTheDocument();
  });

  it("keys: F opens and closes the filters, Esc closes them, ⌘F and / put the cursor in search; never while typing", async () => {
    await show();
    const row = screen.getByRole("button", { name: "Ali" });
    row.focus();
    fireEvent.keyDown(row, { key: "f" });
    expect(screen.getByTestId("filter-panel")).toBeInTheDocument();
    fireEvent.keyDown(row, { key: "Escape" });
    expect(screen.queryByTestId("filter-panel")).toBeNull();
    fireEvent.keyDown(row, { key: "f" });
    fireEvent.keyDown(row, { key: "F" });
    expect(screen.queryByTestId("filter-panel")).toBeNull();
    const search = screen.getByRole("searchbox", { name: "Search recordings" });
    fireEvent.keyDown(document.body, { key: "f", metaKey: true });
    expect(search).toHaveFocus();
    // Typing an f in the search is a letter, not the panel.
    fireEvent.keyDown(search, { key: "f" });
    expect(screen.queryByTestId("filter-panel")).toBeNull();
    search.blur();
    row.focus();
    fireEvent.keyDown(row, { key: "/" });
    expect(search).toHaveFocus();
  });

  it("WCAG 2.1.4: F, /, J, K and X answer only while focus is in the Library, not anywhere in the window", async () => {
    render(<><nav aria-label="Elsewhere"><button type="button">Somewhere else</button></nav><LibraryV2 /></>);
    await act(flushAsync);
    const elsewhere = screen.getByRole("button", { name: "Somewhere else" });
    const search = screen.getByRole("searchbox", { name: "Search recordings" });
    for (const target of [elsewhere, document.body]) {
      if (target instanceof HTMLElement && target !== document.body) target.focus();
      for (const key of ["f", "F", "/", "j", "k", "x"]) fireEvent.keyDown(target, { key });
      expect(screen.queryByTestId("filter-panel")).toBeNull();
      expect(search).not.toHaveFocus();
      expect(screen.queryByRole("toolbar", { name: "Selected recordings" })).toBeNull();
      expect(document.activeElement?.closest("[data-row-id]")).toBeNull();
    }
    // ⌘F is not a one-letter key: it still finds the Library's search.
    elsewhere.focus();
    fireEvent.keyDown(elsewhere, { key: "f", metaKey: true });
    expect(search).toHaveFocus();
    // In the Library: J moves to a row, X ticks it, F opens the filters.
    const title = screen.getByRole("heading", { level: 1 });
    fireEvent.keyDown(title, { key: "j" });
    expect(document.activeElement?.closest("[data-row-id]")).not.toBeNull();
    fireEvent.keyDown(document.activeElement!, { key: "x" });
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("1 selected");
    fireEvent.keyDown(document.activeElement!, { key: "Escape" });
    fireEvent.keyDown(screen.getByRole("heading", { level: 1 }), { key: "f" });
    expect(screen.getByTestId("filter-panel")).toBeInTheDocument();
  });

  it("under 1440 px (the wide breakpoint token) the filters slide over the list from the end side and start closed; the scrim and Esc close it", async () => {
    // The width is the token's: tokens.css --el-breakpoint-wide.
    const tokens = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "..", "tokens.css"), "utf8");
    expect(tokens).toContain(`--el-breakpoint-wide: ${DRAWER_BELOW}px;`);
    window.localStorage.setItem("echoledger-library-filters-open", "1");
    setWidth(1100);
    await show();
    expect(screen.queryByTestId("filter-panel")).toBeNull();
    fireEvent.click(filtersButton());
    const drawer = screen.getByTestId("filter-panel");
    expect(drawer).toHaveAttribute("role", "dialog");
    expect(drawer).toHaveClass("el-filter--drawer");
    expect(document.querySelector(".el-filter-scrim")).not.toBeNull();
    // Modal: it takes focus; Tab and Shift+Tab stay inside; ⌘F and F leave the list behind it alone.
    expect(drawer).toHaveAttribute("aria-modal", "true");
    expect(drawer.contains(document.activeElement)).toBe(true);
    const inside = within(drawer);
    const close = inside.getByRole("button", { name: "Close filters" });
    // F is a letter while the drawer has focus (it is modal): only Escape is said to close it.
    expect(close).toHaveAttribute("aria-keyshortcuts", "Escape");
    const save = inside.getByRole("button", { name: "Save as view" });
    const last = [...drawer.querySelectorAll<HTMLElement>("button:not([disabled]), input:not([disabled])")].at(-1)!;
    last.focus();
    fireEvent.keyDown(last, { key: "Tab" });
    expect(close).toHaveFocus();
    fireEvent.keyDown(close, { key: "Tab", shiftKey: true });
    expect(last).toHaveFocus();
    expect(save).toBeDisabled();
    fireEvent.keyDown(last, { key: "f", metaKey: true });
    expect(last).toHaveFocus();
    fireEvent.keyDown(last, { key: "f" });
    expect(screen.getByTestId("filter-panel")).toBeInTheDocument();
    // A press on the scrim closes it; focus goes back to what opened it.
    fireEvent.mouseDown(document.querySelector(".el-filter-scrim")!);
    expect(screen.queryByTestId("filter-panel")).toBeNull();
    filtersButton().focus();
    fireEvent.click(filtersButton());
    expect(screen.getByTestId("filter-panel").contains(document.activeElement)).toBe(true);
    fireEvent.mouseDown(document.querySelector(".el-filter-scrim")!);
    expect(filtersButton()).toHaveFocus();
    fireEvent.click(filtersButton());
    fireEvent.keyDown(screen.getByTestId("filter-panel"), { key: "Escape" });
    expect(screen.queryByTestId("filter-panel")).toBeNull();
    // Wide again: a column beside the list.
    fireEvent.click(filtersButton());
    act(() => setWidth(1440));
    expect(screen.getByTestId("filter-panel")).not.toHaveAttribute("role", "dialog");
    expect(document.querySelector(".el-filter-scrim")).toBeNull();
  });

  it("in selection a section's box picks it, all or some, and clears it", async () => {
    await show();
    fireEvent.click(screen.getByRole("checkbox", { name: "Select Ali" }));
    expect((screen.getByRole("checkbox", { name: "Select all in Today" }) as HTMLInputElement).indeterminate).toBe(true);
    fireEvent.click(screen.getByRole("checkbox", { name: "Select all in Today" }));
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("2 selected");
    fireEvent.click(screen.getByRole("checkbox", { name: "Clear Today" }));
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("Select recordings");
  });

  it("with filters or a search on, Cmd+A, export, Copy for AI and Delete act only on the rows shown", async () => {
    respond("sessions_bulk", 2);
    respond("sessions_export", "/tmp/x.zip");
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("session_detail", { playback_url: null });
    respond("transcript_detail", { id: "t", kind: "final", provider: "x", status: "completed", language: "en", text: null, segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 1000, text: "Hello" }] });
    await show();
    fireEvent.click(screen.getAllByRole("button", { name: "Show only WhatsApp" })[0]);
    fireEvent.keyDown(document.body, { key: "a", metaKey: true });
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("2 selected");
    fireEvent.click(screen.getByRole("button", { name: "Share or export" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Text (.txt)" }));
    await act(flushAsync);
    expect(callsOf("sessions_export")).toEqual([{ ids: ["c", "a"], format: "txt", view: undefined }]);
    fireEvent.click(screen.getByRole("button", { name: "Share or export" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Copy for AI" }));
    await act(flushAsync);
    expect(callsOf("transcript_detail").map((c) => c.id)).toEqual(["tr-one"]);
    fireEvent.click(screen.getByRole("button", { name: "Cancel selection" }));
    // The search narrows further: Cmd+A takes only Ali; Delete takes only Ali.
    fireEvent.change(screen.getByRole("searchbox", { name: "Search recordings" }), { target: { value: "Ali" } });
    await act(flushAsync);
    fireEvent.keyDown(document.body, { key: "a", metaKey: true });
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("1 selected");
    respond("delete_session", null);
    fireEvent.click(within(screen.getByRole("toolbar", { name: "Selected recordings" })).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("delete_session")).toEqual([{ id: "a" }]);
    expect(callsOf("sessions_bulk")).toEqual([]);
  });

  it("a folded section's rows can't be reached: Cmd+A, Shift-range and the Delete key take only the rows on screen", async () => {
    respond("sessions_bulk", 2);
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Today · 2" }));
    fireEvent.keyDown(document.body, { key: "a", metaKey: true });
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("2 selected");
    // A folded section shows no box to pick it; unfolded, it does.
    expect(screen.queryByRole("checkbox", { name: /Today/ })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Today · 2" }));
    expect(screen.getByRole("checkbox", { name: "Select all in Today" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Today · 2" }));
    fireEvent.keyDown(document.body, { key: "Escape" });
    fireEvent.click(screen.getByRole("button", { name: "Chand" }), { metaKey: true });
    fireEvent.click(screen.getByRole("button", { name: "Dawood" }), { shiftKey: true });
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("2 selected");
    fireEvent.keyDown(screen.getByRole("button", { name: "Dawood" }), { key: "Delete" });
    // Both rows on screen: deleting everything shown asks first.
    fireEvent.click(within(screen.getByRole("alertdialog")).getByRole("button", { name: "Move to Trash" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["c", "d"], action: "delete", clientId: null, label: null }]);
    // Folding a section with picked rows drops them from the selection.
    fireEvent.click(screen.getByRole("button", { name: "Today · 2" }));
    fireEvent.click(screen.getByRole("button", { name: "Ali" }), { metaKey: true });
    fireEvent.click(screen.getByRole("button", { name: "Today · 2" }));
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("Select recordings");
  });

  it("the sidebar's Synced line syncs now; the Library reads the list again when the core says it changed", async () => {
    render(<><LibraryFoot trashCurrent={false} onTrash={() => undefined} onSync={() => void import("./syncClock").then((m) => m.syncLibrary())} /><LibraryV2 /></>);
    await act(flushAsync);
    const line = screen.getByTestId("sidebar-synced");
    const said = screen.getByTestId("sidebar-synced-said");
    expect(said).toHaveAttribute("role", "status");
    expect(line).toHaveTextContent("Synced just now");
    // The line is quiet; the Library's own syncs say nothing.
    expect(line).not.toHaveAttribute("aria-live");
    expect(said).toHaveTextContent("");
    const before = callsOf("library_refresh").length;
    respond("library_refresh", ITEMS.slice(0, 2));
    respond("library_list", ITEMS.slice(0, 2));
    fireEvent.click(line);
    await act(flushAsync);
    expect(callsOf("library_refresh").length).toBe(before + 1);
    // Pressed: how it went is said once.
    expect(said).toHaveTextContent("Synced just now");
    await emitEvent("library:updated", 2);
    await act(flushAsync);
    expect(rows()).toEqual(["a", "b"]);
    // A sync that fails is said, pressed or not.
    fail("library_refresh", "The server didn't answer.");
    await act(async () => {
      await import("./syncClock").then((m) => m.syncLibrary().catch(() => undefined));
    });
    await act(flushAsync);
    expect(said).toHaveTextContent("Couldn't sync: The server didn't answer.");
  });
});
