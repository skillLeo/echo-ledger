// Typed wrappers around the Rust commands (src-tauri/src/commands.rs). Tokens never reach the webview.
import { Channel, invoke } from "@tauri-apps/api/core";
import { listen, type UnlistenFn } from "@tauri-apps/api/event";

/** `translate_lock` and `translate_hide` (v1.3, Live Translate) are bound only while it runs and never shown in Settings. */
export type ShortcutAction = "record_now" | "toggle_captions" | "toggle_saving" | "copy_last2_min" | "dictation" | "voice_note" | "mark" | "hide_all" | "capture_screen" | "translate_lock" | "translate_hide";
/** "Hide EchoLedger from screen sharing" (since v1.8.1). */
export type ShareHiding = "all" | "floating" | "off";
/** Windows before 10 version 2004: a black box where EchoLedger is, or the window as it is. */
export type OldWindowsSharing = "black_box" | "show";
export type Corner = "top_left" | "top_right" | "bottom_left" | "bottom_right";
/** After a call (contract 1.8.0-e): `ask` (default), a small chip that saves the audio by itself after 12 s, not
 * transcribed; `always_transcribe` or `always_audio`, kept at once with "Saved · Undo". v1.5's `keep` reads as `ask`. */
/** A Show as view an export can be in. */
export type ExportView = "roman" | "english" | "urdu";
/** `sessions_transcribe` (docs/API.md "Audio only"). */
export interface TranscribeStarted {
  started: string[];
  skipped: { id: string; code: string }[];
  minutes: number;
}
/** `sessions_transcribe_estimate`. */
export interface TranscribeEstimate {
  minutes: number;
  minutes_left: number | null;
  items: { id: string; minutes: number }[];
}
export type AfterCall = "ask" | "always_transcribe" | "always_audio";
/** "When to ask" under Ask me (the call choice): after the call (the card) or at the start (the banner by the pill). */
export type AskWhen = "after" | "start";
/** "After each call" as Settings shows it: `off` is Don't record calls (autoMode off; afterCall is kept under it). */
export type AfterEachCall = "ask" | "always_transcribe" | "always_audio" | "off";
/** The three choices of the card and the pill's menu (`delete` is "Stop and delete" on the pill). */
export type CallChoice = "transcribe" | "audio" | "delete";
/** The same choice made on the card this many times in a row ("Always do this?" after 3). */
export interface CardStreak {
  choice: CallChoice;
  count: number;
}
/** Why an automatic call was skipped quietly (v1.5): shorter than the setting, or nobody spoke on either side. */
export type SkipReason = "too_short" | "no_speech";
export type TrayState = "idle" | "recording" | "paused" | "uploading" | "attention";
export type BudgetState = "ok" | "warn" | "reached";
export type AskAnswer = "record" | "ignore" | "always_ignore";
export type MicDecision = "auto_record" | "recording" | "ignored" | "ask" | "off";
export type IgnoreReason = "dictation" | "unknown_site" | "unknown_app" | "never_record" | "auto_off" | "app_off";
export type CaptionSize = "small" | "medium" | "large";
export type CaptionWidth = "narrow" | "normal" | "wide";
/** `reopen`: Screen Recording allowed in System Settings while EchoLedger ran, used once it is quit and reopened. */
export type PermissionState = "allowed" | "denied" | "unknown" | "reopen";
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
  /** The core's id for this sign-in, made at each sign-in (none while signed out; from a core before 2.0.0-p, none). */
  signIn?: string | null;
  trayState: TrayState;
  budget: BudgetState;
  /** What the workspace's plan and the person's role allow (since v1.5). */
  plan: PlanInfo;
  /** This Windows version has no per-app capture: the sentence to show (since v1.5). */
  systemAudioNote?: string | null;
  /** The running recording's devices (since v1.5). */
  deviceNote?: DeviceNote | null;
  /** The last Ready check (Phase 1C): null before the first one. */
  callsReady?: boolean | null;
  readyProblems?: number;
  /** What the server says to every app (v1.6, P2.7); missing from an older core. */
  server?: ServerNotices;
  /** Said once after "Keep automatically" became Ask me; null otherwise (missing from an older core). */
  afterCallNotice?: string | null;
  /** Said once after the v2.0 update: the main window now shows in screen shares unless the person hides it. */
  shareNotice?: string | null;
}

/** `config.app_update`: this version is below the server's minimum; `refused`: its other requests are refused. */
export interface AppUpdateNotice {
  minimum: string;
  required: boolean;
  refused: boolean;
  message: string;
  url: string | null;
}
/** `config.announcement`: a banner until `until` (none: until it is taken down). */
export interface AnnouncementNotice {
  text: string;
  level: "info" | "warning" | string;
  until: string | null;
}
export interface MaintenanceNotice {
  message: string;
  until: string | null;
}
/** The update, the announcement, maintenance, and why uploads wait (none: they go as usual). */
export interface ServerNotices {
  appUpdate: AppUpdateNotice | null;
  announcement: AnnouncementNotice | null;
  maintenance: MaintenanceNotice | null;
  waiting: "maintenance" | "update_required" | null;
}

/** `GET /api/auth/options` (Phase 1C): what this server's sign-in offers; links only on the server's own address. */
export interface AuthOptions {
  password: boolean;
  google: boolean;
  signUp: boolean;
  signUpUrl: string | null;
  resetUrl: string | null;
  browserSignIn: boolean;
  /** Email codes are offered (since v1.6). */
  code: boolean;
  /** What a new password needs (since 2.0.0-l); null from an older server. */
  passwordRules?: { min: number; letters: boolean; numbers: boolean } | null;
}

/** "Email → Continue" (since v1.6): `older` is a server without the route (the v1.5 sign-in then). */
export interface AuthStart {
  account: "existing" | "new" | "older";
  methods: string[];
  signUp: boolean;
}
/** A code on its way, or (`sent: false`) one went out a moment ago and another may be asked for in `resendAfter` s. */
export interface CodeSent {
  resendAfter: number;
  expiresIn: number | null;
  sent: boolean;
}
export type CodeResult = "signed_in" | "otp_required" | "profile";
export interface CodeOutcome {
  result: CodeResult;
  status: AppStatus | null;
}

/** `GET /api/me/entitlements` (since v1.6), as the server writes it; the core drops malformed parts and odd colours. */
export interface Entitlements {
  plan: EntitlementPlan | null;
  features: Record<string, FeatureEntitlement>;
  limits: Record<string, LimitEntitlement>;
  engines: string[];
  transcription_key: TranscriptionKeyState | null;
  upgrade_request: UpgradeRequest | null;
  plans: OfferedPlan[];
}
export interface EntitlementPlan {
  key: string;
  name: string;
  badge: string | null;
  color: string | null;
  expires_at: string | null;
  trial_minutes_left: number | null;
}
export interface FeatureEntitlement {
  enabled: boolean;
  name: string | null;
  description: string | null;
  cost: string | null;
  lowest_plan: string | null;
}
export interface LimitEntitlement {
  limit: number | null;
  used: number | null;
  /** Since v1.7, `monthly_minutes` only: when the minutes start again (the 1st of next month). */
  resets_at?: string | null;
}
export interface TranscriptionKeyState {
  provider: string | null;
  set: boolean;
  last4: string | null;
}
export interface UpgradeRequest {
  id: string;
  plan: string;
  status: "pending" | "approved" | "declined" | string;
  message: string | null;
  payment_instructions: string | null;
  created_at: string | null;
  decided_at: string | null;
  reply: string | null;
  /** Since v1.6.2: the promo code on the request, its discount and the price after it. */
  promo_code?: string | null;
  percent?: number | null;
  amount_due?: string | null;
  /** Since v1.6.2: a 100 % code approves at once; the plan switched on and until when. */
  approved_plan?: string | null;
  approved_until?: string | null;
  /** Since v1.7 (payment receipts): the reference for the transfer, the amount in rupees, how to pay, the steps. */
  reference_code?: string | null;
  amount_due_pkr?: number | null;
  currency?: string | null;
  payment?: PaymentState | null;
  timeline?: TimelineStep[];
}
/** Where a request stands (since v1.7), computed by the server. */
export type PaymentStage = "awaiting_payment" | "in_review" | "needs_receipt" | "approved" | "declined" | "cancelled";
export interface PaymentState {
  stage: PaymentStage | string;
  accounts: PaymentAccount[];
  note: string | null;
  review_hours: string | null;
  paid_at: string | null;
  reference: string | null;
  payer_name: string | null;
  proof_request: { note: string | null; at: string | null } | null;
  proofs: Proof[];
  max_proofs: number | null;
  max_bytes: number | null;
  accepts: string[];
}
export interface PaymentAccount {
  bank: string | null;
  branch: string | null;
  title: string | null;
  account_number: string | null;
  iban: string | null;
}
/** One receipt on a request; its picture is read through the core (`receiptFile`). */
export interface Proof {
  id: string;
  kind: "image" | "pdf" | string;
  mime: string | null;
  name: string | null;
  bytes: number | null;
  width: number | null;
  height: number | null;
  created_at: string | null;
  locked: boolean;
}
export interface TimelineStep {
  step: "requested" | "receipt_sent" | "receipt_requested" | "decided" | "cancelled" | string;
  at: string | null;
}
/** A receipt's picture from the core: its type and base64 bytes, for a `blob:` URL that is let go on close. */
export interface ProofFile {
  mime: string;
  data: string;
}
/** `POST /api/promo-codes/check` (since v1.6.2): a code that applies to the plan asked. */
export interface PromoCheck {
  code: string;
  plan: string;
  plan_name: string | null;
  percent: number | null;
  price: string | null;
  final_price: string | null;
  /** A 100 % code: nothing to pay, the plan switches on at once. */
  free: boolean;
  months: number | null;
}
/** What the sheet hears about a promo code; `unsupported` is an older server without them. */
export type PromoAnswer =
  | { result: "valid"; check: PromoCheck }
  | { result: "invalid"; message: string }
  | { result: "other_plan"; plan: string; message: string }
  | { result: "unsupported" };
