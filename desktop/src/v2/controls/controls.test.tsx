import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { AfterCallState, PendingItem, RecordingState } from "../../lib/ipc";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { RecordingPill, type PillActions, type PillModel } from "./RecordingPill";
import { AfterCallCard, cardTitle, timeLeftText } from "./AfterCallCard";
import { AfterCallStack } from "./AfterCallStack";
import PillApp from "../../views/pill/PillApp";
import ReviewApp from "../../views/review/ReviewApp";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../../test/tauri")).windowModule);

beforeEach(() => resetTauriMock());

const MODEL: PillModel = { elapsed: "02:14", name: "Omar", paused: false, captions: false, language: "UR+EN", screens: { on: true, count: 3 } };
const actions = (): PillActions => ({
  onCaptions: vi.fn(), onPause: vi.fn(), onStop: vi.fn(), onScreens: vi.fn(), onCapture: vi.fn(), onLanguage: vi.fn(), onDiscard: vi.fn(),
});

describe("a kept screen on the pill (screens:captured)", () => {
  it("marks the pill for a moment and says how many screens were kept", async () => {
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    try {
      render(<RecordingPill model={MODEL} actions={actions()} />);
      await act(async () => { await emitEvent("screens:captured", { count: 4 }); });
      const pill = screen.getByTestId("recording-pill-v2");
      expect(pill).toHaveClass("el-pill--captured");
      expect(within(pill).getByRole("status")).toHaveTextContent("Screen kept. 4 screens in this recording.");
      act(() => vi.advanceTimersByTime(1300));
      expect(pill).not.toHaveClass("el-pill--captured");
    } finally {
      vi.useRealTimers();
    }
  });

  it("under Reduce motion the mark is a still ring, never a flash (the stylesheet says so)", async () => {
    const { readFileSync } = await import("node:fs");
    const { dirname, join } = await import("node:path");
    const { fileURLToPath } = await import("node:url");
    const css = readFileSync(join(dirname(fileURLToPath(import.meta.url)), "..", "styles", "v2.css"), "utf8");
    expect(css).toMatch(/@media \(prefers-reduced-motion: reduce\) \{ \.el-pill--captured \{ animation: none; \} \}/);
    expect(css).toMatch(/\.el-pill--captured \{ box-shadow: [^}]*var\(--el-role-accent-default\); animation: el-captured [^;]* 2; \}/);
  });
});

