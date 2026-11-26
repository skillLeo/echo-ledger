// Desktop Urdu, D-261: the Projects page's name dialogs as the new design shows them (New project, New folder in a
// project, Rename: their titles, buttons and words), the project menu it hands a project's page, and the notices after
// adding, renaming, moving and deleting, in the window's language. A project's or a recording's name is its own, in its
// own isolate. The classic Projects page keeps its English in every window, and English is unchanged.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { useState } from "react";
import type { ClientFolder, ClientWorkspace, LibraryItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { anyTarget, makeItem } from "../../test/fixtures";
import { FSI, PDI, resetLanguageForTests, setLanguage, stripIsolates } from "../../i18n";
import { deletedText, ProjectsView, type ProjectPageContext, type ProjectsListContext } from "../../views/main/ProjectsView";
import { movedText } from "../../views/main/ProjectDialogs";
import { NameDialogV2 } from "./ProjectsListV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const iso = (value: string | number) => `${FSI}${value}${PDI}`;
const urdu = () => act(() => setLanguage("ur", { root: false }));
/** Latin letters but the names kept as they are: what an Urdu line of the app's own words must not have. */
const latinIn = (words: string, kept: readonly string[] = []) =>
  /[A-Za-z]/.test(kept.reduce((rest, name) => rest.split(name).join(""), stripIsolates(words)));

const PROJECTS: ClientFolder[] = [
  { id: "c1", name: "Acme Holdings", session_count: 2, ai_notes: true, parent_id: null, path: [], children_count: 1, nested_session_count: 3 },
  { id: "c3", name: "Invoices", session_count: 1, ai_notes: true, parent_id: "c1", path: [{ id: "c1", name: "Acme Holdings" }], children_count: 0, nested_session_count: 1 },
  { id: "c2", name: "Zed Logistics", session_count: 0, ai_notes: true, parent_id: null, path: [], children_count: 0, nested_session_count: 0 },
];
const NAMES = ["Acme Holdings", "Invoices", "Zed Logistics"];
const RECORDINGS: LibraryItem[] = [
  makeItem("s1", { title: "Kick-off", client: { id: "c1", name: "Acme Holdings" }, started_at: "2026-09-30T10:00:00Z" }),
  makeItem("s2", { title: "Budget review", client: { id: "c1", name: "Acme Holdings" }, started_at: "2026-09-28T10:00:00Z" }),
];
const WORKSPACE = (id: string): ClientWorkspace => ({
  client: { id, name: PROJECTS.find((p) => p.id === id)?.name ?? "", ai_notes: true },
  ai: { configured: true, spent_usd: 0, budget_usd: 5, client_spent_usd: 0 },
  brief: null, memory: [], notes: [], files: [],
});

const notify = vi.fn();
let page: ProjectPageContext | null = null;
/** The new design's list and project page, as far as this page's dialogs need them: New project, and the menu. */
const renderList = (ctx: ProjectsListContext) => <button type="button" data-testid="list-new" onClick={ctx.onNew}>+</button>;
const renderPage = (ctx: ProjectPageContext) => {
  page = ctx;
  return (
    <div>
      <h1>{ctx.project.name}</h1>
      <ul aria-label="project-menu">{ctx.menu.map((m) => <li key={m.label}><button type="button" onClick={m.onPick}>{m.label}</button></li>)}</ul>
    </div>
  );
};

/** The Projects page with MainApp's state for it; `v2` gives it the new design's list, page and name dialog (ui_v2.ask). */
function Harness({ initial, v2 }: { initial: string | null; v2: boolean }) {
  const [projects, setProjects] = useState<ClientFolder[] | null>(PROJECTS);
  const [projectId, setProjectId] = useState<string | null>(initial);
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  return (
    <ProjectsView notify={notify} onOpenRecording={() => undefined} onReload={async () => undefined} projects={projects}
      onChanged={(change) => setProjects((l) => change(l ?? []))} projectId={projectId} onOpenProject={setProjectId}
      expanded={expanded} onToggle={(id) => setExpanded((s) => new Set(s.has(id) ? [...s].filter((x) => x !== id) : [...s, id]))}
      renderList={v2 ? renderList : null} renderPage={v2 ? renderPage : null} renderNameDialog={v2 ? (props) => <NameDialogV2 {...props} /> : null} />
  );
}

async function open({ project = null as string | null, v2 = true } = {}) {
  respond("library_list", RECORDINGS);
  respondWith("client_workspace", ({ id }) => ({ data: WORKSPACE(String(id)) }));
  render(<Harness initial={project} v2={v2} />);
  await act(flushAsync);
  await act(flushAsync);
}
/** The new design's project menu, item by item as shown. */
const menuWords = () => within(screen.getByRole("list", { name: "project-menu" })).getAllByRole("button").map((b) => b.textContent ?? "");
const pick = (item: string) => fireEvent.click(within(screen.getByRole("list", { name: "project-menu" })).getByRole("button", { name: item }));
const title = (dialog: HTMLElement) => dialog.querySelector("h2")?.textContent ?? "";
const typeName = (dialog: HTMLElement, value: string) => fireEvent.change(within(dialog).getByRole("textbox"), { target: { value } });

beforeEach(() => {
  resetTauriMock();
  resetLanguageForTests();
  notify.mockClear();
  page = null;
});
afterEach(async () => {
  await act(flushAsync);
  resetLanguageForTests();
});

describe("the new design's name dialogs and notices, in Urdu", () => {
  it("New project: its title, words and button in Urdu; the notice keeps the project's name", async () => {
    respond("client_create", { data: { id: "c9", name: "Nova", parent_id: null } });
    await urdu();
    await open();
    fireEvent.click(screen.getByTestId("list-new"));
    const dialog = screen.getByRole("dialog");
    expect(title(dialog)).toBe("نیا پروجیکٹ");
    expect(dialog).toHaveTextContent("پروجیکٹ اپنی کالز، فائلیں، بریف اور نوٹس ایک ساتھ رکھتا ہے، اور “پوچھیں” ان سب سے جواب دیتا ہے۔");
    expect(within(dialog).getByRole("button", { name: "پروجیکٹ شامل کریں" })).toBeDisabled();
    expect(latinIn(dialog.textContent ?? "")).toBe(false);
    typeName(dialog, " Nova ");
    fireEvent.click(within(dialog).getByRole("button", { name: "پروجیکٹ شامل کریں" }));
    await waitFor(() => expect(callsOf("client_create")).toEqual([{ name: "Nova", color: null, avatar: null, parentId: null }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith(`${iso("Nova")} کو شامل کر لیا گیا`, false, { kind: "project", id: "c9" }));
  });

  it("the project menu in Urdu, and New folder in a project: named after it, its notice naming both", async () => {
    respond("client_create", { data: { id: "c7", name: "Contracts", parent_id: "c1" } });
    await urdu();
    await open({ project: "c1" });
    expect(menuWords()).toEqual(["اندر نیا فولڈر", "نام بدلیں", "منتقل کریں…", "شیئر لنک…", "ساتھی کو بھیجیں…", "پروجیکٹ حذف کریں…"]);
    pick("اندر نیا فولڈر");
    const dialog = screen.getByRole("dialog");
    expect(title(dialog)).toBe(`${iso("Acme Holdings")} میں نیا فولڈر`);
    expect(dialog).toHaveTextContent("فولڈر پروجیکٹ کی کچھ ریکارڈنگز اور فائلیں الگ رکھتا ہے۔ اس کے اپنے نوٹس اور “پوچھیں” ہیں۔");
    expect(latinIn(dialog.textContent ?? "", NAMES)).toBe(false);
    typeName(dialog, "Contracts");
    fireEvent.click(within(dialog).getByRole("button", { name: "فولڈر شامل کریں" }));
    await waitFor(() => expect(callsOf("client_create")).toEqual([{ name: "Contracts", color: null, avatar: null, parentId: "c1" }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith(`${iso("Contracts")} کو ${iso("Acme Holdings")} میں شامل کر لیا گیا`, false, anyTarget));
  });

  it("Rename: the title names the project, the button and the notice in Urdu", async () => {
    respond("client_update", { data: { ...PROJECTS[0], name: "Acme Group" } });
    await urdu();
    await open({ project: "c1" });
    pick("نام بدلیں");
    const dialog = screen.getByRole("dialog");
    expect(title(dialog)).toBe(`${iso("Acme Holdings")} کا نام بدلیں`);
    expect(within(dialog).getByRole("textbox")).toHaveValue("Acme Holdings");
    expect(within(dialog).getByRole("button", { name: "نام بدلیں" })).toBeDisabled();
    expect(latinIn(dialog.textContent ?? "", NAMES)).toBe(false);
    typeName(dialog, "Acme Group");
    fireEvent.click(within(dialog).getByRole("button", { name: "نام بدلیں" }));
    await waitFor(() => expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { name: "Acme Group" } }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("نام بدل گیا", false, { kind: "project", id: "c1" }));
    expect(await screen.findByRole("heading", { name: "Acme Group" })).toBeInTheDocument();
  });

  it("a project moved or deleted, and recordings dropped on a project: the notices in Urdu, the names as they are", async () => {
    respond("client_update", { data: { ...PROJECTS[1], parent_id: "c2" } });
    respond("session_organise", { data: RECORDINGS[0] });
    await urdu();
    await open({ project: "c3" });
    pick("منتقل کریں…");
    // The move dialog (D-263): its title, words, picker and buttons in the window's language, the names as they are.
    const mover = screen.getByRole("dialog");
    expect(title(mover)).toBe(`${iso("Invoices")} منتقل کریں`);
    expect(mover).toHaveTextContent("اس کی ریکارڈنگز، فائلیں اور فولڈرز بھی ساتھ منتقل ہو جاتے ہیں۔");
    expect(within(mover).getByRole("radiogroup", { name: "کہاں منتقل کریں" })).toHaveTextContent("اوپری سطح");
    expect(within(mover).getByRole("radio", { name: /Acme Holdings/ }).closest("label")).toHaveTextContent("ابھی یہیں ہے");
    expect(latinIn(mover.textContent ?? "", NAMES)).toBe(false);
    fireEvent.click(within(mover).getByRole("radio", { name: /Zed Logistics/ }));
    fireEvent.click(within(mover).getByRole("button", { name: "منتقل کریں" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith(`${iso("Invoices")} کو ${iso("Zed Logistics")} میں منتقل کر دیا گیا`, false, anyTarget));

    act(() => page?.onMoveRecordings(["s1"], "c2"));
    await waitFor(() => expect(notify).toHaveBeenCalledWith(`“${iso("Kick-off")}” ${iso("Zed Logistics")} میں منتقل ہو گئی`, false, { kind: "project", id: "c2" }));
  });

  it("Delete project (D-263): its title, what happens to its recordings and folders, and its buttons in Urdu", async () => {
    await urdu();
    await open({ project: "c1" });
    pick("پروجیکٹ حذف کریں…");
    const dialog = screen.getByRole("dialog");
    expect(title(dialog)).toBe(`${iso("Acme Holdings")} حذف کریں؟`);
    expect([...dialog.querySelectorAll("p")].map((p) => p.textContent)).toEqual([
      `اس کی ${iso(2)} ریکارڈنگز ان باکس میں چلی جاتی ہیں، اس لیے کچھ ضائع نہیں ہوتا۔`,
      "اس کا فولڈر اپنی ریکارڈنگز سمیت اوپری سطح پر چلا جاتا ہے۔",
      "اس کی فائلیں، بریف، نوٹس، یادداشت اور فائلنگ کے قاعدے بھی ساتھ حذف ہو جاتے ہیں۔",
    ]);
    expect(within(dialog).getAllByRole("button").map((b) => b.textContent)).toEqual(["منسوخ کریں", "پروجیکٹ حذف کریں"]);
    expect(latinIn(dialog.textContent ?? "", NAMES)).toBe(false);
  });

  it("a delete's notice in Urdu: the project, its recordings to the Inbox, its folders up", async () => {
    respond("client_delete", { moved_sessions: 2, moved_projects: 1 });
    await urdu();
    await open({ project: "c1" });
    pick("پروجیکٹ حذف کریں…");
    fireEvent.click(within(screen.getByRole("dialog")).getByRole("button", { name: "پروجیکٹ حذف کریں" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith(
      `${iso("Acme Holdings")} حذف ہو گیا · ${iso(2)} ریکارڈنگز ان باکس میں منتقل ہو گئیں · ${iso(1)} فولڈر ایک سطح اوپر چلا گیا`, false, { kind: "library", folder: "inbox" }));
  });

  it("movedText and deletedText in the window's language, each form", async () => {
    await urdu();
    expect(movedText(1, "Kick-off", PROJECTS, "c3")).toBe(`“${iso("Kick-off")}” ${iso("Acme Holdings › Invoices")} میں منتقل ہو گئی`);
    expect(movedText(1, null, PROJECTS, "c3")).toBe(`ریکارڈنگ ${iso("Acme Holdings › Invoices")} میں منتقل ہو گئی`);
    expect(movedText(1, "Kick-off", PROJECTS, null)).toBe(`“${iso("Kick-off")}” ان باکس میں منتقل ہو گئی`);
    expect(movedText(1, null, PROJECTS, null)).toBe("ریکارڈنگ ان باکس میں منتقل ہو گئی");
    expect(movedText(3, null, PROJECTS, "c2")).toBe(`${iso(3)} ریکارڈنگز ${iso("Zed Logistics")} میں منتقل ہو گئیں`);
    expect(movedText(3, null, PROJECTS, null)).toBe(`${iso(3)} ریکارڈنگز ان باکس میں منتقل ہو گئیں`);
    expect(movedText(1, "Kick-off", PROJECTS, "gone")).toBe(`“${iso("Kick-off")}” پروجیکٹ میں منتقل ہو گئی`);
    expect(deletedText("Acme", {})).toBe(`${iso("Acme")} حذف ہو گیا`);
    expect(deletedText("Acme", { moved_sessions: 1, moved_projects: 2 })).toBe(`${iso("Acme")} حذف ہو گیا · ${iso(1)} ریکارڈنگ ان باکس میں منتقل ہو گئی · ${iso(2)} فولڈرز ایک سطح اوپر چلے گئے`);
  });
});

describe("the classic Projects page in an Urdu window keeps its English", () => {
  it("New project: the classic dialog and its notice", async () => {
    respond("client_create", { data: { id: "c9", name: "Nova", parent_id: null } });
    await urdu();
    await open({ v2: false });
    fireEvent.click(screen.getAllByRole("button", { name: "New project" })[0]);
    const dialog = screen.getByRole("dialog", { name: "New project" });
    expect(dialog).toHaveTextContent("A project keeps its calls, files, a brief and notes together, and Ask answers from all of them.");
    typeName(dialog, "Nova");
    fireEvent.click(within(dialog).getByRole("button", { name: "Add project" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Nova added", false, { kind: "project", id: "c9" }));
  });

  it("New folder inside and Rename from the classic menu, and their notices", async () => {
    respond("client_create", { data: { id: "c7", name: "Contracts", parent_id: "c1" } });
    respond("client_update", { data: { id: "c7", name: "Agreements", parent_id: "c1" } });
    await urdu();
    await open({ project: "c1", v2: false });
    const menu = (item: string) => {
      fireEvent.click(screen.getByRole("button", { name: "Project menu" }));
      fireEvent.click(screen.getByRole("menuitem", { name: item }));
    };
    menu("New folder inside");
    let dialog = screen.getByRole("dialog", { name: "New folder in Acme Holdings" });
    typeName(dialog, "Contracts");
    fireEvent.click(within(dialog).getByRole("button", { name: "Add folder" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Contracts added to Acme Holdings", false, anyTarget));
    await act(flushAsync);
    menu("Rename");
    dialog = screen.getByRole("dialog", { name: "Rename Contracts" });
    typeName(dialog, "Agreements");
    fireEvent.click(within(dialog).getByRole("button", { name: "Rename" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Renamed", false, anyTarget));
  });

  it("Move and Delete project from the classic menu stay English (D-263)", async () => {
    await urdu();
    await open({ project: "c3", v2: false });
    const menu = (item: string) => {
      fireEvent.click(screen.getByRole("button", { name: "Project menu" }));
      fireEvent.click(screen.getByRole("menuitem", { name: item }));
    };
    menu("Move…");
    let dialog = screen.getByRole("dialog");
    expect(title(dialog)).toBe("Move Invoices");
    expect(within(dialog).getByRole("radiogroup", { name: "Move to" })).toHaveTextContent("Top level");
    expect(within(dialog).getByRole("radio", { name: /Acme Holdings/ }).closest("label")).toHaveTextContent("It is here now");
    fireEvent.click(within(dialog).getByRole("button", { name: "Cancel" }));
    menu("Delete project…");
    dialog = screen.getByRole("dialog");
    expect(title(dialog)).toBe("Delete Invoices?");
    expect(dialog).toHaveTextContent("Its recording goes to the Inbox, so nothing is lost.");
    expect(within(dialog).getAllByRole("button").map((b) => b.textContent)).toEqual(["Cancel", "Delete project"]);
  });

  it("movedText and deletedText asked in English stay English in an Urdu window", async () => {
    await urdu();
    expect(movedText(1, "Call", PROJECTS, "c3", "en")).toBe("Moved “Call” to Acme Holdings › Invoices");
    expect(movedText(3, null, PROJECTS, null, "en")).toBe("Moved 3 recordings to the Inbox");
    expect(movedText(1, null, PROJECTS, null, "en")).toBe("Moved “the recording” to the Inbox");
    expect(deletedText("Acme", { moved_sessions: 1, moved_projects: 2 }, "en")).toBe("Deleted Acme · 1 recording moved to the Inbox · 2 folders moved up");
  });
});

describe("the new design's dialogs in English, unchanged", () => {
  it("New project and recordings dropped on the Inbox: the same title, button and notices as before", async () => {
    respond("client_create", { data: { id: "c9", name: "Nova", parent_id: null } });
    await open();
    fireEvent.click(screen.getByTestId("list-new"));
    let dialog = screen.getByRole("dialog", { name: "New project" });
    expect(dialog).toHaveTextContent("A project keeps its calls, files, a brief and notes together, and Ask answers from all of them.");
    typeName(dialog, "Nova");
    fireEvent.click(within(dialog).getByRole("button", { name: "Add project" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Nova added", false, { kind: "project", id: "c9" }));
    respond("sessions_bulk", 2);
    act(() => page?.onMoveRecordings(["s1", "s2"], null));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Moved 2 recordings to the Inbox", false, { kind: "library", folder: "inbox" }));
  });

  it("a project's menu, New folder in it and Rename", async () => {
    respond("client_create", { data: { id: "c7", name: "Contracts", parent_id: "c1" } });
    await open({ project: "c1" });
    expect(menuWords()).toEqual(["New folder inside", "Rename", "Move…", "Share link…", "Send to a teammate…", "Delete project…"]);
    pick("New folder inside");
    let dialog = screen.getByRole("dialog", { name: "New folder in Acme Holdings" });
    expect(within(dialog).getByRole("button", { name: "Add folder" })).toBeDisabled();
    typeName(dialog, "Contracts");
    fireEvent.click(within(dialog).getByRole("button", { name: "Add folder" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Contracts added to Acme Holdings", false, anyTarget));
    await act(flushAsync);
    respond("client_update", { data: { id: "c7", name: "Agreements", parent_id: "c1" } });
    pick("Rename");
    dialog = screen.getByRole("dialog", { name: "Rename Contracts" });
    typeName(dialog, "Agreements");
    fireEvent.click(within(dialog).getByRole("button", { name: "Rename" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Renamed", false, { kind: "project", id: "c7" }));
  });
});
