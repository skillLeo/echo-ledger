// The v2 Library's filters and its Sort and group (PLAN_V2_PARITY 3 "Find"; classic's Filters panel, project and label
// selects, and View menu). Ticks inside one filter add up, filters narrow each other, as classic's panel did. Pure, so
// the rules are tested without a window.
import type { LibraryItem } from "../../lib/ipc";
import { itemState, sortItems, type SortKey } from "../../lib/library";
import { groupBy, type GroupKey, type ItemGroup } from "../../lib/libraryGroups";
import { sourceAppLabel } from "../../lib/format";

export type FilterKey = "source" | "device" | "project" | "language" | "status" | "label" | "tag";
export type Filters = Partial<Record<FilterKey, readonly string[]>>;

/** The chips' names, in the chip row's order. */
export const FILTER_NAMES: Record<FilterKey, string> = {
  source: "Source", device: "Recorded on", project: "Project", language: "Language", status: "Status", label: "Label", tag: "Bookmark tag",
};
export const FILTER_KEYS = Object.keys(FILTER_NAMES) as FilterKey[];

const DEVICE_NAMES: Record<string, string> = { phone: "Phone", desktop: "Desktop", web: "Web" };
const STATUS_ORDER = ["Needs you", "Transcribing", "Ready"];

/** A recording's values for one filter: its value ids and the words the menu shows for each. */
function valuesOf(item: LibraryItem, key: FilterKey): { value: string; label: string }[] {
  switch (key) {
    case "source": {
      const app = item.source_app ?? "other";
      return [{ value: app, label: app === "voice_note" ? "Voice notes" : item.source_app_label || (item.source_app ? sourceAppLabel(item.source_app) : "Other") }];
    }
    case "device": {
      const d = item.source_device ?? "";
      return d ? [{ value: d, label: DEVICE_NAMES[d] ?? d }] : [];
    }
    case "project":
      return [item.client ? { value: item.client.id, label: item.client.name } : { value: "none", label: "No project" }];
    case "language": {
      const chip = item.language_chip && item.language_chip !== "Auto" ? item.language_chip : item.transcript?.final_language?.toUpperCase() || "";
      return [chip ? { value: chip, label: chip } : { value: "auto", label: "Automatic" }];
    }
    case "status": {
      const state = itemState(item);
      const label = state === "ready" ? "Ready" : state === "processing" ? "Transcribing" : "Needs you";
      return [{ value: label, label }];
    }
    case "label":
      return (item.labels ?? []).map((l) => ({ value: l, label: l }));
    case "tag":
      return (item.tags ?? []).map((t) => ({ value: t, label: t }));
  }
}

/** What a filter offers for these recordings, with how many have each; "No project" and the like last. */
export function filterOptions(items: readonly LibraryItem[], key: FilterKey): { value: string; label: string; count: number }[] {
  const found = new Map<string, { value: string; label: string; count: number }>();
  for (const item of items) {
    for (const v of valuesOf(item, key)) {
      const had = found.get(v.value);
      if (had) had.count++;
      else found.set(v.value, { ...v, count: 1 });
    }
  }
  const last = (v: string) => Number(v === "none" || v === "auto" || v === "other");
  return [...found.values()].sort((a, b) =>
    key === "status" ? STATUS_ORDER.indexOf(a.label) - STATUS_ORDER.indexOf(b.label)
      : last(a.value) - last(b.value) || a.label.localeCompare(b.label, undefined, { sensitivity: "base" }));
}

/** The recordings that pass every filter that has a tick. */
export function applyFilters(items: readonly LibraryItem[], filters: Filters): LibraryItem[] {
  const on = FILTER_KEYS.filter((k) => (filters[k]?.length ?? 0) > 0);
  if (!on.length) return [...items];
  return items.filter((item) => on.every((k) => valuesOf(item, k).some((v) => filters[k]!.includes(v.value))));
}

/** How many ticks are on, across every filter. */
export function filterCount(filters: Filters): number {
  return FILTER_KEYS.reduce((n, k) => n + (filters[k]?.length ?? 0), 0);
}

/** Ticks a value on or off in one filter. */
export function toggleFilter(filters: Filters, key: FilterKey, value: string): Filters {
  const now = filters[key] ?? [];
  const next = now.includes(value) ? now.filter((v) => v !== value) : [...now, value];
  return { ...filters, [key]: next };
}

/** A chip's words: its name alone, the one value ticked, or the name and how many. */
export function chipLabel(items: readonly LibraryItem[], filters: Filters, key: FilterKey): string {
  const on = filters[key] ?? [];
  if (on.length === 0) return FILTER_NAMES[key];
  if (on.length === 1) return `${FILTER_NAMES[key]}: ${filterOptions(items, key).find((o) => o.value === on[0])?.label ?? on[0]}`;
  return `${FILTER_NAMES[key]} · ${on.length}`;
}

export const GROUP_NAMES: Record<GroupKey, string> = {
  date: "Date", app: "Source", project: "Project", language: "Language", status: "Status", device: "Device", none: "None",
};
export const SORT_NAMES: Record<SortKey, string> = {
  newest: "Newest", oldest: "Oldest", longest: "Longest", shortest: "Shortest", title: "Name A–Z", opened: "Recently opened",
};

/**
 * The list in its sections: sorted, then grouped. Grouped by date, pinned recordings come first in their own
 * "Pinned" section (as the plan's "Pinned · 4, Today, Yesterday…"), unless the view is Pinned already.
 */
export function sections(items: readonly LibraryItem[], group: GroupKey, sort: SortKey, opened: Record<string, number>, pinnedFirst: boolean, now: Date = new Date()): ItemGroup[] {
  const sorted = sortItems([...items], sort, opened);
  if (group !== "date" || !pinnedFirst) return groupBy(sorted, group, sort, now);
  const pinned = sorted.filter((i) => i.pinned);
  const rest = groupBy(sorted.filter((i) => !i.pinned), group, sort, now);
  return pinned.length ? [{ key: "pinned", label: "Pinned", items: pinned }, ...rest] : rest;
}
