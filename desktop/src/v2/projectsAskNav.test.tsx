// The new design against classic for the workspace menu, breadcrumbs, the project page, request links and Ask
// (docs/v2/audit/parity.csv DT-NAV-020, DT-NAV-021, DT-NAV-022, DT-LIB-001, DT-LIB-057, DT-PRJ-003, DT-PRJ-005,
// DT-PRJ-011, DT-PRJ-023, DT-SHR-006, DT-ASK-005, DT-ASK-007): each control sends classic's command or opens classic's
// place, and the page says what classic says.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import MainApp from "../views/main/MainApp";
import type { ClientFolder, ClientWorkspace, LibraryItem } from "../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../test/fixtures";
import { lowestPlan, planName, resetEntitlementsForTests } from "../lib/entitlements";
import { Shell, type ShellWorkspace } from "./shell/Shell";
import { LibraryV2 } from "./library/LibraryV2";
import { WorkspaceV2 } from "./workspace/WorkspaceV2";
import { AskV2 } from "./ask/AskV2";
import { runAction } from "./actions/registry";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const ready = { kind: "ready" as const, text: "Ready to record" };
const WS = (over: Partial<ClientWorkspace> = {}): ClientWorkspace => ({
  client: { id: "c2", name: "2026", ai_notes: true },
  ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 },
  brief: null, memory: [], notes: [],
  files: [{ id: "f1", name: "Proforma.pdf", mime: "application/pdf", size_bytes: 1000, pages: 1, status: "ready", error: null, instructions: false, created_at: null }],
  ...over,
});
const TREE: ClientFolder[] = [
  { id: "c0", name: "Qureshi Textiles" } as ClientFolder,
  { id: "c1", name: "Orders", parent_id: "c0" } as ClientFolder,
  { id: "c2", name: "2026", parent_id: "c1" } as ClientFolder,
];
const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Cutting schedule", client: { id: "c2", name: "2026" } as LibraryItem["client"] }),
  makeItem("b", { title: "Packing plan", client: { id: "c2", name: "2026" } as LibraryItem["client"] }),
  makeItem("z", { title: "Weekly Zoom", source_app: "zoom" }),
];
const nav = () => screen.getByRole("navigation", { name: "Main" });

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(makeEntitlements());
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("bin_list", { data: [] });
  respond("client_screens", null);
  respond("search_library", { data: [], next_page: null });
});

