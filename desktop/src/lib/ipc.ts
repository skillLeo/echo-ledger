// Typed wrappers around the Rust commands (src-tauri/src/commands.rs). Tokens never reach the webview.
import { Channel, invoke } from "@tauri-apps/api/core";
import { listen, type UnlistenFn } from "@tauri-apps/api/event";

export type ShortcutAction = "toggle_captions" | "toggle_saving" | "copy_last2_min" | "dictation" | "voice_note" | "mark";
export type Corner = "top_left" | "top_right" | "bottom_left" | "bottom_right";
export type AskBeforeSaving = "always" | "auto_only" | "never";
export type DecisionTimeout = "keep" | "discard" | "wait";
export type TrayState = "idle" | "recording" | "paused" | "uploading" | "attention";
export type BudgetState = "ok" | "warn" | "reached";
export type AskAnswer = "record" | "ignore" | "always_ignore";
export type MicDecision = "auto_record" | "recording" | "ignored" | "ask" | "off";
export type IgnoreReason = "dictation" | "unknown_site" | "unknown_app" | "never_record" | "auto_off" | "app_off";
export type CaptionSize = "small" | "medium" | "large";
export type CaptionWidth = "narrow" | "normal" | "wide";
export type PermissionState = "allowed" | "denied" | "unknown";
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
  autoRecordApps: Record<string, boolean>;
  captionStyle: CaptionStyle;
  inputDevice: string | null;
  farSideWarning: boolean;
  transcriptionLanguage: TranscriptionLanguage | null;
  dictationMode: DictationMode;
  dictationCleanup: boolean;
  dictationSounds: boolean;
  dictationReplacements: DictationReplacement[];
  dictationSnippets: DictationSnippet[];
  appLock: boolean;
  lockAfterMinutes: LockAfterMinutes;
}

/** App lock (B16): Windows Hello / Touch ID or the PIN; 0 minutes = only when the app starts. */
export type LockAfterMinutes = 0 | 1 | 5 | 15 | 60;
export interface LockStatus {
  enabled: boolean;
  locked: boolean;
  hasPin: boolean;
  osMethod: "windows_hello" | "touch_id" | null;
  retryAfterSeconds: number;
}
export interface SecurityDevice {
  id: string;
  name: string;
  platform: string;
  app_version: string | null;
  last_seen_at: string | null;
  paired_at: string | null;
  current: boolean;
}
export interface SecurityEvent {
  id: number;
  event: string;
  label: string;
  summary: string;
  actor: "web" | "device" | "system";
  device: string | null;
  at: string;
}

/** `toggle`: press to start and press again to insert; `push_to_talk`: hold the shortcut while speaking. */
export type DictationMode = "toggle" | "push_to_talk";
/** Dictionary and replacements: when I say `from`, write `to`. */
export interface DictationReplacement {
  from: string;
  to: string;
}
/** Saying `trigger` inserts `text`. */
export interface DictationSnippet {
  trigger: string;
  text: string;
}
export interface DictationHistoryEntry {
  at: string;
  text: string;
}

/** Languages offered for new recordings; null is automatic (the client's or the console default). */
export type TranscriptionLanguage = "en" | "ur" | "hi" | "en+ur";

/** How the caption overlay looks. utoHideSeconds is 0 (never), 5, 10 or 30; colours are #rrggbb or null (theme). */
export interface CaptionStyle {
  size: CaptionSize;
  width: CaptionWidth;
  opacity: number;
  compact: boolean;
  autoHideSeconds: number;
  meColor: string | null;
  clientColor: string | null;
}

/**
 * The settings `update_settings` accepts (Rust `SettingsPatch`); a missing key keeps the stored value.
 * `clientId: null` clears the client, `siteRules` replaces every user rule, `captionLines` is 2, 3 or 5.
 * `pillPosition` is saved only by `pillMoved`. `autoRecordApps` merges per app; `captionStyle` replaces the style;
 * `inputDevice: null` follows the system default microphone.
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
  autoRecordApps?: Record<string, boolean>;
  captionStyle?: CaptionStyle;
  inputDevice?: string | null;
  farSideWarning?: boolean;
  transcriptionLanguage?: TranscriptionLanguage | null;
  dictationMode?: DictationMode;
  dictationCleanup?: boolean;
  dictationSounds?: boolean;
  dictationReplacements?: DictationReplacement[];
  dictationSnippets?: DictationSnippet[];
  appLock?: boolean;
  lockAfterMinutes?: LockAfterMinutes;
}

/** A bookmark just made on the current recording (`recording:marked`); `count` is how many it has now. */
export interface MarkPayload {
  atMs: number;
  tag: string | null;
  note: string | null;
  count: number;
}

