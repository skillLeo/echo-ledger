// The Library's rename (row menu and the recording's title), saving (transcript in the view on screen, the audio, a
// zip of the selection; the core asks where) and live status (a quiet sync every 5 s while something is moving).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { LibraryView } from "./LibraryView";
import { BulkBar } from "./LibraryOrganise";
import type { LibraryItem, TranscriptDetail } from "../../lib/ipc";
import { needsLiveStatus } from "../../lib/library";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 9, 24, 12, 0, 0);
const UPDATED = new Date(2026, 9, 24, 11, 0, 0).toISOString();

const done = (id: string, title: string, extra: Partial<LibraryItem> = {}) =>
  makeItem(id, { title, updated_at: UPDATED, transcript: { final_status: "completed", final_transcript_id: `tr-${id}`, live_available: false, preview: null }, ...extra });
const transcribing = (id: string, title: string) =>
  makeItem(id, { title, updated_at: UPDATED, status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null } });

const ITEMS = [done("a", "Zoom with Acme"), done("b", "Budget review", { pinned: true })];

function mockBackend(items: LibraryItem[] = ITEMS, transcripts: Record<string, TranscriptDetail> = {}) {
  respond("library_list", items);
  respond("library_refresh", items);
  respondWith("transcript_detail", ({ id }) => transcripts[id as string] ?? makeTranscript({ id: id as string }));
  respondWith("session_detail", ({ id }) => ({ id, playback_url: "https://audio.example.test/rec.flac" }));
  respond("pending_list", []);
  respond("upload_list", []);
  respond("list_clients", { data: [] });
}

async function renderLibrary(items: LibraryItem[] = ITEMS, transcripts: Record<string, TranscriptDetail> = {}) {
  mockBackend(items, transcripts);
  const notify = vi.fn();
  const view = render(<LibraryView notify={notify} />);
  await flushAsync();
  return { notify, ...view };
}

const list = () => screen.getByTestId("library-list");
const detail = () => document.querySelector(".detail") as HTMLElement;
const titles = () => [...list().querySelectorAll(".item-title")].map((n) => n.textContent?.replace("★ ", ""));
const open = async (title: string) => {
  fireEvent.click(within(list()).getByText(title).closest("button") as HTMLElement);
  await flushAsync();
};
const menuOf = (title: string) => screen.getByRole("button", { name: `More for ${title}` });

beforeEach(() => {
  resetTauriMock();
  vi.useFakeTimers({ toFake: ["Date"], now: NOW });
});

afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("Rename", () => {
  it("renames from the row menu and shows the new name at once", async () => {
    let answer: (value: unknown) => void = () => undefined;
    respondWith("session_organise", () => new Promise((resolve) => (answer = resolve)));
    const { notify } = await renderLibrary();
    fireEvent.click(menuOf("Zoom with Acme"));
    fireEvent.click(screen.getByRole("menuitem", { name: "Rename" }));
    const field = within(list()).getByRole("textbox", { name: "Title" });
    expect(field).toHaveValue("Zoom with Acme");
    fireEvent.change(field, { target: { value: "  Acme kickoff  " } });
    fireEvent.keyDown(field, { key: "Enter" });

    // Before the server answers, the list already shows it.
    expect(titles()).toContain("Acme kickoff");
    expect(callsOf("session_organise")).toEqual([{ id: "a", patch: { title: "Acme kickoff" } }]);
    answer({ data: done("a", "Acme kickoff") });
    await flushAsync();
    expect(notify).toHaveBeenCalledWith("Renamed");
    expect(titles()).toContain("Acme kickoff");
  });

  it("renames by clicking the title of the open recording", async () => {
    respondWith("session_organise", ({ patch }) => ({ data: done("a", (patch as { title: string }).title) }));
    await renderLibrary();
    await open("Zoom with Acme");
    fireEvent.click(within(detail()).getByRole("button", { name: "Zoom with Acme" }));
    const field = within(detail()).getByRole("textbox", { name: "Title" });
    fireEvent.change(field, { target: { value: "Call with Acme about the invoice" } });
    fireEvent.blur(field);
    expect(within(detail()).getByRole("heading", { name: "Call with Acme about the invoice" })).toBeInTheDocument();
    expect(titles()).toContain("Call with Acme about the invoice");
    await flushAsync();
    expect(callsOf("session_organise")).toEqual([{ id: "a", patch: { title: "Call with Acme about the invoice" } }]);
  });

  it("clears the title when the field is emptied and shows the name the server gives", async () => {
    respond("session_organise", { data: done("a", "Zoom call") });
    const { notify } = await renderLibrary();
    await open("Zoom with Acme");
    fireEvent.click(within(detail()).getByRole("button", { name: "Zoom with Acme" }));
    const field = within(detail()).getByRole("textbox", { name: "Title" });
    fireEvent.change(field, { target: { value: "   " } });
    fireEvent.keyDown(field, { key: "Enter" });
    await flushAsync();
    expect(callsOf("session_organise")).toEqual([{ id: "a", patch: { title: "" } }]);
    expect(within(detail()).getByRole("heading", { name: "Zoom call" })).toBeInTheDocument();
    expect(notify).toHaveBeenCalledWith("Title cleared");
  });

  it("sends nothing for Escape or an unchanged title", async () => {
    await renderLibrary();
    fireEvent.click(menuOf("Zoom with Acme"));
    fireEvent.click(screen.getByRole("menuitem", { name: "Rename" }));
    fireEvent.keyDown(within(list()).getByRole("textbox", { name: "Title" }), { key: "Escape" });
    expect(within(list()).queryByRole("textbox", { name: "Title" })).not.toBeInTheDocument();
    fireEvent.click(menuOf("Zoom with Acme"));
    fireEvent.click(screen.getByRole("menuitem", { name: "Rename" }));
    fireEvent.keyDown(within(list()).getByRole("textbox", { name: "Title" }), { key: "Enter" });
    await flushAsync();
    expect(callsOf("session_organise")).toHaveLength(0);
    expect(titles()).toContain("Zoom with Acme");
  });

  it("puts the old name back when the server refuses", async () => {
    fail("session_organise", "Server returned 422: The title may not be greater than 200 characters.");
    const { notify } = await renderLibrary();
    fireEvent.click(menuOf("Zoom with Acme"));
    fireEvent.click(screen.getByRole("menuitem", { name: "Rename" }));
    const field = within(list()).getByRole("textbox", { name: "Title" });
    expect(field).toHaveAttribute("maxLength", "200");
    fireEvent.change(field, { target: { value: "New name" } });
    fireEvent.keyDown(field, { key: "Enter" });
    await flushAsync();
    expect(titles()).toContain("Zoom with Acme");
    expect(titles()).not.toContain("New name");
    expect(notify).toHaveBeenCalledWith("Server returned 422: The title may not be greater than 200 characters.", true);
  });

  it("offers Pin or Unpin and Delete in the same menu, and closes it on a click elsewhere", async () => {
    respond("session_organise", { data: done("b", "Budget review") });
    await renderLibrary();
    fireEvent.click(menuOf("Budget review"));
    expect(screen.getByRole("menu", { name: "Actions for Budget review" })).toBeInTheDocument();
    expect(screen.getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["Rename", "Unpin", "Delete"]);
    fireEvent.mouseDown(document.body);
    expect(screen.queryByRole("menu")).not.toBeInTheDocument();
    fireEvent.click(menuOf("Budget review"));
    fireEvent.click(screen.getByRole("menuitem", { name: "Unpin" }));
    await flushAsync();
    expect(callsOf("session_organise")).toEqual([{ id: "b", patch: { pinned: false } }]);
  });

  it("hides the row menu while recordings are being selected", async () => {
    await renderLibrary();
    fireEvent.click(screen.getByRole("button", { name: "Select" }));
    expect(screen.queryByRole("button", { name: "More for Zoom with Acme" })).not.toBeInTheDocument();
  });
});

