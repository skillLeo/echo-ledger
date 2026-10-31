// The recording page (since v1.4): call notes of a client's recording, "Always write X as Y" after an edit, suggested
// fixes, Find, and the player keys.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { LibraryView } from "./LibraryView";
import type { CallNotes, LibraryItem, TranscriptDetail } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS: LibraryItem[] = [
  makeItem("a", { title: "Kick-off with Acme", client: { id: "c1", name: "Acme" }, started_at: "2026-10-24T09:30:00Z", duration_ms: 125_000,
    transcript: { final_status: "completed", final_transcript_id: "tr-a", live_available: false, preview: "We will build it" } }),
  makeItem("b", { title: "Voice note", started_at: "2026-10-23T09:30:00Z", duration_ms: 20_000,
    transcript: { final_status: "completed", final_transcript_id: "tr-b", live_available: false, preview: "Remember" } }),
];

const SEGMENTS = [
  { channel: 1, speaker: "Me", start_ms: 0, end_ms: 4_000, text: "We will build it on la ravel with Vue." },
  { channel: 2, speaker: "Client", start_ms: 5_000, end_ms: 9_000, text: "Launch is in November, the launch party too." },
];

let transcripts: Record<string, TranscriptDetail> = {};
const NOTES: CallNotes = {
  id: "n1", session_id: "a", status: "ready", error: null, language: "en", created_at: "2026-10-24T10:00:00Z", session: null,
  sections: [
    { key: "decisions", heading: "Decisions", items: [{ text: "Build on Laravel", verify: false, lines: [0], at_ms: 0 }] },
    { key: "to_confirm", heading: "To confirm with the client", items: [{ text: "Launch in November", verify: true, lines: [1], at_ms: 5_000 }] },
  ],
};

const play = vi.fn(() => Promise.resolve());
let times = new WeakMap<HTMLMediaElement, number>();

function mockBackend() {
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respondWith("transcript_detail", ({ id }) => transcripts[id as string]);
  respondWith("session_detail", ({ id }) => ({ id, playback_url: "https://audio.example.test/a.wav" }));
  respond("list_clients", { data: [{ id: "c1", name: "Acme" }] });
}

async function openRecording(title = "Kick-off with Acme") {
  mockBackend();
  const notify = vi.fn();
  render(<LibraryView notify={notify} />);
  await flushAsync();
  fireEvent.click(within(screen.getByTestId("library-list")).getByText(title).closest("button") as HTMLElement);
  await flushAsync();
  return notify;
}
const detail = () => document.querySelector(".detail") as HTMLElement;
const audio = () => document.querySelector("audio") as HTMLAudioElement;

