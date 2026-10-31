import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { AutoRecordApps, CaptionStyleCard, MicrophoneCard, appRecords, testVerdict } from "./CaptureSettings";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondAll } from "../../test/tauri";
import { makeSettings } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const switchFor = (name: string) => screen.getByRole("switch", { name });

describe("auto-record apps (B1)", () => {
  it("treats apps without a stored switch as on", () => {
    expect(appRecords({}, "zoom")).toBe(true);
    expect(appRecords({ zoom: false }, "zoom")).toBe(false);
    expect(appRecords({ zoom: true }, "zoom")).toBe(true);
  });

  it("shows one switch per call app and saves only the app that changed", () => {
    const patch = vi.fn(async () => true);
    render(<AutoRecordApps settings={makeSettings({ autoRecordApps: { whatsapp: false } })} patch={patch} />);
    expect(screen.getAllByRole("switch")).toHaveLength(6);
    expect(switchFor("Auto-record WhatsApp")).toHaveAttribute("aria-checked", "false");
    expect(switchFor("Auto-record Zoom")).toHaveAttribute("aria-checked", "true");
    fireEvent.click(switchFor("Auto-record Zoom"));
    expect(patch).toHaveBeenCalledWith({ autoRecordApps: { zoom: false } });
  });

  it("disables the switches and says why while auto-record is off", () => {
    render(<AutoRecordApps settings={makeSettings({ autoMode: false })} patch={vi.fn(async () => true)} />);
    expect(switchFor("Auto-record Google Meet")).toBeDisabled();
    expect(screen.getByText(/Auto-record is off/)).toBeInTheDocument();
  });
});

describe("caption style (B4)", () => {
  const style = makeSettings().captionStyle;

  it("saves the whole style with the changed value", () => {
    const patch = vi.fn(async () => true);
    render(<CaptionStyleCard settings={makeSettings()} patch={patch} />);
    fireEvent.change(screen.getByLabelText("Text size"), { target: { value: "large" } });
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, size: "large" } });
    fireEvent.change(screen.getByLabelText("Width"), { target: { value: "wide" } });
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, width: "wide" } });
    fireEvent.change(screen.getByLabelText("Background opacity"), { target: { value: "60" } });
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, opacity: 60 } });
    fireEvent.change(screen.getByLabelText("Hide captions when nobody speaks"), { target: { value: "10" } });
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, autoHideSeconds: 10 } });
    fireEvent.click(switchFor("Compact captions"));
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, compact: true } });
  });

  it("picks speaker colours and goes back to the theme colour", () => {
    const patch = vi.fn(async () => true);
    render(<CaptionStyleCard settings={makeSettings({ captionStyle: { ...style, clientColor: "#f59e0b" } })} patch={patch} />);
    expect(screen.getByLabelText("Colour for Me")).toHaveValue("#9eb1ff");
    expect(screen.getAllByRole("button", { name: "Default" })).toHaveLength(1);
    fireEvent.change(screen.getByLabelText("Colour for Me"), { target: { value: "#22c55e" } });
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, clientColor: "#f59e0b", meColor: "#22c55e" } });
    fireEvent.click(screen.getByRole("button", { name: "Default" }));
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, clientColor: null } });
  });
});