describe("the sidebar's workspace menu", () => {
  const workspace = (over: Partial<ShellWorkspace> = {}): ShellWorkspace => ({
    name: "Acme Ltd", role: "Owner", others: [{ key: "w2", name: "Personal", role: "Member" }, { key: "w3", name: "Mehran Foods", role: "Viewer" }],
    invite: { locked: null, onPick: vi.fn() }, onManage: vi.fn(), ...over,
  });
  const open = () => {
    fireEvent.click(within(nav()).getByRole("button", { name: "Acme Ltd · Owner" }));
    return within(screen.getByRole("menu"));
  };

  it("DT-NAV-020: it names this computer's workspace and role, and lists the others with their roles, said and not offered", () => {
    render(<Shell tab="library" onGo={() => undefined} status={ready} workspace={workspace()}><p /></Shell>);
    expect(within(nav()).getByRole("button", { name: "Acme Ltd · Owner" })).toHaveAttribute("title", "Workspace");
    const menu = open();
    expect(menu.getByText("Your other workspaces")).toBeInTheDocument();
    expect(menu.getByRole("menuitem", { name: "Personal · Member" })).toHaveAttribute("aria-disabled", "true");
    expect(menu.getByRole("menuitem", { name: "Mehran Foods · Viewer" })).toHaveAttribute("aria-disabled", "true");
    expect(menu.getByText("This computer works in Acme Ltd")).toBeInTheDocument();
  });

  it("DT-NAV-021 and DT-NAV-022: Invite people and Switch or manage in the browser do what they say", () => {
    const w = workspace();
    render(<Shell tab="library" onGo={() => undefined} status={ready} workspace={w}><p /></Shell>);
    fireEvent.click(open().getByRole("menuitem", { name: "Invite people" }));
    expect(w.invite!.onPick).toHaveBeenCalledTimes(1);
    fireEvent.click(open().getByRole("menuitem", { name: "Switch or manage in the browser" }));
    expect(w.onManage).toHaveBeenCalledTimes(1);
  });

  it("DT-NAV-021: a member is not offered Invite people", () => {
    render(<Shell tab="library" onGo={() => undefined} status={ready} workspace={workspace({ role: "Member", invite: null })}><p /></Shell>);
    fireEvent.click(within(nav()).getByRole("button", { name: "Acme Ltd · Member" }));
    expect(screen.queryByRole("menuitem", { name: /^Invite people/ })).toBeNull();
    expect(screen.getByRole("menuitem", { name: "Switch or manage in the browser" })).toBeInTheDocument();
  });

  function app(account: Record<string, unknown>) {
    respond("app_status", makeStatus());
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("session_detail", {});
    respond("server_page_open", null);
    respond("account_get", { name: "Ayesha", email: "ayesha@example.test", email_verified: true, two_factor: false, google: false, created_at: null, deletes_at: null, ...account });
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true }, ui_classic: false });
  }

  it("DT-NAV-020 to DT-NAV-022 in the app: the server's workspaces and roles; the browser opens the workspace page; a plan without teams says its plan", async () => {
    const teams = makeEntitlements({ features: { ...makeEntitlements().features, team: { enabled: true, name: null, description: null, cost: "paid", lowest_plan: "business" } } });
    resetEntitlementsForTests(teams);
    respond("entitlements_get", teams);
    app({ workspace: { id: 1, name: "Acme Ltd", role: "owner" }, workspaces: [{ id: 1, name: "Acme Ltd", role: "owner", current: true }, { id: 2, name: "Personal", role: "member", current: false }] });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" });
    const menu = open();
    expect(menu.getByRole("menuitem", { name: "Personal · Member" })).toBeInTheDocument();
    fireEvent.click(menu.getByRole("menuitem", { name: "Switch or manage in the browser" }));
    await act(flushAsync);
    expect(callsOf("server_page_open")).toEqual([{ page: "app/workspace", serverUrl: null }]);
    fireEvent.click(open().getByRole("menuitem", { name: "Invite people" }));
    await act(flushAsync);
    expect(callsOf("server_page_open")).toHaveLength(2);
  });

  it("DT-NAV-021 in the app: with teams outside the plan, Invite people names the plan and opens the upgrade sheet", async () => {
    app({ workspace: { id: 1, name: "Acme Ltd", role: "member" } });
    respond("entitlements_get", makeEntitlements());
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav()).getByRole("button", { name: "Acme Ltd · Member" }));
    const plan = planName(lowestPlan(makeEntitlements(), "team"));
    expect(plan).not.toBe("");
    fireEvent.click(screen.getByRole("menuitem", { name: `Invite people (in ${plan})` }));
    await act(flushAsync);
    expect(callsOf("server_page_open")).toEqual([]);
    expect(await screen.findByRole("dialog")).toHaveTextContent(plan);
  });
});

