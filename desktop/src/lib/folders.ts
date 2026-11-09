// Smart folders of the Library (docs/API.md "Projects", since v1.5): the Inbox, the call apps, voice notes, imported
// files and what teammates sent. Each is a view the server computes (`GET /api/library?folder=`), never a copy. Pure;
// unit-tested.
import type { LibraryItem, SmartFolder } from "./ipc";

export const SMART_FOLDERS: readonly (readonly [SmartFolder, string])[] = [
  ["inbox", "Inbox"],
  ["whatsapp", "WhatsApp"],
  ["whatsapp_business", "WhatsApp Business"],
  ["google_meet", "Meet"],
  ["zoom", "Zoom"],
  ["teams", "Teams"],
  ["phone", "SIM"],
  ["voice_notes", "Voice notes"],
  ["imported", "Imported"],
  ["shared", "Shared with me"],
];

export function folderLabel(folder: SmartFolder): string {
  return SMART_FOLDERS.find(([id]) => id === folder)?.[1] ?? folder;
}

/** The source apps an app folder stands for (WhatsApp Web counts as WhatsApp). */
const APPS: Partial<Record<SmartFolder, readonly string[]>> = {
  whatsapp: ["whatsapp", "whatsapp_web"],
  whatsapp_business: ["whatsapp_business"],
  google_meet: ["google_meet"],
  zoom: ["zoom"],
  teams: ["microsoft_teams"],
  phone: ["phone_call"],
  voice_notes: ["voice_note"],
};

/** The source app an app folder files new rules by (Filing rules), when it has one. */
export function folderApp(folder: SmartFolder): string | null {
  return APPS[folder]?.[0] ?? null;
}

/**
 * Whether a saved recording is in a folder as far as this computer can tell: what the Library shows before the server
 * answers, or while it cannot be reached. Null when only the server knows (Imported, Shared with me).
 */
export function inFolderHere(item: LibraryItem, folder: SmartFolder): boolean | null {
  if (item.deleted) return false;
  if (folder === "inbox") return !item.client;
  const apps = APPS[folder];
  return apps ? apps.includes(item.source_app ?? "") : null;
}

/**
 * The folders the sidebar lists with their counts: the Inbox always (recordings can be dropped on it), Shared with me
 * whenever the server has it (it counts recordings, so a project sent with none counts 0), the others while they hold
 * something or are open. Nothing at all when the server sent no folders (a server older than v1.5).
 */
export function shownFolders(counts: Partial<Record<SmartFolder, number>> | null | undefined, open: SmartFolder | null): { folder: SmartFolder; label: string; count: number }[] {
  if (!counts) return [];
  const listed = (folder: SmartFolder) => (folder === "shared" ? counts.shared !== undefined : folder === "inbox" || folder === open || (counts[folder] ?? 0) > 0);
  return SMART_FOLDERS.filter(([folder]) => listed(folder)).map(([folder, label]) => ({ folder, label, count: Math.max(0, counts[folder] ?? 0) }));
}

/**
 * The Library's views that are not server folders (v1.6, P4.2): calls waiting for Keep or Discard, pinned recordings
 * and failed uploads (with failed transcriptions), each counted with what this computer holds too.
 */
export type LibraryView = "waiting" | "pinned" | "failed";
export const LIBRARY_VIEWS: readonly (readonly [LibraryView, string])[] = [["waiting", "Waiting"], ["pinned", "Pinned"], ["failed", "Failed uploads"]];

/** One place of the Library's column: every recording, a view, or a smart folder. */
export interface LibraryPlace {
  key: string;
  label: string;
  folder: SmartFolder | null;
  view: LibraryView | null;
  count: number;
  /** Recordings dropped on it move there (the Inbox: out of their project). */
  drop: boolean;
}

/**
 * The places of the Library's nested column, in the plan's order: All · Waiting · Pinned · Failed uploads, then the
 * smart folders as `shownFolders` lists them. `local` adds what only this computer knows (calls waiting for a decision
 * here, uploads that stopped here) to the server's view counts.
 */
export function libraryPlaces(views: { views?: Record<string, number> | null; folders?: Partial<Record<SmartFolder, number>> | null } | null | undefined,
  local: { total: number; waiting: number; failed: number; pinned: number }, open: SmartFolder | null): LibraryPlace[] {
  const server = views?.views ?? {};
  const count = (n: unknown) => (typeof n === "number" && Number.isFinite(n) ? Math.max(0, Math.floor(n)) : 0);
  const viewCounts: Record<LibraryView, number> = {
    waiting: count(server.waiting) + count(local.waiting),
    pinned: Math.max(count(server.pinned), count(local.pinned)),
    failed: count(server.failed) + count(local.failed),
  };
  return [
    { key: "all", label: "All", folder: null, view: null, count: count(local.total), drop: false },
    ...LIBRARY_VIEWS.map(([view, label]) => ({ key: view, label, folder: null, view, count: viewCounts[view], drop: false })),
    ...shownFolders(views?.folders, open).map(({ folder, label, count: n }) => ({ key: folder, label, folder, view: null, count: n, drop: folder === "inbox" })),
  ];
}
