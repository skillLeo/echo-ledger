// The new design's import sheet, drop hint, Projects page and name dialog against classic (docs/v2/audit/parity.csv
// DT-HLP-005, DT-HLP-006, DT-PRJ-001, DT-PRJ-002): the same commands with the same arguments, the same words.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../views/main/MainApp";
import type { ClientFolder } from "../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../test/fixtures";
import { resetEntitlementsForTests } from "../lib/entitlements";
import { ImportSheetV2, importHeading } from "./library/ImportSheetV2";
import { ProjectsListV2, projectLine } from "./workspace/ProjectsListV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

const NOTE = "C:\\Users\\me\\Downloads\\PTT-20261030-WA0003.opus";
const VIDEO = "C:\\Users\\me\\Videos\\Call_with_Acme_Oct.mp4";
const TEXT = "C:\\Users\\me\\Desktop\\readme.txt";
const DONE = { queued: 2, duplicates: 0, unsupported: 0, failed: 0 };
const PROJECTS: ClientFolder[] = [
  { id: "c1", name: "Acme Holdings" } as ClientFolder,
  { id: "c1a", name: "March order", parent_id: "c1" } as ClientFolder,
  { id: "c2", name: "Northwind" } as ClientFolder,
];
const ITEMS = [
  makeItem("a", { title: "Zoom with Acme", started_at: "2026-11-17T06:00:00Z", client: { id: "c1", name: "Acme Holdings" } as never }),
  makeItem("b", { title: "Cutting plan", started_at: "2026-11-16T06:00:00Z", client: { id: "c1a", name: "March order" } as never }),
];
const pick = (combo: string, option: string) => {
  fireEvent.click(screen.getByRole("combobox", { name: new RegExp(`^${combo}`) }));
  fireEvent.click(screen.getByRole("option", { name: option }));
};

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(makeEntitlements());
  respond("list_clients", { data: PROJECTS });
  respond("import_voice_notes", DONE);
});

