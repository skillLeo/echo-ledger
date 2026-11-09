// Settings › Recording › Screen moments (v1.5, rows of v1.7): on or off, the meeting window or the whole screen, the
// apps never captured, and the notes about the Windows border, the macOS permission, the plan and an older server.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, renderHook, screen, within } from "@testing-library/react";
import { useState } from "react";
import { ExcludedAppsPage, ScreenMomentsPage, comparableApp, screensBlocked } from "./ScreenSettings";
import type { ScreensStatus, Settings, SettingsPatch } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeSettings, sampleEntitlements } from "../../test/fixtures";
import { closeUpgradeSheet, resetEntitlementsForTests, useUpgradeSheet } from "../../lib/entitlements";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

function status(overrides: Partial<ScreensStatus> = {}): ScreensStatus {
  return { supported: true, platform: "windows", borderless: true, permission: true, inPlan: true, onServer: true, ...overrides };
}

const patches: SettingsPatch[] = [];
function Harness({ initial, page }: { initial: Settings; page: "screen" | "excluded" }) {
  const [settings, setSettings] = useState(initial);
  const patch = async (p: SettingsPatch) => {
    patches.push(p);
    setSettings((s) => ({ ...s, ...(p as Partial<Settings>) }));
    return true;
  };
  return page === "screen" ? <ScreenMomentsPage settings={settings} patch={patch} onExcluded={vi.fn()} /> : <ExcludedAppsPage settings={settings} patch={patch} platform="windows" />;
}

async function setup(settings: Partial<Settings> = {}, screens: ScreensStatus = status(), page: "screen" | "excluded" = "screen") {
  respond("screens_status", screens);
  render(<Harness initial={makeSettings(settings)} page={page} />);
  await flushAsync();
}
const foot = () => document.querySelector(".set-foot") as HTMLElement;

beforeEach(() => {
  resetTauriMock();
  patches.length = 0;
});

