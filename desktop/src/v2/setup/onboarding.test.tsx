// The first-run wizard in the new design (docs/v2/audit/parity.csv DT-HLP-008) against classic's: the same steps (a
// Mac's welcome and one step per permission, a PC's one page), sign in, the 10-second test, the consent tick and the
// share check, Skip setup on every step; the commands and what is saved are classic's.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp, { WIZARD_FLAGS_WAIT_MS } from "../../views/main/MainApp";
import type { PermissionsStatus } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond, respondWith } from "../../test/tauri";
import { makeEntitlements, makeItem, makeSettings, makeStatus } from "../../test/fixtures";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { OnboardingV2 } from "./OnboardingV2";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const perms = (over: Partial<PermissionsStatus> = {}): PermissionsStatus =>
  ({ microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: false, platform: "macos", ...over } as PermissionsStatus);
const next = () => fireEvent.click(screen.getByRole("button", { name: "Continue" }));
const heading = () => screen.getByRole("heading", { level: 1 }).textContent;
const steps = () => within(screen.getByRole("list", { name: "Setup steps" }));

beforeEach(() => {
  resetTauriMock();
  resetEntitlementsForTests(makeEntitlements());
  respond("update_settings", makeSettings({ onboardingDone: true }));
  respond("privacy_status", null);
});