describe("the recording pill", () => {
  it("reads '● 02:14 · Omar' and opens its controls on a press", () => {
    render(<RecordingPill model={MODEL} actions={actions()} />);
    const summary = screen.getByRole("button", { name: "Recording 02:14, Omar. Show the controls" });
    expect(summary).toHaveAttribute("aria-expanded", "false");
    expect(summary).toHaveTextContent("02:14·Omar");
    expect(screen.queryByRole("button", { name: "Captions" })).toBeNull();
    fireEvent.click(summary);
    expect(summary).toHaveAttribute("aria-expanded", "true");
    for (const name of ["Captions", "Pause", "Stop and save", "Capture the screen now", "Language: UR+EN. Change", "Discard this recording"]) {
      expect(screen.getByRole("button", { name })).toBeInTheDocument();
    }
    expect(screen.getByRole("button", { name: "Captions" })).toHaveAttribute("aria-pressed", "false");
    expect(screen.getByRole("button", { name: /^Screens on, 3 kept/ })).toHaveAttribute("aria-pressed", "true");
  });

  it("each control runs its action; Discard asks first", () => {
    const a = actions();
    render(<RecordingPill model={MODEL} actions={a} defaultOpen />);
    fireEvent.click(screen.getByRole("button", { name: "Captions" }));
    fireEvent.click(screen.getByRole("button", { name: "Pause" }));
    fireEvent.click(screen.getByRole("button", { name: "Stop and save" }));
    fireEvent.click(screen.getByRole("button", { name: /^Screens on/ }));
    fireEvent.click(screen.getByRole("button", { name: "Capture the screen now" }));
    fireEvent.click(screen.getByRole("button", { name: "Language: UR+EN. Change" }));
    expect([a.onCaptions, a.onPause, a.onStop, a.onScreens, a.onCapture, a.onLanguage].map((f) => (f as ReturnType<typeof vi.fn>).mock.calls.length)).toEqual([1, 1, 1, 1, 1, 1]);
    fireEvent.click(screen.getByRole("button", { name: "Discard this recording" }));
    const ask = screen.getByRole("group", { name: "Discard this recording?" });
    fireEvent.click(within(ask).getByRole("button", { name: "Keep recording" }));
    expect(a.onDiscard).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole("button", { name: "Discard this recording" }));
    fireEvent.click(screen.getByRole("button", { name: "Discard" }));
    expect(a.onDiscard).toHaveBeenCalledTimes(1);
  });

  it("paused says so; without screens there is no screens control; an error replaces the name", () => {
    render(<RecordingPill model={{ ...MODEL, paused: true, screens: null, error: "The microphone is busy" }} actions={actions()} defaultOpen />);
    expect(screen.getByRole("button", { name: /^Paused 02:14/ })).toHaveTextContent("The microphone is busy");
    expect(screen.getByRole("button", { name: "Resume" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Capture the screen now" })).toBeNull();
  });
});

const ITEM: PendingItem = {
  id: "p1", sourceApp: "whatsapp", auto: true, mode: "call", title: null, clientId: null, clientName: null,
  startedAt: "2026-11-17T10:00:00Z", endedAt: "2026-11-17T10:04:12Z", durationMs: 252_000, firstLine: null,
  deadlineAt: new Date(Date.parse("2026-11-17T10:04:20Z") + 8_000).toISOString(), callerName: "Omar Farooq",
};

describe("the after-call card", () => {
  it("names the call with its length and the screens kept, and offers Transcribe, Save audio and Delete", () => {
    const a = { onTranscribe: vi.fn(), onSaveAudio: vi.fn(), onDelete: vi.fn(), onOpen: vi.fn() };
    render(<AfterCallCard item={ITEM} screens={3} actions={a} now={Date.parse("2026-11-17T10:04:20Z")} />);
    const card = screen.getByRole("region", { name: "After the call: Call with Omar Farooq · 4:12" });
    expect(within(card).getByText("3 screens")).toBeInTheDocument();
    const buttons = within(card).getAllByRole("button").map((b) => b.textContent);
    expect(buttons.slice(1)).toEqual(["Transcribe", "Save audio", "Delete"]);
    expect(within(card).getByRole("button", { name: "Transcribe" })).toHaveClass("el-button--primary");
    fireEvent.click(within(card).getByRole("button", { name: /Rename or choose a project$/ }));
    fireEvent.click(within(card).getByRole("button", { name: "Transcribe" }));
    fireEvent.click(within(card).getByRole("button", { name: "Save audio" }));
    fireEvent.click(within(card).getByRole("button", { name: "Delete" }));
    expect([a.onOpen, a.onTranscribe, a.onSaveAudio, a.onDelete].map((f) => f.mock.calls.length)).toEqual([1, 1, 1, 1]);
    expect(within(card).getByRole("progressbar", { name: "Time to answer" })).toHaveAttribute("aria-valuetext", "Saves the audio in 8 seconds");
  });

  it("words and titles", () => {
    expect(cardTitle({ ...ITEM, callerName: null, sourceApp: "zoom" })).toBe("Zoom · 4:12");
    expect(cardTitle({ ...ITEM, mode: "voice_note", durationMs: 48_000 })).toBe("Voice note · 0:48");
    expect(timeLeftText(1)).toBe("Saves the audio in 1 second");
    expect(timeLeftText(0)).toBe("Saving the audio");
  });
});

describe("the after-call stack", () => {
  const STATE: AfterCallState = { saved: null, discarded: null, ask: ITEM };

  it("Transcribe keeps the call; Save audio keeps it as audio only", async () => {
    respond("pending_keep", null);
    respond("pending_keep_audio", null);
    const onGone = vi.fn();
    const { rerender } = render(<AfterCallStack state={STATE} onGone={onGone} />);
    fireEvent.click(screen.getByRole("button", { name: "Transcribe" }));
    await act(flushAsync);
    expect(callsOf("pending_keep")).toEqual([{ id: "p1" }]);
    expect(onGone).toHaveBeenCalledWith("ask");
    rerender(<AfterCallStack state={{ ...STATE, ask: { ...ITEM, id: "p2" } }} onGone={onGone} />);
    fireEvent.click(screen.getByRole("button", { name: "Save audio" }));
    await act(flushAsync);
    expect(callsOf("pending_keep_audio")).toEqual([{ id: "p2" }]);
  });

  it("an older core without audio-only says so on the card", async () => {
    fail("pending_keep_audio", "This version of EchoLedger can't save audio only yet.");
    render(<AfterCallStack state={STATE} onGone={() => undefined} />);
    fireEvent.click(screen.getByRole("button", { name: "Save audio" }));
    await act(flushAsync);
    expect(screen.getByRole("alert")).toHaveTextContent("can't save audio only yet");
  });

  it("Delete goes through the Toast with Undo", async () => {
    respond("after_call_discard", null);
    respond("after_call_discard_undo", null);
    const onGone = vi.fn();
    const { rerender } = render(<AfterCallStack state={STATE} onGone={onGone} />);
    fireEvent.click(screen.getByRole("button", { name: "Delete" }));
    await act(flushAsync);
    expect(callsOf("after_call_discard")).toEqual([{ id: "p1" }]);
    const gone = { id: "p1", title: null, callerName: "Omar Farooq", sourceApp: "whatsapp", mode: "call" as const, durationMs: 252_000, until: "2026-11-17T10:05:00Z" };
    rerender(<AfterCallStack state={{ saved: null, discarded: gone, ask: null }} onGone={onGone} />);
    const toast = screen.getByRole("status", { name: "Notifications" });
    expect(toast).toHaveTextContent("Deleted · Call with Omar Farooq · 4:12");
    fireEvent.click(within(toast).getByRole("button", { name: "Undo" }));
    await act(flushAsync);
    expect(callsOf("after_call_discard_undo")).toEqual([{ id: "p1" }]);
    expect(onGone).toHaveBeenCalledWith("discarded");
  });
});

describe("the windows with the flags", () => {
  const REC: RecordingState = {
    active: true, paused: false, elapsedMs: 134_000, sourceApp: "whatsapp", mode: "call", captions: false, levels: [0, 0],
    auto: true, hidden: false, language: null, bluetoothMic: false, screens: null,
  };

  it("ui_v2.recording_controls draws the new pill in the pill window", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.recording_controls": true } });
    respond("recording_state", REC);
    respond("set_hit_regions", null);
    respond("window_ready", null);
    render(<PillApp />);
    await act(flushAsync);
    await act(() => emitEvent("pill:visibility", true));
    await act(flushAsync);
    expect(await screen.findByTestId("recording-pill-v2")).toBeInTheDocument();
    expect(screen.queryByTestId("recording-pill")).toBeNull();
  });

  it("ui_v2.after_call draws the new card in the after-call window", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.after_call": true } });
    respond("after_call_state", { saved: null, discarded: null, ask: ITEM });
    respond("set_hit_regions", null);
    respond("window_ready", null);
    render(<ReviewApp />);
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByTestId("after-call-card")).toBeInTheDocument();
  });
});

