import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ComponentProps } from "react";
import { RecordView } from "./RecordView";
import type { AppStatus, MicUser } from "../../lib/ipc";
import { callsOf, emitEvent, fail, resetTauriMock, respond } from "../../test/tauri";
import { makeMicUser, makeRecording, makeSettings, makeStatus } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { ConfirmHost } from "./ConfirmHost";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

type Run = ComponentProps<typeof RecordView>["run"];

/** Mirrors MainApp.run: executes the action; the success message is recorded for assertions. */
function makeRun() {
  const run = vi.fn(async (fn: () => Promise<unknown>, _ok?: string) => fn());
  return run as unknown as Run & typeof run;
}

async function setup(status: Partial<AppStatus> = {}, users: MicUser[] = []) {
  respond("mic_users", users);
  respond("start_recording", makeStatus());
  respond("record_now", makeStatus());
  respond("stop_recording", makeStatus());
  respond("toggle_captions", makeStatus());
  respond("toggle_voice_note", makeStatus());
  respond("toggle_mic_note", makeStatus());
  respond("copy_last_minutes", "text");
  respond("update_settings", {});
  const run = makeRun();
  let view!: ReturnType<typeof render>;
  await act(async () => {
    view = render(<RecordView status={makeStatus(status)} run={run} />);
  });
  return { run, ...view };
}

const meterFills = (container: HTMLElement) => [...container.querySelectorAll<HTMLElement>(".meter > div")].map((el) => el.style.transform);

describe("RecordView main control", () => {
  beforeEach(() => resetTauriMock());

  it("shows Record as a filled dot, a recording as stop, and breathes a ring only while it records (v1.5)", async () => {
    const idle = await setup();
    const glyph = () => idle.container.querySelector(".record-glyph svg")!;
    expect(glyph().querySelector("circle")).toHaveAttribute("fill", "currentColor");
    expect(screen.getByTestId("record-wrap")).not.toHaveClass("live");
    idle.unmount();
    await setup({ recording: makeRecording() });
    expect(document.querySelector(".record-glyph rect")).toHaveAttribute("fill", "currentColor");
    expect(screen.getByTestId("record-wrap")).toHaveClass("live");
    cleanup();
    await setup({ recording: makeRecording(), paused: true });
    expect(screen.getByTestId("record-wrap")).not.toHaveClass("live");
  });

  it("offers REC when idle and records now on click: the meeting on screen, else a voice note", async () => {
    const { run } = await setup();
    expect(screen.getByRole("heading", { name: "Record" })).toBeInTheDocument();
    const button = screen.getByRole("button", { name: "Record now" });
    expect(button).toHaveTextContent("REC");
    expect(screen.getByText("Not recording")).toBeInTheDocument();
    expect(screen.queryByText(/loopback|Core Audio|Simulated/)).not.toBeInTheDocument();

    fireEvent.click(button);
    expect(run).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(callsOf("record_now")).toHaveLength(1));
    expect(callsOf("stop_recording")).toHaveLength(0);
    expect(screen.getByTestId("record-now-hint")).toHaveTextContent("both sides");
  });

  it("offers STOP while recording and stops on click", async () => {
    await setup({ recording: makeRecording() });
    const button = screen.getByRole("button", { name: "Stop recording" });
    expect(button).toHaveTextContent("STOP");
    expect(screen.getByText(/^Recording ·/)).toBeInTheDocument();
    fireEvent.click(button);
    await waitFor(() => expect(callsOf("stop_recording")).toHaveLength(1));
    expect(callsOf("start_recording")).toHaveLength(0);
  });

  it("shows a live indicator only while recording", async () => {
    const idle = await setup();
    expect(idle.container.querySelector(".record-hero .status-dot")).not.toHaveClass("live");
    idle.unmount();
    const live = await setup({ recording: makeRecording() });
    expect(live.container.querySelector(".record-hero .status-dot")).toHaveClass("live");
  });

  it.each(["process_loopback", "endpoint_loopback", "core_audio_tap", "mock"] as const)("never names the capture method while recording (%s; D-013)", async (backend) => {
    await setup({ recording: makeRecording({ backend }) });
    expect(screen.queryByText(/loopback|Core Audio|Simulated|EchoLedger excluded/)).not.toBeInTheDocument();
  });

  it("scales the Me and Client meters from the audio levels and caps them at full", async () => {
    const quiet = await setup({ levels: [0, 0] });
    expect(meterFills(quiet.container)).toEqual(["scaleX(0)", "scaleX(0)"]);
    quiet.unmount();
    const mixed = await setup({ levels: [0.1, 0.5] });
    expect(meterFills(mixed.container)).toEqual(["scaleX(0.4)", "scaleX(1)"]);
    expect(screen.getByText("Me")).toBeInTheDocument();
    expect(screen.getByText("Client")).toBeInTheDocument();
  });

  // A6.DU8: the Record view shows the raw app id ("whatsapp business") instead of the shared display label.
  it("names the source app with its display label while recording", async () => {
    // The shared label map ("WhatsApp Business", "Google Meet") is what every other screen uses.
    await setup({ recording: makeRecording({ sourceApp: "whatsapp_business" }) });
    expect(screen.getByText("Recording · WhatsApp Business")).toBeInTheDocument();
  });

  it.each([
    ["whatsapp_web", "Recording · WhatsApp Web"],
    ["microsoft_teams", "Recording · Microsoft Teams"],
    ["google_meet", "Recording · Google Meet"],
  ])("labels a %s recording with its display name", async (sourceApp, text) => {
    await setup({ recording: makeRecording({ sourceApp }) });
    expect(screen.getByText(text)).toBeInTheDocument();
  });

  it("calls a microphone note a voice note", async () => {
    await setup({ recording: makeRecording({ mode: "mic_note", sourceApp: "voice_note" }) });
    expect(screen.getByText("Recording · voice note")).toBeInTheDocument();
  });
});

