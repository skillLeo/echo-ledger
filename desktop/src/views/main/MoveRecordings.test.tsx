// Moving recordings (since v1.5): the Move dialog from a row's menu and from the selection bar, dragging rows onto a
// project or the Inbox in the sidebar, the recording page's Project picker, and "Filed by rule" on its page.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import MainApp from "./MainApp";
import { LibraryView } from "./LibraryView";
import { OrganisePanel } from "./LibraryOrganise";
import { movedText } from "./ProjectDialogs";
import type { ClientFolder, LibraryItem } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus, makeTranscript } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const PROJECTS: ClientFolder[] = [
  { id: "c1", name: "Acme", parent_id: null },
  { id: "c3", name: "Invoices", parent_id: "c1" },
  { id: "c2", name: "Zed", parent_id: null },
];
const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Kick-off", client: { id: "c1", name: "Acme" }, started_at: "2026-10-24T09:00:00Z" }),
  makeItem("b", { title: "Loose call", client: null, started_at: "2026-10-24T08:00:00Z" }),
  makeItem("c", { title: "Memo", client: null, started_at: "2026-10-23T08:00:00Z" }),
];

function backend() {
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("pending_list", []);
  respond("upload_list", []);
  respond("unsure_markers", null);
  respond("list_clients", { data: PROJECTS });
  respondWith("transcript_detail", ({ id }) => makeTranscript({ id: String(id) }));
  respondWith("session_detail", ({ id }) => ({ id, playback_url: null }));
}
const row = (title: string) => within(screen.getByTestId("library-list")).getByText(title).closest("button") as HTMLElement;
const rowMenu = (title: string) => {
  fireEvent.click(screen.getByRole("button", { name: `More for ${title}` }));
  fireEvent.click(screen.getByRole("menuitem", { name: "Move to…" }));
};

let under: Element | null = null;
beforeEach(() => {
  resetTauriMock();
  window.localStorage.clear();
  backend();
  under = null;
  Object.defineProperty(document, "elementFromPoint", { configurable: true, value: vi.fn(() => under) });
});
afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

