// Typed wrappers around the Rust commands (src-tauri/src/commands.rs). Tokens never reach the webview.
import { Channel, invoke } from "@tauri-apps/api/core";

export type ShortcutAction = "toggle_captions" | "toggle_saving" | "copy_last2_min" | "dictation" | "voice_note";
export type Corner = "top_left" | "top_right" | "bottom_left" | "bottom_right";

export interface RecordingInfo {
  clientRef: string;
  mode: "call" | "voice_note";
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

export const api = {
  status: () => invoke<AppStatus>("app_status"),
  pair: (serverUrl: string, code: string, deviceName: string) => invoke<AppStatus>("pair_device", { serverUrl, code, deviceName }),
  unpair: () => invoke<AppStatus>("unpair"),
  settings: () => invoke<Settings>("get_settings"),
  updateSettings: (patch: Partial<Omit<Settings, "clientId">> & { clientId?: string | null }) =>
    invoke<Settings>("update_settings", { patch }),
  setShortcut: (action: ShortcutAction, accelerator: string) => invoke<Settings>("set_shortcut", { action, accelerator }),
  resetShortcuts: () => invoke<Settings>("reset_shortcuts"),
  toggleCaptions: () => invoke<AppStatus>("toggle_captions"),
  startRecording: (sourceApp?: string) => invoke<AppStatus>("start_recording", { sourceApp: sourceApp ?? null }),
  stopRecording: () => invoke<AppStatus>("stop_recording"),
  toggleVoiceNote: () => invoke<AppStatus>("toggle_voice_note"),
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
};

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
