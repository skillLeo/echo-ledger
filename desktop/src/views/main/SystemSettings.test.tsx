import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { SettingsView } from "./SettingsView";
import { OnboardingView } from "./OnboardingView";
import MainApp from "./MainApp";
import { conflictFor, normaliseAccelerator } from "../../lib/shortcutConflicts";
import { callsOf, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeSettings, makeStatus } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

beforeEach(() => resetTauriMock());

describe("shortcut conflicts (B19)", () => {
  it("normalises accelerators per platform", () => {
    expect(normaliseAccelerator("CommandOrControl+Shift+c", "windows")).toBe("Ctrl+Shift+C");
    expect(normaliseAccelerator("shift+CommandOrControl+R", "macos")).toBe("Cmd+Shift+R");
    expect(normaliseAccelerator("Alt+F4", "windows")).toBe("Alt+F4");
  });

  it("knows the browser and system shortcuts a global one would take over", () => {
    expect(conflictFor("CommandOrControl+Shift+C", "windows")).toMatch(/DevTools/);
    expect(conflictFor("CommandOrControl+Shift+R", "macos")).toMatch(/hard-reloads/);
    // Record now's default since 2026-11-09: free on Windows, Safari's reload from the server on a Mac.
    expect(conflictFor("CommandOrControl+Alt+R", "windows")).toBeNull();
    expect(conflictFor("CommandOrControl+Alt+R", "macos")).toMatch(/Safari/);
    expect(conflictFor("Alt+F4", "windows")).toMatch(/closes the active window/);
    expect(conflictFor("Command+Space", "macos")).toMatch(/Spotlight/);
    expect(conflictFor("CommandOrControl+Alt+Shift+R", "windows")).toBeNull();
    expect(conflictFor("", "windows")).toBeNull();
  });
});

