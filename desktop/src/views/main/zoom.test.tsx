// UX-07 [S3] Zoom, like Chrome (v21_zoom): only the main window zooms; ⌘+ ⌘= ⌘− ⌘0 (Ctrl off a Mac), the View menu's
// items, the avatar's "Zoom [−] 100% [+]" row, the title row's chip while it isn't 100%, and a bubble on each change.
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "./MainApp";
import { flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { resetSyncClock } from "../../v2/library/syncClock";
import { canRun, runAction } from "../../v2/actions/registry";
import { ZOOM_KEY, setZoom, zoomNow } from "../../lib/zoom";
import { BUBBLE_MS } from "../../v2/shell/Zoom";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);
const webview = vi.hoisted(() => ({ setZoom: vi.fn(async (_factor: number) => undefined) }));
vi.mock("@tauri-apps/api/webview", () => ({ getCurrentWebview: () => webview }));

const unlocked = { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 };
/** The new design's window drawn (its parts load on demand), and the flags read. */
const settle = async () => {
  await screen.findByTestId("title-row", undefined, { timeout: 10_000 });
  for (let i = 0; i < 3; i++) await act(flushAsync);
};
function app(flags: Record<string, boolean>, platform: "macos" | "windows" = "windows") {
  respond("app_status", makeStatus({ platform }));
  respond("get_settings", makeSettings());
  respond("lock_status", unlocked);
  respond("library_list", [makeItem("a", { title: "Bilal Qureshi" })]);
  respond("library_refresh", [makeItem("a", { title: "Bilal Qureshi" })]);
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("bin_list", { data: [] });
  respond("app_menu_set", null);
  respond("account_get", { name: "Sara Ahmed", email: "sara@example.test", email_verified: true, two_factor: false, google: false, created_at: null,
    workspace: { id: 1, name: "Acme team", role: "owner" }, deletes_at: null });
  respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.library": true, ...flags }, ui_classic: false });
  render(<MainApp />);
}
const ON = { v21_zoom: true };
const press = (key: string, mods: { ctrlKey?: boolean; metaKey?: boolean; code?: string } = { ctrlKey: true }) =>
  act(() => void fireEvent.keyDown(window, { key, ...mods }));

beforeEach(() => {
  localStorage.clear();
  resetTauriMock();
  resetSyncClock();
  resetEntitlementsForTests(makeEntitlements());
  webview.setZoom.mockClear();
  setZoom(100, false, async () => undefined);
});
afterEach(() => cleanup());

describe("zoom like Chrome (UX-07)", () => {
  it("flag off: nothing zooms; the View menu's items and the avatar's row aren't there", async () => {
    app({});
    await settle();
    press("=");
    expect(zoomNow()).toBe(100);
    for (const id of ["view.zoomIn", "view.zoomOut", "view.actualSize"]) expect(canRun(id), id).toBe(false);
    expect(screen.queryByRole("button", { name: /^Zoom/ })).toBeNull();
  });

  it("flag off after a zoom was kept: the window is put back to 100%, and the kept zoom stays for later", async () => {
    localStorage.setItem(ZOOM_KEY, "150");
    setZoom(150, false, async () => undefined);
    app({});
    await settle();
    expect(zoomNow()).toBe(100);
    expect(webview.setZoom).toHaveBeenLastCalledWith(1);
    expect(localStorage.getItem(ZOOM_KEY)).toBe("150");
  });

  it("flag on: the kept zoom comes back; the keys step it (the number pad's too) and keep it; ⌘0 is 100%", async () => {
    localStorage.setItem(ZOOM_KEY, "110");
    app(ON);
    await settle();
    expect(webview.setZoom).toHaveBeenLastCalledWith(1.1);
    press("=");
    expect(zoomNow()).toBe(125);
    press("+", { ctrlKey: true, code: "NumpadAdd" });
    expect(zoomNow()).toBe(150);
    press("-");
    expect(zoomNow()).toBe(125);
    expect(localStorage.getItem(ZOOM_KEY)).toBe("125");
    press("0");
    expect(zoomNow()).toBe(100);
    expect(localStorage.getItem(ZOOM_KEY)).toBeNull();
    // A Mac's keys are ⌘'s, not Ctrl's.
    cleanup();
    app(ON, "macos");
    await settle();
    press("=", { ctrlKey: true });
    expect(zoomNow()).toBe(100);
    press("=", { metaKey: true });
    expect(zoomNow()).toBe(110);
  });

  it("the View menu's items run it (Zoom In, Zoom Out, Actual Size)", async () => {
    app(ON);
    await settle();
    expect(runAction("view.zoomIn", { platform: "windows" })).toBe(true);
    expect(zoomNow()).toBe(110);
    runAction("view.zoomOut", { platform: "windows" });
    runAction("view.zoomOut", { platform: "windows" });
    expect(zoomNow()).toBe(90);
    runAction("view.actualSize", { platform: "windows" });
    expect(zoomNow()).toBe(100);
  });

  it("the avatar's menu: the Zoom [−] 100% [+] row next to Theme, staying open while it steps", async () => {
    app(ON);
    await settle();
    fireEvent.click(screen.getByRole("button", { name: /^Account, / }));
    const menu = screen.getByRole("menu", { name: /^Account, / });
    const labels = [...menu.querySelectorAll('[role^="menuitem"]')].map((m) => m.querySelector(".el-menu__label")?.textContent);
    expect(labels.indexOf("Zoom")).toBe(labels.indexOf("Theme") + 1);
    fireEvent.click(within(menu).getByRole("button", { name: "Zoom in" }));
    fireEvent.click(within(menu).getByRole("button", { name: "Zoom in" }));
    expect(zoomNow()).toBe(125);
    expect(screen.getByRole("menu", { name: /^Account, / })).toBeInTheDocument();
    expect(menu.querySelector(".el-menu__step-value")).toHaveTextContent("125%");
    fireEvent.click(within(menu).getByRole("button", { name: "Zoom out" }));
    expect(zoomNow()).toBe(110);
  });

  it("the chip shows in the title row while it isn't 100%; its menu steps and resets it; the bubble says each change", async () => {
    app(ON);
    await settle();
    // The bubble's 2 s, on a clock of the test's own (once the window is drawn: its waits use the real one).
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    try {
      expect(screen.queryByRole("button", { name: /^Zoom/ })).toBeNull();
      press("=");
      const chip = within(screen.getByTestId("title-row")).getByRole("button", { name: /110%/ });
      const bubble = document.querySelector(".el-zoom-bubble")!;
      expect(bubble).toHaveAttribute("data-shown");
      expect(bubble).toHaveTextContent("110% · Reset");
      act(() => void vi.advanceTimersByTime(BUBBLE_MS + 10));
      expect(bubble).not.toHaveAttribute("data-shown");
      fireEvent.click(chip);
      const menu = screen.getByRole("menu", { name: "Zoom, 110%" });
      fireEvent.click(within(menu).getByRole("menuitem", { name: "Reset to 100%" }));
      expect(zoomNow()).toBe(100);
      expect(within(screen.getByTestId("title-row")).queryByRole("button", { name: /%$/ })).toBeNull();
      // The bubble's Reset.
      press("-");
      fireEvent.click(within(bubble as HTMLElement).getByRole("button", { name: "Reset" }));
      expect(zoomNow()).toBe(100);
    } finally {
      vi.useRealTimers();
    }
  });
});
