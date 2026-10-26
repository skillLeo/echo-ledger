// C9: closing the main window destroys it; the next one opens on the same page, recording and scroll position.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import MainApp from "../views/main/MainApp";
import { parseMainView, rememberMainView, restoreScroll } from "./mainView";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondAll, respondWith } from "../test/tauri";
import { makeSettings, makeStatus } from "../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../test/tauri")).windowModule);

beforeEach(() => {
  resetTauriMock();
  respondAll({
    app_status: makeStatus(),
    get_settings: makeSettings(),
    lock_status: { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 },
    library_list: [],
    library_refresh: [],
    pending_list: [],
    upload_list: [],
    activity_list: { data: [], unread_count: 0 },
    list_clients: { data: [] },
    mic_users: [],
    input_devices: [],
    permissions_status: { microphone: "granted", browserAutomation: false, platform: "windows" },
  });
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe("the kept main view", () => {
  it("accepts only a view of ours", () => {
    expect(parseMainView(null)).toBeNull();
    expect(parseMainView("not json")).toBeNull();
    expect(parseMainView(JSON.stringify({ tab: "admin" }))).toBeNull();
    expect(parseMainView(JSON.stringify({ tab: "settings", recordingId: 7, scroll: { content: 120.4, list: -3, other: 9 } }))).toEqual({
      tab: "settings",
      recordingId: null,
      scroll: { content: 120 },
    });
  });

  it("is handed to the core shortly after a change, and a new page forgets the old page's scroll", async () => {
    vi.useFakeTimers();
    respondWith("main_view_set", () => undefined);
    rememberMainView({ tab: "library", recordingId: "r1", scroll: { list: 300 } });
    rememberMainView({ scroll: { detail: 40 } });
    expect(callsOf("main_view_set")).toHaveLength(0);
    await act(async () => void vi.advanceTimersByTime(300));
    expect(JSON.parse(callsOf("main_view_set")[0].view as string)).toEqual({ tab: "library", recordingId: "r1", scroll: { list: 300, detail: 40 } });
    rememberMainView({ tab: "settings" });
    await act(async () => void vi.advanceTimersByTime(300));
    expect(JSON.parse(callsOf("main_view_set")[1].view as string).scroll).toEqual({});
  });

  it("scrolls back once the content is tall enough, and stops when the user scrolls", () => {
    vi.useFakeTimers();
    const area = document.createElement("div");
    area.className = "content";
    document.body.appendChild(area);
    // jsdom has no layout: the area can scroll as far as `room` (0 until its content loaded).
    let room = 0;
    let top = 0;
    Object.defineProperty(area, "scrollTop", { get: () => top, set: (v: number) => (top = Math.min(v, room)), configurable: true });
    restoreScroll({ content: 500 }, () => 0);
    vi.advanceTimersByTime(50);
    expect(area.scrollTop).toBe(0);
    room = 1000;
    vi.advanceTimersByTime(50);
    expect(area.scrollTop).toBe(500);

    top = 0;
    restoreScroll({ content: 800 }, () => 0);
    window.dispatchEvent(new Event("wheel"));
    vi.advanceTimersByTime(100);
    expect(area.scrollTop, "the user scrolled first").toBe(0);
    area.remove();
  });

  it("a new main window opens on the kept page and only then says it listens", async () => {
    respond("main_view_get", JSON.stringify({ tab: "settings", recordingId: null, scroll: {} }));
    let tabAtReady = "";
    respondWith("window_ready", () => {
      tabAtReady = document.querySelector(".nav-btn.active")?.textContent ?? "(not rendered yet)";
    });
    render(<MainApp />);
    await flushAsync();
    await flushAsync();
    expect(screen.getByRole("heading", { name: "Settings" })).toBeTruthy();
    expect(callsOf("window_ready")).toHaveLength(1);
    expect(tabAtReady).not.toBe("Library");

    // A navigation the core kept while the window was built is applied after the kept view.
    await emitEvent("main:navigate", { view: "record", pendingId: null, rename: false });
    expect(screen.getByRole("heading", { name: "Record" })).toBeTruthy();
  });
});
