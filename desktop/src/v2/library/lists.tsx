// Labels in the v2 Library (D14, contract 2.0.0-m; PLAN_LIBRARY_FILTERS: "lists ARE labels"). A label is one of the
// person's lists kept by the server (a name, a colour, a place in the order), so the same labels in the same order show
// on every device. They live in the sidebar's LABELS section (LabelsNav): choosing one shows its recordings, "+ New
// label" makes one, and each has Show recordings, Rename…, Colour ›, Move up / Move down and Delete label…. A recording's ⋮ and
// right-click have Labels ▸ to put it in or take it out of them. Every word is in the window's language (desktop Urdu,
// D1b), the shared list dialogs' too (given their words here); a label's name is the person's own.
import { useCallback, useRef, useState, useSyncExternalStore } from "react";
import { listen } from "@tauri-apps/api/event";
import { api, errorMessage, type LibraryItem, type ListItem } from "../../lib/ipc";
import {
  Button, ContextMenu, Dialog, Icon, Icons, LABEL_COLOURS, LabelDot, NewListDialog, RenameListDialog,
  addToListItems, deleteListWords, labelColourName, menuPointFor, type LabelColour, type NewListWords, type RenameListWords,
} from "../components";
import type { MenuItem } from "../components/Menu";
import { contextItems, LABEL } from "../actions/contextMenus";
import { currentPlatform, popupNative } from "../actions/popup";
import { choiceItems, type ActionContext } from "../actions/registry";
import { useActions } from "../actions/useActions";
import { sayProblem, type Say } from "./organise";
import { libraryTabName, libraryViewName } from "./facets";
import { lineDir, t, useLanguage, type Lang } from "../../i18n";

/** What a label's menu runs (contextMenus LABEL's ids). */
const LABEL_IDS = ["label.showRecordings", "file.rename", "label.colour", "view.moveUp", "view.moveDown", "label.delete"] as const;

/** A label's key: its name in lower case, as the server keys it. */
export const listKey = (name: string) => name.toLowerCase();

/** The eight colours' names, in the window's language (the picker's, and a label's Colour ›). */
const colourNames = (): Record<LabelColour, string> => ({
  grey: t("Grey"), red: t("Red"), orange: t("Orange"), amber: t("Amber"), green: t("Green"), teal: t("Teal"), blue: t("Blue"), pink: t("Pink"),
});

/** The words of labels (the components' list words, as the Library says them now), in the window's language. */
export function labelWords() {
  const taken = (name: string) => t("You already have a label called :name.", { name });
  const dialog = { name: t("Label name"), cancel: t("Cancel"), close: t("Close"), duplicate: taken };
  const newDialog: NewListWords = { ...dialog, title: t("New label"), colour: t("Colour"), create: t("Create"), colourNames: colourNames() };
  const renameDialog: RenameListWords = { ...dialog, title: t("Rename label"), save: t("Save") };
  return { section: t("Labels"), add: t("New label"), menu: t("Labels"), newDialog, renameDialog, newItem: t("New label…") };
}

/**
 * The library views' names are reserved: a label can't be called All, All recordings, Unread or Important, in English
 * (as the server keys them) or in the window's language.
 */
export function reservedNames(): string[] {
  const names = (lang?: Lang) => [libraryTabName("all", lang), libraryViewName("all", lang), libraryViewName("unread", lang), libraryViewName("important", lang)];
  return [...new Set([...names("en"), ...names()])];
}

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
  const words = labelWords();
  return {
    label: words.menu, icon: Icons.LabelIcon, group: t("Organise"),
    items: addToListItems({
      lists: all.map((l) => ({ name: l.name, colour: l.color })), checked, onNew, words: { newList: words.newItem },
      onToggle: (name) => {
        const list = all.find((l) => l.name === name);
        if (list) onToggle(list, !checked.has(name));
      },
    }),
  };
}

type Editing = { kind: "rename" | "delete"; list: ListItem };

/** Under the name field: a library view's name is reserved, another label's name is in use. */
const nameWords = (taken: (name: string) => string, reserved: readonly string[]) => ({
  duplicate: (name: string) => (reserved.includes(name) ? t(":name is one of the Library's views. Choose another name.", { name }) : taken(name)),
});

/**
 * The sidebar's LABELS section: a header that folds (kept on this computer) with "+", each label with its colour dot
 * and its count (muted), the one shown marked, its menu on ⋮ or a right-click, and "+ New label" at the end. The
 * dialogs it opens: New label, Rename label and the Delete question (Colour › sets a colour at once).
 */