describe("microphone and capture health (B5)", () => {
  const devices = [
    { id: "mic-default", name: "Laptop microphone", isDefault: true },
    { id: "mic-usb", name: "USB headset", isDefault: false },
  ];
  const result = { seconds: 10, micPeak: 0.2, systemPeak: 0, micHeard: true, systemHeard: false, wavBase64: "UklGRiQAAABXQVZF" };

  beforeEach(() => {
    resetTauriMock();
    respondAll({
      input_devices: devices,
      permissions_status: { microphone: "allowed", browserAutomation: false, platform: "windows" },
      test_capture: result,
      open_privacy_settings: undefined,
    });
  });

  async function renderCard(settings = makeSettings(), disabled = false) {
    const patch = vi.fn(async () => true);
    render(<MicrophoneCard settings={settings} patch={patch} disabled={disabled} />);
    await waitFor(() => expect(screen.getByRole("option", { name: "USB headset" })).toBeInTheDocument());
    return patch;
  }

  it("lists the microphones with the default marked and saves the choice", async () => {
    const patch = await renderCard();
    expect(screen.getByRole("option", { name: "Laptop microphone (default)" })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("Record from"), { target: { value: "mic-usb" } });
    expect(patch).toHaveBeenCalledWith({ inputDevice: "mic-usb" });
  });

  it("goes back to the system default with null", async () => {
    const patch = await renderCard(makeSettings({ inputDevice: "mic-usb" }));
    expect(screen.getByLabelText("Record from")).toHaveValue("mic-usb");
    fireEvent.change(screen.getByLabelText("Record from"), { target: { value: "" } });
    expect(patch).toHaveBeenCalledWith({ inputDevice: null });
  });

  it("says when the chosen microphone is not connected", async () => {
    await renderCard(makeSettings({ inputDevice: "mic-gone" }));
    expect(screen.getByLabelText("Record from")).toHaveValue("");
    expect(screen.getByText(/not connected, so the system default is used/)).toBeInTheDocument();
  });

  it("runs the 10-second test, moves the meters and plays the result back", async () => {
    let finish: (v: unknown) => void = () => undefined;
    const { respondWith } = await import("../../test/tauri");
    respondWith("test_capture", () => new Promise((resolve) => (finish = resolve)));
    await renderCard();
    fireEvent.click(screen.getByRole("button", { name: "Test 10 seconds" }));
    expect(await screen.findByRole("button", { name: "Recording…" })).toBeDisabled();
    await emitEvent("capture:test_level", [0.25, 0.05]);
    expect(screen.getByRole("meter", { name: "Microphone level" })).toHaveAttribute("aria-valuenow", "100");
    expect(screen.getByRole("meter", { name: "Computer sound level" })).toHaveAttribute("aria-valuenow", "20");
    finish(result);
    expect(await screen.findByText("Your microphone is working.")).toBeInTheDocument();
    expect(screen.getByText(/No sound from this computer was captured/)).toBeInTheDocument();
    expect(screen.getByLabelText("Test recording")).toHaveAttribute("src", `data:audio/wav;base64,${result.wavBase64}`);
  });

  it("shows why the test could not run", async () => {
    fail("test_capture", "Finish the current recording before testing the microphone.");
    await renderCard();
    fireEvent.click(screen.getByRole("button", { name: "Test 10 seconds" }));
    expect(await screen.findByText("Finish the current recording before testing the microphone.")).toBeInTheDocument();
  });

  it("disables the test while a recording runs", async () => {
    await renderCard(makeSettings(), true);
    expect(screen.getByRole("button", { name: "Test 10 seconds" })).toBeDisabled();
  });

  it("shows the microphone permission and opens the system settings", async () => {
    respond("permissions_status", { microphone: "denied", browserAutomation: false, platform: "windows" });
    await renderCard();
    expect(await screen.findByText(/Blocked/)).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Open settings" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "microphone" }]);
  });

  it("offers the system audio permission on macOS", async () => {
    respond("permissions_status", { microphone: "unknown", browserAutomation: false, platform: "macos" });
    await renderCard();
    await flushAsync();
    const buttons = await screen.findAllByRole("button", { name: "Open settings" });
    fireEvent.click(buttons[1]);
    expect(callsOf("open_privacy_settings").at(-1)).toEqual({ kind: "system_audio" });
  });

  it("switches the silent-other-side warning", async () => {
    const patch = await renderCard();
    fireEvent.click(switchFor("Warn me when the other side is silent"));
    expect(patch).toHaveBeenCalledWith({ farSideWarning: false });
  });

  it("explains both sides of a test in plain words", () => {
    expect(testVerdict({ micHeard: false, systemHeard: true })).toEqual([
      "We did not hear your microphone. Check the device above and the microphone permission.",
      "Sound from this computer was captured.",
    ]);
  });
});
