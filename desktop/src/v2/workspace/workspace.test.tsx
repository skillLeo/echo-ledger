import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { ClientWorkspace, MemoryLine } from "../../lib/ipc";
import MainApp from "../../views/main/MainApp";
import { callsOf, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { WorkspaceV2, memoryGroups } from "./WorkspaceV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECT = { id: "c1", name: "Qureshi Textiles" };
const line = (id: string, kind: MemoryLine["kind"], text: string, over: Partial<MemoryLine> = {}): MemoryLine =>
  ({ id, kind, text, happened_on: null, pinned: false, source: null, ...over });
const WS: ClientWorkspace = {
  client: { id: "c1", name: "Qureshi Textiles", ai_notes: true },
  ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 },
  brief: { sections: { goal: "Ship 40,000 metres of cotton lawn in March.", deadlines: ["Packing done by 21 March"], budget: ["40% advance"] }, edited: false, status: "ready", error: null, generated_at: null, updated_at: null },
  memory: [
    line("m1", "decision", "40% in advance, the balance against the bill of lading", { source: { type: "call", id: "bilal", at_ms: 31_000 } }),
    line("m2", "question", "Which bank opens the LC?"),
    line("m3", "decision", "Packing done by 21 March", { pinned: true }),
  ],
  notes: [],
  files: [
    { id: "f1", name: "Proforma March.pdf", mime: "application/pdf", size_bytes: 1, pages: 2, status: "ready", error: null, instructions: false },
    { id: "f2", name: "Instructions", mime: "text/plain", size_bytes: 1, pages: null, status: "ready", error: null, instructions: true },
  ],
};
const ITEMS = [
  makeItem("bilal", { title: "Payment terms for the March shipment", client: { id: "c1", name: "Qureshi Textiles" } }),
  makeItem("other", { title: "Weekly sales review", client: { id: "c2", name: "Mehran Foods" } }),
];

beforeEach(() => {
  resetTauriMock();
  respond("library_list", ITEMS);
  respond("client_workspace", { data: WS });
});

const props = () => ({ onBack: vi.fn(), onOpenRecording: vi.fn(), onLibrary: vi.fn() });
const show = async (p = props()) => {
  render(<WorkspaceV2 project={PROJECT} {...p} />);
  await act(flushAsync);
  return p;
};

describe("memory groups", () => {
  it("puts lines under their kind, pinned first", () => {
    expect(memoryGroups(WS.memory).map((g) => [g.heading, g.lines.map((l) => l.id)])).toEqual([["Decisions", ["m3", "m1"]], ["Open questions", ["m2"]]]);
  });
});

