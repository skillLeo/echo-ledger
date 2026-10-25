import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ComponentProps } from "react";
import { RecordView } from "./RecordView";
import type { AppStatus, MicUser } from "../../lib/ipc";
import { callsOf, fail, resetTauriMock, respond } from "../../test/tauri";
import { makeMicUser, makeRecording, makeStatus } from "../../test/fixtures";

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

const meterWidths = (container: HTMLElement) => [...container.querySelectorAll<HTMLElement>(".meter > div")].map((el) => el.style.width);

describe("RecordView main control", () => {
  beforeEach(() => resetTauriMock());

  it("offers REC when idle and starts a recording on click", async () => {
    const { run } = await setup();
    expect(screen.getByRole("heading", { name: "Record" })).toBeInTheDocument();
    const button = screen.getByRole("button", { name: "Start recording" });
    expect(button).toHaveTextContent("REC");
    expect(screen.getByText("Not recording")).toBeInTheDocument();
    expect(screen.queryByText(/loopback|Core Audio|Simulated/)).not.toBeInTheDocument();

    fireEvent.click(button);
    expect(run).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(callsOf("start_recording")).toEqual([{ sourceApp: null }]));
    expect(callsOf("stop_recording")).toHaveLength(0);
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

  it.each([
    ["process_loopback", "Windows process loopback (EchoLedger excluded)"],
    ["endpoint_loopback", "Windows endpoint loopback (all system audio)"],
    ["core_audio_tap", "macOS Core Audio tap"],
    ["mock", "Simulated source"],
  ] as const)("describes the %s capture backend", async (backend, label) => {
    await setup({ recording: makeRecording({ backend }) });
    expect(screen.getByText(label)).toBeInTheDocument();
  });

  it("scales the Me and Client meters from the audio levels and caps them at full", async () => {
    const quiet = await setup({ levels: [0, 0] });
    expect(meterWidths(quiet.container)).toEqual(["0%", "0%"]);
    quiet.unmount();
    const mixed = await setup({ levels: [0.1, 0.5] });
    expect(meterWidths(mixed.container)).toEqual(["40%", "100%"]);
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
    expect(screen.getByText(/Drag & drop .opus \/ .ogg \/ .m4a \/ .mp3 \/ .wav voice notes here/)).toBeInTheDocument();
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
    expect(screen.getByText(/Turning captions on starts a recording/)).toBeInTheDocument();
    idle.unmount();
    await setup({ recording: makeRecording() });
    expect(screen.queryByText(/Turning captions on starts a recording/)).not.toBeInTheDocument();
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
    for (const name of ["Toggle live captions", "Toggle automatic call recording"]) {
      const control = screen.getByRole("switch", { name });
      const state = control.getAttribute("aria-pressed") ?? control.getAttribute("aria-checked");
      expect(state, `${name} has no aria-pressed / aria-checked`).not.toBeNull();
    }
  });

  it("reports each switch as checked exactly when its feature is on", async () => {
    const on = await setup({ captionsLive: true, autoMode: true });
    expect(screen.getByRole("switch", { name: "Toggle live captions" })).toBeChecked();
    expect(screen.getByRole("switch", { name: "Toggle automatic call recording" })).toBeChecked();
    on.unmount();
    await setup({ captionsLive: false, autoMode: false });
    expect(screen.getByRole("switch", { name: "Toggle live captions" })).not.toBeChecked();
    expect(screen.getByRole("switch", { name: "Toggle automatic call recording" })).not.toBeChecked();
  });
});

describe("RecordView automatic call recording", () => {
  beforeEach(() => resetTauriMock());

  it.each([
    [true, false, /^On: EchoLedger records a call when an app both uses your microphone and plays audio/],
    [false, true, /^Off: calls are only recorded when you press REC/],
  ])("with auto mode %s the switch sends autoMode=%s and the text matches", async (autoMode, next, text) => {
    await setup({ autoMode });
    expect(screen.getByText(text)).toBeInTheDocument();
    const toggle = screen.getByRole("switch", { name: "Toggle automatic call recording" });
    if (autoMode) expect(toggle).toHaveClass("on");
    else expect(toggle).not.toHaveClass("on");
    fireEvent.click(toggle);
    await waitFor(() => expect(callsOf("update_settings")).toEqual([{ patch: { autoMode: next } }]));
  });
});

describe("RecordView call detection panel", () => {
  beforeEach(() => resetTauriMock());
  afterEach(() => vi.useRealTimers());

  const rowOf = (name: string) => screen.getByText(name).closest(".mic-user") as HTMLElement;

  it("says nothing is using the microphone when the list is empty", async () => {
    await setup({}, []);
    expect(await screen.findByText("No app is using the microphone right now.")).toBeInTheDocument();
    expect(screen.queryByText(/Holds mic/)).not.toBeInTheDocument();
  });

  it("shows the on-text from the contract while auto-record is on", async () => {
    await setup({ autoMode: true });
    expect(
      screen.getByText(
        "On: EchoLedger records a call when an app both uses your microphone and plays audio, for example WhatsApp, Zoom, Teams, or Google Meet in a browser. Dictation and voice typing are never recorded.",
      ),
    ).toBeInTheDocument();
  });

  // A1.8: one explainer line per app holding the microphone.
  it("explains a Meet tab that will be recorded, with its site next to the process name", async () => {
    const { container } = await setup({ autoMode: true }, [makeMicUser()]);
    expect(await screen.findByText("chrome.exe")).toBeInTheDocument();
    const row = rowOf("chrome.exe");
    expect(within(row).getByText("meet.google.com")).toBeInTheDocument();
    expect(within(row).getByText("Holds mic ✓ · Playing audio ✓ · Identified as Google Meet · Decision: auto-record")).toBeInTheDocument();
    expect(container.querySelector(".mic-user .status-dot")).toHaveClass("ok");
  });

  it("says Recording now for the app being recorded and marks it live", async () => {
    const { container } = await setup({}, [makeMicUser({ name: "WhatsApp.exe", app: "whatsapp", site: null, decision: "recording" })]);
    expect(await screen.findByText("Holds mic ✓ · Playing audio ✓ · Identified as WhatsApp · Recording now")).toBeInTheDocument();
    expect(container.querySelector(".mic-user .status-dot")).toHaveClass("live");
  });

  it.each([
    [{ app: "whatsapp", site: null, playsAudio: false, reason: "dictation" }, "Holds mic ✓ · Playing audio ✗ · Identified as WhatsApp · Ignored: dictation (no audio playing)"],
    [{ app: null, site: null, playsAudio: true, reason: "unknown_site" }, "Holds mic ✓ · Playing audio ✓ · Ignored: unknown site"],
    [{ name: "obs64.exe", app: null, site: null, playsAudio: false, reason: "unknown_app" }, "Holds mic ✓ · Playing audio ✗ · Ignored: not a call app"],
    [{ app: null, site: "chatgpt.com", playsAudio: false, reason: "never_record" }, "Holds mic ✓ · Playing audio ✗ · Ignored: on your never-record list"],
  ] as const)("explains an ignored app: %o", async (overrides, line) => {
    const { container } = await setup({}, [makeMicUser({ ...overrides, decision: "ignored" } as Partial<MicUser>)]);
    const why = await screen.findByText(line);
    expect(why).toHaveClass("muted");
    expect(container.querySelector(".mic-user .status-dot")).not.toHaveClass("live");
    expect(container.querySelector(".mic-user .status-dot")).not.toHaveClass("ok");
  });

  it("says auto-record is off when it is", async () => {
    await setup({ autoMode: false }, [makeMicUser({ decision: "off", reason: "auto_off" })]);
    expect(await screen.findByText("Holds mic ✓ · Playing audio ✓ · Identified as Google Meet · Auto-record is off")).toBeInTheDocument();
  });

  it("says it will ask for an unrecognised app when the ask chip is on", async () => {
    const { container } = await setup({}, [makeMicUser({ name: "Signal.exe", app: null, site: null, decision: "ask" })]);
    expect(await screen.findByText("Holds mic ✓ · Playing audio ✓ · Will ask you")).toBeInTheDocument();
    expect(container.querySelector(".mic-user .status-dot")).toHaveClass("ask");
  });

  it("labels identified apps with their display names", async () => {
    await setup({}, [
      makeMicUser({ name: "a", app: "whatsapp_business", site: null }),
      makeMicUser({ name: "b", app: "microsoft_teams", site: null }),
      makeMicUser({ name: "c", app: "whatsapp_web", site: "web.whatsapp.com" }),
    ]);
    expect(await screen.findByText(/Identified as WhatsApp Business/)).toBeInTheDocument();
    expect(screen.getByText(/Identified as Microsoft Teams/)).toBeInTheDocument();
    expect(screen.getByText(/Identified as WhatsApp Web/)).toBeInTheDocument();
  });

  it("lists several apps at once, one row each", async () => {
    const { container } = await setup({}, [
      makeMicUser(),
      makeMicUser({ name: "a protected process", app: null, site: null, playsAudio: false, decision: "ignored", reason: "unknown_app" }),
    ]);
    expect(await screen.findByText("a protected process")).toBeInTheDocument();
    expect(container.querySelectorAll(".mic-user")).toHaveLength(2);
  });

  it("polls immediately and then every two seconds, and stops after unmount", async () => {
    vi.useFakeTimers();
    const { unmount } = await setup();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(callsOf("mic_users")).toHaveLength(1);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2_000);
    });
    expect(callsOf("mic_users")).toHaveLength(2);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(4_000);
    });
    expect(callsOf("mic_users")).toHaveLength(4);
    unmount();
    await act(async () => {
      await vi.advanceTimersByTimeAsync(10_000);
    });
    expect(callsOf("mic_users")).toHaveLength(4);
  });

  it("updates the list when a later poll returns different apps", async () => {
    vi.useFakeTimers();
    await setup({}, []);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByText("No app is using the microphone right now.")).toBeInTheDocument();
    respond("mic_users", [makeMicUser({ name: "ms-teams.exe", app: "microsoft_teams", site: null })]);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2_000);
    });
    expect(screen.getByText("ms-teams.exe")).toBeInTheDocument();
  });

  it("keeps the last known list when a poll fails", async () => {
    vi.useFakeTimers();
    await setup({}, [makeMicUser({ name: "WhatsApp.exe", app: "whatsapp", site: null })]);
    await act(async () => {
      await vi.advanceTimersByTimeAsync(0);
    });
    expect(screen.getByText("WhatsApp.exe")).toBeInTheDocument();
    fail("mic_users", "audio session enumeration failed");
    await act(async () => {
      await vi.advanceTimersByTimeAsync(2_000);
    });
    expect(callsOf("mic_users")).toHaveLength(2);
    expect(screen.getByText("WhatsApp.exe")).toBeInTheDocument();
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

  it("asks before cancelling and discards only when confirmed", async () => {
    respond("cancel_recording", makeStatus());
    const confirm = vi.spyOn(window, "confirm").mockReturnValueOnce(false).mockReturnValueOnce(true);
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