describe("the first-run wizard in the new design", () => {
  const props = () => ({ onStatus: vi.fn(), onFinished: vi.fn(), notify: vi.fn() });

  it("DT-HLP-008: on a Mac: welcome with the consent notice, then microphone, system audio and screen recording, each with its own buttons", async () => {
    respond("permissions_status", perms({ microphone: "unknown", systemAudio: "denied", screen: "denied" }));
    respond("permission_request", perms({ systemAudio: "denied", screen: "denied" }));
    render(<div className="el-root"><OnboardingV2 status={makeStatus({ platform: "macos", paired: true })} {...props()} /></div>);
    await act(flushAsync);
    expect(steps().getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Welcome", "Microphone", "System audio", "Screen recording", "Sign in", "Test", "Done"]);
    expect(steps().getByText("Welcome").closest("li")).toHaveAttribute("aria-current", "step");
    expect(heading()).toBe("Welcome to EchoLedger");
    expect(screen.getByTestId("consent-notice")).toHaveTextContent("Recording laws differ by country.");
    next();
    expect(heading()).toBe("Microphone");
    // The step before is marked done, for screen readers too.
    expect(steps().getAllByRole("listitem")[0]).toHaveTextContent("Welcome (done)");
    expect(screen.getByRole("heading", { level: 1 })).toHaveFocus();
    fireEvent.click(screen.getByRole("button", { name: "Allow" }));
    await act(flushAsync);
    expect(callsOf("permission_request")).toEqual([{ kind: "microphone" }]);
    next();
    expect(heading()).toBe("System audio");
    fireEvent.click(screen.getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "system_audio" }]);
    next();
    expect(heading()).toBe("Screen recording");
    next();
    expect(heading()).toBe("Connect to your server");
    expect(screen.getByText(/^Signed in to/)).toBeInTheDocument();
  });

  it("DT-HLP-008: on a PC one page for permissions; the test records 10 seconds and says what it heard; Done saves the consent tick", async () => {
    respond("permissions_status", perms({ platform: "windows", microphone: "denied" }));
    respond("test_capture", { seconds: 10, micPeak: 0.4, systemPeak: 0, micHeard: true, systemHeard: false, wavBase64: "" });
    const p = props();
    render(<div className="el-root"><OnboardingV2 status={makeStatus({ platform: "windows", paired: true })} {...p} /></div>);
    await act(flushAsync);
    expect(steps().getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Permissions", "Sign in", "Test", "Done"]);
    expect(screen.getByTestId("microphone-line")).toHaveTextContent("Blocked");
    fireEvent.click(within(screen.getByTestId("microphone-line")).getByRole("button", { name: "Open settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "microphone" }]);
    next();
    next();
    expect(heading()).toBe("Try a 10-second test");
    expect(screen.getByRole("button", { name: "Skip the test" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Start the test" }));
    await act(flushAsync);
    expect(callsOf("test_capture")).toEqual([{ seconds: 10 }]);
    expect(within(screen.getByRole("list", { name: "Test result" })).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Your microphone is working.", "No sound from this computer was captured. Play something during the test to check the other side of calls.",
    ]);
    next();
    expect(heading()).toBe("You are set");
    fireEvent.click(screen.getByRole("checkbox", { name: "I understand and will get consent where required" }));
    // FIX-11: asked at the end, recommended (ticked); the menu bar's icon is a Mac's only.
    const atLogin = screen.getByRole("checkbox", { name: "Open EchoLedger at login (recommended, so calls are recorded automatically)" });
    expect(atLogin).toBeChecked();
    expect(screen.queryByRole("checkbox", { name: "Show EchoLedger in the menu bar (recommended)" })).toBeNull();
    fireEvent.click(atLogin);
    fireEvent.click(screen.getByRole("button", { name: "Start using EchoLedger" }));
    await act(flushAsync);
    expect(callsOf("update_settings")).toEqual([{ patch: { onboardingDone: true, consentAcknowledged: true, autostart: false } }]);
    expect(p.onFinished).toHaveBeenCalledTimes(1);
  });

  it("DT-HLP-008: Skip setup on any step saves only that it is done; a refusal is said and the wizard stays", async () => {
    respond("permissions_status", perms());
    const p = props();
    const { unmount } = render(<div className="el-root"><OnboardingV2 status={makeStatus({ platform: "macos", paired: true })} {...p} /></div>);
    await act(flushAsync);
    next();
    fireEvent.click(screen.getByRole("button", { name: "Skip setup" }));
    await act(flushAsync);
    expect(callsOf("update_settings")).toEqual([{ patch: { onboardingDone: true } }]);
    expect(p.onFinished).toHaveBeenCalledTimes(1);
    unmount();
    fail("update_settings", "The settings couldn't be saved.");
    const q = props();
    render(<div className="el-root"><OnboardingV2 status={makeStatus({ platform: "macos", paired: true })} {...q} /></div>);
    await act(flushAsync);
    fireEvent.click(screen.getByRole("button", { name: "Skip setup" }));
    await act(flushAsync);
    expect(q.notify).toHaveBeenCalledWith("The settings couldn't be saved.", true);
    expect(q.onFinished).not.toHaveBeenCalled();
    expect(heading()).toBe("Welcome to EchoLedger");
  });

  it("DT-HLP-008: the share check runs from the last step and says what it found", async () => {
    respond("permissions_status", perms({ platform: "windows" }));
    respond("share_check_run", { result: "pass", reason: null, methods: [], system: "Windows 11", at: new Date().toISOString(), picture: null, width: 0, height: 0, testArea: null, controlArea: null });
    render(<div className="el-root"><OnboardingV2 status={makeStatus({ platform: "windows", paired: true })} {...props()} /></div>);
    await act(flushAsync);
    next();
    next();
    fireEvent.click(screen.getByRole("button", { name: "Skip the test" }));
    const check = within(screen.getByRole("region", { name: "Screen-share privacy" }));
    expect(check.getByRole("heading", { name: "Screen-share privacy" })).toBeInTheDocument();
    fireEvent.click(check.getByRole("button", { name: "Check screen-share privacy" }));
    await act(flushAsync);
    expect(callsOf("share_check_run")).toEqual([{ why: "onboarding" }]);
    expect(check.getByRole("status")).toHaveTextContent(/Hidden|Private/);
    expect(check.getByRole("button", { name: "Check again" })).toBeInTheDocument();
  });
});

describe("in the app", () => {
  function app(flags: Record<string, boolean> | null) {
    respond("app_status", makeStatus({ platform: "windows" }));
    respond("get_settings", makeSettings({ onboardingDone: false }));
    respond("permissions_status", perms({ platform: "windows" }));
    respond("library_list", [makeItem("a")]);
    respond("library_refresh", [makeItem("a")]);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    if (flags) respond("ui_flags", { ui_flags: flags, ui_classic: false });
  }

  it("DT-HLP-008: with the new design on, the first start shows the new wizard (never classic's), and finishing opens the app", async () => {
    app({ "ui_v2.shell": true });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { level: 1, name: "Welcome to EchoLedger" })).toBeInTheDocument();
    expect(document.querySelector('[data-v2="onboarding"]')).not.toBeNull();
    expect(document.querySelector(".onboarding-card")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Skip setup" }));
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("navigation", { name: "Main" })).toBeInTheDocument();
  });

  it("DT-HLP-008: flags that never come don't leave the window blank: after a moment the wizard is classic's", async () => {
    app(null);
    respondWith("ui_flags", () => new Promise(() => undefined));
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    expect(screen.queryByRole("heading", { name: "Welcome to EchoLedger" })).toBeNull();
    expect(await screen.findByRole("heading", { name: "Welcome to EchoLedger" }, { timeout: WIZARD_FLAGS_WAIT_MS + 1500 })).toBeInTheDocument();
    expect(document.querySelector(".onboarding-card")).not.toBeNull();
  });

  it("DT-HLP-008: a refused Allow says so on its step", async () => {
    respond("permissions_status", perms({ microphone: "unknown" }));
    fail("permission_request", "The request couldn't be shown.");
    render(<div className="el-root"><OnboardingV2 status={makeStatus({ platform: "macos", paired: true })} {...{ onStatus: vi.fn(), onFinished: vi.fn(), notify: vi.fn() }} /></div>);
    await act(flushAsync);
    next();
    fireEvent.click(screen.getByRole("button", { name: "Allow" }));
    await act(flushAsync);
    expect(screen.getByTestId("allow-failed")).toHaveTextContent("macOS didn't ask: The request couldn't be shown. Use Open System Settings instead.");
    expect(screen.getByTestId("allow-failed")).toHaveAttribute("role", "alert");
  });

  it("DT-HLP-008: without it, classic's wizard as before", async () => {
    app(null);
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    expect(await screen.findByRole("heading", { name: "Welcome to EchoLedger" })).toBeInTheDocument();
    expect(document.querySelector(".onboarding-card")).not.toBeNull();
    expect(document.querySelector('[data-v2="onboarding"]')).toBeNull();
  });
});
