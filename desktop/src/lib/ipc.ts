// Typed wrappers around the Rust commands (src-tauri/src/commands.rs). Tokens never reach the webview.
import { Channel, invoke } from "@tauri-apps/api/core";
import { listen, type UnlistenFn } from "@tauri-apps/api/event";

export type ShortcutAction = "toggle_captions" | "toggle_saving" | "copy_last2_min" | "dictation" | "voice_note";
export type Corner = "top_left" | "top_right" | "bottom_left" | "bottom_right";
export type AskBeforeSaving = "always" | "auto_only" | "never";
export type DecisionTimeout = "keep" | "discard" | "wait";
export type TrayState = "idle" | "recording" | "paused" | "uploading" | "attention";
export type BudgetState = "ok" | "warn" | "reached";
export type AskAnswer = "record" | "ignore" | "always_ignore";
export type MicDecision = "auto_record" | "recording" | "ignored" | "ask" | "off";
export type IgnoreReason = "dictation" | "unknown_site" | "unknown_app" | "never_record" | "auto_off";
export type UploadState = "queued" | "uploading" | "verifying" | "failed";

export interface RecordingInfo {
  clientRef: string;
  mode: "call" | "voice_note" | "mic_note";
  sourceApp: string;
  clientId: string | null;
  startedAt: string;
  backend: "process_loopback" | "endpoint_loopback" | "core_audio_tap" | "mock";
}

export interface AppStatus {
  paired: boolean;
  serverUrl: string | null;
  deviceName: string | null;
  pinned: boolean;
  recording: RecordingInfo | null;
  captionsLive: boolean;
  dictationActive: boolean;
  autoMode: boolean;
  pendingUploads: number;
  uploadErrors: string[];
  shortcutErrors: [ShortcutAction, string][];
  levels: [number, number];
  platform: "windows" | "macos" | "other";
  paused: boolean;
  elapsedMs: number;
  pillHidden: boolean;
  pendingDecisions: number;
  failedUploads: number;
  trayState: TrayState;
  budget: BudgetState;
}

export interface Settings {
  serverUrl: string | null;
  pins: string[];
  deviceId: string | null;
  deviceName: string | null;
  shortcuts: Partial<Record<ShortcutAction, string>>;
  captionsOnRecord: boolean;
  autoMode: boolean;
  contentProtection: boolean;
  overlayCorner: Corner;
  autostart: boolean;
  dictationOutput: "type" | "clipboard";
  clientId: string | null;
  consentAcknowledged: boolean;
  askBeforeSaving: AskBeforeSaving;
  decisionTimeout: DecisionTimeout;
  askUnknownApps: boolean;
  siteRules: SiteRule[];
  captionLines: number;
  pillPosition: PillPosition | null;
  browserAutomation: boolean;
}

/**
 * The settings `update_settings` accepts (Rust `SettingsPatch`); a missing key keeps the stored value.
 * `clientId: null` clears the client, `siteRules` replaces every user rule, `captionLines` is 2, 3 or 5.
 * `pillPosition` is saved only by `pillMoved`.
 */
export interface SettingsPatch {
  captionsOnRecord?: boolean;
  autoMode?: boolean;
  contentProtection?: boolean;
  autostart?: boolean;
  dictationOutput?: "type" | "clipboard";
  clientId?: string | null;
  consentAcknowledged?: boolean;
  overlayCorner?: Corner;
  askBeforeSaving?: AskBeforeSaving;
  decisionTimeout?: DecisionTimeout;
  askUnknownApps?: boolean;
  siteRules?: SiteRule[];
  captionLines?: number;
  browserAutomation?: boolean;
}

/**
 * A process holding the microphone and why EchoLedger does or does not record it (Record view explainer).
 * `app` is the call app it is identified as, `site` the matched browser host or rule id.
 */
export interface MicUser {
  name: string;
  app: string | null;
  holdsMic: boolean;
  playsAudio: boolean;
  site: string | null;
  decision: MicDecision;
  reason: IgnoreReason | null;
}

