import { beforeEach, describe, expect, it, vi } from "vitest";
import { formatWhen } from "../../lib/format";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { getConfig, getRoles } from "@testing-library/dom";
import { createRequire } from "node:module";
import type { ClientFolder, ClientWorkspace, MemoryLine } from "../../lib/ipc";
import MainApp from "../../views/main/MainApp";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { fileDropClaim } from "../../lib/dropTarget";
import { answerConfirm } from "../../test/confirm";
import { makeEntitlements, makeItem, makeScreen, makeSettings, makeStatus } from "../../test/fixtures";
import { ClientWorkspace as ClientWorkspacePage } from "../../views/main/ClientWorkspace";
import { RulesSection } from "../../views/main/FilingRules";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { WorkspaceV2, latestStart, memoryGroups } from "./WorkspaceV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
// The accessible-name library Testing Library itself loads (the same version, so the same names as its ByRole queries).
const fromTestingLibrary = createRequire(createRequire(import.meta.url).resolve("@testing-library/dom"));
const { computeAccessibleName: accessibleName } = fromTestingLibrary("dom-accessibility-api") as { computeAccessibleName: (el: Element, options?: { computedStyleSupportsPseudoElements?: boolean }) => string };
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

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
 * Parity with today's project page. Today's page (ClientWorkspace, with the rules, Share and menu ProjectsView gives
 * it) is rendered with the same project and walked through every tab and state: the menu open, a search with matches
 * in transcripts, the rules being edited, Paste instructions, the brief being edited, an answer from Ask, the Screens
 * tab, and a workspace that didn't load. The accessible name of every control it shows is collected, and each one must
 * be mapped below to where the new page has it, where the test then finds it. A control added to today's page fails
 * this test until the new page has it too (or the table says why it isn't needed).
 *
 * Gestures have no accessible name and are checked separately: dragging a recording onto a project in the sidebar
 * ("recordings: … a row can start a drag onto a project") and files dropped on the window while Files is open
 * ("files: … a dropped file is added while Files is open").
 */
describe("parity with today's project page", () => {
  const ROLES = ["button", "link", "checkbox", "switch", "combobox", "textbox", "searchbox", "radio", "menuitem", "tab", "slider", "spinbutton"] as const;
  type Role = (typeof ROLES)[number];
  type State = "menu" | "search" | "paste" | "brief" | "answered" | "failed";
  type Where = { tab: string; role: Role; name: string | RegExp; state?: State } | { reason: string };
  const R = "Recordings";
  const M = "Memory & instructions";
  /** Today's control (role: accessible name) → where the new page has it. */
  const WHERE: [today: string | RegExp, where: Where][] = [
    ["button: Projects", { tab: R, role: "button", name: "Projects" }],
    ["button: Share", { tab: R, role: "button", name: "Share" }],
    ["button: Copy for AI", { tab: R, role: "button", name: "Copy for AI" }],
    ["button: Project menu", { tab: R, role: "button", name: "Project menu" }],
    ["menuitem: New folder inside", { tab: R, role: "menuitem", name: "New folder inside", state: "menu" }],
    ["menuitem: Rename", { tab: R, role: "menuitem", name: "Rename", state: "menu" }],
    ["menuitem: Delete project…", { tab: R, role: "menuitem", name: "Delete project…", state: "menu" }],
    [/^button: March order\d*$/, { tab: R, role: "button", name: "March order" }],
    [/^button: Payment terms for the March shipment\S/, { tab: R, role: "button", name: "Payment terms for the March shipment" }],
    ["checkbox: Include its folders", { tab: R, role: "checkbox", name: "Include its folders" }],
    ["combobox: Sort recordings", { tab: R, role: "combobox", name: /^Sort recordings/ }],
    ["textbox: Search in this project", { tab: R, role: "searchbox", name: "Search Qureshi Textiles" }],
    ["button: Clear search", { tab: R, role: "button", name: "Clear search", state: "search" }],
    ["button: Play Payment terms for the March shipment from 0:31", { tab: R, role: "button", name: "Play Payment terms for the March shipment from 0:31", state: "search" }],
    ["button: Edit rules", { reason: "the new page shows the filing rules ready to add to; there is nothing to open" }],
    ["button: Done", { reason: "closes today's rules editor (see Edit rules)" }],
    ["button: Remove the rule Title contains “Qureshi”", { tab: R, role: "button", name: "Remove the rule Title contains “Qureshi”" }],
    ["button: Add rule", { tab: R, role: "button", name: "Add rule" }],
    ["combobox: Kind of rule", { tab: R, role: "combobox", name: /^Kind of rule/ }],
    ["textbox: Title contains", { tab: R, role: "textbox", name: "Title contains" }],
    ["tab: Recordings", { tab: R, role: "tab", name: "Recordings" }],
    ["tab: Files", { tab: R, role: "tab", name: "Files" }],
    ["tab: Notes", { tab: R, role: "tab", name: M }],
    // UX-09: the tabs are Recordings · Files · Memory & instructions · Ask · Members; Screens is a section of Files and
    // the call notes one of Memory & instructions (their controls are found there, below).
    ["tab: Screens", { reason: "UX-09: Screens is a section of the Files tab (button: Screen 1 · 1:00 below)" }],
    ["tab: AI notes", { reason: "UX-09: Call notes is a section of the Memory & instructions tab (button: Open below)" }],
    ["button: Add files", { tab: "Files", role: "button", name: "Add files" }],
    ["button: Paste instructions", { tab: "Files", role: "button", name: "Paste instructions" }],
    ["button: Remove Proforma March.pdf", { tab: "Files", role: "button", name: "Remove Proforma March.pdf" }],
    ["button: Remove House rules", { tab: "Files", role: "button", name: "Remove House rules" }],
    ["button: Save instructions", { tab: "Files", role: "button", name: "Save instructions", state: "paste" }],
    ["button: Cancel", { tab: "Files", role: "button", name: "Cancel", state: "paste" }],
    ["textbox: Name", { tab: "Files", role: "textbox", name: "Name", state: "paste" }],
    ["textbox: Instructions", { tab: "Files", role: "textbox", name: "Instructions", state: "paste" }],
    ["textbox: Question", { tab: "Ask", role: "textbox", name: "Your question" }],
    ["button: English", { tab: "Ask", role: "radio", name: "English" }],
    ["button: Roman Urdu", { tab: "Ask", role: "radio", name: "Roman Urdu" }],
    ["button: Ask", { tab: "Ask", role: "button", name: "Ask" }],
    ["button: Payment terms · 0:31", { tab: "Ask", role: "button", name: /^Payment terms/, state: "answered" }],
    ["button: Copy answer", { tab: "Ask", role: "button", name: "Copy answer", state: "answered" }],
    ["button: Clear the answer", { tab: "Ask", role: "button", name: "Clear the answer", state: "answered" }],
    ["switch: AI notes", { tab: M, role: "switch", name: "AI notes" }],
    ["button: Edit", { tab: M, role: "button", name: "Edit" }],
    ["button: Make again", { tab: M, role: "button", name: "Write again" }],
    ["button: Save brief", { tab: M, role: "button", name: "Save brief", state: "brief" }],
    ["textbox: Goal", { tab: M, role: "textbox", name: "Goal", state: "brief" }],
    ...(["Scope", "Constraints", "Deadlines", "Budget", "People", "Glossary"].map((f) =>
      [`textbox: ${f}· one per line`, { tab: M, role: "textbox", name: f, state: "brief" }] as [string, Where])),
    ["button: 0:31", { tab: M, role: "button", name: "Open the call at 0:31" }],
    ...(["40% in advance, the balance against the bill of lading", "Which bank opens the LC?", "Delivery moved to the 25th", "Packing done by 21 March"].flatMap((t) => [
      [`button: ${t === "Packing done by 21 March" ? "Unpin" : "Pin"} “${t}”`, { tab: M, role: "button", name: `${t === "Packing done by 21 March" ? "Unpin" : "Pin"} “${t}”` }],
      [`button: Remove “${t}”`, { tab: M, role: "button", name: `Remove “${t}”` }],
    ] as [string, Where][])),
    ["combobox: Kind", { tab: M, role: "combobox", name: /^Kind (?!of rule)/ }],
    ["textbox: Add to memory", { tab: M, role: "textbox", name: "Add a line" }],
    ["button: Add", { tab: M, role: "button", name: "Add to memory" }],
    ["button: Open", { tab: M, role: "button", name: "Open Payment terms for the March shipment" }],
    ["button: 0:3140% advance", { tab: M, role: "button", name: "0:3140% advance" }],
    ["button: Screen 1 · 1:00", { tab: "Files", role: "button", name: "Screen 1 · 1:00" }],
    ["button: Retry", { tab: M, role: "button", name: "Try again", state: "failed" }],
  ];

  const fixtures = () => {
    respond("client_ask", { data: { answer: "40% in advance.", citations: [{ type: "call", session_id: "bilal", title: "Payment terms", at_ms: 31_000 }] } });
    respond("search_library", { data: [{ session: ITEMS[0], hit_count: 1, hits: [{ transcript_id: "t1", segment_index: 3, start_ms: 31_000, speaker: "Bilal", snippet: "forty percent in advance", highlights: [[0, 5]] }] }], next_page: null });
    respond("client_screens", { data: [{ ...makeScreen(1, 60_000), id: "s1-1", session_id: "bilal", session_title: "Kick-off", session_started_at: "2026-11-04T10:00:00Z" }], next_cursor: null });
    respondWith("screen_image", ({ url }) => `data:image/webp;base64,${btoa(String(url))}`);
  };
  const MENU = () => [{ label: "New folder inside", onPick: vi.fn() }, { label: "Rename", onPick: vi.fn() }, { label: "Delete project…", danger: true, onPick: vi.fn() }];
  /** The project search shows what it found (it asks the server 350 ms after the last key): wait for that, not a guess. */
  const searched = async () => {
    await screen.findAllByRole("button", { name: /^Play Payment terms for the March shipment from / });
    await act(flushAsync);
  };
  const press = async (role: Role, name: string) => {
    fireEvent.click(screen.getByRole(role, { name }));
    await act(flushAsync);
  };
  /**
   * Every control's "role: name" in one walk of the page (D-167): Testing Library's own roles and names (its getRoles,
   * and the accessible-name library it uses itself), where queryAllByRole for each role in turn walked it twelve times.
   */
  const collect = (into: Set<string>) => {
    const byRole = getRoles(document.body, { hidden: false });
    for (const role of ROLES) {
      for (const element of byRole[role] ?? []) into.add(`${role}: ${accessibleName(element, { computedStyleSupportsPseudoElements: getConfig().computedStyleSupportsPseudoElements })}`);
    }
  };

  /** Every control today's page shows, in every tab and state. */
  const todays = async (signal: AbortSignal): Promise<string[]> => {
    // A run that ran out of time stops here, instead of rendering on into the tests after it (D-167).
    const step = () => { if (signal.aborted) throw new Error("timed out: stopped"); };
    fixtures();
    const seen = new Set<string>();
    const page = () => (
      <ClientWorkspacePage client={PROJECTS[0]} projects={PROJECTS} items={ITEMS} notify={vi.fn()} onOpenRecording={vi.fn()} onClientChanged={vi.fn()}
        onOpenProject={vi.fn()} menu={MENU()} actions={<button type="button">Share</button>} onMoveRecordings={vi.fn()}
        rules={<RulesSection project={PROJECT} notify={vi.fn()} />} />
    );
    const { unmount } = render(page());
    await act(flushAsync);
    collect(seen);
    await press("button", "Project menu");
    collect(seen);
    fireEvent.keyDown(document.activeElement!, { key: "Escape" });
    fireEvent.change(screen.getByRole("textbox", { name: "Search in this project" }), { target: { value: "advance" } });
    await searched();
    step();
    await press("button", "Edit rules");
    collect(seen);
    for (const tab of ["Files", "Notes", "Screens", "AI notes"]) {
      step();
      await press("tab", tab);
      if (tab === "Screens") await screen.findByRole("button", { name: "Screen 1 · 1:00" });
      collect(seen);
      if (tab === "Files") await press("button", "Paste instructions");
      if (tab === "Notes") {
        await press("button", "Edit");
        collect(seen);
        await press("button", "Cancel");
        fireEvent.change(screen.getByRole("textbox", { name: "Question" }), { target: { value: "What's the advance?" } });
        await press("button", "Ask");
      }
      collect(seen);
    }
    unmount();
    step();
    // A workspace that didn't load.
    fail("client_workspace", "The server is out of reach.");
    render(page());
    await act(flushAsync);
    await press("tab", "Notes");
    collect(seen);
    return [...seen];
  };

  it("maps every control today's page has, and nothing it doesn't", async ({ signal }) => {
    const today = await todays(signal);
    expect(today.length).toBeGreaterThan(50);
    const matches = (key: string | RegExp, name: string) => (typeof key === "string" ? key === name : key.test(name));
    expect(today.filter((name) => !WHERE.some(([key]) => matches(key, name)))).toEqual([]);
    expect(WHERE.filter(([key]) => !today.some((name) => matches(key, name))).map(([key]) => String(key))).toEqual([]);
  });

  it.each(WHERE.filter(([, w]) => !("reason" in w)).map(([k, w]) => [String(k), w]) as [string, Exclude<Where, { reason: string }>][])("finds %s on the new page", async (_, where) => {
    fixtures();
    if (where.state === "failed") fail("client_workspace", "The server is out of reach.");
    await show({ menu: MENU() });
    if (where.tab !== R) await openTab(where.tab);
    if (where.state === "menu") await press("button", "Project menu");
    if (where.state === "search") {
      fireEvent.change(screen.getByRole("searchbox", { name: "Search Qureshi Textiles" }), { target: { value: "advance" } });
      await searched();
    }
    if (where.state === "paste") await press("button", "Paste instructions");
    if (where.state === "brief") await press("button", "Edit");
    if (where.state === "answered") {
      fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "What's the advance?" } });
      fireEvent.click(within(document.querySelector("[data-v2=ask]") as HTMLElement).getByRole("button", { name: "Ask" }));
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

  it("Copy for AI copies the brief, memory and recent call notes as one text", async () => {
    respond("client_bundle", "# Qureshi Textiles\nGoal: Ship 40,000 metres");
    const writeText = vi.fn(async () => undefined);
    Object.assign(navigator, { clipboard: { writeText } });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Copy for AI" }));
    await act(flushAsync);
    expect(callsOf("client_bundle")).toEqual([{ id: "c1", notes: null }]);
    expect(writeText).toHaveBeenCalledWith("# Qureshi Textiles\nGoal: Ship 40,000 metres");
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Copied the brief, memory and recent call notes for AI");
    fail("client_bundle", "The server is out of reach.");
    fireEvent.click(screen.getByRole("button", { name: "Copy for AI" }));
    await act(flushAsync);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("The server is out of reach.");
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
    // The tabs that list things show their counts after the name (1 recording, 2 files).
    // UX-09's tabs (Ask needs the plan's AI notes); Screens and the call notes are sections of Files and Memory now.
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Recordings1", "Files2", "Memory & instructions", "Members"]);
    await openTab("Memory & instructions");
    expect(screen.getByTestId("ai-not-in-plan")).toHaveTextContent("not in your workspace's plan");
    expect(screen.getByRole("button", { name: "Edit" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Write again" })).toBeNull();
  });

  it("the AI notes switch turns call notes off for the project", async () => {
    respond("client_update", { data: { id: "c1", name: "Qureshi Textiles", ai_notes: false } });
    const p = await show();
    await openTab("Memory & instructions");
    expect(screen.getByRole("region", { name: "Call notes" })).toBeInTheDocument();
    expect(screen.getByTestId("ai-budget")).toHaveTextContent("AI this month");
    fireEvent.click(screen.getByRole("switch", { name: "AI notes" }));
    await act(flushAsync);
    expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { ai_notes: false } }]);
    expect(p.onProjectChanged).toHaveBeenCalledWith({ id: "c1", ai_notes: false });
    expect(screen.queryByRole("region", { name: "Call notes" })).toBeNull();
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
    fireEvent.click(within(memory()).getByRole("button", { name: "Pin “40% in advance, the balance against the bill of lading”" }));
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

describe("the project's header (parity DT-PRJ-006)", () => {
  it("says how many recordings and when the last call was, as classic's", async () => {
    respond("library_list", [
      makeItem("bilal", { title: "Payment terms", client: { id: "c1", name: "Qureshi Textiles" }, started_at: "2026-11-10T06:42:00Z" }),
      makeItem("later", { title: "Follow-up", client: { id: "c1", name: "Qureshi Textiles" }, started_at: "2026-11-17T06:42:00Z" }),
      makeItem("other", { title: "Weekly sales review", client: { id: "c2", name: "Mehran Foods" }, started_at: "2026-11-18T06:42:00Z" }),
    ]);
    await show();
    expect(screen.getByTestId("project-summary")).toHaveTextContent(/^2 recordings · Last call /);
    expect(screen.getByTestId("project-summary").textContent).toContain(formatWhen("2026-11-17T06:42:00Z").split(" · ")[0]);
    // By the instant, not the text: 10:00 at +05:00 is before 06:42 UTC.
    expect(latestStart([{ started_at: "2026-11-17T10:00:00+05:00" }, { started_at: "2026-11-17T06:42:00Z" }, { started_at: null }, { started_at: "soon" }])).toBe("2026-11-17T06:42:00Z");
  });
});

describe("a locked tab (parity DT-PRJ-012)", () => {
  it("Screens carries the plan that has screen moments when the plan leaves them out", async () => {
    resetEntitlementsForTests(makeEntitlements());
    try {
      await show();
      // Screens is a section of Files (UX-09): its title carries the plan.
      await openTab("Files");
      expect(screen.getByRole("heading", { name: /^Screens Pro$/ })).toBeInTheDocument();
    } finally {
      resetEntitlementsForTests(null);
    }
  });
});

describe("a project's page in UX-09", () => {
  beforeEach(() => {
    respond("account_get", { name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null });
  });

  it("its tabs are Recordings · Files · Memory & instructions · Ask · Members; Request files is a button in its header", async () => {
    const onRequest = vi.fn();
    await show({ onRequest });
    expect(screen.getAllByRole("tab").map((t) => t.textContent)).toEqual(["Recordings1", "Files2", "Memory & instructions", "Ask", "Members"]);
    const header = document.querySelector<HTMLElement>("[data-v2=workspace] .el-page__head")!;
    fireEvent.click(within(header).getByRole("button", { name: "Request files" }));
    expect(onRequest).toHaveBeenCalled();
  });

  it("Members: you, and whoever its open share links let in; Share and Send a copy; a project shared with nobody says so", async () => {
    const link = (over: Record<string, unknown>) => ({ id: "l1", session_id: null, has_password: false, include_audio: true, expires_at: null, revoked_at: null, active: true,
      view_count: 2, last_viewed_at: null, created_by: null, created_at: "2026-11-20T08:00:00Z", kind: "project", client_id: "c1", ...over });
    respond("shares_of", { data: [
      link({ id: "l1", audience: "people", people: ["sara@acme.pk", "bilal@qureshitextiles.pk"] }),
      link({ id: "l2", audience: "public" }),
      link({ id: "l3", audience: "public", active: false, revoked_at: "2026-11-21T08:00:00Z" }),
    ] });
    const onShare = vi.fn();
    const onSend = vi.fn();
    await show({ onShare, onSend });
    await openTab("Members");
    expect(callsOf("shares_of")).toContainEqual({ sessionId: null, clientId: "c1" });
    const members = within(screen.getByRole("list", { name: "Members" }));
    expect(members.getAllByRole("listitem").map((r) => r.querySelector(".el-row__title")?.textContent)).toEqual([
      "Ayesha Siddiqui (you)", "sara@acme.pk", "bilal@qureshitextiles.pk", "Anyone with the link",
    ]);
    fireEvent.click(screen.getByRole("button", { name: "Share…" }));
    fireEvent.click(screen.getByRole("button", { name: "Send a copy to a teammate…" }));
    expect([onShare, onSend].every((f) => f.mock.calls.length === 1)).toBe(true);
  });

  it("Members of a project shared with nobody: only you, and why", async () => {
    respond("shares_of", { data: [] });
    await show();
    await openTab("Members");
    expect(within(screen.getByRole("list", { name: "Members" })).getAllByRole("listitem")).toHaveLength(1);
    expect(screen.getByText(/a project is private until you share it/)).toBeInTheDocument();
  });

  it("Files has the screens kept in its meetings, and Memory & instructions the call notes", async () => {
    respond("client_screens", { data: [{ ...makeScreen(1, 60_000), id: "s1-1", session_id: "bilal", session_title: "Kick-off", session_started_at: "2026-11-04T10:00:00Z" }], next_cursor: null });
    respondWith("screen_image", ({ url }) => `data:image/webp;base64,${btoa(String(url))}`);
    await show();
    await openTab("Files");
    expect(await screen.findByRole("button", { name: "Screen 1 · 1:00" })).toBeInTheDocument();
    expect(screen.getByRole("region", { name: "Screens" })).toBeInTheDocument();
    await openTab("Memory & instructions");
    expect(within(screen.getByRole("region", { name: "Call notes" })).getByRole("button", { name: "Open Payment terms for the March shipment" })).toBeInTheDocument();
  });
});
