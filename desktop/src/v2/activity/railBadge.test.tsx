// UX-08: the rail's Activity number is the server's one number (GET /api/badge, the same on the phone and the web
// console) plus the calls waiting on this computer; red only when something failed; counted here while the server can't
// be asked. It is read again whenever Activity changes, and the Dock shows the same.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { resetSyncClock } from "../library/syncClock";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const settle = async () => {
  for (let i = 0; i < 3; i++) await act(flushAsync);
};
const unlocked = { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 };
// What the list read here would count: two unread problems, one of them a failure.
const LIST = [
  { id: "n1", kind: "transcript_failed", title: "Transcription failed", body: "", session_id: "s1", read: false, at: null },
  { id: "n2", kind: "budget_reached", title: "Minutes used up", body: "", session_id: null, read: false, at: null },
];

function app(pendingDecisions = 0) {
  respond("app_status", makeStatus({ pendingDecisions }));
  respond("get_settings", makeSettings());
  respond("lock_status", unlocked);
  respond("library_list", [makeItem("a")]);
  respond("library_refresh", [makeItem("a")]);
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("bin_list", { data: [] });
  respond("app_menu_set", null);
  respond("dock_activity_set", null);
  respond("activity_list", { data: LIST, unread_count: 2 });
  respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true }, ui_classic: false });
  render(<MainApp />);
}
const activity = () => within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: /^Activity/ });

beforeEach(() => {
  localStorage.clear();
  resetTauriMock();
  resetSyncClock();
  resetEntitlementsForTests(makeEntitlements());
});
afterEach(() => cleanup());

describe("the rail's Activity number (UX-08)", () => {
  it("is the server's number plus the calls waiting here, in the server's colour; the Dock gets the server's part", async () => {
    respond("badge_get", { count: 5, label: "5", tone: "accent" });
    app(1);
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 6");
    const count = activity().querySelector(".el-rail__count")!;
    expect(count).toHaveTextContent("6");
    expect(count).not.toHaveClass("el-rail__count--danger");
    // The core adds the calls waiting to the Dock's number itself.
    expect(callsOf("dock_activity_set").at(-1)).toEqual({ count: 5 });
  });

  it("turns red when the server says something failed, and follows it when Activity changes", async () => {
    respond("badge_get", { count: 2, label: "2", tone: "danger" });
    app();
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    expect(activity().querySelector(".el-rail__count")).toHaveClass("el-rail__count--danger");
    // Resolved on the server (an upload that went up after all): gone from the badge.
    respond("badge_get", { count: 0, label: null, tone: "accent" });
    await act(() => emitEvent("activity:updated", 0));
    await settle();
    expect(activity()).toHaveAccessibleName("Activity");
    expect(activity().querySelector(".el-rail__count")).toBeNull();
  });

  it("offline (or an older server): counted here from the list, red for the failed transcript", async () => {
    fail("badge_get", "The server is unreachable.");
    app();
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 2");
    expect(activity().querySelector(".el-rail__count")).toHaveClass("el-rail__count--danger");
  });
});
