import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import ReviewApp from "./ReviewApp";
import { callsOf, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondAll, setWindowLabel } from "../../test/tauri";
import { makeAfterCallState, makeAskPayload, makeChipItem, makeSavedToast } from "../../test/fixtures";
import type { AfterCallState } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../../test/tauri")).windowModule);

class NoopResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
}

async function renderWindow(initial: AfterCallState = makeAfterCallState()) {
  respond("after_call_state", initial);
  const view = render(<ReviewApp />);
  await waitFor(() => expect(callsOf("after_call_state").length).toBeGreaterThan(0));
  await flushAsync();
  return view;
}

const toast = () => screen.queryByTestId("saved-toast");
const chip = () => screen.queryByTestId("keep-chip");
const askChip = () => screen.queryByTestId("ask-chip");
const button = (name: string | RegExp) => screen.getByRole("button", { name });

function setup() {
  resetTauriMock();
  setWindowLabel("review");
  vi.stubGlobal("ResizeObserver", NoopResizeObserver);
  respondAll({ overlay_set_hit_regions: undefined, saved_undo: undefined, pending_keep: undefined, pending_discard: undefined, pending_open: undefined, ask_answer: undefined });
}

describe("Saved toast (Keep automatically)", () => {
  beforeEach(setup);
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("reads what to show when the window starts: one line, Saved · title · length · Undo", async () => {
    await renderWindow(makeAfterCallState({ saved: makeSavedToast({ callerName: "Omar", durationMs: 252_000 }) }));
    expect(toast()).toHaveTextContent("Saved·Call with Omar·4:12Undo");
    expect(toast()).toHaveAccessibleName("Saved: Call with Omar");
    expect(button("Undo")).toBeInTheDocument();
    expect(chip()).not.toBeInTheDocument();
  });

  it("names the recording by its title, else the app, and a voice note as one", async () => {
    await renderWindow(makeAfterCallState({ saved: makeSavedToast({ title: "Budget review", callerName: "Omar" }) }));
    expect(toast()).toHaveTextContent("Budget review");
    await emitEvent("aftercall:state", makeAfterCallState({ saved: makeSavedToast({ id: "rec-2", callerName: null, sourceApp: "zoom" }) }));
    expect(toast()).toHaveTextContent("Saved·Zoom·4:12");
    await emitEvent("aftercall:state", makeAfterCallState({ saved: makeSavedToast({ id: "rec-3", callerName: null, sourceApp: "voice_note", mode: "mic_note", durationMs: 42_000 }) }));
    expect(toast()).toHaveTextContent("Saved·Voice note·0:42");
  });

  it("keeps a long or Urdu title on one line, readable in its own direction", async () => {
    const long = "Quarterly planning with the whole Northwind team about the second phase of the website and its budget";
    await renderWindow(makeAfterCallState({ saved: makeSavedToast({ title: "کال اسلم کے ساتھ" }) }));
    const urdu = screen.getByText("کال اسلم کے ساتھ");
    expect(urdu).toHaveAttribute("dir", "auto");
    await emitEvent("aftercall:state", makeAfterCallState({ saved: makeSavedToast({ id: "rec-2", title: long }) }));
    expect(screen.getByText(long)).toHaveAttribute("title", long);
    expect(screen.getByText(long)).toHaveClass("after-title");
  });

  it("Undo takes the recording back and the toast goes", async () => {
    await renderWindow(makeAfterCallState({ saved: makeSavedToast({ id: "rec-9" }) }));
    fireEvent.click(button("Undo"));
    await waitFor(() => expect(callsOf("saved_undo")).toEqual([{ id: "rec-9" }]));
    await flushAsync();
    expect(toast()).not.toBeInTheDocument();
  });

  it("shows why Undo did not work and keeps Undo there", async () => {
    fail("saved_undo", "Network error: the server could not be reached");
    await renderWindow(makeAfterCallState({ saved: makeSavedToast() }));
    fireEvent.click(button("Undo"));
    expect(await screen.findByRole("alert")).toHaveTextContent("Network error: the server could not be reached");
    expect(button("Undo")).toBeInTheDocument();
  });

  it("goes by itself when its six seconds are over", async () => {
    // Rendered first: waitFor drains with a real setTimeout, which a faked one would never run.
    await renderWindow();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout", "Date"] });
    vi.setSystemTime(new Date("2026-11-04T10:00:00Z"));
    await emitEvent("aftercall:state", makeAfterCallState({ saved: makeSavedToast({}, Date.now()) }));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(5_900);
    });
    expect(toast()).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(200);
    });
    expect(toast()).not.toBeInTheDocument();
    expect(callsOf("saved_undo")).toHaveLength(0);
  });

  it("follows the core: a newer toast replaces it and an empty state hides it", async () => {
    await renderWindow();
    expect(toast()).not.toBeInTheDocument();
    await emitEvent("aftercall:state", makeAfterCallState({ saved: makeSavedToast({ id: "a", callerName: "Omar" }) }));
    expect(toast()).toHaveTextContent("Call with Omar");
    await emitEvent("aftercall:state", makeAfterCallState({ saved: makeSavedToast({ id: "b", callerName: "Sara" }) }));
    expect(toast()).toHaveTextContent("Call with Sara");
    await emitEvent("aftercall:state", makeAfterCallState());
    expect(toast()).not.toBeInTheDocument();
  });
});

