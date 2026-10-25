// Screenshot harness: renders any EchoLedger window in a plain browser with the Tauri IPC mocked, so the views can be
// captured by a browser driver against `npm run dev`. Open /screenshots/harness.html?w=<window>&theme=<dark|light>
// [&view=library|record|settings|onboarding|lock|pairing]. Nothing here ships: it is not part of the app build.
import { mockIPC, mockWindows } from "@tauri-apps/api/mocks";
import { emit } from "@tauri-apps/api/event";
import {
  CALL_SEGMENTS,
  makeItem,
  makePendingItem,
  makeRecordingState,
  makeReviewPayload,
  makeSettings,
  makeStatus,
  makeTranscript,
  makeUploadItem,
} from "../src/test/fixtures";
import type { CaptionEvent, LibraryItem } from "../src/lib/ipc";

const params = new URLSearchParams(location.search);
const win = params.get("w") ?? "main";
const theme = params.get("theme") === "light" ? "light" : "dark";
const view = params.get("view") ?? "library";
const empty = params.get("empty") === "1";

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
      return ITEMS;
    case "transcript_detail":
      return makeTranscript({ segments: CALL_SEGMENTS });
    case "list_clients":
      return { data: [{ id: "c1", name: "Northwind", color: "#8b5cf6", session_count: 12 }, { id: "c2", name: "Aria Studio", color: "#22d3ee", session_count: 4 }] };
    case "pending_list":
      return empty ? [] : [makePendingItem("pend-1", { firstLine: "Thanks for joining, shall we start with the invoice?" })];
    case "upload_list":
      return empty ? [] : [makeUploadItem("up-1", { state: "failed", lastError: "No connection to the server", attempts: 3 })];
    case "lock_status":
      return { enabled: view === "lock", locked: view === "lock", hasPin: true, osMethod: "windows_hello", retryAfterSeconds: 0 };
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
      return makeRecordingState({ active: win === "pill", elapsedMs: 754_000, sourceApp: "google_meet", captions: true, levels: [0.55, 0.3] });
    case "review_state":
      return win === "review" ? makeReviewPayload({ item: makePendingItem("pend-1", { sourceApp: "google_meet", clientName: "Northwind", firstLine: "Thanks for joining. Let us start with the budget." }) }) : null;
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
if (win === "review") await emit("review:show", respond("review_state", undefined));
document.documentElement.dataset.shotReady = "1";