describe("the import sheet", () => {
  it("DT-HLP-005: each file is listed by the title it will get; one project and language for all; Import N files imports them", async () => {
    const notify = vi.fn();
    const onClose = vi.fn();
    render(<div className="el-root"><ImportSheetV2 paths={[NOTE, VIDEO, TEXT]} notify={notify} onClose={onClose} /></div>);
    await act(flushAsync);
    expect(importHeading(0)).toBe("Nothing to import");
    const dialog = within(screen.getByRole("dialog", { name: "Import 2 files" }));
    expect(dialog.getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "PTT-20261030-WA0003PTT-20261030-WA0003.opus", "Call with Acme OctCall_with_Acme_Oct.mp4", "readme.txtCan't be imported: not a voice note, audio or video file",
    ]);
    pick("Project", "Acme Holdings");
    pick("Language", "Urdu (with English words)");
    fireEvent.click(dialog.getByRole("button", { name: "Import 2 files" }));
    await act(flushAsync);
    expect(callsOf("import_voice_notes")).toEqual([{ pathsList: [NOTE, VIDEO, TEXT], clientId: "c1", language: "ur" }]);
    expect(notify).toHaveBeenCalledWith(expect.stringMatching(/2/), false, { kind: "page", page: "activity" });
    expect(onClose).toHaveBeenCalled();
  });

  it("DT-HLP-005: no project and Auto by default; a refusal is said and the sheet stays", async () => {
    fail("import_voice_notes", "The disk is full.");
    const notify = vi.fn();
    const onClose = vi.fn();
    render(<div className="el-root"><ImportSheetV2 paths={[NOTE]} notify={notify} onClose={onClose} /></div>);
    await act(flushAsync);
    expect(screen.getByRole("combobox", { name: /^Project/ })).toHaveTextContent("No project");
    expect(screen.getByRole("combobox", { name: /^Language/ })).toHaveTextContent("Auto");
    fireEvent.click(screen.getByRole("button", { name: "Import" }));
    await act(flushAsync);
    expect(callsOf("import_voice_notes")).toEqual([{ pathsList: [NOTE], clientId: null, language: null }]);
    expect(notify).toHaveBeenCalledWith("The disk is full.", true);
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByRole("dialog", { name: "Import 1 file" })).toBeInTheDocument();
  });

  it("DT-HLP-005: the same file picked twice is listed twice, each row its own", async () => {
    const errors = vi.spyOn(console, "error").mockImplementation(() => undefined);
    render(<div className="el-root"><ImportSheetV2 paths={[NOTE, NOTE]} notify={vi.fn()} onClose={vi.fn()} /></div>);
    await act(flushAsync);
    expect(within(screen.getByRole("list", { name: "Files to import" })).getAllByRole("listitem")).toHaveLength(2);
    expect(errors.mock.calls.filter((c) => String(c[0]).includes("same key"))).toEqual([]);
    errors.mockRestore();
  });

  it("DT-HLP-005: nothing importable: Nothing to import, and Import can't be pressed; Cancel closes", async () => {
    const onClose = vi.fn();
    render(<div className="el-root"><ImportSheetV2 paths={[TEXT]} notify={vi.fn()} onClose={onClose} /></div>);
    await act(flushAsync);
    expect(screen.getByRole("dialog", { name: "Nothing to import" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Import" })).toBeDisabled();
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(onClose).toHaveBeenCalled();
  });
});

describe("in the app with the new design", () => {
  function app(flags: Record<string, boolean> = { "ui_v2.shell": true }) {
    respond("app_status", makeStatus());
    respond("get_settings", makeSettings());
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("mic_users", []);
    respond("session_detail", {});
    respond("pending_list", []);
    respond("upload_list", []);
    respond("request_links", { data: [] });
    respond("ui_flags", { ui_flags: flags, ui_classic: false });
  }
  async function start(flags?: Record<string, boolean>) {
    app(flags);
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" });
  }

  it("DT-HLP-006: files dragged over any page show the new drop hint; a drop opens the new sheet; classic's stay away", async () => {
    await start();
    await act(() => emitEvent("tauri://drag-enter", { paths: [NOTE], position: { x: 10, y: 10 } }));
    await act(flushAsync);
    expect(await screen.findByTestId("drop-hint")).toHaveTextContent("Drop voice notes, audio or video files to import them");
    expect(document.querySelector(".drop-hint")).toBeNull();
    await act(() => emitEvent("tauri://drag-leave", null));
    expect(screen.queryByTestId("drop-hint")).toBeNull();
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" }));
    await act(flushAsync);
    await act(() => emitEvent("tauri://drag-drop", { paths: [NOTE, VIDEO], position: { x: 10, y: 10 } }));
    await act(flushAsync);
    const dialog = await screen.findByRole("dialog", { name: "Import 2 files" });
    expect(dialog.closest(".el-root")).not.toBeNull();
    expect(document.querySelector(".sheet-backdrop")).toBeNull();
    fireEvent.click(within(dialog).getByRole("button", { name: "Import 2 files" }));
    await act(flushAsync);
    expect(callsOf("import_voice_notes")).toEqual([{ pathsList: [NOTE, VIDEO], clientId: null, language: null }]);
  });

  it("DT-PRJ-001: Projects lists every project in the new look; a project opens its page", async () => {
    await start({ "ui_v2.shell": true, "ui_v2.ask": true });
    respond("client_workspace", { data: { client: { id: "c2", name: "Northwind", ai_notes: true }, ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 }, brief: null, memory: [], notes: [], files: [] } });
    respond("client_screens", null);
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Projects" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Projects" })).toBeInTheDocument();
    expect(document.querySelector('[data-v2="projects"]')).not.toBeNull();
    expect(document.querySelector(".projects-home")).toBeNull();
    const list = within(within(screen.getByRole("main")).getByRole("list", { name: "Projects" }));
    fireEvent.click(list.getByRole("button", { name: /^Northwind/ }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Northwind" })).toBeInTheDocument();
  });

  it("DT-PRJ-002: New project asks for a name in the new dialog, adds it and opens it", async () => {
    await start({ "ui_v2.shell": true, "ui_v2.ask": true });
    respond("client_create", { data: { id: "c9", name: "Mehran Foods" } });
    respond("client_workspace", { data: { client: { id: "c9", name: "Mehran Foods", ai_notes: true }, ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 }, brief: null, memory: [], notes: [], files: [] } });
    respond("client_screens", null);
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Projects" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await within(screen.getByRole("main")).findByRole("button", { name: "New project" }));
    await act(flushAsync);
    const dialog = await screen.findByRole("dialog", { name: "New project" });
    expect(dialog.closest(".el-root")).not.toBeNull();
    const add = within(dialog).getByRole("button", { name: "Add project" });
    expect(add).toBeDisabled();
    fireEvent.change(within(dialog).getByLabelText("Name"), { target: { value: "  Mehran Foods " } });
    // The server lists it from now on.
    respond("list_clients", { data: [...PROJECTS, { id: "c9", name: "Mehran Foods" }] });
    fireEvent.click(add);
    await act(flushAsync);
    await act(flushAsync);
    expect(callsOf("client_create").at(-1)).toMatchObject({ name: "Mehran Foods", parentId: null });
    expect(await screen.findByRole("heading", { level: 1, name: "Mehran Foods" })).toBeInTheDocument();
  });
});

