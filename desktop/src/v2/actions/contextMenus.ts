// UX-22 (PLAN_V2_1_POLISH 3b): what each thing's right-click menu (and its ⋯, the same menu) holds, as actions.json
// ids in 3b's order and groups ("-" starts a group). Only what fits is shown: what doesn't apply to what was clicked,
// or that nothing here can do, is left out (not greyed), and a sub-menu with no choices goes too.
import type { MenuItem } from "../components/Menu";
import { canRun, findAction, menuItems, type ActionContext } from "./registry";

/**
 * Whether a key press is an action's shortcut as actions.json writes it (pure): a Mac's "⇧⌘L" or Windows's
 * "Ctrl+Shift+L", "F2", "Delete". Every modifier must match, so ⌘T is not ⇧⌘T.
 */
export function keyMatches(e: Pick<KeyboardEvent, "key" | "ctrlKey" | "altKey" | "shiftKey" | "metaKey">, shortcut: string | null | undefined): boolean {
  if (!shortcut) return false;
  const mods = { ctrl: false, alt: false, shift: false, meta: false };
  let key: string;
  if (/^[⌃⌥⇧⌘]/.test(shortcut)) {
    const chars = [...shortcut];
    const names: Record<string, keyof typeof mods> = { "⌃": "ctrl", "⌥": "alt", "⇧": "shift", "⌘": "meta" };
    let at = 0;
    while (names[chars[at]]) mods[names[chars[at++]]] = true;
    key = chars.slice(at).join("");
  } else {
    const parts = shortcut.split("+");
    key = parts.pop() ?? "";
    const names: Record<string, keyof typeof mods> = { Ctrl: "ctrl", Alt: "alt", Shift: "shift" };
    for (const part of parts) if (names[part]) mods[names[part]] = true;
  }
  // The return key is written as its code (the design rules' emoji check reads the symbol as one).
  const keyNames: Record<string, string> = { "⌫": "Backspace", "\u21A9": "Enter", Del: "Delete" };
  return e.ctrlKey === mods.ctrl && e.altKey === mods.alt && e.shiftKey === mods.shift && e.metaKey === mods.meta
    && e.key.toLowerCase() === (keyNames[key] ?? key).toLowerCase();
}

/** One recording: Open · Transcribe (Audio only) or Ask about this | Pin · Important · Unread · Labels › · Move › |
 * Rename… · Share › · Export › | Move to trash. */
export const RECORDING_ONE = [
  "recording.open", "recording.transcribe", "recording.ask",
  "-", "recording.pin", "recording.important", "recording.unread", "recording.labels", "recording.moveToProject",
  "-", "file.rename", "file.share", "recording.exportAs",
  "-", "recording.moveToTrash",
] as const;
/** Several (titled "3 recordings"): Transcribe | Pin · Important · Unread · Labels › · Move › | Export › | Move to trash. */
export const RECORDING_MANY = [
  "recording.transcribe",
  "-", "recording.pin", "recording.important", "recording.unread", "recording.labels", "recording.moveToProject",
  "-", "recording.exportAs",
  "-", "recording.moveToTrash",
] as const;
/** The list's empty space: New recording · New voice note · Import audio… | Sort by › · Group by › · As list · As table ·
 * Density › | Select all. */
export const LIST_SPACE = [
  "file.newRecording", "file.newVoiceNote", "file.importAudio",
  "-", "view.sortBy", "view.groupBy", "view.asList", "view.asTable", "view.density",
  "-", "edit.selectAll",
] as const;
/** One of My views: Open | Rename… · Edit filters… · Duplicate · Move up · Move down | Delete view…. */
export const MY_VIEW = ["place.open", "-", "file.rename", "view.editFilters", "view.duplicate", "view.moveUp", "view.moveDown", "-", "view.delete"] as const;
/** A label: Show recordings | Rename… · Colour › · Move up · Move down | Delete label…. */
export const LABEL = ["label.showRecordings", "-", "file.rename", "label.colour", "view.moveUp", "view.moveDown", "-", "label.delete"] as const;
/** A built-in view (All recordings, Needs you, Unread…): Open; Unread's Mark all as read, Audio only's Transcribe all…. */
export const LIBRARY_VIEW = ["place.open", "view.markAllRead", "view.transcribeAll"] as const;
/** A recording in Trash: Restore | Delete immediately…; Trash itself: Empty trash…. */
export const TRASH_ITEM = ["trash.restore", "-", "recording.deleteNow"] as const;
export const TRASH = ["trash.empty"] as const;

/**
 * A right-click menu from ids (one code path: each item sends its id, the registry runs it). `children` are the
 * sub-menus' items (choiceItems, or actions); a hairline starts each group that has something in it.
 */
export function contextItems(ids: readonly string[], ctx: ActionContext, children: Readonly<Record<string, readonly MenuItem[] | undefined>> = {},
  /** The ids whose keys this screen answers: only theirs are shown (a shortcut that does nothing isn't). */
  keyed: ReadonlySet<string> = new Set()): MenuItem[] {
  const out: MenuItem[] = [];
  let group: string[] = [];
  const flush = () => {
    const shown = menuItems(group, { ...ctx, context: true }).flatMap((item): MenuItem[] => {
      if (findAction(item.action)?.submenu) {
        const kids = children[item.action];
        return kids?.length ? [{ ...item, items: [...kids] }] : [];
      }
      return canRun(item.action, ctx.kind) ? [item] : [];
    }).map((item) => (item.shortcut && !keyed.has(item.action ?? "") ? { ...item, shortcut: undefined } : item));
    shown.forEach((item, at) => out.push(at === 0 && out.length > 0 ? { ...item, separator: true } : { ...item, separator: undefined }));
    group = [];
  };
  for (const id of ids) {
    if (id === "-") flush();
    else group.push(id);
  }
  flush();
  return out;
}

/** "3 recordings": a several-things menu's title (3b: a title only when several are chosen). */
export const manyTitle = (n: number, things = "recordings") => (n > 1 ? `${n} ${things}` : undefined);
