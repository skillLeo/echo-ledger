// Why a call was or wasn't recorded (A1.8; since D-014 in Settings › Calls & meetings, no longer on Home): one plain
// line per app using the microphone, saying what EchoLedger will do and why, so a call that was not recorded can be
// understood instead of silently missed. Plain words only: never how sound is captured.
import { formatTime, processName, rowDate, sourceAppLabel, whenParts } from "./format";
import type { IgnoreReason, MicUser, NotRecordedMic } from "./ipc";
import { language, t } from "../i18n";

/** Why an app is not recorded, in plain words (the window's language): the reason on its own, for "…, because …". */
const NOT_RECORDED: Record<IgnoreReason, string> = {
  get dictation() { return t("no sound is playing, so it looks like dictation"); },
  get unknown_site() { return t("this website isn't one of your call sites"); },
  get unknown_app() { return t("it isn't a call app EchoLedger knows"); },
  get never_record() { return t("it's on your never-record list"); },
  get auto_off() { return t("auto-record is off"); },
  get app_off() { return t("auto-record is off for this app"); },
};
/** The same, as a whole line ("Not recorded: …"): one sentence each, never glued. */
const NOT_RECORDED_LINE: Record<IgnoreReason, string> = {
  get dictation() { return t("Not recorded: no sound is playing, so it looks like dictation"); },
  get unknown_site() { return t("Not recorded: this website isn't one of your call sites"); },
  get unknown_app() { return t("Not recorded: it isn't a call app EchoLedger knows"); },
  get never_record() { return t("Not recorded: it's on your never-record list"); },
  get auto_off() { return t("Not recorded: auto-record is off"); },
  get app_off() { return t("Not recorded: auto-record is off for this app"); },
};
const knownReason = (reason: IgnoreReason | null | undefined): reason is IgnoreReason => !!reason && reason in NOT_RECORDED;

/** What EchoLedger does with it (pure): "Recorded now", "Will be recorded", "Will ask you", "Not recorded: …". */
export function decisionText(user: Pick<MicUser, "decision" | "reason">): string {
  switch (user.decision) {
    case "auto_record":
      return t("Will be recorded");
    case "recording":
      return t("Recorded now");
    case "ask":
      return t("Will ask you");
    case "off":
      return NOT_RECORDED_LINE.auto_off;
    default:
      return knownReason(user.reason) ? NOT_RECORDED_LINE[user.reason] : t("Not recorded");
  }
}

/** Why it wasn't recorded, on its own ("auto-record is off"), or "" when no reason is known. */
function whyNot(user: Pick<MicUser, "decision" | "reason">): string {
  switch (user.decision) {
    case "auto_record":
    case "recording":
    case "ask":
      return decisionText(user);
    case "off":
      return NOT_RECORDED.auto_off;
    default:
      return knownReason(user.reason) ? NOT_RECORDED[user.reason] : "";
  }
}

/** The app as people know it: the call app's name, the website, or the program's name. */
export function micUserName(user: Pick<MicUser, "app" | "site" | "name">): string {
  return user.app ? sourceAppLabel(user.app) : user.site?.trim() || processName(user.name);
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
  // In another language the day and the time as every date line says them (whenParts: the time kept whole); English
  // as it always was.
  const parts = language() === "en" ? null : whenParts(iso, now);
  const said = parts?.time ?? time;
  if (at.toDateString() === now.toDateString()) return t("at :time", { time: said });
  if (at.toDateString() === yesterday.toDateString()) return t("yesterday at :time", { time: said });
  return t("on :date at :time", { date: parts?.day ?? rowDate(iso, now), time: said });
}

/** The line about the last app not recorded (pure), kept by the core since EchoLedger started, so a call from before
 * the page was opened is explained too: "Last not recorded: Slack at 3:42 pm, because it isn't a call app EchoLedger
 * knows." */
export function lastNotRecordedLine(last: NotRecordedMic, now: Date = new Date()): string {
  const why = whyNot(last.user);
  const when = seenWhen(last.at, now);
  const app = micUserName(last.user);
  // One sentence for each shape (a time known or not, a reason known or not), the values filled in.
  if (when && why) return t("Last not recorded: :app :when, because :why.", { app, when, why });
  if (when) return t("Last not recorded: :app :when.", { app, when });
  if (why) return t("Last not recorded: :app, because :why.", { app, why });
  return t("Last not recorded: :app.", { app });
}

export type MicTone = "live" | "ok" | "ask" | "idle";

/** Colour of the row's status dot: red while recording, green when it will record, amber when it will ask. */
export function micTone(user: Pick<MicUser, "decision">): MicTone {
  if (user.decision === "recording") return "live";
  if (user.decision === "auto_record") return "ok";
  if (user.decision === "ask") return "ask";
  return "idle";
}
