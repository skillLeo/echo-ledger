import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import DictationApp, { ENDED_MESSAGE_MS } from "./DictationApp";
import { callsOf, emitEvent, listenerCount, pushChannelMessage, resetTauriMock, respond } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
const playTone = vi.hoisted(() => vi.fn(() => true));
vi.mock("../../lib/sounds", () => ({ playTone }));

async function renderDictation() {
  const view = render(<DictationApp />);
  await waitFor(() => expect(callsOf("subscribe_captions")).toHaveLength(1));
  return view;
}

const root = (container: HTMLElement) => container.querySelector(".overlay-root") as HTMLElement;
const textLine = (container: HTMLElement) => container.querySelector(".dictation-card .caption-line:last-child") as HTMLElement;

describe("dictation box", () => {
  beforeEach(() => {
    resetTauriMock();
    playTone.mockClear();
    respond("subscribe_captions", undefined);
  });

  it("is hidden until the backend shows it, then explains how to finish", async () => {
    const { container } = await renderDictation();
    expect(root(container)).toHaveClass("overlay-hidden");
    await emitEvent("overlay:visibility", true);
    expect(root(container)).not.toHaveClass("overlay-hidden");
    expect(screen.getByText("Dictating — press the shortcut again to insert")).toBeInTheDocument();
    await emitEvent("overlay:visibility", false);
    expect(root(container)).toHaveClass("overlay-hidden");
  });

  it("hides a few seconds after a dictation that could not start, instead of staying on screen (C9)", async () => {
    const { container } = await renderDictation();
    vi.useFakeTimers();
    try {
      await emitEvent("overlay:visibility", true);
      await emitEvent("dictation:state", { active: true, sounds: false, mode: "toggle" });
      await pushChannelMessage({ kind: "status", live: false, recording: false, provider: null, message: "This computer is not paired with an EchoLedger server yet." });
      await emitEvent("dictation:state", { active: false, sounds: false, mode: "toggle" });
      await act(async () => void vi.advanceTimersByTime(ENDED_MESSAGE_MS - 100));
      expect(root(container)).not.toHaveClass("overlay-hidden");
      await act(async () => void vi.advanceTimersByTime(200));
      expect(root(container)).toHaveClass("overlay-hidden");

      // The next press shows it again.
      await emitEvent("overlay:visibility", true);
      await act(async () => void vi.advanceTimersByTime(ENDED_MESSAGE_MS * 2));
      expect(root(container)).not.toHaveClass("overlay-hidden");
    } finally {
      vi.useRealTimers();
    }
  });

  it("shows the in-progress phrase and replaces it with each final turn", async () => {
    const { container } = await renderDictation();
    await pushChannelMessage({ kind: "partial", channel: 1, speaker: "Me", text: "send the inv" });
    expect(textLine(container).textContent?.trim()).toBe("send the inv");
    await pushChannelMessage({ kind: "final", channel: 1, speaker: "Me", text: "Send the invoice.", startMs: 0, endMs: 900 });
    expect(textLine(container).textContent?.trim()).toBe("Send the invoice.");
    await pushChannelMessage({ kind: "partial", channel: 1, speaker: "Me", text: "by friday" });
    expect(textLine(container).textContent?.trim()).toBe("Send the invoice. by friday");
  });

  it("joins several final turns with single spaces", async () => {
    const { container } = await renderDictation();
    for (const text of ["First.", "Second.", "Third."]) {
      await pushChannelMessage({ kind: "final", channel: 1, speaker: "Me", text, startMs: 0, endMs: 1 });
    }
    expect(textLine(container).textContent?.trim()).toBe("First. Second. Third.");
  });

  it("shows a status message and clears everything on clear", async () => {
    const { container } = await renderDictation();
    await pushChannelMessage({ kind: "status", live: false, recording: true, provider: null, message: "Microphone unavailable." });
    expect(screen.getByText("Microphone unavailable.")).toBeInTheDocument();
    await pushChannelMessage({ kind: "final", channel: 1, speaker: "Me", text: "kept until cleared", startMs: 0, endMs: 1 });
    await pushChannelMessage({ kind: "clear" });
    expect(screen.queryByText("Microphone unavailable.")).not.toBeInTheDocument();
    expect(textLine(container).textContent?.trim()).toBe("");
  });

  // A6.DU8: long dictation used to run out of the 420 x 110 box.
  it("wraps long text inside its own scroll area and keeps the newest words in view", async () => {
    const { container } = await renderDictation();
    const text = textLine(container);
    expect(text).toHaveClass("dictation-text");
    Object.defineProperty(text, "scrollHeight", { configurable: true, value: 480 });
    await pushChannelMessage({ kind: "final", channel: 1, speaker: "Me", text: "https://example.test/".repeat(20), startMs: 0, endMs: 1 });
    expect(text.scrollTop).toBe(480);
    text.scrollTop = 0;
    await pushChannelMessage({ kind: "partial", channel: 1, speaker: "Me", text: "and more" });
    expect(text.scrollTop).toBe(480);
  });

  // B14: the flow bar, the start / stop sounds and the push-to-talk hint.
  it("shows the microphone level in the flow bar and empties it when dictation stops", async () => {
    await renderDictation();
    const meter = screen.getByRole("meter", { name: "Microphone level" });
    expect(meter).toHaveAttribute("aria-valuenow", "0");
    await emitEvent("dictation:level", 0.2);
    expect(Number(meter.getAttribute("aria-valuenow"))).toBeGreaterThan(0);
    await emitEvent("dictation:state", { active: false, sounds: false, mode: "toggle" });
    expect(meter).toHaveAttribute("aria-valuenow", "0");
  });

  it("plays the start and stop sounds only when they are on", async () => {
    await renderDictation();
    await emitEvent("dictation:state", { active: true, sounds: true, mode: "toggle" });
    await emitEvent("dictation:state", { active: false, sounds: true, mode: "toggle" });
    expect(playTone.mock.calls).toEqual([["start"], ["stop"]]);
    await emitEvent("dictation:state", { active: true, sounds: false, mode: "toggle" });
    expect(playTone).toHaveBeenCalledTimes(2);
  });

  it("says to let go of the shortcut in hold-to-talk mode", async () => {
    await renderDictation();
    await emitEvent("dictation:state", { active: true, sounds: false, mode: "push_to_talk" });
    expect(screen.getByText("Dictating — let go of the shortcut to insert")).toBeInTheDocument();
  });

  it("removes its visibility listener on unmount", async () => {
    const { unmount } = await renderDictation();
    await waitFor(() => expect(listenerCount("overlay:visibility")).toBe(1));
    unmount();
    await waitFor(() => expect(listenerCount("overlay:visibility")).toBe(0));
    expect(listenerCount("dictation:level") + listenerCount("dictation:state")).toBe(0);
  });
});
