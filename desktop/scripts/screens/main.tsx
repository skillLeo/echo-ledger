// A fixture mode for screenshots: the real views in a plain browser, with Tauri's own IPC mock answering every command
// from sample data (data.ts). `?screen=library|library-empty|library-backup|library-update|projects|project|shared|share|move|
// request-files|record|record-bluetooth|record-viewer|activity|settings|settings-account|sign-in|controls|controls-bluetooth
// &theme=light|dark` (v1.5: "-bluetooth" records from AirPods, "library-backup" opens the backup-engine call; projects,
// shared, share, move and request-files open on the Projects page or the Library, where scripts/screenshots.mjs clicks its
// way to the dialog; "sign-in" is a computer not signed in, "-viewer" a viewer's, "library-update" an update ready. Phase 1B:
// "-mac" is a Mac, "-windows10" a Windows without per-app capture, "onboarding-mac" the guided permissions,
// "record-permission-mac" a permission off, "library-lost-mac" a permission macOS forgot, "settings-mac-check" the check's
// results, "controls-permission", "controls-nothing" and "controls-device" what the pill says, "controls-dictation-refused"
// the dictation box with the microphone off).
import React from "react";
import ReactDOM from "react-dom/client";
import { clearMocks, mockIPC, mockWindows } from "@tauri-apps/api/mocks";
import "../../src/styles.css";
import "../../src/views/controls.css";
import { makeAfterCallState, makeChipItem, makeMicUser, makePlan, makeRecordingState, makeSavedToast, makeSettings, makeStatus, makeUpgradeRequest, sampleEntitlements } from "../../src/test/fixtures";
import { ACCOUNT, ACTIVITY, ANSWER, CLIENTS, ITEMS, LIBRARY_VIEWS, NOTES_R5, PENDING, REQUESTS, RULES, SHARED, SHARES, SKIPPED, TRANSCRIPTS, VIEWS, WORKSPACE } from "./data";
import { inFolderHere } from "../../src/lib/folders";
import type { SmartFolder } from "../../src/lib/ipc";

const params = new URLSearchParams(location.search);
const screen = params.get("screen") ?? "library";
const theme = params.get("theme") === "dark" ? "dark" : "light";
localStorage.setItem("echoledger-theme", theme);
document.documentElement.dataset.theme = theme;
document.documentElement.style.colorScheme = theme;
const controls = screen.startsWith("controls");
document.documentElement.dataset.window = controls ? "controls" : "main";

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
const bluetooth = screen.endsWith("-bluetooth");
const mac = screen.includes("-mac");
const windows10 = screen.endsWith("-windows10");
const onboarding = screen === "onboarding-mac";
const NO_SYSTEM_AUDIO = { kind: "system_audio" as const, sentence: "System audio is off for EchoLedger: a recording will have your voice, not the other side of the call." };
const NOTHING_TO_RECORD = "The microphone and system audio are both off for EchoLedger, so there is nothing to record. Allow them in System Settings.";
// v1.5: projects, the Library's smart folders, sharing and moving open from the Library or the Projects page.
const LIBRARY_SCREENS = ["shared", "share", "move"];
const tab = screen.startsWith("library") || LIBRARY_SCREENS.includes(screen) ? "library"
  : screen.startsWith("client") || screen.startsWith("project") || screen === "request-files" ? "projects"
    : screen.startsWith("record") ? "record" : screen.startsWith("settings") ? "settings" : onboarding ? "library" : screen;
// v1.5: the plan with limits next to the usage; a viewer on "-viewer".
const plan = makePlan({
  name: "Team", workspace: "Acme team", role: screen.endsWith("-viewer") ? "viewer" : "owner",
  monthlyMinutes: 3000, usedMinutes: 812.5, storageBytes: 10 * 1024 ** 3, usedStorageBytes: 2.4 * 1024 ** 3, retentionDays: 90, seats: 5,
});
const opened = screen === "library-backup" ? "r2" : "r5";
// v1.6: "-free" is an account on the Free plan (badges, Plan & usage, the own Groq key); "sign-in…" a computer signed out.
// v1.7: or `&plan=free|pro|ultra`, with `&request=<stage>` (an upgrade request at that stage), `&near=1` (Pro near its
// minutes), `&round=1` (two receipts added and not sent yet) and `&deleting=1` (the account is being deleted).
const planParam = params.get("plan");
const free = planParam === "free" || screen.endsWith("-free");
const ultra = planParam === "ultra" || screen.endsWith("-ultra");
const pro = planParam === "pro";
const requestStage = params.get("request") as Parameters<typeof makeUpgradeRequest>[0] | null;
const near = params.get("near") === "1";

