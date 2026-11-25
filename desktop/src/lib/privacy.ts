// Screen-share privacy (since v1.8.1): the words the badge, Settings and "Check screen-share privacy" use, and the hooks
// every window uses to follow the privacy status and to draw nothing while EchoLedger's windows are off the screen. The
// words follow the words rule and the names of docs/DESIGN_SYSTEM.md: "Private", "Black box", "Visible in screen
// share"; nothing here promises more than the check measured.
import { useEffect, useState } from "react";
import { api, listenTo, type CheckOutcome, type LastCheck, type MeetingAppRow, type PrivacyBadge, type PrivacyStatus, type ShareCheckView } from "./ipc";
import { isRtl, isolate, language, t, tc } from "../i18n";
import { whenParts } from "./format";

export const BADGE_LABEL: Record<PrivacyBadge, string> = {
  get private() { return t("Private"); },
  get black_box() { return t("Black box"); },
  get not_checked() { return t("Not checked"); },
  get visible() { return t("Visible in screen share"); },
};

/** What a tap on the badge is for, for screen readers and the tooltip. */
/** The badge sits on the recording controls, the dictation box and Live Translate (FIX-01): the first two always show in
 * screen shares, so it speaks of the live words, never of the window it is on. */
export const BADGE_HINT: Record<PrivacyBadge, string> = {
  get private() { return t("Private: live words are hidden from screen shares. Check screen-share privacy"); },
  get black_box() { return t("Black box: people see a black box instead of the live words. Check screen-share privacy"); },
  get not_checked() { return t("Not checked on this system yet. Check screen-share privacy"); },
  get visible() { return t("Live words may show in screen shares. Check screen-share privacy"); },
};

/** "Zoom", "Zoom and Teams", "Zoom, Teams and Slack" (pure). */
export function nameList(names: string[]): string {
  if (names.length <= 1) return names.join("");
  // In a right-to-left window each name in its own isolate, joined with the Urdu comma, the run marked right to left
  // (an RLM first) so the names read in their order from the right.
  const first = isRtl() ? `\u200F${names.slice(0, -1).map(isolate).join("، ")}` : names.slice(0, -1).join(", ");
  return t(":names and :last", { names: first, last: names[names.length - 1] });
}

/** "today", "yesterday", or "on 12 Nov" (pure; `now` for tests). */
export function checkedWhen(at: string, now: Date = new Date()): string {
  const then = new Date(at);
  const day = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((day(now) - day(then)) / 86_400_000);
  if (days <= 0) return t("today");
  if (days === 1) return t("yesterday");
  // English as it always was; in another language the day as every date line says it (whenParts: "12 نومبر").
  const date = language() === "en" ? `${then.getDate()} ${then.toLocaleString("en-GB", { month: "short" })}` : whenParts(at, now)?.day ?? "";
  return t("on :date", { date });
}

export interface ResultLine {
  text: string;
  tone: "success" | "warning" | "neutral";
  /** The fix a button offers: the system's Screen Recording settings, or running the check again. */
  fix: "open_settings" | "try_again" | null;
}

/** The one plain line under a check's result (pure). On a Mac a pass says only what this check saw, since each meeting
 * app captures its own way; a fail names the meeting apps here not confirmed hidden by the test with the phone. `where`
 * is the page ("test yours below") or onboarding. */
