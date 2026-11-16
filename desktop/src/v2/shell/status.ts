// The sidebar's status line, kept apart from the shell so the app can work it out without loading the new screens.
export type ShellStatus = { kind: "ready" | "setup" | "recording"; text: string };

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