export interface OfferedPlan {
  key: string;
  name: string;
  badge: string | null;
  color: string | null;
  price: string | null;
  /** Since v1.7: the price in whole rupees and as written ("Rs 2,999 a month"); highlights when the server sends them. */
  price_pkr?: number | null;
  price_text?: string | null;
  highlights?: string[];
  description: string | null;
  features: string[];
  limits: Record<string, number | null>;
}

/** The Ready check (Phase 1C): one check per thing that can stop the next call from being recorded. */
export type ReadyFixAction = "sign_in" | "allow_microphone" | "allow_system_audio" | "open_sound_settings" | "software_update"
  | "turn_on_auto_mode" | "turn_on_apps" | "restart_detection" | "open_shortcuts" | "open_share_privacy";
export interface ReadyCheck {
  id: "account" | "microphone" | "microphone_permission" | "other_side" | "detection" | "shortcuts" | "overlay_protection";
  label: string;
  ok: boolean;
  detail: string | null;
  fix: { label: string; action: ReadyFixAction } | null;
  /** Shown with its state and fix, but never makes the computer "Not ready" (shortcuts, screen sharing; since v2.0). */
  advisory: boolean;
}
export interface ReadyReport {
  ready: boolean;
  checks: ReadyCheck[];
  checkedAt: string;
}
/** After a call where one side was never heard (`capture:one_sided`, Phase 1C). */
export interface OneSided {
  title: string;
  reason: string;
  fix: string;
}

/** Notification categories (Phase 1C), the phone's channels; `call_capture` is Critical (never held by quiet hours). */
export type NotificationCategory = "recording" | "transcription" | "enhancement" | "uploads" | "call_capture" | "sharing"
  | "account" | "usage" | "plan_payments" | "live_translate" | "app_updates" | "other";

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
  /** The server's operator (v1.6 shell; false when the server does not say): the avatar menu offers the admin panel. */
  super_admin?: boolean;
  /** Every workspace of the person (v1.6 shell; empty when the server does not list them). */
  workspaces?: { id: unknown; name: string; role?: string | null; current?: boolean | null }[];
  /** The person's picture (since 2.0.0-l): a signed link and the version it is kept by; null shows the initials. */
  avatar_url?: string | null;
  avatar_version?: number | null;
  /** The colour of the initials' circle without a photo, 0–7 (since 2.0.0-r, BE-06); left out, one from the email. */
  avatar_color?: number | null;
  /** The account has a password (one made with Google may not); null from a server before 2.0.0-l. */
  has_password?: boolean | null;
  /** An email change waiting for its code, and until when. */
  pending_email?: string | null;
  pending_email_expires_at?: string | null;
  passkeys_available?: boolean | null;
}

/** Signed updates from the server (since v1.5). */
export type UpdatePhase = "idle" | "checking" | "up_to_date" | "downloading" | "ready" | "failed" | "unavailable";
/** The Mac menu bar the page draws (app_menu.rs app_menu_set; built by v2/actions/menuBar.ts). */
export type NativeMenuBar = { menus: { title: string; role?: "window" | "help"; items: NativeBarEntry[] }[] };
export type NativeBarEntry =
  | {
    kind: "item"; action: string; value?: string; label: string; accelerator: string | null; enabled: boolean; checked: boolean | null;
    /** One of the core's own commands: what its look follows while there is no page (app_menu.rs CoreItem). */
    core?: { when: string[]; state: string | null; off: string | null; on: string | null; check: string | null };
  }
  | { kind: "native"; action: string; native: string }
  | { kind: "separator" }
  | { kind: "submenu"; action?: string; label: string; enabled: boolean; items: NativeBarEntry[] };

/** One line of a native menu (native_menu.rs): the action it sends (none: a line), its words, keys, tick. */
export interface NativeMenuItem {
  /** The action it sends; none: a hairline, or with a label a greyed title line ("3 Recordings"). */
  action: string | null;
  label?: string;
  /** Tauri's accelerator ("CmdOrCtrl+Shift+N"). */
  accelerator?: string | null;
  enabled?: boolean;
  checked?: boolean | null;
  /** What it acts on, when that isn't the selection (the project right-clicked in the panel's tree): "action:<id>@<target>". */
  target?: string | null;
  /**
   * A sub-menu's choice (UX-22): picked, it comes back as `<action>=<value>`. A target or value is an id we build (a
   * project's, a label's, a sort key), never a display name; the core percent-encodes any "%", "=", "@" or "~" in it.
   */
  value?: string | null;
  /** A sub-menu's items (one level). */
  items?: NativeMenuItem[] | null;
}

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
  installId: string | null; // since v1.6: sent with every sign-in, never exported
  signInId: string | null; // since 2.0.0-p: made at each sign-in, forgotten at sign-out (AppStatus.signIn)
  shortcuts: Partial<Record<ShortcutAction, string>>;
  captionsOnRecord: boolean;
  autoMode: boolean;
  hideFromSharing: ShareHiding; // since v1.8.1, replaces contentProtection; since v2.0 the other floating windows only
  mainShareHide: MainShareHide | null; // since v2.0: the main window hidden from screen shares, and until when
  shareNotice: boolean; // the one-time notice after the v2.0 update
  oldWindowsSharing: OldWindowsSharing; // Windows before build 19041
  shareOneWindow: boolean; // "I share one window or a tab" (macOS)
  sendDiagnostics: boolean; // Share Check results to the server, off until turned on
  overlayCorner: Corner;
  autostart: boolean;
  menuBarIcon: boolean; // FIX-11: EchoLedger's icon in the Mac menu bar
  menuBarTimer: boolean; // FIX-11: the recording's time beside it
  dockBadge: boolean; // FIX-11: the Activity number on the Dock icon
  dictationOutput: "type" | "clipboard";
  clientId: string | null;
  consentAcknowledged: boolean;
  afterCall: AfterCall;
  afterCallNotice: boolean;
  askWhen: AskWhen; // "When to ask" under Ask me
  cardHintSeen: boolean; // the card's first-time line was shown
  cardStreak: CardStreak | null; // the same card choice in a row
  alwaysOffered: boolean; // "Always do this?" was offered (once)
  skipCallsShorterThanS: number;
  askUnknownApps: boolean;
  siteRules: SiteRule[];
  captionLines: number;
  pillPosition: PillPosition | null;
  assistCardPosition: PillPosition | null;
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
  notificationCategories: Partial<Record<NotificationCategory, boolean>>;
  notificationSounds: Partial<Record<NotificationCategory, boolean>>;
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
  hideFromSharing?: ShareHiding;
  oldWindowsSharing?: OldWindowsSharing;
  shareOneWindow?: boolean;
  sendDiagnostics?: boolean;
  autostart?: boolean;
  menuBarIcon?: boolean;
  menuBarTimer?: boolean;
  dockBadge?: boolean;
  dictationOutput?: "type" | "clipboard";
  clientId?: string | null;
  consentAcknowledged?: boolean;
  overlayCorner?: Corner;
  afterCall?: AfterCall;
  askWhen?: AskWhen;
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
  notificationCategories?: Partial<Record<NotificationCategory, boolean>>;
  notificationSounds?: Partial<Record<NotificationCategory, boolean>>;
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
/** A pane of the system's settings the app can open. */
export type SettingsPane = PermissionKind | "automation" | "sound" | "software_update";

