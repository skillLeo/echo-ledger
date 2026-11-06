import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import { PERMISSION_KINDS, PERMISSION_STEP, PermissionBanner, PermissionGuide, PermissionLossNotice, PermissionStep, accessOf } from "./Permissions";
import { MAC_STEPS, OnboardingView, STEPS, stepsFor } from "./OnboardingView";
import { RecordView } from "./RecordView";
import { MicrophoneCard } from "./CaptureSettings";
import { callsOf, emitEvent, flushAsync, resetTauriMock, respond, respondAll } from "../../test/tauri";
import { makeRecording, makeSettings, makeStatus } from "../../test/fixtures";
import type { PermissionsStatus } from "../../lib/ipc";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const mac = (overrides: Partial<PermissionsStatus> = {}): PermissionsStatus => ({
  microphone: "allowed",
  systemAudio: "allowed",
  screen: "allowed",
  browserAutomation: false,
  platform: "macos",
  missing: [],
  lost: null,
  ...overrides,
});
const NO_SYSTEM = { kind: "system_audio" as const, sentence: "System audio is off for EchoLedger: a recording will have your voice, not the other side of the call." };

beforeEach(() => {
  resetTauriMock();
  respondAll({ open_privacy_settings: undefined, permission_notice_dismiss: undefined });
});

describe("a guided step per permission", () => {
  it("says what each is for and where it lives", () => {
    expect(PERMISSION_KINDS).toEqual(["microphone", "system_audio", "screen"]);
    expect(PERMISSION_STEP.microphone.forWhat).toMatch(/only the other side/);
    expect(PERMISSION_STEP.system_audio.forWhat).toMatch(/only your voice/);
    expect(PERMISSION_STEP.screen.forWhat).toMatch(/Recordings work without it/);
    expect(PERMISSION_STEP.system_audio.where).toContain("Screen & System Audio Recording");
  });

  it("explains the monthly question in plain words where screen recording is set up", () => {
    render(<PermissionStep kind="screen" permissions={mac({ screen: "denied" })} onChange={vi.fn()} />);
    expect(screen.getByTestId("permission-note")).toHaveTextContent(/about once a month/);
    expect(screen.getByTestId("permission-note")).toHaveTextContent(/recording goes on/);
  });

  it("asks macOS while it has not asked yet, and offers System Settings", async () => {
    respond("permission_request", mac({ microphone: "allowed" }));
    const onChange = vi.fn();
    render(<PermissionStep kind="microphone" permissions={mac({ microphone: "unknown" })} onChange={onChange} />);
    expect(screen.getByTestId("permission-state-microphone")).toHaveTextContent("Not asked yet");
    fireEvent.click(screen.getByRole("button", { name: "Allow" }));
    await flushAsync();
    expect(callsOf("permission_request")).toEqual([{ kind: "microphone" }]);
    expect(onChange).toHaveBeenCalledTimes(1);
    fireEvent.click(screen.getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "microphone" }]);
  });

  it("only offers System Settings once macOS has been told no", () => {
    render(<PermissionStep kind="system_audio" permissions={mac({ systemAudio: "denied" })} onChange={vi.fn()} />);
    expect(screen.getByTestId("permission-state-system_audio")).toHaveTextContent("Off");
    expect(screen.queryByRole("button", { name: "Allow" })).not.toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Open System Settings" })).toBeInTheDocument();
  });

  it("offers nothing when it is allowed", () => {
    render(<PermissionStep kind="microphone" permissions={mac()} onChange={vi.fn()} />);
    expect(screen.getByTestId("permission-state-microphone")).toHaveTextContent("Allowed");
    expect(screen.queryByRole("button")).not.toBeInTheDocument();
  });

  it("reads an older core's answer as not asked", () => {
    expect(accessOf({ microphone: "allowed", browserAutomation: false, platform: "macos" }, "system_audio")).toBe("unknown");
    expect(accessOf(null, "microphone")).toBe("unknown");
  });
});

