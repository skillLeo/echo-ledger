// The Record view's call-detection explainer (A1.8): one line per app holding the microphone, saying what the core
// saw and what it decided, so a call that was not recorded can be understood instead of silently missed.
import { sourceAppLabel } from "./format";
import type { IgnoreReason, MicUser } from "./ipc";

const IGNORED: Record<IgnoreReason, string> = {
  dictation: "Ignored: dictation (no audio playing)",
  unknown_site: "Ignored: unknown site",
  unknown_app: "Ignored: not a call app",
  never_record: "Ignored: on your never-record list",
  auto_off: "Auto-record is off",
};

/** The decision part of the line: `Decision: auto-record`, `Recording now`, `Ignored: …`, `Will ask you`, … */
export function decisionText(user: Pick<MicUser, "decision" | "reason">): string {
  switch (user.decision) {
    case "auto_record":
      return "Decision: auto-record";
    case "recording":
      return "Recording now";
    case "ask":
      return "Will ask you";
    case "off":
      return "Auto-record is off";
    case "ignored":
      return (user.reason && IGNORED[user.reason]) || "Ignored";
    default:
      return "Ignored";
  }
}

/** `Holds mic ✓ · Playing audio ✓ · Identified as Google Meet · Decision: auto-record` */
export function explainMicUser(user: MicUser): string {
  const parts = [`Holds mic ${user.holdsMic ? "✓" : "✗"}`, `Playing audio ${user.playsAudio ? "✓" : "✗"}`];
  if (user.app) parts.push(`Identified as ${sourceAppLabel(user.app)}`);
  parts.push(decisionText(user));
  return parts.join(" · ");
}

export type MicTone = "live" | "ok" | "ask" | "idle";

/** Colour of the row's status dot: red while recording, green when it will record, amber when it will ask. */
export function micTone(user: Pick<MicUser, "decision">): MicTone {
  if (user.decision === "recording") return "live";
  if (user.decision === "auto_record") return "ok";
  if (user.decision === "ask") return "ask";
  return "idle";
}
