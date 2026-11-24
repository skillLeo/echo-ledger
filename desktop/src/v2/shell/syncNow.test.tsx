// FIX-10: F5 and ⌘R (Ctrl+R) are Sync Now in the app itself: the library syncs (syncLibrary, so the sidebar's "Synced
// … ago" follows), never behind a dialog, on the lock screen or in setup.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { SYNC_NOW_EVENT } from "../../lib/nativeFeel";
import { resetSyncClock } from "../library/syncClock";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [makeItem("a", { title: "Bilal Qureshi" })];
const settle = async () => {
  await act(flushAsync);
  await act(flushAsync);
};

beforeEach(() => {
  resetTauriMock();
  resetSyncClock();
  resetEntitlementsForTests(makeEntitlements());
});

describe("Sync Now from the keys (FIX-10)", () => {
  it("syncs the library in the app, but not behind a dialog", async () => {
    respond("app_status", makeStatus());
    respond("get_settings", makeSettings());
    respond("library_list", ITEMS);
    respond("library_refresh", ITEMS);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("bin_list", { data: [] });
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true }, ui_classic: false });
    render(<MainApp />);
    await settle();
    await screen.findByRole("navigation", { name: "Main" });
    await settle();
    const before = callsOf("library_refresh").length;
    act(() => { window.dispatchEvent(new Event(SYNC_NOW_EVENT)); });
    await settle();
    expect(callsOf("library_refresh").length).toBe(before + 1);
    const dialog = document.createElement("div");
    dialog.setAttribute("aria-modal", "true");
    document.body.append(dialog);
    act(() => { window.dispatchEvent(new Event(SYNC_NOW_EVENT)); });
    await settle();
    dialog.remove();
    expect(callsOf("library_refresh").length).toBe(before + 1);
  });

  it("does nothing before the app is there (signed out: the sign-in)", async () => {
    respond("app_status", makeStatus({ paired: false }));
    respond("get_settings", makeSettings());
    respond("library_refresh", ITEMS);
    respond("ui_flags", { ui_flags: {}, ui_classic: false });
    render(<MainApp />);
    await settle();
    act(() => { window.dispatchEvent(new Event(SYNC_NOW_EVENT)); });
    await settle();
    expect(callsOf("library_refresh")).toHaveLength(0);
  });
});
