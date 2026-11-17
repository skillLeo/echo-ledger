import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { GOAL_GROUPS, SettingsV2, sectionFor, ungrouped } from "./SettingsV2";
import { SETTINGS, findSetting } from "../../lib/settingsRegistry";
import { hasV2Page } from "./pages/route";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const ACCOUNT = { name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: false, google: false, created_at: null, workspace: null, deletes_at: null };

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(makeEntitlements({ limits: { monthly_minutes: { limit: 300, used: 212 } } }));
  respond("account_get", ACCOUNT);
  respond("get_settings", makeSettings());
  respond("storage_info", { recordings_bytes: 3_435_973_837, recordings_count: 212, disk_free_bytes: null, disk_total_bytes: null, retention_days: 0 });
});

describe("the goal groups", () => {
  it("give every feature of today's Settings exactly one home", () => {
    expect(ungrouped()).toEqual([]);
    const ids = GOAL_GROUPS.flatMap((g) => g.ids);
    expect(new Set(ids).size).toBe(ids.length);
    expect(GOAL_GROUPS.map((g) => g.title)).toEqual([
      "Recording", "Transcription & language", "Live & overlays", "Privacy & data", "Notifications", "Appearance",
      "Account & plan", "Setup & health", "Shortcuts & start-up", "About & help",
    ]);
  });

  it("a result opens its page, scrolled to the setting", () => {
    expect(sectionFor(findSetting("calls")!)).toBe("calls");
    expect(sectionFor(findSetting("calls.skip_short")!)).toBe("calls#calls.skip_short");
    expect(sectionFor(findSetting("transcription.language")!)).toBe("transcription.language#transcription.language");
  });
});

