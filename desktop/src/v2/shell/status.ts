// The sidebar's status line, kept apart from the shell so the app can work it out without loading the new screens.
/** `time`: a recording's length, shown after its words but kept out of the live region (it changes every second). */
export type ShellStatus = { kind: "ready" | "setup" | "recording"; text: string; time?: string };

/** The sidebar's status line from the core's status: recording first, then a Ready check that found problems. */
export function shellStatus(s: { recording: unknown; elapsedMs: number; callsReady?: boolean | null }): ShellStatus {
  if (s.recording) {
    const total = Math.floor(s.elapsedMs / 1000);
    const pad = (n: number) => String(n).padStart(2, "0");
    return { kind: "recording", text: `Recording ${pad(Math.floor(total / 60))}:${pad(total % 60)}` };
  }
  if (s.callsReady === false) return { kind: "setup", text: "Needs setup" };
  return { kind: "ready", text: "Ready to record" };
}

/**
 * The sidebar's recording chip (classic's top bar): "Recording · Zoom · 02:14", "Paused · voice note · 02:14"; otherwise
 * the same as shellStatus.
 */
export function shellChip(s: { recording: { mode: string; sourceApp: string } | null; paused?: boolean; elapsedMs: number; callsReady?: boolean | null },
  appLabel: (app: string) => string): ShellStatus {
  const base = shellStatus(s);
  if (!s.recording) return base;
  const what = s.recording.mode === "mic_note" ? "voice note" : appLabel(s.recording.sourceApp);
  const time = base.text.replace(/^Recording /, "");
  return { kind: "recording", text: `${s.paused ? "Paused" : "Recording"} · ${what}`, time };
}
