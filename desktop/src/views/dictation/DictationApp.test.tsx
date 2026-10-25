import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import DictationApp from "./DictationApp";
import { callsOf, emitEvent, listenerCount, pushChannelMessage, resetTauriMock, respond } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

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

  it("removes its visibility listener on unmount", async () => {
    const { unmount } = await renderDictation();
    await waitFor(() => expect(listenerCount("overlay:visibility")).toBe(1));
    unmount();
    await waitFor(() => expect(listenerCount("overlay:visibility")).toBe(0));
  });
});
