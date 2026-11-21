// The words as heard on the v2 recording page, as classic shows them (docs/v2/audit/parity.csv DT-REC-106, 107, 111,
// 112): words the engine was not sure of are underlined and a press plays from that word; a line it was unsure of says
// so; suggested fixes are marked, with a note and Review, and each opens with Accept, Dismiss and Close; a second
// transcription's words follow the line it disagreed on.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { Profiler } from "react";
import type { LibraryItem, TranscriptDetail } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { SECOND_HEARD, UNSURE_LINE, UNSURE_WORD, wordsMatch } from "./RecordingPage";
import { Transcript } from "../components";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEM: LibraryItem = makeItem("bilal", {
  title: "Bilal Qureshi", source_app: "whatsapp", started_at: "2026-11-17T06:42:00Z", duration_ms: 60_000,
  transcript: { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Hello" },
});
const SEGMENTS: TranscriptDetail["segments"] = [
  { channel: 1, speaker: "Me", start_ms: 0, end_ms: 4000, text: "Send the proforma to Omer today",
    words: [{ text: "Send", start_ms: 0, end_ms: 400, confidence: 0.98 }, { text: "the", start_ms: 400, end_ms: 600, confidence: 0.97 },
      { text: "proforma", start_ms: 600, end_ms: 1400, confidence: 0.41 }, { text: "to", start_ms: 1400, end_ms: 1600, confidence: 0.99 },
      { text: "Omer", start_ms: 1600, end_ms: 2000, confidence: 0.95 }, { text: "today", start_ms: 2000, end_ms: 2600, confidence: 0.96 }] },
  { channel: 2, speaker: "Client", start_ms: 5000, end_ms: 9000, text: "We will pay forty percent", uncertain: true,
    check: { provider: "groq", label: "Groq Whisper large-v3", text: "We will pay fourteen percent" } },
];
const play = vi.fn(() => Promise.resolve());

async function open(transcript: TranscriptDetail) {
  respond("library_list", [ITEM]);
  respond("library_refresh", [ITEM]);
  respond("bin_list", { data: [] });
  respond("unsure_markers", null);
  respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a" });
  respond("transcript_detail", transcript);
  render(<LibraryV2 />);
  await act(flushAsync);
  fireEvent.click(screen.getByRole("button", { name: /^Bilal Qureshi/ }));
  await act(flushAsync);
  Object.defineProperty(document.querySelector("audio")!, "play", { configurable: true, value: play });
}
const line = (n: number) => within(screen.getByRole("list", { name: "Transcript" })).getAllByRole("listitem")[n];

describe("the words as heard", () => {
  beforeEach(() => {
    resetTauriMock();
    play.mockClear();
  });

  it("DT-REC-111: an unsure word is underlined and a press plays from it; an unsure line says so", async () => {
    await open(makeTranscript({ id: "tr-1", segments: SEGMENTS }));
    const word = within(line(0)).getByRole("button", { name: "proforma, not sure of this word, play it" });
    expect(word).toHaveAttribute("title", UNSURE_WORD);
    // Out of the Tab order; the line's time button plays it from the keys.
    expect(word).toHaveAttribute("tabindex", "-1");
    // The unsure line's tooltip is read too, as words.
    expect(within(line(1)).getByText(`(${UNSURE_LINE})`)).toHaveClass("el-visually-hidden");
    expect(within(line(0)).getAllByRole("button").filter((b) => b.classList.contains("el-word--unsure"))).toHaveLength(1);
    fireEvent.click(word);
    expect(document.querySelector("audio")!.currentTime).toBeCloseTo(0.6);
    expect(play).toHaveBeenCalled();
    expect(line(1).querySelector(".el-segment__text")).toHaveAttribute("title", UNSURE_LINE);
  });

  it("DT-REC-110: while playing, the word being heard is marked and follows the audio; other lines stay plain", async () => {
    await open(makeTranscript({ id: "tr-1", segments: SEGMENTS }));
    const audio = document.querySelector("audio")!;
    const on = () => [...screen.getByRole("list", { name: "Transcript" }).querySelectorAll(".el-word--on")].map((w) => w.textContent);
    expect(on()).toEqual([]);
    audio.currentTime = 1.7;
    fireEvent.timeUpdate(audio);
    expect(on()).toEqual(["Omer"]);
    audio.currentTime = 2.1;
    fireEvent.timeUpdate(audio);
    expect(on()).toEqual(["today"]);
    // An unsure word being heard is marked too, and stays a button that plays it.
    audio.currentTime = 0.7;
    fireEvent.timeUpdate(audio);
    expect(on()).toEqual(["proforma"]);
    expect(within(line(0)).getByRole("button", { name: /^proforma/ })).toHaveClass("el-word--on");
    // A line with no word timings (the second) is marked as a whole, never word by word.
    audio.currentTime = 6;
    fireEvent.timeUpdate(audio);
    expect(on()).toEqual([]);
    expect(line(1)).toHaveAttribute("aria-current", "true");
  });

  it("DT-REC-112: a second transcription's words follow the line, never the engine's name", async () => {
    await open(makeTranscript({ id: "tr-1", segments: SEGMENTS }));
    expect(line(1)).toHaveTextContent(`${SECOND_HEARD} We will pay fourteen percent`);
    expect(line(1)).not.toHaveTextContent("Groq");
  });

  it("DT-REC-106 and DT-REC-107: suggested fixes are marked with a note and Review; Accept changes the word, Dismiss keeps it, Close shuts it", async () => {
    let suggestions = [
      { id: 0, index: 0, from: "Omer", to: "Omar", reason: "name", status: "open" },
      { id: 1, index: 1, from: "forty", to: "fourteen", reason: null, status: "open" },
    ];
    let segments = SEGMENTS;
    respondWith("transcript_suggestion", (args) => {
      const { suggestion, accept } = args as { suggestion: number; accept: boolean };
      suggestions = suggestions.map((s) => (s.id === suggestion ? { ...s, status: accept ? "accepted" : "dismissed" } : s));
      if (accept && suggestion === 0) segments = [{ ...SEGMENTS[0], text: "Send the proforma to Omar today", words: undefined }, SEGMENTS[1]];
      return makeTranscript({ id: "tr-1", segments, suggestions });
    });
    await open(makeTranscript({ id: "tr-1", segments: SEGMENTS, suggestions }));
    const note = screen.getByText(/2 suggested fixes: words that may have been misheard are underlined\./);
    const review = within(note.closest("p")!).getByRole("button", { name: "Review" });
    const scrolled = vi.fn();
    Element.prototype.scrollIntoView = scrolled;
    fireEvent.click(review);
    await act(flushAsync);
    let pop = screen.getByRole("group", { name: "Suggested fix" });
    expect(pop).toHaveTextContent("Change Omer to Omar? (name)");
    // Review brings the box into view with the keyboard on Accept.
    expect(scrolled).toHaveBeenCalled();
    expect(within(pop).getByRole("button", { name: "Accept" })).toHaveFocus();
    expect(within(line(0)).getByRole("button", { name: "Suggested fix: Omer to Omar" })).toHaveAttribute("aria-expanded", "true");
    // Close shuts it and the keyboard is back on Review.
    fireEvent.click(within(pop).getByRole("button", { name: "Close" }));
    await act(flushAsync);
    expect(screen.queryByRole("group", { name: "Suggested fix" })).toBeNull();
    expect(review).toHaveFocus();
    fireEvent.click(within(line(0)).getByRole("button", { name: "Suggested fix: Omer to Omar" }));
    fireEvent.click(within(screen.getByRole("group", { name: "Suggested fix" })).getByRole("button", { name: "Accept" }));
    await act(flushAsync);
    expect(callsOf("transcript_suggestion")).toEqual([{ id: "tr-1", suggestion: 0, accept: true }]);
    expect(line(0)).toHaveTextContent("Send the proforma to Omar today");
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Changed to “Omar”");
    expect(screen.getByText(/1 suggested fix: words that may have been misheard are underlined\./)).toBeInTheDocument();
    // The next fix opens by itself, the keyboard on its Accept.
    pop = screen.getByRole("group", { name: "Suggested fix" });
    expect(pop).toHaveTextContent("Change forty to fourteen?");
    expect(within(pop).getByRole("button", { name: "Accept" })).toHaveFocus();
    fireEvent.click(within(pop).getByRole("button", { name: "Dismiss" }));
    await act(flushAsync);
    expect(callsOf("transcript_suggestion").at(-1)).toEqual({ id: "tr-1", suggestion: 1, accept: false });
    expect(screen.queryByText(/suggested fix/)).toBeNull();
    expect(line(1)).toHaveTextContent("We will pay forty percent");
    // None left: Review has gone, so the keyboard goes to the title.
    expect(screen.getByRole("button", { name: "Bilal Qureshi" })).toHaveFocus();
  });

  it("a long transcript plays without drawing every line again: only the line being played follows the audio", async () => {
    const long: TranscriptDetail["segments"] = Array.from({ length: 2000 }, (_, i) => ({
      channel: (i % 2) + 1, speaker: i % 2 ? "Client" : "Me", start_ms: i * 3000, end_ms: i * 3000 + 2500, text: `Line ${i} with five words`,
      words: ["Line", String(i), "with", "five", "words"].map((text, k) => ({ text, start_ms: i * 3000 + k * 400, end_ms: i * 3000 + k * 400 + 300, confidence: k === 3 ? 0.4 : 0.95 })),
    }));
    const commits: number[] = [];
    respond("library_list", [{ ...ITEM, duration_ms: 6_000_000 }]);
    respond("library_refresh", [{ ...ITEM, duration_ms: 6_000_000 }]);
    respond("bin_list", { data: [] });
    respond("unsure_markers", null);
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a" });
    respond("transcript_detail", makeTranscript({ id: "tr-1", segments: long }));
    render(<Profiler id="page" onRender={(_id, _phase, actual) => commits.push(actual)}><LibraryV2 /></Profiler>);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: /^Bilal Qureshi/ }));
    await act(flushAsync);
    expect(screen.getByRole("list", { name: "Transcript" }).children).toHaveLength(2000);
    const drawing = Math.max(...commits);
    commits.length = 0;
    const audio = document.querySelector("audio")!;
    for (let k = 0; k < 10; k++) {
      audio.currentTime = 1500 + k * 0.4;
      fireEvent.timeUpdate(audio);
    }
    expect(screen.getByRole("list", { name: "Transcript" }).querySelectorAll(".el-word--on")).toHaveLength(1);
    // Each tick draws the player and one line, not 2,000: far less than drawing the transcript took.
    // (Measured: about 3% with each line drawn only when it changes, about 18% when every line is drawn again.)
    expect(Math.max(...commits)).toBeLessThan(drawing / 12);
  });

  it("the transcript draws again only the lines whose parts changed: the one that stopped and the one that started playing", () => {
    const segments = Array.from({ length: 2000 }, (_, i) => ({ speaker: "Me", start: i * 3, text: `Line ${i}` }));
    const drawn = vi.fn((s: { start: number }) => `Play from ${s.start}`);
    const text = segments.map((s) => <span>{s.text}</span>);
    const renderText = (i: number) => text[i];
    const onPlayLine = () => undefined;
    const { rerender } = render(<Transcript label="T" segments={segments} activeIndex={10} renderText={renderText} onPlayLine={onPlayLine} playLabel={drawn} />);
    expect(drawn).toHaveBeenCalledTimes(2000);
    drawn.mockClear();
    rerender(<Transcript label="T" segments={segments} activeIndex={11} renderText={renderText} onPlayLine={onPlayLine} playLabel={drawn} />);
    expect(drawn).toHaveBeenCalledTimes(2);
  });

  it("draws a line's words one by one only when they join back into its text", () => {
    expect(wordsMatch([{ text: "Hello" }, { text: "there" }], " Hello  there ")).toBe(true);
    expect(wordsMatch([{ text: "Hello" }, { text: "there" }], "Hello there, Omar")).toBe(false);
  });

  it("suggested fixes are left alone while editing", async () => {
    await open(makeTranscript({ id: "tr-1", segments: SEGMENTS, suggestions: [{ id: 0, index: 0, from: "Omer", to: "Omar", reason: null, status: "open" }] }));
    fireEvent.click(screen.getByRole("button", { name: "Edit text" }));
    expect(screen.queryByText(/suggested fix/)).toBeNull();
    expect(screen.queryByRole("button", { name: "Suggested fix: Omer to Omar" })).toBeNull();
  });
});
