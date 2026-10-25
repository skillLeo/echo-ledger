import { beforeEach, describe, expect, it, vi } from "vitest";
import { api, errorMessage, subscribeCaptions } from "./ipc";
import { Channel, callsOf, invoke, resetTauriMock, respond } from "../test/tauri";

vi.mock("@tauri-apps/api/core", async () => (await import("../test/tauri")).coreModule);

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

describe("errorMessage", () => {
  it("returns a string rejection as is, an Error's message, and a generic text for anything else", () => {
    expect(errorMessage("Network error: timed out")).toBe("Network error: timed out");
    expect(errorMessage(new Error("boom"))).toBe("boom");
    expect(errorMessage({ code: 500 })).toBe("Something went wrong");
    expect(errorMessage(undefined)).toBe("Something went wrong");
    expect(errorMessage(null)).toBe("Something went wrong");
  });
});
