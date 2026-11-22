import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import ReviewApp from "./ReviewApp";
import { callsOf, emitEvent, fail, flushAsync, listenerCount, resetTauriMock, respond, respondAll, setWindowLabel } from "../../test/tauri";
import { makeAfterCallState, makeAskPayload, makeCallDone, makeChipItem } from "../../test/fixtures";
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

// What is shown: the card, a confirmation, the grouped card.
const chip = () => document.querySelector<HTMLElement>("[data-testid=keep-chip]");
const done = () => screen.queryByTestId("call-done");
const askChip = () => screen.queryByTestId("ask-chip");
const button = (name: string | RegExp) => screen.getByRole("button", { name });

function setup() {
  resetTauriMock();
  setWindowLabel("review");
  vi.stubGlobal("ResizeObserver", NoopResizeObserver);
  respondAll({
    overlay_set_hit_regions: undefined, pending_open: undefined, ask_answer: undefined, call_card_answer: undefined, call_undo: undefined,
    call_done_transcribe: undefined, call_always: undefined, call_group_close: undefined,
  });
}

describe("the card (Ask me, after the call)", () => {
  beforeEach(setup);
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("reads what to show when the window starts: the logo, the name and 0:44 · just now, Delete and Transcribe", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1", { callerName: "Muneeb Quraishi", durationMs: 44_000 }) }));
    const card = chip()!;
    expect(card).toHaveTextContent("Muneeb Quraishi");
    expect(card).toHaveTextContent("0:44 · just now");
    expect(card).toHaveTextContent("Swipe away to keep audio only");
    // One filled button, Transcribe; Delete is a quiet text button; the × saves the audio only.
    expect(button("Transcribe")).toHaveClass("ctl-btn", "primary");
    expect(button("Delete")).toHaveClass("call-text-btn");
    expect(button("Save audio only")).toBeInTheDocument();
    expect(card.querySelectorAll(".primary")).toHaveLength(1);
    expect(card.textContent).not.toMatch(/\b(Keep|Skip|Discard)\b/);
  });

  it("names the call by its title, else who it was with, else Call, readable in its own direction", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1", { title: null, callerName: null }) }));
    expect(chip()).toHaveTextContent(/^Call/);
    await emitEvent("aftercall:state", makeAfterCallState({ ask: makeChipItem("p2", { title: "عمر کے ساتھ ملاقات", callerName: "Omar" }) }));
    const title = chip()!.querySelector(".call-card-title")!;
    expect(title).toHaveTextContent("عمر کے ساتھ ملاقات");
    expect(title).toHaveAttribute("dir", "auto");
  });

  it("Transcribe and Delete send the card's answer; the core applies it after its Undo time", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1") }));
    fireEvent.click(button("Transcribe"));
    await flushAsync();
    fireEvent.click(button("Delete"));
    await flushAsync();
    expect(callsOf("call_card_answer")).toEqual([{ id: "p1", choice: "transcribe" }, { id: "p1", choice: "delete" }]);
    expect(callsOf("pending_keep")).toEqual([]);
  });

  it("the ×, Esc and a swipe away save the audio only", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1") }));
    fireEvent.click(button("Save audio only"));
    await flushAsync();
    fireEvent.keyDown(window, { key: "Escape" });
    await flushAsync();
    const card = chip()!;
    fireEvent.pointerDown(card, { button: 0, clientX: 200, pointerId: 1 });
    fireEvent.pointerMove(card, { clientX: 260, pointerId: 1 });
    fireEvent.pointerUp(card, { clientX: 260, pointerId: 1 });
    await flushAsync();
    expect(callsOf("call_card_answer")).toHaveLength(2);
    fireEvent.pointerDown(card, { button: 0, clientX: 200, pointerId: 1 });
    fireEvent.pointerMove(card, { clientX: 100, pointerId: 1 });
    fireEvent.pointerUp(card, { clientX: 100, pointerId: 1 });
    await flushAsync();
    expect(callsOf("call_card_answer")).toEqual([1, 2, 3].map(() => ({ id: "p1", choice: "audio" })));
  });

  it("8 s with no choice: the core sends Saved as audio, with Transcribe and Undo", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1") }));
    await emitEvent("aftercall:state", makeAfterCallState({ done: makeCallDone({ id: "p1", kind: "audio" }) }));
    expect(chip()).not.toBeInTheDocument();
    expect(done()).toHaveTextContent("Saved as audio");
    fireEvent.click(button("Transcribe"));
    await flushAsync();
    expect(callsOf("call_done_transcribe")).toEqual([{ id: "p1" }]);
  });

  it("the first card ever says where to change it", async () => {
    await renderWindow(makeAfterCallState({ hint: true, ask: makeChipItem("p1") }));
    expect(screen.getByTestId("call-hint")).toHaveTextContent("You can change this in Settings › After each call.");
    await emitEvent("aftercall:state", makeAfterCallState({ ask: makeChipItem("p2") }));
    expect(screen.queryByTestId("call-hint")).not.toBeInTheDocument();
  });

  it("the name opens the recording in the main window with its title field", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1", { callerName: "Omar" }) }));
    fireEvent.click(button(/^Omar, .*Rename or choose a project$/));
    await flushAsync();
    expect(callsOf("pending_open")).toEqual([{ id: "p1", rename: true }]);
  });

  it("draws the 8 s line over the time that is left, from where it is", async () => {
    vi.useFakeTimers({ toFake: ["Date"] });
    vi.setSystemTime(new Date("2026-11-04T10:00:04Z"));
    const item = makeChipItem("p1", { deadlineAt: "2026-11-04T10:00:08Z" });
    const { container } = await renderWindow(makeAfterCallState({ ask: item }));
    const line = container.querySelector<HTMLElement>(".call-card-line > span")!;
    expect(line.style.animationDuration).toBe("4s");
    expect(line.style.getPropertyValue("--from")).toBe("0.5");
  });

  it("keeps the card and says why when an answer fails", async () => {
    fail("call_card_answer", "This recording is no longer waiting.");
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1") }));
    fireEvent.click(button("Transcribe"));
    expect(await screen.findByRole("alert")).toHaveTextContent("This recording is no longer waiting.");
    expect(chip()).toBeInTheDocument();
  });

  it("removes its event listeners on unmount", async () => {
    const { unmount } = await renderWindow();
    await waitFor(() => expect(listenerCount("aftercall:state")).toBe(1));
    unmount();
    await waitFor(() => expect(listenerCount("aftercall:state")).toBe(0));
    expect(listenerCount("ask:show")).toBe(0);
  });
});