describe("Save and export", () => {
  const urduViews = { status: "ready", available: ["roman", "english"], roman: ["Aap kaise hain"], english: ["How are you"], urdu: null, default: "original", model: "claude", made_at: null, error: null };
  const urduTranscript = makeTranscript({ id: "tr-a", language: "ur", segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 1_000, text: "آپ کیسے ہیں" }],
    views: { available: ["roman", "english"], status: "ready" } });

  it("exports the transcript in the view on screen and says where it was saved", async () => {
    respond("transcript_views", urduViews);
    respond("export_transcript", "D:\\Clients\\Acme call.srt");
    const { notify } = await renderLibrary(ITEMS, { "tr-a": urduTranscript });
    await open("Zoom with Acme");
    fireEvent.click(within(detail()).getByRole("button", { name: "Roman Urdu" }));
    await flushAsync();
    const menu = within(detail()).getByLabelText("Export");
    expect(within(menu).getAllByRole("option")[0]).toHaveTextContent("Export as Roman Urdu…");
    fireEvent.change(menu, { target: { value: "srt" } });
    await flushAsync();
    expect(callsOf("export_transcript")).toEqual([{ id: "tr-a", format: "srt", timestamps: true, speakers: true, view: "roman" }]);
    expect(notify).toHaveBeenCalledWith("Saved to D:\\Clients\\Acme call.srt");
  });

  it("exports the original words when Original is shown", async () => {
    respond("export_transcript", "D:\\out.txt");
    await renderLibrary();
    await open("Zoom with Acme");
    const menu = within(detail()).getByLabelText("Export");
    expect(within(menu).getAllByRole("option")[0]).toHaveTextContent("Export…");
    fireEvent.change(menu, { target: { value: "txt" } });
    await flushAsync();
    expect(callsOf("export_transcript")[0]).toMatchObject({ format: "txt", view: null });
  });

  it("says nothing when the save dialog is cancelled", async () => {
    respond("export_transcript", null);
    const { notify } = await renderLibrary();
    await open("Zoom with Acme");
    fireEvent.change(within(detail()).getByLabelText("Export"), { target: { value: "docx" } });
    await flushAsync();
    expect(callsOf("export_transcript")).toHaveLength(1);
    expect(notify).not.toHaveBeenCalled();
  });

  it("opens the print page for PDF and explains how to save it", async () => {
    respond("export_transcript", "C:\\Temp\\Zoom with Acme.html");
    const { notify } = await renderLibrary();
    await open("Zoom with Acme");
    const menu = within(detail()).getByLabelText("Export");
    expect(within(menu).getByRole("option", { name: "PDF (print)" })).toBeInTheDocument();
    fireEvent.change(menu, { target: { value: "html" } });
    await flushAsync();
    expect(callsOf("export_transcript")[0]).toMatchObject({ format: "html" });
    expect(notify).toHaveBeenCalledWith("Opened the print page in your browser: choose Save as PDF there");
  });

  it("saves the audio of the open recording", async () => {
    respondWith("save_audio", ({ id }) => `D:\\Audio\\${id}.flac`);
    const { notify } = await renderLibrary();
    await open("Zoom with Acme");
    fireEvent.click(within(detail()).getByRole("button", { name: "Save audio" }));
    await flushAsync();
    expect(callsOf("save_audio")).toEqual([{ id: "a" }]);
    expect(notify).toHaveBeenCalledWith("Saved to D:\\Audio\\a.flac");
  });

  it("offers no Save audio when the recording has no audio on the server", async () => {
    mockBackend();
    respondWith("session_detail", ({ id }) => ({ id, playback_url: null }));
    render(<LibraryView notify={vi.fn()} />);
    await flushAsync();
    await open("Zoom with Acme");
    expect(within(detail()).queryByRole("button", { name: "Save audio" })).not.toBeInTheDocument();
  });

  it("says nothing when the zip's save dialog is cancelled", async () => {
    respond("sessions_export", null);
    const notify = vi.fn();
    render(<BulkBar ids={["a", "b"]} clients={[]} knownLabels={[]} notify={notify} onDone={vi.fn()} onClientCreated={vi.fn()} />);
    fireEvent.change(screen.getByRole("combobox", { name: "Export format" }), { target: { value: "json" } });
    fireEvent.click(screen.getByRole("button", { name: "Export transcripts" }));
    await flushAsync();
    expect(callsOf("sessions_export")).toEqual([{ ids: ["a", "b"], format: "json" }]);
    expect(notify).not.toHaveBeenCalled();
  });
});

