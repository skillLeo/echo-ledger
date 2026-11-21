// Recording on the v2 Record page against classic's Record section (docs/v2/audit/parity.csv DT-HOME-001, DT-HOME-007
// to DT-HOME-013, DT-HOME-015, DT-HOME-016, DT-HOME-018 to DT-HOME-020): each control sends classic's command, and the
// page says what classic says.
import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, within } from "@testing-library/react";
import MainApp from "../../views/main/MainApp";
import type { AppStatus, RecordingInfo } from "../../lib/ipc";
import { callsOf, fail, flushAsync, resetTauriMock, respond } from "../../test/tauri";
import { makeEntitlements, makeSettings, makeStatus } from "../../test/fixtures";
import { answerConfirm } from "../../test/confirm";
import { answerConfirmForTests } from "../../lib/confirm";
import { resetEntitlementsForTests } from "../../lib/entitlements";
import { errorMessage } from "../../lib/ipc";
import { HomeV2 } from "./HomeV2";
import { levelPercent, recordingLine, type Run } from "./RecordSection";

vi.mock("@tauri-apps/api/core", async () => (await import("../../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../../test/tauri")).eventModule);

const CALL: RecordingInfo = { clientRef: "r1", mode: "call", sourceApp: "zoom", clientId: null, startedAt: "", backend: "mock" };
const NOTE: RecordingInfo = { ...CALL, clientRef: "r2", mode: "mic_note", sourceApp: "voice_note" };
const CAPTURE: RecordingInfo = { ...CALL, clientRef: "r3", mode: "voice_note", sourceApp: "voice_note" };

let said: string[] = [];
/** MainApp's run: the command, then its words (or the error's). */
const run: Run = async (fn, ok) => {
  try {
    const result = await fn();
    if (ok) said.push(ok);
    return result;
  } catch (e) {
    said.push(errorMessage(e));
    return undefined;
  }
};
const props = () => ({
  onRecord: vi.fn(), onStop: vi.fn(), onSetup: vi.fn(), onReadyCheck: vi.fn(), onOpenRecording: vi.fn(), onLibrary: vi.fn(), onAction: vi.fn(), onPlan: vi.fn(),
  onImport: vi.fn(), run,
});
async function show(status: Partial<AppStatus> = {}, p = props()) {
  const view = render(<HomeV2 status={makeStatus({ platform: "macos", ...status })} {...p} />);
  await act(flushAsync);
  return { ...view, p };
}
const section = () => within(screen.getByRole("region", { name: "Record" }));
const now = () => within(screen.getByRole("region", { name: "Recording now" }));

