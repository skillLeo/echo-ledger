// What the Library shows, as the sidebar chooses it (PLAN_LIBRARY_FILTERS): a library view, one of My views, a label,
// or Trash. The filters, the search and the sort narrow it further in the Library itself.
import type { SmartFolder } from "../../lib/ipc";
import { t } from "../../i18n";
import { LIBRARY_VIEW_NAMES, type FilterSpec, type LibraryViewId } from "./facets";
import type { SavedView } from "./model";

export type LibraryScope =
  | { kind: "view"; view: LibraryViewId }
  | { kind: "saved"; id: string }
  | { kind: "label"; key: string }
  | { kind: "trash" };

export const ALL_SCOPE: LibraryScope = { kind: "view", view: "all" };

/** One string per scope, for the sidebar's current item and comparisons. */
export function scopeKey(scope: LibraryScope): string {
  switch (scope.kind) {
    case "view": return `view:${scope.view}`;
    case "saved": return `saved:${scope.id}`;
    case "label": return `label:${scope.key}`;
    case "trash": return "trash";
  }
}

export const sameScope = (a: LibraryScope, b: LibraryScope) => scopeKey(a) === scopeKey(b);

/** A library view's name, a view's or a label's name (given), or Trash. */
export function scopeName(scope: LibraryScope, names: { saved?: (id: string) => string | undefined; label?: (key: string) => string | undefined } = {}): string {
  switch (scope.kind) {
    // A library view's name is facets.ts's English, said in the window's language here.
    case "view": return LIBRARY_VIEW_NAMES[scope.view];
    // A saved view not read yet: "View" in English, ویو in Urdu (the plain "View" is the layout, انداز).
    case "saved": return names.saved?.(scope.id) ?? t("library_context.saved_view");
    case "label": return names.label?.(scope.key) ?? t("Label");
    case "trash": return t("Trash");
  }
}

/**
 * Where a request from before the redesign goes (Home's "isn't transcribed", a notice, the palette, Activity): today's
 * saved views and smart folders, as their new homes say (PLAN_LIBRARY_FILTERS §6 and its lead decisions).
 */
export function fromOldView(view: SavedView): { scope: LibraryScope; filters: FilterSpec } {
  switch (view) {
    case "trash": return { scope: { kind: "trash" }, filters: {} };
    case "untranscribed": return { scope: { kind: "view", view: "audio_only" }, filters: {} };
    case "waiting":
    case "failed": return { scope: { kind: "view", view: "needs" }, filters: {} };
    case "today": return { scope: ALL_SCOPE, filters: { date: { op: "is", values: ["today"] } } };
    case "this_week": return { scope: ALL_SCOPE, filters: { date: { op: "is", values: ["last_7_days"] } } };
    case "pinned": return { scope: ALL_SCOPE, filters: { marked: { op: "is", values: ["pinned"] } } };
    case "bookmarked": return { scope: ALL_SCOPE, filters: { bookmarks: { op: "is", values: ["any"] } } };
    default: return { scope: ALL_SCOPE, filters: {} };
  }
}

/** Today's smart folders, as Source and Project choices: the Inbox is "No project"; Shared with me is Shared's own. */
export function fromFolder(folder: SmartFolder): FilterSpec | null {
  switch (folder) {
    case "inbox": return { project: { op: "is", values: ["none"] } };
    case "whatsapp": return { source: { op: "is", values: ["whatsapp"] } };
    case "whatsapp_business": return { source: { op: "is", values: ["whatsapp_business"] } };
    case "google_meet": return { source: { op: "is", values: ["google_meet"] } };
    case "zoom": return { source: { op: "is", values: ["zoom"] } };
    case "teams": return { source: { op: "is", values: ["microsoft_teams"] } };
    case "phone": return { source: { op: "is", values: ["phone_call"] } };
    case "voice_notes": return { source: { op: "is", values: ["voice_note"] } };
    case "imported": return { source: { op: "is", values: ["imported"] } };
    case "shared": return null;
  }
}
