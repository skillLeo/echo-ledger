// The Library's "Waiting for your decision" and "Uploads that need you" sections (contract 5.3 item 1), driven
// through the Library screen with the same commands and events the Rust core uses.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { LibraryView } from "./LibraryView";
import type { PendingFocus } from "./LibraryQueue";
import type { PendingItem, UploadItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makePendingItem, makeUploadItem } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 9, 24, 12, 0, 0);
const local = (hour: number, minute = 0) => new Date(2026, 9, 24, hour, minute).toISOString();
const CLIENTS = { data: [{ id: "c1", name: "Acme Holdings", keyterm_count: 4 }, { id: "c2", name: "Northwind", keyterm_count: 0 }] };
const DISCARD = "Discard this recording? It can't be undone.";

const scrollIntoView = vi.fn();

function mockBackend(pending: PendingItem[] = [], uploads: UploadItem[] = []) {
  respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("transcript_detail", { id: "t", kind: "final", provider: "p", status: "completed", language: null, text: null, segments: [] });
  respond("session_detail", {});
  respond("pending_list", pending);
  respond("upload_list", uploads);
  respond("list_clients", CLIENTS);
  respond("pending_keep", undefined);
  respond("pending_discard", undefined);
  respond("upload_retry", undefined);
  respond("upload_discard", undefined);
}

const reviewButton = () => screen.getByRole("button", { name: /^(Review|Hide)$/ });

/**
 * Renders the Library and, unless `open: false`, opens the waiting list with Review (a focus request may already
 * have opened it), so the tests below can reach each row.
 */
async function renderLibrary(pending: PendingItem[] = [], uploads: UploadItem[] = [], focus: PendingFocus | null = null, { open = true } = {}) {
  mockBackend(pending, uploads);
  const notify = vi.fn();
  const view = render(<LibraryView notify={notify} focus={focus} />);
  await flushAsync();
  if (open && pending.length > 0 && reviewButton().getAttribute("aria-expanded") === "false") fireEvent.click(reviewButton());
  return { notify, ...view };
}

const row = (id: string) => screen.getByTestId(`pending-${id}`);
const uploadRow = (id: string) => screen.getByTestId(`upload-${id}`);

beforeEach(() => {
  resetTauriMock();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
  scrollIntoView.mockClear();
  Object.defineProperty(Element.prototype, "scrollIntoView", { configurable: true, value: scrollIntoView });
});

afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("Waiting for your decision", () => {
  it("is absent when nothing waits", async () => {
    await renderLibrary();
    expect(screen.queryByRole("heading", { name: "Waiting for your decision" })).not.toBeInTheDocument();
    expect(callsOf("pending_list")).toHaveLength(1);
    expect(callsOf("upload_list")).toHaveLength(1);
  });

  it("sits above the recordings in the list, with a count", async () => {
    await renderLibrary([makePendingItem("p1"), makePendingItem("p2")]);
    const list = screen.getByTestId("library-list");
    const heading = within(list).getByRole("heading", { name: "Waiting for your decision" });
    expect(heading.closest(".queue-head")).toHaveTextContent("2");
    const recording = await within(list).findByText("Zoom with Acme");
    expect(heading.compareDocumentPosition(recording) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it("shows source, title, start time, duration, client and the first caption line", async () => {
    await renderLibrary([
      makePendingItem("p1", { startedAt: local(9, 30), durationMs: 750_000, clientName: "Acme Holdings", clientId: "c1" }),
      makePendingItem("p2", { title: "Budget review", sourceApp: "google_meet", startedAt: local(8), durationMs: 65_000, firstLine: null }),
      makePendingItem("p3", { mode: "mic_note", sourceApp: "voice_note", startedAt: local(7), durationMs: 0 }),
    ]);
    expect(within(row("p1")).getByText("Zoom call")).toBeInTheDocument();
    expect(within(row("p1")).getByText("Zoom · Today · 9:30 AM · 12:30 · Acme Holdings")).toBeInTheDocument();
    expect(within(row("p1")).getByText("Thanks for joining, shall we start with the invoice?")).toBeInTheDocument();
    expect(within(row("p2")).getByText("Budget review")).toBeInTheDocument();
    expect(within(row("p2")).getByText("Google Meet · Today · 8:00 AM · 1:05")).toBeInTheDocument();
    expect(row("p2").querySelector(".item-preview")).toBeNull();
    expect(within(row("p3")).getByText("Voice note")).toBeInTheDocument();
    expect(within(row("p3")).getByText("Voice note · Today · 7:00 AM · 0:00")).toBeInTheDocument();
    expect(screen.getByRole("group", { name: "Budget review" })).toBe(row("p2"));
  });

  it("keeps a recording: calls pending_keep with its id, removes the row and confirms", async () => {
    const { notify } = await renderLibrary([makePendingItem("p1"), makePendingItem("p2")]);
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Keep" }));
    await waitFor(() => expect(callsOf("pending_keep")).toEqual([{ id: "p1" }]));
    await waitFor(() => expect(screen.queryByTestId("pending-p1")).not.toBeInTheDocument());
    expect(row("p2")).toBeInTheDocument();
    expect(notify).toHaveBeenCalledWith("Kept");
  });

  it("reports a recording that is no longer waiting and re-reads the list", async () => {
    const { notify } = await renderLibrary([makePendingItem("p1")]);
    fail("pending_keep", "This recording is no longer waiting.");
    respond("pending_list", []);
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Keep" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("This recording is no longer waiting.", true));
    await waitFor(() => expect(screen.queryByTestId("pending-p1")).not.toBeInTheDocument());
    expect(callsOf("pending_list")).toHaveLength(2);
  });

  it("asks before discarding and discards only when confirmed", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValueOnce(false).mockReturnValueOnce(true);
    const { notify } = await renderLibrary([makePendingItem("p1")]);
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Discard" }));
    expect(confirm).toHaveBeenCalledWith(DISCARD);
    await flushAsync();
    expect(callsOf("pending_discard")).toHaveLength(0);
    expect(row("p1")).toBeInTheDocument();
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Discard" }));
    await waitFor(() => expect(callsOf("pending_discard")).toEqual([{ id: "p1" }]));
    await waitFor(() => expect(screen.queryByRole("heading", { name: "Waiting for your decision" })).not.toBeInTheDocument());
    expect(notify).toHaveBeenCalledWith("Discarded");
    expect(callsOf("pending_keep")).toHaveLength(0);
  });

  it("disables the row's buttons while a command runs", async () => {
    let release: (v: unknown) => void = () => undefined;
    await renderLibrary([makePendingItem("p1")]);
    respondWith("pending_keep", () => new Promise((resolve) => (release = resolve)));
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Keep" }));
    await waitFor(() => expect(within(row("p1")).getByRole("button", { name: "Discard" })).toBeDisabled());
    await act(async () => release(undefined));
    await waitFor(() => expect(screen.queryByTestId("pending-p1")).not.toBeInTheDocument());
  });

  it("follows pending:changed from the core and stops listening on unmount", async () => {
    const { unmount } = await renderLibrary([makePendingItem("p1")]);
    await emitEvent("pending:changed", [makePendingItem("p9", { title: "New call" })]);
    expect(screen.queryByTestId("pending-p1")).not.toBeInTheDocument();
    expect(within(row("p9")).getByText("New call")).toBeInTheDocument();
    await emitEvent("pending:changed", []);
    expect(screen.queryByRole("heading", { name: "Waiting for your decision" })).not.toBeInTheDocument();
    expect(listenerCount("pending:changed")).toBe(1);
    expect(listenerCount("uploads:changed")).toBe(1);
    unmount();
    await waitFor(() => expect(listenerCount("pending:changed")).toBe(0));
    expect(listenerCount("uploads:changed")).toBe(0);
  });

  it("stays usable when the core cannot list pending items", async () => {
    mockBackend();
    fail("pending_list", "state not ready");
    fail("upload_list", "state not ready");
    render(<LibraryView notify={vi.fn()} />);
    expect(await screen.findByText("Zoom with Acme")).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Waiting for your decision" })).not.toBeInTheDocument();
  });
});

describe("Waiting for your decision: summary card", () => {
  const items = () => [
    makePendingItem("p1", { startedAt: local(9, 30), durationMs: 36_000 }),
    makePendingItem("p2", { startedAt: local(8), durationMs: 102_000 }),
  ];

  it("shows one summary card, collapsed, with no rows", async () => {
    await renderLibrary(items(), [], null, { open: false });
    expect(screen.getByTestId("waiting-summary")).toHaveTextContent("2 calls waiting · oldest Oct 24 · 2:18 total");
    expect(screen.getByRole("heading", { name: "Waiting for your decision" }).closest(".queue-head")).toHaveTextContent("2");
    expect(screen.getByRole("button", { name: "Review" })).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByTestId("pending-p1")).not.toBeInTheDocument();
    expect(screen.queryByTestId("pending-p2")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Keep all" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Discard all" })).not.toBeInTheDocument();
  });

  it("opens the list with Review and closes it with Hide", async () => {
    await renderLibrary(items(), [], null, { open: false });
    fireEvent.click(screen.getByRole("button", { name: "Review" }));
    const hide = screen.getByRole("button", { name: "Hide" });
    expect(hide).toHaveAttribute("aria-expanded", "true");
    expect(row("p1")).toBeInTheDocument();
    expect(row("p2")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Keep all" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Discard all" })).toBeInTheDocument();
    fireEvent.click(hide);
    expect(screen.getByRole("button", { name: "Review" })).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByTestId("pending-p1")).not.toBeInTheDocument();
    expect(screen.getByTestId("waiting-summary")).toBeInTheDocument();
  });

  it("follows the list in the summary and says recordings once a voice note waits", async () => {
    await renderLibrary(items(), [], null, { open: false });
    await emitEvent("pending:changed", [...items(), makePendingItem("p3", { mode: "mic_note", sourceApp: "voice_note", startedAt: local(7), durationMs: 0 })]);
    expect(screen.getByTestId("waiting-summary")).toHaveTextContent("3 recordings waiting · oldest Oct 24 · 2:18 total");
  });

  it("keeps every recording with Keep all", async () => {
    const { notify } = await renderLibrary(items());
    fireEvent.click(screen.getByRole("button", { name: "Keep all" }));
    await waitFor(() => expect(callsOf("pending_keep")).toEqual([{ id: "p1" }, { id: "p2" }]));
    await waitFor(() => expect(screen.queryByRole("heading", { name: "Waiting for your decision" })).not.toBeInTheDocument());
    expect(notify).toHaveBeenCalledWith("Kept 2 recordings");
    expect(callsOf("pending_discard")).toHaveLength(0);
  });

  it("reports a recording Keep all could not keep and still keeps the rest", async () => {
    const { notify } = await renderLibrary(items());
    respondWith("pending_keep", ({ id }) => (id === "p1" ? Promise.reject("This recording is no longer waiting.") : undefined));
    respond("pending_list", [makePendingItem("p2")]);
    fireEvent.click(screen.getByRole("button", { name: "Keep all" }));
    await waitFor(() => expect(callsOf("pending_keep")).toEqual([{ id: "p1" }, { id: "p2" }]));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Kept 1 recording"));
    expect(notify).toHaveBeenCalledWith("This recording is no longer waiting.", true);
  });

  it("asks once before Discard all and discards every recording", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(true);
    const { notify } = await renderLibrary(items());
    fireEvent.click(screen.getByRole("button", { name: "Discard all" }));
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(confirm).toHaveBeenCalledWith("Discard all 2 recordings? It can't be undone.");
    await waitFor(() => expect(callsOf("pending_discard")).toEqual([{ id: "p1" }, { id: "p2" }]));
    await waitFor(() => expect(screen.queryByRole("heading", { name: "Waiting for your decision" })).not.toBeInTheDocument());
    expect(confirm).toHaveBeenCalledTimes(1);
    expect(notify).toHaveBeenCalledWith("Discarded 2 recordings");
    expect(callsOf("pending_keep")).toHaveLength(0);
  });

  it("discards nothing when Discard all is cancelled", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValue(false);
    const { notify } = await renderLibrary(items());
    fireEvent.click(screen.getByRole("button", { name: "Discard all" }));
    expect(confirm).toHaveBeenCalledTimes(1);
    await flushAsync();
    expect(callsOf("pending_discard")).toHaveLength(0);
    expect(row("p1")).toBeInTheDocument();
    expect(row("p2")).toBeInTheDocument();
    expect(notify).not.toHaveBeenCalled();
  });

  it("disables Keep all, Discard all and the rows while Keep all runs", async () => {
    let release: (v: unknown) => void = () => undefined;
    await renderLibrary(items());
    respondWith("pending_keep", () => new Promise((resolve) => (release = resolve)));
    fireEvent.click(screen.getByRole("button", { name: "Keep all" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Discard all" })).toBeDisabled());
    expect(screen.getByRole("button", { name: "Keep all" })).toBeDisabled();
    expect(within(row("p2")).getByRole("button", { name: "Keep" })).toBeDisabled();
    await act(async () => release(undefined));
    await act(async () => release(undefined));
    await waitFor(() => expect(screen.queryByRole("heading", { name: "Waiting for your decision" })).not.toBeInTheDocument());
  });

  it("starts collapsed again once the list empties and a new recording arrives", async () => {
    await renderLibrary(items());
    await emitEvent("pending:changed", []);
    await emitEvent("pending:changed", [makePendingItem("p9")]);
    expect(screen.getByRole("button", { name: "Review" })).toHaveAttribute("aria-expanded", "false");
    expect(screen.queryByTestId("pending-p9")).not.toBeInTheDocument();
  });
});

describe("Waiting for your decision: rename", () => {
  const titleBox = (id: string) => within(row(id)).getByRole("textbox", { name: "Title" });

  it("opens the title for editing with the current title, focused", async () => {
    await renderLibrary([makePendingItem("p1", { title: "Weekly sync" })]);
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Rename" }));
    expect(titleBox("p1")).toHaveValue("Weekly sync");
    expect(titleBox("p1")).toHaveFocus();
    expect(titleBox("p1")).toHaveAttribute("maxLength", "200");
  });

  it("saves on Enter with pending_rename and shows the title the core returns", async () => {
    await renderLibrary([makePendingItem("p1")]);
    respondWith("pending_rename", ({ id, title }) => makePendingItem(id as string, { title: title as string }));
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Rename" }));
    expect(titleBox("p1")).toHaveValue("");
    expect(titleBox("p1")).toHaveAttribute("placeholder", "Zoom call");
    const box = titleBox("p1");
    fireEvent.change(box, { target: { value: "  Invoice call with Acme  " } });
    fireEvent.keyDown(box, { key: "Enter" });
    fireEvent.blur(box); // the field leaving the page must not save a second time
    await waitFor(() => expect(within(row("p1")).getByText("Invoice call with Acme")).toBeInTheDocument());
    expect(callsOf("pending_rename")).toEqual([{ id: "p1", title: "Invoice call with Acme" }]);
    expect(within(row("p1")).queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("saves when the field loses focus", async () => {
    await renderLibrary([makePendingItem("p1", { title: "Old" })]);
    respondWith("pending_rename", ({ id, title }) => makePendingItem(id as string, { title: title as string }));
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Rename" }));
    fireEvent.change(titleBox("p1"), { target: { value: "New" } });
    fireEvent.blur(titleBox("p1"));
    await waitFor(() => expect(callsOf("pending_rename")).toEqual([{ id: "p1", title: "New" }]));
  });

  it("clears a title by saving an empty one (the core stores none)", async () => {
    await renderLibrary([makePendingItem("p1", { title: "Old" })]);
    respondWith("pending_rename", ({ id }) => makePendingItem(id as string, { title: null }));
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Rename" }));
    fireEvent.change(titleBox("p1"), { target: { value: "   " } });
    fireEvent.keyDown(titleBox("p1"), { key: "Enter" });
    await waitFor(() => expect(within(row("p1")).getByText("Zoom call")).toBeInTheDocument());
    expect(callsOf("pending_rename")).toEqual([{ id: "p1", title: "" }]);
  });

  it("cancels with Escape and sends nothing for an unchanged title", async () => {
    await renderLibrary([makePendingItem("p1", { title: "Same" })]);
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Rename" }));
    fireEvent.change(titleBox("p1"), { target: { value: "Changed" } });
    fireEvent.keyDown(titleBox("p1"), { key: "Escape" });
    expect(within(row("p1")).getByText("Same")).toBeInTheDocument();
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Rename" }));
    fireEvent.keyDown(titleBox("p1"), { key: "Enter" });
    await flushAsync();
    expect(callsOf("pending_rename")).toHaveLength(0);
  });

  it("reports a title the core refuses and keeps the old one", async () => {
    const { notify } = await renderLibrary([makePendingItem("p1", { title: "Old" })]);
    fail("pending_rename", "Use 200 characters or fewer.");
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Rename" }));
    fireEvent.change(titleBox("p1"), { target: { value: "x".repeat(200) } });
    fireEvent.keyDown(titleBox("p1"), { key: "Enter" });
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Use 200 characters or fewer.", true));
    expect(within(row("p1")).getByText("Old")).toBeInTheDocument();
  });
});

describe("Waiting for your decision: assign client", () => {
  const clientSelect = (id: string) => within(row(id)).getByRole("combobox", { name: "Client" });

  it("loads the clients once and offers No client first", async () => {
    await renderLibrary([makePendingItem("p1"), makePendingItem("p2")]);
    expect(callsOf("list_clients")).toHaveLength(0);
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Assign client" }));
    await waitFor(() => expect([...(clientSelect("p1") as HTMLSelectElement).options].map((o) => o.text)).toEqual(["No client", "Acme Holdings", "Northwind"]));
    expect(clientSelect("p1")).toHaveValue("");
    expect(within(row("p1")).getByRole("button", { name: "Assign client" })).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Assign client" }));
    expect(within(row("p1")).queryByRole("combobox", { name: "Client" })).not.toBeInTheDocument();
    fireEvent.click(within(row("p2")).getByRole("button", { name: "Assign client" }));
    expect(clientSelect("p2")).toBeInTheDocument();
    expect(callsOf("list_clients")).toHaveLength(1);
  });

  it("assigns the chosen client and shows its name", async () => {
    await renderLibrary([makePendingItem("p1")]);
    respondWith("pending_assign_client", ({ id, clientId }) => makePendingItem(id as string, { clientId: clientId as string, clientName: "Northwind" }));
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Assign client" }));
    await waitFor(() => expect(within(clientSelect("p1")).getByRole("option", { name: "Northwind" })).toBeInTheDocument());
    fireEvent.change(clientSelect("p1"), { target: { value: "c2" } });
    await waitFor(() => expect(callsOf("pending_assign_client")).toEqual([{ id: "p1", clientId: "c2" }]));
    await waitFor(() => expect(within(row("p1")).getByText(/· Northwind$/)).toBeInTheDocument());
    expect(within(row("p1")).queryByRole("combobox", { name: "Client" })).not.toBeInTheDocument();
  });

  it("the language chip sets the language Keep uses and offers to remember it for the client", async () => {
    respond("list_clients", { data: [{ id: "c1", name: "Acme Holdings", language: null }] });
    respond("client_update", { data: { id: "c1", name: "Acme Holdings", language: "ur" } });
    await renderLibrary([makePendingItem("p1", { clientId: "c1", clientName: "Acme Holdings" })]);
    respondWith("pending_set_language", ({ id, language }) => makePendingItem(id as string, { clientId: "c1", clientName: "Acme Holdings", language: language as "ur" }));
    const select = within(row("p1")).getByRole("combobox", { name: "Language" });
    expect([...(select as HTMLSelectElement).options].map((o) => o.text)).toEqual(["Auto (English / Urdu / Arabic)", "English only", "Urdu (with English words)", "Arabic (with English words)", "French", "German", "Spanish", "Italian", "Portuguese", "Dutch", "Turkish", "Russian"]);
    fireEvent.change(select, { target: { value: "ur" } });
    await waitFor(() => expect(callsOf("pending_set_language")).toEqual([{ id: "p1", language: "ur" }]));
    await waitFor(() => expect(within(row("p1")).getByText("Use Urdu for Acme Holdings from now on?")).toBeInTheDocument());
    expect(within(row("p1")).getByRole("combobox", { name: "Language" })).toHaveValue("ur");
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Yes" }));
    await waitFor(() => expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { language: "ur" } }]));
  });

  it("sends an explicit null for No client", async () => {
    await renderLibrary([makePendingItem("p1", { clientId: "c1", clientName: "Acme Holdings" })]);
    respondWith("pending_assign_client", ({ id }) => makePendingItem(id as string));
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Assign client" }));
    await waitFor(() => expect(clientSelect("p1")).toHaveValue("c1"));
    fireEvent.change(clientSelect("p1"), { target: { value: "" } });
    await waitFor(() => expect(callsOf("pending_assign_client")).toHaveLength(1));
    expect(JSON.stringify(callsOf("pending_assign_client")[0])).toBe('{"id":"p1","clientId":null}');
  });

  it("keeps a client the server no longer lists selectable by its cached name", async () => {
    await renderLibrary([makePendingItem("p1", { clientId: "gone", clientName: "Old client" })]);
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Assign client" }));
    await waitFor(() => expect(within(clientSelect("p1")).getByRole("option", { name: "Acme Holdings" })).toBeInTheDocument());
    expect(clientSelect("p1")).toHaveValue("gone");
    expect(within(clientSelect("p1")).getByRole("option", { name: "Old client" })).toBeInTheDocument();
  });

  it("reports when the client list cannot be loaded and still offers No client", async () => {
    const { notify } = await renderLibrary([makePendingItem("p1")]);
    fail("list_clients", "Network error: offline");
    fireEvent.click(within(row("p1")).getByRole("button", { name: "Assign client" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("Network error: offline", true));
    expect([...(clientSelect("p1") as HTMLSelectElement).options].map((o) => o.text)).toEqual(["No client"]);
  });
});

describe("Waiting for your decision: opened from the review card or the tray", () => {
  it("highlights the requested row, scrolls to it and opens its title when asked", async () => {
    await renderLibrary([makePendingItem("p1"), makePendingItem("p2")], [], { id: "p2", rename: true, seq: 1 });
    await waitFor(() => expect(row("p2")).toHaveClass("highlight"));
    expect(row("p1")).not.toHaveClass("highlight");
    expect(scrollIntoView).toHaveBeenCalledTimes(1);
    expect(scrollIntoView.mock.contexts[0]).toBe(row("p2"));
    expect(within(row("p2")).getByRole("textbox", { name: "Title" })).toHaveFocus();
  });

  it("tells the app once the request is handled", async () => {
    mockBackend([makePendingItem("p1")]);
    const handled = vi.fn();
    render(<LibraryView notify={vi.fn()} focus={{ id: "p1", rename: false, seq: 1 }} onFocusHandled={handled} />);
    await flushAsync();
    await waitFor(() => expect(handled).toHaveBeenCalledTimes(1));
    await emitEvent("pending:changed", [makePendingItem("p1"), makePendingItem("p2")]);
    expect(handled).toHaveBeenCalledTimes(1);
  });

  it("only highlights when rename is not asked for", async () => {
    await renderLibrary([makePendingItem("p1")], [], { id: "p1", rename: false, seq: 1 });
    await waitFor(() => expect(row("p1")).toHaveClass("highlight"));
    expect(within(row("p1")).queryByRole("textbox")).not.toBeInTheDocument();
  });

  it("waits for the list when the request arrives first, and applies each request once", async () => {
    let release: (v: unknown) => void = () => undefined;
    mockBackend();
    respondWith("pending_list", () => new Promise((resolve) => (release = resolve)));
    const view = render(<LibraryView notify={vi.fn()} focus={{ id: "p1", rename: false, seq: 1 }} />);
    await flushAsync();
    await act(async () => release([makePendingItem("p1")]));
    await waitFor(() => expect(row("p1")).toHaveClass("highlight"));
    await emitEvent("pending:changed", [makePendingItem("p1", { title: "Renamed" })]);
    expect(scrollIntoView).toHaveBeenCalledTimes(1);
    view.rerender(<LibraryView notify={vi.fn()} focus={{ id: "p1", rename: false, seq: 2 }} />);
    await flushAsync();
    expect(scrollIntoView).toHaveBeenCalledTimes(2);
  });

  it("asks the core once more when the requested recording is newer than its list", async () => {
    mockBackend([]);
    let calls = 0;
    respondWith("pending_list", () => (++calls === 1 ? [] : [makePendingItem("p5")]));
    render(<LibraryView notify={vi.fn()} focus={{ id: "p5", rename: false, seq: 1 }} />);
    await flushAsync();
    await waitFor(() => expect(row("p5")).toHaveClass("highlight"));
    expect(callsOf("pending_list")).toHaveLength(2);
  });

  it("drops the highlight after a few seconds", async () => {
    vi.useFakeTimers({ toFake: ["Date", "setTimeout", "clearTimeout"], now: NOW });
    await renderLibrary([makePendingItem("p1")], [], { id: "p1", rename: false, seq: 1 });
    await flushAsync();
    expect(row("p1")).toHaveClass("highlight");
    await act(async () => {
      vi.advanceTimersByTime(4_000);
    });
    expect(row("p1")).not.toHaveClass("highlight");
  });

  it("ignores a request for a recording that is not waiting", async () => {
    await renderLibrary([makePendingItem("p1")], [], { id: "decided", rename: true, seq: 1 });
    expect(row("p1")).not.toHaveClass("highlight");
    expect(scrollIntoView).not.toHaveBeenCalled();
  });
});

describe("Uploads that need you", () => {
  const failed = (id: string, overrides: Partial<UploadItem> = {}) =>
    makeUploadItem(id, { state: "failed", lastError: "The server could not verify this upload.", attempts: 3, startedAt: local(10), ...overrides });

  it("lists failed uploads with their last error, and nothing for queued or uploading ones", async () => {
    await renderLibrary([], [failed("u1"), makeUploadItem("u2", { state: "queued" }), makeUploadItem("u3", { state: "uploading" })]);
    const heading = screen.getByRole("heading", { name: "Uploads that need you" });
    expect(heading.closest(".queue-head")).toHaveTextContent("1");
    expect(within(uploadRow("u1")).getByText("Zoom call")).toBeInTheDocument();
    expect(within(uploadRow("u1")).getByText("Zoom · Today · 10:00 AM · 12:30")).toBeInTheDocument();
    expect(within(uploadRow("u1")).getByText("The server could not verify this upload.")).toHaveClass("error-text");
    expect(screen.queryByTestId("upload-u2")).not.toBeInTheDocument();
    expect(screen.queryByTestId("upload-u3")).not.toBeInTheDocument();
  });

  it("retries a failed upload and takes it off the list", async () => {
    await renderLibrary([], [failed("u1", { title: "Board call" })]);
    fireEvent.click(within(uploadRow("u1")).getByRole("button", { name: "Retry" }));
    await waitFor(() => expect(callsOf("upload_retry")).toEqual([{ id: "u1" }]));
    await waitFor(() => expect(screen.queryByRole("heading", { name: "Uploads that need you" })).not.toBeInTheDocument());
  });

  it("reports an upload that left the queue and re-reads it", async () => {
    const { notify } = await renderLibrary([], [failed("u1")]);
    fail("upload_retry", "This upload is no longer in the queue.");
    respond("upload_list", []);
    fireEvent.click(within(uploadRow("u1")).getByRole("button", { name: "Retry" }));
    await waitFor(() => expect(notify).toHaveBeenCalledWith("This upload is no longer in the queue.", true));
    await waitFor(() => expect(screen.queryByTestId("upload-u1")).not.toBeInTheDocument());
    expect(callsOf("upload_list")).toHaveLength(2);
  });

  it("asks before discarding a failed upload", async () => {
    const confirm = vi.spyOn(window, "confirm").mockReturnValueOnce(false).mockReturnValueOnce(true);
    const { notify } = await renderLibrary([], [failed("u1")]);
    fireEvent.click(within(uploadRow("u1")).getByRole("button", { name: "Discard" }));
    expect(confirm).toHaveBeenCalledWith(DISCARD);
    await flushAsync();
    expect(callsOf("upload_discard")).toHaveLength(0);
    fireEvent.click(within(uploadRow("u1")).getByRole("button", { name: "Discard" }));
    await waitFor(() => expect(callsOf("upload_discard")).toEqual([{ id: "u1" }]));
    await waitFor(() => expect(screen.queryByTestId("upload-u1")).not.toBeInTheDocument());
    expect(notify).toHaveBeenCalledWith("Discarded");
  });

  it("shows an upload the server is still checking, without actions", async () => {
    await renderLibrary([], [makeUploadItem("u1", { state: "verifying", title: "Long call" })]);
    expect(within(uploadRow("u1")).getByText("Checking upload…")).toBeInTheDocument();
    expect(within(uploadRow("u1")).getByText("Long call")).toBeInTheDocument();
    expect(within(uploadRow("u1")).queryByRole("button")).not.toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Uploads that need you" })).not.toBeInTheDocument();
  });

  it("follows uploads:changed from the core", async () => {
    await renderLibrary([], []);
    await emitEvent("uploads:changed", [failed("u7", { lastError: "Server returned 413: too large" })]);
    expect(within(uploadRow("u7")).getByText("Server returned 413: too large")).toBeInTheDocument();
    await emitEvent("uploads:changed", [failed("u7", { state: "verifying" })]);
    expect(within(uploadRow("u7")).getByText("Checking upload…")).toBeInTheDocument();
  });
});
