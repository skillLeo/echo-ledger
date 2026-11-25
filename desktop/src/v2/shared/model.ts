// The words a shared item is shown with on the v2 Shared page and in its read-only view (classic's SharedView words), in
// the account's language (src/i18n): read when they are shown, so a change of language shows at once.
import { createElement, Fragment, type ReactNode } from "react";
import type { SharedItem } from "../../lib/ipc";
import { formatWhen } from "../../lib/format";
import { isRtl, t } from "../../i18n";

/** A shared item's title: the recording's name (Recording when it has none), else the item's or the project's. */
export function sharedTitle(item: SharedItem): string {
  return item.session ? (item.title?.trim() || item.session.title)?.trim() || t("Recording") : item.title ?? item.client?.name ?? t("Shared item");
}

/** Who sent it: their name, else their email, else "A teammate". */
export function senderOf(item: Pick<SharedItem, "from">): string {
  return item.from?.name || item.from?.email || t("A teammate");
}

/**
 * A date and time with words ("Yesterday · 4:05 PM"): one piece, kept left to right in its own face (.el-ltr); .el-num
 * is for bare numbers. In Urdu formatWhen is one right-to-left run of the day and the time, each isolated (lib/format),
 * which .el-ltr keeps whole and on one line.
 */
export const when = (iso: string): ReactNode => createElement("span", { className: "el-ltr" }, formatWhen(iso));

/**
 * A line with a date in it ("Stops Nov 4 · 9:00 AM"), given as the line for a date. In English the date is its own
 * left-to-right piece (.el-ltr) in the line, as always. In Urdu the line is text, the date formatWhen's own run in it,
 * so a row reads the line's direction from its words (lineDir reads a line of text, not one drawn with a box in it).
 */
export function dated(line: (date: string) => string, iso: string): ReactNode {
  return isRtl() ? line(formatWhen(iso)) : withNode(line(SLOT), when(iso));
}

/** "From Imran · Yesterday · 4:05 PM": who sent it, and when. */
export function sentLine(item: Pick<SharedItem, "from" | "sent_at">): string {
  return [t("From :name", { name: senderOf(item) }), item.sent_at ? formatWhen(item.sent_at) : null].filter(Boolean).join(" · ");
}

/** The sender's note, in quotes: “Please check the totals” (in Urdu its own isolate, whatever its script). */
export const quoted = (note: string): string => t("“:note”", { note });

/**
 * Where a React node (a time in its own span) stands in a line: t("Stops :date", { date: SLOT }) and then
 * withNode(line, node). A private-use character, never in a sentence; in Urdu it is inside the value's isolate, as a
 * text value is.
 */
export const SLOT = "\uE000";
export function withNode(line: string, node: ReactNode): ReactNode {
  const [before, ...after] = line.split(SLOT);
  return createElement(Fragment, null, before, node, after.join(""));
}
