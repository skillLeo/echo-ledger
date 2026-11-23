// Labels in the v2 Library (D14, contract 2.0.0-m; PLAN_LIBRARY_FILTERS: "lists ARE labels"). A label is one of the
// person's lists kept by the server (a name, a colour, a place in the order), so the same labels in the same order show
// on every device. They live in the sidebar's LABELS section (LabelsNav): choosing one shows its recordings, "+ New
// label" makes one, and each has Rename…, Change colour…, Move up / Move down and Delete label…. A recording's ⋮ and
// right-click have Labels ▸ to put it in or take it out of them.
import { useCallback, useRef, useState, useSyncExternalStore } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type LibraryItem, type ListItem } from "../../lib/ipc";
import {
  Button, ColourPicker, ContextMenu, Dialog, Icon, Icons, LABEL_COLOURS, LabelDot, NEW_LIST_WORDS, NewListDialog, RENAME_LIST_WORDS, RenameListDialog,
  addToListItems, deleteListWords, labelColourName, listChipMenuItems, menuPointFor, type LabelColour,
} from "../components";
import type { MenuItem } from "../components/Menu";

/** A label's key: its name in lower case, as the server keys it. */
export const listKey = (name: string) => name.toLowerCase();

/** The words of labels (the components' list words, as the Library says them now). */
export const LABEL_WORDS = {
  section: "Labels",
  add: "New label",
  menu: "Labels",
  newDialog: { ...NEW_LIST_WORDS, title: "New label", name: "Label name", duplicate: (name: string) => `You already have a label called ${name}.` },
  renameDialog: { ...RENAME_LIST_WORDS, title: "Rename label", name: "Label name", duplicate: (name: string) => `You already have a label called ${name}.` },
  chipMenu: { moveLeft: "Move up", moveRight: "Move down", remove: "Delete label…" },
  newItem: "New label…",
} as const;

/** The library views' names are reserved: a label can't be called All, Unread or Important. */
export const RESERVED_NAMES = ["All", "Unread", "Important"];

// The person's labels, one copy for the window (the sidebar and the Library show the same): read from the server when
// first needed, again whenever the library changes; forgotten when nothing shows them any more.
let lists: ListItem[] | null = null;
const listeners = new Set<() => void>();
let unlisten: (() => void) | null = null;
const emit = () => listeners.forEach((l) => l());
const setShared = (next: ListItem[] | null) => {
  lists = next;
  emit();
};
async function reloadShared() {
  try {
    setShared((await api.lists()) ?? null);
  } catch {
    // Labels are an extra: the Library works without them.
  }
}
function subscribe(listener: () => void) {
  if (listeners.size === 0) {
    lists = null;
    void reloadShared();
    const un = listen("library:updated", () => void reloadShared());
    unlisten = () => void un.then((f) => f());
  }
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
    if (listeners.size === 0) {
      unlisten?.();
      unlisten = null;
      lists = null;
    }
  };
}
const snapshot = () => lists;

/** The person's labels (null on a server without them), the same for every part of the window. */
export function useLists() {
  const now = useSyncExternalStore(subscribe, snapshot, snapshot);
  const setLists = useCallback((next: ListItem[]) => setShared(next), []);
  const reload = useCallback(() => reloadShared(), []);
  return { lists: now, setLists, reload };
}

/**
 * Labels ▸ for the chosen recordings, as a sub-menu: each label ticked when every chosen recording has it, New label…
 * at the end.
 */
export function addToListMenu({ lists: all, chosen, onToggle, onNew }: {
  lists: readonly ListItem[];
  chosen: readonly LibraryItem[];
  onToggle: (list: ListItem, on: boolean) => void;
  onNew: () => void;
}): MenuItem {
  const inAll = (key: string) => chosen.length > 0 && chosen.every((i) => (i.labels ?? []).some((l) => listKey(l) === key));
  const checked = new Set(all.filter((l) => inAll(l.key)).map((l) => l.name));
  return {
    label: LABEL_WORDS.menu, icon: Icons.LabelIcon, group: "Organise",
    items: addToListItems({
      lists: all.map((l) => ({ name: l.name, colour: l.color })), checked, onNew, words: { newList: LABEL_WORDS.newItem },
      onToggle: (name) => {
        const list = all.find((l) => l.name === name);
        if (list) onToggle(list, !checked.has(name));
      },
    }),
  };
}

type Editing = { kind: "rename" | "recolour" | "delete"; list: ListItem };

/** Under the name field: a library view's name is reserved, another label's name is in use. */
const nameWords = (taken: (name: string) => string) => ({
  duplicate: (name: string) => (RESERVED_NAMES.includes(name) ? `${name} is one of the Library's views. Choose another name.` : taken(name)),
});

