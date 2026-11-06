// Typed wrappers around the Rust commands (src-tauri/src/commands.rs). Tokens never reach the webview.
import { Channel, invoke } from "@tauri-apps/api/core";
import { listen, type UnlistenFn } from "@tauri-apps/api/event";

/** `translate_lock` and `translate_hide` (v1.3, Live Translate) are bound only while it runs and never shown in Settings. */
export type ShortcutAction = "toggle_captions" | "toggle_saving" | "copy_last2_min" | "dictation" | "voice_note" | "mark" | "translate_lock" | "translate_hide";
export type Corner = "top_left" | "top_right" | "bottom_left" | "bottom_right";
/** After a call (v1.5): kept at once with "Saved · Undo", or a small chip that keeps it by itself after 10 s. */
export type AfterCall = "keep" | "ask";
/** Why an automatic call was skipped quietly (v1.5): shorter than the setting, or nobody spoke on either side. */
export type SkipReason = "too_short" | "no_speech";
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
  /** What the workspace's plan and the person's role allow (since v1.5). */
  plan: PlanInfo;
  /** This Windows version has no per-app capture: the sentence to show (since v1.5). */
  systemAudioNote?: string | null;
  /** The running recording's devices (since v1.5). */
  deviceNote?: DeviceNote | null;
}

/** The plan and role (since v1.5); `known` is false on a server without plans (every feature on, no plan limits). */
export interface PlanInfo {
  known: boolean;
  name: string | null;
  liveTranslate: boolean;
  screenMoments: boolean;
  aiNotes: boolean;
  connector: boolean;
  /** Limits; null is no limit. */
  monthlyMinutes: number | null;
  storageBytes: number | null;
  retentionDays: number | null;
  seats: number | null;
  usedMinutes: number | null;
  usedStorageBytes: number | null;
  workspace: string | null;
  /** null when the server did not say (the app acts as a member). */
  role: WorkspaceRole | null;
}

export type WorkspaceRole = "owner" | "admin" | "member" | "viewer";

/** A sign-in with an account (since v1.5): signed in, the account wants its two-factor code, or the server only pairs. */
export type SignInResult = "signed_in" | "otp_required" | "pairing_only";
export interface SignInOutcome {
  result: SignInResult;
  status: AppStatus | null;
}

/** `GET /api/account` as the server writes it (since v1.5). */
export interface Account {
  name: string;
  email: string;
  email_verified: boolean;
  two_factor: boolean;
  google: boolean;
  created_at: string | null;
  workspace: { id: unknown; name: string; role: string } | null;
  /** When the account will be deleted; null when it is not. */
  deletes_at: string | null;
}

/** Signed updates from the server (since v1.5). */
export type UpdatePhase = "idle" | "checking" | "up_to_date" | "downloading" | "ready" | "failed" | "unavailable";
export interface UpdateStatus {
  phase: UpdatePhase;
  /** This app's version. */
  current: string;
  /** The new version, while it downloads and once it is ready. */
  version: string | null;
  notes: string | null;
  /** The last check that reached the server (RFC 3339). */
  checkedAt: string | null;
  message: string | null;
  /** 0 to 100 while downloading, when the size is known. */
  progress: number | null;
}

/** Export my data (since v1.5): the ZIP is made on the server; `url` works for 24 hours once it is ready. */
export interface AccountExport {
  id: string;
  status: "making" | "ready" | "failed";
  url: string | null;
  expires_at: string | null;
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
  afterCall: AfterCall;
  skipCallsShorterThanS: number;
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
  activityNotifications: boolean;
  quietHours: boolean;
  quietFrom: string;
  quietTo: string;
  onboardingDone: boolean;
  screenMoments: boolean;
  screenTarget: ScreenTarget;
  screenExcludedApps: string[];
}

/** What screen moments capture (since v1.5): the meeting app's window, or the whole screen it is on. */
export type ScreenTarget = "meeting_window" | "whole_screen";

/** One notification of the activity centre (B18). */
export interface ActivityItem {
  id: string;
  kind: string | null;
  title: string;
  body: string;
  session_id: string | null;
  read: boolean;
  at: string | null;
}

/** App lock (B16): Windows Hello / Touch ID or the PIN; 0 minutes = only when the app starts. */
export type LockAfterMinutes = 0 | 1 | 5 | 15 | 60;
/** Main-window material (C2) and the system accent the core can read (Windows); see material.rs. */
export interface WindowAppearance {
  material: "mica" | "vibrancy" | "none";
  accent: string | null;
  platform: "windows" | "macos" | "other";
}

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
export type TranscriptionLanguage = "en" | "ur" | "ar" | "fr" | "de" | "es" | "it" | "pt" | "nl" | "tr" | "ru";

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
  afterCall?: AfterCall;
  skipCallsShorterThanS?: number;
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
  activityNotifications?: boolean;
  quietHours?: boolean;
  quietFrom?: string;
  quietTo?: string;
  onboardingDone?: boolean;
  screenMoments?: boolean;
  screenTarget?: ScreenTarget;
  screenExcludedApps?: string[];
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
  /** A Bluetooth headset or earbuds (v1.5): "Accuracy is lower with Bluetooth mics". */
  bluetooth: boolean;
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

/** A permission the guided steps and the Mac check name (since v1.5). */
export type PermissionKind = "microphone" | "system_audio" | "screen";

/** Something the system does not let EchoLedger use, and what a recording holds without it. */
export interface MissingPermission {
  kind: PermissionKind;
  sentence: string;
}

