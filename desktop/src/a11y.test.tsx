// C7: every control in the main window and the overlay windows has a name a screen reader can announce, and the main
// window can be used from the keyboard (Tab reaches the navigation, the library and the transcript).
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { computeAccessibleName } from "dom-accessibility-api";
import MainApp from "./views/main/MainApp";
import OverlayApp from "./views/overlay/OverlayApp";
import PillApp from "./views/pill/PillApp";
import ReviewApp from "./views/review/ReviewApp";
import DictationApp from "./views/dictation/DictationApp";
import { emitEvent, flushAsync, pushChannelMessage, resetTauriMock, respondAll, setWindowLabel } from "./test/tauri";
import { makeItem, makePendingItem, makeRecordingState, makeReviewPayload, makeSettings, makeStatus, makeTranscript, makeUploadItem } from "./test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("./test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("./test/tauri")).eventModule);
vi.mock("@tauri-apps/api/webviewWindow", async () => (await import("./test/tauri")).windowModule);

const CONTROLS = "button, input:not([type=hidden]), select, textarea, [role=button], [role=switch], [role=checkbox], [role=slider]";

/** Controls without an accessible name (HTML of each, shortened). */
function unnamed(root: ParentNode = document.body): string[] {
  return [...root.querySelectorAll(CONTROLS)].filter((el) => !computeAccessibleName(el).trim()).map((el) => el.outerHTML.slice(0, 160));
}

beforeEach(() => {
  resetTauriMock();
  respondAll({
    app_status: makeStatus({ pendingDecisions: 1, failedUploads: 1 }),
    get_settings: makeSettings(),
    lock_status: { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 },
    library_list: [makeItem("1", { title: "Weekly sync" }), makeItem("2", { title: "Invoice call", source_device: "phone" })],
    library_refresh: [makeItem("1", { title: "Weekly sync" }), makeItem("2", { title: "Invoice call", source_device: "phone" })],
    list_clients: { data: [{ id: "c1", name: "Northwind" }] },
    pending_list: [makePendingItem()],
    upload_list: [makeUploadItem("up-1", { state: "failed", lastError: "No connection" })],
    activity_list: { data: [], unread_count: 0 },
    transcript_detail: makeTranscript(),
    session_detail: { data: { playback_url: null, markers: [], speaker_names: {} } },
    marker_tags: ["Budget"],
    mic_users: [],
    input_devices: [],
    permissions_status: { microphone: "granted", browserAutomation: false, platform: "windows" },
    storage_info: { recordings_bytes: 0, recordings_count: 0, disk_free_bytes: null, disk_total_bytes: null, retention_days: 30 },
    bin_list: { data: [] },
    security_overview: { devices: [], events: [] },
    dictation_history: [],
    share_list: { data: [] },
    transcript_revisions: { data: [] },
    recording_state: makeRecordingState({ active: true, captions: true }),
    review_state: makeReviewPayload(),
    overlay_set_hit_regions: null,
    subscribe_captions: null,
  });
});

afterEach(() => cleanup());

// jsdom has no ResizeObserver; the caption overlay watches its body with one.
globalThis.ResizeObserver ??= class {
  observe() {}
  unobserve() {}
  disconnect() {}
} as unknown as typeof ResizeObserver;

describe("screen-reader names (C7)", () => {
  it.each(["Library", "Record", "Settings"])("names every control on the %s page", async (tab) => {
    setWindowLabel("main");
    render(<MainApp />);
    await flushAsync();
    fireEvent.click(await screen.findByRole("button", { name: new RegExp(`^${tab}`) }));
    await flushAsync();
    if (tab === "Library") {
      fireEvent.click(screen.getByText("Weekly sync").closest("button")!);
      await flushAsync();
    }
    expect(unnamed()).toEqual([]);
  });

  it("names every control of the caption overlay, the pill, the review card and the dictation bar", async () => {
    for (const [label, View] of [["overlay", OverlayApp], ["pill", PillApp], ["review", ReviewApp], ["dictation", DictationApp]] as const) {
      setWindowLabel(label);
      render(<View />);
      await flushAsync();
      if (label === "overlay" || label === "dictation") {
        await pushChannelMessage({ kind: "status", live: true, recording: true, provider: "assemblyai", message: null });
        await emitEvent("overlay:visibility", true);
      }
      if (label === "review") await emitEvent("review:show", makeReviewPayload());
      await act(async () => undefined);
      expect(unnamed(), label).toEqual([]);
      cleanup();
    }
  });
});

describe("keyboard (C7)", () => {
  it("reaches the navigation, the search, the recordings and the transcript tools with Tab", async () => {
    setWindowLabel("main");
    render(<MainApp />);
    await flushAsync();
    fireEvent.click(screen.getByText("Weekly sync").closest("button")!);
    await flushAsync();
    const focusable = [...document.querySelectorAll<HTMLElement>("button, input, select, textarea, a[href], [tabindex]:not([tabindex='-1'])")].filter(
      (el) => !el.hasAttribute("disabled") && el.tabIndex >= 0,
    );
    const names = focusable.map((el) => computeAccessibleName(el));
    for (const wanted of ["Library", "Record", "Settings", "Search recordings", "Copy text"]) {
      expect(names.some((n) => n.includes(wanted)), wanted).toBe(true);
    }
    expect(names.some((n) => n.includes("Weekly sync"))).toBe(true);
  });
});