describe("onboarding on a Mac", () => {
  const props = { onStatus: vi.fn(), onFinished: vi.fn(), notify: vi.fn() };
  const next = () => fireEvent.click(screen.getByRole("button", { name: "Continue" }));

  it("walks one step per permission; a PC keeps its one page", () => {
    expect(stepsFor("macos")).toEqual(MAC_STEPS);
    expect(stepsFor("windows")).toEqual(STEPS);
    expect(MAC_STEPS.slice(1, 4)).toEqual(["Microphone", "System audio", "Screen recording"]);
  });

  it("goes through welcome, microphone, system audio and screen recording, then signs in", async () => {
    respond("permissions_status", mac({ microphone: "unknown", systemAudio: "denied", screen: "denied" }));
    render(<OnboardingView status={makeStatus({ platform: "macos", paired: true })} {...props} />);
    await flushAsync();
    expect(screen.getByRole("heading", { name: "Welcome to EchoLedger" })).toBeInTheDocument();
    expect(screen.getByText(/recording/i, { selector: ".notice *" })).toBeInTheDocument();
    next();
    expect(screen.getByRole("heading", { name: "Microphone" })).toBeInTheDocument();
    expect(screen.getByTestId("permission-state-microphone")).toHaveTextContent("Not asked yet");
    next();
    expect(screen.getByRole("heading", { name: "System audio" })).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "system_audio" }]);
    next();
    expect(screen.getByRole("heading", { name: "Screen recording" })).toBeInTheDocument();
    expect(screen.getByTestId("permission-note")).toHaveTextContent(/once a month/);
    next();
    expect(screen.getByRole("heading", { name: "Connect to your server" })).toBeInTheDocument();
  });

  it("reads the permissions again when the window comes back from System Settings", async () => {
    respond("permissions_status", mac({ microphone: "denied" }));
    render(<OnboardingView status={makeStatus({ platform: "macos" })} {...props} />);
    await flushAsync();
    next();
    expect(screen.getByTestId("permission-state-microphone")).toHaveTextContent("Off");
    respond("permissions_status", mac());
    fireEvent.focus(window);
    await flushAsync();
    expect(screen.getByTestId("permission-state-microphone")).toHaveTextContent("Allowed");
  });

  it("can be skipped at any step, as before", async () => {
    respond("permissions_status", mac({ microphone: "unknown" }));
    respond("update_settings", makeSettings({ onboardingDone: true }));
    const onFinished = vi.fn();
    render(<OnboardingView status={makeStatus({ platform: "macos" })} {...props} onFinished={onFinished} />);
    await flushAsync();
    next();
    fireEvent.click(screen.getByRole("button", { name: "Skip setup" }));
    await flushAsync();
    expect(onFinished).toHaveBeenCalled();
  });
});

