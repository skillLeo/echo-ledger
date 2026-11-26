// Settings in two levels (UX-11, DES-05): with the panel (the search and the groups) a page is its group's whole page,
// its pages as titled sections; no inner list and no "‹ Settings". A sub-page goes back to its group's page.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { SettingsPatch } from "../../../lib/ipc";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondWith } from "../../../test/tauri";
import { makeItem, makeMicUser, makeSettings, makeStatus, sampleEntitlements } from "../../../test/fixtures";
import { resetEntitlementsForTests } from "../../../lib/entitlements";
import { setting, type SettingEntry } from "../../../lib/settingsRegistry";
import { GOAL_GROUPS, entryOf } from "../groups";
import MainApp from "../../../views/main/MainApp";
import { SettingsPagesV2 } from "./SettingsPagesV2";
import { hasV2Page, sectionTarget } from "./route";

vi.mock("@tauri-apps/api/core", async () => (await import("../../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../../test/tauri")).eventModule);

const SETTINGS = makeSettings();
const ACCOUNT = { name: "Ayesha Siddiqui", email: "ayesha@qureshitextiles.pk", email_verified: true, two_factor: true, google: false, created_at: null, workspace: null, deletes_at: null };
const run = <T,>(fn: () => Promise<T>) => fn().catch(() => undefined);

beforeEach(() => {
  resetTauriMock();
  respond("get_settings", SETTINGS);
  respondWith("update_settings", ({ patch }) => ({ ...SETTINGS, ...(patch as SettingsPatch) }));
  respond("dictation_history", []);
  respond("mic_users", [makeMicUser()]);
  respond("mic_last_not_recorded", null);
  respond("list_clients", { data: [] });
  resetEntitlementsForTests(sampleEntitlements("free"));
  respond("account_get", ACCOUNT);
  respond("security_overview", { devices: [], events: [] });
  respond("input_devices", []);
  respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos" });
  respond("screens_status", { supported: true, platform: "macos", borderless: false, permission: true, inPlan: true, onServer: true });
  respond("lock_status", { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 });
  respond("update_status", { phase: "idle", current: "2.0.0", version: null, notes: null, checkedAt: null, message: null, progress: null });
  respond("storage_info", { recordings_bytes: 1_000_000, recordings_count: 3, disk_free_bytes: null, disk_total_bytes: null, retention_days: 0 });
});

const everything = () => true;
const show = async (section: string, { platform = "macos", available = everything as (e: SettingEntry) => boolean } = {}) => {
  const onSection = vi.fn();
  const onHome = vi.fn();
  const view = render(<div className="el-root"><SettingsPagesV2 section={{ id: section, seq: 1 }} grouped threeLevels available={available} onHome={onHome}
    onSection={onSection} run={run} status={makeStatus({ platform: platform as "macos" })} onUnpaired={vi.fn()} /></div>);
  await act(flushAsync);
  await act(flushAsync);
  return { onSection, onHome, view };
};
const headings = (level: number) => screen.queryAllByRole("heading", { level }).map((h) => h.textContent);