export interface LibraryItem {
  id: string;
  deleted: boolean;
  updated_at: string;
  client_ref?: string;
  source_device?: "phone" | "desktop" | "web";
  source_device_label?: string;
  source_device_icon?: string;
  source_app?: string;
  source_app_label?: string;
  device?: { id: string; name: string } | null;
  client?: { id: string; name: string } | null;
  title?: string;
  started_at?: string;
  duration_ms?: number | null;
  status?: string;
  transcript?: {
    final_status: string | null;
    final_transcript_id: string | null;
    live_available: boolean;
    live_transcript_id?: string | null;
    preview: string | null;
  };
}

export interface Segment {
  channel: number;
  speaker: string;
  start_ms: number;
  end_ms: number;
  text: string;
}

export interface TranscriptDetail {
  id: string;
  kind: "live" | "final";
  provider: string;
  status: string;
  language: string | null;
  text: string | null;
  segments: Segment[];
  /** Set only when `status` is `failed`: the reason, written for the user. */
  error?: string | null;
}

export type CaptionEvent =
  | { kind: "partial"; speaker: string; channel: number; text: string }
  | { kind: "final"; speaker: string; channel: number; text: string; startMs: number; endMs: number }
  | { kind: "status"; live: boolean; recording: boolean; provider: string | null; message: string | null }
  | { kind: "clear" };

export interface RectPx {
  x: number;
  y: number;
  w: number;
  h: number;
}

/** A call-site rule. Settings store only user rules (built-ins live in the core); `app: "never"` = never record. */
export interface SiteRule {
  id: string;
  app: string;
  host: string | null;
  titlePrefix: string | null;
  titleContains: string | null;
  titleEquals: string | null;
  priority: number;
  enabled: boolean;
}

/** Physical desktop pixels of the recording pill's top-left corner. */
export interface PillPosition {
  x: number;
  y: number;
}

/** A finished recording waiting for Keep / Discard. Times are RFC 3339 UTC; `deadlineAt` is null for "Keep waiting". */
export interface PendingItem {
  id: string;
  sourceApp: string;
  auto: boolean;
  mode: "call" | "voice_note" | "mic_note";
  title: string | null;
  clientId: string | null;
  clientName: string | null;
  startedAt: string;
  endedAt: string;
  durationMs: number;
  firstLine: string | null;
  deadlineAt: string | null;
}

/** A kept recording in the upload queue. */
export interface UploadItem {
  id: string;
  sourceApp: string;
  title: string | null;
  startedAt: string;
  durationMs: number;
  state: UploadState;
  lastError: string | null;
  attempts: number;
}

/** What the recording pill shows; sent on every change and once a second while recording. */
export interface RecordingState {
  active: boolean;
  paused: boolean;
  elapsedMs: number;
  sourceApp: string | null;
  mode: "call" | "voice_note" | "mic_note" | null;
  captions: boolean;
  levels: [number, number];
  auto: boolean;
  hidden: boolean;
}

/** What the review card shows: the newest pending item of this run and the timeout policy. */
export interface ReviewPayload {
  item: PendingItem;
  timeout: DecisionTimeout;
  queueLength: number;
}

/** The unknown-app chip: `name` is the process, `site` the browser host when known, `seconds` how long it stays. */
export interface AskPayload {
  id: string;
  name: string;
  site: string | null;
  seconds: number;
}

export interface NavigatePayload {
  view: "library" | "record" | "settings";
  pendingId: string | null;
  rename: boolean;
}

/** Events the core emits to the webviews and the payload each one carries (none = `null`). */
export interface EventPayloads {
  "app:status": AppStatus;
  "app:error": string;
  "app:toast": string;
  "library:updated": number;
  "overlay:visibility": boolean;
  "settings:changed": Settings;
  "recording:state": RecordingState;
  "pill:visibility": boolean;
  "pending:changed": PendingItem[];
  "review:show": ReviewPayload;
  "review:hide": null;
  "ask:show": AskPayload;
  "ask:hide": null;
  "uploads:changed": UploadItem[];
  "main:navigate": NavigatePayload;
}

export type AppEvent = keyof EventPayloads;

