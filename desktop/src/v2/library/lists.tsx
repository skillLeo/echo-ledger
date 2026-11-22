// Lists in the v2 Library (D14, contract 2.0.0-m): the chip row All · Unread · Important · the person's lists · "+",
// the new-list dialog, a list chip's menu (Rename…, Change colour…, Move left / Move right, Delete list…) and the Add to
// list picker for a recording's ⋮ and right-click. A list is one of the person's labels, kept by the server, so the
// same lists in the same order show on every device; choosing a chip shows only its recordings.
import { useCallback, useEffect, useRef, useState } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type LibraryItem, type ListItem } from "../../lib/ipc";
import {
  Button, ColourPicker, ContextMenu, Dialog, Icons, LABEL_COLOURS, ListChip, ListChipRow, NEW_LIST_WORDS, NewListDialog, RenameListDialog, addToListItems,
  deleteListWords, labelColourName, listChipMenuItems, type LabelColour,
} from "../components";
import type { MenuItem } from "../components/Menu";

/** Where the Library is: everything, a fixed chip, or one of the lists (by its key). */
export type Place = "all" | "unread" | "important" | `list:${string}`;

export const FIXED_CHIPS = { all: "All", unread: "Unread", important: "Important" } as const;

export const listPlace = (key: string): Place => `list:${key}`;
const keyOf = (place: Place) => (place.startsWith("list:") ? place.slice(5) : null);

/** A list's key for a label: the name in lower case, as the server keys it. */
export const listKey = (name: string) => name.toLowerCase();

/**
 * The recording is unread: the server says it hasn't been opened, and it hasn't been opened here since (`read`, the
 * recordings opened on this computer before the server's word comes back).
 */
const unread = (item: LibraryItem, read?: ReadonlySet<string>) => item.opened === false && !read?.has(item.id);

/** The recording is in the place: unread (not opened yet), marked important, or carrying the list's label. */
export function inPlace(item: LibraryItem, place: Place, read?: ReadonlySet<string>): boolean {
  if (place === "all") return true;
  if (place === "unread") return unread(item, read);
  if (place === "important") return !!item.important;
  const key = keyOf(place);
  return !!key && (item.labels ?? []).some((l) => listKey(l) === key);
}

/** How many recordings are unread: the Unread chip's count. */
export const unreadCount = (items: readonly LibraryItem[], read?: ReadonlySet<string>) => items.filter((i) => !i.deleted && unread(i, read)).length;

/** The place is a list the person no longer has (deleted or renamed on another device, or refused as not found). */
export const placeGone = (place: Place, lists: readonly ListItem[] | null) => {
  const key = keyOf(place);
  return !!key && !!lists && !lists.some((l) => l.key === key);
};

/** The place's name: a fixed chip's word or the list's name. */
export function placeName(place: Place, lists: readonly ListItem[]): string {
  if (place === "all" || place === "unread" || place === "important") return FIXED_CHIPS[place];
  return lists.find((l) => l.key === keyOf(place))?.name ?? "";
}

/** The page reads right to left (Urdu): Move left and Move right swap. */
const rtl = (el: Element | null) => (el?.closest("[dir]")?.getAttribute("dir") ?? document.documentElement.dir) === "rtl";

/** The person's lists, read from the server and again whenever the library changes. `null` on a server without lists. */
export function useLists() {
  const [lists, setLists] = useState<ListItem[] | null>(null);
  const reload = useCallback(async () => {
    try {
      setLists((await api.lists()) ?? null);
    } catch {
      // Lists are an extra: the Library works without them (the fixed chips stay).
      setLists((now) => now);
    }
  }, []);
  useEffect(() => {
    void reload();
    const un = listen("library:updated", () => void reload());
    return () => {
      void un.then((f) => f());
    };
  }, [reload]);
  return { lists, setLists, reload };
}

/**
 * Add to list (D14 §3) for the chosen recordings, as a sub-menu: each list ticked when every chosen recording is in it,
 * New list… at the end.
 */
