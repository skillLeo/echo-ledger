import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import PillApp from "./PillApp";
import { callsOf, currentWindow, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondAll, respondWith, setWindowLabel } from "../../test/tauri";
import { makeRecording, makeRecordingState, makeStatus } from "../../test/fixtures";
import type { RecordingState } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../../test/tauri")).windowModule);

class NoopResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
}

const recordingStatus = (overrides = {}) => makeStatus({ recording: makeRecording(), ...overrides });

async function renderPill(state: RecordingState = makeRecordingState()) {
  respond("recording_state", state);
  const view = render(<PillApp />);
  await waitFor(() => expect(callsOf("recording_state").length).toBeGreaterThan(0));
  await flushAsync();
  return view;
}

const pill = () => screen.queryByTestId("recording-pill");
const button = (name: string) => screen.getByRole("button", { name });

describe("recording pill", () => {
  beforeEach(() => {
    resetTauriMock();
    setWindowLabel("pill");
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    respondAll({
      overlay_set_hit_regions: undefined,
      pause_recording: recordingStatus({ paused: true }),
      resume_recording: recordingStatus(),
      stop_recording: makeStatus(),
      cancel_recording: makeStatus(),
      toggle_captions: recordingStatus({ captionsLive: true }),
      set_pill_hidden: recordingStatus({ pillHidden: true }),
      pill_moved: undefined,
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("asks the core for the current state on mount and shows REC, timer, source and meters", async () => {
    await renderPill(makeRecordingState({ elapsedMs: 65_000, sourceApp: "zoom", levels: [0.1, 0.2] }));
    expect(pill()).toBeInTheDocument();
    expect(screen.getByText("REC")).toBeInTheDocument();
    expect(screen.getByTestId("pill-timer")).toHaveTextContent("1:05");
    expect(screen.getByText("Zoom")).toBeInTheDocument();
    const bars = screen.getByTestId("pill-meters").querySelectorAll(".rec-meter > span");
    expect([...bars].map((b) => (b as HTMLElement).style.height)).toEqual(["40%", "80%"]);
    for (const name of ["Pause recording", "Stop and review", "Cancel recording", "Turn captions on", "Hide controls"]) {
      expect(button(name)).toHaveAttribute("title", name);
    }
  });

  it("the language chip steps to the next language and tells the core, which reconnects captions", async () => {
    respond("set_recording_language", undefined);
    await renderPill(makeRecordingState({ language: "ur", captions: true }));
    const chip = screen.getByTestId("pill-language");
    expect(chip).toHaveTextContent("UR");
    respond("recording_state", makeRecordingState({ language: "ar", captions: true }));
    fireEvent.click(chip);
    expect(chip).toHaveTextContent("AR");
    await waitFor(() => expect(callsOf("set_recording_language")).toEqual([{ language: "ar" }]));
    await flushAsync();
    expect(pill()).toBeInTheDocument();
    expect(screen.getByTestId("pill-language")).toHaveTextContent("AR");
    expect(callsOf("stop_recording")).toHaveLength(0);
  });

  it("renders nothing while no recording runs, and appears with the first recording:state", async () => {
    await renderPill(makeRecordingState({ active: false, elapsedMs: 0, sourceApp: null, mode: null }));
    expect(pill()).not.toBeInTheDocument();
    await emitEvent("recording:state", makeRecordingState({ elapsedMs: 0 }));
    expect(pill()).toBeInTheDocument();
    expect(screen.getByTestId("pill-timer")).toHaveTextContent("0:00");
    await emitEvent("recording:state", makeRecordingState({ active: false }));
    expect(pill()).not.toBeInTheDocument();
  });

  it("stays hidden while the recording is hidden and follows pill:visibility", async () => {
    await renderPill(makeRecordingState({ hidden: true }));
    expect(pill()).not.toBeInTheDocument();
    respond("recording_state", makeRecordingState({ hidden: false }));
    await emitEvent("pill:visibility", true);
    await flushAsync();
    expect(pill()).toBeInTheDocument();
    await emitEvent("pill:visibility", false);
    expect(pill()).not.toBeInTheDocument();
  });

  it("Pause calls pauseRecording and shows the paused state at once", async () => {
    await renderPill();
    respond("recording_state", makeRecordingState({ paused: true }));
    fireEvent.click(button("Pause recording"));
    expect(screen.getByText("Paused")).toBeInTheDocument();
    expect(screen.queryByText("REC")).not.toBeInTheDocument();
    expect(button("Resume recording")).toBeInTheDocument();
    await waitFor(() => expect(callsOf("pause_recording")).toHaveLength(1));
    expect(callsOf("resume_recording")).toHaveLength(0);
  });

  it("in the paused state shows Paused, the resume label, and Resume calls resumeRecording", async () => {
    await renderPill(makeRecordingState({ paused: true, elapsedMs: 125_000 }));
    expect(screen.getByText("Paused")).toBeInTheDocument();
    expect(pill()).toHaveClass("paused");
    expect(screen.getByTestId("pill-timer")).toHaveTextContent("2:05");
    respond("recording_state", makeRecordingState({ elapsedMs: 125_000 }));
    fireEvent.click(button("Resume recording"));
    await waitFor(() => expect(callsOf("resume_recording")).toHaveLength(1));
    expect(callsOf("pause_recording")).toHaveLength(0);
    await flushAsync();
    expect(screen.getByText("REC")).toBeInTheDocument();
  });

  it("Stop calls stopRecording and the pill goes away at once", async () => {
    let finish: (value: unknown) => void = () => undefined;
    respondWith("stop_recording", () => new Promise((resolve) => (finish = resolve)));
    await renderPill();
    fireEvent.click(button("Stop and review"));
    expect(pill()).not.toBeInTheDocument();
    await waitFor(() => expect(callsOf("stop_recording")).toHaveLength(1));
    // the once-a-second event sent while the core is still stopping does not bring it back
    await emitEvent("recording:state", makeRecordingState());
    expect(pill()).not.toBeInTheDocument();
    respond("recording_state", makeRecordingState({ active: false }));
    await act(async () => finish(makeStatus()));
    await flushAsync();
    expect(pill()).not.toBeInTheDocument();
    expect(callsOf("cancel_recording")).toHaveLength(0);
  });

  it("Cancel asks 'Discard?' and Yes calls cancelRecording", async () => {
    await renderPill();
    fireEvent.click(button("Cancel recording"));
    expect(screen.getByText("Discard?")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Stop and review" })).not.toBeInTheDocument();
    expect(callsOf("cancel_recording")).toHaveLength(0);
    respond("recording_state", makeRecordingState({ active: false }));
    fireEvent.click(button("Yes"));
    await waitFor(() => expect(callsOf("cancel_recording")).toHaveLength(1));
    expect(callsOf("stop_recording")).toHaveLength(0);
    await flushAsync();
    expect(pill()).not.toBeInTheDocument();
  });

  it("Cancel then No restores the buttons without discarding", async () => {
    await renderPill();
    fireEvent.click(button("Cancel recording"));
    fireEvent.click(button("No"));
    expect(screen.queryByText("Discard?")).not.toBeInTheDocument();
    expect(button("Stop and review")).toBeInTheDocument();
    expect(callsOf("cancel_recording")).toHaveLength(0);
  });

  it("Escape answers No to the discard question", async () => {
    await renderPill();
    fireEvent.click(button("Cancel recording"));
    fireEvent.keyDown(window, { key: "Escape" });
    expect(screen.queryByText("Discard?")).not.toBeInTheDocument();
    expect(callsOf("cancel_recording")).toHaveLength(0);
  });

  it("Captions calls toggleCaptions and reports its pressed state", async () => {
    await renderPill(makeRecordingState({ captions: false }));
    expect(button("Turn captions on")).toHaveAttribute("aria-pressed", "false");
    respond("recording_state", makeRecordingState({ captions: true }));
    fireEvent.click(button("Turn captions on"));
    expect(button("Turn captions off")).toHaveAttribute("aria-pressed", "true");
    await waitFor(() => expect(callsOf("toggle_captions")).toHaveLength(1));
    await flushAsync();
    expect(button("Turn captions off")).toHaveAttribute("aria-pressed", "true");
  });

  it("Hide calls setPillHidden(true) and the pill goes away", async () => {
    await renderPill();
    respond("recording_state", makeRecordingState({ hidden: true }));
    fireEvent.click(button("Hide controls"));
    expect(pill()).not.toBeInTheDocument();
    await waitFor(() => expect(callsOf("set_pill_hidden")).toEqual([{ hidden: true }]));
  });

  it("shows a failed action's message and resyncs with the core", async () => {
    fail("resume_recording", "The microphone is in use by another app.");
    await renderPill(makeRecordingState({ paused: true }));
    fireEvent.click(button("Resume recording"));
    expect(await screen.findByRole("alert")).toHaveTextContent("The microphone is in use by another app.");
    await flushAsync();
    expect(screen.getByText("Paused")).toBeInTheDocument();
    expect(button("Resume recording")).toBeInTheDocument();
  });

  it("brings the pill back when Stop fails", async () => {
    fail("stop_recording", "Nothing is recording.");
    await renderPill();
    fireEvent.click(button("Stop and review"));
    expect(await screen.findByRole("alert")).toHaveTextContent("Nothing is recording.");
    expect(pill()).toBeInTheDocument();
  });

  it("hides the meters for single-channel voice notes and labels them", async () => {
    await renderPill(makeRecordingState({ mode: "mic_note", sourceApp: "voice_note" }));
    expect(screen.queryByTestId("pill-meters")).not.toBeInTheDocument();
    expect(screen.getByText("Voice note")).toBeInTheDocument();
  });

  it("formats long recordings as h:mm:ss", async () => {
    await renderPill(makeRecordingState({ elapsedMs: 3_725_000 }));
    expect(screen.getByTestId("pill-timer")).toHaveTextContent("1:02:05");
  });

  it("interpolates the timer between events and freezes it while paused", async () => {
    await renderPill(makeRecordingState({ active: false }));
    vi.useFakeTimers({ toFake: ["setInterval", "clearInterval", "Date"] });
    await emitEvent("recording:state", makeRecordingState({ elapsedMs: 10_000 }));
    expect(screen.getByTestId("pill-timer")).toHaveTextContent("0:10");
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2_100);
    });
    expect(screen.getByTestId("pill-timer")).toHaveTextContent("0:12");
    await emitEvent("recording:state", makeRecordingState({ elapsedMs: 12_100, paused: true }));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(5_000);
    });
    expect(screen.getByTestId("pill-timer")).toHaveTextContent("0:12");
  });

  it("drags from the pill surface, then saves the position 400 ms later", async () => {
    await renderPill();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    await act(async () => {
      fireEvent.mouseDown(screen.getByText("Zoom"), { button: 0 });
    });
    expect(currentWindow.startDragging).toHaveBeenCalledTimes(1);
    expect(callsOf("pill_moved")).toHaveLength(0);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(400);
    });
    expect(callsOf("pill_moved")).toHaveLength(1);
  });

  it("does not start a drag from a button or with another mouse button", async () => {
    await renderPill();
    fireEvent.mouseDown(button("Hide controls"), { button: 0 });
    fireEvent.mouseDown(screen.getByText("Zoom"), { button: 2 });
    expect(currentWindow.startDragging).not.toHaveBeenCalled();
  });

  it("removes its event listeners on unmount", async () => {
    const { unmount } = await renderPill();
    await waitFor(() => expect(listenerCount("recording:state")).toBe(1));
    expect(listenerCount("pill:visibility")).toBe(1);
    unmount();
    await waitFor(() => expect(listenerCount("recording:state")).toBe(0));
    expect(listenerCount("pill:visibility")).toBe(0);
  });
});

