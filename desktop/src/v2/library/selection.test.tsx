// Selection mode and the one recording menu in the v2 Library (PLAN_V2_PARITY 3 and 7; docs/v2/audit/parity.csv
// DT-LIB-075..107): Select, a Cmd/Ctrl- or Shift-click, Cmd/Ctrl+A, the bar's four actions and ⋮, the row ⋮, a
// right-click, Delete with Undo, Export ▸ every format, Copy for AI and Transcribe with its minutes first.
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { Toast } from "../components/Toast";
import { aiHeader, ZIP_FORMATS } from "./organise";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
const at = (day: number, hour: number, minute = 0) => new Date(2026, 10, day, hour, minute).toISOString();
const done = (preview: string, id: string) => ({ final_status: "completed", final_transcript_id: id, live_available: false, preview });

const ITEMS: LibraryItem[] = [
  makeItem("bilal", { title: "Bilal Qureshi", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: at(17, 11, 42), duration_ms: 1_104_000, transcript: done("Payment terms", "tr-bilal") }),
  makeItem("voice", { title: "Voice note", source_app: "voice_note", started_at: at(17, 9, 18), duration_ms: 134_000, status: "uploaded", transcript: { final_status: null, final_transcript_id: null, live_available: false, preview: null } }),
  makeItem("usman", { title: "Usman Chaudhry", source_app: "phone_call", started_at: at(17, 8, 47), duration_ms: 452_000, transcript: done("Steel prices", "tr-usman") }),
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

  it("a row's checkbox starts it (no Select button): a click then ticks a row, the bar counts, Select all and Unselect all, × ends it", async () => {
    await show();
    expect(screen.queryByRole("button", { name: "Select" })).toBeNull();
    fireEvent.click(screen.getByRole("checkbox", { name: "Select Bilal Qureshi" }));
    expect(row("Bilal Qureshi")).toHaveAttribute("aria-pressed", "true");
    expect(row("Voice note")).toHaveAttribute("aria-pressed", "false");
    expect(within(bar()).getByRole("heading")).toHaveTextContent("1 selected");
    expect(screen.getByRole("checkbox", { name: "Select Bilal Qureshi" })).toBeChecked();
    // While picking, a press on a row ticks it.
    fireEvent.click(row("Voice note"));
    expect(within(bar()).getByRole("heading")).toHaveTextContent("2 selected");
    fireEvent.click(within(bar()).getByRole("checkbox", { name: "Select all" }));
    expect(within(bar()).getByRole("heading")).toHaveTextContent("4 selected");
    fireEvent.click(within(bar()).getByRole("checkbox", { name: "Unselect all" }));
    expect(within(bar()).getByRole("heading")).toHaveTextContent("Select recordings");
    expect(screen.getByRole("button", { name: "Move to project" })).toBeDisabled();
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

  it("the selection bar is a toolbar: ← → move between its controls, Home and End go to its ends", async () => {
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    const all = within(bar()).getByRole("checkbox", { name: /^(Select|Unselect) all$/ });
    act(() => all.focus());
    fireEvent.keyDown(all, { key: "ArrowRight" });
    expect(within(bar()).getByRole("button", { name: "Transcribe" })).toHaveFocus();
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: "End" });
    expect(within(bar()).getByRole("button", { name: "Cancel selection" })).toHaveFocus();
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: "ArrowLeft" });
    expect(within(bar()).getByRole("button", { name: "More actions" })).toHaveFocus();
    fireEvent.keyDown(document.activeElement as HTMLElement, { key: "Home" });
    expect(all).toHaveFocus();
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

  it("↓ and ↑ on a row move between rows as J and K do (FIX-06), and stop at the ends; off a row they are left alone", async () => {
    await show();
    act(() => rows()[0].focus());
    fireEvent.keyDown(rows()[0], { key: "ArrowDown" });
    expect(row("Voice note")).toHaveFocus();
    fireEvent.keyDown(row("Voice note"), { key: "ArrowDown" });
    expect(row("Usman Chaudhry")).toHaveFocus();
    fireEvent.keyDown(row("Usman Chaudhry"), { key: "ArrowUp" });
    expect(row("Voice note")).toHaveFocus();
    act(() => rows()[3].focus());
    fireEvent.keyDown(rows()[3], { key: "ArrowDown" });
    expect(row("Habib & Co.")).toHaveFocus();
    act(() => rows()[0].focus());
    fireEvent.keyDown(rows()[0], { key: "ArrowUp" });
    expect(row("Bilal Qureshi")).toHaveFocus();
    // Shift+↓ isn't a step; in the search field ↓ stays the field's.
    const shifted = new KeyboardEvent("keydown", { key: "ArrowDown", shiftKey: true, bubbles: true, cancelable: true });
    rows()[0].dispatchEvent(shifted);
    expect(row("Bilal Qureshi")).toHaveFocus();
    expect(shifted.defaultPrevented).toBe(false);
    const search = screen.getByRole("searchbox", { name: "Search recordings" });
    act(() => search.focus());
    fireEvent.keyDown(search, { key: "ArrowDown" });
    expect(search).toHaveFocus();
  });

  it("on an Urdu keyboard layout J, K, X, F, / and ⌘A answer by the key's place (D-221)", async () => {
    await show();
    act(() => rows()[0].focus());
    fireEvent.keyDown(rows()[0], { key: "ج", code: "KeyJ" });
    expect(row("Voice note")).toHaveFocus();
    fireEvent.keyDown(row("Voice note"), { key: "ج", code: "KeyJ" });
    fireEvent.keyDown(row("Usman Chaudhry"), { key: "ک", code: "KeyK" });
    expect(row("Voice note")).toHaveFocus();
    fireEvent.keyDown(row("Voice note"), { key: "ش", code: "KeyX" });
    expect(within(bar()).getByRole("heading")).toHaveTextContent("1 selected");
    fireEvent.keyDown(row("Voice note"), { key: "ا", code: "KeyA", metaKey: true });
    expect(within(bar()).getByRole("heading")).toHaveTextContent("4 selected");
    fireEvent.keyDown(document.body, { key: "Escape" });
    fireEvent.keyDown(row("Voice note"), { key: "ف", code: "KeyF" });
    expect(screen.getByTestId("filter-panel")).toBeInTheDocument();
    fireEvent.keyDown(document.body, { key: "Escape" });
    act(() => row("Voice note").focus());
    fireEvent.keyDown(row("Voice note"), { key: "ظ", code: "Slash" });
    expect(screen.getByRole("searchbox", { name: "Search recordings" })).toHaveFocus();
    // Typed into the search, an Urdu letter is a letter.
    const typed = new KeyboardEvent("keydown", { key: "ج", code: "KeyJ", bubbles: true, cancelable: true });
    screen.getByRole("searchbox", { name: "Search recordings" }).dispatchEvent(typed);
    expect(typed.defaultPrevented).toBe(false);
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

  it("row 2 becomes the bar: ☐, the count, Transcribe, Move to project, Label, Export, Delete, ⋮ (Pin, Unpin, Keep forever, grouped) and ×", async () => {
    respond("sessions_export", "/Users/me/Downloads/EchoLedger transcripts.zip");
    const pinned = ITEMS.map((i) => (i.id === "usman" ? { ...i, pinned: true } : i));
    respond("library_list", pinned);
    respond("library_refresh", pinned);
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Usman Chaudhry"));
    expect(within(bar()).getAllByRole("button").map((b) => b.getAttribute("aria-label") ?? b.textContent))
      .toEqual(["Transcribe", "Move to project", "Label", "Share or export", "Delete", "More actions", "Cancel selection"]);
    expect(within(bar()).getByRole("button", { name: "Share or export" })).toHaveTextContent("Export");
    // Transcribe and Export are on the bar, so its ⋮ doesn't repeat them.
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
    const menu = screen.getByRole("menu", { name: "More actions" });
    expect(within(menu).getAllByRole("menuitem").map((m) => m.textContent)).toEqual(["Pin", "Unpin", "Keep forever"]);
    expect(within(menu).getByText("Organise")).toBeInTheDocument();
    fireEvent.click(menuItem("Pin"));
    await act(flushAsync);
    // Ids go in the list's order: Usman is pinned, so first.
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["usman", "bilal"], action: "pin", clientId: null, label: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Pinned · 2 recordings");

    fireEvent.click(within(bar()).getByRole("button", { name: "Share or export" }));
    const formats = screen.getByRole("menu", { name: "Share or export" });
    expect(within(formats).getAllByRole("menuitem").map((m) => m.textContent)).toEqual([...ZIP_FORMATS.map((f) => f.label), "Copy for AI"]);
    expect(ZIP_FORMATS.map((f) => f.format)).toEqual(["docx", "txt", "md", "srt", "vtt", "json"]);
    fireEvent.click(within(formats).getByRole("menuitem", { name: "Subtitles (.srt)" }));
    await act(flushAsync);
    expect(callsOf("sessions_export")).toEqual([{ ids: ["usman", "bilal"], format: "srt", view: undefined }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Saved /Users/me/Downloads/EchoLedger transcripts.zip");
  });

  it("exports the picked recordings in the view the list shows them in: Roman Urdu for Urdu calls (U19)", async () => {
    respond("sessions_export", "/Users/me/Downloads/EchoLedger transcripts.zip");
    const roman = ITEMS.map((i) => (i.id === "bilal" || i.id === "usman" ? { ...i, transcript: { ...i.transcript!, views: { status: "ready" as const, default: "roman" as const, progress: {} } } } : i));
    respond("library_list", roman);
    respond("library_refresh", roman);
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Usman Chaudhry"), { metaKey: true });
    fireEvent.click(within(bar()).getByRole("button", { name: "Share or export" }));
    fireEvent.click(within(screen.getByRole("menu", { name: "Share or export" })).getByRole("menuitem", { name: "Text (.txt)" }));
    await act(flushAsync);
    expect(callsOf("sessions_export").at(-1)).toMatchObject({ format: "txt", view: "roman" });
  });

  it("Export ▸ opens from the keyboard: ArrowRight in, ArrowLeft back, Esc closes one level", async () => {
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Habib & Co." }));
    const menu = screen.getByRole("menu", { name: "More for Habib & Co." });
    // From the foot: Move to trash, then Export (UX-22's order).
    fireEvent.keyDown(menu, { key: "End" });
    fireEvent.keyDown(menu, { key: "ArrowUp" });
    expect(menuItem("Export")).toHaveFocus();
    fireEvent.keyDown(menu, { key: "ArrowRight" });
    expect(screen.getByRole("menuitem", { name: "Word (.docx)" })).toHaveFocus();
    fireEvent.keyDown(screen.getByRole("menu", { name: "Export" }), { key: "ArrowLeft" });
    expect(screen.queryByRole("menu", { name: "Export" })).toBeNull();
    expect(menuItem("Export")).toHaveFocus();
    fireEvent.keyDown(menu, { key: "Escape" });
    expect(screen.queryByRole("menu", { name: "More for Habib & Co." })).toBeNull();
    expect(screen.getByRole("button", { name: "More for Habib & Co." })).toHaveFocus();
  });

  it("Transcribe says the minutes and the minutes left before it starts, and won't start what the month can't cover", async () => {
    respond("sessions_transcribe_estimate", { minutes: 40, minutes_left: 310, items: [] });
    respond("sessions_transcribe", { started: ["voice"], skipped: [{ id: "bilal", code: "already_transcribed" }], minutes: 3 });
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Voice note"));
    fireEvent.click(within(bar()).getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "Transcribe 2 recordings?" });
    expect(dialog).toHaveAccessibleDescription("About 40 minutes of the 310 minutes left this month.");
    expect(callsOf("sessions_transcribe")).toEqual([]);
    fireEvent.click(within(dialog).getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(callsOf("sessions_transcribe")).toEqual([{ ids: ["bilal", "voice"] }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Transcribing 1 recording · 1 skipped");

    respond("sessions_transcribe_estimate", { minutes: 400, minutes_left: 310, items: [] });
    fireEvent.click(within(bar()).getByRole("button", { name: "Transcribe" }));
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
    const move = screen.getByRole("dialog", { name: "Move to project" });
    expect(move).toHaveAccessibleDescription("2 recordings");
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

  it("a row's ⋮ (UX-22, 3b): Open | Pin or Unpin · Important · Unread · Labels › · Move › | Rename… · Export › | Move to trash, last and red", async () => {
    const pinned = ITEMS.map((i) => (i.id === "usman" ? { ...i, pinned: true } : i));
    respond("library_list", pinned);
    respond("library_refresh", pinned);
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Usman Chaudhry" }));
    const menu = screen.getByRole("menu", { name: "More for Usman Chaudhry" });
    const words = () => [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
    expect(words()).toEqual(["Open", "Unpin", "Mark as important", "Mark as unread", "Labels", "Move to project", "Rename…", "Export", "Move to trash"]);
    // Hairlines between 3b's groups, no group headers; every item without a tick has its icon.
    expect(menu.querySelectorAll(".el-menu__separator")).toHaveLength(3);
    expect(menu.querySelectorAll(".el-menu__group")).toHaveLength(0);
    expect([...menu.querySelectorAll('[role="menuitem"]')].filter((m) => !m.querySelector("svg")).map((m) => m.textContent)).toEqual([]);
    expect(menuItem("Move to trash")).toHaveClass("el-menu__option--danger");
    expect(menuItem("Move to trash")).toHaveAttribute("aria-keyshortcuts", "Delete");
    fireEvent.click(menuItem("Unpin"));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["usman"], action: "unpin", clientId: null, label: null }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Unpinned");
    // An Audio only recording offers Transcribe; a transcribed one doesn't.
    fireEvent.click(screen.getByRole("button", { name: "More for Voice note" }));
    expect(menuItem("Transcribe")).toBeInTheDocument();
  });

  it("a row's hover actions, in place of when and how long (UX-06): Transcribe for Audio only, Pin or Unpin, Label, then ⋯; none while picking", async () => {
    respond("sessions_transcribe_estimate", { minutes: 3, minutes_left: 310, items: [] });
    await show();
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
    // Shown on hover or keyboard focus (or while its menu is open), and the end side's when and how long hide for them.
    expect(css).toMatch(/\.el-rec--actions:hover \.el-rec__actions, \.el-rec--actions:focus-within \.el-rec__actions[^{]*\{ display: flex; \}/);
    expect(css).toMatch(/\.el-rec--actions:hover \.el-rec__end, \.el-rec--actions:focus-within \.el-rec__end[^{]*\{ visibility: hidden; \}/);
    const actions = (title: string) => row(title).closest("li")!.querySelector<HTMLElement>(".el-rec__actions")!;
    expect(within(actions("Voice note")).getAllByRole("button").map((b) => b.getAttribute("aria-label")))
      .toEqual(["Transcribe Voice note", "Pin Voice note", "Label Voice note", "More for Voice note"]);
    // A transcribed recording has nothing to transcribe.
    expect(within(actions("Bilal Qureshi")).getAllByRole("button").map((b) => b.getAttribute("aria-label")))
      .toEqual(["Pin Bilal Qureshi", "Label Bilal Qureshi", "More for Bilal Qureshi"]);
    fireEvent.click(within(actions("Bilal Qureshi")).getByRole("button", { name: "Pin Bilal Qureshi" }));
    await act(flushAsync);
    expect(callsOf("sessions_bulk")).toEqual([{ ids: ["bilal"], action: "pin", clientId: null, label: null }]);
    fireEvent.click(within(actions("Voice note")).getByRole("button", { name: "Transcribe Voice note" }));
    await act(flushAsync);
    fireEvent.click(within(screen.getByRole("dialog", { name: /^Transcribe/ })).getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(callsOf("sessions_transcribe")).toEqual([{ ids: ["voice"] }]);
    // While picking, a press on a row ticks it: no actions on the rows.
    fireEvent.click(row("Usman Chaudhry"), { metaKey: true });
    expect(document.querySelectorAll(".el-rec__actions")).toHaveLength(0);
  });

  it("Select, Keep forever, Copy for AI and Send to WhatsApp left the row menu (3b) for their homes: X and the box, the bar's ⋮ and Export", async () => {
    await show();
    fireEvent.contextMenu(row("Habib & Co."), { clientX: 120, clientY: 300 });
    const menu = screen.getByRole("menu", { name: "Recording actions" });
    expect(within(menu).queryByText(/^(Select|Keep forever|Copy for AI|Send to WhatsApp…)$/)).toBeNull();
    fireEvent.keyDown(menu, { key: "Escape" });
    // X (or the row's box) starts selection with exactly that row ticked.
    act(() => row("Habib & Co.").focus());
    fireEvent.keyDown(row("Habib & Co."), { key: "x" });
    expect(within(bar()).getByRole("heading")).toHaveTextContent("1 selected");
    expect(rows().filter((r) => r.getAttribute("aria-pressed") === "true")).toHaveLength(1);
    // The bar's ⋮ keeps Keep forever and Send to WhatsApp…; its Export keeps Copy for AI.
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
    expect(menuItem("Keep forever")).toBeInTheDocument();
    expect(menuItem("Send to WhatsApp…")).toBeInTheDocument();
    fireEvent.keyDown(screen.getByRole("menu", { name: "More actions" }), { key: "Escape" });
    fireEvent.click(within(bar()).getByRole("button", { name: "Share or export" }));
    expect(menuItem("Copy for AI")).toBeInTheDocument();
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

  for (const source of ["person", "auto", "app"] as const) {
    it(`Rename on a title the person didn't choose (${source}) starts empty, the title as its placeholder; empty saves nothing`, async () => {
      // The row reads "Bilal Qureshi" (UX-06: no "Call with"); the box shows the whole title as its placeholder.
      const shown = ITEMS.map((i) => (i.id === "bilal" ? { ...i, title: "Call with Bilal Qureshi", title_source: source } : i));
      respond("library_list", shown);
      respond("library_refresh", shown);
      respondWith("session_organise", (args) => ({ data: { ...shown[0], title: (args as { patch: { title: string | null } }).patch.title, title_source: "own" } }));
      await show();
      fireEvent.click(screen.getByRole("button", { name: "More for Bilal Qureshi" }));
      fireEvent.click(menuItem("Rename…"));
      let dialog = screen.getByRole("dialog", { name: "Rename" });
      let field = within(dialog).getByRole("textbox", { name: "Title" });
      expect(field).toHaveValue("");
      expect(field).toHaveAttribute("placeholder", "Call with Bilal Qureshi");
      expect(field).toHaveAccessibleDescription("Type a title of your own, or leave it empty to keep this one.");
      fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
      await act(flushAsync);
      expect(callsOf("session_organise")).toHaveLength(0);
      expect(screen.queryByRole("dialog", { name: "Rename" })).toBeNull();

      fireEvent.click(screen.getByRole("button", { name: "More for Bilal Qureshi" }));
      fireEvent.click(menuItem("Rename…"));
      dialog = screen.getByRole("dialog", { name: "Rename" });
      field = within(dialog).getByRole("textbox", { name: "Title" });
      fireEvent.change(field, { target: { value: "March shipment" } });
      fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
      await act(flushAsync);
      expect(callsOf("session_organise")).toEqual([{ id: "bilal", patch: { title: "March shipment" } }]);
    });
  }

  it("Rename on the person's own title keeps it in the box; clearing it goes back to the title EchoLedger gives it", async () => {
    const own = ITEMS.map((i) => (i.id === "bilal" ? { ...i, title: "Bilal · March shipment", title_source: "own" as const } : i));
    respond("library_list", own);
    respond("library_refresh", own);
    respondWith("session_organise", () => ({ data: { ...own[0], title: "Call with Bilal Qureshi", title_source: "person" } }));
    await show();
    fireEvent.click(screen.getByRole("button", { name: "More for Bilal · March shipment" }));
    fireEvent.click(menuItem("Rename…"));
    const dialog = screen.getByRole("dialog", { name: "Rename" });
    const field = within(dialog).getByRole("textbox", { name: "Title" });
    expect(field).toHaveValue("Bilal · March shipment");
    expect(field).not.toHaveAttribute("placeholder");
    expect(field).toHaveAccessibleDescription("Leave it empty for the title EchoLedger gives it.");
    fireEvent.change(field, { target: { value: "" } });
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("session_organise")).toEqual([{ id: "bilal", patch: { title: null } }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Back to the title EchoLedger gives it");
  });

  it("Send to WhatsApp is classic's sheet: Text only copies the text and says when WhatsApp got only its start", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a" });
    respond("transcript_detail", makeTranscript({ id: "tr-bilal" }));
    respond("whatsapp_open", true);
    await show();
    // Send to WhatsApp… lives in the selection bar's ⋮ and on the recording page (UX-22: not in the row menu).
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
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

  describe("Send to WhatsApp › Text and voice, through the system's share (F6)", () => {
    const writeText = vi.fn((_: string) => Promise.resolve());
    beforeEach(() => {
      writeText.mockClear();
      Object.assign(navigator, { clipboard: { writeText } });
      respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a" });
      respond("transcript_detail", makeTranscript({ id: "tr-bilal" }));
      respond("whatsapp_open", false);
      respond("save_audio_downloads", "/Users/a/Downloads/x.ogg");
    });
    const open = async () => {
      await show();
      fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
      fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
      fireEvent.click(menuItem("Send to WhatsApp…"));
      await act(flushAsync);
      return screen.getByRole("dialog", { name: "Send to WhatsApp" });
    };
    const voice = async (outcome: unknown) => {
      respond("whatsapp_share_voice", outcome);
      const sheet = await open();
      fireEvent.click(within(sheet).getByRole("button", { name: "Text and voice" }));
      await act(flushAsync);
    };
    const fallback = { kind: "fallback", path: "/Users/a/Downloads/Bilal Qureshi.ogg", fileOnClipboard: true, textCut: false, pasteKeys: "⌘V" };

    it("shared: the dialog closes with the usual notice, and nothing is saved to Downloads by the app any more", async () => {
      await voice({ kind: "shared", toWhatsApp: true });
      const [call] = callsOf("whatsapp_share_voice") as { id: string; text: string; share: boolean }[];
      expect(call.id).toBe("bilal");
      expect(call.text.length).toBeGreaterThan(0);
      expect(call.share).toBe(true);
      expect(screen.queryByRole("dialog", { name: "Send to WhatsApp" })).toBeNull();
      expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Text and voice sent to WhatsApp");
      expect(callsOf("save_audio_downloads")).toEqual([]);
      expect(callsOf("whatsapp_open")).toEqual([]);
    });

    it("shared to another app chosen in the Share window: says shared, not sent to WhatsApp", async () => {
      await voice({ kind: "shared", toWhatsApp: false });
      expect(screen.queryByRole("dialog", { name: "Send to WhatsApp" })).toBeNull();
      const notice = screen.getByRole("status", { name: "Notifications" });
      expect(notice).toHaveTextContent("Text and voice shared");
      expect(notice).not.toHaveTextContent("WhatsApp");
    });

    it("opened: nothing is claimed, and Open WhatsApp with the text instead runs the fallback", async () => {
      await voice({ kind: "opened" });
      const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      expect(sheet).toHaveAccessibleDescription("The share is open: finish sending there.");
      expect(sheet).not.toHaveTextContent(/sent/i);
      expect(screen.queryByRole("status", { name: "Notifications" })?.textContent ?? "").toBe("");
      respond("whatsapp_share_voice", { ...fallback, pasteKeys: "Ctrl+V" });
      fireEvent.click(within(sheet).getByRole("button", { name: "Open WhatsApp with the text instead" }));
      await act(flushAsync);
      const calls = callsOf("whatsapp_share_voice") as { id: string; share: boolean }[];
      expect(calls.map((c) => c.share)).toEqual([true, false]);
      expect(calls[1].id).toBe("bilal");
      expect(screen.getByRole("dialog", { name: "Send to WhatsApp" })).toHaveAccessibleDescription("The voice file is copied: in the chat, press Ctrl+V to attach it.");
    });

    it("opened, then the fallback pressed: Done, Esc and × wait for its answer, which is then shown", async () => {
      await voice({ kind: "opened" });
      const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      let finish: (v: unknown) => void = () => undefined;
      respondWith("whatsapp_share_voice", () => new Promise((resolve) => { finish = resolve; }));
      fireEvent.click(within(sheet).getByRole("button", { name: "Open WhatsApp with the text instead" }));
      await act(flushAsync);
      const doneButton = within(sheet).getByRole("button", { name: "Done" });
      expect(doneButton).toHaveAttribute("aria-disabled", "true");
      fireEvent.click(doneButton);
      fireEvent.keyDown(sheet, { key: "Escape" });
      for (const close of within(sheet).queryAllByRole("button", { name: "Close" })) fireEvent.click(close);
      expect(screen.getByRole("dialog", { name: "Send to WhatsApp" })).toBeInTheDocument();
      await act(async () => finish({ ...fallback, pasteKeys: "Ctrl+V" }));
      const after = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      expect(after).toHaveAccessibleDescription("The voice file is copied: in the chat, press Ctrl+V to attach it.");
      fireEvent.click(within(after).getByRole("button", { name: "Done" }));
      expect(screen.queryByRole("dialog", { name: "Send to WhatsApp" })).toBeNull();
    });

    it("opened, then Done: the dialog closes without a notice", async () => {
      await voice({ kind: "opened" });
      fireEvent.click(within(screen.getByRole("dialog", { name: "Send to WhatsApp" })).getByRole("button", { name: "Done" }));
      expect(screen.queryByRole("dialog", { name: "Send to WhatsApp" })).toBeNull();
      expect(screen.queryByRole("status", { name: "Notifications" })?.textContent ?? "").toBe("");
    });

    it("cancelled: quiet, the dialog stays as it was", async () => {
      await voice({ kind: "cancelled" });
      const [call] = callsOf("whatsapp_share_voice") as { id: string; share: boolean }[];
      expect(call).toMatchObject({ id: "bilal", share: true });
      const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      expect(within(sheet).getByRole("button", { name: "Text and voice" })).not.toHaveAttribute("aria-busy");
      for (const name of ["Text only", "Link (works for 7 days)", "Cancel"]) {
        expect(within(sheet).getByRole("button", { name })).not.toHaveAttribute("aria-disabled");
      }
      expect(screen.queryByRole("status", { name: "Notifications" })?.textContent ?? "").toBe("");
    });

    it("fallback with the voice on the clipboard: the paste keys are the ones the core sent (⌘V or Ctrl+V)", async () => {
      await voice(fallback);
      let sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      expect(sheet).toHaveTextContent("The voice is saved in Downloads too: Bilal Qureshi.ogg");
      expect(sheet.querySelector("p bdi")).toHaveTextContent(/^Bilal Qureshi\.ogg$/);
      expect(sheet).toHaveAccessibleDescription("The voice file is copied: in the chat, press ⌘V to attach it.");
      expect(within(sheet).queryByRole("button", { name: "Copy the whole text" })).toBeNull();
      expect(writeText).not.toHaveBeenCalled();
      cleanup();
      await voice({ ...fallback, pasteKeys: "Ctrl+V" });
      sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      expect(sheet).toHaveAccessibleDescription("The voice file is copied: in the chat, press Ctrl+V to attach it.");
      fireEvent.click(within(sheet).getByRole("button", { name: "Done" }));
      expect(screen.queryByRole("dialog", { name: "Send to WhatsApp" })).toBeNull();
    });

    it("fallback with a cut text and the voice on the clipboard: Copy the whole text copies it and says Copied", async () => {
      await voice({ ...fallback, textCut: true });
      const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      expect(sheet).toHaveTextContent("it was too long to send whole");
      expect(writeText).not.toHaveBeenCalled();
      fireEvent.click(within(sheet).getByRole("button", { name: "Copy the whole text" }));
      await act(flushAsync);
      const [call] = callsOf("whatsapp_share_voice") as { text: string }[];
      expect(writeText).toHaveBeenCalledTimes(1);
      expect(writeText).toHaveBeenCalledWith(call.text);
      expect(within(sheet).getByRole("button", { name: "Copied" })).toBeInTheDocument();
      expect(within(sheet).queryByRole("button", { name: "Copy the whole text" })).toBeNull();
      expect(screen.getByRole("dialog", { name: "Send to WhatsApp" })).toBeInTheDocument();
    });

    it("fallback with a cut text but the voice not on the clipboard: the text is already copied, so no button", async () => {
      await voice({ ...fallback, textCut: true, fileOnClipboard: false });
      const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      expect(within(sheet).queryByRole("button", { name: "Copy the whole text" })).toBeNull();
      expect(writeText).toHaveBeenCalledTimes(1);
    });

    it("fallback without the voice on the clipboard: the text is copied and the saved file is to attach", async () => {
      await voice({ ...fallback, fileOnClipboard: false });
      const sheet = screen.getByRole("dialog", { name: "Send to WhatsApp" });
      expect(sheet).toHaveAccessibleDescription("Saved in Downloads: Bilal Qureshi.ogg");
      expect(sheet.querySelector("bdi")).toHaveTextContent(/^Bilal Qureshi\.ogg$/);
      expect(sheet).toHaveTextContent("Attach the saved voice file in WhatsApp");
      expect(writeText).toHaveBeenCalledWith((callsOf("whatsapp_share_voice")[0] as { text: string }).text);
    });

    it("an error is said as every error, and the dialog stays", async () => {
      fail("whatsapp_share_voice", "The voice could not be saved.");
      const sheet = await open();
      fireEvent.click(within(sheet).getByRole("button", { name: "Text and voice" }));
      await act(flushAsync);
      expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("The voice could not be saved.");
      expect(screen.getByRole("dialog", { name: "Send to WhatsApp" })).toBeInTheDocument();
    });

    it("while it runs only the chosen button shows progress; the others look the same but take no press", async () => {
      let finish: (v: unknown) => void = () => undefined;
      respondWith("whatsapp_share_voice", () => new Promise((resolve) => { finish = resolve; }));
      respond("share_create", { url: "https://ledger.example.com/s/abc", id: "sh1" });
      const sheet = await open();
      fireEvent.click(within(sheet).getByRole("button", { name: "Text and voice" }));
      await act(flushAsync);
      const chosen = within(sheet).getByRole("button", { name: "Sending…" });
      expect(chosen).toHaveAttribute("aria-busy", "true");
      expect(within(sheet).getAllByText("Sending…")).toHaveLength(1);
      expect(within(sheet).queryByText("Opening WhatsApp…")).toBeNull();
      expect(within(sheet).queryByText("Making the link…")).toBeNull();
      for (const name of ["Text only", "Link (works for 7 days)"]) {
        const other = within(sheet).getByRole("button", { name });
        expect(other).not.toBeDisabled();
        expect(other).not.toHaveAttribute("aria-busy");
        expect(other).toHaveAttribute("aria-disabled", "true");
        fireEvent.click(other);
      }
      await act(flushAsync);
      expect(callsOf("whatsapp_open")).toEqual([]);
      expect(callsOf("share_create")).toEqual([]);
      await act(async () => finish({ kind: "cancelled" }));
      expect(within(sheet).getByRole("button", { name: "Text only" })).not.toHaveAttribute("aria-disabled");
    });

    it("while it runs, Cancel and Esc wait for the answer: a fallback is still shown", async () => {
      let finish: (v: unknown) => void = () => undefined;
      respondWith("whatsapp_share_voice", () => new Promise((resolve) => { finish = resolve; }));
      const sheet = await open();
      fireEvent.click(within(sheet).getByRole("button", { name: "Text and voice" }));
      await act(flushAsync);
      const cancel = within(sheet).getByRole("button", { name: "Cancel" });
      expect(cancel).toHaveAttribute("aria-disabled", "true");
      fireEvent.click(cancel);
      fireEvent.keyDown(sheet, { key: "Escape" });
      expect(screen.getByRole("dialog", { name: "Send to WhatsApp" })).toBeInTheDocument();
      await act(async () => finish(fallback));
      expect(screen.getByRole("dialog", { name: "Send to WhatsApp" })).toHaveAccessibleDescription("The voice file is copied: in the chat, press ⌘V to attach it.");
    });
  });

  it("Send to WhatsApp without a transcript offers only the 7-day link, which is made, copied and sent", async () => {
    const writeText = vi.fn(() => Promise.resolve());
    Object.assign(navigator, { clipboard: { writeText } });
    respond("session_detail", { playback_url: null });
    respond("share_create", { url: "https://ledger.example.com/s/abc", id: "sh1" });
    respond("whatsapp_open", false);
    await show();
    fireEvent.click(row("Voice note"), { metaKey: true });
    fireEvent.click(within(bar()).getByRole("button", { name: "More actions" }));
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
    fireEvent.click(within(many).getByRole("menuitem", { name: "Move to trash" }));
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

  it("a sub-menu taller than the window scrolls inside it", async () => {
    Object.assign(window, { innerHeight: 200 });
    const spy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      return this.classList.contains("el-menu--sub") ? box(0, 260, 600) : box(0, 0);
    });
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "More for Habib & Co." }));
    fireEvent.click(menuItem("Export"));
    const sub = screen.getByRole("menu", { name: "Export" });
    expect(sub.style.maxBlockSize).toBe("184px");
    expect(sub.style.overflowY).toBe("auto");
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
    // Focus goes to the next row still in the list, never lost on the page.
    expect(row("Voice note")).toHaveFocus();
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent("1 of 2 moved to Trash · kept there for 36 hours");
    respond("library_list", ITEMS);
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "bilal" }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("1 recording back");
  });

  it("only some moved is a problem: the N of M message is never pushed off by a newer one from elsewhere (FIX-03)", async () => {
    respond("sessions_bulk", 1);
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Usman Chaudhry"));
    respond("library_list", ITEMS.filter((i) => i.id !== "bilal"));
    fireEvent.click(within(bar()).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    const shown = () => [...document.querySelectorAll(".el-toast .el-toast__message")].map((m) => m.textContent);
    expect(shown()).toEqual(["1 of 2 moved to Trash · kept there for 36 hours"]);
    // The app says something meanwhile (a sync): it waits for the problem to close.
    render(<Toast message="Library synced" duration={0} onClose={() => undefined} />);
    expect(shown()).toEqual(["1 of 2 moved to Trash · kept there for 36 hours"]);
  });

  it("when only some moved and the list can't be read, it still says N of M and keeps Undo", async () => {
    respond("sessions_bulk", 1);
    respondWith("session_restore", () => ({ data: ITEMS[0] }));
    await show();
    fireEvent.click(row("Bilal Qureshi"), { metaKey: true });
    fireEvent.click(row("Usman Chaudhry"));
    respondWith("library_list", () => { throw new Error("The server is out of reach"); });
    fireEvent.click(within(bar()).getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent("1 of 2 moved to Trash");
    respond("library_list", ITEMS);
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("session_restore")).toEqual([{ id: "bilal" }, { id: "usman" }]);
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
    fireEvent.click(within(bar()).getByRole("checkbox", { name: "Unselect all" }));
    for (const r of rows().slice(0, 51)) fireEvent.click(r);
    expect(within(bar()).getByRole("button", { name: "Transcribe (50 at a time)" })).toBeDisabled();
    fireEvent.click(rows()[51]);
    fireEvent.click(rows()[51]);
    expect(within(bar()).getByRole("button", { name: "Transcribe (50 at a time)" })).toBeDisabled();
    fireEvent.click(rows()[50]);
    expect(within(bar()).getByRole("button", { name: "Transcribe" })).toBeEnabled();
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
