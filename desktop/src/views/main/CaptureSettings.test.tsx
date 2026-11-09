// Settings › Recording (v1.7 rows): Calls & meetings, Microphone & audio and Captions › Style.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { CallsPage, CaptionStylePage, MicrophonePage, appRecords, callAppsValue, testVerdict } from "./CaptureSettings";
import { callsOf, emitEvent, fail, flushAsync, resetTauriMock, respond, respondAll, respondWith } from "../../test/tauri";
import { makeSettings } from "../../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const switchFor = (name: string) => screen.getByRole("switch", { name });

describe("Calls & meetings", () => {
  it("treats apps without a stored switch as on, and says which record by themselves", () => {
    expect(appRecords({}, "zoom")).toBe(true);
    expect(appRecords({ zoom: false }, "zoom")).toBe(false);
    expect(callAppsValue(makeSettings({ autoMode: true, autoRecordApps: {} }))).toBe("WhatsApp, WhatsApp Business +4");
    expect(callAppsValue(makeSettings({ autoMode: true, autoRecordApps: { whatsapp_business: false, whatsapp_web: false, google_meet: false, microsoft_teams: false } }))).toBe("WhatsApp, Zoom");
    expect(callAppsValue(makeSettings({ autoMode: false }))).toBe("Off");
  });

  it("shows one switch per call app and saves only the app that changed", () => {
    const patch = vi.fn(async () => true);
    render(<CallsPage settings={makeSettings({ autoRecordApps: { whatsapp: false } })} patch={patch} platform="windows" onSites={vi.fn()} consentRead onConsent={vi.fn()} />);
    expect(switchFor("Auto-record WhatsApp")).toHaveAttribute("aria-checked", "false");
    fireEvent.click(switchFor("Auto-record Zoom"));
    expect(patch).toHaveBeenCalledWith({ autoRecordApps: { zoom: false } });
    expect(screen.queryByRole("switch", { name: "Read browser tab addresses" })).not.toBeInTheDocument();
  });

  it("disables the app switches while auto-record is off, and offers the consent notice until it is read", () => {
    const onConsent = vi.fn();
    render(<CallsPage settings={makeSettings({ autoMode: false })} patch={vi.fn(async () => true)} platform="macos" onSites={vi.fn()} consentRead={false} onConsent={onConsent} />);
    expect(switchFor("Auto-record Google Meet")).toBeDisabled();
    expect(switchFor("Read browser tab addresses")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Read the recording consent notice" }));
    expect(onConsent).toHaveBeenCalled();
  });

  it("sends the seconds to skip as a number and resets only this page", () => {
    const patch = vi.fn(async () => true);
    render(<CallsPage settings={makeSettings()} patch={patch} platform="windows" onSites={vi.fn()} consentRead onConsent={vi.fn()} />);
    fireEvent.change(screen.getByLabelText("Skip calls shorter than"), { target: { value: "30" } });
    expect(patch).toHaveBeenLastCalledWith({ skipCallsShorterThanS: 30 });
    vi.spyOn(window, "confirm").mockReturnValue(true);
    fireEvent.click(screen.getByRole("button", { name: "Reset to defaults" }));
    expect(window.confirm).toHaveBeenCalledWith("Reset Calls & meetings to defaults?");
    expect(patch).toHaveBeenLastCalledWith(expect.objectContaining({ autoMode: true, afterCall: "keep", skipCallsShorterThanS: 10 }));
  });
});

describe("Captions › Style", () => {
  const style = makeSettings().captionStyle;

  it("saves the whole style with the changed value, from segments, selects, the slider and a switch", () => {
    const patch = vi.fn(async () => true);
    render(<CaptionStylePage settings={makeSettings()} patch={patch} />);
    fireEvent.click(within(screen.getByRole("radiogroup", { name: "Text size" })).getByRole("radio", { name: "Large" }));
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, size: "large" } });
    fireEvent.click(within(screen.getByRole("radiogroup", { name: "Width" })).getByRole("radio", { name: "Wide" }));
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, width: "wide" } });
    fireEvent.change(screen.getByLabelText("Background opacity"), { target: { value: "60" } });
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, opacity: 60 } });
    fireEvent.change(screen.getByLabelText("Hide when nobody speaks"), { target: { value: "10" } });
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, autoHideSeconds: 10 } });
    fireEvent.click(switchFor("Compact captions"));
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, compact: true } });
    fireEvent.click(within(screen.getByRole("radiogroup", { name: "Lines" })).getByRole("radio", { name: "5" }));
    expect(patch).toHaveBeenLastCalledWith({ captionLines: 5 });
    fireEvent.change(screen.getByLabelText("Position"), { target: { value: "top_left" } });
    expect(patch).toHaveBeenLastCalledWith({ overlayCorner: "top_left" });
  });

  it("picks a speaker colour from the swatches, keeps an earlier one as a seventh, and goes back to Automatic", () => {
    const patch = vi.fn(async () => true);
    render(<CaptionStylePage settings={makeSettings({ captionStyle: { ...style, clientColor: "#f59e0b" } })} patch={patch} />);
    const me = screen.getByRole("radiogroup", { name: "Colour for Me" });
    expect(within(me).getByRole("radio", { name: "Automatic" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(within(me).getByRole("radio", { name: "#34d399" }));
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, clientColor: "#f59e0b", meColor: "#34d399" } });
    const client = screen.getByRole("radiogroup", { name: "Colour for Client" });
    expect(within(client).getByRole("radio", { name: "#f59e0b" })).toHaveAttribute("aria-checked", "true");
    fireEvent.click(within(client).getByRole("radio", { name: "Automatic" }));
    expect(patch).toHaveBeenLastCalledWith({ captionStyle: { ...style, clientColor: null } });
  });
});

