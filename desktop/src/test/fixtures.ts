// Builders for the wire types the UI receives from the Rust core.
import type {
  AppStatus,
  AskPayload,
  LibraryItem,
  MicUser,
  NavigatePayload,
  PendingItem,
  RecordingInfo,
  RecordingState,
  ReviewPayload,
  Segment,
  Settings,
  SiteRule,
  TranscriptDetail,
  UploadItem,
} from "../lib/ipc";

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
    paused: false,
    elapsedMs: 0,
    pillHidden: false,
    pendingDecisions: 0,
    failedUploads: 0,
    trayState: "idle",
    budget: "ok",
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
    askBeforeSaving: "always",
    decisionTimeout: "keep",
    askUnknownApps: false,
    siteRules: [],
    captionLines: 3,
    pillPosition: null,
    browserAutomation: false,
    ...overrides,
  };
}

export function makeSiteRule(overrides: Partial<SiteRule> = {}): SiteRule {
  return {
    id: "user-1",
    app: "google_meet",
    host: "meet.example.test",
    titlePrefix: null,
    titleContains: null,
    titleEquals: null,
    priority: 100,
    enabled: true,
    ...overrides,
  };
}

/** A browser identified as Google Meet that auto-records (the explainer's happy path). */
export function makeMicUser(overrides: Partial<MicUser> = {}): MicUser {
  return {
    name: "chrome.exe",
    app: "google_meet",
    holdsMic: true,
    playsAudio: true,
    site: "meet.google.com",
    decision: "auto_record",
    reason: null,
    ...overrides,
  };
}

export function makeRecordingState(overrides: Partial<RecordingState> = {}): RecordingState {
  return {
    active: true,
    paused: false,
    elapsedMs: 65_000,
    sourceApp: "zoom",
    mode: "call",
    captions: false,
    levels: [0.4, 0.2],
    auto: true,
    hidden: false,
    ...overrides,
  };
}

/** A finished automatic Zoom call waiting for a decision, kept automatically in 60 s. */
export function makePendingItem(id = "pend-1", overrides: Partial<PendingItem> = {}): PendingItem {
  return {
    id,
    sourceApp: "zoom",
    auto: true,
    mode: "call",
    title: null,
    clientId: null,
    clientName: null,
    startedAt: "2026-10-24T09:00:00Z",
    endedAt: "2026-10-24T09:12:30Z",
    durationMs: 750_000,
    firstLine: "Thanks for joining, shall we start with the invoice?",
    deadlineAt: "2026-10-24T09:13:30Z",
    ...overrides,
  };
}

export function makeReviewPayload(overrides: Partial<ReviewPayload> = {}): ReviewPayload {
  return { item: makePendingItem(), timeout: "keep", queueLength: 1, ...overrides };
}

export function makeAskPayload(overrides: Partial<AskPayload> = {}): AskPayload {
  return { id: "ask-1", name: "chrome.exe", site: "call.example.test", seconds: 8, ...overrides };
}

export function makeUploadItem(id = "up-1", overrides: Partial<UploadItem> = {}): UploadItem {
  return {
    id,
    sourceApp: "zoom",
    title: null,
    startedAt: "2026-10-24T09:00:00Z",
    durationMs: 750_000,
    state: "queued",
    lastError: null,
    attempts: 0,
    ...overrides,
  };
}

export function makeNavigate(overrides: Partial<NavigatePayload> = {}): NavigatePayload {
  return { view: "library", pendingId: null, rename: false, ...overrides };
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
