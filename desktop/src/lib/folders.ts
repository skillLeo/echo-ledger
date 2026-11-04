// Smart folders of the Library (docs/API.md "Projects", since v1.5): the Inbox, the call apps, voice notes, imported
// files and what teammates sent. Each is a view the server computes (`GET /api/library?folder=`), never a copy. Pure;
// unit-tested.
import type { LibraryItem, SmartFolder } from "./ipc";

export const SMART_FOLDERS: readonly (readonly [SmartFolder, string])[] = [
  ["inbox", "Inbox"],
  ["whatsapp", "WhatsApp"],
  ["whatsapp_business", "WhatsApp Business"],
  ["google_meet", "Google Meet"],
  ["zoom", "Zoom"],
  ["teams", "Teams"],
  ["phone", "Phone"],
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
 * The folders the sidebar lists with their counts: the Inbox always (recordings can be dropped on it), the others while
 * they hold something or are open. Nothing at all when the server sent no folders (a server older than v1.5).
 */
export function shownFolders(counts: Partial<Record<SmartFolder, number>> | null | undefined, open: SmartFolder | null): { folder: SmartFolder; label: string; count: number }[] {
  if (!counts) return [];
  return SMART_FOLDERS.filter(([folder]) => folder === "inbox" || folder === open || (counts[folder] ?? 0) > 0)
    .filter(([folder]) => folder !== "shared" || counts.shared !== undefined)
    .map(([folder, label]) => ({ folder, label, count: Math.max(0, counts[folder] ?? 0) }));
}
