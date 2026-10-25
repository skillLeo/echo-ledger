import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { readFileSync } from "node:fs";
import OverlayApp, { captionFontPx, captionStyleVars, isIdle } from "./OverlayApp";
import { callsOf, currentWindow, emitEvent, fail, listen, listenerCount, pushChannelMessage, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeSettings } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../../test/tauri")).windowModule);

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
const body = () => screen.getByTestId("caption-body");
const longLine = (n: number) =>
  `Turn ${n}: we went through the second quarter invoice line by line and agreed to send the corrected copy by Friday, ` +
  `see https://files.example.test/shared/invoices/2026/q2/corrected-copy-with-a-very-long-unbroken-path-${n}.pdf`;

/** jsdom has no layout: give the caption body the scroll metrics a real window would have. */
function setScrollMetrics(el: HTMLElement, metrics: { scrollHeight: number; clientHeight: number }) {
  Object.defineProperty(el, "scrollHeight", { configurable: true, get: () => metrics.scrollHeight });
  Object.defineProperty(el, "clientHeight", { configurable: true, get: () => metrics.clientHeight });
}

function userScroll(el: HTMLElement, top: number) {
  el.scrollTop = top;
  fireEvent.scroll(el);
}

function baseResponses() {
  respond("subscribe_captions", undefined);
  respond("overlay_set_hit_regions", undefined);
  respond("get_settings", makeSettings());
  respond("hide_captions", {});
  respond("copy_last_minutes", "text");
  respond("overlay_snap", undefined);
}

describe("caption overlay", () => {
  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    baseResponses();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("subscribes to caption events, overlay visibility and settings changes on mount", async () => {
    await renderOverlay();
    expect(Object.keys(callsOf("subscribe_captions")[0])).toEqual(["onEvent"]);
    expect(listen).toHaveBeenCalledWith("overlay:visibility", expect.any(Function));
    expect(listen).toHaveBeenCalledWith("settings:changed", expect.any(Function));
    expect(callsOf("get_settings")).toHaveLength(1);
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

  it("keeps earlier final lines in the scrolling body instead of dropping them after three", async () => {
    await renderOverlay();
    for (const n of [1, 2, 3, 4, 5]) await pushChannelMessage(final(1, "Me", `line ${n}`));
    const texts = [...body().querySelectorAll(".caption-line")].map((l) => l.textContent);
    expect(texts).toEqual(["Meline 1", "Meline 2", "Meline 3", "Meline 4", "Meline 5"]);
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
    expect(currentWindow.startDragging).toHaveBeenCalledTimes(1);
    expect(callsOf("overlay_snap")).toHaveLength(0);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(400);
    });
    expect(callsOf("overlay_snap")).toHaveLength(1);
  });

  it("unsubscribes the visibility and settings listeners on unmount", async () => {
    const { unmount } = await renderOverlay();
    await waitFor(() => expect(listenerCount("overlay:visibility")).toBe(1));
    await waitFor(() => expect(listenerCount("settings:changed")).toBe(1));
    unmount();
    await waitFor(() => expect(listenerCount("overlay:visibility")).toBe(0));
    await waitFor(() => expect(listenerCount("settings:changed")).toBe(0));
  });

  it("still renders with the default three lines when the settings cannot be read", async () => {
    fail("get_settings", "Settings are unavailable.");
    await renderOverlay();
    await waitFor(() => expect(callsOf("get_settings")).toHaveLength(1));
    expect(body().style.maxHeight).toBe("calc(3 * var(--caption-line-height))");
  });
});