describe("the Move dialog", () => {
  it("moves one recording from its row menu into a project, saying where", async () => {
    respond("session_organise", { data: ITEMS[1] });
    const notify = vi.fn();
    render(<LibraryView notify={notify} projects={PROJECTS} />);
    await within(screen.getByTestId("library-list")).findByText("Loose call");
    rowMenu("Loose call");
    const dialog = screen.getByRole("dialog", { name: "Move “Loose call”" });
    const picker = within(dialog).getByRole("radiogroup", { name: "Move to" });
    expect(within(picker).getAllByRole("radio").map((r) => r.closest("label")?.textContent)).toEqual(["InboxIt is here now", "Acme", "Invoices", "Zed"]);
    expect(within(picker).getByRole("radio", { name: /Inbox/ })).toBeDisabled();
    fireEvent.click(within(picker).getByRole("radio", { name: /Invoices/ }));
    fireEvent.click(within(dialog).getByRole("button", { name: "Move" }));
    await waitFor(() => expect(callsOf("session_organise")).toEqual([{ id: "b", patch: { client_id: "c3" } }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Moved “Loose call” to Acme › Invoices"));
    await waitFor(() => expect(screen.queryByRole("dialog")).not.toBeInTheDocument());
  });

  it("moves the selection with one bulk action, into the Inbox", async () => {
    respond("sessions_bulk", 2);
    const notify = vi.fn();
    render(<LibraryView notify={notify} projects={PROJECTS} />);
    await within(screen.getByTestId("library-list")).findByText("Kick-off");
    fireEvent.click(screen.getByRole("button", { name: "Select" }));
    fireEvent.click(row("Kick-off"));
    fireEvent.click(row("Memo"));
    fireEvent.click(within(screen.getByRole("toolbar", { name: "Selected recordings" })).getByRole("button", { name: "Move…" }));
    const dialog = screen.getByRole("dialog", { name: "Move 2 recordings" });
    fireEvent.click(within(dialog).getByRole("radio", { name: /Inbox/ }));
    fireEvent.click(within(dialog).getByRole("button", { name: "Move" }));
    await waitFor(() => expect(callsOf("sessions_bulk")).toEqual([{ ids: ["a", "c"], action: "move", clientId: null, label: null }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Moved 2 recordings to the Inbox"));
  });

  it("makes a new project and moves into it", async () => {
    respond("client_create", { data: { id: "c9", name: "Nova", parent_id: null } });
    respond("session_organise", { data: ITEMS[1] });
    const notify = vi.fn();
    render(<LibraryView notify={notify} projects={PROJECTS} />);
    await within(screen.getByTestId("library-list")).findByText("Loose call");
    rowMenu("Loose call");
    fireEvent.click(screen.getByRole("button", { name: "New project" }));
    fireEvent.change(screen.getByLabelText("New project name"), { target: { value: "Nova" } });
    fireEvent.click(screen.getByRole("button", { name: "Create and move" }));
    await waitFor(() => expect(callsOf("session_organise")).toEqual([{ id: "b", patch: { client_id: "c9" } }]));
    expect(callsOf("client_create")).toEqual([{ name: "Nova", color: null, avatar: null, parentId: null }]);
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Moved “Loose call” to Nova"));
  });

  it("keeps the dialog open and says why a move failed", async () => {
    fail("session_organise", "Server returned 404: Unknown client.");
    const notify = vi.fn();
    render(<LibraryView notify={notify} projects={PROJECTS} />);
    await within(screen.getByTestId("library-list")).findByText("Loose call");
    rowMenu("Loose call");
    fireEvent.click(screen.getByRole("radio", { name: /Zed/ }));
    fireEvent.click(screen.getByRole("button", { name: "Move" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Server returned 404: Unknown client.", true));
    expect(screen.getByRole("dialog", { name: "Move “Loose call”" })).toBeInTheDocument();
  });

  it("writes where they went", () => {
    expect(movedText(1, "Call", PROJECTS, "c3")).toBe("Moved “Call” to Acme › Invoices");
    expect(movedText(3, null, PROJECTS, null)).toBe("Moved 3 recordings to the Inbox");
  });
});

describe("dragging recordings onto the Library's column (v1.6; the sidebar's pinned projects take them too)", () => {
  async function renderApp() {
    respond("app_status", makeStatus());
    respond("get_settings", makeSettings());
    respond("mic_users", []);
    respond("library_views", { folders: { inbox: 2 } });
    render(<MainApp />);
    await screen.findByRole("list", { name: "Projects in the column" });
    await within(screen.getByTestId("library-list")).findByText("Loose call");
  }
  const drag = (from: HTMLElement, target: Element | null) => {
    fireEvent.pointerDown(from, { clientX: 300, clientY: 300, button: 0 });
    under = target;
    act(() => {
      window.dispatchEvent(new PointerEvent("pointermove", { clientX: 120, clientY: 200, buttons: 1 }));
    });
    act(() => {
      window.dispatchEvent(new PointerEvent("pointerup", { clientX: 120, clientY: 200 }));
    });
    // The click that ends the drag must not open the recording.
    fireEvent.click(from);
  };

  it("drops a recording on a project in the tree", async () => {
    respond("session_organise", { data: ITEMS[1] });
    await renderApp();
    const target = within(screen.getByRole("list", { name: "Projects in the column" })).getByRole("button", { name: "Zed" });
    drag(row("Loose call"), target);
    await waitFor(() => expect(callsOf("session_organise")).toEqual([{ id: "b", patch: { client_id: "c2" } }]));
    expect(await screen.findByRole("status")).toHaveTextContent("Moved “Loose call” to Zed");
    expect(document.querySelector(".detail h2")).toBeNull();
  });

  it("drops every selected recording on the Inbox", async () => {
    respond("sessions_bulk", 2);
    await renderApp();
    fireEvent.click(screen.getByRole("button", { name: "Select" }));
    fireEvent.click(row("Kick-off"));
    fireEvent.click(row("Memo"));
    const inbox = within(screen.getByRole("list", { name: "Smart folders" })).getByRole("button", { name: "Inbox" });
    drag(row("Kick-off"), inbox);
    await waitFor(() => expect(callsOf("sessions_bulk")).toEqual([{ ids: ["a", "c"], action: "move", clientId: null, label: null }]));
    expect(await screen.findByRole("status")).toHaveTextContent("Moved 2 recordings to the Inbox");
    expect(screen.queryByRole("toolbar", { name: "Selected recordings" })).not.toBeInTheDocument();
  });

  it("moves nothing when the row is dropped outside the sidebar", async () => {
    await renderApp();
    drag(row("Loose call"), document.querySelector(".detail"));
    await flushAsync();
    expect(callsOf("session_organise")).toHaveLength(0);
  });
});

describe("the recording page", () => {
  it("files the recording with the Project picker", async () => {
    respond("session_organise", { data: ITEMS[1] });
    const notify = vi.fn();
    render(<OrganisePanel item={ITEMS[1]} clients={PROJECTS} knownLabels={[]} notify={notify} onClientCreated={vi.fn()} />);
    const select = screen.getByRole("combobox", { name: "Project" }) as HTMLSelectElement;
    expect([...select.options].map((o) => o.text)).toEqual(["Inbox", "Acme", "Acme › Invoices", "Zed", "New project…"]);
    fireEvent.change(select, { target: { value: "c3" } });
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Moved to Acme › Invoices"));
  });

  it("says which rule filed the recording", async () => {
    respond("client_rules", { data: [{ id: 7, kind: "app", value: "zoom" }, { id: 8, kind: "title", value: "kick" }] });
    const item = { ...ITEMS[0], filed_by: "rule" as const };
    render(<OrganisePanel item={item} clients={PROJECTS} knownLabels={[]} notify={vi.fn()} onClientCreated={vi.fn()} />);
    expect(await screen.findByText("Filed by rule: title contains kick")).toBeInTheDocument();
    expect(callsOf("client_rules")).toEqual([{ id: "c1" }]);
  });

  it("says a contact filed it, and nothing for a recording moved by hand", async () => {
    const { rerender } = render(<OrganisePanel item={{ ...ITEMS[0], filed_by: "contact" }} clients={PROJECTS} knownLabels={[]} notify={vi.fn()} onClientCreated={vi.fn()} />);
    expect(screen.getByTestId("filed-by")).toHaveTextContent("Filed by contact: the caller is one of Acme's contacts");
    rerender(<OrganisePanel item={{ ...ITEMS[0], filed_by: "user" }} clients={PROJECTS} knownLabels={[]} notify={vi.fn()} onClientCreated={vi.fn()} />);
    expect(screen.queryByTestId("filed-by")).not.toBeInTheDocument();
    expect(callsOf("client_rules")).toHaveLength(0);
  });

  it("says a rule filed it even when the rule is gone", async () => {
    respond("client_rules", { data: [] });
    render(<OrganisePanel item={{ ...ITEMS[0], filed_by: "rule" }} clients={PROJECTS} knownLabels={[]} notify={vi.fn()} onClientCreated={vi.fn()} />);
    await flushAsync();
    expect(screen.getByTestId("filed-by")).toHaveTextContent("Filed by a rule");
  });
});
