// The recording page's transcript tools against classic (parity.csv DT-REC-012, 061, 064, 084-092, 114-119, 134, 136):
// Find (Ctrl/Cmd+F, every match, N of M, Enter, Shift+Enter, Esc), editing a line, Find and replace, Speakers,
// History with Restore original, and a project recording's call notes.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEM: LibraryItem = makeItem("s1", {
  title: "Kick-off", duration_ms: 3_730_000, client: { id: "c1", name: "Acme" },
  transcript: { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Hello" },
});
const tools = () => screen.getByRole("toolbar", { name: "Transcript tools" });
const lines = () => [...document.querySelectorAll(".el-segment__text")].map((p) => p.textContent);

async function open() {
  render(<LibraryV2 openRequest={{ id: "s1", seq: 1 }} />);
  await act(flushAsync);
  await act(flushAsync);
}

describe("transcript tools", () => {
  beforeEach(() => {
    resetTauriMock();
    respond("library_list", [ITEM]);
    respond("library_refresh", [ITEM]);
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", speaker_names: {} });
    respond("transcript_detail", makeTranscript({ id: "tr-1" }));
    Object.assign(HTMLMediaElement.prototype, { play: vi.fn(() => Promise.resolve()), pause: vi.fn() });
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("Find: Ctrl/Cmd+F opens it, every match marked, N of M, Enter and Shift+Enter step, Esc closes", async () => {
    await open();
    expect(tools()).toHaveTextContent("Final transcript");
    fireEvent.keyDown(document.body, { key: "f", metaKey: true });
    const find = screen.getByRole("searchbox", { name: "Find in this transcript" });
    expect(find).toHaveFocus();
    fireEvent.change(find, { target: { value: "sam" } });
    expect(document.querySelectorAll("mark.el-find")).toHaveLength(2);
    expect(screen.getByRole("search", { name: "Find in this transcript" })).toHaveTextContent("1 of 2");
    fireEvent.keyDown(find, { key: "Enter" });
    expect(screen.getByRole("search", { name: "Find in this transcript" })).toHaveTextContent("2 of 2");
    expect(document.querySelectorAll("mark.el-find--current")[0].closest("li")).toHaveTextContent("Hi Sam, thanks for calling back.");
    fireEvent.keyDown(find, { key: "Enter", shiftKey: true });
    expect(screen.getByRole("search", { name: "Find in this transcript" })).toHaveTextContent("1 of 2");
    fireEvent.change(find, { target: { value: "zebra" } });
    expect(screen.getByRole("search", { name: "Find in this transcript" })).toHaveTextContent("No matches");
    fireEvent.keyDown(find, { key: "Escape" });
    fireEvent.keyDown(find, { key: "Escape" });
    expect(screen.queryByRole("search", { name: "Find in this transcript" })).toBeNull();
    // The page stays: Esc in Find never closes the recording.
    expect(screen.getByRole("heading", { level: 1, name: "Kick-off" })).toBeInTheDocument();
  });

  it("Edit text: a line is edited in place and saved on Ctrl/Cmd+Enter; Esc puts it back; Done editing", async () => {
    respondWith("transcript_edit", (a) => {
      const { index, text } = a as { index: number; text: string };
      const t = makeTranscript({ id: "tr-1", edited_at: "2026-11-18T10:00:00Z" });
      t.segments = t.segments.map((s, i) => (i === index ? { ...s, text } : s));
      return t;
    });
    await open();
    fireEvent.click(within(tools()).getByRole("button", { name: "Edit text" }));
    fireEvent.click(screen.getByRole("button", { name: "Edit line 1: Hello, this is Sam." }));
    let box = screen.getByRole("textbox", { name: "Edit line" });
    fireEvent.change(box, { target: { value: "Hello, this is Sam from Acme." } });
    fireEvent.keyDown(box, { key: "Enter", metaKey: true });
    await act(flushAsync);
    expect(callsOf("transcript_edit")).toEqual([{ id: "tr-1", index: 0, text: "Hello, this is Sam from Acme." }]);
    expect(lines()[0]).toBe("Hello, this is Sam from Acme.");
    expect(tools()).toHaveTextContent("Final transcript · edited");
    fireEvent.click(screen.getByRole("button", { name: /^Edit line 2:/ }));
    box = screen.getByRole("textbox", { name: "Edit line" });
    fireEvent.change(box, { target: { value: "changed" } });
    fireEvent.keyDown(box, { key: "Escape" });
    expect(callsOf("transcript_edit")).toHaveLength(1);
    fireEvent.click(within(tools()).getByRole("button", { name: "Done editing" }));
    expect(screen.queryByRole("button", { name: /^Edit line/ })).toBeNull();
  });

  it("an edit's answer after another recording opened is dropped; a line being edited is saved when the page closes", async () => {
    const other = makeItem("s2", { title: "Standup", transcript: { final_status: "completed", final_transcript_id: "tr-2", live_available: false, preview: "Hi" } });
    respond("library_list", [ITEM, other]);
    respond("library_refresh", [ITEM, other]);
    let answer: (v: unknown) => void = () => undefined;
    respondWith("transcript_edit", () => new Promise((done) => { answer = done; }));
    const { rerender } = render(<LibraryV2 openRequest={{ id: "s1", seq: 1 }} />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(tools()).getByRole("button", { name: "Edit text" }));
    fireEvent.click(screen.getByRole("button", { name: "Edit line 1: Hello, this is Sam." }));
    fireEvent.change(screen.getByRole("textbox", { name: "Edit line" }), { target: { value: "Hello, this is Sam from Acme." } });
    // Another recording opens while the line is still open: the edit is saved, not lost.
    respond("transcript_detail", makeTranscript({ id: "tr-2", segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 1000, text: "Standup words." }] }));
    rerender(<LibraryV2 openRequest={{ id: "s2", seq: 2 }} />);
    await act(flushAsync);
    expect(callsOf("transcript_edit")).toEqual([{ id: "tr-1", index: 0, text: "Hello, this is Sam from Acme." }]);
    // Its answer arrives late: Standup's lines stay on screen.
    const late = makeTranscript({ id: "tr-1" });
    late.segments = late.segments.map((x, i) => (i === 0 ? { ...x, text: "Hello, this is Sam from Acme." } : x));
    await act(async () => answer(late));
    expect(screen.getByRole("heading", { level: 1, name: "Standup" })).toBeInTheDocument();
    expect(lines()).toEqual(["Standup words."]);
  });

  it("while editing Show as is off and a line starts from the words as heard, never a view's", async () => {
    respond("transcript_detail", makeTranscript({ id: "tr-1", views: { available: ["roman"], status: "ready" } }));
    respond("transcript_views", { status: "ready", available: ["roman"], roman: ["Roman line one", null, null, null], english: null, model: null, made_at: null, error: null });
    await open();
    fireEvent.click(screen.getByRole("radio", { name: "Roman Urdu" }));
    await act(flushAsync);
    expect(lines()[0]).toBe("Roman line one");
    fireEvent.click(within(tools()).getByRole("button", { name: "Edit text" }));
    await act(flushAsync);
    expect(screen.queryByRole("radiogroup", { name: "Show as" })).toBeNull();
    expect(screen.getByText(/Show as is off while you edit/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Edit line 1: Hello, this is Sam." }));
    expect(screen.getByRole("textbox", { name: "Edit line" })).toHaveValue("Hello, this is Sam.");
  });

  it("a Speakers save that fails says why in the dialog", async () => {
    respondWith("speakers_rename", () => { throw new Error("The server is out of reach"); });
    await open();
    fireEvent.click(within(tools()).getByRole("button", { name: "Speakers" }));
    const dialog = screen.getByRole("dialog", { name: "Speakers" });
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(dialog).toHaveAccessibleDescription(/The names weren't saved: .*out of reach/);
  });

  it("Find and replace with Match case and Whole words; says how many", async () => {
    respond("transcript_replace", { replaced: 2, transcript: makeTranscript({ id: "tr-1" }) });
    await open();
    fireEvent.click(within(tools()).getByRole("button", { name: "Find and replace" }));
    const dialog = screen.getByRole("dialog", { name: "Find and replace" });
    fireEvent.change(within(dialog).getByRole("textbox", { name: "Find" }), { target: { value: "Sam" } });
    fireEvent.change(within(dialog).getByRole("textbox", { name: "Replace with" }), { target: { value: "Samir" } });
    fireEvent.click(within(dialog).getByRole("checkbox", { name: "Match case" }));
    fireEvent.click(within(dialog).getByRole("checkbox", { name: "Whole words" }));
    fireEvent.click(within(dialog).getByRole("button", { name: "Replace all" }));
    await act(flushAsync);
    expect(callsOf("transcript_replace")).toEqual([{ id: "tr-1", find: "Sam", replace: "Samir", matchCase: true, wholeWord: true }]);
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Replaced 2 places");
  });

  it("Speakers: names for each label, and for the project's next recordings", async () => {
    respond("speakers_rename", { speaker_names: { Client: "Bilal" } });
    await open();
    fireEvent.click(within(tools()).getByRole("button", { name: "Speakers" }));
    const dialog = screen.getByRole("dialog", { name: "Speakers" });
    fireEvent.change(within(dialog).getByRole("textbox", { name: "Client" }), { target: { value: "Bilal" } });
    fireEvent.click(within(dialog).getByRole("checkbox", { name: "Use these names for this project's next recordings" }));
    fireEvent.click(within(dialog).getByRole("button", { name: "Save" }));
    await act(flushAsync);
    expect(callsOf("speakers_rename")).toEqual([{ sessionId: "s1", names: { Me: null, Client: "Bilal" }, rememberForClient: true }]);
    expect(within(screen.getByRole("list", { name: "Transcript" })).getAllByText("Bilal")).toHaveLength(2);
  });

  it("History lists the changes; Restore original asks first", async () => {
    respond("transcript_detail", makeTranscript({ id: "tr-1", edited_at: "2026-11-18T10:00:00Z" }));
    respond("transcript_revisions", { data: [{ id: "r1", source: "original", author: null, created_at: "2026-11-17T09:00:00Z" }, { id: "r2", source: "edit", author: "Ayesha", created_at: "2026-11-18T10:00:00Z" }] });
    respond("transcript_restore", makeTranscript({ id: "tr-1" }));
    await open();
    fireEvent.click(within(tools()).getByRole("button", { name: "History" }));
    await act(flushAsync);
    const dialog = screen.getByRole("dialog", { name: "History" });
    expect(within(dialog).getByRole("list", { name: "Changes" })).toHaveTextContent("Original from the engine");
    expect(dialog).toHaveTextContent("by Ayesha");
    const asked = answerConfirm();
    asked.mockReturnValue(true);
    fireEvent.click(within(dialog).getByRole("button", { name: "Restore original" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Put the original text back? Your edits stay in the history.");
    expect(callsOf("transcript_restore")).toEqual([{ id: "tr-1" }]);
    expect(tools()).toHaveTextContent(/^Final transcriptFind/);
  });

  it("an empty transcript says no speech was detected", async () => {
    respond("transcript_detail", makeTranscript({ id: "tr-1", segments: [] }));
    await open();
    expect(screen.getByRole("region", { name: "The transcript is empty" })).toHaveTextContent("No speech was detected in this recording.");
  });

  it("call notes of a project's recording: write, the language switch, a line plays its moment", async () => {
    respond("session_notes", null);
    respond("session_notes_write", { data: { id: "n1", session_id: "s1", status: "ready", error: null, language: "roman_urdu", created_at: null,
      sections: [{ key: "asked", heading: "Asked for", items: [{ text: "Invoice by Friday", at_ms: 65_000, verify: true }] }] } });
    await open();
    fireEvent.click(screen.getByRole("tab", { name: "Call notes" }));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("radio", { name: "Roman Urdu" }));
    fireEvent.click(screen.getByRole("button", { name: "Write call notes" }));
    await act(flushAsync);
    expect(callsOf("session_notes_write")).toEqual([{ id: "s1", language: "roman_urdu" }]);
    const asked = screen.getByRole("region", { name: "Asked for" });
    expect(asked).toHaveTextContent("Verify");
    fireEvent.click(within(asked).getByRole("button", { name: "Invoice by Friday · Play from 1:05" }));
    expect(screen.getByRole("tab", { name: "Transcript" })).toHaveAttribute("aria-selected", "true");
    expect(screen.getByText(/1:05 \/ 1:02:10/)).toBeInTheDocument();
  });
});