describe("a group's page (UX-11)", () => {
  it("Recording: the group's title, each page a titled section, their own sections a level under; no way back, no inner list", async () => {
    const { onHome } = await show("calls");
    expect(headings(1)).toEqual(["Recording"]);
    expect(headings(2)).toEqual(["Calls & meetings", "Microphone & audio", "Screen moments", "Voice notes & dictation"]);
    // DES-05: a section's title is a heading under its page's, text only (no button in it).
    const record = screen.getByRole("heading", { level: 3, name: "Record automatically" });
    expect(record).toHaveClass("el-group__title");
    expect(within(record).queryByRole("button")).toBeNull();
    expect(document.querySelector("[data-v2=settings-group]")).not.toBeNull();
    expect(document.querySelector(".el-page__back")).toBeNull();
    expect(screen.queryByRole("button", { name: "Settings" })).toBeNull();
    expect(screen.queryByRole("navigation")).toBeNull();
    // Each page's rows are on the one page: the call choice and the microphone's.
    expect(screen.getByRole("radio", { name: /Ask me/ })).toBeInTheDocument();
    expect(screen.getByRole("switch", { name: "Auto-record WhatsApp" })).toBeInTheDocument();
    expect(onHome).not.toHaveBeenCalled();
  });

  it("a sub-page (Call sites) has its own page; its way back is the group's page, at the row that opened it", async () => {
    await show("calls");
    fireEvent.click(screen.getByRole("button", { name: /^Call sites/ }));
    await act(flushAsync);
    expect(headings(1)).toEqual(["Call sites"]);
    const back = document.querySelector<HTMLButtonElement>(".el-page__back")!;
    expect(back).toHaveTextContent("Recording");
    fireEvent.click(back);
    await act(flushAsync);
    expect(headings(1)).toEqual(["Recording"]);
    await waitFor(() => expect(document.getElementById("setting-calls.sites")).toHaveFocus());
    // Back to where it was, not a search result: no highlight.
    expect(document.querySelector(".is-highlighted")).toBeNull();
  });

  it("a setting asked for (a search result) opens its group's page, scrolled to it, highlighted and with the keyboard on it", async () => {
    await show("calls#calls.skip_short");
    expect(headings(1)).toEqual(["Recording"]);
    await waitFor(() => expect(document.querySelector(".is-highlighted")?.id).toBe("setting-calls.skip_short"));
    expect(document.getElementById("setting-calls.skip_short")).toHaveFocus();
  });

  it("a page asked for that isn't the group's first (Microphone & audio): its section, the keyboard on its title", async () => {
    await show("mic");
    expect(headings(1)).toEqual(["Recording"]);
    await waitFor(() => expect(screen.getByRole("heading", { level: 2, name: "Microphone & audio" })).toHaveFocus());
    // Not a setting: nothing highlighted.
    expect(document.querySelector(".is-highlighted")).toBeNull();
  });

  it("a sub-page answers Esc and ⌘[ with its way back; the group's page stays while it shows, so nothing is read again", async () => {
    await show("calls");
    const reads = callsOf("input_devices").length;
    expect(reads).toBeGreaterThan(0);
    const open = async () => {
      fireEvent.click(screen.getByRole("button", { name: /^Call sites/ }));
      await act(flushAsync);
      expect(headings(1)).toEqual(["Call sites"]);
    };
    await open();
    const back = document.querySelector<HTMLButtonElement>(".el-page__back")!;
    expect(back).toHaveAttribute("aria-keyshortcuts", expect.stringMatching(/^Escape /));
    fireEvent.keyDown(window, { key: "Escape" });
    await act(flushAsync);
    expect(headings(1)).toEqual(["Recording"]);
    await open();
    // Esc while typing in a field stays in the field.
    const field = screen.getByRole("textbox", { name: "Address or title contains" });
    fireEvent.keyDown(field, { key: "Escape" });
    expect(headings(1)).toEqual(["Call sites"]);
    fireEvent.keyDown(window, { key: "[", ctrlKey: true });
    await act(flushAsync);
    expect(headings(1)).toEqual(["Recording"]);
    expect(callsOf("input_devices")).toHaveLength(reads);
  });

  it("a sub-page of another group (Calls' consent notice): the app opens it, and its way back is its own group's page", async () => {
    const { onSection } = await show("calls");
    fireEvent.click(screen.getByRole("button", { name: "Read the recording consent notice" }));
    expect(onSection).toHaveBeenCalledWith("privacy.consent");
    cleanup();
    await show("privacy.consent");
    const back = document.querySelector<HTMLButtonElement>(".el-page__back")!;
    expect(back).toHaveTextContent("Privacy & data");
    fireEvent.click(back);
    await act(flushAsync);
    expect(headings(1)).toEqual(["Privacy & data"]);
    await waitFor(() => expect(document.getElementById("setting-privacy.consent")).toHaveFocus());
  });

  it("each page's Reset names its page on a group's page; a group of one page keeps Reset to defaults", async () => {
    await show("calls");
    expect(screen.getByRole("button", { name: "Reset Calls & meetings to defaults" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Reset Microphone & audio to defaults" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Reset to defaults" })).toBeNull();
    cleanup();
    await show("notifications");
    expect(screen.getByRole("button", { name: "Reset to defaults" })).toBeInTheDocument();
  });

  it("Shortcuts & start-up: the keys, then Start-up with its switches, and the Mac's Menu bar & Dock; a start-up switch found opens here", async () => {
    await show("app.autostart#app.autostart");
    expect(headings(1)).toEqual(["Shortcuts & start-up"]);
    expect(headings(2)).toEqual(["Keyboard shortcuts", "Start-up"]);
    expect(screen.getByRole("heading", { level: 3, name: "Menu bar & Dock" })).toBeInTheDocument();
    for (const name of ["Show EchoLedger in the menu bar", "Show timer in the menu bar", "Show count on the app icon"]) {
      expect(screen.getByRole("switch", { name })).toBeInTheDocument();
    }
    await waitFor(() => expect(document.querySelector(".is-highlighted")?.id).toBe("setting-app.autostart"));
    fireEvent.click(screen.getByRole("switch", { name: "Start when I sign in" }));
    await act(flushAsync);
    expect(callsOf("update_settings")).toContainEqual({ patch: { autostart: !SETTINGS.autostart } });
  });

  it("on Windows: what ✕ does is in Start-up, and nothing of the Mac's menu bar", async () => {
    const available = (e: SettingEntry) => !e.requires?.os || e.requires.os === "windows";
    await show("shortcuts", { platform: "windows", available });
    expect(screen.getByRole("combobox", { name: /^When I close the window/ })).toBeInTheDocument();
    expect(screen.queryByRole("heading", { name: "Menu bar & Dock" })).toBeNull();
    expect(screen.queryByRole("switch", { name: "Show EchoLedger in the menu bar" })).toBeNull();
  });

  it("About & help: Help is one row (no title over it, its words from the settings list) that opens its own page", async () => {
    const { onSection } = await show("about");
    expect(headings(1)).toEqual(["About & help"]);
    expect(headings(2)).toEqual(["About"]);
    const help = screen.getByRole("button", { name: /^Help/ });
    expect(help).toHaveAccessibleDescription(setting("help").description!);
    fireEvent.click(help);
    expect(onSection).toHaveBeenCalledWith("help");
  });

  it("a page of another group opens through the app, so the panel shows its group (About › Device name opens Devices)", async () => {
    const { onSection } = await show("about");
    fireEvent.click(screen.getByRole("button", { name: /^Device name/ }));
    expect(onSection).toHaveBeenCalledWith("devices");
  });

  it("only what this computer, account and plan offer: no Transcription key without plans", async () => {
    await show("transcription.language", { available: (e) => e.id !== "key" });
    expect(headings(1)).toEqual(["Transcription & language"]);
    expect(headings(2)).toEqual(["Language", "Custom words"]);
  });

  it("a group of one page, or a page named as its group, isn't titled twice", async () => {
    await show("notifications");
    expect(headings(1)).toEqual(["Notifications"]);
    expect(headings(2)).not.toContain("Notifications");
    cleanup();
    await show("privacy");
    expect(headings(1)).toEqual(["Privacy & data"]);
    expect(headings(2)).toEqual(["Storage", "App lock"]);
  });
});

