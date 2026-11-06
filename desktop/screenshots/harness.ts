// Screenshot harness: renders any EchoLedger window in a plain browser with the Tauri IPC mocked, so the views can be
// captured by a browser driver against `npm run dev`. Open /screenshots/harness.html?w=<window>&theme=<dark|light>
// [&view=library|record|settings|onboarding|lock|pairing] [&after=saved|ask|both for w=review] [&screens=1: screen
// moments on the first recording, the projects and the pill, &platform=windows|macos]. Nothing here ships: it is not
// part of the app build.
import { mockIPC, mockWindows } from "@tauri-apps/api/mocks";
import { emit } from "@tauri-apps/api/event";
import {
  CALL_SEGMENTS,
  makeAfterCallState,
  makeChipItem,
  makeItem,
  makePendingItem,
  makeRecordingState,
  makeSavedToast,
  makeScreen,
  makeSettings,
  makeStatus,
  makeTranscript,
  makeUploadItem,
} from "../src/test/fixtures";
import type { CaptionEvent, LibraryItem, ProjectScreen, ScreenMoment } from "../src/lib/ipc";

const params = new URLSearchParams(location.search);
const win = params.get("w") ?? "main";
const theme = params.get("theme") === "light" ? "light" : "dark";
const view = params.get("view") ?? "library";
const empty = params.get("empty") === "1";
/** The after-call window (w=review): the Saved toast, the Ask-me chip, or both. */
const after = params.get("after") ?? "both";
/** Screen moments (v1.5) on the first recording, its project and the pill. */
const screens = params.get("screens") === "1";

try {
  localStorage.setItem("echoledger-theme", theme);
} catch {
  // ignore
}

const preview = (text: string) => ({ final_status: "completed", final_transcript_id: "tr", live_available: false, preview: text });
const ITEMS: LibraryItem[] = empty
  ? []
  : [
      makeItem("1", { title: "Weekly sync with Northwind", source_app: "google_meet", source_app_label: "Google Meet", started_at: "2026-10-26T09:30:00Z", duration_ms: 1_845_000, transcript: preview("Thanks for joining. Let us start with the budget for the second phase and then go over the launch dates.") }),
      makeItem("2", { title: "Invoice questions — Aria Studio", source_app: "zoom", source_app_label: "Zoom", started_at: "2026-10-26T07:10:00Z", duration_ms: 742_000, transcript: preview("Hi Sam, thanks for calling back. We had a question about the second invoice and the payment terms.") }),
      makeItem("3", { title: "Voice note", source_device: "phone", source_device_label: "Phone", source_app: "voice_note", source_app_label: "Voice note", started_at: "2026-10-25T16:02:00Z", duration_ms: 95_000, status: "processing", transcript: { final_status: "processing", final_transcript_id: null, live_available: true, preview: null } }),
      makeItem("4", { title: "WhatsApp call with Omar", source_device: "phone", source_device_label: "Phone", source_app: "whatsapp", source_app_label: "WhatsApp", started_at: "2026-10-24T12:44:00Z", duration_ms: 1_205_000, transcript: preview("Can we move the delivery to Thursday? The client wants to see the second draft first.") }),
      makeItem("5", { title: "Kick-off: Contoso website", source_app: "microsoft_teams", source_app_label: "Microsoft Teams", started_at: "2026-10-22T10:00:00Z", duration_ms: 3_120_000, transcript: preview("Our main goal for the new website is fewer support calls. Requirement one is a clear pricing page.") }),
    ];

/** A made-up shared screen: a web page with a title, lines of text and a button, in the colours of `n`. */
function painted(n: number, w: number, h: number): string {
  const canvas = document.createElement("canvas");
  canvas.width = w;
  canvas.height = h;
  const g = canvas.getContext("2d")!;
  const hues = [215, 160, 28, 280];
  const hue = hues[n % hues.length];
  g.fillStyle = "#f6f7f9";
  g.fillRect(0, 0, w, h);
  g.fillStyle = `hsl(${hue} 55% 42%)`;
  g.fillRect(0, 0, w, h * 0.12);
  g.fillStyle = "#ffffff";
  g.font = `600 ${Math.round(h * 0.05)}px sans-serif`;
  g.fillText(["Invoice #2041", "Checkout", "Launch plan", "Pricing"][n % 4], w * 0.04, h * 0.08);
  g.fillStyle = "#ffffff";
  g.fillRect(w * 0.04, h * 0.18, w * 0.6, h * 0.72);
  g.fillStyle = "#c9ced6";
  for (let i = 0; i < 7; i++) g.fillRect(w * 0.07, h * (0.25 + i * 0.08), w * (0.5 - (i % 3) * 0.08), h * 0.025);
  g.fillStyle = `hsl(${hue} 60% 48%)`;
  g.fillRect(w * 0.7, h * 0.2, w * 0.25, h * 0.08);
  g.fillStyle = "#ffffff";
  g.font = `600 ${Math.round(h * 0.035)}px sans-serif`;
  g.fillText(["Pay now", "Place order", "Approve", "Choose plan"][n % 4], w * 0.73, h * 0.255);
  return canvas.toDataURL("image/png");
}

