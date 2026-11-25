// Formatting helpers shared by views (pure; covered by Vitest in Phase 4). Dates, times and numbers in the window's
// language (src/i18n): Urdu's ur-PK while the app speaks Urdu, the English forms the app has always shown otherwise.
import { dateLocale, isolate, language, numberLocale, PDI, RLI, t, wordsIn, type Lang } from "../i18n/locale.ts";

export const SOURCE_DEVICE_LABELS: Record<string, string> = { phone: "Phone", desktop: "Desktop", web: "Web" };
/** Display names of the server's `source_app` values (web/app/Enums/SourceApp.php). */
export const SOURCE_APP_LABELS: Record<string, string> = {
  whatsapp: "WhatsApp",
  whatsapp_business: "WhatsApp Business",
  whatsapp_web: "WhatsApp Web",
  google_meet: "Google Meet",
  zoom: "Zoom",
  microsoft_teams: "Microsoft Teams",
  telegram: "Telegram",
  signal: "Signal",
  messenger: "Messenger",
  instagram: "Instagram",
  skype: "Skype",
  slack: "Slack",
  discord: "Discord",
  phone_call: "Phone call",
  voice_note: "Voice note",
  other: "Other",
};

/**
 * The display label of a `source_app` id: an app by its name in every language, the three that are words ("Phone call",
 * "Voice note", "Other") in the window's language, or in English with `lang` "en" (a window still in English); an id
 * the app does not know yet is shown as is.
 */
export function sourceAppLabel(app: string, lang?: Lang): string {
  const { t } = wordsIn(lang);
  switch (app) {
    case "phone_call":
      return t("Phone call");
    case "voice_note":
      return t("Voice note");
    case "other":
      return t("Other");
    default:
      return SOURCE_APP_LABELS[app] ?? app;
  }
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

/** Account → Preferences (v1.6): times read "9:05 AM" (12-hour, the default) or "09:05" (24-hour). */
let clock24 = false;
export function setClock24(on: boolean): void {
  clock24 = on;
}
export function clockTime(date: Date): string {
  return clock24
    ? date.toLocaleTimeString(dateLocale(), { hour: "2-digit", minute: "2-digit", hourCycle: "h23" })
    : date.toLocaleTimeString(numberLocale(), { hour: "numeric", minute: "2-digit", hour12: true });
}

/** When something happened, in parts, in the window's language: the day ("Today", "Yesterday", "23 Nov") and the time. */
export type WhenParts = { day: string; time: string; today: boolean; yesterday: boolean };

/**
 * The parts of formatWhen, for a place that shows only one of them (Activity's time today, a project's last call, a
 * row's time): never by splitting formatWhen's line, which in Urdu is one right-to-left run. In Urdu the day is آج, کل
 * or "23 نومبر" and the time keeps its space from breaking ("2:00 PM" whole). Null with no date, or one that isn't.
 */
export function whenParts(iso?: string | null, now: Date = new Date()): WhenParts | null {
  if (!iso) return null;
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return null;
  const today = date.toDateString() === now.toDateString();
  const before = new Date(now);
  before.setDate(now.getDate() - 1);
  const yesterday = date.toDateString() === before.toDateString();
  const urdu = language() === "ur";
  const time = urdu ? clockTime(date).replace(/\s/g, "\u00A0") : clockTime(date);
  if (today) return { day: urdu ? t("Today") : "Today", time, today, yesterday };
  if (yesterday) return { day: urdu ? t("Yesterday") : "Yesterday", time, today, yesterday };
  return { day: date.toLocaleDateString(urdu ? "ur-PK" : [], { month: "short", day: "numeric" }), time, today, yesterday };
}

/** One of whenParts' parts as a line shows it: as it is in English; in Urdu in its own isolate, closed. */
export function whenPart(part: string): string {
  return language() === "ur" ? isolate(part) : part;
}

/**
 * When something happened: "Today · 2:00 PM", "Yesterday · 2:00 PM", "23 Nov · 2:00 PM". In Urdu the day and the time
 * are each their own isolated run inside one right-to-left run, so neither a left-to-right box (.el-ltr) nor a sentence
 * around it swaps the month and the time or puts PM on its own line. English as it always was.
 */
export function formatWhen(iso?: string, now: Date = new Date()): string {
  const parts = whenParts(iso, now);
  if (!parts) return "";
  return language() === "ur" ? `${RLI}${whenPart(parts.day)} · ${whenPart(parts.time)}${PDI}` : `${parts.day} · ${parts.time}`;
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
  return transcriptLines(segments, withTimestamps).join("\n");
}

/** The lines of [transcriptText], one per segment. */
export function transcriptLines(segments: { speaker: string; start_ms: number; text: string }[], withTimestamps: boolean): string[] {
  const single = new Set(segments.map((s) => s.speaker)).size <= 1;
  return segments.map((s) => `${withTimestamps ? `[${timestamp(s.start_ms)}] ` : ""}${single ? "" : `${s.speaker}: `}${s.text}`);
}

/** How unsure words appear in copied text (the server's `config.unsure`, plan A4). */
export interface UnsureMarkers {
  style: "brackets" | "suffix" | "off" | string;
  threshold: number;
  note: boolean;
  note_text: string;
}

export const DEFAULT_UNSURE: UnsureMarkers = {
  style: "brackets",
  threshold: 0.5,
  note: true,
  note_text: "Note: [?word] = the transcription is unsure about this word; [unclear] = inaudible audio.",
};

const MARKED = /\[\?([^\]]+)\]/g;