export function resultLine(result: CheckOutcome, reason: string | null, system: string, at: string, platform: string, now: Date = new Date(), apps: MeetingAppRow[] = [], where: "page" | "onboarding" = "page"): ResultLine {
  // Each line one sentence or two whole ones: what was seen, then when it was checked (its own sentence).
  const checked = t("Checked on :system, :when.", { system, when: checkedWhen(at, now) });
  if (result === "pass" && reason === "old_windows") {
    return { text: t("Private: people see a black box instead of EchoLedger. :checked", { checked }), tone: "success", fix: null };
  }
  if (result === "pass" && platform === "macos") {
    const text = where === "page"
      ? t("Hidden in this check. Each meeting app captures differently on a Mac: test yours below. :checked", { checked })
      : t("Hidden in this check. Each meeting app captures differently on a Mac: test yours in Settings › Screen-share privacy. :checked", { checked });
    return { text, tone: "success", fix: null };
  }
  if (result === "pass") {
    return { text: t("Hidden. People who see your whole screen won't see EchoLedger. :checked", { checked }), tone: "success", fix: null };
  }
  if (result === "fail") {
    if (platform !== "macos") return { text: t("Visible in screen share on this computer. Share one window or a tab instead."), tone: "warning", fix: null };
    const open = apps.filter((a) => a.version && a.state !== "hidden").map((a) => a.name);
    return {
      text: open.length ? t("Visible on this Mac in :apps. Share one window or a tab instead.", { apps: nameList(open) })
        : t("Visible on this Mac in screen share. Share one window or a tab instead."),
      tone: "warning", fix: null,
    };
  }
  if (reason === "permission_missing") {
    return { text: t("EchoLedger needs the Screen Recording permission to see what others see. Allow it in System Settings, then check again."), tone: "warning", fix: "open_settings" };
  }
  if (reason === "covered") {
    return { text: t("Another window covered the test window, so nothing was checked. Move it away and try again."), tone: "warning", fix: "try_again" };
  }
  if (reason === "capture_failed") {
    return { text: t("The screen capture didn't work, so nothing was checked. Try again."), tone: "warning", fix: "try_again" };
  }
  return { text: t("The capture didn't show your screen, so nothing was checked. Try again."), tone: "warning", fix: "try_again" };
}

/** The line for the last check in Settings ("Hidden, checked today" style), from a kept result (pure). */
export function lastCheckLine(last: LastCheck | null, system: string, platform: string, now: Date = new Date()): string {
  if (!last) return t("Not checked yet");
  return resultLine(last.result, last.reason, system, last.at, platform, now).text;
}

/** The view as kept: a run is shown until the page closes, then its picture is dropped with it. */
export function summary(view: ShareCheckView, platform: string, now: Date = new Date(), apps: MeetingAppRow[] = [], where: "page" | "onboarding" = "page"): ResultLine {
  return resultLine(view.result, view.reason, view.system, view.at, platform, now, apps, where);
}

/** The demo's whole minutes left (at least 1), or null when it isn't on. */
export function demoMinutes(seconds: number | null): number | null {
  return seconds ? Math.max(1, Math.ceil(seconds / 60)) : null;
}

/** "9 min left" for the demo. */
export function demoLeft(seconds: number | null): string | null {
  const minutes = demoMinutes(seconds);
  return minutes === null ? null : tc(":count min left", minutes);
}

/** Follows the privacy status: read at once, again whenever the core says something changed. */
export function usePrivacyStatus(): PrivacyStatus | null {
  const [status, setStatus] = useState<PrivacyStatus | null>(null);
  useEffect(() => {
    let alive = true;
    const read = () => void api.privacyStatus().then((s) => alive && setStatus(s)).catch(() => undefined);
    read();
    // A settings change that matters here (the hiding choice) comes as privacy:changed too.
    const unlisten = [listenTo("privacy:changed", read)];
    return () => {
      alive = false;
      unlisten.forEach((p) => p.then((f) => f()).catch(() => undefined));
    };
  }, []);
  return status;
}

/** True while EchoLedger's windows are off the screen ("Hide EchoLedger windows", or a whole-screen share in an
 * app not confirmed hidden on this Mac): the window then draws nothing. */
export function useConcealed(): boolean {
  const [concealed, setConcealed] = useState(false);
  useEffect(() => {
    let alive = true;
    void api.privacyConcealed().then((c) => alive && setConcealed(c)).catch(() => undefined);
    const unlisten = listenTo("privacy:conceal", (c) => setConcealed(!!c));
    return () => {
      alive = false;
      unlisten.then((f) => f()).catch(() => undefined);
    };
  }, []);
  return concealed;
}
