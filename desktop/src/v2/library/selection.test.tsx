// Selection mode and the one recording menu in the v2 Library (PLAN_V2_PARITY 3 and 7; docs/v2/audit/parity.csv
// DT-LIB-075..107): Select, a Cmd/Ctrl- or Shift-click, Cmd/Ctrl+A, the bar's four actions and ⋮, the row ⋮, a
// right-click, Delete with Undo, Export ▸ every format, Copy for AI and Transcribe with its minutes first.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { aiHeader, ZIP_FORMATS } from "./organise";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
const at = (day: number, hour: number, minute = 0) => new Date(2026, 10, day, hour, minute).toISOString();
const done = (preview: string, id: string) => ({ final_status: "completed", final_transcript_id: id, live_available: false, preview });

const ITEMS: LibraryItem[] = [
  makeItem("bilal", { title: "Bilal Qureshi", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: at(17, 11, 42), duration_ms: 1_104_000, transcript: done("Payment terms", "tr-bilal") }),
  makeItem("voice", { title: "Voice note", source_app: "voice_note", started_at: at(17, 9, 18), duration_ms: 134_000, status: "uploaded", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } }),
  makeItem("usman", { title: "Usman Chaudhry", source_app: "phone_call", started_at: at(17, 8, 47), duration_ms: 452_000, pinned: true, transcript: done("Steel prices", "tr-usman") }),
  makeItem("habib", { title: "Habib & Co.", source_app: "zoom", started_at: at(16, 16, 30), duration_ms: 2_282_000, labels: ["Audit"], transcript: done("Sales tax", "tr-habib") }),
];

const rows = () => [...screen.getByRole("list", { name: "Recordings" }).querySelectorAll<HTMLButtonElement>("button[data-row-id]")];
const row = (title: string) => within(screen.getByRole("list", { name: "Recordings" })).getByRole("button", { name: title });
const bar = () => screen.getByRole("toolbar", { name: "Selected recordings" });
const menuItem = (name: string | RegExp) => screen.getByRole("menuitem", { name });