export interface PermissionsStatus {
  microphone: PermissionState;
  /** This Mac's sound and Screen Recording (since v1.5); `allowed` on Windows. */
  systemAudio?: PermissionState;
  screen?: PermissionState;
  browserAutomation: boolean;
  platform: "windows" | "macos" | "other";
  /** What is off now and what a recording holds without it (since v1.5). */
  missing?: MissingPermission[];
  /** A permission that was granted has gone: one calm sentence, until dismissed or granted again. */
  lost?: string | null;
}

/** One line about the running recording's devices (since v1.5): one that is gone, or news of a change. */
export interface DeviceNote {
  text: string;
  warning: boolean;
}

/** Help & diagnostics → Run Mac check (since v1.5). */
export type CheckState = "ok" | "limited" | "off" | "failed";
export interface CheckItem {
  key: string;
  title: string;
  state: CheckState;
  detail: string;
  /** A button to the System Settings pane that fixes it. */
  fix: { label: string; settings: string } | null;
}
export interface CheckProgress {
  index: number;
  total: number;
  line: string;
  done: CheckItem[];
}
export interface CheckReport {
  items: CheckItem[];
  stopped: string | null;
  finishedAt: string;
  appVersion: string;
  osVersion: string;
  model: string;
  sentAt: string | null;
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
  /** The recording's language (null = automatic) and its chip, "UR+EN" (since v1.1.2). */
  language?: TranscriptionLanguage | null;
  language_chip?: string;
  /** Screen moments of the recording (since v1.5); missing on a server without them. */
  screen_count?: number;
  transcript?: {
    final_status: string | null;
    final_transcript_id: string | null;
    live_available: boolean;
    live_transcript_id?: string | null;
    preview: string | null;
    /** Which language and engine made the final transcript, and a wrong-script warning (since v1.1.2). */
    final_language?: string | null;
    final_provider?: string | null;
    final_provider_label?: string | null;
    final_model?: string | null;
    live_language?: string | null;
    script_warning?: string | null;
    /** Why the final transcript failed; `no_audio`: nothing was heard (since v1.1.2). */
    final_error_code?: string | null;
    /** The other version: the previous transcript after "Re-transcribe as", or a second engine's run (since v1.2). */
    alternate_transcript_id?: string | null;
    alternate_status?: string | null;
    alternate_language?: string | null;
    /** Parts done of all parts while a long recording is transcribed in pieces (since v1.2). */
    final_progress?: TranscriptProgress | null;
  };
  /** How it got into its project (since v1.5): by hand, the caller is a contact, a filing rule; null = the Inbox. */
  filed_by?: "user" | "contact" | "rule" | null;
}

/**
 * A client folder (B15), called a project in the apps since v1.5: `avatar` is one emoji or two letters; pinned folders
 * come first. A project can sit in another one (at most 4 levels); an older server sends none of the v1.5 fields.
 */
export interface ClientFolder {
  id: string;
  name: string;
  color?: string | null;
  avatar?: string | null;
  pinned?: boolean;
  /** The client's default language (since v1.1.2); null = the console default. */
  language?: TranscriptionLanguage | null;
  keyterm_count?: number;
  /** Recordings directly in it. */
  session_count?: number;
  /** Claude writes call notes after each kept recording of this client (since v1.4, default on). */
  ai_notes?: boolean;
  /** The project it sits in (since v1.5); null = the top level. */
  parent_id?: string | null;
  /** Its ancestors from the top (since v1.5), empty at the top. */
  path?: { id: string; name: string }[];
  children_count?: number;
  /** Recordings in it and in its subfolders (since v1.5). */
  nested_session_count?: number;
  /** Screen moments are kept for its meetings (since v1.5, default on); missing on a server without them. */
  screens?: boolean;
}

/** The smart folders of the Library (since v1.5): views the server computes, never copies. */
export type SmartFolder = "inbox" | "whatsapp" | "whatsapp_business" | "google_meet" | "zoom" | "teams" | "phone" | "voice_notes" | "imported" | "shared";

/** `GET /api/library/views`: counts of the smart views and labels and, since v1.5, of the smart folders. */
export interface LibraryViews {
  views?: Record<string, number>;
  labels?: { label: string; count: number }[];
  /** Missing on an older server, which then shows no smart folders. */
  folders?: Partial<Record<SmartFolder, number>> | null;
}

/** A project's filing rule (since v1.5): the title contains `value`, the recording comes from an app, or the caller is a contact. */
export type RuleKind = "title" | "app" | "contact";
export interface FilingRule {
  id: number | string;
  kind: RuleKind;
  value: string;
  created_at?: string | null;
}

/** Who may open a share link (since v1.5): anyone with it, anyone with it and the password, the people named. */
export type ShareAudience = "public" | "password" | "people";

/** What the share dialog asks for (Rust `sharing::ShareOptions`); `expiresAt` null = the link never expires. */
export interface ShareOptions {
  audience: ShareAudience;
  password?: string | null;
  people?: string[];
  allowDownload: boolean;
  expiresAt?: string | null;
  view?: TranscriptView | null;
}

/** A recording or a project a teammate sent (since v1.5); `session` is read-only. */
export interface SharedItem {
  id: string;
  kind: "session" | "project";
  from: { name?: string | null; email?: string | null } | null;
  note: string | null;
  title: string | null;
  sent_at: string | null;
  saved_at: string | null;
  session: LibraryItem | null;
  client: ClientFolder | null;
}