describe("pill hit regions", () => {
  const rect = (x: number, y: number, w: number, h: number) => ({ left: x, top: y, width: w, height: h, right: x + w, bottom: y + h, x, y, toJSON: () => ({}) }) as DOMRect;

  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    vi.stubGlobal("devicePixelRatio", 1);
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      return this.hasAttribute("data-hit") ? rect(4, 4, 272, 36) : rect(0, 0, 0, 0);
    });
    respond("overlay_set_hit_regions", undefined);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  const lastRegions = () => {
    const calls = callsOf("overlay_set_hit_regions");
    return calls[calls.length - 1]?.regions;
  };

  it("reports the whole pill while recording and nothing once it stops", async () => {
    await renderPill(makeRecordingState({ active: false }));
    await waitFor(() => expect(lastRegions()).toEqual([]));
    await emitEvent("recording:state", makeRecordingState());
    await waitFor(() => expect(lastRegions()).toEqual([{ x: 4 - 6, y: 4 - 6, w: 272 + 12, h: 36 + 12 }]));
    await emitEvent("recording:state", makeRecordingState({ active: false }));
    await waitFor(() => expect(lastRegions()).toEqual([]));
  });
});

describe("bookmarks and the silent-other-side warning on the pill", () => {
  beforeEach(() => {
    resetTauriMock();
    setWindowLabel("pill");
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    respondAll({
      overlay_set_hit_regions: undefined,
      mark_moment: { atMs: 65_000, tag: null, note: null, count: 1 },
      tag_last_mark: undefined,
      undo_mark: undefined,
      marker_tags: ["Requirement", "Budget", "Deadline"],
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  const marked = (overrides = {}) => emitEvent("recording:marked", { atMs: 65_000, tag: null, note: null, count: 1, ...overrides });

  it("has a Mark button that bookmarks the recording", async () => {
    await renderPill();
    fireEvent.click(button("Bookmark this moment"));
    await waitFor(() => expect(callsOf("mark_moment")).toHaveLength(1));
  });

  it("shows the bookmark time with the tag presets after a bookmark, also one made by the shortcut", async () => {
    await renderPill();
    await marked();
    await flushAsync();
    const tray = screen.getByTestId("mark-tray");
    expect(tray).toHaveTextContent("Bookmarked 1:05");
    await waitFor(() => expect(screen.getByRole("button", { name: "Budget" })).toHaveAttribute("aria-pressed", "false"));
  });

  it("tags the bookmark with one click and clears the tag with a second", async () => {
    await renderPill();
    await marked();
    await flushAsync();
    fireEvent.click(await screen.findByRole("button", { name: "Budget" }));
    expect(callsOf("tag_last_mark").at(-1)).toEqual({ tag: "Budget", note: null });
    expect(screen.getByRole("button", { name: "Budget" })).toHaveAttribute("aria-pressed", "true");
    fireEvent.click(screen.getByRole("button", { name: "Budget" }));
    expect(callsOf("tag_last_mark").at(-1)).toEqual({ tag: null, note: null });
  });

  it("undoes the bookmark and closes the tray", async () => {
    await renderPill();
    await marked();
    await flushAsync();
    fireEvent.click(button("Undo"));
    expect(callsOf("undo_mark")).toHaveLength(1);
    expect(screen.queryByTestId("mark-tray")).not.toBeInTheDocument();
  });

  it("closes the tray by itself after a few seconds", async () => {
    await renderPill();
    vi.useFakeTimers();
    await act(async () => {
      await marked();
    });
    expect(screen.getByTestId("mark-tray")).toBeInTheDocument();
    await act(async () => {
      vi.advanceTimersByTime(6_100);
    });
    expect(screen.queryByTestId("mark-tray")).not.toBeInTheDocument();
  });

  it("warns when the other side has been silent and hides the warning when it is heard again", async () => {
    await renderPill();
    await emitEvent("capture:far_side", { silent: true, seconds: 30 });
    expect(screen.getByRole("alert")).toHaveTextContent("Not hearing the other side for 30 s");
    await emitEvent("capture:far_side", { silent: false, seconds: 0 });
    expect(screen.queryByTestId("far-side-warning")).not.toBeInTheDocument();
  });

  it("lets the user dismiss the warning, and shows nothing while paused", async () => {
    await renderPill();
    await emitEvent("capture:far_side", { silent: true, seconds: 31 });
    fireEvent.click(button("Dismiss warning"));
    expect(screen.queryByTestId("far-side-warning")).not.toBeInTheDocument();
    await emitEvent("recording:state", makeRecordingState({ paused: true }));
    await emitEvent("capture:far_side", { silent: true, seconds: 40 });
    expect(screen.queryByTestId("far-side-warning")).not.toBeInTheDocument();
  });

  it("forgets the tray and the warning when the recording ends", async () => {
    await renderPill();
    await marked();
    await emitEvent("capture:far_side", { silent: true, seconds: 30 });
    await emitEvent("recording:state", makeRecordingState({ active: false, sourceApp: null, mode: null }));
    await emitEvent("recording:state", makeRecordingState());
    expect(screen.queryByTestId("mark-tray")).not.toBeInTheDocument();
    expect(screen.queryByTestId("far-side-warning")).not.toBeInTheDocument();
  });
});
