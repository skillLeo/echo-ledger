// C9: windows are built on demand and the core keeps their events until the page says it listens. Every window must
// say so exactly once, and only after the listeners for the events the core kept are attached.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, render } from "@testing-library/react";
import type { ReactElement } from "react";
import OverlayApp from "../views/overlay/OverlayApp";
import PillApp from "../views/pill/PillApp";
import ReviewApp from "../views/review/ReviewApp";
import DictationApp from "../views/dictation/DictationApp";
import MainApp from "../views/main/MainApp";
import { callsOf, flushAsync, listenerCount, resetTauriMock, respondAll, respondWith, setWindowLabel } from "../test/tauri";
import { makeRecordingState, makeSettings, makeStatus } from "../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../test/tauri")).windowModule);

class NoopResizeObserver {
  observe() {}
  unobserve() {}
  disconnect() {}
}

beforeEach(() => {
  resetTauriMock();
  vi.stubGlobal("ResizeObserver", NoopResizeObserver);
  respondAll({
    app_status: makeStatus(),
    get_settings: makeSettings(),
    subscribe_captions: undefined,
    overlay_set_hit_regions: undefined,
    recording_state: makeRecordingState(),
    marker_tags: [],
    review_state: null,
    lock_status: { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 },
    library_list: [],
    library_refresh: [],
    pending_list: [],
    upload_list: [],
    activity_list: { data: [], unread_count: 0 },
    list_clients: { data: [] },
  });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const windows: [string, () => ReactElement, string[], boolean][] = [
  ["overlay", () => <OverlayApp />, ["overlay:visibility"], true],
  ["pill", () => <PillApp />, ["pill:visibility", "recording:state"], false],
  ["review", () => <ReviewApp />, ["review:show", "review:hide", "ask:show", "ask:hide"], false],
  ["dictation", () => <DictationApp />, ["overlay:visibility", "dictation:state", "dictation:level"], true],
  ["main", () => <MainApp />, ["main:navigate", "app:status"], false],
];

describe("each window says when it listens (C9)", () => {
  it.each(windows)("%s announces itself once, after its listeners are attached", async (label, view, events, captions) => {
    setWindowLabel(label);
    const attached: Record<string, number> = {};
    let subscribed = 0;
    respondWith("window_ready", () => {
      for (const event of events) attached[event] = listenerCount(event);
      subscribed = callsOf("subscribe_captions").length;
    });
    render(view());
    await flushAsync();
    await flushAsync();
    expect(callsOf("window_ready")).toHaveLength(1);
    for (const event of events) expect(attached[event], `${event} listener attached before window_ready`).toBeGreaterThan(0);
    if (captions) expect(subscribed, "captions subscribed before window_ready").toBe(1);
  });
});
