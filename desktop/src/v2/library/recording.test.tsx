// The v2 recording page against classic (PLAN_V2_PARITY 4; docs/v2/audit/parity.csv DT-REC): its ⋮ (the Library's
// one menu plus Change language, Save audio and a view-aware Export ▸), the Copy and Export menus under the player,
// Show as, Change language & re-transcribe, the player's ±10 s, speeds, Skip silence, Follow, a line that plays from
// its moment, Space, and Esc back to the Library.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem, TranscriptViewsState } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
const BILAL: LibraryItem = makeItem("bilal", {
  title: "Bilal Qureshi", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: new Date(2026, 10, 17, 11, 42).toISOString(),
  duration_ms: 3_730_000, language: "ur", screen_count: 0,
  transcript: { final_status: "completed", final_transcript_id: "tr-bilal", live_available: false, preview: "Assalam o alaikum, kya haal hai" },
});
const VIEWS: TranscriptViewsState = {
  status: "ready", available: ["roman", "urdu", "english"], model: "m", made_at: null, error: null,
  roman: ["Hello, yeh Sam hai.", "Hi Sam, shukriya.", "Invoice dekh lete hain.", "Theek hai."],
  english: ["Hello, this is Sam.", "Hi Sam, thanks.", "Let us look at the invoice.", "That works."],
  urdu: ["ہیلو، یہ سام ہے۔", null, null, null],
  kept: { roman: [3] },
};

const menuItem = (name: string | RegExp) => screen.getByRole("menuitem", { name });
const play = vi.fn(() => Promise.resolve());
const pause = vi.fn();

async function open(transcript = makeTranscript({ id: "tr-bilal", views: { available: ["roman", "urdu", "english"], status: "none" } })) {
  respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: { Client: "Bilal" } });
  respond("transcript_detail", transcript);
  render(<LibraryV2 />);
  await act(flushAsync);
  fireEvent.click(screen.getByRole("button", { name: "Bilal Qureshi" }));
  await act(flushAsync);
}