const SCREENS: ScreenMoment[] = [
  makeScreen(1, 13_000, { description: "Invoice #2041 with the Pay now button" }),
  makeScreen(2, 66_000, { description: "The checkout page, shipping to Lahore" }),
  makeScreen(3, 70_000, { description: "The launch plan with the November dates" }),
];
const PROJECT_SCREENS: ProjectScreen[] = [
  ...SCREENS.map((sc) => ({ ...sc, session_id: "1", session_title: "Weekly sync with Northwind", session_started_at: "2026-10-26T09:30:00Z" })),
  ...[makeScreen(1, 40_000, { id: "p1" }), makeScreen(2, 300_000, { id: "p2", description: "The pricing page" })].map((sc) => ({ ...sc, session_id: "5", session_title: "Kick-off: Contoso website", session_started_at: "2026-10-22T10:00:00Z" })),
];
const pictureOf = (url: string): string => {
  const at = [...SCREENS, ...PROJECT_SCREENS].findIndex((sc) => sc.image_url === url || sc.thumb_url === url);
  return painted(Math.max(0, at), url.includes("thumb") ? 480 : 1600, url.includes("thumb") ? 270 : 900);
};

function respond(cmd: string, args: Record<string, unknown> | undefined): unknown {
  const status = makeStatus({
    paired: view !== "pairing",
    recording: view === "record" ? null : null,
    pendingDecisions: empty ? 0 : 1,
    captionsLive: false,
    pendingUploads: 0,
  });
  switch (cmd) {
    case "app_status":
      return status;
    case "get_settings":
      return makeSettings({ onboardingDone: view !== "onboarding", appLock: view === "lock" });
    case "library_list":
    case "library_refresh":
      return screens ? ITEMS.map((i, n) => (n === 0 ? { ...i, screen_count: SCREENS.length, client: { id: "c1", name: "Northwind" } } : { ...i, screen_count: 0 })) : ITEMS;
    case "session_screens":
      return { data: SCREENS };
    case "client_screens":
      return { data: PROJECT_SCREENS, next_cursor: "more" };
    case "screen_image":
      return pictureOf(String(args?.url ?? ""));
    case "screens_status":
      return { supported: true, platform: params.get("platform") ?? "windows", borderless: params.get("borderless") !== "0", permission: params.get("permission") !== "0", inPlan: true, onServer: true };
    case "transcript_detail":
      return makeTranscript({ segments: CALL_SEGMENTS });
    case "list_clients":
      return { data: [{ id: "c1", name: "Northwind", color: "#8b5cf6", session_count: 12, ...(screens ? { screens: true, parent_id: null, path: [], children_count: 0, nested_session_count: 12, ai_notes: false } : {}) }, { id: "c2", name: "Aria Studio", color: "#22d3ee", session_count: 4 }] };
    case "pending_list":
      return empty ? [] : [makePendingItem("pend-1", { firstLine: "Thanks for joining, shall we start with the invoice?" })];
    case "upload_list":
      return empty ? [] : [makeUploadItem("up-1", { state: "failed", lastError: "No connection to the server", attempts: 3 })];
    case "lock_status":
      return { enabled: view === "lock", locked: view === "lock", hasPin: true, osMethod: "windows_hello", retryAfterSeconds: 0 };
    case "window_appearance":
      return { material: params.get("material") ?? "none", accent: params.get("accent") ? `#${params.get("accent")}` : null, platform: params.get("platform") ?? "windows" };
    case "lock_unlock_os":
      return new Promise(() => undefined); // the Windows Hello prompt stays open
    case "activity_list":
      return { data: [], unread_count: 2 };
    case "mic_users":
      return [{ name: "chrome.exe", app: "google_meet", holdsMic: true, playsAudio: true, site: "meet.google.com", decision: "auto_record", reason: null }];
    case "input_devices":
      return [{ id: "default", name: "Microphone (Realtek Audio)", isDefault: true }];
    case "permissions_status":
      return { microphone: "granted", browserAutomation: false, platform: "windows" };
    case "marker_tags":
      return ["Requirement", "Budget", "Deadline", "Decision", "Follow-up"];
    case "storage_info":
      return { recordings_bytes: 1_250_000_000, recordings_count: 48, disk_free_bytes: 210_000_000_000, disk_total_bytes: 512_000_000_000, retention_days: 30 };
    case "bin_list":
    case "share_list":
    case "transcript_revisions":
      return { data: [] };
    case "security_overview":
      return { devices: [], events: [] };
    case "dictation_history":
      return [];
    case "recording_state":
      return makeRecordingState({ active: win === "pill", elapsedMs: 754_000, sourceApp: "google_meet", captions: true, levels: [0.55, 0.3], screens: screens ? { on: true, reason: null, count: 4 } : null });
    case "after_call_state":
      // The toast stays for the picture (an hour); the chip line starts at 6 of its 10 seconds.
      return win === "review"
        ? makeAfterCallState({
            saved: after === "ask" ? null : makeSavedToast({ callerName: "Omar Khan", sourceApp: "whatsapp", durationMs: 252_000, until: new Date(Date.now() + 3_600_000).toISOString() }),
            ask: after === "saved" ? null : makeChipItem("pend-1", { sourceApp: "google_meet", title: "Weekly sync with Northwind", durationMs: 1_845_000, deadlineAt: new Date(Date.now() + 6_000).toISOString() }),
          })
        : makeAfterCallState();
    case "subscribe_captions":
      channelId = (args?.onEvent as { id: number } | undefined)?.id ?? null;
      return null;
    default:
      return null;
  }
}

