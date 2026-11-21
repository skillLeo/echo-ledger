// The words as heard on the v2 recording page, as classic shows them (docs/v2/audit/parity.csv DT-REC-106, 107, 111,
// 112): words the engine was not sure of are underlined and a press plays from that word; a line it was unsure of says
// so; suggested fixes are marked, with a note and Review, and each opens with Accept, Dismiss and Close; a second
// transcription's words follow the line it disagreed on.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem, TranscriptDetail } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { SECOND_HEARD, UNSURE_LINE, UNSURE_WORD } from "./RecordingPage";

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
    expect(within(line(0)).getAllByRole("button").filter((b) => b.classList.contains("el-word--unsure"))).toHaveLength(1);
    fireEvent.click(word);
    expect(document.querySelector("audio")!.currentTime).toBeCloseTo(0.6);
    expect(play).toHaveBeenCalled();
    expect(line(1).querySelector(".el-segment__text")).toHaveAttribute("title", UNSURE_LINE);
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
    fireEvent.click(within(note.closest("p")!).getByRole("button", { name: "Review" }));
    let pop = screen.getByRole("group", { name: "Suggested fix" });
    expect(pop).toHaveTextContent("Change Omer to Omar? (name)");
    expect(within(line(0)).getByRole("button", { name: "Suggested fix: Omer to Omar" })).toHaveAttribute("aria-expanded", "true");
    fireEvent.click(within(pop).getByRole("button", { name: "Close" }));
    expect(screen.queryByRole("group", { name: "Suggested fix" })).toBeNull();
    fireEvent.click(within(line(0)).getByRole("button", { name: "Suggested fix: Omer to Omar" }));
    fireEvent.click(within(screen.getByRole("group", { name: "Suggested fix" })).getByRole("button", { name: "Accept" }));
    await act(flushAsync);
    expect(callsOf("transcript_suggestion")).toEqual([{ id: "tr-1", suggestion: 0, accept: true }]);
    expect(line(0)).toHaveTextContent("Send the proforma to Omar today");
    expect(screen.getByRole("status", { name: "Notifications" })).toHaveTextContent("Changed to “Omar”");
    expect(screen.getByText(/1 suggested fix: words that may have been misheard are underlined\./)).toBeInTheDocument();
    fireEvent.click(within(line(1)).getByRole("button", { name: "Suggested fix: forty to fourteen" }));
    pop = screen.getByRole("group", { name: "Suggested fix" });
    fireEvent.click(within(pop).getByRole("button", { name: "Dismiss" }));
    await act(flushAsync);
    expect(callsOf("transcript_suggestion").at(-1)).toEqual({ id: "tr-1", suggestion: 1, accept: false });
    expect(screen.queryByText(/suggested fix/)).toBeNull();
    expect(line(1)).toHaveTextContent("We will pay forty percent");
  });

  it("suggested fixes are left alone while editing", async () => {
    await open(makeTranscript({ id: "tr-1", segments: SEGMENTS, suggestions: [{ id: 0, index: 0, from: "Omer", to: "Omar", reason: null, status: "open" }] }));
    fireEvent.click(screen.getByRole("button", { name: "Edit text" }));
    expect(screen.queryByText(/suggested fix/)).toBeNull();
    expect(screen.queryByRole("button", { name: "Suggested fix: Omer to Omar" })).toBeNull();
  });
});