/** A receipt as a phone screenshot: drawn here, handed out as base64 like the core does. */
function receiptPicture(): string {
  const canvas = document.createElement("canvas");
  canvas.width = 192;
  canvas.height = 192;
  const g = canvas.getContext("2d")!;
  g.fillStyle = "#f4f6f8"; g.fillRect(0, 0, 192, 192);
  g.fillStyle = "#1e7b53"; g.beginPath(); g.arc(96, 46, 18, 0, Math.PI * 2); g.fill();
  g.fillStyle = "#ffffff"; g.font = "bold 20px sans-serif"; g.fillText("✓", 88, 53);
  g.fillStyle = "#1c2024"; g.font = "bold 18px sans-serif"; g.fillText("Rs 2,999", 56, 96);
  g.fillStyle = "#60646c"; g.font = "11px sans-serif";
  ["EXAMPLE PRIVATE LIMITED", "9 Nov 2026, 3:42 pm", "TID FT26313ABC7781"].forEach((t, i) => g.fillText(t, 28, 122 + i * 18));
  return canvas.toDataURL("image/png").split(",")[1];
}

/** The entitlements of the plan asked for, with its request. */
function entitlementsHere() {
  if (!free && !ultra && !pro) return null;
  const e = sampleEntitlements(free ? "free" : pro ? "pro" : "ultra");
  if (near && e.limits.monthly_minutes) e.limits.monthly_minutes = { ...e.limits.monthly_minutes, used: 552 };
  if (requestStage) {
    const r = makeUpgradeRequest(requestStage, { plan: pro ? "ultra" : "pro", amount_due: pro ? "Rs 12,499" : "Rs 2,999", amount_due_pkr: pro ? 12499 : 2999 });
    if (params.get("round") === "1" && r.payment) {
      r.payment.proofs = [
        ...r.payment.proofs,
        { id: "01P2", kind: "image", mime: "image/png", name: "Screenshot 3.42 pm.png", bytes: 210_000, width: 720, height: 1560, created_at: new Date().toISOString(), locked: false },
        { id: "01P3", kind: "pdf", mime: "application/pdf", name: "Bank statement.pdf", bytes: 348_000, width: null, height: null, created_at: new Date().toISOString(), locked: false },
      ];
    }
    e.upgrade_request = r;
  }
  return e;
}
const signedOut = screen.startsWith("sign-in");
// Acme Holdings shows its folders in the trees.
localStorage.setItem("echoledger-projects-open", JSON.stringify(["c-acme"]));
// v1.6 (P4): "library-table" opens the Library as a table; "library-grey" and "library-darkbar" show the sidebar's
// Soft grey and Dark styles (Account → Preferences).
if (screen === "library-table") localStorage.setItem("echoledger-library-layout", "table");
if (screen === "library-grey" || screen === "library-darkbar") {
  const sidebarStyle = screen === "library-grey" ? "grey" : "dark";
  localStorage.setItem("echoledger-preferences", JSON.stringify({ sidebarStyle, density: "comfortable", clock: "12" }));
  document.documentElement.dataset.sidebar = sidebarStyle;
}
const SERVER_FOLDERS: Partial<Record<SmartFolder, string[]>> = { imported: ["r3"] };
type Args = Record<string, unknown>;
let captions: { onmessage: (e: unknown) => void } | null = null;

// Phase 1C: the Ready check, ready or with two things to fix ("record-not-ready").
const notReady = screen === "record-not-ready";
const check = (id: string, label: string, ok: boolean, detail: string | null = null, fix: { label: string; action: string } | null = null) => ({ id, label, ok, detail, fix });
const READY_REPORT = {
  ready: !notReady,
  checkedAt: new Date().toISOString(),
  checks: [
    check("account", "Signed in", true),
    check("microphone", "Microphone", true),
    check("microphone_permission", "Microphone allowed", true),
    notReady
      ? check("other_side", "The other side of calls", false, "EchoLedger may not record this computer's sound: only your voice would be recorded.", { label: "Open System Settings", action: "allow_system_audio" })
      : check("other_side", "The other side of calls", true),
    notReady
      ? check("detection", "Meetings recorded by themselves", false, "Record calls automatically is off: calls are recorded only when you start them.", { label: "Turn on", action: "turn_on_auto_mode" })
      : check("detection", "Meetings recorded by themselves", true),
  ],
};

