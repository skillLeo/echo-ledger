// The banners above every page in the new design's window (docs/v2/audit/parity.csv DT-HLP-012): the update, server,
// share, permission, one-sided call and budget banners in the new design's look, still one at a time in their order
// (BannerSlot), × or Esc closing one until its state changes and bringing up the next, a banner about something
// opening it; classic's window keeps classic's look.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import type { AppStatus } from "../../lib/ipc";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makePrivacyStatus, makeSettings, makeStatus } from "../../test/fixtures";
import { Notice, NoticeLook } from "../../components/Notice";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { restartForTests } from "../../components/StateBanner";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const NONE = { appUpdate: null, announcement: null, maintenance: null, waiting: null };
const BUDGET_WARN = "You have used 80% of this month's transcription limit.";

async function app(status: AppStatus, flags: Record<string, boolean> | null = { "ui_v2.shell": true }) {
  respond("app_status", status);
  respond("get_settings", makeSettings());
  respond("library_list", [makeItem("a", { title: "Bilal Qureshi" })]);
  respond("library_refresh", [makeItem("a", { title: "Bilal Qureshi" })]);
  respond("list_clients", { data: [] });
  respond("mic_users", []);
  respond("session_detail", {});
  respond("bin_list", { data: [] });
  if (flags) respond("ui_flags", { ui_flags: flags, ui_classic: false });
  render(<MainApp />);
  await act(flushAsync);
  await act(flushAsync);
}
const shown = () => [...document.querySelectorAll<HTMLElement>("[data-testid=maintenance], [data-testid=budget-banner], [data-testid=announcement], [data-testid=app-update]")];

beforeEach(() => {
  resetTauriMock();
  restartForTests();
  resetEntitlementsForTests(makeEntitlements());
  try {
    localStorage.clear();
  } catch {
    // no storage here
  }
});