/** A microphone for the device picker; `id` is what `inputDevice` stores. */
export interface InputDevice {
  id: string;
  name: string;
  isDefault: boolean;
}

/** The 10-second test recording: loudest 100 ms per side (0-1), whether each side was heard, and a WAV to play. */
export interface TestResult {
  seconds: number;
  micPeak: number;
  systemPeak: number;
  micHeard: boolean;
  systemHeard: boolean;
  wavBase64: string;
}

export interface PermissionsStatus {
  microphone: PermissionState;
  browserAutomation: boolean;
  platform: "windows" | "macos" | "other";
}

/** `capture:far_side`: the other side has been silent for `seconds` (`silent`) or is heard again. */
export interface FarSidePayload {
  silent: boolean;
  seconds: number;
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
  client?: { id: string; name: string; color?: string | null; avatar?: string | null } | null;
  title?: string;
  started_at?: string;
  duration_ms?: number | null;
  status?: string;
  /** Bookmarks on the recording and the distinct tags among them (since v1.1). */
  marker_count?: number;
  tags?: string[];
  /** Pinned to the top and recording labels (since v1.1, B15). */
  pinned?: boolean;
  labels?: string[];
  /** Never deleted by retention (B17). */
  keep?: boolean;
  transcript?: {
    final_status: string | null;
    final_transcript_id: string | null;
    live_available: boolean;
    live_transcript_id?: string | null;
    preview: string | null;
  };
}

/** A client folder (B15): `avatar` is one emoji or two letters; pinned folders come first. */
export interface ClientFolder {
  id: string;
  name: string;
  color?: string | null;
  avatar?: string | null;
  pinned?: boolean;
  keyterm_count?: number;
  session_count?: number;
}
export interface SessionOrganisePatch {
  title?: string | null;
  client_id?: string | null;
  pinned?: boolean;
  labels?: string[];
  /** Never deleted by retention (B17). */
  keep?: boolean;
}
export type BulkAction = "move" | "label" | "unlabel" | "pin" | "unpin" | "keep" | "unkeep" | "delete";
/** A recording in the 48-hour undo bin (B17). */
export interface BinItem {
  id: string;
  title: string;
  client: { id: string; name: string } | null;
  started_at: string;
  duration_ms: number | null;
  deleted_at: string;
  erase_at: string;
}
export interface StorageInfo {
  recordings_bytes: number;
  recordings_count: number;
  disk_free_bytes: number | null;
  disk_total_bytes: number | null;
  retention_days: number;
}
export type BulkExportFormat = "txt" | "md" | "srt" | "vtt" | "json" | "docx";

export interface Segment {
  channel: number;
  speaker: string;
  start_ms: number;
  end_ms: number;
  text: string;
  /** Word timings when the engine gave them (an edited line has none). */
  words?: { text: string; start_ms: number; end_ms: number }[];
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
  /** Machine-readable reason when `status` is `failed`, e.g. `no_audio` or `budget_exceeded`. */
  error_code?: string | null;
  /** The model the engine reports it used, e.g. `universal-2`. */
  model?: string | null;
  /** Set while the text differs from the engine's original. */
  edited_at?: string | null;
}

/** A transcript search (`GET /api/search`); empty filters are not sent. */
export interface SearchQuery {
  q: string;
  clientId?: string | null;
  sourceApp?: string | null;
  sourceDevice?: string | null;
  from?: string | null;
  to?: string | null;
  tag?: string | null;
  bookmarked?: boolean;
  page?: number;
}

/** One matching line: jump to `start_ms`; `highlights` are [start, length] ranges in `snippet`. */
export interface SearchHit {
  transcript_id: string;
  kind: string;
  segment_index: number;
  start_ms: number;
  end_ms: number;
  speaker: string;
  snippet: string;
  highlights: [number, number][];
}

export interface SearchResult {
  data: { session: LibraryItem; hits: SearchHit[]; hit_count: number }[];
  next_page: number | null;
}

/** A share link of a recording; `url` is only present right after it was made. */
export interface ShareLink {
  id: string;
  session_id: string;
  has_password: boolean;
  include_audio: boolean;
  expires_at: string | null;
  revoked_at: string | null;
  active: boolean;
  view_count: number;
  last_viewed_at: string | null;
  created_by: string | null;
  created_at: string;
  url?: string;
}

export interface TranscriptRevision {
  id: string;
  source: "original" | "edit" | "replace" | "restore";
  author: string | null;
  created_at: string;
}