/** "[?word]" in the chosen style: kept, "word(?)", or plain. */
export function restyleUnsure(text: string, style: string): string {
  if (style === "suffix") return text.replace(MARKED, "$1(?)");
  if (style === "off") return text.replace(MARKED, "$1");
  return text;
}

/**
 * A line as copied: the view's text when one is shown (it already carries "[?word]"), else the words with the unsure
 * ones (scored below the threshold) marked; an edited line has no words and is copied as it is.
 */
export function markedLine(
  segment: { text: string; words?: { text: string; confidence?: number | null }[] },
  inView: string | null,
  markers: UnsureMarkers,
  style: string = markers.style,
): string {
  if (inView !== null) return restyleUnsure(inView, style);
  // The line keeps its own text (punctuation, words without timing); each unsure word is found in order and wrapped.
  const line = segment.text;
  let out = "";
  let at = 0;
  for (const w of segment.words ?? []) {
    const word = w.text.trim();
    const pos = word ? line.indexOf(word, at) : -1;
    if (pos < 0) continue;
    const unsure = w.confidence != null && w.confidence < markers.threshold && !word.startsWith("[");
    out += line.slice(at, pos) + (unsure ? `[?${word}]` : word);
    at = pos + word.length;
  }
  return restyleUnsure(out + line.slice(at), style);
}

/** Whether a copied text has a mark the note line explains. */
export function hasUnsureMarks(text: string): boolean {
  return /\[\?[^\]]+\]|\[unclear|\(\?\)/.test(text);
}

/**
 * The text Copy puts on the clipboard: the note line first for "Copy for AI" (always, with "[?word]" marks even when
 * the console turned marks off), or when the console has the note on and the text has marks. Copy for AI puts the
 * lines `after(i)` gives after line `i` (since v1.5 the screens of that moment, "[Screen 7 at 14:32: …]").
 */
export function copiedTranscript(
  segments: { speaker: string; start_ms: number; text: string; words?: { text: string; confidence?: number | null }[] }[],
  inView: (i: number) => string | null,
  withTimestamps: boolean,
  markers: UnsureMarkers,
  forAi = false,
  after?: (i: number) => string[],
): string {
  const style = forAi && markers.style === "off" ? "brackets" : markers.style;
  const lines = transcriptLines(segments.map((s, i) => ({ ...s, text: markedLine(s, inView(i), markers, style) })), withTimestamps);
  const body = lines.flatMap((line, i) => [line, ...(forAi && after ? after(i) : [])]).join("\n");
  const note = style === "suffix" ? markers.note_text.replace("[?word]", "word(?)") : markers.note_text;
  return forAi || (markers.note && style !== "off" && hasUnsureMarks(body)) ? `${note}\n\n${body}` : body;
}