describe("every group (one home per feature)", () => {
  it("every group's page holds each of its pages as a section (one home per feature)", async () => {
    for (const group of GOAL_GROUPS) {
      if (group.ids.includes("setup_health")) continue;
      cleanup();
      await show(entryOf(group));
      expect(headings(1), group.title).toEqual([group.title]);
      const pages = group.ids.filter((id) => id !== "help" && !id.startsWith("app.")).map((id) => setting(id).title);
      const parts = [...document.querySelectorAll(".el-settings-part")].map((p) => p.id);
      for (const id of group.ids.filter((x) => x !== "help" && !x.startsWith("app."))) expect(parts, `${group.title}: ${id}`).toContain(`settings-part-${id}`);
      // Each titled by its page, but for the page named as the group (its title is the page's).
      if (parts.length > 1) for (const title of pages.filter((t) => t !== group.title)) expect(headings(2), group.title).toContain(title);
    }
  });
});

describe("which sections open a group's page", () => {
  it("a start-up switch (no page of its own) opens Shortcuts & start-up in two levels, and today's list without the panel", () => {
    expect(sectionTarget("app.autostart#app.autostart", true)).toEqual({ page: "shortcuts", setting: "app.autostart" });
    expect(hasV2Page("app.menu_bar_icon", true)).toBe(true);
    expect(sectionTarget("app.autostart#app.autostart")).toBeNull();
    expect(sectionTarget("signout", true)).toBeNull();
  });
});

