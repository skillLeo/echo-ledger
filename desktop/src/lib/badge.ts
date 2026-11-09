// The Activity badge (v1.6, P6): it counts only what needs the person, never every unread notification. Someone saw
// "21" and "22" there because finished transcripts, new devices and teammates' shares all counted. Now it counts
// unread problems (a failed transcript or upload, the monthly limit reached), unread answers to an upgrade request and
// calls waiting for Keep or Discard, capped at "9+" like the phone's tab. Pure; unit-tested.
import type { ActivityItem } from "./ipc";

/** How many notifications are read for the badge (newest first): more than "9+" ever needs. */
export const BADGE_READ_LIMIT = 50;

/** Answers to an upgrade request (docs/API.md "Upgrade requests"). */
const UPGRADE_REPLIES = new Set(["upgrade_approved", "upgrade_declined"]);

/** A problem the person has to look at: the phone's rule (fail, error, problem) and the monthly limit reached. */
export function isProblem(kind: string | null | undefined): boolean {
  const k = (kind ?? "").toLowerCase();
  return k.includes("fail") || k.includes("error") || k.includes("problem") || k === "budget_reached";
}

/** An unread notification that asks something of the person. */
export function needsAction(item: Pick<ActivityItem, "kind" | "read">): boolean {
  if (item.read) return false;
  return isProblem(item.kind) || UPGRADE_REPLIES.has(item.kind ?? "");
}

/** The badge's number: unread problems and upgrade replies, plus calls waiting for a decision. */
export function actionCount(items: readonly Pick<ActivityItem, "kind" | "read">[] | null | undefined, waitingCalls = 0): number {
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
