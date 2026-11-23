// The v2 Library's Sort & display (PLAN_LIBRARY_FILTERS: Sort, Group by; classic's View menu): the list sorted, then in
// its sections. The filters themselves (Date, Source, Device…, with "is" and "is not" and their counts) are facets.ts.
// Pure, so the rules are tested without a window.
import type { LibraryItem } from "../../lib/ipc";
import { sortItems, type SortKey } from "../../lib/library";
import { groupBy, type GroupKey, type ItemGroup } from "../../lib/libraryGroups";

export const GROUP_NAMES: Record<GroupKey, string> = {
  date: "Date", app: "Source", project: "Project", language: "Language", status: "Status", device: "Device", none: "None",
};
/** Group by's choices in the menu's order: the plan's four first, then the rest classic had. */
export const GROUP_ORDER: readonly GroupKey[] = ["date", "app", "project", "none", "language", "status", "device"];
export const SORT_NAMES: Record<SortKey, string> = {
  newest: "Newest", oldest: "Oldest", longest: "Longest", shortest: "Shortest", title: "Name A–Z", opened: "Recently opened",
};
/** Sort's choices in the menu's order: the plan's four first, then the rest classic had. */
export const SORT_ORDER: readonly SortKey[] = ["newest", "oldest", "longest", "title", "shortest", "opened"];

/**
 * The list in its sections: sorted, then grouped. Pinned recordings come first in their own "Pinned" section at the top
 * of every list, whatever the grouping (the lead decision: Pinned is no longer a folder of its own), unless
 * `pinnedFirst` is off (the list shows only pinned recordings already).
 */
export function sections(items: readonly LibraryItem[], group: GroupKey, sort: SortKey, opened: Record<string, number>, pinnedFirst: boolean, now: Date = new Date()): ItemGroup[] {
  const sorted = sortItems([...items], sort, opened);
  if (!pinnedFirst) return groupBy(sorted, group, sort, now);
  const pinned = sorted.filter((i) => i.pinned);
  if (!pinned.length || pinned.length === sorted.length) return groupBy(sorted, group, sort, now);
  const rest = groupBy(sorted.filter((i) => !i.pinned), group, sort, now);
  // Ungrouped, the rest needs a heading of its own under Pinned.
  return [{ key: "pinned", label: "Pinned", items: pinned }, ...rest.map((g) => (g.key === "none" ? { ...g, label: "Recordings" } : g))];
}
