import { beforeEach, describe, expect, it, vi } from "vitest";
import { api, errorMessage, listenTo, subscribeCaptions, type RecordingState } from "./ipc";
import { Channel, callsOf, emitEvent, invoke, listen, listenerCount, resetTauriMock, respond } from "../test/tauri";
import { makeRecordingState, makeSettings } from "../test/fixtures";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);
vi.mock("@tauri-apps/api/event", async () => (await import("../test/tauri")).eventModule);

describe("api wrappers", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  // [wrapper call, command name, exact argument object sent to Rust]
  const cases: [string, () => Promise<unknown>, string, Record<string, unknown> | undefined][] = [
    ["status", () => api.status(), "app_status", undefined],
    ["pair", () => api.pair("https://ledger.example.test", "ABCD-1234", "Work laptop"), "pair_device", { serverUrl: "https://ledger.example.test", code: "ABCD-1234", deviceName: "Work laptop" }],
    ["unpair", () => api.unpair(), "unpair", undefined],
    ["settings", () => api.settings(), "get_settings", undefined],
    ["updateSettings", () => api.updateSettings({ autoMode: false }), "update_settings", { patch: { autoMode: false } }],
    ["updateSettings (clear client)", () => api.updateSettings({ clientId: null }), "update_settings", { patch: { clientId: null } }],
    ["setShortcut", () => api.setShortcut("dictation", "CommandOrControl+Shift+D"), "set_shortcut", { action: "dictation", accelerator: "CommandOrControl+Shift+D" }],
    ["resetShortcuts", () => api.resetShortcuts(), "reset_shortcuts", undefined],
    ["toggleCaptions", () => api.toggleCaptions(), "toggle_captions", undefined],
    ["startRecording (default)", () => api.startRecording(), "start_recording", { sourceApp: null }],
    ["startRecording (zoom)", () => api.startRecording("zoom"), "start_recording", { sourceApp: "zoom" }],
    ["stopRecording", () => api.stopRecording(), "stop_recording", undefined],
    ["toggleVoiceNote", () => api.toggleVoiceNote(), "toggle_voice_note", undefined],
    ["toggleMicNote", () => api.toggleMicNote(), "toggle_mic_note", undefined],
    ["hideCaptions", () => api.hideCaptions(), "hide_captions", undefined],
    ["micUsers", () => api.micUsers(), "mic_users", undefined],
    ["importVoiceNotes", () => api.importVoiceNotes(["notes/a.opus", "notes/b.mp3"]), "import_voice_notes", { pathsList: ["notes/a.opus", "notes/b.mp3"] }],
    ["copyLastMinutes (default)", () => api.copyLastMinutes(), "copy_last_minutes", { minutes: 2 }],
    ["copyLastMinutes (5)", () => api.copyLastMinutes(5), "copy_last_minutes", { minutes: 5 }],
    ["library", () => api.library(), "library_list", undefined],
    ["refreshLibrary", () => api.refreshLibrary(), "library_refresh", undefined],
    ["session", () => api.session("s1"), "session_detail", { id: "s1" }],
    ["transcript", () => api.transcript("t1"), "transcript_detail", { id: "t1" }],
    ["deleteSession", () => api.deleteSession("s1"), "delete_session", { id: "s1" }],
    ["clients", () => api.clients(), "list_clients", undefined],
    ["setHitRegions", () => api.setHitRegions([{ x: 1, y: 2, w: 3, h: 4 }]), "overlay_set_hit_regions", { regions: [{ x: 1, y: 2, w: 3, h: 4 }] }],
    ["snapOverlay", () => api.snapOverlay(), "overlay_snap", undefined],
    ["updateSettings (v1.1 keys)", () => api.updateSettings({ askBeforeSaving: "auto_only", decisionTimeout: "wait", captionLines: 5 }), "update_settings", { patch: { askBeforeSaving: "auto_only", decisionTimeout: "wait", captionLines: 5 } }],
    ["pauseRecording", () => api.pauseRecording(), "pause_recording", undefined],
    ["resumeRecording", () => api.resumeRecording(), "resume_recording", undefined],
    ["cancelRecording", () => api.cancelRecording(), "cancel_recording", undefined],
    ["setPillHidden (hide)", () => api.setPillHidden(true), "set_pill_hidden", { hidden: true }],
    ["setPillHidden (show)", () => api.setPillHidden(false), "set_pill_hidden", { hidden: false }],
    ["pillMoved", () => api.pillMoved(), "pill_moved", undefined],
    ["recordingState", () => api.recordingState(), "recording_state", undefined],
    ["pendingList", () => api.pendingList(), "pending_list", undefined],
    ["pendingKeep", () => api.pendingKeep("p1"), "pending_keep", { id: "p1" }],
    ["pendingDiscard", () => api.pendingDiscard("p1"), "pending_discard", { id: "p1" }],
    ["pendingRename", () => api.pendingRename("p1", "Call with Acme"), "pending_rename", { id: "p1", title: "Call with Acme" }],
    ["pendingAssignClient", () => api.pendingAssignClient("p1", "c1"), "pending_assign_client", { id: "p1", clientId: "c1" }],
    ["pendingAssignClient (none)", () => api.pendingAssignClient("p1", null), "pending_assign_client", { id: "p1", clientId: null }],
    ["pendingSetLanguage", () => api.pendingSetLanguage("p1", "en+ur"), "pending_set_language", { id: "p1", language: "en+ur" }],
    ["setRecordingLanguage", () => api.setRecordingLanguage(null), "set_recording_language", { language: null }],
    ["retranscribe", () => api.retranscribe("s1", "ur"), "session_retranscribe", { id: "s1", language: "ur" }],
    ["pendingOpen", () => api.pendingOpen("p1", false), "pending_open", { id: "p1", rename: false }],
    ["pendingOpen (rename)", () => api.pendingOpen("p1", true), "pending_open", { id: "p1", rename: true }],
    ["reviewState", () => api.reviewState(), "review_state", undefined],
    ["uploadList", () => api.uploadList(), "upload_list", undefined],
    ["uploadRetry", () => api.uploadRetry("u1"), "upload_retry", { id: "u1" }],
    ["uploadDiscard", () => api.uploadDiscard("u1"), "upload_discard", { id: "u1" }],
    ["askAnswer (record)", () => api.askAnswer("a1", "record"), "ask_answer", { id: "a1", answer: "record" }],
    ["askAnswer (ignore)", () => api.askAnswer("a1", "ignore"), "ask_answer", { id: "a1", answer: "ignore" }],
    ["askAnswer (always ignore)", () => api.askAnswer("a1", "always_ignore"), "ask_answer", { id: "a1", answer: "always_ignore" }],
  ];

  it.each(cases)("%s invokes the matching Rust command with the exact argument object", async (_name, call, command, args) => {
    respond(command, "ok");
    await expect(call()).resolves.toBe("ok");
    expect(invoke).toHaveBeenCalledTimes(1);
    expect(invoke.mock.calls[0][0]).toBe(command);
    if (args === undefined) expect(invoke.mock.calls[0]).toHaveLength(1);
    else expect(invoke.mock.calls[0][1]).toEqual(args);
  });

  it("passes backend rejections through unchanged", async () => {
    invoke.mockRejectedValueOnce("Server returned 422: The pairing code is invalid.");
    await expect(api.pair("https://ledger.example.test", "bad", "")).rejects.toBe("Server returned 422: The pairing code is invalid.");
  });
});