export function addToListMenu({ lists, chosen, onToggle, onNew }: {
  lists: readonly ListItem[];
  chosen: readonly LibraryItem[];
  onToggle: (list: ListItem, on: boolean) => void;
  onNew: () => void;
}): MenuItem {
  const inAll = (key: string) => chosen.length > 0 && chosen.every((i) => (i.labels ?? []).some((l) => listKey(l) === key));
  const checked = new Set(lists.filter((l) => inAll(l.key)).map((l) => l.name));
  return {
    label: "Add to list", icon: Icons.LabelIcon, group: "Organise",
    items: addToListItems({
      lists: lists.map((l) => ({ name: l.name, colour: l.color })), checked, onNew,
      onToggle: (name) => {
        const list = lists.find((l) => l.name === name);
        if (list) onToggle(list, !checked.has(name));
      },
    }),
  };
}

type Editing = { kind: "rename" | "recolour" | "delete"; list: ListItem };

/** Under the name field: a fixed chip's word is reserved, another list's name is in use (as the server words both). */
const nameWords = (reserved: readonly string[]) => ({
  duplicate: (name: string) => (reserved.includes(name) ? `${name} is one of the fixed lists. Choose another name.` : NEW_LIST_WORDS.duplicate(name)),
});

/**
 * The chip row with "+", and the dialogs it opens. `creating` asks for the new-list dialog from outside (Add to list ›
 * New list…): the new list comes back through `onCreating`, which puts those recordings in it.
 */
