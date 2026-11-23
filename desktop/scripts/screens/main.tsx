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
import { LABEL_COLOURS } from "../../src/v2/components/Label";
import ReactDOM from "react-dom/client";
import { clearMocks, mockIPC, mockWindows } from "@tauri-apps/api/mocks";
import "../../src/styles.css";
import "../../src/views/controls.css";
import { makeAfterCallState, makeChipItem, makeMicUser, makePlan, makeRecording, makeRecordingState, makeCallDone, makeCallGroup, makeSettings, makeStatus, makeUpgradeRequest, sampleEntitlements } from "../../src/test/fixtures";
import { HELP_GUIDE, HELP_GUIDES, WHATS_NEW, SCREENS_R1, screenPictureFor, ACCOUNT, ACTIVITY, ANSWER, CLIENTS, ITEMS, LIBRARY_VIEWS, NOTES_R5, PENDING, REQUESTS, RULES, SHARED, SHARES, SKIPPED, TRANSCRIPTS, VIEWS, WORKSPACE } from "./data";
import { inFolderHere } from "../../src/lib/folders";
import type { SmartFolder, UploadItem } from "../../src/lib/ipc";
import conf from "../../src-tauri/tauri.conf.json";
import { applyTitlebar, titlebarMode } from "../../src/lib/titlebar";

const params = new URLSearchParams(location.search);
const screen = params.get("screen") ?? "library";
const theme = params.get("theme") === "dark" ? "dark" : "light";
localStorage.setItem("echoledger-theme", theme);
document.documentElement.dataset.theme = theme;
document.documentElement.style.colorScheme = theme;
// "&lang=ur": the window right to left, as for an Urdu reader. The desktop's own words are English (it has no Urdu
// interface yet); recordings, transcripts and names keep their own script and direction.
if (params.get("lang") === "ur") {
  document.documentElement.lang = "ur";
  document.documentElement.dir = "rtl";
}
const controls = screen.startsWith("controls");
document.documentElement.dataset.window = controls ? "controls" : "main";
// "&platform=macos" (or "windows"): the main window as on that computer, as main.tsx sets it from window_appearance.
// "&lights=1" draws macOS's close, minimize and zoom where the app puts them (tauri.conf.json trafficLightPosition), so
// a picture shows whether anything sits under them; "&fullscreen=1" is the window in full screen (no lights).
// "&inactive=1": the window not in front (macOS greys its buttons; the page looks the same).
const platform = params.get("platform");
if (platform === "macos" || platform === "windows") document.documentElement.dataset.platform = platform;
const fullscreen = params.get("fullscreen") === "1";
// As main.tsx decides it from the window (lib/titlebar.ts): the strip in a normal Mac window.
applyTitlebar(titlebarMode(platform, fullscreen));
if (platform === "macos" && params.get("lights") === "1" && !fullscreen) drawTrafficLights(params.get("inactive") === "1");

/**
 * macOS's three window buttons over the page, at the left whatever the page's direction. tao moves the close button's
 * frame (14 x 16) to x and makes the title bar 16 + y tall, the buttons 6 above its bottom edge: each 12 px light is
 * centred 7 px into its frame, the next one 20 px on.
 */