/** Something the system does not let EchoLedger use, and what a recording holds without it. */
export interface MissingPermission {
  kind: PermissionKind;
  sentence: string;
  /** A call's other side found not recorded by the recording itself (2.0.6): a tap held back (System Audio
   * Recording). A long silence is the 30-second "not hearing the other side" warning (`capture:far_side`) instead. */
  otherSide?: "permission";
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
  /** macOS: allowed before on this computer, "not asked" now (D-120): another copy of EchoLedger may hold it, or the
   * privacy settings were reset, so it is said as a possibility. */
  allowedBefore?: PermissionKind[];
  /** macOS: Screen Recording still reads `reopen` although EchoLedger was reopened since it was allowed: not Quit &
   * reopen again, but switch EchoLedger off and on in System Settings. */
  reopenDidNotApply?: boolean;
  /** macOS: system audio and Screen Recording are read through the privacy call that tells "not asked" apart; `false`:
   * their `unknown` says nothing (D-136). Missing (an older core): as if it could. */
  canTellNotAsked?: boolean;
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
  /** A button to the System Settings pane that fixes it, or, with `reopen`, Quit & reopen (Screen Recording allowed while
   * EchoLedger ran). */
  fix: { label: string; settings: SettingsPane; reopen?: boolean } | null;
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
 * A process holding the microphone and why EchoLedger does or does not record it (Settings › Calls & meetings).
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

/** Live Assist (docs/API_ASSIST.md 1.9.0-g): the core's side of the card's commands (desktop/src/v2/assist/adapter.ts). */
export type AssistMeetingType = "interview" | "boss" | "existing_client" | "new_client" | "general";
export type AssistHintLevel = "keywords" | "outline" | "full";
export type AssistMode = "fast" | "balanced" | "deep" | "max";
export type AssistLanguageHelp = "off" | "urdu" | "roman_urdu" | "arabic";
export type AssistPrepStatus = "draft" | "scheduled" | "armed" | "done" | "cancelled";
export interface AssistPrepDocument {
  file_id: string;
  name: string;
  tokens: number | null;
}
export interface AssistPrepDraft {
  id?: string;
  meeting_type: AssistMeetingType;
  title: string;
  counterpart: string;
  company: string;
  project_id: string | null;
  goals: string;
  done: string;
  not_done: string;
  to_discuss: string;
  instructions: string;
  hint_level: AssistHintLevel;
  mode: AssistMode;
  language_help: AssistLanguageHelp;
  scheduled_at: string | null;
}
export type AssistPrep = AssistPrepDraft & { id: string; status: AssistPrepStatus; documents: AssistPrepDocument[] };
export interface AssistStarted {
  session_id: string;
  /** Started from a prep: the call is saved without the after-call card. */
  auto_saved: boolean;
  mode: AssistMode | "captions_only";
  allowed_modes: AssistMode[];
  seconds_left: number | null;
  practice: boolean;
}
export type AssistSendKind = "help_now" | "regenerate" | "shorter" | "formal" | "translate" | "cancel";
export interface AssistCardSettings {
  mode?: AssistMode;
  hint_level?: AssistHintLevel;
  paused?: boolean;
  muted_seconds?: number;
}
/** `assist:message`: the relay's messages as it sends them (without `seq`) and the core's `turn`, `budget` and `ended`. */
export type AssistMessage =
  | { type: "ready"; session_id: string; mode: AssistMode | "captions_only"; requested_mode: AssistMode; allowed_modes: AssistMode[]; resumed: boolean; practice: boolean; meeting_type?: AssistMeetingType }
  | { type: "suggestion_start"; suggestion_id: string; turn_id: string | null; kind: "answer" | "help_now" | "regenerate" | "shorter" | "formal" | "translate" | "upgrade" | "other"; speculative: boolean; replaces: string | null; mode: string; model: string; draft: boolean }
  | { type: "token"; suggestion_id: string; text: string }
  | { type: "suggestion_end"; suggestion_id: string; status: "done" | "cancelled" | "superseded" | "other" }
  | { type: "skip"; suggestion_id: string; turn_id?: string | null }
  | { type: "mode_changed"; from: string; to: string; reason: "budget" | "budget_restored" | "plan" | "assist_time" | "requested" | "other"; requested?: AssistMode | null }
  | { type: "error"; code: string; message: string; retry: boolean; suggestion_id: string | null; in_reply_to: number | null; fatal: boolean }
  | { type: "turn"; turn_id: string; channel: "me" | "other"; text: string; final: boolean }
  | { type: "budget"; seconds_left: number | null; seconds_total: number | null }
  | { type: "session_active"; session_id: string; practice: boolean; text: string; action: string }
  | { type: "offer"; prep_id: string; title: string; caller: string | null; question: string; client_ref: string }
  | { type: "ended"; reason: "closed" | "replaced" | "wrong_session" | "insecure" | "gave_up" | "token_rejected" | "app_update_required"; message: string | null };
/** `assist_state`: the session so far, for a window opened mid-session. */
export interface AssistSnapshot {
  messages: AssistMessage[];
  hint_level: AssistHintLevel;
  paused: boolean;
  muted_until: string | null;
  meeting_type: AssistMeetingType | null;
}

/** The interface flags (contract 2.0.0-a): every flag the app knows, on or off, for this person, and the "classic"
 * choice; `ui:flags` carries the same when the cached flags change. */
export interface UiFlagsAnswer {
  ui_flags: Record<string, boolean>;
  ui_classic: boolean;
  /** The design switch (since 2.0.0-g); null from an older server (show only the 2.0.0-a switch). */
  ui_design?: UiDesign | null;
}
/** `ui_design` (docs/API.md "The design switch"): show Try the new design while `can_try`, Switch back to classic
 * while `can_switch_back`. */
export interface UiDesign {
  choice: "v2" | "classic" | null;
  current: "v2" | "classic";
  can_try: boolean;
  can_switch_back: boolean;
}
/** The same, by the name the design switch's reader uses (v2/flags.ts). */
export type UiDesignAnswer = UiDesign;

/** The last app that used the microphone without being recorded, and when it was last seen so (kept by the core). */
export interface NotRecordedMic {
  user: MicUser;
  /** RFC 3339. */
  at: string;
}

/** One of the person's lists (contract 2.0.0-m): a label with its colour and place in the Library's row. */
/** A saved view as the server keeps it (contract 2.0.0-p): a filter set, the search and how the list is shown. */
export interface ServerView {
  id: string;
  name: string;
  filters: Record<string, unknown>;
  search: string | null;
  sort: string;
  group: string;
  density: string;
  position: number;
  client_ref?: string | null;
}

/** A view change the server refused for good (a 4xx: `views_limit`, `view_not_found`, `validation_failed`…), its
 * message in the app's words; offline and passing trouble are errors instead (the change is sent again later). */
export interface ViewRefusal {
  status: number;
  code: string | null;
  message: string;
}
export type ViewAnswer<T> = T | { refused: ViewRefusal };

export interface ListItem {
  /** The name in lower case. */
  key: string;
  name: string;
  /** "#rrggbb", or null (the neutral colour). */
  color: string | null;
  position: number;
  /** The person's recordings in it, not in Trash. */
  count: number;
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
  /** The server named it from what was said (Phase 1C); false or missing for a title a person set. */
  title_auto?: boolean;
  started_at?: string;
  duration_ms?: number | null;
  status?: string;
  /** Bookmarks on the recording and the distinct tags among them (since v1.1). */
  marker_count?: number;
  tags?: string[];
  /** Pinned to the top and recording labels (since v1.1, B15). */
  pinned?: boolean;
  labels?: string[];
  /** The person's colour of each label that has one, label as spelt here => "#rrggbb" (since 2.0.0-i). */
  label_colors?: Record<string, string>;
  /** The person flagged it (since 2.0.0-i). */
  important?: boolean;
  /** The person has opened it (since v1.3): false is unread. */
  opened?: boolean;
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
    /**
     * The views of the final transcript (since 2.0.0-n on a row): "making" from the moment the transcript is done until
     * the default view is written (`progress` `{}` until the first lines are), "failed" when it stopped or went quiet.
     */
    views?: ViewsSummary | null;
  };
  /** How it got into its project (since v1.5): by hand, the caller is a contact, a filing rule; null = the Inbox. */
  filed_by?: "user" | "contact" | "rule" | null;
  /** When it ended (Needs you's 24 hours are counted from it when no attempt is under way). */
  ended_at?: string | null;
  /** When its current upload or transcript attempt began (since 2.0.0-p): Needs you's 24 hours are counted from it. */
  processing_since?: string | null;
  /** Its value in the Library's Source, Status and Language filters, as the server works them out (since 2.0.0-p). */
  facets?: { source?: string; status?: string; language?: string } | null;
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
  /** The recording's language, changed without re-transcribing it (null: automatic). */
  language?: TranscriptionLanguage | null;
}
export type BulkAction = "move" | "label" | "unlabel" | "pin" | "unpin" | "keep" | "unkeep" | "important" | "unimportant" | "delete";
/** A recording in the undo bin (B17; 48 hours unless the server says otherwise, lib/trash.ts). */
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

