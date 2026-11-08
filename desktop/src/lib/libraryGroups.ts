// Group by and Sort by in the Library (Phase 1C, D): toolbar menus as in Explorer and Finder, remembered per view (the
// whole Library and each smart folder), group headers with their name and count, and when each recording was last
// opened here for Recently opened. Pure parts are tested; storage is a per-computer convenience and may be missing.
import type { LibraryItem } from "./ipc";
import { dayGroup, itemState, type SortKey } from "./library";

export type GroupKey = "date" | "app" | "project" | "language" | "status" | "device" | "none";

export const GROUP_LABELS: Record<GroupKey, string> = {
  date: "Date",
  app: "App / Type",
  project: "Project",
  language: "Language",
  status: "Status",
  device: "Device",
  none: "None",
};

export interface ItemGroup {
  /** Stable id of the group (its collapse and selection follow it). */
  key: string;
  label: string;
  items: LibraryItem[];
}

const DATE_ORDER = ["Today", "Yesterday", "This week", "Earlier"];
const STATUS_ORDER = ["Needs you", "Transcribing", "Ready"];

function groupOf(item: LibraryItem, by: GroupKey, now: Date): string {
  switch (by) {
    case "date":
      return dayGroup(item.started_at, now);
    case "app":
      return item.source_app === "voice_note" ? "Voice notes" : item.source_app_label || "Other";
    case "project":
      return item.client?.name ?? "No project";
    case "language":
      return item.language_chip && item.language_chip !== "Auto" ? item.language_chip : item.transcript?.final_language?.toUpperCase() || "Automatic";
    case "status": {
      const state = itemState(item);
      return state === "ready" ? "Ready" : state === "processing" ? "Transcribing" : "Needs you";
    }
    case "device":
      return item.device?.name ?? item.source_device_label ?? "Unknown device";
    default:
      return "";
  }
}

/** Groups first that the order puts first; within a group the items keep the sort order. */
function groupRank(by: GroupKey, label: string, sort: SortKey): number {
  if (by === "date") {
    const at = DATE_ORDER.indexOf(label);
    return sort === "oldest" ? -at : at;
  }
  if (by === "status") return STATUS_ORDER.indexOf(label);
  return 0;
}

const LAST = new Set(["No project", "Other", "Unknown device", "Automatic"]);

/** The Library in groups (pure). `None` is one group without a header. */
export function groupBy(sorted: LibraryItem[], by: GroupKey, sort: SortKey, now: Date = new Date()): ItemGroup[] {
  if (sorted.length === 0) return [];
  if (by === "none") return [{ key: "none", label: "", items: sorted }];
  const groups = new Map<string, LibraryItem[]>();
  for (const item of sorted) {
    const label = groupOf(item, by, now);
    const list = groups.get(label);
    if (list) list.push(item);
    else groups.set(label, [item]);
  }
  const named = [...groups.entries()].map(([label, items]) => ({ key: `${by}:${label}`, label, items }));
  if (by === "date" || by === "status") return named.sort((a, b) => groupRank(by, a.label, sort) - groupRank(by, b.label, sort));
  return named.sort((a, b) => Number(LAST.has(a.label)) - Number(LAST.has(b.label)) || a.label.localeCompare(b.label, undefined, { sensitivity: "base" }));
}

/** A header's box (D3): all, some or none of its recordings picked. */
export function groupPick(items: readonly LibraryItem[], picked: ReadonlySet<string>): "all" | "some" | "none" {
  const n = items.filter((i) => picked.has(i.id)).length;
  return n === 0 ? "none" : n === items.length ? "all" : "some";
}

/** Ticking a header: picks the whole group, or (when all of it is picked) puts it back. */
export function toggleGroup(items: readonly LibraryItem[], picked: ReadonlySet<string>): Set<string> {
  const next = new Set(picked);
  if (groupPick(items, picked) === "all") items.forEach((i) => next.delete(i.id));
  else items.forEach((i) => next.add(i.id));
  return next;
}

export interface LibraryViewChoice {
  group: GroupKey;
  sort: SortKey;
}

export const DEFAULT_CHOICE: LibraryViewChoice = { group: "date", sort: "newest" };
const CHOICE_KEY = "echoledger-library-view";
const OPENED_KEY = "echoledger-library-opened";
const OPENED_LIMIT = 300;

const GROUPS = Object.keys(GROUP_LABELS) as GroupKey[];
const SORTS: SortKey[] = ["newest", "oldest", "longest", "shortest", "title", "opened"];

function read<T>(key: string): T | null {
  try {
    const raw = window.localStorage.getItem(key);
    return raw ? (JSON.parse(raw) as T) : null;
  } catch {
    return null;
  }
}

function write(key: string, value: unknown): void {
  try {
    window.localStorage.setItem(key, JSON.stringify(value));
  } catch {
    // Storage can be unavailable: the choice then lasts until the window closes.
  }
}

/** The Group by and Sort by of one view (`all` or a smart folder), Date and Newest when none was chosen. */
export function loadChoice(view: string): LibraryViewChoice {
  const saved = read<Record<string, Partial<LibraryViewChoice>>>(CHOICE_KEY)?.[view];
  return {
    group: GROUPS.includes(saved?.group as GroupKey) ? (saved!.group as GroupKey) : DEFAULT_CHOICE.group,
    sort: SORTS.includes(saved?.sort as SortKey) ? (saved!.sort as SortKey) : DEFAULT_CHOICE.sort,
  };
}

export function saveChoice(view: string, choice: LibraryViewChoice): void {
  const all = read<Record<string, LibraryViewChoice>>(CHOICE_KEY) ?? {};
  write(CHOICE_KEY, { ...all, [view]: choice });
}

/** When each recording was last opened here (ms). */
export function loadOpened(): Record<string, number> {
  const saved = read<Record<string, number>>(OPENED_KEY);
  return saved && typeof saved === "object" ? saved : {};
}

/** Remembers that `id` was opened now; keeps the newest few hundred. */
export function rememberOpened(id: string, at: number = Date.now()): Record<string, number> {
  const entries = Object.entries({ ...loadOpened(), [id]: at }).sort((a, b) => b[1] - a[1]).slice(0, OPENED_LIMIT);
  const next = Object.fromEntries(entries);
  write(OPENED_KEY, next);
  return next;
}
