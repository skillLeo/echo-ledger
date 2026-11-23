// The Library as a table (parity.csv DT-LIB-045..056): Show as List / Table and the columns, kept on this computer;
// sections as header rows; a row opens its recording, or ticks it while selecting; its ⋮ and right-click.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 18, 15, 0);
const ITEMS = [
  makeItem("a", { title: "Ali", started_at: new Date(2026, 10, 18, 9).toISOString(), duration_ms: 60_000, client: { id: "c1", name: "Acme" }, labels: ["Q4"], source_device: "phone", source_app: "whatsapp", source_app_label: "WhatsApp" }),
  makeItem("b", { title: "Bina", started_at: new Date(2026, 10, 10, 9).toISOString(), duration_ms: 600_000, status: "failed", transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null } }),
];
const table = () => screen.getByRole("table", { name: "Recordings" });

describe("the Library as a table", () => {
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
  // List or Table is in Sort & display ▾ now (PLAN_LIBRARY_FILTERS), with the table's Columns ▸.
  const showAs = (layout: "List" | "Table") => {
    fireEvent.click(screen.getByRole("button", { name: "Sort & display" }));
    fireEvent.click(screen.getByRole("menuitemradio", { name: layout }));
  };

  it("Show as Table: the default columns, sections as header rows, remembered on this computer", async () => {
    await show();
    showAs("Table");
    expect(within(table()).getAllByRole("columnheader").map((h) => h.querySelector("input")?.getAttribute("aria-label") ?? h.textContent)).toEqual(["Select all", "Recording", "Date", "Length", "App", "Project", "Status", "Actions"]);
    const rows = within(table()).getAllByRole("row").map((r) => r.textContent);
    expect(rows[1]).toBe("Today · 1");
    expect(rows[2]).toMatch(/^Ali18 Nov1:00WhatsAppAcmeReady/);
    expect(rows[4]).toMatch(/^Bina10 Nov10:00ZoomNo projectFailed/);
    expect(window.localStorage.getItem("echoledger-v2-library-layout")).toBe("table");
  });

  it("Status says Couldn't convert in the danger colour with the alert icon (U19)", async () => {
    const failed = makeItem("u", { title: "Usman", started_at: new Date(2026, 10, 18, 10).toISOString(),
      transcript: { final_status: "completed", final_transcript_id: "tr-u", live_available: false, preview: null, views: { status: "failed", default: "roman", progress: {} } } });
    respond("library_list", [failed]);
    respond("library_refresh", [failed]);
    await show();
    showAs("Table");
    const cell = within(table()).getByTestId("table-convert-failed");
    expect(cell).toHaveTextContent("Couldn't convert to Roman Urdu");
    expect(cell).toHaveClass("el-converting--danger");
    expect(cell.querySelector("svg")).not.toBeNull();
  });

  it("Columns: Labels and Device on, Date off, kept; List brings the rows back", async () => {
    await show();
    showAs("Table");
    fireEvent.click(screen.getByRole("button", { name: "Sort & display" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Columns" }));
    fireEvent.click(screen.getByRole("menuitemcheckbox", { name: "Labels" }));
    fireEvent.click(screen.getByRole("menuitemcheckbox", { name: "Device" }));
    fireEvent.click(screen.getByRole("menuitemcheckbox", { name: "Date" }));
    expect(within(table()).getAllByRole("columnheader").map((h) => h.querySelector("input")?.getAttribute("aria-label") ?? h.textContent)).toEqual(["Select all", "Recording", "Length", "App", "Project", "Status", "Labels", "Device", "Actions"]);
    expect(within(table()).getAllByRole("row")[2]).toHaveTextContent(/Q4Phone$/);
    expect(JSON.parse(window.localStorage.getItem("echoledger-v2-library-columns")!)).toEqual(["length", "app", "project", "status", "labels", "device"]);
    // The ticks leave the menu open; a press away closes it.
    fireEvent.pointerDown(document.body);
    showAs("List");
    expect(screen.queryByRole("table")).toBeNull();
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
  });

  it("the header's box picks every row shown, mixed while some are, and picks none again", async () => {
    window.localStorage.setItem("echoledger-v2-library-layout", "table");
    await show();
    const all = () => within(table()).getByRole("columnheader", { name: /Select all|Unselect all/ }).querySelector("input")!;
    expect(all()).toHaveAccessibleName("Select all");
    expect(all()).not.toBeChecked();
    fireEvent.click(within(table()).getByRole("checkbox", { name: "Select Bina" }));
    expect(all().indeterminate).toBe(true);
    fireEvent.click(all());
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("2 selected");
    expect(all()).toBeChecked();
    expect(all()).toHaveAccessibleName("Unselect all");
    fireEvent.click(all());
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("Select recordings");
    expect(all()).not.toBeChecked();
  });

  it("a row opens its recording; its checkbox picks it, and then a press ticks it; its ⋮ and right-click are the one menu", async () => {
    window.localStorage.setItem("echoledger-v2-library-layout", "table");
    respond("session_detail", { playback_url: null });
    respond("sessions_bulk", 1);
    await show();
    // No Select button: a row's checkbox (on hover) picks it; then a press on another row ticks that one.
    fireEvent.click(within(table()).getByRole("checkbox", { name: "Select Bina" }));
    fireEvent.click(within(table()).getByRole("button", { name: "Select Ali" }));
    expect(within(table()).getByRole("button", { name: "Select Ali" })).toHaveAttribute("aria-pressed", "true");
    expect(within(table()).getByRole("checkbox", { name: "Select Ali" })).toBeChecked();
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("2 selected");
    fireEvent.click(screen.getByRole("button", { name: "Cancel selection" }));
    fireEvent.contextMenu(within(table()).getAllByRole("row")[2], { clientX: 40, clientY: 40 });
    fireEvent.click(screen.getByRole("menuitem", { name: "Pin" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["a"], action: "pin", clientId: null, label: null }]);
    fireEvent.click(within(table()).getByRole("button", { name: "More for Bina" }));
    expect(screen.getByRole("menu", { name: "More for Bina" })).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("menu", { name: "More for Bina" }), { key: "Escape" });
    fireEvent.click(within(table()).getByRole("button", { name: "Ali" }));
    await act(flushAsync);
    // A drawer over the table, as classic's: the table stays; Close, Esc and the scrim close it, focus back on the row.
    const drawer = screen.getByRole("dialog", { name: "Ali" });
    expect(within(drawer).getByRole("heading", { level: 1, name: "Ali" })).toBeInTheDocument();
    expect(drawer).toHaveAttribute("aria-modal", "true");
    expect(within(drawer).getByRole("button", { name: "Close" })).toHaveFocus();
    // Tab stays inside: from the last control back to the first.
    const inside = [...drawer.querySelectorAll<HTMLElement>("button:not([disabled])")];
    inside[inside.length - 1].focus();
    fireEvent.keyDown(inside[inside.length - 1], { key: "Tab" });
    expect(within(drawer).getByRole("button", { name: "Close" })).toHaveFocus();
    // Esc inside the drawer closes it too.
    fireEvent.keyDown(within(drawer).getByRole("button", { name: "Close" }), { key: "Escape" });
    await act(flushAsync);
    expect(screen.queryByRole("dialog", { name: "Ali" })).toBeNull();
    // A press on a row focuses it (as a click does): closing gives focus back there.
    act(() => within(table()).getByRole("button", { name: "Ali" }).focus());
    fireEvent.click(within(table()).getByRole("button", { name: "Ali" }));
    await act(flushAsync);
    const again = screen.getByRole("dialog", { name: "Ali" });
    expect(table()).toBeInTheDocument();
    fireEvent.click(within(again).getByRole("button", { name: "Close" }));
    await act(flushAsync);
    expect(screen.queryByRole("dialog", { name: "Ali" })).toBeNull();
    expect(within(table()).getByRole("button", { name: "Ali" })).toHaveFocus();
    fireEvent.click(within(table()).getByRole("button", { name: "Ali" }));
    await act(flushAsync);
    fireEvent.keyDown(document.body, { key: "Escape" });
    await act(flushAsync);
    expect(screen.queryByRole("dialog", { name: "Ali" })).toBeNull();
    fireEvent.click(within(table()).getByRole("button", { name: "Bina" }));
    await act(flushAsync);
    fireEvent.mouseDown(document.querySelector(".el-drawer-scrim")!);
    await act(flushAsync);
    expect(screen.queryByRole("dialog", { name: "Bina" })).toBeNull();
  });
});