/** A request link (since v1.5): a page where anyone can send voice notes and files into a project or the Inbox. */
export interface RequestLink {
  id: string;
  /** Only right after it was made. */
  url?: string;
  title: string | null;
  client_id: string | null;
  expires_at: string | null;
  max_files: number | null;
  max_mb: number | null;
  received: number;
  revoked_at: string | null;
  created_at?: string | null;
}

/** What the Request files sheet asks for (Rust `sharing::RequestLinkOptions`); `clientId` null = the Inbox. */
export interface RequestLinkOptions {
  title: string;
  clientId: string | null;
  expiresAt: string | null;
  maxFiles: number | null;
  maxMb: number | null;
}

/** What a share, project or request link is for this workspace (`GET /api/links/resolve`, since v1.5). */
export type ResolvedLink =
  | { kind: "session"; session_id: string }
  | { kind: "project"; client_id: string }
  | { kind: "shared"; shared_id: string }
  | { kind: "request"; request_link_id: string };

/** A line of a client's memory (since v1.4): what was decided, asked, changed or is in scope. */
export type MemoryKind = "scope" | "decision" | "question" | "change";
export interface MemoryLine {
  id: string;
  kind: MemoryKind;
  text: string;
  happened_on: string | null;
  pinned: boolean;
  /** Where it came from: a call (at `at_ms`), a file, or a person. */
  source: { type: "call" | "file" | "user"; id?: string | null; at_ms?: number | null } | null;
}

/** The one-page brief of a client; `goal` is a line, the others are lists of lines. */
export interface BriefSections {
  goal?: string | null;
  scope?: string[];
  constraints?: string[];
  deadlines?: string[];
  budget?: string[];
  people?: string[];
  glossary?: string[];
}
export interface ClientBrief {
  sections: BriefSections | null;
  /** A person edited it: it is never rewritten by itself. */
  edited: boolean;
  status: "none" | "working" | "ready" | "failed";
  error: string | null;
  generated_at: string | null;
  updated_at: string | null;
}

/** One line of call notes: `verify` = the transcript was unclear there; `at_ms` = the moment of its first line. */
export interface NoteItem {
  text: string;
  verify: boolean;
  lines: number[];
  at_ms: number | null;
  /** Changes only: what kind of change it is. */
  kind?: "add" | "remove" | "modify";
}
export interface NoteSection {
  key: string;
  heading: string;
  items: NoteItem[];
}
/** The notes Claude writes after a kept recording of a client (since v1.4). */
export interface CallNotes {
  id: string;
  session_id: string;
  status: "pending" | "ready" | "failed" | "skipped";
  error: string | null;
  language: string | null;
  sections: NoteSection[];
  created_at: string | null;
  session?: { id: string; title: string | null; started_at: string | null; duration_ms: number | null } | null;
}

/** A file or pasted instructions in a client's workspace; files are read on the server (`extracting`). */
export interface ClientFile {
  id: string;
  name: string;
  mime: string | null;
  size_bytes: number | null;
  pages: number | null;
  status: "extracting" | "ready" | "failed";
  error: string | null;
  instructions: boolean;
  created_at: string | null;
}

/** `GET /api/clients/{id}/workspace` (since v1.4). */
export interface ClientWorkspace {
  client: { id: string; name: string; ai_notes: boolean };
  ai: { configured: boolean; spent_usd: number; budget_usd: number; client_spent_usd: number };
  brief: ClientBrief | null;
  memory: MemoryLine[];
  notes: CallNotes[];
  files: ClientFile[];
}

/** The language of an Ask answer or of call notes written again. */
export type AnswerLanguage = "en" | "roman_urdu";
/** A source of an Ask answer: a moment of a call or a page of a file. */
export interface AnswerCitation {
  type: "call" | "file";
  session_id?: string | null;
  file_id?: string | null;
  title: string;
  at_ms?: number | null;
  page?: number | null;
  quote?: string | null;
}
export interface ClientAnswer {
  answer: string;
  citations: AnswerCitation[];
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
  /** Word timings when the engine gave them (an edited line has none); `confidence` 0-1 when the engine scored it. */
  words?: { text: string; start_ms: number; end_ms: number; confidence?: number | null }[];
  /** The engine was not sure of this line (since v1.2); it is kept as heard. */
  uncertain?: boolean;
  /** Double-check: what a second engine heard on this line when it disagreed (since v1.2); never merged. */
  check?: { provider: string; label: string; text: string } | null;
}

/** Parts done of all parts while a long recording is transcribed in pieces (since v1.2). */
export interface TranscriptProgress {
  done: number;
  total: number;
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
  /** Parts done of all parts while it is being made in pieces (since v1.2). */
  progress?: TranscriptProgress | null;
  /** Show as: the views this transcript can have and whether they are made (since v1.2). */
  views?: { available: TranscriptView[]; status: TranscriptViewsStatus; default?: "original" | TranscriptView } | null;
  /** Suggested fixes: likely mishearings of the client's words, nothing changes until a person decides (since v1.4). */
  suggestions?: TranscriptSuggestion[];
  /**
   * Since v1.5: an Urdu or Auto recording was transcribed by an engine the routing table ranks lower, because the
   * preferred one stayed unavailable for the whole wait (`waited`) or refused this recording (`rejected`). Missing on
   * an older server: false / null.
   */
  backup_engine?: boolean;
  backup_reason?: "waited" | "rejected" | null;
  /** Since v1.5: while an Urdu or Auto transcript waits for its preferred engine, when it tries again and why. */
  retry_at?: string | null;
  retry_reason?: "rate_limited" | "unavailable" | null;
}