describe("selection mode", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("bin_list", { data: [] });
    respondWith("sessions_bulk", (args) => ((args as { ids: string[] }).ids.length));
  });
  const show = async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
  };

  it("Select starts it: a click ticks a row, the bar counts, Select all and Unselect all, × ends it", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Select" }));
    expect(bar()).toHaveTextContent("Select recordings");
    expect(screen.getByRole("button", { name: "Move to project" })).toBeDisabled();
    fireEvent.click(row("Bilal Qureshi"));
    expect(row("Bilal Qureshi")).toHaveAttribute("aria-pressed", "true");
    expect(row("Voice note")).toHaveAttribute("aria-pressed", "false");
    expect(within(bar()).getByRole("heading")).toHaveTextContent("1 selected");
    fireEvent.click(within(bar()).getByRole("button", { name: "Select all" }));
    expect(within(bar()).getByRole("heading")).toHaveTextContent("4 selected");
    fireEvent.click(within(bar()).getByRole("button", { name: "Unselect all" }));
    expect(within(bar()).getByRole("heading")).toHaveTextContent("Select recordings");
    fireEvent.click(row("Bilal Qureshi"));
    fireEvent.click(row("Bilal Qureshi"));
    expect(row("Bilal Qureshi")).toHaveAttribute("aria-pressed", "false");
    fireEvent.click(screen.getByRole("button", { name: "Cancel selection" }));
    expect(screen.queryByRole("toolbar", { name: "Selected recordings" })).toBeNull();
    expect(row("Bilal Qureshi")).not.toHaveAttribute("aria-pressed");
    // Out of selection mode a click opens the recording again.
    respond("session_detail", { playback_url: null });
    respond("transcript_detail", makeTranscript({ id: "tr-bilal" }));
    fireEvent.click(row("Bilal Qureshi"));
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Bilal Qureshi" })).toBeInTheDocument();
  });

  it("Cmd/Ctrl-click starts it on that row; Shift-click adds the range; Cmd/Ctrl+A picks every row shown; Esc ends it", async () => {
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    expect(within(bar()).getByRole("heading")).toHaveTextContent("1 selected");
    fireEvent.click(row("Usman Chaudhry"), { shiftKey: true });
    expect(rows().map((r) => r.getAttribute("aria-pressed"))).toEqual(["true", "true", "true", "false"]);
    fireEvent.keyDown(document.body, { key: "Escape" });
    expect(screen.queryByRole("toolbar", { name: "Selected recordings" })).toBeNull();
    fireEvent.keyDown(document.body, { key: "a", ctrlKey: true });
    expect(within(bar()).getByRole("heading")).toHaveTextContent("4 selected");
    // Typing in search is left alone.
    fireEvent.keyDown(document.body, { key: "Escape" });
    const search = screen.getByRole("searchbox", { name: "Search recordings" });
    fireEvent.keyDown(search, { key: "a", metaKey: true });
    expect(screen.queryByRole("toolbar", { name: "Selected recordings" })).toBeNull();
  });

  it("J and K move between rows, X ticks the focused one", async () => {
    await show();
    act(() => rows()[0].focus());
    fireEvent.keyDown(rows()[0], { key: "j" });
    expect(row("Voice note")).toHaveFocus();
    fireEvent.keyDown(row("Voice note"), { key: "j" });
    fireEvent.keyDown(row("Usman Chaudhry"), { key: "k" });
    expect(row("Voice note")).toHaveFocus();
    fireEvent.keyDown(row("Voice note"), { key: "x" });
    expect(within(bar()).getByRole("heading")).toHaveTextContent("1 selected");
    expect(row("Voice note")).toHaveAttribute("aria-pressed", "true");
  });

  it("Delete, from the bar or the Delete key, sends the picked ones to Trash at once, and one Undo brings them all back", async () => {
    respondWith("session_restore", () => ({ data: ITEMS[0] }));
    await show();
    fireEvent.keyDown(document.body, { key: "a", metaKey: true });
    fireEvent.click(row("Habib & Co."));
    fireEvent.click(row("Voice note"));
    respond("library_list", [ITEMS[1], ITEMS[3]]);
    fireEvent.click(within(bar()).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal", "usman"], action: "delete", clientId: null, label: null }]);
    expect(screen.queryByRole("toolbar", { name: "Selected recordings" })).toBeNull();
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent("2 recordings moved to Trash");
    respond("library_list", ITEMS);
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "bilal" }, { id: "usman" }]);
    expect(rows()).toHaveLength(4);

    // The Delete key on picked rows does the same.
    fireEvent.click(row("Habib & Co."), { ctrlKey: true });
    fireEvent.click(row("Voice note"), { ctrlKey: true });
    fireEvent.keyDown(row("Voice note"), { key: "Delete" });
    await act(flushAsync);
    expect(callsOf("sessions_bulk").at(-1)).toMatchObject({ ids: ["voice", "habib"], action: "delete" });
  });

  it("the bar's ⋮: Pin, Unpin, Keep forever, Allow auto clean-up and Transcribe, grouped, with Export ▸ every format", async () => {
    respond("sessions_export", "/Users/me/Downloads/EchoLedger transcripts.zip");
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Usman Chaudhry"));
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
    const menu = screen.getByRole("menu", { name: "More actions" });
    expect(within(menu).getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["Pin", "Unpin", "Keep forever", "Transcribe…", "Export"]);
    expect(within(menu).getByText("Organise")).toBeInTheDocument();
    fireEvent.click(menuItem("Pin"));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal", "usman"], action: "pin", clientId: null, label: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Pinned · 2 recordings");

    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
    fireEvent.click(menuItem("Export"));
    const formats = screen.getByRole("menu", { name: "Export" });
    expect(within(formats).getAllByRole("menuitem").map((m) => m.textContent)).toEqual(ZIP_FORMATS.map((f) => f.label));
    expect(ZIP_FORMATS.map((f) => f.format)).toEqual(["docx", "txt", "md", "srt", "vtt", "json"]);
    fireEvent.click(within(formats).getByRole("menuitem", { name: "Subtitles (.srt)" }));
    await act(flushAsync);
    expect(callsOf("sessions_export")).toEqual([{ ids: ["bilal", "usman"], format: "srt", view: undefined }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Saved /Users/me/Downloads/EchoLedger transcripts.zip");
  });

  it("Export ▸ opens from the keyboard: ArrowRight in, ArrowLeft back, Esc closes one level", async () => {
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Habib & Co."));
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
    const menu = screen.getByRole("menu", { name: "More actions" });
    fireEvent.keyDown(menu, { key: "End" });
    expect(menuItem("Export")).toHaveFocus();
    fireEvent.keyDown(menu, { key: "ArrowRight" });
    expect(screen.getByRole("menuitem", { name: "Word (.docx)" })).toHaveFocus();
    fireEvent.keyDown(screen.getByRole("menu", { name: "Export" }), { key: "ArrowLeft" });
    expect(screen.queryByRole("menu", { name: "Export" })).toBeNull();
    expect(menuItem("Export")).toHaveFocus();
    fireEvent.keyDown(menu, { key: "Escape" });
    expect(screen.queryByRole("menu", { name: "More actions" })).toBeNull();
    expect(within(bar()).getByRole("button", { name: "More actions" })).toHaveFocus();
  });

  it("Transcribe says the minutes and the minutes left before it starts, and won't start what the month can't cover", async () => {
    respond("sessions_transcribe_estimate", { minutes: 40, minutes_left: 310, items: [] });
    respond("sessions_transcribe", { started: ["voice"], skipped: [{ id: "bilal", code: "already_transcribed" }], minutes: 3 });
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Voice note"));
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
    fireEvent.click(menuItem("Transcribe…"));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "Transcribe 2 recordings?" });
    expect(dialog).toHaveAccessibleDescription("About 40 minutes of the 310 minutes left this month.");
    expect(callsOf("sessions_transcribe")).toEqual([]);
    fireEvent.click(within(dialog).getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(callsOf("sessions_transcribe")).toEqual([{ ids: ["bilal", "voice"] }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Transcribing 1 recording · 1 skipped");

    respond("sessions_transcribe_estimate", { minutes: 400, minutes_left: 310, items: [] });
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
    fireEvent.click(menuItem("Transcribe…"));
    await act(flushAsync);
    const over = screen.getByRole("dialog", { name: "Transcribe 2 recordings?" });
    expect(over).toHaveAccessibleDescription(/more than is left, so nothing will start/);
    expect(within(over).getByRole("button", { name: "Transcribe" })).toBeDisabled();
  });

  it("Move to project and Label from the bar", async () => {
    respond("list_clients", { data: [{ id: "c1", name: "Qureshi Textiles" }] });
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Habib & Co."));
    fireEvent.click(within(bar()).getByRole("button", { name: "Move to project" }));
    await act(flushAsync);
    const move = screen.getByRole("dialog", { name: "Move 2 recordings" });
    fireEvent.click(within(move).getByRole("combobox", { name: /^Move to/ }));
    fireEvent.click(screen.getByRole("option", { name: "Qureshi Textiles" }));
    fireEvent.click(within(move).getByRole("button", { name: "Move" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal", "habib"], action: "move", clientId: "c1", label: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Moved 2 recordings to Qureshi Textiles");

    fireEvent.click(within(bar()).getByRole("button", { name: "Label" }));
    const label = screen.getByRole("dialog", { name: "Label 2 recordings" });
    expect(label).toHaveTextContent("In use: Audit");
    fireEvent.change(within(label).getByRole("combobox", { name: "Label" }), { target: { value: "Q4" } });
    fireEvent.click(within(label).getByRole("button", { name: "Add label" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk").at(-1)).toEqual({ ids: ["bilal", "habib"], action: "label", clientId: null, label: "Q4" });
  });

  it("Share or export: every format and Copy for AI, with a short header per recording", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respondWith("session_detail", () => ({ playback_url: null, speaker_names: { S1: "Bilal" } }));
    respondWith("transcript_detail", (args) => makeTranscript({ id: (args as { id: string }).id }));
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Voice note"));
    fireEvent.click(within(bar()).getByRole("button", { name: "Share or export" }));
    const menu = screen.getByRole("menu", { name: "Share or export" });
    expect(within(menu).getAllByRole("menuitem").map((m) => m.textContent)).toEqual([...ZIP_FORMATS.map((f) => f.label), "Copy for AI"]);
    fireEvent.click(menuItem("Copy for AI"));
    await act(flushAsync);
    const text = (writeText.mock.calls[0] as unknown as [string])[0];
    expect(text).toMatch(/^Note: \[\?word\]/);
    expect(text).toContain("# Bilal Qureshi\n17 November 2026, 11:42 am · WhatsApp · 18:24");
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Copied 1 for AI · 1 without a transcript left out");
  });
});

describe("the row ⋮ and the right-click menu", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respondWith("sessions_bulk", (args) => ((args as { ids: string[] }).ids.length));
  });
  const show = async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
  };

  it("a row's ⋮: Open, Rename, Move, Label, Pin or Unpin, Keep, Export, Copy for AI, WhatsApp, then Delete last and red", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Usman Chaudhry" }));
    const menu = screen.getByRole("menu", { name: "More for Usman Chaudhry" });
    expect(within(menu).getAllByRole("menuitem").map((m) => m.textContent)).toEqual([
      "Open", "Rename…", "Move to project…", "Label…", "Unpin", "Keep forever", "Export", "Copy for AI", "Send to WhatsApp…", "DeleteDel",
    ]);
    expect([...menu.querySelectorAll(".el-menu__group")].map((g) => g.textContent)).toEqual(["Organise", "Export", "Danger"]);
    expect(menuItem("Delete")).toHaveClass("el-menu__option--danger");
    expect(menuItem("Delete")).toHaveAttribute("aria-keyshortcuts", "Delete");
    fireEvent.click(menuItem("Unpin"));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["usman"], action: "unpin", clientId: null, label: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Unpinned");
    // An untranscribed recording offers Transcribe; a transcribed one doesn't.
    fireEvent.click(screen.getByRole("button", { name: "More for Voice note" }));
    expect(menuItem("Transcribe…")).toBeInTheDocument();
  });

  it("Rename: a new title, or empty for the automatic one", async () => {
    respondWith("session_organise", (args) => ({ data: { ...ITEMS[0], title: (args as { patch: { title: string | null } }).patch.title ?? "Payment terms call" } }));
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Bilal Qureshi" }));
    fireEvent.click(menuItem("Rename…"));
    const dialog = screen.getByRole("dialog", { name: "Rename" });
    const field = within(dialog).getByRole("textbox", { name: "Title" });
    expect(field).toHaveFocus();
    fireEvent.change(field, { target: { value: "Bilal · March shipment" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("session_organise")).toEqual([{ id: "bilal", patch: { title: "Bilal · March shipment" } }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Renamed");
  });

  it("Send to WhatsApp is classic's sheet: Text only copies the text and says when WhatsApp got only its start", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a" });
    respond("transcript_detail", makeTranscript({ id: "tr-bilal" }));
    respond("whatsapp_open", true);
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Bilal Qureshi" }));
    fireEvent.click(menuItem("Send to WhatsApp…"));
    await act(flushAsync);
    const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
    expect(within(sheet).getAllByRole("button").map((b) => b.textContent)).toEqual(["", "Text only", "Text and voice", "Link (works for 7 days)", "Cancel"]);
    fireEvent.click(within(sheet).getByRole("button", { name: "Text only" }));
    await act(flushAsync);
    const sent = (callsOf("whatsapp_open")[0] as { text: string }).text;
    expect(sent.length).toBeGreaterThan(0);
    expect(writeText).toHaveBeenCalledWith(sent);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("WhatsApp opens with the start of the text. The whole text is copied: paste it there.");
    expect(screen.queryByRole("dialog", { name: "Send to WhatsApp" })).toBeNull();
  });

  it("Send to WhatsApp without a transcript offers only the 7-day link, which is made, copied and sent", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("session_detail", { playback_url: null });
    respond("share_create", { url: "https://ledger.example.com/s/abc", id: "sh1" });
    respond("whatsapp_open", false);
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Voice note" }));
    fireEvent.click(menuItem("Send to WhatsApp…"));
    await act(flushAsync);
    const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
    expect(sheet).toHaveAccessibleDescription("There is no transcript yet: only the link can be sent.");
    expect(within(sheet).getByRole("button", { name: "Text only" })).toBeDisabled();
    fireEvent.click(within(sheet).getByRole("button", { name: "Link (works for 7 days)" }));
    await act(flushAsync);
    expect(writeText).toHaveBeenCalledWith("https://ledger.example.com/s/abc");
    expect(callsOf("whatsapp_open")).toEqual([{ text: "Voice note\nhttps://ledger.example.com/s/abc" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Link made (it works for 7 days) and copied");
  });

  it("a right-click opens the same menu at the pointer; on a picked row it acts on every picked one", async () => {
    await show();
    fireEvent.contextMenu(row("Habib & Co."), { clientX: 120, clientY: 300 });
    const menu = screen.getByRole("menu", { name: "Recording actions" });
    expect(menu).toHaveStyle({ left: "120px", top: "300px" });
    expect(within(menu).getAllByRole("menuitem")[0]).toHaveFocus();
    fireEvent.keyDown(menu, { key: "Escape" });
    expect(screen.queryByRole("menu")).toBeNull();

    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Voice note"));
    fireEvent.contextMenu(row("Voice note"), { clientX: 10, clientY: 10 });
    const many = screen.getByRole("menu", { name: "Actions for 2 recordings" });
    fireEvent.click(within(many).getByRole("menuitem", { name: /^Delete 2/ }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal", "voice"], action: "delete", clientId: null, label: null }]);
  });

  it("Shift+F10 on a focused row opens its menu from the keyboard", async () => {
    await show();
    act(() => row("Habib & Co.").focus());
    fireEvent.keyDown(row("Habib & Co."), { key: "F10", shiftKey: true });
    expect(screen.getByRole("menu", { name: "Recording actions" })).toBeInTheDocument();
  });

  it("the Delete key on a focused row sends it to Trash; Undo brings it back and focus returns to it; plain Backspace does nothing", async () => {
    respond("delete_session", null);
    respondWith("session_restore", () => ({ data: ITEMS[3] }));
    await show();
    act(() => row("Habib & Co.").focus());
    fireEvent.keyDown(row("Habib & Co."), { key: "Backspace" });
    await act(flushAsync);
    expect(callsOf("delete_session")).toEqual([]);
    fireEvent.keyDown(row("Habib & Co."), { key: "Delete" });
    await act(flushAsync);
    expect(callsOf("delete_session")).toEqual([{ id: "habib" }]);
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent('"Habib & Co." moved to Trash');
    act(() => within(toast).getByRole("button", { name: "Undo" }).focus());
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "habib" }]);
    expect(row("Habib & Co.")).toHaveFocus();
  });
});

describe("menus stay inside the window", () => {
  const box = (top: number, height: number, left = 900, width = 220) =>
    ({ top, bottom: top + height, left, right: left + width, width, height, x: left, y: top, toJSON: () => ({}) }) as DOMRect;
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    Object.assign(window, { innerWidth: 1280, innerHeight: 800 });
  });

  it("a row ⋮ near the bottom opens upwards; a sub-menu that would run off the bottom moves up; one off the end side flips", async () => {
    const spy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      if (this.classList.contains("el-menu--sub")) return box(700, 240, 1200);
      if (this.getAttribute("role") === "menu") return box(760, 420);
      if (this.classList.contains("el-menu-anchor")) return box(724, 32);
      return box(0, 0);
    });
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "More for Habib & Co." }));
    const menu = screen.getByRole("menu", { name: "More for Habib & Co." });
    expect(menu).toHaveAttribute("data-up", "true");
    fireEvent.click(menuItem("Export"));
    const sub = screen.getByRole("menu", { name: "Export" });
    expect(sub).toHaveAttribute("data-flip", "true");
    expect(sub.style.transform).toBe("translateY(-148px)");
    spy.mockRestore();
  });

  it("in a short window a long ⋮ scrolls inside its room and Export ▸ opens in place under its item", async () => {
    Object.assign(window, { innerHeight: 300 });
    const spy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      if (this.getAttribute("role") === "menu" && !this.classList.contains("el-menu--sub")) return box(120, 420);
      if (this.classList.contains("el-menu-anchor")) return box(84, 32);
      return box(0, 0);
    });
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "More for Habib & Co." }));
    const menu = screen.getByRole("menu", { name: "More for Habib & Co." });
    expect(menu.style.maxBlockSize).toBe("172px");
    expect(menu.style.overflowY).toBe("auto");
    fireEvent.mouseEnter(menuItem("Export"));
    expect(screen.queryByRole("menu", { name: "Export" })).toBeNull();
    fireEvent.click(menuItem("Export"));
    expect(screen.getByRole("menu", { name: "Export" })).toHaveClass("el-menu--inline");
    spy.mockRestore();
  });

  it("a sub-menu is placed once, where it opens: a later render never moves it back (no flicker)", async () => {
    let calls = 0;
    const spy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      if (this.classList.contains("el-menu--sub")) {
        calls++;
        return calls === 1 ? box(100, 200, 1200) : box(100, 200, 700);
      }
      return box(0, 0);
    });
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "More for Habib & Co." }));
    fireEvent.click(menuItem("Export"));
    const sub = screen.getByRole("menu", { name: "Export" });
    fireEvent.mouseEnter(within(sub).getAllByRole("menuitem")[2]);
    fireEvent.mouseEnter(within(sub).getAllByRole("menuitem")[3]);
    expect(sub).toHaveAttribute("data-flip", "true");
    expect(calls).toBe(1);
    spy.mockRestore();
  });

  it("a right-click menu taller than the window scrolls inside it", async () => {
    Object.assign(window, { innerHeight: 300 });
    const spy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      return this.classList.contains("el-menu--context") ? box(0, 420, 0, 240) : box(0, 0);
    });
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.contextMenu(row("Habib & Co."), { clientX: 20, clientY: 20 });
    const menu = screen.getByRole("menu", { name: "Recording actions" });
    expect(menu.style.maxBlockSize).toBe("284px");
    expect(menu.style.overflowY).toBe("auto");
    spy.mockRestore();
  });

  it("a right-click near the window's corner keeps the whole menu in view, in right-to-left too", async () => {
    const spy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      return this.classList.contains("el-menu--context") ? box(0, 400, 0, 240) : box(0, 0);
    });
    document.documentElement.dir = "rtl";
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.contextMenu(row("Habib & Co."), { clientX: 1270, clientY: 790 });
    expect(screen.getByRole("menu", { name: "Recording actions" })).toHaveStyle({ left: "1040px", top: "400px" });
    document.documentElement.dir = "ltr";
    spy.mockRestore();
  });

  it("the context menu's place is its left and top alone (inset: auto), so right-to-left pages can't stretch it", async () => {
    const { readFileSync } = await import("node:fs");
    const css = readFileSync(`${process.cwd()}/src/v2/styles/v2.css`, "utf8");
    expect(css).toMatch(/\.el-menu--context \{ position: fixed; inset: auto;/);
  });
});

