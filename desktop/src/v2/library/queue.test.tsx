// The top of the v2 Library list (QueueV2): recordings waiting for Keep / Discard with Review, Keep all, Discard all,
// Rename, Choose project, Language and Remember the language; Importing with Cancel; Uploads that need you with Retry /
// Discard; Waiting for another account with Discard / Discard all N; Checking upload. The same core commands as classic.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { PendingItem, UploadItem } from "../../lib/ipc";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makePendingItem, makeStatus, makeUploadItem } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 9, 24, 12, 0);
const CLIENTS = { data: [{ id: "c1", name: "Acme Holdings", language: null }, { id: "c2", name: "Northwind", language: null }] };
const DISCARD = "Discard this recording? It can't be undone.";

async function show(pending: PendingItem[] = [], uploads: UploadItem[] = []) {
  respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("bin_list", { data: [] });
  respond("pending_list", pending);
  respond("upload_list", uploads);
  respond("list_clients", CLIENTS);
  respond("pending_keep", undefined);
  respond("pending_discard", undefined);
  respond("upload_retry", undefined);
  respond("upload_discard", undefined);
  render(<LibraryV2 />);
  await act(flushAsync);
}

const review = () => screen.getByRole("button", { name: /^(Review|Hide)$/ });
const waiting = () => screen.getByRole("list", { name: "Waiting recordings" });
/** One row of a queue list, by the words of its title. */
const rowOf = (title: string) => screen.getByText(title).closest("li") as HTMLElement;
const pick = async (row: HTMLElement, path: string[]) => {
  fireEvent.click(within(row).getByRole("button", { name: /^More for / }));
  for (const [i, name] of path.entries()) {
    const item = screen.getByRole(i === path.length - 1 && path.length > 1 ? "menuitemradio" : "menuitem", { name });
    if (i < path.length - 1) fireEvent.keyDown(item, { key: "ArrowRight" });
    fireEvent.click(item);
  }
  await act(flushAsync);
};

beforeEach(() => {
  resetTauriMock();
  window.localStorage.clear();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
});

