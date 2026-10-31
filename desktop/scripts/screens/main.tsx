// A fixture mode for screenshots: the real views in a plain browser, with Tauri's own IPC mock answering every command
// from sample data (data.ts). `?screen=library|library-empty|clients|record|activity|settings|controls&theme=light|dark`.
import React from "react";
import ReactDOM from "react-dom/client";
import { clearMocks, mockIPC, mockWindows } from "@tauri-apps/api/mocks";
import "../../src/styles.css";
import "../../src/views/controls.css";
import { makeMicUser, makeRecordingState, makeSettings, makeStatus } from "../../src/test/fixtures";
import { ACTIVITY, ANSWER, CLIENTS, ITEMS, NOTES_R5, PENDING, TRANSCRIPTS, VIEWS, WORKSPACE } from "./data";

const params = new URLSearchParams(location.search);
const screen = params.get("screen") ?? "library";
const theme = params.get("theme") === "dark" ? "dark" : "light";
localStorage.setItem("echoledger-theme", theme);
document.documentElement.dataset.theme = theme;
document.documentElement.style.colorScheme = theme;
document.documentElement.dataset.window = screen === "controls" ? "controls" : "main";

/** One second of silence, so the player has something to load. */
function silentWav(): string {
  const rate = 8000;
  const data = new Uint8Array(rate).fill(128);
  const header = new DataView(new ArrayBuffer(44));
  const text = (at: number, s: string) => [...s].forEach((c, i) => header.setUint8(at + i, c.charCodeAt(0)));
  text(0, "RIFF"); header.setUint32(4, 36 + data.length, true); text(8, "WAVE"); text(12, "fmt ");
  header.setUint32(16, 16, true); header.setUint16(20, 1, true); header.setUint16(22, 1, true); header.setUint32(24, rate, true);
  header.setUint32(28, rate, true); header.setUint16(32, 1, true); header.setUint16(34, 8, true); text(36, "data"); header.setUint32(40, data.length, true);
  return URL.createObjectURL(new Blob([header.buffer, data], { type: "audio/wav" }));
}
const audioUrl = silentWav();

const empty = screen === "library-empty";
const tab = screen.startsWith("library") ? "library" : screen.startsWith("client") ? "clients" : screen;
type Args = Record<string, unknown>;
let captions: { onmessage: (e: unknown) => void } | null = null;