export type ExportFormat = "docx" | "html" | "txt" | "md" | "srt" | "vtt" | "json";

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
  "recording:marked": MarkPayload;
  "capture:far_side": FarSidePayload;
  "capture:test_level": [number, number];
  "dictation:state": { active: boolean; sounds: boolean; mode: DictationMode };
  "dictation:level": number;
  "lock:changed": LockStatus;
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
  clients: () => invoke<{ data: ClientFolder[] }>("list_clients"),
  organiseSession: (id: string, patch: SessionOrganisePatch) => invoke<{ data: LibraryItem }>("session_organise", { id, patch }),
  bulkSessions: (ids: string[], action: BulkAction, clientId: string | null = null, label: string | null = null) =>
    invoke<number>("sessions_bulk", { ids, action, clientId, label }),
  exportSessions: (ids: string[], format: BulkExportFormat) => invoke<string>("sessions_export", { ids, format }),
  createClient: (name: string, color: string | null = null, avatar: string | null = null) => invoke<{ data: ClientFolder }>("client_create", { name, color, avatar }),
  updateClient: (id: string, patch: Partial<Pick<ClientFolder, "name" | "color" | "avatar" | "pinned">>) => invoke<{ data: ClientFolder }>("client_update", { id, patch }),
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
  markMoment: (tag: string | null = null, note: string | null = null) => invoke<MarkPayload>("mark_moment", { tag, note }),
  tagLastMark: (tag: string | null, note: string | null = null) => invoke<void>("tag_last_mark", { tag, note }),
  undoMark: () => invoke<void>("undo_mark"),
  markerTags: () => invoke<string[]>("marker_tags"),
  inputDevices: () => invoke<InputDevice[]>("input_devices"),
  testCapture: (seconds?: number) => invoke<TestResult>("test_capture", { seconds: seconds ?? null }),
  permissionsStatus: () => invoke<PermissionsStatus>("permissions_status"),
  openPrivacySettings: (kind: "microphone" | "system_audio" | "automation") => invoke<void>("open_privacy_settings", { kind }),
  searchLibrary: (query: SearchQuery) => invoke<SearchResult>("search_library", { query }),
  editTranscript: (id: string, index: number, text: string) => invoke<TranscriptDetail>("transcript_edit", { id, index, text }),
  replaceInTranscript: (id: string, find: string, replace: string, matchCase: boolean, wholeWord: boolean) =>
    invoke<{ replaced: number; transcript: TranscriptDetail }>("transcript_replace", { id, find, replace, matchCase, wholeWord }),
  restoreTranscript: (id: string) => invoke<TranscriptDetail>("transcript_restore", { id }),
  transcriptRevisions: (id: string) => invoke<{ data: TranscriptRevision[] }>("transcript_revisions", { id }),
  renameSpeakers: (sessionId: string, names: Record<string, string | null>, rememberForClient: boolean) =>
    invoke<{ speaker_names: Record<string, string> }>("speakers_rename", { sessionId, names, rememberForClient }),
  exportTranscript: (id: string, format: ExportFormat, timestamps = true, speakers = true) => invoke<string>("export_transcript", { id, format, timestamps, speakers }),
  createShare: (sessionId: string, expiresIn: number, password: string | null, includeAudio: boolean) =>
    invoke<ShareLink>("share_create", { sessionId, expiresIn, password, includeAudio }),
  listShares: (sessionId: string) => invoke<{ data: ShareLink[] }>("share_list", { sessionId }),
  revokeShare: (id: string) => invoke<void>("share_revoke", { id }),
  addToVocabulary: (term: string, clientId: string | null) => invoke<{ keyterms: string[] }>("vocabulary_add", { term, clientId }),
  dictationHistory: () => invoke<DictationHistoryEntry[]>("dictation_history"),
  lockStatus: () => invoke<LockStatus>("lock_status"),
  lockNow: () => invoke<LockStatus>("lock_now"),
  lockTouch: () => invoke<void>("lock_touch"),
  unlockWithSystem: () => invoke<LockStatus>("lock_unlock_os"),
  unlockWithPin: (pin: string) => invoke<LockStatus>("lock_unlock_pin", { pin }),
  setLockPin: (pin: string) => invoke<LockStatus>("lock_set_pin", { pin }),
  securityOverview: () => invoke<{ devices: SecurityDevice[]; events: SecurityEvent[] }>("security_overview"),
  revokeDevice: (id: string) => invoke<void>("device_revoke", { id }),
  bin: () => invoke<{ data: BinItem[] }>("bin_list"),
  restoreSession: (id: string) => invoke<{ data: LibraryItem }>("session_restore", { id }),
  eraseFromBin: (id: string) => invoke<void>("bin_erase", { id }),
  storage: () => invoke<StorageInfo>("storage_info"),
  clearDictationHistory: () => invoke<void>("dictation_history_clear"),
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