/** Parts done of all parts while a recording is transcribed (since v1.2; for every run since v1.5 Phase 1C, `total` 1 for
 * a run in one piece), and the time left from the server's measured speed (null until there is a measure; missing on an
 * older server). */
export interface TranscriptProgress {
  done: number;
  total: number;
  eta_seconds?: number | null;
}

/** Lines done of lines per view while the views are being made (`views.progress`, Phase 1C); `{}` otherwise. */
export type ViewsProgress = Partial<Record<TranscriptView, { done: number; total: number }>>;

/** A transcript's views as a Library row and the transcript detail carry them: their state, the view the apps open in, how far. */
export interface ViewsSummary {
  status: TranscriptViewsStatus;
  default?: "original" | TranscriptView;
  progress?: ViewsProgress;
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
  views?: { available: TranscriptView[]; status: TranscriptViewsStatus; default?: "original" | TranscriptView; progress?: ViewsProgress } | null;
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
  /** Lines done per view while making (Phase 1C). */
  progress?: ViewsProgress;
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
/** What this person sent of a recording or a project (`GET /api/sent`, since v1.5). */
export interface SentItem {
  id: string;
  kind: "session" | "project";
  to: { name: string | null; email: string | null } | null;
  title: string | null;
  note: string | null;
  sent_at: string | null;
  saved_at: string | null;
}

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
  | { kind: "clear" }
  /** The latency probe (development only): the caption just sent carried word `id`; say when it is painted. */
  | { kind: "probe"; id: number };

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
  /** Screens kept for this recording on this computer (the after-call card's "3 screens"). */
  screens?: number;
  /** Back in the waiting list because its Delete was still waiting when EchoLedger closed (restored_delete). */
  restoredDelete?: boolean;
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
  /** Who the call is with, once its meeting window's title says; null until then. */
  callerName?: string | null;
  /** Chosen on the pill's menu: what happens when the call ends (no card then). */
  callChoice?: CallChoice | null;
  /** "Recording this call · Don't record" beside the pill (its first 5 s, "At the start of the call"). */
  startBanner?: boolean;
}

/** Why screen moments are paused (since v1.5): by the person, the recording paused, the meeting window minimised or
 * hidden, no meeting window, an excluded app showing, macOS permission missing, 120 this hour, the project being read,
 * or (since v2.0) the server keeping no more screens of this recording (`409 too_many_screens`). */
export type ScreenHoldReason = "you" | "recording_paused" | "minimised" | "no_window" | "excluded_app" | "permission" | "hour_cap" | "starting" | "limit";

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
  /** Since v1.8 P4.2 (docs/API.md 2.0.0-e): its name in the meeting folder, "S07_14-32_orders-page.webp". */
  file_name?: string;
  /** Since v1.8 P4.2: what was said within 15 s of it, a "Speaker: words" line each; null when nothing was. */
  said?: string | null;
}

/** `live_translate_status`: whether Live Translate can run on this computer and plan, and why not. */
export interface LiveTranslateAvailability {
  available: boolean;
  reason: LiveTranslateUnavailable | null;
}

/** `GET /api/help/guides?q=` (2.0.0-d): a section of the user guide. */
export interface HelpGuideHit {
  id: string;
  title: string;
  excerpt: string;
}

/** `GET /api/help/guides/{id}`. */
export interface HelpGuide {
  id: string;
  title: string;
  markdown: string;
}

/** Why Live Translate cannot start here: this system has no capture of one app's sound for it yet (a Mac), or the plan
 * leaves it out. */
export type LiveTranslateUnavailable = "platform" | "plan";

/** What a message to support attaches when the person asks for the device report (`last_device_report`): the last
 * report this computer sent for this account with Send to my admin, and when the server took it; null when none yet. */
export interface LastDeviceReport {
  sentAt: string | null;
}