// A3.1 / A3.5: jsdom cannot measure layout, so these tests pin the DOM structure and the CSS contract that keeps the
// header inside the window (the real-window check is a manual QA step).
describe("caption overlay layout", () => {
  let style: HTMLStyleElement;

  beforeAll(() => {
    // Vitest replaces CSS imports (even `?raw`) with empty strings, so read the real stylesheet from disk.
    const dir = (import.meta as ImportMeta & { dirname: string }).dirname;
    style = document.createElement("style");
    style.textContent = readFileSync(`${dir}/../../styles.css`, "utf8");
    document.head.appendChild(style);
  });

  afterAll(() => {
    style.remove();
  });

  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    baseResponses();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  async function renderWithLongLines(count: number) {
    const view = await renderOverlay();
    await emitEvent("overlay:visibility", true);
    for (let n = 1; n <= count; n++) await pushChannelMessage(final(n % 2 ? 1 : 2, n % 2 ? "Me" : "Client", longLine(n)));
    await pushChannelMessage({ kind: "partial", channel: 1, speaker: "Me", text: longLine(51) });
    return view;
  }

  it("with 50 long lines the header comes before the scrolling body and holds the toolbar, outside the scroll area", async () => {
    await renderWithLongLines(50);
    const card = screen.getByTestId("caption-card");
    const header = screen.getByTestId("caption-header");
    const scroller = body();

    expect(header.parentElement).toBe(card);
    expect(scroller.parentElement).toBe(card);
    expect(card.firstElementChild).toBe(header);
    expect(header.compareDocumentPosition(scroller) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(scroller.contains(header)).toBe(false);
    expect(scroller.querySelectorAll(".caption-line")).toHaveLength(51);
    for (const name of ["Drag", "Copy last 2 minutes", "Hide captions"]) expect(header.contains(screen.getByTitle(name))).toBe(true);
  });

  it("keeps the card inside the window: flex column capped at 100%, fixed header, scrolling body", async () => {
    const { container } = await renderWithLongLines(50);
    const rootStyle = getComputedStyle(root(container));
    expect(rootStyle.display).toBe("flex");
    expect(rootStyle.flexDirection).toBe("column");
    expect(rootStyle.justifyContent).toBe("flex-end");
    expect(rootStyle.height).toBe("100%");
    expect(rootStyle.boxSizing).toBe("border-box");

    const card = getComputedStyle(screen.getByTestId("caption-card"));
    expect(card.display).toBe("flex");
    expect(card.flexDirection).toBe("column");
    expect(card.maxHeight).toBe("100%");
    expect(card.minHeight).toMatch(/^0(px)?$/);
    expect(card.overflow).toBe("hidden");

    const header = getComputedStyle(screen.getByTestId("caption-header"));
    expect(header.flexGrow).toBe("0");
    expect(header.flexShrink).toBe("0");

    const scroller = getComputedStyle(body());
    expect(scroller.overflowY).toBe("auto");
    expect(scroller.flexGrow).toBe("1");
    expect(scroller.flexShrink).toBe("1");
    expect(scroller.minHeight).toMatch(/^0(px)?$/);
    expect(scroller.overflowWrap).toBe("anywhere");
  });

  it("sizes one caption row from the caption font so N rows show N lines", async () => {
    const { container } = await renderOverlay();
    const rowHeight = getComputedStyle(root(container)).getPropertyValue("--caption-line-height");
    expect(rowHeight.replace(/\s+/g, "")).toBe("calc(var(--caption-font)*1.38+4px)");
    expect(root(container).style.getPropertyValue("--caption-font")).toBe("15px");
    await pushChannelMessage(final(1, "Me", "one row"));
    const line = body().querySelector(".caption-line") as HTMLElement;
    const lineStyle = getComputedStyle(line);
    expect(lineStyle.fontSize.replace(/\s+/g, "")).toMatch(/^(15px|var\(--caption-font\))$/);
    expect(lineStyle.lineHeight).toBe("1.38");
    expect(lineStyle.marginTop).toBe("0px");
    expect(lineStyle.paddingTop).toBe("2px");
    expect(lineStyle.paddingBottom).toBe("2px");
  });

  it("leaves the dictation box, which shares the overlay styles, top-anchored with its block layout", () => {
    document.documentElement.dataset.window = "dictation";
    const dictationRoot = document.createElement("div");
    dictationRoot.className = "overlay-root";
    dictationRoot.innerHTML = '<div class="overlay-card dictation-card"></div>';
    document.body.appendChild(dictationRoot);
    try {
      expect(getComputedStyle(dictationRoot).justifyContent).toBe("flex-start");
      expect(getComputedStyle(dictationRoot.firstElementChild as HTMLElement).display).toBe("block");
    } finally {
      dictationRoot.remove();
      delete document.documentElement.dataset.window;
    }
  });

  it("shows 2, 3 or 5 lines from the Caption lines setting and follows changes", async () => {
    respond("get_settings", makeSettings({ captionLines: 2 }));
    await renderOverlay();
    await waitFor(() => expect(body().style.maxHeight).toBe("calc(2 * var(--caption-line-height))"));

    await emitEvent("settings:changed", makeSettings({ captionLines: 5 }));
    expect(body().style.maxHeight).toBe("calc(5 * var(--caption-line-height))");

    await emitEvent("settings:changed", makeSettings({ captionLines: 3 }));
    expect(body().style.maxHeight).toBe("calc(3 * var(--caption-line-height))");

    await emitEvent("settings:changed", makeSettings({ captionLines: 9 }));
    expect(body().style.maxHeight).toBe("calc(3 * var(--caption-line-height))");
  });

  it("does not let a late answer to the first settings read undo a newer settings change", async () => {
    let answer: (value: unknown) => void = () => undefined;
    respondWith("get_settings", () => new Promise((resolve) => (answer = resolve)));
    await renderOverlay();
    await emitEvent("settings:changed", makeSettings({ captionLines: 5 }));
    await act(async () => answer(makeSettings({ captionLines: 2 })));
    expect(body().style.maxHeight).toBe("calc(5 * var(--caption-line-height))");
  });

  it("follows the live end as lines arrive while the user is at the bottom", async () => {
    await renderWithLongLines(50);
    const scroller = body();
    setScrollMetrics(scroller, { scrollHeight: 1_500, clientHeight: 74 });
    await pushChannelMessage(final(1, "Me", "newest"));
    expect(scroller.scrollTop).toBe(1_500);
    expect(screen.queryByRole("button", { name: "Jump to live" })).not.toBeInTheDocument();
  });

  it("stops following after the user scrolls up and offers Jump to live, which returns to the end", async () => {
    await renderWithLongLines(50);
    const scroller = body();
    const metrics = { scrollHeight: 1_500, clientHeight: 74 };
    setScrollMetrics(scroller, metrics);
    await pushChannelMessage(final(1, "Me", "at the end"));
    expect(screen.queryByRole("button", { name: "Jump to live" })).not.toBeInTheDocument();

    userScroll(scroller, 600);
    const jump = await screen.findByRole("button", { name: "Jump to live" });
    expect(jump).toHaveAttribute("data-hit");

    metrics.scrollHeight = 1_600;
    await pushChannelMessage(final(2, "Client", "said while reading back"));
    expect(scroller.scrollTop).toBe(600);

    fireEvent.click(jump);
    expect(scroller.scrollTop).toBe(1_600);
    expect(screen.queryByRole("button", { name: "Jump to live" })).not.toBeInTheDocument();

    metrics.scrollHeight = 1_700;
    await pushChannelMessage(final(1, "Me", "following again"));
    expect(scroller.scrollTop).toBe(1_700);
  });

  it("resumes following without the button when the user scrolls back to within 8 px of the end", async () => {
    await renderWithLongLines(50);
    const scroller = body();
    setScrollMetrics(scroller, { scrollHeight: 1_500, clientHeight: 74 });
    userScroll(scroller, 300);
    expect(await screen.findByRole("button", { name: "Jump to live" })).toBeInTheDocument();
    userScroll(scroller, 1_500 - 74 - 6);
    await waitFor(() => expect(screen.queryByRole("button", { name: "Jump to live" })).not.toBeInTheDocument());
  });

  it("starts at the live end again when the captions are shown again", async () => {
    await renderWithLongLines(50);
    const scroller = body();
    setScrollMetrics(scroller, { scrollHeight: 1_500, clientHeight: 74 });
    userScroll(scroller, 300);
    expect(await screen.findByRole("button", { name: "Jump to live" })).toBeInTheDocument();
    await emitEvent("overlay:visibility", false);
    await emitEvent("overlay:visibility", true);
    expect(screen.queryByRole("button", { name: "Jump to live" })).not.toBeInTheDocument();
    expect(scroller.scrollTop).toBe(1_500);
  });
});

describe("overlay hit regions", () => {
  const rect = (x: number, y: number, w: number, h: number) => ({ left: x, top: y, width: w, height: h, right: x + w, bottom: y + h, x, y, toJSON: () => ({}) }) as DOMRect;
  // padding 6 css px on every side, scaled by the device pixel ratio of 2
  const region = (r: DOMRect) => ({ x: (r.left - 6) * 2, y: (r.top - 6) * 2, w: (r.width + 12) * 2, h: (r.height + 12) * 2 });
  let toolsRect = rect(300, 8, 90, 28);
  let bodyRect = rect(12, 44, 436, 74);
  let jumpRect = rect(190, 96, 80, 22);

  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", NoopResizeObserver);
    vi.stubGlobal("devicePixelRatio", 2);
    toolsRect = rect(300, 8, 90, 28);
    bodyRect = rect(12, 44, 436, 74);
    jumpRect = rect(190, 96, 80, 22);
    vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (this: HTMLElement) {
      if (!this.hasAttribute("data-hit")) return rect(0, 0, 0, 0);
      if (this.classList.contains("overlay-body")) return bodyRect;
      if (this.classList.contains("overlay-jump")) return jumpRect;
      return toolsRect;
    });
    respond("subscribe_captions", undefined);
    respond("overlay_set_hit_regions", undefined);
    respond("get_settings", makeSettings());
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
  });

  const lastRegions = () => callsOf("overlay_set_hit_regions").at(-1);

  it("reports the toolbar rectangle in physical pixels with padding on mount", async () => {
    await renderOverlay();
    await waitFor(() => expect(callsOf("overlay_set_hit_regions").length).toBeGreaterThan(0));
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

  it("adds the scroll area while the captions are visible, so the wheel scrolls them; nothing else is clickable", async () => {
    await renderOverlay();
    await emitEvent("overlay:visibility", true);
    await waitFor(() => expect(lastRegions()).toEqual({ regions: [region(toolsRect), region(bodyRect)] }));
    await emitEvent("overlay:visibility", false);
    await waitFor(() => expect(lastRegions()).toEqual({ regions: [region(toolsRect)] }));
  });

  it("re-reports on a scroll of the caption body within a frame (no waiting for the safety timer)", async () => {
    await renderOverlay();
    await emitEvent("overlay:visibility", true);
    await waitFor(() => expect(lastRegions()).toEqual({ regions: [region(toolsRect), region(bodyRect)] }));
    const before = callsOf("overlay_set_hit_regions").length;

    toolsRect = rect(300, 20, 90, 28);
    await new Promise((resolve) => setTimeout(resolve, 80));
    expect(callsOf("overlay_set_hit_regions")).toHaveLength(before);

    fireEvent.scroll(body());
    await new Promise((resolve) => setTimeout(resolve, 80));
    expect(callsOf("overlay_set_hit_regions").length).toBeGreaterThan(before);
    expect(lastRegions()).toEqual({ regions: [region(toolsRect), region(bodyRect)] });
  });

  it("re-reports when the window is resized", async () => {
    await renderOverlay();
    await waitFor(() => expect(callsOf("overlay_set_hit_regions").length).toBe(1));
    toolsRect = rect(260, 8, 90, 28);
    window.dispatchEvent(new Event("resize"));
    await new Promise((resolve) => setTimeout(resolve, 80));
    expect(lastRegions()).toEqual({ regions: [region(toolsRect)] });
  });

  it("re-reports when the Caption lines setting resizes the body", async () => {
    await renderOverlay();
    await emitEvent("overlay:visibility", true);
    await waitFor(() => expect(lastRegions()).toEqual({ regions: [region(toolsRect), region(bodyRect)] }));
    bodyRect = rect(12, 44, 436, 124);
    await emitEvent("settings:changed", makeSettings({ captionLines: 5 }));
    await waitFor(() => expect(lastRegions()).toEqual({ regions: [region(toolsRect), region(bodyRect)] }));
  });

  it("makes Jump to live clickable while it is shown", async () => {
    await renderOverlay();
    await emitEvent("overlay:visibility", true);
    for (let n = 1; n <= 50; n++) await pushChannelMessage(final(1, "Me", longLine(n)));
    setScrollMetrics(body(), { scrollHeight: 1_500, clientHeight: 74 });
    userScroll(body(), 200);
    await screen.findByRole("button", { name: "Jump to live" });
    await waitFor(() => expect(lastRegions()).toEqual({ regions: [region(toolsRect), region(bodyRect), region(jumpRect)] }));
    fireEvent.click(screen.getByRole("button", { name: "Jump to live" }));
    await waitFor(() => expect(lastRegions()).toEqual({ regions: [region(toolsRect), region(bodyRect)] }));
  });
});