/** One suggested fix: in segment `index`, `from` may be `to`. */
export interface TranscriptSuggestion {
  id: number;
  index: number;
  from: string;
  to: string;
  reason?: string | null;
  status: string;
}

/** After a line edit of a client's recording: words the edit changed, to offer "Always write X as Y" (since v1.4). */
export interface LearnOffer {
  from: string;
  to: string;
  client_id: string;
  client_name: string;
}

/** A "Show as" reading view of a transcript (since v1.2); the transcript itself never changes. */
export type TranscriptView = "roman" | "english" | "urdu";
export type TranscriptViewsStatus = "unavailable" | "none" | "making" | "ready" | "failed";

/** `GET /api/transcripts/{id}/views`: each view as a list aligned with the segments (null for an empty line). */
export interface TranscriptViewsState {
  status: TranscriptViewsStatus;
  available: TranscriptView[];
  roman: (string | null)[] | null;
  english: (string | null)[] | null;
  urdu?: (string | null)[] | null;
  default?: "original" | TranscriptView;
  model: string | null;
  made_at: string | null;
  error: string | null;
  /** Lines shown with their original words in a view because the view failed the checks twice (since v1.3). */
  kept?: Partial<Record<TranscriptView, number[]>>;
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

/** A share link of a recording or (since v1.5) a project; `url` is only present right after it was made. */
export interface ShareLink {
  id: string;
  /** The recording's; null for a project's link. */
  session_id: string | null;
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
  /** Since v1.5 (missing on an older server). */
  kind?: "session" | "project";
  client_id?: string | null;
  audience?: ShareAudience;
  people?: string[];
  allow_download?: boolean;
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

/**
 * A finished recording that is not kept yet. Times are RFC 3339 UTC; `deadlineAt` is when the after-call chip keeps it
 * by itself, null while it waits for Keep or Discard in the Library.
 */
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
  /** The language Keep transcribes in; null = automatic (the client's or the console default). */
  language?: TranscriptionLanguage | null;
  /** Who the call was with (a meeting window's title), for "Call with <name>" while it has no title. */
  callerName?: string | null;
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
  /** A file brought in with Import or a drop: listed while it uploads; cancelling it loses nothing. */
  imported: boolean;
  /** Percent sent while `uploading`. */
  progress: number | null;
  /** The account it waits for: it was made while another account was signed in here (since v1.5). */
  heldFor?: string | null;
}

/** What one import did: files queued, already in the queue, of a type that cannot be imported, or unreadable. */
export interface ImportSummary {
  queued: number;
  duplicates: number;
  unsupported: number;
  failed: number;
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
  /** The recording's language chip; null = automatic. */
  language?: TranscriptionLanguage | null;
  /** The recording's microphone is Bluetooth (v1.5): the pill says so when it starts. */
  bluetoothMic: boolean;
  /** Screen moments of the recording (v1.5); null while they are off for it. */
  screens: ScreensPill | null;
  /** What the system does not let this recording use (since v1.5). */
  missing?: MissingPermission[];
  /** One of its devices is gone, or changed a moment ago (since v1.5). */
  deviceNote?: DeviceNote | null;
}

/** Why screen moments are paused (since v1.5): by the person, the recording paused, the meeting window minimised or
 * hidden, no meeting window, an excluded app showing, macOS permission missing, 120 this hour, or the project being read. */
export type ScreenHoldReason = "you" | "recording_paused" | "minimised" | "no_window" | "excluded_app" | "permission" | "hour_cap" | "starting";

/** The recording pill's Screens chip: on, or paused and why, and how many screens were kept. */
export interface ScreensPill {
  on: boolean;
  reason: ScreenHoldReason | null;
  count: number;
}

/** What Settings → Screen moments says about this computer and the server (`screens_status`). */
export interface ScreensStatus {
  supported: boolean;
  platform: "windows" | "macos" | "other";
  /** No border around what is captured (Windows 11); Windows 10 draws a yellow one. */
  borderless: boolean;
  /** macOS Screen Recording is allowed (always true on Windows). */
  permission: boolean;
  inPlan: boolean;
  onServer: boolean;
}

/** One screen moment of a recording (docs/API.md "Screen moments"); the image links are signed for 30 minutes. */
export interface ScreenMoment {
  id: string;
  /** "Screen 7": the recording's screens in time order from 1. */
  index: number;
  at_ms: number;
  width: number;
  height: number;
  trigger: "change" | "words" | "manual";
  ocr_text: string | null;
  description: string | null;
  image_url: string;
  thumb_url: string;
  created_at?: string;
}

/** `GET /api/sessions/{id}/screens`. */
export interface SessionScreens {
  data: ScreenMoment[];
}

/** A screen of a project's Screens tab, with its recording. */
export interface ProjectScreen extends ScreenMoment {
  session_id: string;
  session_title: string | null;
  session_started_at: string | null;
}

/** One page of `GET /api/clients/{id}/screens` (60 a page, newest recording first). */
export interface ProjectScreensPage {
  data: ProjectScreen[];
  next_cursor: string | null;
}

/** Export → Meeting pack: the print page (Save as PDF in the browser) or a ZIP with the pictures. */
export type MeetingPackFormat = "pdf" | "zip";

/** "Saved · Call with Omar · 4:12 · Undo": a recording kept at once after a call; `until` is when the toast goes. */
export interface SavedToast {
  id: string;
  title: string | null;
  callerName: string | null;
  sourceApp: string;
  mode: "call" | "voice_note" | "mic_note";
  durationMs: number;
  until: string;
}

/** What the after-call window shows: the Saved toast and the Ask-me chip (its item's `deadlineAt` is when it keeps). */
export interface AfterCallState {
  saved: SavedToast | null;
  ask: PendingItem | null;
}

/** A short or silent automatic call kept on this computer for 24 h (Activity's "Skipped 3 short calls"). Times are RFC 3339 UTC. */
export interface SkippedItem {
  id: string;
  sourceApp: string;
  title: string | null;
  callerName: string | null;
  startedAt: string;
  durationMs: number;
  reason: SkipReason;
  /** When it is deleted for good. */
  expiresAt: string;
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
  /** A recording to open (an OS notification was clicked, B18). */
  recordingId?: string | null;
  /** A share, project or request link to open in place (an echoledger://open link, since v1.5). */
  link?: string | null;
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
  "skipped:changed": SkippedItem[];
  "aftercall:state": AfterCallState;
  "ask:show": AskPayload;
  "ask:hide": null;
  "uploads:changed": UploadItem[];
  "main:navigate": NavigatePayload;
  "recording:marked": MarkPayload;
  "capture:far_side": FarSidePayload;
  "mac_check:progress": CheckProgress;
  "capture:test_level": [number, number];
  "dictation:state": { active: boolean; sounds: boolean; mode: DictationMode };
  "dictation:level": number;
  "lock:changed": LockStatus;
  "activity:updated": number;
  "signin:done": AppStatus;
  "signin:failed": string;
  /** A link of another browser sign-in (an earlier tab): what to say while this one keeps waiting. */
  "signin:other": string;
  "update:status": UpdateStatus;
}

export type AppEvent = keyof EventPayloads;

/** The server's public pages the app opens in the browser (since v1.5). */
export type ServerPage = "terms" | "privacy" | "account/delete" | "downloads";

export const api = {
  status: () => invoke<AppStatus>("app_status"),
  windowAppearance: () => invoke<WindowAppearance>("window_appearance"),
  setWindowTheme: (theme: "light" | "dark" | "system") => invoke<void>("set_window_theme", { theme }),
  pair: (serverUrl: string, code: string, deviceName: string) => invoke<AppStatus>("pair_device", { serverUrl, code, deviceName }),
  unpair: () => invoke<AppStatus>("unpair"),
  // Signing in with an account and the account page (since v1.5).
  signInPassword: (serverUrl: string, email: string, password: string, otp: string | null, deviceName: string) =>
    invoke<SignInOutcome>("sign_in_password", { serverUrl, email, password, otp, deviceName }),
  /** Opens the server's sign-in page in the browser; false (and no browser) when the server only pairs. The answer
   * comes back as `signin:done` or `signin:failed` (`signin:other` for an earlier sign-in's link). */
  signInBrowser: (serverUrl: string, deviceName: string) => invoke<boolean>("sign_in_browser", { serverUrl, deviceName }),
  signInBrowserCancel: () => invoke<void>("sign_in_browser_cancel"),
  /** null on a server without accounts. */
  account: () => invoke<Account | null>("account_get"),
  /** Every device and console session of the account, this computer too: the answer is the signed-out status. */
  signOutEverywhere: () => invoke<AppStatus>("account_sign_out_everywhere"),
  /** When the account will be deleted (in 7 days). */
  deleteAccount: (password: string | null, otp: string | null) => invoke<string | null>("account_delete", { password, otp }),
  startExport: () => invoke<AccountExport>("account_export_start"),
  exportStatus: (id: string) => invoke<AccountExport>("account_export_status", { id }),
  openExport: (url: string) => invoke<void>("account_export_open", { url }),
  /** The server's Terms, Privacy, account deletion or downloads page in the browser. */
  openServerPage: (page: ServerPage) => invoke<void>("server_page_open", { page }),
  // Updates (since v1.5): checked every 12 hours and on request; `update:status` follows the check and the download.
  updateStatus: () => invoke<UpdateStatus>("update_status"),
  checkForUpdates: () => invoke<UpdateStatus>("update_check"),
  /** "Restart now": installs the ready update and starts the new version. */
  installUpdate: () => invoke<void>("update_install"),
  settings: () => invoke<Settings>("get_settings"),
  updateSettings: (patch: SettingsPatch) => invoke<Settings>("update_settings", { patch }),
  setShortcut: (action: ShortcutAction, accelerator: string) => invoke<Settings>("set_shortcut", { action, accelerator }),
  resetShortcuts: () => invoke<Settings>("reset_shortcuts"),
  toggleCaptions: () => invoke<AppStatus>("toggle_captions"),
  // Live Translate (v1.3, lib/translate.ts holds its types).
  translateApps: () => invoke<import("./translate").TranslateApp[]>("translate_apps"),
  translateStatus: () => invoke<import("./translate").TranslateFeed | null>("translate_status"),
  translateStart: (request: import("./translate").TranslateStartRequest) => invoke<string[]>("translate_start", { request }),
  translateStop: () => invoke<void>("translate_stop"),
  translateToggleLock: () => invoke<void>("translate_toggle_lock"),
  startRecording: (sourceApp?: string) => invoke<AppStatus>("start_recording", { sourceApp: sourceApp ?? null }),
  stopRecording: () => invoke<AppStatus>("stop_recording"),
  toggleVoiceNote: () => invoke<AppStatus>("toggle_voice_note"),
  toggleMicNote: () => invoke<AppStatus>("toggle_mic_note"),
  hideCaptions: () => invoke<AppStatus>("hide_captions"),
  micUsers: () => invoke<MicUser[]>("mic_users"),
  /** One recording per file, with one client and language for the batch (null: no client / automatic). */
  importVoiceNotes: (pathsList: string[], clientId: string | null = null, language: TranscriptionLanguage | null = null) =>
    invoke<ImportSummary>("import_voice_notes", { pathsList, clientId, language }),
  /** The Import button's file picker (several files); empty when cancelled. */
  pickImportFiles: () => invoke<string[]>("import_pick"),
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
  /** One zip file, saved where the person chooses; null when they cancel the save dialog. */
  exportSessions: (ids: string[], format: BulkExportFormat) => invoke<string | null>("sessions_export", { ids, format }),
  /** A new project; `parentId` makes it a subfolder (since v1.5). */
  createClient: (name: string, color: string | null = null, avatar: string | null = null, parentId: string | null = null) =>
    invoke<{ data: ClientFolder }>("client_create", { name, color, avatar, parentId }),
  /** `parent_id` moves a project into another one, null to the top level (since v1.5). */
  updateClient: (id: string, patch: Partial<Pick<ClientFolder, "name" | "color" | "avatar" | "pinned" | "language" | "ai_notes" | "parent_id" | "screens">>) => invoke<{ data: ClientFolder }>("client_update", { id, patch }),
  // Projects, smart folders, filing rules, sharing and links (since v1.5).
  /** Its recordings go to the Inbox and its subfolders up a level. */
  deleteClient: (id: string) => invoke<{ moved_sessions?: number; moved_projects?: number }>("client_delete", { id }),
  libraryViews: (tz: string | null = null) => invoke<LibraryViews>("library_views", { tz }),
  libraryFolder: (folder: SmartFolder) => invoke<LibraryItem[]>("library_folder", { folder }),
  /** null: the server has no filing rules yet. */
  clientRules: (id: string) => invoke<{ data: FilingRule[] } | null>("client_rules", { id }),
  addClientRule: (id: string, kind: RuleKind, value: string) => invoke<{ data: FilingRule }>("client_rule_add", { id, kind, value }),
  deleteClientRule: (id: string, rule: string) => invoke<void>("client_rule_delete", { id, rule }),
  /** A share link for a recording or a project; its `url` is shown once. */
  createShareLink: (kind: "session" | "project", id: string, options: ShareOptions) => invoke<ShareLink>("share_link_create", { kind, id, options }),
  /** The links of a recording or a project; null on a server older than v1.5 (then `listShares` and `createShare`). */
  sharesOf: (sessionId: string | null, clientId: string | null = null) => invoke<{ data: ShareLink[] } | null>("shares_of", { sessionId, clientId }),
  /** How many people it was sent to. */
  sendToTeammate: (kind: "session" | "project", id: string, to: string[], note: string | null = null) => invoke<number>("send_to_teammate", { kind, id, to, note }),
  /** null: the server has no Shared with me yet. */
  sharedWithMe: () => invoke<{ data: SharedItem[] } | null>("shared_with_me"),
  saveShared: (id: string) => invoke<{ session_id?: string; client_id?: string }>("shared_save", { id }),
  removeShared: (id: string) => invoke<void>("shared_remove", { id }),
  createRequestLink: (options: RequestLinkOptions) => invoke<{ data: RequestLink }>("request_link_create", { options }),
  /** null: the server has no request links yet. */
  requestLinks: () => invoke<{ data: RequestLink[] } | null>("request_links"),
  revokeRequestLink: (id: string) => invoke<void>("request_link_revoke", { id }),
  /** null: the app cannot open it in place; `openLink` opens its page in the browser. */
  resolveLink: (url: string) => invoke<ResolvedLink | null>("links_resolve", { url }),
  openLink: (url: string) => invoke<void>("link_open", { url }),
  // Client workspaces (since v1.4): brief, memory, files, Ask, Copy for AI and call notes.
  clientWorkspace: (id: string) => invoke<{ data: ClientWorkspace }>("client_workspace", { id }),
  saveClientBrief: (id: string, sections: BriefSections) => invoke<{ data: ClientBrief }>("client_brief_save", { id, sections }),
  refreshClientBrief: (id: string) => invoke<{ data: ClientBrief }>("client_brief_refresh", { id }),
  addClientMemory: (id: string, kind: MemoryKind, text: string, happenedOn: string | null = null) =>
    invoke<{ data: MemoryLine }>("client_memory_add", { id, kind, text, happenedOn }),
  updateClientMemory: (id: string, item: string, patch: { text?: string; pinned?: boolean }) => invoke<{ data: MemoryLine }>("client_memory_update", { id, item, patch }),
  deleteClientMemory: (id: string, item: string) => invoke<void>("client_memory_delete", { id, item }),
  /** The native picker for PDF, Word, text and Markdown files; empty when cancelled. */
  pickClientFiles: () => invoke<string[]>("client_files_pick"),
  uploadClientFile: (id: string, path: string) => invoke<{ data: ClientFile }>("client_file_upload", { id, path }),
  addClientInstructions: (id: string, text: string, name: string | null = null) => invoke<{ data: ClientFile }>("client_instructions_add", { id, name, text }),
  deleteClientFile: (id: string, file: string) => invoke<void>("client_file_delete", { id, file }),
  askClient: (id: string, question: string, language: AnswerLanguage = "en") => invoke<{ data: ClientAnswer }>("client_ask", { id, question, language }),
  /** "Copy for AI": the brief, memory and recent call notes as one text. */
  clientBundle: (id: string, notes: number | null = null) => invoke<string>("client_bundle", { id, notes }),
  /** The call notes of a recording, or null when none were written. */
  sessionNotes: (id: string) => invoke<{ data: CallNotes } | null>("session_notes", { id }),
  writeSessionNotes: (id: string, language: AnswerLanguage | null = null) => invoke<{ data: CallNotes }>("session_notes_write", { id, language }),
  /** "Always write `from` as `to`" (for the client, or every recording when null). */
  addReplacement: (from: string, to: string, clientId: string | null) => invoke<{ replacements: unknown[] }>("vocabulary_replace_add", { from, to, clientId }),
  /** Accepts or dismisses a suggested fix; the answer is the transcript. */
  decideSuggestion: (id: string, suggestion: number, accept: boolean) => invoke<TranscriptDetail>("transcript_suggestion", { id, suggestion, accept }),
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
  pendingSetLanguage: (id: string, language: TranscriptionLanguage | null) => invoke<PendingItem>("pending_set_language", { id, language }),
  /** "Re-transcribe as <language>": the current transcript stays until the new one is ready (since v1.2). */
  retranscribe: (id: string, language: TranscriptionLanguage | null) => invoke<unknown>("session_retranscribe", { id, language }),
  /** The pill's language chip: live captions reconnect in it; the recording keeps running. */
  setRecordingLanguage: (language: TranscriptionLanguage | null) => invoke<void>("set_recording_language", { language }),
  /** Opens a waiting recording in the main window (from the chip: with its title open); its chip stops counting. */
  pendingOpen: (id: string, rename: boolean) => invoke<void>("pending_open", { id, rename }),
  /** What the after-call window shows now (the Saved toast and the Ask-me chip). */
  afterCallState: () => invoke<AfterCallState>("after_call_state"),
  /** Undo on the Saved toast: the recording goes (to Recently deleted once it is uploaded). */
  savedUndo: (id: string) => invoke<void>("saved_undo", { id }),
  /** Short or silent automatic calls skipped in the last 24 h, newest first. */
  skippedList: () => invoke<SkippedItem[]>("skipped_list"),
  /** Keeps a skipped call like any other (it uploads and is transcribed). */
  skippedRestore: (id: string) => invoke<void>("skipped_restore", { id }),
  uploadList: () => invoke<UploadItem[]>("upload_list"),
  uploadRetry: (id: string) => invoke<void>("upload_retry", { id }),
  uploadDiscard: (id: string) => invoke<void>("upload_discard", { id }),
  askAnswer: (id: string, answer: AskAnswer) => invoke<void>("ask_answer", { id, answer }),
  markMoment: (tag: string | null = null, note: string | null = null) => invoke<MarkPayload>("mark_moment", { tag, note }),
  tagLastMark: (tag: string | null, note: string | null = null) => invoke<void>("tag_last_mark", { tag, note }),
  undoMark: () => invoke<void>("undo_mark"),
  markerTags: () => invoke<string[]>("marker_tags"),
  /** How unsure words appear in copied text (since v1.3). */
  unsureMarkers: () => invoke<import("./format").UnsureMarkers>("unsure_markers"),
  inputDevices: () => invoke<InputDevice[]>("input_devices"),
  testCapture: (seconds?: number) => invoke<TestResult>("test_capture", { seconds: seconds ?? null }),
  permissionsStatus: () => invoke<PermissionsStatus>("permissions_status"),
  openPrivacySettings: (kind: "microphone" | "system_audio" | "automation" | "screen" | "sound" | "software_update") => invoke<void>("open_privacy_settings", { kind }),
  permissionRequest: (kind: PermissionKind) => invoke<PermissionsStatus>("permission_request", { kind }),
  permissionNoticeDismiss: () => invoke<void>("permission_notice_dismiss"),
  macCheckRun: () => invoke<CheckReport>("mac_check_run"),
  macCheckLast: () => invoke<CheckReport | null>("mac_check_last"),
  macCheckCopy: () => invoke<void>("mac_check_copy"),
  macCheckSend: () => invoke<string>("mac_check_send"),
  searchLibrary: (query: SearchQuery) => invoke<SearchResult>("search_library", { query }),
  /** The edited transcript; for a client's recording `learn` lists words to offer "Always write X as Y" for (v1.4). */
  editTranscript: (id: string, index: number, text: string) => invoke<TranscriptDetail & { learn?: LearnOffer[] }>("transcript_edit", { id, index, text }),
  replaceInTranscript: (id: string, find: string, replace: string, matchCase: boolean, wholeWord: boolean) =>
    invoke<{ replaced: number; transcript: TranscriptDetail }>("transcript_replace", { id, find, replace, matchCase, wholeWord }),
  restoreTranscript: (id: string) => invoke<TranscriptDetail>("transcript_restore", { id }),
  transcriptRevisions: (id: string) => invoke<{ data: TranscriptRevision[] }>("transcript_revisions", { id }),
  renameSpeakers: (sessionId: string, names: Record<string, string | null>, rememberForClient: boolean) =>
    invoke<{ speaker_names: Record<string, string> }>("speakers_rename", { sessionId, names, rememberForClient }),
  /** Saved where the person chooses (null when they cancel); `html` opens the print page in the browser instead. */
  exportTranscript: (id: string, format: ExportFormat, timestamps = true, speakers = true, view: TranscriptView | null = null) =>
    invoke<string | null>("export_transcript", { id, format, timestamps, speakers, view }),
  /** The recording's audio file, saved where the person chooses; null when they cancel. */
  saveAudio: (id: string) => invoke<string | null>("save_audio", { id }),
  /** Show as (since v1.2): the Roman Urdu and English views and their state; `makeTranscriptViews` starts them. */
  transcriptViews: (id: string) => invoke<TranscriptViewsState>("transcript_views", { id }),
  makeTranscriptViews: (id: string) => invoke<TranscriptViewsState>("transcript_views_make", { id }),
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
  activity: (limit = 30) => invoke<{ data: ActivityItem[]; unread_count: number }>("activity_list", { limit }),
  exportSettings: () => invoke<string>("settings_export"),
  importSettings: (text: string) => invoke<Settings>("settings_import", { text }),
  markActivityRead: (ids: string[] | null = null) => invoke<{ unread_count: number }>("activity_read", { ids }),
  clearDictationHistory: () => invoke<void>("dictation_history_clear"),
  windowReady: () => invoke<void>("window_ready"),
  mainView: () => invoke<string | null>("main_view_get"),
  saveMainView: (view: string) => invoke<void>("main_view_set", { view }),
  // Screen moments and the meeting pack (since v1.5).
  /** Settings → Screen moments: capture support, the border, the macOS permission, the plan and the server. */
  screensStatus: () => invoke<ScreensStatus>("screens_status"),
  /** The pill's Screens chip: pause or resume screens of the running recording (the recording goes on). */
  screensPause: (paused: boolean) => invoke<RecordingState>("screens_pause", { paused }),
  /** macOS: asks for Screen Recording, or opens System Settings once it was refused; true when allowed. */
  screensPermission: () => invoke<boolean>("screens_permission"),
  /** A recording's screens in time order; null on a server without screen moments. */
  sessionScreens: (id: string) => invoke<SessionScreens | null>("session_screens", { id }),
  /** A page of a project's screens; null on a server without screen moments. */
  clientScreens: (id: string, includeSubfolders: boolean, cursor: string | null = null) =>
    invoke<ProjectScreensPage | null>("client_screens", { id, includeSubfolders, cursor }),
  deleteScreen: (sessionId: string, screen: string) => invoke<void>("screen_delete", { sessionId, screen }),
  /** A screen's picture from its signed link, as a data: URL (fetched by the core, pinned to the server). */
  screenImage: (url: string) => invoke<string>("screen_image", { url }),
  /** Export → Meeting pack in the view on screen: `pdf` opens the print page in the browser, `zip` is saved where
   * the person chooses (null when they cancel). */
  meetingPack: (id: string, format: MeetingPackFormat, view: TranscriptView | null, includeAudio = false) =>
    invoke<string | null>("meeting_pack", { id, format, view, includeAudio }),
};

/**
 * C9: windows are built on demand, so the core keeps the events meant for a window until its page listens. Call
 * once the listeners are attached; `alive` is false after the effect was cleaned up (React's strict mode runs effects
 * twice in development).
 */
export function announceReady(subscriptions: Promise<unknown>[], alive: () => boolean): void {
  Promise.allSettled(subscriptions)
    .then(() => (alive() ? api.windowReady() : undefined))
    .catch(() => undefined);
}

/**
 * Listens to a core event and hands the callback only its payload. Resolves to the unlisten function.
 * With a known event name the payload type is inferred; `listenTo<T>(name, cb)` works for any event.
 */
export function listenTo<E extends AppEvent>(event: E, cb: (payload: EventPayloads[E]) => void): Promise<UnlistenFn>;
export function listenTo<T>(event: string, cb: (payload: T) => void): Promise<UnlistenFn>;
export function listenTo(event: string, cb: (payload: unknown) => void): Promise<UnlistenFn> {
  return listen<unknown>(event, (e) => cb(e.payload));
}

/** Files dragged over this window and dropped on it. The core hands the page the drop (Tauri's drag-drop events). */
export interface FileDropHandlers {
  onEnter?: () => void;
  onLeave?: () => void;
  onDrop: (paths: string[]) => void;
}

export async function listenForFileDrops(handlers: FileDropHandlers): Promise<UnlistenFn> {
  const unlisten = await Promise.all([
    listen("tauri://drag-enter", () => handlers.onEnter?.()),
    listen("tauri://drag-leave", () => handlers.onLeave?.()),
    listen<{ paths?: string[] }>("tauri://drag-drop", (e) => handlers.onDrop(e.payload?.paths ?? [])),
  ]);
  return () => unlisten.forEach((f) => f());
}

/** Subscribes this window to caption events (replaces any previous subscription of the same window). */
export async function subscribeCaptions(onEvent: (e: CaptionEvent) => void): Promise<void> {
  const channel = new Channel<CaptionEvent>(onEvent);
  await invoke("subscribe_captions", { onEvent: channel });
}

export function errorMessage(e: unknown): string {
  // A rejection with an empty text (or an Error without a message) used to show an empty error, or none at all.
  const text = typeof e === "string" ? e
    : e instanceof Error ? e.message
      : typeof (e as { message?: unknown } | null)?.message === "string" ? (e as { message: string }).message
        : "";
  return text.trim() || "Something went wrong";
}