describe("the Projects page", () => {
  const props = () => ({ error: null, onRetry: vi.fn(), items: ITEMS, expanded: new Set<string>(), onToggle: vi.fn(), onOpen: vi.fn(), onNew: vi.fn(), onRequest: vi.fn() });

  it("DT-PRJ-001: each project with its recordings and last call; folders under their project, shown or hidden", () => {
    const p = props();
    const { rerender } = render(<ProjectsListV2 projects={PROJECTS} {...p} />);
    const list = within(screen.getByRole("list", { name: "Projects" }));
    expect(projectLine(PROJECTS[0], PROJECTS, ITEMS, 1)).toMatch(/^2 recordings · .+ · 1 folder$/);
    expect(list.getAllByRole("listitem")).toHaveLength(2);
    const fold = list.getByRole("button", { name: "Show the folders in Acme Holdings" });
    expect(fold).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(fold);
    expect(p.onToggle).toHaveBeenCalledWith("c1");
    rerender(<ProjectsListV2 projects={PROJECTS} {...p} expanded={new Set(["c1"])} />);
    expect(within(screen.getByRole("list", { name: "Projects" })).getAllByRole("listitem").map((li) => li.querySelector(".el-body-strong")?.textContent))
      .toEqual(["Acme Holdings", "March order", "Northwind"]);
    expect(screen.getByRole("button", { name: "Hide the folders in Acme Holdings" })).toHaveAttribute("aria-expanded", "true");
    // A folder says where it sits and its level; a top project says neither.
    expect(screen.getByRole("button", { name: /^March order, folder in Acme Holdings, level 2/ })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /^Northwind/ }).textContent).not.toMatch(/level/);
    fireEvent.click(screen.getByRole("button", { name: /^March order/ }));
    expect(p.onOpen).toHaveBeenCalledWith("c1a");
    fireEvent.click(screen.getByRole("button", { name: "Request files" }));
    fireEvent.click(screen.getByRole("button", { name: "New project" }));
    expect(p.onRequest).toHaveBeenCalled();
    expect(p.onNew).toHaveBeenCalled();
  });

  it("DT-PRJ-001: loading, a failed load with Retry, and no projects yet", () => {
    const p = props();
    const { rerender } = render(<ProjectsListV2 projects={null} {...p} />);
    expect(screen.getByRole("status")).toHaveTextContent("Loading projects");
    rerender(<ProjectsListV2 projects={null} {...p} error="The server didn't answer." />);
    expect(screen.getByTestId("projects-error")).toHaveTextContent("Couldn't load the projects. The server didn't answer.");
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    expect(p.onRetry).toHaveBeenCalled();
    rerender(<ProjectsListV2 projects={[]} {...p} onRequest={null} />);
    // UX-09 changed its words: one empty state saying what a project is for, with the ways to start (no second New
    // project in the header, and Request files from a client only where the server has request links).
    expect(screen.getByRole("region", { name: "Keep each client's calls, files and notes together" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /^Request files/ })).toBeNull();
    expect(screen.getAllByRole("button", { name: "New project" })).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "New project" }));
    expect(p.onNew).toHaveBeenCalled();
  });
});
