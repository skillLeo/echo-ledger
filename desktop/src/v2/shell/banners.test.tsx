// The banners above every page in the new design's window (docs/v2/audit/parity.csv DT-HLP-012): the update, server,
// share, permission, one-sided call and budget banners in the new design's look, still one at a time in their order
// (BannerSlot), × or Esc closing one until its state changes and bringing up the next, a banner about something
// opening it; classic's window keeps classic's look.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import type { AppStatus } from "../../lib/ipc";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
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

  it("DT-HLP-012: classic's window keeps classic's banners", async () => {
    await app(makeStatus({ budget: "warn" }), null);
    await screen.findByRole("heading", { name: "Library" });
    await act(flushAsync);
    const budget = await screen.findByTestId("budget-banner");
    expect(budget).toHaveClass("banner");
    expect(budget).not.toHaveClass("el-banner");
  });
});
