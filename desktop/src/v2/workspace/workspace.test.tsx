import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import type { ClientFolder, ClientWorkspace, MemoryLine } from "../../lib/ipc";
import MainApp from "../../views/main/MainApp";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { fileDropClaim } from "../../lib/dropTarget";
import { answerConfirm } from "../../test/confirm";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { WorkspaceV2, memoryGroups } from "./WorkspaceV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const here = dirname(fileURLToPath(import.meta.url));
const PROJECT = { id: "c1", name: "Qureshi Textiles", ai_notes: true };
const PROJECTS: ClientFolder[] = [{ id: "c1", name: "Qureshi Textiles" }, { id: "c1a", name: "March order", parent_id: "c1" }];
const line = (id: string, kind: MemoryLine["kind"], text: string, over: Partial<MemoryLine> = {}): MemoryLine =>
  ({ id, kind, text, happened_on: null, pinned: false, source: null, ...over });
const WS: ClientWorkspace = {
  client: { id: "c1", name: "Qureshi Textiles", ai_notes: true },
  ai: { configured: true, spent_usd: 1.2, budget_usd: 5, client_spent_usd: 0.4 },
  brief: { sections: { goal: "Ship 40,000 metres of cotton lawn in March.", deadlines: ["Packing done by 21 March"], budget: ["40% advance"] }, edited: false, status: "ready", error: null, generated_at: null, updated_at: null },
  memory: [
    line("m1", "decision", "40% in advance, the balance against the bill of lading", { source: { type: "call", id: "bilal", at_ms: 31_000 } }),
    line("m2", "question", "Which bank opens the LC?"),
    line("m3", "decision", "Packing done by 21 March", { pinned: true }),
    line("m4", "change", "Delivery moved to the 25th", { happened_on: "2026-11-14" }),
  ],
  notes: [{ id: "n1", session_id: "bilal", status: "ready", error: null, language: "en", created_at: "2026-11-17T07:00:00Z",
    sections: [{ title: "Decisions", items: [{ text: "40% advance", verify: false, lines: [3], at_ms: 31_000 }] }] as never,
    session: { id: "bilal", title: "Payment terms for the March shipment", started_at: "2026-11-17T06:42:00Z", duration_ms: 1_104_000 } }],
  files: [
    { id: "f1", name: "Proforma March.pdf", mime: "application/pdf", size_bytes: 120_000, pages: 2, status: "ready", error: null, instructions: false },
    { id: "f2", name: "House rules", mime: "text/plain", size_bytes: 900, pages: null, status: "ready", error: null, instructions: true },
  ],
};
const ITEMS = [
  makeItem("bilal", { title: "Payment terms for the March shipment", client: { id: "c1", name: "Qureshi Textiles" } }),
  makeItem("order", { title: "Cutting schedule", client: { id: "c1a", name: "March order" } }),
  makeItem("other", { title: "Weekly sales review", client: { id: "c2", name: "Mehran Foods" } }),
];

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(null);
  respond("library_list", ITEMS);
  respond("client_workspace", { data: WS });
  respond("client_screens", null);
  respond("client_rules", { data: [{ id: 7, kind: "title", value: "Qureshi" }] });
  respond("search_library", { data: [], next_page: null });
});

const props = () => ({ onBack: vi.fn(), onOpenRecording: vi.fn(), onLibrary: vi.fn(), onOpenProject: vi.fn(), onMoveRecordings: vi.fn(), onProjectChanged: vi.fn() });
const full = (over: Record<string, unknown> = {}) => ({
  project: PROJECT, projects: PROJECTS, parent: null, folders: [{ id: "c1a", name: "March order", count: 1 }],
  menu: [{ label: "Rename", onPick: vi.fn() }, { label: "Delete project…", danger: true, onPick: vi.fn() }], onShare: vi.fn(), rules: true,
  ...props(), ...over,
});
const show = async (over: Record<string, unknown> = {}) => {
  const p = full(over);
  render(<WorkspaceV2 {...p} />);
  await act(flushAsync);
  return p;
};
const openTab = async (name: string) => {
  fireEvent.click(screen.getByRole("tab", { name }));
  await act(flushAsync);
};

describe("memory groups", () => {
  it("puts lines under their kind, pinned first", () => {
    expect(memoryGroups(WS.memory).map((g) => [g.heading, g.lines.map((l) => l.id)])).toEqual([["Decisions", ["m3", "m1"]], ["Changes", ["m4"]], ["Open questions", ["m2"]]]);
  });
});