describe("flags reach the overlay windows on their next show", () => {
  const REC_ON: RecordingState = {
    active: true, paused: false, elapsedMs: 10_000, sourceApp: "zoom", mode: "call", captions: false, levels: [0, 0],
    auto: true, hidden: false, language: null, bluetoothMic: false, screens: null,
  };

  it("the pill: a flag turned on between two recordings shows the new pill on the next one, never mid-recording", async () => {
    respond("ui_flags", { ui_flags: {} });
    respond("recording_state", { ...REC_ON, active: false });
    respond("set_hit_regions", null);
    respond("window_ready", null);
    render(<PillApp />);
    await act(flushAsync);
    await act(() => emitEvent("recording:state", REC_ON));
    await act(flushAsync);
    expect(screen.getByTestId("recording-pill")).toBeInTheDocument();
    respond("ui_flags", { ui_flags: { "ui_v2.recording_controls": true } });
    await act(() => emitEvent("recording:state", { ...REC_ON, elapsedMs: 11_000 }));
    await act(flushAsync);
    expect(screen.getByTestId("recording-pill")).toBeInTheDocument();
    await act(() => emitEvent("recording:state", { ...REC_ON, active: false }));
    await act(flushAsync);
    await act(() => emitEvent("recording:state", REC_ON));
    await act(flushAsync);
    expect(await screen.findByTestId("recording-pill-v2")).toBeInTheDocument();
    expect(callsOf("ui_flags")).toHaveLength(3);
  });

  it("the after-call window: switching back to classic between two shows draws the classic chip next time", async () => {
    respond("ui_flags", { ui_flags: { "ui_v2.after_call": true } });
    respond("after_call_state", { saved: null, discarded: null, ask: null });
    respond("set_hit_regions", null);
    respond("window_ready", null);
    render(<ReviewApp />);
    await act(flushAsync);
    await act(() => emitEvent("aftercall:state", { saved: null, discarded: null, ask: ITEM }));
    await act(flushAsync);
    expect(await screen.findByTestId("after-call-card")).toBeInTheDocument();
    respond("ui_flags", { ui_flags: {}, ui_classic: true });
    await act(() => emitEvent("aftercall:state", { saved: null, discarded: null, ask: null }));
    await act(flushAsync);
    await act(() => emitEvent("aftercall:state", { saved: null, discarded: null, ask: { ...ITEM, id: "p9" } }));
    await act(flushAsync);
    expect(screen.queryByTestId("after-call-card")).toBeNull();
    expect(document.querySelector(".review-root")!.textContent).toMatch(/Call with Omar Farooq/);
  });
});
