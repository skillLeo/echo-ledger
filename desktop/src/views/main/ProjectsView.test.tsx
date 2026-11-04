// Projects (docs/API.md "Projects" since v1.5, "Client workspaces" since v1.4): the tree of projects and subfolders, a
// project's page with its breadcrumbs, folders and menu (new folder, rename, move, delete), and its tabs Recordings,
// Files, Notes, Screens and AI notes, driven through the same commands the Rust core exposes.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { useState } from "react";
import { deletedText, ProjectsView } from "./ProjectsView";
import { budgetLine, citationLabel, workspaceBusy } from "./ClientWorkspace";
import type { ClientFolder, ClientWorkspace, LibraryItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECTS: ClientFolder[] = [
  { id: "c1", name: "Acme Holdings", session_count: 2, ai_notes: true, parent_id: null, path: [], children_count: 1, nested_session_count: 3 },
  { id: "c3", name: "Invoices", session_count: 1, ai_notes: true, parent_id: "c1", path: [{ id: "c1", name: "Acme Holdings" }], children_count: 0, nested_session_count: 1 },
  { id: "c2", name: "Zed Logistics", session_count: 0, ai_notes: true, parent_id: null, path: [], children_count: 0, nested_session_count: 0 },
];
const RECORDINGS: LibraryItem[] = [
  makeItem("s1", { title: "Kick-off", client: { id: "c1", name: "Acme Holdings" }, started_at: "2026-09-30T10:00:00Z", duration_ms: 1_800_000, source_app: "zoom" }),
  makeItem("s2", { title: "Budget review", client: { id: "c1", name: "Acme Holdings" }, started_at: "2026-09-28T10:00:00Z", duration_ms: 600_000, source_app: "whatsapp" }),
  makeItem("s3", { title: "March invoice", client: { id: "c3", name: "Invoices" }, started_at: "2026-10-02T10:00:00Z", duration_ms: 120_000, source_app: "zoom" }),
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
const props = { notify: vi.fn(), onOpenRecording: vi.fn(), onReload: vi.fn(async () => undefined) };

/** The Projects page with the state MainApp keeps for it: the list, the open project and the expanded ones. */
function Harness({ list, initial }: { list: ClientFolder[] | null; initial: string | null }) {
  const [projects, setProjects] = useState<ClientFolder[] | null>(list);
  const [projectId, setProjectId] = useState<string | null>(initial);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const toggle = (id: string) => setExpanded((s) => { const next = new Set(s); if (next.has(id)) next.delete(id); else next.add(id); return next; });
  return (
    <ProjectsView {...props} projects={projects} onChanged={(change) => setProjects((l) => change(l ?? []))} projectId={projectId} onOpenProject={setProjectId}
      expanded={expanded} onToggle={toggle} />
  );
}

async function renderProjects({ ws = workspace(), list = PROJECTS, open = "c1" as string | null } = {}) {
  respond("library_list", RECORDINGS);
  respondWith("client_workspace", ({ id }) => ({ data: id === "c1" ? ws : workspace({ client: { id: String(id), name: list.find((p) => p.id === id)?.name ?? "", ai_notes: true }, brief: null, memory: [], notes: [], files: [] }) }));
  render(<Harness list={list} initial={open} />);
  // Microtasks only (no polling helpers), so tests on a fake clock render the same way.
  await flushAsync();
  await flushAsync();
  if (open) expect(screen.getByRole("heading", { name: list.find((p) => p.id === open)!.name })).toBeInTheDocument();
  return props;
}
const tab = (name: string) => fireEvent.click(screen.getByRole("tab", { name }));
const menu = (item: string) => {
  fireEvent.click(screen.getByRole("button", { name: "Project menu" }));
  fireEvent.click(screen.getByRole("menuitem", { name: item }));
};

beforeEach(() => {
  resetTauriMock();
  writeText.mockClear();
  props.notify.mockClear();
  props.onOpenRecording.mockClear();
  props.onReload.mockClear();
  Object.defineProperty(navigator, "clipboard", { configurable: true, value: { writeText } });
});
afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("the Projects page", () => {
  it("lists the projects as a tree and shows a project's folders when it is expanded", async () => {
    await renderProjects({ open: null });
    expect(screen.getByRole("heading", { name: "Projects" })).toBeInTheDocument();
    const tree = screen.getByRole("list", { name: "Projects" });
    expect(within(tree).getByText("Acme Holdings")).toBeInTheDocument();
    expect(within(tree).getByText("3 recordings · Last call Oct 2 · 1 folder")).toBeInTheDocument();
    expect(within(tree).getByText("No recordings yet")).toBeInTheDocument();
    expect(within(tree).queryByText("Invoices")).not.toBeInTheDocument();
    const toggle = within(tree).getByRole("button", { name: "Show the folders in Acme Holdings" });
    expect(toggle).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(toggle);
    expect(within(tree).getByText("Invoices")).toBeInTheDocument();
    expect(within(tree).getByRole("button", { name: "Hide the folders in Acme Holdings" })).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(within(tree).getByText("Invoices"));
    await flushAsync();
    expect(screen.getByRole("heading", { name: "Invoices" })).toBeInTheDocument();
    expect(callsOf("client_workspace")).toEqual([{ id: "c3" }]);
  });

  it("adds a project at the top level", async () => {
    respond("client_create", { data: { id: "c9", name: "Nova", parent_id: null } });
    await renderProjects({ open: null });
    fireEvent.click(screen.getAllByRole("button", { name: "New project" })[0]);
    fireEvent.change(within(screen.getByRole("dialog", { name: "New project" })).getByRole("textbox", { name: "Name" }), { target: { value: " Nova " } });
    fireEvent.click(screen.getByRole("button", { name: "Add project" }));
    await waitFor(() => expect(callsOf("client_create")).toEqual([{ name: "Nova", color: null, avatar: null, parentId: null }]));
    expect(await screen.findByRole("heading", { name: "Nova" })).toBeInTheDocument();
    expect(props.notify).toHaveBeenCalledWith("Nova added");
    expect(props.onReload).toHaveBeenCalled();
  });

  it("says so when the projects cannot be loaded, and retries", async () => {
    respond("library_list", []);
    render(<ProjectsView {...props} projects={null} error="Network error" onChanged={vi.fn()} projectId={null} onOpenProject={vi.fn()} expanded={new Set()} onToggle={vi.fn()} />);
    expect(await screen.findByText(/Couldn't load the projects\. Network error/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    expect(props.onReload).toHaveBeenCalled();
  });

  it("says so when the workspace cannot be loaded, and retries", async () => {
    respond("library_list", []);
    fail("client_workspace", "Network error");
    render(<Harness list={PROJECTS} initial="c1" />);
    await flushAsync();
    tab("Notes");
    expect(await screen.findByText(/Couldn't load Acme Holdings's workspace/)).toBeInTheDocument();
    respond("client_workspace", { data: workspace() });
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    expect(await screen.findByText("An online shop")).toBeInTheDocument();
  });
});

describe("a project's page", () => {
  it("has the tabs Recordings, Files, Notes, Screens and AI notes, and opens on Recordings", async () => {
    await renderProjects();
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Recordings", "Files", "Notes", "Screens", "AI notes"]);
    expect(screen.getByRole("tab", { name: "Recordings" })).toHaveAttribute("aria-selected", "true");
    expect(callsOf("client_workspace")).toEqual([{ id: "c1" }]);
  });

  it("shows AI notes only while they are on for the project", async () => {
    await renderProjects({ ws: workspace({ client: { id: "c1", name: "Acme Holdings", ai_notes: false } }) });
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Recordings", "Files", "Notes", "Screens"]);
  });

  it("says screen moments come in a later update", async () => {
    await renderProjects();
    tab("Screens");
    expect(screen.getByTestId("screens-empty")).toHaveTextContent("Screen moments arrive in a later update");
  });

  it("shows where the project is and opens a level from its breadcrumbs", async () => {
    await renderProjects({ open: "c3" });
    const crumbs = screen.getByRole("navigation", { name: "Where this project is" });
    expect(crumbs).toHaveTextContent("ProjectsAcme HoldingsInvoices");
    expect(within(crumbs).getByText("Invoices")).toHaveAttribute("aria-current", "page");
    fireEvent.click(within(crumbs).getByRole("button", { name: "Acme Holdings" }));
    await flushAsync();
    expect(screen.getByRole("heading", { name: "Acme Holdings" })).toBeInTheDocument();
    fireEvent.click(within(screen.getByRole("navigation", { name: "Where this project is" })).getByRole("button", { name: "Projects" }));
    expect(screen.getByRole("heading", { name: "Projects" })).toBeInTheDocument();
  });

  it("lists its folders and opens one", async () => {
    await renderProjects();
    const folders = screen.getByRole("list", { name: "Folders" });
    fireEvent.click(within(folders).getByRole("button", { name: /Invoices/ }));
    await flushAsync();
    expect(screen.getByRole("heading", { name: "Invoices" })).toBeInTheDocument();
  });
});

describe("Recordings", () => {
  const titles = () => within(screen.getByRole("list", { name: "Acme Holdings recordings" })).getAllByRole("button").map((b) => b.querySelector(".item-title")?.textContent);

  it("lists the project's recordings, newest first, and opens one", async () => {
    await renderProjects();
    expect(titles()).toEqual(["Kick-off", "Budget review"]);
    fireEvent.click(screen.getByText("Budget review"));
    expect(props.onOpenRecording).toHaveBeenCalledWith("s2", undefined);
  });

  it("sorts by oldest, name and length", async () => {
    await renderProjects();
    const sort = screen.getByRole("combobox", { name: "Sort recordings" });
    expect([...(sort as HTMLSelectElement).options].map((o) => o.text)).toEqual(["Newest first", "Oldest first", "Name A–Z", "Longest first"]);
    fireEvent.change(sort, { target: { value: "oldest" } });
    expect(titles()).toEqual(["Budget review", "Kick-off"]);
    fireEvent.change(sort, { target: { value: "title" } });
    expect(titles()).toEqual(["Budget review", "Kick-off"]);
    fireEvent.change(sort, { target: { value: "longest" } });
    expect(titles()).toEqual(["Kick-off", "Budget review"]);
  });

  it("includes its folders' recordings when asked, saying which folder each is in", async () => {
    await renderProjects();
    fireEvent.click(screen.getByRole("checkbox", { name: "Include its folders" }));
    expect(titles()).toEqual(["March invoice", "Kick-off", "Budget review"]);
    expect(screen.getByText(/in Invoices/)).toBeInTheDocument();
  });

  it("shows the project's filing rules above its recordings", async () => {
    respond("client_rules", { data: [{ id: 7, kind: "title", value: "Acme" }] });
    await renderProjects();
    await flushAsync();
    expect(screen.getByText("Filing rules")).toBeInTheDocument();
    expect(screen.getByText("Title contains “Acme”")).toBeInTheDocument();
    expect(callsOf("client_rules")).toEqual([{ id: "c1" }]);
  });

  it("finds recordings by their words, and what was said in them", async () => {
    respond("search_library", { data: [{ session: RECORDINGS[0], hits: [{ transcript_id: "t1", kind: "final", segment_index: 4, start_ms: 65_000, end_ms: 66_000, speaker: "Client", snippet: "the budget is fine", highlights: [[4, 6]] }], hit_count: 1 }], next_page: null });
    await renderProjects();
    fireEvent.change(screen.getByRole("textbox", { name: "Search in this project" }), { target: { value: "budget" } });
    expect(titles()).toEqual(["Budget review"]);
    await waitFor(() => expect(callsOf("search_library")).toEqual([{ query: { q: "budget", clientId: "c1" } }]));
    const hits = await screen.findByRole("region", { name: "Matches in transcripts" });
    fireEvent.click(within(hits).getByRole("button", { name: "Play Kick-off from 1:05" }));
    expect(props.onOpenRecording).toHaveBeenCalledWith("s1", 65_000);
    fireEvent.change(screen.getByRole("textbox", { name: "Search in this project" }), { target: { value: "nothing like this" } });
    expect(screen.getByText("Nothing matches. Try other words.")).toBeInTheDocument();
  });
});

describe("the project menu", () => {
  it("makes a folder inside the project and opens it", async () => {
    respond("client_create", { data: { id: "c7", name: "Contracts", parent_id: "c1" } });
    await renderProjects();
    menu("New folder inside");
    const dialog = screen.getByRole("dialog", { name: "New folder in Acme Holdings" });
    fireEvent.change(within(dialog).getByRole("textbox", { name: "Name" }), { target: { value: "Contracts" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Add folder" }));
    await waitFor(() => expect(callsOf("client_create")).toEqual([{ name: "Contracts", color: null, avatar: null, parentId: "c1" }]));
    expect(await screen.findByRole("heading", { name: "Contracts" })).toBeInTheDocument();
    expect(props.notify).toHaveBeenCalledWith("Contracts added to Acme Holdings");
  });

  it("renames the project", async () => {
    respond("client_update", { data: { ...PROJECTS[0], name: "Acme Group" } });
    await renderProjects();
    menu("Rename");
    const field = within(screen.getByRole("dialog", { name: "Rename Acme Holdings" })).getByRole("textbox", { name: "Name" });
    expect(field).toHaveValue("Acme Holdings");
    expect(screen.getByRole("button", { name: "Rename" })).toBeDisabled();
    fireEvent.change(field, { target: { value: "Acme Group" } });
    fireEvent.click(screen.getByRole("button", { name: "Rename" }));
    await waitFor(() => expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { name: "Acme Group" } }]));
    expect(await screen.findByRole("heading", { name: "Acme Group" })).toBeInTheDocument();
  });

  it("moves a folder to another project or the top level, never into itself", async () => {
    respond("client_update", { data: { ...PROJECTS[1], parent_id: "c2" } });
    await renderProjects({ open: "c3" });
    menu("Move…");
    const picker = within(screen.getByRole("dialog", { name: "Move Invoices" })).getByRole("radiogroup", { name: "Move to" });
    expect(within(picker).getByRole("radio", { name: /Invoices/ })).toBeDisabled();
    expect(within(picker).getByRole("radio", { name: /Acme Holdings/ })).toBeDisabled();
    expect(within(picker).getByText("It is here now")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Move" })).toBeDisabled();
    fireEvent.click(within(picker).getByRole("radio", { name: /Zed Logistics/ }));
    fireEvent.click(screen.getByRole("button", { name: "Move" }));
    await waitFor(() => expect(callsOf("client_update")).toEqual([{ id: "c3", patch: { parent_id: "c2" } }]));
    await waitFor(() => expect(props.notify).toHaveBeenCalledWith("Moved Invoices into Zed Logistics"));
    await flushAsync();
    expect(within(screen.getByRole("navigation", { name: "Where this project is" })).getByRole("button", { name: "Zed Logistics" })).toBeInTheDocument();
  });

  it("does not let a project with a folder go where the folder would pass four levels", async () => {
    const deep: ClientFolder[] = [
      { id: "a", name: "A", parent_id: null }, { id: "b", name: "B", parent_id: "a" }, { id: "c", name: "C", parent_id: "b" },
      { id: "x", name: "X", parent_id: null }, { id: "y", name: "Y", parent_id: "x" },
    ];
    respond("client_workspace", { data: workspace() });
    respond("library_list", []);
    render(<Harness list={deep} initial="x" />);
    await flushAsync();
    menu("Move…");
    const picker = screen.getByRole("radiogroup", { name: "Move to" });
    expect(within(picker).getByRole("radio", { name: /^C/ })).toBeDisabled();
    expect(within(picker).getByRole("radio", { name: /^B/ })).toBeEnabled();
    expect(within(picker).getAllByText("Too deep: 4 levels at most")).toHaveLength(1);
  });

  it("shows the server's refusal of a move in plain words and keeps the dialog open", async () => {
    fail("client_update", "Projects can be nested 4 levels deep at most. Pick a folder higher up.");
    await renderProjects({ open: "c3" });
    menu("Move…");
    fireEvent.click(screen.getByRole("radio", { name: /Top level/ }));
    fireEvent.click(screen.getByRole("button", { name: "Move" }));
    await waitFor(() => expect(props.notify).toHaveBeenCalledWith("Projects can be nested 4 levels deep at most. Pick a folder higher up.", true));
    expect(screen.getByRole("dialog", { name: "Move Invoices" })).toBeInTheDocument();
  });

  it("deletes a project after saying its recordings go to the Inbox", async () => {
    respond("client_delete", { moved_sessions: 2, moved_projects: 1 });
    await renderProjects();
    menu("Delete project…");
    const dialog = screen.getByRole("dialog", { name: "Delete Acme Holdings?" });
    expect(dialog).toHaveTextContent("Its 2 recordings go to the Inbox, so nothing is lost.");
    expect(dialog).toHaveTextContent("Its folder moves up to the top level, with its recordings.");
    expect(dialog).toHaveTextContent("Its files, brief, notes, memory and filing rules are deleted with it.");
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete project" }));
    await waitFor(() => expect(callsOf("client_delete")).toEqual([{ id: "c1" }]));
    await waitFor(() => expect(props.notify).toHaveBeenCalledWith("Deleted Acme Holdings · 2 recordings moved to the Inbox · 1 folder moved up"));
    expect(await screen.findByRole("heading", { name: "Projects" })).toBeInTheDocument();
    expect(within(screen.getByRole("list", { name: "Projects" })).getByText("Invoices")).toBeInTheDocument();
  });

  it("cancels a delete without touching the project", async () => {
    await renderProjects();
    menu("Delete project…");
    fireEvent.click(within(screen.getByRole("dialog", { name: "Delete Acme Holdings?" })).getByRole("button", { name: "Cancel" }));
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(callsOf("client_delete")).toHaveLength(0);
  });

  it("offers only Rename on a server without subfolders", async () => {
    const old: ClientFolder[] = [{ id: "c1", name: "Acme Holdings", session_count: 2, ai_notes: true }];
    await renderProjects({ list: old });
    fireEvent.click(screen.getByRole("button", { name: "Project menu" }));
    expect(screen.getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["Rename"]);
    expect(screen.queryByRole("navigation", { name: "Where this project is" })).toBeInTheDocument();
  });

  it("says what a delete moved", () => {
    expect(deletedText("Acme", {})).toBe("Deleted Acme");
    expect(deletedText("Acme", { moved_sessions: 1, moved_projects: 2 })).toBe("Deleted Acme · 1 recording moved to the Inbox · 2 folders moved up");
  });
});

describe("Notes", () => {
  it("turns AI notes off for the project, which hides its AI notes tab, and shows the month's AI budget", async () => {
    respond("client_update", { data: { ...PROJECTS[0], ai_notes: false } });
    await renderProjects();
    tab("Notes");
    expect(screen.getByTestId("ai-budget")).toHaveTextContent("AI this month: $0.42 of $5.00 · this client $0.12");
    const toggle = screen.getByRole("switch", { name: "AI notes" });
    expect(toggle).toHaveAttribute("aria-checked", "true");
    fireEvent.click(toggle);
    await waitFor(() => expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { ai_notes: false } }]));
    expect(screen.getByRole("switch", { name: "AI notes" })).toHaveAttribute("aria-checked", "false");
    expect(screen.queryByRole("tab", { name: "AI notes" })).not.toBeInTheDocument();
  });

  it("edits the brief, one line per item", async () => {
    respondWith("client_brief_save", ({ sections }) => ({ data: { ...workspace().brief, sections, edited: true } }));
    await renderProjects();
    tab("Notes");
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
    await renderProjects();
    tab("Notes");
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
    await renderProjects();
    tab("Notes");
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

  it("plays a memory line's moment in its call", async () => {
    await renderProjects();
    tab("Notes");
    fireEvent.click(within(screen.getByRole("group", { name: "Changes" })).getByRole("button", { name: /3:20/ }));
    expect(props.onOpenRecording).toHaveBeenLastCalledWith("s1", 200_000);
  });

  it("copies the brief, memory and notes for AI", async () => {
    respond("client_bundle", "# Acme: project context\n\n## Brief\n…");
    await renderProjects();
    fireEvent.click(screen.getByRole("button", { name: "Copy for AI" }));
    await waitFor(() => expect(writeText).toHaveBeenCalledWith("# Acme: project context\n\n## Brief\n…"));
    expect(callsOf("client_bundle")).toEqual([{ id: "c1", notes: null }]);
    expect(props.notify).toHaveBeenCalledWith("Copied the brief, memory and recent call notes for AI");
  });
});

describe("AI notes", () => {
  it("opens the recording at a call note's moment and marks lines to verify", async () => {
    await renderProjects();
    tab("AI notes");
    const notes = screen.getByRole("region", { name: "Call notes" });
    fireEvent.click(within(notes).getByRole("button", { name: /Launch on November 15/ }));
    expect(props.onOpenRecording).toHaveBeenCalledWith("s1", 125_000);
    expect(within(within(notes).getByRole("button", { name: /Budget is \$12,000/ })).getByText("verify")).toBeInTheDocument();
    expect(within(within(notes).getByRole("button", { name: /Launch on November 15/ })).queryByText("verify")).toBeNull();
    fireEvent.click(within(notes).getByRole("button", { name: "Open" }));
    expect(props.onOpenRecording).toHaveBeenLastCalledWith("s1");
  });

  it("says when no call notes were written yet", async () => {
    await renderProjects({ ws: workspace({ notes: [] }) });
    tab("AI notes");
    expect(screen.getByText("No call notes yet")).toBeInTheDocument();
  });
});

describe("Files", () => {
  it("shows each file's state: read, reading or failed", async () => {
    await renderProjects({ ws: workspace({ files: [...workspace().files, { id: "f3", name: "brief.md", mime: "text/markdown", size_bytes: 400, pages: null, status: "extracting", error: null, instructions: false, created_at: null }] }) });
    tab("Files");
    const files = screen.getByRole("list", { name: "Files" });
    expect(within(files).getByText("PDF · 4 pages · 178 KB")).toBeInTheDocument();
    expect(within(files).getByText(/Couldn't read it: The file is password protected\./)).toBeInTheDocument();
    expect(within(files).getByText("Reading…")).toBeInTheDocument();
  });

  it("adds picked files, and refuses types the workspace cannot read", async () => {
    respond("client_files_pick", ["C:\\docs\\scope v2.pdf", "C:\\docs\\setup.exe"]);
    respondWith("client_file_upload", ({ path }) => ({ data: { id: "f9", name: String(path).split("\\").pop(), mime: "application/pdf", size_bytes: 10, pages: null, status: "extracting", error: null, instructions: false, created_at: null } }));
    await renderProjects();
    tab("Files");
    fireEvent.click(screen.getByRole("button", { name: "Add files" }));
    await waitFor(() => expect(callsOf("client_file_upload")).toEqual([{ id: "c1", path: "C:\\docs\\scope v2.pdf" }]));
    expect(props.notify).toHaveBeenCalledWith("Only PDF, Word (.docx), text and Markdown files can be added.", false);
    expect(await screen.findByText("scope v2.pdf")).toBeInTheDocument();
    expect(screen.getAllByText("Reading…").length).toBeGreaterThan(0);
  });

  it("takes files dropped on the window while the tab is open", async () => {
    respondWith("client_file_upload", ({ path }) => ({ data: { id: "f8", name: String(path).split("/").pop(), mime: "text/plain", size_bytes: 10, pages: null, status: "extracting", error: null, instructions: false, created_at: null } }));
    await renderProjects();
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
    tab("Notes");
    expect(fileDropClaim()).toBeNull();
  });

  it("pastes instructions and removes a file", async () => {
    respond("client_instructions_add", { data: { id: "f7", name: "House rules", mime: "text/plain", size_bytes: 30, pages: null, status: "ready", error: null, instructions: true, created_at: null } });
    respond("client_file_delete", undefined);
    vi.spyOn(window, "confirm").mockReturnValue(true);
    await renderProjects();
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
    await renderProjects({ ws: reading });
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

  it("asks in Roman Urdu from the Notes tab and opens a call source at its moment", async () => {
    respond("client_ask", { data: ANSWER });
    await renderProjects();
    tab("Notes");
    // An Urdu question is typed right to left (v1.5 sweep).
    expect(screen.getByRole("textbox", { name: "Question" })).toHaveAttribute("dir", "auto");
    fireEvent.change(screen.getByRole("textbox", { name: "Question" }), { target: { value: "  When do we launch?  " } });
    fireEvent.click(within(screen.getByRole("group", { name: "Answer in" })).getByRole("button", { name: "Roman Urdu" }));
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    await waitFor(() => expect(callsOf("client_ask")).toEqual([{ id: "c1", question: "When do we launch?", language: "roman_urdu" }]));
    expect(await screen.findByText("Launch is on November 15.")).toBeInTheDocument();
    const sources = screen.getByRole("list", { name: "Sources" });
    fireEvent.click(within(sources).getByRole("button", { name: /Kick-off · Oct 30 · 2:05/ }));
    expect(props.onOpenRecording).toHaveBeenCalledWith("s1", 125_000);
    // A file source names the file and the page; it is not a button.
    expect(within(sources).getByText("scope.pdf · page 2")).toBeInTheDocument();
    expect(within(sources).queryByRole("button", { name: /scope\.pdf/ })).not.toBeInTheDocument();
  });

  it("needs three characters and shows the server's refusal", async () => {
    fail("client_ask", "This month's AI budget ($5.00) is used up.");
    await renderProjects();
    tab("Notes");
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