describe("RecordView voice notes", () => {
  beforeEach(() => resetTauriMock());

  const micButton = () => screen.getByRole("button", { name: /voice note$/ });
  const captureButton = () => screen.getByRole("button", { name: /Capture audio playing now|Stop capture/ });

  it("is idle: both buttons enabled with their start labels", async () => {
    await setup();
    expect(micButton()).toHaveTextContent("Record a voice note");
    expect(micButton()).toBeEnabled();
    expect(captureButton()).toHaveTextContent("Capture audio playing now");
    expect(captureButton()).toBeEnabled();

    fireEvent.click(micButton());
    await waitFor(() => expect(callsOf("toggle_mic_note")).toHaveLength(1));
    fireEvent.click(captureButton());
    await waitFor(() => expect(callsOf("toggle_voice_note")).toHaveLength(1));
  });

  it("during a call recording: both disabled so a note cannot start over the call", async () => {
    await setup({ recording: makeRecording({ mode: "call" }) });
    expect(micButton()).toBeDisabled();
    expect(captureButton()).toBeDisabled();
  });

  it("during a microphone note: only the microphone button is active and says stop", async () => {
    await setup({ recording: makeRecording({ mode: "mic_note", sourceApp: "voice_note" }) });
    expect(micButton()).toHaveTextContent("Stop and save voice note");
    expect(micButton()).toBeEnabled();
    expect(captureButton()).toBeDisabled();
    fireEvent.click(micButton());
    await waitFor(() => expect(callsOf("toggle_mic_note")).toHaveLength(1));
  });

  it("during a captured voice note: only the capture button is active and says stop", async () => {
    await setup({ recording: makeRecording({ mode: "voice_note", sourceApp: "voice_note" }) });
    expect(captureButton()).toHaveTextContent("Stop capture");
    expect(captureButton()).toBeEnabled();
    expect(screen.getByRole("button", { name: "Record a voice note" })).toBeDisabled();
    fireEvent.click(captureButton());
    await waitFor(() => expect(callsOf("toggle_voice_note")).toHaveLength(1));
  });

  it("explains that voice notes are never recorded automatically and can be dropped in", async () => {
    await setup();
    expect(screen.getByText(/never recorded automatically/)).toBeInTheDocument();
    expect(screen.getByText(/Drop voice notes, audio or video files anywhere in this window to transcribe them \(OPUS, OGG, MP3, M4A, AAC, WAV, MP4 or WEBM\)/)).toBeInTheDocument();
  });
});

