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

function app(pendingDecisions = 0, pendingDecisionsHere = pendingDecisions) {
  respond("app_status", makeStatus({ pendingDecisions, pendingDecisionsHere }));
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
  it("is the server's number plus the calls waiting that only this computer knows, in the server's colour; the Dock gets the server's part", async () => {
    respond("badge_get", { count: 5, label: "5", tone: "accent" });
    // Two calls wait here; the server has a session for one of them (captions opened one), so its 5 counts that one
    // already: the rail adds only the other (the rule changed in review: it used to add both).
    app(2, 1);
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 6");
    const count = activity().querySelector(".el-rail__count")!;
    expect(count).toHaveTextContent("6");
    expect(count).not.toHaveClass("el-rail__count--danger");
    // The core adds the calls waiting that only this computer knows to the Dock's number itself.
    expect(callsOf("dock_activity_set").at(-1)).toEqual({ count: 5 });
  });

  it("a waiting call the server has a session for is counted once: in the server's number, not again here", async () => {
    respond("badge_get", { count: 5, label: "5", tone: "accent" });
    app(1, 0);
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 5");
    expect(callsOf("dock_activity_set").at(-1)).toEqual({ count: 5 });
  });

  it("turns red when the server says something failed, and follows it when Activity changes", async () => {
    respond("badge_get", { count: 2, label: "2", tone: "danger" });
    app();
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    expect(activity().querySelector(".el-rail__count")).toHaveClass("el-rail__count--danger");
    // Red is not the only sign: the name says it too.
    expect(activity()).toHaveAccessibleName("Activity, 2, something failed");
    // A read that fails for a moment (offline, the server busy) keeps the last number; it doesn't jump to a count here.
    fail("badge_get", "Network error: the server did not answer");
    await act(() => emitEvent("activity:updated", 0));
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 2, something failed");
    // Resolved on the server (an upload that went up after all): gone from the badge.
    respond("badge_get", { count: 0, label: null, tone: "accent" });
    await act(() => emitEvent("activity:updated", 0));
    await settle();
    expect(activity()).toHaveAccessibleName("Activity");
    expect(activity().querySelector(".el-rail__count")).toBeNull();
  });

  it("another sign-in never shows the last account's number; a call decided here reads the number again", async () => {
    respond("badge_get", { count: 5, label: "5", tone: "accent" });
    app();
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 5");
    // Deciding a waiting call the server counts (one with captions): its number is read again at once.
    respond("badge_get", { count: 4, label: "4", tone: "accent" });
    await act(() => emitEvent("pending:changed", []));
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 4");
    // Another account signs in, and its number can't be read yet: the list here counts, never the first account's 4.
    fail("badge_get", "Network error: the server did not answer");
    await act(() => emitEvent("app:status", makeStatus({ signIn: "sign-in-2" })));
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 2, something failed");
    respond("badge_get", { count: 1, label: "1", tone: "accent" });
    await act(() => emitEvent("activity:updated", 0));
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 1");
  });

  it("never read (offline from the start): counted here from the list, red for the failed transcript", async () => {
    fail("badge_get", "The server is unreachable.");
    app();
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    expect(activity()).toHaveAccessibleName("Activity, 2, something failed");
    expect(activity().querySelector(".el-rail__count")).toHaveClass("el-rail__count--danger");
  });

  it("from a server older than the badge (null): counted here, every call waiting counting", async () => {
    respond("badge_get", null);
    app(1, 0);
    await settle();
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
    await settle();
    // The two unread problems of the list and the call waiting (no server number has it).
    expect(activity()).toHaveAccessibleName("Activity, 3, something failed");
    expect(callsOf("dock_activity_set").at(-1)).toEqual({ count: 3 });
  });
});
