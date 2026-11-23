// Labels (D14, contract 2.0.0-m; PLAN_LIBRARY_FILTERS: lists ARE labels) in the sidebar's LABELS section and the
// Library: each label with its colour and count, choosing one, an empty one, "+ New label" and its dialog, Labels ▸ in a
// recording's ⋮ and right-click, and a label's menu (Rename with the duplicate check, Change colour, Move up / Move
// down, Delete with confirm). The Library's views (Unread here) are the sidebar's LIBRARY section.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { useState } from "react";
import type { LibraryItem, ListItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { LibraryNav } from "./LibraryNav";
import { libraryViewCounts, prepare } from "./facets";
import { ALL_SCOPE, scopeKey, type LibraryScope } from "./scope";
import { resetSyncClock, syncLibrary } from "./syncClock";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const at = (day: number, hour: number) => new Date(2026, 10, day, hour).toISOString();
const ITEMS: LibraryItem[] = [
  makeItem("bilal", { title: "Bilal Qureshi", started_at: at(17, 11), labels: ["Clients"], opened: false, important: true }),
  makeItem("ayesha", { title: "Ayesha call", started_at: at(17, 10), labels: ["clients", "Home"], opened: true }),
  makeItem("usman", { title: "Usman Chaudhry", started_at: at(17, 9), opened: false }),
];
const LISTS: ListItem[] = [
  { key: "clients", name: "Clients", color: "#26978f", position: 0, count: 2 },
  { key: "home", name: "Home", color: "#b3820f", position: 1, count: 1 },
  { key: "family", name: "Family", color: null, position: 2, count: 0 },
];

/** The sidebar and the Library as MainApp joins them: a sidebar row opens its scope; the counts come from the Library's list. */
function App() {
  const [scope, setScope] = useState<LibraryScope>(ALL_SCOPE);
  const [request, setRequest] = useState<{ scope: LibraryScope; seq: number } | null>(null);
  const [seen, setSeen] = useState<{ items: readonly LibraryItem[]; read: ReadonlySet<string> } | null>(null);
  const [notice, setNotice] = useState("");
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
          onNewView={() => undefined} say={setNotice} onLibraryChanged={() => void syncLibrary().catch(() => undefined)} />
      </nav>
      <p role="status" aria-label="Sidebar notices">{notice}</p>
      <LibraryV2 scopeRequest={request} onScope={setScope} onItems={(items, read) => setSeen({ items, read })} />
    </>
  );
}

const nav = () => within(screen.getByRole("navigation", { name: "Main" }));
const labels = () => within(nav().getByRole("list", { name: "Labels" }));
/** The labels' rows as the sidebar names them ("Clients, 2"), then + New label. */
const labelRows = () => labels().getAllByRole("button").filter((b) => !b.className.includes("el-nav-row__more")).map((b) => b.getAttribute("aria-label") ?? b.textContent);
const label = (name: string) => labels().getByRole("button", { name: new RegExp(`^${name}, `) });
const rowTitles = () => [...screen.getByRole("list", { name: "Recordings" }).querySelectorAll<HTMLButtonElement>("button[data-row-id]")].map((b) => b.dataset.rowId);
const menuItem = (name: string | RegExp) => screen.getByRole("menuitem", { name });
const menuItems = () => within(screen.getByRole("menu")).getAllByRole("menuitem").map((i) => i.textContent);
const title = () => screen.getByRole("heading", { level: 1 });