describe("the confirmation (one at a time, Undo for 5 s)", () => {
  beforeEach(setup);
  afterEach(() => vi.unstubAllGlobals());

  it.each([
    ["transcribe", "Transcribing…", false],
    ["audio", "Saved as audio", true],
    ["delete", "Deleted", false],
  ] as const)("after %s it says %s with Undo, and Undo brings the card back", async (kind, words, transcribe) => {
    await renderWindow(makeAfterCallState({ done: makeCallDone({ id: "p1", kind, callerName: "Omar" }) }));
    expect(done()).toHaveTextContent(words);
    expect(done()).toHaveTextContent("Omar");
    expect(screen.queryByRole("button", { name: "Transcribe" }) !== null).toBe(transcribe);
    fireEvent.click(button("Undo"));
    await flushAsync();
    expect(callsOf("call_undo")).toEqual([{ id: "p1" }]);
    await emitEvent("aftercall:state", makeAfterCallState({ ask: makeChipItem("p1") }));
    expect(done()).not.toBeInTheDocument();
    expect(chip()).toBeInTheDocument();
  });

  it("says so when Undo comes too late", async () => {
    fail("call_undo", "Too late to undo: it was already done.");
    await renderWindow(makeAfterCallState({ done: makeCallDone({ id: "p1", kind: "delete" }) }));
    fireEvent.click(button("Undo"));
    expect(await screen.findByRole("alert")).toHaveTextContent("Too late to undo: it was already done.");
  });

  it("after 3 same choices offers Always do this? once; Yes sets it", async () => {
    await renderWindow(makeAfterCallState({ done: makeCallDone({ id: "p1", kind: "audio", offerAlways: "always_audio" }) }));
    expect(screen.getByTestId("call-always")).toHaveTextContent("Always do this?");
    fireEvent.click(button("Yes"));
    await flushAsync();
    expect(callsOf("call_always")).toEqual([{ id: "p1", value: "always_audio" }]);
  });

  it("never shows two things at once: the card comes first, then the grouped card, then a confirmation", async () => {
    await renderWindow(makeAfterCallState({ ask: makeChipItem("p1"), group: { count: 2, until: new Date(Date.now() + 8000).toISOString() }, done: makeCallDone() }));
    expect(chip()).toBeInTheDocument();
    expect(done()).not.toBeInTheDocument();
    expect(screen.queryByTestId("call-group")).not.toBeInTheDocument();
  });
});

describe("back-to-back calls", () => {
  beforeEach(setup);
  afterEach(() => vi.unstubAllGlobals());

  it("one grouped card says how many were saved as audio; Review opens the Library, × closes it", async () => {
    await renderWindow(makeAfterCallState({ group: { count: 3, until: new Date(Date.now() + 8000).toISOString() } }));
    expect(screen.getByTestId("call-group")).toHaveTextContent("3 calls saved as audio");
    expect(document.querySelectorAll("[data-testid=keep-chip], [data-testid=call-group]")).toHaveLength(1);
    fireEvent.click(button("Review"));
    await flushAsync();
    expect(screen.queryByTestId("call-group")).not.toBeInTheDocument();
    await emitEvent("aftercall:state", makeAfterCallState({ group: { count: 4, until: new Date(Date.now() + 8000).toISOString() } }));
    expect(screen.getByTestId("call-group")).toHaveTextContent("4 calls saved as audio");
    fireEvent.click(button("Close"));
    await flushAsync();
    expect(callsOf("call_group_close")).toEqual([{ review: true }, { review: false }]);
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

  it("reports the confirmation while it is shown and nothing after it is gone", async () => {
    await renderWindow();
    await emitEvent("aftercall:state", makeAfterCallState({ done: makeCallDone() }));
    await waitFor(() => expect(lastRegions()).toEqual(pillRegion));
    await emitEvent("aftercall:state", makeAfterCallState());
    await waitFor(() => expect(lastRegions()).toEqual([]));
  });

  it("reports the card while it is shown", async () => {
    await renderWindow();
    await emitEvent("aftercall:state", makeAfterCallState({ ask: makeChipItem("p1") }));
    await waitFor(() => expect(lastRegions()).toEqual(pillRegion));
    await emitEvent("aftercall:state", makeAfterCallState());
    await waitFor(() => expect(lastRegions()).toEqual([]));
  });
});
