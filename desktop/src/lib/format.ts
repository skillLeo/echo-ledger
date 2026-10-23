// Formatting helpers shared by views (pure; covered by Vitest in Phase 4).

export const SOURCE_DEVICE_LABELS: Record<string, string> = { phone: "Phone", desktop: "Desktop", web: "Web" };
export const SOURCE_APP_LABELS: Record<string, string> = {
  whatsapp: "WhatsApp",
  whatsapp_business: "WhatsApp Business",
  google_meet: "Google Meet",
  zoom: "Zoom",
  voice_note: "Voice note",
  other: "Other",
};

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
  const time = date.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  if (sameDay) return `Today · ${time}`;
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (date.toDateString() === yesterday.toDateString()) return `Yesterday · ${time}`;
  return `${date.toLocaleDateString([], { month: "short", day: "numeric" })} · ${time}`;
}

export function timestamp(ms: number): string {
  const total = Math.floor(ms / 1000);
  const m = Math.floor(total / 60);
  const s = total % 60;
  return `${m}:${s.toString().padStart(2, "0")}`;
}

/** Display form of an accelerator for the current platform. */
export function prettyShortcut(accelerator: string | undefined, platform: string): string {
  if (!accelerator) return "Not set";
  return accelerator
    .split("+")
    .map((k) => {
      const key = k.trim();
      if (/^(CommandOrControl|CmdOrCtrl)$/i.test(key)) return platform === "macos" ? "⌘" : "Ctrl";
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