/**
 * Parity with today's project page: every action and control ClientWorkspace.tsx offers (its button words and
 * accessible names, read from its source) is mapped to where the new page has it, and each one is found there. A new
 * action on today's page fails this test until the new page has it too.
 */
describe("parity with today's project page", () => {
  type Where = { tab: string; role: Parameters<typeof screen.getByRole>[0]; name: string | RegExp } | { reason: string };
  const WHERE: Record<string, Where> = {
    "Add files": { tab: "Files", role: "button", name: "Add files" },
    "Paste instructions": { tab: "Files", role: "button", name: "Paste instructions" },
    "Save instructions": { tab: "Files", role: "button", name: "Save instructions" },
    "Name": { tab: "Files", role: "textbox", name: "Name" },
    "Instructions": { tab: "Files", role: "textbox", name: "Instructions" },
    "Files": { tab: "Files", role: "list", name: "Files" },
    "Remove file": { tab: "Files", role: "button", name: "Remove Proforma March.pdf" },
    "Cancel": { tab: "Memory & instructions", role: "button", name: "Cancel" },
    "Edit": { tab: "Memory & instructions", role: "button", name: "Edit" },
    "Save brief": { tab: "Memory & instructions", role: "button", name: "Save brief" },
    "Make again": { tab: "Memory & instructions", role: "button", name: "Write again" },
    "AI": { tab: "Memory & instructions", role: "switch", name: "AI notes" },
    "Add": { tab: "Memory & instructions", role: "button", name: "Add to memory" },
    "Add to memory": { tab: "Memory & instructions", role: "textbox", name: "Add a line" },
    "Kind": { tab: "Memory & instructions", role: "combobox", name: /^Kind/ },
    "Pin": { tab: "Memory & instructions", role: "button", name: "Pin" },
    "Remove memory line": { tab: "Memory & instructions", role: "button", name: "Remove “Which bank opens the LC?”" },
    "Open": { tab: "Memory & instructions", role: "button", name: "Open" },
    "Ask": { tab: "Ask", role: "button", name: "Ask" },
    "Question": { tab: "Ask", role: "textbox", name: "Your question" },
    "Answer in": { tab: "Ask", role: "radiogroup", name: "Answer in" },
    "English": { tab: "Ask", role: "radio", name: "English" },
    "Roman Urdu": { tab: "Ask", role: "radio", name: "Roman Urdu" },
    "Copy answer": { tab: "Ask", role: "button", name: "Copy answer" },
    "Clear the answer": { tab: "Ask", role: "button", name: "Clear the answer" },
    "Sources": { tab: "Ask", role: "list", name: "From" },
    "Call notes": { tab: "Call notes", role: "button", name: "Open" },
    "Search in this project": { tab: "Recordings", role: "searchbox", name: "Search Qureshi Textiles" },
    "Clear search": { tab: "Recordings", role: "button", name: "Clear search" },
    "Sort recordings": { tab: "Recordings", role: "combobox", name: /^Sort recordings/ },
    "Include its folders": { tab: "Recordings", role: "checkbox", name: "Include its folders" },
    "Folders": { tab: "Recordings", role: "list", name: "Folders" },
    "Matches in transcripts": { tab: "Recordings", role: "list", name: "Matches in transcripts" },
    "Play": { tab: "Recordings", role: "button", name: /^Payment terms for the March shipment$/ },
    "Filing rules": { tab: "Recordings", role: "button", name: "Add rule" },
    "Where this project is": { tab: "Recordings", role: "button", name: "Projects" },
    "Projects": { tab: "Recordings", role: "button", name: "Projects" },
    "Project menu": { tab: "Recordings", role: "button", name: "Project menu" },
    "Share": { tab: "Recordings", role: "button", name: "Share" },
    "Screens": { tab: "Screens", role: "region", name: "No screens here" },
    "Retry": { reason: "each part that can fail to load says so with Try again (tested under states)" },
  };
  /** Today's words for an action, from its source: button text and the start of each aria-label. */
  const todays = (): string[] => {
    const src = readFileSync(join(here, "..", "..", "views", "main", "ClientWorkspace.tsx"), "utf8");
    const words = new Set<string>();
    for (const m of src.matchAll(/<button[^>]*>(?:\s*<[^>]+\/>)*\s*([A-Z][A-Za-z ]+?)\s*</g)) words.add(m[1].trim());
    for (const m of src.matchAll(/aria-label="([^"]+)"/g)) words.add(m[1]);
    for (const m of src.matchAll(/aria-label=\{`([^`$]*)/g)) words.add(m[1].trim() || "Pin");
    return [...words].map((w) => (w === "Remove" ? "Remove file" : w.startsWith("Remove “") ? "Remove memory line" : w));
  };
  const SAME_NAMES: Record<string, string> = { "Remove": "Remove file", "Remove “": "Remove memory line" };

  it("maps every action today's page has", () => {
    const today = todays().map((w) => SAME_NAMES[w] ?? w);
    expect(today.length).toBeGreaterThan(20);
    expect(today.filter((w) => !(w in WHERE))).toEqual([]);
  });

  it.each(Object.entries(WHERE).filter(([, w]) => !("reason" in w)) as [string, Exclude<Where, { reason: string }>][])("finds %s on the new page", async (label, where) => {
    respond("client_ask", { data: { answer: "40% in advance.", citations: [{ type: "call", session_id: "bilal", title: "Payment terms", at_ms: 31_000 }] } });
    respond("search_library", { data: [{ session: ITEMS[0], hit_count: 1, hits: [{ transcript_id: "t1", segment_index: 3, start_ms: 31_000, speaker: "Bilal", snippet: "forty percent in advance", highlights: [[0, 5]] }] }], next_page: null });
    await show();
    await openTab(where.tab);
    if (where.tab === "Files" && /Name|Instructions|Save instructions/.test(String(where.name))) fireEvent.click(screen.getByRole("button", { name: "Paste instructions" }));
    if (where.tab === "Memory & instructions" && /Cancel|Save brief/.test(String(where.name))) fireEvent.click(screen.getByRole("button", { name: "Edit" }));
    if (where.tab === "Ask" && /Copy answer|Clear the answer|From/.test(String(where.name))) {
      fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "What's the advance?" } });
      fireEvent.click(within(document.querySelector("[data-v2=ask]") as HTMLElement).getByRole("button", { name: "Ask" }));
      await act(flushAsync);
    }
    if (where.tab === "Recordings" && (label === "Play" || /Search|Clear search|Matches/.test(String(where.name)))) {
      fireEvent.change(screen.getByRole("searchbox", { name: "Search Qureshi Textiles" }), { target: { value: "advance" } });
      await act(async () => { await new Promise((r) => setTimeout(r, 400)); });
      await act(flushAsync);
    }
    expect(screen.getAllByRole(where.role, { name: where.name }).length).toBeGreaterThan(0);
  });
});

