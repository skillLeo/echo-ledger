// The sidebar's status line, kept apart from the shell so the app can work it out without loading the new screens. Its
// words are the window's language (D-255) unless the caller asks for English (a page still in English); the time is
// digits, and the app's name is the caller's (appLabel).
import { t, wordsIn, type Lang } from "../../i18n";

/** `time`: a recording's length, shown after its words but kept out of the live region (it changes every second). */
export type ShellStatus = { kind: "ready" | "setup" | "recording"; text: string; time?: string };

/** A recording's length as the line shows it: "02:14". */
function clock(elapsedMs: number): string {
  const total = Math.floor(elapsedMs / 1000);
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${pad(Math.floor(total / 60))}:${pad(total % 60)}`;
}

/**
 * The sidebar's status line from the core's status: recording first, then a Ready check that found problems. In `lang`:
 * the window's when left out, "en" beside a page's own English buttons.
 */
export function shellStatus(s: { recording: unknown; elapsedMs: number; callsReady?: boolean | null }, lang?: Lang): ShellStatus {
  const words = wordsIn(lang);
  if (s.recording) return { kind: "recording", text: words.t("Recording :time", { time: clock(s.elapsedMs) }) };
  if (s.callsReady === false) return { kind: "setup", text: words.t("Needs setup") };
  return { kind: "ready", text: words.t("Ready to record") };
}

/**
 * The sidebar's recording chip (classic's top bar): "Recording · Zoom · 02:14", "Paused · voice note · 02:14"; otherwise
 * the same as shellStatus.
 */
export function shellChip(s: { recording: { mode: string; sourceApp: string } | null; paused?: boolean; elapsedMs: number; callsReady?: boolean | null },
  appLabel: (app: string) => string): ShellStatus {
  const base = shellStatus(s);
  if (!s.recording) return base;
  const what = s.recording.mode === "mic_note" ? t("voice note") : appLabel(s.recording.sourceApp);
  return { kind: "recording", text: s.paused ? t("Paused · :what", { what }) : t("Recording · :what", { what }), time: clock(s.elapsedMs) };
}