describe("Live status", () => {
  const advance = async (ms: number) => {
    await act(async () => {
      await vi.advanceTimersByTimeAsync(ms);
    });
  };
  const useClock = () => vi.useFakeTimers({ toFake: ["Date", "setTimeout", "clearTimeout"], now: NOW });

  it("knows when a status is still moving", () => {
    const now = NOW.getTime();
    expect(needsLiveStatus([done("a", "Done")], now)).toBe(false);
    expect(needsLiveStatus([transcribing("b", "Busy")], now)).toBe(true);
    expect(needsLiveStatus([makeItem("u", { updated_at: UPDATED, status: "uploading" })], now)).toBe(true);
    expect(needsLiveStatus([done("r", "Re-transcribing", { transcript: { final_status: "completed", final_transcript_id: "t", live_available: false, preview: null, alternate_status: "submitted" } })], now)).toBe(true);
    expect(needsLiveStatus([{ ...transcribing("x", "Gone"), deleted: true }], now)).toBe(false);
    // A phone that never finished its upload two days ago is not waited for.
    expect(needsLiveStatus([makeItem("old", { status: "uploading", updated_at: new Date(2026, 9, 22).toISOString() })], now)).toBe(false);
  });

  it("syncs every 5 seconds while a recording is transcribed, and stops once it is done", async () => {
    useClock();
    await renderLibrary([done("a", "Zoom with Acme"), transcribing("b", "WhatsApp call")]);
    expect(callsOf("library_refresh")).toHaveLength(1);
    await advance(4_000);
    expect(callsOf("library_refresh")).toHaveLength(1);
    await advance(1_000);
    expect(callsOf("library_refresh")).toHaveLength(2);
    await advance(5_000);
    expect(callsOf("library_refresh")).toHaveLength(3);

    // The transcript is ready: the list shows it without a manual sync, and the syncing stops.
    respond("library_refresh", [done("a", "Zoom with Acme"), done("b", "WhatsApp call")]);
    await advance(5_000);
    expect(callsOf("library_refresh")).toHaveLength(4);
    expect(within(list()).getByText("WhatsApp call").closest("button")).toHaveTextContent("Ready");
    await advance(30_000);
    expect(callsOf("library_refresh")).toHaveLength(4);
  });

  it("does not sync on its own when nothing is moving", async () => {
    useClock();
    await renderLibrary();
    await advance(30_000);
    expect(callsOf("library_refresh")).toHaveLength(1);
  });

  it("updates the open recording when its transcript is ready", async () => {
    useClock();
    await renderLibrary([transcribing("b", "WhatsApp call")], { "tr-b": makeTranscript({ id: "tr-b", segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 900, text: "Ready at last." }] }) });
    await open("WhatsApp call");
    expect(within(detail()).getByText(/Transcribing/)).toBeInTheDocument();
    respond("library_refresh", [done("b", "WhatsApp call")]);
    await advance(5_000);
    await flushAsync();
    expect(within(detail()).getByText("Ready at last.")).toBeInTheDocument();
  });

  it("starts when an upload shows up and pauses while the window is off screen", async () => {
    useClock();
    await renderLibrary();
    respond("library_list", [done("a", "Zoom with Acme"), makeItem("n", { title: "Imported note", updated_at: UPDATED, status: "uploading" })]);
    await emitEvent("library:updated", 1);
    await flushAsync();
    expect(within(list()).getByText("Imported note")).toBeInTheDocument();
    await emitEvent("main:visibility", false);
    await advance(20_000);
    expect(callsOf("library_refresh")).toHaveLength(1);
    await emitEvent("main:visibility", true);
    await advance(5_000);
    expect(callsOf("library_refresh")).toHaveLength(2);
  });
});