describe("the banner on the Record page", () => {
  it("says what is missing and what a recording will contain, with the way to fix it", async () => {
    respond("permissions_status", mac({ systemAudio: "denied", missing: [NO_SYSTEM] }));
    render(<PermissionBanner recordingRef={null} />);
    await flushAsync();
    const banner = screen.getByTestId("permission-banner");
    expect(banner).toHaveTextContent("your voice, not the other side");
    fireEvent.click(within(banner).getByRole("button", { name: "Open System Settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "system_audio" }]);
  });

  it("names Windows Settings on a PC", async () => {
    respond("permissions_status", { microphone: "denied", browserAutomation: false, platform: "windows", missing: [{ kind: "microphone", sentence: "The microphone is off." }] });
    render(<PermissionBanner recordingRef={null} />);
    await flushAsync();
    expect(screen.getByRole("button", { name: "Open Windows Settings" })).toBeInTheDocument();
  });

  it("is not there when nothing is missing, or with an older core", async () => {
    respond("permissions_status", mac());
    const { unmount } = render(<PermissionBanner recordingRef={null} />);
    await flushAsync();
    expect(screen.queryByTestId("permission-banner")).not.toBeInTheDocument();
    unmount();
    respond("permissions_status", { microphone: "allowed", browserAutomation: false, platform: "windows" });
    render(<PermissionBanner recordingRef={null} />);
    await flushAsync();
    expect(screen.queryByTestId("permission-banner")).not.toBeInTheDocument();
  });

  it("looks again when a recording starts", async () => {
    respond("permissions_status", mac());
    const { rerender } = render(<PermissionBanner recordingRef={null} />);
    await flushAsync();
    respond("permissions_status", mac({ missing: [NO_SYSTEM] }));
    rerender(<PermissionBanner recordingRef="rec-1" />);
    await flushAsync();
    expect(screen.getByTestId("permission-banner")).toBeInTheDocument();
  });

  it("looks again when a recording running says what it may use has changed, not on every tick", async () => {
    respond("permissions_status", mac());
    render(<PermissionBanner recordingRef="rec-1" />);
    await flushAsync();
    const reads = callsOf("permissions_status").length;
    await emitEvent("recording:state", { active: true, missing: [] });
    await flushAsync();
    expect(callsOf("permissions_status")).toHaveLength(reads);

    // Don't Allow was pressed after the start.
    respond("permissions_status", mac({ systemAudio: "denied", missing: [NO_SYSTEM] }));
    await emitEvent("recording:state", { active: true, missing: [NO_SYSTEM] });
    await flushAsync();
    expect(screen.getByTestId("permission-banner")).toHaveTextContent("your voice, not the other side");
    expect(callsOf("permissions_status")).toHaveLength(reads + 1);
    await emitEvent("recording:state", { active: true, missing: [NO_SYSTEM] });
    await flushAsync();
    expect(callsOf("permissions_status")).toHaveLength(reads + 1);
  });
});

describe("a permission macOS forgot", () => {
  const lost = "macOS no longer lets EchoLedger use the microphone, which can happen after an update. Setting it up again takes a moment.";

  it("says so in one calm sentence and goes when dismissed", async () => {
    respond("permissions_status", mac({ microphone: "unknown", lost }));
    render(<PermissionLossNotice />);
    await flushAsync();
    expect(screen.getByTestId("permission-loss")).toHaveTextContent(lost);
    fireEvent.click(screen.getByRole("button", { name: "Dismiss" }));
    await flushAsync();
    expect(screen.queryByTestId("permission-loss")).not.toBeInTheDocument();
    expect(callsOf("permission_notice_dismiss")).toHaveLength(1);
  });

  it("offers the guided steps, and closing them counts as told", async () => {
    respond("permissions_status", mac({ microphone: "unknown", lost }));
    render(<PermissionLossNotice />);
    await flushAsync();
    fireEvent.click(screen.getByRole("button", { name: "Set up" }));
    const guide = screen.getByRole("dialog", { name: "Set up permissions" });
    for (const kind of PERMISSION_KINDS) expect(within(guide).getByTestId(`permission-${kind}`)).toBeInTheDocument();
    fireEvent.click(within(guide).getByRole("button", { name: "Done" }));
    await flushAsync();
    expect(screen.queryByRole("dialog")).not.toBeInTheDocument();
    expect(screen.queryByTestId("permission-loss")).not.toBeInTheDocument();
    expect(callsOf("permission_notice_dismiss")).toHaveLength(1);
  });

  it("is silent when nothing was lost", async () => {
    respond("permissions_status", mac({ microphone: "unknown" }));
    render(<PermissionLossNotice />);
    await flushAsync();
    expect(screen.queryByTestId("permission-loss")).not.toBeInTheDocument();
    expect(callsOf("permission_notice_dismiss")).toHaveLength(0);
  });

  it("closes the guide with Escape", () => {
    const onClose = vi.fn();
    render(<PermissionGuide permissions={mac()} onChange={vi.fn()} onClose={onClose} />);
    fireEvent.keyDown(window, { key: "Escape" });
    expect(onClose).toHaveBeenCalled();
  });
});

describe("the whole-system sentence of an old Windows, and the devices of a recording", () => {
  const WHOLE = "This Windows version records all sound playing on this computer, not just the call";
  const run = async <T,>(fn: () => Promise<T>) => fn();

  function record(status = makeStatus()) {
    respondAll({ mic_users: [], input_devices: [], get_settings: makeSettings(), permissions_status: { microphone: "allowed", browserAutomation: false, platform: "windows", missing: [] }, translate_status: { running: false }, translate_apps: [] });
    return render(<RecordView status={status} run={run} />);
  }

  it("says it on the Record page, before and during a recording", async () => {
    const { rerender } = record(makeStatus({ platform: "windows", systemAudioNote: WHOLE }));
    await flushAsync();
    expect(screen.getByTestId("whole-system-note")).toHaveTextContent(WHOLE);
    rerender(<RecordView status={makeStatus({ platform: "windows", systemAudioNote: WHOLE, recording: makeRecording({ backend: "endpoint_loopback" }) })} run={run} />);
    await flushAsync();
    expect(screen.getByTestId("whole-system-note")).toHaveTextContent(WHOLE);
  });

  it("says nothing where per-app capture works", async () => {
    record(makeStatus({ platform: "windows", systemAudioNote: null }));
    await flushAsync();
    expect(screen.queryByTestId("whole-system-note")).not.toBeInTheDocument();
  });

  it("says it in Settings", async () => {
    respondAll({ input_devices: [], permissions_status: { microphone: "allowed", browserAutomation: false, platform: "windows" } });
    render(<MicrophoneCard settings={makeSettings()} patch={vi.fn(async () => true)} disabled={false} systemAudioNote={WHOLE} />);
    await flushAsync();
    expect(screen.getByTestId("whole-system-note")).toHaveTextContent(WHOLE);
  });

  it("is quiet in Settings on a newer Windows", async () => {
    respondAll({ input_devices: [], permissions_status: { microphone: "allowed", browserAutomation: false, platform: "windows" } });
    render(<MicrophoneCard settings={makeSettings()} patch={vi.fn(async () => true)} disabled={false} systemAudioNote={null} />);
    await flushAsync();
    expect(screen.queryByTestId("whole-system-note")).not.toBeInTheDocument();
  });

  it("shows how system audio stands on a Mac", async () => {
    respondAll({ input_devices: [], permissions_status: mac({ systemAudio: "denied" }) });
    render(<MicrophoneCard settings={makeSettings()} patch={vi.fn(async () => true)} disabled={false} />);
    await flushAsync();
    expect(screen.getByTestId("system-audio-permission")).toHaveTextContent(/your voice, not the other side/);
  });

  it("says what happened to a device during a recording", async () => {
    record(makeStatus({ recording: makeRecording(), deviceNote: { text: "No microphone: recording the other side only.", warning: true } }));
    await flushAsync();
    expect(screen.getByTestId("device-note")).toHaveTextContent("other side only");
    expect(screen.getByTestId("device-note")).toHaveClass("warning-text");
  });

  it("does not show a stale device note with no recording", async () => {
    record(makeStatus({ recording: null, deviceNote: { text: "x", warning: false } }));
    await flushAsync();
    expect(screen.queryByTestId("device-note")).not.toBeInTheDocument();
  });
});