describe("labels in the sidebar and the Library", () => {
  beforeEach(() => {
    resetTauriMock();
    resetSyncClock();
    window.localStorage.clear();
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("library_folder", []);
    respond("bin_list", { data: [] });
    respond("lists_get", LISTS);
    respondWith("sessions_bulk", (args) => (args as { ids: string[] }).ids.length);
  });
  afterEach(() => {
    document.documentElement.dir = "";
  });
  const show = async () => {
    render(<App />);
    await act(flushAsync);
  };

  it("LABELS lists them in their order with their colour and count (none at 0), then + New label", async () => {
    await show();
    expect(labelRows()).toEqual(["Clients, 2", "Home, 1", "Family, 0", "New label"]);
    expect(label("Clients").querySelector(".el-label-dot")).not.toBeNull();
    expect(label("Clients")).toHaveTextContent("Clients2");
    expect(label("Family")).toHaveTextContent(/^Family$/);
  });

  it("choosing a label shows only its recordings (its name in any capitals), marked in the sidebar and named in the toolbar", async () => {
    await show();
    fireEvent.click(label("Clients"));
    await act(flushAsync);
    expect(label("Clients")).toHaveAttribute("aria-current", "page");
    expect(title()).toHaveTextContent(/^Clients2$/);
    expect(rowTitles()).toEqual(["bilal", "ayesha"]);
    fireEvent.click(nav().getByRole("button", { name: /^Important, / }));
    await act(flushAsync);
    expect(rowTitles()).toEqual(["bilal"]);
    fireEvent.click(nav().getByRole("button", { name: /^All recordings, / }));
    await act(flushAsync);
    expect(rowTitles()).toEqual(["bilal", "ayesha", "usman"]);
  });

  it("an empty label says so and how to fill it", async () => {
    await show();
    fireEvent.click(label("Family"));
    await act(flushAsync);
    expect(screen.getByText("Nothing in Family yet")).toBeInTheDocument();
    expect(screen.getByText("Add recordings from their ⋮ menu: Labels.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Show all" }));
    await act(flushAsync);
    expect(rowTitles()).toHaveLength(3);
  });

  it("+ New label opens with the least used colour chosen; Create sends the name and colour and opens the new label", async () => {
    respond("list_create", { key: "suppliers", name: "Suppliers", color: "#8e8a80", position: 3, count: 0 });
    await show();
    fireEvent.click(labels().getByRole("button", { name: "New label" }));
    const dialog = screen.getByRole("dialog", { name: "New label" });
    // Teal and amber are taken; grey is the first colour no label has.
    expect(within(dialog).getByRole("radio", { name: "Grey" })).toBeChecked();
    fireEvent.change(within(dialog).getByLabelText("Label name"), { target: { value: "  Suppliers " } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create" }));
    await act(flushAsync);
    expect(callsOf("list_create")).toEqual([{ name: "Suppliers", color: "#8e8a80" }]);
    expect(labelRows()).toEqual(["Clients, 2", "Home, 1", "Family, 0", "Suppliers, 0", "New label"]);
    expect(label("Suppliers")).toHaveAttribute("aria-current", "page");
    expect(screen.getByText("Nothing in Suppliers yet")).toBeInTheDocument();
    // The section's own "+" opens the same dialog.
    fireEvent.click(nav().getAllByRole("button", { name: "New label" })[0]);
    expect(screen.getByRole("dialog", { name: "New label" })).toBeInTheDocument();
  });

  it("New label refuses a name in use and the Library's views' words before anything is sent", async () => {
    await show();
    fireEvent.click(labels().getByRole("button", { name: "New label" }));
    const dialog = screen.getByRole("dialog", { name: "New label" });
    for (const name of ["clients", "UNREAD"]) {
      fireEvent.change(within(dialog).getByLabelText("Label name"), { target: { value: name } });
      expect(within(dialog).getByRole("button", { name: "Create" })).toBeDisabled();
    }
    expect(dialog).toHaveTextContent("Unread is one of the Library's views. Choose another name.");
    fireEvent.change(within(dialog).getByLabelText("Label name"), { target: { value: "clients" } });
    expect(dialog).toHaveTextContent("You already have a label called Clients.");
    expect(callsOf("list_create")).toEqual([]);
  });

  it("opening an unread recording tells the server and takes it out of Unread at once", async () => {
    respond("session_opened", null);
    respond("session_detail", { playback_url: null });
    await show();
    fireEvent.click(nav().getByRole("button", { name: /^Unread, / }));
    await act(flushAsync);
    expect(rowTitles()).toEqual(["bilal", "usman"]);
    expect(nav().getByRole("button", { name: /^Unread, / })).toHaveAccessibleName("Unread, 2");
    fireEvent.click(document.querySelector<HTMLButtonElement>('button[data-row-id="usman"]')!);
    await act(flushAsync);
    expect(callsOf("session_opened")).toEqual([{ id: "usman" }]);
    expect(nav().getByRole("button", { name: /^Unread, / })).toHaveAccessibleName("Unread, 1");
    fireEvent.click(screen.getAllByRole("button", { name: "Library" }).at(-1)!);
    await act(flushAsync);
    expect(rowTitles()).toEqual(["bilal"]);
  });

  it("a recording opened while offline is still read here", async () => {
    fail("session_opened", "The server could not be reached.");
    respond("session_detail", { playback_url: null });
    await show();
    fireEvent.click(document.querySelector<HTMLButtonElement>('button[data-row-id="bilal"]')!);
    await act(flushAsync);
    expect(callsOf("session_opened")).toEqual([{ id: "bilal" }]);
    expect(nav().getByRole("button", { name: /^Unread, / })).toHaveAccessibleName("Unread, 1");
  });

  it("Labels ▸ in the row ⋮ ticks the labels it has; a tick adds it at once with Undo", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Usman Chaudhry" }));
    fireEvent.click(menuItem("Labels"));
    const picker = screen.getByRole("menu", { name: "Labels" });
    expect(within(picker).getAllByRole("menuitemcheckbox").map((i) => [i.textContent, i.getAttribute("aria-checked")]))
      .toEqual([["Clients", "false"], ["Home", "false"], ["Family", "false"]]);
    fireEvent.click(within(picker).getByRole("menuitemcheckbox", { name: "Home" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["usman"], action: "label", clientId: null, label: "Home" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Added to Home");
    fireEvent.click(screen.getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk").at(-1)).toEqual({ ids: ["usman"], action: "unlabel", clientId: null, label: "Home" });
  });

  it("Undo after Added to a label takes it off only the recordings that didn't have it already", async () => {
    await show();
    for (const id of ["bilal", "ayesha"]) fireEvent.click(screen.getByRole("checkbox", { name: `Select ${ITEMS.find((i) => i.id === id)!.title}` }));
    fireEvent.contextMenu(document.querySelector<HTMLButtonElement>('button[data-row-id="ayesha"]')!);
    fireEvent.click(menuItem("Labels"));
    // Ayesha has Home already; Bilal hasn't.
    fireEvent.click(within(screen.getByRole("menu", { name: "Labels" })).getByRole("menuitemcheckbox", { name: "Home" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal", "ayesha"], action: "label", clientId: null, label: "Home" }]);
    fireEvent.click(screen.getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk").at(-1)).toEqual({ ids: ["bilal"], action: "unlabel", clientId: null, label: "Home" });
  });

  it("Labels ▸ takes Label…'s place in the menu", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Usman Chaudhry" }));
    expect(screen.getByRole("menuitem", { name: "Labels" })).toBeInTheDocument();
    expect(screen.queryByRole("menuitem", { name: "Label…" })).toBeNull();
  });

  it("a right-click on a recording with labels shows them ticked; unticking takes it off", async () => {
    await show();
    fireEvent.contextMenu(document.querySelector<HTMLButtonElement>('button[data-row-id="ayesha"]')!);
    fireEvent.click(menuItem("Labels"));
    const picker = screen.getByRole("menu", { name: "Labels" });
    expect(within(picker).getByRole("menuitemcheckbox", { name: "Clients" })).toHaveAttribute("aria-checked", "true");
    expect(within(picker).getByRole("menuitemcheckbox", { name: "Home" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(within(picker).getByRole("menuitemcheckbox", { name: "Clients" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["ayesha"], action: "unlabel", clientId: null, label: "Clients" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Removed from Clients");
  });

  it("Labels ▸ New label… makes the label and puts the recording in it; the Library stays where it was", async () => {
    respond("list_create", { key: "suppliers", name: "Suppliers", color: "#8e8a80", position: 3, count: 0 });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Bilal Qureshi" }));
    fireEvent.click(menuItem("Labels"));
    fireEvent.click(menuItem("New label…"));
    const dialog = screen.getByRole("dialog", { name: "New label" });
    fireEvent.change(within(dialog).getByLabelText("Label name"), { target: { value: "Suppliers" } });
    // The server has it from now on.
    respond("lists_get", [...LISTS, { key: "suppliers", name: "Suppliers", color: "#8e8a80", position: 3, count: 1 }]);
    fireEvent.click(within(dialog).getByRole("button", { name: "Create" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal"], action: "label", clientId: null, label: "Suppliers" }]);
    expect(nav().getByRole("button", { name: /^All recordings, / })).toHaveAttribute("aria-current", "page");
    expect(labelRows().some((r) => r?.startsWith("Suppliers, "))).toBe(true);
  });

  it("a label's menu (⋮ or a right-click): Rename… checks the name, then renames on the server; the shown label follows", async () => {
    respond("list_update", { key: "customers", name: "Customers", color: "#26978f", position: 0, count: 2 });
    await show();
    fireEvent.click(label("Clients"));
    await act(flushAsync);
    fireEvent.click(labels().getByRole("button", { name: "More for Clients" }));
    expect(menuItems()).toEqual(["Rename…", "Change colour…", "Move down", "Delete label…"]);
    fireEvent.click(menuItem("Rename…"));
    const dialog = screen.getByRole("dialog", { name: "Rename label" });
    const field = within(dialog).getByLabelText("Label name");
    fireEvent.change(field, { target: { value: " home " } });
    expect(dialog).toHaveTextContent("You already have a label called Home.");
    expect(within(dialog).getByRole("button", { name: "Save" })).toBeDisabled();
    fireEvent.change(field, { target: { value: "Customers" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("list_update")).toEqual([{ key: "clients", name: "Customers", color: null }]);
    expect(labelRows()[0]).toMatch(/^Customers, /);
    expect(label("Customers")).toHaveAttribute("aria-current", "page");
  });

  it("a rename the server refuses says why and keeps the label", async () => {
    fail("list_update", "You already have a label called Home.");
    await show();
    fireEvent.contextMenu(label("Family"));
    fireEvent.click(menuItem("Rename…"));
    fireEvent.change(within(screen.getByRole("dialog", { name: "Rename label" })).getByLabelText("Label name"), { target: { value: "Relatives" } });
    fireEvent.click(within(screen.getByRole("dialog", { name: "Rename label" })).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(screen.getByRole("status", { name: "Sidebar notices" })).toHaveTextContent("You already have a label called Home.");
    expect(labelRows()).toContain("Family, 0");
  });

  it("Change colour… sends one of the eight colours", async () => {
    respond("list_update", { ...LISTS[1], color: "#d7609c" });
    await show();
    fireEvent.contextMenu(label("Home"));
    fireEvent.click(menuItem("Change colour…"));
    const dialog = screen.getByRole("dialog", { name: "Change colour" });
    expect(within(dialog).getByRole("radio", { name: "Amber" })).toBeChecked();
    fireEvent.click(within(dialog).getByRole("radio", { name: "Pink" }));
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("list_update")).toEqual([{ key: "home", name: null, color: "#d7609c" }]);
  });

  it("Move up and Move down send the new order (the same in right to left: the sidebar is a column); the ends offer only one way", async () => {
    respondWith("lists_order", (args) => (args as { keys: string[] }).keys.map((key, position) => ({ ...LISTS.find((l) => l.key === key)!, position })));
    await show();
    fireEvent.contextMenu(label("Home"));
    expect(menuItems()).toEqual(["Rename…", "Change colour…", "Move up", "Move down", "Delete label…"]);
    fireEvent.click(menuItem("Move up"));
    await act(flushAsync);
    expect(callsOf("lists_order")).toEqual([{ keys: ["home", "clients", "family"] }]);
    expect(labelRows().slice(0, 3).map((r) => r?.split(",")[0])).toEqual(["Home", "Clients", "Family"]);
    fireEvent.contextMenu(label("Family"));
    expect(menuItems()).toEqual(["Rename…", "Change colour…", "Move up", "Delete label…"]);
    fireEvent.keyDown(screen.getByRole("menu"), { key: "Escape" });
    document.documentElement.dir = "rtl";
    fireEvent.contextMenu(label("Clients"));
    fireEvent.click(menuItem("Move down"));
    await act(flushAsync);
    expect(callsOf("lists_order").at(-1)).toEqual({ keys: ["home", "family", "clients"] });
  });

  it("Delete label… asks first; Cancel keeps it, Delete removes it and goes back to All", async () => {
    respond("list_delete", 2);
    await show();
    fireEvent.click(label("Clients"));
    await act(flushAsync);
    fireEvent.contextMenu(label("Clients"));
    fireEvent.click(menuItem("Delete label…"));
    const ask = screen.getByRole("alertdialog", { name: "Delete Clients?" });
    expect(ask).toHaveTextContent("Its recordings stay in your Library; only the label goes.");
    fireEvent.click(within(ask).getByRole("button", { name: "Cancel" }));
    expect(callsOf("list_delete")).toEqual([]);
    fireEvent.contextMenu(label("Clients"));
    fireEvent.click(menuItem("Delete label…"));
    fireEvent.click(within(screen.getByRole("alertdialog", { name: "Delete Clients?" })).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("list_delete")).toEqual([{ key: "clients" }]);
    expect(labelRows().some((r) => r?.startsWith("Clients"))).toBe(false);
    expect(nav().getByRole("button", { name: /^All recordings, / })).toHaveAttribute("aria-current", "page");
    expect(rowTitles()).toEqual(["bilal", "ayesha", "usman"]);
  });

  it("a server without labels shows no LABELS section, and Label… stays in the menu", async () => {
    respond("lists_get", null);
    await show();
    expect(nav().queryByRole("list", { name: "Labels" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "More for Usman Chaudhry" }));
    expect(screen.queryByRole("menuitem", { name: "Labels" })).toBeNull();
    expect(screen.getByRole("menuitem", { name: "Label…" })).toBeInTheDocument();
  });

  it("a label shown and deleted on another device gives way to All, and the labels are read again", async () => {
    await show();
    fireEvent.click(label("Clients"));
    await act(flushAsync);
    expect(rowTitles()).toEqual(["bilal", "ayesha"]);
    respond("lists_get", LISTS.slice(1));
    await act(() => emitEvent("library:updated", 1));
    await act(flushAsync);
    expect(labelRows().some((r) => r?.startsWith("Clients"))).toBe(false);
    expect(rowTitles()).toEqual(["bilal", "ayesha", "usman"]);
  });

  it("a label the server no longer has (list_not_found) reads them again and goes back to All", async () => {
    await show();
    fireEvent.click(label("Home"));
    await act(flushAsync);
    fail("list_update", "List not found.");
    respond("lists_get", [LISTS[0], LISTS[2]]);
    fireEvent.contextMenu(label("Home"));
    fireEvent.click(menuItem("Change colour…"));
    fireEvent.click(within(screen.getByRole("dialog", { name: "Change colour" })).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(screen.getByRole("status", { name: "Sidebar notices" })).toHaveTextContent("List not found.");
    expect(labelRows().some((r) => r?.startsWith("Home"))).toBe(false);
    expect(rowTitles()).toEqual(["bilal", "ayesha", "usman"]);
  });

  it("a rename or a delete syncs the Library once", async () => {
    respond("list_update", { key: "customers", name: "Customers", color: "#26978f", position: 0, count: 2 });
    respond("list_delete", 1);
    await show();
    const before = callsOf("library_refresh").length;
    fireEvent.contextMenu(label("Clients"));
    fireEvent.click(menuItem("Rename…"));
    fireEvent.change(within(screen.getByRole("dialog", { name: "Rename label" })).getByLabelText("Label name"), { target: { value: "Customers" } });
    fireEvent.click(within(screen.getByRole("dialog", { name: "Rename label" })).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("library_refresh").length - before).toBe(1);
    fireEvent.contextMenu(label("Home"));
    fireEvent.click(menuItem("Delete label…"));
    fireEvent.click(within(screen.getByRole("alertdialog", { name: "Delete Home?" })).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("library_refresh").length - before).toBe(2);
  });

  it("LABELS folds, and stays folded on this computer", async () => {
    const view = render(<App />);
    await act(flushAsync);
    fireEvent.click(nav().getByRole("button", { name: "Labels" }));
    expect(nav().queryByRole("list", { name: "Labels" })).toBeNull();
    view.unmount();
    await show();
    expect(nav().queryByRole("list", { name: "Labels" })).toBeNull();
    expect(nav().getByRole("button", { name: "Labels" })).toHaveAttribute("aria-expanded", "false");
  });
});
