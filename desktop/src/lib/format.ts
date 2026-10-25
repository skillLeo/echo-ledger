// Formatting helpers shared by views (pure; covered by Vitest in Phase 4).

export const SOURCE_DEVICE_LABELS: Record<string, string> = { phone: "Phone", desktop: "Desktop", web: "Web" };
/** Display names of the server's `source_app` values (web/app/Enums/SourceApp.php). */
export const SOURCE_APP_LABELS: Record<string, string> = {
  whatsapp: "WhatsApp",
  whatsapp_business: "WhatsApp Business",
  whatsapp_web: "WhatsApp Web",
  google_meet: "Google Meet",
  zoom: "Zoom",
  microsoft_teams: "Microsoft Teams",
  voice_note: "Voice note",
  other: "Other",
};

/** The display label of a `source_app` id; an id the app does not know yet is shown as is. */
export function sourceAppLabel(app: string): string {
  return SOURCE_APP_LABELS[app] ?? app;
}

export function formatDuration(ms?: number | null): string {
  if (ms == null || ms <= 0) return "";
  const total = Math.round(ms / 1000);
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const pad = (n: number) => n.toString().padStart(2, "0");
  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

export function formatWhen(iso?: string, now: Date = new Date()): string {
  if (!iso) return "";
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return "";
  const sameDay = date.toDateString() === now.toDateString();
  const time = date.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit", hour12: true });
  if (sameDay) return `Today · ${time}`;
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (date.toDateString() === yesterday.toDateString()) return `Yesterday · ${time}`;
  return `${date.toLocaleDateString([], { month: "short", day: "numeric" })} · ${time}`;
}

export function timestamp(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const mm = h > 0 ? m.toString().padStart(2, "0") : `${m}`;
  return `${h > 0 ? `${h}:` : ""}${mm}:${s.toString().padStart(2, "0")}`;
}

/**
 * Plain-text export of a transcript, identical on desktop, Android and the web console: one line per segment,
 * `[m:ss] ` prefix when timestamps are requested, `Speaker: ` prefix unless there is only one speaker.
 */
export function transcriptText(segments: { speaker: string; start_ms: number; text: string }[], withTimestamps: boolean): string {
  const single = new Set(segments.map((s) => s.speaker)).size <= 1;
  return segments
    .map((s) => `${withTimestamps ? `[${timestamp(s.start_ms)}] ` : ""}${single ? "" : `${s.speaker}: `}${s.text}`)
    .join("\n");
}

/** Display form of an accelerator for the current platform. */
export function prettyShortcut(accelerator: string | undefined, platform: string): string {
  if (!accelerator) return "Not set";
  return accelerator
    .split("+")
    .map((k) => {
      const key = k.trim();
      if (/^(CommandOrControl|CmdOrCtrl)$/i.test(key)) return platform === "macos" ? "⌘" : "Ctrl";
      // A physical Control key: acceleratorFromEvent records it on macOS next to Command.
      if (/^(Control|Ctrl)$/i.test(key)) return platform === "macos" ? "⌃" : "Ctrl";
      if (/^Shift$/i.test(key)) return platform === "macos" ? "⇧" : "Shift";
      if (/^(Alt|Option)$/i.test(key)) return platform === "macos" ? "⌥" : "Alt";
      if (/^Space$/i.test(key)) return "Space";
      return key.replace(/^Key/i, "");
    })
    .join(platform === "macos" ? "" : " + ");
}

/** Builds a Tauri accelerator from a keyboard event (used by the shortcut recorder). */
export function acceleratorFromEvent(e: Pick<KeyboardEvent, "ctrlKey" | "metaKey" | "shiftKey" | "altKey" | "code">, platform: string): string | null {
  const modifiers: string[] = [];
  if ((platform === "macos" && e.metaKey) || (platform !== "macos" && e.ctrlKey)) modifiers.push("CommandOrControl");
  if (platform === "macos" && e.ctrlKey) modifiers.push("Control");
  if (e.altKey) modifiers.push("Alt");
  if (e.shiftKey) modifiers.push("Shift");
  const code = e.code;
  if (!code || /^(Control|Shift|Alt|Meta|OS)(Left|Right)?$/.test(code)) return null;
  if (modifiers.length === 0) return null; // global shortcuts need at least one modifier
  let key = code;
  if (/^Key[A-Z]$/.test(code)) key = code.slice(3);
  else if (/^Digit\d$/.test(code)) key = code.slice(5);
  return [...modifiers, key].join("+");
}