/**
 * The sidebar's LABELS section: a header that folds (kept on this computer) with "+", each label with its colour dot
 * and its count (muted), the one shown marked, its menu on ⋮ or a right-click, and "+ New label" at the end. The
 * dialogs it opens: New label, Rename label, Change colour and the Delete question.
 */
export function LabelsNav({ counts, current, onOpen, onGone, say, onLibraryChanged }: {
  /** How many recordings have each label, by key. */
  counts: Readonly<Record<string, number>>;
  /** The label shown in the Library (its key), if one is. */
  current: string | null;
  onOpen: (key: string) => void;
  /** The label shown was deleted or renamed away: the Library goes back to All. */
  onGone: () => void;
  say: (text: string) => void;
  /** The recordings' labels changed (a rename, a recolour or a delete): the Library is synced once. */
  onLibraryChanged: () => void;
}) {
  const { lists: all, setLists, reload } = useLists();
  const [folded, setFolded] = useState(() => {
    try {
      return window.localStorage.getItem("echoledger-sidebar-labels-folded") === "1";
    } catch {
      return false;
    }
  });
  const [creating, setCreating] = useState(false);
  const [menu, setMenu] = useState<{ list: ListItem; at: { x: number; y: number } } | null>(null);
  const [editing, setEditing] = useState<Editing | null>(null);
  const [colour, setColour] = useState<LabelColour>("grey");
  const [busy, setBusy] = useState(false);
  const section = useRef<HTMLElement>(null);
  if (!all) return null;
  const names = all.map((l) => l.name);
  const fold = () => setFolded((f) => {
    try {
      window.localStorage.setItem("echoledger-sidebar-labels-folded", f ? "0" : "1");
    } catch {
      // Folded for this session only.
    }
    return !f;
  });
  const run = async (fn: () => Promise<void>) => {
    if (busy) return;
    setBusy(true);
    try {
      await fn();
    } catch (e) {
      say(errorMessage(e));
      // The label may be gone (deleted on another device: list_not_found): read them again.
      await reload();
    } finally {
      setBusy(false);
    }
  };
  const create = (name: string, color: string) => run(async () => {
    const made = await api.createList(name, color.toLowerCase());
    setLists([...all, made]);
    setCreating(false);
    onOpen(made.key);
  });
  const move = (list: ListItem, by: -1 | 1) => run(async () => {
    const at = all.findIndex((l) => l.key === list.key);
    const to = at + by;
    if (at < 0 || to < 0 || to >= all.length) return;
    const next = [...all];
    [next[at], next[to]] = [next[to], next[at]];
    setLists(next);
    // A refusal puts the order back as the server has it (run reads it again).
    setLists(await api.orderLists(next.map((l) => l.key)));
  });
  const rename = (list: ListItem, name: string) => run(async () => {
    const saved = await api.updateList(list.key, { name });
    setLists(all.map((l) => (l.key === list.key ? saved : l)));
    if (current === list.key) onOpen(saved.key);
    setEditing(null);
    onLibraryChanged();
    say(`Renamed to ${saved.name}`);
  });
  const recolour = (list: ListItem) => run(async () => {
    const saved = await api.updateList(list.key, { color: LABEL_COLOURS[colour].toLowerCase() });
    setLists(all.map((l) => (l.key === list.key ? saved : l)));
    setEditing(null);
    onLibraryChanged();
  });
  const remove = (list: ListItem) => run(async () => {
    await api.deleteList(list.key);
    setLists(all.filter((l) => l.key !== list.key));
    if (current === list.key) onGone();
    setEditing(null);
    onLibraryChanged();
    say(`${list.name} deleted · its recordings are still in your Library`);
  });
  const edit = (kind: Editing["kind"], list: ListItem) => {
    setColour(labelColourName(list.color) ?? "grey");
    setEditing({ kind, list });
  };
  const asked = editing ? deleteListWords(editing.list.name, { body: "Its recordings stay in your Library; only the label goes." }) : null;
  return (
    <section className="el-nav-section" aria-label={LABEL_WORDS.section} ref={section}>
      <div className="el-nav-section__head">
        <button type="button" className="el-nav-section__title el-caption" aria-expanded={!folded} onClick={fold}>
          <Icon icon={folded ? Icons.ChevronEndIcon : Icons.ChevronDownIcon} directional />{LABEL_WORDS.section}
        </button>
        <Button size="sm" variant="quiet" icon={Icons.PlusIcon} aria-label={LABEL_WORDS.add} title={LABEL_WORDS.add} onClick={() => setCreating(true)} />
      </div>
      {!folded && (
        <ul className="el-nav-list" aria-label={LABEL_WORDS.section}>
          {all.map((l, i) => (
            <li key={l.key} className="el-nav-row" onContextMenu={(e) => { e.preventDefault(); setMenu({ list: l, at: { x: e.clientX, y: e.clientY } }); }}>
              <button type="button" className="el-nav-item el-nav-item--row" aria-current={current === l.key ? "page" : undefined}
                aria-label={`${l.name}, ${counts[l.key] ?? 0}`} onClick={() => onOpen(l.key)}
                onKeyDown={(e) => { if (e.key === "ContextMenu" || (e.shiftKey && e.key === "F10")) { e.preventDefault(); setMenu({ list: l, at: menuPointFor(e.currentTarget) }); } }}>
                <LabelDot name={l.name} colour={l.color} decorative />
                <span className="el-nav-item__label" dir="auto">{l.name}</span>
                {(counts[l.key] ?? 0) > 0 && <span className="el-nav-item__count el-caption el-num">{counts[l.key]}</span>}
              </button>
              <Button size="sm" variant="quiet" className="el-nav-row__more" icon={Icons.MoreIcon} aria-label={`More for ${l.name}`}
                onClick={(e) => setMenu({ list: l, at: menuPointFor(e.currentTarget) })} data-index={i} />
            </li>
          ))}
          <li>
            <button type="button" className="el-nav-item el-nav-item--row el-nav-item--add" onClick={() => setCreating(true)}>
              <Icon icon={Icons.PlusIcon} /><span className="el-nav-item__label">{LABEL_WORDS.add}</span>
            </button>
          </li>
        </ul>
      )}
      {menu && (() => {
        const i = all.findIndex((l) => l.key === menu.list.key);
        const list = all[i];
        if (!list) return null;
        return (
          <ContextMenu label={list.name} at={menu.at} onClose={() => setMenu(null)}
            items={listChipMenuItems({
              name: list.name, canMoveEarlier: i > 0, canMoveLater: i < all.length - 1, words: LABEL_WORDS.chipMenu,
              onRename: () => edit("rename", list), onRecolour: () => edit("recolour", list),
              onMoveEarlier: () => void move(list, -1), onMoveLater: () => void move(list, 1), onDelete: () => edit("delete", list),
            })} />
        );
      })()}
      <NewListDialog open={creating} onClose={() => setCreating(false)} existing={names} reserved={RESERVED_NAMES} usedColours={all.map((l) => l.color)}
        words={{ ...LABEL_WORDS.newDialog, ...nameWords(LABEL_WORDS.newDialog.duplicate) }} onCreate={(name, color) => void create(name, color)} />
      <RenameListDialog open={editing?.kind === "rename"} name={editing?.list.name ?? ""} existing={names} reserved={RESERVED_NAMES}
        words={{ ...LABEL_WORDS.renameDialog, ...nameWords(LABEL_WORDS.renameDialog.duplicate) }}
        onClose={() => setEditing(null)} onRename={(name) => editing && void rename(editing.list, name)} />
      <Dialog open={editing?.kind === "recolour"} title="Change colour" onClose={() => setEditing(null)}
        actions={<><Button onClick={() => setEditing(null)}>Cancel</Button><Button variant="primary" loading={busy} loadingLabel="Saving…" onClick={() => editing && void recolour(editing.list)}>Save</Button></>}>
        <ColourPicker label="Colour" hideLabel value={colour} onChange={setColour} />
      </Dialog>
      <Dialog open={editing?.kind === "delete"} alert title={asked?.title ?? ""} description={asked?.body} onClose={() => setEditing(null)}
        actions={<><Button onClick={() => setEditing(null)}>{asked?.cancel}</Button><Button variant="danger" loading={busy} loadingLabel="Deleting…" onClick={() => editing && void remove(editing.list)}>{asked?.confirm}</Button></>} />
    </section>
  );
}

/** The dialog for a new label from a recording's Labels ▸ New label…: made, then the recordings go in it. */
export function NewLabelFor({ ids, onClose, onMade }: { ids: readonly string[]; onClose: () => void; onMade: (list: ListItem, ids: readonly string[]) => void }) {
  const { lists: all, setLists } = useLists();
  const create = async (name: string, color: string) => {
    const made = await api.createList(name, color.toLowerCase());
    setLists([...(all ?? []), made]);
    onClose();
    onMade(made, ids);
  };
  return (
    <NewListDialog open onClose={onClose} existing={(all ?? []).map((l) => l.name)} reserved={RESERVED_NAMES} usedColours={(all ?? []).map((l) => l.color)}
      words={{ ...LABEL_WORDS.newDialog, ...nameWords(LABEL_WORDS.newDialog.duplicate) }} onCreate={(name, color) => void create(name, color).catch(() => undefined)} />
  );
}
