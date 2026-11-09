// Settings → Screen moments (since v1.5): on or off, the meeting window or the whole screen, the excluded apps, and the
// notes about the Windows border, the macOS permission, the plan and an older server.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { SettingsView } from "./SettingsView";
import { comparableApp, screensBlocked } from "./ScreenSettings";
import { errorMessage, type ScreensStatus, type Settings } from "../../lib/ipc";
import { callsOf, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeSettings, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

function status(overrides: Partial<ScreensStatus> = {}): ScreensStatus {
  return { supported: true, platform: "windows", borderless: true, permission: true, inPlan: true, onServer: true, ...overrides };
}

async function setup(settings: Partial<Settings> = {}, screens: ScreensStatus = status()) {
  let current = makeSettings(settings);
  respond("get_settings", current);
  respond("list_clients", { data: [] });
  respond("screens_status", screens);
  respondWith("update_settings", ({ patch }) => {
    current = { ...current, ...(patch as Partial<Settings>) };
    return current;
  });
  const errors: string[] = [];
  const run = (async (fn: () => Promise<unknown>) => {
    try {
      return await fn();
    } catch (e) {
      errors.push(errorMessage(e));
      return undefined;
    }
  }) as never;
  render(<SettingsView status={makeStatus({ platform: screens.platform === "macos" ? "macos" : "windows" })} run={run} onUnpaired={vi.fn()} />);
  await screen.findByRole("heading", { name: "Account", level: 1 });
  await flushAsync();
  return { errors, card: () => screen.getByTestId("screen-moments-card") };
}

beforeEach(() => resetTauriMock());

describe("Settings → Screen moments", () => {
  it("is under Calls & meetings and found by searching", async () => {
    await setup();
    fireEvent.change(screen.getByRole("textbox", { name: "Search settings" }), { target: { value: "password managers" } });
    expect(screen.getAllByRole("region").map((r) => r.getAttribute("aria-label"))).toEqual(["Screen moments"]);
    expect(screen.getByRole("heading", { name: "Calls & meetings" })).toBeInTheDocument();
  });

  it("switches screen moments off and on, and chooses the meeting window or the whole screen", async () => {
    const { card } = await setup();
    const keep = within(card()).getByRole("switch", { name: "Keep screen moments" });
    expect(keep).toHaveAttribute("aria-checked", "true");
    const capture = within(card()).getByRole("combobox", { name: "Capture" });
    expect(capture).toHaveValue("meeting_window");
    expect(within(card()).getByText("Only the meeting app's window: nothing else on your screen is kept.")).toBeInTheDocument();
    fireEvent.change(capture, { target: { value: "whole_screen" } });
    await flushAsync();
    expect(within(card()).getByText("The screen the meeting is on, without EchoLedger's own windows. Screens pause while an excluded app shows on it. Meanwhile Windows hides EchoLedger's window from screen sharing too.")).toBeInTheDocument();
    fireEvent.click(keep);
    await flushAsync();
    expect(callsOf("update_settings")).toEqual([{ patch: { screenTarget: "whole_screen" } }, { patch: { screenMoments: false } }]);
    expect(within(card()).queryByRole("combobox", { name: "Capture" })).not.toBeInTheDocument();
  });

  it("adds and removes excluded apps; a name already listed is not added twice", async () => {
    const { card } = await setup({ screenExcludedApps: ["1Password", "Bitwarden"] });
    const list = within(card()).getByRole("list", { name: "Never capture these apps" });
    expect(within(list).getAllByRole("listitem").map((i) => i.textContent)).toEqual(["1Password", "Bitwarden"]);
    const field = within(card()).getByRole("textbox", { name: "App to exclude" });
    fireEvent.change(field, { target: { value: " Signal.exe " } });
    fireEvent.keyDown(field, { key: "Enter" });
    await flushAsync();
    expect(callsOf("update_settings").at(-1)).toEqual({ patch: { screenExcludedApps: ["1Password", "Bitwarden", "Signal.exe"] } });
    expect(field).toHaveValue("");
    fireEvent.change(field, { target: { value: "bit warden" } });
    fireEvent.click(within(card()).getByRole("button", { name: "Exclude" }));
    await flushAsync();
    expect(callsOf("update_settings")).toHaveLength(1);
    fireEvent.click(within(card()).getByRole("button", { name: "Remove 1Password" }));
    await flushAsync();
    expect(callsOf("update_settings").at(-1)).toEqual({ patch: { screenExcludedApps: ["Bitwarden", "Signal.exe"] } });
  });

  it("says Windows 10 draws a yellow border, and Windows 11 points to its setting", async () => {
    const { card } = await setup({}, status({ borderless: false }));
    expect(within(card()).getByTestId("screens-border-note")).toHaveTextContent("Windows 10 draws a yellow border around the meeting window while screens are on.");
    expect(within(card()).queryByRole("button", { name: "Windows Settings" })).not.toBeInTheDocument();
  });

  it("opens the Windows setting for borderless capture on Windows 11", async () => {
    respond("open_privacy_settings", undefined);
    const { card } = await setup();
    expect(within(card()).getByTestId("screens-border-note")).toHaveTextContent("Windows 11 captures without the yellow border.");
    fireEvent.click(within(card()).getByRole("button", { name: "Windows Settings" }));
    await flushAsync();
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "screen" }]);
  });

  it("says only on Windows that EchoLedger is hidden from screen sharing while the whole screen is kept", async () => {
    const { card } = await setup({ screenTarget: "whole_screen" }, status({ platform: "macos" }));
    expect(within(card()).getByText("The screen the meeting is on, without EchoLedger's own windows. Screens pause while an excluded app shows on it.")).toBeInTheDocument();
  });

  it("asks macOS for Screen Recording and says it asks again about once a month", async () => {
    respond("screens_permission", false);
    const { card } = await setup({}, status({ platform: "macos", permission: false }));
    expect(within(card()).queryByTestId("screens-border-note")).not.toBeInTheDocument();
    expect(within(card()).getByTestId("screens-permission-note")).toHaveTextContent("From macOS 15, macOS asks again about once a month");
    fireEvent.click(within(card()).getByRole("button", { name: "Allow Screen Recording" }));
    await flushAsync();
    expect(callsOf("screens_permission")).toHaveLength(1);
    expect(within(card()).getByTestId("screens-permission-note")).toHaveTextContent("System Settings → Privacy & Security → Screen & System Audio Recording");
    expect(within(card()).getByRole("button", { name: "Open System Settings" })).toBeInTheDocument();
    respond("screens_permission", true);
    fireEvent.click(within(card()).getByRole("button", { name: "Allow Screen Recording" }));
    await flushAsync();
    expect(within(card()).getByTestId("screens-permission-note")).toHaveTextContent("From macOS 15, macOS asks again about once a month");
    expect(within(card()).queryByRole("button", { name: "Allow Screen Recording" })).not.toBeInTheDocument();
  });

  it("says when the plan, the server or this computer leaves screen moments out", async () => {
    const { card } = await setup({}, status({ inPlan: false }));
    expect(within(card()).getByRole("status")).toHaveTextContent("Screen moments are not in your workspace's plan, so nothing is captured or kept.");
    // The capture's controls are hidden: there is nothing to switch on.
    expect(within(card()).queryByRole("switch", { name: "Keep screen moments" })).not.toBeInTheDocument();
    expect(within(card()).queryByText("Never capture these apps")).not.toBeInTheDocument();
    expect(screensBlocked(status({ onServer: false }))).toMatch(/does not have screen moments yet/);
    expect(screensBlocked(status({ supported: false }))).toMatch(/cannot capture the screen/);
    expect(screensBlocked(status())).toBeNull();
  });

  it("compares app names the way the core does", () => {
    expect(comparableApp(" Proton Pass ")).toBe(comparableApp("ProtonPass.exe"));
    expect(comparableApp("Keeper_Password-Manager")).toBe("keeperpasswordmanager");
  });
});