describe("the new project page does what today's does", () => {
  it("files: Add files uploads what was picked; a dropped file is added while Files is open; Remove asks first", async () => {
    respond("client_files_pick", ["/Users/ayesha/Documents/Proforma April.pdf", "/Users/ayesha/Pictures/logo.png"]);
    respond("client_file_upload", { data: { id: "f3", name: "Proforma April.pdf", mime: "application/pdf", size_bytes: 1, pages: 1, status: "extracting", error: null, instructions: false } });
    respond("client_file_delete", null);
    await show();
    await openTab("Files");
    fireEvent.click(screen.getByRole("button", { name: "Add files" }));
    await act(flushAsync);
    expect(callsOf("client_file_upload")).toEqual([{ id: "c1", path: "/Users/ayesha/Documents/Proforma April.pdf" }]);
    expect(screen.getByRole("alert")).toHaveTextContent("Only PDF, Word (.docx), text and Markdown files can be added.");
    expect(within(screen.getByRole("list", { name: "Files" })).getByText("Proforma April.pdf")).toBeInTheDocument();

    // Files dropped on the window go to this project while Files is open (the main window hands them to the claim).
    expect(fileDropClaim()).not.toBeNull();
    await act(async () => fileDropClaim()!(["/Users/ayesha/Documents/Scope.docx"]));
    expect(callsOf("client_file_upload")[1]).toEqual({ id: "c1", path: "/Users/ayesha/Documents/Scope.docx" });

    const asked = answerConfirm();
    asked.mockReturnValue(false);
    fireEvent.click(screen.getByRole("button", { name: "Remove Proforma March.pdf" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Remove Proforma March.pdf from this project?");
    expect(callsOf("client_file_delete")).toHaveLength(0);
    asked.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Remove Proforma March.pdf" }));
    await act(flushAsync);
    expect(callsOf("client_file_delete")).toEqual([{ id: "c1", file: "f1" }]);
  });

  it("instructions are pasted with a name, and removed after asking", async () => {
    respond("client_instructions_add", { data: { id: "f9", name: "Payment rules", mime: "text/plain", size_bytes: 1, pages: null, status: "ready", error: null, instructions: true } });
    await show();
    await openTab("Files");
    fireEvent.click(screen.getByRole("button", { name: "Paste instructions" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Name" }), { target: { value: "Payment rules" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Instructions" }), { target: { value: "Amounts in rupees." } });
    fireEvent.click(screen.getByRole("button", { name: "Save instructions" }));
    await act(flushAsync);
    expect(callsOf("client_instructions_add")).toEqual([{ id: "c1", name: "Payment rules", text: "Amounts in rupees." }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Instructions saved");
    await openTab("Memory & instructions");
    expect(within(screen.getByRole("list", { name: "Instructions" })).getByText("Payment rules")).toBeInTheDocument();
  });

  it("the brief: Edit and Save brief; Write again asks first when it was edited", async () => {
    respond("client_brief_save", { data: { ...WS.brief!, sections: { goal: "Ship by 25 March" }, edited: true } });
    respond("client_brief_refresh", { data: WS.brief });
    await show();
    await openTab("Memory & instructions");
    fireEvent.click(screen.getByRole("button", { name: "Edit" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Goal" }), { target: { value: "Ship by 25 March" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Deadlines" }), { target: { value: "Packing done\n\nLC opened by the 20th " } });
    fireEvent.click(screen.getByRole("button", { name: "Save brief" }));
    await act(flushAsync);
    expect(callsOf("client_brief_save")[0]).toMatchObject({ id: "c1", sections: { goal: "Ship by 25 March", deadlines: ["Packing done", "LC opened by the 20th"] } });
    expect(screen.getByRole("region", { name: "Brief" })).toHaveTextContent("Ship by 25 March");
    const asked = answerConfirm();
    fireEvent.click(screen.getByRole("button", { name: "Write again" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Write the brief again? It's written from the project's files and call notes. Your edits are replaced.");
    expect(callsOf("client_brief_refresh")).toHaveLength(0);
  });

  it("Ask copies its answer", async () => {
    respond("client_ask", { data: { answer: "40% in advance.", citations: [] } });
    const writeText = vi.fn(async () => undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    await show();
    await openTab("Ask");
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "What's the advance?" } });
    fireEvent.click(within(document.querySelector("[data-v2=ask]") as HTMLElement).getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Copy answer" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("40% in advance.");
    expect(screen.getByRole("button", { name: "Copied" })).toBeInTheDocument();
  });

  it("a plan without AI notes: no Ask or call notes, the reason with its badge, the brief by hand", async () => {
    await show({ aiNotesInPlan: false });
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Recordings", "Files", "Memory & instructions", "Screens"]);
    await openTab("Memory & instructions");
    expect(screen.getByTestId("ai-not-in-plan")).toHaveTextContent("not in your workspace's plan");
    expect(screen.getByRole("button", { name: "Edit" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Write again" })).toBeNull();
  });

  it("the AI notes switch turns call notes off for the project", async () => {
    respond("client_update", { data: { id: "c1", name: "Qureshi Textiles", ai_notes: false } });
    const p = await show();
    expect(screen.getByRole("tab", { name: "Call notes" })).toBeInTheDocument();
    await openTab("Memory & instructions");
    expect(screen.getByTestId("ai-budget")).toHaveTextContent("AI this month");
    fireEvent.click(screen.getByRole("switch", { name: "AI notes" }));
    await act(flushAsync);
    expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { ai_notes: false } }]);
    expect(p.onProjectChanged).toHaveBeenCalledWith({ id: "c1", ai_notes: false });
    expect(screen.queryByRole("tab", { name: "Call notes" })).toBeNull();
  });

  it("recordings: its folders' recordings with Include its folders; a row can start a drag onto a project", async () => {
    const p = await show();
    const list = () => screen.getByRole("list", { name: "Recordings" });
    expect(within(list()).queryByRole("button", { name: "Cutting schedule" })).toBeNull();
    fireEvent.click(screen.getByRole("checkbox", { name: "Include its folders" }));
    expect(within(list()).getByRole("button", { name: "Cutting schedule" })).toHaveAccessibleDescription(/March order/);
    fireEvent.pointerDown(within(list()).getByRole("button", { name: "Payment terms for the March shipment" }), { button: 0, clientX: 10, clientY: 10 });
    fireEvent.click(within(list()).getByRole("button", { name: "Payment terms for the March shipment" }));
    expect(p.onOpenRecording).toHaveBeenCalledWith("bilal");
  });

  it("memory: pin, remove after asking, add; two changes in flight both stay", async () => {
    let pin: (v: unknown) => void = () => undefined;
    respondWith("client_memory_update", () => new Promise((r) => (pin = r)));
    respond("client_memory_add", { data: line("m8", "decision", "Insurance by the buyer") });
    respond("client_memory_delete", null);
    await show();
    await openTab("Memory & instructions");
    const memory = () => screen.getByRole("list", { name: "Memory" });
    fireEvent.click(within(memory()).getAllByRole("button", { name: "Pin" })[0]);
    fireEvent.change(screen.getByRole("textbox", { name: "Add a line" }), { target: { value: "Insurance by the buyer" } });
    fireEvent.click(screen.getByRole("button", { name: "Add to memory" }));
    await act(flushAsync);
    await act(async () => pin({ data: { ...WS.memory[0], pinned: true } }));
    expect(within(memory()).getByText("Insurance by the buyer")).toBeInTheDocument();
    expect(within(memory()).getAllByText(/^Pinned/).length).toBe(2);
    expect(within(memory()).getByText("14 Nov")).toBeInTheDocument();

    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(within(memory()).getByRole("button", { name: "Remove “Which bank opens the LC?”" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Remove this line from memory? “Which bank opens the LC?”");
    expect(callsOf("client_memory_delete")).toEqual([{ id: "c1", item: "m2" }]);
  });

  it("the project menu: focus back on its button after a pick; ArrowDown and ArrowUp on the button open it", async () => {
    const p = await show();
    const opener = screen.getByRole("button", { name: "Project menu" });
    opener.focus();
    fireEvent.keyDown(opener, { key: "ArrowUp" });
    const items = within(screen.getByRole("menu", { name: "Project menu" })).getAllByRole("menuitem");
    expect(items[1]).toHaveFocus();
    fireEvent.keyDown(items[1], { key: "Escape" });
    fireEvent.keyDown(opener, { key: "ArrowDown" });
    expect(within(screen.getByRole("menu")).getAllByRole("menuitem")[0]).toHaveFocus();
    fireEvent.keyDown(within(screen.getByRole("menu")).getAllByRole("menuitem")[0], { key: "Enter" });
    expect((p.menu as { onPick: ReturnType<typeof vi.fn> }[])[0].onPick).toHaveBeenCalled();
    expect(screen.queryByRole("menu")).toBeNull();
    expect(opener).toHaveFocus();
  });

  it("filing rules: Undo says whether the rule came back", async () => {
    respond("client_rule_delete", null);
    respond("client_rule_add", { data: { id: 9, kind: "title", value: "Qureshi" } });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Remove the rule Title contains “Qureshi”" }));
    await act(flushAsync);
    fireEvent.click(within(screen.getByRole("status", { name: "Notifications" })).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Rule restored");

    fail("client_rule_add", "This project already has 50 rules.");
    fireEvent.click(screen.getByRole("button", { name: "Remove the rule Title contains “Qureshi”" }));
    await act(flushAsync);
    fireEvent.click(within(screen.getByRole("status", { name: "Notifications" })).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("The rule couldn't be restored: This project already has 50 rules.");
  });

  it("states: no recordings yet, and a workspace that didn't load with Try again", async () => {
    respond("library_list", []);
    fail("client_workspace", "The server is out of reach.");
    await show({ folders: [] });
    expect(screen.getByRole("region", { name: "No recordings in this project yet" })).toBeInTheDocument();
    await openTab("Memory & instructions");
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("The project's workspace didn't load");
    respond("client_workspace", { data: WS });
    fireEvent.click(within(alert).getByRole("button", { name: "Try again" }));
    await act(flushAsync);
    expect(screen.getByRole("region", { name: "Brief" })).toBeInTheDocument();
  });
});

describe("the app's project page with ui_v2.ask keeps today's actions", () => {
  it("the project menu opens today's dialogs (Rename)", async () => {
    respond("app_status", makeStatus());
    respond("library_refresh", ITEMS);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: PROJECTS });
    respond("mic_users", []);
    respond("session_detail", {});
    respond("request_links", null);
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
    expect(await screen.findByRole("list", { name: "Folders" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Share" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Project menu" }));
    expect(within(screen.getByRole("menu")).getAllByRole("menuitem").map((i) => i.textContent)).toContain("Delete project…");
    fireEvent.click(screen.getByRole("menuitem", { name: "Rename" }));
    expect(await screen.findByRole("dialog", { name: "Rename Qureshi Textiles" })).toBeInTheDocument();
  });
});
