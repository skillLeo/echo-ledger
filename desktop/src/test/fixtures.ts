// Builders for the wire types the UI receives from the Rust core.
import type {
  AfterCallState,
  AppStatus,
  AskPayload,
  Entitlements,
  OfferedPlan,
  LibraryItem,
  MicUser,
  NavigatePayload,
  PendingItem,
  PlanInfo,
  RecordingInfo,
  RecordingState,
  SavedToast,
  ScreenMoment,
  Segment,
  SkippedItem,
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
    plan: makePlan(),
    server: { appUpdate: null, announcement: null, maintenance: null, waiting: null },
    ...overrides,
  };
}

/** A v1.5 plan with every feature and no limits, the person a member of "Personal" (override what a test needs). */
export function makePlan(overrides: Partial<PlanInfo> = {}): PlanInfo {
  return {
    known: true,
    name: "Personal",
    liveTranslate: true,
    screenMoments: true,
    aiNotes: true,
    connector: true,
    monthlyMinutes: null,
    storageBytes: null,
    retentionDays: null,
    seats: null,
    usedMinutes: 0,
    usedStorageBytes: 0,
    workspace: "Personal",
    role: "owner",
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
    installId: null,
    shortcuts: {
      record_now: "CommandOrControl+Alt+R",
      toggle_captions: "CommandOrControl+Shift+Space",
      toggle_saving: "CommandOrControl+Shift+R",
      copy_last2_min: "CommandOrControl+Shift+C",
      dictation: "CommandOrControl+Shift+D",
      mark: "CommandOrControl+Shift+B",
    },
    captionsOnRecord: true,
    autoMode: true,
    contentProtection: true,
    overlayCorner: "bottom_right",
    autostart: false,
    dictationOutput: "type",
    clientId: null,
    consentAcknowledged: false,
    afterCall: "keep",
    skipCallsShorterThanS: 10,
    askUnknownApps: false,
    siteRules: [],
    captionLines: 3,
    pillPosition: null,
    browserAutomation: false,
    autoRecordApps: {},
    captionStyle: { size: "medium", width: "normal", opacity: 85, compact: false, autoHideSeconds: 0, meColor: null, clientColor: null },
    inputDevice: null,
    farSideWarning: true,
    transcriptionLanguage: null,
    dictationMode: "toggle",
    dictationCleanup: true,
    dictationSounds: true,
    dictationReplacements: [],
    dictationSnippets: [],
    appLock: false,
    lockAfterMinutes: 5,
    activityNotifications: true,
    quietHours: false,
    quietFrom: "22:00",
    quietTo: "07:00",
    notificationCategories: {},
    notificationSounds: {},
    onboardingDone: true,
    screenMoments: true,
    screenTarget: "meeting_window",
    screenExcludedApps: ["1Password", "Bitwarden", "KeePass"],
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
    bluetoothMic: false,
    screens: null,
    ...overrides,
  };
}

/** A finished automatic Zoom call waiting for Keep or Discard in the Library (no chip counts down for it). */
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
    deadlineAt: null,
    callerName: null,
    ...overrides,
  };
}

/** "Saved · Call with Omar · 4:12 · Undo", on screen for another 6 s from `now`. */
export function makeSavedToast(overrides: Partial<SavedToast> = {}, now = Date.now()): SavedToast {
  return {
    id: "rec-1",
    title: null,
    callerName: "Omar",
    sourceApp: "whatsapp",
    mode: "call",
    durationMs: 252_000,
    until: new Date(now + 6_000).toISOString(),
    ...overrides,
  };
}

/** The Ask-me chip of a WhatsApp call, kept by itself 10 s from `now`. */
export function makeChipItem(id = "pend-1", overrides: Partial<PendingItem> = {}, now = Date.now()): PendingItem {
  return makePendingItem(id, { sourceApp: "whatsapp", durationMs: 252_000, deadlineAt: new Date(now + 10_000).toISOString(), ...overrides });
}

/** A 6-second WhatsApp call skipped as too short, restorable until a day after 09:12 on 24 Oct 2026. */
export function makeSkippedItem(id = "skip-1", overrides: Partial<SkippedItem> = {}): SkippedItem {
  return {
    id,
    sourceApp: "whatsapp",
    title: null,
    callerName: null,
    startedAt: "2026-10-24T09:12:00Z",
    durationMs: 6_000,
    reason: "too_short",
    expiresAt: "2026-10-25T09:12:06Z",
    ...overrides,
  };
}