describe("the app in two levels (ui_v2.library)", () => {
  // A window wide enough for the panel beside the page (below 1100 px it is a flyout).
  beforeEach(() => {
    Object.defineProperty(window, "innerWidth", { value: 1440, configurable: true, writable: true });
    return () => Object.defineProperty(window, "innerWidth", { value: 1024, configurable: true, writable: true });
  });
  const app = async () => {
    respond("app_status", makeStatus({ platform: "macos" }));
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("bin_list", { data: [] });
    respond("app_menu_set", null);
    respond("dock_activity_set", null);
    respond("activity_list", { data: [], unread_count: 0 });
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.settings": true, "ui_v2.library": true }, ui_classic: false });
    render(<MainApp />);
    for (let i = 0; i < 3; i++) await act(flushAsync);
    await screen.findByRole("navigation", { name: "Main" }, { timeout: 15_000 });
  };
  const panel = () => within(screen.getByTestId("nav-panel"));

  it("⌘, opens Settings; a group from the panel is its whole page; Help from About & help goes back to it", async () => {
    await app();
    // The menu's Settings… (⌘,) asks the window for Settings.
    await act(() => emitEvent("main:navigate", { view: "settings" }));
    for (let i = 0; i < 3; i++) await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument();
    fireEvent.click(panel().getByRole("button", { name: "Recording" }));
    for (let i = 0; i < 3; i++) await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Recording" })).toBeInTheDocument();
    expect(panel().getByRole("button", { name: "Recording" })).toHaveAttribute("aria-current", "page");
    expect(document.querySelector(".el-page__back")).toBeNull();
    // A sub-page: the title row's ← (and ⌘[ with it) is its way back.
    fireEvent.click(screen.getByRole("button", { name: /^Call sites/ }));
    for (let i = 0; i < 2; i++) await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Call sites" })).toBeInTheDocument();
    fireEvent.click(within(screen.getByTestId("title-row")).getByRole("button", { name: "Back" }));
    for (let i = 0; i < 2; i++) await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Recording" })).toBeInTheDocument();
    fireEvent.click(panel().getByRole("button", { name: "About & help" }));
    for (let i = 0; i < 3; i++) await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "About & help" })).toBeInTheDocument();
    fireEvent.click(within(document.querySelector<HTMLElement>("[data-v2=settings-group]")!).getByRole("button", { name: /^Help/ }));
    for (let i = 0; i < 3; i++) await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Help" })).toBeInTheDocument();
    const back = document.querySelector<HTMLButtonElement>("[data-v2=help] > .el-page__back")!;
    expect(back).toHaveTextContent("About & help");
    fireEvent.click(back);
    for (let i = 0; i < 3; i++) await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "About & help" })).toBeInTheDocument();
  });

  it("the panel's search finds a setting and opens its group's page at it", async () => {
    await app();
    fireEvent.click(within(screen.getByRole("navigation", { name: "Main" })).getByRole("button", { name: /^Settings/ }));
    for (let i = 0; i < 3; i++) await act(flushAsync);
    fireEvent.change(panel().getByRole("searchbox", { name: "Search settings" }), { target: { value: "skip short calls" } });
    fireEvent.click(within(panel().getByRole("list", { name: "Matches" })).getByRole("button", { name: "Skip calls shorter than" }));
    for (let i = 0; i < 3; i++) await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Recording" })).toBeInTheDocument();
    await waitFor(() => expect(document.querySelector(".is-highlighted")?.id).toBe("setting-calls.skip_short"));
  });
});
