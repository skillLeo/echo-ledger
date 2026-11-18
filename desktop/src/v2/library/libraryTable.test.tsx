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

  it("Show as Table: the default columns, sections as header rows, remembered on this computer", async () => {
    await show();
    fireEvent.click(screen.getByRole("radio", { name: "Table" }));
    expect(within(table()).getAllByRole("columnheader").map((h) => h.textContent)).toEqual(["Recording", "Date", "Length", "App", "Project", "Status", "Actions"]);
    const rows = within(table()).getAllByRole("row").map((r) => r.textContent);
    expect(rows[1]).toBe("Today · 1");
    expect(rows[2]).toMatch(/^Ali18 Nov1:00WhatsAppAcmeReady/);
    expect(rows[4]).toMatch(/^Bina10 Nov10:00ZoomInboxFailed/);
    expect(window.localStorage.getItem("echoledger-v2-library-layout")).toBe("table");
  });

  it("Columns: Labels and Device on, Date off, kept; List brings the rows back", async () => {
    await show();
    fireEvent.click(screen.getByRole("radio", { name: "Table" }));
    fireEvent.click(screen.getByRole("button", { name: "Columns" }));
    fireEvent.click(screen.getByRole("menuitemcheckbox", { name: "Labels" }));
    fireEvent.click(screen.getByRole("menuitemcheckbox", { name: "Device" }));
    fireEvent.click(screen.getByRole("menuitemcheckbox", { name: "Date" }));
    expect(within(table()).getAllByRole("columnheader").map((h) => h.textContent)).toEqual(["Recording", "Length", "App", "Project", "Status", "Labels", "Device", "Actions"]);
    expect(within(table()).getAllByRole("row")[2]).toHaveTextContent(/Q4Phone$/);
    expect(JSON.parse(window.localStorage.getItem("echoledger-v2-library-columns")!)).toEqual(["length", "app", "project", "status", "labels", "device"]);
    fireEvent.click(screen.getByRole("radio", { name: "List" }));
    expect(screen.queryByRole("table")).toBeNull();
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
  });

  it("a row opens its recording; while selecting it ticks; its ⋮ and right-click are the one menu", async () => {
    window.localStorage.setItem("echoledger-v2-library-layout", "table");
    respond("session_detail", { playback_url: null });
    respond("sessions_bulk", 1);
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Select" }));
    fireEvent.click(within(table()).getByRole("button", { name: "Select Ali" }));
    expect(within(table()).getByRole("button", { name: "Select Ali" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByRole("toolbar", { name: "Selected recordings" })).toHaveTextContent("1 selected");
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
    expect(screen.getByRole("heading", { level: 1, name: "Ali" })).toBeInTheDocument();
  });
});