describe("caption style (B4)", () => {
  const style = makeSettings().captionStyle;

  beforeEach(() => {
    resetTauriMock();
    vi.stubGlobal("ResizeObserver", class { observe() {} unobserve() {} disconnect() {} });
    respond("subscribe_captions", undefined);
    respond("overlay_set_hit_regions", undefined);
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it("maps text sizes to pixels", () => {
    expect([captionFontPx("small"), captionFontPx("medium"), captionFontPx("large"), captionFontPx(undefined)]).toEqual([13, 15, 19, 15]);
  });

  it("turns the style into overlay variables, keeping theme colours when none are chosen", () => {
    expect(captionStyleVars(null)).toEqual({ "--caption-font": "15px", "--caption-alpha": "0.85" });
    expect(captionStyleVars({ ...style, size: "large", opacity: 60, meColor: "#22c55e" })).toEqual({
      "--caption-font": "19px",
      "--caption-alpha": "0.6",
      "--caption-me": "#22c55e",
    });
    expect(captionStyleVars({ ...style, opacity: 10 })).toMatchObject({ "--caption-alpha": "0.4" });
  });

  it("is idle only with auto-hide on and the quiet time passed", () => {
    expect(isIdle(0, 0, 999_999)).toBe(false);
    expect(isIdle(5, 1_000, 5_999)).toBe(false);
    expect(isIdle(5, 1_000, 6_000)).toBe(true);
  });

  it("applies the stored style and restyles on settings:changed", async () => {
    respond("get_settings", makeSettings({ captionStyle: { ...style, compact: true, clientColor: "#f59e0b" } }));
    const { container } = await renderOverlay();
    await waitFor(() => expect(root(container)).toHaveClass("compact"));
    expect(root(container).style.getPropertyValue("--caption-client")).toBe("#f59e0b");
    await emitEvent("settings:changed", makeSettings({ captionStyle: { ...style, size: "small" } }));
    expect(root(container)).not.toHaveClass("compact");
    expect(root(container).style.getPropertyValue("--caption-font")).toBe("13px");
  });

  it("colours lines by speaker so compact mode can drop the labels", async () => {
    respond("get_settings", makeSettings());
    await renderOverlay();
    await pushChannelMessage(final(2, "Client", "hello"));
    expect(body().querySelector(".caption-line.ch-2")).toHaveTextContent("hello");
  });

  it("hides after the quiet time and comes back with the next caption", async () => {
    respond("get_settings", makeSettings({ captionStyle: { ...style, autoHideSeconds: 5 } }));
    const { container } = await renderOverlay();
    await waitFor(() => expect(callsOf("get_settings").length).toBeGreaterThan(0));
    vi.useFakeTimers();
    await act(async () => {
      await pushChannelMessage({ kind: "status", live: true, recording: true, provider: null, message: null });
    });
    expect(root(container)).not.toHaveClass("overlay-idle");
    await act(async () => {
      vi.advanceTimersByTime(5_100);
    });
    expect(root(container)).toHaveClass("overlay-idle");
    await act(async () => {
      await pushChannelMessage(final(1, "Me", "back"));
    });
    expect(root(container)).not.toHaveClass("overlay-idle");
  });
});