describe("SettingsV2", () => {
  const page = vi.fn((section: { id: string }, onHome: () => void) => (
    <div><p>page {section.id}</p><button type="button" onClick={onHome}>Back to Settings</button></div>
  ));
  const show = async (props: Partial<Parameters<typeof SettingsV2>[0]> = {}) => {
    render(<SettingsV2 available={() => true} renderPage={page} {...props} />);
    await act(flushAsync);
  };

  it("shows the profile card with the plan chip and Upgrade, then the groups", async () => {
    await show();
    const card = screen.getByRole("region", { name: "Ayesha Siddiqui" });
    expect(within(card).getByText("Free")).toBeInTheDocument();
    expect(within(card).getByRole("button", { name: "Upgrade" })).toBeInTheDocument();
    const recording = screen.getByRole("list", { name: "Recording" });
    const names = within(recording).getAllByRole("button").map((b) => document.getElementById(b.getAttribute("aria-labelledby")!)?.textContent);
    expect(names).toEqual(["Calls & meetings", "Microphone & audio", "Screen moments", "Voice notes & dictation"]);
    expect(within(screen.getByRole("list", { name: "Account & plan" })).getByRole("button", { name: "Plan & usage" })).toHaveAccessibleDescription("Free");
    expect(screen.queryByRole("list", { name: "Setup & health" })).toBeNull();
  });

  it("search finds an individual setting and jumps to it; back returns to the list", async () => {
    await show();
    fireEvent.change(screen.getByRole("searchbox", { name: "Search settings" }), { target: { value: "skip short calls" } });
    const results = screen.getByRole("list", { name: "Results" });
    fireEvent.click(within(results).getByRole("button", { name: "Skip calls shorter than" }));
    expect(screen.getByText("page calls#calls.skip_short")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Back to Settings" }));
    expect(screen.getByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument();
  });

  it("finds settings by their Roman Urdu and Urdu words too", async () => {
    await show();
    fireEvent.change(screen.getByRole("searchbox", { name: "Search settings" }), { target: { value: "zuban" } });
    expect(within(screen.getByRole("list", { name: "Results" })).getByRole("button", { name: "Language" })).toBeInTheDocument();
  });

  it("a setting with its own title in the new look is found and shown by it (Trash, once the Bin)", async () => {
    await show();
    fireEvent.change(screen.getByRole("searchbox", { name: "Search settings" }), { target: { value: "trash" } });
    const results = screen.getByRole("list", { name: "Results" });
    fireEvent.click(within(results).getByRole("button", { name: "Trash" }));
    expect(screen.getByText("page storage#storage.bin")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Back to Settings" }));
    fireEvent.change(screen.getByRole("searchbox", { name: "Search settings" }), { target: { value: "bin" } });
    expect(within(screen.getByRole("list", { name: "Results" })).getByRole("button", { name: "Trash" })).toBeInTheDocument();
  });

  it("no match says so and offers what is close", async () => {
    await show();
    fireEvent.change(screen.getByRole("searchbox", { name: "Search settings" }), { target: { value: "caller tune" } });
    expect(screen.getByRole("region", { name: "No settings match" })).toBeInTheDocument();
  });

  it("opens a page asked for from elsewhere, and offers Setup & health when its flag is on", async () => {
    const { rerender } = render(<SettingsV2 available={() => true} renderPage={page} setupHealth />);
    await act(flushAsync);
    fireEvent.click(within(screen.getByRole("list", { name: "Setup & health" })).getByRole("button", { name: "Setup & health" }));
    expect(screen.getByText("page setup_health")).toBeInTheDocument();
    rerender(<SettingsV2 available={() => true} renderPage={page} setupHealth section={{ id: "plan", seq: 7 }} />);
    expect(screen.getByText("page plan")).toBeInTheDocument();
  });

  it("start-up is a switch in place; sign out asks the app", async () => {
    respond("update_settings", makeSettings({ autostart: true }));
    const onSignOut = vi.fn();
    await show({ onSignOut });
    fireEvent.click(screen.getByRole("switch", { name: "Start when I sign in" }));
    await act(flushAsync);
    expect(callsOf("update_settings")).toEqual([{ patch: { autostart: true } }]);
    fireEvent.click(screen.getByRole("button", { name: "Sign out" }));
    expect(onSignOut).toHaveBeenCalled();
  });
});

describe("start-up", () => {
  it("a failed change says why under the switch", async () => {
    const { fail } = await import("../../test/tauri");
    fail("update_settings", "This computer refused to start EchoLedger at sign-in.");
    render(<SettingsV2 available={() => true} renderPage={() => null} />);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("switch", { name: "Start when I sign in" }));
    await act(flushAsync);
    const row = screen.getByRole("list", { name: "Shortcuts & start-up" });
    expect(within(row).getByRole("alert")).toHaveTextContent("This computer refused to start EchoLedger at sign-in.");
    expect(screen.getByRole("switch", { name: "Start when I sign in" })).toHaveAttribute("aria-checked", "false");
  });
});

describe("the app with ui_v2.settings", () => {
  it("Settings opens the new list; About opens its v2 page, Devices from it, and the way back returns to the list", async () => {
    respond("app_status", makeStatus());
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("session_detail", {});
    respond("security_overview", { devices: [], events: [] });
    respond("input_devices", []);
    respond("update_status", { phase: "idle", current: "2.0.0", version: null, notes: null, checkedAt: null, message: null, progress: null });
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.settings": true } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    const nav = await screen.findByRole("navigation", { name: "Main" });
    fireEvent.click(within(nav).getByRole("button", { name: "Settings" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "About" }));
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "About" })).toBeInTheDocument();
    expect(document.querySelector("[data-v2=settings-page]")).not.toBeNull();
    expect(document.querySelector(".settings-root")).toBeNull();
    fireEvent.click(document.querySelector("[data-v2=settings-page] > .el-page__back")!);
    await act(flushAsync);
    expect(screen.getByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Switch back to classic" })).toBeInTheDocument();
  });

  it("every Settings page has its v2 page (Help and Setup & health have their own)", () => {
    const pages = SETTINGS.filter((e) => e.kind === "page").map((e) => e.id);
    expect(pages.length).toBeGreaterThan(30);
    expect(pages.filter((id) => id !== "help" && !hasV2Page(id))).toEqual([]);
  });
});