describe("Settings › Screen moments", () => {
  it("switches screen moments off and on, and chooses the meeting window or the whole screen", async () => {
    await setup();
    const keep = screen.getByRole("switch", { name: "Keep screen moments" });
    expect(keep).toHaveAttribute("aria-checked", "true");
    expect(screen.getByLabelText("Capture")).toHaveValue("meeting_window");
    expect(foot()).toHaveTextContent("Only the meeting app's window: nothing else on your screen is kept.");
    fireEvent.change(screen.getByLabelText("Capture"), { target: { value: "whole_screen" } });
    await flushAsync();
    expect(foot()).toHaveTextContent("Meanwhile Windows hides EchoLedger's window from screen sharing too.");
    fireEvent.click(keep);
    await flushAsync();
    expect(patches).toEqual([{ screenTarget: "whole_screen" }, { screenMoments: false }]);
    expect(screen.queryByLabelText("Capture")).not.toBeInTheDocument();
  });

  it("counts the apps never captured", async () => {
    await setup({ screenExcludedApps: ["1Password", "Bitwarden"] });
    expect(screen.getByRole("button", { name: /^Never capture these apps/ })).toHaveTextContent("2 apps");
  });

  it("adds and removes excluded apps; a name already listed is not added twice", async () => {
    await setup({ screenExcludedApps: ["1Password", "Bitwarden"] }, status(), "excluded");
    const list = screen.getByRole("list", { name: "Never capture these apps" });
    expect(within(list).getAllByRole("listitem").map((i) => i.querySelector(".set-title")?.textContent)).toEqual(["1Password", "Bitwarden"]);
    const field = screen.getByRole("textbox", { name: "App or process name" });
    fireEvent.change(field, { target: { value: " Signal.exe " } });
    fireEvent.click(screen.getByRole("button", { name: "Exclude" }));
    await flushAsync();
    expect(patches.at(-1)).toEqual({ screenExcludedApps: ["1Password", "Bitwarden", "Signal.exe"] });
    expect(field).toHaveValue("");
    fireEvent.change(field, { target: { value: "bit warden" } });
    fireEvent.click(screen.getByRole("button", { name: "Exclude" }));
    await flushAsync();
    expect(patches).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "Remove 1Password" }));
    await flushAsync();
    expect(patches.at(-1)).toEqual({ screenExcludedApps: ["Bitwarden", "Signal.exe"] });
  });

  it("says Windows 10 draws a yellow border, and Windows 11 points to its setting", async () => {
    await setup({}, status({ borderless: false }));
    expect(foot()).toHaveTextContent("Windows 10 draws a yellow border around the meeting window while screens are on.");
    expect(screen.queryByRole("button", { name: "Windows Settings" })).not.toBeInTheDocument();
  });

  it("opens the Windows setting for borderless capture on Windows 11", async () => {
    respond("open_privacy_settings", undefined);
    await setup();
    expect(foot()).toHaveTextContent("Windows 11 captures without the yellow border.");
    fireEvent.click(screen.getByRole("button", { name: "Windows Settings" }));
    await flushAsync();
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
  });

  it("asks macOS for Screen Recording and says it asks again about once a month", async () => {
    respond("screens_permission", false);
    await setup({}, status({ platform: "macos", permission: false }));
    expect(document.getElementById("setting-screen.permission")).toHaveTextContent("Not allowed");
    expect(foot()).toHaveTextContent("From macOS 15, macOS asks again about once a month");
    fireEvent.click(screen.getByRole("button", { name: "Allow Screen Recording" }));
    await flushAsync();
    expect(callsOf("screens_permission")).toHaveLength(1);
    expect(foot()).toHaveTextContent("System Settings › Privacy & Security › Screen & System Audio Recording");
    expect(screen.getByRole("button", { name: "Open System Settings" })).toBeInTheDocument();
    respond("screens_permission", true);
    fireEvent.click(screen.getByRole("button", { name: "Allow Screen Recording" }));
    await flushAsync();
    expect(document.getElementById("setting-screen.permission")).toHaveTextContent("Allowed");
  });

  it("says when the plan, the server or this computer leaves screen moments out", async () => {
    await setup({}, status({ inPlan: false }));
    expect(screen.getByTestId("screens-blocked")).toHaveTextContent("Screen moments aren't in your workspace's plan, so nothing is captured or kept.");
    expect(screen.getByRole("switch", { name: "Keep screen moments" })).toBeDisabled();
    expect(screen.queryByText("Never capture these apps")).not.toBeInTheDocument();
    expect(screensBlocked(status({ onServer: false }))).toMatch(/doesn't have screen moments yet/);
    expect(screensBlocked(status({ supported: false }))).toMatch(/can't capture the screen/);
    expect(screensBlocked(status())).toBeNull();
  });

  it("shows the plan's tag instead of the switch when the plan leaves screen moments out; a click opens the upgrade flow and changes nothing", async () => {
    resetEntitlementsForTests(sampleEntitlements("free"));
    const sheet = renderHook(() => useUpgradeSheet());
    try {
      await setup({ screenMoments: true });
      const row = screen.getByRole("button", { name: "Keep screen moments, in Pro" });
      expect(row).toHaveTextContent("Keep screen momentsPro");
      expect(screen.queryByRole("switch", { name: "Keep screen moments" })).not.toBeInTheDocument();
      fireEvent.click(row);
      expect(sheet.result.current?.feature).toBe("screen_moments");
      expect(patches).toEqual([]);
    } finally {
      act(() => {
        resetEntitlementsForTests();
        closeUpgradeSheet();
      });
      sheet.unmount();
    }
  });

  it("compares app names the way the core does", () => {
    expect(comparableApp(" Proton Pass ")).toBe(comparableApp("ProtonPass.exe"));
    expect(comparableApp("Keeper_Password-Manager")).toBe("keeperpasswordmanager");
  });
});