/** Display form of an accelerator for the current platform. */
export function prettyShortcut(accelerator: string | undefined, platform: string, lang?: Lang): string {
  if (!accelerator) return wordsIn(lang).t("Not set");
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

const PROVIDER_NAMES: Record<string, string> = {
  assemblyai: "AssemblyAI",
  elevenlabs: "ElevenLabs",
  azure_mai: "Azure Speech",
  whispercpp_local: "whisper.cpp",
  parakeet_local: "Parakeet",
  soniox: "Soniox",
  groq: "Groq",
  gemini: "Gemini",
};

/** A speech engine by its product name ("assemblyai" → "AssemblyAI"); unknown ids are shown as sent (C8). */
export function providerName(id: string | null | undefined): string {
  if (!id) return "";
  return PROVIDER_NAMES[id] ?? id;
}

const PROCESS_NAMES: Record<string, string> = {
  chrome: "Google Chrome",
  msedge: "Microsoft Edge",
  firefox: "Firefox",
  brave: "Brave",
  opera: "Opera",
  arc: "Arc",
  safari: "Safari",
  zoom: "Zoom",
  "ms-teams": "Microsoft Teams",
  teams: "Microsoft Teams",
  whatsapp: "WhatsApp",
  slack: "Slack",
  discord: "Discord",
  skype: "Skype",
};

/** An app as people know it, not its file name ("chrome.exe" → "Google Chrome", "Softphone.exe" → "Softphone"). */
export function processName(name: string): string {
  const base = (name.split(/[\\/]/).pop() ?? name).replace(/\.(exe|app)$/i, "");
  return PROCESS_NAMES[base.toLowerCase()] ?? base;
}

/**
 * One size formatter (v1.7): "640 KB" and "120 MB" without decimals, "1 GB", "1.4 GB" and "20 GB" with one decimal
 * from a gigabyte up, the ".0" dropped.
 */
export function fileSize(bytes: number): string {
  const units = ["B", "KB", "MB", "GB", "TB"];
  let value = Math.max(0, bytes);
  let unit = 0;
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024;
    unit++;
  }
  // 1023.6 KB rounds to the next unit, never "1024 KB".
  if (unit > 0 && unit < 3 && Math.round(value) >= 1024) {
    value /= 1024;
    unit++;
  }
  const text = unit < 3 ? String(Math.round(value)) : value.toFixed(1).replace(/\.0$/, "");
  return `${text} ${units[unit]}`;
}

/** Money as the apps write it: "Rs 2,999" (whole rupees, grouped). */
export function rupees(amount: number): string {
  return `Rs ${Math.round(amount).toLocaleString(numberLocale())}`;
}

/** A whole number with groups: "3,000". */
export function count(n: number): string {
  return Math.round(n).toLocaleString(numberLocale());
}

/** A date in a row: "9 Dec" within this year, "9 Dec 2026" otherwise, in this computer's time zone. */
export function rowDate(iso: string | null | undefined, now: Date = new Date()): string {
  if (!iso) return "";
  const at = new Date(iso.length === 10 ? `${iso}T00:00:00` : iso);
  if (Number.isNaN(at.getTime())) return "";
  const thisYear = at.getFullYear() === now.getFullYear();
  return at.toLocaleDateString(dateLocale(), thisYear ? { day: "numeric", month: "short" } : { day: "numeric", month: "short", year: "numeric" }).replace("Sept", "Sep");
}

/** A date in a row that always has its year: "9 Dec 2026". */
export function rowDateWithYear(iso: string | null | undefined): string {
  return rowDate(iso, new Date(1970, 0, 1));
}

/** A date in a sentence: "9 December 2026", in this computer's time zone. */
export function sentenceDate(iso: string | null | undefined): string {
  if (!iso) return "";
  const at = new Date(iso.length === 10 ? `${iso}T00:00:00` : iso);
  return Number.isNaN(at.getTime()) ? "" : at.toLocaleDateString(dateLocale(), { day: "numeric", month: "long", year: "numeric" });
}

/** A time of day by Settings › Appearance › Times: "3:42 pm" (12-hour) or "15:42" (24-hour). */
export function formatTime(date: Date): string {
  if (clock24) return date.toLocaleTimeString(dateLocale(), { hour: "2-digit", minute: "2-digit", hourCycle: "h23" });
  const h = date.getHours();
  const m = date.getMinutes();
  return `${h % 12 === 0 ? 12 : h % 12}:${String(m).padStart(2, "0")} ${h < 12 ? "am" : "pm"}`;
}

/** A range of times: "10 pm–7 am" (":00" dropped) or "22:00–07:00". */
export function timeRange(from: string, to: string): string {
  const parse = (hhmm: string) => {
    const [h, m] = hhmm.split(":").map(Number);
    const d = new Date(2026, 0, 1, Number.isFinite(h) ? h : 0, Number.isFinite(m) ? m : 0);
    return d;
  };
  const one = (hhmm: string) => {
    const text = formatTime(parse(hhmm));
    return clock24 ? text : text.replace(":00", "");
  };
  return `${one(from)}–${one(to)}`;
}