beforeEach(() => {
  resetTauriMock();
  transcripts = {
    "tr-a": makeTranscript({ id: "tr-a", segments: SEGMENTS, suggestions: [{ id: 7, index: 0, from: "la ravel", to: "Laravel", reason: "the PHP framework", status: "open" }] }),
    "tr-b": makeTranscript({ id: "tr-b", segments: [{ channel: 1, speaker: "Me", start_ms: 0, end_ms: 2_000, text: "Remember the milk." }] }),
  };
  times = new WeakMap();
  play.mockClear();
  Object.defineProperty(HTMLMediaElement.prototype, "play", { configurable: true, value: play });
  Object.defineProperty(HTMLMediaElement.prototype, "pause", { configurable: true, value: vi.fn() });
  Object.defineProperty(HTMLMediaElement.prototype, "currentTime", {
    configurable: true,
    get(this: HTMLMediaElement) { return times.get(this) ?? 0; },
    set(this: HTMLMediaElement, v: number) { times.set(this, v); },
  });
});
afterEach(async () => {
  await flushAsync();
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe("Call notes of a client's recording", () => {
  it("shows the notes in sections; a line plays its moment and unclear lines say verify", async () => {
    respond("session_notes", { data: NOTES });
    await openRecording();
    const notes = await within(detail()).findByRole("region", { name: "Call notes" });
    expect(callsOf("session_notes")).toEqual([{ id: "a" }]);
    expect(within(notes).getByText("Decisions")).toBeInTheDocument();
    expect(within(notes).getByText("To confirm with the client")).toBeInTheDocument();
    const confirm = within(notes).getByRole("button", { name: /Launch in November/ });
    expect(within(confirm).getByText("verify")).toBeInTheDocument();
    fireEvent.click(confirm);
    expect(audio().currentTime).toBe(5);
    expect(play).toHaveBeenCalled();
  });

  it("has no notes section for a recording without a client", async () => {
    respond("session_notes", null);
    await openRecording("Voice note");
    expect(within(detail()).queryByRole("region", { name: "Call notes" })).not.toBeInTheDocument();
    expect(callsOf("session_notes")).toHaveLength(0);
  });

  it("writes notes that were never written, asks every 5 s while they are pending, then shows them", async () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    respond("session_notes", null);
    respond("session_notes_write", { data: { ...NOTES, status: "pending", sections: [] } });
    await openRecording();
    const notes = within(detail()).getByRole("region", { name: "Call notes" });
    fireEvent.click(within(notes).getByRole("button", { name: "Write call notes" }));
    await flushAsync();
    expect(callsOf("session_notes_write")).toEqual([{ id: "a", language: "en" }]);
    expect(within(notes).getByText(/Writing the call notes/)).toBeInTheDocument();
    respond("session_notes", { data: NOTES });
    await act(async () => { vi.advanceTimersByTime(5_000); });
    await flushAsync();
    expect(within(notes).getByText("Build on Laravel")).toBeInTheDocument();
    const reads = callsOf("session_notes").length;
    await act(async () => { vi.advanceTimersByTime(20_000); });
    expect(callsOf("session_notes")).toHaveLength(reads);
  });

  it("writes them again in Roman Urdu", async () => {
    respond("session_notes", { data: NOTES });
    respond("session_notes_write", { data: { ...NOTES, status: "pending", language: "roman_urdu" } });
    await openRecording();
    const notes = await within(detail()).findByRole("region", { name: "Call notes" });
    await within(notes).findByText("Build on Laravel");
    fireEvent.click(within(within(notes).getByRole("group", { name: "Notes language" })).getByRole("button", { name: "Roman Urdu" }));
    await waitFor(() => expect(callsOf("session_notes_write")).toEqual([{ id: "a", language: "roman_urdu" }]));
    expect(within(notes).getByRole("button", { name: "Roman Urdu" })).toHaveAttribute("aria-pressed", "true");
  });

  it("says when the notes cannot be loaded and lets the person retry", async () => {
    fail("session_notes", "Network error");
    await openRecording();
    const notes = within(detail()).getByRole("region", { name: "Call notes" });
    expect(within(notes).getByText("Couldn't load the call notes.")).toBeInTheDocument();
    respond("session_notes", { data: NOTES });
    fireEvent.click(within(notes).getByRole("button", { name: "Retry loading the call notes" }));
    expect(await within(notes).findByText("Build on Laravel")).toBeInTheDocument();
  });
});

describe("Always write X as Y (learn after an edit)", () => {
  beforeEach(() => respond("session_notes", null));

  async function editFirstLine(answer: Record<string, unknown>) {
    respondWith("transcript_edit", ({ index, text }) => ({ ...transcripts["tr-a"], segments: SEGMENTS.map((s, i) => (i === index ? { ...s, text } : s)), ...answer }));
    const notify = await openRecording();
    fireEvent.click(within(detail()).getByRole("button", { name: "Edit text" }));
    const line = within(detail()).getByRole("textbox", { name: "Line at 0:00" });
    fireEvent.change(line, { target: { value: "We will build it on Laravel with Vue." } });
    fireEvent.blur(line);
    await flushAsync();
    return notify;
  }

  it("offers the rule for the client and saves it on Yes", async () => {
    respond("vocabulary_replace_add", { replacements: [] });
    const notify = await editFirstLine({ learn: [{ from: "la ravel", to: "Laravel", client_id: "c1", client_name: "Acme" }] });
    const offer = within(detail()).getByRole("group", { name: "Always write it this way" });
    expect(offer).toHaveTextContent("Always write la ravel as Laravel for Acme?");
    // The keyterm hint gives way to the rule offer.
    expect(within(detail()).queryByText(/Add to Acme’s vocabulary/)).not.toBeInTheDocument();
    fireEvent.click(within(offer).getByRole("button", { name: "Yes" }));
    await waitFor(() => expect(callsOf("vocabulary_replace_add")).toEqual([{ from: "la ravel", to: "Laravel", clientId: "c1" }]));
    expect(notify).toHaveBeenCalledWith("Acme's transcripts will write “Laravel” from now on");
    expect(within(detail()).queryByRole("group", { name: "Always write it this way" })).not.toBeInTheDocument();
  });

  it("asks one rule at a time and saves nothing on No", async () => {
    await editFirstLine({ learn: [
      { from: "la ravel", to: "Laravel", client_id: "c1", client_name: "Acme" },
      { from: "view", to: "Vue", client_id: "c1", client_name: "Acme" },
    ] });
    fireEvent.click(within(detail()).getByRole("button", { name: "No" }));
    expect(within(detail()).getByRole("group", { name: "Always write it this way" })).toHaveTextContent("Always write view as Vue for Acme?");
    fireEvent.click(within(detail()).getByRole("button", { name: "No" }));
    expect(within(detail()).queryByRole("group", { name: "Always write it this way" })).not.toBeInTheDocument();
    expect(callsOf("vocabulary_replace_add")).toHaveLength(0);
  });

  it("keeps the keyterm hint when the answer has nothing to learn", async () => {
    await editFirstLine({ learn: [] });
    expect(within(detail()).queryByRole("group", { name: "Always write it this way" })).not.toBeInTheDocument();
    expect(within(detail()).getByText(/Add to Acme’s vocabulary/)).toBeInTheDocument();
  });
});

describe("Suggested fixes", () => {
  beforeEach(() => respond("session_notes", null));

  it("marks the words, and Accept sends the decision and shows the returned transcript", async () => {
    respondWith("transcript_suggestion", () => ({ ...transcripts["tr-a"], segments: [{ ...SEGMENTS[0], text: "We will build it on Laravel with Vue." }, SEGMENTS[1]], suggestions: [] }));
    await openRecording();
    const mark = within(detail()).getByText("la ravel");
    expect(mark).toHaveClass("suggest-mark");
    expect(mark).toHaveAttribute("title", "Suggested: Laravel (the PHP framework)");
    fireEvent.click(mark);
    const pop = within(detail()).getByRole("group", { name: "Suggested fix" });
    expect(pop).toHaveTextContent("Change la ravel to Laravel?");
    fireEvent.click(within(pop).getByRole("button", { name: "Accept" }));
    await waitFor(() => expect(callsOf("transcript_suggestion")).toEqual([{ id: "tr-a", suggestion: 7, accept: true }]));
    expect(await within(detail()).findByText("We will build it on Laravel with Vue.")).toBeInTheDocument();
    expect(within(detail()).queryByText("la ravel")).not.toBeInTheDocument();
    expect(within(detail()).queryByRole("group", { name: "Suggested fix" })).not.toBeInTheDocument();
  });

  it("Dismiss leaves the words; Review opens the first fix", async () => {
    respondWith("transcript_suggestion", () => ({ ...transcripts["tr-a"], suggestions: [{ id: 7, index: 0, from: "la ravel", to: "Laravel", status: "dismissed" }] }));
    await openRecording();
    expect(within(detail()).getByText(/1 suggested fix/)).toBeInTheDocument();
    fireEvent.click(within(detail()).getByRole("button", { name: "Review" }));
    fireEvent.click(within(within(detail()).getByRole("group", { name: "Suggested fix" })).getByRole("button", { name: "Dismiss" }));
    await waitFor(() => expect(callsOf("transcript_suggestion")).toEqual([{ id: "tr-a", suggestion: 7, accept: false }]));
    await flushAsync();
    expect(within(detail()).getByText(/We will build it on la ravel with Vue\./)).toBeInTheDocument();
    expect(within(detail()).queryByText(/suggested fix/)).not.toBeInTheDocument();
  });
});

describe("Find and the player keys", () => {
  beforeEach(() => respond("session_notes", null));

  it("Ctrl+F finds every match in the transcript and steps through them", async () => {
    await openRecording();
    fireEvent.keyDown(window, { key: "f", ctrlKey: true });
    const find = within(detail()).getByRole("textbox", { name: "Find in transcript" });
    fireEvent.change(find, { target: { value: "launch" } });
    const marks = () => [...detail().querySelectorAll("mark.find-hit")];
    expect(marks()).toHaveLength(2);
    expect(within(detail()).getByText("1 of 2")).toBeInTheDocument();
    expect(marks()[0]).toHaveClass("current");
    fireEvent.keyDown(find, { key: "Enter" });
    expect(within(detail()).getByText("2 of 2")).toBeInTheDocument();
    expect(marks()[1]).toHaveClass("current");
    fireEvent.change(find, { target: { value: "zzz" } });
    expect(within(detail()).getByText("No matches")).toBeInTheDocument();
    fireEvent.keyDown(find, { key: "Escape" });
    expect(within(detail()).queryByRole("textbox", { name: "Find in transcript" })).not.toBeInTheDocument();
  });

  it("Space plays and pauses from anywhere in the window, J / L jump 10 s, and typing is left alone", async () => {
    await openRecording();
    fireEvent.keyDown(document.body, { key: " " });
    expect(play).toHaveBeenCalledTimes(1);
    audio().currentTime = 30;
    fireEvent.keyDown(document.body, { key: "l" });
    expect(audio().currentTime).toBe(40);
    fireEvent.keyDown(document.body, { key: "j" });
    expect(audio().currentTime).toBe(30);
    fireEvent.click(within(detail()).getByRole("button", { name: "Find in this transcript" }));
    fireEvent.keyDown(within(detail()).getByRole("textbox", { name: "Find in transcript" }), { key: "l" });
    expect(audio().currentTime).toBe(30);
  });
});