describe("Trash, Undo and the limits", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("trash_keep_hours", 36);
    respondWith("sessions_bulk", (args) => ((args as { ids: string[] }).ids.length));
  });
  const show = async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
  };

  it("Undo restores one after another and reloads once; a failed one is counted and said, the rest come back", async () => {
    respondWith("session_restore", (args) => {
      if ((args as { id: string }).id === "usman") throw new Error("Already erased");
      return { data: ITEMS[0] };
    });
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Usman Chaudhry"));
    fireEvent.click(within(bar()).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent("2 recordings moved to Trash · kept there for 36 hours");
    const reloads = callsOf("library_list").length;
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "bilal" }, { id: "usman" }]);
    expect(callsOf("library_list").length).toBe(reloads + 1);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("1 back · 1 still in Trash: Already erased");
  });

  it("when the server moves only some, the list is read again, it says N of M, and Undo brings back only those", async () => {
    respond("sessions_bulk", 1);
    respondWith("session_restore", () => ({ data: ITEMS[0] }));
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Usman Chaudhry"));
    respond("library_list", ITEMS.filter((i) => i.id !== "bilal"));
    fireEvent.click(within(bar()).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(rows().map((r) => r.dataset.rowId)).toEqual(["voice", "usman", "habib"]);
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent("1 of 2 moved to Trash · kept there for 36 hours");
    respond("library_list", ITEMS);
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "bilal" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("1 recording back");
  });

  it("deleting every recording shown asks first; Cancel leaves them", async () => {
    await show();
    fireEvent.keyDown(document.body, { key: "a", metaKey: true });
    fireEvent.click(within(bar()).getByRole("button", { name: "Delete" }));
    const ask = screen.getByRole("alertdialog", { name: "Move 4 recordings to Trash?" });
    expect(ask).toHaveAccessibleDescription(/stay in Trash for 36 hours/);
    fireEvent.click(within(ask).getByRole("button", { name: "Cancel" }));
    expect(callsOf("sessions_bulk")).toEqual([]);
    fireEvent.click(within(bar()).getByRole("button", { name: "Delete" }));
    fireEvent.click(within(screen.getByRole("alertdialog")).getByRole("button", { name: "Move to Trash" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toHaveLength(1);
  });

  it("says so plainly when the server moved none", async () => {
    respond("sessions_bulk", 0);
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Usman Chaudhry"));
    fireEvent.click(within(bar()).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Nothing was moved to Trash.");
  });

  it("more than 200 picked: the actions wait and the bar says why; Transcribe says its 50", async () => {
    const many = Array.from({ length: 201 }, (_, i) => makeItem(`r${i}`, { title: `Call ${i}`, started_at: at(17, 8), status: "uploaded", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } }));
    respond("library_list", many);
    respond("library_refresh", many);
    await show();
    fireEvent.keyDown(document.body, { key: "a", metaKey: true });
    expect(bar()).toHaveTextContent("Up to 200 at a time: unselect 1");
    expect(within(bar()).getByRole("button", { name: "Delete" })).toBeDisabled();
    expect(within(bar()).getByRole("button", { name: "Move to project" })).toBeDisabled();
    fireEvent.click(within(bar()).getByRole("button", { name: "Unselect all" }));
    for (const r of rows().slice(0, 51)) fireEvent.click(r);
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
    expect(menuItem("Transcribe (50 at a time)")).toHaveAttribute("aria-disabled", "true");
    fireEvent.keyDown(document.body, { key: "Escape" });
    // Over 200: the Delete key and a right-click on a picked row say the limit and do nothing else.
    fireEvent.keyDown(document.body, { key: "a", metaKey: true });
    fireEvent.keyDown(rows()[0], { key: "Delete" });
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Up to 200 at a time: unselect 1");
    fireEvent.contextMenu(rows()[0], { clientX: 10, clientY: 10 });
    const menu = screen.getByRole("menu", { name: "Actions for 201 recordings" });
    expect(within(menu).getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["Up to 200 at a time: unselect 1"]);
    expect(within(menu).getByRole("menuitem")).toHaveAttribute("aria-disabled", "true");
  }, 30_000);
});

describe("Copy for AI's header", () => {
  it("title, then date · source · length, the people and the language", () => {
    const item = makeItem("x", { title: "Kick-off", source_app: "zoom", source_app_label: "Zoom", started_at: at(16, 16, 30), duration_ms: 60_000, language_chip: "UR+EN" });
    expect(aiHeader(item, ["Me", "Habib"])).toBe("# Kick-off\n16 November 2026, 4:30 pm · Zoom · 1:00\nPeople: Me, Habib\nLanguage: UR+EN");
  });
});