/** `GET /api/help/whats-new?platform=` (2.0.0-d): one release's notes. */
export interface WhatsNewRelease {
  version: string;
  platform: string;
  released_at: string | null;
  notes: string;
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

/** "Transcribing…", "Saved as audio · Transcribe", "Deleted", each with Undo; `until` is when it goes and the choice is
 * applied. `offerAlways`: "Always do this?" [Yes] sets After each call to it (offered once). */
export interface CallDone {
  kind: CallChoice;
  id: string;
  title: string | null;
  callerName: string | null;
  sourceApp: string;
  mode: "call" | "voice_note" | "mic_note";
  durationMs: number;
  until: string;
  offerAlways: AfterEachCall | null;
}

/** "3 calls saved as audio · Review": back-to-back calls, never a stack of cards. Each call keeps what happens to it
 * and its Undo (the newest last); the choices are applied when the card goes (`until`). */
export interface CallGroup {
  count: number;
  until: string;
  calls: CallDone[];
}

/** What the after-call window shows, one at a time: the card (its item's `deadlineAt` is when it saves the audio only),
 * one confirmation, or the grouped card. `hint`: the card's first time ever. */
export interface AfterCallState {
  ask: PendingItem | null;
  hint: boolean;
  /** The rest of a grouped card under a reopened card (Undo on one of its rows), each with its own Undo. */
  held: CallDone[];
  done: CallDone | null;
  group: CallGroup | null;
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
  /** A Settings page or setting to open (since v1.8.1: the privacy badge opens "share_privacy.check"). */
  section?: string | null;
}

// ---- Screen-share privacy (since v1.8.1) -------------------------------------------------------------------------------

/** The privacy badge on every floating window: from the last real check. */
export type PrivacyBadge = "private" | "black_box" | "not_checked" | "visible";
export type CheckOutcome = "pass" | "fail" | "error";
/** Why a Share Check ran. */
export type CheckWhy = "asked" | "onboarding" | "first" | "app_update" | "system_update" | "weekly" | "before_call" | "retry";
export type MeetingApp = "zoom" | "teams" | "meet" | "slack" | "whatsapp";

/** How Send to WhatsApp → Text and voice ended (F6): the share took it (`toWhatsApp`: WhatsApp was the target), the
 *  share is open and its end not known (`opened`: nothing is claimed), the person closed the share, or wa.me opened with
 *  the text and the voice saved at `path` (on the clipboard, to paste with `pasteKeys`, when `fileOnClipboard`). */
export type WhatsAppVoiceOutcome =
  | { kind: "shared"; toWhatsApp: boolean }
  | { kind: "opened" }
  | { kind: "cancelled" }
  | { kind: "fallback"; path: string; fileOnClipboard: boolean; textCut: boolean; pasteKeys: string };
/** What the guided test says of a meeting app on this Mac. */
export type MeetingAppState = "hidden" | "visible" | "not_checked" | "share_tab";

export interface CheckMethod {
  method: string;
  result: CheckOutcome;
  /** `permission_missing` | `capture_failed` | `control_missing`. */
  reason: string | null;
}

/** One Share Check run, as the page shows it: the captured screen stays in this object only (never kept). */
export interface ShareCheckView {
  result: CheckOutcome;
  reason: string | null;
  methods: CheckMethod[];
  system: string;
  at: string;
  /** "What others see": the captured screen, scaled down (a data: URL). */
  picture: string | null;
  width: number;
  height: number;
  /** Fractions of the picture: [x, y, w, h]. */
  testArea: [number, number, number, number] | null;
  controlArea: [number, number, number, number] | null;
}

export interface MeetingAppRow {
  app: MeetingApp;
  name: string;
  version: string | null;
  state: MeetingAppState;
  line: string;
  needsTest: boolean;
}

export interface PrivacyLine {
  warning: boolean;
  text: string;
  /** The one button that fixes it: the system's Screen Recording settings (Mac), or setting the flags again (Windows). */
  fix: "open_settings" | "hide_again" | null;
}

export interface LastCheck {
  at: string;
  result: CheckOutcome;
  reason: string | null;
  methods: CheckMethod[];
}

export interface ShareSeen {
  app: MeetingApp | null;
  mode: "screen" | "window" | "tab";
  hid: boolean;
}

/** Until when the main window is hidden from screen shares (v2.0). */
export type HideUntil = { kind: "call_ends" } | { kind: "at"; at_ms: number } | { kind: "until_off" } | { kind: "until_quit" };
export interface MainShareHide {
  until: HideUntil;
  sinceMs: number;
}
/** What the person picks under "Hide from screen shares". */
export type HideFor =
  | { kind: "call_ends" } | { kind: "minutes15" } | { kind: "hour1" } | { kind: "tomorrow" } | { kind: "until_off" } | { kind: "until_quit" }
  | { kind: "custom"; minutes: number };
/** The two windows hidden from screen shares unless the person shows them, each on its own. */
export type PinnedWindow = "card" | "captions";
/** What the system reads back for that window: never only the saved choice. */
export type WindowShareState = "hidden" | "shown" | "black_box" | "could_not_hide" | "not_open";
export interface WindowShareView {
  which: PinnedWindow;
  /** The person showed it in screen shares from its own badge (until it closes; never saved). */
  shown: boolean;
  state: WindowShareState;
  badge: string;
  switchLabel: string;
}

export interface PrivacyStatus {
  platform: string;
  badge: PrivacyBadge;
  badgeLabel: string;
  system: string;
  hiding: ShareHiding;
  oldWindows: boolean;
  lines: PrivacyLine[];
  demoSecondsLeft: number | null;
  hiddenByShortcut: boolean;
  hiddenForShare: boolean;
  /** The person opened the main window during a whole-screen share: it says it may be in the screen share. */
  mainInShare: boolean;
  /** The main window is hidden from screen shares (v2.0), and until when ("until 14:30"); null: shown. */
  mainHide?: MainShareHide | null;
  mainHiddenUntil?: string | null;
  /** A call is detected: "Until this call ends" is offered. */
  callDetected?: boolean;
  showCard?: boolean;
  showCaptions?: boolean;
  /** Which whole-screen share that is, counted in this run: the notice closed in one share shows again in the next. */
  shareNumber: number;
  /** The share's sharing bar is on screen: "Show EchoLedger windows" is not offered (they come back when it ends). */
  barOnScreen: boolean;
  share: ShareSeen | null;
  lastCheck: LastCheck | null;
  checkRunning: boolean;
  checkDue: CheckWhy | null;
  apps: MeetingAppRow[];
  zoomInstalled: boolean;
  permission: boolean;
  /** One of the floating windows is open: "Move to the other display" has something to move. */
  floatingOpen: boolean;
  displays: number;
}

/** Events the core emits to the webviews and the payload each one carries (none = `null`). */
export interface EventPayloads {
  "app:status": AppStatus;
  "ui:flags": UiFlagsAnswer;
  "assist:message": AssistMessage;
  /** "Capture screen now" kept the screen (the pill's flash); `count`: the recording's screens so far. */
  "screens:captured": { count: number };
  /** The words, or (since v1.6.1) the words and where the notice goes (lib/notice.ts NoticePayload). */
  "app:error": string | { text: string; target?: unknown };
  "app:toast": string | { text: string; target?: unknown };
  "library:updated": number;
  "overlay:visibility": boolean;
  "settings:changed": Settings;
  "recording:state": RecordingState;
  "pill:visibility": boolean;
  "pending:changed": PendingItem[];
  "skipped:changed": SkippedItem[];
  /** Another recovered recording reached the server (`recovered_sessions` has it). */
  "recovered:changed": null;
  "aftercall:state": AfterCallState;
  "ask:show": AskPayload;
  "ask:hide": null;
  "uploads:changed": UploadItem[];
  "main:navigate": NavigatePayload;
  "recording:marked": MarkPayload;
  "capture:far_side": FarSidePayload;
  "mac_check:progress": CheckProgress;
  "capture:test_level": [number, number];
  /** `permission`: the one that kept dictation from starting (the box offers its settings), else null. */
  "dictation:state": { active: boolean; sounds: boolean; mode: DictationMode; permission?: PermissionKind | null };
  "dictation:level": number;
  "lock:changed": LockStatus;
  "activity:updated": number;
  "signin:done": AppStatus;
  "signin:failed": string;
  /** A link of another browser sign-in (an earlier tab): what to say while this one keeps waiting. */
  "signin:other": string;
  "update:status": UpdateStatus;
  /** A native menu's pick (native_menu.rs): the action id to run. */
  "menu:action": string;
  "ready:changed": ReadyReport;
  "capture:one_sided": OneSided;
  /** Something about screen-share privacy changed: read `privacy_status` again (since v1.8.1). */
  "privacy:changed": null;
  /** EchoLedger's windows are off the screen (the shortcut or the Mac safety net): draw nothing. */
  "privacy:conceal": boolean;
}

export type AppEvent = keyof EventPayloads;

/** The server's public pages the app opens in the browser (since v1.5). */
/** The server's pages the apps open in the browser (v1.6: the console's admin panel, workspace and profile pages). */
export type ServerPage = "terms" | "privacy" | "account/delete" | "downloads" | "admin" | "app/workspace" | "app/profile" | "app/settings/security/passkeys";
/** The words the core uses while the server holds this computer's work (error.rs: MAINTENANCE_TEXT, UPDATE_REQUIRED_TEXT). */
export const MAINTENANCE_TEXT = "The server is in maintenance for a while. Your recordings wait on this computer and upload when it is back.";
export const UPDATE_REQUIRED_TEXT = "This version of EchoLedger is no longer accepted by the server. Update it: your recordings wait on this computer and upload after the update.";

export const api = {
  status: () => invoke<AppStatus>("app_status"),
  // Screen-share privacy (since v1.8.1).
  privacyStatus: () => invoke<PrivacyStatus>("privacy_status"),
  shareCheckRun: (why: CheckWhy | null = null) => invoke<ShareCheckView>("share_check_run", { why }),
  shareCheckDrawn: () => invoke<void>("share_check_drawn"),
  shareCheckCard: (open: boolean) => invoke<void>("share_check_card", { open }),
  shareCheckAnswer: (meetingApp: MeetingApp, visible: boolean) => invoke<PrivacyStatus>("share_check_answer", { meetingApp, visible }),
  privacyShowInShares: (minutes: number) => invoke<void>("privacy_show_in_shares", { minutes }),
  /** "Hide from screen shares" for the main window, until the time chosen (v2.0). */
  privacyMainHide: (choice: HideFor) => invoke<PrivacyStatus>("privacy_main_hide", { choice }),
  /** "Show now": the main window is in screen shares again. */
  privacyMainShow: () => invoke<PrivacyStatus>("privacy_main_show"),
  /** The card's or the captions' real state, read back from the system; the main window names the one it means. */
  privacyWindowShare: (which: PinnedWindow | null = null) => invoke<WindowShareView>("privacy_window_share", { which }),
  /** That window's own switch: shown in screen shares only from its own badge; the main window may only hide it. */
  privacyWindowShareSet: (shown: boolean, which: PinnedWindow | null = null) => invoke<WindowShareView>("privacy_window_share_set", { which, shown }),
  shareNoticeDismiss: () => invoke<void>("share_notice_dismiss"),
  /** The latency probe (development only): word `id` is on screen. */
  captionProbePainted: (id: number) => invoke<void>("caption_probe_painted", { id }),
  privacyConcealed: () => invoke<boolean>("privacy_concealed"),
  privacyHideAll: () => invoke<void>("privacy_hide_all"),
  /** Windows: sets every capture flag again after something else on the computer changed one. */
  privacyHideAgain: () => invoke<void>("privacy_hide_again"),
  /** "Hide EchoLedger windows" (`hidden`) or "Show EchoLedger windows". */
  privacySetHidden: (hidden: boolean) => invoke<void>("privacy_set_hidden", { hidden }),
  privacyOpenCheck: () => invoke<void>("privacy_open_check"),
  privacyDiagnostics: () => invoke<string>("privacy_diagnostics"),
  /** The floating windows to the display that isn't the main one (so the main one can be shared). */
  privacyMoveToOtherDisplay: () => invoke<void>("privacy_move_to_other_display"),
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
  /** What the sign-in screen offers on this server (Phase 1C); null from an older server (the screen stays as before). */
  authOptions: (serverUrl: string) => invoke<AuthOptions | null>("auth_options", { serverUrl }),
  /** Create account or Forgot password: the server's own page in the browser. */
  openAuthPage: (serverUrl: string, url: string) => invoke<void>("auth_page_open", { serverUrl, url }),
  // Sign-up and sign-in with an email code (since v1.6). A new email's sign-up token stays in the core.
  authStart: (serverUrl: string, email: string) => invoke<AuthStart>("auth_start", { serverUrl, email }),
  sendCode: (serverUrl: string, email: string, purpose: "sign_in" | "sign_up") => invoke<CodeSent>("auth_code_send", { serverUrl, email, purpose }),
  verifyCode: (serverUrl: string, email: string, code: string, otp: string | null, deviceName: string) =>
    invoke<CodeOutcome>("auth_code_verify", { serverUrl, email, code, otp, deviceName }),
  signUp: (name: string, acceptTerms: boolean) => invoke<AppStatus>("auth_sign_up", { name, acceptTerms }),
  // What this person may use, their own key, upgrades (since v1.6); null entitlements from an older server.
  entitlements: () => invoke<Entitlements | null>("entitlements_get"),
  setTranscriptionKey: (key: string) => invoke<TranscriptionKeyState>("transcription_key_set", { key }),
  deleteTranscriptionKey: () => invoke<void>("transcription_key_delete"),
  /** console.groq.com's API keys page in the browser, where the free key is made. */
  openGroqConsole: () => invoke<void>("groq_console_open"),
  requestUpgrade: (plan: string, message: string | null, promoCode: string | null = null) => invoke<UpgradeRequest>("upgrade_request_create", { plan, message, promoCode }),
  checkPromoCode: (code: string, plan: string | null) => invoke<PromoAnswer>("promo_code_check", { code, plan }),
  latestUpgradeRequest: () => invoke<UpgradeRequest | null>("upgrade_request_latest"),
  // Payment receipts (since v1.7): the core reads, checks and strips each file, and fetches their pictures.
  pickReceipts: () => invoke<string[]>("upgrade_receipt_pick"),
  sendReceiptFile: (id: string, path: string) => invoke<Proof>("upgrade_request_proof", { id, path }),
  sendReceiptData: (id: string, name: string | null, data: string) => invoke<Proof>("upgrade_request_proof_data", { id, name, data }),
  removeReceipt: (id: string, proof: string) => invoke<void>("upgrade_request_proof_delete", { id, proof }),
  receiptFile: (id: string, proof: string, variant: "thumb" | "full") => invoke<ProofFile>("upgrade_request_proof_file", { id, proof, variant }),
  markPaid: (id: string, reference: string | null, payerName: string | null) => invoke<UpgradeRequest>("upgrade_request_paid", { id, reference, payerName }),
  cancelUpgradeRequest: (id: string) => invoke<UpgradeRequest>("upgrade_request_cancel", { id }),
  /** Account → Devices → Rename (since v1.6); the new name. */
  renameDevice: (id: string, name: string) => invoke<string>("device_rename", { id, name }),
  /** null on a server without accounts. */
  account: () => invoke<Account | null>("account_get"),
  /** Every device and console session of the account, this computer too: the answer is the signed-out status. */
  signOutEverywhere: () => invoke<AppStatus>("account_sign_out_everywhere"),
  /** When the account will be deleted (in 7 days). */
  deleteAccount: (password: string | null, otp: string | null) => invoke<string | null>("account_delete", { password, otp }),
  /** One account change of 2.0.0-l, answered whole (lib/account.ts `call`). */
  accountCall: (action: string, body: Record<string, unknown> | null, id: string | null) => invoke<unknown>("account_call", { action, id, body }),
  /** Change picture…: the picker, then the upload; null when the person cancels the picker. */
  chooseAvatar: () => invoke<unknown>("account_avatar_choose"),
  /** A person's picture as a data: URL, kept on this computer by its version; null shows the initials. */
  avatarImage: (url: string | null, version: number) => invoke<string | null>("account_avatar_image", { url, version }),
  /** Save the recovery codes as a text file; null when the person cancels. */
  saveRecoveryCodes: (codes: string[], email: string) => invoke<string | null>("account_recovery_codes_save", { codes, email }),
  /** Copy codes: on the clipboard, left out of clipboard history and managers, and taken off it after a minute. */
  copyRecoveryCodes: (codes: string[]) => invoke<void>("account_recovery_codes_copy", { codes }),
  startExport: () => invoke<AccountExport>("account_export_start"),
  exportStatus: (id: string) => invoke<AccountExport>("account_export_status", { id }),
  openExport: (url: string) => invoke<void>("account_export_open", { url }),
  /** The server's Terms, Privacy, account deletion or downloads page in the browser; `serverUrl` before signing in. */
  openServerPage: (page: ServerPage, serverUrl: string | null = null) => invoke<void>("server_page_open", { page, serverUrl }),
  // Updates (since v1.5): checked every 12 hours and on request; `update:status` follows the check and the download.
  updateStatus: () => invoke<UpdateStatus>("update_status"),
  checkForUpdates: () => invoke<UpdateStatus>("update_check"),
  /** "Restart": installs the ready update and starts the new version. */
  installUpdate: () => invoke<void>("update_install"),
  /** A double click on the title row (UX-20): zoom, minimize or nothing, as the Mac's setting says (lib/titleClick.ts). */
  titleDoubleClick: () => invoke<void>("title_double_click"),
  /** A menu drawn by the system (DES-02: Record's on a Mac) at x, y in the window; a pick comes back as `menu:action`. */
  /** `menu`: a right-click menu's number (UX-22), which each of its picks carries as `~<menu>`. */
  nativeMenuPopup: (items: NativeMenuItem[], x: number, y: number, menu?: number) => invoke<void>("native_menu_popup", { items, x, y, menu: menu ?? null }),
  /**
   * The Mac menu bar (FIX-05, v2/actions/menuBar.ts), drawn by the system; a pick comes back as `menu:action`. Null puts
   * classic's own bar back (the new design's shell turned off).
   */
  /** The Dock icon's number (FIX-11): the page's Activity part; the core adds the calls waiting. */
  dockActivitySet: (count: number) => invoke<void>("dock_activity_set", { count }),
  appMenuSet: (bar: NativeMenuBar | null) => invoke<void>("app_menu_set", { bar }),
  settings: () => invoke<Settings>("get_settings"),
  updateSettings: (patch: SettingsPatch) => invoke<Settings>("update_settings", { patch }),
  setShortcut: (action: ShortcutAction, accelerator: string) => invoke<Settings>("set_shortcut", { action, accelerator }),
  resetShortcuts: () => invoke<Settings>("reset_shortcuts"),
  toggleCaptions: () => invoke<AppStatus>("toggle_captions"),
  // Live Translate (v1.3, lib/translate.ts holds its types).
  translateApps: () => invoke<import("./translate").TranslateApp[]>("translate_apps"),
  translateStatus: () => invoke<import("./translate").TranslateFeed | null>("translate_status"),
  /** Setup & health (both platforms): the diagnostic report as plain text (no secrets, audio or transcripts). */
  diagnosticReport: () => invoke<string>("diagnostic_report"),
  /** Setup & health › Copy the report. */
  diagnosticReportCopy: () => invoke<void>("diagnostic_report_copy"),
  translateStart: (request: import("./translate").TranslateStartRequest) => invoke<string[]>("translate_start", { request }),
  translateStop: () => invoke<void>("translate_stop"),
  translateToggleLock: () => invoke<void>("translate_toggle_lock"),
  startRecording: (sourceApp?: string) => invoke<AppStatus>("start_recording", { sourceApp: sourceApp ?? null }),
  stopRecording: () => invoke<AppStatus>("stop_recording"),
  toggleVoiceNote: () => invoke<AppStatus>("toggle_voice_note"),
  toggleMicNote: () => invoke<AppStatus>("toggle_mic_note"),
  /** Record now (Phase 1C): the meeting on screen with both sides, else a voice note; again to stop. */
  recordNow: () => invoke<AppStatus>("record_now"),
  // The Ready check (Phase 1C).
  readyCheck: () => invoke<ReadyReport>("ready_check"),
  readyLast: () => invoke<ReadyReport | null>("ready_last"),
  readyFix: (action: ReadyFixAction) => invoke<ReadyReport>("ready_fix", { action }),
  /** "Notify me when ready" (Phase 1C): a notification when this recording's transcript is ready, whatever the switches say. */
  notifyWhenReady: (sessionId: string) => invoke<void>("notify_when_ready", { sessionId }),
  // Notification categories (Phase 1C).
  notificationTest: (category: NotificationCategory) => invoke<boolean>("notification_test", { category }),
  notificationSend: (category: NotificationCategory, title: string, body: string) => invoke<boolean>("notification_send", { category, title, body }),
  hideCaptions: () => invoke<AppStatus>("hide_captions"),
  micUsers: () => invoke<MicUser[]>("mic_users"),
  /** The last app not recorded since EchoLedger started, and when; null when there was none. */
  micLastNotRecorded: () => invoke<NotRecordedMic | null>("mic_last_not_recorded"),
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
  /** The finished transcripts of the selected recordings in one zip; `view` (roman | english | urdu) exports each one
   * whose Show as view is ready in it. */
  exportSessions: (ids: string[], format: BulkExportFormat, view?: ExportView) => invoke<string | null>("sessions_export", { ids, format, view }),
  /** Transcribe on an audio-only recording (since v1.8): its transcript starts now. */
  sessionTranscribe: (id: string) => invoke<Record<string, unknown>>("session_transcribe", { id }),
  /** Transcribe selected (1-50): which started, which were skipped and why, and the minutes. Nothing starts when the
   * minutes left can't cover them all (`budget_exceeded`, said in minutes). */
  sessionsTranscribe: (ids: string[]) => invoke<TranscribeStarted>("sessions_transcribe", { ids }),
  /** What Transcribe selected would use and the minutes left this month (null = no limit), before it starts. */
  sessionsTranscribeEstimate: (ids: string[]) => invoke<TranscribeEstimate>("sessions_transcribe_estimate", { ids }),
  /** A new project; `parentId` makes it a subfolder (since v1.5). */
  createClient: (name: string, color: string | null = null, avatar: string | null = null, parentId: string | null = null) =>
    invoke<{ data: ClientFolder }>("client_create", { name, color, avatar, parentId }),
  /** `parent_id` moves a project into another one, null to the top level (since v1.5). */
  updateClient: (id: string, patch: Partial<Pick<ClientFolder, "name" | "color" | "avatar" | "pinned" | "language" | "ai_notes" | "parent_id" | "screens">>) => invoke<{ data: ClientFolder }>("client_update", { id, patch }),
  // Projects, smart folders, filing rules, sharing and links (since v1.5).
  /** Its recordings go to the Inbox and its subfolders up a level. */
  deleteClient: (id: string) => invoke<{ moved_sessions?: number; moved_projects?: number }>("client_delete", { id }),
  libraryViews: (tz: string | null = null) => invoke<LibraryViews>("library_views", { tz }),
  /** The person opened this recording (since v1.3): no longer unread, on every device. */
  markOpened: (id: string) => invoke<void>("session_opened", { id }),
  /** The person's lists in their order (since 2.0.0-m); null on a server without lists. */
  lists: () => invoke<ListItem[] | null>("lists_get"),
  /** "+": a new, empty list; without a colour the server picks the one fewest lists have. */
  createList: (name: string, color: string | null = null) => invoke<ListItem>("list_create", { name, color }),
  /** Rename (every recording in it with it) and/or recolour. */
  updateList: (key: string, change: { name?: string; color?: string }) => invoke<ListItem>("list_update", { key, name: change.name ?? null, color: change.color ?? null }),
  /** The label comes off its recordings (they stay); how many changed. */
  deleteList: (key: string) => invoke<number>("list_delete", { key }),
  /** Every list's key once, in the new order. */
  orderLists: (keys: string[]) => invoke<ListItem[]>("lists_order", { keys }),
  /** My views (contract 2.0.0-p "Saved views"): null on a server without them. */
  views: () => invoke<{ data: ServerView[]; views_version: number; limit?: number } | null>("views_get"),
  createView: (view: Omit<ServerView, "id" | "position">) => invoke<ViewAnswer<{ data: ServerView; views_version: number }>>("view_create", { view }),
  updateView: (id: string, patch: Partial<Omit<ServerView, "id" | "position">>) => invoke<ViewAnswer<{ data: ServerView; views_version: number }>>("view_update", { id, patch }),
  deleteView: (id: string) => invoke<ViewAnswer<{ id: string; views_version: number }>>("view_delete", { id }),
  orderViews: (ids: string[]) => invoke<ViewAnswer<{ data: ServerView[]; views_version: number }>>("views_order", { ids }),
  /** The saved views' version the last library sync carried (null before one did, or from an older server). */
  viewsVersion: () => invoke<number | null>("library_views_version"),
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
  sentOf: (sessionId: string | null, clientId: string | null = null) => invoke<{ data: SentItem[] } | null>("sent_of", { sessionId, clientId }),
  stopSharing: (id: string) => invoke<void>("stop_sharing", { id }),
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
  /** The window's interactive parts in physical pixels, and the device pixel ratio they were measured at (the core
   * keeps them at the window's current scale, so a move to a monitor with another scale never leaves them stale). */
  setHitRegions: (regions: RectPx[], scale = 1) => invoke<void>("overlay_set_hit_regions", { regions, scale }),
  snapOverlay: () => invoke<void>("overlay_snap"),
  pauseRecording: () => invoke<AppStatus>("pause_recording"),
  resumeRecording: () => invoke<AppStatus>("resume_recording"),
  cancelRecording: () => invoke<AppStatus>("cancel_recording"),
  setPillHidden: (hidden: boolean) => invoke<AppStatus>("set_pill_hidden", { hidden }),
  pillMoved: () => invoke<void>("pill_moved"),
  recordingState: () => invoke<RecordingState>("recording_state"),
  pendingList: () => invoke<PendingItem[]>("pending_list"),
  pendingKeep: (id: string) => invoke<void>("pending_keep", { id }),
  /** The waiting list's Delete / Delete all: gone from the list now, the files only after "Deleted · Undo" (5 s). */
  pendingDelete: (ids: string[]) => invoke<number>("pending_delete", { ids }),
  /** Undo on the waiting list's "Deleted · Undo": the recordings are back in the list. */
  pendingDeleteUndo: (ids: string[]) => invoke<number>("pending_delete_undo", { ids }),
  /** "Save audio": kept and uploaded as audio only, not transcribed (contract 1.8.0-e); it lands in "Not transcribed". */
  pendingKeepAudio: (id: string) => invoke<void>("pending_keep_audio", { id }),
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
  /** What the after-call window shows now (the card, a confirmation or the grouped card). */
  afterCallState: () => invoke<AfterCallState>("after_call_state"),
  /** The card's answer: Transcribe, Save audio only (its ×, Esc or a swipe) or Delete; applied after its 5 s Undo. */
  callCardAnswer: (id: string, choice: CallChoice) => invoke<void>("call_card_answer", { id, choice }),
  /** Undo on a confirmation: the call is back on its card. */
  callUndo: (id: string) => invoke<void>("call_undo", { id }),
  /** Transcribe on "Saved as audio". */
  callDoneTranscribe: (id: string) => invoke<void>("call_done_transcribe", { id }),
  /** "Always do this?" › Yes. */
  callAlways: (id: string, value: AfterEachCall) => invoke<void>("call_always", { id, value }),
  /** The grouped card: Review (opens the Library) or its ×. */
  callGroupClose: (review: boolean) => invoke<void>("call_group_close", { review }),
  /** The pill's menu or the start banner opened or closed: its window grows for it and gives the screen back after. */
  pillExpanded: (expanded: boolean, warning = false) => invoke<void>("pill_expanded", { expanded, warning }),
  /** The pill's menu during a call: Transcribe this call, Save audio only, or Stop and delete. */
  callChoice: (choice: CallChoice) => invoke<AppStatus>("call_choice", { choice }),
  /** Undo on the Saved toast: the recording goes (to Recently deleted once it is uploaded). */
  savedUndo: (id: string) => invoke<void>("saved_undo", { id }),
  /** Delete on the after-call card (older windows): the files stay until its Undo time is over. */
  afterCallDiscard: (id: string) => invoke<void>("after_call_discard", { id }),
  /** Undo on "Deleted": the call is back on its card. */
  afterCallDiscardUndo: (id: string) => invoke<void>("after_call_discard_undo", { id }),
  /** Short or silent automatic calls skipped in the last 24 h, newest first. */
  skippedList: () => invoke<SkippedItem[]>("skipped_list"),
  /** The server sessions of recordings recovered after a restart (their "Recovered" chip). */
  recoveredSessions: () => invoke<string[]>("recovered_sessions"),
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
  openPrivacySettings: (kind: SettingsPane) => invoke<void>("open_privacy_settings", { kind }),
  /** "Try again" on "The other side isn't being recorded": opens the call's system side again; false when only quitting
   * and reopening EchoLedger would. */
  captureRetrySystem: () => invoke<boolean>("capture_retry_system"),
  permissionRequest: (kind: PermissionKind) => invoke<PermissionsStatus>("permission_request", { kind }),
  /** "Quit & reopen": Screen Recording allowed while EchoLedger ran works once it opens again (refused while recording). */
  quitAndReopen: () => invoke<void>("quit_and_reopen"),
  /** The interface flags from the last config read, no request (contract 2.0.0-a); desktop/src/v2/flags.ts reads them. */
  uiFlags: () => invoke<UiFlagsAnswer>("ui_flags"),
  /** How many hours a deleted recording can be restored (contract 2.0.0-b; 48 from an older server). */
  trashKeepHours: () => invoke<number>("trash_keep_hours"),
  /** "Switch back to classic" (`PUT /api/account/ui`): the server's new flags. */
  uiClassicSet: (classic: boolean) => invoke<UiFlagsAnswer>("ui_classic_set", { classic }),
  /** The design switch (contract 2.0.0-g): "v2" is Try the new design, "classic" is Switch back to classic, null the
   * default. The answer has the same shape as `ui_flags`, with `ui_design`. */
  uiDesignSet: (design: "v2" | "classic" | null) => invoke<UiFlagsAnswer>("ui_design_set", { design }),
  /** Help (docs/API.md 2.0.0-d, v1.8 P6.4): the user guide's sections, those matching `q` best first (at most 20). */
  helpGuides: (q: string | null = null) => invoke<{ data: HelpGuideHit[] }>("help_guides", { q }),
  /** One section of the guide, as Markdown. */
  helpGuide: (id: string) => invoke<HelpGuide>("help_guide", { id }),
  /** This platform's last releases with notes, newest first (the core names the platform: windows or mac). */
  helpWhatsNew: () => invoke<{ data: WhatsNewRelease[] }>("help_whats_new"),
  /** Whether Live Translate can run here: `platform` = not on this system yet (the Mac), `plan` = not in the plan. */
  liveTranslateStatus: () => invoke<LiveTranslateAvailability>("live_translate_status"),
  /** The device report a message to support would attach: when this computer last sent one, or null (never checks or sends). */
  lastDeviceReport: () => invoke<LastDeviceReport>("last_device_report"),
  /** Contact support (5 an hour): the device's last report goes with it only when `includeDeviceReport`. */
  supportSend: (subject: string, message: string, includeDeviceReport: boolean) =>
    invoke<void>("support_send", { subject, message, includeDeviceReport }),
  /** Live Assist (API_ASSIST.md 1.9.0-g): the person's preps, all of them without a status. */
  assistPrepsList: (status: AssistPrepStatus | null = null) => invoke<AssistPrep[]>("assist_preps_list", { status }),
  assistPrepSave: (prep: AssistPrepDraft) => invoke<AssistPrep>("assist_prep_save", { prep }),
  assistPrepDelete: (id: string) => invoke<void>("assist_prep_delete", { id }),
  assistPrepDocumentsAdd: (id: string, fileIds: string[]) => invoke<AssistPrep>("assist_prep_documents_add", { id, fileIds }),
  assistPrepDocumentRemove: (id: string, fileId: string) => invoke<AssistPrep>("assist_prep_document_remove", { id, fileId }),
  /** Schedules a prep for `at` (RFC 3339): armed 2 minutes before, it waits up to 10 minutes for the meeting. */
  assistSchedule: (prepId: string, at: string) => invoke<AssistPrep>("assist_schedule", { prepId, at }),
  /** Starts the recording, the session and the relay while `live_assist` is on; the card hears `assist:message`. */
  assistStart: (prepId: string | null, mode: AssistMode | null = null, practice = false, clientRef: string | null = null) =>
    invoke<AssistStarted>("assist_start", { prepId, mode, practice, clientRef }),
  /** "End it and start this one" after `session_active`: that session ends, then this start is tried once more. */
  assistStartReplacing: (sessionId: string, prepId: string | null, mode: AssistMode | null = null, practice = false, clientRef: string | null = null) =>
    invoke<AssistStarted>("assist_start_replacing", { sessionId, prepId, mode, practice, clientRef }),
  /** "Keep running" after `session_active`: the other session goes on; what this start turned on is undone. */
  assistKeepRunning: () => invoke<void>("assist_keep_running"),
  /** Ends the running session and stops the recording (nothing runs: nothing happens). */
  assistEnd: () => invoke<void>("assist_end"),
  assistSend: (kind: AssistSendKind) => invoke<void>("assist_send", { kind }),
  assistSet: (settings: AssistCardSettings) => invoke<AssistSnapshot>("assist_set", { settings }),
  assistFeedback: (suggestionId: string, action: "used" | "dismissed") => invoke<void>("assist_feedback", { suggestionId, action }),
  /** The card drew a suggestion's first text `renderMs` after its `suggestion_start` (contract 1.9.0-n "shown"). */
  assistShown: (suggestionId: string, renderMs: number) => invoke<void>("assist_shown", { suggestionId, renderMs }),
  /** Hides the card; the session goes on. */
  assistCardHide: () => invoke<void>("assist_card_hide"),
  assistState: () => invoke<AssistSnapshot | null>("assist_state"),
  permissionNoticeDismiss: () => invoke<void>("permission_notice_dismiss"),
  /** The After a call notice: Always transcribe (true) or closed (false); said once either way. */
  afterCallNoticeAnswer: (alwaysTranscribe: boolean) => invoke<AppStatus>("after_call_notice_answer", { alwaysTranscribe }),
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
  /** Send to WhatsApp → Text and voice (v1.6): the audio saved in Downloads without a dialog; its path. */
  saveAudioToDownloads: (id: string) => invoke<string>("save_audio_downloads", { id }),
  /** Send to WhatsApp (v1.6): opens wa.me with the text; true when it was too long and was cut (the core builds the address). */
  whatsappOpen: (text: string) => invoke<boolean>("whatsapp_open", { text }),
  /** Send to WhatsApp → Text and voice (F6): the audio handed with the text to the system's share (WhatsApp's); without
   *  it, or with `share` false, the audio in Downloads, wa.me with the text and the file on the clipboard. */
  whatsappShareVoice: (id: string, text: string, share = true) => invoke<WhatsAppVoiceOutcome>("whatsapp_share_voice", { id, text, share }),
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
  /** "Capture now": the running recording keeps the screen at its next sample; `recording:state` then has the count. */
  screensCaptureNow: () => invoke<RecordingState>("screens_capture_now"),
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