describe("breadcrumbs", () => {
  // PLAN_LIBRARY_FILTERS: one toolbar row, no breadcrumbs. Where the list is shows in the toolbar's title (the sidebar marks
  // it too); today's folders and views from elsewhere arrive as filters, each a pill whose × (or Clear all) shows everything.
  it("DT-LIB-001: a folder from before arrives as its filter, a pill; its × shows every recording again", async () => {
    respondWith("library_folder", () => [ITEMS[2]]);
    const onFolder = vi.fn();
    const { rerender } = render(<LibraryV2 folder={null} onFolder={onFolder} />);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: /^All/ })).toBeInTheDocument();
    expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
    rerender(<LibraryV2 folder="zoom" onFolder={onFolder} />);
    await act(flushAsync);
    // Taken at once, so the same folder can be asked for again.
    expect(onFolder).toHaveBeenCalledWith(null);
    const pills = within(screen.getByRole("group", { name: "Filters on" }));
    expect(pills.getByRole("button", { name: "Source: Zoom" })).toBeInTheDocument();
    fireEvent.click(pills.getByRole("button", { name: "Remove Source: Zoom" }));
    await act(flushAsync);
    expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
  });

  it("DT-LIB-001: a view from before (Pinned) arrives as its filter too, and Clear all goes back to every recording", async () => {
    render(<LibraryV2 viewRequest={{ view: "pinned", seq: 1 }} />);
    await act(flushAsync);
    const pills = within(screen.getByRole("group", { name: "Filters on" }));
    expect(pills.getByRole("button", { name: "Marked: Pinned" })).toBeInTheDocument();
    fireEvent.click(pills.getByRole("button", { name: "Clear all" }));
    await act(flushAsync);
    expect(screen.queryByRole("group", { name: "Filters on" })).toBeNull();
    expect(screen.getByRole("heading", { level: 1, name: /^All/ })).toBeInTheDocument();
  });

  it("DT-PRJ-005: a project's page says Projects › its folders › itself; each step opens its page", async () => {
    respond("client_workspace", { data: WS() });
    const onProjects = vi.fn();
    const onOpenProject = vi.fn();
    render(<WorkspaceV2 project={{ id: "c2", name: "2026" }} projects={TREE} parent={{ id: "c1", name: "Orders" }} onBack={vi.fn()} onProjects={onProjects}
      onOpenProject={onOpenProject} onOpenRecording={vi.fn()} onLibrary={vi.fn()} />);
    await act(flushAsync);
    const crumbs = within(screen.getByRole("navigation", { name: "Where this project is" }));
    expect(crumbs.getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Projects", "Qureshi Textiles", "Orders", "2026"]);
    fireEvent.click(crumbs.getByRole("button", { name: "Projects" }));
    fireEvent.click(crumbs.getByRole("button", { name: "Qureshi Textiles" }));
    fireEvent.click(crumbs.getByRole("button", { name: "Orders" }));
    expect(onProjects).toHaveBeenCalledTimes(1);
    expect(onOpenProject.mock.calls).toEqual([["c0"], ["c1"]]);
    expect(crumbs.getByText("2026")).toHaveAttribute("aria-current", "page");
  });
});

describe("the project page", () => {
  it("DT-PRJ-011: Recordings and Files say how many they list", async () => {
    respond("client_workspace", { data: WS() });
    render(<WorkspaceV2 project={{ id: "c2", name: "2026" }} projects={TREE} onBack={vi.fn()} onOpenRecording={vi.fn()} onLibrary={vi.fn()} />);
    await act(flushAsync);
    expect(screen.getByRole("tab", { name: "Recordings" })).toHaveTextContent("Recordings2");
    expect(screen.getByRole("tab", { name: "Files" })).toHaveTextContent("Files1");
    // Screen readers hear the count in words with the tab; its name stays the tab's.
    expect(screen.getByRole("tab", { name: "Recordings" })).toHaveAccessibleDescription("2 recordings");
    expect(screen.getByRole("tab", { name: "Files" })).toHaveAccessibleDescription("1 file");
    expect(screen.getByRole("tab", { name: "Memory & instructions" }).querySelector(".el-tab__count")).toBeNull();
  });

  it("DT-PRJ-023: a brief written again and a file read while away each send a notification; nothing while in front", async () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "setInterval", "Date"] });
    try {
      respond("notification_send", true);
      const answers = [
        WS({ brief: { sections: {}, edited: false, status: "working", error: null, generated_at: null, updated_at: null } as never,
          files: [{ id: "f1", name: "Proforma.pdf", mime: null, size_bytes: 1, pages: 1, status: "extracting", error: null, instructions: false, created_at: null }] }),
        WS({ brief: { sections: {}, edited: false, status: "ready", error: null, generated_at: null, updated_at: null } as never }),
      ];
      respondWith("client_workspace", () => ({ data: answers.length > 1 ? answers.shift()! : answers[0] }));
      const focus = vi.spyOn(document, "hasFocus").mockReturnValue(false);
      render(<WorkspaceV2 project={{ id: "c2", name: "2026" }} projects={TREE} onBack={vi.fn()} onOpenRecording={vi.fn()} onLibrary={vi.fn()} />);
      await act(flushAsync);
      await act(async () => {
        await vi.advanceTimersByTimeAsync(6_000);
      });
      await act(flushAsync);
      expect(callsOf("notification_send")).toEqual([
        { category: "enhancement", title: "Brief ready", body: "2026's brief was written again." },
        { category: "enhancement", title: "File read", body: "2026: Proforma.pdf" },
      ]);
      focus.mockRestore();
    } finally {
      vi.useRealTimers();
    }
  });

  it("DT-PRJ-023: the same work finishing while the window is in front sends nothing", async () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "setInterval", "Date"] });
    try {
      respond("notification_send", true);
      const answers = [
        WS({ brief: { sections: {}, edited: false, status: "working", error: null, generated_at: null, updated_at: null } as never,
          files: [{ id: "f1", name: "Proforma.pdf", mime: null, size_bytes: 1, pages: 1, status: "extracting", error: null, instructions: false, created_at: null }] }),
        WS({ brief: { sections: {}, edited: false, status: "ready", error: null, generated_at: null, updated_at: null } as never }),
      ];
      respondWith("client_workspace", () => ({ data: answers.length > 1 ? answers.shift()! : answers[0] }));
      const focus = vi.spyOn(document, "hasFocus").mockReturnValue(true);
      render(<WorkspaceV2 project={{ id: "c2", name: "2026" }} projects={TREE} onBack={vi.fn()} onOpenRecording={vi.fn()} onLibrary={vi.fn()} />);
      await act(flushAsync);
      await act(async () => {
        await vi.advanceTimersByTimeAsync(6_000);
      });
      await act(flushAsync);
      // The brief and the file did finish (the page reads them again)…
      expect(callsOf("client_workspace").length).toBeGreaterThan(1);
      // …but the person is looking: no notification.
      expect(callsOf("notification_send")).toEqual([]);
      focus.mockRestore();
    } finally {
      vi.useRealTimers();
    }
  });
});