describe("RecordView captions and copy", () => {
  beforeEach(() => resetTauriMock());

  it("toggles live captions", async () => {
    await setup({ captionsLive: false });
    const toggle = screen.getByRole("switch", { name: "Toggle live captions" });
    expect(toggle).not.toHaveClass("on");
    fireEvent.click(toggle);
    await waitFor(() => expect(callsOf("toggle_captions")).toHaveLength(1));
  });

  it("marks the captions switch as on when captions are live", async () => {
    await setup({ captionsLive: true });
    expect(screen.getByRole("switch", { name: "Toggle live captions" })).toHaveClass("on");
  });

  it("warns that captions start a recording only when nothing is recording", async () => {
    const idle = await setup();
    expect(screen.getByText(/Turning them on starts a recording/)).toBeInTheDocument();
    idle.unmount();
    await setup({ recording: makeRecording() });
    expect(screen.queryByText(/Turning them on starts a recording/)).not.toBeInTheDocument();
  });

  it("copies the last two minutes and passes the success message to the runner", async () => {
    const { run } = await setup();
    fireEvent.click(screen.getByRole("button", { name: "Copy last 2 minutes" }));
    expect(run).toHaveBeenCalledWith(expect.any(Function), "Copied the last 2 minutes");
    await waitFor(() => expect(callsOf("copy_last_minutes")).toEqual([{ minutes: 2 }]));
  });

  // A6.DU7: the Record view switches show their state only through a CSS class (no aria-pressed / aria-checked).
  // Phase 1 contract 5.4: switches are role="switch" with aria-checked, so they are looked up by that role.
  it("exposes the on/off state of each switch to assistive technology", async () => {
    await setup({ captionsLive: true });
    for (const name of ["Toggle live captions"]) {
      const control = screen.getByRole("switch", { name });
      const state = control.getAttribute("aria-pressed") ?? control.getAttribute("aria-checked");
      expect(state, `${name} has no aria-pressed / aria-checked`).not.toBeNull();
    }
  });

  it("reports each switch as checked exactly when its feature is on", async () => {
    const on = await setup({ captionsLive: true, autoMode: true });
    expect(screen.getByRole("switch", { name: "Toggle live captions" })).toBeChecked();
    on.unmount();
    await setup({ captionsLive: false, autoMode: false });
    expect(screen.getByRole("switch", { name: "Toggle live captions" })).not.toBeChecked();
  });
});

describe("Home leaves call detection to Settings (D-014)", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => vi.useRealTimers());

  it("lists no apps using the microphone and has no second auto-record switch", async () => {
    await setup({ autoMode: true }, [makeMicUser({ name: "ms-teams.exe", app: "microsoft_teams", site: null })]);
    expect(screen.queryByTestId("mic-users")).not.toBeInTheDocument();
    expect(screen.queryByText(/No app is using the microphone|Holds mic|ms-teams/)).not.toBeInTheDocument();
    expect(screen.queryByRole("switch", { name: /automatic call recording/i })).not.toBeInTheDocument();
    expect(screen.queryByText("Automatic call recording")).not.toBeInTheDocument();
  });

  it("never asks the core which apps use the microphone", async () => {
    vi.useFakeTimers({ toFake: ["setInterval", "clearInterval", "setTimeout", "clearTimeout"] });
    await setup();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_000);
    });
    expect(callsOf("mic_users")).toHaveLength(0);
  });
});