export function LabelsNav({ counts, current, onOpen, onGone, say, onLibraryChanged }: {
  /** How many recordings have each label, by key. */
  counts: Readonly<Record<string, number>>;
  /** The label shown in the Library (its key), if one is. */
  current: string | null;
  onOpen: (key: string) => void;
  /** The label shown was deleted or renamed away: the Library goes back to All. */
  onGone: () => void;
  say: Say;
  /** The recordings' labels changed (a rename, a recolour or a delete): the Library is synced once. */
  onLibraryChanged: () => void;
}) {
  const { lists: all, setLists, reload } = useLists();
  // Drawn again in the new language when it changes.
  useLanguage();
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
  const [busy, setBusy] = useState(false);
  const section = useRef<HTMLElement>(null);
  // A label's menu (UX-22): what it does, registered before the labels are known (filled in below).
  const labelActions = useRef<Record<string, (c: ActionContext) => void>>({});
  useActions("label", Object.fromEntries(LABEL_IDS.map((id) => [id, (c: ActionContext) => labelActions.current[id]?.(c)])));
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
      sayProblem(say, errorMessage(e));
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
    say(t("Renamed to :name", { name: saved.name }));
  });
  /** Colour › a colour (UX-22): set at once. */
  const setColourOf = (list: ListItem, name: keyof typeof LABEL_COLOURS) => run(async () => {
    const saved = await api.updateList(list.key, { color: LABEL_COLOURS[name].toLowerCase() });
    setLists(all.map((l) => (l.key === list.key ? saved : l)));
    onLibraryChanged();
  });
  // A label's menu (UX-22, 3b), from actions.json: Show recordings | Rename… · Colour › · Move up · Move down |
  // Delete label… (its recordings stay).
  const listOf = (c: ActionContext) => all.find((l) => l.key === c.targets?.[0]);
  labelActions.current = {
    "label.showRecordings": (c) => { const l = listOf(c); if (l) onOpen(l.key); },
    "file.rename": (c) => { const l = listOf(c); if (l) edit("rename", l); },
    "label.colour": (c) => { const l = listOf(c); if (l && c.value && c.value in LABEL_COLOURS) void setColourOf(l, c.value as keyof typeof LABEL_COLOURS); },
    "view.moveUp": (c) => { const l = listOf(c); if (l) void move(l, -1); },
    "view.moveDown": (c) => { const l = listOf(c); if (l) void move(l, 1); },
    "label.delete": (c) => { const l = listOf(c); if (l) edit("delete", l); },
  };
  const labelMenu = (list: ListItem): MenuItem[] => {
    const i = all.findIndex((l) => l.key === list.key);
    const ctx: ActionContext = { platform: currentPlatform(), targets: [list.key], kind: "label" };
    const now = labelColourName(list.color);
    const colourName = colourNames();
    const colours = (Object.keys(LABEL_COLOURS) as (keyof typeof LABEL_COLOURS)[]).map((name) => ({
      value: name, label: colourName[name], checked: now === name,
    }));
    return contextItems(LABEL, ctx, { "label.colour": choiceItems("label.colour", colours, ctx).map((item) => ({ ...item, radio: true })) })
      .filter((item) => !(item.action === "view.moveUp" && i === 0) && !(item.action === "view.moveDown" && i === all.length - 1));
  };
  const openMenu = (list: ListItem, where: { x: number; y: number }) => {
    if (currentPlatform() !== "macos") return setMenu({ list, at: where });
    void popupNative(labelMenu(list), where).then((shown) => { if (!shown) setMenu({ list, at: where }); });
  };
  const remove = (list: ListItem) => run(async () => {
    await api.deleteList(list.key);
    setLists(all.filter((l) => l.key !== list.key));
    if (current === list.key) onGone();
    setEditing(null);
    onLibraryChanged();
    say(t(":name deleted · its recordings are still in your Library", { name: list.name }));
  });
  const edit = (kind: Editing["kind"], list: ListItem) => {
    setEditing({ kind, list });
  };
  const asked = editing ? deleteListWords(editing.list.name, {
    title: (name) => t("Delete :name?", { name }), body: t("Its recordings stay in your Library; only the label goes."), confirm: t("Delete"), cancel: t("Cancel"),
  }) : null;
  const words = labelWords();
  const reserved = reservedNames();
  // Rename: a label already called by a view's name in this language (made on the phone, or before this one was
  // reserved) keeps it without a word against it; any other view's name is still refused.
  const own = (editing?.list.name ?? "").trim().toLowerCase();
  const keepOwn = reserved.filter((r) => r.trim().toLowerCase() !== own);
  return (
    <section className="el-nav-section" aria-label={words.section} ref={section}>
      <div className="el-nav-section__head">
        <button type="button" className="el-nav-section__title el-caption" aria-expanded={!folded} onClick={fold}>
          <Icon icon={folded ? Icons.ChevronEndIcon : Icons.ChevronDownIcon} directional />{words.section}
        </button>
        <Button size="sm" variant="quiet" icon={Icons.PlusIcon} aria-label={words.add} title={words.add} onClick={() => setCreating(true)} />
      </div>
      {!folded && (
        <ul className="el-nav-list" aria-label={words.section}>
          {all.map((l, i) => (
            <li key={l.key} className="el-nav-row" onContextMenu={(e) => { e.preventDefault(); openMenu(l, { x: e.clientX, y: e.clientY }); }}>
              <button type="button" className="el-nav-item el-nav-item--row" aria-current={current === l.key ? "page" : undefined}
                aria-label={`${l.name}, ${counts[l.key] ?? 0}`} onClick={() => onOpen(l.key)}
                onKeyDown={(e) => { if (e.key === "ContextMenu" || (e.shiftKey && e.key === "F10")) { e.preventDefault(); openMenu(l, menuPointFor(e.currentTarget)); } }}>
                <LabelDot name={l.name} colour={l.color} decorative />
                <span className="el-nav-item__label" dir={lineDir(l.name)}>{l.name}</span>
                {(counts[l.key] ?? 0) > 0 && <>{" "}<span className="el-nav-item__count el-caption el-num">{counts[l.key]}</span></>}
              </button>
              <Button size="sm" variant="quiet" className="el-nav-row__more" icon={Icons.MoreIcon} aria-label={t("More for :name", { name: l.name })}
                onClick={(e) => openMenu(l, menuPointFor(e.currentTarget))} data-index={i} />
            </li>
          ))}
          <li>
            <button type="button" className="el-nav-item el-nav-item--row el-nav-item--add" onClick={() => setCreating(true)}>
              <Icon icon={Icons.PlusIcon} /><span className="el-nav-item__label">{words.add}</span>
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
            items={labelMenu(list)} />
        );
      })()}
      <NewListDialog open={creating} onClose={() => setCreating(false)} existing={names} reserved={reserved} usedColours={all.map((l) => l.color)}
        words={{ ...words.newDialog, ...nameWords(words.newDialog.duplicate, reserved) }} onCreate={(name, color) => void create(name, color)} />
      <RenameListDialog open={editing?.kind === "rename"} name={editing?.list.name ?? ""} existing={names} reserved={keepOwn}
        words={{ ...words.renameDialog, ...nameWords(words.renameDialog.duplicate, keepOwn) }}
        onClose={() => setEditing(null)} onRename={(name) => editing && void rename(editing.list, name)} />
      <Dialog open={editing?.kind === "delete"} alert title={asked?.title ?? ""} description={asked?.body} onClose={() => setEditing(null)} closeLabel={t("Close")}
        actions={<><Button onClick={() => setEditing(null)}>{asked?.cancel}</Button><Button variant="danger" loading={busy} loadingLabel={t("Deleting…")} onClick={() => editing && void remove(editing.list)}>{asked?.confirm}</Button></>} />
    </section>
  );
}

/** The dialog for a new label from a recording's Labels ▸ New label…: made, then the recordings go in it. */
export function NewLabelFor({ ids, onClose, onMade }: { ids: readonly string[]; onClose: () => void; onMade: (list: ListItem, ids: readonly string[]) => void }) {
  const { lists: all, setLists } = useLists();
  useLanguage();
  const words = labelWords();
  const reserved = reservedNames();
  const create = async (name: string, color: string) => {
    const made = await api.createList(name, color.toLowerCase());
    setLists([...(all ?? []), made]);
    onClose();
    onMade(made, ids);
  };
  return (
    <NewListDialog open onClose={onClose} existing={(all ?? []).map((l) => l.name)} reserved={reserved} usedColours={(all ?? []).map((l) => l.color)}
      words={{ ...words.newDialog, ...nameWords(words.newDialog.duplicate, reserved) }} onCreate={(name, color) => void create(name, color).catch(() => undefined)} />
  );
}
