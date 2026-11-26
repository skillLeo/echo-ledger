// The top of the v2 Library list (QueueV2): recordings waiting for Transcribe / Delete with Review, Transcribe all, Delete all,
// Rename, Choose project, Language and Remember the language; Importing with Cancel; Uploads that need you with Retry /
// Discard; Waiting for another account with Discard / Discard all N; Checking upload. The same core commands as classic.
// Since PLAN_LIBRARY_FILTERS what needs the person (the calls waiting, the uploads that need them, the recordings waiting
// for another account) is in the sidebar's Needs you, and what is on its way (imports, uploads being checked) in the
// other views.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { PendingItem, UploadItem } from "../../lib/ipc";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makePendingItem, makeUploadItem } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 9, 24, 12, 0);
const CLIENTS = { data: [{ id: "c1", name: "Acme Holdings", language: null }, { id: "c2", name: "Northwind", language: null }] };
const DISCARD = "Discard this recording? It can't be undone.";
const DELETE = "Delete this recording? You can undo it for a few seconds.";

async function show(pending: PendingItem[] = [], uploads: UploadItem[] = [], view: "needs" | "all" = "needs") {
  respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("bin_list", { data: [] });
  respond("pending_list", pending);
  respond("upload_list", uploads);
  respond("list_clients", CLIENTS);
  respond("pending_keep", undefined);
  respond("pending_discard", undefined);
  respond("pending_delete", 1);
  respond("pending_keep_audio", undefined);
  respond("upload_retry", undefined);
  respond("upload_discard", undefined);
  render(<LibraryV2 scopeRequest={{ scope: { kind: "view", view }, seq: 1 }} />);
  await act(flushAsync);
  // Needs you opens the waiting calls' list; these tests start from its summary line.
  const hide = screen.queryByRole("button", { name: "Hide" });
  if (hide) fireEvent.click(hide);
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

  it("Transcribe sends one with pending_keep; Delete asks first", async () => {
    const confirm = answerConfirm().mockReturnValue(true);
    await show([makePendingItem("p1", { title: "Keep me" }), makePendingItem("p2", { title: "Drop me" })]);
    fireEvent.click(review());
    fireEvent.click(within(rowOf("Keep me")).getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(callsOf("pending_keep")).toEqual([{ id: "p1" }]);
    expect(screen.queryByText("Keep me")).toBeNull();
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Transcribing…");
    fireEvent.click(within(rowOf("Drop me")).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(confirm).toHaveBeenCalledWith(DELETE);
    expect(callsOf("pending_delete")).toEqual([{ ids: ["p2"] }]);
    expect(callsOf("pending_discard"), "never the immediate delete").toEqual([]);
    // The queue's "Deleted · Undo" is the toast's own Undo button, which brings the recording back.
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast.querySelector(".el-toast__message")).toHaveTextContent(/^Deleted$/);
    expect(screen.queryByTestId("library-queue")).toBeNull();
    respond("pending_delete_undo", undefined);
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("pending_delete_undo")).toEqual([{ ids: ["p2"] }]);
  });

  it("Transcribe all sends every one; Delete all asks once and deletes nothing when cancelled", async () => {
    const confirm = answerConfirm().mockReturnValue(false);
    await show([makePendingItem("p1"), makePendingItem("p2")]);
    fireEvent.click(review());
    fireEvent.click(screen.getByRole("button", { name: "Delete all" }));
    await act(flushAsync);
    expect(confirm).toHaveBeenCalledWith("Delete all 2 recordings? You can undo it for a few seconds.");
    expect(callsOf("pending_delete")).toEqual([]);
    fireEvent.click(screen.getByRole("button", { name: "Transcribe all" }));
    await act(flushAsync);
    expect(callsOf("pending_keep")).toEqual([{ id: "p1" }, { id: "p2" }]);
    expect(screen.queryByTestId("library-queue")).toBeNull();
  });

  it("a recording whose Delete was still waiting when EchoLedger closed says so on its row, with all three choices", async () => {
    await show([makePendingItem("p1", { title: "Came back", restoredDelete: true }), makePendingItem("p2", { title: "Plain" })]);
    fireEvent.click(review());
    expect(within(rowOf("Came back")).getByTestId("restored-delete")).toHaveTextContent("Deleted just before EchoLedger closed. Choose again.");
    expect(within(rowOf("Plain")).queryByTestId("restored-delete")).toBeNull();
    for (const name of ["Transcribe", "Save audio only", "Delete"]) expect(within(rowOf("Came back")).getByRole("button", { name })).toBeInTheDocument();
  });

  it("Save audio only, for one and for all, keeps the audio without a transcript", async () => {
    await show([makePendingItem("p1", { title: "One" }), makePendingItem("p2", { title: "Two" }), makePendingItem("p3", { title: "Three" })]);
    fireEvent.click(review());
    fireEvent.click(within(rowOf("One")).getByRole("button", { name: "Save audio only" }));
    await act(flushAsync);
    expect(callsOf("pending_keep_audio")).toEqual([{ id: "p1" }]);
    fireEvent.click(screen.getByRole("button", { name: "Save all as audio only" }));
    await act(flushAsync);
    expect(callsOf("pending_keep_audio")).toEqual([{ id: "p1" }, { id: "p2" }, { id: "p3" }]);
    expect(callsOf("pending_keep")).toEqual([]);
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

  it("one primary per area: Transcribe all is the card's only filled button; each row's Transcribe is secondary", async () => {
    await show([makePendingItem("p1"), makePendingItem("p2", { title: "Board call" })]);
    fireEvent.click(review());
    const card = screen.getByRole("region", { name: "Waiting recordings" });
    const primary = [...card.querySelectorAll("button.el-button--primary")].map((b) => b.textContent);
    expect(primary).toEqual(["Transcribe all"]);
    for (const keep of within(waiting()).getAllByRole("button", { name: "Transcribe" })) {
      expect(keep).toHaveClass("el-button--secondary");
      expect(keep).not.toHaveClass("el-button--primary");
    }
  });

  it("follows pending:changed from the core", async () => {
    await show([makePendingItem("p1")]);
    await act(() => emitEvent("pending:changed", [makePendingItem("p1"), makePendingItem("p2"), makePendingItem("p3")]));
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("3 calls waiting");
  });
});

describe("uploads at the top of the v2 Library", () => {
  it("Importing: each file with its progress and Cancel (no question: the file is still on the computer)", async () => {
    await show([], [makeUploadItem("i1", { imported: true, title: "interview.m4a", state: "uploading", progress: 40 }), makeUploadItem("i2", { imported: true, title: "notes.mp3" })], "all");
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
    await show([], [makeUploadItem("v1", { state: "verifying", title: "Checked call" })], "all");
    expect(rowOf("Checked call")).toHaveTextContent("Checking upload…");
    expect(within(rowOf("Checked call")).queryByRole("button")).toBeNull();
  });

  it("follows uploads:changed from the core", async () => {
    await show();
    await act(() => emitEvent("uploads:changed", [makeUploadItem("u1", { state: "failed", title: "Late failure" })]));
    await waitFor(() => expect(screen.getByRole("heading", { name: "Uploads that need you · 1" })).toBeInTheDocument());
  });
});

describe("Needs you: what it lists and what it counts (PLAN_LIBRARY_FILTERS)", () => {
  const items = [makeItem("a", { title: "Zoom with Acme" }), makeItem("f", { title: "Failed call", status: "failed", transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null } })];
  const open = async (scope: "needs" | "all", pending: PendingItem[], uploads: UploadItem[] = []) => {
    respond("library_list", items);
    respond("library_refresh", items);
    respond("bin_list", { data: [] });
    respond("pending_list", pending);
    respond("upload_list", uploads);
    respond("list_clients", CLIENTS);
    const view = render(<LibraryV2 scopeRequest={{ scope: { kind: "view", view: scope }, seq: 1 }} />);
    await act(flushAsync);
    return view;
  };
  const title = () => screen.getByRole("heading", { level: 1 });
  const held = makeUploadItem("h1", { title: "Other account's call", heldFor: "bina@example.com" });

  it("All shows neither the calls waiting nor the uploads that need you: they are Needs you's, which lists them with its own Review", async () => {
    const view = await open("all", [makePendingItem("p1"), makePendingItem("p2")], [makeUploadItem("u1", { state: "failed", title: "Board call" })]);
    expect(screen.queryByTestId("waiting-line")).toBeNull();
    expect(screen.queryByRole("heading", { name: /^Uploads that need you/ })).toBeNull();
    view.rerender(<LibraryV2 scopeRequest={{ scope: { kind: "view", view: "needs" }, seq: 2 }} />);
    await act(flushAsync);
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("2 calls waiting");
    expect(screen.getByRole("heading", { name: "Uploads that need you · 1" })).toBeInTheDocument();
    // The calls' list is open: Needs you is where they are decided.
    expect(within(waiting()).getAllByRole("listitem")).toHaveLength(2);
    expect(screen.getAllByRole("button", { name: /^(Review|Hide)$/ })).toHaveLength(1);
  });

  it("counts what it lists: the calls waiting, the uploads that need you, the recordings waiting for another account, and the recordings that need you", async () => {
    await open("needs", [makePendingItem("p1"), makePendingItem("p2")], [makeUploadItem("u1", { state: "failed", title: "Board call" }), held]);
    expect(title()).toHaveTextContent(/^Needs you5$/);
    expect(document.querySelectorAll("button[data-row-id]")).toHaveLength(1);
    expect(document.querySelector("button[data-row-id=f]")).not.toBeNull();
  });

  it("nothing needs you: it says so", async () => {
    respond("library_list", [items[0]]);
    respond("library_refresh", [items[0]]);
    respond("bin_list", { data: [] });
    respond("pending_list", []);
    respond("upload_list", []);
    render(<LibraryV2 scopeRequest={{ scope: { kind: "view", view: "needs" }, seq: 1 }} />);
    await act(flushAsync);
    expect(screen.getByRole("region", { name: "Nothing needs you" })).toBeInTheDocument();
  });

  it("the waiting list can be hidden there, and opens again", async () => {
    await open("needs", [makePendingItem("p1")]);
    fireEvent.click(review());
    expect(screen.queryByRole("list", { name: "Waiting recordings" })).toBeNull();
    expect(review()).toHaveTextContent("Review");
    fireEvent.click(review());
    expect(waiting()).toBeInTheDocument();
  });

  it("Activity's Review opens Needs you with the list open, once: Trash and back leaves it as the person left it", async () => {
    respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
    respond("bin_list", { data: [] });
    respond("pending_list", [makePendingItem("p1")]);
    respond("upload_list", []);
    respond("list_clients", CLIENTS);
    const onScope = vi.fn();
    // The app keeps one request object until the next Review (MainApp's state).
    const request = { seq: 7 };
    const view = render(<LibraryV2 reviewRequest={request} onScope={onScope} />);
    await act(flushAsync);
    expect(onScope).toHaveBeenCalledWith({ kind: "view", view: "needs" });
    expect(waiting()).toBeInTheDocument();
    fireEvent.click(review());
    expect(screen.queryByRole("list", { name: "Waiting recordings" })).toBeNull();
    view.rerender(<LibraryV2 reviewRequest={request} onScope={onScope} scopeRequest={{ scope: { kind: "trash" }, seq: 1 }} />);
    await act(flushAsync);
    view.rerender(<LibraryV2 reviewRequest={request} onScope={onScope} scopeRequest={{ scope: { kind: "view", view: "all" }, seq: 2 }} />);
    await act(flushAsync);
    expect(screen.queryByTestId("waiting-line")).toBeNull();
    view.rerender(<LibraryV2 reviewRequest={request} onScope={onScope} scopeRequest={{ scope: { kind: "view", view: "needs" }, seq: 3 }} />);
    await act(flushAsync);
    // Needs you again from the sidebar: the list opens (it is what Needs you is for); Review's request isn't answered twice.
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("1 call waiting");
  });

  it("once nothing waits the list closes; the next call to arrive starts with the summary again", async () => {
    await show([makePendingItem("p1")]);
    fireEvent.click(review());
    expect(waiting()).toBeInTheDocument();
    await act(() => emitEvent("pending:changed", []));
    expect(screen.queryByTestId("waiting-line")).toBeNull();
    await act(() => emitEvent("pending:changed", [makePendingItem("p2")]));
    expect(screen.getByTestId("waiting-line")).toHaveTextContent("1 call waiting");
    expect(screen.queryByRole("list", { name: "Waiting recordings" })).toBeNull();
  });

  it("imports and uploads being checked are on their way, not something to do: they show in the other views, not in Needs you", async () => {
    const view = await open("needs", [], [makeUploadItem("i1", { imported: true, title: "interview.m4a" }), makeUploadItem("v1", { state: "verifying", title: "Checked call" })]);
    expect(screen.queryByText("interview.m4a")).toBeNull();
    view.rerender(<LibraryV2 scopeRequest={{ scope: { kind: "view", view: "all" }, seq: 2 }} />);
    await act(flushAsync);
    expect(screen.getByText("interview.m4a")).toBeInTheDocument();
    expect(screen.getByText("Checked call")).toBeInTheDocument();
  });
});
