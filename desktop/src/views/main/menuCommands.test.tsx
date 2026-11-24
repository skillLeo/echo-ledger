// FIX-05: the window's own menu commands (New Project…, Sync Now, Show Captions…) are there only while the app is in use
// (signed in, unlocked, set up): on the lock screen and at sign-in nothing answers them, so the menu bar greys them.
// The menu's Sync Now is ⌘R's (FIX-10). The bar itself is drawn only with the new design's shell; classic keeps its own.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, render, screen } from "@testing-library/react";
import MainApp from "./MainApp";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { resetSyncClock } from "../../v2/library/syncClock";
import { canRun, runAction } from "../../v2/actions/registry";
import type { NativeMenuBar } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ITEMS = [makeItem("a", { title: "Bilal Qureshi" })];
const settle = async () => {
  for (let i = 0; i < 3; i++) await act(flushAsync);
};
/** Long enough for the bar to settle and be sent (useMenuBar's 50 ms). */
const barSettles = () => act(() => new Promise((done) => setTimeout(done, 80)));
const COMMANDS = ["file.newProject", "file.syncNow", "live.captions", "view.themeDark", "go.trash", "help.setupHealth", "project.open"];
const unlocked = { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 };

function app(over: { status?: Parameters<typeof makeStatus>[0]; lock?: typeof unlocked; flags?: Record<string, boolean> } = {}) {
  respond("app_status", makeStatus(over.status));
  respond("get_settings", makeSettings());
  respond("lock_status", over.lock ?? unlocked);
  respond("library_list", ITEMS);
  respond("library_refresh", ITEMS);
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("bin_list", { data: [] });
  respond("app_menu_set", null);
  respond("ui_flags", { ui_flags: over.flags ?? { "ui_v2.shell": true, "ui_v2.library": true }, ui_classic: false });
  render(<MainApp />);
}

beforeEach(() => {
  resetTauriMock();
  resetSyncClock();
  resetEntitlementsForTests(makeEntitlements());
});
afterEach(() => cleanup());

describe("the window's menu commands (FIX-05)", () => {
  it("on the lock screen nothing answers them, and the Mac's menu bar greys them", async () => {
    app({ status: { platform: "macos" }, lock: { ...unlocked, enabled: true, locked: true, hasPin: true } });
    await settle();
    await barSettles();
    expect(screen.getByRole("heading", { name: "EchoLedger is locked" })).toBeInTheDocument();
    for (const id of COMMANDS) expect(canRun(id), id).toBe(false);
    const bar = callsOf("app_menu_set").at(-1)?.bar as NativeMenuBar;
    const file = bar.menus.find((m) => m.title === "File")!.items;
    expect(file.find((e) => e.kind === "item" && e.action === "file.newProject")).toMatchObject({ enabled: false });
    expect(file.find((e) => e.kind === "item" && e.action === "file.syncNow")).toMatchObject({ enabled: false });
    // Run anyway (a pick kept from before): nothing happens.
    expect(runAction("file.syncNow", { platform: "macos" })).toBe(false);
    expect(callsOf("library_refresh")).toHaveLength(0);
  });

  it("at sign-in nothing answers them either", async () => {
    app({ status: { paired: false } });
    await settle();
    for (const id of COMMANDS) expect(canRun(id), id).toBe(false);
  });

  it("in the app they run; the menu's Sync Now is ⌘R's, never behind a dialog", async () => {
    app();
    await settle();
    await screen.findByRole("navigation", { name: "Main" });
    await settle();
    for (const id of COMMANDS) expect(canRun(id), id).toBe(true);
    const before = callsOf("library_refresh").length;
    act(() => { runAction("file.syncNow", { platform: "windows" }); });
    await settle();
    expect(callsOf("library_refresh").length).toBe(before + 1);
    const dialog = document.createElement("div");
    dialog.setAttribute("aria-modal", "true");
    document.body.append(dialog);
    act(() => { runAction("file.syncNow", { platform: "windows" }); });
    await settle();
    dialog.remove();
    expect(callsOf("library_refresh").length).toBe(before + 1);
  });

  it("the bar is drawn on a Mac only with the new design's shell; classic keeps the core's own", async () => {
    app({ status: { platform: "macos" }, flags: { "ui_v2.library": true } });
    await settle();
    await barSettles();
    expect(callsOf("app_menu_set")).toHaveLength(0);
    cleanup();
    resetTauriMock();
    app({ status: { platform: "macos" } });
    await settle();
    await barSettles();
    expect((callsOf("app_menu_set").at(-1)?.bar as NativeMenuBar).menus[0].title).toBe("EchoLedger");
  });
});