describe("Ask-me chip", () => {
  beforeEach(setup);
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("shows the title and length with Keep and Discard, pill-size", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1", { callerName: "Omar" }) }));
    expect(chip()).toHaveAccessibleName("Keep Call with Omar?");
    expect(button(/^Call with Omar/)).toHaveTextContent("Call with Omar·4:12");
    expect(button("Keep")).toBeInTheDocument();
    expect(button("Discard")).toBeInTheDocument();
    expect(toast()).not.toBeInTheDocument();
  });

  it("✓ keeps and ✕ discards the recording, and the chip goes", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1") }));
    fireEvent.click(button("Keep"));
    await waitFor(() => expect(callsOf("pending_keep")).toEqual([{ id: "p1" }]));
    await flushAsync();
    expect(chip()).not.toBeInTheDocument();

    await emitEvent("aftercall:state", makeAfterCallState({ ask: makeChipItem("p2") }));
    fireEvent.click(button("Discard"));
    await waitFor(() => expect(callsOf("pending_discard")).toEqual([{ id: "p2" }]));
    await flushAsync();
    expect(chip()).not.toBeInTheDocument();
  });

  it("the title opens the recording in the main window with its title field", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1") }));
    fireEvent.click(button(/rename or choose a client/));
    await waitFor(() => expect(callsOf("pending_open")).toEqual([{ id: "p1", rename: true }]));
    await flushAsync();
    expect(chip()).not.toBeInTheDocument();
    expect(callsOf("pending_keep")).toHaveLength(0);
  });

  it("draws the timer line over the time that is left, from where it is", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-11-04T10:00:04Z"));
    const item = makeChipItem("p1", { deadlineAt: "2026-11-04T10:00:10Z" });
    const { container } = await renderWindow(makeAfterCallState({ ask: item }));
    const line = container.querySelector<HTMLElement>(".after-progress > span")!;
    expect(line.style.animationDuration).toBe("6s");
    expect(line.style.getPropertyValue("--from")).toBe("0.6");
  });

  it("keeps the chip and says why when an answer fails", async () => {
    fail("pending_keep", "This recording is no longer waiting.");
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1") }));
    fireEvent.click(button("Keep"));
    expect(await screen.findByRole("alert")).toHaveTextContent("This recording is no longer waiting.");
    expect(chip()).toBeInTheDocument();
  });

  it("shows with a Saved toast of another recording, the chip nearest the tray", async () => {
    const { container } = await renderWindow(makeAfterCallState({ saved: makeSavedToast({ mode: "mic_note", sourceApp: "voice_note" }), ask: makeChipItem("p1") }));
    const pills = [...container.querySelectorAll(".after-stack > .after-pill")].map((el) => el.getAttribute("data-testid"));
    expect(pills).toEqual(["saved-toast", "keep-chip"]);
  });

  it("removes its event listeners on unmount", async () => {
    const { unmount } = await renderWindow();
    await waitFor(() => expect(listenerCount("aftercall:state")).toBe(1));
    unmount();
    await waitFor(() => expect(listenerCount("aftercall:state")).toBe(0));
    expect(listenerCount("ask:show")).toBe(0);
  });
});