describe("the project's page", () => {
  it("has the tabs Recordings, Ask and Memory & instructions; recordings are only this project's", async () => {
    const p = await show();
    expect(screen.getByRole("heading", { level: 1, name: "Qureshi Textiles" })).toBeInTheDocument();
    expect(screen.getByText("1 recording")).toBeInTheDocument();
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Recordings", "Ask", "Memory & instructions"]);
    const list = screen.getByRole("list", { name: "Recordings" });
    fireEvent.click(within(list).getByRole("button", { name: "Payment terms for the March shipment" }));
    expect(p.onOpenRecording).toHaveBeenCalledWith("bilal");
    expect(within(list).queryByRole("button", { name: "Weekly sales review" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Projects" }));
    expect(p.onBack).toHaveBeenCalled();
  });

  it("Ask asks this project, without a project choice or a second title", async () => {
    respond("client_ask", { data: { answer: "40% in advance.", citations: [] } });
    await show();
    fireEvent.click(screen.getByRole("tab", { name: "Ask" }));
    expect(screen.queryByRole("combobox", { name: /Ask about/ })).toBeNull();
    expect(screen.getAllByRole("heading", { level: 1 })).toHaveLength(1);
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "What's the advance?" } });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    expect(callsOf("client_ask")).toEqual([{ id: "c1", question: "What's the advance?", language: "en" }]);
    expect(screen.getByText("40% in advance.")).toBeInTheDocument();
  });

  it("memory & instructions: the brief, memory by kind with where it came from, pin, delete after asking, add", async () => {
    respond("client_memory_update", { data: { ...WS.memory[0], pinned: true } });
    respond("client_memory_delete", null);
    respond("client_memory_add", { data: line("m9", "change", "Delivery moved to Karachi port") });
    const p = await show();
    fireEvent.click(screen.getByRole("tab", { name: "Memory & instructions" }));
    expect(screen.getByRole("region", { name: "Brief" })).toHaveTextContent("Ship 40,000 metres of cotton lawn in March.");
    const memory = screen.getByRole("list", { name: "Memory" });
    expect(within(memory).getByText("Decisions")).toBeInTheDocument();
    expect(within(memory).getByText("From a call at 0:31")).toBeInTheDocument();
    fireEvent.click(within(memory).getByRole("button", { name: "Open" }));
    expect(p.onOpenRecording).toHaveBeenCalledWith("bilal", 31_000);
    fireEvent.click(within(memory).getAllByRole("button", { name: "Pin" })[0]);
    await act(flushAsync);
    expect(callsOf("client_memory_update")).toEqual([{ id: "c1", item: "m1", patch: { pinned: true } }]);

    fireEvent.click(within(memory).getByRole("button", { name: 'Delete "Which bank opens the LC?"' }));
    const dialog = screen.getByRole("alertdialog", { name: "Delete this line from memory?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("client_memory_delete")).toEqual([{ id: "c1", item: "m2" }]);
    expect(screen.queryByText("Which bank opens the LC?")).toBeNull();

    fireEvent.click(screen.getByRole("combobox", { name: /Kind/ }));
    fireEvent.click(screen.getByRole("option", { name: "Change" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Add a line" }), { target: { value: "Delivery moved to Karachi port" } });
    fireEvent.click(screen.getByRole("button", { name: "Add to memory" }));
    await act(flushAsync);
    expect(callsOf("client_memory_add")).toEqual([{ id: "c1", kind: "change", text: "Delivery moved to Karachi port", happenedOn: null }]);
    expect(screen.getByText("Delivery moved to Karachi port")).toBeInTheDocument();
  });

  it("instructions: listed, added and deleted", async () => {
    respond("client_instructions_add", { data: { id: "f3", name: "Instructions 2", mime: "text/plain", size_bytes: 1, pages: null, status: "ready", error: null, instructions: true } });
    respond("client_file_delete", null);
    await show();
    fireEvent.click(screen.getByRole("tab", { name: "Memory & instructions" }));
    const list = screen.getByRole("list", { name: "Instructions" });
    expect(within(list).getByText("Instructions")).toBeInTheDocument();
    expect(within(list).queryByText("Proforma March.pdf")).toBeNull();
    fireEvent.change(screen.getByRole("textbox", { name: "Instructions for answers and notes" }), { target: { value: "Amounts in rupees." } });
    fireEvent.click(screen.getByRole("button", { name: "Save instructions" }));
    await act(flushAsync);
    expect(callsOf("client_instructions_add")).toEqual([{ id: "c1", name: null, text: "Amounts in rupees." }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Instructions saved");
    fireEvent.click(screen.getByRole("button", { name: 'Delete "Instructions"' }));
    fireEvent.click(within(screen.getByRole("alertdialog")).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("client_file_delete")).toEqual([{ id: "c1", file: "f2" }]);
  });

  it("states: no recordings yet, and memory that didn't load with Try again", async () => {
    respond("library_list", []);
    fail("client_workspace", "The server is out of reach.");
    await show();
    expect(screen.getByRole("region", { name: "No recordings in this project yet" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("tab", { name: "Memory & instructions" }));
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("The project's memory didn't load");
    respond("client_workspace", { data: WS });
    fireEvent.click(within(alert).getByRole("button", { name: "Try again" }));
    await act(flushAsync);
    expect(screen.getByRole("region", { name: "Brief" })).toBeInTheDocument();
  });
});

describe("the app with ui_v2.ask", () => {
  it("a project opens its new page", async () => {
    respond("app_status", makeStatus());
    respond("library_refresh", ITEMS);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [{ id: "c1", name: "Qureshi Textiles" }] });
    respond("mic_users", []);
    respond("session_detail", {});
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.ask": true }, ui_classic: false });
    respond("client_ask", { data: { answer: "x", citations: [{ type: "file", file_id: "f1", title: "Proforma March.pdf", page: 2 }] } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    fireEvent.change(await screen.findByRole("textbox", { name: "Your question" }), { target: { value: "Which proforma?" } });
    fireEvent.click(within(document.querySelector("[data-v2=ask]") as HTMLElement).getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    fireEvent.click(within(screen.getByRole("list", { name: "From" })).getByRole("button", { name: "Proforma March.pdf" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Qureshi Textiles" })).toBeInTheDocument();
    expect(document.querySelector("[data-v2=workspace]")).not.toBeNull();
  });
});
