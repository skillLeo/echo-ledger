// Library search, sorting and date grouping (pure; unit-tested).
import type { LibraryItem } from "./ipc";

export type SortKey = "newest" | "oldest" | "longest" | "title";

export const SORT_LABELS: Record<SortKey, string> = {
  newest: "Newest first",
  oldest: "Oldest first",
  longest: "Longest first",
  title: "Title A–Z",
};

/** Case-insensitive match against the title, transcript preview, client, device and source labels. */
export function searchItems(items: LibraryItem[], query: string): LibraryItem[] {
  const q = query.trim().toLowerCase();
  if (!q) return items;
  return items.filter((i) =>
    [i.title, i.transcript?.preview, i.client?.name, i.device?.name, i.source_app_label, i.source_device_label].some((s) => s?.toLowerCase().includes(q)),
  );
}

export function sortItems(items: LibraryItem[], key: SortKey): LibraryItem[] {
  const time = (i: LibraryItem) => (i.started_at ? Date.parse(i.started_at) || 0 : 0);
  const copy = [...items];
  switch (key) {
    case "oldest":
      return copy.sort((a, b) => time(a) - time(b));
    case "longest":
      return copy.sort((a, b) => (b.duration_ms ?? 0) - (a.duration_ms ?? 0));
    case "title":
      return copy.sort((a, b) => (a.title ?? "").localeCompare(b.title ?? "", undefined, { sensitivity: "base" }));
    default:
      return copy.sort((a, b) => time(b) - time(a));
  }
}

export type DayGroup = "Today" | "Yesterday" | "This week" | "Earlier";

export function dayGroup(iso: string | undefined, now: Date = new Date()): DayGroup {
  const t = iso ? Date.parse(iso) : NaN;
  if (Number.isNaN(t)) return "Earlier";
  const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
  const days = Math.round((startOfDay(now) - startOfDay(new Date(t))) / 86_400_000);
  if (days <= 0) return "Today";
  if (days === 1) return "Yesterday";
  if (days < 7) return "This week";
  return "Earlier";
}

/** Groups newest/oldest-sorted items under day headings; other sort orders stay one flat, unlabelled group. */
export function groupItems(items: LibraryItem[], key: SortKey, now: Date = new Date()): { label: string | null; items: LibraryItem[] }[] {
  if (key !== "newest" && key !== "oldest") return items.length ? [{ label: null, items }] : [];
  const groups: { label: string | null; items: LibraryItem[] }[] = [];
  for (const item of items) {
    const label = dayGroup(item.started_at, now);
    const last = groups[groups.length - 1];
    if (last && last.label === label) last.items.push(item);
    else groups.push({ label, items: [item] });
  }
  return groups;
}

export type ItemState = "ready" | "processing" | "failed";

export function itemState(item: Pick<LibraryItem, "status" | "transcript">): ItemState {
  if (item.status === "failed" || item.transcript?.final_status === "failed") return "failed";
  if (item.status === "completed" || item.transcript?.final_status === "completed") return "ready";
  return "processing";
}
