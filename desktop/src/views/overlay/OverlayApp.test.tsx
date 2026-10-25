import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import OverlayApp from "./OverlayApp";
import { callsOf, emitEvent, fail, listen, listenerCount, pushChannelMessage, resetTauriMock, respond } from "../../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const win = vi.hoisted(() => ({ startDragging: vi.fn(() => Promise.resolve()) }));
vi.mock("@tauri-apps/api/webviewWindow", () => ({ getCurrentWebviewWindow: () => ({ label: "overlay", startDragging: win.startDragging }) }));

class NoopResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
}

async function renderOverlay() {
  const view = render(<OverlayApp />);
  await waitFor(() => expect(callsOf("subscribe_captions")).toHaveLength(1));
  return view;
}

const final = (channel: number, speaker: string, text: string) => ({ kind: "final", channel, speaker, text, startMs: 0, endMs: 1_000 });
const root = (container: HTMLElement) => container.querySelector(".overlay-root") as HTMLElement;

describe("caption overlay", () => {
  beforeEach(() => {
    resetTauriMock();
    win.startDragging.mockClear();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    respond("subscribe_captions", undefined);
    respond("overlay_set_hit_regions", undefined);
    respond("hide_captions", {});
    respond("copy_last_minutes", "text");
    respond("overlay_snap", undefined);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("subscribes to caption events and to overlay visibility on mount", async () => {
    await renderOverlay();
    expect(Object.keys(callsOf("subscribe_captions")[0])).toEqual(["onEvent"]);
    expect(listen).toHaveBeenCalledWith("overlay:visibility", expect.any(Function));
  });

  it("starts hidden with a listening placeholder", async () => {
    const { container } = await renderOverlay();
    expect(root(container)).toHaveClass("overlay-hidden");
    expect(screen.getByText("Listening…")).toBeInTheDocument();
    expect(screen.getByText("Captions")).toBeInTheDocument();
  });

  it("shows and hides in response to the visibility event", async () => {
    const { container } = await renderOverlay();
    await emitEvent("overlay:visibility", true);
    expect(root(container)).not.toHaveClass("overlay-hidden");
    await emitEvent("overlay:visibility", false);
    expect(root(container)).toHaveClass("overlay-hidden");
  });

  it("becomes visible and shows the live indicator when a live status arrives", async () => {
    const { container } = await renderOverlay();
    await pushChannelMessage({ kind: "status", live: true, recording: true, provider: "assemblyai", message: null });
    expect(root(container)).not.toHaveClass("overlay-hidden");
    expect(screen.getByText("Live")).toBeInTheDocument();
    expect(container.querySelector(".overlay-head .status-dot")).toHaveClass("live");
  });

  it("does not become visible on a non-live status", async () => {
    const { container } = await renderOverlay();
    await pushChannelMessage({ kind: "status", live: false, recording: false, provider: null, message: null });
    expect(root(container)).toHaveClass("overlay-hidden");
    expect(screen.getByText("Captions")).toBeInTheDocument();
  });

  it("renders partial text per channel, labelled Me and Client", async () => {
    const { container } = await renderOverlay();
    await pushChannelMessage({ kind: "partial", channel: 1, speaker: "Me", text: "hello th" });
    await pushChannelMessage({ kind: "partial", channel: 2, speaker: "Client", text: "good mor" });
    const lines = [...container.querySelectorAll(".caption-line.partial")];
    expect(lines.map((l) => l.textContent)).toEqual(["Mehello th", "Clientgood mor"]);
    expect(screen.queryByText("Listening…")).not.toBeInTheDocument();
  });

  it("promotes a final turn, clears that channel's partial and styles speakers by channel", async () => {
    const { container } = await renderOverlay();
    await pushChannelMessage({ kind: "partial", channel: 2, speaker: "Client", text: "good mor" });
    await pushChannelMessage(final(2, "Client", "Good morning."));
    expect(container.querySelectorAll(".caption-line.partial")).toHaveLength(0);
    const line = screen.getByText("Good morning.", { exact: false });
    expect(line.querySelector(".who")).toHaveClass("spk-2");
    expect(line.querySelector(".who")).toHaveTextContent("Client");
    await pushChannelMessage(final(1, "Me", "Hi there."));
    expect(screen.getByText("Hi there.", { exact: false }).querySelector(".who")).toHaveClass("spk-1");
  });

  it("keeps only the three most recent final lines", async () => {
    await renderOverlay();
    for (const n of [1, 2, 3, 4, 5]) await pushChannelMessage(final(1, "Me", `line ${n}`));
    expect(screen.queryByText("line 1", { exact: false })).not.toBeInTheDocument();
    expect(screen.queryByText("line 2", { exact: false })).not.toBeInTheDocument();
    for (const n of [3, 4, 5]) expect(screen.getByText(`line ${n}`, { exact: false })).toBeInTheDocument();
  });

  it("shows a status message instead of the placeholder", async () => {
    await renderOverlay();
    await pushChannelMessage({ kind: "status", live: false, recording: true, provider: null, message: "Live captions unavailable: no provider key." });
    expect(screen.getByText("Live captions unavailable: no provider key.")).toBeInTheDocument();
    expect(screen.queryByText("Listening…")).not.toBeInTheDocument();
  });

  it("clears every line, partial and message on a clear event", async () => {
    const { container } = await renderOverlay();
    await pushChannelMessage(final(1, "Me", "something said"));
    await pushChannelMessage({ kind: "partial", channel: 2, speaker: "Client", text: "and more" });
    await pushChannelMessage({ kind: "status", live: true, recording: true, provider: "assemblyai", message: "note" });
    await pushChannelMessage({ kind: "clear" });
    expect(screen.getByText("Listening…")).toBeInTheDocument();
    expect(container.querySelectorAll(".caption-line")).toHaveLength(1);
    expect(screen.getByText("Captions")).toBeInTheDocument();
  });

  it("close button calls hide_captions", async () => {
    await renderOverlay();
    fireEvent.click(screen.getByRole("button", { name: "Hide captions" }));
    await waitFor(() => expect(callsOf("hide_captions")).toHaveLength(1));
  });

  it("copy button asks for the last two minutes", async () => {
    await renderOverlay();
    fireEvent.click(screen.getByTitle("Copy last 2 minutes"));
    await waitFor(() => expect(callsOf("copy_last_minutes")).toEqual([{ minutes: 2 }]));
  });

  it("swallows a failing hide_captions call (the runner fails on any unhandled rejection)", async () => {
    fail("hide_captions", "window is gone");
    await renderOverlay();
    fireEvent.click(screen.getByRole("button", { name: "Hide captions" }));
    await waitFor(() => expect(callsOf("hide_captions")).toHaveLength(1));
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(screen.getByRole("button", { name: "Hide captions" })).toBeInTheDocument();
  });

  it("starts a window drag from the grip and snaps to a corner shortly after", async () => {
    await renderOverlay();
    vi.useFakeTimers({ toFake: ["setTimeout"] });
    await act(async () => {
      fireEvent.mouseDown(screen.getByTitle("Drag"));
    });
    expect(win.startDragging).toHaveBeenCalledTimes(1);
    expect(callsOf("overlay_snap")).toHaveLength(0);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(400);
    });
    expect(callsOf("overlay_snap")).toHaveLength(1);
  });

  it("unsubscribes the visibility listener on unmount", async () => {
    const { unmount } = await renderOverlay();
    await waitFor(() => expect(listenerCount("overlay:visibility")).toBe(1));
    unmount();
    await waitFor(() => expect(listenerCount("overlay:visibility")).toBe(0));
  });
});