let channelId: number | null = null;
let channelIndex = 0;
function caption(e: CaptionEvent) {
  if (channelId === null) return;
  const internals = (window as unknown as { __TAURI_INTERNALS__: { runCallback: (id: number, data: unknown) => void } }).__TAURI_INTERNALS__;
  internals.runCallback(channelId, { index: channelIndex++, message: e });
}

mockWindows(win === "main" ? "main" : win);
mockIPC(respond, { shouldMockEvents: true });

// A window material shows the OS backdrop through the page: stand in for Mica with its usual tint.
if (win === "main" && params.get("material")) document.body.style.background = theme === "light" ? "#f3f3f3" : "#202020";
// Overlay-type windows are transparent: put a desktop-like wallpaper behind them so translucency can be judged.
if (win !== "main") {
  const paper = document.createElement("div");
  paper.style.cssText = "position:fixed;inset:0;z-index:-1;background:linear-gradient(120deg,#3b6e8f 0%,#9ab7c9 45%,#e8d9c4 100%)";
  document.body.prepend(paper);
}

await import("../src/main.tsx");

const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));
for (let i = 0; i < 100 && !document.getElementById("root")?.childElementCount; i++) await wait(50);
await wait(400);
if (win === "main" && view !== "library" && view !== "onboarding" && view !== "lock" && view !== "pairing") {
  await emit("main:navigate", { view, pendingId: null, rename: false });
}
if (win === "overlay" || win === "dictation") {
  caption({ kind: "status", live: true, recording: true, provider: "assemblyai", message: null });
  await emit("overlay:visibility", true);
  if (win === "overlay") {
    caption({ kind: "final", speaker: "Client", channel: 2, text: "Thanks for joining. Can we start with the budget for phase two?", startMs: 0, endMs: 3000 });
    caption({ kind: "final", speaker: "Me", channel: 1, text: "Sure. We kept it at forty thousand, the same as last month.", startMs: 3000, endMs: 6000 });
    caption({ kind: "partial", speaker: "Client", channel: 2, text: "Great, and the launch date is still" });
  } else {
    await emit("dictation:level", 0.45);
    caption({ kind: "partial", speaker: "Me", channel: 1, text: "Please send the second draft by Thursday" });
  }
}
if (win === "review") await emit("aftercall:state", respond("after_call_state", undefined));
document.documentElement.dataset.shotReady = "1";
