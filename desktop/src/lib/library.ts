// Library search, sorting and date grouping (pure; unit-tested).
import type { LibraryItem } from "./ipc";

export type SortKey = "newest" | "oldest" | "longest" | "title";

export const SORT_LABELS: Record<SortKey, string> = {
  newest: "Newest first",
  oldest: "Oldest first",
  longest: "Longest first",
  title: "Title A–Z",
};

/** Case-insensitive match against the title, transcript preview, client, device, source labels and bookmark tags. */
export function searchItems(items: LibraryItem[], query: string): LibraryItem[] {
  const q = query.trim().toLowerCase();
  if (!q) return items;
  return items.filter((i) =>
    [i.title, i.transcript?.preview, i.client?.name, i.device?.name, i.source_app_label, i.source_device_label, ...(i.tags ?? [])].some((s) => s?.toLowerCase().includes(q)),
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

/** Every bookmark tag used in the library, sorted (the tag filter's chips). */
export function libraryTags(items: LibraryItem[]): string[] {
  const tags = new Set<string>();
  for (const item of items) if (!item.deleted) for (const tag of item.tags ?? []) tags.add(tag);
  return [...tags].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: "base" }));
}

/** The tag filter: `null` = everything, `"*"` = recordings with any bookmark, else recordings with that tag. */
export function filterByTag(items: LibraryItem[], tag: string | null): LibraryItem[] {
  if (tag === null) return items;
  if (tag === "*") return items.filter((i) => (i.marker_count ?? 0) > 0);
  return items.filter((i) => i.tags?.includes(tag));
}

export interface Bookmark {
  id: string;
  at_ms: number;
  tag: string | null;
  note: string | null;
}

/** The bookmarks of a session detail answer (`markers`), in time order; anything malformed is skipped. */
export function bookmarksOf(detail: Record<string, unknown>): Bookmark[] {
  const raw = Array.isArray(detail.markers) ? detail.markers : [];
  return raw
    .filter((m): m is Record<string, unknown> => typeof m === "object" && m !== null && typeof (m as Record<string, unknown>).at_ms === "number")
    .map((m) => ({
      id: typeof m.id === "string" ? m.id : String(m.at_ms),
      at_ms: m.at_ms as number,
      tag: typeof m.tag === "string" ? m.tag : null,
      note: typeof m.note === "string" ? m.note : null,
    }))
    .sort((a, b) => a.at_ms - b.at_ms);
}