describe("overlay hit regions", () => {
  const rect = (x: number, y: number, w: number, h: number) => ({ left: x, top: y, width: w, height: h, right: x + w, bottom: y + h, x, y, toJSON: () => ({}) }) as DOMRect;
  let toolsRect = rect(300, 8, 90, 28);

  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    vi.stubGlobal("devicePixelRatio", 2);
    toolsRect = rect(300, 8, 90, 28);
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      return this.hasAttribute("data-hit") ? toolsRect : rect(0, 0, 0, 0);
    });
    respond("subscribe_captions", undefined);
    respond("overlay_set_hit_regions", undefined);
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  it("reports the toolbar rectangle in physical pixels with padding on mount", async () => {
    await renderOverlay();
    await waitFor(() => expect(callsOf("overlay_set_hit_regions").length).toBeGreaterThan(0));
    // padding 6 css px on every side, scaled by the device pixel ratio of 2
    expect(callsOf("overlay_set_hit_regions")[0]).toEqual({ regions: [{ x: (300 - 6) * 2, y: (8 - 6) * 2, w: (90 + 12) * 2, h: (28 + 12) * 2 }] });
  });

  it("re-reports when the toolbar moves after the captions grow", async () => {
    await renderOverlay();
    await waitFor(() => expect(callsOf("overlay_set_hit_regions").length).toBe(1));
    toolsRect = rect(300, 40, 90, 28);
    await pushChannelMessage(final(1, "Me", "A long sentence that wraps onto a second line and pushes the toolbar upward."));
    await waitFor(() => expect(callsOf("overlay_set_hit_regions").length).toBe(2));
    expect(callsOf("overlay_set_hit_regions")[1]).toEqual({ regions: [{ x: (300 - 6) * 2, y: (40 - 6) * 2, w: (90 + 12) * 2, h: (28 + 12) * 2 }] });
  });

  it("does not resend an unchanged region when other content changes", async () => {
    await renderOverlay();
    await waitFor(() => expect(callsOf("overlay_set_hit_regions").length).toBe(1));
    await pushChannelMessage({ kind: "partial", channel: 2, speaker: "Client", text: "typing" });
    await new Promise((resolve) => setTimeout(resolve, 60));
    expect(callsOf("overlay_set_hit_regions")).toHaveLength(1);
  });

  it("reports no regions when the toolbar has no size", async () => {
    toolsRect = rect(0, 0, 0, 0);
    await renderOverlay();
    await waitFor(() => expect(callsOf("overlay_set_hit_regions").length).toBe(1));
    expect(callsOf("overlay_set_hit_regions")[0]).toEqual({ regions: [] });
  });

  it("stops reporting after unmount", async () => {
    const { unmount } = await renderOverlay();
    await waitFor(() => expect(callsOf("overlay_set_hit_regions").length).toBe(1));
    unmount();
    toolsRect = rect(10, 10, 20, 20);
    await new Promise((resolve) => setTimeout(resolve, 100));
    expect(callsOf("overlay_set_hit_regions")).toHaveLength(1);
  });
});