describe("Microphone & audio", () => {
  const devices = [
    { id: "mic-default", name: "Laptop microphone", isDefault: true, bluetooth: false },
    { id: "mic-usb", name: "USB headset", isDefault: false, bluetooth: false },
  ];
  const result = { seconds: 10, micPeak: 0.2, systemPeak: 0, micHeard: true, systemHeard: false, wavBase64: "UklGRiQAAABXQVZF" };

  beforeEach(() => {
    resetTauriMock();
    respondAll({ input_devices: devices, permissions_status: { microphone: "allowed", browserAutomation: false, platform: "windows" }, test_capture: result, open_privacy_settings: undefined });
  });

  async function renderPage(settings = makeSettings(), disabled = false) {
    const patch = vi.fn(async () => true);
    render(<MicrophonePage settings={settings} patch={patch} disabled={disabled} />);
    await waitFor(() => expect(screen.getByRole("option", { name: "USB headset" })).toBeInTheDocument());
    return patch;
  }

  it("lists the inputs with the default marked, saves the choice and goes back to Automatic with null", async () => {
    const patch = await renderPage(makeSettings({ inputDevice: "mic-usb" }));
    expect(screen.getByRole("option", { name: "Laptop microphone (default)" })).toBeInTheDocument();
    expect(screen.getByLabelText("Microphone")).toHaveValue("mic-usb");
    fireEvent.change(screen.getByLabelText("Microphone"), { target: { value: "" } });
    expect(patch).toHaveBeenCalledWith({ inputDevice: null });
  });

  it("says accuracy is lower with a Bluetooth microphone in use, and nothing for a wired one", async () => {
    respond("input_devices", [...devices, { id: "mic-bt", name: "AirPods Pro", isDefault: false, bluetooth: true }]);
    await renderPage(makeSettings({ inputDevice: "mic-bt" }));
    expect(screen.getByTestId("bluetooth-note")).toHaveTextContent("Accuracy is lower with Bluetooth mics. A wired or built-in microphone hears calls better.");
    expect(screen.getByTestId("bluetooth-note")).toHaveAttribute("title", "AirPods Pro");
  });

  it("says when the chosen microphone isn't connected", async () => {
    await renderPage(makeSettings({ inputDevice: "mic-gone" }));
    expect(screen.getByLabelText("Microphone")).toHaveValue("");
    expect(screen.getByText(/isn't connected, so the system default is used/)).toBeInTheDocument();
  });

  it("runs the 10-second test, moves the meters and plays the result back", async () => {
    let finish: (v: unknown) => void = () => undefined;
    respondWith("test_capture", () => new Promise((resolve) => (finish = resolve)));
    await renderPage();
    fireEvent.click(screen.getByRole("button", { name: "Test 10 s" }));
    expect(await screen.findByRole("button", { name: "Recording…" })).toBeDisabled();
    await emitEvent("capture:test_level", [0.25, 0.05]);
    expect(screen.getByRole("meter", { name: "Microphone level" })).toHaveAttribute("aria-valuenow", "100");
    expect(screen.getByRole("meter", { name: "Computer sound level" })).toHaveAttribute("aria-valuenow", "20");
    finish(result);
    expect(await screen.findByText("Your microphone is working.")).toBeInTheDocument();
    expect(screen.getByLabelText("Play back the test recording")).toHaveAttribute("src", `data:audio/wav;base64,${result.wavBase64}`);
  });

  it("shows why the test couldn't run, and disables it while a recording runs", async () => {
    fail("test_capture", "Finish the current recording before testing the microphone.");
    await renderPage();
    fireEvent.click(screen.getByRole("button", { name: "Test 10 s" }));
    expect(await screen.findByText("Finish the current recording before testing the microphone.")).toBeInTheDocument();
  });

  it("disables the test while recording and says so in the footer", async () => {
    await renderPage(makeSettings(), true);
    expect(screen.getByRole("button", { name: "Test 10 s" })).toBeDisabled();
    expect(screen.getByText("Finish the current recording to run a test.")).toBeInTheDocument();
  });

  it("shows a permission that isn't allowed with Fix, which opens the system settings", async () => {
    respond("permissions_status", { microphone: "denied", browserAutomation: false, platform: "windows" });
    await renderPage();
    await flushAsync();
    expect(document.getElementById("setting-mic.perm.microphone")).toHaveTextContent("Not allowed");
    fireEvent.click(screen.getByRole("button", { name: "Fix" }));
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "microphone" }]);
  });

  it("switches the silent caller warning", async () => {
    const patch = await renderPage();
    fireEvent.click(switchFor("Silent caller warning"));
    expect(patch).toHaveBeenCalledWith({ farSideWarning: false });
  });

  it("explains both sides of a test in plain words", () => {
    expect(testVerdict({ micHeard: false, systemHeard: true })).toEqual([
      "We didn't hear your microphone. Check the microphone above and its permission.",
      "Sound from this computer was captured.",
    ]);
  });
});
