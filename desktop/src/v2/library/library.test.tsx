import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { BinItem, LibraryItem } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { BIN_HOURS, binHours, binSentence, dayGroups, isUntranscribed, rowModel, viewCounts, viewItems } from "./model";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
const at = (day: number, hour: number, minute = 0) => new Date(2026, 10, day, hour, minute).toISOString();

const ITEMS: LibraryItem[] = [
  makeItem("bilal", {
    title: "Bilal Qureshi · Qureshi Textiles", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: at(17, 11, 42), duration_ms: 1_104_000,
    transcript: { final_status: "completed", final_transcript_id: "tr-bilal", live_available: false, preview: "Payment terms for the March shipment" },
  }),
  makeItem("voice", {
    title: "Voice note", source_app: "voice_note", source_app_label: "Voice note", started_at: at(17, 9, 18), duration_ms: 134_000, status: "uploaded",
    transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null },
  }),
  makeItem("usman", {
    title: "Usman Chaudhry · Chaudhry Steel", source_app: "phone_call", source_app_label: "Phone call", started_at: at(17, 8, 47), duration_ms: 452_000, status: "failed",
    transcript: { final_status: "failed", final_transcript_id: null, live_available: false, preview: null },
  }),
  makeItem("habib", {
    title: "Audit kick-off with Habib & Co.", source_app: "zoom", source_app_label: "Zoom", started_at: at(16, 16, 30), duration_ms: 2_282_000,
    transcript: { final_status: "completed", final_transcript_id: "tr-habib", live_available: false, preview: "Quarterly sales tax return" },
  }),
  makeItem("gone", { title: "Deleted call", deleted: true, started_at: at(16, 10) }),
];

const BIN: BinItem[] = [
  { id: "old", title: "Sadia Hassan · Zamzam Travels", client: null, started_at: at(10, 9, 40), duration_ms: 348_000, deleted_at: at(16, 12), erase_at: at(18, 12) },
];

describe("the Library's rules", () => {
  it("saved views: All, Today and Untranscribed, searched, newest first, without deleted ones", () => {
    expect(viewItems(ITEMS, "all", "", NOW).map((i) => i.id)).toEqual(["bilal", "voice", "usman", "habib"]);
    expect(viewItems(ITEMS, "today", "", NOW).map((i) => i.id)).toEqual(["bilal", "voice", "usman"]);
    expect(viewItems(ITEMS, "untranscribed", "", NOW).map((i) => i.id)).toEqual(["voice", "usman"]);
    expect(viewItems(ITEMS, "all", "march shipment", NOW).map((i) => i.id)).toEqual(["bilal"]);
    expect(viewCounts(ITEMS, NOW)).toEqual({ all: 4, today: 3, untranscribed: 2 });
    expect(isUntranscribed(ITEMS[0])).toBe(false);
  });

  it("groups by day", () => {
    expect(dayGroups(viewItems(ITEMS, "all", "", NOW), NOW).map((g) => [g.label, g.items.length])).toEqual([["Today", 3], ["Yesterday", 1]]);
  });

  it("a row: who, the first words or the state, the time today or the date before, and the length", () => {
    expect(rowModel(ITEMS[0], NOW)).toMatchObject({ title: "Bilal Qureshi · Qureshi Textiles", meta: "Payment terms for the March shipment", badge: null, when: "11:42 am", duration: "18:24", app: "whatsapp" });
    expect(rowModel(ITEMS[1], NOW)).toMatchObject({ meta: "Voice note", badge: { tone: "warning", text: "Untranscribed" } });
    expect(rowModel(ITEMS[2], NOW)).toMatchObject({ meta: "Phone call · Transcription failed", badge: { tone: "danger", text: "Failed" } });
    expect(rowModel(ITEMS[3], NOW).when).toBe("16 Nov");
    const transcribing = makeItem("t", { status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null } });
    expect(rowModel(transcribing, NOW)).toMatchObject({ meta: "Zoom · Transcribing…", badge: null });
  });
});

describe("Trash's length", () => {
  it("is the server's: erase_at less deleted_at, else 48 hours, in the classic Library's words", () => {
    expect(BIN_HOURS).toBe(48);
    expect(binHours([])).toBe(48);
    expect(binHours(BIN)).toBe(48);
    expect(binHours([{ deleted_at: at(16, 12), erase_at: at(17, 12) }])).toBe(24);
    expect(binSentence(48)).toBe("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.");
  });
});