export function makeAfterCallState(overrides: Partial<AfterCallState> = {}): AfterCallState {
  return { saved: null, ask: null, ...overrides };
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
    imported: false,
    progress: null,
    heldFor: null,
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

/** A screen moment of a recording (docs/API.md "Screen moments"): Screen `index` at `at_ms`. */
export function makeScreen(index: number, at_ms: number, overrides: Partial<ScreenMoment> = {}): ScreenMoment {
  return {
    id: `sc${index}`, index, at_ms, width: 1920, height: 1080, trigger: "change", ocr_text: null, description: null,
    image_url: `https://ledger.example.test/screens/${index}.webp?sig=1`, thumb_url: `https://ledger.example.test/screens/${index}-thumb.webp?sig=1`,
    ...overrides,
  };
}

/** `GET /api/me/entitlements` (v1.6) for a Free account: views, screens, Live Translate and the rest locked. */
export function makeEntitlements(overrides: Partial<Entitlements> = {}): Entitlements {
  const paid = (enabled: boolean, lowest: string, name: string | null = null) => ({ enabled, name, description: null, cost: "paid", lowest_plan: lowest });
  return {
    plan: { key: "free", name: "Free", badge: "FREE", color: "#64748b", expires_at: null, trial_minutes_left: 0 },
    features: {
      record: { enabled: true, name: null, description: null, cost: "free", lowest_plan: null },
      platform_transcription: paid(false, "pro", "Transcription on EchoLedger"),
      views: { enabled: false, name: "Transcript views", description: "Read any transcript in Roman Urdu, English or Urdu script.", cost: "paid", lowest_plan: "pro" },
      double_check: paid(false, "pro"),
      screen_moments: paid(false, "pro"),
      connector: paid(false, "pro"),
      live_translate: paid(false, "business"),
      ai_notes: paid(false, "business"),
      team: paid(false, "business"),
      premium_engines: paid(false, "business"),
    },
    limits: {
      monthly_minutes: { limit: 0, used: 0 },
      devices: { limit: 2, used: 1 },
      storage_bytes: { limit: 1073741824, used: 52428800 },
      retention_days: { limit: 30, used: null },
    },
    engines: ["own_groq"],
    transcription_key: { provider: "groq", set: false, last4: null },
    upgrade_request: null,
    plans: [
      { key: "pro", name: "Pro", badge: "PRO", color: "#7c3aed", price: "Rs 2,000 a month", description: "For one person who records a lot.",
        features: ["platform_transcription", "views", "double_check", "screen_moments", "connector"], limits: { monthly_minutes: 600, devices: 5, storage_bytes: 21474836480 } },
      { key: "business", name: "Business", badge: "BUSINESS", color: "#0f766e", price: "Rs 6,000 a month", description: "For a team.",
        features: ["platform_transcription", "views", "double_check", "screen_moments", "connector", "team", "premium_engines", "live_translate", "ai_notes"],
        limits: { monthly_minutes: null, devices: 20, storage_bytes: 107374182400 } },
    ],
    ...overrides,
  };
}

/** Any notice target, none included (v1.6.1: every notify call says where it goes; a test that is not about that). */
export const anyTarget = { asymmetricMatch: () => true, toString: () => "anyTarget", toAsymmetricMatcher: () => "anyTarget" };

/**
 * The feature registry as docs/api-samples/entitlements.json sends it (v1.6.2, All features): key, name, what it
 * does, and the lowest plan that has it. `registry.test.ts` keeps this list equal to the real sample.
 */
export const FEATURE_REGISTRY: readonly (readonly [string, string, string, string | null])[] = [
  ["record", "Record and upload", "Record calls, meetings and voice notes on your phone and computer, and upload files.", null],
  ["organise", "Organise", "Projects, labels, pins and filing rules for your recordings.", null],
  ["search", "Search", "Find any word in your recordings and transcripts.", null],
  ["share_links", "Share links", "Links to a recording or a project that you can take back at any time.", null],
  ["export", "Export", "Transcripts as text and subtitles.", null],
  ["ready_check", "Ready check", "Checks that your phone or computer will record your calls.", null],
  ["platform_transcription", "Transcription on EchoLedger", "Your recordings are transcribed for you, with no key of your own.", "pro"],
  ["views", "Roman Urdu, English and Urdu", "Read any call in Roman Urdu, English or Urdu, side by side with what was said.", "pro"],
  ["double_check", "Double-check", "A second engine listens again to the words the first one was unsure of, and to every short note.", "pro"],
  ["screen_moments", "Screen moments", "What was on the screen during a meeting, next to the words that were said.", "pro"],
  ["connector", "Claude and ChatGPT", "Ask Claude or ChatGPT about your recordings.", "pro"],
  ["team", "Team workspace", "Invite people to a shared workspace, with roles and seats.", "business"],
  ["premium_engines", "Premium engines", "ElevenLabs and Gemini for the hardest calls and languages.", "business"],
  ["live_translate", "Live Translate", "Live subtitles in your language for games and videos.", "business"],
  ["ai_notes", "AI notes", "Project notes, memory and Ask about a project.", "business"],
];

const PRO_FEATURES = FEATURE_REGISTRY.filter(([, , , lowest]) => lowest === "pro").map(([key]) => key);
const PAID_KEYS = FEATURE_REGISTRY.filter(([, , , lowest]) => lowest).map(([key]) => key);

/**
 * The real sample's answer for a plan: `free` has the six free features, `pro` adds its five, and `ultra` (the top
 * plan; the sample calls it Business, the badge is whatever the server sends) has all fifteen.
 */
export function sampleEntitlements(planKey: "free" | "pro" | "ultra"): Entitlements {
  const top = { key: "ultra", name: "Ultra", badge: "ULTRA", color: "#0369a1" };
  const plans: OfferedPlan[] = [
    { key: "pro", name: "Pro", badge: "PRO", color: "#7c3aed", price: "Ask for the price",
      description: "Transcripts made for you, Roman Urdu and English views, double-check, screen moments and Claude or ChatGPT.",
      features: PRO_FEATURES, limits: { monthly_minutes: 600, devices: 5, storage_bytes: 21474836480, retention_days: 365, seats: 1 } },
    { ...top, price: "Ask for the price", description: "Everything in Pro, and a team with roles, premium engines, Live Translate and AI notes.",
      features: PAID_KEYS, limits: { monthly_minutes: 3000, devices: 5, storage_bytes: 107374182400, retention_days: 365, seats: 10 } },
  ];
  const has = (lowest: string | null) => !lowest || planKey === "ultra" || (planKey === "pro" && lowest === "pro");
  const plan = planKey === "free" ? { key: "free", name: "Free", badge: "FREE", color: "#64748b" } : planKey === "pro" ? { key: "pro", name: "Pro", badge: "PRO", color: "#7c3aed" } : top;
  return {
    plan: { ...plan, expires_at: null, trial_minutes_left: planKey === "free" ? 0 : null },
    features: Object.fromEntries(FEATURE_REGISTRY.map(([key, name, description, lowest]) => [key, {
      enabled: has(lowest), name, description, cost: lowest ? "paid" : "free", lowest_plan: lowest === "business" ? "ultra" : lowest,
    }])),
    limits: planKey === "free"
      ? { monthly_minutes: { limit: 0, used: 0 }, devices: { limit: 2, used: 1 }, storage_bytes: { limit: 1073741824, used: 4332716 }, retention_days: { limit: 30, used: null } }
      : { monthly_minutes: { limit: planKey === "pro" ? 600 : 3000, used: 212 }, devices: { limit: 5, used: 3 }, storage_bytes: { limit: 107374182400, used: 4332716 }, seats: { limit: planKey === "pro" ? 1 : 10, used: 2 } },
    engines: planKey === "free" ? ["own_groq", "on_device"] : ["own_groq", "on_device", "assemblyai", "groq", "soniox", "azure_mai", ...(planKey === "ultra" ? ["elevenlabs", "gemini"] : [])],
    transcription_key: { provider: "groq", set: false, last4: null },
    upgrade_request: null,
    plans,
  };
}
