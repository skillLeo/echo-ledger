import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import type { CheckReport, PermissionsStatus, ReadyReport } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { SetupHealth, currentStep, permissionKinds } from "./SetupHealth";
import MainApp from "../../views/main/MainApp";
import { makeItem, makeSettings, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const MAC_PERMS: PermissionsStatus = { microphone: "allowed", systemAudio: "unknown", screen: "denied", browserAutomation: true, platform: "macos" };
const READY: ReadyReport = {
  ready: false,
  checkedAt: "2026-11-17T10:00:00Z",
  checks: [
    { id: "account", label: "Signed in", ok: true, detail: null, fix: null },
    { id: "other_side", label: "The other side of calls", ok: false, detail: "System audio is off, so a recording has only your voice.", fix: { label: "Allow system audio", action: "allow_system_audio" } },
  ],
};
const MAC: CheckReport = {
  items: [
    { key: "mic", title: "Microphone", state: "ok", detail: "MacBook Pro Microphone works.", fix: null },
    { key: "screen", title: "Screen recording", state: "off", detail: "Screen moments are paused.", fix: { label: "Open Screen Recording", settings: "screen" } },
  ],
  stopped: null, finishedAt: "2026-11-17T09:00:00Z", appVersion: "2.0.0", osVersion: "macOS 15.2", model: "MacBook Pro", sentAt: null,
};

beforeEach(() => {
  resetTauriMock();
  respond("permissions_status", MAC_PERMS);
  respond("ready_last", READY);
  respond("mac_check_last", MAC);
  respond("open_privacy_settings", null);
});

const show = async (platform: PermissionsStatus["platform"] = "macos") => {
  render(<SetupHealth platform={platform} />);
  await act(flushAsync);
};

describe("the permissions wizard", () => {
  it("asks for three permissions on a Mac and the microphone on Windows; the current step is the first not allowed", () => {
    expect(permissionKinds("macos")).toEqual(["microphone", "system_audio", "screen"]);
    expect(permissionKinds("windows")).toEqual(["microphone"]);
    expect(currentStep(MAC_PERMS, permissionKinds("macos"))).toBe("system_audio");
    expect(currentStep({ ...MAC_PERMS, systemAudio: "allowed", screen: "allowed" }, permissionKinds("macos"))).toBeNull();
  });

  it("shows the steps in order, done ones checked, and the current one with what it is for and Allow", async () => {
    respond("permission_request", { ...MAC_PERMS, systemAudio: "allowed" });
    await show();
    expect(screen.getByRole("status")).toHaveTextContent("1 thing to fix before calls are recorded");
    const steps = within(screen.getByRole("list", { name: "Permissions" })).getAllByRole("listitem");
    expect(steps).toHaveLength(3);
    expect(steps[0]).toHaveTextContent("MicrophoneAllowed");
    expect(steps[1]).toHaveAttribute("aria-current", "step");
    expect(within(steps[1]).getByText(/record the other side of a call/)).toBeInTheDocument();
    fireEvent.click(within(steps[1]).getByRole("button", { name: "Allow" }));
    await act(flushAsync);
    expect(callsOf("permission_request")).toEqual([{ kind: "system_audio" }]);
    const after = within(screen.getByRole("list", { name: "Permissions" })).getAllByRole("listitem");
    expect(after[2]).toHaveAttribute("aria-current", "step");
    fireEvent.click(within(after[2]).getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
  });

  it("a step that is not current opens on a press", async () => {
    await show();
    const steps = within(screen.getByRole("list", { name: "Permissions" })).getAllByRole("listitem");
    const head = within(steps[2]).getByRole("button", { expanded: false });
    fireEvent.click(head);
    expect(within(steps[2]).getByText(/Only for screen moments/)).toBeInTheDocument();
  });
});

describe("the health checks", () => {
  it("lists the Ready check with words for each state and runs a fix", async () => {
    respond("ready_fix", { ...READY, ready: true, checks: READY.checks.map((c) => ({ ...c, ok: true, fix: null })) });
    await show();
    const ready = screen.getByRole("list", { name: "Ready check" });
    expect(within(ready).getAllByRole("listitem")[1]).toHaveAccessibleDescription(/Needs a fix System audio is off/);
    fireEvent.click(within(ready).getByRole("button", { name: "Allow system audio" }));
    await act(flushAsync);
    expect(callsOf("ready_fix")).toEqual([{ action: "allow_system_audio" }]);
    expect(screen.getByRole("status")).toHaveTextContent(/^Ready to record calls$/);
    expect(within(screen.getByRole("list", { name: "Ready check" })).queryByRole("button")).toBeNull();
  });

  it("shows the last Mac check with its fixes, and runs it again", async () => {
    respond("mac_check_run", { ...MAC, items: MAC.items.map((i) => ({ ...i, state: "ok" as const, fix: null })) });
    await show();
    const mac = screen.getByRole("list", { name: "Mac check" });
    expect(within(mac).getAllByRole("listitem")[1]).toHaveAccessibleDescription(/^Off Screen moments are paused\.$/);
    fireEvent.click(within(mac).getByRole("button", { name: "Open Screen Recording" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
    fireEvent.click(screen.getByRole("button", { name: "Run again" }));
    await act(flushAsync);
    expect(callsOf("mac_check_run")).toHaveLength(1);
    expect(within(screen.getByRole("list", { name: "Mac check" })).queryByRole("button")).toBeNull();
  });

  it("without a Mac check yet it offers to run one; Windows has none", async () => {
    respond("mac_check_last", null);
    await show();
    expect(screen.getByRole("region", { name: "Run the Mac check" })).toBeInTheDocument();
    resetTauriMock();
    respond("permissions_status", { ...MAC_PERMS, platform: "windows", microphone: "denied" });
    respond("ready_last", READY);
    document.body.innerHTML = "";
    await show("windows");
    expect(screen.queryByRole("region", { name: "Mac check" })).toBeNull();
    expect(callsOf("mac_check_last")).toHaveLength(0);
    expect(within(screen.getByRole("list", { name: "Permissions" })).getAllByRole("listitem")).toHaveLength(1);
  });
});

describe("the app with ui_v2.setup_health", () => {
  it("Settings › Setup & health opens the new page, and its back link returns to Settings", async () => {
    respond("app_status", makeStatus({ platform: "macos" }));
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("get_settings", makeSettings());
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("account_get", null);
    respond("storage_info", null);
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.settings": true, "ui_v2.setup_health": true } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "Settings" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "Setup & health" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Setup & health" })).toBeInTheDocument();
    expect(screen.getByRole("list", { name: "Permissions" })).toBeInTheDocument();
    fireEvent.click(document.querySelector("[data-v2=setup-health] .el-page__back")!);
    expect(await screen.findByRole("heading", { level: 1, name: "Settings" })).toBeInTheDocument();
  });
});
