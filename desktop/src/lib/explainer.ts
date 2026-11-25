// Why a call was or wasn't recorded (A1.8; since D-014 in Settings › Calls & meetings, no longer on Home): one plain
// line per app using the microphone, saying what EchoLedger will do and why, so a call that was not recorded can be
// understood instead of silently missed. Plain words only: never how sound is captured.
import { formatTime, processName, rowDate, sourceAppLabel } from "./format";
import type { IgnoreReason, MicUser, NotRecordedMic } from "./ipc";

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
  // In English, as the sentences that name it ("Last not recorded: …") still are.
  return user.app ? sourceAppLabel(user.app, "en") : user.site?.trim() || processName(user.name);
}

/** Whether an app using the microphone is left unrecorded (pure): what the "last one not recorded" line is about. */
export function notRecorded(user: Pick<MicUser, "decision">): boolean {
  return user.decision === "ignored" || user.decision === "off";
}

/** The same app as the core counts it (pure): the same program, on the same website for a browser. The line about the
 * last one not recorded is left out while that app is listed: the list says what happens with it now. */
export function sameMicApp(a: Pick<MicUser, "name" | "site">, b: Pick<MicUser, "name" | "site">): boolean {
  return a.name === b.name && (a.site ?? null) === (b.site ?? null);
}

/** When it was last seen (pure): "at 3:42 pm", "yesterday at 3:42 pm" or "on 9 Dec at 3:42 pm" (Settings' clock). */
export function seenWhen(iso: string, now: Date = new Date()): string {
  const at = new Date(iso);
  if (Number.isNaN(at.getTime())) return "";
  const time = formatTime(at);
  const yesterday = new Date(now);
  yesterday.setDate(now.getDate() - 1);
  if (at.toDateString() === now.toDateString()) return `at ${time}`;
  if (at.toDateString() === yesterday.toDateString()) return `yesterday at ${time}`;
  return `on ${rowDate(iso, now)} at ${time}`;
}

/** The line about the last app not recorded (pure), kept by the core since EchoLedger started, so a call from before
 * the page was opened is explained too: "Last not recorded: Slack at 3:42 pm, because it isn't a call app EchoLedger
 * knows." */
export function lastNotRecordedLine(last: NotRecordedMic, now: Date = new Date()): string {
  const why = decisionText(last.user).replace(/^Not recorded(: )?/, "");
  const when = seenWhen(last.at, now);
  return `Last not recorded: ${micUserName(last.user)}${when ? ` ${when}` : ""}${why ? `, because ${why}` : ""}.`;
}

export type MicTone = "live" | "ok" | "ask" | "idle";

/** Colour of the row's status dot: red while recording, green when it will record, amber when it will ask. */
export function micTone(user: Pick<MicUser, "decision">): MicTone {
  if (user.decision === "recording") return "live";
  if (user.decision === "auto_record") return "ok";
  if (user.decision === "ask") return "ask";
  return "idle";
}