describe("the banners in the new design's window", () => {
  const status = () => makeStatus({
    budget: "warn",
    server: { ...NONE, maintenance: { message: "Upgrading the database.", until: null }, announcement: { text: "New: Live Assist for Zoom.", level: "info" } } as AppStatus["server"],
  });

  it("DT-HLP-012: one banner at a time in the new look, the most important first; × brings up the next, in order", async () => {
    await app(status());
    await screen.findByRole("navigation", { name: "Main" });
    await screen.findByTestId("maintenance");
    // Maintenance comes before the budget, the budget before the announcement: one shows.
    expect(shown().map((b) => b.dataset.testid)).toEqual(["maintenance"]);
    const maintenance = screen.getByTestId("maintenance");
    expect(maintenance).toHaveClass("el-banner", "el-banner--app");
    expect(maintenance.closest(".el-root")).not.toBeNull();
    expect(maintenance).not.toHaveClass("banner");
    expect(maintenance).toHaveTextContent("Upgrading the database.");
    fireEvent.click(within(maintenance).getByRole("button", { name: "Dismiss" }));
    expect(shown().map((b) => b.dataset.testid)).toEqual(["budget-banner"]);
    expect(screen.getByTestId("budget-banner")).toHaveClass("el-banner--warning");
    fireEvent.click(within(screen.getByTestId("budget-banner")).getByRole("button", { name: "Dismiss" }));
    expect(shown().map((b) => b.dataset.testid)).toEqual(["announcement"]);
    expect(screen.getByTestId("announcement")).toHaveTextContent("New: Live Assist for Zoom.");
  });

  it("DT-HLP-012: Esc on a banner closes it; it stays closed while its state is the same and comes back when it changes", async () => {
    await app(makeStatus({ budget: "warn" }));
    await screen.findByRole("navigation", { name: "Main" });
    const budget = await screen.findByTestId("budget-banner");
    fireEvent.keyDown(within(budget).getByRole("button", { name: /Open Plan & usage/ }), { key: "Escape" });
    expect(screen.queryByTestId("budget-banner")).toBeNull();
    await act(() => emitEvent("app:status", makeStatus({ budget: "warn" })));
    await act(flushAsync);
    expect(screen.queryByTestId("budget-banner")).toBeNull();
    await act(() => emitEvent("app:status", makeStatus({ budget: "reached" })));
    await act(flushAsync);
    const reached = await screen.findByTestId("budget-banner");
    expect(reached).toHaveClass("el-banner--danger");
    expect(reached).toHaveAttribute("role", "alert");
  });

  it("DT-HLP-012: a banner about something opens it: the budget opens Plan & usage", async () => {
    await app(makeStatus({ budget: "warn" }));
    await screen.findByRole("navigation", { name: "Main" });
    const budget = await screen.findByTestId("budget-banner");
    expect(budget).toHaveTextContent(BUDGET_WARN);
    fireEvent.click(within(budget).getByRole("button", { name: /Open Plan & usage/ }));
    await act(flushAsync);
    await act(flushAsync);
    expect(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" })).toHaveAttribute("aria-current", "page");
    expect(screen.queryByTestId("budget-banner")).toBeNull();
  });

  it("DT-HLP-012: an update the server asks for has its Update button in the new look, and it looks for the update", async () => {
    respond("update_check", null);
    await app(makeStatus({ server: { ...NONE, appUpdate: { minimum: "9.9.0", message: null, refused: false } } as AppStatus["server"] }));
    await screen.findByRole("navigation", { name: "Main" });
    const update = await screen.findByTestId("app-update");
    expect(update).toHaveClass("el-banner--warning");
    expect(update).toHaveTextContent("Please update EchoLedger to 9.9.0 or later.");
    const button = within(update).getByRole("button", { name: /^Update/ });
    expect(button.closest(".el-banner__actions")).not.toBeNull();
    fireEvent.click(button);
    await act(flushAsync);
    expect(callsOf("update_check").length).toBeGreaterThan(0);
  });

  it("DT-HLP-012: the share banner (the window is in a whole-screen share) in the new look", async () => {
    respond("privacy_status", makePrivacyStatus({ platform: "windows", mainInShare: true, shareNumber: 1 }));
    await app(makeStatus());
    await screen.findByRole("navigation", { name: "Main" });
    const share = await screen.findByTestId("main-in-share");
    expect(share).toHaveClass("el-banner", "el-banner--warning", "el-banner--app");
    expect(share).not.toHaveClass("banner");
    expect(share.closest('[data-testid="app-banners"]')).not.toBeNull();
    fireEvent.click(within(share).getByRole("button", { name: "Dismiss" }));
    expect(screen.queryByTestId("main-in-share")).toBeNull();
  });

  it("DT-HLP-012: the permission banner (macOS forgot a permission) in the new look, with its own button", async () => {
    respond("permissions_status", { microphone: "allowed", systemAudio: "denied", screen: "allowed", browserAutomation: true, platform: "macos",
      lost: "macOS turned off System audio for EchoLedger: calls record only your side." });
    respond("permission_notice_dismiss", undefined);
    await app(makeStatus({ platform: "macos" }));
    await screen.findByRole("navigation", { name: "Main" });
    const loss = await screen.findByTestId("permission-loss");
    expect(loss).toHaveClass("el-banner--warning", "el-banner--app");
    expect(loss).not.toHaveClass("banner", "warn");
    expect(loss).toHaveTextContent("macOS turned off System audio for EchoLedger");
    fireEvent.click(within(loss).getByRole("button", { name: "Dismiss" }));
    await act(flushAsync);
    expect(screen.queryByTestId("permission-loss")).toBeNull();
    expect(callsOf("permission_notice_dismiss")).toHaveLength(1);
  });

  it("DT-HLP-012: the one-sided call banner in the new look: an alert, Ready check goes to the Record page; it outranks the budget", async () => {
    await app(makeStatus({ budget: "warn" }));
    await screen.findByRole("navigation", { name: "Main" });
    await screen.findByTestId("budget-banner");
    await act(() => emitEvent("capture:one_sided", { title: "Only your side was heard", reason: "Zoom's sound never reached EchoLedger.", fix: "Run the Ready check." }));
    await act(flushAsync);
    const one = await screen.findByTestId("one-sided");
    expect(screen.queryByTestId("budget-banner")).toBeNull();
    expect(one).toHaveAttribute("role", "alert");
    expect(one).toHaveClass("el-banner--warning", "el-banner--app", "one-sided-banner");
    expect(one).not.toHaveClass("banner");
    fireEvent.click(within(one).getByRole("button", { name: "Ready check" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(screen.queryByTestId("one-sided")).toBeNull();
    // The budget waited its turn and shows again.
    expect(screen.getByTestId("budget-banner")).toBeInTheDocument();
  });

  it("DT-HLP-012: the banners' live region is in the page before any banner and stays after the last", async () => {
    await app(makeStatus({ budget: "warn" }));
    await screen.findByRole("navigation", { name: "Main" });
    const region = screen.getByTestId("app-banners");
    expect(region).toHaveAttribute("aria-live", "polite");
    const budget = await screen.findByTestId("budget-banner");
    expect(region).toContainElement(budget);
    fireEvent.click(within(budget).getByRole("button", { name: "Dismiss" }));
    expect(screen.getByTestId("app-banners")).toBe(region);
    expect(region.querySelector(".el-banner")).toBeNull();
  });

  it("DT-HLP-012: a banner with a time goes by itself, paused while the pointer is on it; a new timerKey starts it again; its own classes stay", async () => {
    vi.useFakeTimers();
    try {
      const onDismiss = vi.fn();
      const banner = (key: number) => (
        <NoticeLook.Provider value="v2">
          <Notice kind="banner" tone="info" className="banner info my-banner" timeoutMs={1000} timerKey={key} onDismiss={onDismiss} testId="timed">Saved</Notice>
        </NoticeLook.Provider>
      );
      const { rerender } = render(banner(1));
      const el = screen.getByTestId("timed");
      expect(el).toHaveClass("el-banner", "my-banner");
      expect(el).not.toHaveClass("banner", "info");
      expect(el.querySelector(".el-button .el-icon svg")).not.toBeNull();
      fireEvent.mouseEnter(el);
      act(() => vi.advanceTimersByTime(2000));
      expect(onDismiss).not.toHaveBeenCalled();
      fireEvent.mouseLeave(el);
      act(() => vi.advanceTimersByTime(600));
      rerender(banner(2));
      act(() => vi.advanceTimersByTime(600));
      expect(onDismiss).not.toHaveBeenCalled();
      act(() => vi.advanceTimersByTime(500));
      expect(onDismiss).toHaveBeenCalledTimes(1);
    } finally {
      vi.useRealTimers();
    }
  });

  it("DT-HLP-012: classic's window keeps classic's banners", async () => {
    await app(makeStatus({ budget: "warn" }), null);
    await screen.findByRole("heading", { name: "Library" });
    await act(flushAsync);
    const budget = await screen.findByTestId("budget-banner");
    expect(budget).toHaveClass("banner");
    expect(budget).not.toHaveClass("el-banner");
  });
});
