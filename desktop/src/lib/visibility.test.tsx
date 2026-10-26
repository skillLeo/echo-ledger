// C9: a hidden main window does no polling; showing it again refreshes at once.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render } from "@testing-library/react";
import MainApp from "../views/main/MainApp";
import { RecordView } from "../views/main/RecordView";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respondAll } from "../test/tauri";
import { makeSettings, makeStatus } from "../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("../test/tauri")).windowModule);

beforeEach(() => {
  resetTauriMock();
  vi.useFakeTimers();
  respondAll({
    app_status: makeStatus(),
    get_settings: makeSettings(),
    lock_status: { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 },
    library_list: [],
    library_refresh: [],
    pending_list: [],
    upload_list: [],
    activity_list: { data: [], unread_count: 0 },
    mic_users: [],
    input_devices: [],
    permissions_status: { microphone: "granted", browserAutomation: false, platform: "windows" },
  });
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

const advance = (ms: number) => act(async () => void vi.advanceTimersByTime(ms));

describe("no work behind a hidden main window (C9)", () => {
  it("stops the status poll while hidden and refreshes when shown again", async () => {
    render(<MainApp />);
    await flushAsync();
    const start = callsOf("app_status").length;
    await advance(3_000);
    expect(callsOf("app_status").length).toBeGreaterThan(start);

    await emitEvent("main:visibility", false);
    const hidden = callsOf("app_status").length;
    await advance(10_000);
    expect(callsOf("app_status").length).toBe(hidden);

    await emitEvent("main:visibility", true);
    await flushAsync();
    expect(callsOf("app_status").length).toBe(hidden + 1);
  });

  it("stops scanning microphone users on the Record page while hidden", async () => {
    render(<RecordView status={makeStatus()} run={async (fn) => fn()} />);
    await flushAsync();
    await advance(4_000);
    const shown = callsOf("mic_users").length;
    expect(shown).toBeGreaterThanOrEqual(2);

    await emitEvent("main:visibility", false);
    await advance(10_000);
    expect(callsOf("mic_users").length).toBe(shown);
  });
});
