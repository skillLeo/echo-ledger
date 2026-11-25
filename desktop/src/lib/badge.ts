// The Activity badge (v1.6, P6): it counts only what needs the person, never every unread notification. Someone saw
// "21" and "22" there because finished transcripts, new devices and teammates' shares all counted. Now it counts
// unread problems (a failed transcript or upload, the monthly limit reached), unread answers to an upgrade request and
// calls waiting for Keep or Discard, capped at "9+" like the phone's tab. Pure; unit-tested.
import type { ActivityBadge, ActivityItem } from "./ipc";

/** How many notifications are read for the badge (newest first): more than "9+" ever needs. */
export const BADGE_READ_LIMIT = 50;

/** Answers to an upgrade request (docs/API.md "Upgrade requests"), the receipt asked for among them (the server's
 * ActionBadge::UPGRADE_REPLIES). */
const UPGRADE_REPLIES = new Set(["upgrade_approved", "upgrade_declined", "upgrade_receipt_requested"]);
/** A call an app saw but didn't record (docs/API.md "Missed recordings"): it needs action, as the server counts it. */
const MISSED_RECORDING = "missed_recording";

/** The server lacks what uploads need (2.0.0-v, super admins only): a problem, drawn as the server's. */
export const SERVER_PROBLEM = "server_problem";

/** A problem the person has to look at: the phone's rule (fail, error, problem) and the monthly limit reached. */
export function isProblem(kind: string | null | undefined): boolean {
  const k = (kind ?? "").toLowerCase();
  return k.includes("fail") || k.includes("error") || k.includes("problem") || k === "budget_reached";
}

/** An unread notification that asks something of the person (the server's badge rule: problems, upgrade answers and
 * missed recordings); a problem that is over (resolved) no longer does. */
export function needsAction(item: Pick<ActivityItem, "kind" | "read" | "resolved">): boolean {
  if (item.read || item.resolved) return false;
  return isProblem(item.kind) || UPGRADE_REPLIES.has(item.kind ?? "") || item.kind === MISSED_RECORDING;
}

/** The badge's number: unread problems and upgrade replies, plus calls waiting for a decision. */
export function actionCount(items: readonly Pick<ActivityItem, "kind" | "read" | "resolved">[] | null | undefined, waitingCalls = 0): number {
  const unread = (items ?? []).filter(needsAction).length;
  return unread + Math.max(0, Math.floor(waitingCalls || 0));
}

/** What the badge says: nothing at 0, the number up to 9, then "9+". */
export function badgeLabel(count: number): string | null {
  if (!Number.isFinite(count) || count <= 0) return null;
  return count > 9 ? "9+" : String(Math.floor(count));
}

/** The words a screen reader hears after the page's name. */
export function badgeSpoken(count: number): string {
  return count === 1 ? "1 needs you" : `${count} need you`;
}

/** A failure among what the badge counts (its red), as the server decides it: a kind with "fail" or "error" in it. */
export function isFailure(kind: string | null | undefined): boolean {
  const k = (kind ?? "").toLowerCase();
  return k.includes("fail") || k.includes("error");
}

/** The rail's Activity number and colour (UX-08). */
export interface RailBadge {
  count: number;
  /** Red only when something failed; the accent otherwise. */
  tone: "accent" | "danger";
  /** From the server's one number (the same as on the phone and the web console), not counted here. */
  shared: boolean;
}

/**
 * The rail's Activity badge (UX-08, BE-04): the server's one number (`GET /api/badge`, the same on the phone and the web
 * console; a merged item counts once, a resolved one never; it counts the waiting calls it has a session for), plus the
 * calls waiting on this computer that the server doesn't know (`waitingHere`). Without the server's number (an older
 * server, or never read yet): counted here from the list by the server's rules, with every call waiting (`waiting`).
 */
export function railBadge(server: ActivityBadge | null | undefined, items: readonly Pick<ActivityItem, "kind" | "read" | "resolved">[] | null | undefined,
  waiting = 0, waitingHere = waiting): RailBadge {
  const whole = (n: number) => Math.max(0, Math.floor(n || 0));
  if (server && Number.isFinite(server.count)) {
    return { count: whole(server.count) + whole(waitingHere), tone: server.tone === "danger" ? "danger" : "accent", shared: true };
  }
  const counted = (items ?? []).filter(needsAction);
  return { count: counted.length + whole(waiting), tone: counted.some((i) => isFailure(i.kind)) ? "danger" : "accent", shared: false };
}

/** What the rail and the Dock say about its colour, for a screen reader and the tooltip: "something failed" in red. */
export const FAILED_WORDS = "something failed";

/** What the rail's badge says: nothing at 0, the number up to 99, then "99+" (as the server's `label`). */
export function railBadgeLabel(count: number): string | null {
  if (!Number.isFinite(count) || count <= 0) return null;
  return count > 99 ? "99+" : String(Math.floor(count));
}

/** Activity's group line (2.0.0-r): "3 recordings didn't finish uploading". */
export function uploadGroupTitle(count: number): string {
  return count === 1 ? "1 recording didn't finish uploading" : `${count} recordings didn't finish uploading`;
}

/** How often the same thing happened to one recording (2.0.0-r `repeat_count`): "3 times", nothing for once. */
export function repeatWords(count: number | null | undefined): string | null {
  return count && count > 1 ? `${Math.floor(count)} times` : null;
}
