// Screen-share privacy (since v1.8.1): the words the badge, Settings and "Check screen-share privacy" use, and the hooks
// every window uses to follow the privacy status and to draw nothing while EchoLedger's windows are off the screen. The
// words follow the words rule and the names of docs/DESIGN_SYSTEM.md: "Private", "Black box", "Visible in screen
// share"; nothing here promises more than the check measured.
import { useEffect, useState } from "react";
import { api, listenTo, type CheckOutcome, type LastCheck, type MeetingAppRow, type PrivacyBadge, type PrivacyStatus, type ShareCheckView } from "./ipc";

export const BADGE_LABEL: Record<PrivacyBadge, string> = {
  private: "Private",
  black_box: "Black box",
  not_checked: "Not checked",
  visible: "Visible in screen share",
};

/** What a tap on the badge is for, for screen readers and the tooltip. */
export const BADGE_HINT: Record<PrivacyBadge, string> = {
  private: "Private: hidden from screen share. Check screen-share privacy",
  black_box: "Black box: people see a black box instead of EchoLedger. Check screen-share privacy",
  not_checked: "Not checked on this system yet. Check screen-share privacy",
  visible: "Visible in screen share. Check screen-share privacy",
};

/** "Zoom", "Zoom and Teams", "Zoom, Teams and Slack" (pure). */
export function nameList(names: string[]): string {
  if (names.length <= 1) return names.join("");
  return `${names.slice(0, -1).join(", ")} and ${names[names.length - 1]}`;
}

/** "today", "yesterday", or "on 12 Nov" (pure; `now` for tests). */
export function checkedWhen(at: string, now: Date = new Date()): string {
  const then = new Date(at);
  const day = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((day(now) - day(then)) / 86_400_000);
  if (days <= 0) return "today";
  if (days === 1) return "yesterday";
  return `on ${then.getDate()} ${then.toLocaleString("en-GB", { month: "short" })}`;
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
  const checked = `Checked on ${system}, ${checkedWhen(at, now)}.`;
  if (result === "pass" && reason === "old_windows") {
    return { text: `Private: people see a black box instead of EchoLedger. ${checked}`, tone: "success", fix: null };
  }
  if (result === "pass" && platform === "macos") {
    const test = where === "page" ? "test yours below" : "test yours in Settings › Screen-share privacy";
    return { text: `Hidden in this check. Each meeting app captures differently on a Mac: ${test}. ${checked}`, tone: "success", fix: null };
  }
  if (result === "pass") {
    return { text: `Hidden. People who see your whole screen won't see EchoLedger. ${checked}`, tone: "success", fix: null };
  }
  if (result === "fail") {
    if (platform !== "macos") return { text: "Visible in screen share on this computer. Share one window or a tab instead.", tone: "warning", fix: null };
    const open = apps.filter((a) => a.version && a.state !== "hidden").map((a) => a.name);
    return { text: `Visible on this Mac${open.length ? ` in ${nameList(open)}` : " in screen share"}. Share one window or a tab instead.`, tone: "warning", fix: null };
  }
  if (reason === "permission_missing") {
    return { text: "EchoLedger needs the Screen Recording permission to see what others see. Allow it in System Settings, then check again.", tone: "warning", fix: "open_settings" };
  }
  if (reason === "covered") {
    return { text: "Another window covered the test window, so nothing was checked. Move it away and try again.", tone: "warning", fix: "try_again" };
  }
  if (reason === "capture_failed") {
    return { text: "The screen capture didn't work, so nothing was checked. Try again.", tone: "warning", fix: "try_again" };
  }
  return { text: "The capture didn't show your screen, so nothing was checked. Try again.", tone: "warning", fix: "try_again" };
}

/** The line for the last check in Settings ("Hidden, checked today" style), from a kept result (pure). */
export function lastCheckLine(last: LastCheck | null, system: string, platform: string, now: Date = new Date()): string {
  if (!last) return "Not checked yet";
  return resultLine(last.result, last.reason, system, last.at, platform, now).text;
}

/** The view as kept: a run is shown until the page closes, then its picture is dropped with it. */
export function summary(view: ShareCheckView, platform: string, now: Date = new Date(), apps: MeetingAppRow[] = [], where: "page" | "onboarding" = "page"): ResultLine {
  return resultLine(view.result, view.reason, view.system, view.at, platform, now, apps, where);
}

/** "9 min left" for the demo. */
export function demoLeft(seconds: number | null): string | null {
  if (!seconds) return null;
  const minutes = Math.max(1, Math.ceil(seconds / 60));
  return `${minutes} min left`;
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
