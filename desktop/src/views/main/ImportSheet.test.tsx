// Import on the desktop: the Library's Import button (the core's file picker) and files dropped anywhere on the main
// window open one sheet, which imports every file as its own recording with one client and language.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "./MainApp";
import { ImportSheet } from "./ImportSheet";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOTE = "C:\\Users\\me\\Downloads\\PTT-20261030-WA0003.opus";
const VIDEO = "C:\\Users\\me\\Videos\\Call_with_Acme_Oct.mp4";
const TEXT = "C:\\Users\\me\\Desktop\\readme.txt";
const DONE = { queued: 2, duplicates: 0, unsupported: 0, failed: 0 };

function mockBackend() {
  respond("app_status", makeStatus());
  respond("get_settings", makeSettings());
  respond("library_list", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("library_refresh", [makeItem("a", { title: "Zoom with Acme" })]);
  respond("list_clients", { data: [{ id: "c1", name: "Acme Holdings" }, { id: "c2", name: "Northwind" }] });
  respond("pending_list", []);
  respond("upload_list", []);
  respond("import_voice_notes", DONE);
}

async function renderPaired() {
  mockBackend();
  const view = render(<MainApp />);
  await screen.findByRole("heading", { name: "Library" });
  await flushAsync();
  return view;
}

const toast = () => document.querySelector(".toast");
const sheet = () => screen.getByRole("dialog");

beforeEach(() => resetTauriMock());

afterEach(async () => {
  await flushAsync();
  vi.restoreAllMocks();
});

describe("Import button", () => {
  it("picks several files and imports them with the chosen client and language", async () => {
    respond("import_pick", [NOTE, VIDEO]);
    await renderPaired();
    fireEvent.click(screen.getByRole("button", { name: "Import" }));
    await flushAsync();

    const dialog = screen.getByRole("dialog", { name: "Import 2 files" });
    // Each file becomes its own recording, titled from its file name.
    expect(within(dialog).getByText("PTT-20261030-WA0003")).toBeInTheDocument();
    expect(within(dialog).getByText("Call with Acme Oct")).toBeInTheDocument();
    fireEvent.change(within(dialog).getByLabelText("Client"), { target: { value: "c1" } });
    fireEvent.change(within(dialog).getByLabelText("Language"), { target: { value: "ur" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Import 2 files" }));
    await flushAsync();

    expect(callsOf("import_pick")).toHaveLength(1);
    expect(callsOf("import_voice_notes")).toEqual([{ pathsList: [NOTE, VIDEO], clientId: "c1", language: "ur" }]);
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(toast()).toHaveTextContent("Importing 2 files. They upload in the background.");
  });

  it("opens nothing when the picker is cancelled", async () => {
    respond("import_pick", []);
    await renderPaired();
    fireEvent.click(screen.getByRole("button", { name: "Import" }));
    await flushAsync();
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(callsOf("import_voice_notes")).toHaveLength(0);
  });

  it("is also on the Record page, next to the drop area", async () => {
    respond("import_pick", [NOTE]);
    respond("mic_users", []);
    await renderPaired();
    fireEvent.click(screen.getByRole("button", { name: "Record" }));
    fireEvent.click(await screen.findByRole("button", { name: "Choose files…" }));
    await flushAsync();
    expect(screen.getByRole("dialog", { name: "Import 1 file" })).toBeInTheDocument();
  });
});

describe("Dropping files on the window", () => {
  it("shows where to drop while files are dragged over, and hides it when they leave", async () => {
    await renderPaired();
    await emitEvent("tauri://drag-enter", { paths: [NOTE], position: { x: 10, y: 10 } });
    expect(document.querySelector(".drop-hint")).toHaveTextContent("Drop voice notes, audio or video files to import them");
    await emitEvent("tauri://drag-leave", null);
    expect(document.querySelector(".drop-hint")).not.toBeInTheDocument();
  });

  it("opens the sheet for the dropped files and imports them without a client by default", async () => {
    await renderPaired();
    await emitEvent("tauri://drag-enter", { paths: [NOTE, VIDEO], position: { x: 10, y: 10 } });
    await emitEvent("tauri://drag-drop", { paths: [NOTE, VIDEO], position: { x: 10, y: 10 } });
    expect(document.querySelector(".drop-hint")).not.toBeInTheDocument();
    const dialog = screen.getByRole("dialog", { name: "Import 2 files" });
    expect(within(dialog).getByLabelText("Client")).toHaveValue("");
    expect(within(dialog).getByLabelText("Language")).toHaveValue("");
    fireEvent.click(within(dialog).getByRole("button", { name: "Import 2 files" }));
    await flushAsync();
    expect(callsOf("import_voice_notes")).toEqual([{ pathsList: [NOTE, VIDEO], clientId: null, language: null }]);
  });

  it("works from any page of the window", async () => {
    await renderPaired();
    fireEvent.click(screen.getByRole("button", { name: "Settings" }));
    await emitEvent("tauri://drag-drop", { paths: [VIDEO], position: { x: 10, y: 10 } });
    expect(screen.getByRole("dialog", { name: "Import 1 file" })).toBeInTheDocument();
  });

  it("is ignored on the lock screen", async () => {
    mockBackend();
    respond("lock_status", { enabled: true, locked: true, hasPin: true, osMethod: null, retryAfterSeconds: 0 });
    render(<MainApp />);
    await flushAsync();
    expect(screen.getByRole("heading", { name: "EchoLedger is locked" })).toBeInTheDocument();
    await emitEvent("tauri://drag-enter", { paths: [NOTE], position: { x: 10, y: 10 } });
    expect(document.querySelector(".drop-hint")).not.toBeInTheDocument();
    await emitEvent("tauri://drag-drop", { paths: [NOTE], position: { x: 10, y: 10 } });
    expect(screen.queryByRole("dialog", { name: /Import/ })).not.toBeInTheDocument();
  });
});

describe("The import sheet", () => {
  const notify = vi.fn();
  const onClose = vi.fn();
  beforeEach(() => {
    notify.mockClear();
    onClose.mockClear();
    respond("list_clients", { data: [{ id: "c2", name: "Northwind" }, { id: "c1", name: "Acme Holdings", pinned: true }] });
    respond("import_voice_notes", DONE);
  });

  it("lists every file with the title it will get, and says which ones cannot be imported", async () => {
    render(<ImportSheet paths={[NOTE, TEXT, VIDEO]} notify={notify} onClose={onClose} />);
    await flushAsync();
    const files = within(screen.getByRole("list", { name: "Files to import" })).getAllByRole("listitem");
    expect(files.map((f) => f.firstChild?.textContent)).toEqual(["PTT-20261030-WA0003", "readme.txt", "Call with Acme Oct"]);
    expect(files[1]).toHaveTextContent("Can't be imported: not a voice note, audio or video file");
    expect(files[2]).toHaveTextContent("Call_with_Acme_Oct.mp4");
    expect(screen.getByRole("button", { name: "Import 2 files" })).toBeEnabled();
  });

  it("offers No client first, then the client folders with pinned ones on top, and every language", async () => {
    render(<ImportSheet paths={[NOTE]} notify={notify} onClose={onClose} />);
    await flushAsync();
    const clients = within(screen.getByLabelText("Client")).getAllByRole("option").map((o) => o.textContent);
    expect(clients).toEqual(["No client", "AH · Acme Holdings", "NO · Northwind"]);
    const languages = within(screen.getByLabelText("Language")).getAllByRole("option").map((o) => o.textContent);
    expect(languages.slice(0, 4)).toEqual(["Auto (English / Urdu / Arabic)", "English only", "Urdu (with English words)", "Arabic (with English words)"]);
    expect(languages).toContain("French");
  });

  it("cannot import when none of the files can be", async () => {
    render(<ImportSheet paths={[TEXT]} notify={notify} onClose={onClose} />);
    await flushAsync();
    expect(screen.getByRole("dialog", { name: "Nothing to import" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Import" })).toBeDisabled();
  });

  it("closes with Cancel or Escape and imports nothing", async () => {
    render(<ImportSheet paths={[NOTE]} notify={notify} onClose={onClose} />);
    await flushAsync();
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    fireEvent.keyDown(window, { key: "Escape" });
    expect(onClose).toHaveBeenCalledTimes(2);
    expect(callsOf("import_voice_notes")).toHaveLength(0);
  });

  it("works offline without the client list", async () => {
    fail("list_clients", "Network error: offline");
    render(<ImportSheet paths={[NOTE]} notify={notify} onClose={onClose} />);
    await flushAsync();
    expect(within(screen.getByLabelText("Client")).getAllByRole("option").map((o) => o.textContent)).toEqual(["No client"]);
    fireEvent.click(screen.getByRole("button", { name: "Import" }));
    await flushAsync();
    expect(callsOf("import_voice_notes")).toEqual([{ pathsList: [NOTE], clientId: null, language: null }]);
  });

  it("keeps the sheet open and says why when the import fails", async () => {
    fail("import_voice_notes", "EchoLedger is locked.");
    render(<ImportSheet paths={[NOTE]} notify={notify} onClose={onClose} />);
    await flushAsync();
    fireEvent.click(screen.getByRole("button", { name: "Import" }));
    await flushAsync();
    expect(notify).toHaveBeenCalledWith("EchoLedger is locked.", true);
    expect(onClose).not.toHaveBeenCalled();
    expect(screen.getByRole("button", { name: "Import" })).toBeEnabled();
  });

  it("disables its buttons while the files are copied", async () => {
    let finish: (value: unknown) => void = () => undefined;
    respondWith("import_voice_notes", () => new Promise((resolve) => (finish = resolve)));
    render(<ImportSheet paths={[NOTE, VIDEO]} notify={notify} onClose={onClose} />);
    await flushAsync();
    fireEvent.click(screen.getByRole("button", { name: "Import 2 files" }));
    expect(screen.getByRole("button", { name: "Importing…" })).toBeDisabled();
    expect(screen.getByRole("button", { name: "Cancel" })).toBeDisabled();
    finish(DONE);
    await flushAsync();
    expect(onClose).toHaveBeenCalled();
  });
});

describe("Duplicates", () => {
  it("says how many files were skipped because they are already waiting to upload", async () => {
    respond("import_pick", [NOTE, NOTE, VIDEO]);
    await renderPaired();
    respond("import_voice_notes", { queued: 1, duplicates: 2, unsupported: 0, failed: 0 });
    fireEvent.click(screen.getByRole("button", { name: "Import" }));
    await flushAsync();
    fireEvent.click(within(sheet()).getByRole("button", { name: "Import 3 files" }));
    await flushAsync();
    expect(toast()).toHaveTextContent("Importing 1 file. It uploads in the background. Skipped 2 files already waiting to upload.");
    expect(toast()).not.toHaveClass("error");
  });

  it("shows the core's notice for files the server already holds", async () => {
    await renderPaired();
    await emitEvent("app:toast", "2 files were already in your Library, so they were skipped.");
    expect(toast()).toHaveTextContent("2 files were already in your Library, so they were skipped.");
  });

  it("reports an import where nothing could be queued as an error", async () => {
    respond("import_pick", [TEXT, NOTE]);
    await renderPaired();
    respond("import_voice_notes", { queued: 0, duplicates: 0, unsupported: 1, failed: 1 });
    fireEvent.click(screen.getByRole("button", { name: "Import" }));
    await flushAsync();
    fireEvent.click(within(sheet()).getByRole("button", { name: "Import" }));
    await flushAsync();
    expect(toast()).toHaveTextContent("Skipped 1 file that is not a voice note, audio or video file. Couldn't read 1 file.");
    expect(toast()).toHaveClass("error");
  });
});