// v1.6 (P2.7): "library-maintenance" while the server is in maintenance, "library-refused" when it refuses this version,
// "library-announcement" with the owner's announcement.
const inAnHour = new Date(Date.now() + 3_600_000);
inAnHour.setMinutes(0, 0, 0);
const SERVER_NOTICES: Record<string, unknown> = {
  "library-maintenance": { appUpdate: null, announcement: null, maintenance: { message: "Upgrading the server.", until: inAnHour.toISOString() }, waiting: "maintenance" },
  "library-refused": {
    appUpdate: { minimum: "1.6.0", required: true, refused: true, message: "Please update EchoLedger to 1.6.0 or later.", url: "https://ledger.example.com/downloads" },
    announcement: null, maintenance: null, waiting: "update_required",
  },
  "library-announcement": { appUpdate: null, announcement: { text: "New in 1.6: send a recording to WhatsApp from its page, as text, with the voice, or as a link.", level: "info", until: null }, maintenance: null, waiting: null },
};

const commands: Record<string, (args: Args) => unknown> = {
  ready_last: () => READY_REPORT,
  ready_check: () => READY_REPORT,
  auth_options: () => ({ password: true, google: true, signUp: true, signUpUrl: "https://ledger.example.com/admin/register", resetUrl: "https://ledger.example.com/admin/password-reset/request", browserSignIn: true, code: true }),
  auth_start: ({ email }) => (String(email).startsWith("new") ? { account: "new", methods: ["code", "google"], signUp: true } : { account: "existing", methods: ["password", "code", "google"], signUp: true }),
  auth_code_send: () => ({ resendAfter: 60, expiresIn: 600, sent: true }),
  auth_code_verify: () => ({ result: "profile", status: null }),
  entitlements_get: () => entitlementsHere(),
  upgrade_request_latest: () => entitlementsHere()?.upgrade_request ?? null,
  upgrade_request_proof_file: () => ({ mime: "image/png", data: receiptPicture() }),
  upgrade_receipt_pick: () => [],
  upgrade_request_create: ({ plan }) => makeUpgradeRequest("awaiting_payment", { plan: String(plan) }),
  upgrade_request_paid: () => makeUpgradeRequest("in_review"),
  // v1.6.2: a promo code for Pro, at half price.
  promo_code_check: () => ({ result: "valid", check: { code: "SAVE50", plan: "pro", plan_name: "Pro", percent: 50, price: "Rs 2,999 / month", final_price: "Rs 1,500 / month", free: false, months: 1 } }),
  app_status: () => makeStatus({
    callsReady: !notReady,
    readyProblems: notReady ? 2 : 0,
    deviceName: "Work laptop",
    pendingDecisions: empty ? 0 : PENDING.length,
    captionsLive: controls,
    plan,
    paired: !signedOut,
    serverUrl: signedOut ? null : "https://ledger.example.test",
    platform: mac ? "macos" : "windows",
    systemAudioNote: windows10 ? "This Windows version records all sound playing on this computer, not just the call" : null,
    // v1.6 (P2.7): what the server says to every app.
    pendingUploads: screen === "library-maintenance" ? 2 : screen === "library-refused" ? 1 : 0,
    server: SERVER_NOTICES[screen] ?? { appUpdate: null, announcement: null, maintenance: null, waiting: null },
  }),
  account_get: () => (params.get("deleting") === "1" ? { ...ACCOUNT, deletes_at: "2026-12-09T10:00:00Z" } : ACCOUNT),
  // Send to WhatsApp (v1.6): the browser opens nothing here; the voice is "saved" in Downloads.
  whatsapp_open: () => false,
  save_audio_downloads: () => "C:\\Users\\Hassam\\Downloads\\Kick-off Contoso website 2026-11-06.ogg",
  update_status: () =>
    screen === "library-update"
      ? { phase: "ready", current: "1.5.0", version: "1.5.1", notes: "Faster sign-in and the account page.", checkedAt: new Date().toISOString(), message: null, progress: null }
      : { phase: "up_to_date", current: "1.5.0", version: null, notes: null, checkedAt: new Date(Date.now() - 3 * 3_600_000).toISOString(), message: "You have the latest version.", progress: null },
  get_settings: () => makeSettings({ deviceName: "Work laptop", consentAcknowledged: true, onboardingDone: !onboarding, notificationCategories: { sharing: false }, notificationSounds: { usage: false } }),
  main_view_get: () => JSON.stringify({ tab, recordingId: empty || tab !== "library" || screen === "library-table" ? null : opened, scroll: {} }),
  lock_status: () => ({ enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 }),
  library_list: () => (empty ? [] : ITEMS),
  library_refresh: () => (empty ? [] : ITEMS),
  list_clients: () => ({ data: CLIENTS }),
  library_views: () => (empty ? { views: {}, labels: [], folders: { inbox: 0 } } : LIBRARY_VIEWS),
  library_folder: ({ folder }) => ITEMS.filter((i) => SERVER_FOLDERS[folder as SmartFolder]?.includes(i.id) ?? inFolderHere(i, folder as SmartFolder) === true),
  client_rules: ({ id }) => ({ data: RULES[id as string] ?? [] }),
  shares_of: () => ({ data: SHARES }),
  shared_with_me: () => ({ data: SHARED }),
  request_links: () => ({ data: REQUESTS }),
  session_detail: ({ id }) => ({ id, playback_url: audioUrl, markers: id === "r5" ? [{ id: "mk1", at_ms: 95_000, tag: "Budget", note: "Phase one estimate" }] : [], speaker_names: { Me: "You", Client: "Sara (Acme)" } }),
  transcript_detail: ({ id }) => TRANSCRIPTS[id as string] ?? { ...TRANSCRIPTS["t-r5"], id, suggestions: [], views: null },
  transcript_views: () => VIEWS,
  unsure_markers: () => null,
  pending_list: () => (empty ? [] : PENDING),
  skipped_list: () => (empty ? [] : SKIPPED),
  upload_list: () => [],
  activity_list: () => ({ data: ACTIVITY, unread_count: ACTIVITY.filter((a) => !a.read).length }),
  search_library: () => ({ data: [], next_page: null }),
  session_notes: ({ id }) => (id === "r5" ? { data: NOTES_R5 } : null),
  client_workspace: ({ id }) => ({ data: id === "c-acme" ? WORKSPACE : { ...WORKSPACE, client: { ...WORKSPACE.client, id, name: CLIENTS.find((c) => c.id === id)?.name ?? "" }, brief: null, memory: [], notes: [], files: [] } }),
  client_ask: () => ({ data: ANSWER }),
  mic_users: () => [makeMicUser(), makeMicUser({ name: "Code.exe", app: null, playsAudio: false, site: null, decision: "ignored", reason: "dictation" })],
  translate_apps: () => [],
  translate_status: () => null,
  input_devices: () => [
    { id: "default", name: "Microphone (Realtek Audio)", isDefault: !bluetooth, bluetooth: false },
    ...(bluetooth ? [{ id: "airpods", name: "AirPods Pro", isDefault: true, bluetooth: true }] : []),
  ],
  permissions_status: () => (mac
    ? {
        microphone: onboarding ? "unknown" : "allowed",
        systemAudio: screen === "record-permission-mac" || onboarding ? "denied" : "allowed",
        screen: onboarding ? "denied" : "allowed",
        browserAutomation: false,
        platform: "macos",
        missing: screen === "record-permission-mac" ? [NO_SYSTEM_AUDIO] : [],
        lost: screen === "library-lost-mac" ? "macOS no longer lets EchoLedger use the microphone, which can happen after an update. Setting it up again takes a moment." : null,
      }
    : { microphone: "allowed", browserAutomation: false, platform: "windows", missing: [] }),
  mac_check_last: () => screen === "settings-mac-check"
    ? {
        finishedAt: new Date().toISOString(),
        stopped: null,
        appVersion: "1.5.0",
        osVersion: "15.1",
        model: "Mac14,2",
        sentAt: new Date(Date.now() - 120_000).toISOString(),
        items: [
          { key: "macos_version", title: "macOS version", state: "ok", detail: "macOS 15.1: call audio works on this version.", fix: null },
          { key: "microphone_permission", title: "Microphone permission", state: "ok", detail: "Allowed.", fix: null },
          { key: "system_audio_permission", title: "System audio permission", state: "off", detail: "Off. Without it a recording has your voice, not the other side of the call.", fix: { label: "Open System Settings", settings: "system_audio" } },
          { key: "screen_permission", title: "Screen Recording permission", state: "ok", detail: "Allowed. macOS asks again about once a month: choose Allow to keep screen moments.", fix: null },
          { key: "microphone_test", title: "Microphone test", state: "ok", detail: "Heard your microphone (loudest about -28 dB).", fix: null },
          { key: "system_audio_test", title: "System audio test", state: "off", detail: "Not tested: system audio is off for EchoLedger.", fix: { label: "Open System Settings", settings: "system_audio" } },
          { key: "meeting_apps", title: "Meeting apps", state: "ok", detail: "Installed: Zoom, Chrome, Safari. Not installed: Teams, WhatsApp, Edge, Arc. EchoLedger recognises all of them.", fix: null },
          { key: "overlay_windows", title: "Captions and pill on screen", state: "ok", detail: "The captions and the pill are on screen, above other windows, in every Space and over full-screen meetings.", fix: null },
          { key: "screen_capture_test", title: "Screen moment test", state: "ok", detail: "One frame captured and thrown away. Nothing was stored.", fix: null },
        ],
      }
    : null,
  security_overview: () => ({ devices: [{ id: "d1", name: "Work laptop", platform: "windows", app_version: "1.3.0", last_seen_at: new Date().toISOString(), paired_at: null, current: true }, { id: "d2", name: "Pixel 9", platform: "android", app_version: "1.3.0", last_seen_at: new Date().toISOString(), paired_at: null, current: false }], events: [] }),
  storage_info: () => ({ recordings_bytes: 812_000_000, recordings_count: 42, disk_free_bytes: 80_123_456_789, disk_total_bytes: 107_374_182_400, retention_days: 30 }),
  dictation_history: () => [],
  marker_tags: () => ["Budget", "Decision", "Follow up"],
  recording_state: () => makeRecordingState({
    elapsedMs: 134_000,
    sourceApp: "whatsapp",
    language: "ur",
    bluetoothMic: bluetooth,
    missing: screen === "controls-permission" ? [NO_SYSTEM_AUDIO] : screen === "controls-nothing" ? [{ kind: "microphone", sentence: NOTHING_TO_RECORD }, { kind: "system_audio", sentence: NOTHING_TO_RECORD }] : [],
    deviceNote: screen === "controls-device" ? { text: "Microphone changed to AirPods Pro. Still recording.", warning: false } : null,
  }),
  // The after-call window: "Saved · … · Undo" over the Ask-me chip (its line at 6 of 10 s); both stay for the picture.
  after_call_state: () =>
    makeAfterCallState({
      saved: makeSavedToast({ callerName: "Omar Khan", sourceApp: "whatsapp", durationMs: 252_000, until: new Date(Date.now() + 3_600_000).toISOString() }),
      ask: makeChipItem(PENDING[1].id, { ...PENDING[1], deadlineAt: new Date(Date.now() + 6_000).toISOString() }),
    }),
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
  if (!controls) {
    const { default: MainApp } = await import("../../src/views/main/MainApp");
    root.render(<React.StrictMode><MainApp /></React.StrictMode>);
    return;
  }
  if (screen === "controls-dictation-refused") {
    const { default: DictationApp } = await import("../../src/views/dictation/DictationApp");
    const { emit } = await import("@tauri-apps/api/event");
    root.render(<div className="screens-canvas"><div className="screens-window" style={{ width: 420, height: 110 }}><DictationApp /></div></div>);
    // What the core sends when it refuses.
    window.setTimeout(() => {
      void emit("overlay:visibility", true);
      captions?.onmessage({ kind: "status", live: false, recording: false, provider: null, message: "The microphone is off for EchoLedger. Allow it in your computer's privacy settings." });
      void emit("dictation:state", { active: false, sounds: false, mode: "toggle" });
    }, 300);
    return;
  }
  // The floating windows side by side on one canvas: the recording pill, the after-call toast and chip, and the captions.
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
