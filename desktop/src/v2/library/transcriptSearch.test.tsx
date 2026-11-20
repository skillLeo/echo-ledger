// Search inside transcripts in the v2 Library (parity.csv DT-LIB-010, 011, 062): from two letters the server's
// transcript search, "In transcripts" with each recording's matching lines, and a line opens it at its moment.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const BILAL = makeItem("bilal", { title: "Bilal Qureshi", started_at: "2026-11-16T09:00:00Z", duration_ms: 3_730_000, transcript: { final_status: "completed", final_transcript_id: "tr-b", live_available: false, preview: "Hello" } });
const HIT = { transcript_id: "tr-b", kind: "final", segment_index: 2, start_ms: 65_000, end_ms: 70_000, speaker: "Me", snippet: "Let us go over the invoice.", highlights: [[19, 7]] as [number, number][] };

describe("search inside transcripts", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
    respond("library_list", [BILAL]);
    respond("library_refresh", [BILAL]);
    respond("search_library", { data: [{ session: BILAL, hits: [HIT], hit_count: 4 }], next_page: null });
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a" });
    respond("transcript_detail", makeTranscript({ id: "tr-b" }));
    Object.assign(HTMLMediaElement.prototype, { play: vi.fn(() => Promise.resolve()), pause: vi.fn() });
    Element.prototype.scrollIntoView = vi.fn();
  });

  it("asks the server from two letters, once typing stops; shows the lines with the words marked and how many matches", async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
    const search = screen.getByRole("searchbox", { name: "Search recordings" });
    fireEvent.change(search, { target: { value: "i" } });
    await act(async () => vi.advanceTimersByTime(400));
    expect(callsOf("search_library")).toEqual([]);
    fireEvent.change(search, { target: { value: "in" } });
    fireEvent.change(search, { target: { value: "invoice" } });
    await act(async () => vi.advanceTimersByTime(400));
    await act(flushAsync);
    expect(callsOf("search_library")).toEqual([{ query: { q: "invoice" } }]);
    const hits = screen.getByRole("region", { name: "Matches in transcripts" });
    expect(hits).toHaveTextContent("Bilal Qureshi");
    expect(hits).toHaveTextContent("4 matches");
    expect(within(hits).getByText("invoice", { selector: "mark" })).toBeInTheDocument();
    fireEvent.change(search, { target: { value: "" } });
    await act(async () => vi.advanceTimersByTime(400));
    expect(screen.queryByRole("region", { name: "Matches in transcripts" })).toBeNull();
  });

  it("a line opens the recording at its moment, with its line marked", async () => {
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.change(screen.getByRole("searchbox", { name: "Search recordings" }), { target: { value: "invoice" } });
    await act(async () => vi.advanceTimersByTime(400));
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Play Bilal Qureshi from 1:05" }));
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Bilal Qureshi" })).toBeInTheDocument();
    expect(screen.getByText(/1:05 \/ 1:02:10/)).toBeInTheDocument();
    expect(document.querySelector(".el-segment--active")).toHaveTextContent("Let us go over the invoice.");
  });

  it("a recording opened from outside at a moment (the palette, Ask) starts there too", async () => {
    render(<LibraryV2 openRequest={{ id: "bilal", seq: 1, ms: 12_400 }} />);
    await act(flushAsync);
    await act(flushAsync);
    expect(screen.getByText(/0:12 \/ 1:02:10/)).toBeInTheDocument();
  });
});