function drawTrafficLights(inactive: boolean) {
  const { x, y } = conf.app.windows[0].trafficLightPosition;
  const strip = document.createElement("div");
  strip.dataset.trafficLights = "";
  strip.setAttribute("aria-hidden", "true");
  strip.style.cssText = `position: fixed; left: ${x + 1}px; top: ${y - 4}px; width: 54px; height: 12px; z-index: 2147483647; pointer-events: none; display: flex; gap: 8px; direction: ltr;`;
  for (const colour of inactive ? ["#cfcfcf", "#cfcfcf", "#cfcfcf"] : ["#ff5f57", "#febc2e", "#28c840"]) {
    const light = document.createElement("span");
    light.style.cssText = `width: 12px; height: 12px; border-radius: 50%; background: ${colour}; box-shadow: inset 0 0 0 0.5px rgba(0, 0, 0, 0.2);`;
    strip.append(light);
  }
  document.body.append(strip);
}

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
const UPLOADS: UploadItem[] = [
  { id: "u-imp", sourceApp: "imported", title: "Supplier interview.m4a", startedAt: new Date(Date.now() - 600_000).toISOString(), durationMs: 1_260_000, state: "uploading", lastError: null, attempts: 0, imported: true, progress: 45, heldFor: null },
  { id: "u-fail", sourceApp: "zoom", title: "Weekly sync", startedAt: new Date(Date.now() - 7_200_000).toISOString(), durationMs: 2_400_000, state: "failed", lastError: "The server could not verify this upload.", attempts: 3, imported: false, progress: null, heldFor: null },
  { id: "u-held1", sourceApp: "whatsapp", title: null, startedAt: new Date(Date.now() - 86_400_000).toISOString(), durationMs: 300_000, state: "queued", lastError: null, attempts: 0, imported: false, progress: null, heldFor: "sara@example.test" },
  { id: "u-held2", sourceApp: "phone_call", title: null, startedAt: new Date(Date.now() - 90_000_000).toISOString(), durationMs: 120_000, state: "queued", lastError: null, attempts: 0, imported: false, progress: null, heldFor: "sara@example.test" },
  { id: "u-check", sourceApp: "google_meet", title: "Budget review", startedAt: new Date(Date.now() - 1_800_000).toISOString(), durationMs: 900_000, state: "verifying", lastError: null, attempts: 1, imported: false, progress: null, heldFor: null },
];
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
// `&untitled=1`: the opened recording has no title (D-031: it is called Recording everywhere).
const LISTED = params.get("untitled") === "1" ? ITEMS.map((i) => (i.id === opened ? { ...i, title: null, title_auto: false } : i)) : ITEMS;
// v1.6: "-free" is an account on the Free plan (badges, Plan & usage, the own Groq key); "sign-in…" a computer signed out.
// v1.7: or `&plan=free|pro|ultra`, with `&request=<stage>` (an upgrade request at that stage), `&near=1` (Pro near its
// minutes), `&round=1` (two receipts added and not sent yet) and `&deleting=1` (the account is being deleted).
const planParam = params.get("plan");

