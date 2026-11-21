import type { IconType } from "./Button";
import { LabelDot } from "./Label";
import type { MenuItem } from "./Menu";
import { AppearanceIcon, PlusIcon, RenameIcon, TrashIcon } from "./icons";

const dots = new Map<string, IconType>();

/**
 * A menu icon that draws a list's colour dot (the menu's icon slot takes a component). One component per colour, made
 * once, so a menu that is drawn again keeps the same icons.
 */
export function listDotIcon(colour: string | null | undefined): IconType {
  const key = colour ?? "";
  let icon = dots.get(key);
  if (!icon) {
    const Dot = () => <LabelDot name="" colour={colour} decorative />;
    Dot.displayName = `ListDot(${key || "none"})`;
    icon = Dot;
    dots.set(key, icon);
  }
  return icon;
}

export type AddToListWords = { newList: string };
export const ADD_TO_LIST_WORDS: AddToListWords = { newList: "New list…" };

/**
 * The Add to list picker (D14 §3) as items for Menu or ContextMenu: each of the person's lists as a tick with its
 * colour dot, in their order, that stays open so several can be ticked in a row; then, set apart, New list…, which
 * opens the new-list dialog (the recording comes back ticked in it).
 */
export function addToListItems({ lists, checked, onToggle, onNew, words }: {
  lists: readonly { name: string; colour: string | null }[];
  /** The names of the lists the recording (or every picked recording) is in. */
  checked: ReadonlySet<string>;
  onToggle: (name: string) => void;
  onNew: () => void;
  words?: Partial<AddToListWords>;
}): MenuItem[] {
  const w = { ...ADD_TO_LIST_WORDS, ...words };
  return [
    ...lists.map((list): MenuItem => ({
      label: list.name, icon: listDotIcon(list.colour), checked: checked.has(list.name), keepOpen: true, onPick: () => onToggle(list.name),
    })),
    { label: w.newList, icon: PlusIcon, separator: lists.length > 0, onPick: onNew },
  ];
}

export type ListChipMenuWords = { rename: string; recolour: string; moveEarlier: string; moveLater: string; remove: string };

/**
 * "Move earlier" and "Move later" rather than left and right: a list's place is its order, which reads right to left
 * in Urdu, so the same words are right in both directions.
 */
export const LIST_CHIP_MENU_WORDS: ListChipMenuWords = {
  rename: "Rename…", recolour: "Change colour…", moveEarlier: "Move earlier", moveLater: "Move later", remove: "Delete list…",
};

/**
 * A list chip's menu (D14 §4): Rename…, Change colour…, Move earlier and Move later (each only when there is somewhere
 * to move), then, set apart and last, Delete list… in red. The fixed chips (All, Unread, Important) have no menu.
 */
export function listChipMenuItems({ canMoveEarlier, canMoveLater, onRename, onRecolour, onMoveEarlier, onMoveLater, onDelete, words }: {
  name: string;
  canMoveEarlier: boolean;
  canMoveLater: boolean;
  onRename: () => void;
  onRecolour: () => void;
  onMoveEarlier: () => void;
  onMoveLater: () => void;
  onDelete: () => void;
  words?: Partial<ListChipMenuWords>;
}): MenuItem[] {
  const w = { ...LIST_CHIP_MENU_WORDS, ...words };
  const items: MenuItem[] = [
    { label: w.rename, icon: RenameIcon, onPick: onRename },
    { label: w.recolour, icon: AppearanceIcon, onPick: onRecolour },
  ];
  if (canMoveEarlier) items.push({ label: w.moveEarlier, onPick: onMoveEarlier });
  if (canMoveLater) items.push({ label: w.moveLater, onPick: onMoveLater });
  items.push({ label: w.remove, icon: TrashIcon, danger: true, separator: true, onPick: onDelete });
  return items;
}

export type DeleteListWords = { title: string; body: string; confirm: string; cancel: string };

/**
 * The words of Delete list…'s confirmation (an alert dialog whose Delete button is red): "Delete Clients?" and that
 * the recordings stay. A page in another language passes its own.
 */
export function deleteListWords(name: string, words?: { title?: (name: string) => string; body?: string; confirm?: string; cancel?: string }): DeleteListWords {
  return {
    title: words?.title ? words.title(name) : `Delete ${name}?`,
    body: words?.body ?? "Its recordings stay in your Library; only the list goes.",
    confirm: words?.confirm ?? "Delete",
    cancel: words?.cancel ?? "Cancel",
  };
}
