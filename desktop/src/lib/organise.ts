// Organising the library (plan B15): smart views, labels, pins and client folders. Pure; unit-tested.
import type { ClientFolder, LibraryItem } from "./ipc";

export type SmartView = "all" | "today" | "this_week" | "waiting" | "bookmarked" | "failed" | "pinned";

export const SMART_VIEWS: readonly (readonly [SmartView, string])[] = [
  ["all", "All recordings"],
  ["today", "Today"],
  ["this_week", "This week"],
  ["waiting", "Waiting for decision"],
  ["bookmarked", "Bookmarked"],
  ["failed", "Failed"],
  ["pinned", "Pinned"],
];

export const MAX_LABELS = 20;
export const MAX_LABEL_CHARS = 40;

const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate());

/** Monday 00:00 of the week [now] is in, local time. */
export function startOfWeek(now: Date): Date {
  const day = startOfDay(now);
  day.setDate(day.getDate() - ((day.getDay() + 6) % 7));
  return day;
}

/** Same rules as the server: calendar day and Monday-based week in local time, recordings still waiting, and so on. */
export function inSmartView(item: LibraryItem, view: SmartView, now: Date = new Date()): boolean {
  if (item.deleted) return false;
  const started = item.started_at ? Date.parse(item.started_at) : NaN;
  switch (view) {
    case "all":
      return true;
    case "today":
      return started >= startOfDay(now).getTime() && started < startOfDay(now).getTime() + 86_400_000;
    case "this_week": {
      const from = startOfWeek(now);
      const to = new Date(from);
      to.setDate(to.getDate() + 7);
      return started >= from.getTime() && started < to.getTime();
    }
    case "waiting":
      return item.status === "recording";
    case "bookmarked":
      return (item.marker_count ?? 0) > 0;
    case "failed":
      return item.status === "failed" || item.transcript?.final_status === "failed";
    case "pinned":
      return !!item.pinned;
  }
}

export function smartViewCounts(items: LibraryItem[], now: Date = new Date()): Record<SmartView, number> {
  const counts = Object.fromEntries(SMART_VIEWS.map(([v]) => [v, 0])) as Record<SmartView, number>;
  for (const item of items) for (const [view] of SMART_VIEWS) if (inSmartView(item, view, now)) counts[view]++;
  return counts;
}

/** Every recording label in the library, sorted (the label filter). */
export function libraryLabels(items: LibraryItem[]): string[] {
  const seen = new Map<string, string>();
  for (const item of items) if (!item.deleted) for (const label of item.labels ?? []) if (!seen.has(label.toLowerCase())) seen.set(label.toLowerCase(), label);
  return [...seen.values()].sort((a, b) => a.localeCompare(b, undefined, { sensitivity: "base" }));
}

export function filterByLabel(items: LibraryItem[], label: string | null): LibraryItem[] {
  if (label === null) return items;
  const wanted = label.toLowerCase();
  return items.filter((i) => (i.labels ?? []).some((l) => l.toLowerCase() === wanted));
}

/** The folder filter: `null` = every recording, `""` = recordings without a client, else that client's. */
export function filterByClient(items: LibraryItem[], clientId: string | null): LibraryItem[] {
  if (clientId === null) return items;
  return items.filter((i) => (clientId === "" ? !i.client : i.client?.id === clientId));
}

/** A label as the server stores it: single spaces, no control characters, at most 40 characters. */
export function cleanLabel(text: string): string | null {
  // eslint-disable-next-line no-control-regex
  const clean = text.replace(/[\u0000-\u001f\u007f]/g, "").replace(/\s+/g, " ").trim();
  return clean === "" || [...clean].length > MAX_LABEL_CHARS ? null : clean;
}

/** The labels with [label] added; null when it is blank, already there (ignoring capitals) or the list is full. */
export function withLabel(labels: string[], label: string): string[] | null {
  const clean = cleanLabel(label);
  if (clean === null || labels.length >= MAX_LABELS || labels.some((l) => l.toLowerCase() === clean.toLowerCase())) return null;
  return [...labels, clean];
}

/** What the folder shows: its avatar, else the initials of its name. */
export function folderBadge(client: Pick<ClientFolder, "name" | "avatar">): string {
  if (client.avatar) return client.avatar;
  const words = client.name.trim().split(/\s+/).filter(Boolean);
  return (words.length > 1 ? words[0][0] + words[1][0] : (words[0] ?? "?").slice(0, 2)).toUpperCase();
}

/** The client folders the library's recordings are in, merged with [known] folders (known wins). */
export function foldersOf(items: LibraryItem[], known: ClientFolder[]): ClientFolder[] {
  const byId = new Map<string, ClientFolder>();
  for (const item of items) if (!item.deleted && item.client) byId.set(item.client.id, { id: item.client.id, name: item.client.name, color: item.client.color ?? null, avatar: item.client.avatar ?? null });
  for (const client of known) byId.set(client.id, client);
  return sortFolders([...byId.values()]);
}

/** Pinned folders first, then by name. */
export function sortFolders(clients: ClientFolder[]): ClientFolder[] {
  return [...clients].sort((a, b) => Number(!!b.pinned) - Number(!!a.pinned) || a.name.localeCompare(b.name, undefined, { sensitivity: "base" }));
}
