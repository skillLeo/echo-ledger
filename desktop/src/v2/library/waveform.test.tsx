// The recording page's waveform, as classic's player (docs/v2/audit/parity.csv DT-REC-129, DT-REC-053): bars of who
// speaks when (coloured by side), the bookmarks as marks, how far it has played, and a press that seeks there. The
// Position slider stays the control for the keyboard and screen readers.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/react";
import type { LibraryItem } from "../../lib/ipc";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { Waveform } from "../components";
import { WAVE_BARS } from "../../views/main/TranscriptTools";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEM: LibraryItem = makeItem("bilal", {
  title: "Bilal Qureshi", started_at: "2026-11-17T06:42:00Z", duration_ms: 100_000,
  transcript: { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Hello" },
});
const SEGMENTS = [
  { channel: 1, speaker: "Me", start_ms: 0, end_ms: 40_000, text: "One two three four five six" },
  { channel: 2, speaker: "Client", start_ms: 60_000, end_ms: 100_000, text: "Seven eight nine ten" },
];

describe("the waveform", () => {
  beforeEach(() => resetTauriMock());

  it("DT-REC-129 and DT-REC-053: bars by side, the bookmarks marked, and a press seeks there", async () => {
    respond("library_list", [ITEM]);
    respond("library_refresh", [ITEM]);
    respond("bin_list", { data: [] });
    respond("unsure_markers", null);
    respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", markers: [{ id: "m1", at_ms: 50_000, tag: "Decision", note: "Agreed" }] });
    respond("transcript_detail", makeTranscript({ id: "tr-1", segments: SEGMENTS }));
    render(<LibraryV2 />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: /^Bilal Qureshi/ }));
    await act(flushAsync);
    const wave = screen.getByTestId("waveform");
    expect(wave).toHaveAttribute("aria-hidden", "true");
    const bars = [...wave.querySelectorAll(".el-wave__bar")];
    expect(bars).toHaveLength(WAVE_BARS);
    expect(bars[0]).toHaveClass("el-wave__bar--1");
    expect(bars[WAVE_BARS - 1]).toHaveClass("el-wave__bar--2");
    // Silence between the turns stays low.
    expect((bars[Math.round(WAVE_BARS / 2)] as HTMLElement).style.getPropertyValue("--el-wave-level")).toBe("10%");
    const mark = wave.querySelector(".el-wave__mark") as HTMLElement;
    expect(mark).toHaveAttribute("title", "0:50 Decision · Agreed");
    expect(mark.style.getPropertyValue("--el-wave-at")).toBe("0.5");
    // A press at a quarter of the way seeks there; what has played is drawn as played.
    wave.getBoundingClientRect = () => ({ left: 100, width: 400, top: 0, height: 40, right: 500, bottom: 40, x: 100, y: 0, toJSON: () => ({}) });
    fireEvent.click(wave, { clientX: 200 });
    expect(document.querySelector("audio")!.currentTime).toBeCloseTo(25);
    expect(screen.getByRole("slider", { name: "Position" })).toHaveValue("25");
    expect(wave.querySelectorAll(".el-wave__bar--played").length).toBe(Math.floor(WAVE_BARS / 4));
  });

  it("draws nothing without a length or anything to draw", () => {
    const { container, rerender } = render(<Waveform bars={[]} durationMs={60_000} nowMs={0} onSeek={() => undefined} />);
    expect(container.firstChild).toBeNull();
    rerender(<Waveform bars={[{ level: 1, channel: 1 }]} durationMs={0} nowMs={0} onSeek={() => undefined} />);
    expect(container.firstChild).toBeNull();
  });
});