// v1.8.1 Screen-share privacy: "settings-share" (Windows 11), "settings-share-mac" (Zoom installed, the per-app lines),
// "settings-share-old" (Windows 10 1909: the black box line), "settings-share-pass-mac" / "-fail-mac" / "-error-mac"
// (the check's three results; "settings-share-pass" the same on Windows), "settings-share-off" / "-floating" / "-demo"
// (the setting Off, Only floating windows, the 10 minutes running), "controls-share-visible" and
// "controls-share-notchecked" (the floating windows' badge), "controls-translate-box" / "-subtitles" (Live Translate
// with its badge), "controls-check-card" (the test card of the test with the phone), "library-share-notice-mac" (the
// main window opened during a whole-screen share), "settings-share-bar-mac" (the windows off while the sharing bar is on
// screen: no Show, and when they come back).
const share = screen.includes("share");
const shareResult = screen.includes("-fail") ? "fail" : screen.includes("-error") ? "error" : "pass";
/** A desktop as a capture would show it: a page, its text, the green control, and the test window where it leaked. */
function capturedScreen(leaked: boolean): string {
  const canvas = document.createElement("canvas");
  canvas.width = 960;
  canvas.height = 600;
  const g = canvas.getContext("2d")!;
  g.fillStyle = "#2b3a55";
  g.fillRect(0, 0, 960, 600);
  g.fillStyle = "#f9f9fb";
  g.fillRect(90, 60, 780, 470);
  g.fillStyle = "#3e63dd";
  g.fillRect(90, 60, 780, 36);
  g.fillStyle = "#c9ccd3";
  for (let y = 130; y < 500; y += 26) g.fillRect(130, y, 560 - ((y * 7) % 180), 10);
  g.fillStyle = "rgb(0, 200, 0)";
  g.fillRect(492, 230, 165, 112);
  if (leaked) {
    for (let x = 0; x < 165; x += 12) for (let y = 0; y < 112; y += 12) {
      g.fillStyle = ((x + y) / 12) % 2 === 0 ? "#ff00ff" : "#000000";
      g.fillRect(303 + x, 230 + y, 12, 12);
    }
  }
  return canvas.toDataURL("image/png");
}
const MAC_APPS = [
  { app: "zoom", name: "Zoom", version: "7.1.9", state: "hidden", line: "Zoom: hidden", needsTest: false },
  { app: "teams", name: "Teams", version: "25.290.1", state: "not_checked", line: "Teams: not checked", needsTest: true },
  { app: "meet", name: "Meet", version: "142.0.7444.60", state: "share_tab", line: "Meet: share a tab", needsTest: true },
  { app: "slack", name: "Slack", version: null, state: "not_checked", line: "Slack: not checked", needsTest: false },
  { app: "whatsapp", name: "WhatsApp", version: "26.1.0", state: "not_checked", line: "WhatsApp: not checked", needsTest: true },
];
/** The setting "Hide EchoLedger from screen sharing" of the screen. */
const hiding = screen.includes("share-off") ? "off" : screen.includes("share-floating") ? "floating" : "all";
function privacyStatus() {
  const old = screen.includes("share-old");
  const shownOnPurpose = hiding === "off" || screen.includes("share-demo");
  const visible = shownOnPurpose || screen.includes("share-visible") || shareResult === "fail" && screen.includes("-fail");
  const badge = old ? "black_box" : visible ? "visible" : screen.includes("share-error") || screen.includes("share-notchecked") ? "not_checked" : "private";
  const inShare = screen.includes("share-notice");
  // "settings-share-bar-mac": a whole-screen share keeps the windows off and its sharing bar is on screen.
  const bar = screen.includes("share-bar");
  return {
    platform: mac ? "macos" : "windows",
    badge,
    badgeLabel: badge === "visible" ? "Visible in screen share" : badge === "not_checked" ? "Not checked" : badge === "black_box" ? "Black box" : "Private",
    system: mac ? "macOS 27.3" : old ? "Windows 10 (build 18363)" : "Windows 11 24H2",
    hiding,
    oldWindows: old,
    lines: old ? [{ warning: true, text: "On this Windows version, people see a black box instead of EchoLedger. Update Windows for a clear result.", fix: null }] : [],
    demoSecondsLeft: screen.includes("share-demo") ? 540 : null,
    hiddenByShortcut: false,
    hiddenForShare: inShare || bar,
    mainInShare: inShare,
    shareNumber: inShare || bar ? 1 : 0,
    barOnScreen: bar,
    share: inShare || bar ? { app: "teams", mode: "screen", hid: true } : null,
    lastCheck: old ? { at: new Date().toISOString(), result: "pass", reason: "old_windows", methods: [{ method: "wgc", result: "pass", reason: "old_windows" }] }
      : screen.includes("share-notchecked") ? null : { at: new Date().toISOString(), result: "pass", reason: null, methods: [{ method: mac ? "sck" : "wgc", result: "pass", reason: null }] },
    checkRunning: false,
    checkDue: null,
    apps: mac ? MAC_APPS : [],
    zoomInstalled: mac,
    permission: !screen.includes("-error"),
    floatingOpen: true,
    displays: mac ? 2 : 1,
  };
}
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
  // `&unlimited=1`: minutes without a monthly limit (the Ultra account in the owner's screenshots).
  if (params.get("unlimited") === "1") e.limits.monthly_minutes = { limit: null, used: 651, resets_at: "2026-12-01" };
  if (requestStage) {
    // A decided request shows for a few days after its decision (requestShown): decided this morning, so it always does.
    const decided = requestStage === "approved" || requestStage === "declined" ? { decided_at: new Date(new Date().setHours(9, 0, 0, 0)).toISOString() } : {};
    const r = makeUpgradeRequest(requestStage, { plan: pro ? "ultra" : "pro", amount_due: pro ? "Rs 12,499" : "Rs 2,999", amount_due_pkr: pro ? 12499 : 2999, ...decided });
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

// Phase 1C: the Ready check, ready or with two things to fix ("record-not-ready"); "&older=1": the microphone was
// allowed before on this Mac and macOS calls it "not asked" now (D-120), so calls are not ready.
const older = params.get("older") === "1";
const twoToFix = screen === "record-not-ready";
const notReady = twoToFix || older;
const check = (id: string, label: string, ok: boolean, detail: string | null = null, fix: { label: string; action: string } | null = null) => ({ id, label, ok, detail, fix });
const READY_REPORT = {
  ready: !notReady,
  checkedAt: new Date().toISOString(),
  checks: [
    check("account", "Signed in", true),
    check("microphone", "Microphone", true),
    older
      ? check("microphone_permission", "Microphone allowed", false, "Allowed before on this Mac. If System Settings already lists EchoLedger, remove it there; macOS asks again when a recording starts.", { label: "Open System Settings", action: "allow_microphone" })
      : check("microphone_permission", "Microphone allowed", true),
    twoToFix
      ? check("other_side", "The other side of calls", false, "EchoLedger may not record this computer's sound: only your voice would be recorded.", { label: "Open System Settings", action: "allow_system_audio" })
      : check("other_side", "The other side of calls", true),
    twoToFix
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
    // "record-recording": Home while a Zoom call records (no capture method, no list of apps; D-013, D-014).
    recording: screen === "record-recording" ? makeRecording({ sourceApp: "zoom", backend: "core_audio_tap" }) : null,
    levels: screen === "record-recording" ? [0.42, 0.31] : [0, 0],
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
    // "library-banners-mac": a forgotten permission and 80 % of the minutes at once; one banner shows.
    budget: screen === "library-banners-mac" ? "warn" : "ok",
  }),
  account_get: () => (params.get("deleting") === "1" ? { ...ACCOUNT, deletes_at: "2026-12-09T10:00:00Z" } : ACCOUNT),
  // Send to WhatsApp (v1.6): the browser opens nothing here; the voice is "saved" in Downloads.
  whatsapp_open: () => false,
  save_audio_downloads: () => "C:\\Users\\Ayesha\\Downloads\\Kick-off Contoso website 2026-11-06.ogg",
  // Text and voice (F6): no share here, so the fallback: the voice in Downloads and on the clipboard, pasted with the
  // system's keys ("-mac": ⌘V). "whatsapp-opened": a share whose end is not known (Windows 10, or no answer in time).
  whatsapp_share_voice: (args: Args) => screen.includes("whatsapp-opened") && args.share !== false ? { kind: "opened" } : ({
    kind: "fallback",
    path: mac ? "/Users/ayesha/Downloads/Kick-off Contoso website 2026-11-06.ogg" : "C:\\Users\\Ayesha\\Downloads\\Kick-off Contoso website 2026-11-06.ogg",
    fileOnClipboard: true,
    textCut: false,
    pasteKeys: mac ? "⌘V" : "Ctrl+V",
  }),
  update_status: () =>
    screen === "library-update"
      ? { phase: "ready", current: "1.5.0", version: "1.5.1", notes: "Faster sign-in and the account page.", checkedAt: new Date().toISOString(), message: null, progress: null }
      : { phase: "up_to_date", current: "1.5.0", version: null, notes: null, checkedAt: new Date(Date.now() - 3 * 3_600_000).toISOString(), message: "You have the latest version.", progress: null },
  get_settings: () => makeSettings({ deviceName: "Work laptop", consentAcknowledged: true, dictationReplacements: [{ from: "echo ledger", to: "EchoLedger" }, { from: "kuraishi", to: "Qureshi" }], dictationSnippets: [{ trigger: "insert signature", text: "Regards, Ayesha" }], onboardingDone: !onboarding, notificationCategories: { sharing: false }, notificationSounds: { usage: false }, hideFromSharing: hiding }),
  main_view_get: () => JSON.stringify({ tab, recordingId: empty || tab !== "library" || screen === "library-table" ? null : opened, scroll: {} }),
  lock_status: () => ({ enabled: false, locked: false, hasPin: false, osMethod: null, retryAfterSeconds: 0 }),
  library_list: () => (empty ? [] : LISTED),
  library_refresh: () => (empty ? [] : LISTED),
  list_clients: () => ({ data: CLIENTS }),
  library_views: () => (empty ? { views: {}, labels: [], folders: { inbox: 0 } } : LIBRARY_VIEWS),
  // The person's labels (lists, contract 2.0.0-m): the Library filters redesign shows them in the sidebar.
  lists_get: () => (empty ? [] : [
    { key: "kick-off", name: "Kick-off", color: LABEL_COLOURS.teal, position: 0, count: 1 },
    { key: "invoice", name: "Invoice", color: LABEL_COLOURS.amber, position: 1, count: 1 },
    { key: "follow up", name: "Follow up", color: LABEL_COLOURS.pink, position: 2, count: 0 },
  ]),
  library_folder: ({ folder }) => ITEMS.filter((i) => SERVER_FOLDERS[folder as SmartFolder]?.includes(i.id) ?? inFolderHere(i, folder as SmartFolder) === true),
  client_rules: ({ id }) => ({ data: RULES[id as string] ?? [] }),
  shares_of: () => ({ data: SHARES }),
  shared_with_me: () => ({ data: SHARED }),
  request_links: () => ({ data: REQUESTS }),
  session_detail: ({ id }) => ({ id, playback_url: audioUrl, markers: id === "r5" ? [{ id: "mk1", at_ms: 95_000, tag: "Budget", note: "Phase one estimate" }] : [], speaker_names: { Me: "You", Client: "Sara (Acme)" } }),
  // `&failure=<error_code>`: the failed call fails for that reason instead (D-130: Try again only where it can help).
  // `&check=1`: a second transcription disagreed on the "la ravel" line (D-132: said without the engine's name).
  transcript_detail: ({ id }) => (id === "t-r4" && params.get("failure") ? { ...TRANSCRIPTS["t-r4"], error_code: params.get("failure") }
    : id === "t-r5" && params.get("check") === "1" ? { ...TRANSCRIPTS["t-r5"], segments: TRANSCRIPTS["t-r5"].segments.map((s, i) => (i === 3
      ? { ...s, check: { provider: "groq", label: "Groq Whisper large-v3", text: "We will build it on Laravel with a Vue front end." } } : s)) }
    : TRANSCRIPTS[id as string] ?? { ...TRANSCRIPTS["t-r5"], id, suggestions: [], views: null }),
  transcript_views: () => VIEWS,
  session_screens: ({ id }) => ({ data: id === "r1" ? SCREENS_R1 : [] }),
  screen_image: ({ url }) => screenPictureFor(String(url)),
  help_guides: ({ q }) => ({ data: q ? HELP_GUIDES.filter((g) => `${g.title} ${g.excerpt}`.toLowerCase().includes(String(q).toLowerCase())) : HELP_GUIDES }),
  help_guide: () => HELP_GUIDE,
  help_whats_new: () => ({ data: WHATS_NEW }),
  support_send: () => null,
  last_device_report: () => ({ sentAt: new Date(Date.now() - 86_400_000).toISOString() }),
  unsure_markers: () => null,
  pending_list: () => (empty ? [] : PENDING),
  skipped_list: () => (empty ? [] : SKIPPED),
  // "library-recovered": the first recording was recovered after a restart, and its transcript renamed it.
  recovered_sessions: () => (screen === "library-recovered" ? [ITEMS[0].id] : []),
  // `&uploads=1`: a file being imported, one upload that failed, two waiting for another account and one being checked.
  upload_list: () => (params.get("uploads") === "1" ? UPLOADS : []),
  activity_list: () => ({ data: ACTIVITY, unread_count: ACTIVITY.filter((a) => !a.read).length }),
  // `&found=1`: recordings whose title holds what was typed come back, as from the server (D-087: they arrive later).
  search_library: ({ query }) => {
    const q = String((query as { q?: string } | undefined)?.q ?? "").toLowerCase();
    const data = params.get("found") === "1" && q ? ITEMS.filter((i) => i.title?.toLowerCase().includes(q)).map((session) => ({ session, hit_count: 1, hits: [] })) : [];
    return { data, next_page: null };
  },
  session_notes: ({ id }) => (id === "r5" ? { data: NOTES_R5 } : null),
  client_workspace: ({ id }) => ({ data: id === "c-acme" ? WORKSPACE : { ...WORKSPACE, client: { ...WORKSPACE.client, id, name: CLIENTS.find((c) => c.id === id)?.name ?? "" }, brief: null, memory: [], notes: [], files: [] } }),
  client_ask: () => ({ data: ANSWER }),
  // A noise filter holds the microphone all the time, beside the Meet tab and an editor (D-014).
  mic_users: () => [makeMicUser({ name: "Krisp", app: null, playsAudio: false, site: null, decision: "ignored", reason: "unknown_app" }), makeMicUser(),
    makeMicUser({ name: "Code.exe", app: null, playsAudio: false, site: null, decision: "ignored", reason: "dictation" })],
  // What the core chose: the Zoom call earlier today, not recorded because its switch is off, not the filter (D-014).
  mic_last_not_recorded: () => ({ user: makeMicUser({ name: "zoom.us", app: "zoom", site: null, decision: "ignored", reason: "app_off" }), at: new Date(new Date().setHours(9, 40, 0, 0)).toISOString() }),
  translate_apps: () => [{ pid: 4242, name: "Valorant" }, { pid: 77, name: "Chrome" }],
  // "&translate=ended": a Live Translate session that ended on its own (core batch 5), its reason in the notice.
  translate_status: () => params.get("translate") === "ended"
    ? { mode: "game", app: "Valorant", locked: true, hidden: false, status: "ended", notice: null, partial: "", original: "", text: "" }
    : screen.startsWith("controls-translate") ? {
    mode: screen.endsWith("subtitles") ? "watch" : "game", app: "Valorant", locked: true, hidden: false, status: "live", notice: null, partial: "",
    original: screen.endsWith("subtitles") ? "" : "Necesitamos rotar al sitio B ahora mismo",
    text: screen.endsWith("subtitles") ? "We were never supposed to meet like this." : "We need to rotate to site B right now",
  } : null,
  screens_status: () => ({ supported: true, platform: mac ? "macos" : "windows", borderless: !mac, permission: true, inPlan: true, onServer: true }),
  screens_permission: () => true,
  input_devices: () => [
    { id: "default", name: "Microphone (Realtek Audio)", isDefault: !bluetooth, bluetooth: false },
    ...(bluetooth ? [{ id: "airpods", name: "AirPods Pro", isDefault: true, bluetooth: true }] : []),
  ],
  permissions_status: () => (mac
    ? {
        // "&older=1": the microphone was allowed before on this Mac and reads "not asked" now (D-120); "&reopen=1": Screen
        // Recording was switched on in System Settings while EchoLedger ran; "&reopen=2": and a reopen did not apply it.
        microphone: onboarding || older ? "unknown" : "allowed",
        // "&untold=1": this macOS lacks the privacy call, so system audio and Screen Recording read unknown (D-136).
        systemAudio: screen === "record-permission-mac" || onboarding ? "denied" : params.get("untold") === "1" ? "unknown" : "allowed",
        screen: onboarding ? "denied" : params.get("reopen") ? "reopen" : params.get("untold") === "1" ? "unknown" : "allowed",
        canTellNotAsked: params.get("untold") !== "1",
        reopenDidNotApply: params.get("reopen") === "2",
        browserAutomation: false,
        platform: "macos",
        missing: screen === "record-permission-mac" ? [NO_SYSTEM_AUDIO] : [],
        lost: screen === "library-lost-mac" || screen === "library-banners-mac" ? "macOS no longer lets EchoLedger use the microphone." : null,
        allowedBefore: older ? ["microphone"] : [],
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
          // "&reopen=1": Screen Recording allowed while EchoLedger ran, with the step's own Quit & reopen.
          params.get("reopen") === "1"
            ? { key: "screen_permission", title: "Screen Recording permission", state: "limited", detail: "Allowed in System Settings. Quit and reopen EchoLedger to finish.", fix: { label: "Quit & reopen", settings: "screen", reopen: true } }
            : { key: "screen_permission", title: "Screen Recording permission", state: "ok", detail: "Allowed. macOS asks again about once a month: choose Allow to keep screen moments.", fix: null },
          { key: "microphone_test", title: "Microphone test", state: "ok", detail: "Heard your microphone (loudest about -28 dB).", fix: null },
          { key: "system_audio_test", title: "System audio test", state: "off", detail: "Not tested: system audio is off for EchoLedger.", fix: { label: "Open System Settings", settings: "system_audio" } },
          { key: "meeting_apps", title: "Meeting apps", state: "ok", detail: "Installed: Zoom, Chrome, Safari. Not installed: Teams, WhatsApp, Edge, Arc. EchoLedger recognises all of them.", fix: null },
          { key: "overlay_windows", title: "Captions and recording controls on screen", state: "ok", detail: "The captions and the recording controls are on screen, above other windows, in every Space and over full-screen meetings.", fix: null },
          { key: "screen_capture_test", title: "Screen moment test", state: "ok", detail: "One frame captured and thrown away. Nothing was stored.", fix: null },
        ],
      }
    : null,
  security_overview: () => ({ devices: [{ id: "d1", name: "Work laptop", platform: "windows", app_version: "1.3.0", last_seen_at: new Date().toISOString(), paired_at: null, current: true }, { id: "d2", name: "Pixel 9", platform: "android", app_version: "1.3.0", last_seen_at: new Date().toISOString(), paired_at: null, current: false }], events: [
    { id: 1, event: "sign_in", label: "Signed in", summary: "Signed in", actor: "device", device: "Work laptop", at: new Date(Date.now() - 3_600_000).toISOString() },
    { id: 2, event: "two_factor_on", label: "Two-step on", summary: "Two-step sign-in turned on", actor: "web", device: null, at: new Date(Date.now() - 86_400_000).toISOString() },
  ] }),
  storage_info: () => ({ recordings_bytes: 812_000_000, recordings_count: 42, disk_free_bytes: 80_123_456_789, disk_total_bytes: 107_374_182_400, retention_days: 30 }),
  dictation_history: () => [{ at: new Date(Date.now() - 600_000).toISOString(), text: "Please send the signed proforma by Thursday, and copy Bilal on it." },
    { at: new Date(Date.now() - 3_600_000).toISOString(), text: "Meeting moved to 4 pm." }],
  marker_tags: () => ["Budget", "Decision", "Follow up"],
  recording_state: () => makeRecordingState({
    elapsedMs: 134_000,
    sourceApp: "whatsapp",
    language: "ur",
    bluetoothMic: bluetooth,
    // "controls-warning": the other side not being recorded (2.0.6), with the call choice's menu open over it.
    missing: screen === "controls-warning" ? [{ kind: "system_audio", sentence: "The other side isn't being recorded. Allow System Audio Recording for EchoLedger.", otherSide: "permission" }]
      : screen === "controls-permission" ? [NO_SYSTEM_AUDIO] : screen === "controls-nothing" ? [{ kind: "microphone", sentence: NOTHING_TO_RECORD }, { kind: "system_audio", sentence: NOTHING_TO_RECORD }] : [],
    deviceNote: screen === "controls-device" ? { text: "Microphone changed to AirPods Pro. Still recording.", warning: false } : null,
    // "At the start of the call": the banner beside the pill. No choice is made yet: nothing on the menu is ticked.
    startBanner: screen === "controls-banner",
    callChoice: null,
  }),
  // The after-call window (the call choice), one thing at a time: the card (with its first-time line on
  // "controls-card"), a confirmation, the grouped card, or "Always do this?". Each stays for the picture.
  after_call_state: () => {
    const call = { ...PENDING[1], title: null, clientId: null, clientName: null, callerName: "Muneeb Quraishi", sourceApp: "whatsapp", durationMs: 44_000 };
    const until = new Date(Date.now() + 3_600_000).toISOString();
    if (screen === "controls-done") return makeAfterCallState({ done: makeCallDone({ ...call, id: call.id, kind: "audio", until }) });
    if (screen === "controls-discarded") return makeAfterCallState({ done: makeCallDone({ ...call, id: call.id, kind: "delete", until }) });
    if (screen === "controls-always") return makeAfterCallState({ done: makeCallDone({ ...call, id: call.id, kind: "transcribe", until, offerAlways: "always_transcribe" }) });
    // Back to back: Omar's call was saved as audio, then Muneeb's was stopped and deleted from the pill: one card, both Undos.
    // Undo on Omar's row: his card is back, Muneeb's Delete held under it with its own Undo.
    if (screen === "controls-held") return makeAfterCallState({ ask: makeChipItem("g1", { ...call, id: "g1", callerName: "Omar Khan", deadlineAt: new Date(Date.now() + 5_000).toISOString() }), held: [makeCallDone({ ...call, id: call.id, kind: "delete", until })] });
    if (screen === "controls-group") return makeAfterCallState({ group: makeCallGroup([makeCallDone({ ...call, id: "g1", callerName: "Omar Khan", kind: "audio", until }), makeCallDone({ ...call, id: call.id, kind: "delete", until })], Date.now() + 3_592_000) });
    return makeAfterCallState({ hint: screen === "controls-card", ask: makeChipItem(call.id, { ...call, deadlineAt: new Date(Date.now() + 5_000).toISOString() }) });
  },
  privacy_status: () => privacyStatus(),
  privacy_concealed: () => false,
  privacy_diagnostics: () => "",
  share_check_card: () => null,
  share_check_run: () => ({
    result: shareResult,
    reason: shareResult === "error" ? "permission_missing" : null,
    methods: mac ? [{ method: "sck", result: shareResult, reason: shareResult === "error" ? "permission_missing" : null }]
      : [{ method: "wgc", result: shareResult, reason: null }, { method: "dxgi", result: shareResult, reason: null }],
    system: mac ? "macOS 27.3" : "Windows 11 24H2",
    at: new Date().toISOString(),
    picture: shareResult === "error" ? null : capturedScreen(shareResult === "fail"),
    width: 960,
    height: 600,
    testArea: shareResult === "error" ? null : [303 / 960, 230 / 600, 165 / 960, 112 / 600],
    controlArea: shareResult === "error" ? null : [492 / 960, 230 / 600, 165 / 960, 112 / 600],
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
  if (screen.startsWith("controls-translate")) {
    const { default: TranslateOverlayApp } = await import("../../src/views/translate/TranslateOverlayApp");
    // The window itself (the screenshot's viewport is its size): the box over a game, the subtitles over a video.
    document.documentElement.dataset.window = "translate";
    document.body.style.background = screen.endsWith("subtitles") ? "#20242b" : "#3b4250";
    root.render(<TranslateOverlayApp />);
    return;
  }
  if (screen === "controls-check-card") {
    const { default: ShareCheckWindow } = await import("../../src/views/sharecheck/ShareCheckWindow");
    // The card window itself, 640 x 360 (the screenshot's viewport).
    document.documentElement.dataset.window = "share-check-card";
    root.render(<ShareCheckWindow kind="card" />);
    return;
  }
  if (screen === "controls-dictation-refused") {
    const { default: DictationApp } = await import("../../src/views/dictation/DictationApp");
    const { emit } = await import("@tauri-apps/api/event");
    // The window's platform, as main.tsx sets it from window_appearance: the settings button says it in its words.
    document.documentElement.dataset.platform = mac ? "macos" : "windows";
    root.render(<div className="screens-canvas"><div className="screens-window" style={{ width: 420, height: 110 }}><DictationApp /></div></div>);
    // What the core sends when it refuses.
    window.setTimeout(() => {
      void emit("overlay:visibility", true);
      captions?.onmessage({ kind: "status", live: false, recording: false, provider: null, message: "The microphone is off for EchoLedger. Allow it in your computer's privacy settings." });
      void emit("dictation:state", { active: false, sounds: false, mode: "toggle", permission: "microphone" });
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
      <div className="screens-window" style={{ width: 352, height: 228 }}><PillApp /></div>
      <div className="screens-window" style={{ width: 360, height: 340 }}><ReviewApp /></div>
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