export const api = {
  status: () => invoke<AppStatus>("app_status"),
  pair: (serverUrl: string, code: string, deviceName: string) => invoke<AppStatus>("pair_device", { serverUrl, code, deviceName }),
  unpair: () => invoke<AppStatus>("unpair"),
  settings: () => invoke<Settings>("get_settings"),
  updateSettings: (patch: SettingsPatch) => invoke<Settings>("update_settings", { patch }),
  setShortcut: (action: ShortcutAction, accelerator: string) => invoke<Settings>("set_shortcut", { action, accelerator }),
  resetShortcuts: () => invoke<Settings>("reset_shortcuts"),
  toggleCaptions: () => invoke<AppStatus>("toggle_captions"),
  startRecording: (sourceApp?: string) => invoke<AppStatus>("start_recording", { sourceApp: sourceApp ?? null }),
  stopRecording: () => invoke<AppStatus>("stop_recording"),
  toggleVoiceNote: () => invoke<AppStatus>("toggle_voice_note"),
  toggleMicNote: () => invoke<AppStatus>("toggle_mic_note"),
  hideCaptions: () => invoke<AppStatus>("hide_captions"),
  micUsers: () => invoke<MicUser[]>("mic_users"),
  importVoiceNotes: (pathsList: string[]) => invoke<number>("import_voice_notes", { pathsList }),
  copyLastMinutes: (minutes = 2) => invoke<string>("copy_last_minutes", { minutes }),
  library: () => invoke<LibraryItem[]>("library_list"),
  refreshLibrary: () => invoke<LibraryItem[]>("library_refresh"),
  session: (id: string) => invoke<Record<string, unknown>>("session_detail", { id }),
  transcript: (id: string) => invoke<TranscriptDetail>("transcript_detail", { id }),
  deleteSession: (id: string) => invoke<void>("delete_session", { id }),
  clients: () => invoke<{ data: { id: string; name: string; keyterm_count: number }[] }>("list_clients"),
  setHitRegions: (regions: RectPx[]) => invoke<void>("overlay_set_hit_regions", { regions }),
  snapOverlay: () => invoke<void>("overlay_snap"),
  pauseRecording: () => invoke<AppStatus>("pause_recording"),
  resumeRecording: () => invoke<AppStatus>("resume_recording"),
  cancelRecording: () => invoke<AppStatus>("cancel_recording"),
  setPillHidden: (hidden: boolean) => invoke<AppStatus>("set_pill_hidden", { hidden }),
  pillMoved: () => invoke<void>("pill_moved"),
  recordingState: () => invoke<RecordingState>("recording_state"),
  pendingList: () => invoke<PendingItem[]>("pending_list"),
  pendingKeep: (id: string) => invoke<void>("pending_keep", { id }),
  pendingDiscard: (id: string) => invoke<void>("pending_discard", { id }),
  pendingRename: (id: string, title: string) => invoke<PendingItem>("pending_rename", { id, title }),
  pendingAssignClient: (id: string, clientId: string | null) => invoke<PendingItem>("pending_assign_client", { id, clientId }),
  pendingOpen: (id: string, rename: boolean) => invoke<void>("pending_open", { id, rename }),
  reviewState: () => invoke<ReviewPayload | null>("review_state"),
  uploadList: () => invoke<UploadItem[]>("upload_list"),
  uploadRetry: (id: string) => invoke<void>("upload_retry", { id }),
  uploadDiscard: (id: string) => invoke<void>("upload_discard", { id }),
  askAnswer: (id: string, answer: AskAnswer) => invoke<void>("ask_answer", { id, answer }),
};

/**
 * Listens to a core event and hands the callback only its payload. Resolves to the unlisten function.
 * With a known event name the payload type is inferred; `listenTo<T>(name, cb)` works for any event.
 */
export function listenTo<E extends AppEvent>(event: E, cb: (payload: EventPayloads[E]) => void): Promise<UnlistenFn>;
export function listenTo<T>(event: string, cb: (payload: T) => void): Promise<UnlistenFn>;
export function listenTo(event: string, cb: (payload: unknown) => void): Promise<UnlistenFn> {
  return listen<unknown>(event, (e) => cb(e.payload));
}

/** Subscribes this window to caption events (replaces any previous subscription of the same window). */
export async function subscribeCaptions(onEvent: (e: CaptionEvent) => void): Promise<void> {
  const channel = new Channel<CaptionEvent>(onEvent);
  await invoke("subscribe_captions", { onEvent: channel });
}

export function errorMessage(e: unknown): string {
  if (typeof e === "string") return e;
  if (e instanceof Error) return e.message;
  return "Something went wrong";
}
