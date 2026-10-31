// Client workspaces (docs/API.md "Client workspaces", since v1.4): the Clients page and a client's Overview, Recordings,
// Files and Ask tabs, driven through the same commands the Rust core exposes.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { ClientsView } from "./ClientsView";
import { budgetLine, citationLabel, workspaceBusy } from "./ClientWorkspace";
import type { ClientWorkspace, LibraryItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const CLIENTS = [
  { id: "c1", name: "Acme Holdings", session_count: 2, ai_notes: true },
  { id: "c2", name: "Zed Logistics", session_count: 0, ai_notes: true },
];
const RECORDINGS: LibraryItem[] = [
  makeItem("s1", { title: "Kick-off", client: { id: "c1", name: "Acme Holdings" }, started_at: "2026-09-30T10:00:00Z", duration_ms: 1_800_000, source_app: "zoom" }),
  makeItem("s2", { title: "Budget review", client: { id: "c1", name: "Acme Holdings" }, started_at: "2026-09-28T10:00:00Z", duration_ms: 600_000, source_app: "whatsapp" }),
];

function workspace(overrides: Partial<ClientWorkspace> = {}): ClientWorkspace {
  return {
    client: { id: "c1", name: "Acme Holdings", ai_notes: true },
    ai: { configured: true, spent_usd: 0.42, budget_usd: 5, client_spent_usd: 0.12 },
    brief: {
      sections: { goal: "An online shop", scope: ["Login"], constraints: [], deadlines: ["Nov 15"], budget: [], people: ["Ali (owner)"], glossary: [] },
      edited: false, status: "ready", error: null, generated_at: "2026-10-30T10:00:00Z", updated_at: "2026-10-30T10:00:00Z",
    },
    memory: [
      { id: "m1", kind: "decision", text: "Launch on November 15", happened_on: "2026-10-30", pinned: false, source: { type: "call", id: "s1", at_ms: 125_000 } },
      { id: "m2", kind: "question", text: "Who writes the copy?", happened_on: "2026-10-30", pinned: false, source: { type: "user" } },
      { id: "m3", kind: "change", text: "Guest checkout added", happened_on: "2026-10-30", pinned: false, source: { type: "call", id: "s1", at_ms: 200_000 } },
      { id: "m4", kind: "scope", text: "Stripe only", happened_on: "2026-10-29", pinned: true, source: { type: "file", id: "f1" } },
    ],
    notes: [{
      id: "n1", session_id: "s1", status: "ready", error: null, language: "en", created_at: "2026-10-30T11:00:00Z",
      session: { id: "s1", title: "Kick-off", started_at: "2026-10-30T10:00:00Z", duration_ms: 1_800_000 },
      sections: [{ key: "decisions", heading: "Decisions", items: [
        { text: "Launch on November 15", verify: false, lines: [3], at_ms: 125_000 },
        { text: "Budget is $12,000", verify: true, lines: [9], at_ms: 400_000 },
      ] }],
    }],
    files: [
      { id: "f1", name: "scope.pdf", mime: "application/pdf", size_bytes: 182_340, pages: 4, status: "ready", error: null, instructions: false, created_at: "2026-10-29T10:00:00Z" },
      { id: "f2", name: "notes.docx", mime: null, size_bytes: 1_000, pages: null, status: "failed", error: "The file is password protected.", instructions: false, created_at: "2026-10-29T10:00:00Z" },
    ],
    ...overrides,
  };
}

const writeText = vi.fn((_text: string) => Promise.resolve());

async function renderClients(ws: ClientWorkspace = workspace()) {
  respond("list_clients", { data: CLIENTS });
  respond("library_list", RECORDINGS);
  respondWith("client_workspace", ({ id }) => ({ data: id === "c1" ? ws : workspace({ client: { id: "c2", name: "Zed Logistics", ai_notes: true }, brief: null, memory: [], notes: [], files: [] }) }));
  const props = { notify: vi.fn(), onOpenRecording: vi.fn() };
  render(<ClientsView {...props} />);
  // Microtasks only (no polling helpers), so tests on a fake clock render the same way.
  await flushAsync();
  await flushAsync();
  expect(screen.getByRole("heading", { name: "Acme Holdings" })).toBeInTheDocument();
  return props;
}
const tab = (name: string) => fireEvent.click(screen.getByRole("tab", { name }));

beforeEach(() => {
  resetTauriMock();
  writeText.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
});
afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("the Clients page", () => {
  it("lists the clients like the phone and opens the first one's workspace", async () => {
    await renderClients();
    const list = screen.getByRole("list", { name: "Clients" });
    expect(within(list).getByText("Acme Holdings")).toBeInTheDocument();
    expect(within(list).getByText("2 recordings · Last call Sep 30")).toBeInTheDocument();
    expect(within(list).getByText("No recordings yet")).toBeInTheDocument();
    expect(callsOf("client_workspace")).toEqual([{ id: "c1" }]);
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Overview", "Recordings", "Files", "Ask"]);
    expect(screen.getByRole("tab", { name: "Overview" })).toHaveAttribute("aria-selected", "true");
  });

  it("adds a client", async () => {
    respond("client_create", { data: { id: "c3", name: "Nova" } });
    await renderClients();
    fireEvent.click(screen.getByRole("button", { name: "New client" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Client name" }), { target: { value: "Nova" } });
    fireEvent.click(screen.getByRole("button", { name: "Add client" }));
    await waitFor(() => expect(callsOf("client_create")).toEqual([{ name: "Nova", color: null, avatar: null }]));
    expect(await screen.findByRole("heading", { name: "Nova" })).toBeInTheDocument();
  });

  it("says so when the workspace cannot be loaded, and retries", async () => {
    respond("list_clients", { data: CLIENTS });
    respond("library_list", []);
    fail("client_workspace", "Network error");
    render(<ClientsView notify={vi.fn()} onOpenRecording={vi.fn()} />);
    expect(await screen.findByText(/Couldn't load Acme Holdings's workspace/)).toBeInTheDocument();
    respond("client_workspace", { data: workspace() });
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    expect(await screen.findByText("An online shop")).toBeInTheDocument();
  });
});

describe("Overview", () => {
  it("turns AI notes off for the client and shows the month's AI budget", async () => {
    respond("client_update", { data: { ...CLIENTS[0], ai_notes: false } });
    await renderClients();
    expect(screen.getByTestId("ai-budget")).toHaveTextContent("AI this month: $0.42 of $5.00 · this client $0.12");
    const toggle = screen.getByRole("switch", { name: "AI notes" });
    expect(toggle).toHaveAttribute("aria-checked", "true");
    fireEvent.click(toggle);
    await waitFor(() => expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { ai_notes: false } }]));
    expect(screen.getByRole("switch", { name: "AI notes" })).toHaveAttribute("aria-checked", "false");
  });

  it("edits the brief, one line per item", async () => {
    respondWith("client_brief_save", ({ sections }) => ({ data: { ...workspace().brief, sections, edited: true } }));
    await renderClients();
    expect(screen.getByText("An online shop")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Edit" }));
    fireEvent.change(screen.getByRole("textbox", { name: /^Scope/ }), { target: { value: "Login\n  Payments  \n\n" } });
    fireEvent.click(screen.getByRole("button", { name: "Save brief" }));
    await waitFor(() => expect(callsOf("client_brief_save")).toHaveLength(1));
    const sent = callsOf("client_brief_save")[0];
    expect(sent.id).toBe("c1");
    expect((sent.sections as Record<string, unknown>).scope).toEqual(["Login", "Payments"]);
    expect((sent.sections as Record<string, unknown>).goal).toBe("An online shop");
    expect(await screen.findByText("Payments")).toBeInTheDocument();
    expect(screen.getByText("Edited by you")).toBeInTheDocument();
  });

  it("makes the brief again and asks every 5 s until it is written", async () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    let brief = { ...workspace().brief!, status: "working" as const };
    respondWith("client_brief_refresh", () => ({ data: brief }));
    await renderClients();
    respondWith("client_workspace", () => ({ data: workspace({ brief }) }));
    fireEvent.click(screen.getByRole("button", { name: "Make again" }));
    await flushAsync();
    expect(callsOf("client_brief_refresh")).toEqual([{ id: "c1" }]);
    expect(screen.getByText(/Writing the brief/)).toBeInTheDocument();
    const loads = callsOf("client_workspace").length;
    await act(async () => { vi.advanceTimersByTime(5_000); });
    await flushAsync();
    expect(callsOf("client_workspace").length).toBe(loads + 1);
    brief = { ...workspace().brief!, sections: { goal: "A better shop" }, status: "ready" };
    await act(async () => { vi.advanceTimersByTime(5_000); });
    await flushAsync();
    expect(screen.getByText("A better shop")).toBeInTheDocument();
    const done = callsOf("client_workspace").length;
    await act(async () => { vi.advanceTimersByTime(20_000); });
    expect(callsOf("client_workspace").length).toBe(done);
  });

  it("groups the memory, and pins, removes and adds lines", async () => {
    respondWith("client_memory_update", ({ item, patch }) => ({ data: { ...workspace().memory.find((m) => m.id === item)!, ...(patch as object) } }));
    respond("client_memory_delete", undefined);
    respond("client_memory_add", { data: { id: "m9", kind: "question", text: "When is the review?", happened_on: "2026-10-31", pinned: false, source: { type: "user" } } });
    await renderClients();
    const headings = [...document.querySelectorAll(".memory-group h4")].map((h) => h.textContent);
    expect(headings).toEqual(["Decisions", "Open questions", "Changes", "Scope"]);
    const decisions = screen.getByRole("group", { name: "Decisions" });
    fireEvent.click(within(decisions).getByRole("button", { name: "Pin “Launch on November 15”" }));
    await waitFor(() => expect(callsOf("client_memory_update")).toEqual([{ id: "c1", item: "m1", patch: { pinned: true } }]));
    expect(await within(decisions).findByRole("button", { name: "Unpin “Launch on November 15”" })).toHaveAttribute("aria-pressed", "true");

    fireEvent.click(screen.getByRole("button", { name: "Remove “Guest checkout added”" }));
    await waitFor(() => expect(callsOf("client_memory_delete")).toEqual([{ id: "c1", item: "m3" }]));
    expect(screen.queryByText("Guest checkout added")).not.toBeInTheDocument();

    fireEvent.change(screen.getByRole("combobox", { name: "Kind" }), { target: { value: "question" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Add to memory" }), { target: { value: "When is the review?" } });
    fireEvent.click(screen.getByRole("button", { name: "Add" }));
    await waitFor(() => expect(callsOf("client_memory_add")).toEqual([{ id: "c1", kind: "question", text: "When is the review?", happenedOn: null }]));
    expect(await within(screen.getByRole("group", { name: "Open questions" })).findByText("When is the review?")).toBeInTheDocument();
  });

  it("opens the recording at a call note's moment and marks lines to verify", async () => {
    const { onOpenRecording } = await renderClients();
    const notes = screen.getByRole("region", { name: "Latest call notes" });
    fireEvent.click(within(notes).getByRole("button", { name: /Launch on November 15/ }));
    expect(onOpenRecording).toHaveBeenCalledWith("s1", 125_000);
    expect(within(within(notes).getByRole("button", { name: /Budget is \$12,000/ })).getByText("verify")).toBeInTheDocument();
    expect(within(within(notes).getByRole("button", { name: /Launch on November 15/ })).queryByText("verify")).toBeNull();
    // A memory line from a call plays its moment too.
    fireEvent.click(within(screen.getByRole("group", { name: "Changes" })).getByRole("button", { name: /3:20/ }));
    expect(onOpenRecording).toHaveBeenLastCalledWith("s1", 200_000);
  });

  it("copies the brief, memory and notes for AI", async () => {
    respond("client_bundle", "# Acme: project context\n\n## Brief\n…");
    const { notify } = await renderClients();
    fireEvent.click(screen.getByRole("button", { name: "Copy for AI" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith("# Acme: project context\n\n## Brief\n…"));
    expect(callsOf("client_bundle")).toEqual([{ id: "c1", notes: null }]);
    expect(notify).toHaveBeenCalledWith("Copied the brief, memory and recent call notes for AI");
  });
});

describe("Recordings", () => {
  it("lists the client's recordings and opens one", async () => {
    const { onOpenRecording } = await renderClients();
    tab("Recordings");
    fireEvent.click(screen.getByText("Budget review"));
    expect(onOpenRecording).toHaveBeenCalledWith("s2");
  });
});

describe("Files", () => {
  it("shows each file's state: read, reading or failed", async () => {
    await renderClients(workspace({ files: [...workspace().files, { id: "f3", name: "brief.md", mime: "text/markdown", size_bytes: 400, pages: null, status: "extracting", error: null, instructions: false, created_at: null }] }));
    tab("Files");
    const files = screen.getByRole("list", { name: "Files" });
    expect(within(files).getByText("PDF · 4 pages · 178 KB")).toBeInTheDocument();
    expect(within(files).getByText(/Couldn't read it: The file is password protected\./)).toBeInTheDocument();
    expect(within(files).getByText("Reading…")).toBeInTheDocument();
  });

  it("adds picked files, and refuses types the workspace cannot read", async () => {
    respond("client_files_pick", ["C:\\docs\\scope v2.pdf", "C:\\docs\\setup.exe"]);
    respondWith("client_file_upload", ({ path }) => ({ data: { id: "f9", name: String(path).split("\\").pop(), mime: "application/pdf", size_bytes: 10, pages: null, status: "extracting", error: null, instructions: false, created_at: null } }));
    const { notify } = await renderClients();
    tab("Files");
    fireEvent.click(screen.getByRole("button", { name: "Add files" }));
    await waitFor(() => expect(callsOf("client_file_upload")).toEqual([{ id: "c1", path: "C:\\docs\\scope v2.pdf" }]));
    expect(notify).toHaveBeenCalledWith("Only PDF, Word (.docx), text and Markdown files can be added.", false);
    expect(await screen.findByText("scope v2.pdf")).toBeInTheDocument();
    expect(screen.getAllByText("Reading…").length).toBeGreaterThan(0);
  });

  it("takes files dropped on the window while the tab is open", async () => {
    respondWith("client_file_upload", ({ path }) => ({ data: { id: "f8", name: String(path).split("/").pop(), mime: "text/plain", size_bytes: 10, pages: null, status: "extracting", error: null, instructions: false, created_at: null } }));
    await renderClients();
    tab("Files");
    await flushAsync();
    const { fileDropClaim } = await import("../../lib/dropTarget");
    expect(fileDropClaim()).not.toBeNull();
    await emitEvent("tauri://drag-enter");
    expect(screen.getByTestId("file-drop")).toHaveClass("over");
    await act(async () => fileDropClaim()!(["/home/me/rules.txt"]));
    await emitEvent("tauri://drag-drop", { paths: ["/home/me/rules.txt"] });
    await waitFor(() => expect(callsOf("client_file_upload")).toEqual([{ id: "c1", path: "/home/me/rules.txt" }]));
    expect(screen.getByTestId("file-drop")).not.toHaveClass("over");
    tab("Overview");
    expect(fileDropClaim()).toBeNull();
  });

  it("pastes instructions and removes a file", async () => {
    respond("client_instructions_add", { data: { id: "f7", name: "House rules", mime: "text/plain", size_bytes: 30, pages: null, status: "ready", error: null, instructions: true, created_at: null } });
    respond("client_file_delete", undefined);
    vi.spyOn(window, "confirm").mockReturnValue(true);
    await renderClients();
    tab("Files");
    fireEvent.click(screen.getByRole("button", { name: "Paste instructions" }));
    fireEvent.change(screen.getByRole("textbox", { name: "Name" }), { target: { value: "House rules" } });
    fireEvent.change(screen.getByRole("textbox", { name: "Instructions" }), { target: { value: "Always reply in English." } });
    fireEvent.click(screen.getByRole("button", { name: "Save instructions" }));
    await waitFor(() => expect(callsOf("client_instructions_add")).toEqual([{ id: "c1", name: "House rules", text: "Always reply in English." }]));
    expect(await screen.findByText("Pasted instructions · 1 KB")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Remove scope.pdf" }));
    await waitFor(() => expect(callsOf("client_file_delete")).toEqual([{ id: "c1", file: "f1" }]));
    expect(screen.queryByText("scope.pdf")).not.toBeInTheDocument();
  });

  it("asks again every 5 s while a file is being read, and stops once it is", async () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    const reading = workspace({ files: [{ id: "f3", name: "brief.md", mime: "text/markdown", size_bytes: 400, pages: null, status: "extracting", error: null, instructions: false, created_at: null }] });
    await renderClients(reading);
    expect(workspaceBusy(reading)).toBe(true);
    const first = callsOf("client_workspace").length;
    await act(async () => { vi.advanceTimersByTime(5_000); });
    await flushAsync();
    expect(callsOf("client_workspace").length).toBe(first + 1);
    respondWith("client_workspace", () => ({ data: workspace() }));
    await act(async () => { vi.advanceTimersByTime(5_000); });
    await flushAsync();
    const done = callsOf("client_workspace").length;
    await act(async () => { vi.advanceTimersByTime(30_000); });
    expect(callsOf("client_workspace").length).toBe(done);
  });
});

describe("Ask", () => {
  const ANSWER = {
    answer: "Launch is on November 15.",
    citations: [
      { type: "call", session_id: "s1", title: "Kick-off · Oct 30", at_ms: 125_000, quote: "launch on the fifteenth" },
      { type: "file", file_id: "f1", title: "scope.pdf", page: 2, quote: "Go-live" },
    ],
  };

  it("asks in Roman Urdu and opens a call source at its moment", async () => {
    respond("client_ask", { data: ANSWER });
    const { onOpenRecording } = await renderClients();
    tab("Ask");
    fireEvent.change(screen.getByRole("textbox", { name: "Question" }), { target: { value: "  When do we launch?  " } });
    fireEvent.click(within(screen.getByRole("group", { name: "Answer in" })).getByRole("button", { name: "Roman Urdu" }));
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    await waitFor(() => expect(callsOf("client_ask")).toEqual([{ id: "c1", question: "When do we launch?", language: "roman_urdu" }]));
    expect(await screen.findByText("Launch is on November 15.")).toBeInTheDocument();
    const sources = screen.getByRole("list", { name: "Sources" });
    fireEvent.click(within(sources).getByRole("button", { name: /Kick-off · Oct 30 · 2:05/ }));
    expect(onOpenRecording).toHaveBeenCalledWith("s1", 125_000);
    // A file source names the file and the page; it is not a button.
    expect(within(sources).getByText("scope.pdf · page 2")).toBeInTheDocument();
    expect(within(sources).queryByRole("button", { name: /scope\.pdf/ })).not.toBeInTheDocument();
  });

  it("needs three characters and shows the server's refusal", async () => {
    fail("client_ask", "This month's AI budget ($5.00) is used up.");
    await renderClients();
    tab("Ask");
    expect(screen.getByRole("button", { name: "Ask" })).toBeDisabled();
    fireEvent.change(screen.getByRole("textbox", { name: "Question" }), { target: { value: "Budget?" } });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    expect(await screen.findByRole("alert")).toHaveTextContent("This month's AI budget ($5.00) is used up.");
  });
});

describe("workspace helpers", () => {
  it("writes the budget line and the source labels", () => {
    expect(budgetLine({ configured: true, spent_usd: 0.4, budget_usd: 5, client_spent_usd: 0.123 })).toBe("AI this month: $0.40 of $5.00 · this client $0.12");
    expect(citationLabel({ type: "call", title: "Kick-off", at_ms: 61_000 })).toBe("Kick-off · 1:01");
    expect(citationLabel({ type: "file", title: "scope.pdf", page: 3 })).toBe("scope.pdf · page 3");
  });

  it("is busy while the brief, notes or a file are being made", () => {
    expect(workspaceBusy(workspace())).toBe(false);
    expect(workspaceBusy(workspace({ brief: { ...workspace().brief!, status: "working" } }))).toBe(true);
    expect(workspaceBusy(workspace({ notes: [{ ...workspace().notes[0], status: "pending" }] }))).toBe(true);
  });
});