const commands: Record<string, (args: Args) => unknown> = {
  app_status: () => makeStatus({ deviceName: "Work laptop", pendingDecisions: empty ? 0 : PENDING.length, captionsLive: screen === "controls" }),
  get_settings: () => makeSettings({ deviceName: "Work laptop", consentAcknowledged: true }),
  main_view_get: () => JSON.stringify({ tab, recordingId: empty || tab !== "library" ? null : "r5", scroll: {} }),
  lock_status: () => ({ enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 }),
  library_list: () => (empty ? [] : ITEMS),
  library_refresh: () => (empty ? [] : ITEMS),
  list_clients: () => ({ data: CLIENTS }),
  session_detail: ({ id }) => ({ id, playback_url: audioUrl, markers: id === "r5" ? [{ id: "mk1", at_ms: 95_000, tag: "Budget", note: "Phase one estimate" }] : [], speaker_names: { Me: "You", Client: "Sara (Acme)" } }),
  transcript_detail: ({ id }) => TRANSCRIPTS[id as string] ?? { ...TRANSCRIPTS["t-r5"], id, suggestions: [], views: null },
  transcript_views: () => VIEWS,
  unsure_markers: () => null,
  pending_list: () => (empty ? [] : PENDING),
  upload_list: () => [],
  activity_list: () => ({ data: ACTIVITY, unread_count: ACTIVITY.filter((a) => !a.read).length }),
  search_library: () => ({ data: [], next_page: null }),
  session_notes: ({ id }) => (id === "r5" ? { data: NOTES_R5 } : null),
  client_workspace: ({ id }) => ({ data: id === "c-acme" ? WORKSPACE : { ...WORKSPACE, client: { ...WORKSPACE.client, id, name: CLIENTS.find((c) => c.id === id)?.name ?? "" }, brief: null, memory: [], notes: [], files: [] } }),
  client_ask: () => ({ data: ANSWER }),
  mic_users: () => [makeMicUser(), makeMicUser({ name: "Code.exe", app: null, playsAudio: false, site: null, decision: "ignored", reason: "dictation" })],
  translate_apps: () => [],
  translate_status: () => null,
  input_devices: () => [{ id: "default", name: "Microphone (Realtek Audio)", isDefault: true }],
  permissions_status: () => ({ microphone: "allowed", browserAutomation: false, platform: "windows" }),
  security_overview: () => ({ devices: [{ id: "d1", name: "Work laptop", platform: "windows", app_version: "1.3.0", last_seen_at: new Date().toISOString(), paired_at: null, current: true }, { id: "d2", name: "Pixel 9", platform: "android", app_version: "1.3.0", last_seen_at: new Date().toISOString(), paired_at: null, current: false }], events: [] }),
  storage_info: () => ({ recordings_bytes: 812_000_000, recordings_count: 42, disk_free_bytes: 80_123_456_789, disk_total_bytes: 107_374_182_400, retention_days: 30 }),
  dictation_history: () => [],
  marker_tags: () => ["Budget", "Decision", "Follow up"],
  recording_state: () => makeRecordingState({ elapsedMs: 134_000, sourceApp: "whatsapp", language: "ur" }),
  review_state: () => ({ item: { ...PENDING[0], durationMs: 272_000 }, timeout: "keep", queueLength: 1 }),
  subscribe_captions: ({ onEvent }) => {
    captions = onEvent as typeof captions;
  },
  "plugin:app|version": () => "1.3.0",
};

mockWindows("main");
mockIPC((cmd, args) => (commands[cmd] ?? (() => null))((args ?? {}) as Args), { shouldMockEvents: true });
window.addEventListener("beforeunload", () => clearMocks());

async function render() {
  const root = ReactDOM.createRoot(document.getElementById("root")!);
  if (screen !== "controls") {
    const { default: MainApp } = await import("../../src/views/main/MainApp");
    root.render(<React.StrictMode><MainApp /></React.StrictMode>);
    return;
  }
  // The floating windows side by side on one canvas: the recording pill, the save card and the captions.
  const [{ default: PillApp }, { default: ReviewApp }, { default: OverlayApp }] = await Promise.all([
    import("../../src/views/pill/PillApp"), import("../../src/views/review/ReviewApp"), import("../../src/views/overlay/OverlayApp"),
  ]);
  const { emit } = await import("@tauri-apps/api/event");
  root.render(
    <div className="screens-canvas">
      <div className="screens-window" style={{ width: 352, height: 84 }}><PillApp /></div>
      <div className="screens-window" style={{ width: 360, height: 168 }}><ReviewApp /></div>
      <div className="screens-window" style={{ width: 560, height: 150 }}><OverlayApp /></div>
    </div>,
  );
  window.setTimeout(() => {
    void emit("pill:visibility", true);
    captions?.onmessage({ kind: "status", live: true, recording: true, provider: "assemblyai", message: null });
    captions?.onmessage({ kind: "final", speaker: "Client", channel: 2, text: "Can you send the estimate for phase one by Monday?", startMs: 82_000, endMs: 95_000 });
    captions?.onmessage({ kind: "final", speaker: "Me", channel: 1, text: "Yes, I will send it on Monday with the timeline.", startMs: 97_000, endMs: 108_000 });
    captions?.onmessage({ kind: "partial", speaker: "Client", channel: 2, text: "One change: we want guest checkout as well" });
  }, 300);
}
void render();