describe("request links", () => {
  function app() {
    respond("app_status", makeStatus());
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [{ id: "c0", name: "Qureshi Textiles" }] });
    respond("mic_users", []);
    respond("session_detail", {});
    respond("request_links", { data: [] });
    respond("client_workspace", { data: WS({ client: { id: "c0", name: "Qureshi Textiles", ai_notes: true } }) });
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.ask": true }, ui_classic: false });
  }

  it("DT-PRJ-003 and DT-SHR-006: the Projects page's Request files opens the new Request files sheet, into no project", async () => {
    app();
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Projects" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "Request files" }));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "Request files" });
    // The new design's sheet (its own look), never classic's.
    expect(dialog.closest(".el-root")).not.toBeNull();
    expect(document.querySelector(".sheet-backdrop")).toBeNull();
    const sheet = within(dialog);
    expect(sheet.getByRole("combobox", { name: /^Files go to/ })).toHaveTextContent("No project");
    expect(sheet.getByRole("textbox", { name: "Title people see" })).toHaveValue("Send me your voice notes");
  });

  it("UX-09: the project menu's Members… and Instructions & memory… open the project on that tab", async () => {
    app();
    respond("shares_of", { data: [] });
    respond("sent_of", { data: [] });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" });
    expect(runAction("project.members", { platform: "macos", targets: ["c0"], context: true })).toBe(true);
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("tab", { name: "Members", selected: true })).toBeInTheDocument();
    expect(runAction("project.instructions", { platform: "macos", targets: ["c0"], context: true })).toBe(true);
    await act(flushAsync);
    expect(await screen.findByRole("tab", { name: "Memory & instructions", selected: true })).toBeInTheDocument();
  });

  it("DT-SHR-006: a project's own Request files (a button in its header, UX-09) opens the new sheet with that project chosen", async () => {
    app();
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Projects" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click((await screen.findAllByRole("button", { name: /^Qureshi Textiles/ }))[0]);
    await act(flushAsync);
    await act(flushAsync);
    // The rule changed with UX-09: Request files was "Request files into it…" in the project menu; it is the header's
    // button now, and the menu doesn't repeat it.
    fireEvent.click(await screen.findByRole("button", { name: "Project menu" }));
    expect(screen.queryByRole("menuitem", { name: "Request files into it…" })).toBeNull();
    fireEvent.keyDown(document.activeElement!, { key: "Escape" });
    const header = document.querySelector<HTMLElement>("[data-v2=workspace] .el-page__head")!;
    fireEvent.click(within(header).getByRole("button", { name: "Request files" }));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "Request files" });
    // The new design's sheet (its own look), never classic's.
    expect(dialog.closest(".el-root")).not.toBeNull();
    expect(document.querySelector(".sheet-backdrop")).toBeNull();
    const sheet = within(dialog);
    expect(sheet.getByRole("combobox", { name: /^Files go to/ })).toHaveTextContent("Qureshi Textiles");
  });
});