beforeEach(() => {
  resetTauriMock();
  said = [];
  answerConfirmForTests(null);
  resetEntitlementsForTests(makeEntitlements());
  respond("ready_last", { ready: true, checkedAt: "2026-11-17T10:00:00Z", checks: [] });
  respond("permissions_status", { microphone: "allowed", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos", missing: [] });
  respond("library_list", []);
  respond("skipped_list", []);
  respond("input_devices", [{ id: "mac", name: "MacBook Microphone", isDefault: true, bluetooth: false }]);
  respond("get_settings", makeSettings());
  for (const c of ["pause_recording", "resume_recording", "cancel_recording", "toggle_captions", "toggle_mic_note", "toggle_voice_note"]) respond(c, makeStatus());
  respond("mark_moment", { count: 1 });
  respond("copy_last_minutes", "text");
  respond("open_privacy_settings", undefined);
});

describe("the Record page's recording section", () => {
  it("DT-HOME-007: Not recording, Recording · the app, Paused · voice note", async () => {
    expect([recordingLine({ recording: null, paused: false }), recordingLine({ recording: CALL, paused: false }), recordingLine({ recording: NOTE, paused: true })])
      .toEqual(["Not recording", "Recording · Zoom", "Paused · voice note"]);
    const { rerender, p } = await show();
    expect(now().getByRole("status")).toHaveTextContent("Not recording");
    expect(now().queryByRole("group", { name: "Recording controls" })).toBeNull();
    rerender(<HomeV2 status={makeStatus({ platform: "macos", recording: CALL, elapsedMs: 5_000 })} {...p} />);
    expect(now().getByRole("status")).toHaveTextContent("Recording · Zoom");
    rerender(<HomeV2 status={makeStatus({ platform: "macos", recording: NOTE, paused: true })} {...p} />);
    expect(now().getByRole("status")).toHaveTextContent("Paused · voice note");
  });

  it("DT-HOME-008: Pause recording pauses it; while paused, Resume recording resumes it", async () => {
    const { rerender, p } = await show({ recording: CALL });
    fireEvent.click(now().getByRole("button", { name: "Pause recording" }));
    await act(flushAsync);
    expect(callsOf("pause_recording")).toHaveLength(1);
    expect(callsOf("resume_recording")).toHaveLength(0);
    rerender(<HomeV2 status={makeStatus({ platform: "macos", recording: CALL, paused: true })} {...p} />);
    expect(now().queryByRole("button", { name: "Pause recording" })).toBeNull();
    fireEvent.click(now().getByRole("button", { name: "Resume recording" }));
    await act(flushAsync);
    expect(callsOf("resume_recording")).toHaveLength(1);
  });

  it("DT-HOME-009: Cancel recording asks first, in the same word; Keep recording keeps it, Cancel recording cancels it", async () => {
    const asked = answerConfirm();
    await show({ recording: CALL });
    fireEvent.click(now().getByRole("button", { name: "Cancel recording" }));
    await act(flushAsync);
    expect(asked).toHaveBeenCalledWith("Cancel this recording? What was recorded so far is deleted. It can't be undone.");
    expect(now().getByRole("button", { name: "Cancel recording" })).toHaveClass("el-button--danger");
    expect(callsOf("cancel_recording")).toHaveLength(0);
    asked.mockReturnValue(true);
    fireEvent.click(now().getByRole("button", { name: "Cancel recording" }));
    await act(flushAsync);
    expect(callsOf("cancel_recording")).toHaveLength(1);
  });

  it("DT-HOME-010: Bookmark this moment marks the running recording and says so", async () => {
    await show({ recording: CALL });
    fireEvent.click(now().getByRole("button", { name: "Bookmark this moment" }));
    await act(flushAsync);
    expect(callsOf("mark_moment")).toEqual([{ tag: null, note: null }]);
    expect(said).toEqual(["Bookmarked"]);
  });

  it("DT-HOME-010: a bookmark the core refused says why", async () => {
    fail("mark_moment", "Nothing is being recorded.");
    await show({ recording: CALL });
    fireEvent.click(now().getByRole("button", { name: "Bookmark this moment" }));
    await act(flushAsync);
    expect(said).toEqual(["Nothing is being recorded."]);
  });

  it("DT-HOME-011: the Me and Client levels follow the core's levels", async () => {
    expect([levelPercent(0), levelPercent(0.1), levelPercent(0.3), levelPercent(Number.NaN)]).toEqual([0, 40, 100, 0]);
    const { rerender, p } = await show({ recording: CALL, levels: [0.1, 0.05] });
    expect(now().getByRole("meter", { name: "Me level" })).toHaveAttribute("aria-valuenow", "40");
    expect(now().getByRole("meter", { name: "Client level" })).toHaveAttribute("aria-valuenow", "20");
    rerender(<HomeV2 status={makeStatus({ platform: "macos", recording: CALL, levels: [0, 0.5] })} {...p} />);
    expect(now().getByRole("meter", { name: "Me level" })).toHaveAttribute("aria-valuenow", "0");
    expect(now().getByRole("meter", { name: "Client level" })).toHaveAttribute("aria-valuenow", "100");
  });

  it("DT-HOME-012: while recording, the device note and a Bluetooth microphone's note show", async () => {
    respond("input_devices", [{ id: "buds", name: "AirPods Pro", isDefault: true, bluetooth: true }]);
    await show({ recording: CALL, deviceNote: { text: "Recording from AirPods Pro and Zoom", warning: true } });
    expect(screen.getByTestId("device-note")).toHaveTextContent("Recording from AirPods Pro and Zoom");
    expect(screen.getByTestId("device-note")).toHaveClass("el-row__error");
    expect(screen.getByTestId("bluetooth-note")).toHaveTextContent("Accuracy is lower with Bluetooth mics · AirPods Pro");
  });

  it("DT-HOME-012: nothing about devices when not recording", async () => {
    respond("input_devices", [{ id: "buds", name: "AirPods Pro", isDefault: true, bluetooth: true }]);
    await show({ deviceNote: { text: "Recording from AirPods Pro", warning: false } });
    expect(screen.queryByTestId("device-note")).toBeNull();
    expect(screen.queryByTestId("bluetooth-note")).toBeNull();
  });

  it("DT-HOME-013: a permission the system holds back is said, and its button opens that pane of System Settings", async () => {
    respond("permissions_status", { microphone: "denied", systemAudio: "allowed", screen: "allowed", browserAutomation: true, platform: "macos",
      missing: [{ kind: "microphone", sentence: "EchoLedger can't hear your microphone: your side won't be recorded." }] });
    await show();
    const banner = screen.getByTestId("permission-microphone");
    expect(banner).toHaveTextContent("EchoLedger can't hear your microphone: your side won't be recorded.");
    fireEvent.click(within(banner).getByRole("button", { name: "Open System Settings" }));
    await act(flushAsync);
    expect(callsOf("open_privacy_settings")).toEqual([{ kind: "microphone" }]);
  });

  it("DT-HOME-015: the Live captions switch turns captions on (which starts a recording) and shows their state", async () => {
    const { rerender, p } = await show();
    const toggle = section().getByRole("switch", { name: "Live captions" });
    expect(toggle).toHaveAttribute("aria-checked", "false");
    expect(toggle).toHaveAccessibleDescription("Captions float over your other windows. Turning them on starts a recording.");
    fireEvent.click(toggle);
    await act(flushAsync);
    expect(callsOf("toggle_captions")).toHaveLength(1);
    rerender(<HomeV2 status={makeStatus({ platform: "macos", recording: CALL, captionsLive: true })} {...p} />);
    expect(section().getByRole("switch", { name: "Live captions" })).toHaveAttribute("aria-checked", "true");
    expect(section().getByRole("switch", { name: "Live captions" })).toHaveAccessibleDescription("Captions float over your other windows.");
  });

  it("DT-HOME-016: Copy last 2 minutes asks the core for 2 minutes and says it copied them", async () => {
    await show({ recording: CALL, captionsLive: true });
    fireEvent.click(section().getByRole("button", { name: "Copy last 2 minutes" }));
    await act(flushAsync);
    expect(callsOf("copy_last_minutes")).toEqual([{ minutes: 2 }]);
    expect(said).toEqual(["Copied the last 2 minutes"]);
  });

  it("DT-HOME-018: Capture audio playing now starts a capture; while it runs it says Stop capture, and the voice note waits", async () => {
    const { rerender, p } = await show();
    fireEvent.click(section().getByRole("button", { name: "Capture audio playing now" }));
    await act(flushAsync);
    expect(callsOf("toggle_voice_note")).toHaveLength(1);
    rerender(<HomeV2 status={makeStatus({ platform: "macos", recording: CAPTURE })} {...p} />);
    fireEvent.click(section().getByRole("button", { name: "Stop capture" }));
    await act(flushAsync);
    expect(callsOf("toggle_voice_note")).toHaveLength(2);
    expect(section().getByRole("button", { name: "Record a voice note" })).toBeDisabled();
    // A microphone voice note is the other way round.
    rerender(<HomeV2 status={makeStatus({ platform: "macos", recording: NOTE })} {...p} />);
    expect(section().getByRole("button", { name: "Capture audio playing now" })).toBeDisabled();
    fireEvent.click(section().getByRole("button", { name: "Stop and save voice note" }));
    await act(flushAsync);
    expect(callsOf("toggle_mic_note")).toHaveLength(1);
  });

  it("DT-HOME-019: the drop card says what can be dropped, and Choose files… opens the picker", async () => {
    const { p } = await show();
    const drop = within(screen.getByRole("region", { name: "Upload files" }));
    expect(drop.getByText(/Drop voice notes, audio or video files anywhere in this window/)).toBeInTheDocument();
    fireEvent.click(drop.getByRole("button", { name: "Choose files…" }));
    expect(p.onImport).toHaveBeenCalledTimes(1);
  });

  it("DT-HOME-020: uploads waiting to retry show their errors, three at most", async () => {
    await show({ uploadErrors: ["Bilal call: the server didn't answer", "Voice note: offline", "Zoom: file too large", "Teams: offline"] });
    const card = within(screen.getByRole("region", { name: "Uploads waiting to retry" }));
    expect(card.getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Bilal call: the server didn't answer", "Voice note: offline", "Zoom: file too large"]);
    expect(card.getByText("And 1 more")).toBeInTheDocument();
  });

  it("DT-HOME-020: no card when nothing waits", async () => {
    await show();
    expect(screen.queryByRole("region", { name: "Uploads waiting to retry" })).toBeNull();
  });

  it("DT-HOME-001: Upload in the header opens the picker; a viewer has none, and no recording section", async () => {
    const { p, unmount } = await show();
    fireEvent.click(screen.getByRole("button", { name: "Upload" }));
    expect(p.onImport).toHaveBeenCalledTimes(1);
    unmount();
    const viewer = makeStatus({ platform: "macos" });
    viewer.plan = { ...viewer.plan, role: "viewer" } as AppStatus["plan"];
    render(<HomeV2 status={viewer} {...props()} />);
    await act(flushAsync);
    expect(screen.queryByRole("button", { name: "Upload" })).toBeNull();
    expect(screen.queryByRole("region", { name: "Record" })).toBeNull();
  });
});

describe("in the app", () => {
  it("DT-HOME-001 and DT-HOME-019: Upload and Choose files… open the file picker, then the import sheet", async () => {
    respond("app_status", makeStatus({ platform: "macos" }));
    respond("library_refresh", []);
    respond("list_clients", { data: [] });
    respond("mic_users", []);
    respond("import_pick", ["/Users/me/Desktop/bilal.m4a"]);
    respond("ui_flags", { ui_flags: { "ui_v2.shell": true, "ui_v2.home": true } });
    render(<MainApp />);
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(within(await screen.findByRole("navigation", { name: "Main" })).getByRole("button", { name: "More ways to record" }));
    fireEvent.click(screen.getByRole("menuitem", { name: "Record page" }));
    await act(flushAsync);
    await act(flushAsync);
    fireEvent.click(await screen.findByRole("button", { name: "Upload" }));
    await act(flushAsync);
    expect(callsOf("import_pick")).toHaveLength(1);
    expect(await screen.findByText(/bilal\.m4a/)).toBeInTheDocument();
  });
});
