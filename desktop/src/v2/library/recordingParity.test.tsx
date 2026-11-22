// The v2 recording page and Library rows against classic (docs/v2/audit/parity.csv, desktop batch 1): the meta line
// with the length, the file size in Details, a failure's reason, the progress while transcribing, the live captions'
// label, the engine's retry line, an expired playback link asked for once more, the labels with their ×, and the
// row's project and bookmarks.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { LibraryItem, TranscriptDetail } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeItem, makeTranscript } from "../../test/fixtures";
import { LibraryV2 } from "./LibraryV2";
import { rowModel } from "./model";
import { primarySize } from "./RecordingPage";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NOW = new Date(2026, 10, 17, 15, 0);
const at = (hour: number, minute = 0) => new Date(2026, 10, 17, hour, minute).toISOString();
const done = { final_status: "completed", final_transcript_id: "tr-1", live_available: false, preview: "Payment terms" };
const item = (over: Partial<LibraryItem> = {}): LibraryItem => makeItem("bilal", {
  title: "Bilal Qureshi", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: at(11, 42), duration_ms: 1_104_000, transcript: done, ...over,
});

async function open(it: LibraryItem, transcript: TranscriptDetail | null = makeTranscript({ id: "tr-1" }), detail: Record<string, unknown> = {}) {
  respond("library_list", [it]);
  respond("library_refresh", [it]);
  respond("bin_list", { data: [] });
  respond("session_detail", { playback_url: "https://ledger.example.com/a.m4a", recordings: [{ id: "r1", role: "primary", size_bytes: 4_404_019 }], ...detail });
  if (transcript) respond("transcript_detail", transcript);
  render(<LibraryV2 />);
  await act(flushAsync);
  fireEvent.click(screen.getByRole("button", { name: /^Bilal Qureshi/ }));
  await act(flushAsync);
}

describe("the recording page (parity batch 1)", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(NOW);
    respond("unsure_markers", null);
    respond("transcript_views", { status: "none", available: [], model: null, made_at: null, error: null });
  });

  it("DT-REC-009: the meta line says when and how long, then the project", async () => {
    await open(item({ client: { id: "c1", name: "Qureshi Textiles" } as LibraryItem["client"] }));
    const meta = document.querySelector(".el-page__meta")!;
    expect(meta).toHaveTextContent("WhatsApp · Today · 11:42 AM · 18:24");
    expect(within(meta as HTMLElement).getByText("Qureshi Textiles")).toBeInTheDocument();
  });

  it("DT-REC-144: Details has the file size", async () => {
    await open(item());
    fireEvent.click(screen.getByRole("tab", { name: "Details" }));
    expect(screen.getByText("File size").nextElementSibling).toHaveTextContent("4 MB");
    expect(primarySize({ recordings: [{ role: "channel_1", size_bytes: 9 }, { role: "primary", size_bytes: 12 }] })).toBe(12);
    expect(primarySize({})).toBeNull();
  });

  it("DT-REC-054: a failed transcription says why", async () => {
    const failed = item({ status: "failed", transcript: { ...done, final_status: "failed", final_error_code: "provider_unreachable" } });
    await open(failed, makeTranscript({ id: "tr-1", status: "failed", error_code: "provider_unreachable", segments: [] }));
    expect(screen.getByText(/The transcription service couldn't be reached\. Try again later\./)).toBeInTheDocument();
  });

  it("DT-REC-059: transcribing says which part it is on", async () => {
    const making = item({ status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: false, preview: null, final_progress: { done: 2, total: 8 } } });
    await open(making, null);
    expect(screen.getByText(/Transcribing… part 3 of 8/)).toBeInTheDocument();
  });

  it("DT-REC-060: live captions read while the final transcript is made are labelled", async () => {
    const live = item({ transcript: { final_status: "processing", final_transcript_id: null, live_transcript_id: "tr-1", live_available: true, preview: null, final_progress: { done: 0, total: 3 } } as LibraryItem["transcript"] });
    await open(live, makeTranscript({ id: "tr-1", kind: "live" }));
    expect(screen.getByTestId("live-line")).toHaveTextContent("Live captions (final transcript pending · part 1 of 3)");
  });

  it("DT-REC-036: waiting for the engine says when it tries again", async () => {
    const waiting = item({ transcript: { ...done, final_status: "processing" } });
    await open(waiting, makeTranscript({ id: "tr-1", status: "processing", retry_at: new Date(2026, 10, 17, 15, 30).toISOString(), segments: [] }));
    expect(screen.getByText("Waiting to transcribe — retrying at 3:30 pm")).toBeInTheDocument();
  });

  it("DT-REC-051: an expired playback link is asked for again once, then said", async () => {
    await open(item());
    respond("session_detail", { playback_url: "https://ledger.example.com/b.m4a" });
    fireEvent.error(document.querySelector("audio")!);
    await act(flushAsync);
    expect(document.querySelector("audio")).toHaveAttribute("src", "https://ledger.example.com/b.m4a");
    fireEvent.error(document.querySelector("audio")!);
    await act(flushAsync);
    expect(screen.getByText("Playback link expired. Open the recording again.")).toBeInTheDocument();
  });

  it("DT-REC-045: the labels are on the page, each with its ×", async () => {
    respondWith("session_organise", (args) => ({ data: { ...item(), labels: (args as { patch: { labels: string[] } }).patch.labels } }));
    await open(item({ labels: ["Invoice", "Follow up"], label_colors: { Invoice: "#2d9a63" } }));
    const labels = screen.getByRole("list", { name: "Labels" });
    expect(within(labels).getAllByRole("listitem").map((l) => l.textContent)).toEqual(["Invoice", "Follow up"]);
    // Each chip in its list's colour (none: the neutral one).
    expect(within(labels).getByText("Invoice").closest(".el-label-chip")).toHaveClass("el-label--green");
    fireEvent.click(within(labels).getByRole("button", { name: "Remove label: Invoice" }));
    await act(flushAsync);
    expect(callsOf("session_organise")).toEqual([{ id: "bilal", patch: { labels: ["Follow up"] } }]);
    // Gone from the page at once, without waiting for the next sync.
    expect(within(screen.getByRole("list", { name: "Labels" })).getAllByRole("listitem").map((l) => l.textContent)).toEqual(["Follow up"]);
  });
});

describe("a Library row (parity batch 1)", () => {
  it("DT-LIB-069: the sub-line has the project and the bookmarks after the first words", () => {
    const row = rowModel(item({ client: { id: "c1", name: "Qureshi Textiles" } as LibraryItem["client"], marker_count: 2 }), NOW);
    expect(row.meta).toBe("Payment terms · Qureshi Textiles · 2 bookmarks");
    expect(rowModel(item({ marker_count: 1 }), NOW).meta).toBe("Payment terms · 1 bookmark");
    expect(rowModel(item(), NOW).duration).toBe("18:24");
  });
});
