// The way back to all recordings (PLAN_NAV_3_LEVELS §0): "All recordings" first and chosen at the start; from every
// view, the sidebar's "All recordings" goes back to All recordings with the filters and the search cleared (the rail's
// Library pressed again does the same: navShell.test.tsx); Clear all whenever a filter is on; Back and Forward (⌘[ ⌘],
// the mouse's side buttons) walk the places shown with their filters and search. Since v2.1 there are no breadcrumbs:
// the title row says where (docs/PLAN_V2_1_POLISH.md UX-05, UX-20).
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import type { LibraryItem, ListItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { LibraryFoot, LibraryNav } from "./LibraryNav";
import { libraryViewCounts, prepare } from "./facets";
import { viewsStore } from "./myViews";
import { ALL_SCOPE, scopeKey, type LibraryScope } from "./scope";
import { resetSyncClock } from "./syncClock";
import { filterBySource } from "../../test/library";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const none = { final_status: null, final_transcript_id: null, live_available: false, preview: null };
const at = (hour: number) => new Date(2026, 10, 17, hour).toISOString();
const ITEMS: LibraryItem[] = [
  makeItem("wa", { title: "Bilal on WhatsApp", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: at(11), labels: ["Clients"], opened: false, important: true }),
  makeItem("zo", { title: "Weekly Zoom", source_app: "zoom", source_app_label: "Zoom", started_at: at(10), status: "audio_only", transcript: none }),
  makeItem("tm", { title: "Teams call", source_app: "microsoft_teams", started_at: at(9), status: "failed", transcript: { ...none, final_status: "failed" } }),
];
const LISTS: ListItem[] = [{ key: "clients", name: "Clients", color: "#26978f", position: 0, count: 1 }];
const ALL = ["wa", "zo", "tm"];

/** The sidebar, its foot and the Library as MainApp joins them. */
function App() {
  const [scope, setScope] = useState<LibraryScope>(ALL_SCOPE);
  const [request, setRequest] = useState<{ scope: LibraryScope; seq: number } | null>(null);
  const [seen, setSeen] = useState<{ items: readonly LibraryItem[]; read: ReadonlySet<string> } | null>(null);
  const facts = seen ? prepare(seen.items, { read: seen.read }) : null;
  const labelCounts: Record<string, number> = {};
  for (const f of facts?.facts ?? []) for (const key of f.values.labels) labelCounts[key] = (labelCounts[key] ?? 0) + 1;
  const open = (next: LibraryScope) => {
    setScope(next);
    setRequest((r) => ({ scope: next, seq: (r?.seq ?? 0) + 1 }));
  };
  return (
    <>
      <nav aria-label="Main">
        <LibraryNav current={scopeKey(scope)} counts={facts ? libraryViewCounts(facts.facts) : null} labelCounts={labelCounts} onScope={open}
          onNewView={() => undefined} say={() => undefined} onLibraryChanged={() => undefined} />
        <LibraryFoot trashCurrent={scope.kind === "trash"} onTrash={() => open({ kind: "trash" })} onSync={() => undefined} />
      </nav>
      <LibraryV2 scopeRequest={request} onScope={setScope} onItems={(items, read) => setSeen({ items, read })} />
    </>
  );
}
const nav = () => within(screen.getByRole("navigation", { name: "Main" }));
const rows = () => [...(screen.queryByRole("list", { name: "Recordings" })?.querySelectorAll<HTMLButtonElement>("button[data-row-id]") ?? [])].map((b) => b.dataset.rowId);
const title = () => screen.getByRole("heading", { level: 1 });
const search = () => screen.queryByRole("searchbox", { name: "Search recordings" });
/** The list shows every recording, nothing filtered or searched, and the sidebar marks All recordings. */
const atAll = () => {
  expect(title()).toHaveTextContent(/^All recordings/);
  expect(nav().getByRole("button", { name: /^All recordings, / })).toHaveAttribute("aria-current", "page");
  expect(rows()).toEqual(ALL);
  expect(search()).toHaveValue("");
  expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
};
const settle = () => act(flushAsync);
/** Past the time a search takes to become one step of Back. */
const searchStep = () => act(() => new Promise((r) => setTimeout(r, 750)));

describe("the way back to all recordings", () => {
  beforeEach(() => {
    resetTauriMock();
    resetSyncClock();
    window.localStorage.clear();
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("library_folder", []);
    respond("bin_list", { data: [] });
    respond("lists_get", LISTS);
    respondWith("views_get", () => Promise.reject("offline"));
    respondWith("library_views_version", () => Promise.reject("offline"));
    respondWith("view_create", () => Promise.reject("offline"));
    viewsStore.use(null);
    viewsStore.use("https://ledger.example.test|me@example.test|1");
  });

  it("All recordings is first in the Library and chosen at the start; no breadcrumbs, the title says where", async () => {
    render(<App />);
    await settle();
    const views = within(nav().getByRole("list", { name: "Library views" })).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent);
    expect(views[0]).toBe("All recordings, 3");
    expect(screen.queryByRole("navigation", { name: "Breadcrumbs" })).toBeNull();
    atAll();
    // No "Inbox" anywhere: recordings in no project are Project › No project.
    expect(document.body).not.toHaveTextContent(/Inbox/);
  });

  it("from every view, the panel's All recordings goes back to All recordings, filters and search cleared", async () => {
    render(<App />);
    await settle();
    await act(async () => {
      await viewsStore.create("WhatsApp", { filters: { source: { op: "is", values: ["whatsapp"] } }, search: null, sort: "newest", group: "date" });
    });
    await settle();
    const places: [string, () => HTMLElement][] = [
      ["Needs you", () => nav().getByRole("button", { name: /^Needs you, / })],
      ["Unread", () => nav().getByRole("button", { name: /^Unread, / })],
      ["Important", () => nav().getByRole("button", { name: /^Important, / })],
      ["Audio only", () => nav().getByRole("button", { name: /^Audio only, / })],
      ["WhatsApp", () => within(nav().getByRole("list", { name: "My views" })).getByRole("button", { name: "WhatsApp" })],
      ["Clients", () => within(nav().getByRole("list", { name: "Labels" })).getByRole("button", { name: /^Clients, / })],
      ["Trash", () => nav().getByRole("button", { name: "Trash" })],
    ];
    for (const [name, place] of places) {
      for (let twice = 0; twice < 2; twice++) {
        fireEvent.click(place());
        await settle();
        expect(title(), name).toHaveTextContent(new RegExp(`^${name}`));
        if (search()) {
          // A filter and a search on as well.
          fireEvent.change(search()!, { target: { value: "zz" } });
          if (!screen.queryByTestId("filter-panel")) fireEvent.click(screen.getByRole("button", { name: /^Filters/ }));
          const source = within(within(screen.getByTestId("filter-panel")).getByRole("region", { name: "Source" }));
          fireEvent.click(source.getAllByRole("checkbox")[0]);
          await act(() => new Promise((r) => setTimeout(r, 200)));
          expect(screen.getByRole("group", { name: "Filters on" })).toBeInTheDocument();
        }
        // The panel's All recordings (the rail's Library pressed again does the same: navShell.test.tsx).
        fireEvent.click(nav().getByRole("button", { name: /^All recordings, / }));
        await settle();
        atAll();
      }
    }
  });

  it("a built-in view's menu (UX-22): Open; Unread's Mark all as read; Audio only's Transcribe all…", async () => {
    respond("session_opened", null);
    respond("sessions_transcribe_estimate", { minutes: 5, minutes_left: 300, items: [] });
    render(<App />);
    await settle();
    const words = () => [...screen.getByRole("menu").querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
    fireEvent.contextMenu(nav().getByRole("button", { name: /^All recordings, / }));
    expect(words()).toEqual(["Open"]);
    fireEvent.keyDown(screen.getByRole("menu"), { key: "Escape" });
    fireEvent.contextMenu(nav().getByRole("button", { name: /^Unread, / }));
    expect(words()).toEqual(["Open", "Mark all as read"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Mark all as read" }));
    await settle();
    expect(callsOf("session_opened")).toEqual([{ id: "wa" }]);
    expect(nav().getByRole("button", { name: /^Unread, / })).toHaveAccessibleName("Unread, 0");
    fireEvent.contextMenu(nav().getByRole("button", { name: /^Audio only, / }));
    expect(words()).toEqual(["Open", "Transcribe all…"]);
    fireEvent.click(screen.getByRole("menuitem", { name: "Transcribe all…" }));
    await settle();
    expect(screen.getByRole("dialog", { name: /^Transcribe/ })).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("dialog", { name: /^Transcribe/ }), { key: "Escape" });
    fireEvent.contextMenu(nav().getByRole("button", { name: /^Important, / }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Open" }));
    await settle();
    expect(title()).toHaveTextContent(/^Important/);
  });

  it("Clear all shows whenever a filter is on, and clears every one", async () => {
    render(<App />);
    await settle();
    await filterBySource("WhatsApp");
    const pills = within(screen.getByRole("group", { name: "Filters on" }));
    fireEvent.click(pills.getByRole("button", { name: "Clear all" }));
    atAll();
  });

  it("Back and Forward (⌘[ ⌘], the mouse's side buttons) walk the places with their filters and search", async () => {
    render(<App />);
    await settle();
    const back = () => fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    const forward = () => fireEvent.keyDown(document.body, { key: "]", ctrlKey: true });
    // All → Unread → Unread with Source: WhatsApp → Audio only, searched.
    fireEvent.click(nav().getByRole("button", { name: /^Unread, / }));
    await settle();
    await filterBySource("WhatsApp");
    await settle();
    fireEvent.click(nav().getByRole("button", { name: /^Audio only, / }));
    await settle();
    fireEvent.change(search()!, { target: { value: "weekly" } });
    await searchStep();
    expect(rows()).toEqual(["zo"]);
    back();
    await settle();
    expect(title()).toHaveTextContent(/^Audio only/);
    expect(search()).toHaveValue("");
    back();
    await settle();
    expect(title()).toHaveTextContent(/^Unread/);
    expect(within(screen.getByRole("group", { name: "Filters on" })).getByRole("button", { name: "Source: WhatsApp" })).toBeInTheDocument();
    expect(nav().getByRole("button", { name: /^Unread, / })).toHaveAttribute("aria-current", "page");
    // The mouse's back button, then its forward button.
    fireEvent.mouseDown(window, { button: 3 });
    fireEvent.mouseUp(window, { button: 3 });
    await settle();
    expect(title()).toHaveTextContent(/^Unread/);
    expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
    back();
    await settle();
    atAll();
    // Nothing further back: it stays.
    back();
    await settle();
    atAll();
    forward();
    await settle();
    expect(title()).toHaveTextContent(/^Unread/);
    fireEvent.mouseUp(window, { button: 4 });
    await settle();
    expect(screen.getByRole("group", { name: "Filters on" })).toBeInTheDocument();
    forward();
    forward();
    await settle();
    expect(title()).toHaveTextContent(/^Audio only/);
    expect(search()).toHaveValue("weekly");
    expect(rows()).toEqual(["zo"]);
    // A new place after going back drops what was ahead.
    back();
    await settle();
    fireEvent.click(nav().getByRole("button", { name: /^Important, / }));
    await settle();
    forward();
    await settle();
    expect(title()).toHaveTextContent(/^Important/);
  });

  it("⌘[ and ⌘] while typing (the search, the panel's search) are the field's; the mouse's buttons wait behind a question", async () => {
    render(<App />);
    await settle();
    fireEvent.click(nav().getByRole("button", { name: /^Unread, / }));
    await settle();
    fireEvent.click(nav().getByRole("button", { name: /^Important, / }));
    await settle();
    const box = search()!;
    box.focus();
    fireEvent.keyDown(box, { key: "[", metaKey: true });
    fireEvent.keyDown(box, { key: "]", ctrlKey: true });
    await settle();
    expect(title()).toHaveTextContent(/^Important/);
    // A question open: the mouse's back button does nothing.
    const question = document.createElement("div");
    question.setAttribute("role", "alertdialog");
    document.body.appendChild(question);
    fireEvent.mouseUp(window, { button: 3 });
    await settle();
    expect(title()).toHaveTextContent(/^Important/);
    question.remove();
    fireEvent.mouseUp(window, { button: 3 });
    await settle();
    expect(title()).toHaveTextContent(/^Unread/);
  });

  it("two quick presses step twice, and Forward walks them back", async () => {
    render(<App />);
    await settle();
    for (const name of [/^Unread, /, /^Important, /, /^Audio only, /]) {
      fireEvent.click(nav().getByRole("button", { name }));
      await settle();
    }
    // Twice before the Library has settled.
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    expect(title()).toHaveTextContent(/^Unread/);
    fireEvent.keyDown(document.body, { key: "]", metaKey: true });
    await settle();
    expect(title()).toHaveTextContent(/^Important/);
    fireEvent.keyDown(document.body, { key: "]", metaKey: true });
    await settle();
    expect(title()).toHaveTextContent(/^Audio only/);
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    atAll();
  });

  it("a view deleted since (on another device) falls back to All recordings, and the history goes on from there", async () => {
    render(<App />);
    await settle();
    const made = await act(async () => viewsStore.create("WhatsApp", { filters: { source: { op: "is", values: ["whatsapp"] } }, search: null, sort: "newest", group: "date" }));
    await settle();
    fireEvent.click(within(nav().getByRole("list", { name: "My views" })).getByRole("button", { name: "WhatsApp" }));
    await settle();
    expect(title()).toHaveTextContent(/^WhatsApp/);
    fireEvent.click(nav().getByRole("button", { name: /^Unread, / }));
    await settle();
    await act(async () => viewsStore.remove(made.id));
    await settle();
    // Back to the view that is gone: All recordings.
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    atAll();
    // The history still records: a new place, Back, Forward.
    fireEvent.click(nav().getByRole("button", { name: /^Important, / }));
    await settle();
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    atAll();
    fireEvent.keyDown(document.body, { key: "]", metaKey: true });
    await settle();
    expect(title()).toHaveTextContent(/^Important/);
  });

  it("a late fall back (the view shown is deleted then) leaves no step alike or gone: the next Back changes the place", async () => {
    render(<App />);
    await settle();
    const made = await act(async () => viewsStore.create("WhatsApp", { filters: { source: { op: "is", values: ["whatsapp"] } }, search: null, sort: "newest", group: "date" }));
    await settle();
    fireEvent.click(nav().getByRole("button", { name: /^Important, / }));
    await settle();
    fireEvent.click(within(nav().getByRole("list", { name: "My views" })).getByRole("button", { name: "WhatsApp" }));
    await settle();
    fireEvent.click(nav().getByRole("button", { name: /^Unread, / }));
    await settle();
    // Back to the view, settled there; then it is deleted on another device and the Library falls back.
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    await act(() => new Promise((r) => setTimeout(r, 20)));
    expect(title()).toHaveTextContent(/^WhatsApp/);
    await act(async () => viewsStore.remove(made.id));
    await settle();
    atAll();
    // One Back: Important (not the gone view, not All recordings again).
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    expect(title()).toHaveTextContent(/^Important/);
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    atAll();
    // Nothing further back.
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    atAll();
  });

  it("with a recording open, Back goes back to the list first", async () => {
    respond("session_detail", { playback_url: null });
    render(<App />);
    await settle();
    fireEvent.click(nav().getByRole("button", { name: /^Unread, / }));
    await settle();
    fireEvent.click(screen.getByRole("button", { name: "Bilal on WhatsApp" }));
    await settle();
    expect(screen.queryByRole("list", { name: "Recordings" })).toBeNull();
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    expect(title()).toHaveTextContent(/^Unread/);
    fireEvent.keyDown(document.body, { key: "[", metaKey: true });
    await settle();
    atAll();
  });
});
