// Why a call was or wasn't recorded (A1.8; since D-014 in Settings › Calls & meetings, no longer on Home): one plain
// line per app using the microphone, saying what EchoLedger will do and why, so a call that was not recorded can be
// understood instead of silently missed. Plain words only: never how sound is captured.
import { processName, sourceAppLabel } from "./format";
import type { IgnoreReason, MicUser } from "./ipc";

/** Why an app is not recorded, in plain words. */
const NOT_RECORDED: Record<IgnoreReason, string> = {
  dictation: "no sound is playing, so it looks like dictation",
  unknown_site: "this website isn't one of your call sites",
  unknown_app: "it isn't a call app EchoLedger knows",
  never_record: "it's on your never-record list",
  auto_off: "auto-record is off",
  app_off: "auto-record is off for this app",
};

/** What EchoLedger does with it (pure): "Recorded now", "Will be recorded", "Will ask you", "Not recorded: …". */
export function decisionText(user: Pick<MicUser, "decision" | "reason">): string {
  switch (user.decision) {
    case "auto_record":
      return "Will be recorded";
    case "recording":
      return "Recorded now";
    case "ask":
      return "Will ask you";
    case "off":
      return `Not recorded: ${NOT_RECORDED.auto_off}`;
    default:
      return `Not recorded${user.reason && NOT_RECORDED[user.reason] ? `: ${NOT_RECORDED[user.reason]}` : ""}`;
  }
}

/** The app as people know it: the call app's name, the website, or the program's name. */
export function micUserName(user: Pick<MicUser, "app" | "site" | "name">): string {
  return user.app ? sourceAppLabel(user.app) : user.site?.trim() || processName(user.name);
}

/** Whether an app using the microphone is left unrecorded (pure): what the "last one not recorded" line keeps. */
export function notRecorded(user: Pick<MicUser, "decision">): boolean {
  return user.decision === "ignored" || user.decision === "off";
}

export type MicTone = "live" | "ok" | "ask" | "idle";

/** Colour of the row's status dot: red while recording, green when it will record, amber when it will ask. */
export function micTone(user: Pick<MicUser, "decision">): MicTone {
  if (user.decision === "recording") return "live";
  if (user.decision === "auto_record") return "ok";
  if (user.decision === "ask") return "ask";
  return "idle";
}