describe("Ask", () => {
  const PROJECTS = [{ id: "c0", name: "Qureshi Textiles" }];
  const ask = () => render(<AskV2 projects={PROJECTS} onOpenAt={vi.fn()} onOpenProject={vi.fn()} onLibrary={vi.fn()} />);

  it("DT-ASK-005: with AI not set up on the server, Ask says so before anything is asked, and asks nothing", async () => {
    respond("client_workspace", { data: WS({ ai: { configured: false, spent_usd: 0, budget_usd: 0, client_spent_usd: 0 } }) });
    respond("client_ask", { data: { answer: "x", citations: [] } });
    ask();
    await act(flushAsync);
    expect(callsOf("client_workspace")).toEqual([{ id: "c0" }]);
    expect(screen.getByTestId("ask-no-ai")).toHaveTextContent("Ask needs AI set up on the server. Ask the person who runs the console to set it up.");
    const field = screen.getByRole("textbox", { name: "Your question" });
    fireEvent.change(field, { target: { value: "What was agreed?" } });
    expect(screen.getByRole("button", { name: "Ask" })).toBeDisabled();
    fireEvent.keyDown(field, { key: "Enter", ctrlKey: true });
    await act(flushAsync);
    expect(callsOf("client_ask")).toEqual([]);
  });

  it("DT-ASK-005: with AI set up there is no notice and Ask asks", async () => {
    respond("client_workspace", { data: WS() });
    respond("client_ask", { data: { answer: "40% in advance.", citations: [] } });
    ask();
    await act(flushAsync);
    expect(screen.queryByTestId("ask-no-ai")).toBeNull();
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "What was agreed?" } });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    expect(callsOf("client_ask")).toHaveLength(1);
  });

  it("DT-ASK-007: an answer that comes while the window is away sends Answer ready with the question; none while in front", async () => {
    respond("client_workspace", { data: WS() });
    respond("client_ask", { data: { answer: "40% in advance.", citations: [] } });
    respond("notification_send", true);
    const focus = vi.spyOn(document, "hasFocus").mockReturnValue(false);
    ask();
    await act(flushAsync);
    fireEvent.change(screen.getByRole("textbox", { name: "Your question" }), { target: { value: "What was agreed?" } });
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    expect(callsOf("notification_send")).toEqual([{ category: "enhancement", title: "Answer ready", body: "What was agreed?" }]);
    focus.mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Ask" }));
    await act(flushAsync);
    expect(callsOf("notification_send")).toHaveLength(1);
    focus.mockRestore();
  });
});

describe("the Library's group headers", () => {
  it("DT-LIB-057: a group's header stays at the top while its group scrolls, except while picking (the selection bar holds it)", async () => {
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "styles", "v2.css"), "utf8");
    const rule = /\[data-v2="library"\]:not\(:has\(\.el-selectbar\)\) \.el-list > \.el-list-section \{([^}]*)\}/.exec(css);
    expect(rule?.[1]).toMatch(/position: sticky; inset-block-start: 0; z-index: var\(--el-layer-sticky\); background: var\(--el-role-bg-canvas\);/);
    // The headers the rule holds are the group headers of the list the Library draws, one per group.
    render(<LibraryV2 />);
    await act(flushAsync);
    const list = document.querySelector('[data-v2="library"] .el-list')!;
    const headers = [...list.children].filter((c) => c.classList.contains("el-list-section"));
    expect(headers.length).toBeGreaterThan(0);
    expect(document.querySelector(".el-selectbar")).toBeNull();
    // No Select button, and no Select all until picking (UX-05: the selection bar has it): a row's checkbox starts it.
    expect(screen.queryByRole("checkbox", { name: "Select all" })).toBeNull();
    fireEvent.click(screen.getAllByRole("checkbox", { name: /^Select / })[0]);
    expect(document.querySelector('[data-v2="library"] .el-selectbar')).not.toBeNull();
  });
});
