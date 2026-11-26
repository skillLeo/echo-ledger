import type { IconType } from "./Button";
import { LabelDot } from "./Label";
import type { MenuItem } from "./Menu";
import { AppearanceIcon, PlusIcon, RenameIcon, TrashIcon } from "./icons";
import { t } from "../../i18n";

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

export type ListChipMenuWords = { rename: string; recolour: string; moveLeft: string; moveRight: string; remove: string };

/** The owner's words: "Move left" and "Move right", on every platform, as the chips are seen. */
export const LIST_CHIP_MENU_WORDS: ListChipMenuWords = {
  rename: "Rename…", recolour: "Change colour…", moveLeft: "Move left", moveRight: "Move right", remove: "Delete list…",
};

/**
 * A list chip's menu (D14 §4): Rename…, Change colour…, Move left and Move right (each only when there is somewhere to
 * move), then, set apart and last, Delete list… in red. The fixed chips (All, Unread, Important) have no menu.
 * Left and right are where the chip goes on screen: left to right, left is earlier in the order; right to left (`rtl`),
 * left is later, so the handlers swap.
 */
export function listChipMenuItems({ canMoveEarlier, canMoveLater, onRename, onRecolour, onMoveEarlier, onMoveLater, onDelete, rtl = false, words }: {
  name: string;
  canMoveEarlier: boolean;
  canMoveLater: boolean;
  onRename: () => void;
  onRecolour: () => void;
  onMoveEarlier: () => void;
  onMoveLater: () => void;
  onDelete: () => void;
  /** The page reads right to left. */
  rtl?: boolean;
  words?: Partial<ListChipMenuWords>;
}): MenuItem[] {
  const w = { ...LIST_CHIP_MENU_WORDS, ...words };
  const [canLeft, onLeft, canRight, onRight] = rtl
    ? [canMoveLater, onMoveLater, canMoveEarlier, onMoveEarlier]
    : [canMoveEarlier, onMoveEarlier, canMoveLater, onMoveLater];
  const items: MenuItem[] = [
    { label: w.rename, icon: RenameIcon, onPick: onRename },
    { label: w.recolour, icon: AppearanceIcon, onPick: onRecolour },
  ];
  if (canLeft) items.push({ label: w.moveLeft, onPick: onLeft });
  if (canRight) items.push({ label: w.moveRight, onPick: onRight });
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
    title: words?.title ? words.title(name) : t("Delete :name?", { name }),
    body: words?.body ?? "Its recordings stay in your Library; only the list goes.",
    confirm: words?.confirm ?? t("Delete"),
    cancel: words?.cancel ?? t("Cancel"),
  };
}
