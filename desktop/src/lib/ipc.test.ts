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
    ["micLastNotRecorded", () => api.micLastNotRecorded(), "mic_last_not_recorded", undefined],
    ["quitAndReopen", () => api.quitAndReopen(), "quit_and_reopen", undefined],
    ["uiFlags", () => api.uiFlags(), "ui_flags", undefined],
    ["trashKeepHours", () => api.trashKeepHours(), "trash_keep_hours", undefined],
    ["afterCallNoticeAnswer", () => api.afterCallNoticeAnswer(true), "after_call_notice_answer", { alwaysTranscribe: true }],
    ["uiClassicSet", () => api.uiClassicSet(true), "ui_classic_set", { classic: true }],
    ["assistPrepsList", () => api.assistPrepsList(), "assist_preps_list", { status: null }],
    ["assistPrepsList (scheduled)", () => api.assistPrepsList("scheduled"), "assist_preps_list", { status: "scheduled" }],
    ["assistPrepSave", () => api.assistPrepSave({ meeting_type: "interview", title: "Acme", counterpart: "", company: "", project_id: null, goals: "", done: "", not_done: "", to_discuss: "", instructions: "", hint_level: "outline", mode: "balanced", language_help: "off", scheduled_at: null }), "assist_prep_save", { prep: { meeting_type: "interview", title: "Acme", counterpart: "", company: "", project_id: null, goals: "", done: "", not_done: "", to_discuss: "", instructions: "", hint_level: "outline", mode: "balanced", language_help: "off", scheduled_at: null } }],
    ["assistPrepDelete", () => api.assistPrepDelete("p1"), "assist_prep_delete", { id: "p1" }],
    ["assistPrepDocumentsAdd", () => api.assistPrepDocumentsAdd("p1", ["f1", "f2"]), "assist_prep_documents_add", { id: "p1", fileIds: ["f1", "f2"] }],
    ["assistPrepDocumentRemove", () => api.assistPrepDocumentRemove("p1", "f1"), "assist_prep_document_remove", { id: "p1", fileId: "f1" }],
    ["assistSchedule", () => api.assistSchedule("p1", "2026-12-01T10:00:00Z"), "assist_schedule", { prepId: "p1", at: "2026-12-01T10:00:00Z" }],
    ["assistStart", () => api.assistStart("p1", "balanced"), "assist_start", { prepId: "p1", mode: "balanced", practice: false, clientRef: null }],
    ["assistStart (now, practice)", () => api.assistStart(null, null, true), "assist_start", { prepId: null, mode: null, practice: true, clientRef: null }],
    ["assistStart (an offer)", () => api.assistStart("p1", null, false, "rec-7"), "assist_start", { prepId: "p1", mode: null, practice: false, clientRef: "rec-7" }],
    ["assistEnd", () => api.assistEnd(), "assist_end", undefined],
    ["assistKeepRunning", () => api.assistKeepRunning(), "assist_keep_running", undefined],
    ["assistStartReplacing", () => api.assistStartReplacing("old", "p1"), "assist_start_replacing", { sessionId: "old", prepId: "p1", mode: null, practice: false, clientRef: null }],
    ["assistSend", () => api.assistSend("help_now"), "assist_send", { kind: "help_now" }],
    ["assistSet", () => api.assistSet({ muted_seconds: 60 }), "assist_set", { settings: { muted_seconds: 60 } }],
    ["assistFeedback", () => api.assistFeedback("s1", "used"), "assist_feedback", { suggestionId: "s1", action: "used" }],
    ["assistShown", () => api.assistShown("s1", 412), "assist_shown", { suggestionId: "s1", renderMs: 412 }],
    ["assistCardHide", () => api.assistCardHide(), "assist_card_hide", undefined],
    ["assistState", () => api.assistState(), "assist_state", undefined],
    ["importVoiceNotes", () => api.importVoiceNotes(["notes/a.opus", "notes/b.mp3"]), "import_voice_notes", { pathsList: ["notes/a.opus", "notes/b.mp3"], clientId: null, language: null }],
    ["importVoiceNotes (client, language)", () => api.importVoiceNotes(["clip.mp4"], "c1", "ur"), "import_voice_notes", { pathsList: ["clip.mp4"], clientId: "c1", language: "ur" }],
    ["pickImportFiles", () => api.pickImportFiles(), "import_pick", undefined],
    ["saveAudio", () => api.saveAudio("s1"), "save_audio", { id: "s1" }],
    ["copyLastMinutes (default)", () => api.copyLastMinutes(), "copy_last_minutes", { minutes: 2 }],
    ["copyLastMinutes (5)", () => api.copyLastMinutes(5), "copy_last_minutes", { minutes: 5 }],
    ["library", () => api.library(), "library_list", undefined],
    ["refreshLibrary", () => api.refreshLibrary(), "library_refresh", undefined],
    ["session", () => api.session("s1"), "session_detail", { id: "s1" }],
    ["transcript", () => api.transcript("t1"), "transcript_detail", { id: "t1" }],
    ["deleteSession", () => api.deleteSession("s1"), "delete_session", { id: "s1" }],
    ["clients", () => api.clients(), "list_clients", undefined],
    ["setHitRegions", () => api.setHitRegions([{ x: 1, y: 2, w: 3, h: 4 }], 1.5), "overlay_set_hit_regions", { regions: [{ x: 1, y: 2, w: 3, h: 4 }], scale: 1.5 }],
    ["snapOverlay", () => api.snapOverlay(), "overlay_snap", undefined],
    ["updateSettings (after a call)", () => api.updateSettings({ afterCall: "ask", captionLines: 5 }), "update_settings", { patch: { afterCall: "ask", captionLines: 5 } }],
    ["pauseRecording", () => api.pauseRecording(), "pause_recording", undefined],
    ["resumeRecording", () => api.resumeRecording(), "resume_recording", undefined],
    ["cancelRecording", () => api.cancelRecording(), "cancel_recording", undefined],
    ["setPillHidden (hide)", () => api.setPillHidden(true), "set_pill_hidden", { hidden: true }],
    ["setPillHidden (show)", () => api.setPillHidden(false), "set_pill_hidden", { hidden: false }],
    ["pillMoved", () => api.pillMoved(), "pill_moved", undefined],
    ["recordingState", () => api.recordingState(), "recording_state", undefined],
    ["pendingList", () => api.pendingList(), "pending_list", undefined],
    ["pendingKeep", () => api.pendingKeep("p1"), "pending_keep", { id: "p1" }],
    ["screensCaptureNow", () => api.screensCaptureNow(), "screens_capture_now", undefined],
    ["pendingKeepAudio", () => api.pendingKeepAudio("p1"), "pending_keep_audio", { id: "p1" }],
    ["pendingDiscard", () => api.pendingDiscard("p1"), "pending_discard", { id: "p1" }],
    ["pendingRename", () => api.pendingRename("p1", "Call with Acme"), "pending_rename", { id: "p1", title: "Call with Acme" }],
    ["pendingAssignClient", () => api.pendingAssignClient("p1", "c1"), "pending_assign_client", { id: "p1", clientId: "c1" }],
    ["pendingAssignClient (none)", () => api.pendingAssignClient("p1", null), "pending_assign_client", { id: "p1", clientId: null }],
    ["pendingSetLanguage", () => api.pendingSetLanguage("p1", "en+ur"), "pending_set_language", { id: "p1", language: "en+ur" }],
    ["setRecordingLanguage", () => api.setRecordingLanguage(null), "set_recording_language", { language: null }],
    ["retranscribe", () => api.retranscribe("s1", "ur"), "session_retranscribe", { id: "s1", language: "ur" }],
    ["transcriptViews", () => api.transcriptViews("t1"), "transcript_views", { id: "t1" }],
    ["makeTranscriptViews", () => api.makeTranscriptViews("t1"), "transcript_views_make", { id: "t1" }],
    ["pendingOpen", () => api.pendingOpen("p1", false), "pending_open", { id: "p1", rename: false }],
    ["pendingOpen (rename)", () => api.pendingOpen("p1", true), "pending_open", { id: "p1", rename: true }],
    ["afterCallState", () => api.afterCallState(), "after_call_state", undefined],
    ["savedUndo", () => api.savedUndo("rec-1"), "saved_undo", { id: "rec-1" }],
    ["skippedList", () => api.skippedList(), "skipped_list", undefined],
    ["recoveredSessions", () => api.recoveredSessions(), "recovered_sessions", undefined],
    ["afterCallDiscard", () => api.afterCallDiscard("r1"), "after_call_discard", { id: "r1" }],
    ["afterCallDiscardUndo", () => api.afterCallDiscardUndo("r1"), "after_call_discard_undo", { id: "r1" }],
    ["skippedRestore", () => api.skippedRestore("skip-1"), "skipped_restore", { id: "skip-1" }],
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

  it("never gives an empty message: an empty or blank rejection, or an Error without one, reads as the generic text", () => {
    expect(errorMessage("")).toBe("Something went wrong");
    expect(errorMessage("   ")).toBe("Something went wrong");
    expect(errorMessage(new Error(""))).toBe("Something went wrong");
    expect(errorMessage({ message: "Not allowed" })).toBe("Not allowed");
    expect(errorMessage({ message: "" })).toBe("Something went wrong");
  });
});
