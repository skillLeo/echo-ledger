// The words a shared item is shown with on the v2 Shared page and in its read-only view (classic's SharedView words), in
// the account's language (src/i18n): read when they are shown, so a change of language shows at once.
import { createElement, Fragment, type ReactNode } from "react";
import type { SharedItem } from "../../lib/ipc";
import { clockTime, formatWhen } from "../../lib/format";
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
 * A date and time with words ("Yesterday · 4:05 PM"): one piece, kept left to right in its own face; .el-num is for bare
 * numbers. In Urdu it is one piece in its own direction instead, its clock one left-to-right piece inside it: the date is
 * in Urdu ("23 نومبر · 2:00 PM", read right to left, the day first), and a forced left-to-right order would turn the
 * month and the time round, while a free one would put PM before 2:00.
 */
export function when(iso: string): ReactNode {
  const words = formatWhen(iso);
  if (!isRtl()) return createElement("span", { className: "el-ltr" }, words);
  const clock = clockTime(new Date(iso));
  if (!clock || !words.endsWith(clock)) return createElement("span", { dir: "auto" }, words);
  // The clock stays on one line: never 2:00 at the end of one and PM at the start of the next.
  return createElement("span", { dir: "auto" }, words.slice(0, -clock.length), createElement("span", { dir: "ltr", style: { whiteSpace: "nowrap" } }, clock));
}

/** "From Imran · Yesterday · 4:05 PM": who sent it, and when (the time one left-to-right piece). */
export function sentLine(item: Pick<SharedItem, "from" | "sent_at">): ReactNode {
  const from = t("From :name", { name: senderOf(item) });
  return item.sent_at ? createElement(Fragment, null, from, " · ", when(item.sent_at)) : from;
}

/** The sender's note, in quotes: “Please check the totals” (in Urdu its own isolate, whatever its script). */
export const quoted = (note: string): string => t("“:note”", { note });

/** U+200F RIGHT-TO-LEFT MARK: invisible, a right-to-left letter for the line's direction. */
const RLM = "\u200F";
/**
 * A line of words or values in the window's direction, in a place that finds its own (dir="auto": a row's line, the
 * page's meta lines): in Urdu it starts with a right-to-left mark, so a line that begins with a value in Latin letters
 * ("Imran", "Acme Ltd", "Today", "WhatsApp") still reads right to left, its first piece at the right. English: the
 * line as it is.
 */
export function windowLine(line: ReactNode): ReactNode {
  return isRtl() && line !== null && line !== undefined && line !== false && line !== "" ? createElement(Fragment, null, RLM, line) : line;
}

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
