// Lists in the v2 Library (D14, contract 2.0.0-m): the chip row All · Unread · Important · the person's lists · "+",
// choosing a chip, the empty list, "+" and its dialog, Add to list in the row ⋮ and right-click, and a list chip's menu
// (Rename with the duplicate check, Change colour, Move left / Move right, swapped right to left, Delete with confirm).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem, ListItem } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { inPlace, listPlace, unreadCount } from "./lists";

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

const chips = () => within(screen.getByRole("group", { name: "Lists" }));
const chipNames = () => chips().getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent);
const rowTitles = () => [...screen.getByRole("list", { name: "Recordings" }).querySelectorAll<HTMLButtonElement>("button[data-row-id]")].map((b) => b.dataset.rowId);
const menuItem = (name: string | RegExp) => screen.getByRole("menuitem", { name });
const menuItems = () => within(screen.getByRole("menu")).getAllByRole("menuitem").map((i) => i.textContent);

describe("lists in the Library", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
    respond("lists_get", LISTS);
    respondWith("sessions_bulk", (args) => (args as { ids: string[] }).ids.length);
  });
  afterEach(() => {
    document.documentElement.dir = "";
  });
  const show = async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
  };

  it("shows All, Unread with its count, Important, the lists in their order, then +", async () => {
    await show();
    expect(chipNames()).toEqual(["All", "Unread 2", "Important", "Clients", "Home", "Family", "New list"]);
    expect(chips().getByRole("button", { name: "All" })).toHaveAttribute("aria-current", "true");
  });

  it("a chip shows only its recordings: unread, important, or the list's label in any capitals", async () => {
    await show();
    fireEvent.click(chips().getByRole("button", { name: /Unread/ }));
    expect(rowTitles()).toEqual(["bilal", "usman"]);
    fireEvent.click(chips().getByRole("button", { name: "Important" }));
    expect(rowTitles()).toEqual(["bilal"]);
    fireEvent.click(chips().getByRole("button", { name: "Clients" }));
    expect(chips().getByRole("button", { name: "Clients" })).toHaveAttribute("aria-current", "true");
    expect(rowTitles()).toEqual(["bilal", "ayesha"]);
    fireEvent.click(chips().getByRole("button", { name: "All" }));
    expect(rowTitles()).toEqual(["bilal", "ayesha", "usman"]);
  });

  it("an empty list says so and how to fill it", async () => {
    await show();
    fireEvent.click(chips().getByRole("button", { name: "Family" }));
    expect(screen.getByText("Nothing in Family yet")).toBeInTheDocument();
    expect(screen.getByText("Add recordings from their ⋮ menu: Add to list.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Show all" }));
    expect(rowTitles()).toHaveLength(3);
  });

  it("+ opens New list with the least used colour chosen; Create sends the name and colour and opens the new list", async () => {
    respond("list_create", { key: "suppliers", name: "Suppliers", color: "#8e8a80", position: 3, count: 0 });
    await show();
    fireEvent.click(chips().getByRole("button", { name: "New list" }));
    const dialog = screen.getByRole("dialog", { name: "New list" });
    // Teal and amber are taken; grey is the first colour no list has.
    expect(within(dialog).getByRole("radio", { name: "Grey" })).toBeChecked();
    fireEvent.change(within(dialog).getByLabelText("List name"), { target: { value: "  Suppliers " } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create" }));
    await act(flushAsync);
    expect(callsOf("list_create")).toEqual([{ name: "Suppliers", color: "#8e8a80" }]);
    expect(chipNames()).toEqual(["All", "Unread 2", "Important", "Clients", "Home", "Family", "Suppliers", "New list"]);
    expect(chips().getByRole("button", { name: "Suppliers" })).toHaveAttribute("aria-current", "true");
    expect(screen.getByText("Nothing in Suppliers yet")).toBeInTheDocument();
  });

  it("New list refuses a name in use and the fixed chips' words before anything is sent", async () => {
    await show();
    fireEvent.click(chips().getByRole("button", { name: "New list" }));
    const dialog = screen.getByRole("dialog", { name: "New list" });
    for (const name of ["clients", "UNREAD"]) {
      fireEvent.change(within(dialog).getByLabelText("List name"), { target: { value: name } });
      expect(within(dialog).getByRole("button", { name: "Create" })).toBeDisabled();
    }
    expect(dialog).toHaveTextContent("You already have a list called Unread.");
    expect(callsOf("list_create")).toEqual([]);
  });

  it("Add to list in the row ⋮ ticks the lists it is in; a tick adds it at once with Undo", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Usman Chaudhry" }));
    fireEvent.click(menuItem("Add to list"));
    const picker = screen.getByRole("menu", { name: "Add to list" });
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

  it("a right-click on a recording in a list shows it ticked; unticking takes it out", async () => {
    await show();
    fireEvent.contextMenu(document.querySelector<HTMLButtonElement>('button[data-row-id="ayesha"]')!);
    fireEvent.click(menuItem("Add to list"));
    const picker = screen.getByRole("menu", { name: "Add to list" });
    expect(within(picker).getByRole("menuitemcheckbox", { name: "Clients" })).toHaveAttribute("aria-checked", "true");
    expect(within(picker).getByRole("menuitemcheckbox", { name: "Home" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(within(picker).getByRole("menuitemcheckbox", { name: "Clients" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["ayesha"], action: "unlabel", clientId: null, label: "Clients" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Removed from Clients");
  });

  it("Add to list › New list… makes the list and puts the recording in it", async () => {
    respond("list_create", { key: "suppliers", name: "Suppliers", color: "#8e8a80", position: 3, count: 0 });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Bilal Qureshi" }));
    fireEvent.click(menuItem("Add to list"));
    fireEvent.click(menuItem("New list…"));
    const dialog = screen.getByRole("dialog", { name: "New list" });
    fireEvent.change(within(dialog).getByLabelText("List name"), { target: { value: "Suppliers" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Create" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal"], action: "label", clientId: null, label: "Suppliers" }]);
    // From Add to list the Library stays where it was.
    expect(chips().getByRole("button", { name: "All" })).toHaveAttribute("aria-current", "true");
  });

  it("a list chip's menu: Rename… checks the name, then renames on the server", async () => {
    respond("list_update", { key: "customers", name: "Customers", color: "#26978f", position: 0, count: 2 });
    await show();
    fireEvent.click(chips().getByRole("button", { name: "Clients" }));
    fireEvent.contextMenu(chips().getByRole("button", { name: "Clients" }));
    expect(menuItems()).toEqual(["Rename…", "Change colour…", "Move right", "Delete list…"]);
    fireEvent.click(menuItem("Rename…"));
    const dialog = screen.getByRole("dialog", { name: "Rename list" });
    const field = within(dialog).getByLabelText("List name");
    fireEvent.change(field, { target: { value: " home " } });
    expect(dialog).toHaveTextContent("You already have a list called Home.");
    expect(within(dialog).getByRole("button", { name: "Save" })).toBeDisabled();
    fireEvent.change(field, { target: { value: "Customers" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("list_update")).toEqual([{ key: "clients", name: "Customers", color: null }]);
    expect(chipNames()).toContain("Customers");
    // The chosen chip follows the new name.
    expect(chips().getByRole("button", { name: "Customers" })).toHaveAttribute("aria-current", "true");
  });

  it("a rename the server refuses says why and keeps the list", async () => {
    fail("list_update", "You already have a list called Home.");
    await show();
    fireEvent.contextMenu(chips().getByRole("button", { name: "Family" }));
    fireEvent.click(menuItem("Rename…"));
    fireEvent.change(within(screen.getByRole("dialog", { name: "Rename list" })).getByLabelText("List name"), { target: { value: "Relatives" } });
    fireEvent.click(within(screen.getByRole("dialog", { name: "Rename list" })).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("You already have a list called Home.");
    expect(chipNames()).toContain("Family");
  });

  it("Change colour… sends one of the eight colours", async () => {
    respond("list_update", { ...LISTS[1], color: "#d7609c" });
    await show();
    fireEvent.contextMenu(chips().getByRole("button", { name: "Home" }));
    fireEvent.click(menuItem("Change colour…"));
    const dialog = screen.getByRole("dialog", { name: "Change colour" });
    expect(within(dialog).getByRole("radio", { name: "Amber" })).toBeChecked();
    fireEvent.click(within(dialog).getByRole("radio", { name: "Pink" }));
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("list_update")).toEqual([{ key: "home", name: null, color: "#d7609c" }]);
  });

  it("Move left and Move right send the new order; the ends offer only one way", async () => {
    respondWith("lists_order", (args) => (args as { keys: string[] }).keys.map((key, position) => ({ ...LISTS.find((l) => l.key === key)!, position })));
    await show();
    fireEvent.contextMenu(chips().getByRole("button", { name: "Home" }));
    expect(menuItems()).toEqual(["Rename…", "Change colour…", "Move left", "Move right", "Delete list…"]);
    fireEvent.click(menuItem("Move left"));
    await act(flushAsync);
    expect(callsOf("lists_order")).toEqual([{ keys: ["home", "clients", "family"] }]);
    expect(chipNames().slice(3, 6)).toEqual(["Home", "Clients", "Family"]);
    fireEvent.contextMenu(chips().getByRole("button", { name: "Family" }));
    expect(menuItems()).toEqual(["Rename…", "Change colour…", "Move left", "Delete list…"]);
  });

  it("right to left, Move left goes later in the order and Move right earlier", async () => {
    document.documentElement.dir = "rtl";
    respondWith("lists_order", (args) => (args as { keys: string[] }).keys.map((key, position) => ({ ...LISTS.find((l) => l.key === key)!, position })));
    await show();
    fireEvent.contextMenu(chips().getByRole("button", { name: "Clients" }));
    // The first list is at the right end: it can only move left (later).
    expect(menuItems()).toEqual(["Rename…", "Change colour…", "Move left", "Delete list…"]);
    fireEvent.click(menuItem("Move left"));
    await act(flushAsync);
    expect(callsOf("lists_order")).toEqual([{ keys: ["home", "clients", "family"] }]);
    fireEvent.contextMenu(chips().getByRole("button", { name: "Clients" }));
    fireEvent.click(menuItem("Move right"));
    await act(flushAsync);
    expect(callsOf("lists_order").at(-1)).toEqual({ keys: ["clients", "home", "family"] });
  });

  it("Delete list… asks first; Cancel keeps it, Delete removes it and goes back to All", async () => {
    respond("list_delete", 2);
    await show();
    fireEvent.click(chips().getByRole("button", { name: "Clients" }));
    fireEvent.contextMenu(chips().getByRole("button", { name: "Clients" }));
    fireEvent.click(menuItem("Delete list…"));
    const ask = screen.getByRole("alertdialog", { name: "Delete Clients?" });
    expect(ask).toHaveTextContent("Its recordings stay in your Library; only the list goes.");
    fireEvent.click(within(ask).getByRole("button", { name: "Cancel" }));
    expect(callsOf("list_delete")).toEqual([]);
    fireEvent.contextMenu(chips().getByRole("button", { name: "Clients" }));
    fireEvent.click(menuItem("Delete list…"));
    fireEvent.click(within(screen.getByRole("alertdialog", { name: "Delete Clients?" })).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("list_delete")).toEqual([{ key: "clients" }]);
    expect(chipNames()).not.toContain("Clients");
    expect(chips().getByRole("button", { name: "All" })).toHaveAttribute("aria-current", "true");
  });

  it("the fixed chips have no menu, and a server without lists shows the fixed chips only and no Add to list", async () => {
    respond("lists_get", null);
    await show();
    expect(chipNames()).toEqual(["All", "Unread 2", "Important", "New list"]);
    fireEvent.contextMenu(chips().getByRole("button", { name: "Important" }));
    expect(screen.queryByRole("menu")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "More for Usman Chaudhry" }));
    expect(screen.queryByRole("menuitem", { name: "Add to list" })).toBeNull();
  });
});

describe("inPlace and unreadCount", () => {
  it("match the label ignoring capitals, and count only unread recordings still in the Library", () => {
    const item = makeItem("a", { labels: ["Clients"], opened: false });
    expect(inPlace(item, listPlace("clients"))).toBe(true);
    expect(inPlace(item, listPlace("home"))).toBe(false);
    expect(inPlace(item, "unread")).toBe(true);
    expect(inPlace(item, "important")).toBe(false);
    expect(inPlace(makeItem("b", { opened: undefined }), "unread")).toBe(false);
    expect(unreadCount([item, makeItem("c", { opened: false, deleted: true }), makeItem("d", { opened: true })])).toBe(1);
  });
});
