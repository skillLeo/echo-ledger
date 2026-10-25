// Builders for the wire types the UI receives from the Rust core.
import type { AppStatus, LibraryItem, RecordingInfo, Segment, Settings, TranscriptDetail } from "../lib/ipc";

export function makeStatus(overrides: Partial<AppStatus> = {}): AppStatus {
  return {
    paired: true,
    serverUrl: "https://ledger.example.test",
    deviceName: "Work laptop",
    pinned: true,
    recording: null,
    captionsLive: false,
    dictationActive: false,
    autoMode: true,
    pendingUploads: 0,
    uploadErrors: [],
    shortcutErrors: [],
    levels: [0, 0],
    platform: "windows",
    ...overrides,
  };
}

export function makeRecording(overrides: Partial<RecordingInfo> = {}): RecordingInfo {
  return {
    clientRef: "ref-1",
    mode: "call",
    sourceApp: "zoom",
    clientId: null,
    startedAt: "2026-10-24T09:00:00Z",
    backend: "process_loopback",
    ...overrides,
  };
}

export function makeSettings(overrides: Partial<Settings> = {}): Settings {
  return {
    serverUrl: "https://ledger.example.test",
    pins: ["sha256/abc"],
    deviceId: "dev-1",
    deviceName: "Work laptop",
    shortcuts: {
      toggle_captions: "CommandOrControl+Shift+Space",
      toggle_saving: "CommandOrControl+Shift+R",
      copy_last2_min: "CommandOrControl+Shift+C",
      dictation: "CommandOrControl+Shift+D",
    },
    captionsOnRecord: true,
    autoMode: true,
    contentProtection: true,
    overlayCorner: "bottom_right",
    autostart: false,
    dictationOutput: "type",
    clientId: null,
    consentAcknowledged: false,
    ...overrides,
  };
}

export function makeItem(id: string, overrides: Partial<LibraryItem> = {}): LibraryItem {
  return {
    id,
    deleted: false,
    updated_at: "2026-10-24T08:00:00Z",
    source_device: "desktop",
    source_device_label: "Desktop",
    source_app: "zoom",
    source_app_label: "Zoom",
    title: `Recording ${id}`,
    started_at: "2026-10-24T08:00:00Z",
    duration_ms: 125_000,
    status: "completed",
    transcript: { final_status: "completed", final_transcript_id: `tr-${id}`, live_available: false, preview: null },
    ...overrides,
  };
}

export const CALL_SEGMENTS: Segment[] = [
  { channel: 1, speaker: "Me", start_ms: 0, end_ms: 4_000, text: "Hello, this is Sam." },
  { channel: 2, speaker: "Client", start_ms: 12_400, end_ms: 15_000, text: "Hi Sam, thanks for calling back." },
  { channel: 1, speaker: "Me", start_ms: 65_000, end_ms: 70_000, text: "Let us go over the invoice." },
  { channel: 2, speaker: "Client", start_ms: 3_725_000, end_ms: 3_730_000, text: "That works for us." },
];

export function makeTranscript(overrides: Partial<TranscriptDetail> = {}): TranscriptDetail {
  return {
    id: "tr-1",
    kind: "final",
    provider: "assemblyai",
    status: "completed",
    language: "en",
    text: null,
    segments: CALL_SEGMENTS,
    ...overrides,
  };
}