describe("system-style settings", () => {
  const run = async <T,>(fn: () => Promise<T>) => {
    try {
      return await fn();
    } catch {
      return undefined;
    }
  };
  async function setup(settings = makeSettings()) {
    respond("get_settings", settings);
    respond("list_clients", { data: [] });
    respond("input_devices", []);
    respond("permissions_status", { microphone: "allowed", browserAutomation: false, platform: "windows" });
    respond("lock_status", { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 });
    respond("security_overview", { devices: [], events: [] });
    respond("storage_info", { recordings_bytes: 0, recordings_count: 0, disk_free_bytes: null, disk_total_bytes: null, retention_days: 30 });
    render(<SettingsView status={makeStatus()} run={run} onUnpaired={vi.fn()} />);
    await screen.findByRole("navigation", { name: "Settings groups" });
  }
  const groupTitles = () => [...document.querySelectorAll(".settings-group-title")].map((h) => h.textContent);

  it("shows every tab under its heading, then one tab at a time with its name as the title (v1.6 Account tabs)", async () => {
    await setup();
    // A server without accounts: no Profile, Plan or Privacy tabs; the rest are there.
    expect(groupTitles()).toEqual(["Devices", "Notifications", "Preferences", "Calls & meetings", "Voice notes & dictation", "This computer"]);
    fireEvent.click(screen.getByRole("button", { name: "Calls & meetings" }));
    expect(groupTitles()).toEqual([]);
    expect(screen.getByRole("heading", { level: 1, name: "Calls & meetings" })).toBeInTheDocument();
    expect(within(screen.getByRole("navigation", { name: "Breadcrumbs" })).getByRole("button", { name: "Account" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Calls & meetings" })).toHaveAttribute("aria-current", "page");
    expect(screen.getByRole("switch", { name: "Auto-record calls" })).toBeInTheDocument();
    expect(screen.getByRole("combobox", { name: "Caption position" })).toBeInTheDocument();
    expect(screen.queryByRole("switch", { name: "Start EchoLedger when I sign in" })).not.toBeInTheDocument();
  });

  it("changes the theme from Preferences, and says it saved", async () => {
    window.localStorage.clear();
    await setup();
    fireEvent.click(screen.getByRole("button", { name: "Preferences" }));
    const theme = screen.getByRole("group", { name: "Theme" });
    fireEvent.click(within(theme).getByRole("button", { name: "Dark" }));
    expect(within(theme).getByRole("button", { name: "Dark" })).toHaveAttribute("aria-pressed", "true");
    expect(document.documentElement.dataset.theme).toBe("dark");
    expect(window.localStorage.getItem("echoledger-theme")).toBe("dark");
    expect(screen.getByTestId("saved")).toHaveTextContent("Saved · just now");
  });

  it("keeps the sidebar style, density and clock on this computer (Preferences)", async () => {
    window.localStorage.clear();
    await setup();
    fireEvent.click(screen.getByRole("button", { name: "Preferences" }));
    fireEvent.click(within(screen.getByRole("group", { name: "Sidebar style" })).getByRole("button", { name: "Soft grey" }));
    fireEvent.click(within(screen.getByRole("group", { name: "Density" })).getByRole("button", { name: "Compact" }));
    fireEvent.change(screen.getByRole("combobox", { name: "Times" }), { target: { value: "24" } });
    expect(document.documentElement.dataset.sidebar).toBe("grey");
    expect(document.documentElement.dataset.density).toBe("compact");
    expect(JSON.parse(window.localStorage.getItem("echoledger-preferences") ?? "{}")).toEqual({ sidebarStyle: "grey", density: "compact", clock: "24" });
  });

  it("lists the keys of the app itself under Preferences", async () => {
    await setup();
    fireEvent.click(screen.getByRole("button", { name: "Preferences" }));
    const keys = screen.getByRole("region", { name: "In the app" });
    for (const what of ["Search recordings, pages and commands", "Start or stop a voice note", "Play or pause the open recording", "Back or forward 10 seconds (← / → 5 seconds)", "Collapse or expand the sidebar"]) {
      expect(within(keys).getByText(what)).toBeInTheDocument();
    }
  });

  it("finds settings by words, across groups", async () => {
    await setup();
    fireEvent.change(screen.getByRole("textbox", { name: "Search settings" }), { target: { value: "quiet" } });
    expect(screen.getByRole("region", { name: "Notifications" })).toBeInTheDocument();
    expect(groupTitles()).toEqual(["Notifications"]);
    fireEvent.change(screen.getByRole("textbox", { name: "Search settings" }), { target: { value: "zzzz" } });
    expect(screen.getByText("No setting matches “zzzz”.")).toBeInTheDocument();
  });

  it("warns when a shortcut takes over a browser shortcut", async () => {
    await setup(makeSettings({ shortcuts: { toggle_saving: "CommandOrControl+Shift+R", copy_last2_min: "CommandOrControl+Alt+Shift+C" } as never }));
    fireEvent.click(screen.getByRole("button", { name: "Preferences" }));
    expect(screen.getByText(/This shortcut also hard-reloads the page in browsers/)).toBeInTheDocument();
    expect(screen.getAllByText(/This shortcut also/)).toHaveLength(1);
  });

  it("exports and imports settings", async () => {
    respond("settings_export", "C:\\Users\\me\\Downloads\\EchoLedger settings 2026-10-28.json");
    respondWith("settings_import", ({ text }) => ({ ...makeSettings(), captionLines: JSON.parse(text as string).settings.captionLines }));
    await setup();
    fireEvent.click(screen.getByRole("button", { name: "This computer" }));
    expect(screen.getByRole("region", { name: "About" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Export settings" }));
    await waitFor(() => expect(callsOf("settings_export")).toHaveLength(1));
    const file = new File([JSON.stringify({ format: "echoledger-settings", version: 1, settings: { captionLines: 2 } })], "s.json", { type: "application/json" });
    fireEvent.change(screen.getByLabelText("Import settings"), { target: { files: [file] } });
    await waitFor(() => expect(callsOf("settings_import")).toHaveLength(1));
    expect(JSON.parse(callsOf("settings_import")[0].text as string).settings.captionLines).toBe(2);
    expect(screen.getByText("The file has your preferences only, never the sign-in, keys or PIN.")).toBeInTheDocument();
  });
});

describe("first-run wizard (B19)", () => {
  beforeEach(() => {
    respond("permissions_status", { microphone: "denied", browserAutomation: false, platform: "windows" });
    respond("update_settings", makeSettings());
  });

  it("walks through permissions, signing in, a test and consent", async () => {
    respond("test_capture", { seconds: 10, micPeak: 0.4, systemPeak: 0, micHeard: true, systemHeard: false, wavBase64: "" });
    respond("open_privacy_settings", undefined);
    const onFinished = vi.fn();
    render(<OnboardingView status={makeStatus()} onStatus={vi.fn()} onFinished={onFinished} notify={vi.fn()} />);
    const steps = screen.getByRole("list", { name: "Setup steps" });
    expect(within(steps).getByText("Permissions")).toHaveAttribute("aria-current", "step");
    expect(screen.getByTestId("consent-notice")).toHaveTextContent("Tell participants you are recording where required.");
    fireEvent.click(await screen.findByRole("button", { name: "Open settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "microphone" }]);
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));
    expect(screen.getByText(/Signed in to/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));
    fireEvent.click(screen.getByRole("button", { name: "Start the test" }));
    expect(await screen.findByText("Your microphone is working.")).toBeInTheDocument();
    expect(callsOf("test_capture")).toEqual([{ seconds: 10 }]);
    fireEvent.click(screen.getByRole("button", { name: "Continue" }));
    expect(screen.getByTestId("consent-notice")).toHaveTextContent("Recording laws differ by country. Tell the people in your meeting that you are recording where the law requires it.");
    fireEvent.click(screen.getByRole("checkbox", { name: "I understand and will get consent where required" }));
    fireEvent.click(screen.getByRole("button", { name: "Start using EchoLedger" }));
    await waitFor(() => expect(onFinished).toHaveBeenCalled());
    expect(callsOf("update_settings")).toEqual([{ patch: { onboardingDone: true, consentAcknowledged: true } }]);
  });

  it("can be skipped from any step", async () => {
    const onFinished = vi.fn();
    render(<OnboardingView status={makeStatus()} onStatus={vi.fn()} onFinished={onFinished} notify={vi.fn()} />);
    fireEvent.click(screen.getByRole("button", { name: "Skip setup" }));
    await waitFor(() => expect(onFinished).toHaveBeenCalled());
    expect(callsOf("update_settings")).toEqual([{ patch: { onboardingDone: true } }]);
  });

  it("is what a first start shows", async () => {
    respond("app_status", makeStatus({ paired: false }));
    respond("get_settings", makeSettings({ onboardingDone: false }));
    respond("lock_status", { enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 });
    render(<MainApp />);
    expect(await screen.findByRole("heading", { name: "Welcome to EchoLedger" })).toBeInTheDocument();
  });
});