describe("RecordView pause, resume and cancel", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => vi.restoreAllMocks());

  it("offers no pause or cancel while nothing is recording", async () => {
    await setup();
    expect(screen.queryByRole("button", { name: "Pause recording" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Cancel recording" })).not.toBeInTheDocument();
  });

  it("pauses a running recording", async () => {
    respond("pause_recording", makeStatus({ paused: true }));
    await setup({ recording: makeRecording() });
    expect(screen.getByText("Recording · Zoom")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Pause recording" }));
    await waitFor(() => expect(callsOf("pause_recording")).toHaveLength(1));
    expect(screen.queryByRole("button", { name: "Resume recording" })).not.toBeInTheDocument();
  });

  it("shows the paused state with an amber dot and resumes", async () => {
    respond("resume_recording", makeStatus());
    const { container } = await setup({ recording: makeRecording(), paused: true });
    expect(screen.getByText("Paused · Zoom")).toBeInTheDocument();
    expect(container.querySelector(".record-hero .status-dot")).toHaveClass("paused");
    expect(container.querySelector(".record-hero .status-dot")).not.toHaveClass("live");
    expect(screen.getByRole("button", { name: "Stop recording" })).toHaveTextContent("STOP");
    fireEvent.click(screen.getByRole("button", { name: "Resume recording" }));
    await waitFor(() => expect(callsOf("resume_recording")).toHaveLength(1));
    expect(callsOf("pause_recording")).toHaveLength(0);
  });

  it("discards only the recording it asked about: one that ended while the question was open is left alone", async () => {
    respond("mic_users", []);
    respond("cancel_recording", makeStatus());
    const run = makeRun();
    let view!: ReturnType<typeof render>;
    await act(async () => {
      view = render(<><ConfirmHost /><RecordView status={makeStatus({ recording: makeRecording({ clientRef: "ref-1" }) })} run={run} /></>);
    });
    fireEvent.click(screen.getByRole("button", { name: "Cancel recording" }));
    // Meanwhile that call ended and another one started.
    view.rerender(<><ConfirmHost /><RecordView status={makeStatus({ recording: makeRecording({ clientRef: "ref-2" }) })} run={run} /></>);
    fireEvent.click(within(screen.getByRole("alertdialog", { name: "Discard this recording?" })).getByRole("button", { name: "Discard" }));
    await act(async () => undefined);
    expect(callsOf("cancel_recording")).toHaveLength(0);
    // The same recording still running: discarded once confirmed.
    fireEvent.click(screen.getByRole("button", { name: "Cancel recording" }));
    fireEvent.click(within(screen.getByRole("alertdialog", { name: "Discard this recording?" })).getByRole("button", { name: "Discard" }));
    await waitFor(() => expect(callsOf("cancel_recording")).toHaveLength(1));
  });

  it("asks before cancelling and discards only when confirmed", async () => {
    respond("cancel_recording", makeStatus());
    const confirm = answerConfirm().mockReturnValueOnce(false).mockReturnValueOnce(true);
    await setup({ recording: makeRecording() });
    fireEvent.click(screen.getByRole("button", { name: "Cancel recording" }));
    expect(confirm).toHaveBeenCalledWith("Discard this recording? It can't be undone.");
    expect(callsOf("cancel_recording")).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Cancel recording" }));
    await waitFor(() => expect(callsOf("cancel_recording")).toHaveLength(1));
    expect(callsOf("stop_recording")).toHaveLength(0);
  });

  it("offers pause and cancel for voice notes too", async () => {
    await setup({ recording: makeRecording({ mode: "mic_note", sourceApp: "voice_note" }) });
    expect(screen.getByRole("button", { name: "Pause recording" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Cancel recording" })).toBeInTheDocument();
  });
});

describe("RecordView upload errors", () => {
  beforeEach(() => resetTauriMock());

  it("has no error card when nothing failed", async () => {
    await setup();
    expect(screen.queryByText("Uploads waiting to retry")).not.toBeInTheDocument();
  });

  it("lists at most the first three failures", async () => {
    const { container } = await setup({ uploadErrors: ["e1: timeout", "e2: 503", "e3: reset", "e4: dns", "e5: tls"] });
    const card = screen.getByText("Uploads waiting to retry").closest(".card") as HTMLElement;
    expect(within(card).getAllByText(/^e\d:/).map((n) => n.textContent)).toEqual(["e1: timeout", "e2: 503", "e3: reset"]);
    expect(container.querySelectorAll(".error-text")).toHaveLength(3);
  });
});

describe("RecordView Bluetooth microphone (v1.5)", () => {
  beforeEach(() => resetTauriMock());
  const airpods = { id: "bt", name: "AirPods Pro", isDefault: true, bluetooth: true };
  const laptop = { id: "laptop", name: "Microphone Array (Realtek(R) Audio)", isDefault: false, bluetooth: false };

  it("says accuracy is lower when the microphone a recording uses is Bluetooth", async () => {
    respond("input_devices", [airpods, laptop]);
    respond("get_settings", makeSettings({ inputDevice: null }));
    await setup();
    const note = await screen.findByTestId("bluetooth-note");
    expect(note).toHaveTextContent("Accuracy is lower with Bluetooth mics · AirPods Pro");
  });

  it("says nothing when the chosen microphone is wired, and follows a change in Settings", async () => {
    respond("input_devices", [airpods, laptop]);
    respond("get_settings", makeSettings({ inputDevice: "laptop" }));
    await setup();
    await waitFor(() => expect(callsOf("get_settings")).toHaveLength(1));
    await act(async () => undefined);
    expect(screen.queryByTestId("bluetooth-note")).not.toBeInTheDocument();
    await emitEvent("settings:changed", makeSettings({ inputDevice: "bt" }));
    expect(screen.getByTestId("bluetooth-note")).toBeInTheDocument();
  });

  it("stays quiet when the devices cannot be listed", async () => {
    fail("input_devices", "not supported");
    respond("get_settings", makeSettings());
    await setup();
    await act(async () => undefined);
    expect(screen.queryByTestId("bluetooth-note")).not.toBeInTheDocument();
  });

  it("checks again when a recording starts", async () => {
    respond("input_devices", [laptop]);
    respond("get_settings", makeSettings());
    const { rerender, run } = await setup();
    await waitFor(() => expect(callsOf("input_devices")).toHaveLength(1));
    respond("input_devices", [{ ...laptop, isDefault: false }, airpods]);
    await act(async () => {
      rerender(<RecordView status={makeStatus({ recording: makeRecording() })} run={run} />);
    });
    await waitFor(() => expect(callsOf("input_devices")).toHaveLength(2));
    expect(await screen.findByTestId("bluetooth-note")).toBeInTheDocument();
  });
});

describe("Live Translate on the Live tab (live_translate_status)", () => {
  const plan = (liveTranslate: boolean) => ({ ...makeStatus().plan, liveTranslate });

  it("a Mac older than macOS 14.2: says it needs 14.2, Start stays off, and nothing is asked of the capture", async () => {
    respond("live_translate_status", { available: false, reason: "platform" });
    await setup({ platform: "macos" });
    await act(async () => undefined);
    expect(screen.getByTestId("translate-unavailable")).toHaveTextContent("Live Translate needs macOS 14.2 or later");
    expect(within(screen.getByTestId("translate-unavailable")).getByRole("button", { name: "Start" })).toBeDisabled();
    expect(callsOf("translate_apps")).toHaveLength(0);
  });

  it("the core says the plan leaves it out: Not in your plan", async () => {
    respond("live_translate_status", { available: false, reason: "plan" });
    await setup({ plan: plan(false) });
    await act(async () => undefined);
    expect(screen.getByTestId("translate-unavailable")).toHaveTextContent("Not in your plan");
  });

  it("available: the form to start it", async () => {
    respond("live_translate_status", { available: true, reason: null });
    respond("translate_apps", [{ pid: 4242, name: "Valorant" }]);
    respond("translate_status", null);
    await setup();
    await act(async () => undefined);
    expect(screen.queryByTestId("translate-unavailable")).toBeNull();
    expect(await screen.findByRole("option", { name: "Valorant" })).toBeInTheDocument();
  });

  it("a core that doesn't answer: the plan decides, as before, and a Mac is told it needs macOS 14.2", async () => {
    fail("live_translate_status", "unknown command");
    respond("translate_apps", []);
    respond("translate_status", null);
    await setup({ plan: plan(false) });
    await act(async () => undefined);
    expect(screen.queryByText("Live Translate")).toBeNull();
    cleanup();
    await setup({ platform: "macos" });
    await act(async () => undefined);
    expect(screen.getByTestId("translate-unavailable")).toHaveTextContent("Live Translate needs macOS 14.2 or later");
  });
});