describe("LibraryV2", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: BIN });
  });

  const show = async (props: Parameters<typeof LibraryV2>[0] = {}) => {
    render(<LibraryV2 {...props} />);
    await act(flushAsync);
  };

  it("shows the recordings as rows under day headings, from the core's list", async () => {
    await show();
    const list = screen.getByRole("list", { name: "Recordings" });
    const row = within(list).getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" });
    expect(row).toHaveAccessibleDescription(/Payment terms for the March shipment.*11:42 am.*18:24/);
    expect(within(list).getByText("Today")).toBeInTheDocument();
    expect(within(list).getByText("Yesterday")).toBeInTheDocument();
    expect(within(list).queryByText("Deleted call")).toBeNull();
    expect(callsOf("library_refresh")).toHaveLength(1);
  });

  it("the saved views Today and Untranscribed, with their counts", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Untranscribed 2" }));
    const list = screen.getByRole("list", { name: "Recordings" });
    expect(within(list).getAllByRole("button").map((b) => b.getAttribute("aria-labelledby") && b.textContent)).toHaveLength(2);
    fireEvent.click(screen.getByRole("button", { name: "Today 3" }));
    expect(within(screen.getByRole("list", { name: "Recordings" })).getAllByRole("button")).toHaveLength(3);
  });

  it("loading, then an error with its one fix", async () => {
    resetTauriMock();
    fail("library_list", "The server is out of reach");
    fail("library_refresh", "The server is out of reach");
    render(<LibraryV2 />);
    expect(screen.getByRole("status", { busy: true })).toHaveTextContent("Loading recordings");
    await act(flushAsync);
    const alert = screen.getByRole("alert");
    expect(alert).toHaveTextContent("The library didn't load");
    respond("library_list", ITEMS);
    fireEvent.click(within(alert).getByRole("button", { name: "Try again" }));
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
  });

  it("an empty library says what goes there", async () => {
    respond("library_list", []);
    respond("library_refresh", []);
    await show({ onImport: () => undefined });
    expect(screen.getByRole("region", { name: "No recordings yet" })).toBeInTheDocument();
  });

  it("reloads when the core says the library changed", async () => {
    await show();
    respond("library_list", ITEMS.slice(0, 1));
    await act(() => emitEvent("library:updated"));
    await act(flushAsync);
    expect(within(screen.getByRole("list", { name: "Recordings" })).getAllByRole("button")).toHaveLength(1);
  });

  it("opens a recording: the player, the transcript with speakers, and back", async () => {
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: { Client: "Bilal Qureshi" } });
    respond("transcript_detail", makeTranscript({
      id: "tr-bilal",
      segments: [
        { channel: 1, speaker: "Me", start_ms: 0, end_ms: 5000, text: "Assalam o alaikum, Bilal sahib." },
        { channel: 2, speaker: "Client", start_ms: 6000, end_ms: 9000, text: "Walaikum assalam. The fabric is ready." },
      ],
    }));
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Bilal Qureshi · Qureshi Textiles" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Play" })).toBeInTheDocument();
    const transcript = screen.getByRole("list", { name: "Transcript" });
    expect(within(transcript).getByText("Bilal Qureshi")).toBeInTheDocument();
    expect(within(transcript).getByText("Walaikum assalam. The fabric is ready.")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Library" }));
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
  });

  it("an untranscribed recording offers Transcribe", async () => {
    respond("session_detail", { playback_url: null });
    respond("session_retranscribe", {});
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Voice note" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(callsOf("session_retranscribe")).toEqual([{ id: "voice", language: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Transcription started");
  });

  it("Delete moves the recording to Trash and Undo brings it back", async () => {
    respond("session_detail", { playback_url: null });
    respond("transcript_detail", makeTranscript({ id: "tr-bilal" }));
    respond("delete_session", null);
    respondWith("session_restore", () => ({ data: ITEMS[0] }));
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" }));
    await act(flushAsync);
    respond("library_list", ITEMS.slice(1));
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("delete_session")).toEqual([{ id: "bilal" }]);
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent('"Bilal Qureshi · Qureshi Textiles" moved to Trash');
    expect(screen.queryByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" })).toBeNull();
    respond("library_list", ITEMS);
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "bilal" }]);
    expect(screen.getByRole("button", { name: "Bilal Qureshi · Qureshi Textiles" })).toBeInTheDocument();
  });

  it("Trash lists deleted recordings to restore or delete forever after a confirmation", async () => {
    respond("session_restore", { data: ITEMS[0] });
    respond("bin_erase", null);
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Trash" }));
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Trash" })).toBeInTheDocument();
    const list = screen.getByRole("list", { name: "Deleted recordings" });
    expect(within(list).getByText("Sadia Hassan · Zamzam Travels")).toBeInTheDocument();
    expect(screen.getByText("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.")).toBeInTheDocument();
    expect(within(list).getByText(/Deleted 16 Nov · erased in 21 hours/)).toBeInTheDocument();
    fireEvent.click(within(list).getByRole("button", { name: 'Delete "Sadia Hassan · Zamzam Travels" forever' }));
    const dialog = screen.getByRole("alertdialog", { name: "Delete forever?" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Delete forever" }));
    await act(flushAsync);
    expect(callsOf("bin_erase")).toEqual([{ id: "old" }]);
    const empty = screen.getByRole("region", { name: "Trash is empty" });
    expect(empty).toHaveTextContent("Deleted recordings stay here for 48 hours, then their audio and transcripts are erased.");
    expect(document.body).not.toHaveTextContent(/30 days/);
  });

  it("Restore from Trash says so and offers to open it", async () => {
    respond("session_restore", { data: ITEMS[0] });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Trash" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Restore" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "old" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent('"Sadia Hassan · Zamzam Travels" restored');
  });
});