export function ListsRow({ lists, setLists, reload, place, onPlace, unread, say, onLibraryChanged, creating, onCreating }: {
  lists: readonly ListItem[];
  setLists: (lists: ListItem[]) => void;
  reload: () => Promise<void>;
  place: Place;
  onPlace: (place: Place) => void;
  unread: number;
  say: (text: string) => void;
  /** The recordings' labels changed (a rename, a recolour or a delete): the Library is synced once. */
  onLibraryChanged: () => void;
  creating: { ids: string[] } | null;
  onCreating: (request: { ids: string[] } | null, made?: ListItem) => void;
}) {
  const row = useRef<HTMLDivElement>(null);
  const [menu, setMenu] = useState<{ list: ListItem; at: { x: number; y: number } } | null>(null);
  const [editing, setEditing] = useState<Editing | null>(null);
  const [colour, setColour] = useState<LabelColour>("grey");
  const [busy, setBusy] = useState(false);
  const names = lists.map((l) => l.name);
  const reserved = Object.values(FIXED_CHIPS);
  const words = nameWords(reserved);

  const run = async (fn: () => Promise<void>) => {
    if (busy) return;
    setBusy(true);
    try {
      await fn();
    } catch (e) {
      say(errorMessage(e));
      // The list may be gone (deleted on another device: list_not_found): the row is read again, and a chosen list
      // that is gone gives way to All.
      await reload();
    } finally {
      setBusy(false);
    }
  };
  const create = (name: string, color: string) => run(async () => {
    const made = await api.createList(name, color.toLowerCase());
    setLists([...lists, made]);
    const forIds = creating?.ids ?? [];
    onCreating(null, made);
    // "+" opens the new list; from Add to list the recordings go in it (onCreating) and the place stays.
    if (!forIds.length) onPlace(listPlace(made.key));
  });
  const move = (list: ListItem, by: -1 | 1) => run(async () => {
    const at = lists.findIndex((l) => l.key === list.key);
    const to = at + by;
    if (at < 0 || to < 0 || to >= lists.length) return;
    const next = [...lists];
    [next[at], next[to]] = [next[to], next[at]];
    setLists(next);
    // A refusal puts the row back as the server has it (run reads it again).
    setLists(await api.orderLists(next.map((l) => l.key)));
  });
  const rename = (list: ListItem, name: string) => run(async () => {
    const saved = await api.updateList(list.key, { name });
    setLists(lists.map((l) => (l.key === list.key ? saved : l)));
    if (place === listPlace(list.key)) onPlace(listPlace(saved.key));
    setEditing(null);
    onLibraryChanged();
    say(`Renamed to ${saved.name}`);
  });
  const recolour = (list: ListItem) => run(async () => {
    const saved = await api.updateList(list.key, { color: LABEL_COLOURS[colour].toLowerCase() });
    setLists(lists.map((l) => (l.key === list.key ? saved : l)));
    setEditing(null);
    onLibraryChanged();
  });
  const remove = (list: ListItem) => run(async () => {
    await api.deleteList(list.key);
    setLists(lists.filter((l) => l.key !== list.key));
    if (place === listPlace(list.key)) onPlace("all");
    setEditing(null);
    onLibraryChanged();
    say(`${list.name} deleted · its recordings are still in your Library`);
  });
  const open = (kind: Editing["kind"], list: ListItem) => {
    setColour(labelColourName(list.color) ?? "grey");
    setEditing({ kind, list });
  };

  const asked = editing ? deleteListWords(editing.list.name) : null;
  return (
    <>
      <div ref={row}>
        <ListChipRow label="Lists" addLabel="New list" onAdd={() => onCreating({ ids: [] })}>
          <ListChip name={FIXED_CHIPS.all} fixed selected={place === "all"} onClick={() => onPlace("all")} />
          <ListChip name={FIXED_CHIPS.unread} fixed count={unread} selected={place === "unread"} onClick={() => onPlace("unread")} />
          <ListChip name={FIXED_CHIPS.important} fixed selected={place === "important"} onClick={() => onPlace("important")} />
          {lists.map((l) => (
            <ListChip key={l.key} name={l.name} colour={l.color} selected={place === listPlace(l.key)} onClick={() => onPlace(listPlace(l.key))}
              onMenu={(at) => setMenu({ list: l, at })} />
          ))}
        </ListChipRow>
      </div>
      {menu && (() => {
        const i = lists.findIndex((l) => l.key === menu.list.key);
        const list = lists[i];
        if (!list) return null;
        return (
          <ContextMenu label={list.name} at={menu.at} onClose={() => setMenu(null)}
            items={listChipMenuItems({
              name: list.name, canMoveEarlier: i > 0, canMoveLater: i < lists.length - 1, rtl: rtl(row.current),
              onRename: () => open("rename", list), onRecolour: () => open("recolour", list),
              onMoveEarlier: () => void move(list, -1), onMoveLater: () => void move(list, 1), onDelete: () => open("delete", list),
            })} />
        );
      })()}
      <NewListDialog open={!!creating} onClose={() => onCreating(null)} existing={names} reserved={reserved} usedColours={lists.map((l) => l.color)}
        words={words} onCreate={(name, color) => void create(name, color)} />
      <RenameListDialog open={editing?.kind === "rename"} name={editing?.list.name ?? ""} existing={names} reserved={reserved} words={words}
        onClose={() => setEditing(null)} onRename={(name) => editing && void rename(editing.list, name)} />
      <Dialog open={editing?.kind === "recolour"} title="Change colour" onClose={() => setEditing(null)}
        actions={<><Button onClick={() => setEditing(null)}>Cancel</Button><Button variant="primary" loading={busy} loadingLabel="Saving…" onClick={() => editing && void recolour(editing.list)}>Save</Button></>}>
        <ColourPicker label="Colour" hideLabel value={colour} onChange={setColour} />
      </Dialog>
      <Dialog open={editing?.kind === "delete"} alert title={asked?.title ?? ""} description={asked?.body} onClose={() => setEditing(null)}
        actions={<><Button onClick={() => setEditing(null)}>{asked?.cancel}</Button><Button variant="danger" loading={busy} loadingLabel="Deleting…" onClick={() => editing && void remove(editing.list)}>{asked?.confirm}</Button></>} />
    </>
  );
}