describe("subscribeCaptions", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  it("sends a Channel as `onEvent` and routes its messages to the callback", async () => {
    respond("subscribe_captions", undefined);
    const received: unknown[] = [];
    await subscribeCaptions((e) => received.push(e));

    const [args] = callsOf("subscribe_captions");
    expect(Object.keys(args)).toEqual(["onEvent"]);
    expect(args.onEvent).toBeInstanceOf(Channel);

    (args.onEvent as Channel).onmessage({ kind: "clear" });
    expect(received).toEqual([{ kind: "clear" }]);
  });
});

describe("listenTo", () => {
  beforeEach(() => {
    resetTauriMock();
  });

  it("subscribes to the named event and hands the callback only the payload", async () => {
    const received: RecordingState[] = [];
    await listenTo("recording:state", (state) => received.push(state));
    expect(listen).toHaveBeenCalledWith("recording:state", expect.any(Function));

    await emitEvent("recording:state", makeRecordingState({ paused: true }));
    expect(received).toEqual([makeRecordingState({ paused: true })]);
  });

  it("accepts an explicit payload type for any event name", async () => {
    const received: number[] = [];
    await listenTo<number>("library:updated", (count) => received.push(count));
    await emitEvent("library:updated", 4);
    await emitEvent("settings:changed", makeSettings());
    expect(received).toEqual([4]);
  });

  it("resolves to an unlisten function that removes the listener", async () => {
    const unlisten = await listenTo("review:hide", () => undefined);
    expect(listenerCount("review:hide")).toBe(1);
    unlisten();
    expect(listenerCount("review:hide")).toBe(0);
  });
});

describe("errorMessage", () => {
  it("returns a string rejection as is, an Error's message, and a generic text for anything else", () => {
    expect(errorMessage("Network error: timed out")).toBe("Network error: timed out");
    expect(errorMessage(new Error("boom"))).toBe("boom");
    expect(errorMessage({ code: 500 })).toBe("Something went wrong");
    expect(errorMessage(undefined)).toBe("Something went wrong");
    expect(errorMessage(null)).toBe("Something went wrong");
  });
});