describe("ask chip", () => {
  beforeEach(setup);
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("names the site for a browser and offers Record, Ignore, Always ignore this site", async () => {
    await renderWindow();
    await emitEvent("ask:show", makeAskPayload({ name: "chrome.exe", site: "call.example.test" }));
    expect(askChip()).toBeInTheDocument();
    expect(screen.getByTestId("ask-text")).toHaveTextContent("call.example.test is using the microphone");
    expect(button("Record")).toBeInTheDocument();
    expect(button("Ignore")).toBeInTheDocument();
    expect(button("Always ignore this site")).toBeInTheDocument();
  });

  it("names the app and offers Always ignore this app for a non-browser", async () => {
    await renderWindow();
    await emitEvent("ask:show", makeAskPayload({ name: "Softphone.exe", site: null }));
    expect(screen.getByTestId("ask-text")).toHaveTextContent("Softphone is using the microphone");
    expect(button("Always ignore this app")).toBeInTheDocument();
  });

  it.each([
    ["Record", "record"],
    ["Ignore", "ignore"],
    ["Always ignore this site", "always_ignore"],
  ])("%s answers %s and closes the chip", async (name, answer) => {
    await renderWindow();
    await emitEvent("ask:show", makeAskPayload({ id: "ask-7" }));
    fireEvent.click(button(name));
    await waitFor(() => expect(callsOf("ask_answer")).toEqual([{ id: "ask-7", answer }]));
    await flushAsync();
    expect(askChip()).not.toBeInTheDocument();
  });

  it("keeps the chip and shows the message when the answer fails", async () => {
    fail("ask_answer", "Not paired");
    await renderWindow();
    await emitEvent("ask:show", makeAskPayload());
    fireEvent.click(button("Record"));
    expect(await screen.findByRole("alert")).toHaveTextContent("Not paired");
    expect(askChip()).toBeInTheDocument();
  });

  it("hides itself after the payload's seconds", async () => {
    await renderWindow();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    await emitEvent("ask:show", makeAskPayload({ seconds: 8 }));
    await act(async () => {
      await vi.advanceTimersByTimeAsync(7_900);
    });
    expect(askChip()).toBeInTheDocument();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(200);
    });
    expect(askChip()).not.toBeInTheDocument();
    expect(callsOf("ask_answer")).toHaveLength(0);
  });

  it("goes away on ask:hide", async () => {
    await renderWindow();
    await emitEvent("ask:show", makeAskPayload());
    await emitEvent("ask:hide", null);
    expect(askChip()).not.toBeInTheDocument();
  });

  it("shows in front of the after-call chip, which comes back when it goes", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1") }));
    await emitEvent("ask:show", makeAskPayload());
    expect(askChip()).toBeInTheDocument();
    expect(chip()).not.toBeInTheDocument();
    await emitEvent("ask:hide", null);
    expect(chip()).toBeInTheDocument();
  });
});

describe("after-call hit regions", () => {
  const rect = (x: number, y: number, w: number, h: number) => ({ left: x, top: y, width: w, height: h, right: x + w, bottom: y + h, x, y, toJSON: () => ({}) }) as DOMRect;

  beforeEach(() => {
    setup();
    vi.stubGlobal("devicePixelRatio", 2);
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      return this.hasAttribute("data-hit") ? rect(100, 122, 250, 36) : rect(0, 0, 0, 0);
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  const lastRegions = () => {
    const calls = callsOf("overlay_set_hit_regions");
    return calls[calls.length - 1]?.regions;
  };
  const pillRegion = [{ x: (100 - 6) * 2, y: (122 - 6) * 2, w: (250 + 12) * 2, h: (36 + 12) * 2 }];

  it("reports no hit regions when there is nothing to show", async () => {
    await renderWindow();
    await waitFor(() => expect(lastRegions()).toEqual([]));
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("reports the toast while it is shown and nothing after it is gone", async () => {
    await renderWindow();
    await emitEvent("aftercall:state", makeAfterCallState({ saved: makeSavedToast() }));
    await waitFor(() => expect(lastRegions()).toEqual(pillRegion));
    await emitEvent("aftercall:state", makeAfterCallState());
    await waitFor(() => expect(lastRegions()).toEqual([]));
  });

  it("reports the chip while it is shown", async () => {
    await renderWindow();
    await emitEvent("aftercall:state", makeAfterCallState({ ask: makeChipItem("p1") }));
    await waitFor(() => expect(lastRegions()).toEqual(pillRegion));
    await emitEvent("aftercall:state", makeAfterCallState());
    await waitFor(() => expect(lastRegions()).toEqual([]));
  });
});