describe("the recording page", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("library_list", [BILAL]);
    respond("library_refresh", [BILAL]);
    Object.assign(HTMLMediaElement.prototype, { play, pause });
    play.mockClear();
  });

  it("its ⋮: Rename, Move, Label, Pin, Keep, Change language, Export ▸, Save audio, Copy for AI, WhatsApp, then Delete last", async () => {
    await open();
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    const menu = screen.getByRole("menu", { name: "Recording actions" });
    expect(within(menu).getAllByRole("menuitem").map((m) => m.textContent)).toEqual([
      "Rename…", "Move to project…", "Label…", "Pin", "Keep forever", "Change language and re-transcribe…",
      "Export", "Save audio", "Copy for AI", "Send to WhatsApp…", "DeleteDel",
    ]);
    fireEvent.click(menuItem("Export"));
    expect(within(screen.getByRole("menu", { name: "Export" })).getAllByRole("menuitem").map((m) => m.textContent)).toEqual([
      "Word (.docx)", "PDF (print)", "Text (.txt)", "Markdown (.md)", "Subtitles (.srt)", "Web subtitles (.vtt)", "JSON (.json)",
    ]);
  });

  it("⌘P (Ctrl+P on Windows) prints the open transcript: its print page; never from a text field, never Ctrl+P on a Mac (FIX-10)", async () => {
    respond("transcript_views", VIEWS);
    respond("export_transcript", null);
    const platform = Object.getOwnPropertyDescriptor(Navigator.prototype, "platform");
    Object.defineProperty(navigator, "platform", { value: "MacIntel", configurable: true });
    try {
      await open();
      const before = callsOf("export_transcript").length;
      // A Mac's Ctrl+P moves up a line: not printing.
      expect(fireEvent.keyDown(document.body, { key: "p", ctrlKey: true })).toBe(true);
      expect(callsOf("export_transcript")).toHaveLength(before);
      // From a field: the field's.
      const field = document.createElement("input");
      document.body.append(field);
      fireEvent.keyDown(field, { key: "p", metaKey: true });
      field.remove();
      expect(callsOf("export_transcript")).toHaveLength(before);
      expect(fireEvent.keyDown(document.body, { key: "p", metaKey: true })).toBe(false);
      await act(flushAsync);
      expect(callsOf("export_transcript").at(-1)).toMatchObject({ id: "tr-bilal", format: "html" });
      // Held down: printed once.
      const printed = callsOf("export_transcript").length;
      expect(fireEvent.keyDown(document.body, { key: "p", metaKey: true, repeat: true })).toBe(false);
      await act(flushAsync);
      expect(callsOf("export_transcript")).toHaveLength(printed);
      // An Urdu keyboard's P key (e.code).
      fireEvent.keyDown(document.body, { key: "پ", code: "KeyP", metaKey: true });
      await act(flushAsync);
      expect(callsOf("export_transcript")).toHaveLength(printed + 1);
    } finally {
      delete (navigator as unknown as { platform?: string }).platform;
      if (platform) Object.defineProperty(Navigator.prototype, "platform", platform);
    }
  });

  it("on Windows Ctrl+P prints the open transcript, and the Windows key with P doesn't (FIX-10)", async () => {
    respond("transcript_views", VIEWS);
    respond("export_transcript", null);
    const platform = Object.getOwnPropertyDescriptor(Navigator.prototype, "platform");
    Object.defineProperty(navigator, "platform", { value: "Win32", configurable: true });
    try {
      await open();
      const before = callsOf("export_transcript").length;
      expect(fireEvent.keyDown(document.body, { key: "p", metaKey: true })).toBe(true);
      await act(flushAsync);
      expect(callsOf("export_transcript")).toHaveLength(before);
      expect(fireEvent.keyDown(document.body, { key: "p", ctrlKey: true })).toBe(false);
      await act(flushAsync);
      expect(callsOf("export_transcript").at(-1)).toMatchObject({ id: "tr-bilal", format: "html" });
    } finally {
      delete (navigator as unknown as { platform?: string }).platform;
      if (platform) Object.defineProperty(Navigator.prototype, "platform", platform);
    }
  });

  it("Export under the player: each format in the view on screen; PDF opens the print page; meeting packs", async () => {
    respond("transcript_views", VIEWS);
    respond("export_transcript", "/Users/me/Downloads/Bilal.docx");
    respond("meeting_pack", "/Users/me/Downloads/Bilal pack.zip");
    // The meeting packs only for a recording with screens.
    const withScreens = { ...BILAL, screen_count: 2 } as LibraryItem;
    respond("library_list", [withScreens]);
    respond("library_refresh", [withScreens]);
    respond("session_screens", null);
    await open();
    const tools = screen.getByRole("toolbar", { name: "Share, copy and export" });
    fireEvent.click(within(tools).getByRole("button", { name: "Export" }));
    fireEvent.click(menuItem("Word (.docx)"));
    await act(flushAsync);
    expect(callsOf("export_transcript").at(-1)).toMatchObject({ id: "tr-bilal", format: "docx", view: null });
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Saved to /Users/me/Downloads/Bilal.docx");

    fireEvent.click(screen.getByRole("radio", { name: "Roman Urdu" }));
    await act(flushAsync);
    fireEvent.click(within(tools).getByRole("button", { name: "Export as Roman Urdu" }));
    fireEvent.click(menuItem("PDF (print)"));
    await act(flushAsync);
    expect(callsOf("export_transcript").at(-1)).toMatchObject({ format: "html", view: "roman" });
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Opened the print page in your browser: choose Save as PDF there");
    fireEvent.click(within(tools).getByRole("button", { name: "Export as Roman Urdu" }));
    fireEvent.click(menuItem("Meeting pack with audio (.zip)"));
    await act(flushAsync);
    expect(callsOf("meeting_pack").at(-1)).toMatchObject({ id: "bilal", format: "zip", view: "roman", includeAudio: true });
  });

  it("Copy: the main part copies the text without timestamps at once; the chevron has Copy with timestamps and Copy for AI", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    await open();
    const tools = screen.getByRole("toolbar", { name: "Share, copy and export" });
    // The main part: no menu, the plain text on the clipboard, "Copied".
    fireEvent.click(within(tools).getByRole("button", { name: "Copy text" }));
    await act(flushAsync);
    expect(screen.queryByRole("menu")).toBeNull();
    expect(writeText).toHaveBeenCalledTimes(1);
    expect((writeText.mock.calls.at(-1) as unknown as [string])[0]).toBe("Me: Hello, this is Sam.\nBilal: Hi Sam, thanks for calling back.\nMe: Let us go over the invoice.\nBilal: That works for us.");
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Copied");
    // The chevron: the menu, and nothing copied by opening it.
    const more = within(tools).getByRole("button", { name: "More copy options" });
    expect(more).toHaveAttribute("aria-haspopup", "menu");
    fireEvent.click(more);
    expect(writeText).toHaveBeenCalledTimes(1);
    expect(within(screen.getByRole("menu", { name: "More copy options" })).getAllByRole("menuitem").map((i) => i.textContent)).toEqual(["Copy with timestamps", "Copy for AI"]);
    fireEvent.click(more);
    const pick = async (name: string) => {
      fireEvent.click(within(tools).getByRole("button", { name: "More copy options" }));
      fireEvent.click(menuItem(name));
      await act(flushAsync);
      return (writeText.mock.calls.at(-1) as unknown as [string])[0];
    };
    expect(await pick("Copy with timestamps")).toMatch(/^\[0:00\] Me: Hello, this is Sam\.\n\[0:12\] Bilal:/);
    const ai = await pick("Copy for AI");
    expect(ai).toMatch(/^# Bilal Qureshi\n17 November 2026, 11:42 am · WhatsApp · 1:02:10\nPeople: Me, Bilal\nLanguage: ur\n\nNote: \[\?word\]/);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Copied for AI");
  });

  it("Show as: Roman Urdu, اردو, English and Original switch the lines; a view is made when first asked; kept lines say so", async () => {
    respond("transcript_views", { ...VIEWS, status: "none" });
    respond("transcript_views_make", { ...VIEWS, status: "making" });
    await open();
    const show = screen.getByRole("radiogroup", { name: "Show as" });
    expect(within(show).getAllByRole("radio").map((r) => r.textContent)).toEqual(["Roman Urdu", "اردو", "English", "Original"]);
    expect(within(show).getByRole("radio", { name: "Original" })).toBeChecked();
    fireEvent.click(within(show).getByRole("radio", { name: "Roman Urdu" }));
    await act(flushAsync);
    expect(callsOf("transcript_views_make")).toEqual([{ id: "tr-bilal" }]);
    // U19: the converting line says it, once; the old "Writing the … view…" caption is gone.
    expect(screen.getAllByText(/Converting to Roman Urdu…/)).toHaveLength(1);
    expect(screen.queryByText(/Writing the Roman Urdu view/)).toBeNull();

    resetTauriMock();
    respond("library_list", [BILAL]);
    respond("transcript_views", VIEWS);
    fireEvent.click(within(show).getByRole("radio", { name: "Original" }));
    fireEvent.click(within(show).getByRole("radio", { name: "English" }));
    await act(flushAsync);
    const transcript = screen.getByRole("list", { name: "Transcript" });
    expect(within(transcript).getByText("Let us look at the invoice.")).toBeInTheDocument();
    expect(screen.getByText("English view. The transcript itself is unchanged.")).toBeInTheDocument();
    fireEvent.click(within(show).getByRole("radio", { name: "Roman Urdu" }));
    expect(within(transcript).getByText("Invoice dekh lete hain.")).toBeInTheDocument();
    expect(within(transcript).getAllByText("· original words")).toHaveLength(1);
    fireEvent.click(within(show).getByRole("radio", { name: "Original" }));
    expect(within(transcript).getByText("Let us go over the invoice.")).toBeInTheDocument();
  });

  it("Change language & re-transcribe, with the English-only warning once", async () => {
    respond("session_retranscribe", {});
    await open();
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    fireEvent.click(menuItem("Change language and re-transcribe…"));
    const dialog = screen.getByRole("dialog", { name: "Change language and re-transcribe" });
    fireEvent.click(within(dialog).getByRole("combobox", { name: /^Language/ }));
    fireEvent.click(screen.getByRole("option", { name: "English only" }));
    fireEvent.click(within(dialog).getByRole("button", { name: "Re-transcribe" }));
    expect(dialog).toHaveAccessibleDescription(/English only would translate the Urdu/);
    expect(callsOf("session_retranscribe")).toEqual([]);
    fireEvent.click(within(dialog).getByRole("button", { name: "Re-transcribe anyway" }));
    await act(flushAsync);
    expect(callsOf("session_retranscribe")).toEqual([{ id: "bilal", language: "en" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Re-transcribing in English. The current transcript stays until the new one is ready.");
  });

  it("the player: back and forward 10 s, speeds 0.5× to 2×, Skip silence and Follow, Space, a line plays from its moment", async () => {
    await open();
    expect(screen.getByText(/0:00 \/ 1:02:10/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Forward 10 seconds" }));
    fireEvent.click(screen.getByRole("button", { name: "Forward 10 seconds" }));
    fireEvent.click(screen.getByRole("button", { name: "Back 10 seconds" }));
    expect(screen.getByText(/0:10 \/ 1:02:10/)).toBeInTheDocument();
    fireEvent.keyDown(document.body, { key: "ArrowRight", shiftKey: true });
    expect(screen.getByText(/0:20 \/ 1:02:10/)).toBeInTheDocument();
    fireEvent.keyDown(document.body, { key: " " });
    expect(play).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole("combobox", { name: /^Playback speed/ }));
    expect(screen.getAllByRole("option").map((o) => o.textContent)).toEqual(["0.5×", "0.75×", "1×", "1.25×", "1.5×", "1.75×", "2×"]);
    fireEvent.keyDown(screen.getByRole("combobox", { name: /^Playback speed/ }), { key: "Escape" });
    expect(screen.getByRole("switch", { name: "Skip silence" })).toHaveAttribute("aria-checked", "false");
    fireEvent.click(screen.getByRole("switch", { name: "Skip silence" }));
    expect(screen.getByRole("switch", { name: "Skip silence" })).toHaveAttribute("aria-checked", "true");
    expect(screen.getByRole("switch", { name: "Follow" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(screen.getByRole("button", { name: "Play from 1:05 · Me" }));
    expect(screen.getByText(/1:05 \/ 1:02:10/)).toBeInTheDocument();
    expect(play).toHaveBeenCalledTimes(2);
  });

  it("no Change language on a recording without audio or without a final transcript (classic's rule)", async () => {
    const silent = { ...BILAL, transcript: { ...BILAL.transcript!, final_status: "failed", final_error_code: "no_audio" } } as LibraryItem;
    respond("library_list", [silent]);
    respond("library_refresh", [silent]);
    await open(makeTranscript({ id: "tr-bilal", status: "failed", segments: [] }));
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    expect(screen.queryByRole("menuitem", { name: "Change language and re-transcribe…" })).toBeNull();
  });

  it("no Change language on a recording that has no final transcript yet (final_status null)", async () => {
    const pending = { ...BILAL, status: "uploaded", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } } as LibraryItem;
    respond("library_list", [pending]);
    respond("library_refresh", [pending]);
    await open();
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    expect(within(screen.getByRole("menu", { name: "Recording actions" })).getAllByRole("menuitem").map((m) => m.textContent)).not.toContain("Change language and re-transcribe…");
    expect(screen.getByRole("menuitem", { name: "Transcribe…" })).toBeInTheDocument();
  });

  it("Skip silence jumps a gap while playing; Follow keeps the playing line in view", async () => {
    const scrolled: Element[] = [];
    Element.prototype.scrollIntoView = function (this: Element) { scrolled.push(this); };
    await open();
    const audio = document.querySelector("audio") as HTMLAudioElement;
    Object.defineProperty(audio, "paused", { configurable: true, get: () => false });
    fireEvent.play(audio);
    // 5 s is in the 4 s to 12.4 s gap: off, playing goes on there.
    audio.currentTime = 5;
    fireEvent.timeUpdate(audio);
    expect(audio.currentTime).toBe(5);
    fireEvent.click(screen.getByRole("switch", { name: "Skip silence" }));
    fireEvent.timeUpdate(audio);
    expect(audio.currentTime).toBeCloseTo(12.2);
    // Follow: the line being played is scrolled into view.
    audio.currentTime = 13;
    fireEvent.timeUpdate(audio);
    expect(scrolled.at(-1)).toHaveTextContent("Hi Sam, thanks for calling back.");
    fireEvent.click(screen.getByRole("switch", { name: "Follow" }));
    const before = scrolled.length;
    audio.currentTime = 66;
    fireEvent.timeUpdate(audio);
    expect(scrolled.length).toBe(before);
  });

  it("without a length from the server, the audio file's own length is used, so seeking and lines past the first second work", async () => {
    const noLength = { ...BILAL, duration_ms: null } as LibraryItem;
    respond("library_list", [noLength]);
    respond("library_refresh", [noLength]);
    await open(makeTranscript({ id: "tr-bilal", segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 2000, text: "Hi." }] }));
    const audio = document.querySelector("audio") as HTMLAudioElement;
    Object.defineProperty(audio, "duration", { configurable: true, get: () => 95 });
    fireEvent.loadedMetadata(audio);
    expect(screen.getByText(/0:00 \/ 1:35/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Forward 10 seconds" }));
    expect(screen.getByText(/0:10 \/ 1:35/)).toBeInTheDocument();
  });

  it("Esc goes back to the Library and gives focus to the recording's row; inside a menu Esc only closes the menu", async () => {
    await open();
    fireEvent.click(screen.getByRole("button", { name: "Recording actions" }));
    fireEvent.keyDown(screen.getByRole("menu", { name: "Recording actions" }), { key: "Escape" });
    expect(screen.getByRole("heading", { level: 1, name: "Bilal Qureshi" })).toBeInTheDocument();
    fireEvent.keyDown(document.body, { key: "Escape" });
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "Recordings" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Bilal Qureshi" })).toHaveFocus();
  });

  it("Send to WhatsApp… under the player opens the sheet with the text of the view on screen", async () => {
    respond("transcript_views", VIEWS);
    respondWith("whatsapp_open", () => false);
    Object.assign(navigator, { clipboard: { writeText: vi.fn(() => Promise.resolve()) } });
    await open();
    fireEvent.click(screen.getByRole("radio", { name: "English" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Send to WhatsApp…" }));
    const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
    fireEvent.click(within(sheet).getByRole("button", { name: "Text only" }));
    await act(flushAsync);
    expect((callsOf("whatsapp_open")[0] as { text: string }).text).toContain("Bilal: Hi Sam, thanks.");
  });
});