describe("waiting recordings at the top of the v2 Library", () => {
  it("is absent when nothing waits", async () => {
    await show();
    expect(screen.queryByTestId("library-queue")).toBeNull();
  });

  it("says how many wait; Review opens the list, Hide closes it", async () => {
    await show([makePendingItem("p1"), makePendingItem("p2", { title: "Board call" })]);
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("2 calls waiting");
    expect(review()).toHaveTextContent("Review");
    expect(review()).toHaveAttribute("aria-expanded", "false");
    fireEvent.click(review());
    expect(review()).toHaveTextContent("Hide");
    expect(within(waiting()).getAllByRole("listitem")).toHaveLength(2);
    expect(rowOf("Board call")).toHaveTextContent(/Zoom · .* · 12:30 · Thanks for joining/);
    fireEvent.click(review());
    expect(screen.queryByRole("list", { name: "Waiting recordings" })).toBeNull();
  });

  it("Keep keeps one with pending_keep; Discard asks first", async () => {
    const confirm = answerConfirm().mockReturnValue(true);
    await show([makePendingItem("p1", { title: "Keep me" }), makePendingItem("p2", { title: "Drop me" })]);
    fireEvent.click(review());
    fireEvent.click(within(rowOf("Keep me")).getByRole("button", { name: "Keep" }));
    await act(flushAsync);
    expect(callsOf("pending_keep")).toEqual([{ id: "p1" }]);
    expect(screen.queryByText("Keep me")).toBeNull();
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Kept");
    fireEvent.click(within(rowOf("Drop me")).getByRole("button", { name: "Discard" }));
    await act(flushAsync);
    expect(confirm).toHaveBeenCalledWith(DISCARD);
    expect(callsOf("pending_discard")).toEqual([{ id: "p2" }]);
    expect(screen.queryByTestId("library-queue")).toBeNull();
  });

  it("Keep all keeps every one; Discard all asks once and discards nothing when cancelled", async () => {
    const confirm = answerConfirm().mockReturnValue(false);
    await show([makePendingItem("p1"), makePendingItem("p2")]);
    fireEvent.click(review());
    fireEvent.click(screen.getByRole("button", { name: "Discard all" }));
    await act(flushAsync);
    expect(confirm).toHaveBeenCalledWith("Discard all 2 recordings? It can't be undone.");
    expect(callsOf("pending_discard")).toEqual([]);
    fireEvent.click(screen.getByRole("button", { name: "Keep all" }));
    await act(flushAsync);
    expect(callsOf("pending_keep")).toEqual([{ id: "p1" }, { id: "p2" }]);
    expect(screen.queryByTestId("library-queue")).toBeNull();
  });

  it("Rename… saves the new title with pending_rename", async () => {
    await show([makePendingItem("p1", { title: "Old title" })]);
    respondWith("pending_rename", ({ id, title }) => makePendingItem(id as string, { title: title as string }));
    fireEvent.click(review());
    await pick(rowOf("Old title"), ["Rename…"]);
    const field = screen.getByRole("textbox", { name: "Title" });
    expect(field).toHaveValue("Old title");
    expect(field).toHaveAttribute("dir", "auto");
    fireEvent.change(field, { target: { value: "Invoice call with Acme" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("pending_rename")).toEqual([{ id: "p1", title: "Invoice call with Acme" }]);
    expect(screen.getByText("Invoice call with Acme")).toBeInTheDocument();
  });

  it("Choose project ▸ offers No project and every project, and assigns the one picked", async () => {
    await show([makePendingItem("p1", { title: "A call" })]);
    respondWith("pending_assign_client", ({ id, clientId }) => makePendingItem(id as string, { title: "A call", clientId: clientId as string, clientName: "Northwind" }));
    fireEvent.click(review());
    await act(flushAsync);
    fireEvent.click(within(rowOf("A call")).getByRole("button", { name: "More for A call" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Choose project" }));
    expect(within(screen.getByRole("menu", { name: "Choose project" })).getAllByRole("menuitemradio").map((m) => m.textContent))
      .toEqual(["No project", "Acme Holdings", "Northwind"]);
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Northwind" }));
    await act(flushAsync);
    expect(callsOf("pending_assign_client")).toEqual([{ id: "p1", clientId: "c2" }]);
    expect(rowOf("A call")).toHaveTextContent("Northwind");
  });

  it("Language ▸ (main languages, then More languages) sets the language and asks to remember it for the project", async () => {
    respond("client_update", { data: { id: "c1", name: "Acme Holdings", language: "ur" } });
    await show([makePendingItem("p1", { title: "A call", clientId: "c1", clientName: "Acme Holdings" })]);
    respondWith("pending_set_language", ({ id, language }) => makePendingItem(id as string, { title: "A call", clientId: "c1", clientName: "Acme Holdings", language: language as "ur" }));
    fireEvent.click(review());
    await act(flushAsync);
    fireEvent.click(within(rowOf("A call")).getByRole("button", { name: "More for A call" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Language" }));
    const menu = screen.getByRole("menu", { name: "Language" });
    expect(within(menu).getAllByRole("menuitemradio").map((m) => m.textContent)).toEqual([
      "Auto (English / Urdu / Arabic)", "English only", "Urdu (with English words)", "Arabic (with English words)",
      "French", "German", "Spanish", "Italian", "Portuguese", "Dutch", "Turkish", "Russian",
    ]);
    expect(within(menu).getByText("More languages")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("menuitemradio", { name: "Urdu (with English words)" }));
    await act(flushAsync);
    expect(callsOf("pending_set_language")).toEqual([{ id: "p1", language: "ur" }]);
    const ask = screen.getByRole("group", { name: "Remember the language" });
    expect(ask).toHaveTextContent("Use Urdu for Acme Holdings from now on?");
    fireEvent.click(within(ask).getByRole("button", { name: "Yes" }));
    await act(flushAsync);
    expect(callsOf("client_update")).toEqual([{ id: "c1", patch: { language: "ur" } }]);
  });

  it("follows pending:changed from the core", async () => {
    await show([makePendingItem("p1")]);
    await act(() => emitEvent("pending:changed", [makePendingItem("p1"), makePendingItem("p2"), makePendingItem("p3")]));
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("3 calls waiting");
  });
});

describe("uploads at the top of the v2 Library", () => {
  it("Importing: each file with its progress and Cancel (no question: the file is still on the computer)", async () => {
    await show([], [makeUploadItem("i1", { imported: true, title: "interview.m4a", state: "uploading", progress: 40 }), makeUploadItem("i2", { imported: true, title: "notes.mp3" })]);
    expect(screen.getByRole("heading", { name: "Importing · 2" })).toBeInTheDocument();
    expect(rowOf("interview.m4a")).toHaveTextContent("Uploading 40%");
    expect(rowOf("notes.mp3")).toHaveTextContent("Waiting to upload");
    expect(screen.getByRole("progressbar", { name: "Upload of interview.m4a" })).toHaveAttribute("aria-valuenow", "40");
    fireEvent.click(within(rowOf("interview.m4a")).getByRole("button", { name: "Cancel" }));
    await act(flushAsync);
    expect(callsOf("upload_discard")).toEqual([{ id: "i1" }]);
    expect(screen.queryByText("interview.m4a")).toBeNull();
  });

  it("Uploads that need you: the error, Retry, and Discard after asking", async () => {
    const confirm = answerConfirm().mockReturnValue(true);
    await show([], [makeUploadItem("u1", { state: "failed", title: "Zoom call", lastError: "The server could not verify this upload." }), makeUploadItem("u2", { state: "failed", title: "Board call" })]);
    expect(screen.getByRole("heading", { name: "Uploads that need you · 2" })).toBeInTheDocument();
    expect(rowOf("Zoom call")).toHaveTextContent("The server could not verify this upload.");
    fireEvent.click(within(rowOf("Zoom call")).getByRole("button", { name: "Retry" }));
    await act(flushAsync);
    expect(callsOf("upload_retry")).toEqual([{ id: "u1" }]);
    fireEvent.click(within(rowOf("Board call")).getByRole("button", { name: "Discard" }));
    await act(flushAsync);
    expect(confirm).toHaveBeenCalledWith(DISCARD);
    expect(callsOf("upload_discard")).toEqual([{ id: "u2" }]);
  });

  it("Waiting for another account: which account, Discard, and Discard all N with one question", async () => {
    const confirm = answerConfirm().mockReturnValue(true);
    const held = (id: string, title: string) => makeUploadItem(id, { title, heldFor: "ayesha@example.test" });
    await show([], [held("h1", "First"), held("h2", "Second")]);
    expect(screen.getByRole("heading", { name: "Waiting for another account · 2" })).toBeInTheDocument();
    expect(screen.getByLabelText("Waiting for ayesha@example.test")).toHaveTextContent(
      "2 recordings made while signed in as ayesha@example.test wait for that account. Sign out and sign in as ayesha@example.test to upload them, or discard them.",
    );
    fireEvent.click(screen.getByRole("button", { name: "Discard all 2" }));
    await act(flushAsync);
    expect(confirm).toHaveBeenCalledWith("Discard all 2 recordings? It can't be undone.");
    expect(callsOf("upload_discard")).toEqual([{ id: "h1" }, { id: "h2" }]);
    expect(screen.queryByTestId("held-uploads")).toBeNull();
  });

  it("Checking upload…: a recording the server is still checking, without actions", async () => {
    await show([], [makeUploadItem("v1", { state: "verifying", title: "Checked call" })]);
    expect(rowOf("Checked call")).toHaveTextContent("Checking upload…");
    expect(within(rowOf("Checked call")).queryByRole("button")).toBeNull();
  });

  it("follows uploads:changed from the core", async () => {
    await show();
    await act(() => emitEvent("uploads:changed", [makeUploadItem("u1", { state: "failed", title: "Late failure" })]));
    await waitFor(() => expect(screen.getByRole("heading", { name: "Uploads that need you · 1" })).toBeInTheDocument());
  });
});

describe("one Review for each thing at the top of the v2 Library (classic's Library has only its queue for these)", () => {
  const top = (status = makeStatus({ platform: "macos", pendingDecisions: 2, failedUploads: 1 })) =>
    ({ status, onSetup: vi.fn(), onReadyCheck: vi.fn(), onRecord: vi.fn(), onActivity: vi.fn() });
  beforeEach(() => {
    respond("ready_last", { ready: true, checkedAt: "2026-11-17T10:00:00Z", checks: [] });
    respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos" });
    respond("skipped_list", []);
  });

  it("while the queue shows the waiting calls and the uploads that need you, the banner above leaves them out", async () => {
    const items = [makeItem("a", { title: "Zoom with Acme" }), makeItem("u", { title: "Ali", status: "uploaded", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } })];
    respond("library_list", items);
    respond("library_refresh", items);
    respond("bin_list", { data: [] });
    respond("pending_list", [makePendingItem("p1"), makePendingItem("p2")]);
    respond("upload_list", [makeUploadItem("f1", { state: "failed", title: "Board call" })]);
    render(<LibraryV2 top={top()} />);
    await act(flushAsync);
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("2 calls waiting");
    expect(screen.getByRole("heading", { name: "Uploads that need you · 1" })).toBeInTheDocument();
    // The banner keeps only what the queue doesn't show; there is one Review button, the queue's.
    const banner = screen.getByTestId("needs-action");
    expect(banner).toHaveTextContent("1 recording isn't transcribed");
    expect(banner).not.toHaveTextContent(/wait for your decision|didn't upload/);
    expect(within(banner).queryByRole("button", { name: /^Review/ })).not.toBeNull();
    expect(screen.getAllByRole("button", { name: /^Review/ })).toHaveLength(2);
    fireEvent.click(within(banner).getByRole("button", { name: /^Review/ }));
    expect(screen.queryByRole("menu")).toBeNull();
  });

  it("with nothing in the queue, the banner still says what waits (the status counts it)", async () => {
    respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("bin_list", { data: [] });
    respond("pending_list", []);
    respond("upload_list", []);
    render(<LibraryV2 top={top()} />);
    await act(flushAsync);
    expect(screen.queryByTestId("library-queue")).toBeNull();
    expect(screen.getByTestId("needs-action")).toHaveTextContent("1 recording didn't upload · 1 more needs you");
    fireEvent.click(within(screen.getByTestId("needs-action")).getByRole("button", { name: "Review" }));
    expect(screen.getByRole("menuitem", { name: "2 calls wait for your decision" })).toBeInTheDocument();
  });

  it("Activity's Review opens the list once: Trash and back leaves it as the person left it", async () => {
    respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("bin_list", { data: [] });
    respond("pending_list", [makePendingItem("p1")]);
    respond("upload_list", []);
    respond("list_clients", CLIENTS);
    render(<LibraryV2 reviewRequest={{ seq: 7 }} />);
    await act(flushAsync);
    expect(waiting()).toBeInTheDocument();
    fireEvent.click(review());
    expect(screen.queryByRole("list", { name: "Waiting recordings" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Trash" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Trash" }));
    await act(flushAsync);
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("1 call waiting");
    expect(screen.queryByRole("list", { name: "Waiting recordings" })).toBeNull();
  });

  it("once nothing waits the list closes; the next call to arrive starts with the summary again", async () => {
    await show([makePendingItem("p1")]);
    fireEvent.click(review());
    expect(waiting()).toBeInTheDocument();
    await act(() => emitEvent("pending:changed", []));
    expect(screen.queryByTestId("library-queue")).toBeNull();
    await act(() => emitEvent("pending:changed", [makePendingItem("p2")]));
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("1 call waiting");
    expect(screen.queryByRole("list", { name: "Waiting recordings" })).toBeNull();
  });
});
